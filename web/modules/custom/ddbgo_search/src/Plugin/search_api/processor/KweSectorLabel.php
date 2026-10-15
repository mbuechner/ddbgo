<?php

namespace Drupal\ddbgo_search\Plugin\search_api\processor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Combines original taxonomy labels for indexing and search result rendering.
 */
#[SearchApiProcessor(
  id: 'ddbgo_kwe_sector_label',
  label: new TranslatableMarkup('DDBgo KWE sector label'),
  description: new TranslatableMarkup('Combines sector and subsector without empty parentheses.'),
  stages: ['add_properties' => 20],
)]
final class KweSectorLabel extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource) {
      return [];
    }
    return ['ddbgo_kwe_sector_label' => new ProcessorProperty([
      'label' => $this->t('Sector (subsector)'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
    ])];
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $node = $item->getOriginalObject()->getValue();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'kwe') {
      return;
    }

    // Use Search API's extraction/translation handling for the same reference
    // paths as the previous aggregation. This only reads the entity values.
    $values = $this->getFieldsHelper()->extractItemValues([$item], [
      'entity:node' => [
        'field_sparte:entity:name' => 'sector',
        'field_untersparte:entity:name' => 'subsector',
      ],
    ])[0];
    $sector = trim(implode(', ', $values['sector'] ?? []));
    $subsector = trim(implode(', ', $values['subsector'] ?? []));
    $label = $sector;
    if ($subsector !== '') {
      $label = $sector !== '' ? "$sector ($subsector)" : $subsector;
    }
    if ($label === '') {
      return;
    }

    foreach ($this->getFieldsHelper()->filterForPropertyPath($item->getFields(FALSE), NULL, 'ddbgo_kwe_sector_label') as $field) {
      $field->addValue($label);
    }
  }

}
