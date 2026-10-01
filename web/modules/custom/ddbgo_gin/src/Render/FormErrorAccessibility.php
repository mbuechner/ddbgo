<?php

namespace Drupal\ddbgo_gin\Render;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Connects existing Gin inline errors to their controls without live alerts.
 *
 * Errors are assigned after Form API's build phase. A pre-render callback runs
 * before inputs or composite children become HTML, so it can preserve their
 * existing help references and add the error reference without parsing HTML.
 */
final class FormErrorAccessibility implements TrustedCallbackInterface {

  /**
   * Whether this element uses one of DDBgo's existing Gin form wrappers.
   */
  public static function usesWrapper(array $element): bool {
    foreach ($element['#theme_wrappers'] ?? [] as $key => $wrapper) {
      $name = is_string($key) ? $key : $wrapper;
      if (in_array($name, ['form_element', 'fieldset', 'details', 'datetime_wrapper'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

  /**
   * Adds references only when the wrapper will render an actual inline error.
   */
  public static function preRender(array $element): array {
    if (!\ddbgo_gin_is_theme('gin') || !self::usesWrapper($element)
      || empty($element['#errors']) || !empty($element['#error_no_message'])
      || empty($element['#id'])
      || !\Drupal::moduleHandler()->moduleExists('inline_form_errors')) {
      return $element;
    }

    // Form API already gives every element a unique ID, including repeated
    // Paragraphs/date widgets and rebuilt AJAX controls. Deriving the message
    // ID keeps the rendered reference and its target consistent.
    $error_id = $element['#ddbgo_inline_error_id'] = $element['#id'] . '--ddbgo-error';
    $element['#attributes']['aria-describedby'] = self::describedBy(
      $element['#attributes']['aria-describedby'] ?? '', $error_id,
    );

    if (($element['#type'] ?? '') === 'details') {
      // The summary receives keyboard focus; the surrounding details does not.
      $element['#summary_attributes']['aria-describedby'] = self::describedBy(
        $element['#summary_attributes']['aria-describedby'] ?? '', $error_id,
      );
    }
    elseif (in_array('datetime_wrapper', $element['#theme_wrappers'] ?? [], TRUE)) {
      // Date/time wrappers hold multiple focusable controls. Associate each
      // existing control with the shared error, retaining its individual help.
      foreach (['date', 'time'] as $part) {
        if (!empty($element[$part]['#type'])) {
          $element[$part]['#attributes']['aria-describedby'] = self::describedBy(
            $element[$part]['#attributes']['aria-describedby'] ?? '', $error_id,
          );
        }
      }
    }

    return $element;
  }

  /**
   * Preserves the error reference when Core adds a fieldset's description.
   */
  public static function preprocessFieldset(array &$variables): void {
    if (!empty($variables['element']['#ddbgo_inline_error_id'])) {
      $variables['attributes']['aria-describedby'] = self::describedBy(
        $variables['attributes']['aria-describedby'] ?? '',
        $variables['element']['#attributes']['aria-describedby'] ?? '',
      );
    }
  }

  /**
   * Merges ID-reference lists without deleting help or duplicating references.
   */
  private static function describedBy($existing, string $additional): string {
    $ids = preg_split('/\s+/', trim((string) $existing . ' ' . $additional));
    return implode(' ', array_unique(array_filter($ids)));
  }

}
