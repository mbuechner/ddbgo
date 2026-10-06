<?php

/**
 * @file
 * Read-only regression for native details and Gin's valid focus helpers.
 *
 * Run after drush cr:
 *   drush php:script web/modules/custom/ddbgo_gin/tests/php/html-markup.test.php
 *
 * Uses actual library discovery and unsaved node.add forms. No configuration
 * write, validation, submission or entity save is performed.
 */

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this rendering check through Drush.');
$scripts = static function (string $extension, string $library): array {
  return array_column(Drupal::service('library.discovery')->getLibraryByName($extension, $library)['js'], 'data');
};
$find = static function (array $scripts, string $suffix): int|false {
  foreach ($scripts as $index => $script) {
    if (str_ends_with($script, $suffix)) {
      return $index;
    }
  }
  return FALSE;
};

$collapse = $scripts('core', 'drupal.collapse');
$check($find($collapse, '/details-aria.js') === FALSE, 'Core does not restore invalid summary ARIA state on click.');
$check($find($collapse, '/details.js') !== FALSE && $find($collapse, '/details-summarized-content.js') !== FALSE, 'Core keeps its details and summary-text behaviors.');
$actions = $scripts('gin', 'more_actions');
$original = $find($actions, '/gin/dist/js/more_actions.js');
$local = $find($actions, '/ddbgo_gin/js/ddbgo_gin.focus-helpers.js');
$check($original !== FALSE && $local !== FALSE && $original < $local, 'Local focus helper loads after the original Gin actions implementation.');
$scrollsync = $scripts('gin', 'scrollsync');
$check($find($scrollsync, '/ddbgo_gin/js/ddbgo_gin.scrollsync.js') !== FALSE && $find($scrollsync, '/gin/js/libs/scrollsync.js') === FALSE, 'Gin scroll synchronization uses the valid data-attribute implementation.');
$tab_validation = $scripts('field_group', 'tab_validation');
$check($find($tab_validation, '/ddbgo_gin/js/ddbgo_gin.tab-validation.js') !== FALSE && $find($tab_validation, '/field_group/js/field_group.tab_validation.js') === FALSE, 'FieldGroup validation reveals closed details using native open state.');

$theme_manager = Drupal::theme();
$original_theme = $theme_manager->getActiveTheme();
$switcher = Drupal::service('account_switcher');
$stack = Drupal::requestStack();
$switcher->switchTo(new UserSession(['uid' => 1]));
try {
  foreach (['gin_frontend', 'gin'] as $theme_name) {
    $theme_manager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme_name));
    $request = Request::create('http://localhost/node/add/kwe');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set('_route', 'node.add');
    $request->attributes->set('_route_object', Drupal::service('router.route_provider')->getRouteByName('node.add'));
    $request->attributes->set('node_type', NodeType::load('kwe'));
    $request->attributes->set('_raw_variables', new ParameterBag(['node_type' => 'kwe']));
    $stack->push($request);
    try {
      $node = Node::create(['type' => 'kwe', 'title' => 'Unsaved native HTML fixture']);
      $form = Drupal::service('entity.form_builder')->getForm($node, 'default');
      $helper = (string) ($form['gin_move_focus_to_sticky_bar']['#markup'] ?? '');
      $check(str_contains($helper, ' data-gin-move-focus-to-sticky-bar') && !str_contains($helper, ' gin-move-focus-to-sticky-bar'), "$theme_name: actual form after-build uses the valid focus marker.");
      $html = (string) Drupal::service('renderer')->renderInIsolation($form);
      $document = new DOMDocument();
      @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
      $xpath = new DOMXPath($document);
      $check($xpath->query('//a[@data-gin-move-focus-to-sticky-bar]')->length === 1, "$theme_name: one native focus-transfer link remains.");
      $check($xpath->query('//*[@gin-move-focus-to-sticky-bar]')->length === 0, "$theme_name: invalid original marker is absent from rendered form.");
      $summaries = $xpath->query('//details/summary');
      $check($summaries->length > 0, "$theme_name: real form exercises native details summaries.");
      foreach ($summaries as $summary) {
        $check(!$summary->hasAttribute('role') && !$summary->hasAttribute('aria-expanded'), "$theme_name: native summary does not override its role or expanded state.");
      }
      $check($xpath->query('//details[not(@open)]')->length > 0, "$theme_name: closed native details remain available.");
      $check($node->isNew() && $node->id() === NULL, "$theme_name: form fixture remains unsaved.");
    }
    finally {
      $stack->pop();
    }
  }
}
finally {
  $theme_manager->setActiveTheme($original_theme);
  $switcher->switchBack();
}

printf("PASS: %d HTML/library checks in Gin Frontend/Gin; no configuration or content changed.\n", $checks);
