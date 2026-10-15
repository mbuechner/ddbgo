<?php

/**
 * @file
 * Read-only checks for feedback after full-page form submissions.
 *
 * Run after rebuilding the cache and importing the Toastify settings:
 *   drush php:script web/modules/custom/ddbgo_gin/tests/php/page-feedback.test.php
 *
 * Uses actual Gin/Gin Frontend/Claro templates and Core's HTML renderer. An
 * isolated messenger backed by an in-memory session protects existing flash
 * messages. No form submission, entity save or configuration write occurs.
 * Browser validation popups and screen-reader speech require manual testing.
 */

use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Template\Attribute;
use Drupal\toastify\Element\ToastifyStatusMessages;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this regression through Drush.');
$check(function_exists('ddbgo_gin_preprocess_html') && function_exists('ddbgo_gin_preprocess_status_messages'), 'Page-feedback hooks are loaded.');
$check(Drupal::config('toastify.settings')->get('enable_for.admin_theme') === FALSE && Drupal::config('toastify.settings')->get('enable_for.frontend_theme') === FALSE, 'Imported configuration disables Toastify for both themes.');
$dependencies = Drupal::service('library.dependency_resolver')->getLibrariesWithDependencies(['ddbgo_gin/page_feedback']);
$check(in_array('core/drupal.announce', $dependencies, TRUE), 'Feedback loads Core announcement support.');

$container = Drupal::getContainer();
$original_messenger = Drupal::messenger();
$original_theme = Drupal::theme()->getActiveTheme();
$stack = Drupal::requestStack();
$switcher = Drupal::service('account_switcher');
$session = new Session(new MockArraySessionStorage());
$messenger = new Messenger($session->getFlashBag(), new KillSwitch());
$container->set('messenger', $messenger);
$switcher->switchTo(User::load(1));

$request = static function (string $method = 'GET', bool $ajax = FALSE, string $route = 'system.site_information_settings') use ($session): Request {
  $request = Request::create('http://localhost/ddbgo-page-feedback-fixture', $method);
  $request->setSession($session);
  // A registered route prevents unrelated Gin preprocessors from resolving a
  // synthetic route name; its controller is never invoked by this script.
  $request->attributes->set('_route', $route);
  $request->attributes->set('_route_object', Drupal::service('router.route_provider')->getRouteByName($route));
  if ($ajax) {
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');
  }
  return $request;
};
$xpath = static function (string $html): DOMXPath {
  $document = new DOMDocument();
  @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
  return new DOMXPath($document);
};
$render = static function (array $build) use ($xpath): array {
  $context = new RenderContext();
  $markup = Drupal::service('renderer')->executeInRenderContext($context, static function () use (&$build): string {
    return (string) Drupal::service('renderer')->render($build);
  });
  $metadata = $context->pop();
  return [$xpath($markup), $metadata->getAttachments()];
};
$fixture_messages = static function () use ($messenger): void {
  $messenger->deleteAll();
  $messenger->addError(Markup::create('<p>Fixture validation failed. <a href="#feedback-fixture-field">Review field</a></p>'));
  $messenger->addStatus('Fixture record saved.');
  $messenger->addWarning('Fixture warning.');
};

