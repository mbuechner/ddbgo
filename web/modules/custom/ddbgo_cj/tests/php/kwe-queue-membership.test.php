<?php

/**
 * @file
 * Run with php web/modules/custom/ddbgo_cj/tests/php/kwe-queue-membership.test.php.
 *
 * Native Drupal SELECT/Condition builders execute against an isolated SQLite3
 * database in memory. No Drupal site bootstrap, persistent database, HTTP, or
 * content/configuration writes are involved. Queue insertion and lease methods
 * use Core DatabaseQueue with a small in-memory connection adapter.
 */

declare(strict_types=1);

use Drupal\Component\Datetime\Time;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\Memory;
use Drupal\Core\Queue\QueueDatabaseFactory;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Site\Settings;
use Drupal\content_lock\ContentLock\ContentLock;
use Drupal\ddbgo_cj\DdbgoCjServiceProvider;
use Drupal\ddbgo_cj\KweDatabaseQueue;
use Drupal\ddbgo_cj\KweQueueDatabaseFactory;
use Drupal\ddbgo_cj\KweQueueWorker;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;

$root = dirname(__DIR__, 6);
$loader = require $root . '/vendor/autoload.php';
foreach (['node' => 'web/core/modules/node/src', 'user' => 'web/core/modules/user/src', 'content_lock' => 'web/modules/contrib/content_lock/src', 'ddbgo_cj' => 'web/modules/custom/ddbgo_cj/src'] as $namespace => $path) {
  $loader->addPsr4('Drupal\\' . $namespace . '\\', $root . '/' . $path);
}
require_once $root . '/web/core/lib/Drupal.php';

if (!class_exists(SQLite3::class)) {
  throw new RuntimeException('The isolated queue test requires the SQLite3 PHP extension.');
}

final class KweMembershipStatement {
  public function __construct(private array $rows) {}
  public function fetchField(int $index = 0) {
    return isset($this->rows[0]) ? array_values($this->rows[0])[$index] : FALSE;
  }
  public function fetchCol(int $index = 0): array {
    return array_map(static fn (array $row) => array_values($row)[$index], $this->rows);
  }
  public function fetchObject() { return isset($this->rows[0]) ? (object) $this->rows[0] : FALSE; }
}

/** Native Select is deliberately not replaced by a fluent query fake. */
final class KweMembershipConnection extends Connection {
  private SQLite3 $memory;
  public array $queries = [];
  public array $writes = [];
  public ?Closure $afterSelect = NULL;
  public ?Closure $beforeInsert = NULL;
  public bool $failNextSelect = FALSE;
  public bool $failNextInsert = FALSE;

  public function __construct(bool $create_table = TRUE) {
    $this->identifierQuotes = ['"', '"'];
    $this->memory = new SQLite3(':memory:');
    $this->memory->enableExceptions(TRUE);
    if ($create_table) {
      $this->createTable();
    }
  }

  public function createTable(): void {
    $this->memory->exec('CREATE TABLE queue (item_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, data TEXT, expire INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL)');
  }

  public function tableExists(): bool {
    return (bool) $this->memory->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'queue'");
  }

  public function schema() {
    return new class($this) {
      public function __construct(private KweMembershipConnection $connection) {}
      public function tableExists($table) { return $table === 'queue' && $this->connection->tableExists(); }
      public function createTable($table, $definition) {
        if ($table !== 'queue' || !isset($definition['fields']['data'])) {
          throw new RuntimeException('Unexpected queue schema.');
        }
        $this->connection->createTable();
      }
    };
  }

  private function executeSql(string $sql, array $args): KweMembershipStatement {
    $statement = $this->memory->prepare(str_replace('{queue}', 'queue', $sql));
    foreach ($args as $key => $value) {
      $statement->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $result = $statement->execute();
    $rows = [];
    if ($result->numColumns() > 0) {
      while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
      }
    }
    $result->finalize();
    return new KweMembershipStatement($rows);
  }

