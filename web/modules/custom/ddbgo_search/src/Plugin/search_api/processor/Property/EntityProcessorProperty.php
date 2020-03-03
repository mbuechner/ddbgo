<?php

namespace Drupal\ddbgo_search\Plugin\search_api\processor\Property;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\search_api\Processor\ProcessorPropertyInterface;

/**
 * Provides a definition for a processor property that contains an entity.
 */
class EntityProcessorProperty extends EntityDataDefinition implements ProcessorPropertyInterface {

  /**
   * {@inheritdoc}
   */
  public function getProcessorId() {
    return $this->definition['processor_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function isHidden() {
    return !empty($this->definition['hidden']);
  }

}
