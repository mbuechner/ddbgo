<?php

/**
 * Isolated regressions; run with php, without bootstrapping or writing Drupal.
 */

declare(strict_types=1);

namespace Drupal\Core\Entity {
  interface EntityTypeManagerInterface {}
}

namespace Drupal\Core\TypedData {
  interface ComplexDataInterface {}
}

namespace Drupal\search_api\Utility {
  interface FieldsHelperInterface {}
}

namespace Drupal\search_api\Item {
  interface ItemInterface {}
}

namespace Drupal\search_api {
  class SearchApiException extends \RuntimeException {}
}

namespace Drupal\node\Entity {
  class Node {}
}

namespace Drupal\paragraphs\Entity {
  class Paragraph {}
}

namespace {
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\TypedData\ComplexDataInterface;
  use Drupal\ddbgo_search\PersonRelations;
  use Drupal\node\Entity\Node;
  use Drupal\paragraphs\Entity\Paragraph;
  use Drupal\search_api\Item\ItemInterface;
  use Drupal\search_api\Utility\FieldsHelperInterface;

  require __DIR__ . '/../../src/PersonRelations.php';

  final class References implements \IteratorAggregate {
    public function __construct(public array $references) {}
    public function getIterator(): \Traversable { return new \ArrayIterator($this->references); }
    public function __get(string $property): mixed { return $this->references[0]->$property ?? NULL; }
    public function getValue(): never { throw new \RuntimeException('Entity fields must use scalar references, not getValue().'); }
    public function referencedEntities(): never { throw new \RuntimeException('Entity fields must not resolve references individually.'); }
  }

  final class FakeNode extends Node {
    public array $translations = [];
    public function __construct(public int $nid, public string $type, public string $title = '', public array $fields = [], public bool $published = TRUE) {}
    public function id(): int { return $this->nid; }
    public function bundle(): string { return $this->type; }
    public function getEntityTypeId(): string { return 'node'; }
    public function isPublished(): bool { return $this->published; }
    public function hasField(string $name): bool { return array_key_exists($name, $this->fields); }
    public function get(string $name): References { return new References($this->fields[$name] ?? []); }
    public function getTranslationLanguages(): array { return ['de' => TRUE] + array_fill_keys(array_keys($this->translations), TRUE); }
    public function getTranslation(string $language): self { return $this->translations[$language] ?? $this; }
    public function getUntranslated(): self { return $this; }
    public function label(): string { return $this->title; }
    public function getTypedData(): FakeObject { return new FakeObject($this); }
  }

  final class FakeParagraph extends Paragraph {
    public array $translations = [];
    public function __construct(public int $pid, public int $person, public ?int $role) {}
    public function id(): int { return $this->pid; }
    public function get(string $name): References {
      return new References([(object) ['target_id' => $name === 'field_person' ? $this->person : $this->role]]);
    }
    public function hasField(string $name): bool { return in_array($name, ['field_person', 'field_rolle'], TRUE); }
    public function getTranslationLanguages(): array { return ['de' => TRUE] + array_fill_keys(array_keys($this->translations), TRUE); }
    public function getTranslation(string $language): self { return $this->translations[$language] ?? $this; }
  }

  final class FakeRole {
    public function __construct(public int $tid, public string $name) {}
    public function id(): int { return $this->tid; }
    public function label(): string { return $this->name; }
  }

  final class FakeObject implements ComplexDataInterface {
    public function __construct(private mixed $entity) {}
    public function getValue(): mixed { return $this->entity; }
  }

  final class FakeField {
    public array $values = [];
    public function __construct(private string $property, private string $fieldId = '') {}
    public function getPropertyPath(): string { return $this->property; }
    public function getDatasourceId(): ?string { return NULL; }
    public function getFieldIdentifier(): string { return $this->fieldId ?: $this->property; }
    public function getValues(): array { return $this->values; }
    public function addValue(mixed $value): void { $this->values[] = $value; }
  }

  final class FakeItem implements ItemInterface {
    public function __construct(private mixed $entity, private array $fields) {}
    public function getOriginalObject(bool $load = TRUE): FakeObject {
      if ($this->entity === NULL) { throw new \Drupal\search_api\SearchApiException('Original object is missing.'); }
      return new FakeObject($this->entity);
    }
    public function getFields(bool $extract = TRUE): array {
      if ($extract) { throw new \RuntimeException('Batch preparation must not start recursive field extraction.'); }
      return $this->fields;
    }
    public function getDatasourceId(): string { return 'entity:node'; }
    public function getId(): string { return 'entity:node/' . ($this->entity?->id() ?? 'missing') . ':de'; }
  }

