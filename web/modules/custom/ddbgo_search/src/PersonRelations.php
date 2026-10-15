<?php

declare(strict_types=1);

namespace Drupal\ddbgo_search;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\views\ViewExecutable;

/**
 * Resolves person relations once for a search-result batch.
 */
final class PersonRelations {

  private const PROPERTIES = [
    'ddbgo_person_aggregator' => 'aggregator',
    'ddbgo_person_bestand' => 'bestand',
    'ddbgo_person_kwe' => 'kwe',
  ];

  private array $prepared = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FieldsHelperInterface $fieldsHelper,
  ) {}

  /**
   * Stores original relation fields before Highlight and Views consume them.
   */
  public function prepareResults(ResultSetInterface $results): void {
    // Direct indexing and non-Views queries keep their existing lazy path.
    if (!$results->getQuery()->getOption('search_api_view') instanceof ViewExecutable) {
      return;
    }

    $index = $results->getQuery()->getIndex();
    $fields = [];
    $properties = [];
    $bundles = [];
    foreach ($index->getFields() as $id => $field) {
      $path = $field->getPropertyPath();
      if ($field->getDatasourceId() === NULL && isset(self::PROPERTIES[$path])
        && $index->getProcessorIfAvailable($path)) {
        $fields[$id] = $field;
        $properties[''][$path . '|' . $id] = $id;
        $bundles[self::PROPERTIES[$path]] = self::PROPERTIES[$path];
      }
    }
    if (!$fields) {
      return;
    }

    $items = array_filter($results->getResultItems(), static function ($item) use ($fields): bool {
      return (bool) array_diff_key($fields, $item->getFields(FALSE));
    });
    if (!$items) {
      return;
    }
    $results->preLoadResultItems();
    foreach ($items as $key => $item) {
      try {
        if (!$item->getOriginalObject()->getValue() instanceof Node) {
          unset($items[$key]);
        }
      }
      catch (SearchApiException) {
        // Leave unavailable objects to the existing lazy/error handling path.
        unset($items[$key]);
      }
    }
    if (!$items) {
      return;
    }

    // These original HTML values also enter Views' result cache. Keep them
    // fresh when related content, role labels or link aliases change, including
    // newly added relations that were absent from the current batch.
    $results->getQuery()->addCacheTags(['node_list', 'paragraph_list', 'taxonomy_term_list', 'path_alias_list']);

    $this->withItems($items, array_values($bundles), function () use ($items, $properties, $fields): void {
      $values = $this->fieldsHelper->extractItemValues($items, $properties);
      foreach ($items as $key => $item) {
        $existing = $item->getFields(FALSE);
        foreach ($fields as $id => $field) {
          if (!isset($existing[$id])) {
            $original = clone $field;
            $original->setValues($values[$key][$id]);
            $item->setField($id, $original);
          }
        }
      }
      // Other properties must still be extracted lazily by Search API.
    });
  }

  /**
   * Limits prepared data to the current extraction, including nested batches.
   */
  public function withItems(array $items, array $bundles, callable $callback): mixed {
    $previous = $this->prepared;
    try {
      $this->prepared = $this->loadRelations($items, $bundles);
      return $callback();
    }
    finally {
      $this->prepared = $previous;
    }
  }

  public function getPrepared(int|string $person_id, string $bundle): ?array {
    return $this->prepared[$person_id][$bundle] ?? NULL;
  }

  /**
   * Retains the existing any-translation query and default-entity rendering.
   */
  private function loadRelations(array $items, array $bundles): array {
    $person_ids = [];
    foreach ($items as $item) {
      try {
        $entity = $item->getOriginalObject()->getValue();
      }
      catch (SearchApiException) {
        continue;
      }
      if ($entity instanceof Node && $entity->id()) {
        $person_ids[(int) $entity->id()] = (int) $entity->id();
      }
    }
    $bundles = array_values(array_intersect(array_unique($bundles), self::PROPERTIES));
    if (!$person_ids || !$bundles) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $matches = [];
    $host_ids = [];
    foreach ($bundles as $bundle) {
      $rows = $storage->getAggregateQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $bundle)
        ->condition('field_personen.entity:paragraph.field_person.target_id', array_values($person_ids), 'IN')
        ->groupBy('title')
        ->groupBy('field_personen.entity:paragraph.field_person.target_id')
        ->aggregate('nid', 'MIN')
        ->aggregate('nid', 'MAX')
        ->aggregate('title', 'MIN')
        ->sortAggregate('title', 'MIN', 'ASC')
        ->execute();
      $ties = [];
      foreach ($rows as $row) {
        if ($row['nid_min'] != $row['nid_max']) {
          $ties[$row['field_person_target_id']] = $row['field_person_target_id'];
        }
      }
      $matches[$bundle] = [];
      foreach ($rows as $row) {
        $person_id = $row['field_person_target_id'];
        if (!isset($ties[$person_id])) {
          $id = $row['nid_min'];
          $matches[$bundle][] = ['nid' => $id, 'field_person_target_id' => $person_id];
          $host_ids[$id] = $id;
        }
      }
      foreach ($ties as $person_id) {
        // Group titles using the database's collation, which can also equate
        // case/accent variants. Keep all hosts and the original query's order
        // for these exceptional persons before loading the batch once.
        $ordered_ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition('type', $bundle)
          ->condition('field_personen.entity:paragraph.field_person.target_id', $person_id)
          ->sort('title', 'ASC')
          ->execute();
        foreach ($ordered_ids as $id) {
          $matches[$bundle][] = ['nid' => $id, 'field_person_target_id' => $person_id];
          $host_ids[$id] = $id;
        }
      }
    }
    $hosts = $storage->loadMultiple(array_values($host_ids));
    $default_references = [];
    $paragraph_ids = [];
    foreach ($hosts as $id => $host) {
      if (!$host instanceof Node) {
        continue;
      }
      foreach ($host->get('field_personen') as $reference) {
        if ($reference->target_id) {
          $paragraph_id = (int) $reference->target_id;
          $default_references[$id][] = $paragraph_id;
          $paragraph_ids[$paragraph_id] = $paragraph_id;
        }
      }
    }
    // As before, use target_id/default paragraphs, not target_revision_id.
    $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadMultiple(array_values($paragraph_ids));

    $host_roles = [];
    $role_ids = [];
    foreach ($default_references as $id => $references) {
      foreach ($references as $paragraph_id) {
        $paragraph = $paragraphs[$paragraph_id] ?? NULL;
        if (!$paragraph instanceof Paragraph) {
          continue;
        }
        $person_id = (int) $paragraph->get('field_person')->target_id;
        $role_id = (int) $paragraph->get('field_rolle')->target_id;
        if (isset($person_ids[$person_id]) && $role_id > 0) {
          $host_roles[$id][$person_id][] = $role_id;
          $role_ids[$role_id] = $role_id;
        }
      }
    }
    $roles = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_values($role_ids));
    $prepared = [];
    foreach ($person_ids as $person_id) {
      foreach ($bundles as $bundle) {
        $prepared[$person_id][$bundle] = ['nodes' => [], 'node_role_ids' => [], 'roles' => $roles];
      }
    }
    foreach ($matches as $bundle => $rows) {
      // SQL returns exact membership across translations, even when the
      // loaded default entities have different or missing paragraph data.
      foreach ($rows as $row) {
        $id = $row['nid'];
        $person_id = $row['field_person_target_id'];
        if (!isset($hosts[$id]) || !$hosts[$id] instanceof Node) {
          continue;
        }
        $prepared[$person_id][$bundle]['nodes'][$id] = $hosts[$id];
        $prepared[$person_id][$bundle]['node_role_ids'][$id] = $host_roles[$id][$person_id] ?? [];
      }
    }
    return $prepared;
  }

}
