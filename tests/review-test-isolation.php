<?php
/** G1 R3.tests/R5.tests. Run: php tests/review-test-isolation.php [--quick] */
require_once __DIR__ . '/test-isolation-helper.php';

// Regression child deliberately never exits; forced termination skips shutdown.
if (in_array('--timeout-child', $argv, true)) {
    if (!getenv('BBF_TEST_LEASE')) throw new RuntimeException('Timeout child requires a private supervisor.');
    $root = bbf_test_installation(dirname(__DIR__));
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    bbf_test_verify_server($server);
    register_shutdown_function(static function (): void { print "TIMEOUT SHUTDOWN RAN\n"; });
    print "TIMEOUT CHILD READY\n";
    while (true) usleep(100000);
}

$checks = 0;
function isolation_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    print "PASS $message\n";
}
function isolation_snapshot(string $path): array {
    if (is_link($path)) return [$path => 'link:' . readlink($path)];
    if (is_file($path)) return [$path => hash_file('sha256', $path)];
    if (!is_dir($path)) return [$path => 'absent'];
    $result = [$path => 'directory'];
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') $result += isolation_snapshot($path . '/' . $name);
    }
    ksort($result);
    return $result;
}
function isolation_http(string $url): array {
    $body = @file_get_contents($url, false, stream_context_create(['http' => [
        'timeout' => 2, 'ignore_errors' => true, 'follow_location' => 0]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), $body];
}
function isolation_wait(string $url): void {
    for ($i = 0; $i < 50; $i++) {
        if (isolation_http($url)[0] !== 0) return;
        usleep(100000);
    }
    throw new RuntimeException('Loopback test server did not become ready.');
}

/** Parent owns proc_open handles, not reported PIDs; requests cannot choose cleanup paths. */
function isolation_service(string $root, string $key, array &$servers): void {
    clearstatcache();
    if (!is_file($root . '/request.json')) return;
    $request = json_decode(file_get_contents($root . '/request.json'), true, 512, JSON_THROW_ON_ERROR);
    unlink($root . '/request.json');
    $response = ['sequence' => $request['sequence'] ?? ''];
    try {
        if (!hash_equals($key, $request['key'] ?? '')) throw new RuntimeException('Invalid fixture lease key.');
        $id = $request['id'] ?? '';
        switch ($request['action'] ?? '') {
            case 'start':
                $server = bbf_test_start_server($root, $request['host'], $request['port'], $request['fixtures']);
                $servers[$server['id']] = $server;
                unset($server['proc']);
                $server['remote'] = true;
                $response['result'] = $server;
                break;
            case 'alive':
                $response['result'] = bbf_test_server_alive($servers[$id] ?? null);
                break;
            case 'stop':
                bbf_test_stop_server($servers[$id] ?? null);
                $response['result'] = true;
                break;
            default:
                throw new RuntimeException('Unknown fixture supervisor request.');
        }
    } catch (Throwable $error) {
        $response['error'] = $error->getMessage();
    }
    file_put_contents($root . '/response.tmp', json_encode($response, JSON_THROW_ON_ERROR));
    if (!rename($root . '/response.tmp', $root . '/response.json')) throw new RuntimeException('Cannot reply to suite.');
}

function isolation_run(string $script, array $args, string $cwd, string $config, array $baseline, float $timeout = 240, bool $expectTimeout = false): array {
    $log = $cwd . '/logs/suite-' . bin2hex(random_bytes(6)) . '.log';
    $root = bbf_test_installation(dirname(__DIR__), basename($script) === 'smoke-test.php');
    $proc = null;
    $servers = [];
    $timedOut = false;
    $unchanged = true;
    $code = -1;
    try {
        $key = bin2hex(random_bytes(32));
        file_put_contents($root . '/lease', $key);
        $env = getenv();
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $env['BBF_TEST_LEASE'] = $root . '/lease';
        $env['BBF_TEST_LEASE_KEY'] = $key;
        $proc = proc_open(array_merge([PHP_BINARY, $script], $args), [0 => ['pipe', 'r'],
            1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start CLI suite.');
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout;
        do {
            $unchanged = $unchanged && isolation_snapshot($config) === $baseline;
            $status = proc_get_status($proc);
            if (!$status['running']) { $code = $status['exitcode']; break; }
            if (microtime(true) > $deadline) { $timedOut = true; break; }
            isolation_service($root, $key, $servers);
            usleep(10000);
        } while (true);
    } finally {
        // Terminate only our direct suite, then our direct servers; no PID/tree discovery.
        if (is_resource($proc)) {
            if (proc_get_status($proc)['running']) proc_terminate($proc);
            $closed = proc_close($proc);
            if ($code === -1) $code = $closed;
        }
        bbf_test_cleanup($root);
    }
    $output = file_get_contents($log);
    isolation_check($unchanged && isolation_snapshot($config) === $baseline,
        basename($script) . ': production config hash untouched during and after execution');
    preg_match_all('/^Test installation: (.+)$/m', $output, $roots);
    isolation_check(count($roots[1]) === 1 && trim($roots[1][0]) === $root, basename($script) . ': used its parent-owned private installation');
    isolation_check(!file_exists($root), basename($script) . ': owned fixture removed on exit or timeout');
    foreach ($servers as $server) {
        isolation_check(!bbf_test_server_alive($server), basename($script) . ': owned server process closed');
    }
    if ($timedOut !== $expectTimeout) throw new RuntimeException('Unexpected suite timeout outcome: ' . basename($script));
    if ($timedOut) {
        isolation_check(count($servers) === 1 && str_contains($output, 'TIMEOUT CHILD READY') && !str_contains($output, 'TIMEOUT SHUTDOWN RAN'),
            'injected timeout forcibly terminated a ready suite without relying on its shutdown handler');
        foreach ($servers as $server) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $server['port'], $errno, $error, 0.2);
            if ($socket) fclose($socket);
            isolation_check($socket === false, 'timed-out suite server no longer accepts connections');
        }
    }
    return [$timedOut ? 124 : $code, $output];
}

function isolation_refuses(callable $operation, string $message): void {
    $refused = false;
    try { $operation(); } catch (RuntimeException $error) { $refused = true; }
    isolation_check($refused, $message);
}

/** All collision traffic targets a disposable fake, never an operator listener. */
function isolation_collision(string $source): void {
    $owner = bbf_test_installation($source);
    $fake = bbf_test_installation($source);
    try {
        $port = bbf_test_port();
        $old = bbf_test_start_server($owner, '127.0.0.1', $port);
        bbf_test_stop_server($old);
        $foreign = bbf_test_start_server($fake, '127.0.0.1', $port);
        $fakeEndpoint = '<?php file_put_contents(__DIR__ . "/../logs/foreign-requests.log", $_SERVER["REQUEST_METHOD"] . "\n", FILE_APPEND); echo "disposable foreign listener";';
        file_put_contents($fake . '/tests/isolation-probe.php', $fakeEndpoint);
        file_put_contents($fake . '/tests/foreign.php', $fakeEndpoint);
        $url = 'http://127.0.0.1:' . $port . '/tests/foreign.php';
        isolation_check(isolation_http($url)[1] === 'disposable foreign listener', 'disposable foreign listener is reachable (positive control)');
        isolation_refuses(static function () use ($owner, $port, $url): void {
            $server = bbf_test_start_server($owner, '127.0.0.1', $port);
            bbf_test_http($server, $url, ['collision' => 'must never arrive']);
        }, 'occupied port fails closed before a suite POST');
        isolation_refuses(static fn() => bbf_test_http($old, $url, ['stale' => 'must never arrive']),
            'dead owned child cannot POST to a replacement listener');
        $live = bbf_test_start_server($owner, '127.0.0.1', bbf_test_port());
        $wrongListener = $live;
        $wrongListener['port'] = $port;
        isolation_refuses(static fn() => bbf_test_http($wrongListener, $url, ['identity' => 'must never arrive']),
            'live child alone is insufficient: foreign probe identity rejects POST');
        isolation_refuses(static fn() => bbf_test_http($live, 'http://127.0.0.1:' . $port . '/tests/foreign.php', []),
            'HTTP write refuses a target outside its owned port');
        $requests = file_get_contents($fake . '/logs/foreign-requests.log');
        isolation_check(str_contains($requests, "GET\n") && !str_contains($requests, "POST\n"), 'zero POSTs reached disposable foreign listener');
        isolation_check(bbf_test_server_alive($foreign) && isolation_http($url)[0] === 200, 'collision cleanup leaves foreign listener alive');
    } finally {
        bbf_test_cleanup($owner);
        bbf_test_cleanup($fake);
    }
}

try {
    $source = dirname(__DIR__);
    $config = $source . '/config.php';
    $protected = [];
    foreach (['config.php', 'forms', 'submissions', 'data', 'logs'] as $name) {
        $protected[$source . '/' . $name] = isolation_snapshot($source . '/' . $name);
    }
    foreach (glob(__DIR__ . '/_*', GLOB_ONLYDIR) ?: [] as $dir) $protected[$dir] = isolation_snapshot($dir);
    $baseline = isolation_snapshot($config);
    $root = bbf_test_installation($source);
    isolation_check(!file_exists($root . '/config.php'), 'operator config is not copied or loaded');
    isolation_check(str_contains($root, ' ') && realpath($root) !== realpath($source), 'disposable path includes spaces and is outside the installation');
    file_put_contents($root . '/config.php', "<?php return ['sentinel' => 'do not overwrite'];\n");
    $sentinel = isolation_snapshot($root . '/config.php');
    file_put_contents($root . '/probe.php', '<?php echo json_encode([PHP_BINARY, getcwd(), ini_get("open_basedir"), ini_get("allow_url_fopen"), function_exists("mail")]);');
    $port = bbf_test_port();
    $server = bbf_test_start_server($root, '127.0.0.1', $port, false);
    isolation_check(bbf_test_server_alive($server), 'portable server starts using PHP_BINARY');
    $url = 'http://127.0.0.1:' . $port;
    isolation_wait($url . '/probe.php');
    $probe = json_decode(isolation_http($url . '/probe.php')[1], true);
    isolation_check(realpath($probe[0] ?? '') === realpath(PHP_BINARY), 'child process uses the current PHP executable');
    isolation_check(realpath($probe[1] ?? '') === realpath($root) && realpath($probe[2] ?? '') === realpath($root), 'child cwd and filesystem boundary are the disposable installation');
    isolation_check(empty($probe[3]) && $probe[4] === false, 'server URL fetching and live mail are disabled');
    foreach (['smoke-test.php', 'integration-test.php', 'demo-forms-test.php', 'review-test-isolation.php',
              'test-isolation-helper.php', 'test-options-endpoint.php', 'test-lookup-endpoint.php',
              'test-autocomplete-endpoint.php'] as $name) {
        [$code, $body] = isolation_http($url . '/tests/' . $name);
        isolation_check($code === 403, $name . ': ordinary HTTP invocation denied');
    }
    [$code, $body] = isolation_http($url . '/maintenance.php');
    isolation_check($code === 403 && $body === 'CLI only.', 'maintenance.php: ordinary HTTP invocation denied');
    isolation_check(isolation_snapshot($root . '/config.php') === $sentinel, 'HTTP denial occurs before config mutation');
    bbf_test_stop_server($server);
    $port = bbf_test_port();
    $server = bbf_test_start_server($root, '127.0.0.1', $port);
    $url = 'http://127.0.0.1:' . $port;
    isolation_wait($url . '/probe.php');
    foreach (['test-options-endpoint.php', 'test-lookup-endpoint.php?ico=12345678',
              'test-autocomplete-endpoint.php?q=bra'] as $name) {
        [$code, $body] = isolation_http($url . '/tests/' . $name);
        isolation_check($code === 200 && is_array(json_decode($body, true)), $name . ': mock remains available only in fixture server');
    }
    isolation_collision($source);
    [$exit, $output] = isolation_run(__FILE__, ['--timeout-child'], $root, $config, $baseline, 2, true);
    isolation_check($exit === 124, 'short injected timeout reports timeout status after parent-owned cleanup');
    [$exit, $output] = isolation_run($source . '/tests/smoke-test.php', ['__missing_isolation_form__'], $root, $config, $baseline);
    isolation_check($exit === 1 && str_contains($output, 'not found'), 'early suite failure preserves config and cleans its fixture');
    $quick = in_array('--quick', $argv, true);
    $runs = ['smoke-test.php' => $quick ? ['kontakt'] : [],
             'integration-test.php' => $quick ? ['file'] : [], 'demo-forms-test.php' => []];
    $functionalFailures = 0;
    foreach ($runs as $name => $args) {
        [$exit, $output] = isolation_run($source . '/tests/' . $name, $args, $root, $config, $baseline);
        $complete = preg_match('/(?:Results|Total): (\d+) passed, (\d+) failed, (\d+) warnings/', $output, $summary);
        if (!$complete) fwrite(STDERR, $output);
        isolation_check((bool)$complete && $exit === ((int)$summary[2] > 0 ? 1 : 0), $name . ': suite completed with its real functional exit status');
        print 'FUNCTIONAL ' . $name . ': ' . $summary[0] . "\n";
        if ((int)$summary[2] > 0) {
            $functionalFailures += (int)$summary[2];
            foreach (explode("\n", $output) as $line) {
                if (str_contains($line, "\033[31m")) print preg_replace('/\x1b\[[0-9;]*m/', '', $line) . "\n";
            }
        }
    }
    foreach ($protected as $path => $snapshot) {
        isolation_check(isolation_snapshot($path) === $snapshot, 'protected config/forms/data/logs/operator fixtures unchanged: ' . basename($path));
    }
    bbf_test_cleanup($root);
    isolation_check(!file_exists($root), 'review fixture and its server cleaned without touching operator directories');
    print "Isolation: $checks passed; $functionalFailures functional suite failure(s).\n";
    exit($functionalFailures === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL isolation: ' . $error->getMessage() . "\n");
    exit(1);
}