  final class FakeFieldsHelper implements FieldsHelperInterface {
    public function filterForPropertyPath(array $fields, mixed $datasource, string $property): array {
      return array_filter($fields, static fn ($field) => $field->getDatasourceId() === $datasource && $field->getPropertyPath() === $property);
    }
  }

  final class FakeStorage {
    public array $entities = [];
    public array $loads = [];
    public array $queries = [];
    public array $revisions = [];
    public array $missingOnLoad = [];
    public array $tieOrders = [];
    public array $titleGroups = [];
    public array $aggregateResults = [];
    public bool $failNextQuery = FALSE;
    public function __construct(public string $type, private FakeManager $manager) {}
    public function getQuery(): FakeTieQuery {
      if (!$this->tieOrders) { throw new \RuntimeException('Individual queries are allowed only for explicitly configured title ties.'); }
      return new FakeTieQuery($this);
    }
    public function getAggregateQuery(): FakeQuery { return new FakeQuery($this, $this->manager); }
    public function loadMultiple(?array $ids = NULL): array {
      if ($ids === NULL) { throw new \RuntimeException('Only explicitly collected IDs may be loaded.'); }
      $this->loads[] = array_values($ids);
      $result = [];
      foreach ($ids as $id) {
        if (isset($this->entities[$id]) && !in_array($id, $this->missingOnLoad, TRUE)) { $result[$id] = $this->entities[$id]; }
      }
      return $result;
    }
    public function load(mixed $id): never { throw new \RuntimeException('No individual entity loads.'); }
    public function loadRevision(mixed $id): never { throw new \RuntimeException('Relation output must retain default-paragraph loading.'); }
    public function loadMultipleRevisions(array $ids): never { throw new \RuntimeException('Relation output must retain default-paragraph loading.'); }
  }

  final class FakeQuery {
    private array $conditions = [];
    private array $groups = [];
    private array $aggregates = [];
    private array $sorts = [];
    private ?bool $access = NULL;
    public function __construct(private FakeStorage $storage, private FakeManager $manager) {}
    public function accessCheck(bool $access): self { $this->access = $access; return $this; }
    public function condition(string $field, mixed $value, string $operator = '='): self { $this->conditions[$field] = [$value, $operator]; return $this; }
    public function groupBy(string $field): self { $this->groups[] = $field; return $this; }
    public function aggregate(string $field, string $function, ?string $langcode = NULL, ?string $alias = NULL): self { $this->aggregates[$field][] = $function; return $this; }
    public function sortAggregate(string $field, string $function, string $direction = 'ASC', ?string $langcode = NULL): self { $this->sorts[$field] = [$function, $direction]; return $this; }
    public function execute(): array {
      if ($this->storage->failNextQuery) {
        $this->storage->failNextQuery = FALSE;
        throw new \RuntimeException('query exception');
      }
      $this->storage->queries[] = ['conditions' => $this->conditions, 'sorts' => $this->sorts, 'groups' => $this->groups, 'aggregates' => $this->aggregates, 'access' => $this->access];
      $nodes = array_filter($this->storage->entities, function ($node): bool {
        foreach ($this->conditions as $field => [$value, $operator]) {
          if ($field === 'type' && !in_array($node->bundle(), (array) $value, TRUE)) { return FALSE; }
          if ($field === 'status' && $node->isPublished() !== (bool) $value) { return FALSE; }
          if ($field === 'field_personen.entity:paragraph.field_person.target_id') {
            $linked = [];
            foreach ($node->getTranslationLanguages() as $language => $_) {
              foreach ($node->getTranslation($language)->get('field_personen') as $reference) {
                $paragraph = $this->manager->storages['paragraph']->entities[$reference->target_id ?? 0] ?? NULL;
                if ($paragraph instanceof FakeParagraph) {
                  foreach ($paragraph->getTranslationLanguages() as $paragraphLanguage => $_) {
                    $linked[] = $paragraph->getTranslation($paragraphLanguage)->person;
                  }
                }
              }
            }
            if (!array_intersect((array) $value, $linked)) { return FALSE; }
          }
        }
        return TRUE;
      });
      $rows = [];
      $sortTitles = [];
      $requestedPeople = $this->conditions['field_personen.entity:paragraph.field_person.target_id'][0];
      foreach ($nodes as $nid => $node) {
        $people = [];
        foreach ($node->getTranslationLanguages() as $language => $_) {
          foreach ($node->getTranslation($language)->get('field_personen') as $reference) {
            $paragraph = $this->manager->storages['paragraph']->entities[$reference->target_id ?? 0] ?? NULL;
            if (!$paragraph instanceof FakeParagraph) { continue; }
            foreach ($paragraph->getTranslationLanguages() as $paragraphLanguage => $_) {
              $person = $paragraph->getTranslation($paragraphLanguage)->person;
              if (in_array($person, $requestedPeople, FALSE)) { $people[$person] = $person; }
            }
          }
        }
        // Fixture tokens declare database title equality. The fake deliberately
        // does not reproduce SQL collation with a PHP case/accent normalizer.
        foreach ($node->getTranslationLanguages() as $language => $_) {
          $title = $node->getTranslation($language)->title;
          $titleGroup = $this->storage->titleGroups[$title] ?? $title;
          foreach ($people as $person) {
            $key = json_encode([$titleGroup, $person]);
            if (isset($rows[$key])) {
              $rows[$key]['nid_min'] = min($rows[$key]['nid_min'], $nid);
              $rows[$key]['nid_max'] = max($rows[$key]['nid_max'], $nid);
            }
            else {
              $rows[$key] = ['title' => $title, 'field_person_target_id' => $person, 'nid_min' => $nid, 'nid_max' => $nid, 'title_min' => $title];
              $sortTitles[$key] = $titleGroup;
            }
          }
        }
      }
      if (isset($this->sorts['title'])) {
        uksort($rows, static fn ($a, $b) => strcmp($sortTitles[$a], $sortTitles[$b]));
      }
      $result = array_values($rows);
      $this->storage->aggregateResults[] = $result;
      return $result;
    }
  }