  public function query($query, array $args = [], $options = []) {
    $sql = (string) $query;
    $this->queries[] = ['sql' => $sql, 'args' => $args];
    if ($this->failNextSelect) {
      $this->failNextSelect = FALSE;
      throw new RuntimeException('Synthetic SELECT failure');
    }
    if (!str_starts_with($sql, 'SELECT ')) {
      throw new RuntimeException('Membership must execute SELECT only.');
    }
    $statement = $this->executeSql($sql, $args);
    $this->afterSelect?->__invoke($sql, $args);
    return $statement;
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->query($query . ' LIMIT ' . (int) $count . ' OFFSET ' . (int) $from, $args, $options);
  }

  public function insert($table, array $options = []) {
    return new class($this, $table) {
      private array $values;
      public function __construct(private KweMembershipConnection $connection, private string $table) {}
      public function fields(array $values) { $this->values = $values; return $this; }
      public function execute() { return $this->connection->insertValues($this->table, $this->values); }
    };
  }

  public function insertValues(string $table, array $values) {
    if ($table !== 'queue') {
      throw new RuntimeException('Unexpected insert table.');
    }
    $this->beforeInsert?->__invoke($values);
    if ($this->failNextInsert) {
      $this->failNextInsert = FALSE;
      return FALSE;
    }
    $this->executeSql('INSERT INTO queue (name, data, created, expire) VALUES (:name, :data, :created, :expire)', [
      ':name' => $values['name'], ':data' => $values['data'], ':created' => $values['created'], ':expire' => $values['expire'] ?? 0,
    ]);
    $this->writes[] = ['operation' => 'insert', 'values' => $values];
    return $this->memory->lastInsertRowID();
  }

  public function update($table, array $options = []) { return $this->mutation($table, FALSE); }
  public function delete($table, array $options = []) { return $this->mutation($table, TRUE); }

  private function mutation(string $table, bool $delete): object {
    return new class($this, $table, $delete) {
      private array $values = [];
      private array $conditions = [];
      public function __construct(private KweMembershipConnection $connection, private string $table, private bool $delete) {}
      public function fields(array $values) { $this->values = $values; return $this; }
      public function condition($field, $value, $operator = '=') { $this->conditions[$field] = $value; return $this; }
      public function execute() { return $this->connection->mutate($this->table, $this->delete, $this->values, $this->conditions); }
    };
  }

  public function mutate(string $table, bool $delete, array $values, array $conditions): int {
    if ($table !== 'queue') {
      throw new RuntimeException('Unexpected mutation table.');
    }
    $args = [];
    $where = [];
    foreach ($conditions as $field => $value) {
      $where[] = '"' . $this->escapeTable($field) . '" = :where_' . $field;
      $args[':where_' . $field] = $value;
    }
    $set = [];
    foreach ($values as $field => $value) {
      $set[] = '"' . $this->escapeTable($field) . '" = :set_' . $field;
      $args[':set_' . $field] = $value;
    }
    $sql = $delete ? 'DELETE FROM queue' : 'UPDATE queue SET ' . implode(', ', $set);
    $this->executeSql($sql . ' WHERE ' . implode(' AND ', $where), $args);
    $this->writes[] = ['operation' => $delete ? 'delete' : 'update', 'values' => $values, 'conditions' => $conditions];
    return $this->memory->changes();
  }

  public function rows(): array {
    $result = $this->memory->query('SELECT item_id, name, data, expire, created FROM queue ORDER BY item_id');
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
    return $rows;
  }

  public static function open(array &$connection_options = []) { throw new LogicException('No site connection allowed.'); }
  public function upsert($table, array $options = []) { throw new LogicException('No upserts allowed.'); }
  public function driver() { return 'membership_test'; }
  public function databaseType() { return 'sqlite'; }
  public function createDatabase($database) { throw new LogicException('No persistent database allowed.'); }
  public function mapConditionOperator($operator) { return NULL; }
}

