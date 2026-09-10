<?php
// G2 diagnostics: disposable installations and owned loopback mocks only.
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require dirname(__DIR__) . '/bbf_diagnostics.php';

$passed = 0;
function diagnostic_check(bool $ok, string $label): void {
    global $passed;
    if (!$ok) throw new RuntimeException($label);
    $passed++;
    print "PASS $label\n";
}
function diagnostic_cli(string $root, array $args, bool $streams = false, string $script = 'smoketest.php'): array {
    $output = $root . '/logs/diagnostic-cli.log';
    $command = array_merge([PHP_BINARY, '-d', 'open_basedir=' . $root,
        '-d', 'session.save_path=' . $root . '/sessions',
        '-d', 'allow_url_fopen=1', '-d', 'allow_url_include=0',
        '-d', 'disable_functions=mail,fsockopen,pfsockopen,exec,shell_exec,system,passthru,popen' . ($streams ? ',curl_init' : ''),
        $root . '/' . $script], $args);
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'],
        2 => ['file', $output, 'a']], $pipes, $root);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start isolated diagnostic CLI.');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 30;
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) throw new RuntimeException('Diagnostic CLI timed out.');
            usleep(10000);
        } while (true);
        return [$status['exitcode'], file_get_contents($output)];
    } finally {
        if (proc_get_status($proc)['running']) proc_terminate($proc);
        proc_close($proc);
    }
}
function diagnostic_config(string $root, array $config): void {
    file_put_contents($root . '/config.php', '<?php defined("BBF_LOADED") || exit; '
        . 'file_put_contents(__DIR__ . "/logs/config-loads", "loaded\n", FILE_APPEND); return '
        . var_export($config, true) . ';');
}
// The general isolation helper intentionally disables outbound HTTP. This diagnostic-only
// second listener permits it, with config exclusively pinned to the still-owned mock.
// Never start this server with operator config or production delivery handlers.
function diagnostic_start_server(string $root, bool $streams): array {
    if (isset($GLOBALS['bbf_test_broker'])) throw new RuntimeException('Run diagnostics directly; custom server requires local ownership.');
    $port = bbf_test_port();
    $command = bbf_test_server_command($root, '127.0.0.1', $port);
    array_splice($command, count($command) - 4, 0, ['-d', 'allow_url_fopen=1',
        '-d', 'disable_functions=mail,fsockopen,pfsockopen,exec,shell_exec,system,passthru,popen,proc_open' . ($streams ? ',curl_init' : ''),
        '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0', '-d', 'opcache.cache_id=bbf-diagnostic-' . $port]);
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS'], $env['BBF_TEST_LEASE'], $env['BBF_TEST_LEASE_KEY']);
    $env['BBF_TEST_IDENTITY'] = bin2hex(random_bytes(32));
    $proc = proc_open($command, [0 => ['pipe', 'r'],
        1 => ['file', $root . '/logs/diagnostic-server.log', 'a'],
        2 => ['file', $root . '/logs/diagnostic-server.log', 'a']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start owned diagnostic HTTP server.');
    fclose($pipes[0]);
    $server = ['proc' => $proc, 'root' => $root, 'port' => $port,
        'id' => $env['BBF_TEST_IDENTITY']];
    try { $server['pid'] = bbf_test_verify_server($server, true); }
    catch (Throwable $error) { bbf_test_stop_server($server); throw $error; }
    $GLOBALS['bbf_test_processes'][$root][$server['id']] = $server;
    return $server;
}
function diagnostic_count(string $file): int {
    return is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES)) : 0;
}
function diagnostic_audit_pair(string $root, string $action, string $decision, string $result, string $principal, int $count = 0): void {
    $lines = file($root . '/logs/access-audit.php', FILE_IGNORE_NEW_LINES);
    $pair = array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_slice($lines, -2));
    diagnostic_check(count($pair) === 2 && $pair[0]['result'] === 'attempted' && $pair[1]['result'] === $result
        && $pair[0]['action'] === $action && $pair[1]['action'] === $action
        && $pair[0]['decision'] === $decision && $pair[1]['decision'] === $decision
        && $pair[0]['principal_id'] === $principal && $pair[1]['principal_id'] === $principal
        && $pair[1]['result_count'] === $count, "$action audit $decision attempted/$result ($principal)");
}

