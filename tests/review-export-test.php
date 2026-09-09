<?php
// G3 R2.4: exercise both real CSV endpoints over disposable historical records.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0;
$failures = [];
$exportRequest = null;
$exportEvidence = [];
function export_check(bool $ok, string $label): void {
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures[] = $label;
        export_evidence($label);
    }
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function export_rows(string $body): array {
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, $body);
    rewind($fp);
    $rows = [];
    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) $rows[] = $row;
    fclose($fp);
    return $rows;
}
function export_log(string $path): string {
    clearstatcache(true, $path);
    return is_file($path) ? file_get_contents($path) : '';
}
function export_evidence_write(string $path, string $bytes): void {
    if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot retain export evidence: ' . $path);
}
// Capture BEFORE any assertions or the next request can overwrite the fault trace.
// Only fixture data is retained; no request token, operator config, or live data.
function export_http(array $server, string $path, string $token = 'export-fixture-admin'): array {
    global $exportRequest;
    $root = $server['root'];
    $before = strlen(export_log("$root/logs/access-audit.php"));
    $exportRequest = ['root' => $root, 'endpoint' => $path, 'pid' => $server['pid'],
        'port' => $server['port'], 'started_at' => microtime(true), 'audit_offset' => $before];
    try {
        return $exportRequest['response'] = bbf_test_http($server,
            'http://127.0.0.1:' . $server['port'] . '/' . $path, null, ['headers' => ['X-BBF-Token' => $token]]);
    } catch (Throwable $error) {
        $exportRequest['transport_exception'] = (string)$error;
        throw $error;
    } finally {
        $exportRequest['finished_at'] = microtime(true);
        $exportRequest['audit_delta'] = substr(export_log("$root/logs/access-audit.php"), $before);
        $exportRequest['writes'] = export_log("$root/logs/export-writes.json");
        $exportRequest['runtime'] = export_log("$root/logs/export-runtime.json");
    }
}
function export_evidence(string $label): void {
    global $exportRequest, $exportEvidence;
    if (!isset($exportRequest['evidence_dir'])) {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/bbf-export-failure-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create export evidence directory.');
        $exportRequest ??= [];
        $exportRequest['evidence_dir'] = $dir;
        $root = $exportRequest['root'] ?? null;
        $exportEvidence[$dir] = $root;
        $response = $exportRequest['response'] ?? [];
        export_evidence_write("$dir/response-status.txt", (string)($response['code'] ?? 'unavailable') . "\n");
        export_evidence_write("$dir/response-headers.txt", $response['headers'] ?? '');
        export_evidence_write("$dir/response-body.bin", $response['body'] ?? '');
        export_evidence_write("$dir/audit-delta.log", $exportRequest['audit_delta'] ?? '');
        export_evidence_write("$dir/export-writes.json", $exportRequest['writes'] ?? '');
        export_evidence_write("$dir/export-runtime.json", $exportRequest['runtime'] ?? '');
        $meta = array_diff_key($exportRequest, array_flip(['response', 'audit_delta', 'writes', 'runtime']));
        $meta['response_error'] = $response['error'] ?? null;
        $meta['parent_php'] = ['binary' => PHP_BINARY, 'version' => PHP_VERSION, 'sapi' => PHP_SAPI];
        $meta['fixture_source_sha256'] = [];
        if ($root !== null) {
            foreach (['viewer.php', 'submissions.php', 'bbf_export.php', 'bbf_read.php', 'bbf_storage.php',
                      'bbf_functions.php', 'bbf_auth.php', 'tests/test-isolation-helper.php', 'tests/review-export-test.php'] as $file) {
                $meta['fixture_source_sha256'][$file] = hash_file('sha256', "$root/$file");
            }
            foreach (['php-error', 'server-error', 'server-output'] as $log) {
                export_evidence_write("$dir/$log.log", export_log("$root/logs/$log.log"));
            }
        }
        export_evidence_write("$dir/request.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        print "FAILURE EVIDENCE $dir\n";
    }
    $dir = $exportRequest['evidence_dir'];
    export_evidence_write("$dir/failures.txt", export_log("$dir/failures.txt") . $label . "\n");
}
// After stopping the owned server, preserve its final flushed logs BEFORE cleanup.
function export_final_evidence(string $root): void {
    global $exportEvidence;
    foreach ($exportEvidence as $dir => $fixture) {
        if ($fixture !== $root) continue;
        foreach (['php-error.log', 'server-error.log', 'server-output.log', 'access-audit.php'] as $log) {
            export_evidence_write("$dir/final-$log", export_log("$root/logs/$log"));
        }
    }
}
function export_failed_request(callable $http, string $endpoint, string $root, string $tag, ?int $status = null): void {
    $before = strlen(export_log("$root/logs/access-audit.php"));
    $r = $http($endpoint);
    export_check($status === null ? $r['code'] >= 400 && $r['code'] < 600 : $r['code'] === $status,
        "$tag HTTP failure" . ($status === null ? '' : " ($status)"));
    export_check(!str_contains(strtolower($r['headers']), 'text/csv')
        && !str_contains(strtolower($r['headers']), 'content-disposition: attachment')
        && !str_contains($r['body'], 'id,submitted') && !str_contains($r['body'], 'current-')
        && !str_contains($r['body'], 'No submissions'), "$tag no CSV or successful-empty body released");
    // Read only this request's new entries; a prior failed request cannot satisfy this check.
    $entries = [];
    foreach (explode("\n", substr(export_log("$root/logs/access-audit.php"), $before)) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry)) $entries[] = $entry;
    }
    $action = str_starts_with($endpoint, 'viewer.php') ? 'viewer_export' : 'api_export';
    export_check(count($entries) === 2 && array_column($entries, 'result') === ['attempted', 'failed']
        && array_column($entries, 'action') === [$action, $action]
        && array_column($entries, 'form') === ['alpha', 'alpha']
        && ($entries[1]['result_count'] ?? null) === 0,
        "$tag attempted then failed audit, never completed");
}
export_check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'SQLite required, not skipped');
foreach (['file', 'csv', 'sqlite'] as $storage) {
    $root = bbf_test_installation(dirname(__DIR__));
    $exportRequest = ['root' => $root, 'storage' => $storage];
    $server = null;
    $pdo = null;
    try {
        bbf_test_copy(dirname(__DIR__) . '/viewer.php', "$root/viewer.php");
        bbf_test_copy(__FILE__, "$root/tests/review-export-test.php");
        bbf_test_remove_dir("$root/forms");
        mkdir("$root/forms", 0700);
        $config = ['api_token' => 'export-fixture-admin', 'storage' => $storage,
            'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs",
            'sqlite' => ['path' => "$root/submissions/bbf.sqlite"], 'lang' => 'en',
            'access_tokens' => [['id' => 'reader', 'token' => 'export-fixture-reader', 'forms' => ['alpha'],
                'permissions' => ['read'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]]];
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        $form = ['id' => 'alpha', 'name' => 'Export fixture', 'templates' => [
            'person' => [['name' => 'city', 'type' => 'text', 'label' => 'City']]],
            'fields' => [
                ['name' => 'current', 'type' => 'text'],
                ['name' => 'group', 'type' => 'group', 'fields' => [
                    ['name' => 'nested', 'type' => 'text'],
                    ['name' => 'inner', 'type' => 'group', 'fields' => [['name' => 'deep', 'type' => 'text']]]]],
                ['name' => 'contact', 'type' => 'group', 'use' => 'person', 'prefix' => 'home_'],
                ['name' => 'choices', 'type' => 'checkboxes'],
                ['name' => 'zero', 'type' => 'number'],
                ['name' => 'formula', 'type' => 'text'],
                ['name' => 'multiline', 'type' => 'textarea'], ['name' => 'page', 'type' => 'page_break'], ['name' => 'section', 'type' => 'section'],
                ['name' => 'negative', 'type' => 'number'], ['name' => 'slash_quote', 'type' => 'text'],
                ['name' => 'trailing_slash', 'type' => 'text'],
            ]];
        file_put_contents("$root/forms/alpha.json", json_encode($form, JSON_THROW_ON_ERROR));
        if ($storage === 'file') mkdir("$root/submissions/alpha", 0700);
        if ($storage === 'sqlite') {
            $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT, data TEXT, meta TEXT, created_at TEXT)');
        }
        $unsafeKeys = ['=1+1', '+historical', '-historical', '@historical', "\thistorical", "\rhistorical"];
        $keys = array_merge(['current', 'nested', 'deep', 'home_city', 'choices', 'zero', 'formula', 'multiline',
            'negative', 'slash_quote', 'trailing_slash', 'retired'], $unsafeKeys, ['older_only']);
        $expectedHeader = array_merge(['id', 'submitted'], array_map(static fn($key) => in_array($key, $unsafeKeys, true) ? "'" . $key : $key, $keys));
        $slashQuote = 'backslash\\"quote';
        $trailingSlash = "space then trailing \\";
        $csv = $storage === 'csv' ? fopen("$root/submissions/alpha.csv", 'w') : null;
        if ($csv) fputcsv($csv, array_merge(['_id', '_submitted', '_ip', '_user_agent'], $keys), ',', '"', '');
        for ($i = 0; $i < 125; $i++) {
            $id = 'bbf_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            $stamp = gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-01-01T00:00:00Z') + $i * 86400);
            $data = ['current' => "current-$i", 'nested' => "nested-$i", 'deep' => "deep-$i", 'home_city' => 'Žilina',
                'choices' => ['red', 'blue'], 'zero' => 0, 'formula' => '=1+1', 'multiline' => "quoted \"cell\",\nsecond line",
                'negative' => -12.5, 'slash_quote' => $slashQuote, 'trailing_slash' => $trailingSlash,
                'retired' => "old-$i"];
            foreach ($unsafeKeys as $index => $key) $data[$key] = "history-$index-$i";
            if ($i === 0) $data['older_only'] = 'preserve-oldest-only';
            $record = ['id' => $id, 'form' => 'alpha', 'data' => $data, 'meta' => ['submitted' => $stamp]];
            if ($storage === 'file') {
                file_put_contents("$root/submissions/alpha/$id.json", json_encode($record, JSON_THROW_ON_ERROR));
                touch("$root/submissions/alpha/$id.json", strtotime($stamp));
            } elseif ($storage === 'sqlite') {
                $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
                    $id, 'alpha', json_encode($data, JSON_THROW_ON_ERROR), json_encode($record['meta'], JSON_THROW_ON_ERROR), $stamp]);
            } else {
                $row = [$id, $stamp, '', ''];
                foreach ($keys as $key) {
                    $value = $data[$key] ?? '';
                    if (is_array($value)) $value = implode(', ', $value);
                    $value = (string)$value;
                    if ($value !== '' && str_contains('=+-@', $value[0])) $value = "'" . $value;
                    $row[] = $value;
                }
                fputcsv($csv, $row, ',', '"', '');
            }
        }
        if ($csv) fclose($csv);
        $pdo = null;
        $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
        $http = static fn(string $path, string $token = 'export-fixture-admin') => export_http($server, $path, $token);
        export_check($http('tests/review-export-test.php')['code'] === 403, "$storage CLI guard");
        foreach (['viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $endpoint) {
            $tag = "$storage $endpoint";
            export_check($http($endpoint, 'export-fixture-reader')['code'] === 403, "$tag read-only cannot export");
            $logBefore = [];
            foreach (['php-error', 'server-error', 'server-output'] as $log) $logBefore[$log] = strlen(export_log("$root/logs/$log.log"));
            $r = $http($endpoint);
            export_check($r['code'] === 200 && str_contains(strtolower($r['headers']), 'text/csv'), "$tag CSV response");
            $rows = export_rows($r['body']);
            $header = array_shift($rows) ?? [];
            export_check($header === $expectedHeader, "$tag stable expanded columns plus historical union, no group columns");
            export_check(count($rows) === 125, "$tag default export is complete beyond API page limit");
            export_check(count(array_unique(array_column($rows, 0))) === 125, "$tag no duplicated or dropped IDs");
            $byId = [];
            foreach ($rows as $row) {
                if (count($header) === count($row)) $byId[$row[0]] = array_combine($header, $row);
            }
            $newest = $byId['bbf_0124'] ?? [];
            $oldest = $byId['bbf_0000'] ?? [];
            export_check(($newest['nested'] ?? null) === 'nested-124' && ($newest['deep'] ?? null) === 'deep-124', "$tag nested fields retain values");
            export_check(($newest['home_city'] ?? null) === 'Žilina', "$tag prefixed template field retains Unicode");
            export_check(($newest['choices'] ?? null) === 'red, blue' && ($newest['zero'] ?? null) === '0', "$tag arrays and numeric zero serialize");
            export_check(($newest['formula'] ?? null) === "'=1+1", "$tag formula prefixed exactly once");
            export_check(($newest['multiline'] ?? null) === "quoted \"cell\",\nsecond line", "$tag RFC CSV quoting round trip");
            export_check(($oldest['older_only'] ?? null) === 'preserve-oldest-only', "$tag field present only in oldest record retained");
            $filtered = export_rows($http($endpoint . '&from=2026-01-01&to=2026-01-03')['body']);
            export_check(count($filtered) === 4, "$tag date filter retains exactly three records");
            $last = export_rows($http($endpoint . '&last=2')['body']);
            export_check(count($last) === 3 && array_column(array_slice($last, 1), 0) === ['bbf_0124', 'bbf_0123'], "$tag explicit last=2 newest-first");
            foreach ($unsafeKeys as $index => $key) {
                export_check(in_array("'" . $key, $header, true) && !in_array($key, $header, true)
                    && ($newest["'" . $key] ?? null) === "history-$index-124",
                    "$tag historical header " . json_encode($key) . ' sanitized once, original key value retained');
            }
            export_check(($newest['slash_quote'] ?? null) === $slashQuote, "$tag backslash-before-quote round trip");
            export_check(($newest['trailing_slash'] ?? null) === $trailingSlash, "$tag whitespace-plus-trailing-backslash round trip");
            export_check(($newest['negative'] ?? null) === "'-12.5", "$tag negative float follows scalar formula protection exactly once");
            $newLogs = '';
            foreach ($logBefore as $log => $offset) $newLogs .= substr(export_log("$root/logs/$log.log"), $offset);
            export_check(!preg_match('/warning|notice|deprecated|fatal|uncaught|trying to access array offset/i', $newLogs),
                "$tag zero and negative float exports leave clean PHP/server logs");
        }
        // Historical data is still exportable after its form definition is removed.
        unlink("$root/forms/alpha.json");
        foreach (['viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $endpoint) {
            $rows = export_rows($http($endpoint)['body']);
            $header = array_shift($rows) ?? [];
            export_check(count($rows) === 125 && in_array('older_only', $header, true), "$storage missing definition still unions all historical fields $endpoint");
        }
        // Separate date fixture keeps all original 125-record/pagination checks intact.
        file_put_contents("$root/forms/boundary.json", json_encode(['id' => 'boundary', 'fields' => [['name' => 'value', 'type' => 'text']]], JSON_THROW_ON_ERROR));
        $stamps = ['before' => '2026-01-02T23:59:59Z', 'start' => '2026-01-03T00:00:00Z',
            'last_z' => '2026-01-03T23:59:59Z', 'last_offset' => '2026-01-03T23:59:59+00:00',
            'fraction_z' => '2026-01-03T23:59:59.999999Z', 'fraction_offset' => '2026-01-03T23:59:59.999999+00:00',
            'next_z' => '2026-01-04T00:00:00Z', 'next_offset' => '2026-01-04T00:00:00+00:00'];
        if ($storage === 'file') mkdir("$root/submissions/boundary", 0700);
        if ($storage === 'sqlite') $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $csv = $storage === 'csv' ? fopen("$root/submissions/boundary.csv", 'w') : null;
        if ($csv) fputcsv($csv, ['_id', '_submitted', '_ip', '_user_agent', 'value'], ',', '"', '');
        foreach ($stamps as $name => $stamp) {
            $id = 'bbf_' . $name;
            $record = ['id' => $id, 'form' => 'boundary', 'data' => ['value' => $name], 'meta' => ['submitted' => $stamp]];
            if ($storage === 'file') {
                file_put_contents("$root/submissions/boundary/$id.json", json_encode($record, JSON_THROW_ON_ERROR));
                touch("$root/submissions/boundary/$id.json", strtotime($stamp));
            } elseif ($storage === 'sqlite') {
                $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
                    $id, 'boundary', json_encode($record['data'], JSON_THROW_ON_ERROR), json_encode($record['meta'], JSON_THROW_ON_ERROR), $stamp]);
            } else {
                fputcsv($csv, [$id, $stamp, '', '', $name], ',', '"', '');
            }
        }
        if ($csv) fclose($csv);
        $pdo = null;
        foreach (['viewer.php?action=export&form=boundary', 'submissions.php?format=csv&form=boundary'] as $endpoint) {
            $r = $http($endpoint . '&from=2026-01-03&to=2026-01-03');
            $rows = export_rows($r['body']);
            $ids = array_column(array_slice($rows, 1), 0);
            $tag = "$storage $endpoint";
            export_check($r['code'] === 200 && count($ids) === 5, "$tag single-day CSV includes exactly five boundary records");
            foreach (['start', 'last_z', 'last_offset', 'fraction_z', 'fraction_offset'] as $name) {
                export_check(in_array('bbf_' . $name, $ids, true), "$tag includes $name");
            }
            foreach (['before', 'next_z', 'next_offset'] as $name) {
                export_check(!in_array('bbf_' . $name, $ids, true), "$tag excludes $name");
            }
        }
        // A present but invalid definition must fail before audit completion/CSV release.
        $badDefinitions = [
            'structural fields' => json_encode(array_replace($form, ['fields' => 'invalid']), JSON_THROW_ON_ERROR),
            'structural templates' => json_encode(array_replace($form, ['templates' => 'invalid']), JSON_THROW_ON_ERROR),
            'malformed JSON' => '{"id":"alpha","fields":[',
        ];
        foreach ($badDefinitions as $label => $definition) {
            file_put_contents("$root/forms/alpha.json", $definition);
            foreach (['viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $endpoint) {
                export_failed_request($http, $endpoint, $root, "$storage $label $endpoint");
            }
        }
        file_put_contents("$root/forms/alpha.json", json_encode($form, JSON_THROW_ON_ERROR));
        if ($storage === 'file') {
            // Change only the output allocation in the owned copy, never endpoint/audit logic.
            $expected = [];
            foreach (['viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $endpoint) {
                $expected[$endpoint] = $http($endpoint . '&last=2')['body'];
            }
            bbf_test_stop_server($server);
            $server = null;
            $source = file_get_contents("$root/bbf_export.php");
            $injected = str_replace('$out = tmpfile();', '$out = export_test_output();', $source, $replacements);
            if ($replacements !== 1) throw new RuntimeException('CSV output fault seam changed; refusing ineffective test.');
            $shim = <<<'PHP'

// Test-only stream wrapper appended to a disposable installation, not production.
class ExportTestOutput {
    public $context;
    private $fp;
    private string $fault;
    private array $writes = [];
    private bool $started = false;
    private bool $pause = false;
    public function stream_open($path, $mode, $options, &$opened_path): bool {
        $this->fault = parse_url($path, PHP_URL_HOST);
        $this->fp = tmpfile();
        return is_resource($this->fp);
    }
    public function stream_write($bytes) {
        if (str_starts_with($this->fault, 'row-') && !$this->writes) {
            $written = fwrite($this->fp, $bytes); // The complete, small fixture header.
        } elseif (str_ends_with($this->fault, '-false')) {
            $written = false;
        } elseif (str_ends_with($this->fault, '-zero')) {
            $written = 0;
        } elseif (str_ends_with($this->fault, '-short')) {
            $written = $this->started ? 0 : fwrite($this->fp, substr($bytes, 0, 3));
            $this->started = true;
        } elseif ($this->pause) {
            // PHP retries user stream_write internally. Stop that retry so fwrite
            // itself returns a positive short count; the application's next call recovers.
            $this->pause = false;
            $written = 0;
        } else {
            $written = fwrite($this->fp, substr($bytes, 0, 3));
            $this->pause = $written < strlen($bytes);
        }
        $this->writes[] = $written;
        return $written;
    }
    public function stream_read($length) { return fread($this->fp, $length); }
    public function stream_eof(): bool { return feof($this->fp); }
    public function stream_tell(): int { return ftell($this->fp); }
    public function stream_seek($offset, $whence = SEEK_SET): bool { return fseek($this->fp, $offset, $whence) === 0; }
    public function stream_flush(): bool { return fflush($this->fp); }
    public function stream_stat(): array { return fstat($this->fp); }
    public function stream_close(): void {
        file_put_contents(__DIR__ . '/logs/export-writes.json', json_encode($this->writes, JSON_THROW_ON_ERROR));
        fclose($this->fp);
    }
}
function export_test_output() {
    // Entry evidence is separate from close evidence: absent writes need not mean a stale seam.
    $settings = [];
    foreach (['opcache.enable', 'opcache.enable_cli', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
              'opcache.file_update_protection', 'opcache.file_cache', 'opcache.file_cache_only', 'output_buffering'] as $key) {
        $settings[$key] = ini_get($key);
    }
    file_put_contents(__DIR__ . '/logs/export-runtime.json', json_encode([
        'fault' => $_GET['write_fault'], 'entered_at' => microtime(true), 'pid' => getmypid(),
        'binary' => PHP_BINARY, 'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'settings' => $settings,
        'source_sha256' => hash_file('sha256', __FILE__), 'source_mtime' => filemtime(__FILE__),
        'opcache_status' => function_exists('opcache_get_status') ? opcache_get_status(true) : null,
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    if (!stream_wrapper_register('exportfault', ExportTestOutput::class)) throw new RuntimeException('Cannot install output fault.');
    return fopen('exportfault://' . $_GET['write_fault'], 'w+b');
}
PHP;
            file_put_contents("$root/bbf_export.php", $injected . $shim);
            $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
            $http = static fn(string $path) => export_http($server, $path);
            foreach ($expected as $endpoint => $body) {
                foreach (['header-zero', 'row-zero', 'header-short', 'row-short', 'header-false', 'row-false', 'header-recover', 'row-recover'] as $fault) {
                    $tag = "output $fault $endpoint";
                    $path = $endpoint . '&last=2&write_fault=' . $fault;
                    $before = strlen(export_log("$root/logs/access-audit.php"));
                    if (is_file("$root/logs/export-writes.json")) unlink("$root/logs/export-writes.json");
                    if (is_file("$root/logs/export-runtime.json")) unlink("$root/logs/export-runtime.json");
                    $recover = str_ends_with($fault, '-recover');
                    if ($recover) {
                        $r = $http($path);
                        export_check($r['code'] === 200 && str_contains(strtolower($r['headers']), 'text/csv'), "$tag CSV response");
                        export_check($r['body'] === $body, "$tag exact full bytes, no dropped or duplicated suffix");
                        export_check(export_rows($r['body']) === export_rows($body) && count(export_rows($r['body'])) === 3,
                            "$tag all quoted multiline, backslash, Unicode and sanitized cells round trip");
                    } else {
                        $capture = static function (string $url) use ($http, &$r): array { return $r = $http($url); };
                        export_failed_request($capture, $path, $root, $tag, 500);
                    }
                    $entries = array_values(array_filter(array_map(static fn($line) => json_decode($line, true),
                        explode("\n", substr(export_log("$root/logs/access-audit.php"), $before))), 'is_array'));
                    if ($recover) {
                        $action = str_starts_with($endpoint, 'viewer.php') ? 'viewer_export' : 'api_export';
                        export_check(count($entries) === 2 && array_column($entries, 'result') === ['attempted', 'completed']
                            && array_column($entries, 'action') === [$action, $action]
                            && array_column($entries, 'form') === ['alpha', 'alpha']
                            && ($entries[1]['result_count'] ?? null) === 2, "$tag completed audit only for full two-row preparation");
                    }
                    $writes = json_decode(export_log("$root/logs/export-writes.json"), true) ?? [];
                    $targetWrites = str_starts_with($fault, 'row-') ? array_slice($writes, 1) : $writes;
                    $first = str_ends_with($fault, '-false') ? false : (str_ends_with($fault, '-zero') ? 0 : 3);
                    export_check($targetWrites !== [] && $targetWrites[0] === $first
                        && (!str_starts_with($fault, 'row-') || ($writes[0] ?? 0) > 3)
                        && (!str_ends_with($fault, '-short') || in_array(0, $targetWrites, true))
                        && (!$recover || (in_array(0, $targetWrites, true) && array_sum($writes) === strlen($body))),
                        "$tag actual injected byte counts and stream closure verified");
                    print 'EVIDENCE ' . json_encode(['fault' => $fault, 'endpoint' => $endpoint, 'http' => $r['code'],
                        'body_bytes' => strlen($r['body']), 'spool_bytes' => array_sum($writes),
                        'write_prefix' => array_slice($writes, 0, 5), 'write_calls' => count($writes),
                        'audit' => array_column($entries, 'result'), 'result_count' => $entries[1]['result_count'] ?? null], JSON_THROW_ON_ERROR) . "\n";
                }
            }
        }
        if ($storage === 'sqlite') {
            // This database and table were created above in the owned fixture only.
            $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP TABLE bbf_submissions');
            $pdo = null;
            foreach (['viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $endpoint) {
                export_failed_request($http, $endpoint, $root, "sqlite missing table $endpoint", 500);
            }
        }
    } catch (Throwable $error) {
        export_evidence('Uncaught fixture exception: ' . (string)$error);
        throw $error;
    } finally {
        $pdo = null;
        bbf_test_stop_server($server);
        export_final_evidence($root);
        bbf_test_cleanup($root);
    }
}
print 'Export regression: ' . ($checks - count($failures)) . " passed, " . count($failures) . " failed.\n";
exit($failures ? 1 : 0);
