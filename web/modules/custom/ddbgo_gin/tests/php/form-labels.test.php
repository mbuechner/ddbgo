<?php

/**
 * @file
 * Read-only checks of real node/Paragraphs form labels in Gin and Claro.
 *
 * Run: .\drush.cmd php:script web/modules/custom/ddbgo_gin/tests/php/form-labels.test.php
 * Rebuild Drupal's cache first after changing hook or template definitions.
 * Fixtures are new, unsaved entities. No validation, submission, save, delete
 * or configuration import is performed. These HTML checks do not replace a
 * browser/screenreader check of the form's interaction or appearance.
 */

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this rendering check through Drush.');
$class = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")';
$render = static function (array $build): DOMXPath {
  $html = (string) Drupal::service('renderer')->renderInIsolation($build);
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  return new DOMXPath($dom);
};

// Keep explicit expectations independent of the production helper: a missing
// entry in the implementation must not silently remove its test coverage.
$object_fields = ['field_ddb_objekte', 'field_europeana_objekte_content_', 'field_europeana_objekte_metadata'];
$groups = [
  'person' => ['field_email'],
  'kwe' => ['field_email', 'field_personen', 'field_kontakt'],
  'aggregator' => ['field_personen', 'field_kontakt'],
  'bestand' => array_merge(['field_personen', 'field_kontakt'], $object_fields),
];
$dates = [
  'kwe' => ['field_erstingest_archivportal', 'field_erstingest_europeana'],
  'bestand' => ['field_erstingest', 'field_datum_des_status_der_europ'],
];
$object = static fn (): Paragraph => Paragraph::create([
  'type' => 'ddb_objekte',
  'field_anzahl_der_objekte' => 123,
]);
$fixture = static function (string $bundle) use ($dates, $object): Node {
  $node = Node::create(['type' => $bundle, 'title' => 'Unsaved form label fixture']);
  if (in_array($bundle, ['person', 'kwe'], TRUE)) {
    $node->set('field_email', [['value' => 'first@example.invalid'], ['value' => 'second@example.invalid']]);
  }
  if ($bundle !== 'person') {
    $node->set('field_personen', [['entity' => Paragraph::create([
      'type' => 'person_' . $bundle,
      'field_person' => ['entity' => Node::create(['type' => 'person', 'title' => 'Unsaved person'])],
    ])]]);
    $node->set('field_kontakt', [['entity' => Paragraph::create([
      'type' => 'kontakt',
      'field_bemerkung' => 'Unsaved contact',
      'field_datum' => '2026-09-25',
    ])]]);
  }
  foreach ($dates[$bundle] ?? [] as $field) {
    $node->set($field, '2026-09-25');
  }
  if ($bundle === 'bestand') {
    $node->set('field_ddb_objekte', [['entity' => $object()]]);
    foreach ([
      'field_europeana_objekte_content_' => 'europeana_objekte_content_tier',
      'field_europeana_objekte_metadata' => 'europeana_objekte_metadata_tier',
    ] as $field => $paragraph_bundle) {
      $node->set($field, [['entity' => Paragraph::create([
        'type' => $paragraph_bundle,
        'field_objekte' => [['entity' => $object()]],
      ])]]);
    }
  }
  return $node;
};

/**
 * Check actual header semantics, styling classes and individual control labels.
 */
$group_label = static function (DOMXPath $xpath, DOMElement $scope, string $tag, string $case) use ($check, $class): void {
  $table = $xpath->query('.//table', $scope)->item(0);
  $check($table instanceof DOMElement, "$case: multiple-value table remains present.");
  $header = $xpath->query('./thead/tr/th[1]', $table)->item(0);
  $check($header instanceof DOMElement, "$case: group label remains in a table header.");
  $labels = $xpath->query('.//' . $tag . '[' . $class('form-item__label--multiple-value-form') . ']', $header);
  $check($labels->length === 1 && trim($labels->item(0)->textContent) !== '', "$case: nonempty group label uses $tag with Claro/Gin classes.");
  if ($tag === 'span') {
    $check($xpath->query('.//h4', $scope)->length === 0, "$case: targeted Gin widget has no h4 headings.");
  }

  // Original controls, including row weights and nested fields, must retain
  // their labels. Generated Select2 controls are covered by the JS fixture.
  $controls = $xpath->query('.//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | .//select | .//textarea', $scope);
  $check($controls->length > 0, "$case: editable controls remain present.");
  foreach ($controls as $control) {
    $id = $control->getAttribute('id');
    $check($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length > 0, "$case: control $id keeps its individual label.");
  }
};

