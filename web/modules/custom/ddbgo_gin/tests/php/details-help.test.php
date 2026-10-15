<?php

/**
 * @file
 * Read-only file-details regression; run through drush php:script.
 *
 * Builds unsaved node.add forms in Gin/Gin Frontend. No form is submitted,
 * validated or saved; no files are uploaded and no configuration is changed.
 * Checks rendered structure, not browser visibility or screenreader behavior.
 */

use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Template\Attribute;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
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
$check(PHP_SAPI === 'cli', 'Run this rendering check through Drush.');
$class = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")';
$parse = static function (string $html): DOMXPath {
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  return new DOMXPath($dom);
};
$focusable = './/button | .//input | .//select | .//textarea | .//a[@href] | .//*[@tabindex] | .//*[@contenteditable="true"]';
$fields = ['bestand' => 'field_fragebogen', 'kwe' => 'field_vertrag', 'aggregator' => 'field_vertrag'];
$renderer = Drupal::service('renderer');
$manager = Drupal::theme();
$original_theme = $manager->getActiveTheme();
$switcher = Drupal::service('account_switcher');
$stack = Drupal::requestStack();
$switcher->switchTo(new UserSession(['uid' => 1]));

try {
  foreach (['gin_frontend', 'gin'] as $theme_name) {
    $manager->setActiveTheme(Drupal::service('theme.initialization')->getActiveThemeByName($theme_name));
    foreach ($fields as $bundle => $field) {
      $request = Request::create('http://localhost/node/add/' . $bundle);
      $request->setSession(new Session(new MockArraySessionStorage()));
      $request->attributes->set('_route', 'node.add');
      $request->attributes->set('_route_object', Drupal::service('router.route_provider')->getRouteByName('node.add'));
      $request->attributes->set('node_type', NodeType::load($bundle));
      $request->attributes->set('_raw_variables', new ParameterBag(['node_type' => $bundle]));
      $stack->push($request);
      try {
        foreach ([TRUE, FALSE] as $open) {
          $case = "$theme_name/$bundle/$field/" . ($open ? 'open' : 'closed');
          $node = Node::create(['type' => $bundle, 'title' => 'Unsaved file-details fixture']);
          $description = $node->getFieldDefinition($field)->getDescription();
          $label = $node->getFieldDefinition($field)->getLabel();
          $form = Drupal::service('entity.form_builder')->getForm($node, 'default');
          $check(($form[$field]['widget']['#theme'] ?? NULL) === 'file_widget_multiple', "$case: real multiple-file widget exercises the scoped template branch.");
          $form[$field]['widget']['#open'] = $open;
          // Do not let any already prepared attribute mask the closed case.
          unset($form[$field]['widget']['#attributes']['open']);
          $xpath = $parse((string) $renderer->renderInIsolation($form));
          $outer = $xpath->query('//*[' . $class('field--name-' . str_replace('_', '-', $field)) . ']')->item(0);
          $check($outer instanceof DOMElement, "$case: original field wrapper remains.");
          $details = $xpath->query('.//details', $outer)->item(0);
          $check($details instanceof DOMElement && $details->hasAttribute('open') === $open, "$case: native open/closed state is preserved.");
          $summary = $xpath->query('./summary', $details)->item(0);
          $check($summary instanceof DOMElement && str_contains($summary->textContent, $label), "$case: summary keeps its original field label.");
          $check(!$summary->hasAttribute('role') && !$summary->hasAttribute('aria-expanded'), "$case: native summary exposes role/state without forbidden explicit attributes.");
          $check($summary->getAttribute('aria-controls') === $details->getAttribute('id'), "$case: summary controls relationship remains.");
          $check($xpath->query($focusable, $summary)->length === 0, "$case: summary has no nested focusable controls.");
          $descriptions = $xpath->query('./div[' . $class('claro-details__wrapper') . ']/div[' . $class('claro-details__description') . ']', $details);
          $check($descriptions->length === 1, "$case: description is directly inside the details content wrapper.");
          $text = $descriptions->item(0);
          $check(trim($text->textContent) === $description, "$case: complete original field description remains.");
          $check(!$text->hasAttribute('hidden') && !str_contains(' ' . $text->getAttribute('class') . ' ', ' visually-hidden '), "$case: field description is ordinary inline content, not hidden help.");
          $check($xpath->query('.//button | .//*[@role="tooltip"]', $text)->length === 0, "$case: inline description needs no help control or tooltip.");

          $uploads = $xpath->query('.//input[@type="file"]', $details);
          $check($uploads->length === 1, "$case: original upload control remains.");
          $upload = $uploads->item(0);
          $id = $upload->getAttribute('id');
          $check($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length === 1, "$case: upload keeps its individual label.");
          $help_ids = preg_split('/\s+/', trim($upload->getAttribute('aria-describedby')), -1, PREG_SPLIT_NO_EMPTY);
          $check(count($help_ids) > 0, "$case: upload retains its describedby IDs.");
          $help_text = '';
          foreach ($help_ids as $help_id) {
            $targets = $xpath->query('//*[@id="' . $help_id . '"]');
            $check($targets->length === 1, "$case: upload description ID resolves exactly once.");
            $help_text .= $targets->item(0)->textContent;
          }
          $check(str_contains(strtolower($help_text), 'pdf'), "$case: upload format guidance remains linked to the input.");
          $check($node->isNew() && $node->id() === NULL, "$case: fixture remains unsaved.");
        }
      }
      finally {
        $stack->pop();
      }
    }

    // Explicit Twig cases cover display modes absent from the live field
    // configuration, without changing that configuration or copying logic.
    foreach ([
      'generic-help' => ['details', 'Group label', 'after'],
      'file-invisible' => ['file_widget_multiple', 'File label', 'invisible'],
      'file-no-title' => ['file_widget_multiple', '', 'after'],
    ] as $case => [$widget_theme, $title, $original_display]) {
      $variables = [
        'element' => ['#theme' => $widget_theme],
        'attributes' => new Attribute(['id' => 'fixture-' . $case]),
        'summary_attributes' => new Attribute(['role' => 'button']),
        'content_attributes' => new Attribute(),
        'title' => $title,
        'description' => Markup::create('<p>Complete description. <strong>Keep formatting.</strong></p>'),
        'description_toggle' => TRUE,
        'description_display' => 'invisible',
        'description_display_toggle' => $original_display,
      ];
      $html = $renderer->executeInRenderContext(new RenderContext(), static fn () => Drupal::service('twig')->render('@ddbgo_gin/details--ddbgo-gin.html.twig', $variables));
      $xpath = $parse($html);
      $check($xpath->query('//strong[text()="Keep formatting."]')->length === 1, "$theme_name/$case: rich description is preserved exactly once.");
      if ($case === 'generic-help') {
        $check($xpath->query('//summary/button[' . $class('ddbgo-help-toggle') . ']')->length === 1, "$theme_name/$case: non-file details keep their existing help behavior.");
        $check($xpath->query('//*[@role="tooltip"]')->length === 1, "$theme_name/$case: non-file tooltip remains.");
      }
      else {
        $check($xpath->query('//button | //*[@role="tooltip"]')->length === 0, "$theme_name/$case: file description has no tooltip controls.");
        $description = $xpath->query('//div[' . $class('claro-details__description') . ']')->item(0);
        $check($description instanceof DOMElement, "$theme_name/$case: description remains even without a summary title.");
        $hidden = str_contains(' ' . $description->getAttribute('class') . ' ', ' visually-hidden ');
        $check($hidden === ($original_display === 'invisible'), "$theme_name/$case: original invisible display is honored; Gin's temporary hiding is removed otherwise.");
        if ($title === '') {
          $check($xpath->query('//summary')->length === 0, "$theme_name/$case: no empty summary is introduced.");
        }
      }
    }
  }
}
finally {
  $manager->setActiveTheme($original_theme);
  $switcher->switchBack();
}

echo "PASS: $checks file-details checks in Gin Frontend/Gin. No forms submitted or entities saved.\n";
