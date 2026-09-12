<?php
/** G3 read eligibility and delete/update serialization. Disposable installations only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = $failed = 0;
function rc_check(bool $ok, string $label): void {
    global $checks, $failed;
    $checks++; if (!$ok) $failed++;
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function rc_wait(callable $ready, string $label): void {
    $end = microtime(true) + 10;
    do { clearstatcache(); if ($ready()) return; usleep(10000); } while (microtime(true) < $end);
    throw new RuntimeException('Timeout: ' . $label);
}
function rc_http(string $path, array $options = []): array {
    global $server;
    if (!isset($options['cookie'])) $options['headers']['X-BBF-Token'] = 'consistency-private-admin';
    return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/' . $path, null, $options);
}
function rc_csv_ids(string $body): array {
    $fp = fopen('php://temp', 'w+'); fwrite($fp, $body); rewind($fp);
    fgetcsv($fp, 0, ',', '"', ''); $ids = [];
    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) $ids[] = $row[0];
    fclose($fp); return $ids;
}
function rc_audit(string $root): array {
    $result = []; $fp = fopen("$root/logs/access-audit.php", 'rb');
    if (!$fp || !flock($fp, LOCK_SH)) throw new RuntimeException('Cannot lock fixture audit');
    try { $raw = stream_get_contents($fp); } finally { flock($fp, LOCK_UN); fclose($fp); }
    foreach (explode("\n", $raw) as $line) { $row = json_decode($line, true); if (is_array($row)) $result[] = $row; }
    return $result;
}
rc_check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'SQLite required');
foreach (['file', 'sqlite', 'sqlite_streaming_control'] as $mode) { $storage = $mode === 'file' ? 'file' : 'sqlite';
    $root = bbf_test_installation(dirname(__DIR__)); $server = null; $pdo = null;
    try {
        bbf_test_copy(dirname(__DIR__) . '/viewer.php', "$root/viewer.php");
        bbf_test_copy(__FILE__, "$root/tests/review-read-consistency-test.php");
        bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
        file_put_contents("$root/forms/alpha.json", json_encode(['id' => 'alpha', 'fields' => [['name' => 'value', 'type' => 'text']]]));
        $config = ['api_token' => 'consistency-private-admin', 'storage' => $storage,
            'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs",
            'sqlite' => ['path' => "$root/submissions/bbf.sqlite"]];
        file_put_contents("$root/config.php", '<?php return ' . var_export($config, true) . ';');
        if ($storage === 'file') mkdir("$root/submissions/alpha", 0700);
        else {
            $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE bbf_submissions(id TEXT PRIMARY KEY, form_id TEXT, data TEXT, meta TEXT, created_at TEXT)');
        }
        $stamp = date('Y-m-d') . 'T12:00:00Z'; $meta = json_encode(['submitted' => $stamp]);
        // Put corrupt records before and between valid rows, not only after the page.
        $fixtures = [
            ['bbf_90', '"scalar"', $meta], ['bbf_89', '{', $meta],
            ['bbf_80', '{"value":"needle newest","zero":0}', $meta],
            ['bbf_79', 'null', $meta], ['bbf_78', 'false', $meta], ['bbf_77', '0', $meta],
            ['bbf_76', '{"value":"needle bad meta"}', '"scalar"'],
            ['bbf_75', '{"value":"needle bad meta"}', '{'],
            ['bbf_74', '{"value":"needle bad meta"}', 'null'],
            ['bbf_73', '{"value":"needle bad meta"}', 'false'],
            ['bbf_72', '{"value":"needle bad meta"}', '0'],
            ['bbf_71', '{"value":"bad' . chr(255) . '"}', $meta], ['bbf_69', null, $meta], ['bbf_68', '{}', null], ['bbf_67', '{}', '{"bad":"' . chr(255) . '"}'],
            ['bbf_70', '{"value":"needle older"}', $meta],
            ['bbf_60', '[]', '{}'], ['bbf_50', '{}', '[]'],
            ['bbf_40', '["needle list"]', $meta],
        ];
        $valid = ['bbf_80', 'bbf_70', 'bbf_60', 'bbf_50', 'bbf_40'];
        foreach ($fixtures as [$id, $data, $rawMeta]) {
            $order = (int)substr($id, 4);
            $created = date('Y-m-d') . 'T12:00:' . sprintf('%02d', intdiv($order, 2)) . 'Z';
            if ($storage === 'sqlite') $pdo->prepare('INSERT INTO bbf_submissions VALUES(?,?,?,?,?)')->execute([$id, 'alpha', $data, $rawMeta, $created]);
            else {
                file_put_contents("$root/submissions/alpha/$id.json", '{"id":"' . $id . '","form":"alpha","data":' . $data . ',"meta":' . $rawMeta . '}');
                touch("$root/submissions/alpha/$id.json", time() + $order);
            }
        }
        if ($mode === 'sqlite_streaming_control') { $reader = file_get_contents("$root/bbf_read.php"); $reader = str_replace("if (\$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return false;", 'return false;', $reader, $changed); if ($changed !== 1) throw new RuntimeException('Cannot force streaming control'); file_put_contents("$root/bbf_read.php", $reader); print "CONTROL: SQLite exercises non-SQL eligibility fallback (not real MySQL).\n"; } $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
        rc_check(rc_http('tests/review-read-consistency-test.php')['code'] === 403, "$storage CLI guard");
        foreach (['submissions.php?form=alpha', 'viewer.php?action=submissions&form=alpha'] as $endpoint) {
            foreach ([0, 1, 2, 4, 5, 100] as $offset) {
                $r = rc_http($endpoint . '&limit=2&offset=' . $offset);
                rc_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 5
                    && array_column($r['json']['submissions'] ?? [], 'id') === array_slice($valid, $offset, 2), "$storage eligibility before count/page offset=$offset $endpoint");
            }
            foreach (['needle' => ['bbf_80', 'bbf_70', 'bbf_40'], '0' => ['bbf_80'], '%' => [], 'value' => []] as $q => $ids) {
                $r = rc_http($endpoint . '&q=' . rawurlencode((string)$q));
                rc_check($r['code'] === 200 && ($r['json']['total'] ?? null) === count($ids)
                    && array_column($r['json']['submissions'] ?? [], 'id') === $ids, "$storage valid-only literal search $q $endpoint");
            }
        }
        foreach ($fixtures as [$id]) foreach (['submissions.php?form=alpha&id=', 'viewer.php?action=detail&form=alpha&id='] as $endpoint) {
            $r = rc_http($endpoint . $id); $good = in_array($id, $valid, true);
            rc_check($r['code'] === ($good ? 200 : 404), "$storage detail $id $endpoint");
        }
        foreach (['submissions.php?format=csv&form=alpha', 'viewer.php?action=export&form=alpha'] as $endpoint) {
            $r = rc_http($endpoint);
            rc_check($r['code'] === 200 && rc_csv_ids($r['body']) === $valid, "$storage full export omits invalid $endpoint");
            $r = rc_http($endpoint . '&q=needle&last=2');
            rc_check($r['code'] === 200 && rc_csv_ids($r['body']) === ['bbf_80', 'bbf_70'], "$storage searched limited export $endpoint");
        }
        foreach (['stats', 'dashboard'] as $action) {
            $r = rc_http('viewer.php?action=' . $action . '&form=alpha');
            rc_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 5, "$storage $action valid total");
        }
        $r = rc_http('viewer.php?action=list_forms');
        rc_check($r['code'] === 200 && ($r['json'][0]['count'] ?? null) === 5, "$storage form count valid-only");
        if ($storage === 'sqlite') {
            // Genuine operational failure must never be mistaken for corrupt row omission.
            $pdo->exec('DROP TABLE bbf_submissions');
            foreach (['submissions.php?form=alpha', 'viewer.php?action=submissions&form=alpha',
                'submissions.php?form=alpha&id=bbf_80', 'viewer.php?action=detail&form=alpha&id=bbf_80',
                'viewer.php?action=stats&form=alpha', 'submissions.php?format=csv&form=alpha',
                'viewer.php?action=export&form=alpha'] as $endpoint) {
                $before = count(rc_audit($root)); $r = rc_http($endpoint);
                rc_check($r['code'] === 500 && isset($r['json']['error']) && !str_contains($r['body'], 'no such table'), "SQL operational failure is generic HTTP500 $endpoint");
                $entries = array_slice(rc_audit($root), $before);
                rc_check(array_column($entries, 'result') === ['attempted', 'failed'], "SQL operational failure audit $endpoint");
            }
        } else {
            $login = rc_http('viewer.php');
            preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $login['headers'], $cookie);
            preg_match('/const TOKEN = ("[^"]+");/', $login['body'], $csrf);
            if (!isset($cookie[1], $csrf[1])) throw new RuntimeException('Session/CSRF login failed');
            $session = ['cookie' => $cookie[1], 'headers' => ['X-BBF-CSRF' => json_decode($csrf[1], true), 'Content-Type' => 'application/json']];
            $reviewFailurePath = "$root/submissions/alpha/bbf_review_failure.json";
            $reviewFailureBytes = json_encode(['id' => 'bbf_review_failure', 'form' => 'alpha', 'data' => ['private' => 'response'],
                'meta' => ['submitted' => $stamp]], JSON_THROW_ON_ERROR);
            file_put_contents($reviewFailurePath, $reviewFailureBytes);
            mkdir("$root/submissions/.review", 0700, true);
            $corruptReviewBytes = '{"private-note":"must survive failed delete"';
            file_put_contents("$root/submissions/.review/alpha.json", $corruptReviewBytes);
            $reviewFailure = rc_http('viewer.php?action=delete', $session + [
                'raw' => json_encode(['form' => 'alpha', 'id' => 'bbf_review_failure'], JSON_THROW_ON_ERROR),
            ]);
            rc_check($reviewFailure['code'] === 503 && is_file($reviewFailurePath)
                && file_get_contents($reviewFailurePath) === $reviewFailureBytes
                && file_get_contents("$root/submissions/.review/alpha.json") === $corruptReviewBytes,
                '6129-F04 viewer review-storage failure preserves the primary response and exact private review bytes');
            unlink("$root/submissions/.review/alpha.json");
            // Controlled writer uses the real stable lock and checked publication primitives.
            file_put_contents("$root/writer.php", <<<'PHP'
<?php
define('BBF_LOADED', true); require __DIR__ . '/bbf_storage.php';
$path = __DIR__ . '/submissions/alpha/bbf_race.json';
$ok = bbf_storage_locked($path, static function () use ($path): bool {
    $record = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $record['meta']['payment_status'] = 'paid';
    file_put_contents(__DIR__ . '/ready', 'ready');
    $deadline = microtime(true) + 10;
    while (true) {
        clearstatcache(); if (is_file(__DIR__ . '/resume')) break;
        if (microtime(true) > $deadline) throw new RuntimeException('Writer resume timeout');
        usleep(10000);
    }
    return bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, bbf_storage_json($record)));
});
print json_encode(['ok' => $ok]); exit($ok ? 0 : 1);
PHP);
            foreach (['delete', 'bulk_delete'] as $action) {
                $path = "$root/submissions/alpha/bbf_race.json";
                file_put_contents($path, json_encode(['id' => 'bbf_race', 'form' => 'alpha', 'data' => [], 'meta' => ['submitted' => $stamp]]));
                file_put_contents("$root/submissions/alpha/bbf_other.json", json_encode(['id' => 'bbf_other', 'form' => 'alpha', 'data' => [], 'meta' => []]));
                foreach (['ready', 'resume'] as $marker) if (is_file("$root/$marker")) unlink("$root/$marker");
                $worker = proc_open([PHP_BINARY, "$root/writer.php"], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
                if (!is_resource($worker)) throw new RuntimeException('Cannot launch writer');
                fclose($pipes[0]); $socket = null;
                try {
                    rc_wait(static fn() => is_file("$root/ready"), 'writer locked and read');
                    $lock = fopen($path . '.lock', 'c');
                    rc_check(!flock($lock, LOCK_EX | LOCK_NB), "$action writer actually holds same sidecar"); fclose($lock);
                    // Pin an authenticated request to the verified owned server. Async only so
                    // parent can release the writer while the real endpoint is blocked.
                    bbf_test_verify_server($server);
                    $socket = stream_socket_client('tcp://127.0.0.1:' . $server['port'], $errno, $error, 2);
                    if (!$socket || !bbf_test_server_alive($server)) throw new RuntimeException('Owned server unavailable');
                    $body = json_encode(['form' => 'alpha', 'id' => 'bbf_race', 'ids' => ['bbf_race', 'bbf_other']]);
                    $before = count(rc_audit($root));
                    $request = "POST /viewer.php?action=$action HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nCookie: {$session['cookie']}\r\nX-BBF-CSRF: {$session['headers']['X-BBF-CSRF']}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body";
                    if (fwrite($socket, $request) !== strlen($request)) throw new RuntimeException('Short request');
                    rc_wait(static fn() => count(rc_audit($root)) > $before, 'delete attempted audit');
                    $read = [$socket]; $write = $except = [];
                    $responded = stream_select($read, $write, $except, 0, 300000) > 0;
                    clearstatcache();
                    rc_check(!$responded && is_file($path), "$action blocks without unlink while updater owns lock");
                    file_put_contents("$root/resume", 'resume');
                    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($worker); $worker = null;
                    rc_check($exit === 0 && (json_decode($out, true)['ok'] ?? false), "$action updater published successfully exit=$exit stderr=$err");
                    stream_set_timeout($socket, 10); $response = stream_get_contents($socket);
                    [$headers, $payload] = array_pad(explode("\r\n\r\n", $response, 2), 2, ''); $json = json_decode($payload, true);
                    rc_check(str_contains($headers, '200 OK') && ($json['ok'] ?? false)
                        && ($action !== 'bulk_delete' || ($json['deleted'] ?? null) === 2), "$action real endpoint success/count");
                    clearstatcache();
                    rc_check(!is_file($path), "$action no resurrection after successful updater publication");
                    rc_check(is_file($path . '.lock'), "$action stable sidecar retained");
                    $lock = fopen($path . '.lock', 'c'); rc_check(flock($lock, LOCK_EX | LOCK_NB), "$action lock released after deletion"); fclose($lock);
                    $missing = rc_http('viewer.php?action=delete', $session + ['raw' => json_encode(['form' => 'alpha', 'id' => 'bbf_missing'])]);
                    rc_check($missing['code'] === 404, "$action absent-record negative control");
                } finally {
                    file_put_contents("$root/resume", 'resume');
                    if (is_resource($socket)) fclose($socket);
                    if (is_resource($worker)) { proc_terminate($worker); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($worker); }
                }
            }
        }
    } finally { $pdo = null; bbf_test_stop_server($server); bbf_test_cleanup($root); }
}
print "Read consistency: " . ($checks - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);
