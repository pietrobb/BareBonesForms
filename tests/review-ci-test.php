<?php
/** G6 CI gate: portable orchestration of isolated, local-mock regression suites. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

const BBF_CI_FAILURE_PROBE_EXIT = 23;
const BBF_CI_SELF_TEST_MARKER = 'PASS failure propagation self-test: deliberately failing child was reported by the suite gate';

/** Run without a shell so executable and path quoting is portable. */
function bbf_ci_process(array $command, string $cwd, bool $capture = false): array
{
    $descriptors = $capture
        ? [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
        : [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR];
    $pipes = [];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    unset(
        $environment['BBF_TEST_LEASE'],
        $environment['BBF_TEST_LEASE_KEY'],
        $environment['BBF_TEST_FIXTURE_ROOT'],
        $environment['BBF_TEST_IDENTITY'],
        $environment['PHP_CLI_SERVER_WORKERS']
    );

    $process = proc_open($command, $descriptors, $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return ['code' => null, 'stdout' => '', 'stderr' => 'cannot start child process'];
    }

    fclose($pipes[0]);
    $stdout = '';
    $stderr = '';
    if ($capture) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Every child runs, every nonzero/timeout result is counted, and any failure makes the gate nonzero. */
function bbf_ci_suites(
    array $suites,
    string $root,
    string $summaryLabel,
    int $maxParallel = 1,
    float $timeoutSeconds = 600.0
): array {
    if ($maxParallel < 1 || $timeoutSeconds <= 0) {
        throw new InvalidArgumentException('CI concurrency and timeout must be positive.');
    }

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    unset(
        $environment['BBF_TEST_LEASE'],
        $environment['BBF_TEST_LEASE_KEY'],
        $environment['BBF_TEST_FIXTURE_ROOT'],
        $environment['BBF_TEST_IDENTITY'],
        $environment['PHP_CLI_SERVER_WORKERS']
    );

    $queue = [];
    foreach ($suites as $label => $command) {
        $queue[] = ['label' => $label, 'command' => $command];
    }
    $running = [];
    $passed = 0;
    $failed = 0;

    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $maxParallel) {
            $suite = array_shift($queue);
            $label = $suite['label'];
            $pipes = [];
            $process = proc_open(
                $suite['command'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $root,
                $environment
            );
            if (!is_resource($process)) {
                ++$failed;
                fwrite(STDERR, "\n=== $label ===\nFAIL suite: $label could not start\n");
                continue;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            $running[] = [
                'label' => $label,
                'process' => $process,
                'output' => $pipes[1],
                'buffer' => '',
                'started' => microtime(true),
            ];
        }

        foreach ($running as $index => $child) {
            $running[$index]['buffer'] .= stream_get_contents($child['output']);
            $status = proc_get_status($child['process']);
            $timedOut = $status['running'] && microtime(true) - $child['started'] > $timeoutSeconds;
            if ($status['running'] && !$timedOut) {
                continue;
            }
            if ($timedOut) {
                proc_terminate($child['process']);
            }
            $running[$index]['buffer'] .= stream_get_contents($child['output']);
            fclose($child['output']);
            $closed = proc_close($child['process']);
            $code = $timedOut ? null : ($status['exitcode'] === -1 ? $closed : $status['exitcode']);
            print "\n=== {$child['label']} ===\n";
            print $running[$index]['buffer'];
            if ($running[$index]['buffer'] !== '' && !str_ends_with($running[$index]['buffer'], "\n")) {
                print "\n";
            }
            if ($code === 0) {
                ++$passed;
                print "PASS suite: {$child['label']}\n";
            } else {
                ++$failed;
                $exit = $timedOut ? 'timeout' : ($code === null ? 'not started' : (string)$code);
                print "FAIL suite: {$child['label']} exited $exit\n";
            }
            unset($running[$index]);
        }
        $running = array_values($running);
        if ($running !== []) {
            usleep(50000);
        }
    }

    $total = count($suites);
    print "\n$summaryLabel: $passed/$total suites passed; $failed failed.\n";
    return ['passed' => $passed, 'failed' => $failed, 'total' => $total, 'code' => $failed === 0 ? 0 : 1];
}

function bbf_ci_script(string $path, string $label): void
{
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException("$label is not a regular regression file: $path");
    }
}

function bbf_ci_verify_workflow(string $root): void
{
    $path = $root . '/.github/workflows/lint.yml';
    bbf_ci_script($path, 'GitHub Actions workflow');
    $workflow = file_get_contents($path);
    if ($workflow === false) {
        throw new RuntimeException('Cannot read the GitHub Actions workflow.');
    }
    foreach ([
        'permissions:' => 'least-privilege permissions',
        'contents: read' => 'read-only repository permission',
        "php-version: '8.2'" => 'supported PHP runtime',
        "node-version: '20'" => 'Node test runtime',
        'extensions: mbstring, pdo_sqlite, sqlite3' => 'portable PHP regression extensions',
        'runs-on: windows-latest' => 'Windows MariaDB runner',
        'extensions: mbstring, pdo_mysql' => 'real MySQL regression extension',
        'choco install mariadb --version=12.1.2 --yes --no-progress' => 'reviewed MariaDB runtime installation',
        'run: php tests/review-access-mysql-test.php' => 'owned disposable MariaDB regression gate',
        'run: php tests/review-ci-test.php' => 'failure-propagating G6 gate',
    ] as $needle => $label) {
        if (!str_contains($workflow, $needle)) {
            throw new RuntimeException("Workflow is missing $label.");
        }
    }
    foreach (['cp config.example.php config.php', 'php -S 127.0.0.1:8089', 'curl -s', 'php tools/package-deploy.php'] as $unsafe) {
        if (str_contains($workflow, $unsafe)) {
            throw new RuntimeException("Workflow retained an unisolated legacy smoke command: $unsafe");
        }
    }
    print "PASS workflow invokes the portable isolated G6 gate\n";
}

/**
 * Exercise the real suite aggregation path with a known failing descendant.
 * Success means the inner gate returned nonzero and retained the child's exact exit status.
 */
function bbf_ci_verify_failure_propagation(string $root): void
{
    $result = bbf_ci_process([PHP_BINARY, __FILE__, '--self-test-runner'], $root, true);
    $expectedSummary = 'G6 failure propagation probe: 2/3 suites passed; 1 failed.';
    $expectedFailure = 'FAIL suite: Deliberate failure probe exited ' . BBF_CI_FAILURE_PROBE_EXIT;
    $intactOutputBlocks = true;
    foreach (['A', 'B'] as $marker) {
        $header = "=== Output probe $marker ===";
        $nextHeader = null;
        $headerOffset = strpos($result['stdout'], $header);
        if ($headerOffset !== false) {
            $nextHeader = strpos($result['stdout'], "\n=== ", $headerOffset + strlen($header));
        }
        $block = $headerOffset === false ? '' : substr(
            $result['stdout'],
            $headerOffset,
            $nextHeader === false ? null : $nextHeader - $headerOffset
        );
        $intactOutputBlocks = $intactOutputBlocks
            && substr_count($result['stdout'], $header) === 1
            && substr_count($block, "OUTPUT PROBE $marker START") === 1
            && substr_count($block, "OUTPUT PROBE $marker STDERR") === 1
            && substr_count($block, "OUTPUT PROBE $marker END") === 1
            && substr_count($block, "PASS suite: Output probe $marker") === 1;
    }
    if ($result['code'] !== 1
        || !$intactOutputBlocks
        || !str_contains($result['stdout'], $expectedSummary)
        || !str_contains($result['stdout'], $expectedFailure)
        || !str_contains($result['stdout'], 'DELIBERATE G6 FAILURE PROBE')) {
        fwrite(STDERR, "FAIL failure propagation self-test\n");
        fwrite(STDERR, "self-test stdout:\n" . $result['stdout']);
        fwrite(STDERR, "self-test stderr:\n" . $result['stderr']);
        throw new RuntimeException('A deliberately failing child was not propagated by the suite gate.');
    }
    print BBF_CI_SELF_TEST_MARKER . "\n";
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);

if (count($arguments) === 1 && preg_match('/^--output-probe=([AB])$/', $arguments[0], $probe)) {
    $marker = $probe[1];
    print "OUTPUT PROBE $marker START\n";
    for ($line = 0; $line < 256; ++$line) {
        print "$marker:" . str_pad((string)$line, 3, '0', STR_PAD_LEFT) . ':' . str_repeat($marker, 64) . "\n";
    }
    fwrite(STDERR, "OUTPUT PROBE $marker STDERR\n");
    print "OUTPUT PROBE $marker END\n";
    exit(0);
}

if ($arguments === ['--failure-probe']) {
    fwrite(STDERR, "DELIBERATE G6 FAILURE PROBE\n");
    exit(BBF_CI_FAILURE_PROBE_EXIT);
}

if ($arguments === ['--self-test-runner']) {
    $result = bbf_ci_suites(
        [
            'Output probe A' => [PHP_BINARY, __FILE__, '--output-probe=A'],
            'Output probe B' => ['node', '-e', <<<'JS'
console.log('OUTPUT PROBE B START');
for (let line = 0; line < 256; ++line) {
    console.log(`B:${String(line).padStart(3, '0')}:${'B'.repeat(64)}`);
}
console.error('OUTPUT PROBE B STDERR');
console.log('OUTPUT PROBE B END');
JS],
            'Deliberate failure probe' => [PHP_BINARY, __FILE__, '--failure-probe'],
        ],
        $root,
        'G6 failure propagation probe',
        3
    );
    exit($result['code']);
}

if ($arguments === ['--self-test']) {
    try {
        bbf_ci_verify_failure_propagation($root);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL G6 self-test: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

if ($arguments !== []) {
    fwrite(STDERR, "Usage: php tests/review-ci-test.php [--self-test]\n");
    exit(2);
}

try {
    bbf_ci_verify_workflow($root);
    $required = [
        'Isolated PHP integration and mutation safety' => [__DIR__ . '/review-test-isolation.php', [PHP_BINARY, __DIR__ . '/review-test-isolation.php']],
        'Typed validation and forwarded values' => [__DIR__ . '/review-validation-test.php', [PHP_BINARY, __DIR__ . '/review-validation-test.php']],
        'Storage error handling' => [__DIR__ . '/review-storage-test.php', [PHP_BINARY, __DIR__ . '/review-storage-test.php']],
        'Effective per-form storage routing' => [__DIR__ . '/review-storage-routing-test.php', [PHP_BINARY, __DIR__ . '/review-storage-routing-test.php']],
        'Read consistency and invalid records' => [__DIR__ . '/review-read-consistency-test.php', [PHP_BINARY, __DIR__ . '/review-read-consistency-test.php']],
        'Expanded and historical exports' => [__DIR__ . '/review-export-test.php', [PHP_BINARY, __DIR__ . '/review-export-test.php']],
        'Storage pagination benchmark' => [__DIR__ . '/review-storage-benchmark.php', [PHP_BINARY, __DIR__ . '/review-storage-benchmark.php']],
        'Token revocation' => [__DIR__ . '/review-access-core-test.php', [PHP_BINARY, __DIR__ . '/review-access-core-test.php']],
        'Portable backend authorization' => [__DIR__ . '/review-access-storage-test.php', [PHP_BINARY, __DIR__ . '/review-access-storage-test.php']],
        'Sandbox submit authorization' => [__DIR__ . '/review-sandbox-test.php', [PHP_BINARY, __DIR__ . '/review-sandbox-test.php']],
        'Diagnostic target isolation' => [__DIR__ . '/review-diagnostics-test.php', [PHP_BINARY, __DIR__ . '/review-diagnostics-test.php']],
        'Payment duplicate and out-of-order events' => [__DIR__ . '/review-payment-test.php', [PHP_BINARY, __DIR__ . '/review-payment-test.php']],
        'Delivery protocol rejection' => [__DIR__ . '/review-delivery-test.php', [PHP_BINARY, __DIR__ . '/review-delivery-test.php']],
        'Submit delivery failure propagation' => [__DIR__ . '/review-submit-delivery-test.php', [PHP_BINARY, __DIR__ . '/review-submit-delivery-test.php']],
        'Viewer navigation' => [__DIR__ . '/viewer-navigation.test.js', ['node', '--test', __DIR__ . '/viewer-navigation.test.js']],
        'Real-browser security' => [__DIR__ . '/review-security.test.js', ['node', '--test', __DIR__ . '/review-security.test.js']],
        'Editor save and keyboard behavior' => [__DIR__ . '/editor-save.test.js', ['node', '--test', __DIR__ . '/editor-save.test.js']],
        'Renderer stale requests and conditions' => [__DIR__ . '/review-renderer.test.js', ['node', '--test', __DIR__ . '/review-renderer.test.js']],
        'Server condition parity' => [__DIR__ . '/review-conditions-test.php', [PHP_BINARY, __DIR__ . '/review-conditions-test.php']],
        'Permission-aware viewer UI' => [__DIR__ . '/review-access-ui.test.js', ['node', '--test', __DIR__ . '/review-access-ui.test.js']],
        'Sandbox browser boundary' => [__DIR__ . '/review-sandbox-ui.test.js', ['node', '--test', __DIR__ . '/review-sandbox-ui.test.js']],
        'Viewer inbox API and portable backends' => [__DIR__ . '/review-inbox-test.php', [PHP_BINARY, __DIR__ . '/review-inbox-test.php']],
        'Real-browser viewer inbox' => [__DIR__ . '/review-inbox.test.js', ['node', '--test', __DIR__ . '/review-inbox.test.js']],
        'Form version compatibility and concurrency' => [__DIR__ . '/review-versions-test.php', [PHP_BINARY, __DIR__ . '/review-versions-test.php']],
        'Respondent draft privacy, expiry and isolation' => [__DIR__ . '/review-drafts-test.php', [PHP_BINARY, __DIR__ . '/review-drafts-test.php']],
        'Real-browser respondent drafts' => [__DIR__ . '/review-drafts.test.js', ['node', '--test', __DIR__ . '/review-drafts.test.js']],
        'Delivery outbox durability and concurrency' => [__DIR__ . '/review-outbox-test.php', [PHP_BINARY, __DIR__ . '/review-outbox-test.php']],
        'Retention and archival relationships' => [__DIR__ . '/review-retention-test.php', [PHP_BINARY, __DIR__ . '/review-retention-test.php']],
        'Protected logical backup and restore' => [__DIR__ . '/review-backup-test.php', [PHP_BINARY, __DIR__ . '/review-backup-test.php']],
        'Bounded repeatable group integration' => [__DIR__ . '/review-repeatable-test.php', [PHP_BINARY, __DIR__ . '/review-repeatable-test.php']],
        'Repeatable group client behavior' => [__DIR__ . '/review-repeatable.test.js', ['node', '--test', __DIR__ . '/review-repeatable.test.js']],
        'Finding-to-test acceptance inventory' => [__DIR__ . '/review-acceptance-test.php', [PHP_BINARY, __DIR__ . '/review-acceptance-test.php']],
        'Deployment parity' => [__DIR__ . '/review-deploy-parity-test.php', [PHP_BINARY, __DIR__ . '/review-deploy-parity-test.php']],
    ];

    $suites = [];
    foreach ($required as $label => [$path, $command]) {
        bbf_ci_script($path, $label);
        $suites[$label] = $command;
    }

    // Run this before the real suites so a gate that masks child exits cannot report success.
    $selfTest = bbf_ci_process([PHP_BINARY, __FILE__, '--self-test'], $root, true);
    if ($selfTest['code'] !== 0 || !str_contains($selfTest['stdout'], BBF_CI_SELF_TEST_MARKER)) {
        fwrite(STDERR, $selfTest['stdout'] . $selfTest['stderr']);
        throw new RuntimeException('Failure propagation self-test did not pass.');
    }
    print trim($selfTest['stdout']) . "\n";

    $result = bbf_ci_suites($suites, $root, 'G6 CI regression orchestration', 5, 600.0);
    print "G6 required suite count: {$result['total']}.\n";
    exit($result['code']);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL G6 CI orchestration: ' . $error->getMessage() . "\n");
    exit(1);
}
