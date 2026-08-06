<?php

declare(strict_types=1);

namespace Drupal\ddbgo_gin\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\toolbar_menu\ToolbarMenuManager;
use Drupal\toolbar_menu\ToolbarMenuPrerender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the configured Toolbar Menu entries in the frontend header.
 */
#[Block(
  id: 'ddbgo_workspace_navigation',
  admin_label: new TranslatableMarkup('DDBgo workspace navigation'),
  category: new TranslatableMarkup('DDBgo'),
)]
final class DdbgoWorkspaceNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a DDBgo workspace navigation block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ToolbarMenuManager $toolbarMenuManager,
    protected readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('toolbar_menu.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($account->isAuthenticated())
      ->addCacheContexts(['user.roles:authenticated']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $elements = $this->toolbarMenuManager->getToolbarMenuElements();
    uasort($elements, static fn ($a, $b): int => $a->weight() <=> $b->weight());

    $items = [];
    $cache_tags = ['toolbar_menu'];
    foreach ($elements as $element_id => $element) {
      $menu = $element->loadMenu();
      if ($menu === NULL) {
        continue;
      }

      // Reuse Toolbar Menu's own tree builder and Gin's toolbar menu template.
      $tray = ToolbarMenuPrerender::prerenderToolbarTray(['#id' => $menu->id()]);
      $items[] = [
        'id' => $element_id,
        'label' => $element->getDisplayLabel(),
        'menu' => $tray['toolbar_menu_' . $menu->id()],
      ];
      $cache_tags = Cache::mergeTags($cache_tags, $element->getCacheTags());
      $cache_tags = Cache::mergeTags($cache_tags, $menu->getCacheTags());
    }

    return [
      '#theme' => 'ddbgo_workspace_navigation',
      '#items' => $items,
      '#account_label' => $this->currentUser->getDisplayName(),
      '#account_links' => [
        'account' => [
          '#type' => 'link',
          '#title' => $this->t('My account'),
          '#url' => Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]),
        ],
        'logout' => [
          '#type' => 'link',
          '#title' => $this->t('Log out'),
          '#url' => Url::fromRoute('user.logout'),
        ],
      ],
      '#attached' => [
        'library' => ['ddbgo_gin/workspace_navigation'],
      ],
      '#cache' => [
        'contexts' => ['route', 'user', 'user.permissions'],
        'tags' => $cache_tags,
      ],
    ];
  }

}
