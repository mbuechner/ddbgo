<?php

/**
 * @file
 * Isolated SQL regression tests. Run with drush php:script, NEVER via a browser.
 * Only four tables with a random test prefix are created/removed. No live nodes,
 * field definitions, configuration, or deployment state are modified.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Render\RenderContext;
use Drupal\ddbgo_gin\Status\RecordStatus;
use Drupal\ddbgo_gin\Status\StatusMigration;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$info = Database::getConnectionInfo('default')['default'];
$info['prefix'] = 'dgs_test_' . bin2hex(random_bytes(6)) . '_';
Database::addConnectionInfo('ddbgo_status_test', 'default', $info);
$db = Database::getConnection('default', 'ddbgo_status_test');
$tables = ['node__field_status', 'node_revision__field_status', 'node__field_record_status', 'node_revision__field_record_status'];
$created = [];
$test_config = new \Drupal\Core\Config\MemoryStorage();
$test_schema = new \Drupal\Core\Entity\EntityLastInstalledSchemaRepository(new \Drupal\Core\KeyValueStore\KeyValueMemoryFactory(), new \Drupal\Core\Cache\MemoryBackend(Drupal::service('datetime.time')));
$migration = new StatusMigration($db, $test_config, $test_schema);

try {
  foreach ($tables as $table) {
    $fields = [];
    foreach (['bundle', 'langcode'] as $name) {
      $fields[$name] = ['type' => 'varchar', 'length' => 32, 'not null' => TRUE];
    }
    foreach (['deleted', 'entity_id', 'revision_id', 'delta'] as $name) {
      $fields[$name] = ['type' => 'int', 'not null' => TRUE];
    }
    if (str_contains($table, 'record_status')) {
      $fields['field_record_status_value'] = ['type' => 'varchar', 'length' => 255];
    }
    else {
      $fields['field_status_color'] = ['type' => 'varchar', 'length' => 32];
      $fields['field_status_opacity'] = ['type' => 'float'];
    }
    $db->schema()->createTable($table, ['fields' => $fields, 'primary key' => ['entity_id', 'revision_id', 'deleted', 'langcode', 'delta']]);
    $created[] = $table;
  }
  $id = 0;
  // >250 source rows exercises a resumed batch; all bundles and translations.
  foreach (RecordStatus::BUNDLES as $bundle) {
    foreach (['de', 'en'] as $language) {
      foreach (['#D2222D', '#ffbf00', '#238823', '#FFFFFF', '', NULL] as $color) {
        for ($i = 0; $i < 8; $i++) {
          $id++;
          $row = ['bundle' => $bundle, 'deleted' => 0, 'entity_id' => $id, 'revision_id' => $id * 10, 'langcode' => $language, 'delta' => 0, 'field_status_color' => $color, 'field_status_opacity' => NULL];
          $db->insert($tables[0])->fields($row)->execute();
          $db->insert($tables[1])->fields($row)->execute();
          $row['revision_id']--;
          $row['field_status_color'] = '#D2222D';
          $db->insert($tables[1])->fields($row)->execute();
        }
      }
    }
  }
  $before = $migration->audit();
  $check($before['error_count'] === 0 && $before['pending'] === 576 && $before['empty'] === 288, 'Initial audit includes all revisions and empty statuses');
  $migration->copyBatch(0, 0);
  $partial = $migration->audit();
  $check($partial['matched'] > 0 && $partial['pending'] > 0, 'Partial progress is auditable');
  $migration->copyBatch(0, 0);
  $check($migration->audit() === $partial, 'Replaying committed batch is idempotent');
  for ($table = 0; $table < 2; $table++) {
    for ($offset = 0; ; $offset += 250) {
      if ($migration->copyBatch($table, $offset) < 250) {
        break;
      }
    }
  }
  $after = $migration->audit();
  $migration->assertClean($after, TRUE);
  $check($after['matched'] === 576 && $after['fingerprints'] === $before['fingerprints'], 'Complete copy retains exact source fingerprints');

  $db->update($tables[2])->fields(['field_record_status_value' => 'ingested'])->condition('entity_id', 1)->execute();
  $check($migration->audit()['error_count'] === 1, 'Conflicting target blocks verification');
  try {
    $migration->copyBatch(0, 0);
    throw new RuntimeException('Copy should reject conflicting target');
  }
  catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Target conflict'), 'Copy never overwrites conflict');
  }
  $check($db->select($tables[2], 't')->fields('t', ['field_record_status_value'])->condition('entity_id', 1)->execute()->fetchField() === 'ingested', 'Conflicting value retained');
  $db->update($tables[2])->fields(['field_record_status_value' => 'rejected'])->condition('entity_id', 1)->execute();
  foreach (['#000000', 'red'] as $unknown) {
    $db->update($tables[0])->fields(['field_status_color' => $unknown])->condition('entity_id', 1)->execute();
    $check($migration->audit()['error_count'] === 1, 'Unknown source color is never guessed');
  }
  $db->update($tables[0])->fields(['field_status_color' => '#D2222D'])->condition('entity_id', 1)->execute();
  $db->update($tables[0])->fields(['field_status_opacity' => 0.5])->condition('entity_id', 1)->execute();
  $check($migration->audit()['error_count'] === 1, 'Unexpected opacity is not discarded');
  $db->update($tables[0])->fields(['field_status_opacity' => NULL])->condition('entity_id', 1)->execute();
  $db->insert($tables[2])->fields(['bundle' => 'kwe', 'deleted' => 0, 'entity_id' => 999999, 'revision_id' => 999999, 'langcode' => 'de', 'delta' => 0, 'field_record_status_value' => 'ingested'])->execute();
  $check($migration->audit()['error_count'] === 1, 'Orphan targets block verification');
  $db->delete($tables[2])->condition('entity_id', 999999)->execute();
  $migration->assertClean($migration->audit(), TRUE);
  $check($migration->audit()['fingerprints'] === $before['fingerprints'], 'Original current/revision source unchanged');

  // Prove a conflict rolls back inserts earlier in the same batch.
  $db->delete($tables[2])->condition('entity_id', 1)->execute();
  $db->update($tables[2])->fields(['field_record_status_value' => 'ingested'])->condition('entity_id', 5)->execute();
  try {
    $migration->copyBatch(0, 0);
    throw new RuntimeException('Expected transactional conflict');
  }
  catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Target conflict'), 'Later conflict fails batch');
  }
  $check(!$db->select($tables[2], 't')->fields('t')->condition('entity_id', 1)->execute()->fetchAssoc(), 'Earlier insert rolled back with failing batch');
  $db->update($tables[2])->fields(['field_record_status_value' => 'rejected'])->condition('entity_id', 5)->execute();
  $migration->copyBatch(0, 0);

  // Deployment tests use in-memory state and the isolated SQL connection.
  $container = Drupal::getContainer();
  $original_state = $container->get('state');
  $original_migration = $container->get('ddbgo_gin.status_migration');
  $state = new class extends \Drupal\Core\State\State {
    private array $values = [];
    public function __construct() {}
    public function get($key, $default = NULL) { return $this->values[$key] ?? $default; }
    public function set($key, $value) { $this->values[$key] = $value; }
    public function delete($key) { unset($this->values[$key]); }
  };
  $importer = static function () {
    return new class extends \Drupal\Core\Config\ConfigImporter {
      public function __construct() {
        $this->storageComparer = new \Drupal\Core\Config\StorageComparer(new \Drupal\Core\Config\FileStorage(DRUPAL_ROOT . '/../config/sync'), new \Drupal\Core\Config\MemoryStorage());
      }
      public function getUnprocessedConfiguration($op, $collection = '') { return []; }
    };
  };
  try {
    $container->set('state', $state);
    $container->set('ddbgo_gin.status_migration', $migration);
    $subscriber = new \Drupal\ddbgo_gin\EventSubscriber\StatusImportSubscriber();
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $check(count($attempt->getErrors()) === 1, 'Config cutover blocked before maintenance/migration');
    $state->set('system.maintenance_mode', TRUE);
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $errors = $attempt->getErrors();
    $check(count($errors) === 1 && str_contains((string) reset($errors), 'Run update 11002'), 'Maintenance alone does not replace migration');
    $test_config->write('views.view.test', ['field' => RecordStatus::FIELD]);
    try {
      $migration->archiveOrphanedTargets(TRUE);
      throw new RuntimeException('Expected configuration guard');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'active configuration'), 'Archive refuses referenced target tables');
    }
    $test_config->delete('views.view.test');
    $state->set('ddbgo_gin.status_migrating', TRUE);
    // Existing fixture tables stand in for prepare(); exercise all batch states.
    $sandbox = ['table' => 0, 'offset' => 0, 'processed' => 0, 'total' => $before['source_rows'], 'fingerprints' => $before['fingerprints']];
    do {
      $migration->migrate($sandbox);
    } while ($sandbox['#finished'] < 1);
    $check($state->get('ddbgo_gin.status_verified') === $before['fingerprints'], 'Completion records verified source snapshot');
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $check($attempt->getErrors() === [], 'Complete matching migration allows cutover');
    // Drush restores maintenance to its previous state after running updates.
    $state->set('system.maintenance_mode', FALSE);
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $errors = $attempt->getErrors();
    $check(count($errors) === 1 && str_contains((string) reset($errors), 'Enable maintenance mode'), 'Completed migration reports missing maintenance precisely');
    $state->set('system.maintenance_mode', TRUE);
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $check($attempt->getErrors() === [], 'Re-enabling maintenance permits cutover without rerunning migration');
    $state->set('ddbgo_gin.status_verified', []);
    $attempt = $importer();
    $subscriber->validate(new \Drupal\Core\Config\ConfigImporterEvent($attempt));
    $check(count($attempt->getErrors()) === 1, 'Stale snapshot blocks cutover');
    foreach (RecordStatus::BUNDLES as $bundle) {
      try {
        ddbgo_gin_node_presave(\Drupal\node\Entity\Node::create(['type' => $bundle]));
        throw new RuntimeException('Expected write lock');
      }
      catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'temporarily blocked'), 'Migration locks node writes');
      }
    }
    $state->set('ddbgo_gin.status_active', TRUE);
    try {
      $migration->migrate($sandbox);
      throw new RuntimeException('Expected migration lock after activation');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'already activated'), 'Legacy copy disabled after cutover');
    }
  }
  finally {
    $container->set('state', $original_state);
    $container->set('ddbgo_gin.status_migration', $original_migration);
  }

  // Render the real Twig template: labels remain visible without CSS/JS.
  foreach (RecordStatus::LABELS as $key => $label) {
    $html = Drupal::service('renderer')->executeInRenderContext(new RenderContext(), static fn () => Drupal::service('twig')->render('@ddbgo_gin/ddbgo-record-status.html.twig', ['status' => $key, 'label' => $label]));
    $check(str_contains($html, $label) && str_contains($html, 'aria-hidden="true"'), 'Status text visible, color decoration hidden from screen readers');
    $check(str_contains($html, 'title="Status: ' . $label . '"'), 'Native hover hint names the status');
  }
  foreach (RecordStatus::BUNDLES as $bundle) {
    $config = \Symfony\Component\Yaml\Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/core.entity_form_display.node.' . $bundle . '.default.yml');
    $dependency = reset($config['content']['field_ablehnungsgrund']['third_party_settings']['conditional_fields']);
    $options = $dependency['settings'] + ['field_cardinality' => 1];
    $options['selector'] = ':input[name="field_record_status"]';
    $handler = Drupal::service('plugin.manager.conditional_fields_handlers')->createInstance('states_handler_options_select');
    $states = $handler->statesHandler(['#key_column' => 'value'], [], $options);
    $check($states['!disabled'][$options['selector']]['value'] === 'rejected', 'Rejection reason depends on exact list value');
    $check($config['content'][RecordStatus::FIELD]['type'] === 'options_select' && $config['hidden']['field_status'], 'Native status widget replaces color widget');
  }
  foreach (glob(DRUPAL_ROOT . '/modules/custom/ddbgo_gin/config/status/*.yml') as $file) {
    $check(file_get_contents($file) === file_get_contents(DRUPAL_ROOT . '/../config/sync/' . basename($file)), 'Migration and sync field definitions match');
  }
  $storage_data = \Symfony\Component\Yaml\Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/field.storage.node.field_record_status.yml');
  $storage_data['settings'] = \Drupal\options\Plugin\Field\FieldType\ListStringItem::storageSettingsFromConfigData($storage_data['settings']);
  $storage = \Drupal\field\Entity\FieldStorageConfig::create($storage_data);
  $check($storage->getSetting('allowed_values') === RecordStatus::LABELS, 'Drupal storage receives semantic keys, not serialized option arrays');
  foreach (['aggregator', 'bestand', 'suche_kwe'] as $id) {
    $index = \Symfony\Component\Yaml\Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/search_api.index.' . $id . '.yml');
    $check($index['field_settings']['field_status']['property_path'] === RecordStatus::FIELD, 'Search field ID retained with new source');
    if ($id === 'bestand') {
      $check($index['field_settings']['field_status_kwe']['property_path'] === 'field_kwe:entity:' . RecordStatus::FIELD, 'Related KWE status uses new source');
    }
  }
  $view_count = 0;
  foreach (glob(DRUPAL_ROOT . '/../config/sync/views.view.*.yml') as $file) {
    $view = \Symfony\Component\Yaml\Yaml::parseFile($file);
    $affected = FALSE;
    foreach ($view['display'] as $display) {
      foreach ($display['display_options']['fields'] ?? [] as $field) {
        if (($field['type'] ?? '') === 'ddbgo_record_status') {
          $affected = TRUE;
          $check($field['click_sort_column'] === 'value', 'Status sorting uses list value column');
          $check($field['plugin_id'] === 'search_api_field' || ($field['table'] === 'node__field_record_status' && $field['field'] === RecordStatus::FIELD), 'Native Views handlers use new storage');
        }
      }
    }
    $view_count += (int) $affected;
  }
  $check($view_count === 9, 'All nine affected Views use shared status formatter');

  // Reproduce a restored DB: physical targets remain, definitions/state do not.
  $container->set('state', $state);
  try {
    $state->delete('ddbgo_gin.status_active');
    $state->delete('ddbgo_gin.status_verified');
    $state->delete('ddbgo_gin.status_migrating');
    $state->delete('system.maintenance_mode');
    $plan = $migration->archiveOrphanedTargets();
    $check(count($plan['tables']) === 2, 'Orphan archive defaults to a read-only plan');
    $check($db->schema()->tableExists($tables[2]), 'Planning leaves target table in place');
    try {
      $migration->archiveOrphanedTargets(TRUE);
      throw new RuntimeException('Expected maintenance guard');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'maintenance'), 'Archive requires maintenance mode');
    }
    $state->set('system.maintenance_mode', TRUE);
    $state->set('ddbgo_gin.status_active', TRUE);
    try {
      $migration->archiveOrphanedTargets(TRUE);
      throw new RuntimeException('Expected active migration guard');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'migration state'), 'Archive refuses active migration');
    }
    $state->delete('ddbgo_gin.status_active');
    $test_config->write('field.storage.node.' . RecordStatus::FIELD, ['field_name' => RecordStatus::FIELD]);
    try {
      $migration->archiveOrphanedTargets(TRUE);
      throw new RuntimeException('Expected field ownership guard');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'No unowned'), 'Archive refuses configured target field');
    }
    $test_config->delete('field.storage.node.' . RecordStatus::FIELD);
    $test_schema->setLastInstalledFieldStorageDefinition($storage);
    try {
      $migration->archiveOrphanedTargets(TRUE);
      throw new RuntimeException('Expected installed schema guard');
    }
    catch (RuntimeException $e) {
      $check(str_contains($e->getMessage(), 'installed field schema'), 'Archive refuses installed target schema even without config');
    }
    $test_schema->deleteLastInstalledFieldStorageDefinition($storage);
    $archive = $migration->archiveOrphanedTargets(TRUE);
    foreach ($archive['tables'] as $old => $entry) {
      $created[] = $entry['archive'];
      $check(!$db->schema()->tableExists($old) && $db->schema()->tableExists($entry['archive']), 'Target retained under archive name');
      $check($entry['status'] === 'verified', 'Archived rows and checksum verified');
    }
    $history = $state->get('ddbgo_gin.status_archives');
    $check($history[$archive['id']] === $archive, 'Archive manifest retained for recovery');
    $report = $migration->audit();
    $check($report['error_count'] === 0 && $report['pending'] === $before['pending'], 'Migration can restart without stale targets');
    $check($report['fingerprints'] === $before['fingerprints'], 'Archive leaves both legacy source tables unchanged');
    try {
      $migration->assertClean($report, TRUE);
    }
    catch (RuntimeException $e) {
      $check(strlen($e->getMessage()) < 250 && !str_contains($e->getMessage(), '{'), 'Batch failure is concise, without nested JSON');
    }
  }
  finally {
    // Include partially renamed tables if a verification intentionally fails.
    foreach ($state->get('ddbgo_gin.status_archives', []) as $manifest) {
      foreach ($manifest['tables'] as $entry) {
        $created[] = $entry['archive'];
      }
    }
    $container->set('state', $original_state);
  }
  echo "PASS: $checks status migration/SQL/Twig checks. Live content unchanged.\n";
}
finally {
  foreach (array_reverse($created) as $table) {
    // The connection's random prefix confines cleanup to this test's tables.
    if ($db->schema()->tableExists($table)) {
      $db->schema()->dropTable($table);
    }
  }
  Database::closeConnection('default', 'ddbgo_status_test');
}