$_SERVER['HTTP_HOST'] = 'untrusted.invalid:1234';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '//untrusted.invalid/';
$_SERVER['SCRIPT_NAME'] = '//untrusted.invalid/smoketest.php';
foreach ([null, '', [], 42, 'file:///tmp', 'ftp://untrusted.invalid',
    'http://user:secret@untrusted.invalid', 'http://untrusted.invalid?x=1',
    'http://untrusted.invalid#fragment', "http://untrusted.invalid\r\nHeader: bad",
    'http://untrusted.invalid\\path', 'http://'] as $value) {
    diagnostic_check(bbf_diagnostic_base_url(['diagnostic_base_url' => $value]) === null,
        'invalid/missing target rejected ' . $passed);
}
diagnostic_check(bbf_diagnostic_base_url([]) === null, 'Host and localhost name cannot supply a missing target');
diagnostic_check(bbf_diagnostic_base_url(['diagnostic_base_url' => 'https://example.test/app/']) === 'https://example.test/app', 'fixed installation subpath retained');
diagnostic_check(bbf_diagnostic_probe([], 'config.php') === null, 'missing target is unverified, not blocked');
// This is a two-transport gate; never silently exercise streams twice without cURL.
diagnostic_check(function_exists('curl_init'), 'cURL available for required two-transport regression');

