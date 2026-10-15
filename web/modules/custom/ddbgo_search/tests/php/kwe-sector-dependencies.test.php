<?php

/**
 * @file
 * Read-only native regressions; run with drush php:script.
 * Uses isolated mapping caches and unsaved index/term copies. Never saves an
 * entity or marks real index items as changed.
 */

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\ddbgo_search\EventSubscriber\KweSectorDependenciesSubscriber;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Event\MappingForeignRelationshipsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\Utility\TrackingHelper;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  ++$checks;
};
$activeIndex = Index::load('suche_kwe');
$check($activeIndex !== NULL, 'The native KWE index is required.');
$index = Index::create($activeIndex->toArray());
$subscriber = new KweSectorDependenciesSubscriber();
$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber($subscriber);
$mappingMethod = new ReflectionMethod(TrackingHelper::class, 'getForeignEntityRelationsMap');
$mappingFor = static function (Index $index, EventDispatcher $dispatcher) use ($mappingMethod): array {
  $helper = new TrackingHelper(
    Drupal::entityTypeManager(),
    Drupal::languageManager(),
    $dispatcher,
    Drupal::service('search_api.fields_helper'),
    new MemoryBackend(Drupal::service('datetime.time')),
  );
  return $mappingMethod->invoke($helper, $index);
};
$before = $mappingFor($index, new EventDispatcher());
$mapping = $mappingFor($index, $dispatcher);
$expected = [];
foreach (['field_sparte' => 'kultursparte_kwe', 'field_untersparte' => 'kulturuntersparte_kwe'] as $field => $vocabulary) {
  $relationship = [
    'datasource' => 'entity:node',
    'entity_type' => 'taxonomy_term',
    'bundles' => [$vocabulary],
    'property_path_to_foreign_entity' => "$field:entity",
    'field_name' => 'name',
  ];
  $check(in_array($relationship, $mapping, TRUE), "Native mapping includes $field labels.");
  $expected[] = $relationship;
}
foreach ($before as $relationship) {
  $check(in_array($relationship, $mapping, TRUE), 'Existing foreign relationships are preserved.');
}
$event = new MappingForeignRelationshipsEvent($index, $mapping, new CacheableMetadata());
$dispatcher->dispatch($event, SearchApiEvents::MAPPING_FOREIGN_RELATIONSHIPS);
$check(count($mapping) === count($before) + 2, 'Repeated dispatch does not duplicate relationships.');

$withoutField = Index::create($activeIndex->toArray());
foreach ($withoutField->getFields() as $id => $field) {
  if ($field->getDatasourceId() === NULL && $field->getPropertyPath() === 'ddbgo_kwe_sector_label') {
    $withoutField->removeField($id);
  }
}
$unaffected = $mappingFor($withoutField, $dispatcher);
$check(!array_filter($expected, static fn (array $relationship): bool => in_array($relationship, $unaffected, TRUE)), 'An unused computed property adds no dependencies.');
$withoutProcessor = Index::create($activeIndex->toArray());
$withoutProcessor->removeProcessor('ddbgo_kwe_sector_label');
$unaffected = $mappingFor($withoutProcessor, $dispatcher);
$check(!array_filter($expected, static fn (array $relationship): bool => in_array($relationship, $unaffected, TRUE)), 'A disabled processor adds no dependencies.');

// Exercise native change detection against a real reference, using an unsaved
// taxonomy copy. Do not invoke TrackingHelper::trackReferencedEntityUpdate():
// that would change the production index tracker.
$nodeStorage = Drupal::entityTypeManager()->getStorage('node');
$termStorage = Drupal::entityTypeManager()->getStorage('taxonomy_term');
$nids = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', 'kwe')
  ->condition('field_sparte.entity:taxonomy_term.vid', 'kultursparte_kwe')
  ->range(0, 1)->execute();
$check((bool) $nids, 'An existing KWE with a valid sector reference is required.');
$node = $nodeStorage->load(reset($nids));
$term = $termStorage->load($node->get('field_sparte')->target_id);
$check($term !== NULL && $term->bundle() === 'kultursparte_kwe', 'The native referenced sector exists.');
$renamed = clone $term;
$renamed->setName($term->label() . ' synthetic regression suffix');
$datasource = $index->getDatasource('entity:node');
$check($datasource->getAffectedItemsForEntityChange($renamed, $before, $term) === [], 'The original native mapping misses the rename.');
$affected = $datasource->getAffectedItemsForEntityChange($renamed, $mapping, $term);
$expectedItem = $node->id() . ':' . $node->language()->getId();
$check(in_array($expectedItem, $affected, TRUE), 'A rename now identifies the referenced KWE for reindexing.');
$check($datasource->getAffectedItemsForEntityChange($term, $mapping, $term) === [], 'Unchanged labels do not reindex KWEs.');
$check(in_array($expectedItem, $datasource->getAffectedItemsForEntityChange($term, $mapping), TRUE), 'Deletion identifies the referenced KWE for reindexing.');

$services = Yaml::parseFile(__DIR__ . '/../../ddbgo_search.services.yml');
$service = $services['services']['ddbgo_search.kwe_sector_dependencies'] ?? [];
$check(($service['class'] ?? NULL) === KweSectorDependenciesSubscriber::class
  && in_array(['name' => 'event_subscriber'], $service['tags'] ?? [], TRUE), 'The native mapping subscriber is registered in module services.');
if (in_array('live', $extra ?? [], TRUE)) {
  $check(Drupal::hasService('ddbgo_search.kwe_sector_dependencies')
    && Drupal::service('ddbgo_search.kwe_sector_dependencies') instanceof KweSectorDependenciesSubscriber, 'The rebuilt Drupal container includes the subscriber.');
  $nativeHelper = clone Drupal::service('search_api.tracking_helper');
  $cacheProperty = new ReflectionProperty(TrackingHelper::class, 'cache');
  if (in_array('cache-empty', $extra ?? [], TRUE)) {
    $check($cacheProperty->getValue($nativeHelper)->get('search_api:suche_kwe:foreign_entities_relations_map') === FALSE, 'Cache rebuild removed the previously cached native foreign mapping.');
  }
  $cacheProperty->setValue($nativeHelper, new MemoryBackend(Drupal::service('datetime.time')));
  $nativeMapping = $mappingMethod->invoke($nativeHelper, $index);
  foreach ($expected as $relationship) {
    $check(in_array($relationship, $nativeMapping, TRUE), 'The native Drupal dispatcher supplies each computed sector dependency.');
  }
}
echo "KWE sector dependencies: $checks checks passed. No content or index tracker changed.\n";
