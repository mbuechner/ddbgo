<?php

/**
 * @file
 * Read-only date filter rendering regression; run through drush php:script.
 */

use Drupal\Core\Form\FormState;
use Drupal\views\Views;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$manager = Drupal::theme();
$original_theme = $manager->getActiveTheme();
$manager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));
try {
  $view = Views::getView('suche_bestand_fuer_europeana');
  $view->setDisplay('default');
  $view->setExposedInput([]);
  $view->initHandlers();
  $form = $view->display_handler->getPlugin('exposed_form')->renderExposedForm();
  $group = $form['ddbgo_exposed_filters']['datum_des_status_wrapper'];
  $check(in_array('ddbgo-date-filter', $group['#attributes']['class'], TRUE), 'Real date filter receives shared layout');
  $operator = 'field_datum_des_status_der_europ_op';
  $check($group[$operator]['#name'] === $operator, 'Original operator parameter retained');
  foreach (['value', 'min', 'max'] as $key) {
    $field = $group['datum_des_status'][$key];
    $check($field['#name'] === "datum_des_status[$key]", "Original date parameter retained: $key");
    $check(isset($field['#states']['visible']), "Native operator visibility retained: $key");
  }
  $group['datum_des_status']['min']['#value'] = '2026-01-01';
  $group['datum_des_status']['max']['#value'] = '2026-09-16';
  $group['extra'] = ['#type' => 'hidden', '#name' => 'extra', '#value' => 'preserved'];
  $html = (string) Drupal::service('renderer')->renderRoot($group);
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  $xpath = new DOMXPath($dom);
  foreach (['value' => 'Datum', 'min' => 'Von', 'max' => 'Bis'] as $key => $label) {
    $id = $group['datum_des_status'][$key]['#id'];
    $check(trim($xpath->evaluate('string(//label[@for="' . $id . '"])')) === $label, "Twig supplies label for $key");
  }
  $check(trim($xpath->evaluate('string(//label[@for="' . $group[$operator]['#id'] . '"])')) === (string) $group['#title'], 'Operator carries the visible filter title');
  $check($group['#title_display'] === 'invisible', 'Shared legend remains accessible without an extra visible heading');
  $check($xpath->query('//div[@class="ddbgo-date-filter__controls"]')->length === 1, 'Twig supplies a single layout container');
  $check($xpath->evaluate('string(//input[@name="datum_des_status[min]"]/@value)') === '2026-01-01', 'Twig retains selected start date');
  $check($xpath->evaluate('string(//input[@name="datum_des_status[max]"]/@value)') === '2026-09-16', 'Twig retains selected end date');
  $check($xpath->query('//input[@data-drupal-states]')->length === 3, 'Visibility states reach the browser');
  $check($xpath->query('//input[@name="extra" and @value="preserved"]')->length === 1, 'Additional children render exactly once');
  foreach ($xpath->query('//input[not(@type="hidden")] | //select') as $input) {
    $id = $input->getAttribute('id');
    $check($xpath->query('//label[@for="' . $id . '"]')->length === 1, "One accessible label for $id");
  }
  $check($xpath->query('//legend')->length === 1, 'One shared fieldset legend');

  // Inspect the initial HTML before JavaScript can fix its visibility. Test
  // submitted operators too, so a remembered/default "between" cannot win.
  foreach ($view->filter['field_datum_des_status_der_europ']->operators() as $selected => $definition) {
    $case_view = Views::getView('suche_bestand_fuer_europeana');
    $case_view->setDisplay('default');
    $case_view->setExposedInput([$operator => $selected]);
    $case_view->initHandlers();
    $case_form = $case_view->display_handler->getPlugin('exposed_form')->renderExposedForm();
    $case_group = $case_form['ddbgo_exposed_filters']['datum_des_status_wrapper'];
    $check($case_group[$operator]['#value'] === $selected, "Use selected operator: $selected");
    $case_html = (string) Drupal::service('renderer')->renderRoot($case_group);
    $case_dom = new DOMDocument();
    @$case_dom->loadHTML('<?xml encoding="UTF-8">' . $case_html);
    $case_xpath = new DOMXPath($case_dom);
    foreach (['value' => 1, 'min' => 2, 'max' => 2] as $part => $count) {
      $input = $case_xpath->query('//input[@name="datum_des_status[' . $part . ']"]')->item(0);
      $check($input !== NULL, "Retain input for later operator changes: $selected / $part");
      $wrapper = $input->parentNode;
      $hidden = str_contains($wrapper->getAttribute('style'), 'display: none');
      $check($hidden === ($definition['values'] !== $count), "Correct visibility before JS: $selected / $part");
      $check(!$wrapper->hasAttribute('hidden') && !$input->hasAttribute('disabled'), "Core can reveal and submit the field: $selected / $part");
      $check($input->hasAttribute('data-drupal-states'), "Keep operator switching: $selected / $part");
    }
  }

  // The enhancement follows filter metadata, not a specific View or field ID.
  $filter = clone $view->filter['field_datum_des_status_der_europ'];
  $filter->options['expose']['identifier'] = 'other_date';
  $filter->options['expose']['operator_id'] = 'other_comparison';
  $view->filter = ['other_filter' => $filter];
  $state = (new FormState())->set('view', $view);
  $other = ['other_date_wrapper' => [
    '#type' => 'fieldset',
    'other_comparison' => ['#type' => 'select', '#name' => 'other_comparison'],
    'other_date' => ['min' => ['#type' => 'textfield'], 'max' => ['#type' => 'textfield']],
  ]];
  ddbgo_gin_prepare_exposed_date_filters($other, $state);
  $check(in_array('ddbgo-date-filter', $other['other_date_wrapper']['#attributes']['class'], TRUE), 'Alternative identifiers supported');
  $check($other['other_date_wrapper']['other_date']['min']['#type'] === 'textfield', 'Existing widget type preserved');
  $filter->options['is_grouped'] = TRUE;
  $untouched = ['other_date_wrapper' => ['#type' => 'fieldset']];
  $expected = $untouched;
  ddbgo_gin_prepare_exposed_date_filters($untouched, $state);
  $check($untouched === $expected, 'Configured grouped choices remain unchanged');
  echo "PASS: date filter markup, labels, parameters, states and reuse\n";
}
finally {
  $manager->setActiveTheme($original_theme);
}
