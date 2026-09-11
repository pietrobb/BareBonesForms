<?php
/**
 * G3 storage regression benchmark. Run: php tests/review-storage-benchmark.php
 * Optional: --repeats=2..5 (default 3). No servers, root config, or operator data.
 *
 * Every measurement dispatches an endpoint copy in a fresh restricted CLI
 * process. Fixture creation, process startup, and parent-side response validation
 * are outside the timed interval. stdout goes directly to disk, including export;
 * PHP peak memory is measured in the producer, not the response-reading parent.
 * open_basedir is disabled (Windows path-check overhead); only owned copies/config run.
 * OS cache is not flushed; no cold-disk/HTTP claims. Files retain O(N) path metadata.
 *
 * Workload: (8 viewer + 5 API paths) x 4 datasets x repeats, plus parity extras.
 * Eager controls run once/path/dataset for first/deep/search/export, both endpoints.
 * Stats/dashboard measure real multiple scans. No machine-specific time gate.
 * Dashboard's existing FIVE candidates per form is tested, not global-newest-20.
 * SQL/routing, concurrent writers, permissions, and export fault injection belong
 * to the separate review suites; this artifact does not claim those gates passed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
date_default_timezone_set('UTC');

// A child can only execute copies in its parent's random, marked installation.
if (($argv[1] ?? '') === '--child') {
    $root = realpath(dirname(__DIR__));
    $key = $argv[2] ?? '';
    $marker = $root . '/benchmark-owner';
    if (!$root || !is_file($marker) || strlen($key) !== 64
        || !hash_equals(trim(file_get_contents($marker)), $key)
        || realpath(getenv('BBF_BENCH_ROOT') ?: '') !== $root) {
        fwrite(STDERR, "Refusing unowned benchmark child.\n"); exit(2);
    }
    $request = json_decode(file_get_contents($root . '/request.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($request['endpoint'] ?? '', ['viewer.php', 'submissions.php'], true)) exit(2);
    $_GET = $request['query']; $_POST = $_COOKIE = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'benchmark.invalid';
    $_SERVER['HTTP_X_BBF_TOKEN'] = $key;
    $_SERVER['SCRIPT_NAME'] = '/' . $request['endpoint'];
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    http_response_code(200);
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $baseUsed = memory_get_usage(false); $baseAllocated = memory_get_usage(true);
    $started = hrtime(true);
    register_shutdown_function(static function () use ($root, $baseUsed, $baseAllocated, $started): void {
        // Capture before encoding metrics; include endpoint bootstrap and shutdown-to-dispatch exit.
        $metrics = ['ms' => (hrtime(true) - $started) / 1e6,
            'peak_used' => memory_get_peak_usage(false), 'peak_allocated' => memory_get_peak_usage(true),
            'delta_used' => max(0, memory_get_peak_usage(false) - $baseUsed),
            'delta_allocated' => max(0, memory_get_peak_usage(true) - $baseAllocated),
            'status' => http_response_code() ?: 200, 'last_error' => error_get_last()];
        file_put_contents($root . '/metrics.json', json_encode($metrics, JSON_THROW_ON_ERROR));
    });
    require $root . '/' . $request['endpoint'];
    exit;
}

require_once __DIR__ . '/test-isolation-helper.php';
$repeats = 3;
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--repeats=([2-5])$/D', $arg, $match)) {
        fwrite(STDERR, "Usage: php tests/review-storage-benchmark.php [--repeats=2..5]\n"); exit(2);
    }
    $repeats = (int)$match[1];
}
if (PHP_VERSION_ID < 80100 || !function_exists('proc_open') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "Required: PHP >= 8.1, proc_open and mbstring; not silently skipped.\n"); exit(2);
}
$checks = 0; $failures = []; $measurements = []; $eagerMeasurements = []; $requests = 0;
$day = gmdate('Y-m-d');
function bench_check(bool $ok, string $label): void {
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures[] = $label; print "FAIL $label\n"; }
}
function bench_equal($actual, $expected, string $label): void {
    $ok = $actual === $expected;
    if (!$ok) $label .= ' expected=' . substr(json_encode($expected), 0, 450)
        . ' actual=' . substr(json_encode($actual), 0, 450);
    bench_check($ok, $label);
}
function bench_put(string $path, string $bytes): void {
    if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('Fixture write failed: ' . $path);
}
function bench_csv($fp, array $row): void {
    if (fputcsv($fp, $row, ',', '"', '') === false) throw new RuntimeException('Fixture CSV write failed.');
}
function bench_id(int $i): string { return sprintf('bbf_%06d', $i); }
function bench_keys(): array {
    return array_merge(array_map(static fn($i) => 'answer' . $i, range(0, 19)),
        ['nested', 'hidden', 'home_city', 'zero', 'false', 'formula']);
}
function bench_form(string $id): array {
    $fields = [];
    foreach (array_slice(bench_keys(), 0, 20) as $key) $fields[] = ['name' => $key, 'type' => 'text'];
    return ['id' => $id, 'name' => $id, 'templates' => ['person' => [['name' => 'city', 'type' => 'text']]],
        'fields' => array_merge($fields, [
            ['name' => 'container', 'type' => 'group', 'fields' => [['name' => 'nested', 'type' => 'text']]],
            ['name' => 'hidden', 'type' => 'hidden'],
            ['name' => 'contact', 'type' => 'group', 'use' => 'person', 'prefix' => 'home_'],
            ['name' => 'zero', 'type' => 'number'], ['name' => 'false', 'type' => 'text'],
            ['name' => 'formula', 'type' => 'text'], ['name' => 'section', 'type' => 'section']])];
}
function bench_record(int $i, int $n, string $day, string $form = 'alpha'): array {
    $data = [];
    for ($k = 0; $k < 20; $k++) $data['answer' . $k] = str_pad("answer-$k-$i, \"quoted\" ", 140, 'x');
    $data['answer1'] = "line one, \"quoted\"\nline two\r\nbackslash\\\"quote and trailing \\";
    if ($i === $n - 5) $data['answer0'] = 'Literal %_ NeEdLe';
    $data += ['nested' => "nested-$i", 'hidden' => "hidden-$i", 'home_city' => 'Žilina',
        'zero' => 0, 'false' => false, 'formula' => '=1+1'];
    if ($i === 0) $data += ['retired' => 'only-in-oldest', 'keyneedle' => 'ordinary value'];
    $midnight = strtotime($day . ' UTC');
    $stamp = $midnight - 86400 + $i;
    if ($i <= 1) $stamp = $midnight - 40 * 86400 + $i;
    elseif ($i === 2) $stamp = $midnight - 10 * 86400;
    elseif ($i === $n - 3) $stamp = $midnight;
    elseif ($i === $n - 2) $stamp = $midnight + 12 * 3600;
    elseif ($i === $n - 1) $stamp = $midnight + 86399;
    return ['id' => bench_id($i), 'form' => $form, 'data' => $data,
        'meta' => ['submitted' => gmdate('Y-m-d\TH:i:s\Z', $stamp)]];
}
// Independent fixture codec; do not call production export/search/read functions for expectations.
function bench_cell($value): string {
    $value = (string)$value;
    return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
}
function bench_order(string $storage, int $n): array {
    $order = range($n - 1, 0);
    if ($storage === 'file') {
        // Rewritten old record first; equal newest mtimes use lexical filename order.
        $order = array_merge([1, $n - 2, $n - 1], array_values(array_filter(range($n - 3, 0), static fn($i) => $i !== 1)));
    }
    return $order;
}
function bench_install(string $storage, int $n, string $day): array {
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/bbf benchmark ' . bin2hex(random_bytes(16));
    if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create benchmark installation.');
    $GLOBALS['bbf_test_roots'][$root] = true;
    register_shutdown_function(static function () use ($root): void { bbf_test_cleanup($root); });
    foreach (['tests', 'forms', 'submissions', 'logs', 'sessions', 'tmp'] as $dir) mkdir($root . '/' . $dir, 0700);
    foreach (['viewer.php', 'submissions.php', 'bbf_auth.php', 'bbf_functions.php', 'bbf_storage.php', 'bbf_delivery.php', 'bbf_outbox.php', 'bbf_read.php', 'bbf_export.php', 'bbf_review.php', 'bbf_versions.php'] as $file) {
        bbf_test_copy(dirname(__DIR__) . '/' . $file, $root . '/' . $file);
    }
    bbf_test_copy(__FILE__, $root . '/tests/review-storage-benchmark.php');
    $key = bin2hex(random_bytes(32));
    bench_put($root . '/benchmark-owner', $key);
    $config = ['api_token' => $key, 'storage' => $storage, 'forms_dir' => $root . '/forms',
        'submissions_dir' => $root . '/submissions', 'logs_dir' => $root . '/logs', 'lang' => 'en'];
    bench_put($root . '/config.php', '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
    bench_put($root . '/forms/alpha.json', json_encode(bench_form('alpha'), JSON_THROW_ON_ERROR));
    $keys = array_merge(bench_keys(), ['retired', 'keyneedle']);
    $fp = null;
    if ($storage === 'file') mkdir($root . '/submissions/alpha', 0700);
    else { $fp = fopen($root . '/submissions/alpha.csv', 'wb'); bench_csv($fp, array_merge(['_id', '_submitted', '_ip', '_user_agent'], $keys)); }
    for ($i = 0; $i < $n; $i++) {
        $sub = bench_record($i, $n, $day);
        if ($fp) {
            $row = [$sub['id'], $sub['meta']['submitted'], '', ''];
            foreach ($keys as $field) $row[] = bench_cell($sub['data'][$field] ?? '');
            bench_csv($fp, $row);
        } else {
            $path = $root . '/submissions/alpha/' . $sub['id'] . '.json';
            bench_put($path, json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $mtime = 1700000000 + ($i === 1 ? $n + 1 : ($i >= $n - 2 ? $n : $i));
            if (!touch($path, $mtime)) throw new RuntimeException('Cannot set fixture mtime.');
        }
    }
    if ($fp) {
        // Blank, absent-ID and too-wide logical rows must not inflate counts or leak into export.
        fwrite($fp, "\n"); bench_csv($fp, ['', $day]);
        bench_csv($fp, array_fill(0, count($keys) + 5, 'corrupt-too-wide'));
        fclose($fp);
    } else {
        $bad = ['bbf_badjson' => '{broken', 'bbf_scalar' => '42', 'bbf_badshape' => '{"data":42}',
            'bbf_wrongform' => json_encode(['id' => 'bbf_wrongform', 'form' => 'other', 'data' => [], 'meta' => []]),
            'bbf_wrongid' => json_encode(['id' => 'bbf_other', 'form' => 'alpha', 'data' => [], 'meta' => []])];
        foreach ($bad as $id => $raw) bench_put($root . '/submissions/alpha/' . $id . '.json', $raw);
        // Simulate payment rewriting an old record today: order changes, submitted-time counts must not.
        touch($root . '/submissions/alpha/' . bench_id(1) . '.json', strtotime($day) + 3600);
    }
    print "Fixture $storage/$n at $root (20 ~140-byte fields, multiline, invalid records)\n";
    return [$root, $key];
}
function bench_request(string $root, string $key, string $endpoint, array $query): array {
    global $requests;
    $requests++;
    bench_put($root . '/request.json', json_encode(['endpoint' => $endpoint, 'query' => $query], JSON_THROW_ON_ERROR));
    foreach (['metrics.json', 'response.out', 'child.err', 'logs/php-error.log'] as $file) {
        if (is_file($root . '/' . $file)) unlink($root . '/' . $file);
    }
    $cmd = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'opcache.enable_cli=0',
        '-d', 'memory_limit=256M', '-d', 'date.timezone=UTC', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'error_log=' . $root . '/logs/php-error.log', '-d', 'open_basedir=',
        '-d', 'sys_temp_dir=' . $root . '/tmp', '-d', 'session.save_path=' . $root . '/sessions',
        '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_connect,exec,shell_exec,system,passthru,popen,proc_open',
        $root . '/tests/review-storage-benchmark.php', '--child', $key];
    $env = getenv();
    unset($env['BBF_TEST_LEASE'], $env['BBF_TEST_LEASE_KEY'], $env['PHP_CLI_SERVER_WORKERS']);
    $env['BBF_BENCH_ROOT'] = $root; $env['TMP'] = $env['TEMP'] = $env['TMPDIR'] = $root . '/tmp';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $root . '/response.out', 'w'],
        2 => ['file', $root . '/child.err', 'w']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot launch benchmark child.');
    fclose($pipes[0]); $deadline = microtime(true) + 180; $exit = null;
    try {
        do {
            $state = proc_get_status($proc);
            if (!$state['running']) { $exit = $state['exitcode']; break; }
            if (microtime(true) > $deadline) { proc_terminate($proc); throw new RuntimeException('Child exceeded 180-second safety timeout.'); }
            usleep(10000);
        } while (true);
    } finally { $closed = proc_close($proc); }
    if ($exit === -1) $exit = $closed;
    $diagnostics = file_get_contents($root . '/child.err');
    if (is_file($root . '/logs/php-error.log')) $diagnostics .= file_get_contents($root . '/logs/php-error.log');
    $tag = $endpoint . '?' . http_build_query($query);
    bench_equal($exit, 0, "$tag child exit");
    bench_equal(trim($diagnostics), '', "$tag no runtime warnings/errors");
    if (!is_file($root . '/metrics.json')) throw new RuntimeException('Missing child metrics: ' . $tag . ' ' . $diagnostics);
    $metrics = json_decode(file_get_contents($root . '/metrics.json'), true, 512, JSON_THROW_ON_ERROR);
    bench_equal($metrics['status'], 200, "$tag status");
    bench_equal($metrics['last_error'], null, "$tag no suppressed PHP error");
    return $metrics;
}
function bench_validate(string $root, string $storage, int $n, string $day, string $case, string $endpoint): void {
    $tag = "$storage/$n $endpoint $case";
    $order = bench_order($storage, $n);
    if ($case === 'export' || $case === 'export-search') {
        $expectedOrder = $case === 'export' ? $order : [$n - 5];
        $fp = fopen($root . '/response.out', 'rb');
        try {
            $header = fgetcsv($fp, 0, ',', '"', '');
            $keys = array_merge(bench_keys(), ($storage === 'csv' || $case === 'export') ? ['retired', 'keyneedle'] : []);
            bench_equal($header, array_merge(['id', 'submitted'], $keys), "$tag exact expanded/history header");
            $count = 0; $bad = 0; $firstBad = '';
            while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
                $i = $expectedOrder[$count] ?? null;
                $expected = null;
                if ($i !== null) {
                    $sub = bench_record($i, $n, $day);
                    $expected = [$sub['id'], $sub['meta']['submitted']];
                    foreach ($keys as $field) $expected[] = bench_cell($sub['data'][$field] ?? '');
                }
                if ($row !== $expected) { $bad++; if ($firstBad === '') $firstBad = " row=$count id=" . ($row[0] ?? 'missing'); }
                $count++;
            }
            bench_equal($count, count($expectedOrder), "$tag uncapped exact row count");
            bench_equal($bad, 0, "$tag every ID/order/cell (multiline, zero, false, formula, oldest history)$firstBad");
        } finally { fclose($fp); }
        return;
    }
    $body = json_decode(file_get_contents($root . '/response.out'), true, 512, JSON_THROW_ON_ERROR);
    if ($case === 'count') {
        bench_equal(array_column($body, 'id'), ['alpha'], "$tag form IDs");
        bench_equal(array_column($body, 'count'), [$n], "$tag valid-record count, corrupt omitted");
    } elseif ($case === 'stats') {
        bench_equal($body, ['total' => $n, 'today' => 3, 'this_week' => $n - 3, 'this_month' => $n - 2], "$tag submitted date not rewritten mtime");
    } elseif ($case === 'dashboard') {
        foreach (['total' => $n, 'today' => 3, 'this_week' => $n - 3] as $field => $value) bench_equal($body[$field] ?? null, $value, "$tag $field");
        bench_equal($body['per_form'] ?? null, [['id' => 'alpha', 'name' => 'alpha', 'total' => $n, 'today' => 3]], "$tag per-form counts");
        $recent = array_slice($order, 0, 5);
        usort($recent, static fn($a, $b) => strcmp(bench_record($b, $n, $day)['meta']['submitted'], bench_record($a, $n, $day)['meta']['submitted']));
        bench_equal(array_column($body['recent'] ?? [], 'id'), array_map('bench_id', $recent), "$tag documented five-per-form recent IDs");
    } else {
        $total = $n;
        $expected = match ($case) {
            'first' => array_slice($order, 0, 20), 'deep' => array_slice($order, intdiv($n, 2), 20),
            'past-end', 'empty', 'key-only' => [], 'search' => [$n - 5],
            'date' => array_values(array_filter($order, static fn($i) => $i >= $n - 3)),
            default => throw new RuntimeException('Unknown validation case ' . $case)
        };
        if (in_array($case, ['empty', 'key-only'], true)) $total = 0;
        elseif ($case === 'search') $total = 1;
        elseif ($case === 'date') $total = 3;
        bench_equal($body['total'] ?? null, $total, "$tag exact total");
        $rows = $body['submissions'] ?? [];
        bench_equal(array_column($rows, 'id'), array_map('bench_id', $expected), "$tag exact IDs/order");
        foreach ($rows as $pos => $sub) {
            if (!isset($expected[$pos])) continue;
            $wanted = bench_record($expected[$pos], $n, $day);
            if ($storage === 'csv') {
                $wanted['data'] = array_replace(array_fill_keys(array_merge(bench_keys(), ['retired', 'keyneedle']), ''), array_map(static fn($v) => (string)$v, $wanted['data']));
            }
            bench_equal($sub['data'] ?? null, $wanted['data'], "$tag payload " . $wanted['id']);
            bench_equal($sub['meta']['submitted'] ?? null, $wanted['meta']['submitted'], "$tag timestamp " . $wanted['id']);
            bench_equal($sub['form'] ?? null, 'alpha', "$tag form identity");
        }
    }
}
function bench_query(string $case, int $n, string $day, bool $api = false): array {
    $query = ['form' => 'alpha'];
    if (!$api) $query['action'] = match ($case) { 'count' => 'list_forms', 'stats', 'dashboard' => $case,
        'export', 'export-search' => 'export', default => 'submissions' };
    if (str_starts_with($case, 'export')) { if ($api) $query['format'] = 'csv'; }
    else $query['limit'] = '20';
    if ($case === 'deep') $query['offset'] = (string)intdiv($n, 2);
    if ($case === 'past-end') $query['offset'] = (string)($n + 2);
    if ($case === 'empty') $query['q'] = 'definitely-absent-value';
    if ($case === 'key-only') $query['q'] = 'keyneedle';
    if ($case === 'search' || $case === 'export-search') $query['q'] = '%_ nEeDlE';
    if ($case === 'date') $query += ['from' => $day, 'to' => $day];
    return $query;
}
function bench_range(array $values, float $divisor = 1): string {
    sort($values, SORT_NUMERIC); $n = count($values);
    $median = ($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2;
    return sprintf('%.2f [%.2f..%.2f]', $median / $divisor, $values[0] / $divisor, $values[$n - 1] / $divisor);
}
// One definition of each producer gate, shared by positive and negative controls.
// File readers may retain O(N) filename metadata, not O(N) decoded payloads.
function bench_absolute_budget(string $storage): int { return ($storage === 'file' ? 24 : 12) * 1048576; }
function bench_absolute_ok(string $storage, int $bytes): bool { return $bytes < bench_absolute_budget($storage); }
function bench_scaling_budget(string $storage, int $small): int {
    return $storage === 'file' ? $small * 4 + 4 * 1048576 : $small * 2 + 2 * 1048576;
}
function bench_scaling_ok(string $storage, int $small, int $large): bool {
    return $large <= bench_scaling_budget($storage, $small);
}
function bench_cases(string $endpoint): array {
    return $endpoint === 'viewer.php' ? ['first', 'deep', 'empty', 'search', 'count', 'stats', 'dashboard', 'export']
        : ['first', 'deep', 'empty', 'search', 'export'];
}
function bench_hash(string $root): string {
    $hash = hash_file('sha256', $root . '/response.out');
    if ($hash === false) throw new RuntimeException('Cannot hash complete response.');
    return $hash;
}
/** Mutate only the marked, owned reader copy; endpoints and fixtures stay unchanged.
 * Materialize the actual consumed iterator, not unrelated ballast. Filtering AFTER
 * materialization makes narrow-search controls meaningful while preserving output.
 */
