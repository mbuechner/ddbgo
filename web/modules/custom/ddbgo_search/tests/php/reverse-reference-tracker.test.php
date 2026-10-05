<?php

/**
 * Isolated regressions; run with php, without bootstrapping or writing Drupal.
 */

namespace Drupal\Core\Entity {
  interface EntityInterface {}
  interface EntityTypeManagerInterface {}
}

namespace Drupal\node {
  interface NodeInterface extends \Drupal\Core\Entity\EntityInterface {}
}

namespace Drupal\search_api\Plugin\search_api\datasource {
  class ContentEntityTrackingManager {
    public function __construct(public array $indexes) {}
    public function getIndexesForEntity($entity): array {
      return array_filter($this->indexes, static fn ($index) => in_array($entity->bundle(), $index->bundles, TRUE));
    }
    public static function formatItemId($type, $id, $language): string { return "$id:$language"; }
    public static function filterValidItemIds($index, $datasource, $ids): array {
      return array_values(array_filter($ids, static fn ($id) => in_array(explode(':', $id)[1], $index->languages, TRUE)));
    }
  }
}

namespace {
  use Drupal\Core\Entity\EntityInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\ddbgo_search\ReverseReferenceTracker;
  use Drupal\node\NodeInterface;
  use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager;

  require __DIR__ . '/../../src/ReverseReferenceTracker.php';

  class Entity implements EntityInterface {
    public ?self $original = NULL;
    public bool $default = TRUE;
    public bool $new = FALSE;
    public bool $search_api_skip_tracking = FALSE;
    public array $languages = ['de' => TRUE, 'en' => TRUE];
    public function __construct(public string $type, public string $entityBundle, public int $entityId, public array $fields = [], public bool $published = TRUE) {}
    public function getEntityTypeId(): string { return $this->type; }
    public function bundle(): string { return $this->entityBundle; }
    public function id(): int { return $this->entityId; }
    public function isNew(): bool { return $this->new; }
    public function isDefaultRevision(): bool { return $this->default; }
    public function isPublished(): bool { return $this->published; }
    public function getOriginal(): ?self { return $this->original; }
    public function hasField($field): bool { return isset($this->fields[$field]); }
    public function getTranslationLanguages(): array { return $this->languages; }
    public function get($field): object {
      return new class($this->fields[$field]) {
        public function __construct(private array $values) {}
        public function getValue(): array { return $this->values; }
      };
    }
  }
  class Node extends Entity implements NodeInterface {
    public function __construct($bundle, $id, $fields = [], $published = TRUE) { parent::__construct('node', $bundle, $id, $fields, $published); }
  }
  class Storage {
    public array $entities = [];
    public array $revisions = [];
    public array $cachedRevisions = [];
    public array $unchanged = [];
    public function load($id): ?Entity { return $this->entities[$id] ?? NULL; }
    public function loadUnchanged($id): ?Entity { return $this->unchanged[$id] ?? $this->load($id); }
    public function loadRevision($id): ?Entity { return $this->cachedRevisions[$id] ?? $this->revisions[$id] ?? NULL; }
    public function loadRevisionUnchanged($id): ?Entity { return $this->revisions[$id] ?? NULL; }
    public function loadMultiple($ids): array { return array_intersect_key($this->entities, array_flip($ids)); }
    public function getQuery(): Query { return new Query($this); }
  }
  class Query {
    private array $conditions = [];
    private ?int $limit = NULL;
    public function __construct(private Storage $storage) {}
    public function accessCheck($value): self { return $this; }
    public function condition($field, $value, $operator = '='): self { $this->conditions[] = [$field, $value, $operator]; return $this; }
    public function range($start, $length): self { $this->limit = $length; return $this; }
    public function execute(): array {
      $matches = array_filter($this->storage->entities, function ($entity) {
        foreach ($this->conditions as [$field, $value, $operator]) {
          if ($field === 'type') {
            if (!in_array($entity->bundle(), (array) $value, TRUE)) { return FALSE; }
          }
          elseif ($field === 'status') {
            if ($entity->isPublished() !== (bool) $value) { return FALSE; }
          }
          else {
            [$name, $property] = explode('.', $field);
            $values = array_column($entity->fields[$name] ?? [], $property);
            if (!in_array($value, $values, TRUE)) { return FALSE; }
          }
        }
        return TRUE;
      });
      $ids = array_keys($matches);
      return $this->limit === NULL ? $ids : array_slice($ids, 0, $this->limit);
    }
  }
  class Manager implements EntityTypeManagerInterface {
    public function __construct(public array $storages) {}
    public function getStorage($type): Storage { return $this->storages[$type]; }
  }
  class Index {
    public array $updates = [];
    public function __construct(public array $bundles, public array $properties, public bool $enabled = TRUE, public bool $readOnly = FALSE, public array $languages = ['de']) {}
    public function status(): bool { return $this->enabled; }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function getFields(): array {
      return array_map(static fn ($property) => new class($property) {
        public function __construct(private string $property) {}
        public function getPropertyPath(): string { return $this->property; }
      }, $this->properties);
    }
    public function trackItemsUpdated($datasource, $ids): void {
      if ($datasource !== 'entity:node') { throw new \RuntimeException('Wrong datasource'); }
      $this->updates = array_merge($this->updates, $ids);
    }
  }