final class KweMembershipNode extends Node {
  public function __construct(private int $testId) {}
  public function id() { return $this->testId; }
}

final class KweMembershipStorage {
  public array $queryCalls = [];
  public function __construct(public array $ids = [42]) {}
  public function getQuery() {
    return new class($this) {
      public function __construct(private KweMembershipStorage $storage) {}
      public function accessCheck($value) { $this->storage->queryCalls[] = ['accessCheck', $value]; return $this; }
      public function condition($field, $value) { $this->storage->queryCalls[] = ['condition', $field, $value]; return $this; }
      public function execute() { return $this->storage->ids; }
    };
  }
}

final class KweMembershipManager extends EntityTypeManager {
  public function __construct(private KweMembershipStorage $storage) {}
  public function getStorage($entity_type_id) {
    if ($entity_type_id !== 'node') { throw new LogicException('Unexpected entity storage.'); }
    return $this->storage;
  }
}

final class KweMembershipContentLock extends ContentLock {
  public function __construct() {}
}

final class KweMembershipLockState {
  public array $owners = [];
}

/** Separate owners share per-name state, like concurrent Drupal requests. */
final class KweMembershipLock extends NullLockBackend {
  public array $acquisitions = [];
  public array $waits = [];
  public array $releases = [];
  public int $unownedReleases = 0;
  public ?Closure $duringWait = NULL;
  public function __construct(public readonly KweMembershipLockState $state, public readonly string $owner) {}
  public function acquire($name, $timeout = 30.0) {
    $this->acquisitions[] = [$name, $timeout];
    if (isset($this->state->owners[$name]) && $this->state->owners[$name] !== $this->owner) { return FALSE; }
    $this->state->owners[$name] = $this->owner;
    return TRUE;
  }
  public function wait($name, $delay = 30) {
    $this->waits[] = [$name, $delay];
    $this->duringWait?->__invoke($name);
    return isset($this->state->owners[$name]);
  }
  public function release($name) {
    $this->releases[] = $name;
    if (($this->state->owners[$name] ?? NULL) === $this->owner) { unset($this->state->owners[$name]); }
    else { $this->unownedReleases++; }
  }
}

final class KweMembershipLogger extends AbstractLogger {
  public array $records = [];
  public function log($level, string|Stringable $message, array $context = []): void { $this->records[] = [$level, $message, $context]; }
}

final class KweMembershipMemory extends Memory {
  public int $claims = 0;
  public int $releases = 0;
  public function claimItem($lease_time = 30) { $this->claims++; return parent::claimItem($lease_time); }
  public function releaseItem($item) { $this->releases++; return parent::releaseItem($item); }
  public function snapshot(): array { return array_map(static fn ($item) => clone $item, $this->queue); }
}

final class KweMembershipMemoryFactory {
  public array $names = [];
  public function __construct(public KweMembershipMemory $queue) {}
  public function get($name) { $this->names[] = $name; return $this->queue; }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) { throw new RuntimeException($message); }
  $checks++;
};
$container = new ContainerBuilder();
$container->set('datetime.time', new Time());
Drupal::setContainer($container);
$make_worker = static function (QueueFactory $factory, ?KweMembershipLock $lock = NULL, ?KweMembershipStorage $storage = NULL, ?KweMembershipLogger $logger = NULL): array {
  $lock ??= new KweMembershipLock(new KweMembershipLockState(), 'producer');
  $storage ??= new KweMembershipStorage();
  $logger ??= new KweMembershipLogger();
  $client = new Client(['handler' => static function () { throw new LogicException('No HTTP allowed in membership tests.'); }]);
  $worker = new KweQueueWorker(new KweMembershipManager($storage), new KweMembershipContentLock(), $factory, new AccountProxy(new EventDispatcher()), $client, $logger, new Time(), $lock);
  return [$worker, $lock, $storage, $logger];
};
$fixture = static function (?KweMembershipStorage $storage = NULL) use ($make_worker): array {
  $connection = new KweMembershipConnection();
  $services = new ContainerBuilder();
  $services->set('queue.database', new KweQueueDatabaseFactory($connection));
  $factory = new QueueFactory(new Settings([]), $services);
  return [$connection, $factory, $factory->get(KweQueueWorker::QUEUE_NAME), ...$make_worker($factory, storage: $storage)];
};

