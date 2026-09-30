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
$good = ['api_token' => '9f2c7a41e0b83d56c4a1f7e2', 'access_tokens' => [$token('reader', 'rd-5Kq8zW2mXv7Lp3')]];
auth_check(bbf_auth_config_problems($good) === [], 'a valid configuration reports nothing');

// ─── Review 2.1.5: guessable patterns are no credential ────────────
foreach ([str_repeat('a', 32), '1234567890123456', 'abcdefghijklmnopqrstuvwxyz', '9876543210987654', 'abababababababababab', 'abcabcabcabcabcabc', 'aaaabbbbccccdddd'] as $weak)
    auth_check(bbf_auth_token_weak($weak) && !bbf_auth_token_usable($weak), "weak token rejected: $weak");
foreach (['9f2c7a41e0b83d56', 'rd-5Kq8zW2mXv7Lp3', 'smoke-token-long-0123', 'Tr0ub4dor&3-horse-battery'] as $strong)
    auth_check(!bbf_auth_token_weak($strong), "random-looking token accepted: $strong");
$falsePositives = 0;
for ($i = 0; $i < 20000; $i++) if (bbf_auth_token_weak(bin2hex(random_bytes(8)))) $falsePositives++;
auth_check($falsePositives <= 1, "random 16-hex-digit tokens are practically never flagged ($falsePositives of 20000)");
$weakAdmin = ['api_token' => '1234567890123456'] + $good;
$wm = implode(' ', array_column(bbf_auth_config_problems($weakAdmin), 'message'));
auth_check(str_contains($wm, 'guessable pattern') && !str_contains($wm, '1234567890123456') && count(bbf_auth_registry($weakAdmin)) === 1,
    'a patterned api_token is reported (without its value) and ignored alone');
$weakRecord = $good; $weakRecord['access_tokens'][] = $token('seq', 'abcdefghijklmnop');
auth_check(count(bbf_auth_registry($weakRecord)) === 2 && str_contains(implode(' ', array_column(bbf_auth_config_problems($weakRecord), 'message')), 'seq'),
    'a patterned access token is skipped and named by id');
auth_check(bbf_smoke_token(['smoke_token' => 'aaaaaaaaaaaaaaaaaaaa']) === null, 'a patterned smoke_token is not a credential');
$short = $good; $short['access_tokens'][] = $token('tiny', 'fourteen-chars');
$msgs = implode(' | ', array_column(bbf_auth_config_problems($short), 'message'));
auth_check(str_contains($msgs, 'tiny') && !str_contains($msgs, 'fourteen-chars'), 'a short access token is named by id, never by value');
auth_check(count(bbf_auth_registry($short)) === 2, 'the short token is skipped; api_token and the other token stay');
$shortAdmin = ['api_token' => 'short'] + $good;
auth_check(str_contains(implode(' ', array_column(bbf_auth_config_problems($shortAdmin), 'message')), 'api_token is shorter')
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
    $rc = $ac + ['api_token' => str_repeat('k', 24), 'access_tokens' => [$token('tiny', 'co')], 'smoke_token' => 'nt'];
    bbf_audit_write($rc, $principal, 'viewer_list', 'contact', ['smoke-token-long-0123-1', 'kkkkkkkkkkkkkkkkkkkkkkkk'], 'allowed', 'completed', 1);
    $last = json_decode(trim(array_slice(explode("\n", trim((string)file_get_contents($audit))), -1)[0]), true);
    auth_check(($last['form'] ?? '') === 'contact', 'a 2-character access token or smoke_token ("nt") does not mangle "contact" in the audit log');
    auth_check(($last['submission_ids'] ?? []) === ['smoke-token-long-0123-1', '[redacted]'], 'api_token is still redacted');
    $rc['smoke_token'] = 'smoke-token-long-0123';
    bbf_audit_write($rc, $principal, 'viewer_list', 'contact', ['smoke-token-long-0123-1'], 'allowed', 'completed', 1);
    $last = json_decode(trim(array_slice(explode("\n", trim((string)file_get_contents($audit))), -1)[0]), true);
    auth_check(($last['submission_ids'] ?? []) === ['[redacted]-1'], 'a usable smoke_token (16+ characters) is redacted');
    auth_check(bbf_smoke_token(['smoke_token' => 'nt']) === null && bbf_smoke_token(['smoke_token' => 'smoke-token-long-0123']) === 'smoke-token-long-0123'
        && bbf_smoke_token(['smoke_token' => str_repeat('a', 15) . ' ']) === null, 'a smoke_token shorter than 16 printable characters is not a credential');
    auth_check(str_contains(implode(' ', array_column(bbf_auth_config_problems(['api_token' => str_repeat('k', 24), 'smoke_token' => 'short']), 'message')), 'smoke_token'),
        'check.php/selfcheck warn about an unusable smoke_token');
} finally {
    foreach (["$logs/access-audit.php", "$logs/access-audit.php.1", "$logs/access-audit.1.php"] as $f) @unlink($f);
    @unlink("$logs/.auth_failures.json");
    @rmdir($logs);
}

echo "\nAuth unit: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
