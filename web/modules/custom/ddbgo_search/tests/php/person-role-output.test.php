<?php

/**
 * @file
 * Read-only native role output regressions; run with drush php:script.
 * Requires a published host with a person and role for each relation bundle.
 * Changes only unsaved role copies in an isolated in-memory storage cache.
 */

use Drupal\Core\Cache\MemoryCache\MemoryCache;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\Core\Render\RenderContext;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Item;
use Drupal\views\Views;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  ++$checks;
};
$index = Index::create(Index::load('personen')->toArray());
$view = Views::getView('personensuche');
$check($view !== NULL && $view->setDisplay('page'), 'The native Person search display is required.');
$view->initHandlers();
$relations = Drupal::service('ddbgo_search.person_relations');
$manager = Drupal::entityTypeManager();
$nodeStorage = $manager->getStorage('node');
$paragraphStorage = $manager->getStorage('paragraph');
$roleStorage = $manager->getStorage('taxonomy_term');
$cacheProperty = new ReflectionProperty(EntityStorageBase::class, 'memoryCache');
$originalCache = $cacheProperty->getValue($roleStorage);
$cacheProperty->setValue($roleStorage, new MemoryCache(Drupal::service('datetime.time')));
$setStaticCache = new ReflectionMethod(EntityStorageBase::class, 'setStaticCache');
$roleLabel = 'Kontakt <extern> / <strong>Rolle</strong> & "Ansprechperson"';
$renderer = Drupal::service('renderer');
$preparedProperty = new ReflectionProperty($relations, 'prepared');
$originalPrepared = $preparedProperty->getValue($relations);
try {
  foreach (['kwe', 'bestand', 'aggregator'] as $bundle) {
    $property = 'ddbgo_person_' . $bundle;
    $nids = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)
      ->condition('field_personen.entity:paragraph.field_rolle.entity:taxonomy_term.vid', 'personenrolle')
      ->range(0, 50)->execute();
    $fixture = NULL;
    foreach ($nodeStorage->loadMultiple($nids) as $host) {
      foreach ($host->get('field_personen') as $reference) {
        $paragraph = $paragraphStorage->load($reference->target_id);
        $person = $paragraph ? $nodeStorage->load($paragraph->get('field_person')->target_id) : NULL;
        $role = $paragraph ? $roleStorage->load($paragraph->get('field_rolle')->target_id) : NULL;
        if ($person !== NULL && $role !== NULL) {
          $fixture = [$host, $person, $role];
          break 2;
        }
      }
    }
    $check($fixture !== NULL, "$bundle requires a native published relation with a valid person and role.");
    [$host, $person, $role] = $fixture;
    $roleCopy = clone $role;
    $roleCopy->setName($roleLabel);
    $setStaticCache->invoke($roleStorage, [$roleCopy]);
    $processor = $index->getProcessor($property);
    foreach (['batch', 'fallback'] as $mode) {
      $preparedProperty->setValue($relations, $mode === 'batch' ? [
        $person->id() => [$bundle => [
          'nodes' => [$host->id() => $host],
          'node_role_ids' => [$host->id() => [$role->id(), $role->id()]],
          'roles' => [$role->id() => $roleCopy],
        ]],
      ] : []);
      $field = clone $index->getField($property);
      $field->setValues([]);
      $item = new Item($index, 'entity:node/' . $person->id() . ':' . $person->language()->getId());
      $item->setOriginalObject($person->getTypedData());
      $item->setFields([$property => $field]);
      $item->setFieldsExtracted(TRUE);
      $html = $renderer->executeInRenderContext(new RenderContext(), static function () use ($processor, $item, $field, $view, $property): string {
        $processor->addFieldValues($item);
        $values = $field->getValues();
        if (count($values) !== 1) {
          throw new RuntimeException('The relation processor must produce one HTML value.');
        }
        return (string) $view->field[$property]->render_item(0, ['value' => $values[0]]);
      });
      $previous = libxml_use_internal_errors(TRUE);
      try {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="role-output">' . $html . '</div>', LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $output = $xpath->query('//div[@id="role-output"]')->item(0);
        $check($output !== NULL && str_contains($output->textContent, '(' . $roleLabel . ')'), "$bundle $mode preserves literal role text through the native Views formatter.");
        $check($xpath->query('//div[@id="role-output"]//strong | //div[@id="role-output"]//extern')->length === 0, "$bundle $mode creates no elements from the role label.");
        $check($xpath->query('//div[@id="role-output"]//a')->length > 0, "$bundle $mode keeps native host links.");
        if ($mode === 'batch') {
          $check(substr_count($output->textContent, $roleLabel) === 1, "$bundle batch keeps duplicate-role suppression.");
        }
      }
      finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
      }
    }
  }
}
finally {
  $preparedProperty->setValue($relations, $originalPrepared);
  $cacheProperty->setValue($roleStorage, $originalCache);
}
echo "Person role output: $checks checks passed. No content or configuration changed.\n";
