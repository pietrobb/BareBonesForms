<?php
/** Private installations for CLI suites; never load or back up the operator config. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function bbf_test_installation(string $source, bool $sandbox = false): string {
    // Under review, the parent creates the fixture and owns every server process.
    $lease = getenv('BBF_TEST_LEASE');
    if ($lease !== false && $lease !== '') {
        $root = dirname($lease);
        $key = getenv('BBF_TEST_LEASE_KEY');
        if (!is_file($lease) || !$key || !hash_equals(trim(file_get_contents($lease)), $key)) {
            throw new RuntimeException('Invalid supervised fixture lease.');
        }
        $GLOBALS['bbf_test_broker'] = $root;
        $GLOBALS['bbf_test_roots'][$root] = true;
        fwrite(STDOUT, "Test installation: $root\n");
        return $root;
    }
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/bbf tests ' . bin2hex(random_bytes(16));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create private test installation.');
    }
    $GLOBALS['bbf_test_roots'][$root] = true;
    register_shutdown_function(static function () use ($root): void { bbf_test_cleanup($root); });
    try {
        foreach (['forms', 'templates', 'lang', 'actions', 'tests', 'tests/test-forms',
                  'submissions', 'data', 'logs', 'sessions', 'uploads'] as $dir) {
            if (!mkdir($root . '/' . $dir, 0700)) {
                throw new RuntimeException('Cannot create fixture directory: ' . $dir);
            }
        }
        // Deliberate allowlist: no config.php, operator data/logs, custom actions or symlinks.
        foreach (['submit.php', 'submissions.php', 'bbf_functions.php', 'bbf_storage.php', 'bbf_delivery.php', 'bbf_drafts.php', 'bbf_outbox.php', 'bbf_export.php', 'bbf_read.php', 'bbf_auth.php', 'bbf_review.php', 'bbf_versions.php', 'bbf_retention.php', 'bbf_backup.php', 'maintenance.php', 'payment.php', '.htaccess',
                  'actions/test-echo-response.php'] as $file) {
            bbf_test_copy($source . '/' . $file, $root . '/' . $file);
        }
        foreach (['forms' => '*.json', 'templates' => '*.html', 'lang' => '*.php',
                  'tests/test-forms' => '*.json'] as $dir => $pattern) {
            if (is_link($source . '/' . $dir)) {
                throw new RuntimeException('Refusing linked fixture directory: ' . $dir);
            }
            foreach (glob($source . '/' . $dir . '/' . $pattern) ?: [] as $file) {
                if ($dir === 'forms' && str_ends_with(basename($file), '.map.json')) continue;
                $target = $root . '/' . $dir . '/' . basename($file);
                bbf_test_copy($file, $target);
                if (!$sandbox && str_ends_with($file, '.json')) {
                    $form = json_decode(file_get_contents($target), true);
                    if (is_array($form) && isset($form['on_submit'])) {
                        // Storage suites never exercise live delivery.
                        $form['on_submit'] = array_intersect_key($form['on_submit'],
                            array_flip(['store', 'payment', 'actions', 'redirect', 'message']));
                        if (isset($form['on_submit']['actions'])) {
                            $form['on_submit']['actions'] = array_values(array_filter(
                                $form['on_submit']['actions'],
                                static fn($a) => ($a['type'] ?? '') === 'test-echo-response'
                            ));
                        }
                        file_put_contents($target, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    }
                }
            }
        }
        foreach (['smoke-test.php', 'integration-test.php', 'demo-forms-test.php',
                  'test-options-endpoint.php', 'test-lookup-endpoint.php',
                  'test-autocomplete-endpoint.php', 'test-isolation-helper.php',
                  'review-test-isolation.php'] as $file) {
            bbf_test_copy($source . '/tests/' . $file, $root . '/tests/' . $file);
        }
        file_put_contents($root . '/tests/isolation-probe.php', '<?php header("Content-Type: text/plain"); echo getenv("BBF_TEST_IDENTITY") . ":" . getmypid();');
        fwrite(STDOUT, "Test installation: $root\n");
        return $root;
    } catch (Throwable $error) {
        bbf_test_cleanup($root);
        throw $error;
    }
}

function bbf_test_copy(string $source, string $target): void {
    if (is_link($source) || !is_file($source) || !copy($source, $target)) {
        throw new RuntimeException('Cannot copy regular fixture file: ' . $source);
    }
}

function bbf_test_port(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot select loopback port: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket); // Only a candidate; identity + live-child verification is mandatory.
    return (int)substr(strrchr($address, ':'), 1);
}

function bbf_test_identity_probe(string $root, string $identity): string {
    if (empty($GLOBALS['bbf_test_roots'][$root]) || !preg_match('/\A[a-f0-9]{64}\z/D', $identity)) {
        throw new RuntimeException('Cannot create test server identity probe.');
    }
    $probe = 'tests/isolation-probe-' . bin2hex(random_bytes(16)) . '.php';
    $source = '<?php header("Content-Type: text/plain"); echo '
        . var_export($identity . ':', true) . ' . getmypid();';
    if (file_put_contents($root . '/' . $probe, $source) !== strlen($source)) {
        throw new RuntimeException('Cannot write test server identity probe.');
    }
    return $probe;
}

/** Array commands bypass the shell and handle spaces in both PHP_BINARY and fixture paths. */
function bbf_test_server_command(string $root, string $host, int $port): array {
    if (empty($GLOBALS['bbf_test_roots'][$root]) || $host !== '127.0.0.1') {
        throw new RuntimeException('Test servers must use an owned installation on loopback.');
    }
    return [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
        '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open',
        '-d', 'open_basedir=' . $root, '-d', 'session.save_path=' . $root . '/sessions',
        '-d', 'upload_tmp_dir=' . $root . '/uploads', '-d', 'sys_temp_dir=' . $root,
        '-d', 'error_log=' . $root . '/logs/php-error.log',
        '-S', $host . ':' . $port, '-t', $root];
}

