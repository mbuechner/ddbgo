<?php

/**
 * @file
 * Read-only formatter/template checks; run through drush php:script.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\ddbgo_gin\Status\RecordStatus;
use Drupal\node\Entity\Node;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$renderer = Drupal::service('renderer');
foreach (RecordStatus::BUNDLES as $bundle) {
  foreach (['default', 'full', 'teaser', EntityDisplayBase::CUSTOM_MODE] as $mode) {
    // Views creates an unsaved custom EntityViewDisplay for its field columns;
    // entity displays instead pass their named view mode to the formatter.
    $display = EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => $bundle,
      'mode' => $mode,
      'status' => TRUE,
    ]);
    $display->setComponent(RecordStatus::FIELD, ['type' => 'ddbgo_record_status']);
    $formatter = $display->getRenderer(RecordStatus::FIELD);
    $is_table = $mode === EntityDisplayBase::CUSTOM_MODE;
    foreach (array_merge(array_keys(RecordStatus::LABELS), ['', 'unrecognized']) as $status) {
      $node = Node::create(['type' => $bundle, RecordStatus::FIELD => $status === '' ? [] : $status]);
      $elements = $formatter->viewElements($node->get(RecordStatus::FIELD), 'de');
      $expected_theme = $is_table ? 'ddbgo_record_status' : 'ddbgo_record_status_plain';
      $check($elements[0]['#theme'] === $expected_theme, "Template selection: $bundle / $mode / $status");
      $check(in_array('ddbgo_gin/record_status', $elements[0]['#attached']['library'], TRUE), 'Shared CSS remains attached');
      $html = (string) $renderer->renderRoot($elements);
      $check(str_contains($html, 'title=') === $is_table, "Hover hint only in table template: $bundle / $mode / $status");
      $check(str_contains($html, 'ddbgo-record-status__cursor') === $is_table, 'Help cursor only in table template');
      $label = RecordStatus::LABELS[$status] ?? ($status ?: 'Keine Angabe');
      $check(str_contains($html, $label) && str_contains($html, 'aria-hidden="true"'), "Accessible text and decorative color retained: $bundle / $mode / $status");
    }
  }
}
echo "PASS: status templates for entity displays and Views fields, including empty and unknown values. Nothing saved.\n";
