<?php

namespace Drupal\ddbgo_gin\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\DeleteAction;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ddbgo_gin\BookmarkSelectionLock;
use Drupal\flag\FlaggingInterface;
use Drupal\node\NodeInterface;

/**
 * Stages personal bookmarks for removal after a separate confirmation.
 *
 * Core's DeleteAction only stores the selection in private tempstore. The
 * confirmation form owns deletion; executing this action never deletes data.
 */
#[Action(
  id: 'ddbgo_bookmark_remove',
  label: new TranslatableMarkup('Lesezeichen entfernen'),
  confirm_form_route_name: 'ddbgo_gin.bookmark_remove_confirm',
  type: 'flagging',
)]
class BookmarkRemoveAction extends DeleteAction {

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    BookmarkSelectionLock::withLock($this->currentUser, fn () => parent::executeMultiple($entities));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $access = AccessResult::forbidden()->cachePerUser();

    if ($object instanceof FlaggingInterface) {
      $access->addCacheableDependency($object);
      $flag = $object->getFlag();
      if ($flag) {
        $access->addCacheableDependency($flag);
      }

      if ($account->isAuthenticated()
        && $object->getFlagId() === 'bookmark'
        && (string) $object->getOwnerId() === (string) $account->id()
        && $flag && !$flag->isGlobal()
        && !$object->get('global')->value) {
        $node = $object->getFlaggable();
        if ($node instanceof NodeInterface) {
          // Flagging's generic delete access requires administration rights.
          // Personal removal uses Flag's normal unflag permission instead.
          $access = $flag->actionAccess('unflag', $account, $node)
            ->andIf($node->access('view', $account, TRUE))
            ->cachePerUser()
            ->addCacheableDependency($object)
            ->addCacheableDependency($flag)
            ->addCacheableDependency($node);
        }
      }
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

}
