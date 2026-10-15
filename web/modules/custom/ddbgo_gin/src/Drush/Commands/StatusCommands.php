<?php

namespace Drupal\ddbgo_gin\Drush\Commands;

use Drupal\ddbgo_gin\Status\StatusMigration;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Deployment checks; the data copy itself belongs to update 11002.
 */
final class StatusCommands extends DrushCommands {

  #[CLI\Command(name: 'ddbgo:status-check')]
  #[CLI\Option(name: 'complete', description: 'Require every legacy value to be copied.')]
  public function check(array $options = ['complete' => FALSE]): void {
    $migration = StatusMigration::create();
    $orphans = $migration->orphanedTargetTables();
    if ($orphans) {
      $this->logger()->warning('Status tables exist without field configuration, possibly after restoring a backup. Run ddbgo:status-archive-orphans for a read-only recovery plan.');
    }
    $report = $migration->audit();
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $migration->assertClean($report, (bool) $options['complete']);
    if ($orphans) {
      throw new \RuntimeException('Unowned status tables must be resolved before migration.');
    }
  }

  #[CLI\Command(name: 'ddbgo:status-archive-orphans')]
  #[CLI\Option(name: 'execute', description: 'Rename unowned tables to verified archives; default is a read-only plan.')]
  public function archiveOrphans(array $options = ['execute' => FALSE]): void {
    $plan = StatusMigration::create()->archiveOrphanedTargets((bool) $options['execute']);
    $this->output()->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  #[CLI\Command(name: 'ddbgo:status-finish')]
  public function finish(): void {
    if (!\Drupal::state()->get('ddbgo_gin.status_active')) {
      throw new \RuntimeException('Import and verify the new status configuration first.');
    }
    if (!\Drupal::state()->get('ddbgo_gin.status_migrating')) {
      $this->output()->writeln('Status deployment already finished.');
      return;
    }
    StatusMigration::create()->assertClean(StatusMigration::create()->audit(), TRUE);
    foreach (['aggregator', 'bestand', 'suche_kwe'] as $id) {
      $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($id);
      if (!$index || !$index->status() || $index->getField('field_status')?->getPropertyPath() !== \Drupal\ddbgo_gin\Status\RecordStatus::FIELD
        || $index->getTrackerInstance()->getRemainingItemsCount()) {
        throw new \RuntimeException('Search index not ready: ' . $id);
      }
    }
    \Drupal::state()->delete('ddbgo_gin.status_migrating');
    $this->output()->writeln('Status verified; node writes released. Maintenance mode remains enabled.');
  }

}
