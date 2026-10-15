<?php

namespace Drupal\ddbgo_search\Plugin\search_api\processor;

use Drupal\ddbgo_search\PersonRelations;
use Drupal\search_api\Query\ResultSetInterface;

/**
 * Shares the temporary relation batch between the three person properties.
 */
trait PersonRelationsBatchTrait {

  public function postprocessSearchResults(ResultSetInterface $results) {
    $this->getPersonRelations()?->prepareResults($results);
  }

  protected function getPersonRelations(): ?PersonRelations {
    // Existing web containers may outlive newly deployed service definitions.
    // Keep the single-item extraction available until the container is rebuilt.
    return \Drupal::hasService('ddbgo_search.person_relations')
      ? \Drupal::service('ddbgo_search.person_relations')
      : NULL;
  }

}
