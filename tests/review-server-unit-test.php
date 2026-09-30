<?php
/** Server helpers from the 2.1.1 re-review: empty SQLite file, mail() envelope sender, bounded delivery retry scan. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only.');
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_storage.php';
require_once dirname(__DIR__) . '/bbf_outbox.php';
require_once dirname(__DIR__) . '/bbf_read.php';
require_once dirname(__DIR__) . '/bbf_auth.php';
require_once dirname(__DIR__) . '/bbf_functions.php';

$passed = 0; $failed = 0;
function server_check(bool $ok, string $message): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
}

$root = sys_get_temp_dir() . '/bbf-server-unit-' . bin2hex(random_bytes(6));
mkdir($root . '/submissions', 0700, true);

// ─── Zero-byte SQLite file = no submissions ────────────────────────
$sqlite = $root . '/submissions/bbf.sqlite';
touch($sqlite);
$sqliteConfig = ['storage' => 'sqlite', 'submissions_dir' => $root . '/submissions', 'sqlite' => ['path' => $sqlite]];
server_check(bbf_read_db_connect($sqliteConfig) === null, 'a zero-byte SQLite file connects as "no database" for every reader');
server_check(bbf_read_db_connect(['storage' => 'sqlite', 'sqlite' => ['path' => $root . '/missing.sqlite']]) === null,
    'a missing SQLite file behaves the same');
server_check(iterator_to_array(bbf_read_export('contact', $sqliteConfig, PHP_INT_MAX, 0, null, null), false) === [],
    'export of a zero-byte SQLite database is empty, not an error');
server_check(filesize($sqlite) === 0, 'readers never initialize the empty file');
server_check(bbf_read_db_connect($sqliteConfig, true) instanceof PDO, 'writers (review, restore) still open the zero-byte file');
$sqliteConfig = null;

// ─── SQL pages have a total order ─────────────────────────────────
$ordered = $root . '/ordered.sqlite';
$pdo = new (class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : 'PDO')("sqlite:$ordered", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, data TEXT NOT NULL, meta TEXT, created_at TEXT)");
$insert = $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)');
foreach (['bbf_b', 'bbf_d', 'bbf_a', 'bbf_c'] as $id) $insert->execute([$id, 'contact', '{}', '{}', '2026-09-29 10:00:00']);
$insert->execute(['bbf_0', 'contact', '{}', '{}', '2026-09-29 11:00:00']);
$pages = [];
for ($offset = 0; $offset < 5; $offset++) {
    foreach (bbf_read_db($pdo, 'contact', null, null, null, null, 1, $offset) as $row) $pages[] = $row['id'];
}
server_check($pages === ['bbf_0', 'bbf_d', 'bbf_c', 'bbf_b', 'bbf_a'],
    'rows submitted in the same second page newest first, then by id, without repeats or gaps: ' . implode(',', $pages));
$insert = $pdo = null;

// ─── Delivery retry scan reads only ledgers that can still retry ───
$now = time();
$job = ['key' => 'webhook:0', 'type' => 'webhook', 'payload_hash' => hash('sha256', 'x'), 'idempotency_key' => 'k', 'target' => 't', 'idempotent' => true];
$ledgers = ['recent' => $now - 3600, 'fresh' => $now - 30, 'old' => $now - 8 * 86400, 'olddone' => $now - 9 * 86400];
foreach ($ledgers as $id => $mtime) {
    $path = bbf_outbox_path(['submissions_dir' => $root . '/submissions'], 'contact', "bbf_$id");
    server_check(bbf_outbox_init($path, "contact:$id", [$job], 3, $now)['ok'] === true, "ledger $id created");
    if ($id === 'olddone') {
        $stored = json_decode(file_get_contents($path), true);
        foreach ($stored['jobs'] as &$storedJob) $storedJob['state'] = 'succeeded';
        unset($storedJob);
        file_put_contents($path, json_encode($stored));
    }
    touch($path, $mtime);
}
$report = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now);
server_check($report['ok'] === true && $report['checked'] === 1 && $report['attempted'] === 0,
    'retry scan retries only the quiet ledger inside the 7-day horizon (skips in-flight and week-old ledgers)');
server_check($report['skipped_in_flight'] === 1 && $report['skipped_old'] === 1
    && str_contains($report['note'] ?? '', '1 delivery record(s) older than 7 days still have an undelivered delivery'),
    'review 2.1.4: skipped_old counts only the old ledger with an undelivered job, not the delivered one');
$doneOnly = bbf_outbox_path(['submissions_dir' => $root . '/submissions'], 'contact', 'bbf_old');
$oldMtime = filemtime($doneOnly);
$stored = json_decode(file_get_contents($doneOnly), true);
foreach ($stored['jobs'] as &$storedJob) $storedJob['state'] = 'succeeded';
unset($storedJob);
file_put_contents($doneOnly, json_encode($stored));
touch($doneOnly, $oldMtime + 1); // delivered by hand in the viewer: the mtime changes, the cached answer is refreshed
$delivered = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now);
server_check($delivered['skipped_old'] === 0 && !isset($delivered['note']), 'once everything old is delivered, the note goes away');
$cache = json_decode((string)file_get_contents($root . '/submissions/.delivery/.old-ledgers.json'), true);
server_check(is_array($cache) && count($cache) === 2 && $cache['contact/bbf_old.json'] === [$oldMtime + 1, false],
    'old ledgers are remembered by mtime, so they are read once, not on every cron run');
// Review 2.1.5: an unreadable old ledger is counted and read again next run, never cached as delivered.
$damaged = $root . '/submissions/.delivery/contact/bbf_damaged.json';
file_put_contents($damaged, '{"jobs": [truncated');
touch($damaged, $now - 8 * 86400);
for ($run = 1; $run <= 2; $run++) {
    $unreadable = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now);
    $cache = json_decode((string)file_get_contents($root . '/submissions/.delivery/.old-ledgers.json'), true);
    server_check($unreadable['skipped_old'] === 1 && !isset($cache['contact/bbf_damaged.json']), "run $run: an unreadable old ledger counts as undelivered and is not cached");
}
unlink($damaged);
$wide = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now, 30 * 86400);
server_check($wide['checked'] === 3 && $wide['skipped_old'] === 0 && !isset($wide['note']), 'a wider horizon includes the older ledgers');

// ─── mail() envelope sender ───────────────────────────────────────
$source = file_get_contents(dirname(__DIR__) . '/bbf_functions.php');
server_check(str_contains($source, "@mail(\$to, \$mailSubject, \$body, \$headerStr, \$params)"),
    'mail() transport passes the envelope sender parameter');
foreach (['sender@example.com' => true, 'a.b+c@sub.example.org' => true, 'x@y.z -X/tmp/log' => false, "a@b.c\n" => false, '"q"@b.c' => false] as $from => $ok) {
    server_check(bbf_mail_envelope_sender($from, '/usr/sbin/sendmail -t -i') === $ok,
        'envelope sender filter ' . ($ok ? 'accepts ' : 'rejects ') . json_encode($from));
}
foreach (['/usr/sbin/sendmail -t -i -fbounce@host.example' => false, '/usr/sbin/sendmail -t -i -f bounce@host.example' => false,
    '/usr/sbin/sendmail -t -i' => true, '' => true, '/opt/my-fancy-mailer -t' => true] as $path => $ok) {
    server_check(bbf_mail_envelope_sender('sender@example.com', $path) === $ok,
        ($ok ? 'adds -f with sendmail_path ' : 'leaves the host\'s own -f alone: ') . json_encode($path));
}
// The real ini value: a host sendmail_path with -f (php -d) turns the parameter off for both mail() paths.
$probe = static function (string $sendmailPath): string {
    $code = 'define("BBF_LOADED", true); require ' . var_export(dirname(__DIR__) . '/bbf_alerts.php', true)
        . '; echo bbf_mail_envelope_sender("sender@example.com") ? "adds" : "leaves";';
    $process = proc_open([PHP_BINARY, '-d', "sendmail_path=$sendmailPath", '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    return trim((string)$out);
};
server_check($probe('/usr/sbin/sendmail -t -i -fbounce@host.example') === 'leaves' && $probe('/usr/sbin/sendmail -t -i') === 'adds',
    'the rule reads the host\'s sendmail_path setting');
$alertsSource = (string)file_get_contents(dirname(__DIR__) . '/bbf_alerts.php');
server_check(substr_count($alertsSource, 'bbf_mail_envelope_sender($from) ?') === 1 && substr_count($source, 'bbf_mail_envelope_sender($from) ?') === 1,
    'submission mail and incident mail use the same envelope sender rule');

// ─── Editor creates a form atomically and never overwrites ────────
$formsDir = $root . '/forms'; mkdir($formsDir);
$target = $formsDir . '/new.json';
server_check(bbf_create_file_exclusive($target, '{"id":"new"}') === 'ok' && file_get_contents($target) === '{"id":"new"}',
    'a new form file appears with its full content');
server_check(bbf_create_file_exclusive($target, '{"id":"other"}') === 'exists' && file_get_contents($target) === '{"id":"new"}',
    'an existing form is never overwritten');
server_check(glob($formsDir . '/.*.tmp') === [], 'no temp files are left behind');
server_check(bbf_create_file_exclusive($root . '/no-such-dir/x.json', '{}') === 'error', 'an unwritable directory is an error, not a partial file');
$editorSource = file_get_contents(dirname(__DIR__) . '/editor.php');
server_check(str_contains($editorSource, 'bbf_create_file_exclusive($file, $template)') && !str_contains($editorSource, 'file_put_contents($file, $template)'),
    'editor create uses the atomic exclusive writer');

// ─── Submit releases the session lock right after reading the secret ─
$submitSource = file_get_contents(dirname(__DIR__) . '/submit.php');
preg_match('/function ensureSession\(\): void \{.*?\n\}/s', $submitSource, $m);
server_check(isset($m[0]), 'ensureSession() found in submit.php');
if (isset($m[0])) {
    // Fresh process: sessions cannot start once this test has printed output.
    // No cookies in CLI: session_set_cookie_params() then warns, which must not end up in the JSON on stdout.
    $child = 'define("BBF_LOADED", true); require ' . var_export(dirname(__DIR__) . '/bbf_auth.php', true) . ';'
        . ' ini_set("display_errors", "stderr"); ini_set("session.save_path", ' . var_export($root, true) . '); ini_set("session.use_cookies", "0"); ini_set("session.cache_limiter", "");'
        . $m[0] . ' ensureSession(); $secret = $_SESSION["bbf_secret"] ?? ""; $file = ' . var_export($root, true) . ' . "/sess_" . session_id();'
        . ' $lock = fopen($file, "r+"); $free = $lock && flock($lock, LOCK_EX | LOCK_NB); if ($lock) { flock($lock, LOCK_UN); fclose($lock); }'
        . ' $closed = session_status() === PHP_SESSION_NONE; ensureSession();'
        . ' echo json_encode(["free" => $free, "closed" => $closed, "len" => strlen($secret), "same" => ($_SESSION["bbf_secret"] ?? "") === $secret,'
        . ' "stillClosed" => session_status() === PHP_SESSION_NONE, "persisted" => str_contains((string)file_get_contents($file), $secret)]);';
    file_put_contents($root . '/session-child.php', "<?php\n" . $child);
    $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/session-child.php')), true) ?: [];
    server_check(($r['free'] ?? false) && ($r['closed'] ?? false) && ($r['len'] ?? 0) === 64,
        'after ensureSession() the session file is unlocked and the secret stays readable');
    server_check(($r['same'] ?? false) && ($r['stillClosed'] ?? false), 'a second call is a no-op');
    server_check($r['persisted'] ?? false, 'the secret was persisted for the next request');
}

// ─── on_submit.redirect scheme ──────────────────────────────────
foreach (['https://example.test/thanks?n={{name}}' => 'https://example.test/thanks?n=Ann', 'HTTP://example.test/' => 'HTTP://example.test/',
    '/thanks' => '/thanks', 'thanks.html' => 'thanks.html'] as $tpl => $want) {
    server_check(bbf_redirect_url($tpl, ['name' => 'Ann']) === $want, "redirect $tpl is kept");
}
foreach (['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'java' . "\t" . 'script:alert(1)', 'data:text/html,x', 'vbscript:x',
    '\\\\evil.test', '/\\evil.test', ''] as $tpl) {
    server_check(bbf_redirect_url($tpl, ['name' => 'javascript:alert(1)']) === null, 'redirect ' . json_encode($tpl) . ' is dropped');
}
// Field values are percent-encoded: a space no longer drops the redirect, and "&", "#", ":" or "//" cannot
// add parameters, a fragment, a scheme or another host.
server_check(bbf_redirect_url('/dakujeme?meno={{name}}', ['name' => 'Jana Nová']) === '/dakujeme?meno=Jana%20Nov%C3%A1', 'redirect value with a space is encoded, not dropped');
server_check(bbf_redirect_url('/dakujeme?meno={{name}}', ['name' => 'A&admin=1#x']) === '/dakujeme?meno=A%26admin%3D1%23x', 'redirect value cannot add parameters or a fragment');
// 2.1.5: a target that is only a field is no redirect (the success message is shown), not a relative 404 or an open redirect.
foreach (['{{name}}', ' {{ name }} ', '{{return-url}}'] as $tpl) {
    foreach (['javascript:alert(1)', '//evil.test/x', 'https://example.test/back'] as $value) {
        server_check(bbf_redirect_url($tpl, ['name' => $value, 'return-url' => $value]) === null, "whole-URL placeholder $tpl with $value is no redirect");
    }
}
server_check(bbf_redirect_url('{{lang}}/thanks.html', ['lang' => 'sk']) === 'sk/thanks.html', 'a placeholder that is only part of the target still works');
server_check(bbf_redirect_url('/t?n={{n}}', ['n' => 0]) === '/t?n=0', 'numeric zero is kept');
// Checkbox values and missing values: never a literal "{{tags}}" in the URL.
server_check(bbf_redirect_url('/t?tags={{tags}}&n={{name}}', ['tags' => ['a b', 'c&d'], 'name' => 'Ann']) === '/t?tags=a%20b%2Cc%26d&n=Ann', 'checkbox values are joined and encoded');
server_check(bbf_redirect_url('/t?tags={{tags}}&x={{missing}}', ['tags' => []]) === '/t?tags=&x=', 'an empty checkbox or a missing field becomes empty');
server_check(bbf_redirect_url('/t?ok={{ok}}', ['ok' => true]) === '/t?ok=1', 'a boolean becomes 1');
server_check(bbf_redirect_url('/thanks?ref={{_id}}&f={{_form}}', ['_id' => 'spoofed'], ['_id' => 'bbf_0a1b', '_form' => 'Kontakt & more']) === '/thanks?ref=bbf_0a1b&f=Kontakt%20%26%20more',
    'review 2.1.5: {{_id}} and {{_form}} fill in the redirect and win over a field of that name');
server_check(bbf_redirect_url('/t?a={{7}}&y={{2024}}&r={{_id}}', ['7' => 'seven', '2024' => 'year'], ['_id' => 'bbf_1']) === '/t?a=seven&y=year&r=bbf_1',
    'review 2.1.6: a field with a numeric name ("7", "2024") still fills the redirect');

// ─── Review 2.1.6: cookies behind a reverse proxy ───────────────────
$serverBefore = $_SERVER;
$_SERVER['SCRIPT_NAME'] = '/bbf/submit.php';
server_check(bbf_cookie_path([]) === '/bbf/' && bbf_cookie_path(['cookie_path' => '/forms']) === '/forms/'
    && bbf_cookie_path(['cookie_path' => '/forms/']) === '/forms/', 'cookie_path overrides the folder PHP sees (proxy maps /forms/ to /bbf/)');
server_check(bbf_cookie_path(['cookie_path' => 'forms/']) === '/bbf/' && bbf_cookie_path(['cookie_path' => "/x;\n"]) === '/bbf/'
    && bbf_cookie_path(['cookie_path' => ['/x']]) === '/bbf/', 'an invalid cookie_path falls back to the installation folder');
unset($_SERVER['HTTPS']);
$_SERVER['REMOTE_ADDR'] = '10.0.0.5'; $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
server_check(bbf_request_https(['trusted_proxies' => ['10.0.0.0/8']]), 'X-Forwarded-Proto: https from a trusted proxy counts as HTTPS (Secure, SameSite=None cookie)');
server_check(!bbf_request_https([]) && !bbf_request_https(['trusted_proxies' => ['192.0.2.0/24']]),
    'the header from an address that is not a trusted proxy is ignored');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
server_check(!bbf_request_https(['trusted_proxies' => ['10.0.0.0/8']]), 'X-Forwarded-Proto: http stays plain HTTP');
$_SERVER['HTTPS'] = 'on';
server_check(bbf_request_https([]), 'direct HTTPS is HTTPS');
$_SERVER = $serverBefore;
$submitSource = (string)file_get_contents(dirname(__DIR__) . '/submit.php');
server_check(preg_match_all('/bbf_redirect_url\(\$onSubmit\[\'redirect\'\], \$data\)/', $submitSource) === 0, 'every redirect in submit.php passes the template variables');

// Cleanup.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
rmdir($root);

echo "Server helpers: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