$switcher = Drupal::service('account_switcher');
$theme = Drupal::theme()->getActiveTheme();
$switcher->switchTo(new UserSession(['uid' => 1]));
try {
  foreach (['gin_frontend', 'gin', 'claro'] as $theme_name) {
    Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme_name));
    $tag = $theme_name === 'claro' ? 'h4' : 'span';
    foreach ($groups as $bundle => $fields) {
      $node = $fixture($bundle);
      $form = Drupal::service('entity.form_builder')->getForm($node, 'default');
      foreach ($fields as $field) {
        $check(!empty($form[$field]['widget']['#ddbgo_field_group_label']), "$theme_name/$bundle/$field: real widget receives the scoped label marker.");
      }
      if ($bundle === 'bestand') {
        foreach (['field_europeana_objekte_content_', 'field_europeana_objekte_metadata'] as $field) {
          $check(!empty($form[$field]['widget'][0]['subform']['field_objekte']['widget']['#ddbgo_field_group_label']), "$theme_name/$field: nested object widget receives the marker.");
        }
      }
      $xpath = $render($form);
      if ($theme_name !== 'claro') {
        // Core's node metadata items contain text, not labelable controls.
        // Their titles keep Gin's label styling without invalid for targets.
        foreach (['entity-meta__last-saved' => t('Last saved'), 'entity-meta__author' => t('Author')] as $metadata_class => $title) {
          $scope = $xpath->query('//*[' . $class($metadata_class) . ']')->item(0);
          $check($scope instanceof DOMElement, "$theme_name/$bundle/$metadata_class: read-only metadata remains.");
          $titles = $xpath->query('./span[' . $class('form-item__label') . ']', $scope);
          $check($titles->length === 1 && trim($titles->item(0)->textContent) === (string) $title, "$theme_name/$bundle/$metadata_class: metadata title is styled text.");
          $check($xpath->query('.//label', $scope)->length === 0, "$theme_name/$bundle/$metadata_class: no label points to a non-control.");
        }
        $last_saved = $xpath->query('//*[' . $class('entity-meta__last-saved') . ']')->item(0);
        $check(str_contains($last_saved->textContent, (string) t('Not saved yet')), "$theme_name/$bundle: last-saved value remains visible.");
        $author = $xpath->query('//*[' . $class('entity-meta__author') . ']')->item(0);
        $check(str_contains($author->textContent, (string) $node->getOwner()->getDisplayName()), "$theme_name/$bundle: author value remains visible.");
        // Person titles are generated automatically; use its visible first name.
        $input_name = $bundle === 'person' ? 'field_vorname[0][value]' : 'title[0][value]';
        $input = $xpath->query('//input[@name="' . $input_name . '"]')->item(0);
        $check($input instanceof DOMElement && $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length === 1, "$theme_name/$bundle: editable text retains its native label.");
      }
      foreach ($fields as $field) {
        $scopes = $xpath->query('//*[' . $class('field--name-' . str_replace('_', '-', $field)) . ']');
        $check($scopes->length === 1, "$theme_name/$bundle/$field: exactly one outer widget.");
        $group_label($xpath, $scopes->item(0), $tag, "$theme_name/$bundle/$field");
      }
      if ($bundle === 'bestand') {
        $nested = $xpath->query('//*[' . $class('field--name-field-objekte') . ']');
        $check($nested->length === 2, "$theme_name: both nested Europeana object groups are built.");
        foreach ($nested as $scope) {
          $group_label($xpath, $scope, $tag, "$theme_name/nested-objects");
        }
      }

      // Date-only widgets use a different wrapper than multiple-value tables.
      // Test every requested date, including the date inside each Kontakt.
      $date_fields = $dates[$bundle] ?? [];
      if ($bundle !== 'person') {
        $date_fields[] = 'field_datum';
      }
      foreach ($date_fields as $field) {
        $scope = $xpath->query('//*[' . $class('field--name-' . str_replace('_', '-', $field)) . ']')->item(0);
        $check($scope instanceof DOMElement, "$theme_name/$bundle/$field: date widget exists.");
        $labels = $xpath->query('.//' . $tag . '[' . $class('form-item__label') . ']', $scope);
        $check($labels->length === 1 && trim($labels->item(0)->textContent) !== '', "$theme_name/$field: date label uses $tag with its styling class.");
        $check($xpath->query('.//input[@type="date"]', $scope)->length === 1, "$theme_name/$field: native date input remains.");
        if ($tag === 'span') {
          $check($xpath->query('.//h4', $scope)->length === 0, "$theme_name/$field: date label is not a heading.");
        }
      }
      $check($node->isNew() && $node->id() === NULL, "$theme_name/$bundle: rendering did not persist the fixture.");
    }

    // Empty Paragraphs use their own <strong> title instead of a table. Keep
    // that path intact, including after populated nested widgets were rendered.
    $empty = Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'bestand']), 'default');
    $xpath = $render($empty);
    foreach ($groups['bestand'] as $field) {
      $scope = $xpath->query('//*[' . $class('field--name-' . str_replace('_', '-', $field)) . ']')->item(0);
      $check($scope instanceof DOMElement, "$theme_name/$field: empty Paragraphs widget remains.");
      $check($xpath->query('.//strong', $scope)->length === 1 && $xpath->query('.//h4', $scope)->length === 0, "$theme_name/$field: empty title stays strong.");
    }

    // Simulate a required version of the real email widget in memory. This
    // checks required-marker preservation without editing field configuration.
    $required = Drupal::service('entity.form_builder')->getForm($fixture('person'), 'default');
    $required['field_email']['widget']['#required'] = TRUE;
    $xpath = $render($required);
    $required_labels = $xpath->query('//th//' . $tag . '[' . $class('form-required') . ' and ' . $class('js-form-required') . ']');
    $check($required_labels->length === 1, "$theme_name: changing the tag retains both required-marker classes.");
  }
  printf("PASS: %d form-label checks across Gin Frontend, Gin and Claro; no entities saved or submitted.\n", $checks);
}
finally {
  Drupal::theme()->setActiveTheme($theme);
  $switcher->switchBack();
}
