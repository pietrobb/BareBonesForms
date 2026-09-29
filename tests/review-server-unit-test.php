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
$ledgers = ['recent' => $now - 3600, 'fresh' => $now - 30, 'old' => $now - 8 * 86400];
foreach ($ledgers as $id => $mtime) {
    $path = bbf_outbox_path(['submissions_dir' => $root . '/submissions'], 'contact', "bbf_$id");
    server_check(bbf_outbox_init($path, "contact:$id", [$job], 3, $now)['ok'] === true, "ledger $id created");
    touch($path, $mtime);
}
$report = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now);
server_check($report['ok'] === true && $report['checked'] === 1 && $report['attempted'] === 0,
    'retry scan reads only the quiet ledger inside the 7-day horizon (skips in-flight and week-old ledgers by mtime)');
$wide = bbf_delivery_retry_due(['submissions_dir' => $root . '/submissions', 'storage' => 'file'], 120, $now, 30 * 86400);
server_check($wide['checked'] === 2, 'a wider horizon includes the older ledger');

// ─── mail() envelope sender ───────────────────────────────────────
$source = file_get_contents(dirname(__DIR__) . '/bbf_functions.php');
server_check(str_contains($source, "@mail(\$to, \$mailSubject, \$body, \$headerStr, \$params)"),
    'mail() transport passes the envelope sender parameter');
foreach (['sender@example.com' => true, 'a.b+c@sub.example.org' => true, 'x@y.z -X/tmp/log' => false, "a@b.c\n" => false, '"q"@b.c' => false] as $from => $ok) {
    server_check(bbf_mail_envelope_sender($from) === $ok,
        'envelope sender filter ' . ($ok ? 'accepts ' : 'rejects ') . json_encode($from));
}

// Cleanup.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
rmdir($root);

echo "Server helpers: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
