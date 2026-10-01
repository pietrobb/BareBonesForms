<?php
/** Upgrade an installation with this unpacked release. Works from any older version, also before 2.1.0.
 * php barebonesforms/tools/upgrade.php --install=/path/to/bbf [--apply --confirm=<digest>]
 * Same checks, backup and rollback as `php maintenance.php upgrade` (see bbf_upgrade.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$options = getopt('', ['install:', 'apply', 'confirm:']);
$install = isset($options['install']) ? realpath((string)$options['install']) : false;
if ($install === false || !is_file($install . '/config.php')) {
    fwrite(STDERR, "Required: --install=<installation folder with config.php> [--apply --confirm=<digest>]\n");
    exit(2);
}
if (isset($options['apply']) !== isset($options['confirm'])) {
    fwrite(STDERR, "--apply and --confirm must be supplied together.\n");
    exit(2);
}
$package = dirname(__DIR__);
if (realpath($package) === $install) {
    fwrite(STDERR, "Run this from the unpacked new release. Inside the installation use: php maintenance.php upgrade --package=<release.zip>\n");
    exit(2);
}
define('BBF_LOADED', true);
require_once $package . '/bbf_upgrade.php';
try {
    $config = (static fn() => require $install . '/config.php')();
    if (!is_array($config)) throw new RuntimeException("Cannot read $install/config.php.");
    $result = bbf_upgrade($config, $package, isset($options['apply']) ? (string)$options['confirm'] : null, $install);
} catch (Throwable $error) {
    fwrite(STDERR, 'Upgrade failed: ' . $error->getMessage() . "\n");
    exit(1);
}
if (!isset($options['apply']) && isset($result['confirm']) && (($result['ok'] ?? false) || (($result['access_checked'] ?? false) && !empty($result['access_blocked']))) && !($result['up_to_date'] ?? false)) {
    $result['next'] = bbf_upgrade_apply_command([$argv[0], '--install=' . $install, '--apply', '--confirm=' . $result['confirm']]);
}
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
exit(bbf_upgrade_exit_code($result));
