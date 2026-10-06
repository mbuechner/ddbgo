<?php

namespace Drupal\ddbgo_search\EventSubscriber;

use Drupal\search_api\Event\MappingForeignRelationshipsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tracks taxonomy labels consumed by the computed KWE sector property.
 */
final class KweSectorDependenciesSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::MAPPING_FOREIGN_RELATIONSHIPS => 'addSectorDependencies'];
  }

  /**
   * Adds the relationships Search API cannot infer from a computed property.
   */
  public function addSectorDependencies(MappingForeignRelationshipsEvent $event): void {
    $index = $event->getIndex();
    if (!$index->getProcessorIfAvailable('ddbgo_kwe_sector_label')
      || !isset($index->getDatasources()['entity:node'])) {
      return;
    }
    foreach ($index->getFields() as $field) {
      if ($field->getDatasourceId() === NULL && $field->getPropertyPath() === 'ddbgo_kwe_sector_label') {
        $mapping = &$event->getForeignRelationshipsMapping();
        foreach (['field_sparte' => 'kultursparte_kwe', 'field_untersparte' => 'kulturuntersparte_kwe'] as $reference => $vocabulary) {
          $relationship = [
            'datasource' => 'entity:node',
            'entity_type' => 'taxonomy_term',
            'bundles' => [$vocabulary],
            'property_path_to_foreign_entity' => "$reference:entity",
            'field_name' => 'name',
          ];
          if (!in_array($relationship, $mapping, TRUE)) {
            $mapping[] = $relationship;
          }
        }
        return;
      }
    }
  }

}