function bench_eager_controls(string $root, string $key, string $storage, int $n, string $day, array $hashes): array {
    $path = $root . '/bbf_read.php';
    if (empty($GLOBALS['bbf_test_roots'][$root]) || is_link($root) || is_link($path)
        || !is_file($root . '/benchmark-owner')
        || !hash_equals(trim(file_get_contents($root . '/benchmark-owner')), $key)) {
        throw new RuntimeException('Refusing to mutate an unowned reader.');
    }
    $original = file_get_contents($path);
    if ($original === false) throw new RuntimeException('Cannot save owned reader for restoration.');
    $mutated = $original;
    foreach (['files', 'csv'] as $reader) {
        $needle = 'function bbf_read_' . $reader . '(';
        if (substr_count($mutated, $needle) !== 1) throw new RuntimeException('Eager control reader anchor changed: ' . $reader);
        $mutated = str_replace($needle, 'function bench_lazy_' . $reader . '(', $mutated);
    }
    $mutated .= <<<'PHP'

// Benchmark-only eager regression; the array is the source actually yielded to callers.
function bbf_read_files(string $formId, string $dir, ?string $from = null, ?string $to = null, ?string $q = null): Generator {
    $rows = iterator_to_array(bench_lazy_files($formId, $dir), false);
    $bounds = bbf_read_bounds($from, $to);
    foreach ($rows as $sub) if (bbf_read_matches($sub, $bounds, $q)) yield $sub;
}
function bbf_read_csv(string $formId, string $dir, ?string $from = null, ?string $to = null, ?string $q = null, ?string $id = null): Generator {
    $rows = iterator_to_array(bench_lazy_csv($formId, $dir), false);
    $bounds = bbf_read_bounds($from, $to);
    foreach ($rows as $sub) {
        if (($id === null || $sub['id'] === $id) && bbf_read_matches($sub, $bounds, $q)) {
            yield $sub;
            if ($id !== null) return;
        }
    }
}
PHP;
    $samples = [];
    try {
        bench_put($path, $mutated);
        foreach (['viewer.php', 'submissions.php'] as $endpoint) {
            foreach (['first', 'deep', 'search', 'export'] as $case) {
                $before = count($GLOBALS['failures']);
                $m = bench_request($root, $key, $endpoint, bench_query($case, $n, $day, $endpoint === 'submissions.php'));
                bench_validate($root, $storage, $n, $day, $case, $endpoint);
                $hash = bench_hash($root);
                bench_equal($hash, $hashes[$endpoint][$case], "$storage/$n $endpoint $case eager full-response SHA256 unchanged");
                $samples[$endpoint][$case] = $m;
                if ($n === 10000) bench_check(!bench_absolute_ok($storage, $m['delta_used']), "$storage/$n $endpoint $case eager rejected by SAME absolute gate");
                print sprintf("%s EAGER %-4s %5d %-15s %-9s %.2f ms | delta %d bytes (%.2f MiB) | absolute budget %d bytes | SHA256 %s\n",
                    count($GLOBALS['failures']) === $before ? 'PASS' : 'FAIL', $storage, $n, $endpoint, $case,
                    $m['ms'], $m['delta_used'], $m['delta_used'] / 1048576, bench_absolute_budget($storage), $hash);
            }
        }
    } finally {
        bench_put($path, $original);
        bench_equal(hash_file('sha256', $path), hash('sha256', $original), "$storage/$n owned reader restored byte-for-byte");
    }
    return $samples;
}
function bench_csv_edges(string $root, string $key): void {
    // Tiny parser controls are separate from the realistic large datasets.
    bench_put($root . '/forms/edge.json', json_encode(['id' => 'edge', 'fields' => []], JSON_THROW_ON_ERROR));
    $fp = fopen($root . '/submissions/edge.csv', 'wb');
    bench_csv($fp, ['_id', '_submitted', 'message', 'later']);
    bench_csv($fp, ['bbf_full', '2020-01-01T00:00:00Z', "one\n\"two\",three", 'history']);
    bench_csv($fp, ['bbf_short', '2020-01-02T00:00:00Z']);
    bench_csv($fp, ['', 'ignored']); bench_csv($fp, ['bbf_wide', '', '', '', 'extra']); fwrite($fp, "\n"); fclose($fp);
    foreach (['viewer.php', 'submissions.php'] as $endpoint) {
        $q = ['form' => 'edge']; if ($endpoint === 'viewer.php') $q['action'] = 'submissions';
        bench_request($root, $key, $endpoint, $q);
        $body = json_decode(file_get_contents($root . '/response.out'), true, 512, JSON_THROW_ON_ERROR);
        bench_equal($body['total'] ?? null, 2, "$endpoint CSV short padded, wide/blank/missing-ID omitted");
        bench_equal(array_column($body['submissions'] ?? [], 'id'), ['bbf_short', 'bbf_full'], "$endpoint CSV logical record IDs");
        bench_equal($body['submissions'][0]['data'] ?? null, ['message' => '', 'later' => ''], "$endpoint short CSV padding");
        bench_equal($body['submissions'][1]['data'] ?? null, ['message' => "one\n\"two\",three", 'later' => 'history'], "$endpoint multiline CSV logical record");
    }
    // One lightweight 10,001-record form: uncapped oldest detail AND default export.
    bench_put($root . '/forms/oldest.json', json_encode(['id' => 'oldest', 'fields' => []], JSON_THROW_ON_ERROR));
    $fp = fopen($root . '/submissions/oldest.csv', 'wb');
    bench_csv($fp, ['_id', '_submitted', 'message']);
    for ($i = 0; $i <= 10000; $i++) bench_csv($fp, [bench_id($i), '2020-01-01T00:00:00Z', $i === 0 ? "oldest\n\"history\"" : (string)$i]);
    fclose($fp);
    foreach (['viewer.php', 'submissions.php'] as $endpoint) {
        $q = ['form' => 'oldest', 'id' => bench_id(0)];
        if ($endpoint === 'viewer.php') $q['action'] = 'detail';
        $m = bench_request($root, $key, $endpoint, $q);
        $body = json_decode(file_get_contents($root . '/response.out'), true, 512, JSON_THROW_ON_ERROR);
        $sub = $endpoint === 'viewer.php' ? ($body['submission'] ?? null) : $body;
        bench_equal($sub['id'] ?? null, bench_id(0), "$endpoint uncapped10001 oldest detail ID");
        bench_equal($sub['data']['message'] ?? null, "oldest\n\"history\"", "$endpoint uncapped10001 oldest detail multiline history");
        print sprintf("CONTROL csv/10001 %s oldest-detail %.2f ms, %.2f MiB incremental\n", $endpoint, $m['ms'], $m['delta_used'] / 1048576);
        $q = ['form' => 'oldest'];
        if ($endpoint === 'viewer.php') $q['action'] = 'export'; else $q['format'] = 'csv';
        bench_request($root, $key, $endpoint, $q);
        $fp = fopen($root . '/response.out', 'rb');
        $header = fgetcsv($fp, 0, ',', '"', ''); $count = $bad = 0;
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            $i = 10000 - $count;
            if ($row !== [bench_id($i), '2020-01-01T00:00:00Z', $i === 0 ? "oldest\n\"history\"" : (string)$i]) $bad++;
            $count++;
        }
        fclose($fp);
        bench_equal($header, ['id', 'submitted', 'message'], "$endpoint uncapped10001 header");
        bench_equal($count, 10001, "$endpoint uncapped10001 full export count");
        bench_equal($bad, 0, "$endpoint uncapped10001 all IDs/order/cells");
    }
}

