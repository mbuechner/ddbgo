<?php

/**
 * @file
 * Isolated bookmark queue interleaving checks; run directly with PHP.
 * Uses Core's action staging and the real confirmation submit handler with
 * in-memory storage and separate simulated request lock owners. No DB access.
 */

use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\TempStoreException;
use Drupal\ddbgo_gin\BookmarkSelectionLock;
use Drupal\ddbgo_gin\Form\BookmarkRemoveConfirmForm;
use Drupal\ddbgo_gin\Plugin\Action\BookmarkRemoveAction;

$root = dirname(__DIR__, 6);
require $root . '/vendor/autoload.php';
require $root . '/web/modules/custom/ddbgo_gin/src/BookmarkSelectionLock.php';
require $root . '/web/modules/custom/ddbgo_gin/src/Plugin/Action/BookmarkRemoveAction.php';
require $root . '/web/modules/custom/ddbgo_gin/src/Form/BookmarkRemoveConfirmForm.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$lock = new class implements LockBackendInterface {
  public string $request = 'confirmation';
  public array $held = [];
  public function acquire($name, $timeout = 30.0) {
    if (isset($this->held[$name]) && $this->held[$name] !== $this->request) {
      return FALSE;
    }
    $this->held[$name] = $this->request;
    return TRUE;
  }
  public function wait($name, $delay = 30) { return TRUE; }
  public function lockMayBeAvailable($name) { return !isset($this->held[$name]); }
  public function release($name) {
    if (($this->held[$name] ?? NULL) === $this->request) {
      unset($this->held[$name]);
    }
  }
  public function releaseAll($lockId = NULL) { $this->held = []; }
  public function getLockId() { return $this->request; }
};
$container = new Container();
$container->set('lock', $lock);
Drupal::setContainer($container);
$account = new UserSession(['uid' => 17, 'roles' => ['authenticated']]);
$name = 'ddbgo_gin.bookmark_selection:17';
$store = new class($lock, $name, $check) extends PrivateTempStore {
  public array $values = [];
  public ?Closure $beforeGetReturns = NULL;
  public function __construct(private $testLock, private string $lockName, private Closure $check) {}
  public function get($key) {
    $value = $this->values[$key] ?? NULL;
    if ($this->beforeGetReturns) {
      $callback = $this->beforeGetReturns;
      $this->beforeGetReturns = NULL;
      $callback();
    }
    return $value;
  }
  public function set($key, $value) {
    ($this->check)(($this->testLock->held[$this->lockName] ?? NULL) === $this->testLock->request, 'Staging holds the shared user lock');
    $this->values[$key] = $value;
  }
  public function delete($key) {
    ($this->check)(($this->testLock->held[$this->lockName] ?? NULL) === $this->testLock->request, 'Cleanup holds the shared user lock');
    unset($this->values[$key]);
    return TRUE;
  }
};
$action = new class($store, $account) extends BookmarkRemoveAction {
  public function __construct(PrivateTempStore $store, AccountInterface $account) {
    $this->tempStore = $store;
    $this->currentUser = $account;
    $this->pluginDefinition = ['type' => 'flagging'];
  }
};
$form_object = new class($store, $account) extends BookmarkRemoveConfirmForm {
  public function __construct(PrivateTempStore $store, private AccountInterface $testAccount) {
    $this->selectionStore = $store;
    $this->entityTypeManager = new class extends EntityTypeManager {
      public function __construct() {}
      public function getStorage($type) {
        return new class {
          public function resetCache($ids) {}
          public function delete($entities) {}
        };
      }
    };
  }
  protected function loadRemovable(array $ids): array { return []; }
  protected function currentUser() { return $this->testAccount; }
  protected function t($string, array $args = [], array $options = []) { return $string; }
  public function messenger() {
    return new class { public function addWarning($message) {} };
  }
};
$bookmark = static fn (int $id) => new class($id) {
  public function __construct(private int $bookmarkId) {}
  public function id() { return $this->bookmarkId; }
  public function language() {
    return new class { public function getId() { return 'und'; } };
  }
};
$key = '17:flagging';
$action->executeMultiple([$bookmark(11)]);
$old_queue = $store->get($key);
$check($old_queue === [11 => ['und' => 'und']], 'Core staging format and key remain unchanged');
$state = (new FormState())->set('bookmark_selection', [11])->set('bookmark_queue', $old_queue);
$store->beforeGetReturns = function () use ($lock, $action, $bookmark, $store, $key, $old_queue, $check): void {
  // Interleave a second request after the comparison's read, before deletion.
  $lock->request = 'staging';
  try {
    $action->executeMultiple([$bookmark(22)]);
    throw new RuntimeException('Concurrent staging should have waited for cleanup');
  }
  catch (TempStoreException $exception) {
    $check(($store->values[$key] ?? NULL) === $old_queue, 'Concurrent staging cannot replace the queue between read and delete');
  }
  finally {
    $lock->request = 'confirmation';
  }
};
$form = [];
$form_object->submitForm($form, $state);
$check($store->get($key) === NULL && $lock->held === [], 'Old queue is cleaned and the lock released');
$lock->request = 'staging';
$action->executeMultiple([$bookmark(22)]);
$new_queue = $store->get($key);
$check($new_queue === [22 => ['und' => 'und']], 'Waiting staging can install its selection after cleanup');
$lock->request = 'confirmation';
$form_object->submitForm($form, $state);
$check($store->get($key) === $new_queue, 'Replayed older confirmation preserves the newer queue');
try {
  BookmarkSelectionLock::withLock($account, static function (): void { throw new RuntimeException('callback failed'); });
}
catch (RuntimeException $exception) {
  $check($exception->getMessage() === 'callback failed' && $lock->held === [], 'Callback failure also releases the lock');
}
echo "PASS: $checks isolated bookmark selection interleaving checks. No DB access.\n";
