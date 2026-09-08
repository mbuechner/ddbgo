<?php

namespace Drupal\ddbgo_gin\Status;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * Additive, resumable migration of dedicated current AND revision field tables.
 *
 * Never saves nodes, updates source rows, overwrites target rows, or deletes data.
 * Metadata (including revision IDs/languages) is copied verbatim. Run with all
 * writers stopped. A source fingerprint also detects changes during migration.
 */
final class StatusMigration {

  private const META = ['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta'];
  private const TABLES = ['node__field_status', 'node_revision__field_status'];

  public function __construct(
    private readonly Connection $database,
    private readonly StorageInterface $configStorage,
    private readonly EntityLastInstalledSchemaRepositoryInterface $installedSchema,
  ) {}

  public static function create(): self {
    return \Drupal::service('ddbgo_gin.status_migration');
  }

  private function target(string $table): string {
    return str_replace('field_status', RecordStatus::FIELD, $table);
  }

  private function rows(string $table) {
    $query = $this->database->select($table, 's')->fields('s');
    foreach (self::META as $column) {
      $query->orderBy($column);
    }
    return $query;
  }

  private function key(array $row): string {
    return json_encode(array_map(static fn ($key) => (string) $row[$key], self::META), JSON_THROW_ON_ERROR);
  }

  private function describe(array $row): string {
    return sprintf('bundle=%s node=%s revision=%s language=%s delta=%s', $row['bundle'], $row['entity_id'], $row['revision_id'], $row['langcode'], $row['delta']);
  }

  private function expected(array $row): ?array {
    if (!in_array($row['bundle'], RecordStatus::BUNDLES, TRUE) || (int) $row['delta'] !== 0 || (int) $row['deleted'] !== 0) {
      throw new \UnexpectedValueException('Unexpected bundle, delta or deleted row: ' . $this->describe($row));
    }
    if ($row['field_status_opacity'] !== NULL && (float) $row['field_status_opacity'] !== 1.0) {
      throw new \UnexpectedValueException('Unexpected opacity: ' . $this->describe($row));
    }
    $value = RecordStatus::fromColor($row['field_status_color']);
    return $value === NULL ? NULL : array_intersect_key($row, array_flip(self::META)) + [RecordStatus::FIELD . '_value' => $value];
  }

