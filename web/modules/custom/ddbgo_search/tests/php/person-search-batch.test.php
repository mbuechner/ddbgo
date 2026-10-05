<?php

/**
 * @file
 * Read-only native Person search regression; run with drush php:script.
 * On Windows, from the project root:
 * php vendor/bin/drush.php php:script web/modules/custom/ddbgo_search/tests/php/person-search-batch.test.php
 *
 * Requires the active personensuche/page View and indexed Person content. Each
 * scenario has an isolated request, a synthetic authenticated account and an
 * in-memory session. Display/index changes are in memory only; no entities,
 * configuration, users, API keys or benchmark data are saved. Query arguments
 * and content stay in memory and are never included in test output.
 * The missing-container scenario removes the new service only from the child
 * process's in-memory container, simulating a stale web container after deploy.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Processor\ProcessorInterface;
use Drupal\views\Views;
use Drush\Drush;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$properties = [
  'ddbgo_person_aggregator' => 'aggregator',
  'ddbgo_person_bestand' => 'bestand',
  'ddbgo_person_kwe' => 'kwe',
];
$relationCacheTags = ['node_list', 'paragraph_list', 'taxonomy_term_list', 'path_alias_list'];
$nameFields = ['field_nachname', 'field_vorname'];

// Separate processes also prevent Views/processor/request statics from making
// later scenarios pass because of a previous scenario's prepared data.
if (empty($extra)) {
  $cacheNonce = bin2hex(random_bytes(16));
  $scenarios = [
    'empty' => ['query' => ''],
    'empty-repeat' => ['query' => ''],
    'archiv' => ['query' => 'Archiv'],
    'berlin' => ['query' => 'Berlin'],
    'no-matches' => ['query' => 'zzzzDDBgoPersonBatchNoMatchzzzz'],
    'empty-no-highlight' => ['query' => '', 'no_highlight' => TRUE],
    'archiv-no-highlight' => ['query' => 'Archiv', 'no_highlight' => TRUE],
    'berlin-no-highlight' => ['query' => 'Berlin', 'no_highlight' => TRUE],
    'active-cache-miss' => ['query' => 'Archiv', 'cache_state' => 'miss', 'cache_nonce' => $cacheNonce],
    'active-cache-hit' => ['query' => 'Archiv', 'cache_state' => 'hit', 'cache_nonce' => $cacheNonce],
    'missing-container-service' => ['query' => '', 'missing_service' => TRUE],
  ];
  $results = [];
  $alias = Drush::aliasManager()->getSelf();
  $processManager = Drush::processManager();
  foreach ($scenarios as $name => $scenario) {
    $arguments = [__FILE__, base64_encode(json_encode($scenario, JSON_THROW_ON_ERROR))];
    if (PHP_OS_FAMILY === 'Windows' && !$processManager->hasTransport($alias)) {
      // Drush's @self launcher selects the extensionless vendor/bin/drush.
      // Composer's .bat alternative also invokes a POSIX shell, which might
      // not execute PHP files on Windows. Use the running PHP interpreter and
      // Composer's public PHP entry point, with an argv array for safe quoting.
      $entry = realpath(DRUPAL_ROOT . '/../vendor/bin/drush.php');
      $check($entry !== FALSE, 'The Composer PHP Drush entry point is required for local Windows child requests.');
      $child = $processManager->process([
        PHP_BINARY,
        $entry,
        '--root=' . DRUPAL_ROOT,
        '--uri=' . ($alias->uri() ?: 'default'),
        'php:script',
        ...$arguments,
      ], dirname(DRUPAL_ROOT));
    }
    else {
      $child = Drush::drush($alias, 'php:script', $arguments);
    }
    $child->run();
    $check($child->isSuccessful(), "$name failed (child exit {$child->getExitCode()}); child output was suppressed to protect content.");
    $check(trim($child->getErrorOutput()) === '', "$name emitted a warning; child output was suppressed to protect content.");
    $result = json_decode(trim($child->getOutput()), TRUE, 512, JSON_THROW_ON_ERROR);
    $check(is_array($result), "$name returned no test summary.");
    $results[$name] = $result;
    $status = $result['rows'] === 0 && !in_array($name, ['empty', 'empty-repeat', 'empty-no-highlight', 'no-matches'], TRUE) ? 'SKIP (no matching content)' : 'PASS';
    echo "$status: $name ({$result['rows']} rows, {$result['execute_relations']} execute / {$result['render_relations']} render / {$result['reference_relations']} reference relation queries)\n";
  }
  $check($results['empty']['rows'] > 1, 'At least two indexed accessible Person rows are required to detect per-person queries.');
  $check($results['no-matches']['total'] === 0 && $results['no-matches']['rows'] === 0, 'The null-match scenario must have no hits.');
  foreach ([['empty', 'empty-repeat'], ['empty', 'empty-no-highlight'], ['archiv', 'archiv-no-highlight'], ['berlin', 'berlin-no-highlight']] as [$left, $right]) {
    $check($results[$left]['total'] === $results[$right]['total'] && $results[$left]['rows'] === $results[$right]['rows'], "$left and $right must return the same result counts.");
  }
  $check($results['archiv']['highlight_checks'] + $results['berlin']['highlight_checks'] > 0, 'At least one visible relation keyword match is required to verify native Highlight output.');
  $check($results['active-cache-miss']['rows'] > 1 && $results['active-cache-miss']['execute_relations'] > 0, 'The active cache miss must prepare multiple native Person results.');
  $check($results['active-cache-hit']['execute_relations'] === 0 && $results['active-cache-hit']['render_relations'] === 0, 'A fresh request must reuse the active Views result cache without relation queries.');
  $check($results['active-cache-miss']['cache_signature'] === $results['active-cache-hit']['cache_signature'], 'The active cache hit must preserve native row identity, original relation values and visible HTML.');
  $check($results['active-cache-miss']['total'] === $results['active-cache-hit']['total'], 'The active cache hit must preserve the total result count.');
  $check($results['missing-container-service']['total'] === $results['empty']['total'] && $results['missing-container-service']['rows'] === $results['empty']['rows'], 'A stale container without the new service must preserve all native Person results.');
  $check($results['missing-container-service']['execute_relations'] === 0 && $results['missing-container-service']['render_relations'] === 3 * $results['missing-container-service']['rows'], 'A missing batch service must use the original per-person render extraction.');
  echo "PASS: original names/relations, native Highlight, rendered links/roles/order, cache tags, active result-cache reuse, closed scopes and missing-service fallback. No content or configuration changed.\n";
  return;
}

$scenario = json_decode(base64_decode($extra[0], TRUE), TRUE, 512, JSON_THROW_ON_ERROR);
$check(is_array($scenario) && isset($scenario['query']) && is_string($scenario['query']), 'A valid test scenario is required.');
$cacheState = $scenario['cache_state'] ?? NULL;
$missingService = !empty($scenario['missing_service']);
$check(!$missingService || ($scenario['query'] === '' && $cacheState === NULL), 'The missing-service regression must exercise an uncached empty query.');
if ($cacheState !== NULL) {
  $check(in_array($cacheState, ['miss', 'hit'], TRUE) && preg_match('/^[a-f0-9]{32}$/D', $scenario['cache_nonce'] ?? '') === 1, 'A valid isolated active-cache scenario is required.');
}
$relationCount = static function (array $queries): int {
  return count(array_filter($queries, static function (array $entry): bool {
    $sql = strtolower($entry['query']);
    // Entity storage also reads these tables. Count only the relation query
    // joining the host references to the referenced paragraphs, not field loads.
    return str_contains($sql, 'select') && str_contains($sql, 'node__field_personen') && str_contains($sql, 'paragraph__field_person');
  }));
};

// Compare visible text and link destinations in order, preserving case and all
// role labels. Only presentation whitespace and highlight wrappers are ignored.
$canonical = static function (DOMNode $node): array {
  $space = static fn (string $value): string => trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $value));
  $links = [];
  $collect = static function (DOMNode $current) use (&$collect, &$links, $space): void {
    if ($current instanceof DOMElement && strtolower($current->tagName) === 'a') {
      $links[] = [$current->getAttribute('href'), $space($current->textContent)];
    }
    foreach ($current->childNodes as $child) {
      $collect($child);
    }
  };
  $collect($node);
  return [$space($node->textContent), $links];
};
$parse = static function (string $html): DOMDocument {
  $previous = libxml_use_internal_errors(TRUE);
  try {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
    return $document;
  }
  finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
  }
};

$input = ['query' => $scenario['query']];
if ($cacheState !== NULL) {
  // The active View varies by url.query_args. This unknown parameter changes
  // only the cache context, creating our own key without changing the search.
  $input['ddbgo_person_batch_test'] = $scenario['cache_nonce'];
}
$request = Request::create('http://localhost:8888/search/person', 'GET', $input);
$request->setSession(new Session(new MockArraySessionStorage()));
$requestStack = Drupal::requestStack();
$accountSwitcher = Drupal::service('account_switcher');
$themeManager = Drupal::theme();
$originalTheme = $themeManager->getActiveTheme();
$requestStack->push($request);
$accountSwitcher->switchTo(new UserSession(['uid' => 999999999, 'roles' => ['authenticated']]));
$logKeys = [];
$cacheBackend = NULL;
$cacheKey = NULL;
$containerState = [];
try {
  if ($missingService) {
    $container = Drupal::getContainer();
    $serviceId = 'ddbgo_search.person_relations';
    $check($container->has($serviceId), 'The missing-service fixture requires the current container to define the batch service.');
    // Initialize first, proving that removing only the definition would leave
    // has() true. These are the actual properties used by Drupal's compiled
    // Component/DependencyInjection/Container::has() and ::get().
    $container->get($serviceId);
    foreach (['serviceDefinitions', 'services'] as $propertyName) {
      $property = new ReflectionProperty($container, $propertyName);
      $values = $property->getValue($container);
      $check(array_key_exists($serviceId, $values), 'The missing-service fixture must remove the definition and initialized service.');
      $containerState[] = [$property, $values[$serviceId]];
      unset($values[$serviceId]);
      $property->setValue($container, $values);
    }
    $check(!Drupal::hasService($serviceId), 'The child container must now lack the batch service.');
  }
  $themeManager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
  $view = Views::getView('personensuche');
  $check($view !== NULL && $view->setDisplay('page'), 'The active Person search page is required.');
  $view->setRequest($request);
  $view->setExposedInput($input);
  if ($cacheState === NULL) {
    // Never save this View: ordinary scenarios deliberately exercise misses.
    $view->display_handler->setOption('cache', ['type' => 'search_api_none', 'options' => []]);
  }
  $view->initQuery();
  $query = $view->query->getSearchApiQuery();
  $check($query !== NULL, 'The native Search API Views query must initialize.');

  if (!empty($scenario['no_highlight'])) {
    // Create independent plugin instances from a copy of the active settings.
    // Both native query objects use the unsaved copy; field handlers will then
    // also resolve it through their native SearchApiHandlerTrait::getIndex().
    $index = Index::create($query->getIndex()->toArray());
    $index->removeProcessor('highlight');
    (new ReflectionProperty($view->query, 'index'))->setValue($view->query, $index);
    (new ReflectionProperty($query, 'index'))->setValue($query, $index);
    $check(!$index->isValidProcessor('highlight'), 'The unsaved index copy must have Highlight disabled.');
  }
  $index = $query->getIndex();
  $highlightSettings = $index->getProcessorIfAvailable('highlight')?->getConfiguration() ?? [];
  $stages = array_keys($index->getProcessorsByStage(ProcessorInterface::STAGE_POSTPROCESS_QUERY));
  foreach ($properties as $property => $bundle) {
    $check(in_array($property, $stages, TRUE), "$property must prepare native search results.");
    if (($highlight = array_search('highlight', $stages, TRUE)) !== FALSE) {
      $check(array_search($property, $stages, TRUE) < $highlight, "$property must run before native Highlight.");
    }
  }
  if ($cacheState !== NULL) {
    $check($view->display_handler->getPlugin('cache')->getPluginId() === 'search_api_tag', 'The active Person search must use its configured Search API tag cache.');
    $check($view->build(), 'The active-cache View must build successfully.');
    $cache = $view->display_handler->getPlugin('cache');
    $cacheKey = $cache->generateResultsKey();
    $cacheBackend = $cache->getCacheBackend();
    $existingCache = $cacheBackend->get($cacheKey);
    $check(($existingCache !== FALSE) === ($cacheState === 'hit'), 'The isolated active-cache scenario must start with the expected miss or stored result entry.');
  }

  $executeLog = 'ddbgo_person_batch_execute';
  $logKeys[$executeLog] = TRUE;
  Database::startLog($executeLog);
  $check($view->execute() && empty($view->build_info['fail']) && empty($view->build_info['abort']), 'Native Person search must execute successfully.');
  $check(!$view->query->shouldAbort(), 'Native Person search must not abort, including with a stale container.');
  $executeQueries = $relationCount(Database::getLog($executeLog));
  unset($logKeys[$executeLog]);
  $check($executeQueries <= 3, 'Native result preparation must issue at most three relation queries per page.');
  // On a cache hit Search API's native cache plugin replaces the query object
  // with the cached ResultSet query, including its runtime cache metadata.
  $query = $view->query->getSearchApiQuery();
  $check($query->getOption('search_api_retrieved_field_values') === [], 'Native results must not use normalized backend field values.');
  if ($view->result && !$missingService) {
    foreach (['Search API query' => $query->getCacheTags(), 'Views query' => $view->query->getCacheTags(), 'View' => $view->getCacheTags()] as $source => $tags) {
      $check(!array_diff($relationCacheTags, $tags), "$source must retain all relation invalidation tags.");
    }
  }
  if ($cacheState !== NULL) {
    $storedCache = $cacheBackend->get($cacheKey);
    $check($storedCache !== FALSE && isset($storedCache->data['search_api results']), 'The active result cache must contain the native Search API ResultSet.');
    $check(!array_diff($relationCacheTags, $storedCache->tags), 'The persisted native result cache entry must retain all relation invalidation tags.');
  }
  $relations = $missingService ? NULL : Drupal::service('ddbgo_search.person_relations');
  $original = [];
  $rowIdentity = [];
  foreach ($view->result as $rowNumber => $row) {
    $rowIdentity[] = $row->_item->getId();
    $fields = $row->_item->getFields(FALSE);
    foreach ($properties as $property => $bundle) {
      if ($missingService) {
        $check(!isset($fields[$property]), "$property must remain lazy when the batch service is absent.");
      }
      else {
        $check(isset($fields[$property]), "$property must be attached to each native original Item before rendering.");
        $original[$rowNumber][$property] = $fields[$property]->getValues();
        $check($relations->getPrepared($row->_item->getOriginalObject()->getValue()->id(), $bundle) === NULL, 'Prepared relations must not survive native result preparation.');
      }
    }
    if (!empty($scenario['no_highlight'])) {
      $check(!$row->_item->getExtraData('highlighted_fields'), 'The disabled Highlight processor must not produce highlighted data.');
    }
  }

  $renderLog = 'ddbgo_person_batch_render';
  $logKeys[$renderLog] = TRUE;
  Database::startLog($renderLog);
  $renderer = Drupal::service('renderer');
  $renderTags = [];
  $html = $renderer->executeInRenderContext(new RenderContext(), static function () use ($view, $renderer, &$renderTags): string {
    $build = $view->render();
    $html = (string) $renderer->render($build);
    $renderTags = $build['#cache']['tags'] ?? [];
    return $html;
  });
  $renderQueries = $relationCount(Database::getLog($renderLog));
  unset($logKeys[$renderLog]);
  $check($renderQueries === ($missingService ? 3 * count($view->result) : 0), 'Native rendering must reuse batch fields or use the original fallback when the service is absent.');
  $check(!Drupal::messenger()->messagesByType('error'), 'Native Person search must not emit error messages.');
  if ($view->result && !$missingService) {
    $check(!array_diff($relationCacheTags, $renderTags), 'The native View render array must retain all relation invalidation tags.');
  }

  $required = [];
  foreach ($properties as $property => $bundle) {
    $required[''][$property . '|' . $property] = $property;
  }
  foreach ($nameFields as $nameField) {
    $field = $index->getField($nameField);
    $check($field !== NULL, "$nameField must remain configured on the native Person index.");
    $required[(string) $field->getDatasourceId()][$field->getPropertyPath()] = $nameField;
  }
  $document = $parse($html);
  $xpath = new DOMXPath($document);
  $rows = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " views-table ")]/tbody/tr[td]');
  $check($rows->length === count($view->result), 'The native rendered table must contain all native result rows.');
  $visibleRows = [];
  foreach ($rows as $htmlRow) {
    $visibleRows[] = $canonical($htmlRow);
  }

  $referenceLog = 'ddbgo_person_batch_reference';
  $logKeys[$referenceLog] = TRUE;
  Database::startLog($referenceLog);
  $helper = Drupal::service('search_api.fields_helper');
  $highlightChecks = 0;
  $nameChecks = 0;
  foreach ($view->result as $rowNumber => $row) {
    // Clear populated fields on a clone, outside the service's preload scope.
    // Native FieldsHelper must invoke the original per-person fallback here.
    $item = clone $row->_item;
    $item->setFields([]);
    $item->setFieldsExtracted(TRUE);
    $expected = $helper->extractItemValues([$item], $required)[0];
    foreach ($properties as $property => $bundle) {
      if (!$missingService) {
        $check($original[$rowNumber][$property] === $expected[$property], "$property original batch values must equal independent native single-item extraction.");
      }
      $cells = $xpath->query('./td[contains(concat(" ", normalize-space(@class), " "), " views-field-' . str_replace('_', '-', $property) . ' ")]', $rows->item($rowNumber));
      $check($cells->length === 1, "$property must have one native HTML cell per result row.");
      $expectedHtml = implode($view->field[$property]->options['multi_separator'] ?? ', ', $expected[$property]);
      if ($expectedHtml === '') {
        $expectedHtml = (string) ($view->field[$property]->options['empty'] ?? '');
      }
      $expectedDocument = $parse('<div id="ddbgo-person-reference">' . $expectedHtml . '</div>');
      $expectedNode = (new DOMXPath($expectedDocument))->query('//div[@id="ddbgo-person-reference"]')->item(0);
      $expectedVisible = $canonical($expectedNode);
      $check($canonical($cells->item(0)) === $expectedVisible, "$property native HTML must retain the original links, labels, roles and ordering.");
      if ($scenario['query'] !== '' && ($highlightSettings['highlight'] ?? '') === 'always'
        && !empty($highlightSettings['highlight_partial']) && mb_stripos($expectedVisible[0], $scenario['query']) !== FALSE) {
        $check(!empty($row->_item->getExtraData('highlighted_fields', [])[$property]), "$property visible keyword matches must retain native highlighted field data.");
        $cellHtml = '';
        foreach ($cells->item(0)->childNodes as $childNode) {
          $cellHtml .= $document->saveHTML($childNode);
        }
        $check(str_contains($cellHtml, $highlightSettings['prefix']) && str_contains($cellHtml, $highlightSettings['suffix']), "$property visible keyword matches must retain native Highlight wrappers in HTML.");
        ++$highlightChecks;
      }
    }
    foreach ($nameFields as $nameField) {
      $cells = $xpath->query('./td[contains(concat(" ", normalize-space(@class), " "), " views-field-' . str_replace('_', '-', $nameField) . ' ")]', $rows->item($rowNumber));
      $check($cells->length === 1, "$nameField must have one native HTML cell per Person.");
      $separator = $view->field[$nameField]->options['fallback_options']['multi_separator'] ?? ', ';
      $expectedText = implode($separator, array_map('strval', $expected[$nameField]));
      if ($expectedText === '') {
        $expectedText = (string) ($view->field[$nameField]->options['empty'] ?? '');
      }
      $expectedDocument = $parse('<div id="ddbgo-person-name">' . \Drupal\Component\Utility\Html::escape($expectedText) . '</div>');
      $expectedName = (new DOMXPath($expectedDocument))->query('//div[@id="ddbgo-person-name"]')->item(0);
      $check($canonical($cells->item(0))[0] === $canonical($expectedName)[0], "$nameField native HTML must retain the complete original Person name.");
      ++$nameChecks;
    }
  }
  $referenceQueries = $relationCount(Database::getLog($referenceLog));
  unset($logKeys[$referenceLog]);
  $check($referenceQueries === 3 * count($view->result), 'Independent single-item extraction must run outside the batch scope for all three properties.');

  echo json_encode([
    'total' => (int) $view->total_rows,
    'rows' => count($view->result),
    'execute_relations' => $executeQueries,
    'render_relations' => $renderQueries,
    'reference_relations' => $referenceQueries,
    'highlight_checks' => $highlightChecks,
    'name_checks' => $nameChecks,
    // A request-specific HMAC proves equality without exposing content or IDs.
    // The parent only compares this value; it never prints the signature.
    'cache_signature' => $cacheState === NULL ? NULL : hash_hmac('sha256', serialize([$rowIdentity, $original, $visibleRows]), $scenario['cache_nonce']),
  ], JSON_THROW_ON_ERROR) . "\n";
}
finally {
  foreach ($containerState as [$property, $value]) {
    // Restore only our entry, preserving all other services initialized while
    // rendering. No compiled container definition/cache is saved or cleared.
    $values = $property->getValue($container);
    $values['ddbgo_search.person_relations'] = $value;
    $property->setValue($container, $values);
  }
  if ($cacheState === 'hit' && $cacheBackend !== NULL && $cacheKey !== NULL) {
    // Remove only the result entry generated for this test's random URL key.
    $cacheBackend->delete($cacheKey);
  }
  foreach (array_keys($logKeys) as $key) {
    Database::getLog($key);
  }
  // Drush terminates the kernel after this script. Return to the original
  // anonymous account first, avoiding last-access updates on a real user.
  $accountSwitcher->switchBack();
  $themeManager->setActiveTheme($originalTheme);
  $requestStack->pop();
}
