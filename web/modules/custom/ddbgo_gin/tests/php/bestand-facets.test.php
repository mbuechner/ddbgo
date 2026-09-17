<?php

/**
 * @file
 * Read-only integration regression; run with drush php:script after enabling
 * facets_exposed_filters. Tests the checked-in View without saving config.
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\search_api\Entity\Index;
use Drupal\views\Views;
use Drush\Drush;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Yaml\Yaml;

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$normalize = static function (array $values): array {
  $values = array_values(array_unique(array_map('strval', $values)));
  sort($values);
  return $values;
};

// Each scenario needs its own request/process, as Facets caches per-request
// state in statics. No nodes, terms, users or active configuration are saved.
if (empty($extra)) {
  Drupal::service('account_switcher')->switchTo(new UserSession(['uid' => 999999999, 'roles' => ['authenticated']]));
  try {
    $items = Index::load('bestand')->query()->range(0, 10000)->execute()->getResultItems();
    $tags = [];
    foreach ($items as $item) {
      $entity = $item->getOriginalObject()->getValue();
      $candidate = array_column($entity->get('field_bestandstags')->getValue(), 'target_id');
      if (count($candidate) >= 2) {
        $tags = array_map('strval', array_slice($candidate, 0, 2));
        break;
      }
    }
    $check(count($tags) === 2, 'An indexed Bestand with at least two tags is required.');
  }
  finally {
    Drupal::service('account_switcher')->switchBack();
  }
  $scenarios = [
    'full-page' => ['input' => ['query' => ''], 'full_page' => TRUE],
    'full-page-cached' => ['input' => ['query' => ''], 'full_page' => TRUE],
    'all' => ['input' => ['query' => '']],
    'one' => ['input' => ['query' => '', 'field_bestandstags' => [$tags[0]]]],
    'two' => ['input' => ['query' => '', 'field_bestandstags' => $tags]],
    'remove-one' => ['input' => ['query' => '', 'field_bestandstags' => [$tags[1]]], 'remembered' => ['query' => '', 'field_bestandstags' => $tags]],
    'remove-last' => ['input' => ['query' => ''], 'remembered' => ['query' => '', 'field_bestandstags' => $tags]],
    'fulltext' => ['input' => ['query' => 'Archiv', 'field_bestandstags' => [$tags[0]]]],
    'zero' => ['input' => ['query' => 'zzzzNoDDBgoMatchzzzz', 'field_bestandstags' => $tags]],
    'remembered' => ['input' => [], 'remembered' => ['query' => '', 'field_bestandstags' => $tags]],
    'tag-link' => ['input' => ['query' => '', 'field_bestandstags' => [$tags[0]]], 'remembered' => ['query' => 'zzzzNoDDBgoMatchzzzz', 'field_bestandstags' => [$tags[1]]]],
    'deleted-tag' => ['input' => ['query' => '', 'field_bestandstags' => ['2147483647']], 'missing' => TRUE],
    'pager' => ['input' => ['query' => '', 'field_bestandstags' => [$tags[0]], 'page' => 1]],
  ];
  $results = [];
  foreach ($scenarios as $name => $scenario) {
    $child = Drush::drush(Drush::aliasManager()->getSelf(), 'php:script', [__FILE__, base64_encode(json_encode($scenario))]);
    $child->mustRun();
    $check(trim($child->getErrorOutput()) === '', "$name emitted a warning: " . $child->getErrorOutput());
    $results[$name] = json_decode($child->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);
    echo "PASS: $name (" . $results[$name]['total'] . " hits, " . count($results[$name]['options']) . " tag options)\n";
  }
  $check($results['two']['total'] > 0, 'The two-tag fixture must match indexed content.');
  $check($results['full-page'] === $results['all'] && $results['full-page-cached'] === $results['all'], 'The active full page and repeated requests expose the same tag choices.');
  $check(count($results['one']['options']) < count($results['all']['options']), 'Selecting a tag narrows the available tags.');
  $check($results['two']['total'] <= $results['one']['total'], 'AND semantics narrow the results.');
  $check($results['remove-last'] === $results['all'], 'Removing the final tag restores all results and choices.');
  $check($results['remembered'] === $results['two'], 'Remembered filters restore both results and choices.');
  $check($results['tag-link'] === $results['one'], 'Existing ID-based tag links override remembered filters.');
  $check($results['pager'] === $results['one'], 'Options come from all matching results, independent of pagination.');
  $check($results['zero']['total'] === 0 && $results['deleted-tag']['total'] === 0, 'Empty searches stay empty.');
  echo "PASS: narrowing, AND, removal, sessions, links, empty results and pagination. No content changed.\n";
  return;
}

$scenario = json_decode(base64_decode($extra[0]), TRUE, 512, JSON_THROW_ON_ERROR);
$request = Request::create('http://localhost:8888/search/bestand', 'GET', $scenario['input']);
$session = new Session(new MockArraySessionStorage());
if (isset($scenario['remembered'])) {
  $session->set('views', ['suche_bestand' => ['default' => $scenario['remembered']]]);
}
$request->setSession($session);
Drupal::requestStack()->push($request);
Drupal::service('account_switcher')->switchTo(new UserSession(['uid' => 999999999, 'roles' => ['authenticated']]));
Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
$data = Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/views.view.suche_bestand.yml');
$view = Views::getView('suche_bestand');
// Full-page checks use active configuration, including Views' cached plugin
// definitions, to detect missing/broken filters after configuration imports.
if (empty($scenario['full_page'])) {
  $view->storage->set('display', $data['display']);
}
$view->setDisplay('page');
$view->setRequest($request);
$effective_input = $view->getExposedInput();
$selected = $normalize((array) ($effective_input['field_bestandstags'] ?? []));
$page_html = NULL;
if (!empty($scenario['full_page'])) {
  $build = $view->buildRenderable('page');
  $page_html = Drupal::service('renderer')->executeInRenderContext(new RenderContext(), static function () use (&$build): string {
    return (string) Drupal::service('renderer')->render($build);
  });
  $check(in_array('tagify/default', $build['#attached']['library'] ?? [], TRUE), 'Full page includes the Tagify library.');
}
else {
  $view->execute();
}
$form = $view->exposed_widgets;
$check(isset($form['field_bestandstags']), 'Tag filter exists. Rebuild Drupal caches after importing configuration.');
$tag = $form['field_bestandstags'];
$check(($tag['#type'] ?? '') === 'select_tagify', 'Tagify remains visible, including empty results.');
$check($normalize(array_values($tag['#value'])) === $selected, 'All selected tags remain removable.');
$check($normalize(array_values($session->get('views')['suche_bestand']['default']['field_bestandstags'])) === $selected, 'Views remembers the complete selection.');
$check(isset($tag['#attributes']['data-ddbgo-tag-auto-submit']), 'Native Tagify change submission stays attached.');
$check(isset($form['ddbgo_exposed_filters']['actions']['submit']['#attributes']['data-ddbgo-tag-auto-submit-click']), 'Existing submit button remains the submission path.');
$check($form['ddbgo_exposed_filters']['actions']['reset']['#attributes']['data-drupal-selector'] === 'edit-reset', 'Reset retains server-side session cleanup.');
$check(empty($view->build_info['abort']) && !Drupal::messenger()->messagesByType('error'), 'No form validation errors.');

// Compare against the previous native taxonomy filter, without faceting and
// without pagination. This independently checks both results and tag choices.
$expected = $selected;
if (empty($scenario['missing'])) {
  $reference = Views::getView('suche_bestand');
  $reference->storage->set('id', 'ddbgo_bestand_reference');
  $reference->storage->set('display', $data['display']);
  $reference->setDisplay('page');
  $filters = $reference->display_handler->getOption('filters');
  $filters['field_bestandstags'] = array_replace($filters['field_bestandstags'], [
    'field' => 'field_bestandstags', 'plugin_id' => 'search_api_term',
    'operator' => 'and', 'vid' => 'bestandstags', 'type' => 'select',
    'limit' => TRUE, 'hierarchy' => FALSE, 'error_message' => FALSE,
  ]);
  $reference->display_handler->setOption('filters', $filters);
  $exposed = $reference->display_handler->getOption('exposed_form');
  $exposed['options']['bef']['filter']['field_bestandstags']['plugin_id'] = 'bef_tagify_select';
  $reference->display_handler->setOption('exposed_form', $exposed);
  $reference->display_handler->setOption('cache', ['type' => 'search_api_none', 'options' => []]);
  $reference->setRequest($request);
  $reference->setExposedInput($effective_input);
  $reference->setItemsPerPage(0);
  $reference->setCurrentPage(0);
  $reference->execute();
  $check((int) $view->total_rows === (int) $reference->total_rows, 'Facet and original taxonomy filter return the same total.');
  foreach ($reference->result as $row) {
    $entity = $row->_item->getOriginalObject()->getValue();
    foreach ($entity->get('field_bestandstags')->referencedEntities() as $term) {
      if ($term->access('view')) {
        $expected[] = $term->id();
      }
    }
  }
}
else {
  $check((int) $view->total_rows === 0, 'A deleted tag does not silently broaden the search.');
}
$options = $normalize(array_diff(array_keys($tag['#options']), ['All']));
$check($options === $normalize($expected), 'Every offered tag occurs in the complete matching result set; selected tags are retained.');

// Render the real form: selected labels, field name and Tagify attributes must
// survive the normal theme pipeline, including zero-result searches.
$html = $page_html ?? (string) Drupal::service('renderer')->renderInIsolation($form);
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$check($xpath->query('//select[@name="field_bestandstags[]"]')->length === 1, 'Existing GET parameter is preserved.');
$check($xpath->query('//select[@name="field_bestandstags[]"]/ancestor::details')->length === 0, 'Tag field is visible outside the collapsible filters.');
$check($xpath->query('//select[@name="field_bestandstags[]"]/option[@selected]')->length === count($selected), 'Rendered selection survives rebuilding.');

if (isset($scenario['remembered'])) {
  $before_reset = $session->get('views');
  $view->display_handler->getPlugin('exposed_form')->resetForm($form, new FormState());
  $after_reset = $session->get('views');
  $check(empty($after_reset['suche_bestand']['default']), 'Native BEF reset clears remembered text and tags.');
  unset($before_reset['suche_bestand'], $after_reset['suche_bestand']);
  $check($before_reset === $after_reset, 'Reset does not change remembered filters of other views.');
}
echo json_encode(['total' => (int) $view->total_rows, 'options' => $options]);