// Native SQL predicates, payload types, scope, and read-only membership.
[$connection, $factory, $queue] = $fixture();
$integer_item = $queue->createItem(42);
$legacy_item = $queue->createItem('43');
$queue->createItem(44);
$claimed = $queue->claimItem(600);
$other_queue = (new KweQueueDatabaseFactory($connection))->get('other_queue');
$other_queue->createItem(42);
$before = $connection->rows();
$writes = count($connection->writes);
$check(!$queue->hasUnclaimedNode(42), 'Claimed and other-queue items do not suppress a follow-up.');
$check($queue->hasUnclaimedNode(43) && $queue->hasUnclaimedNode(44) && !$queue->hasUnclaimedNode(45), 'Membership recognizes available legacy string and integer IDs exactly.');
$check($queue->getUnclaimedNodeIds() === [43, 44], 'Bulk membership includes only available IDs of this queue.');
$check($connection->rows() === $before && count($connection->writes) === $writes, 'Membership reads leave every item and lease unchanged.');
$sql = $connection->queries[count($connection->queries) - 2];
$check((bool) preg_match('/FROM\\s+\\{queue\\} "q"/', $sql['sql']), 'Native SELECT uses the Core queue table and alias.');
$check(str_contains($sql['sql'], '"name" =') && str_contains($sql['sql'], '"expire" =') && str_contains($sql['sql'], '"data" IN') && str_contains($sql['sql'], 'LIMIT 1 OFFSET 0'), 'Targeted native SQL scopes queue name, available lease, exact payload, and one result.');
$check(array_values($sql['args']) === [KweQueueWorker::QUEUE_NAME, 0, serialize(45), serialize('45')], 'Targeted lookup binds serialized integer and canonical legacy string IDs.');
$check($connection->rows()[0]['data'] === serialize(42) && $connection->rows()[1]['data'] === serialize('43'), 'Core insertion serializes integers and strings without conversion.');

foreach ([42, '43', '043', '0', 0, -1, FALSE, ['42'], (object) ['id' => 42]] as $payload) { $queue->createItem($payload); }
$connection->insertValues('queue', ['name' => KweQueueWorker::QUEUE_NAME, 'data' => 'invalid serialization', 'created' => 1]);
$check($queue->getUnclaimedNodeIds() === [43, 44, 42], 'Bulk lookup deduplicates valid positive IDs and ignores malformed/noncanonical payloads.');

// Available deduplication and the claimed-item follow-up boundary.
[$connection, $factory, $queue, $worker, $lock] = $fixture();
$queue->createItem(42);
$old_claim = $queue->claimItem(600);
$old_lease = $connection->rows()[0]['expire'];
$connection->queries = [];
$connection->writes = [];
$protected_reads = 0;
$protected_inserts = 0;
$connection->afterSelect = static function () use ($check, $lock, &$protected_reads): void {
  $check(($lock->state->owners['ddbgo_cj:enqueue:42'] ?? NULL) === $lock->owner, 'Membership is checked while the producer owns the node lock.');
  $protected_reads++;
};
$connection->beforeInsert = static function () use ($check, $lock, &$protected_inserts): void {
  $check(($lock->state->owners['ddbgo_cj:enqueue:42'] ?? NULL) === $lock->owner, 'Insertion uses the same uninterrupted producer lock as membership.');
  $protected_inserts++;
};
$worker->enqueueNode(new KweMembershipNode(42));
$worker->enqueueNode(new KweMembershipNode(42));
$connection->afterSelect = NULL;
$connection->beforeInsert = NULL;
$check($protected_reads === 2 && $protected_inserts === 1, 'Both producers recheck under the lock and only the missing follow-up is inserted.');
$check(count($connection->rows()) === 2 && $connection->rows()[0]['expire'] === $old_lease && $connection->rows()[1]['expire'] === 0, 'Two saves during a claimed item create exactly one available follow-up and preserve its old lease.');
$check(count($connection->writes) === 1 && $connection->writes[0]['operation'] === 'insert', 'Optimized enqueue never claims or releases existing items.');
$check(count($connection->queries) === 2 && array_reduce($connection->queries, static fn ($valid, $query) => $valid && str_contains($query['sql'], '"data" IN'), TRUE), 'Each optimized hook executes one targeted lookup instead of a queue scan.');
$queue->deleteItem($old_claim);
$check(count($connection->rows()) === 1 && $queue->hasUnclaimedNode(42), 'Deleting completed claimed work leaves the newer follow-up available.');
$check($lock->releases === ['ddbgo_cj:enqueue:42', 'ddbgo_cj:enqueue:42'] && $lock->unownedReleases === 0 && $lock->state->owners === [], 'Every successful producer releases only its own per-node lock.');

