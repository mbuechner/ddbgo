<?php

/**
 * @file
 * Read-only formatter/filter checks. Run with drush php:script; no nodes saved.
 */

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$ids = array_values(Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', 'bestandstags')->condition('status', 1)->range(0, 2)->execute());
$check(count($ids) === 2, 'Two published Bestandstags are required for this integration check.');
$node = Node::create(['type' => 'bestand', 'field_bestandstags' => $ids]);
$switcher = Drupal::service('account_switcher');
// Synthetic accounts only affect this process; no user is loaded or signed in.
foreach ([0, 999999999] as $uid) {
  $switcher->switchTo(new UserSession(['uid' => $uid, 'roles' => $uid ? ['authenticated'] : ['anonymous']]));
  try {
    $build = $node->get('field_bestandstags')->view(['type' => 'ddbgo_bestand_tags', 'label' => 'inline']);
    $check($build['#label_display'] === 'inline' && (string) $build['#title'] === 'Bestandstags', 'Native inline label provides the colon.');
    foreach ($ids as $delta => $tid) {
      $item = $build[$delta];
      $check(in_array('taxonomy_term:' . $tid, $item['#cache']['tags'] ?? [], TRUE), 'Term label cache tag retained.');
      if (!$uid) {
        $check(!isset($item['#url']) && isset($item['#plain_text']), 'No search link without search access.');
        continue;
      }
      $check($item['#type'] === 'link', 'Authenticated visitors get native links.');
      $url = $item['#url'];
      $query = $url->getOption('query');
      $check($url->getRouteName() === 'view.suche_bestand.page' && $query === ['query' => '', 'field_bestandstags' => [(string) $tid]], 'Each tag links to its exact ID and clears text input.');
      $request = Request::create($url->toString());
      $session = new Session(new MockArraySessionStorage());
      $session->set('views', ['suche_bestand' => ['default' => ['query' => 'Old search', 'field_bestandstags' => ['999']]]]);
      $request->setSession($session);
      $view = Views::getView('suche_bestand');
      $view->setDisplay('page');
      $view->setRequest($request);
      $check($view->getExposedInput() === $query, 'Remembered filters do not narrow the tag link search.');
      $view->destroy();
    }
    $html = (string) Drupal::service('renderer')->renderInIsolation($build);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $check($dom->getElementsByTagName('a')->length === ($uid ? 2 : 0), 'Both tags render as separate links only with access.');
  }
  finally {
    $switcher->switchBack();
  }
}
echo "PASS: Tag labels, URLs, remembered filters, access checks and term cache tags. No content changed.\n";
