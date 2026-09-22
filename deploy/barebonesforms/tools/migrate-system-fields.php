<?php
/** Remove redundant top-level hidden fields after system_fields is configured. Dry run unless --apply.
 * php tools/migrate-system-fields.php --root=/path/to/bbf --fields=gclid,gbraid,wbraid [--apply]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$options = getopt('', ['root:', 'fields:', 'apply']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if (!$root || !isset($options['fields'])) exit("Required: --root=INSTALLATION --fields=name,name [--apply]\n");
define('BBF_LOADED', true);
require_once $root . '/bbf_functions.php';
require_once $root . '/bbf_context.php';
require_once $root . '/bbf_versions.php';
$config = require $root . '/config.php';
$names = array_values(array_unique(explode(',', $options['fields'])));
$effective = array_column(bbfSystemDefinition(['fields' => []], $config)['fields'], null, 'name');
foreach ($names as $name) {
    if (!isset($effective[$name])) { fwrite(STDERR, "Not an enabled system field: $name\n"); exit(1); }
}
$apply = isset($options['apply']);
$plans = [];
try {
    foreach (glob($config['forms_dir'] . '/*.json') ?: [] as $path) {
        if (is_link($path)) throw new RuntimeException('Refusing linked form: ' . $path);
        $raw = file_get_contents($path);
        $form = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($form) || !isset($form['id'], $form['fields'])) continue;
        if (basename($path) !== $form['id'] . '.json') throw new RuntimeException('Form identity mismatch: ' . $path);
        $updated = $form;
        $updated['fields'] = array_values(array_filter($form['fields'], static function(array $field) use ($names): bool {
            if (!in_array($field['name'] ?? '', $names, true)) return true;
            // Do not erase local overrides, validation, conditions or nonempty defaults.
            if (($field['type'] ?? '') !== 'hidden' || ($field['value'] ?? '') !== ''
                || array_diff(array_keys($field), ['name', 'type', 'value']) !== []) {
                throw new RuntimeException('Local override requires operator review: ' . $field['name']);
            }
            return false;
        }));
        if ($updated === $form) continue;
        $errors = validateFormDefinition($updated);
        if ($errors) throw new RuntimeException('Invalid migrated form: ' . implode('; ', $errors));
        $plans[] = [$form, $updated, $raw, $path];
    }
    foreach ($plans as [$form, $updated, $raw, $path]) {
        print ($apply ? 'APPLY ' : 'DRY-RUN ') . $form['id'] . ': remove ' . (count($form['fields']) - count($updated['fields'])) . " fields\n";
        if (!$apply) continue;
        bbf_version_with_state($config, $form['id'], static function(array $paths, array $state) use ($form, $updated, $raw, $path): void {
            if ($state['published_version'] !== bbf_version_id($form) || $state['draft_version'] !== $state['published_version']) {
                throw new RuntimeException('Form changed or has an unpublished draft: ' . $form['id']);
            }
            $backup = $path . '.system-fields.bak_' . gmdate('Ymd');
            $handle = @fopen($backup, 'xb');
            if (!$handle) throw new RuntimeException('Backup already exists or cannot be created: ' . $backup);
            try {
                if (!bbf_storage_write_all($handle, $raw) || !fflush($handle)) throw new RuntimeException('Backup write failed.');
            } finally { fclose($handle); }
            $version = bbf_version_store_blob($paths, $updated);
            $state['draft_version'] = $version;
            $result = bbf_version_publish_locked($paths, $state, $version, 'publish', 'system-fields-migration');
            if (empty($result['ok']) || !empty($result['recovery_pending'])) throw new RuntimeException('Migration publication needs recovery.');
        });
    }
    print count($plans) . ($apply ? " forms migrated.\n" : " forms would change; nothing written.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
