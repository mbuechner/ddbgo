<?php

/**
 * @file
 * Read-only reset regression; run with drush php:script (see README).
 */

use Drupal\ddbgo_gin\Cache\Context\RememberedViewsFiltersCacheContext;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$request = Request::create('http://localhost:8888/search/kwe');
$session = new Session(new MockArraySessionStorage());
$request->setSession($session);
$stack = new RequestStack();
$stack->push($request);
$context = new RememberedViewsFiltersCacheContext($stack);
$empty = $context->getContext('suche_kwe');
$session->set('views', ['suche_kwe' => ['default' => ['query' => 'Archiv', 'field_sparte' => '1226']]]);
$first = $context->getContext('suche_kwe');
$check($first !== $empty, 'Remembered text and dropdown selection change the cache key');
$session->set('views', ['suche_kwe' => ['default' => ['query' => 'Museum', 'field_sparte' => '1231']]]);
$check($context->getContext('suche_kwe') !== $first, 'New selection in the same session changes the cache key');
$check($context->getContext('personensuche') === $empty, 'Other Views keep their cache keys');
$session->remove('views');
$check($context->getContext('suche_kwe') === $empty, 'Reset restores the empty cache variant');
$check($context->getCacheableMetadata()->getCacheMaxAge() === -1, 'Result caching remains enabled');
echo "PASS: remembered-filter cache variants\n";

$account_switcher = Drupal::service('account_switcher');
$manager = Drupal::theme();
$original_theme = $manager->getActiveTheme();
$account_switcher->switchTo(Drupal\user\Entity\User::load(1));
$manager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
Drupal::requestStack()->push($request);
try {
  foreach (['suche_kwe', 'suche_aggregator', 'suche_aggregator_fuer_claudia', 'personensuche', 'suche_bestand', 'suche_bestand_fuer_europeana', 'suche_bestand_fuer_coding_da_vinci'] as $id) {
    $view = Views::getView($id);
    $display = array_key_first(array_filter($view->storage->get('display'), static fn ($d) => str_starts_with($d['display_options']['path'] ?? '', 'search/')));
    $view->setDisplay($display);
    $view->setRequest($request);
    $view->setExposedInput(['query' => 'Archiv']);
    $view->initHandlers();
    $plugin = $view->display_handler->getPlugin('exposed_form');
    $form = $plugin->renderExposedForm();
    $check(in_array('ddbgo_view_filters:' . $id, $form['#cache']['contexts'] ?? [], TRUE), "$id varies by remembered filters");
    $reset = $form['ddbgo_exposed_filters']['actions']['reset'];
    $check($reset['#attributes']['data-drupal-selector'] === 'edit-reset', "$id reset bypasses Views AJAX");
    $check($reset['#type'] === 'submit', "$id keeps Reset as a submit");
    if ($plugin instanceof Drupal\better_exposed_filters\Plugin\views\exposed_form\BetterExposedFilters) {
      $check($reset['#name'] === 'reset' && !empty($reset['#submit']), "$id retains BEF reset handlers");
    }
    else {
      $check($reset['#name'] === 'op', "$id retains core Views reset detection");
    }
    $check(!in_array('better_exposed_filters/reset_ajax', $form['#attached']['library'] ?? [], TRUE), "$id does not bypass session cleanup");
    echo "PASS: $id reset and cache metadata\n";
  }
}
finally {
  Drupal::requestStack()->pop();
  $manager->setActiveTheme($original_theme);
  $account_switcher->switchBack();
}