// Daily snapshot is one bulk read; every missing ID is rechecked under the lock.
[$connection, $factory, $queue, $worker, $lock, $storage] = $fixture(new KweMembershipStorage([42, 43, 44]));
$queue->createItem(44);
$state = $lock->state;
$other_lock = new KweMembershipLock($state, 'other-producer');
[$other_worker] = $make_worker($factory, $other_lock);
$snapshot_inserted = FALSE;
$connection->afterSelect = static function ($sql) use (&$snapshot_inserted, $other_worker): void {
  if (!$snapshot_inserted && str_starts_with($sql, 'SELECT "q"."data"')) {
    $snapshot_inserted = TRUE;
    $other_worker->enqueueNode(new KweMembershipNode(42));
  }
};
$connection->queries = [];
$connection->writes = [];
$worker->enqueuePublishedNodes();
$connection->afterSelect = NULL;
$check($queue->getUnclaimedNodeIds() === [44, 42, 43] && count($connection->rows()) === 3, 'Daily enqueue rechecks snapshot-missing IDs and preserves a concurrently inserted refresh.');
$bulk_queries = array_filter($connection->queries, static fn ($query) => str_starts_with($query['sql'], 'SELECT "q"."data"'));
$check(count($bulk_queries) === 2, 'Daily uses one bulk snapshot (plus the explicit final assertion lookup).');
$check(count($connection->writes) === 2 && $lock->releases === ['ddbgo_cj:enqueue:42', 'ddbgo_cj:enqueue:43'], 'Snapshot-present nodes avoid work; missing nodes use the same producer locks as hooks.');
$check($storage->queryCalls === [['accessCheck', FALSE], ['condition', 'status', 1], ['condition', 'type', 'kwe']], 'Daily discovery still selects published KWE nodes without changing access semantics.');

[$connection, $factory, $queue, $worker, $lock] = $fixture(new KweMembershipStorage([42, 42]));
$connection->failNextInsert = TRUE;
$worker->enqueuePublishedNodes();
$check(count($connection->rows()) === 1 && $queue->hasUnclaimedNode(42) && $lock->state->owners === [], 'Daily discovery does not mark a failed insertion present: a repeated ID retries successfully.');

// A waiter must reacquire and recheck after the competing producer finishes.
[$connection, $factory, $queue] = $fixture();
$state = new KweMembershipLockState();
$first = new KweMembershipLock($state, 'first');
$second = new KweMembershipLock($state, 'second');
$first->acquire('ddbgo_cj:enqueue:42');
[$worker] = $make_worker($factory, $second);
$second->duringWait = static function ($name) use ($first, $queue): void {
  $queue->createItem(42);
  $first->release($name);
};
$worker->enqueueNode(new KweMembershipNode(42));
$check(count($connection->rows()) === 1 && count($second->acquisitions) === 2 && $second->waits === [['ddbgo_cj:enqueue:42', 1]], 'Waiter reacquires after bounded waiting and detects the completed competing enqueue.');
$check($second->unownedReleases === 0 && $state->owners === [], 'Contention recovery releases only the newly acquired lock.');