  /**
   * Read-only preflight/verification, including orphan or conflicting targets.
   */
  public function audit(): array {
    $report = ['source_rows' => 0, 'matched' => 0, 'pending' => 0, 'empty' => 0, 'error_count' => 0, 'errors' => [], 'fingerprints' => []];
    $error = static function (string $message) use (&$report): void {
      $report['error_count']++;
      if (count($report['errors']) < 20) {
        $report['errors'][] = $message;
      }
    };
    foreach (self::TABLES as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        throw new \RuntimeException('Missing source table: ' . $table);
      }
      $targets = [];
      if ($this->database->schema()->tableExists($this->target($table))) {
        foreach ($this->rows($this->target($table))->execute() as $object) {
          $row = (array) $object;
          $targets[$this->key($row)] = $row;
        }
      }
      $hash = hash_init('sha256');
      foreach ($this->rows($table)->execute() as $object) {
        $row = (array) $object;
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        $report['source_rows']++;
        $key = $this->key($row);
        try {
          $expected = $this->expected($row);
          if (isset($targets[$key])) {
            // DB drivers can return numeric metadata as strings or integers.
            if ($expected === NULL || $targets[$key] != $expected) {
              throw new \UnexpectedValueException('Conflicting target: ' . $this->describe($row));
            }
            $report['matched']++;
          }
          elseif ($expected !== NULL) {
            $report['pending']++;
          }
          else {
            $report['empty']++;
          }
        }
        catch (\UnexpectedValueException $e) {
          $error($table . ': ' . $e->getMessage());
        }
        unset($targets[$key]);
      }
      foreach ($targets as $row) {
        $error($this->target($table) . ': Target without matching source revision: ' . $this->describe($row));
      }
      $report['fingerprints'][$table] = hash_final($hash);
    }
    return $report;
  }

  public function assertClean(array $report, bool $complete = FALSE): void {
    if ($report['error_count'] || ($complete && $report['pending'])) {
      // Keep update-batch exceptions short. The read-only command prints details
      // once, rather than embedding a second full JSON document in the error.
      throw new \RuntimeException(sprintf('Status migration blocked: %d conflicts, %d pending values. No target values overwritten. Run ddbgo:status-check for details.', $report['error_count'], $report['pending']));
    }
  }

  /**
   * Physical tables may survive restoring an older SQL dump into the same DB.
   */
  public function orphanedTargetTables(): array {
    if ($this->configStorage->exists('field.storage.node.' . RecordStatus::FIELD)) {
      return [];
    }
    return array_values(array_filter(array_map($this->target(...), self::TABLES), fn (string $table) => $this->database->schema()->tableExists($table)));
  }

  private function tableSnapshot(string $table): array {
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($this->rows($table)->execute() as $row) {
      hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR) . "\n");
      $count++;
    }
    return ['rows' => $count, 'sha256' => hash_final($hash)];
  }

  /**
   * Archive ONLY unowned tables. Never merge, overwrite, truncate, or drop them.
   *
   * Defaults to a read-only plan. Definitions, state and configuration must all
   * agree that the target is unused. Every rename is recorded before execution,
   * because MySQL DDL is not transactionally reversible as a group.
   */
  public function archiveOrphanedTargets(bool $execute = FALSE): array {
    foreach (['status_active', 'status_verified', 'status_migrating'] as $key) {
      if (\Drupal::state()->get('ddbgo_gin.' . $key)) {
        throw new \RuntimeException('Archive refused: migration state exists; inspect the deployment first.');
      }
    }
    $tables = $this->orphanedTargetTables();
    if (!$tables) {
      throw new \RuntimeException('No unowned status tables to archive.');
    }
    $installed = $this->installedSchema->getLastInstalledFieldStorageDefinitions('node');
    if (isset($installed[RecordStatus::FIELD])) {
      throw new \RuntimeException('Archive refused: installed field schema still owns the target tables.');
    }
    $config_storage = $this->configStorage;
    foreach ($config_storage->listAll() as $name) {
      // Also detects references in Views, form/display groups and API settings.
      if (str_contains($name, RecordStatus::FIELD) || str_contains(json_encode($config_storage->read($name), JSON_THROW_ON_ERROR), RecordStatus::FIELD)) {
        throw new \RuntimeException('Archive refused: active configuration still references the target field: ' . $name);
      }
    }
    if ($execute && !\Drupal::state()->get('system.maintenance_mode')) {
      throw new \RuntimeException('Enable maintenance mode and stop writers before archiving.');
    }
    $id = bin2hex(random_bytes(6));
    $plan = ['id' => $id, 'created' => gmdate(DATE_ATOM), 'tables' => []];
    foreach ($tables as $table) {
      $archive = 'dgs_bak_' . $id . (str_contains($table, 'revision') ? '_revision' : '_current');
      if ($this->database->schema()->tableExists($archive)) {
        throw new \RuntimeException('Archive name already exists; nothing changed.');
      }
      $plan['tables'][$table] = ['archive' => $archive, 'snapshot' => $this->tableSnapshot($table), 'status' => 'planned'];
    }
    if (!$execute) {
      return $plan;
    }
    $lock = \Drupal::lock();
    if (!$lock->acquire('ddbgo_gin.status_archive', 300)) {
      throw new \RuntimeException('Another status archive operation is running.');
    }
    try {
      $history = \Drupal::state()->get('ddbgo_gin.status_archives', []);
      $history[$id] = $plan;
      \Drupal::state()->set('ddbgo_gin.status_archives', $history);
      foreach ($plan['tables'] as $table => &$entry) {
        if ($this->tableSnapshot($table) !== $entry['snapshot']) {
          throw new \RuntimeException('Target changed during archive preparation; inspect archive manifest.');
        }
        $this->database->schema()->renameTable($table, $entry['archive']);
        $entry['status'] = 'renamed';
        $history[$id] = $plan;
        \Drupal::state()->set('ddbgo_gin.status_archives', $history);
        if ($this->tableSnapshot($entry['archive']) !== $entry['snapshot']) {
          throw new \RuntimeException('Archive verification failed; both source data and archive must be retained.');
        }
        $entry['status'] = 'verified';
        $history[$id] = $plan;
        \Drupal::state()->set('ddbgo_gin.status_archives', $history);
      }
      unset($entry);
    }
    finally {
      $lock->release('ddbgo_gin.status_archive');
    }
    return $plan;
  }

  /**
   * Create only missing definitions; refuse incompatible pre-existing fields.
   */
  public function prepare(): void {
    $path = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('ddbgo_gin') . '/config/status';
    $definitions = [];
    foreach (glob($path . '/*.yml') as $file) {
      // Storage must precede bundle fields; handled separately below.
      $definitions[basename($file, '.yml')] = Yaml::parseFile($file);
    }
    foreach (array_merge(['field.storage.node.' . RecordStatus::FIELD], array_map(static fn ($bundle) => 'field.field.node.' . $bundle . '.' . RecordStatus::FIELD, RecordStatus::BUNDLES)) as $name) {
      if (!isset($definitions[$name])) {
        throw new \RuntimeException('Missing migration field definition: ' . $name);
      }
    }
    if ($this->orphanedTargetTables()) {
      throw new \RuntimeException('Unowned status tables found, possibly after restoring a backup. Inspect ddbgo:status-archive-orphans first.');
    }
    $storage_data = $definitions['field.storage.node.' . RecordStatus::FIELD];
    $storage_data['settings'] = \Drupal\options\Plugin\Field\FieldType\ListStringItem::storageSettingsFromConfigData($storage_data['settings']);
    $storage = FieldStorageConfig::load('node.' . RecordStatus::FIELD);
    if (!$storage) {
      FieldStorageConfig::create($storage_data)->save();
    }
    elseif ($storage->uuid() !== $storage_data['uuid'] || $storage->getType() !== 'list_string' || $storage->getCardinality() !== 1 || $storage->getSetting('allowed_values') !== RecordStatus::LABELS || !$storage->isTranslatable()) {
      throw new \RuntimeException('Incompatible target storage; nothing overwritten.');
    }
    foreach (RecordStatus::BUNDLES as $bundle) {
      $id = 'node.' . $bundle . '.' . RecordStatus::FIELD;
      $data = $definitions['field.field.' . $id];
      $field = FieldConfig::load($id);
      if (!$field) {
        FieldConfig::create($data)->save();
      }
      elseif ($field->uuid() !== $data['uuid'] || $field->getType() !== 'list_string' || $field->isTranslatable() !== $data['translatable']) {
        throw new \RuntimeException('Incompatible target field: ' . $id);
      }
    }
  }

  /**
   * One update-batch iteration. Exceptions leave the source and writers locked.
   */
  public function migrate(array &$sandbox): void {
    if (\Drupal::state()->get('ddbgo_gin.status_active')) {
      throw new \RuntimeException('Status already activated; legacy migration is disabled.');
    }
    if (!\Drupal::state()->get('system.maintenance_mode')) {
      throw new \RuntimeException('Enable maintenance mode and stop cron/queues/imports before migrating status.');
    }
    if (!isset($sandbox['table'])) {
      if ($this->orphanedTargetTables()) {
        throw new \RuntimeException('Unowned status tables found, possibly after restoring a backup. Inspect ddbgo:status-archive-orphans first.');
      }
      $report = $this->audit();
      $this->assertClean($report);
      \Drupal::state()->set('ddbgo_gin.status_migrating', TRUE);
      $this->prepare();
      $sandbox = ['table' => 0, 'offset' => 0, 'fingerprints' => $report['fingerprints'], 'processed' => 0, 'total' => $report['source_rows']];
    }
    $count = $this->copyBatch($sandbox['table'], $sandbox['offset']);
    $sandbox['offset'] += $count;
    $sandbox['processed'] += $count;
    if ($count < 250) {
      $sandbox['table']++;
      $sandbox['offset'] = 0;
    }
    $sandbox['#finished'] = min(0.99, $sandbox['processed'] / max(1, $sandbox['total']));
    if ($sandbox['table'] === count(self::TABLES)) {
      $report = $this->audit();
      $this->assertClean($report, TRUE);
      if ($report['fingerprints'] !== $sandbox['fingerprints']) {
        throw new \RuntimeException('Source changed during migration; do not switch configuration.');
      }
      \Drupal::state()->set('ddbgo_gin.status_verified', $report['fingerprints']);
      // Retain the write lock through config import and search reindexing.
      \Drupal::entityTypeManager()->getStorage('node')->resetCache();
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
      $sandbox['#finished'] = 1;
    }
  }

  /**
   * Copy one transaction; separate from deployment state for isolated DB tests.
   */
  public function copyBatch(int $table_number, int $offset): int {
    $table = self::TABLES[$table_number];
    $rows = $this->rows($table)->range($offset, 250)->execute()->fetchAll();
    $transaction = $this->database->startTransaction();
    try {
      foreach ($rows as $object) {
        $expected = $this->expected((array) $object);
        if ($expected === NULL) {
          continue;
        }
        $query = $this->database->select($this->target($table), 't')->fields('t');
        foreach (self::META as $column) {
          $query->condition($column, $expected[$column]);
        }
        $existing = $query->execute()->fetchAssoc();
        if ($existing && $existing != $expected) {
          throw new \RuntimeException('Target conflict during copy; source retained.');
        }
        if (!$existing) {
          $this->database->insert($this->target($table))->fields($expected)->execute();
        }
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return count($rows);
  }

}
