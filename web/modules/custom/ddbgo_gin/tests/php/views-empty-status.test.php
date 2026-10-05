<?php

/**
 * @file
 * Read-only empty Views results regression; run with drush php:script.
 *
 * Calls the real AJAX controller with random unmatched search input and checks
 * the appended command. Other cases use configured Views only in memory. No
 * entities, configuration or form submissions are saved. Mock sessions do not
 * persist filters. This does not replace a browser/screenreader test.
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\ddbgo_gin\EventSubscriber\EmptyViewsResultsSubscriber;
use Drupal\user\Entity\User;
use Drupal\views\Ajax\ViewAjaxResponse;
use Drupal\views\Controller\ViewAjaxController;
use Drupal\views\Plugin\views\area\Text;
use Drupal\views\Plugin\views\area\TextCustom;
use Drupal\views\ResultRow;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this regression through Drush.');
$subscriber = Drupal::service('ddbgo_gin.empty_views_results');
$check($subscriber instanceof EmptyViewsResultsSubscriber, 'The registered service uses the expected subscriber.');
$check(EmptyViewsResultsSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE][1] > AjaxResponseSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE][0][1], 'Announcement is appended before Core serializes AJAX responses.');
$libraries = Drupal::service('library.dependency_resolver')->getLibrariesWithDependencies(['views/views.ajax']);
$check(in_array('core/drupal.announce', $libraries, TRUE), 'Core announcement library is present before AJAX updates, via the Views AJAX dependency.');

$stack = Drupal::requestStack();
$theme = Drupal::theme();
$original_theme = $theme->getActiveTheme();
$switcher = Drupal::service('account_switcher');
$destination = Drupal::service('redirect.destination');
$original_destination = $destination->get();
$kernel = Drupal::service('http_kernel');
$switcher->switchTo(User::load(1));
$theme->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName('gin_frontend'));

// Text comes from the handler's visible rendering, not a duplicated translation.
$visible_message = static function ($view): string {
  $messages = [];
  foreach ($view->empty as $handler) {
    if ($handler instanceof TextCustom || $handler instanceof Text) {
      $build = $handler->render(TRUE);
      $text = trim(Html::decodeEntities(strip_tags((string) Drupal::service('renderer')->renderInIsolation($build))));
      if ($text !== '') {
        $messages[] = $text;
      }
    }
  }
  return Html::escape(implode("\n", $messages));
};
$event = static fn (Response $response, Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent => new ResponseEvent($kernel, $request, $type, $response);
$new_view = static function (string $id = 'suche_bestand_fuer_europeana', string $display = 'page') {
  $view = Views::getView($id);
  if (!$view || !$view->setDisplay($display)) {
    throw new RuntimeException('Expected configured Views display is missing.');
  }
  $view->initHandlers();
  $view->executed = TRUE;
  $view->result = [];
  return $view;
};
$synthetic_response = static function ($view): ViewAjaxResponse {
  $response = new ViewAjaxResponse();
  $response->setView($view);
  return $response;
};
$set_empty_handler = static function ($view, string $plugin_id, mixed $content): void {
  $handler = Drupal::service('plugin.manager.views.area')->createInstance($plugin_id);
  $handler->areaType = 'empty';
  $options = ['id' => 'empty_status_fixture', 'table' => 'views', 'field' => 'area', 'empty' => TRUE, 'content' => $content];
  $handler->init($view, $view->display_handler, $options);
  $view->empty = [$handler];
};

try {
  foreach (['suche_bestand_fuer_europeana' => 'search/bestand/europeana', 'suche_bestand_fuer_coding_da_vinci' => 'search/bestand/cdv'] as $id => $path) {
    $request = Request::create('http://localhost/views/ajax', 'GET', [
      'view_name' => $id,
      'view_display_id' => 'page',
      'view_path' => $path,
      'view_dom_id' => 'ddbgo-empty-results-fixture',
      'query' => 'ddbgo-no-results-' . bin2hex(random_bytes(24)),
    ]);
    $request->headers->set('Accept', 'application/json');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set('_route', 'views.ajax');
    $request->attributes->set('_route_object', Drupal::service('router.route_provider')->getRouteByName('views.ajax'));
    $stack->push($request);
    try {
      $response = ViewAjaxController::create(Drupal::getContainer())->ajaxView($request);
      $view = $response->getView();
      $check($view->executed && $view->result === [], "$id: real configured query returns no results.");
      $before = $response->getCommands();
      $replacements = array_values(array_filter($before, static fn (array $command): bool => ($command['command'] ?? '') === 'insert' && ($command['method'] ?? '') === 'replaceWith'));
      $prepends = array_values(array_filter($before, static fn (array $command): bool => ($command['command'] ?? '') === 'insert' && ($command['method'] ?? '') === 'prepend'));
      $check(count($replacements) === 1 && count($prepends) === 1, "$id: real Views replacement and status-message prepend commands exist.");
      $expected = $visible_message($view);
      $check($expected !== '', "$id: existing configured empty message is visible.");

      // Scope markup assertions to the empty-results message, not unrelated
      // status_messages regions provided by Drupal elsewhere in the response.
      $dom = new DOMDocument();
      @$dom->loadHTML('<?xml encoding="UTF-8">' . $replacements[0]['data']);
      $xpath = new DOMXPath($dom);
      $empty = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " view-empty ")]');
      $check($empty->length === 1, "$id: original visible empty-result wrapper remains.");
      $check(Html::escape(trim($empty->item(0)->textContent)) === $expected, "$id: visible text and announcement convey the same message.");
      $check($xpath->query('self::*[@role="status" or @role="alert" or @aria-live] | .//*[@role="status" or @role="alert" or @aria-live]', $empty->item(0))->length === 0, "$id: inserted empty text has no second live region.");

      $subscriber->onResponse($event($response, $request));
      $after = $response->getCommands();
      $check(count($after) === count($before) + 1 && array_slice($after, 0, count($before)) === $before, "$id: original commands and order are preserved.");
      $check(end($after) === ['command' => 'announce', 'text' => $expected, 'priority' => 'polite'], "$id: exactly one matching polite announcement is appended after replacement.");
      $check(in_array('core/drupal.announce', $response->getAttachments()['library'] ?? [], TRUE), "$id: AnnounceCommand keeps its Core asset dependency.");
      $subscriber->onResponse($event($response, $request));
      $check($response->getCommands() === $after, "$id: repeated handling does not announce twice.");

      // Simulate already loaded page assets to avoid generating aggregate files.
      // Use Core's real subscriber to verify the command reaches the JSON sent
      // to the browser, rather than testing only the response's command array.
      $request->attributes->set('ajax_page_state', ['libraries' => implode(',', Drupal::service('library.dependency_resolver')->getLibrariesWithDependencies($response->getAttachments()['library'] ?? []))]);
      Drupal::service('ajax_response.subscriber')->onResponse($event($response, $request));
      $json = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $announcements = array_values(array_filter($json, static fn (array $command): bool => ($command['command'] ?? '') === 'announce'));
      $check($announcements === [['command' => 'announce', 'text' => $expected, 'priority' => 'polite']], "$id: Core serializes exactly one complete announcement into the final JSON response.");
      $check($response->headers->get('X-Drupal-Ajax-Token') === '1', "$id: Core still marks the AJAX response as trusted.");
    }
    finally {
      $stack->pop();
    }
  }

  $request = Request::create('http://localhost/views/ajax');
  $unchanged = static function (Response $response, string $case, int $type = HttpKernelInterface::MAIN_REQUEST) use ($subscriber, $event, $request, $check): void {
    $before = $response instanceof AjaxResponse ? $response->getCommands() : $response->getContent();
    $subscriber->onResponse($event($response, $request, $type));
    $after = $response instanceof AjaxResponse ? $response->getCommands() : $response->getContent();
    $check($before === $after, "$case: no commands or content are changed.");
  };
  $unchanged(new Response('<p>Ordinary page response</p>'), 'Full page HTML');
  $unchanged(new AjaxResponse(), 'Unrelated AJAX response');
  $unchanged(new ViewAjaxResponse(), 'Views response without a View');
  $unchanged($synthetic_response($new_view()), 'Subrequest', HttpKernelInterface::SUB_REQUEST);

  foreach (['suche', 'suche_kwe', 'suche_aggregator', 'suche_bestand', 'personensuche', 'suche_aggregator_fuer_claudia'] as $id) {
    $view = Views::getView($id);
    foreach ($view->storage->get('display') as $display_id => $display) {
      if ($display['display_plugin'] === 'page') {
        $fixture = $new_view($id, $display_id);
        $check(!$fixture->display_handler->ajaxEnabled(), "$id/$display_id: current list configuration loads a whole page.");
        $unchanged($synthetic_response($fixture), "$id/$display_id without AJAX");
      }
    }
  }
  $unchanged($synthetic_response($new_view('person', 'default')), 'Configured AJAX View without an empty-result message');

  // No ID/display whitelist: a default display, a block and a list whose AJAX
  // option is enabled only in this process all use their configured text.
  $announces = static function ($view, string $expected, string $case) use ($subscriber, $event, $request, $check, $synthetic_response): void {
    $response = $synthetic_response($view);
    $subscriber->onResponse($event($response, $request));
    $check($response->getCommands() === [['command' => 'announce', 'text' => $expected, 'priority' => 'polite']], "$case: exactly the visible text is announced once, irrespective of display type or View ID.");
  };
  $view = $new_view('suche_bestand_fuer_europeana', 'default');
  $announces($view, $visible_message($view), 'Default display');
  $view = $new_view('person', 'person_kwe');
  $set_empty_handler($view, 'text_custom', '<p>Keine Personen gefunden.</p>');
  $announces($view, 'Keine Personen gefunden.', 'AJAX block display');
  $view = $new_view('suche_kwe', 'page_1');
  $view->display_handler->setOption('use_ajax', TRUE);
  $set_empty_handler($view, 'text_custom', '<p>Keine Einrichtungen gefunden.</p>');
  $announces($view, 'Keine Einrichtungen gefunden.', 'Another list with AJAX enabled in memory');

  // Standard Text uses processed_text rather than #markup. Exercise the real
  // format filters, including allowed emphasis and encoded HTML as plain text.
  $view = $new_view('person', 'person_kwe');
  $set_empty_handler($view, 'text', ['value' => '<p><strong>Keine Personen</strong> &amp; &lt;span&gt;gefunden&lt;/span&gt;.</p>', 'format' => 'basic_html']);
  $announces($view, 'Keine Personen &amp; &lt;span&gt;gefunden&lt;/span&gt;.', 'Processed-text empty handler');
  $view->empty[0]->options['empty'] = FALSE;
  $unchanged($synthetic_response($view), 'Processed-text handler configured not to display');

  $view = $new_view();
  $set_empty_handler($view, 'result', '@total');
  $unchanged($synthetic_response($view), 'Non-text empty-area plugin');

  $view = $new_view();
  $view->result = [new ResultRow()];
  $unchanged($synthetic_response($view), 'Nonempty results');
  $view = $new_view();
  $view->executed = FALSE;
  $unchanged($synthetic_response($view), 'Not executed yet');
  $view = $new_view();
  $view->display_handler->setOption('use_ajax', FALSE);
  $unchanged($synthetic_response($view), 'AJAX disabled');
  $view = $new_view();
  $view->empty = [];
  $unchanged($synthetic_response($view), 'No visible empty handler');
  $view = $new_view();
  foreach ($view->empty as $handler) {
    $handler->options['empty'] = FALSE;
  }
  $unchanged($synthetic_response($view), 'Empty handlers configured not to display');
  foreach ([new AnnounceCommand('Existing announcement'), new MessageCommand('Existing message')] as $command) {
    $response = $synthetic_response($new_view());
    $response->addCommand($command);
    $unchanged($response, 'Another component already supplies an announcement');
  }

  // Core Drupal.announce() writes innerHTML. Encoded markup must stay text,
  // while ordinary allowed markup is stripped and entities are read correctly.
  $view = $new_view();
  foreach ($view->empty as $handler) {
    if ($handler instanceof TextCustom) {
      $handler->options['content'] = '<p><strong>Keine Treffer</strong> &amp; &lt;img src=x onerror=alert(1)&gt; &quot;Test&quot;</p>';
      $handler->options['empty'] = TRUE;
    }
  }
  $response = $synthetic_response($view);
  $subscriber->onResponse($event($response, $request));
  $commands = $response->getCommands();
  $check(count($commands) === 1 && $commands[0]['text'] === 'Keine Treffer &amp; &lt;img src=x onerror=alert(1)&gt; &quot;Test&quot;', 'Encoded markup cannot become executable HTML in the announcement region.');
  $check(Html::decodeEntities($commands[0]['text']) === 'Keine Treffer & <img src=x onerror=alert(1)> "Test"', 'Escaping preserves the complete plain-text message.');
}
finally {
  $destination->set($original_destination);
  $theme->setActiveTheme($original_theme);
  $switcher->switchBack();
}

echo "PASS: $checks read-only Views empty-result status checks.\n";
