<?php

namespace Drupal\ddbgo_gin\Form;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Confirms removal of personal bookmarks, never their referenced content.
 */
class BookmarkRemoveConfirmForm extends ConfirmFormBase {

  protected PrivateTempStore $selectionStore;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    PrivateTempStoreFactory $temp_store_factory,
    protected ActionManager $actionManager,
    protected CsrfTokenGenerator $csrfToken,
  ) {
    // Reuse the selection prepared by Core's DeleteAction implementation.
    $this->selectionStore = $temp_store_factory->get('entity_delete_multiple_confirm');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('plugin.manager.action'),
      $container->get('csrf_token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ddbgo_bookmark_remove_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Lesezeichen entfernen?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Nur die aufgeführten Lesezeichen werden aus Ihrer Liste entfernt. Die zugehörigen Inhalte bleiben erhalten.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Lesezeichen entfernen');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Abbrechen');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('view.flag_bookmark.page');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $flagging = NULL) {
    if ($this->getRequest()->isMethod('POST')) {
      $input = $form_state->getUserInput();
      $payload = $input['bookmark_selection'] ?? '';
      $signature = $input['bookmark_signature'] ?? '';
      // Normal Form API CSRF protection is still required. This extra signature
      // binds the submitted IDs to the selection actually shown in this session.
      if (!is_string($payload) || !is_string($signature)
        || !$this->csrfToken->validate($signature, $this->getFormId() . ':' . $payload)) {
        throw new BadRequestHttpException('Die Auswahl ist ungültig. Bitte wählen Sie die Lesezeichen erneut aus.');
      }
      $snapshot = json_decode($payload, TRUE);
      if (!is_array($snapshot) || !isset($snapshot['ids'], $snapshot['uid'], $snapshot['expires'])
        || !is_array($snapshot['ids']) || !array_key_exists('queue', $snapshot)
        || (!is_array($snapshot['queue']) && $snapshot['queue'] !== NULL)
        || $snapshot['expires'] < $this->getRequest()->server->get('REQUEST_TIME', time())
        || (string) $snapshot['uid'] !== (string) $this->currentUser()->id()) {
        throw new BadRequestHttpException('Die Bestätigung ist abgelaufen. Bitte wählen Sie die Lesezeichen erneut aus.');
      }
    }
    else {
      $queued = $flagging === NULL ? $this->selectionStore->get($this->selectionKey()) : NULL;
      $ids = $flagging === NULL ? array_keys($queued ?? []) : [$flagging];
      $bookmarks = $this->loadRemovable($ids);
      // GET form-state caching is forbidden by Core. A signed snapshot instead
      // preserves the displayed selection across tabs without trusting raw IDs.
      $snapshot = [
        'ids' => array_keys($bookmarks),
        'queue' => $queued,
        'uid' => $this->currentUser()->id(),
        'expires' => $this->getRequest()->server->get('REQUEST_TIME', time()) + 21600,
      ];
      $payload = json_encode($snapshot, JSON_THROW_ON_ERROR);
      $signature = $this->csrfToken->get($this->getFormId() . ':' . $payload);
    }
    // Never resolve a bookmark by node on POST: a removed and recreated
    // bookmark has a different flagging ID and must remain untouched.
    $form_state->set('bookmark_selection', $snapshot['ids']);
    $form_state->set('bookmark_queue', $snapshot['queue']);
    $bookmarks = $this->loadRemovable($form_state->get('bookmark_selection'));
    $form = parent::buildForm($form, $form_state);
    $form['#cache']['max-age'] = 0;
    $form['bookmark_selection'] = ['#type' => 'hidden', '#value' => $payload];
    $form['bookmark_signature'] = ['#type' => 'hidden', '#value' => $signature];
    $form['bookmarks'] = [
      '#theme' => 'item_list',
      '#items' => array_map(static fn ($bookmark) => ['#plain_text' => $bookmark->getFlaggable()->label()], array_values($bookmarks)),
      '#weight' => 1,
    ];
    $form['actions']['#weight'] = 2;
    if (!$bookmarks) {
      $form['description']['#markup'] = $this->t('Es sind keine entfernbaren Lesezeichen ausgewählt. Bitte kehren Sie zur Lesezeichenliste zurück.');
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $ids = $form_state->get('bookmark_selection') ?? [];
    $storage = $this->entityTypeManager->getStorage('flagging');
    $storage->resetCache($ids);
    // Recheck ownership, permission and content access immediately before use.
    $bookmarks = $this->loadRemovable($ids);
    $storage->delete($bookmarks);
    if ($bookmarks) {
      $this->messenger()->addStatus($this->formatPlural(count($bookmarks), 'Das Lesezeichen wurde entfernt.', '@count Lesezeichen wurden entfernt.'));
    }
    if (count($bookmarks) !== count($ids) || !$ids) {
      $this->messenger()->addWarning($this->t('Einige Lesezeichen waren bereits entfernt oder konnten nicht mehr bearbeitet werden.'));
    }
    // Preserve a newer selection prepared in another browser tab.
    $queued = $form_state->get('bookmark_queue');
    if ($queued !== NULL && $this->selectionStore->get($this->selectionKey()) === $queued) {
      $this->selectionStore->delete($this->selectionKey());
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Loads only personal bookmarks the current user may remove and inspect.
   *
   * @return \Drupal\flag\FlaggingInterface[]
   *   Accessible bookmarks, keyed by flagging ID.
   */
  protected function loadRemovable(array $ids): array {
    $action = $this->actionManager->createInstance('ddbgo_bookmark_remove');
    return array_filter(
      $this->entityTypeManager->getStorage('flagging')->loadMultiple($ids),
      fn ($bookmark) => $action->access($bookmark, $this->currentUser()),
    );
  }

  /**
   * Matches the per-user key used by Core's DeleteAction.
   */
  protected function selectionKey(): string {
    return $this->currentUser()->id() . ':flagging';
  }

}