  final class FakeTieQuery {
    private array $conditions = [];
    private array $sorts = [];
    private ?bool $access = NULL;
    public function __construct(private FakeStorage $storage) {}
    public function accessCheck(bool $access): self { $this->access = $access; return $this; }
    public function condition(string $field, mixed $value, string $operator = '='): self { $this->conditions[$field] = [$value, $operator]; return $this; }
    public function sort(string $field, string $direction = 'ASC'): self { $this->sorts[$field] = $direction; return $this; }
    public function execute(): array {
      $bundle = $this->conditions['type'][0] ?? '';
      $person = $this->conditions['field_personen.entity:paragraph.field_person.target_id'][0] ?? NULL;
      if (!is_scalar($person) || !isset($this->storage->tieOrders[$bundle][$person])) {
        throw new \RuntimeException('Only the person and bundle with a known title tie may use the ordering fallback.');
      }
      if ($this->storage->loads) { throw new \RuntimeException('A title-tie query must obtain all host IDs before loading the batch.'); }
      $this->storage->queries[] = ['conditions' => $this->conditions, 'sorts' => $this->sorts, 'groups' => [], 'aggregates' => [], 'access' => $this->access];
      $ids = $this->storage->tieOrders[$bundle][$person];
      return array_combine($ids, $ids);
    }
  }

  final class FakeManager implements EntityTypeManagerInterface {
    public array $storages = [];
    public function __construct() { foreach (['node', 'paragraph', 'taxonomy_term'] as $type) { $this->storages[$type] = new FakeStorage($type, $this); } }
    public function getStorage(string $type): FakeStorage { return $this->storages[$type]; }
    public function resetLogs(): void { foreach ($this->storages as $storage) { $storage->loads = []; $storage->queries = []; $storage->aggregateResults = []; } }
  }