print "G3 isolated storage benchmark PHP " . PHP_VERSION . ' / ' . PHP_OS_FAMILY . " / UTC $day / repeats=$repeats\n";
print "Reported: median [min..max]; endpoint dispatch time; PHP producer peak, not OS RSS.\n";
print "Baseline diagnosis: file first20 1k=543ms/6.80MiB, 10k=7342ms/68.03MiB; CSV 65ms/5.86MiB, 610ms/58.72MiB.\n";
print "Baseline was single-run extracted loaders with different fixtures; timing is contextual, not a speedup claim.\n";
$startedSuite = microtime(true);
foreach (['file', 'csv'] as $storage) {
    foreach ([1000, 10000] as $n) {
        $root = null;
        try {
            [$root, $key] = bench_install($storage, $n, $day);
            $hashes = [];
            foreach (['viewer.php', 'submissions.php'] as $endpoint) {
                foreach (bench_cases($endpoint) as $case) {
                    $samples = []; $before = count($failures);
                    for ($repeat = 0; $repeat < $repeats; $repeat++) {
                        $m = bench_request($root, $key, $endpoint, bench_query($case, $n, $day, $endpoint === 'submissions.php'));
                        bench_validate($root, $storage, $n, $day, $case, $endpoint);
                        $hash = bench_hash($root);
                        if ($repeat === 0) $hashes[$endpoint][$case] = $hash;
                        else bench_equal($hash, $hashes[$endpoint][$case], "$storage/$n $endpoint $case full-response repeat hash");
                        $samples[] = $m;
                        bench_check(bench_absolute_ok($storage, $m['delta_used']), "$storage/$n $endpoint $case bounded producer payload");
                    }
                    $measurements[$storage][$n][$endpoint][$case] = $samples;
                    print sprintf("%s %-4s %5d %-15s %-9s ms %s | delta MiB %s | peak MiB %s | allocated delta MiB %s\n",
                        count($failures) === $before ? 'PASS' : 'FAIL', $storage, $n, $endpoint, $case,
                        bench_range(array_column($samples, 'ms')), bench_range(array_column($samples, 'delta_used'), 1048576),
                        bench_range(array_column($samples, 'peak_used'), 1048576), bench_range(array_column($samples, 'delta_allocated'), 1048576));
                }
            }
            foreach (['viewer.php', 'submissions.php'] as $endpoint) {
                // Large-fixture final-day/mtime regression; cheaper extras only at 1k.
                $cases = $n === 1000 ? ['date', 'past-end', 'key-only', 'export-search'] : ['date'];
                foreach ($cases as $case) {
                    bench_request($root, $key, $endpoint, bench_query($case, $n, $day, $endpoint === 'submissions.php'));
                    bench_validate($root, $storage, $n, $day, $case, $endpoint);
                }
            }
            $eagerMeasurements[$storage][$n] = bench_eager_controls($root, $key, $storage, $n, $day, $hashes);
            if ($storage === 'csv' && $n === 10000) bench_csv_edges($root, $key);
            print "Validated $storage/$n API parity and date/search/record controls.\n";
        } catch (Throwable $error) {
            bench_check(false, "$storage/$n exception: " . $error->getMessage());
        } finally {
            if ($root !== null) {
                bbf_test_cleanup($root);
                bench_check(!file_exists($root), "$storage/$n disposable installation cleaned");
            }
        }
    }
}
foreach (['file', 'csv'] as $storage) {
    foreach (['viewer.php', 'submissions.php'] as $endpoint) {
        foreach (bench_cases($endpoint) as $case) {
            $small = $measurements[$storage][1000][$endpoint][$case] ?? [];
            $large = $measurements[$storage][10000][$endpoint][$case] ?? [];
            $tag = "$storage $endpoint $case";
            if (!$small || !$large) { bench_check(false, "$tag missing scaling measurements"); continue; }
            $before = count($failures);
            $lo = max(array_column($small, 'delta_used')); $hi = max(array_column($large, 'delta_used'));
            bench_check(bench_scaling_ok($storage, $lo, $hi), "$tag 10x records bounded growth");
            print sprintf("%s SCALE %s streaming %d -> %d bytes | scaling budget %d bytes | absolute budget %d bytes\n",
                count($failures) === $before ? 'PASS' : 'FAIL', $tag, $lo, $hi,
                bench_scaling_budget($storage, $lo), bench_absolute_budget($storage));
            if (!in_array($case, ['first', 'deep', 'search', 'export'], true)) continue;
            $eagerLo = $eagerMeasurements[$storage][1000][$endpoint][$case]['delta_used'] ?? null;
            $eagerHi = $eagerMeasurements[$storage][10000][$endpoint][$case]['delta_used'] ?? null;
            if ($eagerLo === null || $eagerHi === null) { bench_check(false, "$tag missing eager scaling measurements"); continue; }
            $before = count($failures);
            // Apply the SAME predicate to the eager implementation's own 1k/10k pair,
            // as well as the unchanged streaming budget. Neither is an arbitrary threshold.
            bench_check(!bench_absolute_ok($storage, $eagerHi), "$tag eager10k rejected by SAME absolute gate");
            bench_check(!bench_scaling_ok($storage, $eagerLo, $eagerHi), "$tag eager 10x growth rejected by SAME scaling gate with eager1k baseline");
            bench_check(!bench_scaling_ok($storage, $lo, $eagerHi), "$tag eager10k rejected by unchanged streaming scaling budget");
            print sprintf("%s REJECT %s eager %d -> %d bytes | own scaling budget %d bytes | streaming scaling budget %d bytes | absolute budget %d bytes\n",
                count($failures) === $before ? 'PASS' : 'FAIL', $tag, $eagerLo, $eagerHi,
                bench_scaling_budget($storage, $eagerLo), bench_scaling_budget($storage, $lo), bench_absolute_budget($storage));
        }
    }
}
bench_equal(gmdate('Y-m-d'), $day, 'UTC fixture day did not roll over during stats measurements');
print "NOTE dashboard preserves five candidates/form (not true global newest20); stats/dashboard remain multi-scan.\n";
print sprintf("RESULT %d checks, %d failures; %d fresh endpoint children; %.2f seconds including setup/validation/cleanup.\n", $checks, count($failures), $requests, microtime(true) - $startedSuite);
if ($failures) { print "FAILURE SUMMARY\n"; foreach ($failures as $failure) print '- ' . $failure . "\n"; }
exit($failures ? 1 : 0);
