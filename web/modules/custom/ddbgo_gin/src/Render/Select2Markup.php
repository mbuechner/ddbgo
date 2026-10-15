<?php

namespace Drupal\ddbgo_gin\Render;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Labels Select2's empty single-select option without changing its placeholder.
 */
final class Select2Markup implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

  /**
   * Runs after Select2 has inserted its option and serialized its settings.
   */
  public static function preRender(array $element): array {
    if (!empty($element['#multiple'])) {
      return $element;
    }

    $empty_value = $element['#empty_value'] ?? '';
    $options = $element['#options'] ?? [];
    if (!array_key_exists($empty_value, $options)
      || is_array($options[$empty_value])
      || trim((string) $options[$empty_value]) !== '') {
      return $element;
    }

    if (trim((string) ($element['#options_attributes'][$empty_value]['label'] ?? '')) !== '') {
      return $element;
    }

    $serialized_settings = $element['#attributes']['data-select2-config'] ?? '';
    $settings = is_string($serialized_settings) ? Json::decode($serialized_settings) : [];
    $placeholder = is_array($settings) ? ($settings['placeholder'] ?? []) : [];
    $label = is_array($placeholder) ? trim((string) ($placeholder['text'] ?? '')) : '';
    if ($label === '') {
      $label = (string) (!empty($element['#required'])
        ? new TranslatableMarkup('- Select -')
        : new TranslatableMarkup('- None -'));
    }

    // form_options_attributes renders the label attribute while keeping the
    // option's value and text empty, as Select2's placeholder/allowClear expect.
    $element['#options_attributes'][$empty_value]['label'] = $label;
    return $element;
  }

}
