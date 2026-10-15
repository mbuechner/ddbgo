<?php

/**
 * @file
 * Run with php web/modules/custom/ddbgo_cj/tests/php/kwe-queue-worker.test.php.
 *
 * In-memory storage, locks and queues with a synthetic HTTP response: no site
 * bootstrap, database writes or network requests. Field updates exercise Core's
 * actual FieldItemList and EmailItem implementations.
 */

use Drupal\Component\Datetime\Time;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityType;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\Plugin\Field\FieldType\EmailItem;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Queue\Memory;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\content_lock\ContentLock\ContentLock;
use Drupal\ddbgo_cj\KweQueueWorker;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

$root = dirname(__DIR__, 6);
$loader = require $root . '/vendor/autoload.php';
foreach (['node' => 'web/core/modules/node/src', 'user' => 'web/core/modules/user/src', 'content_lock' => 'web/modules/contrib/content_lock/src', 'ddbgo_cj' => 'web/modules/custom/ddbgo_cj/src'] as $namespace => $path) {
  $loader->addPsr4('Drupal\\' . $namespace . '\\', $root . '/' . $path);
}
require_once $root . '/web/core/lib/Drupal.php';
require_once $root . '/web/modules/custom/ddbgo_cj/ddbgo_cj.module';

final class KweTestFieldList extends FieldItemList {
  public function __construct(mixed $values) {
    parent::__construct(new DataDefinition(['type' => 'list']));
    $this->setValue($values);
  }

  protected function createItem($offset = 0, $value = NULL) {
    $definition = new MapDataDefinition(['type' => 'field_item:email']);
    $definition->setPropertyDefinition('value', new DataDefinition(['type' => 'email']));
    $item = new EmailItem($definition, $offset, $this);
    $item->setValue($value, FALSE);
    return $item;
  }
}

final class KweTestNode extends Node {
  public int $saves = 0;
  public ?Closure $onSave = NULL;
  private array $testFields;
  public function __construct(array $fields) {
    $this->testFields = array_map(static fn ($values) => new KweTestFieldList($values), $fields);
  }
  public function id() { return 42; }
  public function bundle() { return 'kwe'; }
  public function getEntityTypeId() { return 'node'; }
  public function hasField($field_name) { return isset($this->testFields[$field_name]); }
  public function get($field_name) { return $this->testFields[$field_name]; }
  public function getEntityType() { return new EntityType(['id' => 'node']); }
  public function set($field_name, $value, $notify = TRUE) {
    $this->get($field_name)->setValue($value);
    return $this;
  }
  public function save() {
    $this->onSave?->__invoke($this);
    $this->saves++;
    ddbgo_cj_node_update($this);
    return 2;
  }
}

final class KweTestStorage {
  public ?KweTestNode $current;
  public int $freshLoads = 0;
  public ?Closure $onFreshLoad = NULL;
  private KweTestNode $cached;
  private bool $invalidated = FALSE;
  public function __construct(KweTestNode $node) {
    $this->cached = $node;
    $this->current = $node;
  }
  public function resetCache(?array $ids = NULL) { $this->invalidated = TRUE; }
  public function load($id) {
    if (!$this->invalidated) {
      return $this->cached;
    }
    $this->onFreshLoad?->__invoke();
    $this->freshLoads++;
    return $this->current;
  }
}

final class KweTestManager extends EntityTypeManager {
  public function __construct(private KweTestStorage $testStorage) {}
  public function getStorage($entity_type_id) { return $this->testStorage; }
}

final class KweTestContentLock extends ContentLock {
  public object|false $activeLock = FALSE;
  public int $releases = 0;
  public function __construct() {}
  public function fetchLock(\Drupal\Core\Entity\EntityInterface $entity, ?string $form_op = NULL, bool $include_stale_locks = FALSE): object|false {
    return $this->activeLock;
  }
  public function release(\Drupal\Core\Entity\EntityInterface $entity, ?string $form_op = NULL, ?int $uid = NULL): void {
    $this->releases++;
  }
}

