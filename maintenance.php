<?php
/** Local maintenance entrypoint. Retention is always dry-run unless --apply and the exact plan digest are both supplied. */
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

function bbf_maintenance_fail(string $message, int $code = 2): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$arguments = $argv;
array_shift($arguments);
$command = array_shift($arguments);
if ($command !== 'retention') {
    bbf_maintenance_fail('Usage: php maintenance.php retention --form=<id> [--apply --confirm=<retention-digest>]');
}
$options = ['apply' => false];
foreach ($arguments as $argument) {
    if ($argument === '--apply') {
        if ($options['apply']) bbf_maintenance_fail('Duplicate --apply option.');
        $options['apply'] = true;
        continue;
    }
    if (!preg_match('/\A--(form|confirm)=(.*)\z/D', $argument, $match)
        || array_key_exists($match[1], $options)) {
        bbf_maintenance_fail('Unknown or duplicate maintenance option.');
    }
    $options[$match[1]] = $match[2];
}
$formId = bbf_auth_id($options['form'] ?? null);
if ($formId === '') bbf_maintenance_fail('A valid --form is required.');
if ($options['apply'] !== array_key_exists('confirm', $options)) {
    bbf_maintenance_fail('--apply and --confirm must be supplied together.');
}
$config = bbf_auth_load_config(__DIR__ . '/config.php');
try {
    $result = $options['apply']
        ? bbf_retention_apply($config, $formId, $options['confirm'])
        : bbf_retention_plan($config, $formId);
} catch (Throwable $error) {
    error_log('BareBonesForms maintenance: ' . $error->getMessage());
    bbf_maintenance_fail('Maintenance operation failed.', 1);
}
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $json . "\n");
exit(($options['apply'] && !($result['ok'] ?? false)) ? 1 : 0);
