<?php

namespace Drupal\ddbgo_gin\EventSubscriber;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Render\RendererInterface;
use Drupal\views\Ajax\ViewAjaxResponse;
use Drupal\views\Plugin\views\area\Text;
use Drupal\views\Plugin\views\area\TextCustom;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Announces empty-result text after any Views display is updated via AJAX.
 *
 * Views replaces its entire wrapper, so a newly inserted role="status" would
 * not reliably announce the message. Reuse Core's persistent live region,
 * already loaded through views/views.ajax, without changing focus or markup.
 */
final class EmptyViewsResultsSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly RendererInterface $renderer) {}

  /**
   * Appends the visible empty-result text to a completed Views AJAX response.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$response instanceof ViewAjaxResponse) {
      return;
    }

    $view = $response->getView();
    if (!$view || !$view->executed || $view->result !== []
      || !$view->display_handler->ajaxEnabled()) {
      return;
    }

    // Preserve an announcement supplied by another component. This also makes
    // repeated response handling silent instead of appending the message twice.
    foreach ($response->getCommands() as $command) {
      if (in_array($command['command'] ?? '', ['announce', 'message'], TRUE)) {
        return;
      }
    }

    $messages = [];
    foreach ($view->empty as $handler) {
      if ($handler instanceof TextCustom || $handler instanceof Text) {
        // Use the existing Views text, including its token handling and display
        // settings. Render processed text with its configured format as well.
        // Other empty-area plugins can embed whole views or entities: announce
        // only text messages, not all such content or an invented fallback.
        $build = $handler->render(TRUE);
        $markup = $this->renderer->renderInIsolation($build);
        $text = trim(Html::decodeEntities(strip_tags((string) $markup)));
        if ($text !== '') {
          $messages[] = $text;
        }
      }
    }
    if ($messages === []) {
      return;
    }

    // Drupal.announce() writes HTML internally. Escape the decoded plain text
    // so entity-encoded markup cannot become executable content in its region.
    // Append after Views' replacement commands; do not move the user's focus.
    $response->addCommand(new AnnounceCommand(
      Html::escape(implode("\n", $messages)),
      AnnounceCommand::PRIORITY_POLITE,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Core serializes AJAX commands at priority -100; add our command first.
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

}
