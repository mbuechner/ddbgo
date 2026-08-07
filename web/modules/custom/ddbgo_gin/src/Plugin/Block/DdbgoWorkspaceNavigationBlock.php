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
    return AccessResult::allowed()
      ->addCacheContexts(['user.roles:authenticated']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $is_authenticated = $this->currentUser->isAuthenticated();
    $login_label = $this->t('Login', [], ['context' => 'DDBgo workspace navigation']);
    $items = [];
    $cache_tags = [];

    if ($is_authenticated) {
      $elements = $this->toolbarMenuManager->getToolbarMenuElements();
      uasort($elements, static fn ($a, $b): int => $a->weight() <=> $b->weight());
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
    }

    $account_links = $is_authenticated
      ? [
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
      ]
      : [
        'login' => [
          '#type' => 'link',
          '#title' => $login_label,
          '#url' => Url::fromRoute('user.login'),
          '#attributes' => [
            'aria-label' => $login_label,
            'class' => [
              'ddbgo-workspace-navigation__link',
              'ddbgo-workspace-navigation__link--account',
            ],
          ],
        ],
      ];

    return [
      '#theme' => 'ddbgo_workspace_navigation',
      '#items' => $items,
      '#is_authenticated' => $is_authenticated,
      '#account_label' => $is_authenticated ? $this->currentUser->getDisplayName() : $login_label,
      '#account_links' => $account_links,
      '#attached' => [
        'library' => ['ddbgo_gin/workspace_navigation'],
      ],
      '#cache' => [
        'contexts' => $is_authenticated
          ? ['route', 'user', 'user.permissions']
          : ['route', 'user.roles:authenticated'],
        'tags' => $cache_tags,
      ],
    ];
  }

}
