<?php

/**
 * @file
 * Bookmark removal integration checks; run through drush php:script only.
 *
 * Creates temporary users, nodes and flaggings in one rolled-back transaction.
 * Existing users, bookmarks, roles and configuration are never modified.
 * Exercises action access and confirmation build/submit with synthetic GET and
 * POST requests. Tests the signed selection, not full HTTP/Form API CSRF or
 * browser behavior. Requires the installed ddb_team role and the
 * bookmark flag, plus discovery of the ddbgo_bookmark_remove action.
 */

use Drupal\Core\Form\FormState;
use Drupal\ddbgo_gin\Form\BookmarkRemoveConfirmForm;
use Drupal\flag\FlaggingInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};

$check(PHP_SAPI === 'cli', 'Run only through Drush, never through a browser');
$check(class_exists(BookmarkRemoveConfirmForm::class), 'Confirmation form is available');
$check(Drupal::entityTypeManager()->getStorage('user_role')->load('ddb_team') !== NULL, 'Existing editor role is available');
$flag = Drupal::entityTypeManager()->getStorage('flag')->load('bookmark');
$check($flag !== NULL && !$flag->isGlobal(), 'Existing bookmark flag is personal');

$database = Drupal::database();
$storage = Drupal::entityTypeManager()->getStorage('flagging');
$switcher = Drupal::service('account_switcher');
$fixture_ids = ['user' => [], 'node' => [], 'flagging' => []];
$prefix = 'ddbgo-bookmark-test-' . bin2hex(random_bytes(6));
$switched = FALSE;
$transaction = $database->startTransaction();

