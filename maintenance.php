<?php
/** Local maintenance entrypoint. Destructive operations require --apply and the exact dry-run digest. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('BBF_LOADED', true);
require_once __DIR__ . '/bbf_auth.php';
function bbf_maintenance_fail(string $message, int $code = 2): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$usage = 'Usage: php maintenance.php retention --form=<id> [--apply --confirm=<retention-digest>]'
    . "\n       php maintenance.php backup --form=<id>"
    . "\n       php maintenance.php restore --bundle=<path> [--apply --confirm=<restore-digest>]"
    . "\n       php maintenance.php restore-abort --form=<id> [--apply --confirm=<restore-abort-digest>]"
    . "\n       php maintenance.php submit-recover"
    . "\n       php maintenance.php uploads-cleanup"
    . "\n       php maintenance.php deliveries-retry (cron: retry failed emails/webhooks whose retry is due)"
    . "\n       php maintenance.php selfcheck      (daily cron: check forms, folders, SMTP, stuck deliveries; email problems)"
    . "\n       php maintenance.php alerts         (send pending admin alerts now)"
    . "\n       php maintenance.php alerts-test    (send a test alert to error_notify)"
    . "\n       php maintenance.php new-token    (generate 64 hex characters using bin2hex(random_bytes(32)); keep secret)"
    . "\n       php maintenance.php help         (tokens require at least 32 hex characters; format does not prove randomness)"
    . "\n       php maintenance.php version"
    . "\n       php maintenance.php upgrade --package=<release.zip|folder> [--checksum=<sha256 from SHA256SUMS> | --trust-package] [--apply --confirm=<upgrade-digest>]"
    . "\n       php maintenance.php upgrade-rollback --backup=<logs/upgrades/...> [--apply --confirm=<rollback-digest>]";
$arguments = $argv;
array_shift($arguments);
$command = array_shift($arguments);
$allowed = match ($command) {
    'retention' => ['form', 'confirm'],
    'backup' => ['form'],
    'restore' => ['bundle', 'confirm'],
    'restore-abort' => ['form', 'confirm'],
    'upgrade' => ['package', 'confirm', 'checksum'],
    'upgrade-rollback' => ['backup', 'confirm'],
    'submit-recover', 'uploads-cleanup', 'deliveries-retry', 'selfcheck', 'alerts', 'alerts-test', 'version', 'new-token', 'help' => [],
    default => bbf_maintenance_fail($usage),
};
$options = ['apply' => false];
foreach ($arguments as $argument) {
    if ($argument === '--trust-package' && $command === 'upgrade' && !isset($options['trust'])) {
        $options['trust'] = true;
        continue;
    }
    if ($argument === '--apply') {
        if ($options['apply'] || in_array($command, ['backup', 'submit-recover', 'uploads-cleanup', 'deliveries-retry', 'selfcheck', 'alerts', 'alerts-test', 'version', 'new-token', 'help'], true)) bbf_maintenance_fail('Unknown or duplicate maintenance option.');
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
if (!in_array($command, ['backup', 'submit-recover', 'uploads-cleanup', 'deliveries-retry', 'selfcheck', 'alerts', 'alerts-test', 'version', 'new-token', 'help'], true) && $options['apply'] !== array_key_exists('confirm', $options)) {
    bbf_maintenance_fail('--apply and --confirm must be supplied together.');
}
if ($command === 'help') { fwrite(STDOUT, $usage . "\n"); exit(0); }
if ($command === 'new-token') { fwrite(STDOUT, bin2hex(random_bytes(32)) . "\n"); exit(0); }
if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "Missing config.php.\n");
    exit(2);
}
require_once __DIR__ . '/bbf_retention.php';
require_once __DIR__ . '/bbf_backup.php';

$config = bbf_auth_load_config(__DIR__ . '/config.php');
if (in_array($command, ['version', 'upgrade', 'upgrade-rollback'], true)) {
    require_once __DIR__ . '/bbf_upgrade.php';
    if ($command === 'version') {
        fwrite(STDOUT, 'BareBonesForms ' . bbf_version() . "\n");
        exit(0);
    }
    $path = $options[$command === 'upgrade' ? 'package' : 'backup'] ?? null;
    if (!is_string($path) || $path === '') bbf_maintenance_fail($command === 'upgrade' ? 'A --package path is required.' : 'A --backup path is required.');
    // --checksum: the SHA-256 from the release's SHA256SUMS, proving the ZIP is the published one.
    $checksum = strtolower(trim((string)($options['checksum'] ?? '')));
    if (isset($options['checksum'])) {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $checksum) || !is_file($path)) bbf_maintenance_fail('--checksum needs a 64-character SHA-256 and a --package ZIP file.');
        if (!hash_equals($checksum, (string)hash_file('sha256', $path))) bbf_maintenance_fail("The package does not match --checksum; it is not the published release ZIP.", 1);
    }
    // Package code (its upgrader, its smoke test) runs in a dry run only for a verified package.
    $verified = $checksum !== '' || !empty($options['trust']);
    try {
        $confirm = $options['apply'] ? $options['confirm'] : null;
        $result = $command === 'upgrade'
            ? (bbf_upgrade_delegate($path, $confirm, __DIR__, $verified) ?? bbf_upgrade($config, $path, $confirm, __DIR__, $verified))
            : bbf_upgrade_rollback($path, $confirm);
    } catch (Throwable $error) {
        bbf_maintenance_fail(ucfirst($command) . ' failed: ' . $error->getMessage(), 1);
    }
    if (!$options['apply'] && isset($result['confirm']) && (($result['ok'] ?? false) || ($command === 'upgrade' && ($result['access_checked'] ?? false) && !empty($result['access_blocked']))) && !($result['up_to_date'] ?? false)) {
        $result['next'] = "php maintenance.php $command " . escapeshellarg('--' . ($command === 'upgrade' ? 'package' : 'backup') . "=$path")
            . ($checksum !== '' ? " --checksum=$checksum" : '') . (!empty($options['trust']) ? ' --trust-package' : '') . " --apply --confirm={$result['confirm']}";
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(bbf_upgrade_exit_code($result));
}
if (in_array($command, ['selfcheck', 'alerts', 'alerts-test'], true)) {
    require_once __DIR__ . '/bbf_functions.php';
    if ($command === 'alerts-test') {
        $to = trim((string)($config['error_notify'] ?? ''));
        if ($to === '') bbf_maintenance_fail('error_notify is empty in config.php.');
        $sent = bbf_alert_send($to, 'BareBonesForms: test alert',
            "This is a test alert from BareBonesForms on " . bbf_alert_site($config) . ".\nIf you can read this, problem reports will reach you.\n", $config);
        fwrite($sent ? STDOUT : STDERR, $sent ? "Test alert sent to $to.\n" : "Test alert could not be sent (SMTP and mail() both failed).\n");
        exit($sent ? 0 : 1);
    }
    $report = $command === 'selfcheck' ? bbf_alert_selfcheck($config) : ['ok' => true];
    $report['alerts'] = bbf_alert_flush($config);
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(($report['ok'] ?? false) && ($report['alerts']['reason'] ?? '') !== 'send_failed' ? 0 : 1);
}
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
if ($command === 'deliveries-retry') {
    require_once __DIR__ . '/bbf_functions.php';
    $report = bbf_delivery_retry_due($config);
    bbf_alert_flush($config);
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(($report['ok'] ?? false) ? 0 : 1);
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
