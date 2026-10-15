<?php

/**
 * @file
 * Verifies configured node deletion redirects without saving/deleting content.
 *
 * Run: .\drush.cmd php:script drush/tests/node-delete-redirect.php
 *
 * Uses the installed NodeDeleteForm and its complete submit-handler chain,
 * followed by Core's FormSubmitter and RedirectResponseSubscriber. Node doubles
 * cannot be saved and only record calls to delete(). Form messages and deletion
 * logs remain in memory; existing entities and configuration are not modified.
 * This is a redirect integration check, not an HTTP/CSRF or browser test.
 *
 * The unmodified module retains Core's destination precedence: an incoming
 * destination overrides the configured list, including a destination pointing
 * to the deleted node. That stale-node case can therefore lead to a 404 after
 * an actual deletion; this test documents that behavior without fetching it.
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * An in-memory node that cannot perform any content writes.
 */
final class NodeDeleteRedirectTestNode extends Node {

  public int $deleteCalls = 0;

  /**
   * {@inheritdoc}
   */
  public function save() {
    throw new LogicException('This test must never save a node.');
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $this->deleteCalls++;
  }

}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};

$check(PHP_SAPI === 'cli', 'Run through Drush, never through a web request.');
$check(Drupal::moduleHandler()->moduleExists('node_delete_redirect'), 'Node Delete Redirect must be enabled.');

$targets = [
  'bestand' => '/search/bestand',
  'kwe' => '/search/kwe',
  'person' => '/search/person',
  'aggregator' => '/search/aggregator',
];
$settings = Drupal::config('node_delete_redirect.admin_settings_form')->get('ndr_admin_form_settings');
$check((int) ($settings['ndr_check'] ?? 0) === 1, 'Node deletion redirects must be enabled in configuration.');
foreach ($targets as $bundle => $target) {
  $configured = $settings['ndr_settings'][$bundle] ?? [];
  $check(!empty($configured['is_enabled']) && ($configured['redirect'] ?? NULL) === $target, "$bundle must redirect to $target.");
}
$check(empty($settings['ndr_settings']['page']['is_enabled']), 'The page bundle must remain unconfigured.');

$entity_type_manager = Drupal::entityTypeManager();
$request_stack = Drupal::service('request_stack');
$form_builder = Drupal::formBuilder();
$form_submitter = Drupal::service('form_submitter');
$redirect_subscriber = Drupal::service('redirect_response_subscriber');
$route = Drupal::service('router.route_provider')->getRouteByName('entity.node.delete_form');
$origin = $request_stack->getCurrentRequest()->getSchemeAndHttpHost();

// No logger backends or real session flash bag: a simulated deletion must not
// leave a success message or a misleading dblog entry behind.
$logger_factory = new LoggerChannelFactory($request_stack, Drupal::currentUser());
$messenger = new Messenger(new FlashBag(), new KillSwitch());

/**
 * Compares paths and query parameters without depending on the Drush host URI.
 */
$same_destination = static function (string $actual, string $expected): bool {
  $actual_parts = parse_url($actual);
  $expected_parts = parse_url($expected);
  parse_str($actual_parts['query'] ?? '', $actual_query);
  parse_str($expected_parts['query'] ?? '', $expected_query);
  return ($actual_parts['path'] ?? '/') === ($expected_parts['path'] ?? '/')
    && $actual_query === $expected_query
    && ($actual_parts['fragment'] ?? '') === ($expected_parts['fragment'] ?? '');
};

