<?php

/**
 * @file
 * Run with drush php:script followed by this file's path.
 *
 * Uses randomly named fixture queues inside a rolled-back transaction. Does
 * not process real queue items, call the DDB API or save site content/config.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ddbgo_cj\KweDatabaseQueue;
use Drupal\ddbgo_cj\KweQueueDatabaseFactory;
use Drupal\ddbgo_cj\KweQueueWorker;
use Drupal\node\Entity\Node;

final class KweDatabaseTestFactory extends QueueFactory {
  public function __construct(private QueueInterface $queue) {}
  public function get($name, $reliable = FALSE) {
    if ($name !== KweQueueWorker::QUEUE_NAME) {
      throw new RuntimeException('Unexpected queue name.');
    }
    return $this->queue;
  }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$connection = Drupal::database();
$check($connection->schema()->tableExists('queue'), 'The native test requires an existing queue table.');
$check(Drupal::service('queue.database') instanceof KweQueueDatabaseFactory, 'The rebuilt container uses the KWE-aware database factory.');
$check(Drupal::queue(KweQueueWorker::QUEUE_NAME) instanceof KweDatabaseQueue, 'The configured KWE queue uses the optimized backend.');
$check(get_class(Drupal::queue('ddbgo_cj_factory_test')) === DatabaseQueue::class, 'Other queue names keep the core database backend.');

$fixture_name = 'ddbgo_cj_test_' . bin2hex(random_bytes(12));
$queue = new KweDatabaseQueue($fixture_name, $connection);
$other_queue = new DatabaseQueue($fixture_name . '_other', $connection);
$transaction = $connection->startTransaction();
$measure = static function (Closure $callback): array {
  Database::startLog('kwe_membership_native');
  try {
    $value = $callback();
  }
  finally {
    $queries = Database::getLog('kwe_membership_native');
  }
  return [$value, $queries];
};
$worker = new KweQueueWorker(
  Drupal::entityTypeManager(), Drupal::service('content_lock'),
  new KweDatabaseTestFactory($queue), Drupal::currentUser(),
  Drupal::httpClient(), new \Psr\Log\NullLogger(), Drupal::time(),
  Drupal::lock(),
);
$node = Node::create(['type' => 'kwe', 'nid' => 42]);

try {
  $claimed_id = $queue->createItem(42);
  $claimed = $queue->claimItem(600);
  $lease = $connection->select('queue', 'q')->fields('q', ['expire'])
    ->condition('item_id', $claimed_id)->execute()->fetchField();
  $other_queue->createItem(42);
  $queue->createItem('43');
  $check(!$queue->hasUnclaimedNode(42), 'Claimed and foreign-queue items do not suppress a new refresh.');
  $check($queue->hasUnclaimedNode(43), 'Legacy serialized string IDs are recognized by the native driver.');

  $worker->enqueueNode($node);
  $worker->enqueueNode($node);
  $check($queue->numberOfItems() === 3, 'A claimed node receives exactly one available follow-up.');
  $check($connection->select('queue', 'q')->fields('q', ['expire'])
    ->condition('item_id', $claimed_id)->execute()->fetchField() === $lease, 'Enqueue preserves the existing lease byte for byte.');
  $queue->deleteItem($claimed);
  $check($queue->hasUnclaimedNode(42), 'Deleting completed work preserves its newer follow-up.');

  foreach ([10, 2000] as $size) {
    $queue->deleteQueue();
    $insert = $connection->insert('queue')->fields(['name', 'data', 'expire', 'created']);
    for ($id = 1; $id <= $size; $id++) {
      $insert->values([$fixture_name, serialize($id), 0, Drupal::time()->getCurrentTime()]);
    }
    $insert->execute();
    [$present, $queries] = $measure(static fn () => $queue->hasUnclaimedNode($size));
    $check($present && count($queries) === 1 && preg_match('/^SELECT\b/i', $queries[0]['query']), "Membership uses one read at queue size $size.");
    [$missing, $queries] = $measure(static fn () => $queue->hasUnclaimedNode($size + 1));
    $check(!$missing && count($queries) === 1, "Missing membership uses one read at queue size $size.");
    [$ids, $queries] = $measure(static fn () => $queue->getUnclaimedNodeIds());
    sort($ids);
    $check($ids === range(1, $size) && count($queries) === 1, "The bulk snapshot uses one read at queue size $size.");
    $node->set('nid', $size);
    [, $queries] = $measure(static fn () => $worker->enqueueNode($node));
    $queue_queries = array_values(array_filter($queries, static fn ($entry) => str_contains($entry['query'], 'queue')));
    $check(count($queue_queries) === 1 && preg_match('/^SELECT\b/i', $queue_queries[0]['query']), "Saving an already queued node does not write queue leases at size $size.");
  }
}
finally {
  $transaction->rollBack();
}
$check($queue->numberOfItems() === 0 && $other_queue->numberOfItems() === 0, 'All fixture rows were rolled back.');
echo "PASS: $checks native KWE database checks. Fixture queues rolled back; no site content changed.\n";
