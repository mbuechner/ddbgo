<?php

namespace Drupal\ddbgo_gin\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Native links to the Bestand search; core handles term loading and translation.
 */
#[FieldFormatter(
  id: 'ddbgo_bestand_tags',
  label: new TranslatableMarkup('Bestandstags mit Suchlinks'),
  field_types: ['entity_reference'],
)]
final class BestandTagsFormatter extends EntityReferenceFormatterBase {

  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getName() === 'field_bestandstags'
      && $field_definition->getSetting('target_type') === 'taxonomy_term';
  }

  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $dependencies['config'][] = 'views.view.suche_bestand';
    return $dependencies;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $term) {
      $label = $term->label();
      $element = ['#plain_text' => $label];
      $cache = CacheableMetadata::createFromObject($term);
      if (!$term->isNew()) {
        // IDs select exact terms. Explicitly clear remembered full-text input
        // and replace, rather than append to, any previously selected tags.
        $url = Url::fromRoute('view.suche_bestand.page', [], ['query' => [
          'query' => '',
          'field_bestandstags' => [(string) $term->id()],
        ]]);
        $access = $url->access(return_as_object: TRUE);
        $cache->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $element = [
            '#type' => 'link',
            '#title' => $label,
            '#url' => $url,
            '#attributes' => [
              'class' => ['ddbgo-tag-link'],
              'title' => $this->t('Bestände mit dem Tag @tag anzeigen', ['@tag' => $label]),
            ],
            '#attached' => ['library' => ['ddbgo_gin/bestand_tags']],
          ];
        }
      }
      $cache->applyTo($element);
      $elements[$delta] = $element;
    }
    return $elements;
  }

  protected function checkAccess(EntityInterface $entity) {
    return $entity->access('view label', NULL, TRUE);
  }

}
