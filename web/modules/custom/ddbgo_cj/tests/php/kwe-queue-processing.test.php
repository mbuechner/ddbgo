<?php

/**
 * @file
 * Run with drush php:script followed by this file's path.
 *
 * Real DatabaseQueue claims, delayed retries and garbage collection operate on
 * random fixture queues in a rolled-back transaction. Nodes and HTTP responses
 * are synthetic: no real queue work, API requests, or content/config saves.
 */

declare(strict_types=1);

use Drupal\Component\Datetime\Time;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Queue\QueueFactory;
use Drupal\content_lock\ContentLock\ContentLock;
use Drupal\ddbgo_cj\KweDatabaseQueue;
use Drupal\ddbgo_cj\KweQueueWorker;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

final class KweProcessingTime extends Time {
  // Historical timestamps keep Core's global GC away from live queue leases.
  public int $now = 10000;
  public function getCurrentTime() { return $this->now; }
  public function getRequestTime() { return $this->now; }
}

final class KweProcessingQueue extends KweDatabaseQueue {
  public ?Closure $beforeDelete = NULL;
  public int $claims = 0;
  public function claimItem($lease_time = 30) { $this->claims++; return parent::claimItem($lease_time); }
  public function deleteItem($item) {
    $this->beforeDelete?->__invoke($item);
    parent::deleteItem($item);
  }
}

final class KweProcessingFactory extends QueueFactory {
  public function __construct(private KweProcessingQueue $queue) {}
  public function get($name, $reliable = FALSE) {
    if ($name !== KweQueueWorker::QUEUE_NAME) { throw new LogicException('Unexpected queue name.'); }
    return $this->queue;
  }
}

final class KweProcessingNode extends Node {
  public int $saves = 0;
  public function __construct(int $id) {
    parent::__construct([], 'node', 'kwe');
    $this->set('type', 'kwe');
    $this->set('nid', $id);
    $this->set('field_ddburi', 'https://www.deutsche-digitale-bibliothek.de/organization/ORG_A');
    $this->set('field_email', 'old@example.invalid');
  }
  public function save() { $this->saves++; return 2; }
}

final class KweProcessingStorage {
  public function __construct(public array $nodes) {}
  public function load($id) { return $this->nodes[$id] ?? NULL; }
  public function resetCache(?array $ids = NULL) {}
}

final class KweProcessingManager extends EntityTypeManager {
  public function __construct(private KweProcessingStorage $storage) {}
  public function getStorage($entity_type_id) {
    if ($entity_type_id !== 'node') { throw new LogicException('Unexpected storage.'); }
    return $this->storage;
  }
}

final class KweProcessingContentLock extends ContentLock {
  public function __construct() {}
  public function fetchLock(\Drupal\Core\Entity\EntityInterface $entity, ?string $form_op = NULL, bool $include_stale_locks = FALSE): object|false { return FALSE; }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) { throw new RuntimeException($message); }
  $checks++;
};
$connection = Drupal::database();
$check($connection->schema()->tableExists('queue'), 'The fixture requires an existing queue table.');
$original_time = Drupal::time();
$time = new KweProcessingTime();
$fixture_name = 'ddbgo_cj_processing_' . bin2hex(random_bytes(12));
$queue = new KweProcessingQueue($fixture_name, $connection);
$transaction = $connection->startTransaction();
$nodes = [2000000001 => new KweProcessingNode(2000000001), 2000000002 => new KweProcessingNode(2000000002)];
$storage = new KweProcessingStorage($nodes);
$factory = new KweProcessingFactory($queue);
$make_worker = static function (Client $client) use ($storage, $factory, $time): KweQueueWorker {
  return new KweQueueWorker(new KweProcessingManager($storage), new KweProcessingContentLock(), $factory,
    Drupal::currentUser(), $client, new NullLogger(), $time, Drupal::lock());
};
$lease = static fn ($id) => (int) $connection->select('queue', 'q')->fields('q', ['expire'])
  ->condition('item_id', $id)->execute()->fetchField();

