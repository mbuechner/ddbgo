<?php

namespace Drupal\ddbgo_search\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\views\ViewExecutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps database search results faithful to the original entity field values.
 */
final class OriginalSearchValuesSubscriber implements EventSubscriberInterface {

  /**
   * Table views that display and highlight the original content.
   */
  private const SEARCH_VIEWS = [
    'suche_kwe',
    'suche_aggregator',
    'suche_aggregator_fuer_claudia',
    'personensuche',
    'suche_bestand',
    'suche_bestand_fuer_europeana',
    'suche_bestand_fuer_coding_da_vinci',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'useOriginalValues'];
  }

  /**
   * Loads display values from entities instead of normalized index values.
   */
  public function useOriginalValues(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    $view = $query->getOption('search_api_view');
    if (!$view instanceof ViewExecutable || !in_array($view->id(), self::SEARCH_VIEWS, TRUE)) {
      return;
    }
    if ($query->getIndex()->getServerInstance()->getBackendId() !== 'search_api_db') {
      return;
    }

    // Search API Database returns normalized, shortened sort values, not the
    // original text. Highlight prefers these values when they are present,
    // causing lowercase, truncated labels even though the entity is intact.
    // Without retrieved values, Highlight and Views load the full entity fields.
    // This affects result rendering only; search, sorting and indexing are intact.
    $query->setOption('search_api_retrieved_field_values', []);
  }

}