// Sustained contention preserves at-least-once work without releasing the owner.
[$connection, $factory, $queue] = $fixture();
$state = new KweMembershipLockState();
$owner = new KweMembershipLock($state, 'owner');
$contender = new KweMembershipLock($state, 'contender');
$owner->acquire('ddbgo_cj:enqueue:42');
$queue->createItem(42);
[$worker, , , $logger] = $make_worker($factory, $contender);
$worker->enqueueNode(new KweMembershipNode(42));
$check(count($connection->rows()) === 2 && $state->owners['ddbgo_cj:enqueue:42'] === 'owner' && $contender->releases === [], 'Stuck-owner fallback accepts a safe duplicate and never releases another producer lock.');
$check($connection->queries === [], 'Persistent contention does not perform an unprotected membership read.');
$check(count($logger->records) === 1 && $logger->records[0][0] === 'warning', 'Lost deduplication emits one warning.');
$worker->enqueueNode(new KweMembershipNode(43));
$check($queue->hasUnclaimedNode(43) && $state->owners === ['ddbgo_cj:enqueue:42' => 'owner'], 'An unrelated node remains independently enqueueable during contention.');

// Exceptions and failed inserts never retain locks or invent queued membership.
[$connection, $factory, $queue, $worker, $lock] = $fixture();
$connection->failNextSelect = TRUE;
try { $worker->enqueueNode(new KweMembershipNode(42)); throw new LogicException('Expected membership failure.'); }
catch (RuntimeException $exception) { $check($exception->getMessage() === 'Synthetic SELECT failure', 'A real membership failure propagates.'); }
$check($lock->state->owners === [] && $lock->unownedReleases === 0, 'Membership failure releases the acquired producer lock.');
$connection->failNextInsert = TRUE;
$worker->enqueueNode(new KweMembershipNode(42));
$check($connection->rows() === [] && $lock->state->owners === [], 'Failed createItem does not leave a phantom membership or retained lock.');
$worker->enqueueNode(new KweMembershipNode(42));
$check(count($connection->rows()) === 1, 'A subsequent enqueue retries a failed insertion.');
$connection->beforeInsert = static function (): void { throw new RuntimeException('Synthetic insert failure'); };
try { $worker->enqueueNode(new KweMembershipNode(43)); throw new LogicException('Expected insertion failure.'); }
catch (RuntimeException $exception) { $check($exception->getMessage() === 'Synthetic insert failure', 'A real insertion failure propagates.'); }
$connection->beforeInsert = NULL;
$check($lock->state->owners === [] && $lock->unownedReleases === 0, 'Insertion exception releases the acquired producer lock.');

// Missing queue tables are empty; only Core createItem creates them on demand.
$connection = new KweMembershipConnection(FALSE);
$queue = new KweDatabaseQueue(KweQueueWorker::QUEUE_NAME, $connection);
$check(!$queue->hasUnclaimedNode(42) && $queue->getUnclaimedNodeIds() === [] && !$connection->tableExists(), 'Read-only membership tolerates a missing queue table without creating it.');
$queue->createItem(42);
$check($connection->tableExists() && $queue->hasUnclaimedNode(42), 'Core insertion still creates a missing queue table on demand.');
$connection->failNextSelect = TRUE;
try { $queue->getUnclaimedNodeIds(); throw new LogicException('Expected bulk membership failure.'); }
catch (RuntimeException $exception) { $check($exception->getMessage() === 'Synthetic SELECT failure', 'Bulk membership does not swallow failures when the table exists.'); }

