<?php

declare(strict_types=1);

namespace Drupal\ddbgo_cj;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Queue\QueueDatabaseFactory;

/**
 * Extends the core database queue factory while respecting backend overrides.
 */
class DdbgoCjServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('queue.database')) {
      $definition = $container->getDefinition('queue.database');
      if ($definition->getClass() === QueueDatabaseFactory::class) {
        $definition->setClass(KweQueueDatabaseFactory::class);
      }
    }
  }

}
