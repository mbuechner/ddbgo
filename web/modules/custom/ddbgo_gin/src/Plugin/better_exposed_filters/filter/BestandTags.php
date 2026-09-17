<?php

declare(strict_types=1);

namespace Drupal\ddbgo_gin\Plugin\better_exposed_filters\filter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\better_exposed_filters\Attribute\FiltersWidget;
use Drupal\facets_exposed_filters\FacetsExposedFiltersHelper;
use Drupal\facets_exposed_filters\Plugin\views\filter\FacetsFilter;
use Drupal\tagify\Plugin\better_exposed_filters\filter\TagifySelect;

/**
 * Keeps the Bestand tag selection usable throughout the facet form lifecycle.
 */
#[FiltersWidget(
  id: 'ddbgo_bestand_tags',
  title: new TranslatableMarkup('DDBgo Bestandstags (Tagify facet)'),
)]
final class BestandTags extends TagifySelect {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(mixed $filter = NULL, array $filter_options = []): bool {
    return $filter instanceof FacetsFilter
      && $filter->view->id() === 'suche_bestand'
      && ($filter_options['field'] ?? '') === 'facets_field_bestandstags';
  }

  /**
   * {@inheritdoc}
   */
  public function exposedFormAlter(array &$form, FormStateInterface $form_state): void {
    $field_id = $this->getExposedFilterFieldId();
    $input = $this->view->getExposedInput()[$field_id] ?? [];
    $selected = array_values(array_filter((array) $input, static fn ($value) => is_scalar($value) && (string) $value !== '' && (string) $value !== 'All'));
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['taxonomy_term_list:bestandstags']);

    // Facets has no select before query execution or when no buckets remain.
    // Keep a real form element so Views can remember the input and users can
    // remove selected tags even after a full-text search returns zero results.
    if (!isset($form[$field_id]['#options'])) {
      $form[$field_id] = [
        '#type' => 'select',
        '#options' => [],
        '#multiple' => TRUE,
        '#default_value' => $selected,
      ];
    }

    // Add only already selected terms, never new zero-hit suggestions. Missing
    // or inaccessible terms get a removable placeholder without leaking labels.
    foreach ($selected as $value) {
      if (isset($form[$field_id]['#options'][$value])) {
        continue;
      }
      $label = $this->t('Unavailable tag (@id)', ['@id' => $value]);
      $term = ctype_digit((string) $value) ? \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($value) : NULL;
      if ($term && $term->bundle() === 'bestandstags') {
        $access = $term->access('view', NULL, TRUE);
        $cache->addCacheableDependency($term)->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $label = \Drupal::service('entity.repository')->getTranslationFromContext($term)->label();
        }
      }
      $form[$field_id]['#options'][$value] = $label;
    }

    parent::exposedFormAlter($form, $form_state);

    // TagifySelect replaces the select and drops Facets' process callback.
    // BEF prepends Tagify's native process callbacks after this method returns.
    $form[$field_id]['#process'] = [
      [FacetsExposedFiltersHelper::class, 'removeValidation'],
    ];
    $cache->applyTo($form[$field_id]);
  }

}