$root = bbf_test_installation(dirname(__DIR__));
$server = $httpServer = null;
try {
    foreach (['smoketest.php', 'check.php', 'bbf_diagnostics.php', 'bbf_auth.php'] as $file) {
        bbf_test_copy(dirname(__DIR__) . '/' . $file, $root . '/' . $file);
    }
    // Replace the fixture's real submission handler as a second safety barrier.
    file_put_contents($root . '/submit.php', '<?php http_response_code(500); exit("Real submit forbidden in diagnostics.");');
    mkdir($root . '/probe', 0700);
    mkdir($root . '/target', 0700);
    $probe = <<<'PHP'
<?php
$status = (int)file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/probe/status');
file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/logs/probe-requests', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
http_response_code($status);
if ($status === 302) header('Location: /trap.php');
echo 'mock diagnostic response';
PHP;
    file_put_contents($root . '/probe/config.php', $probe);
    foreach (['submissions', 'logs', 'templates', 'actions', 'forms', 'tests'] as $dir) {
        mkdir($root . '/probe/' . $dir, 0700);
        file_put_contents($root . '/probe/' . $dir . '/index.php', $probe);
    }
    file_put_contents($root . '/trap.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/logs/trap', "visited\n", FILE_APPEND);
echo 'UNEXPECTED REDIRECT TARGET';
PHP);
    file_put_contents($root . '/target/submit.php', <<<'PHP'
<?php
file_put_contents(dirname(__DIR__) . '/logs/mock-requests', "request\n", FILE_APPEND);
file_put_contents(dirname(__DIR__) . '/logs/mock-request.json', json_encode([
    'uri' => $_SERVER['REQUEST_URI'], 'token' => $_SERVER['HTTP_X_BBF_SMOKE_TOKEN'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'],
]));
$status = (int)file_get_contents(__DIR__ . '/status');
http_response_code($status);
if ($status === 302) { header('Location: /trap.php'); echo 'MOCK REDIRECT'; }
else { header('Content-Type: application/json'); echo json_encode(['submission_id' => 'mock-id-test@example.test']); }
PHP);
    file_put_contents($root . '/target/status', '302');
    // Exercise the complete production check script, retaining only its result data.
    file_put_contents($root . '/run-check.php', <<<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'untrusted.invalid:1234';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['REQUEST_URI'] = '//untrusted.invalid/check.php';
$_SERVER['SCRIPT_NAME'] = '/check.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
if (!in_array('--anonymous', $argv, true)) $_SERVER['HTTP_X_BBF_TOKEN'] = 'diagnostic-test-admin';
ob_start();
require __DIR__ . '/check.php';
ob_end_clean();
echo json_encode(array_values(array_filter($results, static fn($r) => str_ends_with($r['name'], 'blocked via HTTP'))));
PHP);
    file_put_contents($root . '/poison-smoke.php', <<<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'untrusted.invalid:1234';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '//untrusted.invalid/smoketest.php';
$_SERVER['SCRIPT_NAME'] = '//untrusted.invalid/smoketest.php';
require __DIR__ . '/smoketest.php';
PHP);
    file_put_contents($root . '/array-smoke.php', '<?php $_SERVER["HTTP_X_BBF_SMOKE_TOKEN"] = []; require __DIR__ . "/smoketest.php";');
    file_put_contents($root . '/runtime.php', '<?php echo json_encode(["curl" => function_exists("curl_init"), "opcache" => opcache_get_status(false)["opcache_enabled"] ?? false, "timestamps" => ini_get("opcache.validate_timestamps")]);');
    file_put_contents($root . '/auth-fixture.php', <<<'PHP'
<?php
define('BBF_LOADED', true);
require __DIR__ . '/bbf_auth.php';
$config = bbf_auth_load_config(__DIR__ . '/config.php');
$principal = bbf_authenticate($config);
echo json_encode(['id' => $principal['id'] ?? null]);
PHP);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $base = 'http://127.0.0.1:' . $server['port'];
    foreach ([403, 404, 200, 302, 500] as $status) {
        file_put_contents($root . '/probe/status', (string)$status);
        bbf_test_verify_server($server);
        diagnostic_check(bbf_diagnostic_probe(['diagnostic_base_url' => $base . '/probe'], 'config.php') === $status,
            "probe preserves HTTP $status instead of declaring every failure blocked");
    }
    diagnostic_check(!file_exists($root . '/logs/trap'), 'installation probe never follows a redirect');
    diagnostic_check(bbf_diagnostic_probe(['diagnostic_base_url' => $base], '../trap.php') === null, 'probe path cannot choose arbitrary endpoints');
    $config = [
        'storage' => 'file', 'api_token' => 'diagnostic-test-admin', 'smoke_token' => 'diagnostic-test-smoke',
        'smoke_email' => 'test@example.test', 'smoke_notify' => 'notify@example.test',
        'csrf' => false, 'sandbox' => false, 'lang' => 'en', 'rate_limit' => 0,
        'forms_dir' => $root . '/forms', 'submissions_dir' => $root . '/submissions',
        'templates_dir' => $root . '/templates', 'logs_dir' => $root . '/logs',
        'access_tokens' => [['id' => 'diag-reader', 'token' => 'diagnostic-scoped-secret', 'forms' => ['test_diag'],
            'permissions' => ['read'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]],
    ];
    diagnostic_config($root, $config);
    file_put_contents($root . '/forms/test_diag.json', json_encode([
        'id' => 'test_diag', 'schema_version' => 1, 'name' => 'Diagnostic mock form',
        'fields' => [['name' => 'message', 'type' => 'text', 'label' => 'Message']],
        'on_submit' => ['store' => false],
    ], JSON_THROW_ON_ERROR));
    [$exit, $output] = diagnostic_cli($root, ['--anonymous'], false, 'run-check.php');
    diagnostic_check(str_contains($output, 'Access denied'), 'check rejects hostile localhost SERVER_NAME without credentials');
    $response = bbf_test_http($server, $base . '/check.php');
    diagnostic_check($response['code'] === 403, 'check rejects anonymous loopback HTTP request');
    foreach ([null, 403, 404, 200, 302, 500, 'tls-failure'] as $status) {
        $config['diagnostic_base_url'] = $status === null ? '' : ($status === 'tls-failure' ? str_replace('http:', 'https:', $base) : $base) . '/probe';
        if (is_int($status)) file_put_contents($root . '/probe/status', (string)$status);
        diagnostic_config($root, $config);
        bbf_test_verify_server($server);
        [$exit, $output] = diagnostic_cli($root, [], false, 'run-check.php');
        $rows = json_decode($output, true);
        diagnostic_check($exit === 0 && is_array($rows) && count($rows) === 7, 'check runtime emits seven probe results for ' . var_export($status, true));
        foreach ($rows as $row) {
            diagnostic_check($row['pass'] === in_array($status, [403, 404], true), $row['name'] . ' correct classification ' . var_export($status, true));
            if ($status === null || $status === 'tls-failure') diagnostic_check(str_contains($row['detail'], 'Not verified'), 'failed/unconfigured probe is visibly unverified');
        }
    }
    diagnostic_check(!file_exists($root . '/logs/trap'), 'check runtime never follows redirect');
    // Failed TLS negotiation on our still-owned HTTP listener: no released-port race.
    bbf_test_verify_server($server);
    diagnostic_check(bbf_diagnostic_probe(['diagnostic_base_url' => str_replace('http:', 'https:', $base) . '/probe'], 'config.php') === null,
        'owned transport failure is unverified, not blocked');
    unset($config['diagnostic_base_url']);
    diagnostic_config($root, $config);
    [$exit, $output] = diagnostic_cli($root, ['test_diag', '--live']);
    diagnostic_check($exit !== 0 && str_contains($output, 'diagnostic_base_url'), 'CLI live mode rejects absent fixed target');
    $response = bbf_test_http($server, $base . '/poison-smoke.php?live=1&form=test_diag', [], ['headers' => ['X-BBF-Smoke-Token' => 'diagnostic-test-smoke']]);
    diagnostic_check($response['code'] === 400 && str_contains($response['body'], 'diagnostic_base_url'), 'HTTP authorized POST ignores hostile Host/path when fixed target absent');
    diagnostic_check(!file_exists($root . '/logs/mock-request.json'), 'missing target causes no outbound submission');
    diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-http');
    $config['diagnostic_base_url'] = $base . '/target';
    diagnostic_config($root, $config);
    foreach ([false, true] as $streams) {
        bbf_test_verify_server($server);
        [$exit, $output] = diagnostic_cli($root, ['test_diag', '--live'], $streams);
        $mode = $streams ? 'stream fallback' : 'cURL';
        diagnostic_check(str_contains($output, 'HTTP 302'), "$mode reports redirect as failed smoke result");
        $request = json_decode(file_get_contents($root . '/logs/mock-request.json'), true, 512, JSON_THROW_ON_ERROR);
        diagnostic_check($request['uri'] === '/target/submit.php?form=test_diag', "$mode uses configured subpath with no URL credential");
        diagnostic_check($request['token'] === 'diagnostic-test-smoke' && $request['method'] === 'POST', "$mode authenticates mock via POST header");
        diagnostic_check(!file_exists($root . '/logs/trap'), "$mode never follows redirect carrying credential");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-cli', 1);
    }

    foreach ([false, true] as $streams) {
        $mode = $streams ? 'HTTP streams' : 'HTTP cURL';
        $httpServer = diagnostic_start_server($root, $streams);
        $httpBase = 'http://127.0.0.1:' . $httpServer['port'];
        $smokeUrl = $httpBase . '/smoketest.php?form=test_diag';
        $header = ['headers' => ['X-BBF-Smoke-Token' => 'diagnostic-test-smoke']];
        $response = bbf_test_http($httpServer, $httpBase . '/runtime.php');
        diagnostic_check($response['json']['curl'] === !$streams, "$mode selected independently");
        diagnostic_check($response['json']['opcache'] === true && $response['json']['timestamps'] === '0', "$mode config rotation runs with timestamp checks disabled in OPcache");
        $before = diagnostic_count($root . '/logs/mock-requests');
        $response = bbf_test_http($httpServer, $smokeUrl, null, $header);
        diagnostic_check($response['code'] === 200 && $response['json']['mode'] === 'dry', "$mode header-authenticated dry GET succeeds");
        diagnostic_check(str_contains($response['headers'], 'no-store') && stripos($response['headers'], 'Set-Cookie:') === false, "$mode smoke is non-cacheable and stateless");
        diagnostic_audit_pair($root, 'smoke_dry', 'allowed', 'completed', 'smoke-http', 1);
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', null, $header);
        diagnostic_check($response['code'] === 405 && str_contains($response['headers'], 'Allow: POST'), "$mode authenticated GET live is method-rejected");
        diagnostic_audit_pair($root, 'smoke_live', 'denied', 'failed', 'smoke-http');
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', null, array_replace($header, ['method' => 'HEAD']));
        diagnostic_check($response['code'] === 405, "$mode HEAD cannot invoke live work");
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], array_replace($header, ['method' => 'PUT']));
        diagnostic_check($response['code'] === 405, "$mode PUT cannot invoke live work");
        foreach ([
            [$smokeUrl, []],
            [$smokeUrl . '&token=diagnostic-test-smoke', []],
            [$smokeUrl . '&smoke_token=diagnostic-test-smoke', $header],
            [$smokeUrl . '&token=diagnostic-test-smoke', $header],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => '']]],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => 'bad token']]],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => str_repeat('x', 513)]]],
            [$httpBase . '/array-smoke.php?form=test_diag', $header],
        ] as $i => [$url, $options]) {
            $response = bbf_test_http($httpServer, $url . '&live=1', [], $options);
            diagnostic_check(in_array($response['code'], [403, 429], true), "$mode missing/query/empty/malformed HTTP credential rejected $i");
            diagnostic_audit_pair($root, 'smoke_live', 'denied', 'failed', 'anonymous');
        }
        foreach (['diagnostic-test-admin' => 'legacy-admin', 'diagnostic-scoped-secret' => 'diag-reader'] as $token => $id) {
            $response = bbf_test_http($httpServer, $httpBase . '/auth-fixture.php', null, ['headers' => ['X-BBF-Token' => $token]]);
            diagnostic_check(($response['json']['id'] ?? '') === $id && preg_match('/Set-Cookie: (PHPSESSID=[^;\r\n]+)/i', $response['headers'], $m) === 1, "$mode obtains genuine $id management cookie" . ($response['code'] === 500 ? file_get_contents($root . '/logs/php-error.log') : ''));
            $cookie = $m[1];
            if ($id === 'legacy-admin') $adminCookie = $cookie; else foreach ([['cookie' => $cookie], ['headers' => ['X-BBF-Token' => $token]]] as $auth) { $probes = diagnostic_count($root . '/logs/probe-requests'); $denied = bbf_test_http($httpServer, $httpBase . '/check.php', null, $auth); diagnostic_check($denied['code'] === 403 && diagnostic_count($root . '/logs/probe-requests') === $probes, "$mode scoped check credential denied before diagnostic probes"); diagnostic_audit_pair($root, 'diagnostics', 'denied', 'failed', 'diag-reader'); }
            $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], ['cookie' => $cookie]);
            diagnostic_check(in_array($response['code'], [403, 429], true), "$mode $id cookie cannot authorize live smoke");
            $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], ['headers' => ['X-BBF-Token' => $token]]);
            diagnostic_check(in_array($response['code'], [403, 429], true), "$mode $id management header cannot authorize live smoke");
        }
        diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before, "$mode denied requests and dry run caused no outbound submission");
        file_put_contents($root . '/target/status', '200');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
        diagnostic_check($response['code'] === 200 && ($response['json']['forms'][0]['submission_id'] ?? '') === 'mock-id-test@example.test', "$mode authorized POST live completes against separate owned mock");
        diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before + 1, "$mode exactly one mock operation for authorized POST");
        $request = json_decode(file_get_contents($root . '/logs/mock-request.json'), true, 512, JSON_THROW_ON_ERROR);
        diagnostic_check($request['method'] === 'POST' && $request['token'] === 'diagnostic-test-smoke' && $request['uri'] === '/target/submit.php?form=test_diag', "$mode outbound token remains header-only");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'completed', 'smoke-http', 1);
        file_put_contents($root . '/target/status', '302');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
        diagnostic_check($response['code'] === 422 && !file_exists($root . '/logs/trap'), "$mode redirects fail without forwarding credentials");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-http', 1);

        $rotated = $config;
        $rotated['smoke_token'] = 'rotated-diagnostic-smoke';
        diagnostic_config($root, $rotated);
        $before = diagnostic_count($root . '/logs/mock-requests');
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
        diagnostic_check(in_array($response['code'], [403, 429], true), "$mode rotated smoke credential immediately revokes old header");
        file_put_contents($root . '/target/status', '200');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], ['headers' => ['X-BBF-Smoke-Token' => $rotated['smoke_token']]]);
        diagnostic_check($response['code'] === 200 && diagnostic_count($root . '/logs/mock-requests') === $before + 1, "$mode refreshed config approves only new smoke credential");
        $request = json_decode(file_get_contents($root . '/logs/mock-request.json'), true);
        diagnostic_check($request['token'] === $rotated['smoke_token'], "$mode outbound header also uses refreshed smoke config");

        foreach (['', [], 42, "bad\r\nX-Injected: secret", "bad\nvalue", "bad\0value", 'bad token', str_repeat('x', 513)] as $i => $badToken) {
            $invalid = $config; $invalid['smoke_token'] = $badToken;
            diagnostic_config($root, $invalid);
            $before = diagnostic_count($root . '/logs/mock-requests');
            $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
            diagnostic_check(in_array($response['code'], [403, 429], true), "$mode empty/malformed configured token fails closed $i");
            bbf_test_verify_server($server);
            [$exit, $output] = diagnostic_cli($root, ['test_diag', '--live'], $streams);
            diagnostic_check($exit !== 0 && str_contains($output, 'valid smoke_token'), "$mode CLI validates malformed token before outbound header $i");
            diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before, "$mode malformed token sent no outbound request $i");
            diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-cli');
        }
        $local = $config; $local['smoke_token'] = '';
        diagnostic_config($root, $local);
        [$exit, $output] = diagnostic_cli($root, ['test_diag'], $streams);
        diagnostic_check($exit === 0 && str_contains($output, '1/1 forms passed'), "$mode trusted CLI dry invocation needs no smoke credential");
        diagnostic_audit_pair($root, 'smoke_dry', 'allowed', 'completed', 'smoke-cli', 1);

        diagnostic_config($root, $config);
        $loads = diagnostic_count($root . '/logs/config-loads');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $httpBase . '/check.php', null, ['cookie' => $adminCookie]);
        diagnostic_check($response['code'] === 200, "$mode check accepts current administrator session");
        diagnostic_check(diagnostic_count($root . '/logs/config-loads') === $loads + 1, "$mode check loads config once, not again after auth");
        $rotated = $config; $rotated['api_token'] = 'rotated-diagnostic-admin';
        diagnostic_config($root, $rotated);
        $before = diagnostic_count($root . '/logs/probe-requests');
        $response = bbf_test_http($httpServer, $httpBase . '/check.php', null, ['cookie' => $adminCookie]);
        diagnostic_check($response['code'] === 403 && diagnostic_count($root . '/logs/probe-requests') === $before, "$mode check refreshes config and revokes cached administrator session");

        foreach (['missing', 'corrupt'] as $failure) {
            $blocked = $config; $blocked['logs_dir'] = $root . '/audit-' . $mode . '-' . $failure;
            if ($failure === 'corrupt') {
                mkdir($blocked['logs_dir'], 0700);
                file_put_contents($blocked['logs_dir'] . '/access-audit.php', 'not a guarded audit file');
            }
            diagnostic_config($root, $blocked);
            $before = diagnostic_count($root . '/logs/mock-requests');
            bbf_test_verify_server($server);
            $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
            diagnostic_check($response['code'] === 503 && str_contains($response['body'], 'Access audit unavailable'), "$mode $failure audit preflight blocks authorized POST");
            diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before, "$mode $failure audit preflight sent no outgoing request");
        }
        diagnostic_config($root, $config);
        bbf_test_stop_server($httpServer); $httpServer = null;
    }
    $audit = file_get_contents($root . '/logs/access-audit.php');
    diagnostic_check(str_starts_with($audit, '<?php http_response_code(404); exit; ?>'), 'audit file has direct-access guard');
    foreach (['diagnostic-test-smoke', 'rotated-diagnostic-smoke', 'diagnostic-test-admin', 'rotated-diagnostic-admin',
        'diagnostic-scoped-secret', 'test@example.test', 'notify@example.test', '127.0.0.1', '203.0.113.7',
        'mock-id-', 'untrusted.invalid', 'X-Injected', 'bad token', 'Test Value'] as $private) {
        diagnostic_check(!str_contains($audit, $private), 'audit excludes fixture secret/PII: ' . $private);
    }
    foreach (array_slice(file($root . '/logs/access-audit.php', FILE_IGNORE_NEW_LINES), 1) as $line) {
        $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (str_starts_with($entry['action'], 'smoke_') && ($entry['form'] !== '' || $entry['submission_ids'] !== []
            || !in_array($entry['principal_id'], ['anonymous', 'smoke-http', 'smoke-cli'], true))) {
            throw new RuntimeException('Smoke audit includes caller-controlled identifiers.');
        }
    }
    diagnostic_check(true, 'all smoke audit principals are fixed secret-free IDs with no form/body/submission data');
} finally {
    bbf_test_stop_server($httpServer);
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
print "Diagnostics: $passed passed, 0 failed.\n";