final class KweTestLock extends NullLockBackend {
  public bool $occupied = FALSE;
  public int $releases = 0;
  public array $names = [];
  public function acquire($name, $timeout = 30.0) {
    $this->names[] = $name;
    if ($this->occupied) {
      return FALSE;
    }
    $this->occupied = TRUE;
    return TRUE;
  }
  public function release($name) {
    $this->occupied = FALSE;
    $this->releases++;
  }
}

final class KweTestQueueFactory extends QueueFactory {
  public function __construct(public Memory $testQueue) {}
  public function get($name, $reliable = FALSE) { return $this->testQueue; }
}

final class KweTestTime extends Time {
  public int $now = 10000;
  public function getCurrentTime() { return $this->now; }
  public function getRequestTime() { return $this->now; }
}

final class KweTestQueue extends Memory {
  public int $claims = 0;
  public int $releases = 0;
  public function claimItem($lease_time = 30) { $this->claims++; return parent::claimItem($lease_time); }
  public function releaseItem($item) { $this->releases++; return parent::releaseItem($item); }
  public function snapshot(): array { return array_map(static fn ($item) => clone $item, $this->queue); }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$make_node = static fn (array $fields = []) => new KweTestNode($fields + [
  'field_ddburi' => 'https://www.deutsche-digitale-bibliothek.de/organization/ORG',
  'field_email' => [['value' => 'old@example.invalid'], ['value' => 'extra@example.invalid']],
  'editor_notes' => 'Initial notes',
]);
$fixture = static function (?Closure $during_http = NULL, ?Closure $response = NULL, ?Time $time = NULL, bool $http_errors = TRUE) use ($make_node): array {
  $storage = new KweTestStorage($make_node());
  $content_lock = new KweTestContentLock();
  $lock = new KweTestLock();
  $queue = new KweTestQueue('kwe-regression');
  $time ??= new Time();
  $requests = 0;
  $client = new Client(['handler' => HandlerStack::create(static function ($request, $options) use ($during_http, $response, $storage, $content_lock, &$requests) {
    $requests++;
    $during_http?->__invoke($storage, $content_lock);
    return \GuzzleHttp\Promise\Create::promiseFor($response?->__invoke($request) ?? new Response(200, [], '<organization><email>new@example.invalid</email></organization>'));
  }), 'http_errors' => $http_errors]);
  $worker = new KweQueueWorker(new KweTestManager($storage), $content_lock, new KweTestQueueFactory($queue), new AccountProxy(new EventDispatcher()), $client, new NullLogger(), $time, $lock);
  $container = new ContainerBuilder();
  $container->set('ddbgo_cj.kwe_queue_worker', $worker);
  $container->set('datetime.time', $time);
  Drupal::setContainer($container);
  return [$worker, $storage, $content_lock, $lock, $queue, static function () use (&$requests): int { return $requests; }];
};

[$worker, $storage, $content_lock, $lock, $queue] = $fixture();
$queue->createItem(42);
$worker->processQueue();
$check($storage->current->get('field_email')->getValue() === [['value' => 'new@example.invalid'], ['value' => 'extra@example.invalid']], 'Changing the first email preserves subsequent deltas.');
$check($queue->numberOfItems() === 0, 'Synchronous update does not requeue its own synchronization.');
$worker->processQueue();
$check($queue->numberOfItems() === 0 && $storage->current->saves === 1, 'Native update hook leaves no self-requeued item for another queue run.');
$check($lock->names === ['content_lock:node:42'] && !$lock->occupied && $lock->releases === 1, 'Save holds and releases the Content Lock acquisition semaphore.');
$check($content_lock->releases === 0, 'The worker never releases an editor content lock.');
ddbgo_cj_node_insert($storage->current);
$check($queue->numberOfItems() === 1, 'Normal node insertion enqueues the node.');
$queue->deleteQueue();
ddbgo_cj_node_update($storage->current);
$check($queue->numberOfItems() === 1, 'Normal node update enqueues the node after synchronization.');

[$worker, $storage, , $lock] = $fixture(static function ($storage) use ($make_node): void {
  $storage->current = $make_node(['editor_notes' => 'Saved during the HTTP request']);
});
$cached = $storage->load(42);
$storage->onFreshLoad = static function () use ($check, $lock): void {
  $check($lock->occupied, 'Fresh reload is performed while the worker holds the semaphore.');
};
$check($worker->processItem(42), 'Completed edit during HTTP still permits synchronization.');
$check($storage->current->get('editor_notes')->value === 'Saved during the HTTP request' && $cached->saves === 0 && $storage->current->saves === 1, 'Fresh reload preserves concurrent editor changes and does not save a stale object.');
$check($storage->freshLoads === 1 && !$lock->occupied, 'Uncached reload occurs in the protected section.');

[$worker, $storage, $content_lock, $lock, , $requests] = $fixture();
$content_lock->activeLock = (object) ['uid' => 0];
$check(!$worker->processItem(42) && $requests() === 0 && $storage->current->saves === 0, 'Existing content lock prevents synchronization even for the worker current UID.');
$check($lock->releases === 0 && $content_lock->releases === 0, 'Rejected work does not release unowned locks.');

[$worker, $storage, $content_lock, $lock] = $fixture(static function ($storage, $content_lock): void {
  $content_lock->activeLock = (object) ['uid' => 123];
});
$check(!$worker->processItem(42) && $storage->current->saves === 0, 'Editor lock acquired during HTTP prevents saving.');
$check($lock->releases === 1 && $content_lock->activeLock->uid === 123 && $content_lock->releases === 0, 'Retry releases only the worker semaphore and preserves the editor lock.');

[$worker, $storage, , $lock] = $fixture();
$lock->occupied = TRUE;
$check(!$worker->processItem(42) && $storage->current->saves === 0 && $lock->releases === 0, 'Competing semaphore owner prevents saving and is not released.');

[$worker, $storage, , $lock, $queue] = $fixture();
$storage->current->onSave = static function () use ($check, $lock): void {
  $check(!$lock->acquire('content_lock:node:42'), 'Another request cannot acquire the editor semaphore during the save.');
  throw new RuntimeException('Synthetic save failure');
};
try {
  $worker->processItem(42);
  throw new RuntimeException('Expected save failure.');
}
catch (RuntimeException $exception) {
  $check($exception->getMessage() === 'Synthetic save failure', 'Save failure propagates.');
}
$check(!$lock->occupied && $lock->releases === 1, 'Exception releases the acquired semaphore.');
ddbgo_cj_node_update($storage->current);
$check($queue->numberOfItems() === 1, 'Failed save restores the queueing guard.');

[$worker, $storage, , $lock] = $fixture(static function ($storage) use ($make_node): void {
  $storage->current = $make_node(['field_ddburi' => 'https://www.deutsche-digitale-bibliothek.de/organization/CHANGED']);
});
$check(!$worker->processItem(42) && $storage->current->saves === 0, 'Changed organization URI retries without applying old URI data.');
$check($lock->releases === 1, 'URI-change retry releases the semaphore.');

[$worker, $storage, , $lock] = $fixture(static function ($storage): void {
  $storage->current = NULL;
});
$check($worker->processItem(42) && $lock->releases === 1, 'Deletion during HTTP completes without saving and releases the semaphore.');

[$worker, $storage] = $fixture(static function ($storage) use ($make_node): void {
  $storage->current = $make_node(['field_email' => []]);
});
$check($worker->processItem(42) && $storage->current->get('field_email')->getValue() === [['value' => 'new@example.invalid']], 'Updating an empty email list creates its first item.');

// Reservations start immediately before processing, and the run leaves later
// work available rather than consuming its lease while it waits in a batch.
$time = new KweTestTime();
[$worker, $storage, , , $queue, $requests] = $fixture(static function () use ($check, &$queue, $time): void {
  $items = $queue->snapshot();
  $check(count(array_filter($items, static fn ($item) => $item->expire !== 0)) === 1, 'Only the item currently being processed is reserved.');
  $check($items[1]->expire === 0 && $items[2]->expire === 0, 'Later items do not start their lease while the current item runs.');
  $time->now += 301;
}, time: $time);
$queue->createItem(42);
$queue->createItem(43);
$queue->createItem(44);
$worker->processQueue();
$check($requests() === 1 && $queue->numberOfItems() === 2 && $queue->claims === 1, 'A run that reaches its budget stops before reserving another item.');
$check(array_reduce($queue->snapshot(), static fn ($available, $item) => $available && $item->expire === 0, TRUE), 'Remaining work stays available for the next run.');

// A backend without delayItem must keep failures reserved until the loop ends;
// otherwise immediately releasing the first item would cause a retry loop.
[$worker, $storage, $content_lock, , $queue, $requests] = $fixture();
$content_lock->activeLock = (object) ['uid' => 123];
$queue->createItem(42);
$queue->createItem(43);
$worker->processQueue();
$check($queue->numberOfItems() === 2 && $queue->claims === 3 && $queue->releases === 2 && $requests() === 0, 'Each locked item is attempted once and released only after all available items were visited.');
$check(array_reduce($queue->snapshot(), static fn ($available, $item) => $available && $item->expire === 0, TRUE), 'Retry items on an alternate backend remain available for a later run.');

$failures = [
  'connection timeout' => static function ($request) { throw new ConnectException('Synthetic connection timeout', $request); },
  'HTTP 408' => static fn () => new Response(408),
  'HTTP 429' => static fn () => new Response(429),
  'HTTP 503' => static fn () => new Response(503),
  'invalid XML' => static fn () => new Response(200, [], '<broken'),
];
foreach ($failures as $description => $response) {
  [$worker, $storage, , , $queue, $requests] = $fixture(response: $response);
  $queue->createItem(42);
  $worker->processQueue();
  $check($queue->numberOfItems() === 1 && $storage->current->saves === 0 && $requests() === 1, "$description retains the only refresh without an immediate retry or save.");
  $check(!$worker->processItem(42), "$description reports a retryable failure to direct callers.");
}

foreach ([404, 410] as $status) {
  [$worker, $storage, , , $queue, $requests] = $fixture(response: static fn () => new Response($status));
  $queue->createItem(42);
  $worker->processQueue();
  $check($queue->numberOfItems() === 0 && $storage->current->saves === 0 && $requests() === 1, "HTTP $status completes a permanently missing organization without saving stale data.");
}

foreach ([404, 410, 429, 503] as $status) {
  [$worker, $storage, , , $queue, $requests] = $fixture(response: static fn () => new Response($status, [], '<organization><email>error@example.invalid</email></organization>'), http_errors: FALSE);
  $queue->createItem(42);
  $worker->processQueue();
  $check($queue->numberOfItems() === (in_array($status, [404, 410], TRUE) ? 0 : 1)
    && $storage->current->saves === 0 && $requests() === 1, "HTTP $status has the same terminal/retry behavior when the client disables HTTP errors.");
}

$responses = 0;
[$worker, $storage, , , $queue, $requests] = $fixture(response: static function () use (&$responses): Response {
  return ++$responses === 1 ? new Response(503) : new Response(200, [], '<organization><email>recovered@example.invalid</email></organization>');
});
$queue->createItem(42);
$worker->processQueue();
$worker->processQueue();
$check($queue->numberOfItems() === 0 && $requests() === 2 && $storage->current->get('field_email')->value === 'recovered@example.invalid', 'A retained refresh succeeds after the API recovers in a later run.');

echo "PASS: $checks KWE queue regression checks. No site content changed.\n";
