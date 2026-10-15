<?php

namespace Drupal\ddbgo_gin\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Restricts API-key management to administrators.
 */
final class UserKeyAuthAccessCheck implements AccessInterface {

  /**
   * Checks access to a user's Key Auth form.
   */
  public function access(AccountInterface $account, RouteMatchInterface $route_match): AccessResultInterface {
    $user = $route_match->getParameter('user');
    if (!$user instanceof UserInterface) {
      return AccessResult::neutral();
    }

    $access = $account->hasPermission('administer user api keys')
      ? AccessResult::allowed()
      : AccessResult::forbidden();
    $access->addCacheContexts(['user.permissions']);

    return $access
      ->cachePerUser()
      ->addCacheableDependency($user);
  }

}
