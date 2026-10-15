<?php

namespace Drupal\ddbgo_gin\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CalculatedCacheContextInterface;
use Drupal\Core\Cache\Context\RequestStackCacheContextBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies cached search markup by remembered filters, optionally for one View.
 *
 * The URL does not describe a search restored from the session. A session ID
 * alone is also insufficient: Reset changes the filters within that session.
 * Hash values rather than exposing search terms in cache identifiers.
 *
 * Cache context: ddbgo_view_filters[:VIEW_ID].
 */
final class RememberedViewsFiltersCacheContext extends RequestStackCacheContextBase implements CalculatedCacheContextInterface {

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return new TranslatableMarkup('Remembered Views filters');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext($parameter = NULL): string {
    $request = $this->requestStack->getCurrentRequest();
    $filters = $request && $request->hasSession()
      ? $request->getSession()->get('views', [])
      : [];
    if ($parameter !== NULL) {
      $filters = $filters[$parameter] ?? [];
    }
    return hash('sha256', serialize($filters));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($parameter = NULL): CacheableMetadata {
    return new CacheableMetadata();
  }

}