  $checks = 0;
  $check = static function ($actual, $expected, $message) use (&$checks): void {
    sort($actual);
    sort($expected);
    if ($actual !== $expected) { throw new \RuntimeException($message . ': ' . json_encode($actual)); }
    $checks++;
  };
  $ref = static fn ($id, $revision = NULL) => [['target_id' => $id] + ($revision === NULL ? [] : ['target_revision_id' => $revision])];
  $nodes = new Storage();
  $paragraphs = new Storage();
  foreach ([10, 11, 12] as $id) { $nodes->entities[$id] = new Node('person', $id); }
  $nodes->entities[20] = new Node('aggregator', 20, ['field_personen' => $ref(102, 1002)]);
  $nodes->entities[21] = new Node('aggregator', 21);
  $nodes->entities[30] = new Node('bestand', 30, ['field_personen' => $ref(100, 1000), 'field_aggregator' => $ref(20), 'field_kwe' => $ref(31)]);
  $nodes->entities[31] = new Node('kwe', 31, ['field_personen' => $ref(101, 1001)]);
  foreach ([[100, 'person_bestand', 10, 500], [101, 'person_kwe', 11, 500], [102, 'person_aggregator', 12, 501]] as [$id, $bundle, $person, $role]) {
    $paragraphs->entities[$id] = new Entity('paragraph', $bundle, $id, ['field_person' => $ref($person), 'field_rolle' => $ref($role)]);
    $paragraphs->revisions[$id + 900] = clone $paragraphs->entities[$id];
  }
  $people = new Index(['person'], ['ddbgo_person_bestand', 'ddbgo_person_kwe', 'ddbgo_person_aggregator']);
  $aggregators = new Index(['aggregator'], ['ddbgo_aggregator_bestand:title', 'ddbgo_aggregator_kwe:title']);
  $kweOnly = new Index(['person'], ['ddbgo_person_kwe']);
  $disabled = new Index(['person', 'aggregator'], array_merge($people->properties, $aggregators->properties), FALSE);
  $readOnly = new Index(['person', 'aggregator'], array_merge($people->properties, $aggregators->properties), TRUE, TRUE);
  $unrelated = new Index(['person', 'aggregator'], ['title']);
  $wrongBundle = new Index(['kwe'], $people->properties);
  $indexes = [$people, $aggregators, $kweOnly, $disabled, $readOnly, $unrelated, $wrongBundle];
  $manager = new Manager(['node' => $nodes, 'paragraph' => $paragraphs]);
  $tracker = new ReverseReferenceTracker($manager, new ContentEntityTrackingManager($indexes));
  $reset = static function () use ($indexes): void { foreach ($indexes as $index) { $index->updates = []; } };
  $excluded = static function () use ($check, $disabled, $readOnly, $unrelated, $wrongBundle): void {
    foreach ([$disabled, $readOnly, $unrelated, $wrongBundle] as $index) { $check($index->updates, [], 'Excluded index is untouched'); }
  };

  // Newly linked source content updates only its actual dependents.
  $tracker->trackChange($nodes->entities[30]);
  $check($people->updates, ['10:de'], 'Bestand insertion updates its person');
  $check($aggregators->updates, ['20:de'], 'Bestand insertion updates its aggregator once');
  $check($kweOnly->updates, [], 'Indexes without the affected property are untouched');
  $excluded();
  $reset();

  // Reusing a paragraph ID must not lose the old revision's former person.
  $old = clone $nodes->entities[30];
  $changedParagraph = clone $paragraphs->entities[100];
  $changedParagraph->fields['field_person'] = $ref(11);
  $paragraphs->cachedRevisions[1000] = $changedParagraph;
  $paragraphs->entities[100] = $changedParagraph;
  $paragraphs->revisions[2000] = $changedParagraph;
  $changed = clone $old;
  $changed->fields['field_personen'] = $ref(100, 2000);
  $changed->fields['field_aggregator'] = $ref(21);
  $changed->original = $old;
  $tracker->trackChange($changed);
  $check($people->updates, ['10:de', '11:de'], 'Relinking marks old and new person using exact revisions');
  $check([$paragraphs->loadRevision(1000)->fields['field_person'][0]['target_id']], [11], 'Fixture contains an edited cached old paragraph');
  $check($aggregators->updates, ['20:de', '21:de'], 'Relinking marks old and new aggregator');
  $reset();

