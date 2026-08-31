<?php

namespace Drupal\ddbgo_gin\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeTypeInterface;
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

    if ($route = $collection->get('node.add')) {
      $route->setDefault('_title_callback', self::class . '::nodeAddTitle');
    }
  }

  /**
   * Returns consistent add-form titles for DDBgo content types.
   */
  public static function nodeAddTitle(NodeTypeInterface $node_type): string|TranslatableMarkup {
    if (in_array($node_type->id(), ['kwe', 'aggregator', 'person', 'bestand'], TRUE)) {
      return $node_type->label() . ' hinzufügen';
    }

    return new TranslatableMarkup('Create @name', [
      '@name' => $node_type->label(),
    ]);
  }

}
