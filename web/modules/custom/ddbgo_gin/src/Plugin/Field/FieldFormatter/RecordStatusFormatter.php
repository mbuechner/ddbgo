<?php

namespace Drupal\ddbgo_gin\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ddbgo_gin\Status\RecordStatus;

/**
 * One accessible presentation for entity displays and both kinds of Views.
 */
#[FieldFormatter(
  id: 'ddbgo_record_status',
  label: new TranslatableMarkup('DDBgo Status (Farbe und Text)'),
  field_types: ['list_string'],
)]
final class RecordStatusFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $status = $item->value;
      $elements[$delta] = [
        '#theme' => 'ddbgo_record_status',
        '#status' => isset(RecordStatus::LABELS[$status]) ? $status : 'unknown',
        '#label' => RecordStatus::LABELS[$status] ?? $status,
        '#attached' => ['library' => ['ddbgo_gin/record_status']],
      ];
    }
    return $elements ?: [[
      '#theme' => 'ddbgo_record_status',
      '#status' => 'unknown',
      '#label' => $this->t('Keine Angabe'),
      '#attached' => ['library' => ['ddbgo_gin/record_status']],
    ]];
  }

}
