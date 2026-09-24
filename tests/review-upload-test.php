<?php
/** File uploads (docs/FILE-UPLOAD-DESIGN.md §14). CLI-only, disposable installation, private uploads directory. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
if (getenv('BBF_TEST_LEASE') || getenv('BBF_TEST_LEASE_KEY')) throw new RuntimeException('Requires a fresh owned fixture.');
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_uploads.php';

$checks = 0;
$failures = 0;
function up_check(bool $ok, string $label): void {
    ++$GLOBALS['checks'];
    if ($ok) { print "PASS $label\n"; return; }
    ++$GLOBALS['failures'];
    print "FAIL $label\n";
}

function up_config(string $root, string $uploads, array $overrides = [], array $uploadOverrides = []): void {
    $config = array_replace([
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'templates_dir' => "$root/templates", 'csrf' => false,
        'sandbox' => false, 'honeypot_field' => '_hp', 'rate_limit' => 100000, 'lang' => 'en',
        'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
        'mail' => ['method' => 'mail', 'from_email' => 'fixture@example.test', 'from_name' => 'Fixture'],
        'webhook_secret' => '', 'delivery' => ['max_attempts' => 3, 'lease_seconds' => 1, 'retry_delay' => 30],
        'submit_transaction_timeout' => 10, 'submit_replay_rate' => 1000, 'submit_recovery_budget' => 0,
        'sqlite' => ['path' => "$root/submissions/bbf.sqlite"],
        'uploads' => array_replace(['enabled' => true, 'dir' => $uploads, 'rate_limit' => ['max' => 100000, 'window' => 600]], $uploadOverrides),
    ], $overrides);
    $php = '<?php defined("BBF_LOADED") || exit; $root = ' . var_export($root, true) . ';'
        . '$GLOBALS["_bbf_tx_hook"] = static function (string $point) use ($root) {'
        . '  $file = "$root/fault.json"; if (!is_file($file)) return true;'
        . '  $fault = json_decode(file_get_contents($file), true); if (!is_array($fault) || $fault["point"] !== $point) return true;'
        . '  if (($fault["skip"] ?? 0) > 0) { $fault["skip"]--; file_put_contents($file, json_encode($fault)); return true; }'
        . '  if ($fault["once"] ?? true) @unlink($file);'
        . '  if ($fault["action"] === "exit") exit;'
        . '  return false; };'
        . 'return ' . var_export($config, true) . ';';
    if (file_put_contents("$root/config.php", $php) === false) throw new RuntimeException('Cannot write fixture config.');
}

function up_form(string $root, string $id, array $extra = [], ?array $fields = null): void {
    $form = array_replace(['id' => $id, 'name' => "Form $id", 'fields' => $fields ?? [
        ['name' => 'answer', 'type' => 'text', 'label' => 'Answer'],
        ['name' => 'cv', 'type' => 'file', 'label' => 'CV', 'required' => true, 'accept' => ['.pdf', '.png', '.txt', '.docx', '.odt'], 'max_files' => 2],
    ]], $extra);
    file_put_contents("$root/forms/$id.json", json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function up_url(array $server, string $query): string {
    return 'http://127.0.0.1:' . $server['port'] . '/submit.php?' . $query;
}

function up_multipart(array $parts): array {
    $boundary = '----bbf' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($parts as [$name, $filename, $content]) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\""
            . ($filename !== null ? "; filename=\"$filename\"\r\nContent-Type: application/octet-stream" : '') . "\r\n\r\n$content\r\n";
    }
    return [$body . "--$boundary--\r\n", "multipart/form-data; boundary=$boundary"];
}

function up_upload(array $server, string $form, string $field, string $filename, string $content, array $headers = [], ?array $parts = null): array {
    [$body, $type] = up_multipart($parts ?? [['file', $filename, $content]]);
    return bbf_test_http($server, up_url($server, "form=$form&action=upload&field=$field"), null,
        ['raw' => $body, 'method' => 'POST', 'headers' => ['Content-Type' => $type] + $headers, 'timeout' => 60]);
}

function up_submit(array $server, string $form, array $body, ?string $key): array {
    if ($key !== null) $body['_bbf_submit_key'] = $key;
    return bbf_test_http($server, up_url($server, "form=$form"), null,
        ['raw' => json_encode($body), 'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 60]);
}

function up_delete(array $server, string $form, string $token): array {
    return bbf_test_http($server, up_url($server, "form=$form&action=upload_delete"), null,
        ['raw' => json_encode(['token' => $token]), 'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 60]);
}

function up_fault(string $root, string $point, string $action = 'exit', int $skip = 0, bool $once = true): void {
    file_put_contents("$root/fault.json", json_encode(['point' => $point, 'action' => $action, 'skip' => $skip, 'once' => $once]));
}

function up_clear(string $root): void { @unlink("$root/fault.json"); }

function up_ledger(string $data): array {
    $l = json_decode((string)@file_get_contents("$data/.quota.json"), true);
    return is_array($l) ? $l : [];
}

/** Staging (bytes, entries), stored (bytes, files) from the ledger and from disk. */
function up_usage(string $data): array {
    $l = up_ledger($data);
    $staging = [0, 0];
    foreach (glob("$data/staging/*") ?: [] as $f) {
        if (preg_match('/\A[a-f0-9]{64}\z/', basename($f))) { $staging[0] += filesize($f); $staging[1]++; }
    }
    $stored = [0, 0];
    foreach (glob("$data/*/bbf_*/f_*") ?: [] as $f) { $stored[0] += filesize($f); $stored[1]++; }
    return ['ledger' => [[(int)($l['staging']['bytes'] ?? -1), (int)($l['staging']['entries'] ?? -1)],
        [(int)($l['stored']['bytes'] ?? -1), (int)($l['stored']['files'] ?? -1)]], 'disk' => [$staging, $stored],
        'wal' => count($l['wal'] ?? []), 'reservations' => count($l['reservations'] ?? [])];
}