  // Promotion must compare against the stored default, not the edited draft.
  $draft = clone $changed;
  $draft->default = FALSE;
  $tracker->trackChange($draft);
  $check($people->updates, [], 'Draft changes do not invalidate published search');
  $promoted = clone $changed;
  $promoted->original = clone $changed;
  $promoted->original->fields['field_personen'] = $ref(102, 1002);
  $tracker->rememberOriginal($promoted);
  $nodes->entities[30] = $promoted;
  $tracker->trackChange($promoted);
  $check($people->updates, ['10:de', '11:de'], 'Promotion uses previous default rather than original draft');
  $check($aggregators->updates, ['20:de', '21:de'], 'Promotion preserves old aggregator dependency');
  $nodes->entities[30] = $old;
  $reset();
  $paragraphs->cachedRevisions = [];

  $unpublished = clone $old;
  $unpublished->published = FALSE;
  $tracker->rememberOriginal($unpublished);
  $nodes->entities[30] = $unpublished;
  $tracker->trackChange($unpublished);
  $check($people->updates, ['10:de'], 'Unpublishing invalidates former person text');
  $check($aggregators->updates, ['20:de'], 'Unpublishing invalidates former aggregator text');
  $reset();
  unset($nodes->entities[30]);
  $tracker->trackChange($old);
  $check($people->updates, ['10:de'], 'Deleting source still uses its outgoing references');
  $check($aggregators->updates, ['20:de'], 'Deleting source invalidates former aggregator');
  $nodes->entities[30] = $old;
  $reset();

  // A delete hook can run after child paragraphs or their revisions disappear.
  $tracker->rememberDeletion($old);
  $removedParagraph = $paragraphs->revisions[1000];
  unset($nodes->entities[30], $paragraphs->entities[100], $paragraphs->revisions[1000]);
  $tracker->trackChange($old, TRUE);
  $check($people->updates, ['10:de'], 'Predelete captures person IDs before paragraphs disappear');
  $check($aggregators->updates, ['20:de'], 'Predelete preserves aggregator dependency');
  $nodes->entities[30] = $old;
  $paragraphs->entities[100] = clone $removedParagraph;
  $paragraphs->revisions[1000] = $removedParagraph;
  $reset();

  $tracker->trackChange($nodes->entities[31]);
  $check($people->updates, ['11:de'], 'KWE title change updates person text');
  $check($aggregators->updates, ['20:de'], 'KWE title change follows Bestand to aggregator');
  $check($kweOnly->updates, ['11:de'], 'Relevant narrower index is updated');
  $reset();
  $tracker->trackChange($nodes->entities[20]);
  $check($people->updates, ['12:de'], 'Aggregator title change updates linked person');
  $check($aggregators->updates, [], 'Native own-entity tracking is not duplicated');
  $reset();

  $changedParagraph->original = $paragraphs->revisions[1000];
  $tracker->trackChange($changedParagraph);
  $check($people->updates, ['10:de', '11:de'], 'Paragraph person change tracks both people');
  $reset();
  $roleChanged = clone $paragraphs->revisions[1000];
  $roleChanged->fields['field_rolle'] = $ref(501);
  $tracker->trackChange($roleChanged);
  $check($people->updates, ['10:de'], 'Paragraph role change invalidates rendered role');
  $reset();
  $paragraphs->entities[100] = clone $paragraphs->revisions[1000];
  $tracker->trackChange(new Entity('taxonomy_term', 'personenrolle', 500));
  $check($people->updates, ['10:de', '11:de'], 'Role label update/delete touches linked people alone');
  $check($aggregators->updates, [], 'Role labels do not invalidate aggregator properties');
  $check($kweOnly->updates, ['11:de'], 'Role label update respects affected property');
  $excluded();
  $reset();

  $tracker->trackChange(new Entity('taxonomy_term', 'personenrolle', 999));
  $tracker->trackChange(new Entity('taxonomy_term', 'other', 500));
  $tracker->trackChange(new Entity('paragraph', 'other', 100));
  $tracker->trackChange($nodes->entities[10]);
  $check($people->updates, [], 'Unreferenced roles and unrelated entities do not trigger indexing');
  $unlinked = new Entity('paragraph', 'person_bestand', 999, ['field_person' => $ref(10)]);
  $tracker->trackChange($unlinked);
  $check($people->updates, [], 'Unattached paragraph does not trigger indexing');
  $nodes->entities[30]->published = FALSE;
  $tracker->trackChange($paragraphs->entities[100]);
  $check($people->updates, [], 'Paragraph on unpublished host is excluded');
  $nodes->entities[30]->published = TRUE;

  $skipped = clone $old;
  $skipped->search_api_skip_tracking = TRUE;
  $tracker->trackChange($skipped);
  $check($people->updates, [], 'Source skip-tracking is respected');
  $nodes->entities[10]->search_api_skip_tracking = TRUE;
  $tracker->trackChange($old);
  $check($people->updates, [], 'Parent skip-tracking is respected');
  $reset();
  $missing = clone $old;
  $missing->fields['field_personen'] = $ref(999, 9999);
  $tracker->trackChange($missing);
  $check($people->updates, [], 'Missing paragraph revisions are tolerated');
  $check($aggregators->updates, ['20:de'], 'Other valid dependencies survive missing paragraphs');

  echo "Reverse reference tracker: $checks checks passed.\n";
}