$case_number = 0;
$run_case = static function (string $bundle, ?string $target, string $variant) use (
  &$case_number,
  $check,
  $same_destination,
  $entity_type_manager,
  $request_stack,
  $form_builder,
  $form_submitter,
  $redirect_subscriber,
  $route,
  $origin,
  $logger_factory,
  $messenger,
): void {
  $case_number++;
  $id = 2147483000 + $case_number;
  $node_path = '/node/' . $id;
  $list_destination = ($target ?? '/search/person') . '?query=redirect-check&page=2';
  $destination = match ($variant) {
    'node' => $node_path,
    'list' => $list_destination,
    default => NULL,
  };
  // ContentEntityBase's constructor expects language-keyed storage values.
  // Use the field API for plain values, as normal entity storage does when
  // creating an entity, while retaining our non-persisting test double.
  $node = new NodeDeleteRedirectTestNode([], 'node', $bundle);
  foreach ([
    'nid' => $id,
    'type' => $bundle,
    'title' => 'Redirect verification: ' . $bundle,
    'langcode' => Drupal::languageManager()->getDefaultLanguage()->getId(),
  ] as $field => $value) {
    $node->set($field, $value);
  }
  $check((int) $node->id() === $id, 'The in-memory node must have its synthetic ID.');

  $request = Request::create($origin . $node_path . '/delete', 'GET', $destination === NULL ? [] : ['destination' => $destination]);
  $request->setSession(new Session(new MockArraySessionStorage()));
  $request->attributes->add([
    '_route' => 'entity.node.delete_form',
    '_route_object' => $route,
    '_raw_variables' => new ParameterBag(['node' => (string) $id]),
    'node' => $node,
  ]);
  $request_stack->push($request);

  try {
    $form_object = $entity_type_manager->getFormObject('node', 'delete');
    $form_object->setEntity($node);
    $form_object->setRequestStack($request_stack);
    $form_object->setLoggerFactory($logger_factory);
    $form_object->setMessenger($messenger);
    $form_state = new FormState();
    $form_state->disableCache();
    $form = $form_builder->buildForm($form_object, $form_state);
    $label = "$bundle/$variant";

    $check($form_object->getFormId() === 'node_' . $bundle . '_delete_form', "$label: the real node delete form was built.");
    $check($node->deleteCalls === 0, "$label: building the confirmation must not delete anything.");
    $check(isset($form['actions']['cancel']['#url']), "$label: cancel must remain available.");
    $check($same_destination($form['actions']['cancel']['#url']->toString(), $destination ?? $node_path), "$label: cancel must retain its original destination.");

    // This is the actual button stack after all module/theme form alters.
    $handlers = $form['actions']['submit']['#submit'] ?? [];
    $check(in_array('node_delete_redirect_form_submit', $handlers, TRUE) === ($target !== NULL), "$label: redirect handler must apply only to configured bundles.");
    $form_state->setTriggeringElement($form['actions']['submit']);
    $form_state->setSubmitHandlers($handlers);
    $form_state->setSubmitted();
    $request->setMethod('POST');
    $form_submitter->executeSubmitHandlers($form, $form_state);

    $check($node->deleteCalls === 1, "$label: the normal delete callback must run exactly once on the double.");
    $check($form_state->getIgnoreDestination() === FALSE, "$label: the unmodified module must retain Core's destination precedence.");
    $response = $form_submitter->redirectForm($form_state);
    $check($response instanceof RedirectResponse, "$label: FormSubmitter must produce a redirect.");

    // Merely checking FormState would miss Core overriding the configured URL
    // with ?destination=/node/ID later, during kernel.response handling.
    $event = new ResponseEvent(Drupal::service('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $redirect_subscriber->checkRedirectUrl($event);
    $final_response = $event->getResponse();
    $expected = $destination ?? $target ?? Url::fromRoute('<front>')->toString();
    $check($final_response instanceof RedirectResponse && $final_response->getStatusCode() === 303, "$label: the final response must be a safe 303 redirect.");
    $check($same_destination($final_response->headers->get('Location'), $expected), "$label: unexpected final Location; expected $expected.");
  }
  finally {
    $request_stack->pop();
    $redirect_subscriber->setIgnoreDestination(FALSE);
    $messenger->deleteAll();
  }
};

foreach ($targets as $bundle => $target) {
  foreach (['none', 'node', 'list'] as $variant) {
    $run_case($bundle, $target, $variant);
  }
}
foreach (['none', 'node', 'list'] as $variant) {
  $run_case('page', NULL, $variant);
}

printf("PASS: %d checks in %d node deletion redirect cases; no content saved or deleted.\n", $checks, $case_number);