function up_exact(string $data): bool {
    $u = up_usage($data);
    return $u['ledger'] === $u['disk'] && $u['wal'] === 0;
}

function up_records(string $root, string $form): array {
    $out = [];
    foreach (glob("$root/submissions/$form/bbf_*.json") ?: [] as $f) $out[] = json_decode(file_get_contents($f), true);
    return $out;
}

function up_intent(string $root, string $form, string $key): ?array {
    $path = "$root/submissions/.submit/$form/" . hash('sha256', $key) . '.json';
    return is_file($path) ? json_decode(file_get_contents($path), true) : null;
}

function up_maintenance(string $root, string $command): array {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/maintenance.php") . " $command 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

/** A minimal ZIP writer (stored or deflated entries) for OOXML/ODF fixtures without ZipArchive. */
function up_zip(array $entries): string {
    $data = '';
    $central = '';
    foreach ($entries as $name => [$content, $deflate]) {
        $raw = $deflate ? gzdeflate($content) : $content;
        $method = $deflate ? 8 : 0;
        $crc = crc32($content);
        $offset = strlen($data);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0x21, $crc, strlen($raw), strlen($content), strlen($name), 0) . $name . $raw;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0x21, $crc, strlen($raw), strlen($content),
            strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($data), 0);
}

$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$ct = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>';
$docx = up_zip(['[Content_Types].xml' => [$ct, false], '_rels/.rels' => ['<Relationships/>', false], 'word/document.xml' => ['<w:document/>', true]]);
$docm = up_zip(['[Content_Types].xml' => [$ct, false], 'word/document.xml' => ['<w:document/>', true], 'word/vbaProject.bin' => ['macro', false]]);
$plainZip = up_zip(['readme.txt' => ['hello', false]]);
$odtMime = 'application/vnd.oasis.opendocument.text';
$odt = up_zip(['mimetype' => [$odtMime, false], 'content.xml' => ['<office:document-content/>', true]]);
$odtOther = up_zip(['content.xml' => ['<office:document-content/>', true], 'mimetype' => [$odtMime, true]]);
$odtWrong = up_zip(['mimetype' => ['application/vnd.oasis.opendocument.spreadsheet', false], 'content.xml' => ['<x/>', true]]);
$odtMacro = up_zip(['mimetype' => [$odtMime, false], 'content.xml' => ['<x/>', true], 'Basic/Standard/Module1.xml' => ['<x/>', true]]);
$many = [];
for ($i = 0; $i < 2001; $i++) $many["e$i.txt"] = ['', false];
$bigZip = up_zip(['[Content_Types].xml' => [$ct, false], 'word/document.xml' => ['<w:document/>', false]] + $many);
$encrypted = hex2bin('D0CF11E0A1B11AE1') . str_repeat("\0", 600);

