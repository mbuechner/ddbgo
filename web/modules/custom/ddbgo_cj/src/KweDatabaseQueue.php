<?php

declare(strict_types=1);

namespace Drupal\ddbgo_cj;

use Drupal\Core\Queue\DatabaseQueue;

/**
 * Provides read-only membership checks for the database-backed KWE queue.
 */
class KweDatabaseQueue extends DatabaseQueue {

  /**
   * Checks for an available refresh, including legacy string node IDs.
   */
  public function hasUnclaimedNode(int $node_id): bool {
    try {
      return $this->connection->select(static::TABLE_NAME, 'q')
        ->fields('q', ['item_id'])
        ->condition('name', $this->name)
        ->condition('expire', 0)
        ->condition('data', [serialize($node_id), serialize((string) $node_id)], 'IN')
        ->range(0, 1)
        ->execute()->fetchField() !== FALSE;
    }
    catch (\Exception $exception) {
      $this->catchException($exception);
      return FALSE;
    }
  }

  /**
   * Returns available node IDs without acquiring or releasing queue leases.
   *
   * @return int[]
   *   Unique node IDs.
   */
  public function getUnclaimedNodeIds(): array {
    try {
      $data = $this->connection->select(static::TABLE_NAME, 'q')
        ->fields('q', ['data'])
        ->condition('name', $this->name)
        ->condition('expire', 0)
        ->execute()->fetchCol();
    }
    catch (\Exception $exception) {
      $this->catchException($exception);
      return [];
    }

    $node_ids = [];
    foreach ($data as $payload) {
      $node_id = @unserialize($payload, ['allowed_classes' => FALSE]);
      if (is_int($node_id) || (is_string($node_id) && ctype_digit($node_id) && (string) (int) $node_id === $node_id)) {
        if ((int) $node_id > 0) {
          $node_ids[(int) $node_id] = (int) $node_id;
        }
      }
    }
    return array_values($node_ids);
  }

}
