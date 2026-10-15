<?php

namespace Drupal\ddbgo_gin\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ddbgo_gin\Status\RecordStatus;

/**
 * Selects the status presentation for entity displays and Views fields.
 */
#[FieldFormatter(
  id: 'ddbgo_record_status',
  label: new TranslatableMarkup('DDBgo Status (Farbe und Text)'),
  field_types: ['list_string'],
)]
final class RecordStatusFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    // Native Views and Search API build field displays in CUSTOM_MODE. Named
    // entity view modes (default, full, teaser, etc.) use the plain template.
    $theme = $this->viewMode === EntityDisplayBase::CUSTOM_MODE
      ? 'ddbgo_record_status'
      : 'ddbgo_record_status_plain';
    $elements = [];
    foreach ($items as $delta => $item) {
      $status = $item->value;
      $elements[$delta] = [
        '#theme' => $theme,
        '#status' => isset(RecordStatus::LABELS[$status]) ? $status : 'unknown',
        '#label' => RecordStatus::LABELS[$status] ?? $status,
        '#attached' => ['library' => ['ddbgo_gin/record_status']],
      ];
    }
    return $elements ?: [[
      '#theme' => $theme,
      '#status' => 'unknown',
      '#label' => $this->t('Keine Angabe'),
      '#attached' => ['library' => ['ddbgo_gin/record_status']],
    ]];
  }

}
