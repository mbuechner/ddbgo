<?php

declare(strict_types=1);

namespace Drupal\ddbgo_search;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager;

/**
 * Tracks dependencies that processors discover through reverse references.
 */
final class ReverseReferenceTracker {

  private const PERSON_PROPERTIES = [
    'aggregator' => 'ddbgo_person_aggregator',
    'bestand' => 'ddbgo_person_bestand',
    'kwe' => 'ddbgo_person_kwe',
  ];

  /**
   * Dependencies before writes, including when a draft becomes the default.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, array<string, list<int|string>>>
   */
  private \WeakMap $originals;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentEntityTrackingManager $trackingManager,
  ) {
    $this->originals = new \WeakMap();
  }

  /**
   * Remembers the stored default before its fields or child revisions change.
   */
  public function rememberOriginal(EntityInterface $entity): void {
    if (!$this->isRelevant($entity) || $entity->isNew()
      || !empty($entity->search_api_skip_tracking) || !$entity->isDefaultRevision()) {
      return;
    }
    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());
    if ($original) {
      $this->originals[$entity] = $this->getRelations($original);
    }
  }

  /**
   * Captures IDs before field deletion can remove referenced paragraphs.
   */
  public function rememberDeletion(EntityInterface $entity): void {
    if ($this->isRelevant($entity) && empty($entity->search_api_skip_tracking)) {
      $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
        ->loadUnchanged($entity->id());
      $this->originals[$entity] = $this->getRelations($stored ?? $entity);
    }
  }

  /**
   * Marks existing dependent search items for indexing after an entity change.
   */
  public function trackChange(EntityInterface $entity, bool $deleted = FALSE): void {
    if (!$this->isRelevant($entity) || !empty($entity->search_api_skip_tracking)
      || (!$deleted && !$entity->isDefaultRevision())) {
      unset($this->originals[$entity]);
      return;
    }

    $previous_relations = $this->originals[$entity] ?? NULL;
    unset($this->originals[$entity]);
    if ($previous_relations === NULL) {
      $original = method_exists($entity, 'getOriginal') ? $entity->getOriginal() : ($entity->original ?? NULL);
      $previous_relations = $original ? $this->getRelations($original) : [];
    }
    $affected = $this->getRelations($entity);
    $affected = array_merge_recursive($affected, $previous_relations);
    if (!$affected) {
      return;
    }

    $ids = array_unique(array_merge(...array_values($affected)));
    $parents = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    foreach ($parents as $parent) {
      if (!$parent instanceof NodeInterface || !empty($parent->search_api_skip_tracking)) {
        continue;
      }
      foreach ($this->trackingManager->getIndexesForEntity($parent) as $index) {
        if (!$index->status() || $index->isReadOnly()) {
          continue;
        }
        foreach ($index->getFields() as $field) {
          $property = explode(':', $field->getPropertyPath(), 2)[0];
          if (!in_array($parent->id(), $affected[$property] ?? [], FALSE)) {
            continue;
          }
          $item_ids = [];
          foreach (array_keys($parent->getTranslationLanguages()) as $langcode) {
            $item_ids[] = ContentEntityTrackingManager::formatItemId('node', $parent->id(), $langcode);
          }
          $item_ids = ContentEntityTrackingManager::filterValidItemIds($index, 'entity:node', $item_ids);
          if ($item_ids) {
            $index->trackItemsUpdated('entity:node', $item_ids);
          }
          // One update per parent and index, even when several fields depend on it.
          break;
        }
      }
    }
  }

  private function isRelevant(EntityInterface $entity): bool {
    return match ($entity->getEntityTypeId()) {
      'node' => isset(self::PERSON_PROPERTIES[$entity->bundle()]),
      'paragraph' => isset(self::PERSON_PROPERTIES[substr($entity->bundle(), 7)]) && str_starts_with($entity->bundle(), 'person_'),
      'taxonomy_term' => $entity->bundle() === 'personenrolle',
      default => FALSE,
    };
  }

  /**
   * @return array<string, list<int|string>>
   *   Parent node IDs keyed by the computed property they depend on.
   */
  private function getRelations(EntityInterface $entity): array {
    $affected = [];
    switch ($entity->getEntityTypeId()) {
      case 'node':
        $bundle = $entity->bundle();
        if ($entity->isPublished()) {
          $affected[self::PERSON_PROPERTIES[$bundle]] = $this->getPersonIds($entity);
          if ($bundle === 'bestand') {
            $aggregators = $this->getTargetIds($entity, 'field_aggregator');
            $affected['ddbgo_aggregator_bestand'] = $aggregators;
            $affected['ddbgo_aggregator_kwe'] = $aggregators;
          }
        }
        // The aggregator KWE processor follows published Bestände even when
        // their referenced KWE itself is unpublished.
        if ($bundle === 'kwe') {
          $bestand_ids = $this->entityTypeManager->getStorage('node')->getQuery()
            ->accessCheck(FALSE)->condition('type', 'bestand')->condition('status', 1)
            ->condition('field_kwe.target_id', $entity->id())->execute();
          $affected['ddbgo_aggregator_kwe'] = [];
          foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($bestand_ids) as $bestand) {
            $affected['ddbgo_aggregator_kwe'] = array_merge($affected['ddbgo_aggregator_kwe'], $this->getTargetIds($bestand, 'field_aggregator'));
          }
        }
        break;

      case 'paragraph':
        $bundle = substr($entity->bundle(), 7);
        $host_ids = $this->entityTypeManager->getStorage('node')->getQuery()
          ->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)
          ->condition('field_personen.target_id', $entity->id())->range(0, 1)->execute();
        if ($host_ids) {
          $affected[self::PERSON_PROPERTIES[$bundle]] = $this->getTargetIds($entity, 'field_person');
        }
        break;

      case 'taxonomy_term':
        $paragraph_ids = $this->entityTypeManager->getStorage('paragraph')->getQuery()
          ->accessCheck(FALSE)->condition('type', ['person_aggregator', 'person_bestand', 'person_kwe'], 'IN')
          ->condition('field_rolle.target_id', $entity->id())->execute();
        foreach ($this->entityTypeManager->getStorage('paragraph')->loadMultiple($paragraph_ids) as $paragraph) {
          $affected = array_merge_recursive($affected, $this->getRelations($paragraph));
        }
        break;
    }
    return $affected;
  }

  /**
   * Reads the exact paragraph revisions stored on old and new host nodes.
   */
  private function getPersonIds(EntityInterface $node): array {
    $ids = [];
    if (!$node->hasField('field_personen')) {
      return $ids;
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    foreach ($node->get('field_personen')->getValue() as $reference) {
      $paragraph = NULL;
      if (!empty($reference['target_revision_id'])) {
        // The form may have changed the cached paragraph object already. Read
        // persisted revisions when the installed Core supports that API.
        $paragraph = method_exists($storage, 'loadRevisionUnchanged')
          ? $storage->loadRevisionUnchanged($reference['target_revision_id'])
          : $storage->loadRevision($reference['target_revision_id']);
      }
      elseif (!empty($reference['target_id'])) {
        $paragraph = $storage->loadUnchanged($reference['target_id']);
      }
      if ($paragraph) {
        $ids = array_merge($ids, $this->getTargetIds($paragraph, 'field_person'));
      }
    }
    return array_unique($ids);
  }

  private function getTargetIds(EntityInterface $entity, string $field_name): array {
    if (!$entity->hasField($field_name)) {
      return [];
    }
    return array_values(array_filter(array_column($entity->get($field_name)->getValue(), 'target_id')));
  }

}
