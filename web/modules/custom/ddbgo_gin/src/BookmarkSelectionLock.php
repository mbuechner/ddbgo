<?php

namespace Drupal\ddbgo_gin;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\TempStoreException;

/**
 * Serializes staging and conditional cleanup of a user's bookmark selection.
 */
final class BookmarkSelectionLock {

  public static function withLock(AccountInterface $account, callable $callback): mixed {
    $lock = \Drupal::lock();
    $name = 'ddbgo_gin.bookmark_selection:' . $account->id();
    if (!$lock->acquire($name)) {
      $lock->wait($name);
      if (!$lock->acquire($name)) {
        throw new TempStoreException('The bookmark selection is being updated. Please try again.');
      }
    }
    try {
      return $callback();
    }
    finally {
      $lock->release($name);
    }
  }

}
