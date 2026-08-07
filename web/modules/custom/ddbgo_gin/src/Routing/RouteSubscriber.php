<?php

namespace Drupal\ddbgo_gin\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds DDBgo-specific access requirements to contributed routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('key_auth.user_key_auth_form')) {
      $route->setRequirement('_ddbgo_administer_user_api_keys_access', 'TRUE');
    }
  }

}
