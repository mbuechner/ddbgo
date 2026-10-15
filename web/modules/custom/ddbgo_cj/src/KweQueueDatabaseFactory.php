<?php

declare(strict_types=1);

namespace Drupal\ddbgo_cj;

use Drupal\Core\Queue\QueueDatabaseFactory;

/**
 * Adds membership support only to the KWE database queue.
 */
class KweQueueDatabaseFactory extends QueueDatabaseFactory {

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    if ($name === KweQueueWorker::QUEUE_NAME) {
      return new KweDatabaseQueue($name, $this->connection);
    }
    return parent::get($name);
  }

}