try {
  Drupal::getContainer()->set('datetime.time', $time);
  $requests = 0;
  $client = new Client(['handler' => HandlerStack::create(static function () use (&$requests, $time, $queue): object {
    if (++$requests === 1) {
      // Simulate preceding work exceeding a lease; pending work must remain
      // unclaimed and be picked up fresh by the next bounded queue run.
      $time->now += 601;
    }
    else {
      // Core GC uses a later cron request time, as in the reported race.
      $queue->garbageCollection();
    }
    return Create::promiseFor(new Response(200, [], '<organization><email>org-a@example.invalid</email></organization>'));
  })]);
  $worker = $make_worker($client);
  $editor = $make_worker(new Client(['handler' => static function () { throw new LogicException('No editor HTTP allowed.'); }]));
  $first_id = $queue->createItem(2000000001);
  $later_id = $queue->createItem(2000000002);
  $worker->processQueue();
  $check($requests === 1 && $queue->numberOfItems() === 1 && $lease($later_id) === 0, 'Later work stays unclaimed when the run budget expires.');
  $queue->beforeDelete = static function ($item) use ($check, $lease, $editor, $nodes, $time): void {
    if ((int) $item->data !== 2000000002) { return; }
    $check($lease($item->item_id) > $time->now, 'A later run starts a fresh lease that survives Core garbage collection.');
    $check(Drupal::lock()->lockMayBeAvailable('content_lock:node:2000000002'), 'The editor race occurs after the synchronization semaphore was released.');
    $nodes[2000000002]->set('field_ddburi', 'https://www.deutsche-digitale-bibliothek.de/organization/ORG_B');
    $editor->enqueueNode($nodes[2000000002]);
    // Keep the new refresh for the following cron rather than processing it
    // immediately; this makes its survival explicit in the fixture.
    $time->now += 301;
  };
  $worker->processQueue();
  $check($queue->numberOfItems() === 1 && $queue->hasUnclaimedNode(2000000002), 'Deleting older work preserves the refresh requested by the concurrent editor.');
  $check($nodes[2000000002]->get('field_ddburi')->value === 'https://www.deutsche-digitale-bibliothek.de/organization/ORG_B'
    && $nodes[2000000002]->get('field_email')->value === 'org-a@example.invalid', 'The fixture reproduces the A-to-B edit boundary with an outstanding B refresh.');

  $queue->beforeDelete = NULL;
  $queue->deleteQueue();
  $time->now = 20000;
  $failed_id = $queue->createItem(2000000001);
  $available_id = $queue->createItem(2000000002);
  $requests = 0;
  $client = new Client(['handler' => HandlerStack::create(static function () use (&$requests): object {
    return Create::promiseFor(++$requests === 1 ? new Response(503) : new Response(200, [], '<organization><email>recovered@example.invalid</email></organization>'));
  })]);
  $worker = $make_worker($client);
  $worker->processQueue();
  $check($requests === 2 && $queue->numberOfItems() === 1, 'A database-backed failure is delayed while another available item still completes.');
  $check($lease($failed_id) === 20600 && !$queue->hasUnclaimedNode(2000000001), 'Retry delay is relative to the current time and prevents immediate reclamation.');
  $worker->processQueue();
  $check($requests === 2, 'A second run before expiry does not request the failed organization again.');
  $time->now = 20601;
  $queue->garbageCollection();
  $worker->processQueue();
  $check($requests === 3 && $queue->numberOfItems() === 0
    && $nodes[2000000001]->get('field_email')->value === 'recovered@example.invalid', 'A delayed failed refresh completes after the API recovers and Core GC makes it available.');
}
finally {
  Drupal::getContainer()->set('datetime.time', $original_time);
  $transaction->rollBack();
}
$check($queue->numberOfItems() === 0, 'All fixture queue rows were rolled back.');
echo "PASS: $checks native KWE processing checks. Fixture queues rolled back; no site content changed.\n";
