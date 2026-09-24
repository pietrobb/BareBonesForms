<?php
/** Local maintenance entrypoint. Destructive operations require --apply and the exact dry-run digest. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('BBF_LOADED', true);
if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "Missing config.php.\n");
    exit(2);
}
require_once __DIR__ . '/bbf_auth.php';
require_once __DIR__ . '/bbf_retention.php';
require_once __DIR__ . '/bbf_backup.php';

function bbf_maintenance_fail(string $message, int $code = 2): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$usage = 'Usage: php maintenance.php retention --form=<id> [--apply --confirm=<retention-digest>]'
    . "\n       php maintenance.php backup --form=<id>"
    . "\n       php maintenance.php restore --bundle=<path> [--apply --confirm=<restore-digest>]"
    . "\n       php maintenance.php restore-abort --form=<id> [--apply --confirm=<restore-abort-digest>]"
    . "\n       php maintenance.php submit-recover"
    . "\n       php maintenance.php uploads-cleanup";
$arguments = $argv;
array_shift($arguments);
$command = array_shift($arguments);
$allowed = match ($command) {
    'retention' => ['form', 'confirm'],
    'backup' => ['form'],
    'restore' => ['bundle', 'confirm'],
    'restore-abort' => ['form', 'confirm'],
    'submit-recover', 'uploads-cleanup' => [],
    default => bbf_maintenance_fail($usage),
};
$options = ['apply' => false];
foreach ($arguments as $argument) {
    if ($argument === '--apply') {
        if ($options['apply'] || in_array($command, ['backup', 'submit-recover', 'uploads-cleanup'], true)) bbf_maintenance_fail('Unknown or duplicate maintenance option.');
        $options['apply'] = true;
        continue;
    }
    if (!preg_match('/\A--([a-z]+)=(.*)\z/D', $argument, $match)
        || !in_array($match[1], $allowed, true) || array_key_exists($match[1], $options)) {
        bbf_maintenance_fail('Unknown or duplicate maintenance option.');
    }
    $options[$match[1]] = $match[2];
}
if (in_array($command, ['retention', 'backup', 'restore-abort'], true)) {
    $formId = bbf_auth_id($options['form'] ?? null);
    if ($formId === '') bbf_maintenance_fail('A valid --form is required.');
}
if ($command === 'restore' && !is_string($options['bundle'] ?? null)) {
    bbf_maintenance_fail('A --bundle path is required.');
}
if (!in_array($command, ['backup', 'submit-recover', 'uploads-cleanup'], true) && $options['apply'] !== array_key_exists('confirm', $options)) {
    bbf_maintenance_fail('--apply and --confirm must be supplied together.');
}
$config = bbf_auth_load_config(__DIR__ . '/config.php');
if ($command === 'submit-recover') {
    // Recovery for every form's submit intents (docs/SUBMIT-TRANSACTIONS.md §7); no time budget.
    require_once __DIR__ . '/bbf_submit_tx.php';
    try {
        foreach (bbf_tx_sweep($config) as [$form, $k, $action]) fwrite(STDOUT, "$form " . substr($k, 0, 12) . " $action\n");
    } catch (Throwable $error) {
        bbf_maintenance_fail('Submit recovery failed: ' . $error->getMessage(), 1);
    }
    exit(0);
}
if ($command === 'uploads-cleanup') {
    require_once __DIR__ . '/bbf_submit_tx.php';
    try {
        $report = bbf_uploads_cleanup($config,
            static fn(string $form, string $id) => bbf_record_exists(bbf_effective_storage_config($config, $form), $form, $id),
            static fn(string $form) => bbf_tx_storage_fingerprint(bbf_effective_storage_config($config, $form)));
    } catch (Throwable $error) {
        bbf_maintenance_fail('Upload cleanup failed: ' . $error->getMessage(), 1);
    }
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(($report['ok'] ?? false) ? 0 : 1);
}
try {
    $result = match ($command) {
        'retention' => $options['apply']
            ? bbf_retention_apply($config, $formId, $options['confirm'])
            : bbf_retention_plan($config, $formId),
        'backup' => bbf_backup_create($config, $formId),
        'restore' => $options['apply']
            ? bbf_backup_restore($config, $options['bundle'], $options['confirm'])
            : bbf_backup_restore_plan($config, $options['bundle']),
        'restore-abort' => $options['apply']
            ? bbf_backup_restore_abort($config, $formId, $options['confirm'])
            : bbf_backup_restore_abort_plan($config, $formId),
    };
} catch (Throwable $error) {
    error_log('BareBonesForms maintenance: ' . $error->getMessage());
    bbf_maintenance_fail('Maintenance operation failed.', 1);
}
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $json . "\n");
$appliedFailure = $options['apply'] && !($result['ok'] ?? false);
exit(($command === 'backup' && !($result['ok'] ?? false)) || $appliedFailure ? 1 : 0);
