<?php
/** G2 acceptance gate: actual isolated HTTP/backend and Chromium regressions; no skipped prerequisites. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$root = dirname(__DIR__);
$suites = [
    // R3.localhost/sessions and F2: API operations, revocation, audit failures, case aliases.
    'Core authorization and session lifecycle' => [PHP_BINARY, __DIR__ . '/review-access-core-test.php'],
    // F2: positive and negative read/export/delete boundaries on every supported backend.
    'File, CSV and SQLite authorization' => [PHP_BINARY, __DIR__ . '/review-access-storage-test.php'],
    'Real disposable MySQL authorization' => [PHP_BINARY, __DIR__ . '/review-access-mysql-test.php'],
    // R3.sandbox: admin plus CSRF at both entrypoints, never fall through to real submission.
    'Sandbox and submit authorization' => [PHP_BINARY, __DIR__ . '/review-sandbox-test.php'],
    // R3.diagnostics: fixed target, Host spoofing, methods, header-only smoke token and audit.
    'Diagnostic authorization and target isolation' => [PHP_BINARY, __DIR__ . '/review-diagnostics-test.php'],
    // F2 UI and G1 compatibility: real Chromium controls, stale Print/PDF and prior navigation/XSS fixes.
    'Browser permissions and G1 UI regressions' => ['node', '--test',
        __DIR__ . '/review-access-ui.test.js', __DIR__ . '/viewer-navigation.test.js',
        __DIR__ . '/review-security.test.js', __DIR__ . '/editor-save.test.js', __DIR__ . '/review-sandbox-ui.test.js'],
];

// Preflight all scripts so an incomplete gate cannot report partial coverage as success.
foreach ($suites as $label => $command) {
    foreach (array_slice($command, $command[0] === PHP_BINARY ? 1 : 2) as $script) {
        if (!is_file($script) || is_link($script)) {
            fwrite(STDERR, "FAIL $label: missing regular regression script $script\n");
            exit(1);
        }
    }
}
$env = getenv(); $env['PHP_BINARY'] = PHP_BINARY;
unset($env['BBF_TEST_LEASE'], $env['BBF_TEST_LEASE_KEY'], $env['BBF_TEST_FIXTURE_ROOT'],
    $env['BBF_TEST_IDENTITY'], $env['PHP_CLI_SERVER_WORKERS']);
$passed = 0;
foreach ($suites as $label => $command) {
    print "\n=== $label ===\n";
    // Each child creates/cleans its own private installations and owned processes.
    // Array commands avoid shell interpretation, including executable and script paths with spaces.
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root, $env);
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL $label: cannot start regression process\n");
        exit(1);
    }
    fclose($pipes[0]);
    $code = proc_close($process);
    if ($code !== 0) {
        fwrite(STDERR, "FAIL $label: regression process exited $code; G2 is not complete\n");
        exit(1);
    }
    ++$passed;
    print "PASS suite: $label\n";
}
print "\nG2 access acceptance: $passed/" . count($suites) . " suites passed. Local fixtures only; no production validation.\n";
