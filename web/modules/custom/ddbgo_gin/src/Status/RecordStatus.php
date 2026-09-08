<?php

namespace Drupal\ddbgo_gin\Status;

/**
 * Stable business values; colors are presentation and legacy migration input.
 */
final class RecordStatus {

  public const FIELD = 'field_record_status';
  public const BUNDLES = ['aggregator', 'bestand', 'kwe'];
  public const LABELS = [
    'rejected' => 'Abgelehnt',
    'in_progress' => 'In Bearbeitung',
    'ingested' => 'Ingestiert',
  ];

  /**
   * Converts only explicitly understood colors; never guesses a status.
   */
  public static function fromColor(?string $color): ?string {
    $color = strtolower(trim($color ?? ''));
    return match ($color) {
      '#d2222d', 'd2222d' => 'rejected',
      '#ffbf00', 'ffbf00' => 'in_progress',
      '#238823', '238823' => 'ingested',
      '', '#ffffff', 'ffffff', '#fff', 'fff' => NULL,
      default => throw new \UnexpectedValueException('Unknown status color: ' . $color),
    };
  }

}
