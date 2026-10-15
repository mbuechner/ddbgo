<?php

/**
 * @file
 * Read-only HTML checks for inline errors on real node/Paragraphs forms.
 *
 * Run: .\drush.cmd php:script web/modules/custom/ddbgo_gin/tests/php/form-errors.test.php
 * Rebuild Drupal's cache after changing hooks or element definitions first.
 * Errors are inserted into render arrays in memory. No form is validated or
 * submitted, no entity is saved, and no configuration is changed. Interaction
 * and screen-reader announcements still require a manual browser check.
 */

use Drupal\Core\Render\Element;
use Drupal\Core\Session\UserSession;
use Drupal\ddbgo_gin\Render\FormErrorAccessibility;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this read-only rendering check through Drush.');
$render = static function (array $build): DOMXPath {
  $html = (string) Drupal::service('renderer')->renderInIsolation($build);
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  return new DOMXPath($dom);
};
$references = static fn (DOMElement $element): array => preg_split('/\s+/', trim($element->getAttribute('aria-describedby')));

// Find a control in the real widget rather than depending on a contrib
// widget's internal delta nesting. Date groups are targeted separately.
$error_in_widget = static function (array &$element, string $message, array $types) use (&$error_in_widget): ?array {
  if (in_array($element['#type'] ?? '', $types, TRUE) && !empty($element['#id'])) {
    $element['#errors'] = $message;
    $element['#validated'] = TRUE;
    return [$element['#id'], $element['#attributes']['aria-describedby'] ?? ''];
  }
  foreach (Element::children($element) as $key) {
    if ($result = $error_in_widget($element[$key], $message, $types)) {
      return $result;
    }
  }
  return NULL;
};

