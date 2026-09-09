<?php
/** G3 R2.1/R2.2/R2.3. CLI, owned disposable installations only; no live integrations. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
if (getenv('BBF_TEST_LEASE') || getenv('BBF_TEST_LEASE_KEY')) throw new RuntimeException('Requires a fresh owned fixture.');
$checks = 0;
function storage_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    ++$GLOBALS['checks']; print "PASS $label\n";
}
function storage_record(string $id, string $form, array $data): array {
    return ['id' => $id, 'form' => $form, 'data' => $data,
        'meta' => ['submitted' => '2026-09-08T12:00:00Z', 'ip' => '127.0.0.1', 'user_agent' => '=fixture']];
}
function storage_csv(string $path): array {
    $fp = fopen($path, 'rb'); $rows = [];
    try { while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) $rows[] = $row; }
    finally { fclose($fp); }
    return $rows;
}
function storage_config(string $root, array $config): void {
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
}
$root = bbf_test_installation(dirname(__DIR__)); $server = null; $children = []; $pdo = null;
try {
    // The existing helper allowlist is owned by main, not this G3 writer.
    bbf_test_copy(dirname(__DIR__) . '/bbf_storage.php', "$root/bbf_storage.php");
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    define('BBF_LOADED', true);
    require "$root/bbf_functions.php";
    // Load verbatim function declarations, never the endpoint bootstrap or operator config.
    $submit = file_get_contents("$root/submit.php");
    $start = strpos($submit, 'function store(array '); $end = strpos($submit, '// sendEmail, sendSmtp', $start);
    if ($start === false || $end === false) throw new RuntimeException('Storage declaration boundaries changed.');
    $storeFunctions = substr($submit, $start, $end - $start);
    $payment = file_get_contents("$root/payment.php"); $start = strpos($payment, 'function loadSubmission(');
    if ($start === false) throw new RuntimeException('Payment declaration boundary changed.');
    $loadFunction = substr($payment, $start);
    file_put_contents("$root/tests/storage-functions.php", '<?php if (PHP_SAPI !== "cli") exit; ' . $storeFunctions . $loadFunction);
    require "$root/tests/storage-functions.php";
    $config = ['storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'templates_dir' => "$root/templates", 'sqlite' => ['path' => "$root/data/bbf.sqlite"],
        'csrf' => false, 'honeypot_field' => '_hp', 'rate_limit' => 1000, 'lang' => 'en', 'api_token' => 'fixture-only',
        'error_notify' => '', 'stripe' => ['secret_key' => 'sk_test_never_used', 'webhook_secret' => 'fixture-webhook-secret']];
    storage_config($root, $config);
    storage_check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'SQLite driver required');
    foreach (['file', 'csv', 'sqlite', 'mysql'] as $backend) {
        $form = ['storage' => $backend];
        file_put_contents("$root/forms/resolve.json", bbf_storage_json($form));
        storage_check(bbf_effective_storage_config($config, 'resolve')['storage'] === $backend
            && bbf_effective_storage_config($config, 'resolve', $form)['sqlite'] === $config['sqlite'], "$backend override and full config retained");
    }
    foreach (['bogus', false, [], ''] as $bad) {
        storage_check(bbf_effective_storage_config($config, 'resolve', ['storage' => $bad])['storage'] === 'file', 'invalid override uses submit fallback rule');
    }
    storage_check(bbf_effective_storage_config($config, 'deleted')['storage'] === 'file', 'deleted definition preserves global historical fallback');
    file_put_contents("$root/forms/resolve.json", '{bad');
    try { bbf_effective_storage_config($config, 'resolve'); storage_check(false, 'bad definition must not resolve'); }
    catch (JsonException $e) { storage_check(true, 'invalid existing definition fails closed'); }
    unlink("$root/forms/resolve.json");

    $fields = [['name' => 'old', 'type' => 'text'], ['name' => 'common', 'type' => 'text']];
    $old = storage_record('bbf_old', 'evolve', ['old' => "old, quoted \"value\"\nnext line", 'common' => 'before']);
    storage_check(storeCsv($old, $config['submissions_dir'], $fields), 'initial CSV creation');
    $fields = [['name' => 'common'], ['type' => 'group', 'fields' => [['name' => 'new']]], ['type' => 'section', 'name' => 'not_data']];
    $new = storage_record('bbf_new', 'evolve', ['common' => 'after', 'new' => ['a', 'b'], 'extra' => '=formula']);
    storage_check(storeCsv($new, $config['submissions_dir'], $fields), 'CSV evolves with reordered, group and unlisted data fields');
    $rows = storage_csv("$root/submissions/evolve.csv");
    storage_check($rows[0] === ['_id', '_submitted', '_ip', '_user_agent', 'old', 'common', 'new', 'extra'], 'CSV stable historical/new union header');
    storage_check($rows[1][4] === $old['data']['old'] && array_slice($rows[1], 5) === ['before', '', ''], 'historical multiline/quoted values retained and padded');
    storage_check(array_slice($rows[2], 4) === ['', 'after', 'a; b', "'=formula"] && $rows[2][3] === "'=fixture", 'new row aligned, arrays and formula sanitizing preserved');
    storage_check(storeCsv(storage_record('bbf_revert', 'evolve', ['old' => 'restored']), $config['submissions_dir'], [['name' => 'old']]), 'schema reversal does not remove new historical columns');
    $before = file_get_contents("$root/submissions/evolve.csv");
    storage_check(!storeCsv(storage_record('bbf_collision', 'evolve', ['_id' => 'collision']), $config['submissions_dir'], [])
        && file_get_contents("$root/submissions/evolve.csv") === $before, 'reserved CSV metadata collision fails without loss');
    file_put_contents("$root/submissions/broken.csv", "_id,_submitted,_ip,_user_agent,old\nbbf_old,time,ip,ua,old,unmapped\n");
    $broken = file_get_contents("$root/submissions/broken.csv");
    storage_check(!storeCsv(storage_record('bbf_bad', 'broken', ['new' => 'new']), $config['submissions_dir'], [])
        && file_get_contents("$root/submissions/broken.csv") === $broken, 'malformed historical CSV refused byte-for-byte');

    foreach (['file', 'csv', 'sqlite'] as $backend) {
        $cfg = array_replace($config, ['storage' => $backend]);
        $record = storage_record('bbf_valid', $backend, ['answer' => 'Žluťoučký']);
        file_put_contents("$root/forms/$backend.json", bbf_storage_json(['storage' => $backend]));
        storage_check(store($record, $cfg, [['name' => 'answer']]), "$backend valid Unicode write");
        foreach (['data', 'meta', 'id'] as $part) {
            $invalid = $record;
            if ($part === 'id') $invalid['id'] = "bbf_\xff";
            else $invalid[$part]['invalid'] = "\xff";
            storage_check(!store($invalid, $cfg, [['name' => 'answer']]), "$backend rejects invalid UTF-8 in $part");
        }
        if ($backend === 'csv') {
            storage_check(count(storage_csv("$root/submissions/csv.csv")) === 2, 'CSV invalid writes add no records');
            storage_check(!updateSubmissionPayment('bbf_valid', 'csv', 'paid', [], $config), 'CSV payment update unsupported');
            continue;
        }
        storage_check(loadSubmission('bbf_valid', $backend, $config)['data'] === $record['data'], "$backend callback load resolves override and preserves valid record");
        $stripe = ['payment_intent' => 'pi_fixture', 'amount_total' => 1250, 'currency' => 'eur'];
        storage_check(updateSubmissionPayment('bbf_valid', $backend, 'paid', $stripe, $config), "$backend payment update resolves override");
        $paid = loadSubmission('bbf_valid', $backend, $config);
        storage_check($paid['meta']['payment_status'] === 'paid' && $paid['meta']['payment_amount'] == 12.5, "$backend payment metadata durable and readable");
        storage_check(!updateSubmissionPayment('bbf_valid', $backend, 'paid', ['payment_intent' => "\xff"], $config)
            && loadSubmission('bbf_valid', $backend, $config) === $paid, "$backend invalid UTF-8 payment leaves original unchanged");
        storage_check(loadSubmission('bbf_valid', 'missing_form', $cfg) === null, "$backend callback load cannot cross form");
        storage_check(!updateSubmissionPayment('bbf_missing', $backend, 'paid', $stripe, $config), "$backend missing update returns failure");
    }
    // Invalid MySQL input must fail BEFORE even attempting a connection. No DSN is supplied.
    foreach (['data', 'meta', 'id'] as $part) {
        $invalid = storage_record('bbf_mysql', 'mysql', []);
        if ($part === 'id') $invalid['id'] = "\xff"; else $invalid[$part]['invalid'] = "\xff";
        storage_check(!storeMysql($invalid, []), "MySQL invalid UTF-8 $part rejected before connection");
    }
    file_put_contents("$root/forms/mysql.json", '{"storage":"mysql"}');
    storage_check(!updateSubmissionPayment('bbf_mysql', 'mysql', 'paid', ['id' => "\xff"], $config), 'MySQL payment encoding failure rejected before connection');
    $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    storage_check((int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn() === 1, 'SQLite invalid writes create no successful empty rows');
    $pdo->exec("UPDATE bbf_submissions SET meta = '{broken' WHERE id = 'bbf_valid'");
    storage_check(!updateSubmissionPayment('bbf_valid', 'sqlite', 'paid', [], $config)
        && $pdo->query('SELECT meta FROM bbf_submissions')->fetchColumn() === '{broken', 'SQLite corrupt historical JSON not overwritten');
    $pdo = null;

    // Fault injection wraps only builtins in a namespace; production function bodies are verbatim.
    // All targets remain real files beneath this owned root. No runtime test hooks are added.
    $faults = <<<'PHP'
namespace BbfStorageFaults;
function fwrite($fp, $bytes) { if (($GLOBALS['storage_fault'] ?? '') === 'write') return 0; if (($GLOBALS['storage_fault'] ?? '') === 'short') return \fwrite($fp, substr($bytes, 0, 3)); return \fwrite($fp, $bytes); }
function fputcsv($fp, $row, $sep, $quote, $escape) { return ($GLOBALS['storage_fault'] ?? '') === 'csv' ? false : \fputcsv($fp, $row, $sep, $quote, $escape); }
function fflush($fp) { return ($GLOBALS['storage_fault'] ?? '') === 'flush' ? false : \fflush($fp); }
function fclose($fp) { $ok = \fclose($fp); return ($GLOBALS['storage_fault'] ?? '') === 'close' ? false : $ok; }
function rename($from, $to) { return ($GLOBALS['storage_fault'] ?? '') === 'rename' ? false : \rename($from, $to); }
function flock($fp, $mode) { return ($GLOBALS['storage_fault'] ?? '') === 'lock' && $mode === LOCK_EX ? false : \flock($fp, $mode); }
function fopen($path, $mode) { return ($GLOBALS['storage_fault'] ?? '') === 'open' && $mode === 'xb' ? false : \fopen($path, $mode); }
PHP;
    eval($faults . substr(file_get_contents("$root/bbf_storage.php"), 5) . $storeFunctions);
    $faultPath = "$root/submissions/fault.json";
    file_put_contents($faultPath, 'original');
    foreach (['write', 'flush', 'close', 'rename', 'lock', 'open'] as $fault) {
        $GLOBALS['storage_fault'] = $fault;
        storage_check(!BbfStorageFaults\bbf_storage_write_json($faultPath, ['data' => 'replacement'])
            && file_get_contents($faultPath) === 'original', "$fault failure preserves atomic JSON original");
        storage_check(glob($faultPath . '.tmp-*') === [], "$fault cleans unpublished temporary file");
    }
    $GLOBALS['storage_fault'] = 'short';
    storage_check(BbfStorageFaults\bbf_storage_write_json($faultPath, ['data' => 'replacement'])
        && json_decode(file_get_contents($faultPath), true) === ['data' => 'replacement'], 'short writes completed, not reported early');
    foreach (['csv', 'flush', 'close', 'rename', 'lock', 'open'] as $fault) {
        $GLOBALS['storage_fault'] = $fault;
        storage_check(!BbfStorageFaults\storeCsv(storage_record('bbf_fault', 'evolve', ['latest' => 'value']), $config['submissions_dir'], [])
            && file_get_contents("$root/submissions/evolve.csv") === $before, "$fault failure during CSV evolution preserves all historical bytes");
    }
    $GLOBALS['storage_fault'] = '';
    mkdir("$root/submissions/blocked.json");
    storage_check(!bbf_storage_write_json("$root/submissions/blocked.json", ['data' => 'x'])
        && is_dir("$root/submissions/blocked.json"), 'real rename failure does not unlink target');
    file_put_contents("$root/submissions/parent-file", 'keep');
    storage_check(!storeFile(storage_record('bbf_no', 'child', []), "$root/submissions/parent-file")
        && file_get_contents("$root/submissions/parent-file") === 'keep', 'real directory creation failure propagates');

    // Native fputcsv/fwrite on a fault stream backed by a real sibling temp file.
    // Offsets select the header, migrated historical row, or new row, not the encoder buffer.
    $csvNativeFaults = <<<'PHP'
namespace BbfCsvNativeFaults;
class OutputStream {
    public $context;
    private $fp;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$opened): bool {
        $this->fp = \fopen($GLOBALS['csv_native_path'], 'xb');
        return is_resource($this->fp);
    }
    public function stream_write($bytes) {
        $state =& $GLOBALS['csv_native'];
        if ($state['mode'] !== 'control' && $this->position >= $state['start'] && $this->position < $state['end']) {
            if ($state['mode'] === 'zero' || ($state['mode'] === 'stalled-short' && $state['shorts'] > 0)) {
                ++$state['zeros']; return 0;
            }
            ++$state['shorts']; $bytes = substr($bytes, 0, 3);
        }
        $written = \fwrite($this->fp, $bytes);
        if ($written !== false) $this->position += $written;
        return $written;
    }
    public function stream_flush(): bool { return \fflush($this->fp); }
    public function stream_close(): void { \fclose($this->fp); }
    public function stream_stat(): array { return \fstat($this->fp); }
}
function fopen($path, $mode) {
    if ($mode !== 'xb') return \fopen($path, $mode);
    $GLOBALS['csv_native_path'] = $path;
    return \fopen('bbfcsvnative://output', $mode);
}
function fputcsv($fp, $row, $sep, $quote, $escape) {
    $result = \fputcsv($fp, $row, $sep, $quote, $escape);
    $GLOBALS['csv_native']['csv_results'][] = $result;
    return $result;
}
function fwrite($fp, $bytes) {
    // Also expose positive native short results to write_all, not only stream-internal retries.
    $state = $GLOBALS['csv_native']; $offset = array_sum($state['write_results']);
    if ($state['mode'] === 'recoverable-short' && $offset >= $state['start'] && $offset < $state['end']) $bytes = substr($bytes, 0, 3);
    $result = \fwrite($fp, $bytes);
    $GLOBALS['csv_native']['write_results'][] = $result;
    return $result;
}
function rename($from, $to) { ++$GLOBALS['csv_native']['publications']; return \rename($from, $to); }
PHP;
    eval($csvNativeFaults . substr(file_get_contents("$root/bbf_storage.php"), 5) . $storeFunctions);
    storage_check(stream_wrapper_register('bbfcsvnative', BbfCsvNativeFaults\OutputStream::class), 'native CSV fault stream registered');
    $csvPath = "$root/submissions/native-fault.csv";
    $oldHeader = "_id,_submitted,_ip,_user_agent,\"old,\"\"Ž\"\"\"\n";
    $oldRow = "bbf_history,2026-09-08T12:00:00Z,127.0.0.1,'=fixture,\"Žluťoučký, \"\"quoted\"\"\nline\"\n";
    $original = $oldHeader . $oldRow;
    $expectedRecords = [
        rtrim($oldHeader, "\n") . ",\"new,\"\"雪\"\"\"\n",
        rtrim($oldRow, "\n") . ",\n",
        "bbf_native,2026-09-08T12:00:00Z,127.0.0.1,'=fixture,,\"雪, \"\"new\"\"\nline\"\n",
    ];
    $expected = implode('', $expectedRecords);
    $nativeRecord = storage_record('bbf_native', 'native-fault', ['new,"雪"' => "雪, \"new\"\nline"]);
    $matrix = []; $offset = 0;
    foreach (['header', 'historical', 'new'] as $index => $target) {
        foreach (['control', 'zero', 'stalled-short', 'recoverable-short'] as $mode) {
            file_put_contents($csvPath, $original);
            $GLOBALS['csv_native'] = ['mode' => $mode, 'start' => $offset, 'end' => $offset + strlen($expectedRecords[$index]),
                'shorts' => 0, 'zeros' => 0, 'publications' => 0, 'csv_results' => [], 'write_results' => []];
            $ok = BbfCsvNativeFaults\storeCsv($nativeRecord, $config['submissions_dir'], []);
            $state = $GLOBALS['csv_native']; $bytes = file_get_contents($csvPath);
            $recover = in_array($mode, ['control', 'recoverable-short'], true);
            $injected = match ($mode) {
                'control' => $state['shorts'] === 0 && $state['zeros'] === 0,
                'zero' => $state['zeros'] > 0 && $state['shorts'] === 0,
                'stalled-short' => $state['shorts'] === 1 && $state['zeros'] > 0,
                'recoverable-short' => $state['shorts'] > 1 && $state['zeros'] === 0,
            };
            $matrix["$target $mode fault reached"] = $injected;
            $matrix["$target $mode result and exact bytes"] = $ok === $recover && $bytes === ($recover ? $expected : $original);
            $matrix["$target $mode publication and cleanup"] = $state['publications'] === (int)$recover && glob($csvPath . '.tmp-*') === [];
            if ($recover) {
                $rows = storage_csv($csvPath);
                $matrix["$target $mode quotes/Unicode and padding round trip"] = count($rows) === 3
                    && array_slice($rows[0], 4) === ['old,"Ž"', 'new,"雪"']
                    && array_slice($rows[1], 4) === ["Žluťoučký, \"quoted\"\nline", '']
                    && array_slice($rows[2], 4) === ['', $nativeRecord['data']['new,"雪"']];
            }
            print 'CSV_NATIVE ' . json_encode(['target' => $target, 'mode' => $mode, 'ok' => $ok,
                'original_preserved' => $bytes === $original, 'exact_output' => $bytes === $expected,
                'temp_leaks' => count(glob($csvPath . '.tmp-*')), 'state' => $state], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $offset += strlen($expectedRecords[$index]);
    }
    stream_wrapper_unregister('bbfcsvnative');
    // Report every pre-fix control before failing, so all three record positions retain evidence.
    $failed = array_keys(array_filter($matrix, static fn($ok) => !$ok));
    print 'CSV native matrix: ' . (count($matrix) - count($failed)) . '/' . count($matrix)
        . ' passed; failures=' . json_encode($failed) . "\n";
    foreach ($matrix as $label => $ok) storage_check($ok, "native CSV $label");

    // Verbatim primitive with observation-only wrappers: real Windows rename and real sleeps.
    // Release from the first real rename warning, not a timing guess or a new write operation.
    $publicationProbe = <<<'PHP'
namespace BbfPublicationProbe;
function rename($from, $to) {
    $lock = \fopen($to . '.lock', 'c');
    $acquired = \flock($lock, LOCK_EX | LOCK_NB);
    if ($acquired) \flock($lock, LOCK_UN);
    \fclose($lock);
    $GLOBALS['publication_attempts'][] = [$from, $to, hash_file('sha256', $from), !$acquired];
    return \rename($from, $to);
}
function usleep($delay) { $GLOBALS['publication_sleeps'][] = $delay; \usleep($delay); }
PHP;
    eval($publicationProbe . substr(file_get_contents("$root/bbf_storage.php"), 5));
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['transient', 'persistent'] as $mode) {
            for ($probe = 0; $probe < 5; ++$probe) {
                $path = "$root/submissions/held-$mode-$probe.json";
                $original = "original-$mode-$probe"; $replacement = "replacement-$mode-$probe";
                file_put_contents($path, $original);
                $held = fopen($path, 'rb');
                if (!$held) throw new RuntimeException('Cannot hold destination read handle.');
                $GLOBALS['publication_attempts'] = $GLOBALS['publication_sleeps'] = [];
                $warnings = []; $writes = 0;
                set_error_handler(static function ($severity, $message) use (&$held, &$warnings, $mode): bool {
                    if (str_starts_with($message, 'rename(')) {
                        $warnings[] = $message;
                        if ($mode === 'transient' && is_resource($held)) { fclose($held); $held = null; }
                    }
                    return false; // Preserve the real builtin error and false result, including @ semantics.
                });
                $started = microtime(true);
                try {
                    $ok = BbfPublicationProbe\bbf_storage_locked($path, static function () use ($path, $replacement, &$writes): bool {
                        return BbfPublicationProbe\bbf_storage_replace($path, static function ($fp) use ($replacement, &$writes): bool {
                            ++$writes; return BbfPublicationProbe\bbf_storage_write_all($fp, $replacement);
                        });
                    });
                    $elapsed = microtime(true) - $started;
                    $lastError = error_get_last();
                    $attempts = $GLOBALS['publication_attempts']; $sleeps = $GLOBALS['publication_sleeps'];
                    storage_check(count($warnings) === ($mode === 'transient' ? 1 : 11)
                        && str_contains($warnings[0], 'code: 5'), "$mode held handle $probe really blocks Windows rename (code 5)");
                    storage_check($ok === ($mode === 'transient') && file_get_contents($path) === ($ok ? $replacement : $original),
                        "$mode held handle $probe returns correct result and preserves/publishes exact bytes");
                    storage_check(count($attempts) === ($ok ? 2 : 11) && count($sleeps) === count($attempts) - 1
                        && array_sum($sleeps) === ($ok ? 10000 : 100000) && $writes === 1,
                        "$mode held handle $probe bounded publication retries, write callback runs once");
                    storage_check(count(array_unique(array_column($attempts, 0))) === 1
                        && count(array_unique(array_column($attempts, 2))) === 1
                        && $attempts[0][2] === hash('sha256', $replacement)
                        && !in_array(false, array_column($attempts, 3), true),
                        "$mode held handle $probe same prepared temp and exclusive sidecar lock on every attempt");
                    storage_check(glob($path . '.tmp-*') === [] && ($ok || str_contains($lastError['message'] ?? '', 'code: 5')),
                        "$mode held handle $probe cleans temp and retains permanent error");
                    print "HELD $mode $probe attempts=" . count($attempts) . " sleeps_us=" . array_sum($sleeps)
                        . " writes=$writes elapsed_ms=" . round($elapsed * 1000, 2) . " warning=" . $warnings[0] . "\n";
                } finally {
                    restore_error_handler();
                    if (is_resource($held)) fclose($held);
                }
                // A distinct write after release proves failure did not strand the lock or temp.
                storage_check(bbf_storage_write_json($path, ['control' => $probe])
                    && json_decode(file_get_contents($path), true) === ['control' => $probe], "$mode held handle $probe released-lock control");
            }
        }
    } else {
        print "SKIP Windows-only real held-handle tests on " . PHP_OS_FAMILY . "\n";
    }
    // Exercise the other-platform branch even on Windows, without changing production bodies.
    eval('namespace BbfNonWindowsPublication; const PHP_OS_FAMILY = "Linux";
        function rename($from, $to) { ++$GLOBALS["nonwindows_renames"]; return false; }
        function usleep($delay) { ++$GLOBALS["nonwindows_sleeps"]; }'
        . substr(file_get_contents("$root/bbf_storage.php"), 5));
    $GLOBALS['nonwindows_renames'] = $GLOBALS['nonwindows_sleeps'] = 0;
    file_put_contents($faultPath, 'nonwindows-original');
    storage_check(!BbfNonWindowsPublication\bbf_storage_write_json($faultPath, ['data' => 'not published'])
        && $GLOBALS['nonwindows_renames'] === 1 && $GLOBALS['nonwindows_sleeps'] === 0
        && file_get_contents($faultPath) === 'nonwindows-original' && glob($faultPath . '.tmp-*') === [],
        'non-Windows publication failure attempts once without delay and preserves original/cleanup');

    // Concurrent independent CLI writers exercise creation + schema union under the stable lock.
    $worker = <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') exit;
define('BBF_LOADED', true);
require dirname(__DIR__) . '/bbf_functions.php';
require __DIR__ . '/storage-functions.php';
$root = dirname(__DIR__); $n = (int)$argv[1]; $i = -1; $operation = 'start barrier'; $ioErrors = [];
// Observe even @-suppressed warnings without changing builtin return values or retrying IO.
set_error_handler(static function ($severity, $message, $file, $line) use (&$ioErrors): bool {
    $ioErrors[] = compact('severity', 'message', 'file', 'line');
    return false;
});
$fail = static function (int $exitCode) use ($n, &$i, &$operation, &$ioErrors): never {
    fwrite(STDERR, json_encode(['worker' => $n, 'operation' => $operation, 'iteration' => $i,
        'exitcode' => $exitCode, 'io_errors' => $ioErrors, 'last_error' => error_get_last()], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    exit($exitCode);
};
$until = microtime(true) + 10;
while (!is_file($root . '/start-workers')) { if (microtime(true) > $until) $fail(2); usleep(1000); clearstatcache(); }
for ($i = 0; $i < 10; $i++) {
    $r = ['id' => "bbf_{$n}_$i", 'form' => 'race', 'data' => ["field_$n" => "value_{$n}_$i"], 'meta' => ['submitted' => 'fixture']];
    $operation = 'storeCsv'; $ioErrors = []; error_clear_last(); if (!storeCsv($r, $root . '/submissions', [])) $fail(3);
    $operation = 'bbf_storage_write_json'; $ioErrors = []; error_clear_last(); if (!bbf_storage_write_json($root . '/submissions/race.json', ['worker' => $n, 'payload' => str_repeat((string)$n, 32000)])) $fail(4);
}
PHP;
    file_put_contents("$root/tests/worker.php", $worker);
    for ($n = 0; $n < 6; ++$n) {
        $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'open_basedir=' . $root,
            '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect',
            "$root/tests/worker.php", (string)$n], [0 => ['pipe', 'r'], 1 => ['file', "$root/logs/worker-$n.out", 'w'],
                2 => ['file', "$root/logs/worker-$n.err", 'w']], $pipes, $root);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start owned writer.');
        fclose($pipes[0]); $children[] = $proc;
    }
    file_put_contents("$root/start-workers", 'go');
    $deadline = microtime(true) + 30; $done = []; $snapshots = 0;
    do {
        foreach ($children as $n => $proc) {
            if (isset($done[$n])) continue;
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $done[$n] = $status; // Retain the first terminal status; later polls/close can return -1 on older PHP.
                storage_check($done[$n]['exitcode'] === 0, "concurrent writer $n completed exitcode=" . $done[$n]['exitcode'] . ' status=' . json_encode($done[$n]) . ' stderr=' . file_get_contents("$root/logs/worker-$n.err") . ' stdout=' . file_get_contents("$root/logs/worker-$n.out"));
            }
        }
        if (is_file("$root/submissions/race.json")) {
            $snapshot = json_decode(file_get_contents("$root/submissions/race.json"), true, 512, JSON_THROW_ON_ERROR);
            if (strlen($snapshot['payload'] ?? '') !== 32000) throw new RuntimeException('Partial concurrent JSON read.');
            ++$snapshots;
        }
        if (microtime(true) > $deadline) throw new RuntimeException('Concurrent writers timed out.');
        usleep(1000); clearstatcache();
    } while (count($done) < count($children));
    foreach ($children as $proc) proc_close($proc); $children = [];
    $rows = storage_csv("$root/submissions/race.csv"); $header = array_shift($rows);
    storage_check(count($rows) === 60 && count(array_unique(array_column($rows, 0))) === 60 && count($header) === 10, 'concurrent CSV union loses no fields or rows');
    foreach ($rows as $row) {
        $record = array_combine($header, $row); preg_match('/bbf_(\d+)_(\d+)/', $record['_id'], $match);
        if (($record['field_' . $match[1]] ?? '') !== 'value_' . $match[1] . '_' . $match[2]) throw new RuntimeException('Concurrent CSV misalignment.');
    }
    storage_check($snapshots > 0, "concurrent JSON readers see only complete records ($snapshots snapshots)");

    // Real isolated HTTP exercises endpoint control flow, including signed local callback loading.
    file_put_contents("$root/actions/probe.php", '<?php file_put_contents(__DIR__ . "/../data/action.json", json_encode($submission, JSON_THROW_ON_ERROR));');
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $base = 'http://127.0.0.1:' . $server['port'] . '/';
    foreach (['file', 'sqlite', 'csv'] as $backend) {
        $config['storage'] = $backend === 'file' ? 'sqlite' : 'file'; storage_config($root, $config);
        $form = ['id' => 'http_' . $backend, 'name' => 'Fixture', 'storage' => $backend,
            'fields' => [['name' => 'answer', 'type' => 'text']], 'on_submit' => ['store' => true]];
        file_put_contents("$root/forms/http_$backend.json", bbf_storage_json($form));
        $r = bbf_test_http($server, $base . "submit.php?form=http_$backend", ['answer' => 'endpoint-data']);
        storage_check($r['code'] === 200 && !empty($r['json']['submission_id']), "$backend HTTP submit honors override (HTTP {$r['code']})");
        $id = $r['json']['submission_id'];
        $r = bbf_test_http($server, $base . "submit.php?form=http_$backend", ['answer' => "\xff"]);
        storage_check($r['code'] === 500 && ($r['json']['status'] ?? '') === 'error', "$backend HTTP invalid encoding is not successful (HTTP {$r['code']}: {$r['body']})");
        if ($backend === 'csv') continue;
        $form['on_submit']['actions'] = [['type' => 'probe']];
        file_put_contents("$root/forms/http_$backend.json", bbf_storage_json($form));
        storage_check(updateSubmissionPaymentMetadata($id, "http_$backend", [
            'payment_status' => 'pending',
            'payment_expected_amount_minor' => 100,
            'payment_expected_currency' => 'eur',
            'payment_checkout_session_id' => 'cs_fixture',
        ], $config), "$backend callback fixture stores trusted payment expectations");
        $payload = bbf_storage_json(['id' => 'evt_storage_fixture', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_fixture', 'payment_intent' => 'pi_fixture', 'amount_total' => 100, 'currency' => 'eur', 'payment_status' => 'paid',
            'metadata' => ['bbf_submission_id' => $id, 'bbf_form_id' => "http_$backend"]]]]);
        $time = time(); $signature = "t=$time,v1=" . hash_hmac('sha256', "$time.$payload", $config['stripe']['webhook_secret']);
        $options = ['raw' => $payload, 'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => $signature]];
        $r = bbf_test_http($server, $base . 'payment.php', null, $options);
        $seen = json_decode(file_get_contents("$root/data/action.json"), true);
        storage_check($r['code'] === 200 && $seen['id'] === $id && $seen['data']['answer'] === 'endpoint-data'
            && $seen['meta']['payment_status'] === 'paid', "$backend signed local callback updates and loads the effective backend");
        unlink("$root/data/action.json");
        if ($backend === 'file') file_put_contents("$root/submissions/http_file/$id.json", '{broken');
        else { $pdo = new PDO('sqlite:' . $config['sqlite']['path']); $pdo->exec("UPDATE bbf_submissions SET meta='{broken' WHERE form_id='http_sqlite'"); $pdo = null; }
        $r = bbf_test_http($server, $base . 'payment.php', null, $options);
        storage_check($r['code'] === 500 && !file_exists("$root/data/action.json"), "$backend callback persistence failure stops deferred action and returns error");
    }
    foreach ([['file', false], ['csv', true], ['csv', false]] as [$backend, $enabled]) {
        $form = ['id' => 'checkout', 'storage' => $backend, 'fields' => [['name' => 'answer', 'type' => 'text']],
            'on_submit' => ['store' => $enabled, 'payment' => [
                'provider' => 'stripe', 'mode' => 'fixed', 'pricing_version' => 'storage-fixture-v1',
                'currency' => 'eur', 'amount_minor' => 100,
            ]]];
        file_put_contents("$root/forms/checkout.json", bbf_storage_json($form));
        $r = bbf_test_http($server, $base . 'submit.php?form=checkout', ['answer' => 'x']);
        storage_check($r['code'] === 500 && str_contains($r['json']['message'] ?? '', 'Payment requires durable')
            && !file_exists("$root/submissions/checkout.csv") && !is_dir("$root/submissions/checkout"), "$backend store=" . var_export($enabled, true) . ' rejected before persistence/Checkout');
    }
    storage_check(glob("$root/submissions/*.tmp-*") === [], 'no unpublished temporary artifacts remain');
    print "Storage regression: $checks passed, 0 failed. Real MySQL valid write/update not exercised; no database service started.\n";
} finally {
    $pdo = null;
    foreach ($children as $proc) { if (is_resource($proc)) { if (proc_get_status($proc)['running']) proc_terminate($proc); proc_close($proc); } }
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