// ─── Unit checks: names, sizes, definitions ──────────────────────
up_check(bbf_uploads_sanitize_name('../../etc/x.pdf') === 'x.pdf' && bbf_uploads_sanitize_name('C:\\a\\b.pdf') === 'b.pdf', 'names: basename only');
up_check(bbf_uploads_sanitize_name("a\x00b\x1Fc.pdf") === 'abc.pdf', 'names: NUL and control characters removed');
up_check(bbf_uploads_sanitize_name("evil\u{202E}fdp.exe") === 'evilfdp.exe', 'names: bidi override removed');
$scrubbed = bbf_uploads_sanitize_name("bad\xFF\xFEname.pdf");
up_check(preg_match('//u', $scrubbed) === 1 && str_ends_with($scrubbed, '.pdf'), 'names: invalid UTF-8 replaced');
$long = bbf_uploads_sanitize_name(str_repeat('ž', 500) . '.pdf');
up_check(strlen($long) <= 200 && preg_match('//u', $long) === 1 && str_ends_with($long, "\u{2026}.pdf"), 'names: 1000-byte multibyte name truncated on a character boundary, extension kept');
up_check(bbf_uploads_parse_size('5MB') === 5242880 && bbf_uploads_parse_size('500KB') === 512000 && bbf_uploads_parse_size(1000) === 1000
    && bbf_uploads_parse_size('5 GB') === null && bbf_uploads_parse_size(0) === null, 'sizes: "5MB" = 5 242 880 bytes, binary units');
up_check(bbf_uploads_hard_denied('php7') && bbf_uploads_hard_denied('phtml') && bbf_uploads_hard_denied('svg') && bbf_uploads_hard_denied('ini', 'user.ini')
    && bbf_uploads_hard_denied('asp') && !bbf_uploads_hard_denied('pdf'), 'hard-deny list: php*, asp*, svg, user.ini');
up_check(bbf_uploads_definition_errors(['accept' => ['.svg']], 'f', false) !== [] && bbf_uploads_definition_errors(['accept' => ['.php']], 'f', false) !== []
    && bbf_uploads_definition_errors([], 'f', true) !== [] && bbf_uploads_definition_errors(['max_size' => '5MB', 'max_files' => 3, 'accept' => ['pdf']], 'f', false) === [],
    'definition: hard-denied accept, repeatable group rejected; valid options accepted');
up_check(bbf_uploads_content_disposition("a\"b\r\nc\\ž.pdf") === 'attachment; filename="abc_.pdf"; filename*=UTF-8\'\'abc%C5%BE.pdf',
    'Content-Disposition: CR, LF, quote and backslash removed; ASCII fallback plus RFC 5987 name');