$switcher = Drupal::service('account_switcher');
$original_theme = Drupal::theme()->getActiveTheme();
$switcher->switchTo(new UserSession(['uid' => 1]));
try {
  foreach (['gin_frontend', 'gin', 'claro'] as $theme_name) {
    Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme_name));
    $is_gin = $theme_name !== 'claro';
    foreach (['kwe' => 'field_isil', 'aggregator' => 'field_jira_hauptticket', 'person' => 'field_telefonnummer', 'bestand' => 'field_jira_europeana'] as $bundle => $field) {
      $node = Node::create(['type' => $bundle, 'title' => 'Unsaved error fixture']);
      if ($bundle !== 'person') {
        $node->set('field_personen', [['entity' => Paragraph::create(['type' => 'person_' . $bundle])]]);
        $node->set('field_kontakt', [['entity' => Paragraph::create([
          'type' => 'kontakt', 'field_bemerkung' => 'Unsaved contact', 'field_datum' => '2026-10-01',
        ])]]);
      }
      $form = Drupal::service('entity.form_builder')->getForm($node, 'default');
      $check(isset($form['#attributes']['data-ddbgo-form-status']) === $is_gin, "$theme_name/$bundle: status scope follows the active theme.");
      $check(in_array('ddbgo_gin/form_status', $form['#attached']['library'] ?? [], TRUE) === $is_gin, "$theme_name/$bundle: status library follows the active theme.");

      // A pristine render must not manufacture inline errors or references.
      $pristine = $render($form);
      $check($pristine->query('//*[contains(@id,"--ddbgo-error")]')->length === 0, "$theme_name/$bundle: valid controls get no error targets.");

      $cases = [];
      $cases[] = $error_in_widget($form[$field], 'Unsaved scalar error.', ['textfield']);
      if ($bundle !== 'person') {
        $cases[] = $error_in_widget($form['field_personen'], 'Unsaved person selection error.', ['select2', 'select']);
        $cases[] = $error_in_widget($form['field_kontakt'], 'Unsaved contact date error.', ['datetime']);
      }
      foreach ($cases as $case) {
        $check(is_array($case) && !empty($case[0]), "$theme_name/$bundle: every tested real widget exists.");
      }

      $xpath = $render($form);
      foreach ($cases as [$control_id, $help_refs]) {
        $error_id = $control_id . '--ddbgo-error';
        $targets = $xpath->query('//*[@id="' . $error_id . '"]');
        $check($targets->length === ($is_gin ? 1 : 0), "$theme_name/$bundle/$control_id: exactly one Gin error target, none in Claro.");
        if (!$is_gin) {
          continue;
        }
        $error = $targets->item(0);
        $check(trim($error->textContent) !== '' && !$error->hasAttribute('role') && !$error->hasAttribute('aria-live'), "$theme_name/$bundle: inline errors keep text without becoming live alerts.");
        $controls = $xpath->query('//*[@id="' . $control_id . '"]');
        $check($controls->length === 1, "$theme_name/$bundle: original control ID is retained.");
        $control = $controls->item(0);
        // Date groups hold a native date input, which must also receive the
        // shared error reference. Its individual label remains untouched.
        $dates = $xpath->query('.//input[@type="date" or @type="time"]', $control);
        $focus_targets = $dates->length ? iterator_to_array($dates) : [$control];
        foreach ($focus_targets as $focus_target) {
          $ids = $references($focus_target);
          $check(in_array($error_id, $ids, TRUE) && count($ids) === count(array_unique($ids)), "$theme_name/$bundle: focus target describes its error exactly once.");
        }
        foreach ($focus_targets as $focus_target) {
          foreach (preg_split('/\s+/', trim($help_refs)) as $help_id) {
            if ($help_id !== '') {
              $check(in_array($help_id, $references($focus_target), TRUE), "$theme_name/$bundle/$control_id: existing help reference $help_id remains.");
            }
          }
        }
      }
      $check($node->isNew() && $node->id() === NULL, "$theme_name/$bundle: fixture remains unsaved.");
    }

    // Exercise grouping wrappers and opt-out independently of field-specific
    // validation. IDs and extra help targets are intentional test data.
    $group_fixture = [
      'extra_help' => ['#markup' => '<p id="fixture-extra-help">Extra help.</p>'],
      'fieldset' => [
        '#type' => 'fieldset', '#id' => 'fixture-fieldset', '#title' => 'Group',
        '#description' => 'Group help.', '#description_display' => 'after',
        '#attributes' => ['aria-describedby' => 'fixture-extra-help'],
        '#errors' => 'Unsaved group error.',
      ],
      'details' => [
        '#type' => 'details', '#id' => 'fixture-details', '#title' => 'Details',
        '#summary_attributes' => ['aria-describedby' => 'fixture-extra-help'],
        '#errors' => 'Unsaved details error.', '#open' => TRUE,
      ],
      'suppressed' => [
        '#type' => 'textfield', '#id' => 'fixture-suppressed', '#title' => 'Suppressed',
        '#errors' => 'Must not be exposed.', '#error_no_message' => TRUE,
      ],
    ];
    $xpath = $render($group_fixture);
    foreach (['fieldset', 'details'] as $wrapper) {
      $error_id = 'fixture-' . $wrapper . '--ddbgo-error';
      $check($xpath->query('//*[@id="' . $error_id . '"]')->length === ($is_gin ? 1 : 0), "$theme_name/$wrapper: grouping error target follows template scope.");
      if ($is_gin) {
        $target = $xpath->query('//*[@id="fixture-' . $wrapper . '"]' . ($wrapper === 'details' ? '/summary' : ''))->item(0);
        $check($target instanceof DOMElement && in_array($error_id, $references($target), TRUE), "$theme_name/$wrapper: group or summary describes the error.");
        $check(in_array('fixture-extra-help', $references($target), TRUE), "$theme_name/$wrapper: custom help reference remains.");
        if ($wrapper === 'fieldset') {
          $check(in_array('fixture-fieldset--description', $references($target), TRUE), "$theme_name/fieldset: Core's fieldset description remains.");
        }
      }
    }
    $check($xpath->query('//*[@id="fixture-suppressed--ddbgo-error"]')->length === 0, "$theme_name: suppressed errors create no dangling target.");
    $suppressed = $xpath->query('//input[@id="fixture-suppressed"]')->item(0);
    $check($suppressed instanceof DOMElement && !str_contains($suppressed->getAttribute('aria-describedby'), '--ddbgo-error'), "$theme_name: suppressed control has no dangling reference.");
  }

  // The default Core preprocessors hide inline errors without this module.
  // Temporarily adjust only the current ModuleHandler's in-memory inventory;
  // never install/uninstall modules or change core.extension configuration.
  Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
  $module_handler = Drupal::moduleHandler();
  $original_modules = $module_handler->getModuleList();
  $check(isset($original_modules['inline_form_errors']), 'The normal rendering fixtures run with Inline Form Errors enabled.');
  $without_inline_errors = $original_modules;
  unset($without_inline_errors['inline_form_errors']);
  $no_module_fixture = [
    '#type' => 'textfield', '#theme_wrappers' => ['form_element'],
    '#id' => 'fixture-no-inline-module', '#errors' => 'Core would suppress this error.',
    '#attributes' => ['aria-describedby' => 'fixture-extra-help'],
  ];
  try {
    $module_handler->setModuleList($without_inline_errors);
    $check(FormErrorAccessibility::preRender($no_module_fixture) === $no_module_fixture, 'Without Inline Form Errors, the render callback creates no target or dangling reference.');
  }
  finally {
    $module_handler->setModuleList($original_modules);
  }
  $check($module_handler->getModuleList() === $original_modules, 'The original in-memory module inventory is restored.');
}
finally {
  Drupal::theme()->setActiveTheme($original_theme);
  $switcher->switchBack();
}

print "PASS: $checks inline-error rendering checks; no entities saved.\n";
