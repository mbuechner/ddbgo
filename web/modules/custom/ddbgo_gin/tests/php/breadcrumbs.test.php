<?php

/**
 * @file
 * Read-only breadcrumb regression; run with drush php:script.
 *
 * Exercises the installed builders, module hooks and actual theme templates.
 * Nodes exist only in this process's entity memory cache; nothing is saved,
 * submitted or imported. Synthetic alias requests test route-based matching,
 * without creating aliases. This is not a browser or screenreader test.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\AliasPathMatcher;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$check(PHP_SAPI === 'cli', 'Run this regression through Drush.');
$check(Drupal::moduleHandler()->moduleExists('custom_breadcrumbs'), 'Custom Breadcrumbs must be enabled and its configuration imported.');
$container = Drupal::getContainer();
$stack = Drupal::requestStack();
$context = Drupal::service('router.request_context');
$original_context = clone $context;
$theme_manager = Drupal::theme();
$original_theme = $theme_manager->getActiveTheme();
$switcher = Drupal::service('account_switcher');
$memory = Drupal::service('entity.memory_cache');
$renderer = Drupal::service('renderer');
$services = [];
// Both builders capture a path matcher; contrib additionally captures the
// current request at construction. Recreate these per simulated request.
foreach ([PathMatcherInterface::class, 'custom_breadcrumbs.breadcrumb', 'system.breadcrumb.default', 'breadcrumb'] as $id) {
  $services[$id] = $container->get($id);
}
$fixtures = [];
$fixture_ids = [];
$lists = [
  'kwe' => ['/search/kwe', 'Liste der Kultur- und Wissenseinrichtungen'],
  'aggregator' => ['/search/aggregator/uebersicht', 'Aggregatorenübersicht'],
  'bestand' => ['/search/bestand', 'Bestandsliste'],
  'person' => ['/search/person', 'Personenliste'],
];
$normalize = static fn (string $text): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$nonlink = static fn ($url): bool => $url->isRouted() && in_array($url->getRouteName(), ['<nolink>', '<none>'], TRUE);
$switcher->switchTo(User::load(1));

try {
  foreach (array_merge(array_keys($lists), ['page']) as $offset => $bundle) {
    $id = 2000000000 + $offset;
    $check(!Drupal::database()->select('node', 'n')->condition('nid', $id)->countQuery()->execute()->fetchField(), 'Fixture ID is not a stored node.');
    $node = Node::create(['type' => $bundle, 'nid' => $id, 'title' => 'Breadcrumb fixture ' . $bundle, 'uid' => 1, 'status' => 1]);
    $fixtures[$bundle] = $node;
    $fixture_ids[] = $id;
    // Url::access() upcasts canonical links through entity storage. Keep that
    // genuine access path working without inserting a database fixture.
    $memory->set('values:node:' . $id, $node, Cache::PERMANENT, ['node_values']);
  }

  $run = static function (string $case, string $path, ?string $route, array $parameters, array $raw, array $expected_urls, ?array $expected_texts = NULL, string $theme = 'gin_frontend', bool $path_rule = FALSE) use ($check, $container, $stack, $context, $theme_manager, $renderer, $normalize, $nonlink): array {
    $theme_manager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme));
    $request = Request::create('http://localhost' . $path);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($request);
    try {
      $context->fromRequest($request);
      if ($route === NULL) {
        $request->attributes->add(Drupal::service('router.no_access_checks')->matchRequest($request));
      }
      else {
        $request->attributes->add($parameters);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_object', Drupal::service('router.route_provider')->getRouteByName($route));
        $request->attributes->set('_raw_variables', new ParameterBag($raw));
      }
      // path.matcher is an alias to this decorator. Setting the alias itself
      // would leave the decorator's cached frontpage result from the last case.
      $container->set(PathMatcherInterface::class, new AliasPathMatcher(
        new PathMatcher(Drupal::configFactory(), Drupal::routeMatch()),
        Drupal::routeMatch(), Drupal::service('path_alias.manager'), Drupal::configFactory(),
      ));
      foreach (['custom_breadcrumbs.breadcrumb', 'system.breadcrumb.default', 'breadcrumb'] as $id) {
        $container->set($id, NULL);
      }
      $match = RouteMatch::createFromRequest($request);
      if ($case === 'frontpage') {
        $check(Drupal::service('path.matcher')->isFrontPage(), 'frontpage: isolated request is recognized as the configured frontpage.');
      }
      $builder = $container->get('custom_breadcrumbs.breadcrumb');
      $check($builder->applies($match), "$case: an exported custom rule applies.");
      $before = $builder->build($match);
      $breadcrumb = $container->get('breadcrumb')->build($match);
      $links = $breadcrumb->getLinks();
      $urls = array_map(static fn ($link) => $nonlink($link->getUrl()) ? NULL : $link->getUrl()->toString(), $links);
      $texts = array_map(static fn ($link) => $normalize((string) $link->getText()), $links);
      $check($urls === $expected_urls, "$case: exact hierarchy, accessible parents and unlinked current page.");
      if ($expected_texts !== NULL) {
        $check($texts === $expected_texts, "$case: expected hierarchy and action labels.");
      }
      $check(!array_filter($texts, static fn ($text) => $text === ''), "$case: no empty labels.");
      $check(!array_diff($before->getCacheTags(), $breadcrumb->getCacheTags()), "$case: builder cache tags survive the alter hook.");
      $check(!array_diff($before->getCacheContexts(), $breadcrumb->getCacheContexts()), "$case: builder cache contexts survive the alter hook.");
      $check(Cache::mergeMaxAges($before->getCacheMaxAge(), $breadcrumb->getCacheMaxAge()) === $breadcrumb->getCacheMaxAge(), "$case: no cache lifetime is increased by the alter hook.");
      if ($path_rule) {
        $check($before->getCacheMaxAge() === 0 && $breadcrumb->getCacheMaxAge() === 0, "$case: contrib path-rule max-age zero is retained.");
      }
      $check(!array_diff(['route', 'theme', 'user.permissions'], $breadcrumb->getCacheContexts()), "$case: route, theme and permissions remain cache contexts.");
      $check(in_array('config:custom_breadcrumbs.settings', $breadcrumb->getCacheTags(), TRUE), "$case: global settings remain a cache dependency.");
      $check(!array_diff(Drupal::entityTypeManager()->getDefinition('custom_breadcrumbs')->getListCacheTags(), $breadcrumb->getCacheTags()), "$case: rule-list changes invalidate the result.");
      foreach ($before->getLinks() as $link) {
        $access = $link->getUrl()->access(NULL, TRUE);
        $check(!array_diff($access->getCacheContexts(), $breadcrumb->getCacheContexts()) && !array_diff($access->getCacheTags(), $breadcrumb->getCacheTags()), "$case: allowed and denied parent access metadata remains.");
      }
      if (isset($parameters['node']) && $links) {
        $check(!array_diff($parameters['node']->getCacheTags(), $breadcrumb->getCacheTags()), "$case: node label remains a cache dependency.");
      }

      $build = $breadcrumb->toRenderable();
      $html = (string) $renderer->renderInIsolation($build);
      $dom = new DOMDocument();
      @$dom->loadHTML('<?xml encoding="UTF-8"><main>' . $html . '</main>');
      $xpath = new DOMXPath($dom);
      $items = $xpath->query('//nav[@aria-labelledby="system-breadcrumb"]//ol/li');
      if ($theme === 'gin_frontend') {
        $check($items->length === count($links), "$case: Twig retains the complete builder trail.");
        foreach ($items as $index => $item) {
          $check($normalize($item->textContent) === $texts[$index], "$case: Twig retains breadcrumb text and order.");
          $anchors = $xpath->query('./a[@href]', $item);
          $check($anchors->length === ($urls[$index] === NULL ? 0 : 1), "$case: current item remains unlinked, ancestors remain links.");
        }
        $check($xpath->query('//nav[@aria-labelledby="system-breadcrumb"]')->length === ($links ? 1 : 0), "$case: one labelled breadcrumb landmark, none on frontpage.");
      }
      $registry = Drupal::service('theme.registry')->get();
      $preprocessors = $registry['breadcrumb']['preprocess functions'] ?? [];
      $check(in_array('gin_preprocess_breadcrumb', $preprocessors, TRUE) === ($theme === 'gin'), "$case: Gin rewrite is removed only from frontend registry.");
      if ($theme === 'gin_frontend') {
        $check(!in_array('gin_frontend_preprocess_breadcrumb', $preprocessors, TRUE), "$case: redundant frontend repair is removed too.");
      }
      return ['texts' => $texts, 'urls' => $urls, 'items' => $items->length];
    }
    finally {
      $stack->pop();
    }
  };

  foreach ($lists as $bundle => [$list_path, $list_title]) {
    $node = $fixtures[$bundle];
    $node_path = '/node/' . $node->id();
    $base = ['Startseite', $list_title, $node->label()];
    $run("$bundle/view", $node_path, 'entity.node.canonical', ['node' => $node], ['node' => $node->id()], ['/', $list_path, NULL], $base);
    foreach (['edit' => 'Edit', 'delete' => 'Delete'] as $operation => $label) {
      $run("$bundle/$operation", "$node_path/$operation", "entity.node.{$operation}_form", ['node' => $node], ['node' => $node->id()], ['/', $list_path, $node_path, NULL], [...$base, (string) t($label)]);
    }
    $add = $run("$bundle/add", '/node/add/' . $bundle, 'node.add', ['node_type' => NodeType::load($bundle)], ['node_type' => $bundle], ['/', $list_path, NULL], NULL, 'gin_frontend', TRUE);
    $check($add['texts'][0] === 'Startseite' && $add['texts'][1] === $list_title && str_contains($add['texts'][2], NodeType::load($bundle)->label()), "$bundle/add: creation title names the requested content type.");
  }

  foreach (['/search', '/search/kwe', '/search/aggregator', '/search/person', '/search/bestand', '/bookmarks', '/sitemap'] as $path) {
    $run($path, $path, NULL, [], [], ['/', NULL], NULL, 'gin_frontend', TRUE);
  }
  // Alternate lists are peers, regardless of their deeper URL paths.
  foreach (['/search/aggregator/uebersicht', '/search/bestand/europeana', '/search/bestand/cdv'] as $path) {
    $run($path, $path, NULL, [], [], ['/', NULL], NULL, 'gin_frontend', TRUE);
  }

  $page = $fixtures['page'];
  $run('static page', '/node/' . $page->id(), 'entity.node.canonical', ['node' => $page], ['node' => $page->id()], ['/', NULL], ['Startseite', $page->label()]);
  $node = $fixtures['kwe'];
  $run('alias request', '/a11y-fixture-alias', 'entity.node.canonical', ['node' => $node], ['node' => $node->id()], ['/', '/search/kwe', NULL], ['Startseite', $lists['kwe'][1], $node->label()]);

  $front_path = Drupal::config('system.site')->get('page.front');
  $check((bool) preg_match('#^/node/(\d+)$#', $front_path, $front_match), 'The configured frontpage is a node route.');
  // The route parameter is synthetic even for the configured frontpage; no
  // existing content title is loaded or printed by this regression.
  $front_node = Node::create(['type' => 'page', 'nid' => $front_match[1], 'title' => 'Synthetic frontpage']);
  $run('frontpage', $front_path, 'entity.node.canonical', ['node' => $front_node], ['node' => $front_node->id()], [], []);

  // Authenticated users can view person records, but cannot open the person
  // search view. The latter must disappear without losing access cache data.
  $switcher->switchTo(new UserSession(['uid' => 2000000100, 'roles' => ['authenticated']]));
  try {
    $node = $fixtures['person'];
    $run('restricted parent', '/node/' . $node->id(), 'entity.node.canonical', ['node' => $node], ['node' => $node->id()], ['/', NULL], ['Startseite', $node->label()]);
  }
  finally {
    $switcher->switchBack();
  }

  $node = $fixtures['kwe'];
  $admin = $run('admin Gin unchanged', '/node/' . $node->id() . '/edit', 'entity.node.edit_form', ['node' => $node], ['node' => $node->id()], ['/', '/search/kwe', '/node/' . $node->id(), NULL], NULL, 'gin');
  $check($admin['items'] === 2, 'Admin Gin retains its existing Back to site plus operation presentation.');
  foreach ($fixtures as $node) {
    $check($node->isNew(), 'All synthetic nodes remain unsaved.');
  }
}
finally {
  foreach ($fixture_ids as $id) {
    $memory->delete('values:node:' . $id);
  }
  foreach ($services as $id => $service) {
    $container->set($id, $service);
  }
  foreach (['BaseUrl', 'PathInfo', 'Method', 'Host', 'Scheme', 'HttpPort', 'HttpsPort', 'QueryString', 'Parameters', 'CompleteBaseUrl'] as $property) {
    $getter = 'get' . $property;
    $setter = 'set' . $property;
    $context->$setter($original_context->$getter());
  }
  $theme_manager->setActiveTheme($original_theme);
  $switcher->switchBack();
}

$check(!Drupal::database()->select('node', 'n')->condition('nid', $fixture_ids, 'IN')->countQuery()->execute()->fetchField(), 'No fixture node exists in the database after the checks.');
echo "PASS: $checks breadcrumb checks; no entities, aliases or configuration saved.\n";