$source = dirname(__DIR__);
$root = bbf_test_installation($source);
$private = str_replace('\\', '/', sys_get_temp_dir()) . '/bbf-upload-test-' . bin2hex(random_bytes(8));
mkdir($private, 0700);
$private = str_replace('\\', '/', realpath($private));
$data = "$private/uploads";
$GLOBALS['bbf_test_server_extra'][$root] = ['basedir' => [$private], 'extensions' => ['fileinfo', 'zip']];
$server = null;
register_shutdown_function(static function () use ($private): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    @rmdir($private);
});
try {
    up_config($root, $data);
    up_form($root, 'up');
    up_form($root, 'upcsv', ['storage' => 'csv']);
    up_form($root, 'upsql', ['storage' => 'sqlite']);
    up_form($root, 'upcond', [], [
        ['name' => 'apply', 'type' => 'radio', 'label' => 'Apply', 'options' => ['yes', 'no']],
        ['name' => 'cv', 'type' => 'file', 'label' => 'CV', 'required' => true, 'accept' => ['pdf'], 'show_if' => ['field' => 'apply', 'value' => 'yes']],
    ]);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());

    // ── Definition projection ───────────────────────────────────
    $def = bbf_test_http($server, up_url($server, 'form=up&action=definition'));
    $cv = $def['json']['fields'][1] ?? [];
    up_check(in_array('.pdf', $cv['_bbf_accept'] ?? [], true) && in_array('application/pdf', $cv['_bbf_accept'] ?? [], true)
        && is_int($cv['_bbf_max_size'] ?? null) && $cv['_bbf_max_size'] <= 2 * 1048576, 'definition exposes accept (extensions + MIME) and the effective max size');

    // ── Upload → submit → record ────────────────────────────────
    $up = up_upload($server, 'up', 'cv', 'Jana Novak CV.pdf', $pdf);
    $token = $up['json']['token'] ?? '';
    if ($up['code'] !== 200) print 'DEBUG first upload: ' . $up['code'] . ' ' . substr((string)($up['body'] ?? ''), 0, 600) . "\n"
        . substr((string)@file_get_contents("$root/logs/php-error.log"), 0, 1500) . "\n";
    up_check($up['code'] === 200 && preg_match('/\A[a-f0-9]{32}\z/', $token) && ($up['json']['file']['name'] ?? '') === 'Jana Novak CV.pdf'
        && ($up['json']['file']['type'] ?? '') === 'application/pdf' && isset($up['json']['expires_at']), 'upload returns token, expiry and sanitized file info');
    up_check(is_file("$data/staging/" . hash('sha256', $token)) && !glob("$data/staging/*$token*"), 'staging is named by the token hash; the token itself is not on disk');
    up_check(up_exact($data) && up_usage($data)['ledger'][0] === [strlen($pdf), 1], 'ledger counts the staged file exactly');
    $key = bin2hex(random_bytes(16));
    $sub = up_submit($server, 'up', ['answer' => 'hi', 'cv' => [$token]], $key);
    $id = $sub['json']['submission_id'] ?? '';
    $record = up_records($root, 'up')[0] ?? [];
    $desc = $record['data']['cv'][0] ?? [];
    up_check($sub['code'] === 200 && ($desc['name'] ?? '') === 'Jana Novak CV.pdf' && ($desc['size'] ?? 0) === strlen($pdf)
        && ($desc['sha256'] ?? '') === hash('sha256', $pdf) && preg_match('/\Af_[a-f0-9]{16}\z/', $desc['id'] ?? ''), 'submit stores descriptors, never a path or token');
    up_check(!str_contains(json_encode($record), $token), 'the record never contains the upload token');
    up_check(is_file("$data/up/$id/" . ($desc['id'] ?? '-')) && file_get_contents("$data/up/$id/{$desc['id']}") === $pdf, 'file claimed into <form>/<submission_id>/<file_id>');
    up_check(up_exact($data) && up_usage($data)['ledger'] === [[0, 0], [strlen($pdf), 1]], 'ledger: staging released, stored charged, no write-ahead entry left');
    $intent = up_intent($root, 'up', $key);
    up_check(($intent['state'] ?? '') === 'complete' && !isset($intent['files']), 'committed state drops the file plan');
    $replay = up_submit($server, 'up', ['answer' => 'hi', 'cv' => [$token]], $key);
    up_check($replay['code'] === 200 && ($replay['json']['already_submitted'] ?? false) && count(up_records($root, 'up')) === 1, 'replay: same key → 200, no second record');
    $reuse = up_submit($server, 'up', ['answer' => 'hi', 'cv' => [$token]], bin2hex(random_bytes(16)));
    up_check($reuse['code'] === 422 && count(up_records($root, 'up')) === 1, 'a used token cannot be claimed again (single use)');

    // Replay survives definition drift (Review 11).
    up_form($root, 'up', [], [['name' => 'answer', 'type' => 'text', 'label' => 'Answer']]);
    $drift = up_submit($server, 'up', ['answer' => 'hi', 'cv' => [$token]], $key);
    up_check($drift['code'] === 200 && ($drift['json']['already_submitted'] ?? false), 'replay after the file field was removed → 200, never 422');
    up_form($root, 'up');

    // ── Types ───────────────────────────────────────────────────
    $cases = [
        ['PHP as .png', 'shell.png', '<?php echo 1; ?>', 422],
        ['HTML as .pdf', 'x.pdf', '<html><body>x</body></html>', 422],
        ['SVG (not accepted, hard-denied)', 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 422],
        ['.php extension', 'x.php', '%PDF-1.4', 422],
        ['plain ZIP as .docx', 'x.docx', $plainZip, 422],
        ['.docm renamed to .docx', 'x.docx', $docm, 422],
        ['ZIP with 2 001 entries', 'x.docx', $bigZip, 422],
        ['ODF with wrong mimetype', 'x.odt', $odtWrong, 422],
        ['ODF with Basic/ macros', 'x.odt', $odtMacro, 422],
        ['empty file', 'x.pdf', '', 422],
    ];
    foreach ($cases as [$label, $name, $content, $code]) {
        $r = up_upload($server, 'up', 'cv', $name, $content);
        up_check($r['code'] === $code && !isset($r['json']['token']), "types: $label → $code");
    }
    $enc = up_upload($server, 'up', 'cv', 'secret.docx', $encrypted);
    up_check($enc['code'] === 422 && str_contains($enc['json']['message'] ?? '', 'Encrypted documents'), 'types: password-protected .docx gets the clear message');
    foreach ([['valid .docx', 'a.docx', $docx], ['valid .odt', 'a.odt', $odt], ['ODF with compressed, non-first mimetype', 'b.odt', $odtOther], ['PNG', 'a.png', $png]] as [$label, $name, $content]) {
        $r = up_upload($server, 'up', 'cv', $name, $content);
        up_check($r['code'] === 200 && isset($r['json']['token']), "types: $label accepted");
        if (isset($r['json']['token'])) up_delete($server, 'up', $r['json']['token']);
    }
    up_check(up_exact($data) && up_usage($data)['ledger'][0] === [0, 0], 'rejected and deleted uploads leave no staging usage');

    // ── Request shape ───────────────────────────────────────────
    $two = up_upload($server, 'up', 'cv', '', '', [], [['file', 'a.pdf', $pdf], ['other', 'b.pdf', $pdf]]);
    up_check($two['code'] === 400, 'two files in one request → 400');
    $arr = up_upload($server, 'up', 'cv', '', '', [], [['file[]', 'a.pdf', $pdf]]);
    up_check($arr['code'] === 400, 'file[] array → 400');
    up_check(up_upload($server, 'up', 'answer', 'a.pdf', $pdf)['code'] === 400, 'upload to a non-file field → 400');
    up_check(up_upload($server, 'nope', 'cv', 'a.pdf', $pdf)['code'] === 404, 'upload to an unknown form → 404');
    $t1 = up_upload($server, 'up', 'cv', 'a.pdf', $pdf)['json']['token'] ?? '';
    up_check(up_submit($server, 'up', ['cv' => [$t1, $t1]], null)['code'] === 400, 'duplicate token in one field → 400');
    up_check(up_submit($server, 'up', ['cv' => [$t1], 'answer' => [$t1]], null)['code'] === 400, 'duplicate token across fields → 400');
    $t2 = up_upload($server, 'up', 'cv', 'b.pdf', $pdf)['json']['token'] ?? '';
    $t3 = up_upload($server, 'up', 'cv', 'c.pdf', $pdf)['json']['token'] ?? '';
    $tooMany = up_submit($server, 'up', ['cv' => [$t1, $t2, $t3]], null);
    up_check($tooMany['code'] === 422 && isset($tooMany['json']['errors']['cv']), 'max_files exceeded → 422');
    up_check(up_submit($server, 'up', ['cv' => ['sbx.e30.' . str_repeat('0', 64)]], null)['code'] === 422, 'a sandbox token is rejected in production');
    up_check(up_submit($server, 'up', ['cv' => [str_repeat('a', 32)]], null)['code'] === 422, 'a forged token → 422');
    $other = up_upload($server, 'upcsv', 'cv', 'x.pdf', $pdf)['json']['token'] ?? '';
    up_check(up_submit($server, 'up', ['cv' => [$other]], null)['code'] === 422, 'a token of another form → 422');
    $del = up_delete($server, 'up', $t3);
    up_check($del['code'] === 200 && up_submit($server, 'up', ['cv' => [$t3]], null)['code'] === 422, 'upload_delete removes the entry; its token no longer works');
    up_check(up_delete($server, 'up', $other)['code'] === 404, 'upload_delete refuses a token of another form');
    up_delete($server, 'upcsv', $other);

    // Expired token.
    $meta = "$data/staging/" . hash('sha256', $t2) . '.json';
    $m = json_decode(file_get_contents($meta), true);
    $m['expires_at'] = time() - 1;
    file_put_contents($meta, json_encode($m));
    up_check(up_submit($server, 'up', ['cv' => [$t2]], null)['code'] === 422, 'an expired token → 422');

    // Conditional: a hidden required file field does not block submit; its tokens are ignored.
    $hidden = up_submit($server, 'upcond', ['apply' => 'no', 'cv' => [$t1]], null);
    up_check($hidden['code'] === 200 && (up_records($root, 'upcond')[0]['data']['cv'] ?? 'absent') === 'absent'
        && is_file("$data/staging/" . hash('sha256', $t1)), 'hidden required file field does not block submit and claims nothing');
    up_check(up_submit($server, 'upcond', ['apply' => 'yes'], null)['code'] === 422, 'visible required file field without a file → 422');

    // Definition drift between upload and submit.
    up_form($root, 'up', [], [['name' => 'answer', 'type' => 'text'], ['name' => 'cv', 'type' => 'file', 'label' => 'CV', 'accept' => ['png']]]);
    up_check(up_submit($server, 'up', ['cv' => [$t1]], null)['code'] === 422 && is_file("$data/staging/" . hash('sha256', $t1)),
        'definition narrowed after upload → 422, the token stays usable');
    up_form($root, 'up');

    // Other backends.
    $tc = up_upload($server, 'upcsv', 'cv', '=HYPERLINK(1).pdf', $pdf)['json']['token'] ?? '';
    $csv = up_submit($server, 'upcsv', ['answer' => 'csv', 'cv' => [$tc]], null);
    $raw = (string)@file_get_contents("$root/submissions/upcsv.csv");
    up_check($csv['code'] === 200 && str_contains($raw, "'=HYPERLINK(1).pdf (") && str_contains($raw, '__bbf:files'), 'CSV: readable, formula-escaped cell plus the __bbf:files column');
    require_once "$source/bbf_read.php";
    $read = null;
    foreach (bbf_read_csv('upcsv', "$root/submissions") as $row) $read = $row;
    up_check(($read['data']['cv'][0]['name'] ?? '') === '=HYPERLINK(1).pdf' && ($read['data']['cv'][0]['size'] ?? 0) === strlen($pdf),
        'CSV: reading restores the full descriptors from __bbf:files');
    $ts = up_upload($server, 'upsql', 'cv', 's.pdf', $pdf)['json']['token'] ?? '';
    $sql = up_submit($server, 'upsql', ['cv' => [$ts]], null);
    up_check($sql['code'] === 200 && is_dir("$data/upsql/" . ($sql['json']['submission_id'] ?? '-')), 'SQLite: submit with a file');

    // ── Crash, stall and retry (§14 highest priority) ───────────
    // Crash after all renames, before the final ledger write: recovery rolls back, the retry succeeds.
    $ta = up_upload($server, 'up', 'cv', 'crash.pdf', $pdf)['json']['token'] ?? '';
    $tb = up_upload($server, 'up', 'cv', 'crash2.pdf', $pdf . ' ')['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    $before = count(up_records($root, 'up'));
    up_fault($root, 'upload:claim_moved');
    up_submit($server, 'up', ['cv' => [$ta, $tb]], $key);
    $usage = up_usage($data);
    up_check(($usage['wal'] ?? 0) === 1 && $usage['ledger'][1][0] >= $usage['disk'][1][0] && $usage['ledger'][0][0] >= $usage['disk'][0][0],
        'crash after the claim moves: the write-ahead entry stays and the ledger over-counts, never under-counts');
    $busy = up_submit($server, 'up', ['cv' => [$ta]], bin2hex(random_bytes(16)));
    up_check(in_array($busy['code'], [422, 503], true) && count(up_records($root, 'up')) === $before, 'another key cannot claim paths of an unresolved entry');
    $retry = up_submit($server, 'up', ['cv' => [$ta, $tb]], $key);
    $records = up_records($root, 'up');
    $new = array_values(array_filter($records, static fn($r) => ($r['id'] ?? '') === ($retry['json']['submission_id'] ?? '')))[0] ?? [];
    up_check($retry['code'] === 200 && count($records) === $before + 1 && count($new['data']['cv'] ?? []) === 2
        && is_file("$data/up/{$new['id']}/{$new['data']['cv'][0]['id']}"), 'retry with the same key: rollback, then one record with all its files');
    up_check(up_exact($data), 'after recovery the ledger matches the disk exactly');

    // A transient rename failure: undo, 503, tokens stay valid.
    $ta = up_upload($server, 'up', 'cv', 'r1.pdf', $pdf)['json']['token'] ?? '';
    $tb = up_upload($server, 'up', 'cv', 'r2.pdf', $pdf . '  ')['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    up_fault($root, 'upload:claim_move', 'fail', 1);
    $failed = up_submit($server, 'up', ['cv' => [$ta, $tb]], $key);
    up_check($failed['code'] === 503 && (up_intent($root, 'up', $key)['state'] ?? '') === 'aborted' && up_exact($data)
        && is_file("$data/staging/" . hash('sha256', $ta)), 'second rename fails: complete undo, aborted, 503, files back in staging');
    $retry = up_submit($server, 'up', ['cv' => [$ta, $tb]], $key);
    up_check($retry['code'] === 200 && up_exact($data), 'retry with the same key succeeds');

    // A failed claim undo keeps the transaction open; recovery finishes it (Reviews 14 and 15).
    $ta = up_upload($server, 'up', 'cv', 'u1.pdf', $pdf)['json']['token'] ?? '';
    $tb = up_upload($server, 'up', 'cv', 'u2.pdf', $pdf . '   ')['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    up_fault($root, 'upload:rename', 'fail', 1, false);
    $failed = up_submit($server, 'up', ['cv' => [$ta, $tb]], $key);
    up_clear($root);
    up_check($failed['code'] === 503 && (up_intent($root, 'up', $key)['state'] ?? '') === 'open' && up_usage($data)['wal'] === 1,
        'undo fails: the transaction stays open (never aborted) and the write-ahead entry stays');
    $deleteBusy = up_delete($server, 'up', $tb);
    up_check($deleteBusy['code'] === 503, 'upload_delete of a path listed in an unresolved entry → 503');
    [$code, $out] = up_maintenance($root, 'submit-recover');
    up_check($code === 0 && (up_intent($root, 'up', $key)['state'] ?? '') === 'aborted' && up_exact($data)
        && is_file("$data/staging/" . hash('sha256', $ta)) && is_file("$data/staging/" . hash('sha256', $tb)),
        'recovery settles the entry, rolls back and aborts with exact accounting');
    up_check(up_submit($server, 'up', ['cv' => [$ta, $tb]], $key)['code'] === 200 && up_exact($data), 'the respondent\'s retry then succeeds');

    // store() fails (not written): rollback before aborted; retry succeeds.
    $ta = up_upload($server, 'up', 'cv', 's1.pdf', $pdf)['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    up_fault($root, 'store', 'fail');
    $failed = up_submit($server, 'up', ['cv' => [$ta]], $key);
    up_check($failed['code'] === 503 && (up_intent($root, 'up', $key)['state'] ?? '') === 'aborted'
        && is_file("$data/staging/" . hash('sha256', $ta)) && up_exact($data), 'store() fails: files back in staging, aborted, exact ledger');
    up_check(up_submit($server, 'up', ['cv' => [$ta]], $key)['code'] === 200, 'store() failure retry succeeds');

    // Crash after store(): the record exists, recovery completes it with its files.
    $ta = up_upload($server, 'up', 'cv', 'a1.pdf', $pdf)['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    $before = count(up_records($root, 'up'));
    up_fault($root, 'after_store');
    up_submit($server, 'up', ['cv' => [$ta]], $key);
    $retry = up_submit($server, 'up', ['cv' => [$ta]], $key);
    $sid = $retry['json']['submission_id'] ?? '';
    up_check($retry['code'] === 200 && ($retry['json']['already_submitted'] ?? false) && count(up_records($root, 'up')) === $before + 1
        && count(glob("$data/up/$sid/f_*") ?: []) === 1 && up_exact($data), 'crash after store: replay 200, one record with its file');

    // Crash inside rollback after the write-ahead write: recovery resolves the entry exactly.
    $ta = up_upload($server, 'up', 'cv', 'rb.pdf', $pdf)['json']['token'] ?? '';
    $key = bin2hex(random_bytes(16));
    file_put_contents("$root/fault.json", json_encode(['point' => 'store', 'action' => 'fail', 'once' => true]));
    $hook2 = "$root/fault2.json";
    up_fault($root, 'store', 'fail');
    // Chain: store fails, then the process dies right after the rollback moved the bytes back.
    $cfg = file_get_contents("$root/config.php");
    file_put_contents("$root/config.php", str_replace('if (!is_file($file)) return true;',
        'if (!is_file($file)) { $f2 = "$root/fault2.json"; if (is_file($f2) && $point === "upload:rollback_moved") { @unlink($f2); exit; } return true; }', $cfg));
    file_put_contents($hook2, '1');
    up_submit($server, 'up', ['cv' => [$ta]], $key);
    $usage = up_usage($data);
    up_check((up_intent($root, 'up', $key)['state'] ?? '') === 'open' && $usage['wal'] === 1
        && $usage['ledger'][0][0] >= $usage['disk'][0][0] && $usage['ledger'][1][0] >= $usage['disk'][1][0],
        'crash inside rollback: transaction open, entry unresolved, ledger over-counts');
    $other = up_submit($server, 'up', ['cv' => [$ta]], bin2hex(random_bytes(16)));
    up_check($other['code'] === 503, 'a claim by another key of the rolled-back path → 503 until the owner resolves it');
    file_put_contents("$root/config.php", $cfg);
    up_maintenance($root, 'submit-recover');
    up_check((up_intent($root, 'up', $key)['state'] ?? '') === 'aborted' && up_exact($data), 'owner recovery resolves the entry with exact numbers');
    up_check(up_submit($server, 'up', ['cv' => [$ta]], bin2hex(random_bytes(16)))['code'] === 200, 'then another key can claim the file');

    // ── Ledger and GC ───────────────────────────────────────────
    // A request killed after its reservation: the next refusal recounts and the upload succeeds.
    up_maintenance($root, 'uploads-cleanup'); // expired entries must not free a slot during the checks below
    $ipEntries = array_sum(array_column(up_ledger($data)['staging']['ip'] ?? [], 'entries'));
    up_config($root, $data, [], ['per_ip_staging_entries' => $ipEntries + 1]);
    up_fault($root, 'upload:after_incoming');
    up_upload($server, 'up', 'cv', 'killed.pdf', $pdf);
    up_check(up_usage($data)['reservations'] === 1, 'killed upload leaves its reservation');
    $afterKill = up_upload($server, 'up', 'cv', 'next.pdf', $pdf);
    up_check($afterKill['code'] === 200 && up_usage($data)['reservations'] === 0 && !glob("$data/staging/.incoming-*"),
        'a dead reservation is dropped by the recount before refusing; the upload succeeds');
    $limited = up_upload($server, 'up', 'cv', 'third.pdf', $pdf);
    up_check($limited['code'] === 429, 'per-IP staging entry limit → 429');
    up_delete($server, 'up', $afterKill['json']['token'] ?? '');
    // Injected unlink failure on publish keeps the reservation until GC removes the file.
    up_config($root, $data);
    up_fault($root, 'upload:publish_meta', 'fail');
    $pubFail = up_upload($server, 'up', 'cv', 'pub.pdf', $pdf);
    up_check($pubFail['code'] === 503 && up_exact($data) && !glob("$data/staging/.incoming-*"), 'failed publish: incoming removed, reservation released, exact ledger');
    // Expired staging entries are collected.
    $te = up_upload($server, 'up', 'cv', 'old.pdf', $pdf)['json']['token'] ?? '';
    $meta = "$data/staging/" . hash('sha256', $te) . '.json';
    $m = json_decode(file_get_contents($meta), true);
    $m['expires_at'] = time() - 10;
    file_put_contents($meta, json_encode($m));
    file_put_contents("$data/staging/" . str_repeat('b', 64), 'orphan bytes');
    [$code, $out] = up_maintenance($root, 'uploads-cleanup');
    $report = json_decode($out, true);
    up_check($code === 0 && !is_file("$data/staging/" . hash('sha256', $te)) && !is_file("$data/staging/" . str_repeat('b', 64))
        && up_exact($data) && ($report['unresolved_write_ahead'] ?? null) === [], 'uploads-cleanup: expired entries and orphan bytes removed, exact recount');

    // ── Rate limit and location ─────────────────────────────────
    foreach (glob("$root/logs/ratelimit_upload_*") ?: [] as $file) unlink($file);
    up_config($root, $data, [], ['rate_limit' => ['max' => 2, 'window' => 600]]);
    $codes = [];
    foreach ([['x.svg', 'x'], ['y.svg', 'y'], ['a.pdf', $pdf]] as [$name, $content]) $codes[] = up_upload($server, 'up', 'cv', $name, $content)['code'];
    up_check($codes === [422, 422, 429], 'upload rate limit counts rejected requests too');
    up_config($root, "$root/uploads-inside");
    $inside = up_upload($server, 'up', 'cv', 'a.pdf', $pdf);
    up_check($inside['code'] === 503 && str_contains($inside['json']['message'] ?? '', 'inside the web root'), 'upload directory inside the web root without the flag → refused');
    up_config($root, $data, [], ['enabled' => false]);
    up_check(up_upload($server, 'up', 'cv', 'a.pdf', $pdf)['code'] === 404, 'uploads disabled → refused');
    up_config($root, $data);

    // Without ZipArchive the Office types leave the effective allowlist.
    bbf_test_stop_server($server);
    $GLOBALS['bbf_test_server_extra'][$root]['extensions'] = ['fileinfo'];
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $def = bbf_test_http($server, up_url($server, 'form=up&action=definition'));
    $noZip = up_upload($server, 'up', 'cv', 'a.docx', $docx);
    up_check(!in_array('.docx', $def['json']['fields'][1]['_bbf_accept'] ?? ['.docx'], true) && $noZip['code'] === 422,
        'without ZipArchive .docx is not offered and is refused');
    up_check(!is_file("$root/logs/php-error.log") || !preg_match('/PHP (Warning|Fatal|Notice|Deprecated)/', (string)file_get_contents("$root/logs/php-error.log")),
        'no PHP warnings or errors in the server log');
} catch (Throwable $error) {
    ++$failures;
    print 'FAIL unexpected: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n";
} finally {
    if ($server !== null) bbf_test_stop_server($server);
}
print "Upload regression: $checks checks, $failures failures.\n";
exit($failures === 0 ? 0 : 1);
