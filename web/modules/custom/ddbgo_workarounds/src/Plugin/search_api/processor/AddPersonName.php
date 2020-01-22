<?php

namespace Drupal\ddbgo_workarounds\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\ddbgo_workarounds\Plugin\search_api\processor\Property\EntityProcessorProperty;

/**
 * Custom Search API processors.
 *
 * @SearchApiProcessor(
 *   id = "add_person",
 *   label = @Translation("DDBgo person field"),
 *   description = @Translation("Adds a DDBgo person to the indexed data."),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class AddPersonName extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {

    // xdebug_break();

    $properties = [];

    if (!$datasource) {
      $properties['ddbgo_person_kwe'] = new EntityProcessorProperty(
        [
          'label' => $this->t('DDBgo KWE für Personen'),
          'description' => $this->t('KWE, die diese Person über ein Paragraph verlinken.'),
          'type' => 'entity:node',
          'processor_id' => $this->getPluginId(),
          //'is_list' => TRUE,
        ]
      );
      $properties['ddbgo_person_kwe']->setEntityTypeId('node');
    }

    return $properties;
  }

   /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {

    xdebug_break();

    $original_entity = $item->getOriginalObject()->getValue();
    $nid = $original_entity->id();

    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'kwe')
      ->condition('field_personen.entity:paragraph.field_person.target_id', $nid)
      ->execute();

    if ($nids) {

      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($nids);

        $fieldsForProp = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'ddbgo_person_kwe');

        foreach ($fieldsForProp as $field) {
          foreach ($nodes as $node) {
            $field->addValue($node, 'ddbgo_person_kwe');
          }
        }

    }
  }

}
