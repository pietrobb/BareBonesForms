<?php
/** Pure access helpers: client address behind proxies, trusted_proxies validation, config problems, throttle bounds. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only.');
define('BBF_LOADED', true);
require dirname(__DIR__) . '/bbf_auth.php';

$passed = 0; $failed = 0;
function auth_check(bool $ok, string $message): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
}
function auth_ip(array $config, string $remote, ?string $xff = null): string {
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === null) unset($_SERVER['HTTP_X_FORWARDED_FOR']); else $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    return bbf_client_ip($config);
}

// ─── Client address ────────────────────────────────────────────────
auth_check(auth_ip([], '203.0.113.9', '1.1.1.1') === '203.0.113.9', 'without trusted_proxies X-Forwarded-For is ignored');
$cf = ['trusted_proxies' => ['10.0.0.0/8', '2400:cb00::/32']];
auth_check(auth_ip($cf, '10.1.2.3', '198.51.100.7') === '198.51.100.7', 'a trusted proxy passes on the visitor address');
auth_check(auth_ip($cf, '10.1.2.3', '198.51.100.7, 10.9.9.9') === '198.51.100.7', 'the chain is read right to left past trusted hops');
auth_check(auth_ip($cf, '203.0.113.9', '198.51.100.7') === '203.0.113.9', 'an untrusted sender cannot choose its address');
auth_check(auth_ip($cf, '10.1.2.3', '198.51.100.7:51234') === '198.51.100.7', 'X-Forwarded-For with a port is understood');
auth_check(auth_ip($cf, '10.1.2.3', '[2001:db8::5]:443') === '2001:db8::5', 'bracketed IPv6 with a port is understood');
auth_check(auth_ip($cf, '::ffff:10.1.2.3', '198.51.100.7') === '198.51.100.7', 'an IPv4-mapped REMOTE_ADDR matches an IPv4 range');
auth_check(auth_ip($cf, '10.1.2.3', '::ffff:198.51.100.7') === '198.51.100.7', 'an IPv4-mapped visitor address is reported as IPv4');
auth_check(auth_ip(['trusted_proxies' => ['::ffff:10.0.0.0/104']], '10.1.2.3', '198.51.100.7') === '198.51.100.7', 'an IPv4-mapped range covers IPv4 proxies');

// ─── A typo in trusted_proxies must never trust everyone ───────────
foreach (['10.0.0.0/', '10.0.0.0/abc', '10.0.0.0/33', '10.0.0.0/-1', '10.0.0.0/ 8', 'not-an-ip', '::/129'] as $bad) {
    auth_check(!bbf_proxy_entry_valid($bad), "invalid trusted_proxies entry rejected: \"$bad\"");
    $c = ['trusted_proxies' => [$bad]];
    auth_check(auth_ip($c, '203.0.113.9', '1.2.3.4') === '203.0.113.9' && auth_ip($c, '203.0.113.9', '2001:db8::1') === '203.0.113.9',
        "\"$bad\" does not let an attacker spoof X-Forwarded-For (IPv4 or IPv6)");
}
foreach (['10.0.0.0/8', '10.1.2.3', '0.0.0.0/0', '2400:cb00::/32', '::1'] as $good) auth_check(bbf_proxy_entry_valid($good), "valid entry accepted: $good");
$mixed = ['trusted_proxies' => ['10.0.0.0/', '192.0.2.0/24']];
auth_check(auth_ip($mixed, '192.0.2.10', '198.51.100.7') === '198.51.100.7' && auth_ip($mixed, '10.0.0.1', '198.51.100.7') === '10.0.0.1',
    'valid entries keep working next to an invalid one');

// ─── Configuration problems ────────────────────────────────────────
$token = static fn(string $id, string $secret): array => ['id' => $id, 'token' => $secret, 'forms' => ['a'], 'permissions' => ['read'],
    'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
$good = ['api_token' => '9f2c7a41e0b83d56c4a1f7e29a063bc58', 'access_tokens' => [$token('reader', '14baf8b571660fbb65f247c4077272371fff1ebd9a16c541ebb1f6b583476bfd')]];
auth_check(bbf_auth_config_problems($good) === [], 'a valid configuration reports nothing');

// ─── Review 2.1.8: deterministic format, not an entropy estimate ───
foreach (['1qaz2wsx3edc4rfv', 'manchesterunited', 'correct-horse-battery-staple', 'YOUR_TOKEN_HERE_123',
    'pass1word2secret3', 'Bratislava-Petržalka'] as $password)
    auth_check(!bbf_auth_token_usable($password), "human review password rejected: $password");
foreach (['', str_repeat('a', 31), str_repeat('a', 32) . 'g', ' ' . str_repeat('a', 32), str_repeat('a', 32) . "\n",
    'YOUR_SECRET_TOKEN', 'your-secret-token', null, 1234, []] as $invalid)
    auth_check(!bbf_auth_token_usable($invalid), 'invalid credential type, length or non-hex characters rejected');
foreach ([str_repeat('a', 32), str_repeat('0', 64), str_repeat('aB09', 8), str_repeat('F', 33), str_repeat('d', 4096)] as $hex)
    auth_check(bbf_auth_token_usable($hex), 'valid hex format accepted without claiming randomness');
foreach ([16, 32] as $bytes) {
    $allAccepted = true;
    for ($i = 0; $i < 1000; $i++) {
        $hex = bin2hex(random_bytes($bytes));
        $allAccepted = $allAccepted && bbf_auth_token_usable($hex) && bbf_auth_token_usable(strtoupper($hex));
    }
    auth_check($allAccepted, 'random ' . ($bytes * 2) . '-character hex tokens accepted in either case');
}
function auth_cli(array $args): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/maintenance.php', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start maintenance command');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}
[$code, $generated, $errors] = auth_cli(['new-token']);
[$code2, $generated2, $errors2] = auth_cli(['new-token']);
auth_check($code === 0 && $code2 === 0 && $errors === '' && $errors2 === ''
    && preg_match('/\A[0-9a-f]{64}\r?\n\z/', $generated) === 1 && $generated !== $generated2
    && bbf_auth_token_usable(trim($generated)), 'official CLI emits only a fresh 64-character hex token and newline');
[$code, $help] = auth_cli(['help']);
auth_check($code === 0 && str_contains($help, 'new-token') && str_contains($help, 'bin2hex(random_bytes(32))')
    && str_contains($help, 'format does not prove randomness'), 'help documents generator, format and its limits');
foreach (['--apply', '--form=a', '--trust-package'] as $option) {
    [$code, $stdout] = auth_cli(['new-token', $option]);
    auth_check($code !== 0 && $stdout === '', 'generator rejects unsupported option ' . $option);
}
$invalidAdmin = ['api_token' => str_repeat('g', 32)] + $good;
$messages = implode(' ', array_column(bbf_auth_config_problems($invalidAdmin), 'message'));
auth_check(str_contains($messages, 'only hexadecimal') && str_contains($messages, 'new-token')
    && !str_contains($messages, $invalidAdmin['api_token']) && count(bbf_auth_registry($invalidAdmin)) === 1,
    'invalid api_token format is precisely reported without its value and ignored alone');
$sampleAdmin = ['api_token' => 'your-secret-token'] + $good;
auth_check(count(bbf_auth_registry($sampleAdmin)) === 1, 'old documentation sample is not a credential');
$invalidRecord = $good; $invalidRecord['access_tokens'][] = $token('nonhex', str_repeat('z', 32));
auth_check(count(bbf_auth_registry($invalidRecord)) === 2
    && str_contains(implode(' ', array_column(bbf_auth_config_problems($invalidRecord), 'message')), 'nonhex'),
    'invalid scoped token format is skipped and identified without disabling valid credentials');
auth_check(bbf_smoke_token(['smoke_token' => str_repeat('z', 32)]) === null, 'non-hex smoke token is ignored');
$_SERVER['SCRIPT_NAME'] = '/bbf/viewer.php';
foreach (['', '/', '/forms', '/forms/', '/forms-v2.1/'] as $path) {
    auth_check(bbf_cookie_path_valid(['cookie_path' => $path])
        && bbf_auth_config_problems(['cookie_path' => $path] + $good) === [], 'valid cookie_path accepted: ' . $path);
}
auth_check(bbf_cookie_path([]) === '/bbf/' && bbf_cookie_path(['cookie_path' => '/proxy/forms']) === '/proxy/forms/',
    'default cookie path and explicit proxy path resolve correctly');
foreach ([null, [], 42, 'relative/', '/bad path/', '/bad;path', '/bad?path', '/bad#path', "/bad\npath"] as $path) {
    $problems = bbf_auth_config_problems(['cookie_path' => $path] + $good);
    auth_check(!bbf_cookie_path_valid(['cookie_path' => $path]) && count($problems) === 1
        && $problems[0]['level'] === 'error' && str_contains($problems[0]['message'], 'cookie_path')
        && bbf_cookie_path(['cookie_path' => $path]) === '/bbf/', 'invalid cookie_path is reported, not silently ignored');
}
$short = $good; $short['access_tokens'][] = $token('tiny', 'fourteen-chars');
$msgs = implode(' | ', array_column(bbf_auth_config_problems($short), 'message'));
auth_check(str_contains($msgs, 'tiny') && !str_contains($msgs, 'fourteen-chars'), 'a short access token is named by id, never by value');
auth_check(count(bbf_auth_registry($short)) === 2, 'the short token is skipped; api_token and the other token stay');
$shortAdmin = ['api_token' => 'short'] + $good;
auth_check(str_contains(implode(' ', array_column(bbf_auth_config_problems($shortAdmin), 'message')), 'api_token must contain only hexadecimal')
    && count(bbf_auth_registry($shortAdmin)) === 1, 'a short api_token is reported and ignored alone');
$broken = $good; $broken['access_tokens'][] = ['id' => 'x'];
$b = bbf_auth_config_problems($broken);
auth_check(($b[0]['level'] ?? '') === 'error' && str_contains($b[0]['message'], 'ALL tokens') && bbf_auth_registry($broken) === [], 'a malformed record is reported as disabling all access');
auth_check(str_contains(implode(' ', array_column(bbf_auth_config_problems(['api_token' => '']), 'message')), 'No usable access token'), 'no token at all is reported');
$px = bbf_auth_config_problems(['trusted_proxies' => ['10.0.0.0/', '192.0.2.0/24']] + $good);
auth_check(count($px) === 1 && str_contains($px[0]['message'], '"10.0.0.0/"') && !str_contains($px[0]['message'], '192.0.2.0'), 'invalid trusted_proxies entries are reported');

require_once dirname(__DIR__) . '/bbf_backup.php';
try {
    $policy = bbf_backup_access_policy(['api_token' => 'short'] + $short, 'a');
    auth_check($policy['legacy_admin'] === false && array_column($policy['principals'], 'id') === ['reader'], 'backup works with a short api_token or short access token; they are not part of the policy');
} catch (Throwable $error) {
    auth_check(false, 'backup with a short token: ' . $error->getMessage());
}
// Review 2.1.6: a bundle made by 2.1.6 with the documented sample token names it, instead of a bare policy mismatch.
$sample = ['api_token' => 'your-secret-token', 'access_tokens' => []];
$oldBundle = ['legacy_admin' => true, 'principals' => []];
auth_check(bbf_backup_access_policy($sample, 'a') !== $oldBundle && bbf_backup_newly_weak($sample, 'a', $oldBundle) === ['api_token'],
    'a 2.1.6 bundle made with a now-rejected sample api_token is explained by name');
auth_check(bbf_backup_newly_weak($sample, 'a', ['legacy_admin' => false, 'principals' => [['id' => 'x']]]) === [],
    'an unrelated policy mismatch is not blamed on the weak token');
auth_check(bbf_backup_newly_weak(['api_token' => bin2hex(random_bytes(16))], 'a', $oldBundle) === [], 'a strong token is never blamed');

// ─── Throttle file stays bounded ───────────────────────────────────
$logs = sys_get_temp_dir() . '/bbf-auth-unit-' . bin2hex(random_bytes(4));
mkdir($logs, 0700);
try {
    $c = ['logs_dir' => $logs];
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:1::1';
    for ($i = 0; $i < 10; $i++) { $_SERVER['REMOTE_ADDR'] = "2001:db8:1:1::" . dechex($i + 1); bbf_auth_throttle($c, true); }
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:1::ffff';
    auth_check(bbf_auth_throttle($c, true) === false, 'rotating addresses inside one IPv6 /64 counts as one client');
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
    auth_check(bbf_auth_throttle($c, true) === true, 'a different /64 is a different client');
    // ─── Retry-After of a blocked client: until its oldest recorded failure leaves the window ───
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:1::1';
    $retry = bbf_auth_retry_after($c);
    auth_check($retry > BBF_AUTH_FAILURE_WINDOW - 30 && $retry <= BBF_AUTH_FAILURE_WINDOW, "a blocked client is told when wrong tokens are counted again ($retry s)");

    // A 2.1.2 file (a bare failures map) and a 2.1.3–2.1.5 file with 'slots' are still read; slots are dropped.
    file_put_contents("$logs/.auth_failures.json", json_encode([hash('sha256', '198.51.100.5') => array_fill(0, 10, time() - 5)]));
    $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
    auth_check(bbf_auth_throttle($c, true) === false, 'the 2.1.2 failure file format is still honoured');
    file_put_contents("$logs/.auth_failures.json", json_encode(['failures' => [hash('sha256', '198.51.100.6') => array_fill(0, 10, time() - 5)],
        'slots' => [hash('sha256', '198.51.100.6') => microtime(true) + 2]]));
    $_SERVER['REMOTE_ADDR'] = '198.51.100.6';
    auth_check(bbf_auth_throttle($c, true) === false && !array_key_exists('slots', json_decode((string)file_get_contents("$logs/.auth_failures.json"), true)),
        'a 2.1.5 failure file is honoured and its slots are dropped');

    $state = [];
    for ($i = 0; $i < BBF_AUTH_MAX_CLIENTS + 50; $i++) $state[hash('sha256', "c$i")] = [time() - 5];
    file_put_contents("$logs/.auth_failures.json", json_encode($state));
    $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
    bbf_auth_throttle($c, true);
    $after = json_decode((string)file_get_contents("$logs/.auth_failures.json"), true)['failures'] ?? [];
    auth_check(count($after) === BBF_AUTH_MAX_CLIENTS && isset($after[hash('sha256', '198.51.100.99')]), 'the failure file is capped and keeps the newest client');

    // ─── Audit log stays bounded ───────────────────────────────────
    $ac = ['logs_dir' => $logs, 'audit_max_bytes' => 65536];
    $audit = "$logs/access-audit.php";
    $guard = "<?php http_response_code(404); exit; ?>\n";
    $principal = ['id' => 'admin', 'admin' => true];
    for ($i = 0; $i < 600; $i++) bbf_audit_write($ac, $principal, 'viewer_list', 'contact', [], 'allowed', 'completed', $i);
    $size = filesize($audit); clearstatcache();
    auth_check(filesize($audit) <= 65536 + 512, "the audit log stays under its limit ($size bytes after 600 entries)");
    auth_check(is_file("$logs/access-audit.1.php") && !file_exists("$audit.1") && str_starts_with((string)file_get_contents("$logs/access-audit.1.php"), $guard)
        && str_starts_with((string)file_get_contents($audit), $guard), 'the previous generation is access-audit.1.php (.php, guarded) and both files keep the PHP guard');
    $last = json_decode(trim(array_slice(explode("\n", trim((string)file_get_contents($audit))), -1)[0]), true);
    auth_check(($last['result_count'] ?? null) === 599, 'the newest entry is in the current log');
    auth_check(glob("$logs/*.tmp") === [], 'rotation leaves no temp files');
    // A 2.1.2 install left access-audit.php.1 (protected only by .htaccess); the next write retires it.
    @unlink("$logs/access-audit.1.php");
    file_put_contents("$audit.1", $guard . "{\"legacy\":true}\n");
    bbf_audit_write($ac, $principal, 'viewer_list', 'contact', [], 'allowed', 'completed', 1);
    auth_check(!file_exists("$audit.1") && str_contains((string)@file_get_contents("$logs/access-audit.1.php"), 'legacy'), 'a 2.1.2 access-audit.php.1 is renamed to access-audit.1.php');
    file_put_contents("$audit.1", $guard);
    bbf_audit_write($ac, $principal, 'viewer_list', 'contact', [], 'allowed', 'completed', 1);
    auth_check(!file_exists("$audit.1") && str_contains((string)file_get_contents("$logs/access-audit.1.php"), 'legacy'), 'when access-audit.1.php exists the old file is removed, never overwriting it');

    // ─── Audit redaction ignores tokens too short to be credentials ───
    $rc = $ac + ['api_token' => str_repeat('b', 32), 'access_tokens' => [$token('tiny', 'co')], 'smoke_token' => 'nt'];
    bbf_audit_write($rc, $principal, 'viewer_list', 'contact', ['3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3-1', str_repeat('b', 32)], 'allowed', 'completed', 1);
    $last = json_decode(trim(array_slice(explode("\n", trim((string)file_get_contents($audit))), -1)[0]), true);
    auth_check(($last['form'] ?? '') === 'contact', 'a 2-character access token or smoke_token ("nt") does not mangle "contact" in the audit log');
    auth_check(($last['submission_ids'] ?? []) === ['3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3-1', '[redacted]'], 'api_token is still redacted');
    $rc['smoke_token'] = '3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3';
    bbf_audit_write($rc, $principal, 'viewer_list', 'contact', ['3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3-1'], 'allowed', 'completed', 1);
    $last = json_decode(trim(array_slice(explode("\n", trim((string)file_get_contents($audit))), -1)[0]), true);
    auth_check(($last['submission_ids'] ?? []) === ['[redacted]-1'], 'a usable hex smoke_token is redacted');
    auth_check(bbf_smoke_token(['smoke_token' => 'nt']) === null && bbf_smoke_token(['smoke_token' => '3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3']) === '3428410c91a964b91559e45e32b5e3b09e60b8505710cb7bdf53c31948d262b3'
        && bbf_smoke_token(['smoke_token' => str_repeat('a', 15) . ' ']) === null, 'smoke tokens use the same minimum hexadecimal format');
    auth_check(str_contains(implode(' ', array_column(bbf_auth_config_problems(['api_token' => str_repeat('b', 32), 'smoke_token' => 'short']), 'message')), 'smoke_token'),
        'check.php/selfcheck warn about an unusable smoke_token');
} finally {
    foreach (["$logs/access-audit.php", "$logs/access-audit.php.1", "$logs/access-audit.1.php"] as $f) @unlink($f);
    @unlink("$logs/.auth_failures.json");
    @rmdir($logs);
}

echo "\nAuth unit: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
