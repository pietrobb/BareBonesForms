<?php
/** Migration runs only inside a disposable installation, never against workspace forms. */
if (PHP_SAPI !== 'cli') exit('CLI only.');
require_once __DIR__ . '/test-isolation-helper.php';
$root = bbf_test_installation(dirname(__DIR__));
$checks = $failed = 0;
function migrationCheck(bool $ok, string $label): void {
    ++$GLOBALS['checks']; if (!$ok) ++$GLOBALS['failed'];
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function migrateFixture(string $root, bool $apply): array {
    $command = [PHP_BINARY, dirname(__DIR__) . '/tools/migrate-system-fields.php', '--root=' . $root, '--fields=gclid,gbraid,wbraid'];
    if ($apply) $command[] = '--apply';
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['code' => proc_close($p), 'output' => $output];
}
try {
    $config = ['forms_dir' => "$root/forms", 'system_fields' => array_map(static fn($name) => ['name' => $name, 'type' => 'hidden'], ['gclid', 'gbraid', 'wbraid'])];
    file_put_contents("$root/config.php", '<?php return ' . var_export($config, true) . ';');
    $form = ['id' => 'migration-fixture', 'fields' => [['name' => 'answer', 'type' => 'text'],
        ['name' => 'gclid', 'type' => 'hidden', 'value' => ''], ['name' => 'gbraid', 'type' => 'hidden', 'value' => ''], ['name' => 'wbraid', 'type' => 'hidden', 'value' => '']]];
    $path = "$root/forms/migration-fixture.json";
    $original = json_encode($form, JSON_PRETTY_PRINT);
    file_put_contents($path, $original);
    $dry = migrateFixture($root, false);
    migrationCheck($dry['code'] === 0 && str_contains($dry['output'], '1 forms would change')
        && file_get_contents($path) === $original && !is_dir("$root/forms/.versions"), 'dry run writes neither definitions nor version state');
    $applied = migrateFixture($root, true);
    $updated = json_decode(file_get_contents($path), true);
    migrationCheck($applied['code'] === 0 && count($updated['fields']) === 1, 'apply removes exactly the three redundant hidden fields');
    $backup = $path . '.system-fields.bak_' . gmdate('Ymd');
    migrationCheck(is_file($backup) && file_get_contents($backup) === $original, 'migration preserves exact pre-change bytes in dated backup');
    $state = json_decode(file_get_contents("$root/forms/.versions/migration-fixture/state.json"), true);
    migrationCheck($state['published_version'] === $state['draft_version'] && count($state['history']) === 2, 'migration publishes through existing version history');
    $again = migrateFixture($root, true);
    migrationCheck($again['code'] === 0 && str_contains($again['output'], '0 forms migrated'), 'repeat migration is a no-op');
    $override = $form; $override['fields'][1]['value'] = 'local';
    file_put_contents($path, json_encode($override));
    $before = file_get_contents($path);
    $blocked = migrateFixture($root, true);
    migrationCheck($blocked['code'] !== 0 && str_contains($blocked['output'], 'Local override') && file_get_contents($path) === $before, 'nonempty local override blocks migration without modifying the form');
    file_put_contents($path, $original);
    $config['system_fields'] = [];
    file_put_contents("$root/config.php", '<?php return ' . var_export($config, true) . ';');
    $disabled = migrateFixture($root, true);
    migrationCheck($disabled['code'] !== 0 && str_contains($disabled['output'], 'Not an enabled system field') && file_get_contents($path) === $original, 'migration refuses to remove fields before system configuration is active');
} finally { bbf_test_cleanup($root); }
print "$checks checks, $failed failures\n";
exit($failed ? 1 : 0);
