<?php

/**
 * @file
 * Read-only integration checks; run through drush php:script (see README).
 *
 * No form is submitted, no entity is saved and no configuration is imported.
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Template\Attribute;

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $count++;
};

// Do not cache theme detection: one request can render with several themes.
$manager = Drupal::theme();
$original_theme = $manager->getActiveTheme();
try {
  foreach (['gin_frontend', 'gin', 'claro'] as $theme_name) {
    $theme = Drupal::service('theme.initialization')->getActiveThemeByName($theme_name);
    $manager->setActiveTheme($theme);
    foreach (['gin', 'gin_frontend'] as $base) {
      $expected = $theme->getName() === $base || isset($theme->getBaseThemeExtensions()[$base]);
      $check(ddbgo_gin_is_theme($base) === $expected, "Theme inheritance: $theme_name / $base");
    }
  }
}
finally {
  $manager->setActiveTheme($original_theme);
}

foreach ([' KWE ' => 'kwe', 'search/bestand/europeana' => 'bestand', 'person__aggregator' => 'person', 'OTHER' => NULL, '___' => NULL] as $identifier => $expected) {
  $check(ddbgo_gin_normalize_bundle_identifier($identifier) === $expected, "Bundle mapping: $identifier");
}

$attribute = new Attribute(['class' => ['existing']]);
$check(ddbgo_gin_attribute_from_value($attribute) === $attribute, 'Retain Attribute identity');
$check((string) ddbgo_gin_attribute_from_value(['id' => 'field']) === ' id="field"', 'Convert attribute array');
$check((string) ddbgo_gin_attribute_from_value(NULL) === '', 'Empty attributes');

$state = new FormState();
$state->setUserInput(['query' => 'Archiv']);
$form = [
  '#info' => ['filter-query' => ['value' => 'query', 'label' => 'Suche', 'description' => 'Suchhilfe']],
  'query' => ['#type' => 'textfield', '#default_value' => ''],
  'tags' => ['#type' => 'select_tagify'],
  'token' => ['#type' => 'hidden', '#value' => 'preserve'],
  'actions' => ['#type' => 'actions', 'submit' => ['#type' => 'submit']],
];
$built = ddbgo_gin_build_exposed_filter_details($form, $state);
$details = $built['ddbgo_exposed_filters'];
$check($details['#open'] === TRUE, 'Active text filter opens details');
$check($details['query']['#title'] === 'Suche' && $details['query']['#description'] === 'Suchhilfe', 'Preserve Views metadata');
$check($details['query']['#parents'] === ['query'] && $details['query']['#name'] === 'query', 'Preserve submitted filter identifier');
$check(isset($built['tags'], $built['token']) && !isset($built['query']), 'Keep tags and hidden fields outside details');
$check(isset($details['actions']['submit']['#attributes']['data-ddbgo-tag-auto-submit-click']), 'Keep the original Views submit path');
$check(ddbgo_gin_build_exposed_filter_details($built, $state) === $built, 'After-build remains idempotent');

// Exercise label placement branches that are not present in every live form.
$renderer = Drupal::service('renderer');
foreach (['before', 'after', 'invisible', 'none'] as $placement) {
  foreach ([FALSE, TRUE] as $help) {
    $variables = [
      'attributes' => new Attribute(),
      'label' => Markup::create('<label for="field">Field label</label>'),
      'label_display' => $placement,
      'title_display' => $placement,
      'type' => 'textfield',
      'name' => 'field',
      'element' => ['#title' => 'Field label'],
      'children' => Markup::create('<input id="field" aria-describedby="field-help">'),
      'description' => ['content' => 'Help text', 'attributes' => new Attribute(['id' => 'field-help'])],
      'description_display' => 'after',
      'description_toggle' => $help,
    ];
    $html = $renderer->executeInRenderContext(new RenderContext(), static fn () => Drupal::service('twig')->render('@ddbgo_gin/form-element--ddbgo-gin.html.twig', $variables));
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $description = $dom->getElementById('field-help');
    $check($description->hasAttribute('hidden') === ($help && $placement !== 'none'), 'Only tooltip descriptions start hidden in rendered HTML');
    $check(substr_count($html, 'Help text') === 1, 'Description rendered exactly once');
    $check(substr_count($html, 'id="field-help"') === 1, 'Description ID remains unique');
    $check(str_contains($html, 'ddbgo-help-toggle') === ($help && $placement !== 'none'), 'Help follows label visibility');
    $check(str_contains($html, '<label ') === ($placement !== 'none'), 'Preserve absent labels');
    if ($placement !== 'none') {
      $check((strpos($html, '<label ') < strpos($html, '<input ')) === ($placement !== 'after'), 'Preserve label/input reading order');
    }
  }
}

// Flag supplies #title as a render array, including when BigPipe/AJAX replaces
// a bookmark. Keep strings supported, but test the real render-array shape too.
$flag = new class {
  public function id(): string { return 'bookmark'; }
};
$flaggable = new class {
  public function id(): int { return 123; }
};
foreach (['flag' => 'Lesezeichen setzen', 'unflag' => 'Lesezeichen entfernen'] as $action => $label) {
  foreach ([$label, ['#markup' => '<strong>' . $label . '</strong>', '#cache' => ['tags' => ['ddbgo_test:bookmark']]]] as $title) {
    foreach (['ddbgo_page_actions', 'default'] as $view_mode) {
      $context = new RenderContext();
      $variables = [
        'flag' => $flag,
        'flaggable' => $flaggable,
        'action' => $action,
        'title' => $title,
        'view_mode' => $view_mode,
        'ddbgo_bookmark_in_header' => FALSE,
        'attributes' => new Attribute(['class' => ['use-ajax'], 'href' => '/flag/test', 'data-test-preserved' => 'yes']),
      ];
      $html = $renderer->executeInRenderContext($context, static fn () => Drupal::service('twig')->render('@ddbgo_gin/flag--ddbgo-gin.html.twig', $variables));
      $dom = new DOMDocument();
      @$dom->loadHTML($html);
      $xpath = new DOMXPath($dom);
      $link = $xpath->query('//a')->item(0);
      $check($link !== NULL && trim($link->textContent) === $label, 'Bookmark action name retained for both input types and states');
      $check(str_contains($link->getAttribute('class'), 'use-ajax') && $link->getAttribute('href') === '/flag/test' && $link->getAttribute('data-test-preserved') === 'yes', 'Bookmark AJAX/link attributes retained');
      $hidden = $xpath->query('//a/span[@class="visually-hidden"]')->length;
      $check($hidden === 0, 'Bookmark action name stays visible in header and lists');
      if ($view_mode === 'ddbgo_page_actions') {
        $check($link->getAttribute('title') === $label && str_contains($link->getAttribute('class'), 'ddbgo-bookmark-toggle'), 'Header tooltip contains rendered plain text');
      }
      if (is_array($title)) {
        $check(!$context->isEmpty() && in_array('ddbgo_test:bookmark', $context->pop()->getCacheTags(), TRUE), 'Bookmark title cache metadata bubbles to its parent');
      }
    }
  }
}

echo "PASS: $count PHP/Twig integration checks. No content changed.\n";