try {
  $make_user = static function (string $suffix, array $roles) use ($prefix, &$fixture_ids): User {
    $user = User::create([
      'name' => $prefix . '-' . $suffix,
      'mail' => $prefix . '-' . $suffix . '@example.invalid',
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    $fixture_ids['user'][] = $user->id();
    return $user;
  };
  $owner = $make_user('owner', ['ddb_team']);
  $other = $make_user('other', ['ddb_team']);
  $without_permission = $make_user('no-unflag', []);
  $check($owner->hasPermission('unflag bookmark'), 'Fixture owner may remove personal bookmarks');
  $check(!$owner->hasPermission('administer flaggings'), 'Fixture owner has no flagging administration rights');
  $check(!$without_permission->hasPermission('unflag bookmark'), 'Negative fixture has no unflag permission');

  $switcher->switchTo($owner);
  $switched = TRUE;
  $action = Drupal::service('plugin.manager.action')->createInstance('ddbgo_bookmark_remove');
  $check(!empty($action->getPluginDefinition()['confirm_form_route_name']), 'Removal action declares a confirmation route');
  $tempstore = Drupal::service('tempstore.private')->get('entity_delete_multiple_confirm');
  $selection_key = $owner->id() . ':flagging';

  $make_bookmark = static function (User $user, string $suffix, ?Node $node = NULL) use ($storage, $prefix, &$fixture_ids): FlaggingInterface {
    if ($node === NULL) {
      $node = Node::create([
        'type' => 'person',
        'title' => $prefix . '-' . $suffix,
        // The installed automatic title pattern uses these two fields.
        'field_nachname' => $prefix,
        'field_vorname' => $suffix,
        'status' => 1,
        'uid' => $user->id(),
      ]);
      $node->save();
      $fixture_ids['node'][] = $node->id();
    }
    $flagging = $storage->create([
      'flag_id' => 'bookmark',
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'uid' => $user->id(),
      'global' => FALSE,
      'session_id' => NULL,
    ]);
    $flagging->save();
    $fixture_ids['flagging'][] = $flagging->id();
    return $flagging;
  };
  $exists = static function (FlaggingInterface $flagging) use ($storage): bool {
    $storage->resetCache([$flagging->id()]);
    return $storage->load($flagging->id()) !== NULL;
  };
  $build = static function ($id = NULL, string $method = 'GET', array $input = []): array {
    $form_object = BookmarkRemoveConfirmForm::create(Drupal::getContainer());
    $requests = new RequestStack();
    $requests->push(Request::create('/bookmarks/remove', $method, $input, [], [], ['REQUEST_TIME' => time()]));
    $form_object->setRequestStack($requests);
    $state = new FormState();
    $state->setRequestMethod($method)->setUserInput($input);
    $form = $form_object->buildForm([], $state, $id);
    return [$form_object, $state, $form];
  };
  $posted_input = static fn (array $built): array => [
    'bookmark_selection' => $built[2]['bookmark_selection']['#value'],
    'bookmark_signature' => $built[2]['bookmark_signature']['#value'],
    'confirm' => 1,
  ];
  $post = static fn (array $built): array => $build(NULL, 'POST', $posted_input($built));
  $confirm = static function (array $built) use ($check): void {
    [$form_object, $state, $form] = $built;
    $check(is_array($form) && isset($form['actions']['submit']), 'Allowed selection has a confirmation submit button');
    $state->setValue($form_object->getFormName(), 1);
    $form_object->submitForm($form, $state);
  };
  $rejects_build = static function ($id) use ($build): bool {
    try {
      [, , $form] = $build($id);
      return $form instanceof RedirectResponse || (is_array($form) && !isset($form['actions']['submit']));
    }
    catch (HttpExceptionInterface $exception) {
      return in_array($exception->getStatusCode(), [403, 404], TRUE);
    }
  };
  $rejects_post = static function (array $input) use ($build): bool {
    try {
      $build(NULL, 'POST', $input);
      return FALSE;
    }
    catch (HttpExceptionInterface $exception) {
      return $exception->getStatusCode() === 400;
    }
  };

  $single = $make_bookmark($owner, 'single');
  $first = $make_bookmark($owner, 'bulk-first');
  $second = $make_bookmark($owner, 'bulk-second');
  $foreign = $make_bookmark($other, 'foreign');
  $no_permission = $make_bookmark($without_permission, 'no-permission');
  $check($action->access($single, $owner), 'Owner may remove a bookmark without administration rights');
  $check(!$action->access($foreign, $owner), 'Another user\'s bookmark is not removable');
  $check(!$action->access($no_permission, $without_permission), 'Account without permission cannot remove its own bookmark');
  $check($rejects_build($foreign->id()), 'Single confirmation rejects another user\'s bookmark');
  $check($exists($foreign), 'Rejected form build preserves the foreign bookmark');

  // Opening a confirmation or staging an action never performs deletion.
  $action->executeMultiple([$first, $second]);
  $pending = $tempstore->get($selection_key);
  $check(isset($pending[$first->id()], $pending[$second->id()]), 'Bulk action only stages the selected flagging IDs');
  $single_form = $build($single->id());
  $check($exists($single) && $exists($first) && $exists($second), 'Action and confirmation build do not remove any bookmark');
  $single_post = $post($single_form);
  $check($exists($single), 'Fresh POST form build also waits for the submit handler');
  $confirm($single_post);
  $check(!$exists($single) && $exists($first) && $exists($second), 'Single confirmation removes only the selected bookmark');
  $check($tempstore->get($selection_key) === $pending, 'Single confirmation preserves a separately staged bulk selection');

  // Deliberately bypass the caller's access filtering to verify that the form
  // itself also excludes foreign IDs from a mixed staged selection.
  $action->executeMultiple([$first, $second, $foreign]);
  $bulk_form = $build();
  $check($exists($first) && $exists($second) && $exists($foreign), 'Bulk confirmation build does not delete data');
  $listed_labels = array_column($bulk_form[2]['bookmarks']['#items'], '#plain_text');
  $check(count($listed_labels) === 2 && !in_array($foreign->getFlaggable()->label(), $listed_labels, TRUE), 'Confirmation lists only removable records and does not disclose the foreign title');
  $confirm($post($bulk_form));
  $check(!$exists($first) && !$exists($second), 'Bulk confirmation removes its selected own bookmarks');
  $check($exists($foreign), 'Bulk confirmation preserves a foreign bookmark in staged input');

  // Reflagging the same record creates a new flagging ID. A stale confirmation
  // must not resolve by node/user and inadvertently remove this new bookmark.
  $stale = $make_bookmark($owner, 'stale');
  $stale_node = $stale->getFlaggable();
  $stale_form = $build($stale->id());
  $stale->delete();
  $replacement = $make_bookmark($owner, 'replacement', $stale_node);
  $check((string) $replacement->id() !== (string) $stale->id(), 'Replacement bookmark has a different flagging ID');
  $confirm($stale_form);
  $check($exists($replacement), 'Old confirmation preserves the recreated bookmark');
  $check($rejects_build($stale->id()), 'Missing single flagging ID offers no deletion confirmation');

  // Reject input that was never shown, is incomplete, has expired or belongs
  // to another user, even when the test signs the latter cases deliberately.
  $signed_form = $build($replacement->id());
  $input = $posted_input($signed_form);
  $tampered = $input;
  $tampered['bookmark_selection'] .= ' ';
  $check($rejects_post($tampered), 'POST rejects a changed selection with its original signature');
  $check($rejects_post(['confirm' => 1]), 'POST rejects a missing selection instead of using the current queue');
  $expired_snapshot = json_decode($input['bookmark_selection'], TRUE, 512, JSON_THROW_ON_ERROR);
  $expired_snapshot['expires'] = time() - 1;
  $expired = ['confirm' => 1, 'bookmark_selection' => json_encode($expired_snapshot, JSON_THROW_ON_ERROR)];
  $expired['bookmark_signature'] = Drupal::service('csrf_token')->get($signed_form[0]->getFormId() . ':' . $expired['bookmark_selection']);
  $check($rejects_post($expired), 'POST rejects an expired but correctly signed selection');
  $other_snapshot = json_decode($input['bookmark_selection'], TRUE, 512, JSON_THROW_ON_ERROR);
  $other_snapshot['uid'] = $other->id();
  $other_input = ['confirm' => 1, 'bookmark_selection' => json_encode($other_snapshot, JSON_THROW_ON_ERROR)];
  $other_input['bookmark_signature'] = Drupal::service('csrf_token')->get($signed_form[0]->getFormId() . ':' . $other_input['bookmark_selection']);
  $check($rejects_post($other_input), 'POST rejects a signed selection for another user');
  $check($exists($replacement) && $exists($foreign), 'Rejected snapshots do not remove bookmarks');

  // The same protection is required for a bulk selection containing an ID
  // which disappeared before the confirmation page was opened.
  $missing = $make_bookmark($owner, 'missing');
  $survivor = $make_bookmark($owner, 'survivor');
  $action->executeMultiple([$missing, $survivor]);
  $missing->delete();
  $missing_form = $build();
  $confirm($missing_form);
  $check(!$exists($survivor) && $exists($replacement), 'Bulk confirmation tolerates missing IDs and preserves unrelated bookmarks');

  // A later selection in another tab cannot replace the selection already
  // shown on a confirmation page, including with a fresh POST FormState.
  $earlier = $make_bookmark($owner, 'earlier-tab');
  $later = $make_bookmark($owner, 'later-tab');
  $action->executeMultiple([$earlier]);
  $earlier_form = $build();
  $action->executeMultiple([$later]);
  $later_pending = $tempstore->get($selection_key);
  $confirm($post($earlier_form));
  $check(!$exists($earlier) && $exists($later), 'Earlier confirmation removes only its frozen selection');
  $check($tempstore->get($selection_key) === $later_pending, 'Earlier confirmation preserves the later tab\'s pending selection');
  $confirm($build());
  $check(!$exists($later), 'Later selection remains independently confirmable');

  // Recheck ownership at submit time instead of trusting access at build time.
  $changed_owner = $make_bookmark($owner, 'changed-owner');
  $ownership_form = $build($changed_owner->id());
  $changed_owner->setOwnerId($other->id());
  $changed_owner->save();
  $confirm($ownership_form);
  $check($exists($changed_owner), 'Submit rechecks ownership changed after confirmation build');
  $check($exists($foreign), 'All confirmation scenarios preserve the foreign fixture bookmark');
  $remaining_nodes = $database->select('node', 'n')->condition('nid', $fixture_ids['node'], 'IN')->countQuery()->execute()->fetchField();
  $check((int) $remaining_nodes === count($fixture_ids['node']), 'Removing bookmarks never deletes their content');
}
finally {
  try {
    if ($switched) {
      $switcher->switchBack();
    }
  }
  finally {
    $transaction->rollBack();
    foreach ($fixture_ids as $entity_type => $ids) {
      if ($ids) {
        Drupal::entityTypeManager()->getStorage($entity_type)->resetCache($ids);
      }
    }
  }
}

foreach (['user' => ['users', 'uid'], 'node' => ['node', 'nid'], 'flagging' => ['flagging', 'id']] as $entity_type => [$table, $column]) {
  if ($fixture_ids[$entity_type]) {
    $remaining = $database->select($table, 'fixture')->condition($column, $fixture_ids[$entity_type], 'IN')->countQuery()->execute()->fetchField();
    $check((int) $remaining === 0, "Rollback removed all $entity_type fixtures");
  }
}

echo "PASS: $checks bookmark action/confirmation integration checks. All fixture data rolled back.\n";
