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
    $identity = bin2hex(random_bytes(32));
    $probe = bbf_test_identity_probe($root, $identity);
    $proc = proc_open($command, [0 => ['pipe', 'r'],
        1 => ['file', $root . '/logs/diagnostic-server.log', 'a'],
        2 => ['file', $root . '/logs/diagnostic-server.log', 'a']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start owned diagnostic HTTP server.');
    fclose($pipes[0]);
    $server = ['proc' => $proc, 'root' => $root, 'port' => $port,
        'id' => $identity, 'probe' => $probe];
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
    if (!is_dir($root . '/config')) mkdir($root . '/config', 0700); // credentials of actions live here
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
    // PATH_INFO mock: php -S falls back to a parent index.php for missing files only on some
    // platforms/versions, so file probes (sentinels, shipped files) need a script endpoint.
    file_put_contents($root . '/probe.php', <<<'PHP'
<?php
$path = $_SERVER['PATH_INFO'] ?? '';
file_put_contents(__DIR__ . '/logs/probe-requests', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
$leak = is_file(__DIR__ . '/probe/leak') && preg_match('#\A/(?:[a-z]+/[^/]+|README\.md|CHANGELOG\.md|bbf_auth\.php)\z#', $path);
$status = $leak ? 200 : (int)file_get_contents(__DIR__ . '/probe/status');
http_response_code($status);
if ($status === 302) header('Location: /trap.php');
// 200 serves the real file (a leak) unless the fallback flag mimics a catch-all index page.
$file = __DIR__ . $path;
$real = $status === 200 && !is_file(__DIR__ . '/probe/fallback') && $path !== '/config.php' && is_file($file);
echo $real ? file_get_contents($file) : (is_file(__DIR__ . '/probe/empty') ? '' : 'mock diagnostic response');
PHP);
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
if (!in_array('--anonymous', $argv, true)) $_SERVER['HTTP_X_BBF_TOKEN'] = hash('sha256', 'diagnostic-test-admin');
ob_start();
require __DIR__ . '/check.php';
ob_end_clean();
echo json_encode(array_values(array_filter($results, static fn($r) => str_ends_with($r['name'], 'blocked via HTTP') || str_starts_with($r['name'], '.htaccess has'))));
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
    if (function_exists('curl_init')) {
        // Shared hosts (e.g. Hetzner) run with allow_url_fopen=0; probes must still reach the server.
        file_put_contents($root . '/probe/status', '403');
        file_put_contents($root . '/fopen-off.php', '<?php define("BBF_LOADED", true); require __DIR__ . "/bbf_diagnostics.php";'
            . ' echo json_encode(bbf_diagnostic_probe(["diagnostic_base_url" => ' . var_export($base . '/probe', true) . '], "config.php"));');
        $fopenOff = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'display_errors=stderr', $root . '/fopen-off.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fopenPipes, $root);
        $fopenOut = stream_get_contents($fopenPipes[1]); fclose($fopenPipes[1]); fclose($fopenPipes[2]); proc_close($fopenOff);
        diagnostic_check(trim($fopenOut) === '403', 'probe works with allow_url_fopen=0 via cURL');
    }
    $config = [
        'storage' => 'file', 'api_token' => hash('sha256', 'diagnostic-test-admin'), 'smoke_token' => hash('sha256', 'diagnostic-test-smoke'),
        'smoke_email' => 'test@example.test', 'smoke_notify' => 'notify@example.test',
        'csrf' => false, 'sandbox' => false, 'lang' => 'en', 'rate_limit' => 0,
        'forms_dir' => $root . '/forms', 'submissions_dir' => $root . '/submissions',
        'templates_dir' => $root . '/templates', 'logs_dir' => $root . '/logs',
        'access_tokens' => [['id' => 'diag-reader', 'token' => hash('sha256', 'diagnostic-scoped-secret'), 'forms' => ['test_diag'],
            'permissions' => ['read'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]],
    ];
    diagnostic_config($root, $config);
    file_put_contents($root . '/forms/test_diag.json', json_encode([
        'id' => 'test_diag', 'schema_version' => 1, 'name' => 'Diagnostic mock form',
        'fields' => [['name' => 'message', 'type' => 'text', 'label' => 'Message']],
        'on_submit' => ['store' => false],
    ], JSON_THROW_ON_ERROR));
    [$exit, $output] = diagnostic_cli($root, ['--anonymous'], false, 'run-check.php');
    diagnostic_check(str_contains($output, '<h1>Sign in</h1>') && !str_contains($output, 'Invalid token')
        && !str_contains($output, 'blocked via HTTP'), 'check rejects hostile localhost SERVER_NAME without credentials');
    $response = bbf_test_http($server, $base . '/check.php');
    diagnostic_check($response['code'] === 403, 'check rejects anonymous loopback HTTP request');
    diagnostic_check(str_contains($response['body'], '<form method="post" action="check.php">')
        && !str_contains($response['body'], hash('sha256', 'diagnostic-test-admin')), 'anonymous check page offers a POST sign-in form without secrets');
    // Login CSRF: the form carries a per-session value; a POST without it (another site's form) signs nobody in.
    preg_match('/Set-Cookie: (BBFADMIN=[^;\r\n]+)/i', $response['headers'], $loginCookie);
    preg_match('/name="login_csrf" value="([a-f0-9]{64})"/', $response['body'], $loginCsrf);
    diagnostic_check(isset($loginCookie[1], $loginCsrf[1]), 'sign-in page sets the BBFADMIN cookie and a login CSRF value');
    $session = ['cookie' => $loginCookie[1] ?? ''];
    $forged = bbf_test_http($server, $base . '/check.php', ['token' => hash('sha256', 'diagnostic-test-admin')]);
    diagnostic_check($forged['code'] === 403 && str_contains($forged['body'], 'form expired') && !str_contains($forged['body'], 'Invalid token')
        && !str_contains($forged['headers'], 'Location:'),
        'a sign-in POST without the login CSRF value does not sign in, even with the right token');
    $forged = bbf_test_http($server, $base . '/check.php', ['token' => hash('sha256', 'diagnostic-test-admin'), 'login_csrf' => str_repeat('0', 64)], $session);
    diagnostic_check($forged['code'] === 403 && str_contains($forged['body'], 'form expired'), 'a wrong login CSRF value does not sign in');
    $login = bbf_test_http($server, $base . '/check.php', ['token' => 'wrong-token', 'login_csrf' => $loginCsrf[1] ?? ''], $session);
    diagnostic_check($login['code'] === 403 && str_contains($login['body'], 'Invalid token'), 'sign-in form rejects a wrong token and says so');
    diagnostic_check(!str_contains($response['body'], 'Invalid token'), 'first sign-in page shows no failure message');
    $login = bbf_test_http($server, $base . '/check.php', ['token' => hash('sha256', 'diagnostic-test-admin'), 'login_csrf' => $loginCsrf[1] ?? ''], $session);
    diagnostic_check($login['code'] === 303 && preg_match('/^Location: check\.php\r?$/mi', $login['headers']) === 1
        && preg_match('/^Set-Cookie: /mi', $login['headers']) === 1, 'sign-in form POST starts a session and redirects to a clean URL');
    foreach (['README.md', 'CHANGELOG.md'] as $doc) if (!is_file("$root/$doc")) bbf_test_copy(dirname(__DIR__) . "/$doc", "$root/$doc");
    foreach ([null, 403, 404, 200, 302, 500, 'tls-failure'] as $status) {
        $config['diagnostic_base_url'] = $status === null ? '' : ($status === 'tls-failure' ? str_replace('http:', 'https:', $base) : $base) . '/probe.php';
        if (is_int($status)) file_put_contents($root . '/probe/status', (string)$status);
        diagnostic_config($root, $config);
        bbf_test_verify_server($server);
        [$exit, $output] = diagnostic_cli($root, [], false, 'run-check.php');
        $rows = json_decode($output, true);
        diagnostic_check($exit === 0 && is_array($rows) && count($rows) === 11, 'check runtime emits eleven probe results (incl. README.md, CHANGELOG.md, bbf_*.php and config/) for ' . var_export($status, true));
        foreach ($rows as $row) {
            diagnostic_check($row['pass'] === in_array($status, [403, 404], true), $row['name'] . ' correct classification ' . var_export($status, true));
            if ($status === null || $status === 'tls-failure') diagnostic_check(str_contains($row['detail'], 'Not verified'), 'failed/unconfigured probe is visibly unverified');
        }
    }
    diagnostic_check(!file_exists($root . '/logs/trap'), 'check runtime never follows redirect');
    // Server without rules (Nginx, php -S): directory URLs deny, but files inside are served.
    $config['diagnostic_base_url'] = $base . '/probe.php';
    diagnostic_config($root, $config);
    file_put_contents($root . '/probe/status', '403');
    file_put_contents($root . '/probe/leak', '1');
    file_put_contents($root . '/logs/probe-requests', '');
    foreach (['templates/notify.html', 'actions/README.md', 'forms/form.schema.json', 'README.md'] as $leaked) {
        if (!is_file($root . '/' . $leaked)) bbf_test_copy(dirname(__DIR__) . '/' . $leaked, $root . '/' . $leaked);
    }
    bbf_test_verify_server($server);
    [$exit, $output] = diagnostic_cli($root, [], false, 'run-check.php');
    $rows = array_column(json_decode($output, true) ?: [], null, 'name');
    foreach (['templates', 'actions', 'forms'] as $dir) {
        diagnostic_check(($rows["$dir/ blocked via HTTP"]['pass'] ?? true) === false
            && str_contains($rows["$dir/ blocked via HTTP"]['detail'] ?? '', 'publicly readable'),
            "$dir/ fails when the directory URL is denied but a file inside is served");
    }
    diagnostic_check(($rows['README.md blocked via HTTP']['pass'] ?? true) === false
        && ($rows['README.md blocked via HTTP']['level'] ?? '') === 'error'
        && str_contains($rows['README.md blocked via HTTP']['detail'] ?? '', 'reveals the installed version'),
        'a publicly served README.md is reported as an error');
    diagnostic_check(($rows['CHANGELOG.md blocked via HTTP']['pass'] ?? true) === false
        && ($rows['CHANGELOG.md blocked via HTTP']['level'] ?? '') === 'error',
        'a publicly served CHANGELOG.md is reported as an error too');
    $requests = (string)file_get_contents($root . '/logs/probe-requests');
    diagnostic_check(preg_match('#/probe\.php/submissions/bbf-check-[0-9a-f]{32}\.txt#', $requests) === 1,
        'submissions/ is probed with a real sentinel file, not only the directory URL');
    diagnostic_check(preg_match('#/probe\.php/config/bbf-check-[0-9a-f]{32}\.txt#', $requests) === 1
        && ($rows['config/ blocked via HTTP']['pass'] ?? true) === false && ($rows['config/ blocked via HTTP']['level'] ?? '') === 'error',
        'config/ is probed with a sentinel file and a served one is an error');
    diagnostic_check(str_contains($requests, '/probe.php/bbf_auth.php') && ($rows['Libraries (bbf_*.php) blocked via HTTP']['pass'] ?? true) === false,
        'a library served over HTTP is reported');
    diagnostic_check(glob($root . '/submissions/bbf-check-*') === [] && glob($root . '/logs/bbf-check-*') === [] && glob($root . '/config/bbf-check-*') === [],
        'check.php removes its sentinel files');
    // Catch-all fallback (php -S, SPA try_files): HTTP 200, but not the requested file.
    file_put_contents($root . '/probe/fallback', '1');
    file_put_contents($root . '/probe/empty', '1');
    file_put_contents($root . '/probe/status', '200');
    bbf_test_verify_server($server);
    [$exit, $output] = diagnostic_cli($root, [], false, 'run-check.php');
    $rows = array_column(json_decode($output, true) ?: [], null, 'name');
    diagnostic_check(($rows['README.md blocked via HTTP']['pass'] ?? false) === true, 'README.md fallback page is not reported as exposed');
    diagnostic_check(!isset($rows['.htaccess has the rules of this release']), 'no .htaccess rule problem is reported for an installation without one');
    $ownHtaccess = @file_get_contents($root . '/.htaccess');
    file_put_contents($root . '/.htaccess', "AddHandler application/x-httpd-php84 .php\n");
    [, $htOutput] = diagnostic_cli($root, [], false, 'run-check.php');
    $htRow = array_column(json_decode($htOutput, true) ?: [], null, 'name')['.htaccess has the rules of this release'] ?? [];
    diagnostic_check(($htRow['pass'] ?? true) === false && str_contains($htRow['detail'] ?? '', '\.md$'),
        'check.php reports an .htaccess that lacks the *.md rule of this release');
    is_string($ownHtaccess) ? file_put_contents($root . '/.htaccess', $ownHtaccess) : unlink($root . '/.htaccess');
    foreach (['submissions', 'logs', 'templates', 'actions', 'forms', 'tests'] as $dir) {
        diagnostic_check(($rows["$dir/ blocked via HTTP"]['pass'] ?? false) === true
            && str_contains($rows["$dir/ blocked via HTTP"]['detail'] ?? '', 'fallback'),
            "$dir/ is not reported as exposed when HTTP 200 returns a fallback page");
    }
    $cfgRow = $rows['config.php blocked via HTTP'] ?? [];
    diagnostic_check(($cfgRow['level'] ?? '') === 'warn' && str_contains($cfgRow['detail'] ?? '', 'guard works'),
        'empty HTTP 200 for config.php is a warning (guard works), not an error');
    $libRow = $rows['Libraries (bbf_*.php) blocked via HTTP'] ?? [];
    diagnostic_check(($libRow['pass'] ?? true) === false && ($libRow['level'] ?? '') === 'warn' && str_contains($libRow['detail'] ?? '', 'nothing leaked'),
        'an empty HTTP 200 for a library is a warning to add the bbf_*.php rule');
    unlink($root . '/probe/fallback');
    unlink($root . '/probe/empty');
    file_put_contents($root . '/probe/status', '403');
    // A directory missing from the release (tests/ in the ZIP) has nothing to probe.
    rename($root . '/actions', $root . '/actions-off');
    try {
        [$exit, $output] = diagnostic_cli($root, [], false, 'run-check.php');
    } finally {
        rename($root . '/actions-off', $root . '/actions');
    }
    $rows = array_column(json_decode($output, true) ?: [], null, 'name');
    diagnostic_check(($rows['actions/ blocked via HTTP']['pass'] ?? false) === true
        && str_contains($rows['actions/ blocked via HTTP']['detail'] ?? '', 'Not present'),
        'absent directory is skipped instead of flagged by a fallback response');
    unlink($root . '/probe/leak');
    // Failed TLS negotiation on our still-owned HTTP listener: no released-port race.
    bbf_test_verify_server($server);
    diagnostic_check(bbf_diagnostic_probe(['diagnostic_base_url' => str_replace('http:', 'https:', $base) . '/probe'], 'config.php') === null,
        'owned transport failure is unverified, not blocked');
    unset($config['diagnostic_base_url']);
    diagnostic_config($root, $config);
    [$exit, $output] = diagnostic_cli($root, ['test_diag', '--live']);
    diagnostic_check($exit !== 0 && str_contains($output, 'diagnostic_base_url'), 'CLI live mode rejects absent fixed target');
    $response = bbf_test_http($server, $base . '/poison-smoke.php?live=1&form=test_diag', [], ['headers' => ['X-BBF-Smoke-Token' => hash('sha256', 'diagnostic-test-smoke')]]);
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
        diagnostic_check($request['token'] === hash('sha256', 'diagnostic-test-smoke') && $request['method'] === 'POST', "$mode authenticates mock via POST header");
        diagnostic_check(!file_exists($root . '/logs/trap'), "$mode never follows redirect carrying credential");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-cli', 1);
    }

    foreach ([false, true] as $streams) {
        $mode = $streams ? 'HTTP streams' : 'HTTP cURL';
        $httpServer = diagnostic_start_server($root, $streams);
        $httpBase = 'http://127.0.0.1:' . $httpServer['port'];
        $smokeUrl = $httpBase . '/smoketest.php?form=test_diag';
        $header = ['headers' => ['X-BBF-Smoke-Token' => hash('sha256', 'diagnostic-test-smoke')]];
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
            [$smokeUrl . '&token=' . hash('sha256', 'diagnostic-test-smoke'), []],
            [$smokeUrl . '&smoke_token=' . hash('sha256', 'diagnostic-test-smoke'), $header],
            [$smokeUrl . '&token=' . hash('sha256', 'diagnostic-test-smoke'), $header],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => '']]],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => 'bad token']]],
            [$smokeUrl, ['headers' => ['X-BBF-Smoke-Token' => str_repeat('x', 513)]]],
            [$httpBase . '/array-smoke.php?form=test_diag', $header],
        ] as $i => [$url, $options]) {
            $response = bbf_test_http($httpServer, $url . '&live=1', [], $options);
            diagnostic_check(in_array($response['code'], [403, 429], true), "$mode missing/query/empty/malformed HTTP credential rejected $i");
            diagnostic_audit_pair($root, 'smoke_live', 'denied', 'failed', 'anonymous');
        }
        foreach ([hash('sha256', 'diagnostic-test-admin') => 'legacy-admin', hash('sha256', 'diagnostic-scoped-secret') => 'diag-reader'] as $token => $id) {
            $response = bbf_test_http($httpServer, $httpBase . '/auth-fixture.php', null, ['headers' => ['X-BBF-Token' => $token]]);
            diagnostic_check(($response['json']['id'] ?? '') === $id && preg_match('/Set-Cookie: (BBFADMIN=[^;\r\n]+)/i', $response['headers'], $m) === 1, "$mode obtains genuine $id management cookie" . ($response['code'] === 500 ? file_get_contents($root . '/logs/php-error.log') : ''));
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
        diagnostic_check($request['method'] === 'POST' && $request['token'] === hash('sha256', 'diagnostic-test-smoke') && $request['uri'] === '/target/submit.php?form=test_diag', "$mode outbound token remains header-only");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'completed', 'smoke-http', 1);
        file_put_contents($root . '/target/status', '302');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $smokeUrl . '&live=1', [], $header);
        diagnostic_check($response['code'] === 422 && !file_exists($root . '/logs/trap'), "$mode redirects fail without forwarding credentials");
        diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-http', 1);

        $rotated = $config;
        $rotated['smoke_token'] = hash('sha256', 'rotated-diagnostic-smoke');
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
            diagnostic_check($exit !== 0 && str_contains($output, 'generated smoke_token') && str_contains($output, '32 hexadecimal characters'), "$mode CLI validates malformed token before outbound header $i");
            diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before, "$mode malformed token sent no outbound request $i");
            diagnostic_audit_pair($root, 'smoke_live', 'allowed', 'failed', 'smoke-cli');
        }
        $local = $config; $local['smoke_token'] = '';
        diagnostic_config($root, $local);
        [$exit, $output] = diagnostic_cli($root, ['test_diag'], $streams);
        diagnostic_check($exit === 0 && str_contains($output, '1/1 forms passed'), "$mode trusted CLI dry invocation needs no smoke credential");
        diagnostic_audit_pair($root, 'smoke_dry', 'allowed', 'completed', 'smoke-cli', 1);
        // 2.1.3: required fields with a pattern pass even when the placeholder does not match it.
        file_put_contents($root . '/forms/pattern_diag.json', json_encode([
            'id' => 'pattern_diag', 'schema_version' => 1, 'name' => 'Pattern smoke form',
            'fields' => [
                ['name' => 'code', 'type' => 'text', 'label' => 'Code', 'required' => true, 'pattern' => '^[A-Z]{2}\d{4}$', 'placeholder' => 'napr. SK1234'],
                ['name' => 'zip', 'type' => 'text', 'label' => 'ZIP', 'required' => true, 'pattern' => '^\d{3} ?\d{2}$', 'placeholder' => 'PSČ'],
                ['name' => 'iban', 'type' => 'text', 'label' => 'IBAN', 'required' => true, 'pattern' => '^SK\d{2}(?:\s?\d{4}){5}$', 'minlength' => 24],
                ['name' => 'answer', 'type' => 'text', 'label' => 'Answer', 'required' => true, 'pattern' => '^(ano|nie)$'],
                ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'required' => true, 'pattern' => '^09\d{8}$'],
                ['name' => 'mail', 'type' => 'email', 'label' => 'Mail', 'required' => true, 'pattern' => '^[^@]+@firma\.sk$'],
                ['name' => 'note', 'type' => 'textarea', 'label' => 'Note', 'required' => true, 'pattern' => '^[a-z]+$'],
            ],
            'on_submit' => ['store' => false],
        ], JSON_THROW_ON_ERROR));
        [$exit, $output] = diagnostic_cli($root, ['pattern_diag'], $streams);
        diagnostic_check($exit === 0 && str_contains($output, '1/1 forms passed'), "$mode dry smoke builds values from field patterns, not only from placeholders");
        // Review 2.1.4: live mode never makes up an address in a real domain ("a@firma.sk") for a pattern smoke_email does not match.
        diagnostic_config($root, $config);
        $before = diagnostic_count($root . '/logs/mock-requests');
        [$exit, $output] = diagnostic_cli($root, ['pattern_diag', '--live'], $streams);
        diagnostic_check($exit !== 0 && str_contains($output, 'smoke_email does not match') && !str_contains($output, '@firma.sk')
            && diagnostic_count($root . '/logs/mock-requests') === $before, "$mode live smoke refuses to submit when smoke_email does not match an email pattern");
        diagnostic_config($root, $local);
        unlink($root . '/forms/pattern_diag.json');
        // Review 2.1.4: an extreme pattern or minlength cannot exhaust memory; a required field never gets "".
        file_put_contents($root . '/forms/extreme_diag.json', json_encode([
            'id' => 'extreme_diag', 'schema_version' => 1, 'name' => 'Extreme smoke form',
            'fields' => [
                ['name' => 'huge', 'type' => 'text', 'label' => 'Huge', 'required' => true, 'pattern' => '^((a{1000}){1000}){1000}$'],
                ['name' => 'long', 'type' => 'textarea', 'label' => 'Long', 'required' => true, 'minlength' => 1000000000],
            ],
            'on_submit' => ['store' => false],
        ], JSON_THROW_ON_ERROR));
        [$exit, $output] = diagnostic_cli($root, ['extreme_diag'], $streams);
        diagnostic_check(str_contains($output, 'extreme_diag') && !str_contains($output, 'Allowed memory size') && !str_contains($output, 'Fatal'),
            "$mode extreme pattern/minlength is reported, memory is not exhausted");
        // Review 2.1.5: a minlength above the 5000-character pattern cap is still met with plain text.
        file_put_contents($root . '/forms/extreme_diag.json', json_encode([
            'id' => 'extreme_diag', 'schema_version' => 1, 'name' => 'Long text smoke form',
            'fields' => [['name' => 'long', 'type' => 'textarea', 'label' => 'Long', 'required' => true, 'minlength' => 6600, 'maxlength' => 6600]],
            'on_submit' => ['store' => false],
        ], JSON_THROW_ON_ERROR));
        [$exit, $output] = diagnostic_cli($root, ['extreme_diag'], $streams);
        diagnostic_check($exit === 0 && str_contains($output, '1/1 forms passed'), "$mode minlength 6600 (above the pattern cap, divisible by 11) passes" . ($exit === 0 ? '' : ': ' . substr($output, -600)));
        unlink($root . '/forms/extreme_diag.json');
        file_put_contents($root . '/forms/empty_diag.json', json_encode([
            'id' => 'empty_diag', 'schema_version' => 1, 'name' => 'Empty-value smoke form',
            'fields' => [
                ['name' => 'star', 'type' => 'text', 'label' => 'Star', 'required' => true, 'pattern' => '^a*$'],
                ['name' => 'pick', 'type' => 'select', 'label' => 'Pick', 'required' => true, 'options' => [['value' => '', 'label' => 'Choose…'], ['value' => 'x', 'label' => 'X']]],
                ['name' => 'tick', 'type' => 'radio', 'label' => 'Tick', 'required' => true, 'options' => ['', 'yes']],
            ],
            'on_submit' => ['store' => false],
        ], JSON_THROW_ON_ERROR));
        [$exit, $output] = diagnostic_cli($root, ['empty_diag'], $streams);
        diagnostic_check($exit === 0 && str_contains($output, '1/1 forms passed'), "$mode required fields never get an empty value (\"^a*\$\", \"Choose…\" option)");
        unlink($root . '/forms/empty_diag.json');

        diagnostic_config($root, $config);
        $loads = diagnostic_count($root . '/logs/config-loads');
        bbf_test_verify_server($server);
        $response = bbf_test_http($httpServer, $httpBase . '/check.php', null, ['cookie' => $adminCookie]);
        diagnostic_check($response['code'] === 200, "$mode check accepts current administrator session");
        diagnostic_check(diagnostic_count($root . '/logs/config-loads') === $loads + 1, "$mode check loads config once, not again after auth");
        $rotated = $config; $rotated['api_token'] = hash('sha256', 'rotated-diagnostic-admin');
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
            if ($failure === 'missing') diagnostic_check(str_contains($response['body'], 'logs directory does not exist')
                && !str_contains($response['body'], $blocked['logs_dir']) && !str_contains($response['body'], basename($blocked['logs_dir'])),
                "$mode missing logs directory is explained without revealing its path");
            diagnostic_check(diagnostic_count($root . '/logs/mock-requests') === $before, "$mode $failure audit preflight sent no outgoing request");
        }
        diagnostic_config($root, $config);
        bbf_test_stop_server($httpServer); $httpServer = null;
    }
    $audit = file_get_contents($root . '/logs/access-audit.php');
    diagnostic_check(str_starts_with($audit, '<?php http_response_code(404); exit; ?>'), 'audit file has direct-access guard');
    foreach ([hash('sha256', 'diagnostic-test-smoke'), hash('sha256', 'rotated-diagnostic-smoke'), hash('sha256', 'diagnostic-test-admin'), hash('sha256', 'rotated-diagnostic-admin'),
        hash('sha256', 'diagnostic-scoped-secret'), 'test@example.test', 'notify@example.test', '127.0.0.1', '203.0.113.7',
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