/** One synchronous request at a time; only the review parent services this private mailbox. */
function bbf_test_broker_call(array $request) {
    $root = $GLOBALS['bbf_test_broker'];
    $request['key'] = getenv('BBF_TEST_LEASE_KEY');
    $request['sequence'] = bin2hex(random_bytes(16));
    file_put_contents($root . '/request.tmp', json_encode($request, JSON_THROW_ON_ERROR));
    if (!rename($root . '/request.tmp', $root . '/request.json')) throw new RuntimeException('Cannot request owned server.');
    $deadline = microtime(true) + 15;
    do {
        clearstatcache();
        if (is_file($root . '/response.json')) {
            $response = json_decode(file_get_contents($root . '/response.json'), true, 512, JSON_THROW_ON_ERROR);
            unlink($root . '/response.json');
            if (($response['sequence'] ?? '') !== $request['sequence']) throw new RuntimeException('Invalid supervisor response.');
            if (isset($response['error'])) throw new RuntimeException($response['error']);
            return $response['result'];
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Fixture supervisor unavailable.');
}

function bbf_test_start_server(string $root, string $host, int $port, bool $allowFixtures = true): array {
    $command = bbf_test_server_command($root, $host, $port);
    if (isset($GLOBALS['bbf_test_broker'])) {
        return bbf_test_broker_call(['action' => 'start', 'host' => $host, 'port' => $port, 'fixtures' => $allowFixtures]);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS'], $env['BBF_TEST_LEASE'], $env['BBF_TEST_LEASE_KEY']);
    $env['BBF_TEST_FIXTURE_ROOT'] = $allowFixtures ? realpath($root) : '';
    $identity = bin2hex(random_bytes(32)); // Fresh for every installation AND restart.
    $probe = bbf_test_identity_probe($root, $identity);
    $proc = proc_open($command, [0 => ['pipe', 'r'],
        1 => ['file', $root . '/logs/server-output.log', 'a'],
        2 => ['file', $root . '/logs/server-error.log', 'a']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start owned PHP server.');
    fclose($pipes[0]);
    $server = ['proc' => $proc, 'root' => $root, 'port' => $port,
        'id' => $identity, 'probe' => $probe];
    try {
        $server['pid'] = bbf_test_verify_server($server, true);
    } catch (Throwable $error) {
        bbf_test_stop_server($server);
        throw $error;
    }
    $GLOBALS['bbf_test_processes'][$root][$server['id']] = $server;
    return $server;
}

function bbf_test_server_alive(?array $server): bool {
    if (!$server) return false;
    if (isset($server['remote'])) return bbf_test_broker_call(['action' => 'alive', 'id' => $server['id']]);
    return is_resource($server['proc'] ?? null) && proc_get_status($server['proc'])['running'];
}

function bbf_test_stop_server(?array $server): void {
    if (!$server) return;
    if (isset($server['remote'])) {
        bbf_test_broker_call(['action' => 'stop', 'id' => $server['id']]);
        return;
    }
    $proc = $server['proc'] ?? null;
    if (is_resource($proc)) {
        if (proc_get_status($proc)['running']) proc_terminate($proc);
        proc_close($proc);
    }
    unset($GLOBALS['bbf_test_processes'][$server['root']][$server['id']]);
}

function bbf_test_verify_server(array $server, bool $wait = false): int {
    $deadline = microtime(true) + ($wait ? 5 : 0);
    do {
        if (!bbf_test_server_alive($server)) throw new RuntimeException('Owned test server is not alive; refusing HTTP.');
        if (!preg_match('~\Atests/isolation-probe-[a-f0-9]{32}\.php\z~D', $server['probe'] ?? '')) {
            throw new RuntimeException('Invalid test server identity probe path.');
        }
        set_error_handler(static fn() => true);
        try {
            $socket = stream_socket_client('tcp://127.0.0.1:' . $server['port'], $errno, $error, 1);
        } finally {
            restore_error_handler();
        }
        $body = false;
        if ($socket !== false) {
            try {
                stream_set_timeout($socket, 1);
                $request = "GET /{$server['probe']} HTTP/1.0\r\nHost: 127.0.0.1:{$server['port']}\r\nConnection: close\r\n\r\n";
                while ($request !== '') {
                    $written = @fwrite($socket, $request);
                    if (!$written) break;
                    $request = substr($request, $written);
                }
                $response = $request === '' ? stream_get_contents($socket) : false;
                if (is_string($response) && !stream_get_meta_data($socket)['timed_out']) {
                    [, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
                }
            } finally {
                fclose($socket);
            }
        }
        if ($body !== false) {
            [$identity, $pid] = array_pad(explode(':', $body, 2), 2, '');
            if (!hash_equals($server['id'], $identity)) {
                throw new RuntimeException('Foreign listener / port collision: identity mismatch; refusing HTTP.');
            }
            if (!ctype_digit($pid) || (int)$pid < 1) {
                throw new RuntimeException('Foreign listener / port collision: invalid child PID; refusing HTTP.');
            }
            if (!bbf_test_server_alive($server)) throw new RuntimeException('Owned test server exited during probe.');
            return (int)$pid;
        }
        if (!$wait) break;
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Owned test server did not answer its identity probe.');
}

/** Never follow redirects. Pin the connection before the final owned-child liveness check. */
function bbf_test_http(array $server, string $url, ?array $data = null, array $options = []): array {
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1'
        || ($parts['port'] ?? 0) !== $server['port'] || isset($parts['user']) || isset($parts['pass'])
        || preg_match('/[\r\n]/', $url)) throw new RuntimeException('HTTP target is not the owned test server.');
    bbf_test_verify_server($server);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $server['port'], $errno, $error, 2);
    if (!$socket) throw new RuntimeException('Cannot connect to owned test server.');
    try {
        // If the child died and a foreign listener rebound between probe and connect,
        // this fails BEFORE any request bytes. If it dies later, this pinned socket
        // cannot migrate to a replacement listener. Never reconnect/retry here.
        if (!bbf_test_server_alive($server)) throw new RuntimeException('Owned child exited before HTTP write.');
        stream_set_timeout($socket, 10);
        $payload = $options['raw'] ?? ($data === null ? '' : http_build_query($data)); $method = $options['method'] ?? ($data === null && !isset($options['raw']) ? 'GET' : 'POST'); if (!is_string($payload) || !is_string($method) || !preg_match('/\A[A-Z]+\z/', $method)) throw new RuntimeException('Invalid HTTP options.');
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''); $extra = ''; $custom = $options['headers'] ?? []; if (isset($options['cookie'])) $custom['Cookie'] = $options['cookie'];
        foreach ($custom as $name => $value) { if (!preg_match('/\A[A-Za-z0-9-]+\z/', $name) || !is_string($value) || preg_match('/[\r\n]/', $value) || in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding'], true)) throw new RuntimeException('Unsafe HTTP header.'); $extra .= "$name: $value\r\n"; }
        $request = $method . " $target HTTP/1.0\r\nHost: 127.0.0.1:" . $server['port'] . "\r\nConnection: close\r\n" . $extra . (isset($custom['Content-Type']) ? '' : "Content-Type: application/x-www-form-urlencoded\r\n") . 'Content-Length: ' . strlen($payload) . "\r\n\r\n" . $payload;
        while ($request !== '') {
            $written = fwrite($socket, $request);
            if (!$written) throw new RuntimeException('Owned HTTP write failed.');
            $request = substr($request, $written);
        }
        $response = stream_get_contents($socket);
        if (stream_get_meta_data($socket)['timed_out']) throw new RuntimeException('Owned HTTP response timed out.');
    } finally {
        fclose($socket);
    }
    [$headers, $body] = array_pad(explode("\r\n\r\n", $response ?: '', 2), 2, '');
    preg_match('/^HTTP\/\d\.\d (\d{3})/', $headers, $match);
    $code = (int)($match[1] ?? 0);
    return ['code' => $code, 'body' => $body, 'headers' => $headers, 'error' => $code ? '' : 'Request failed', 'json' => json_decode($body, true)];
}

/** Only remove paths beneath roots created by this process; never follow links. */
function bbf_test_remove_dir(string $dir): void {
    $path = str_replace('\\', '/', $dir);
    $owned = false;
    foreach (array_keys($GLOBALS['bbf_test_roots'] ?? []) as $root) {
        $root = str_replace('\\', '/', $root);
        if ($path === $root || str_starts_with($path, $root . '/')) $owned = true;
    }
    if (!$owned || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
        throw new RuntimeException('Refusing cleanup outside an owned fixture.');
    }
    if (is_link($dir)) { unlink($dir); return; }
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $dir . '/' . $item;
        if (is_link($child) || !is_dir($child)) {
            if (!unlink($child)) throw new RuntimeException('Cannot remove fixture file: ' . $child);
        } else {
            bbf_test_remove_dir($child);
        }
    }
    if (!rmdir($dir)) throw new RuntimeException('Cannot remove fixture directory: ' . $dir);
}

function bbf_test_cleanup(string $root): void {
    if (empty($GLOBALS['bbf_test_roots'][$root]) || ($GLOBALS['bbf_test_broker'] ?? '') === $root) return;
    foreach ($GLOBALS['bbf_test_processes'][$root] ?? [] as $server) bbf_test_stop_server($server);
    unset($GLOBALS['bbf_test_processes'][$root]);
    bbf_test_remove_dir($root);
    unset($GLOBALS['bbf_test_roots'][$root]);
}
