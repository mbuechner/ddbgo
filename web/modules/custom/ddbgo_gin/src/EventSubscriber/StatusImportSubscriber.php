<?php

namespace Drupal\ddbgo_gin\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\ddbgo_gin\Status\RecordStatus;
use Drupal\ddbgo_gin\Status\StatusMigration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses a premature cutover and schedules full search reindexing afterwards.
 */
final class StatusImportSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validate', ConfigEvents::IMPORT => 'imported'];
  }

  public function validate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $source = $importer->getStorageComparer()->getSourceStorage();
    foreach ($importer->getUnprocessedConfiguration('delete') as $name) {
      if ($name === 'field.storage.node.field_status' || preg_match('/^field\.field\.node\.(aggregator|bestand|kwe)\.field_status$/', $name)) {
        $importer->logError('The legacy status field is retained for recovery and must not be deleted.');
      }
    }
    if (\Drupal::state()->get('ddbgo_gin.status_active')) {
      return;
    }
    $switch = FALSE;
    foreach ($source->listAll() as $name) {
      if (str_starts_with($name, 'core.entity_') || str_starts_with($name, 'views.view.') || str_starts_with($name, 'search_api.index.')) {
        $switch = $switch || str_contains(json_encode($source->read($name)), RecordStatus::FIELD);
      }
    }
    if (!$switch) {
      return;
    }
    try {
      if (!\Drupal::state()->get('system.maintenance_mode')) {
        throw new \RuntimeException('Enable maintenance mode before importing status configuration: drush state:set system.maintenance_mode 1 --input-format=integer, then drush cr. Keep cron, queues and imports stopped.');
      }
      if (!\Drupal::state()->get('ddbgo_gin.status_migrating')) {
        throw new \RuntimeException('Run update 11002 with writers stopped before importing status configuration.');
      }
      $report = StatusMigration::create()->audit();
      StatusMigration::create()->assertClean($report, TRUE);
      if ($report['fingerprints'] !== \Drupal::state()->get('ddbgo_gin.status_verified')) {
        throw new \RuntimeException('Status source differs from the verified migration snapshot.');
      }
      foreach (RecordStatus::BUNDLES as $bundle) {
        $form = $source->read('core.entity_form_display.node.' . $bundle . '.default');
        if (!isset($form['content'][RecordStatus::FIELD]) || empty($form['hidden']['field_status'])) {
          throw new \RuntimeException('Import all three status form displays together.');
        }
      }
      foreach (['aggregator', 'bestand', 'suche_kwe'] as $id) {
        $index = $source->read('search_api.index.' . $id);
        if (($index['field_settings']['field_status']['property_path'] ?? '') !== RecordStatus::FIELD) {
          throw new \RuntimeException('Import all three status search indexes together.');
        }
      }
    }
    catch (\RuntimeException $e) {
      $importer->logError($e->getMessage());
    }
  }

  public function imported(ConfigImporterEvent $event): void {
    if (\Drupal::state()->get('ddbgo_gin.status_active') || !\Drupal::state()->get('ddbgo_gin.status_verified')) {
      return;
    }
    foreach (RecordStatus::BUNDLES as $bundle) {
      if (!\Drupal::config('core.entity_form_display.node.' . $bundle . '.default')->get('content.' . RecordStatus::FIELD)) {
        return;
      }
    }
    foreach (['aggregator', 'bestand', 'suche_kwe'] as $id) {
      $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($id);
      $index->reindex();
      // Config saves may already have set the per-request reindex flag. Ensure
      // every item is pending even in that case, including related KWE statuses.
      $index->getTrackerInstance()->trackAllItemsUpdated();
    }
    \Drupal::state()->set('ddbgo_gin.status_active', TRUE);
    // Old node render caches must not retain the color-only formatter.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['rendered', 'node_list']);
  }

}