  $checks = 0;
  $check = static function (mixed $actual, mixed $expected, string $message) use (&$checks): void {
    if ($actual !== $expected) { throw new \RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); }
    $checks++;
  };
  $ref = static fn (int|null $id, int|null $revision = NULL): object => (object) ['target_id' => $id, 'target_revision_id' => $revision];
  $fields = static function (array $bundles): array {
    $result = [];
    foreach ($bundles as $bundle) { $result['ddbgo_person_' . $bundle] = new FakeField('ddbgo_person_' . $bundle); }
    return $result;
  };
  $manager = new FakeManager();
  $nodes = $manager->storages['node'];
  $paragraphs = $manager->storages['paragraph'];
  $roles = $manager->storages['taxonomy_term'];
  $persons = [];
  foreach ([10, 11, 12, 13] as $id) { $persons[$id] = new FakeNode($id, 'person'); }
  $nodes->entities[20] = new FakeNode(20, 'kwe', 'Zulu', ['field_personen' => [$ref(100), $ref(101), $ref(102), $ref(103), $ref(104), $ref(105), $ref(9999), $ref(NULL)]]);
  $nodes->entities[21] = new FakeNode(21, 'kwe', 'Alpha', ['field_personen' => [$ref(106)]]);
  $nodes->entities[22] = new FakeNode(22, 'kwe', 'Unpublished', ['field_personen' => [$ref(107)]], FALSE);
  $nodes->entities[23] = new FakeNode(23, 'kwe', 'Unloaded paragraph', ['field_personen' => [$ref(114)]]);
  $nodes->entities[30] = new FakeNode(30, 'bestand', 'Books', ['field_personen' => [$ref(108, 9008)]]);
  $nodes->entities[31] = new FakeNode(31, 'bestand', 'Translation', ['field_personen' => [$ref(109)]]);
  $nodes->entities[31]->translations['en'] = new FakeNode(31, 'bestand', 'Translation', ['field_personen' => [$ref(110)]]);
  $nodes->entities[32] = new FakeNode(32, 'bestand', 'Paragraph translation', ['field_personen' => [$ref(112)]]);
  $nodes->entities[40] = new FakeNode(40, 'aggregator', 'Agency', ['field_personen' => [$ref(111)]]);
  $nodes->entities[41] = new FakeNode(41, 'aggregator', 'Deleted after query', ['field_personen' => [$ref(113)]]);
  $nodes->missingOnLoad = [41];
  foreach ([[100, 10, 500], [101, 11, 501], [102, 10, 500], [103, 10, 502], [104, 10, 999], [105, 10, 0], [106, 10, 501], [107, 10, 500], [108, 11, 500], [109, 10, 500], [110, 12, 501], [111, 11, 500], [112, 11, 500], [113, 11, 501], [114, 12, 501]] as [$id, $person, $role]) {
    $paragraphs->entities[$id] = new FakeParagraph($id, $person, $role);
  }
  $paragraphs->revisions[9008] = new FakeParagraph(108, 10, 501);
  $paragraphs->missingOnLoad = [114];
  $paragraphs->entities[112]->translations['en'] = new FakeParagraph(112, 12, 502);
  foreach ([[500, 'Admin'], [501, 'Editor'], [502, 'Admin']] as [$id, $name]) { $roles->entities[$id] = new FakeRole($id, $name); }
  $bundles = ['kwe', 'bestand', 'aggregator'];
  $items = array_map(static fn ($person) => new FakeItem($person, $fields($bundles)), $persons);
  $resolver = new PersonRelations($manager, new FakeFieldsHelper());
  $check($resolver->getPrepared(10, 'kwe'), NULL, 'No prepared data outside a scope');

  $returned = $resolver->withItems([...array_values($items), $items[10]], [...$bundles, 'kwe', 'other'], function () use ($resolver, $check, $nodes, $paragraphs, $roles): string {
    $first = $resolver->getPrepared(10, 'kwe');
    $check(array_keys($first['nodes']), [21, 20], 'Shared hosts retain title ordering');
    $check($first['node_role_ids'][20], [500, 500, 502, 999], 'Duplicate role references and dangling role IDs retain processor semantics');
    $check($first['node_role_ids'][21], [501], 'Roles are mapped to their host');
    $check(isset($first['roles'][500], $first['roles'][501], $first['roles'][502]), TRUE, 'Existing roles are available');
    $check(isset($first['roles'][999]), FALSE, 'Dangling role entities are omitted');
    $second = $resolver->getPrepared('11', 'kwe');
    $check(array_keys($second['nodes']), [20], 'A shared host is present for its other person');
    $check($second['node_role_ids'][20], [501], 'Shared hosts do not mix different persons\' roles');
    $check(array_keys($resolver->getPrepared(12, 'kwe')['nodes']), [23], 'A SQL membership remains present when its paragraph cannot be loaded');
    $check($resolver->getPrepared(12, 'kwe')['node_role_ids'][23], [], 'An unloadable paragraph contributes no roles');
    $check(array_keys($resolver->getPrepared(11, 'bestand')['nodes']), [30, 32], 'Default paragraph values determine old-revision references');
    $check($resolver->getPrepared(11, 'bestand')['node_role_ids'][30], [500], 'Role lookup loads the default paragraph, not the referenced old revision');
    $check(array_keys($resolver->getPrepared(10, 'bestand')['nodes']), [31], 'An old reference revision does not add the old person');
    $translated = $resolver->getPrepared(12, 'bestand');
    $check(array_keys($translated['nodes']), [32, 31], 'Host-translation and paragraph-translation-only relations retain their hosts in title order');
    $check($translated['node_role_ids'][31] ?? [], [], 'Translation-only membership does not borrow roles from translated paragraphs');
    $check($translated['node_role_ids'][32] ?? [], [], 'Paragraph-translation-only membership does not borrow the translated role');
    $check(array_keys($resolver->getPrepared(10, 'bestand')['node_role_ids']), [31], 'Default-translation roles remain available');
    $check($resolver->getPrepared(10, 'bestand')['node_role_ids'][31], [500], 'Roles use the original host translation');
    $check(array_keys($resolver->getPrepared(11, 'aggregator')['nodes']), [40], 'A host missing after the query is omitted');
    foreach (['kwe', 'bestand', 'aggregator'] as $bundle) {
      $empty = $resolver->getPrepared(13, $bundle);
      $check(is_array($empty), TRUE, 'Empty relations are prepared instead of treated as a cache miss');
      $check($empty['nodes'], [], 'Empty relations have no hosts');
      $check($empty['node_role_ids'], [], 'Empty relations have no host roles');
    }
    $check($resolver->getPrepared(999, 'kwe'), NULL, 'Persons outside the scope are not prepared');
    $check($resolver->getPrepared(10, 'other'), NULL, 'Bundles outside the scope are not prepared');
    $check(count($nodes->queries), 3, 'One relation query per requested bundle, not per person');
    $check(array_column(array_column($nodes->queries, 'conditions'), 'type'), [['kwe', '='], ['bestand', '='], ['aggregator', '=']], 'Queries remain separate for each supported bundle');
    foreach ($nodes->queries as $query) {
      $check($query['access'], FALSE, 'Existing access-check behavior is preserved');
      $check($query['conditions']['status'], [1, '='], 'Queries restrict published hosts');
      $check($query['sorts'], ['title' => ['MIN', 'ASC']], 'Queries retain title sorting through the native aggregate');
      $check($query['groups'], ['title', 'field_personen.entity:paragraph.field_person.target_id'], 'Queries group by SQL title equality and person membership');
      $check($query['aggregates'], ['nid' => ['MIN', 'MAX'], 'title' => ['MIN']], 'Queries detect distinct host IDs within SQL-equivalent title groups');
      $condition = $query['conditions']['field_personen.entity:paragraph.field_person.target_id'];
      $ids = array_map('intval', $condition[0]);
      sort($ids);
      $check([$ids, $condition[1]], [[10, 11, 12, 13], 'IN'], 'Queries include all scoped person IDs at once');
    }
    $check(count($nodes->loads), 1, 'Hosts for all people and bundles load once');
    $check(count($paragraphs->loads), 1, 'Paragraphs for all people and bundles load once');
    $check(count($roles->loads), 1, 'Roles for all people and bundles load once');
    $loadedRoles = array_map('intval', $roles->loads[0]);
    sort($loadedRoles);
    $check($loadedRoles, [500, 501, 502, 999], 'Roles are bulk loaded once with unique positive IDs');
    $check(in_array(9999, $paragraphs->loads[0], FALSE), TRUE, 'Dangling paragraph references are tolerated during bulk loading');
    return 'callback result';
  });
  $check($returned, 'callback result', 'The scope returns the callback value');
  $check($resolver->getPrepared(10, 'kwe'), NULL, 'Prepared relations are cleared after the callback');

  $resolver->withItems([$items[10]], ['kwe'], function () use ($resolver, $items, $check, $nodes): void {
    $outer = $resolver->getPrepared(10, 'kwe');
    $resolver->withItems([$items[11]], ['bestand'], function () use ($resolver, $check): void {
      $check($resolver->getPrepared(10, 'kwe'), NULL, 'A nested scope does not expose its outer scope');
      $check(array_keys($resolver->getPrepared(11, 'bestand')['nodes']), [30, 32], 'A nested scope prepares its own relations');
    });
    $check($resolver->getPrepared(10, 'kwe'), $outer, 'The outer scope is restored after a nested callback');
    $check($resolver->getPrepared(11, 'bestand'), NULL, 'Inner data is removed when its scope ends');
    try {
      $resolver->withItems([$items[11]], ['bestand'], static function (): never { throw new \RuntimeException('nested exception'); });
      throw new \RuntimeException('Expected nested callback exception.');
    }
    catch (\RuntimeException $error) { $check($error->getMessage(), 'nested exception', 'Nested callback exceptions are propagated'); }
    $check($resolver->getPrepared(10, 'kwe'), $outer, 'The outer scope is restored even after an inner exception');
    $nodes->failNextQuery = TRUE;
    $callbackRan = FALSE;
    try {
      $resolver->withItems([$items[11]], ['bestand'], static function () use (&$callbackRan): void { $callbackRan = TRUE; });
      throw new \RuntimeException('Expected preparation exception.');
    }
    catch (\RuntimeException $error) { $check($error->getMessage(), 'query exception', 'Preparation exceptions are propagated'); }
    $check($callbackRan, FALSE, 'A failed preparation does not invoke the callback');
    $check($resolver->getPrepared(10, 'kwe'), $outer, 'Preparation exceptions retain the outer scope');
  });
  $check($resolver->getPrepared(10, 'kwe'), NULL, 'Nested scopes leave no prepared data afterward');
  try {
    $resolver->withItems([$items[10]], ['kwe'], static function (): never { throw new \RuntimeException('callback exception'); });
    throw new \RuntimeException('Expected callback exception.');
  }
  catch (\RuntimeException $error) { $check($error->getMessage(), 'callback exception', 'Callback exceptions are propagated'); }
  $check($resolver->getPrepared(10, 'kwe'), NULL, 'Callback exceptions clear their prepared scope');

  $paragraphs->entities[106]->role = 500;
  $resolver->withItems([$items[10]], ['kwe'], function () use ($resolver, $check): void {
    $check($resolver->getPrepared(10, 'kwe')['node_role_ids'][21], [500], 'A new extraction observes changed relations instead of an earlier scope cache');
  });
  $paragraphs->entities[106]->role = 501;
  $check($resolver->getPrepared(10, 'kwe'), NULL, 'Repeated extractions also clear their scope');

  $tieManager = new FakeManager();
  $tieNodes = $tieManager->storages['node'];
  $tieParagraphs = $tieManager->storages['paragraph'];
  $accentTitle = hex2bin('C3856C616E64');
  $tieNodes->titleGroups = ['Aland' => 'Aland', $accentTitle => 'Aland'];
  foreach ([[200, 'Aland', 800], [201, $accentTitle, 801], [202, 'Aardvark', 802], [203, 'Zulu', 803], [204, 'Aland', 804], [205, 'Baobab', 805]] as [$id, $title, $paragraph]) {
    $tieNodes->entities[$id] = new FakeNode($id, 'kwe', $title, ['field_personen' => [$ref($paragraph)]]);
  }
  $tieNodes->entities[204]->translations['en'] = new FakeNode(204, 'kwe', $accentTitle, ['field_personen' => [$ref(804)]]);
  $tieNodes->entities[205]->translations['en'] = new FakeNode(205, 'kwe', 'Acacia', ['field_personen' => [$ref(805)]]);
  foreach ([[800, 50, 700], [801, 50, 701], [802, 50, 700], [803, 50, 701], [804, 51, 700], [805, 51, 701]] as [$id, $person, $role]) {
    $tieParagraphs->entities[$id] = new FakeParagraph($id, $person, $role);
  }
  $tieManager->storages['taxonomy_term']->entities = [700 => new FakeRole(700, 'Owner'), 701 => new FakeRole(701, 'Editor')];
  $tieNodes->tieOrders['kwe'][50] = [202, 201, 200, 203];
  $tieItems = [new FakeItem(new FakeNode(50, 'person'), $fields($bundles)), new FakeItem(new FakeNode(51, 'person'), $fields($bundles))];
  $tieResolver = new PersonRelations($tieManager, new FakeFieldsHelper());
  $tieResolver->withItems($tieItems, $bundles, function () use ($tieResolver, $tieManager, $tieNodes, $check): void {
    $prepared = $tieResolver->getPrepared(50, 'kwe');
    $check(array_keys($prepared['nodes']), [202, 201, 200, 203], 'SQL-equivalent accented titles retain every host in the native single-person order');
    $accentGroups = array_values(array_filter($tieNodes->aggregateResults[0], static fn ($row) => $row['field_person_target_id'] === 50 && $row['title'] === 'Aland'));
    $check(count($accentGroups), 1, 'The fixture aggregates accent variants into one SQL title group');
    $check([$accentGroups[0]['nid_min'], $accentGroups[0]['nid_max']], [200, 201], 'Distinct host IDs signal the SQL-collation tie');
    $check(in_array(201, $tieNodes->loads[0], TRUE), TRUE, 'The host hidden by the minimum aggregate ID is included in the single bulk load');
    $check($prepared['node_role_ids'][200], [700], 'Reordering retains the first tied host\'s roles');
    $check($prepared['node_role_ids'][201], [701], 'Reordering retains the second tied host\'s roles');
    $check(array_keys($tieResolver->getPrepared(51, 'kwe')['nodes']), [205, 204], 'Multilingual title rows retain each host once at its smallest-title position');
    $singleHostGroups = array_values(array_filter($tieNodes->aggregateResults[0], static fn ($row) => $row['field_person_target_id'] === 51 && $row['nid_min'] === 204));
    $check(count($singleHostGroups), 1, 'Equivalent translated titles of a single host form one SQL group');
    $check([$singleHostGroups[0]['nid_min'], $singleHostGroups[0]['nid_max']], [204, 204], 'Duplicate translated rows for one node do not signal a title tie');
    $multipleTitleGroups = array_values(array_filter($tieNodes->aggregateResults[0], static fn ($row) => $row['field_person_target_id'] === 51 && $row['nid_min'] === 205));
    $check(array_column($multipleTitleGroups, 'title_min'), ['Acacia', 'Baobab'], 'Different translated titles of one host produce multiple ordered SQL groups');
    $check($tieResolver->getPrepared(51, 'kwe')['node_role_ids'][205], [701], 'Multiple title groups preserve the default host roles');
    $check(count(array_filter($tieNodes->loads[0], static fn ($id) => $id === 205)), 1, 'Multiple title groups still bulk load the host only once');
    $check(count($tieNodes->queries), 4, 'Only a tied person and bundle adds one query to the three aggregate queries');
    $fallbackQueries = array_values(array_filter($tieNodes->queries, static fn ($query) => !$query['aggregates']));
    $check(count($fallbackQueries), 1, 'Exactly one person/bundle requires a native ordering query');
    $fallback = $fallbackQueries[0];
    $check($fallback['access'], FALSE, 'The tie fallback retains the native access-check behavior');
    $check($fallback['conditions'], ['status' => [1, '='], 'type' => ['kwe', '='], 'field_personen.entity:paragraph.field_person.target_id' => [50, '=']], 'The tie fallback uses the original published/bundle/single-person conditions');
    $check($fallback['sorts'], ['title' => 'ASC'], 'The tie fallback uses the original title sorting');
    foreach ($tieManager->storages as $storage) { $check(count($storage->loads), 1, 'Ordering fallback reuses already-loaded entities'); }
  });
  $check($tieResolver->getPrepared(50, 'kwe'), NULL, 'Title-tie preparation also ends with an empty scope');

  $manager->resetLogs();
  foreach ([[[], $bundles], [[$items[10]], []], [[$items[10]], ['other']], [[new FakeItem(new \stdClass(), $fields($bundles))], $bundles], [[new FakeItem(new FakeNode(0, 'person'), $fields($bundles))], $bundles], [[new FakeItem(NULL, $fields($bundles))], $bundles]] as [$emptyItems, $emptyBundles]) {
    $check($resolver->withItems($emptyItems, $emptyBundles, static fn () => 42), 42, 'An empty preparation still runs its callback');
  }
  $check($nodes->queries, [], 'Empty preparations execute no relation queries');
  foreach ($manager->storages as $storage) { $check($storage->loads, [], 'Empty preparations execute no entity loads'); }

  echo "Person relations: $checks checks passed.\n";
}