try {
  foreach (['gin', 'gin_frontend', 'claro'] as $theme_name) {
    Drupal::theme()->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme_name));
    $is_gin = $theme_name !== 'claro';
    foreach ([['GET', FALSE], ['POST', FALSE], ['GET', TRUE], ['POST', TRUE]] as [$method, $ajax]) {
      $current_request = $request($method, $ajax);
      $stack->push($current_request);
      try {
        $label = "$theme_name/$method/" . ($ajax ? 'XHR' : 'full-page');
        $fixture_messages();
        $messages_before = $messenger->all();
        $variables = [
          'head_title' => ['title' => 'Existing title & name', 'name' => 'Existing site', 'slogan' => 'Existing slogan'],
          '#cache' => ['max-age' => 600, 'tags' => ['fixture:feedback'], 'contexts' => ['languages:language_interface']],
        ];
        $old_title = $variables['head_title'];
        $old_cache = $variables['#cache'];
        ddbgo_gin_preprocess_html($variables);
        $with_error_title = $is_gin && $method === 'POST' && !$ajax;
        $check(isset($variables['head_title']['feedback']) === $with_error_title, "$label: only failed full-page Gin POSTs get a title prefix.");
        if ($with_error_title) {
          $check(array_key_first($variables['head_title']) === 'feedback' && (string) $variables['head_title']['feedback'] === (string) t('Fehler'), "$label: the translated error prefix comes first.");
          $check(array_diff_key($variables['head_title'], ['feedback' => TRUE]) === $old_title, "$label: page, site and slogan stay unchanged.");
          $check($variables['#cache'] === ['max-age' => 0] + $old_cache, "$label: error-title output is uncacheable and retains other metadata.");
        }
        else {
          $check($variables['head_title'] === $old_title && $variables['#cache'] === $old_cache, "$label: unaffected titles and cache metadata remain identical.");
        }
        $check($messenger->all() === $messages_before, "$label: HTML preprocessing peeks without consuming flash messages.");
        $check(!toastify_is_active(), "$label: configured Toastify API is inactive even for an administrator.");

        // Exercise the contrib render-element class, rather than bypassing it
        // with a synthetic theme array. Its inactive branch must retain Core's
        // actual server-rendered message markup and every message type.
        $build = ToastifyStatusMessages::renderMessages();
        $check(($build['#theme'] ?? NULL) === 'status_messages' && ($build['#message_list'] ?? NULL) === $messages_before, "$label: Toastify falls back to all Core message types.");
        $check(!isset($build['#attached']['drupalSettings']['toastify']['messages']), "$label: messages are not diverted into toast settings.");
        $check($messenger->all() === [], "$label: only the message renderer consumes flash messages.");
        [$dom, $attachments] = $render($build);
        $groups = $dom->query('//*[@role="contentinfo"]');
        $check($groups->length === 3, "$label: error, status and warning groups remain visible.");
        $expected_marked = $is_gin && !$ajax;
        $marked = $dom->query('//*[@data-ddbgo-page-messages]');
        $check($marked->length === ($expected_marked ? 3 : 0), "$label: page-feedback markers are limited to non-AJAX Gin messages.");
        $check(in_array('ddbgo_gin/page_feedback', $attachments['library'] ?? [], TRUE) === $expected_marked, "$label: feedback assets bubble only from the applicable message placeholder.");
        foreach ($marked as $group) {
          $check($group->getAttribute('tabindex') === '-1', "$label: message focus is programmatic, with no additional Tab stop.");
          $ids = preg_split('/\s+/', trim($group->getAttribute('aria-labelledby')));
          $check(count($ids) === 1 && $ids[0] !== '', "$label: Gin retains its message-heading reference.");
          $headings = $dom->query('//*[@id="' . $ids[0] . '"]');
          $check($headings->length === 1 && $headings->item(0)->nodeName === 'h2' && trim($headings->item(0)->textContent) !== '', "$label: every heading reference resolves to one nonempty native heading.");
        }
        $check($dom->query('//a[@href="#feedback-fixture-field"]')->length === 1, "$label: the error-summary field link survives rendering.");
        $check(str_contains($dom->document->textContent, 'Fixture record saved.') && str_contains($dom->document->textContent, 'Fixture validation failed.') && str_contains($dom->document->textContent, 'Fixture warning.'), "$label: original outcome text is retained.");
        if ($is_gin) {
          $check($dom->query('//button[contains(concat(" ",normalize-space(@class)," ")," js-message-button-hide ")]')->length === 3, "$label: Gin's dismiss buttons remain available.");
        }

        $empty_variables = ['message_list' => ['error' => [], 'status' => []], 'attributes' => new Attribute(['class' => ['fixture-class']])];
        ddbgo_gin_preprocess_status_messages($empty_variables);
        $check(!$empty_variables['attributes']->hasAttribute('data-ddbgo-page-messages') && !isset($empty_variables['#attached']), "$label: empty groups get neither focus markers nor a feedback library.");

        // Native required/type validation produces no server error message.
        // The PHP hook must not manufacture an error state from the method.
        $messenger->addStatus('Fixture successful submission.');
        $status_variables = ['head_title' => $old_title, '#cache' => $old_cache];
        ddbgo_gin_preprocess_html($status_variables);
        $check($status_variables === ['head_title' => $old_title, '#cache' => $old_cache], "$label: status-only output never gets an error-title prefix.");
        $messenger->deleteAll();
      }
      finally {
        $stack->pop();
      }
    }

    if (!$is_gin) {
      continue;
    }
    // Core renders the HTML shell before replacing status-message lazy
    // placeholders. Exercise both stages through the actual services. A GET
    // destination has the same late message-rendering path as cached redirect
    // targets; these checks deliberately do not submit a form or seed a page
    // cache entry. Asset aggregation generates URLs without fetching them.
    foreach ([['POST', 'system.site_information_settings'], ['GET', 'system.site_information_settings'], ['POST', 'system.404']] as [$method, $route]) {
      $current_request = $request($method, FALSE, $route);
      $stack->push($current_request);
      try {
        $label = "$theme_name/pipeline/$method/$route";
        if ($method === 'POST') {
          $fixture_messages();
        }
        else {
          $messenger->deleteAll();
          $messenger->addStatus('Fixture record saved.');
        }
        $messages_before = $messenger->all();
        $page = [
          '#type' => 'page',
          '#title' => 'Feedback fixture & title',
          '#cache' => ['max-age' => 600],
          'content' => [
            'fixture' => ['#markup' => '<p>Unsaved fixture page.</p>'],
            'messages' => ['#type' => 'status_messages'],
          ],
        ];
        $response = Drupal::service('main_content_renderer.html')->renderResponse($page, $current_request, RouteMatch::createFromRequest($current_request));
        $dom = $xpath($response->getContent());
        $titles = $dom->query('//title');
        $prefix = $method === 'POST' ? (string) t('Fehler') . ' | ' : '';
        $title = trim($titles->item(0)?->textContent ?? '');
        if ($route === 'system.404') {
          // Metatag deliberately replaces the 404 title in its preprocessor.
          // Our feedback hook must run later, preserving that authoritative
          // title while adding the failed-submission prefix.
          $check(str_starts_with($title, $prefix) && substr_count($title, $prefix) === 1 && str_ends_with($title, ' | ' . Drupal::config('system.site')->get('name')), "$label: the error prefix survives Metatag's title override.");
        }
        else {
          $check($titles->length === 1 && $title === $prefix . 'Feedback fixture & title | ' . Drupal::config('system.site')->get('name'), "$label: real document title reflects errors while preserving the page/site title.");
        }
        if ($method === 'POST') {
          $check($response->getCacheableMetadata()->getCacheMaxAge() === 0, "$label: error-title cache metadata reaches the HTML response.");
        }
        $check($messenger->all() === $messages_before, "$label: HTML rendering leaves messages for the later lazy-placeholder stage.");
        $check($dom->query('//drupal-render-placeholder')->length >= 1, "$label: Core defers message rendering until response attachment processing.");
        $response = Drupal::service('html_response.attachments_processor')->processAttachments($response);
        $final_dom = $xpath($response->getContent());
        $check($final_dom->query('//*[@data-ddbgo-page-messages]')->length === count($messages_before), "$label: final HTML contains every session-specific message group.");
        $check($final_dom->query('//drupal-render-placeholder')->length === 0, "$label: final response replaces all lazy placeholders.");
        $check($messenger->all() === [], "$label: response processing consumes flash messages exactly once.");
        $check(in_array('ddbgo_gin/page_feedback', $response->getAttachments()['library'] ?? [], TRUE), "$label: lazy-placeholder feedback assets reach the final response.");
        $assets = AttachedAssets::createFromRenderArray(['#attached' => $response->getAttachments()]);
        $js_assets = Drupal::service('asset.resolver')->getJsAssets($assets, FALSE);
        $files = array_merge($js_assets[0], $js_assets[1]);
        $check(isset($files['modules/custom/ddbgo_gin/js/ddbgo_gin.page-feedback.js']) && $final_dom->query('//script[@src]')->length > 0, "$label: final script assets include page feedback and are emitted in HTML.");
        $check(trim($final_dom->query('//title')->item(0)->textContent) === $title, "$label: late message rendering leaves the document title unchanged.");
      }
      finally {
        $messenger->deleteAll();
        $stack->pop();
      }
    }
  }
}
finally {
  $messenger->deleteAll();
  $container->set('messenger', $original_messenger);
  Drupal::theme()->setActiveTheme($original_theme);
  $switcher->switchBack();
}

echo "PASS: $checks page-feedback checks; no forms submitted, entities saved or configuration changed.\n";