// Factory scope, service-provider preservation, and existing backend selection.
$connection = new KweMembershipConnection();
$database_factory = new KweQueueDatabaseFactory($connection);
$check($database_factory->get(KweQueueWorker::QUEUE_NAME) instanceof KweDatabaseQueue && get_class($database_factory->get('unrelated')) === DatabaseQueue::class, 'Only the KWE queue receives the membership subclass.');
$database_factory->get('unrelated')->createItem(42);
$check(!$database_factory->get(KweQueueWorker::QUEUE_NAME)->hasUnclaimedNode(42), 'Factory queues share their own connection but preserve name isolation.');
$provider = new DdbgoCjServiceProvider();
$services = new ContainerBuilder();
$definition = new Definition(QueueDatabaseFactory::class, [new Reference('database')]);
$definition->addTag('queue_factory');
$services->setDefinition('queue.database', $definition);
$provider->alter($services);
$check($definition->getClass() === KweQueueDatabaseFactory::class && (string) $definition->getArgument(0) === 'database' && $definition->hasTag('queue_factory'), 'Provider replaces only the native class while preserving constructor wiring and tags.');
foreach ([KweMembershipMemoryFactory::class, KweQueueDatabaseFactory::class] as $override) {
  $services = new ContainerBuilder();
  $services->register('queue.database', $override);
  $provider->alter($services);
  $check($services->getDefinition('queue.database')->getClass() === $override, 'Provider preserves a backend override or already altered definition.');
}
$services = new ContainerBuilder();
$services->register('custom.factory', KweMembershipMemoryFactory::class);
$services->setAlias('queue.database', 'custom.factory');
$provider->alter($services);
$check((string) $services->getAlias('queue.database') === 'custom.factory', 'Provider preserves an aliased backend override.');
$services = new ContainerBuilder();
$provider->alter($services);
$check(!$services->has('queue.database'), 'Provider does not introduce an absent queue service.');

foreach (['queue_service_' . KweQueueWorker::QUEUE_NAME, 'queue_default'] as $setting) {
  $connection = new KweMembershipConnection();
  $memory = new KweMembershipMemory(KweQueueWorker::QUEUE_NAME);
  $custom_factory = new KweMembershipMemoryFactory($memory);
  $services = new ContainerBuilder();
  $services->set('queue.database', new KweQueueDatabaseFactory($connection));
  $services->set('custom.factory', $custom_factory);
  $factory = new QueueFactory(new Settings([$setting => 'custom.factory']), $services);
  [$worker] = $make_worker($factory);
  $memory->createItem(42);
  $claimed = $memory->claimItem(600);
  $old_lease = $claimed->expire;
  $memory->claims = 0;
  $worker->enqueueNode(new KweMembershipNode(42));
  $worker->enqueueNode(new KweMembershipNode(42));
  $snapshot = $memory->snapshot();
  $check(count($snapshot) === 2 && $snapshot[$claimed->item_id]->expire === $old_lease && $memory->claims > 0 && $memory->releases === 1, 'Alternate backend retains the legacy available-item scan and claimed follow-up semantics.');
  $check($connection->queries === [] && $connection->writes === [] && $custom_factory->names === [KweQueueWorker::QUEUE_NAME], 'Named/default backend overrides bypass database membership completely.');
}

$connection = new KweMembershipConnection();
$services = new ContainerBuilder();
$services->set('queue.database', new QueueDatabaseFactory($connection));
$factory = new QueueFactory(new Settings([]), $services);
[$worker] = $make_worker($factory);
$worker->enqueueNode(new KweMembershipNode(42));
$worker->enqueueNode(new KweMembershipNode(42));
$check(get_class($factory->get(KweQueueWorker::QUEUE_NAME)) === DatabaseQueue::class && count($connection->rows()) === 1, 'An old compiled native factory still works through the legacy fallback.');
$check((new ReflectionMethod(KweQueueWorker::class, '__construct'))->getNumberOfRequiredParameters() === 8, 'Worker keeps the existing eight-argument constructor contract.');

echo "PASS: $checks isolated KWE queue membership checks. No site data changed.\n";
