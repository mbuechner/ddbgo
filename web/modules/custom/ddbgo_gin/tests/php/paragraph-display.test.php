<?php

/**
 * @file
 * Read-only rendering checks for paragraph lists; run through drush php:script.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Yaml\Yaml;

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$object = static fn ($count) => Paragraph::create([
  'type' => 'ddb_objekte',
  'field_medientyp' => ['entity' => Term::create(['vid' => 'medientyp', 'name' => 'Bild'])],
  'field_anzahl_der_objekte' => $count,
]);
$tier = static fn ($level, $count) => Paragraph::create([
  'type' => 'europeana_objekte_content_tier',
  'field_content_tier' => ['entity' => Term::create(['vid' => 'content_tier', 'name' => $level])],
  'field_objekte' => [['entity' => $object($count)]],
]);
$node = Node::create([
  'type' => 'bestand',
  'field_ddb_objekte' => [['entity' => $object(3792)]],
  'field_europeana_objekte_content_' => [['entity' => $tier('0', 454)], ['entity' => $tier('1', 3338)]],
  'field_europeana_objekte_metadata' => [['entity' => Paragraph::create([
    'type' => 'europeana_objekte_metadata_tier',
    'field_metadata_tier' => ['entity' => Term::create(['vid' => 'metadata_tier', 'name' => 'A'])],
    'field_objekte' => [['entity' => $object(3792)]],
  ])]],
]);
$fields = [
  'field_ddb_objekte' => ['DDB-Objekte', 'Medientyp', 'Bild', 'Anzahl der Objekte', '3.792'],
  'field_europeana_objekte_content_' => ['Europeanas Content-Tier', 'Content-Tier', 'Objekte', '454', '3.338', 'Bild'],
  'field_europeana_objekte_metadata' => ['Europeanas Metadata-Tier', 'Metadata-Tier', 'Objekte', 'A', '3.792', 'Bild'],
];
$display = EntityViewDisplay::load('node.bestand.default');
$source = Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/core.entity_view_display.node.bestand.default.yml');
$switcher = Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
$theme = Drupal::theme()->getActiveTheme();
Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
try {
  $full_display = EntityViewDisplay::collectRenderDisplay($node, 'full');
  $list_display = EntityViewDisplay::collectRenderDisplay($node, 'listenansicht');
  $build = ['#view_mode' => 'full'];
  foreach ($fields as $field => $expected) {
    $check($full_display->getComponent($field)['settings']['view_mode'] === 'ddbgo_object_details', 'Full Bestand selects the isolated object mode');
    $check($display->getComponent($field)['settings']['view_mode'] === 'default', 'Shared default display is unchanged');
    $check(($list_display->getComponent($field)['settings']['view_mode'] ?? '') !== 'ddbgo_object_details', 'List display retains its original paragraph mode');
    $build[$field] = $node->get($field)->view($full_display->getComponent($field));
  }
  ddbgo_gin_node_view_alter($build, $node, $full_display);
  foreach ($fields as $field => $expected) {
    foreach ([$display->toArray(), $source] as $config) {
      $check($config['content'][$field]['label'] === 'inline', "$field has a visible label");
      $check(in_array($field, $config['third_party_settings']['field_group']['group_europenana_archivportal']['children'], TRUE), "$field is directly in the field list");
    }
    $field_build = $build[$field];
    $html = (string) Drupal::service('renderer')->renderInIsolation($field_build);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $check(str_contains($html, 'ddbgo-object-list'), 'Full display has an explicit styling scope');
    $check(str_contains($html, 'paragraph--view-mode--ddbgo-object-details'), 'Object paragraph uses its own view mode');
    foreach ($expected as $text) {
      $check(str_contains($dom->textContent, $text), "$field retains $text");
    }
    $check($xpath->query('//details | //*[@hidden]')->length === 0, 'All paragraph data is expanded in the initial HTML');
    if ($field === 'field_europeana_objekte_content_') {
      $labels = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " field--name-field-content-tier ")]');
      $check($labels->length === 2 && str_contains($labels->item(0)->textContent, '0') && str_contains($labels->item(1)->textContent, '1'), 'Both tiers, including zero, retain their order');
    }
  }
  foreach (['default', 'listenansicht', 'preview', 'teaser'] as $mode) {
    $other = ['#view_mode' => $mode, 'field_ddb_objekte' => []];
    ddbgo_gin_node_view_alter($other, $node, $display);
    $check(!isset($other['field_ddb_objekte']['#attributes']), "$mode does not opt into object styling");
  }
  $paragraph = $object(3792);
  foreach (['default', 'preview', 'listenansicht'] as $mode) {
    $preview = Drupal::entityTypeManager()->getViewBuilder('paragraph')->view($paragraph, $mode);
    $html = (string) Drupal::service('renderer')->renderInIsolation($preview);
    $check(!str_contains($html, 'ddbgo-object-list') && !str_contains($html, 'paragraph--view-mode--ddbgo-object-details'), "$mode paragraph remains independent");
  }

  // Build the real edit widgets, including nested inputs and add/remove actions.
  // No submit handler is called and no fixture entity is saved.
  $form = Drupal::service('entity.form_builder')->getForm($node, 'edit');
  foreach (array_keys($fields) as $field) {
    $check(in_array('ddbgo-object-widget', $form[$field]['#attributes']['class'] ?? [], TRUE), "$field widget opts in");
    $check(!isset($form[$field]['widget'][0]['subform']['field_objekte']['#attributes']['class']) || !in_array('ddbgo-object-widget', $form[$field]['widget'][0]['subform']['field_objekte']['#attributes']['class'], TRUE), 'Nested widget is not independently marked');
  }
  foreach (['field_personen', 'field_kontakt'] as $field) {
    $check(isset($form[$field]), "$field remains in the form");
    $check(!in_array('ddbgo-object-widget', $form[$field]['#attributes']['class'] ?? [], TRUE), "$field widget stays outside the object layout");
  }
  $html = (string) Drupal::service('renderer')->renderInIsolation($form);
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  $xpath = new DOMXPath($dom);
  $scopes = '//*[contains(concat(" ", normalize-space(@class), " "), " ddbgo-object-widget ")]';
  $check($xpath->query($scopes)->length === 3, 'Exactly three rendered widget scopes');
  $check($xpath->query($scopes . '//input[@type="number"]')->length === 4, 'All four object count inputs remain editable');
  $check($xpath->query($scopes . '//*[contains(@class,"field-add-more-submit")]')->length > 0, 'Add actions remain available');
  $check($xpath->query($scopes . '//*[contains(@class,"ddbgo-object-list")]')->length === 0, 'Form does not inherit read-only object styling');

  $empty_form = Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'bestand']), 'default');
  foreach (array_keys($fields) as $field) {
    $check(in_array('ddbgo-object-widget', $empty_form[$field]['#attributes']['class'] ?? [], TRUE), 'Empty add widgets have the same scope');
    $check((string) $empty_form[$field]['widget']['title']['#value'] === (string) ddbgo_gin_object_field_labels()[$field], 'Empty widget retains its local title');
  }

  $full = Drupal::entityTypeManager()->getViewBuilder('node')->view($node, 'full');
  $full_html = (string) Drupal::service('renderer')->renderInIsolation($full);
  $full_dom = new DOMDocument();
  @$full_dom->loadHTML('<?xml encoding="UTF-8">' . $full_html);
  $full_xpath = new DOMXPath($full_dom);
  $check($full_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ddbgo-object-list ")]')->length === 3, 'Real node rendering invokes the scope hook for exactly three fields');

  Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('claro'));
  $other_display = EntityViewDisplay::collectRenderDisplay($node, 'full');
  $check($other_display->getComponent('field_ddb_objekte')['settings']['view_mode'] === 'default', 'Other themes keep default paragraph rendering even after a frontend render');
  $other_form = Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'bestand']), 'default');
  $check(!in_array('ddbgo-object-widget', $other_form['field_ddb_objekte']['#attributes']['class'] ?? [], TRUE), 'Other themes do not opt into form styling');
  echo "PASS: object data, nested order, full/list/preview isolation, editable form inputs, person/contact exclusion and theme isolation. No entities saved.\n";
}
finally {
  Drupal::theme()->setActiveTheme($theme);
  $switcher->switchBack();
}
