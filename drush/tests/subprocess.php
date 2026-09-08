<?php

/**
 * @file
 * Read-only regression for the local Windows launcher; no updates are applied.
 */

use Drush\Drush;

$alias = Drush::aliasManager()->getSelf();
$child = Drush::drush($alias, 'updatedb:status', [], ['strict' => 0, 'format' => 'json']);
$child->mustRun();
// With no pending updates, Drush may return no JSON at all.
if (trim($child->getOutput()) !== '') {
  json_decode($child->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);
}
echo "PASS: update status subprocess. No updates executed.\n";

$child = Drush::drush($alias, 'php:eval', ['echo "argument with spaces";']);
$child->mustRun();
if ($child->getOutput() !== 'argument with spaces') {
  throw new RuntimeException('Arguments not preserved.');
}
echo "PASS: subprocess arguments preserved.\n";

$child = Drush::drush($alias, 'php:eval', [
  '\Drush\Drush::drush(\Drush\Drush::aliasManager()->getSelf(), "updatedb:status", [], ["strict" => 0])->mustRun(); echo "nested-ok";',
]);
$child->mustRun();
if ($child->getOutput() !== 'nested-ok') {
  throw new RuntimeException('Nested subprocess failed.');
}
echo "PASS: nested subprocess.\n";

// A normal command exception carries an exit code through Drush itself.
// Bare exit(23) is intentionally converted to 1 by Drush's shutdown handler.
$child = Drush::drush($alias, 'php:eval', ['throw new \RuntimeException("Expected launcher test failure", 23);']);
$child->run();
if ($child->getExitCode() !== 23) {
  throw new RuntimeException('Child exit code not preserved.');
}
echo "PASS: child exit code preserved.\n";

// Exercise the API actually used by updb, not just PHP's json_decode().
$payload = ['error' => 'Status "probe"', 'path' => 'C:\\fixtures\\status', 'nested' => '{"status":"ingested"}'];
$code = 'echo json_encode(' . var_export($payload, TRUE) . ', JSON_THROW_ON_ERROR);';
$child = Drush::drush($alias, 'php:eval', [$code]);
$child->mustRun();
if ($child->getOutputAsJson() !== $payload) {
  throw new RuntimeException('Drush JSON decoder corrupted escaped data.');
}
echo "PASS: Drush JSON decoder preserves quotes and backslashes.\n";
