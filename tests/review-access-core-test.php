<?php
/** G2 core only: deliberately not the full review-access-test.php milestone gate. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0;
function core_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
    print "PASS $name\n";
}
$root = bbf_test_installation(dirname(__DIR__));
try {
    foreach (['viewer.php', 'editor.php', 'check.php'] as $file) bbf_test_copy(dirname(__DIR__) . '/' . $file, "$root/$file");
    bbf_test_copy(__FILE__, "$root/tests/review-access-core-test.php");
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    $tokens = [];
    foreach (['reader' => ['read'], 'exporter' => ['read', 'export'], 'deleter' => ['read', 'delete'],
              'exportonly' => ['export'], 'deleteonly' => ['delete'], 'empty' => [], 'all' => ['read', 'export', 'delete']] as $id => $permissions) {
        $tokens[] = ['id' => $id, 'token' => hash('sha256', "fixture-secret-$id-76543210"), 'forms' => ['alpha', 'orphan'],
            'permissions' => $permissions, 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
    }
    $baseConfig = ['api_token' => 'c9ffddaa918a1b920038790fc128858957809e3c80165b8a8cbbe77c556883f2', 'access_tokens' => $tokens,
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'lang' => 'en', 'mail' => ['method' => 'mail'],
        'delivery' => ['lease_seconds' => 5, 'retry_delay' => 1, 'max_attempts' => 3]];
    function core_config(array $config): void {
        global $root;
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        @unlink("$root/logs/.auth_failures.json"); // each scenario starts with a clean wrong-token counter
        clearstatcache();
    }
    function core_seed(string $form, string $id = 'bbf_one'): void {
        global $root;
        if (!is_dir("$root/submissions/$form")) mkdir("$root/submissions/$form", 0700);
        file_put_contents("$root/submissions/$form/$id.json", json_encode(['id' => $id, 'form' => $form,
            'data' => ['answer' => "$form-private-body", 'email' => 'private-person@example.invalid'],
            'meta' => ['submitted' => '2026-09-08T10:00:00Z']]));
    }
    foreach (['alpha', 'beta'] as $id) {
        file_put_contents("$root/forms/$id.json", json_encode(['id' => $id, 'name' => "$id-private-name",
            'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text', 'default' => 'definition-secret',
                'lookup' => ['url' => 'https://private.invalid']], ['name' => 'email', 'type' => 'email']],
            'storage' => ['password' => 'storage-secret'], 'on_submit' => ['webhook' => 'delivery-secret']]));
        core_seed($id);
    }
    core_seed('orphan');
    core_config($baseConfig);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    function core_http(string $path, array $options = []): array {
        global $server;
        return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/' . $path, null, $options);
    }
    function core_header(string $id): array {
        global $baseConfig;
        return ['headers' => ['X-BBF-Token' => $id === 'admin' ? $baseConfig['api_token'] : hash('sha256', "fixture-secret-$id-76543210")]];
    }
    function core_login(string $id, string $page = 'viewer.php'): array {
        $r = core_http($page, core_header($id));
        core_check($r['code'] === 200, "$id login $page");
        preg_match('/Set-Cookie:\s*(BBFADMIN=[^;\r\n]+)/i', $r['headers'], $cookie);
        preg_match('/const TOKEN = ("[^"]+");/', $r['body'], $csrf);
        core_check(isset($cookie[1], $csrf[1]), 'login provides session and CSRF, not credentials');
        return ['cookie' => $cookie[1], 'csrf' => json_decode($csrf[1], true), 'response' => $r];
    }
    function core_mutate(string $action, array $session, array $body, string $method = 'POST', bool $csrf = true, string $page = 'viewer.php'): array {
        return core_http("$page?action=$action", ['cookie' => $session['cookie'], 'method' => $method,
            'headers' => ['Content-Type' => 'application/json'] + ($csrf ? ['X-BBF-Viewer-Token' => $session['csrf'], 'X-BBF-Editor-Token' => $session['csrf']] : []),
            'raw' => json_encode($body)]);
    }
    function core_editor_save(string $form, array $session, array $definition): array {
        $state = core_http("editor.php?action=state&form=$form", ['cookie' => $session['cookie']]);
        return core_http("editor.php?action=save&form=$form", ['cookie' => $session['cookie'], 'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json', 'X-BBF-Editor-Token' => $session['csrf'],
                'X-BBF-Revision' => (string)($state['json']['revision'] ?? '')], 'raw' => json_encode($definition)]);
    }
    foreach (['viewer.php', 'viewer.php?action=dashboard', 'editor.php', 'editor.php?action=list',
              'submissions.php?form=alpha'] as $path) core_check(core_http($path)['code'] === 403, "anonymous loopback denied $path");
    core_check(core_http('tests/review-access-core-test.php')['code'] === 403, 'core test HTTP guard');
    foreach (['viewer.php?action=submissions&form=alpha', 'submissions.php?form=alpha'] as $path) {
        core_check(core_http($path, core_header('reader'))['code'] === 200, "valid header $path");
        $stateless = str_starts_with($path, 'submissions.php');
        core_check(core_http($path . '&token=bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f')['code'] === ($stateless ? 200 : 403),
            $stateless ? "valid query API $path" : "a session page never accepts ?token= ($path)");
        core_check(core_http($path . '&token=bad', core_header('reader'))['code'] === 200, 'header wins over bad query');
        core_check(core_http($path . '&token=bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f', ['headers' => ['X-BBF-Token' => 'bad']])['code'] === 403, 'bad header wins over good query');
    }
    // Review 2.1.4: ?token= never signs a browser in (login CSRF through a link) and never sets a session cookie.
    $q = core_http('viewer.php?token=bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f&form=alpha');
    core_check($q['code'] === 403 && str_contains($q['body'], 'Tokens in the address are not accepted') && !preg_match('/Location:/i', $q['headers']),
        'HTML ?token= shows the sign-in form with a notice instead of signing in');
    core_check(!str_contains($q['body'] . $q['headers'], 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f'), 'ignored query token is never echoed');
    $sandboxQuery = core_http('editor.php?token=' . $baseConfig['api_token']);
    core_check($sandboxQuery['code'] === 403 && !str_contains($sandboxQuery['body'], 'const TOKEN'), 'editor ?token= with the admin token does not sign in');
    // A cross-site request (an <img> on another site) cannot use ?token= at all, so it cannot spend the address's wrong-token budget.
    for ($i = 0; $i < 12; $i++) $cross = core_http('submissions.php?form=alpha&token=cross-guess-' . $i, ['headers' => ['Sec-Fetch-Site' => 'cross-site']]);
    core_check($cross['code'] === 403, 'cross-site ?token= is ignored (403, never 429)');
    core_check(str_contains($cross['body'], 'opened from another site') && !str_contains($cross['body'], 'cross-guess'),
        'review 2.1.6: the cross-site CSV link explains why it is refused, without echoing the token');
    core_check(core_http('submissions.php?form=alpha')['body'] === '{"error":"Access denied."}', 'a request without ?token= keeps the plain message');
    core_check(core_http('submissions.php?form=alpha&token=bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f', ['headers' => ['Sec-Fetch-Site' => 'cross-site']])['code'] === 403,
        'even a right token is ignored on a cross-site request');
    core_check(core_http('submissions.php?form=alpha&token=bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f', ['headers' => ['Sec-Fetch-Site' => 'none']])['code'] === 200,
        'a typed URL / bookmark (Sec-Fetch-Site: none) still works');
    core_check(core_http('viewer.php', core_header('admin'))['code'] === 200, 'cross-site attempts did not block the address');
    core_config($baseConfig);
    $reader = core_login('reader');
    foreach (['</script><script>alert(1)</script>' => 'en', 'pt-BR' => 'pt-BR', 'zh-TW' => 'zh-TW', "sk'" => 'en'] as $lang => $expected) {
        $page = core_http('viewer.php?lang=' . rawurlencode($lang), ['cookie' => $reader['cookie']]);
        core_check($page['code'] === 200 && str_contains($page['body'], 'const LANG = ' . json_encode($expected) . ';')
            && !str_contains($page['body'], '<script>alert(1)'), 'viewer ?lang accepts only a language code: ' . json_encode($lang));
    }
    core_check(!str_contains($reader['response']['body'], 'beta-private') && !str_contains($reader['response']['body'], 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f'), 'sidebar/bootstrap exclude cross-form metadata and token');
    core_check(str_contains(strtolower($reader['response']['headers']), 'httponly') && str_contains(strtolower($reader['response']['headers']), 'samesite=strict') && str_contains($reader['response']['headers'], 'no-store') && str_contains($reader['response']['headers'], 'no-referrer'), 'session/privacy headers');
    foreach (['list_forms', 'dashboard'] as $action) {
        $r = core_http("viewer.php?action=$action", ['cookie' => $reader['cookie']]);
        core_check($r['code'] === 200 && !str_contains($r['body'], 'beta') && !str_contains($r['body'], 'delivery-secret') && !str_contains($r['body'], 'definition-secret'), "$action no cross-form/definition leak");
        if ($action === 'dashboard') core_check($r['json']['total'] === 1, 'dashboard aggregates only authorized enumerated form');
    }
    foreach (['submissions', 'detail', 'stats', 'export', 'print'] as $action) {
        core_check(core_http("viewer.php?action=$action&form=beta&id=bbf_one", core_header('all'))['code'] === 403, "cross-form $action denied");
        $r = core_http("viewer.php?action=$action&form=alpha&id=bbf_one", core_header(in_array($action, ['export', 'print'], true) ? 'exporter' : 'reader'));
        core_check($r['code'] === 200 && !str_contains($r['body'], 'definition-secret') && !str_contains($r['body'], 'storage-secret') && !str_contains($r['body'], 'delivery-secret'), "authorized $action with presentation allowlist");
        core_check(core_http("viewer.php?action=$action&form=orphan&id=bbf_one", core_header('all'))['code'] === 200, "exact scope works without enumeration $action");
    }
    foreach (['reader', 'exportonly', 'deleteonly', 'empty'] as $id) {
        core_check(core_http('viewer.php?action=export&form=alpha', core_header($id))['code'] === 403, "$id cannot CSV without read+export");
        core_check(core_http('submissions.php?form=alpha&format=csv', core_header($id))['code'] === 403, "$id stateless CSV denied");
    }
    core_check(core_http('submissions.php?form=alpha&format=csv', core_header('exporter'))['code'] === 200, 'stateless scoped CSV succeeds');
    core_check(core_http('submissions.php?form=beta&id=bbf_one', core_header('all'))['code'] === 403, 'stateless cross-form detail denied');
    core_check(core_http('submissions.php?form=alpha', ['cookie' => $reader['cookie']])['code'] === 403, 'stateless API ignores session');
    $all = core_login('all'); $deleter = core_login('deleter'); $exporter = core_login('exporter');
    foreach (['delete', 'bulk_delete', 'forward'] as $action) {
        $body = ['form' => 'alpha', 'id' => 'bbf_one', 'ids' => ['bbf_one'], 'to' => 'invalid-recipient'];
        core_check(core_mutate($action, $reader, $body)['code'] === 403, "read-only $action denied");
        core_check(core_mutate($action, $all, $body, 'GET')['code'] === 405, "$action requires POST");
        core_check(core_mutate($action, $all, $body, 'POST', false)['code'] === 403, "$action requires CSRF");
        $body['form'] = 'beta';
        core_check(core_mutate($action, $all, $body)['code'] === 403, "$action cross-form mutation denied");
    }
    core_check(core_mutate('forward', $exporter, ['form' => 'alpha', 'id' => 'bbf_one', 'to' => 'invalid-recipient'])['code'] === 400, 'forward authorization reaches validation without live delivery');
    core_check(core_mutate('delete', $deleter, ['form' => 'orphan', 'id' => 'bbf_one'])['code'] === 200, 'scoped delete non-enumerated form');
    core_seed('alpha', 'bbf_two'); core_seed('alpha', 'bbf_three');
    $r = core_mutate('bulk_delete', $deleter, ['form' => 'alpha', 'ids' => ['bbf_two', 'bbf_three', 'bbf_absent']]);
    core_check($r['code'] === 200 && $r['json']['deleted'] === 2, 'bulk deletion actual count');
    foreach (['', 'list', 'load', 'state', 'history', 'preview_page', 'validate', 'save', 'publish', 'rollback', 'create', 'delete'] as $action)
        core_check(core_http("editor.php?action=$action&form=alpha", core_header('all'))['code'] === 403, "scoped credentials never edit: $action");
    $admin = core_login('admin', 'editor.php');
    core_check(core_http('viewer.php?action=submissions&form=beta', ['cookie' => $admin['cookie']])['code'] === 200, 'admin session shared across editor/viewer');
    foreach (['save', 'publish', 'rollback', 'create', 'delete'] as $action) {
        core_check(core_mutate($action . '&form=alpha', $admin, ['id' => 'alpha'], 'GET', true, 'editor.php')['code'] === 405, "editor $action method guard");
        core_check(core_mutate($action . '&form=alpha', $admin, ['id' => 'alpha'], 'POST', false, 'editor.php')['code'] === 403, "editor $action CSRF guard");
    }
    $arrayName = core_mutate('create', $admin, ['id' => 'arrname', 'name' => ['x']], 'POST', true, 'editor.php');
    core_check($arrayName['code'] === 400 && !is_file("$root/forms/arrname.json"), 'editor create rejects a non-text name with 400, not a TypeError 500');
    core_check(core_mutate('create', $admin, ['id' => ['alpha']], 'POST', true, 'editor.php')['code'] === 400, 'editor create rejects a non-text ID with 400');
    core_check(core_mutate('create', $admin, ['id' => 'created', 'name' => 'Created'], 'POST', true, 'editor.php')['code'] === 200, 'admin create succeeds');
    core_check(core_editor_save('created', $admin, ['id' => 'created', 'fields' => []])['code'] === 200, 'admin versioned draft save succeeds');
    core_check(core_mutate('delete', $admin, ['id' => 'created'], 'POST', true, 'editor.php')['code'] === 200, 'admin delete succeeds');
    // Real HTTP Print/PDF requests, independently of the file-based Chromium response stubs.
    function core_print_audit(array $session, string $query, int $code, string $decision, string $result, int $count): array {
        global $root;
        $before = count(file("$root/logs/access-audit.php"));
        $r = core_http('viewer.php?action=print&' . $query, ['cookie' => $session['cookie']]);
        core_check($r['code'] === $code && ($code === 200 || !str_contains($r['body'], '-private-body')), "print HTTP $code, no disclosure on denial");
        $rows = array_map(fn($line) => json_decode($line, true), array_slice(file("$root/logs/access-audit.php"), $before));
        parse_str($query, $params);
        core_check(count($rows) === 2 && $rows[0]['action'] === 'viewer_print' && $rows[1]['action'] === 'viewer_print'
            && $rows[0]['form'] === $params['form'] && $rows[1]['form'] === $params['form']
            && $rows[0]['submission_ids'] === [$params['id']] && $rows[1]['submission_ids'] === [$params['id']]
            && $rows[0]['decision'] === $decision && $rows[1]['decision'] === $decision
            && $rows[0]['result'] === 'attempted' && $rows[0]['result_count'] === 0
            && $rows[1]['result'] === $result && $rows[1]['result_count'] === $count, 'print exact operation/form/id/decision/result/count audit pair');
        return $r;
    }
    foreach (['reader', 'exportonly', 'deleteonly', 'empty'] as $id)
        core_check(core_http('viewer.php?action=print&form=alpha&id=bbf_one', core_header($id))['code'] === 403, "$id cannot print without read+export");
    foreach (['rotate', 'remove', 'expire', 'revoke', 'scope', 'permissions'] as $change) {
        core_config($baseConfig); $s = core_login('exporter'); $second = core_login('all');
        $loaded = core_http('viewer.php?action=detail&form=alpha&id=bbf_one', ['cookie' => $s['cookie']]);
        core_check($loaded['code'] === 200 && $loaded['json']['submission']['data']['answer'] === 'alpha-private-body', "print record loaded before $change");
        $config = $baseConfig;
        switch ($change) {
            case 'rotate': $config['access_tokens'][1]['token'] = '7f0253d4866e1feb0718fdb2db5fce55123fbb1cc0981d6806fd2eee18f54cd2'; break;
            case 'remove': array_splice($config['access_tokens'], 1, 1); break;
            case 'expire': $config['access_tokens'][1]['expires_at'] = '2000-01-01T00:00:00Z'; break;
            case 'revoke': $config['access_tokens'][1]['revoked'] = true; break;
            case 'scope': $config['access_tokens'][1]['forms'] = []; break;
            case 'permissions': $config['access_tokens'][1]['permissions'] = ['read']; break;
        }
        core_config($config);
        core_print_audit($s, 'form=alpha&id=bbf_one', 403, 'denied', 'failed', 0);
        core_print_audit($second, 'form=alpha&id=bbf_one', 200, 'allowed', 'completed', 1);
        core_check(core_http('viewer.php?action=detail&form=alpha&id=bbf_one', ['cookie' => $second['cookie']])['code'] === 200, "unaffected second session reads after individual $change");
        core_check(core_http('viewer.php?action=print&form=alpha&id=bbf_one', core_header('all'))['code'] === 200, "unaffected second token prints after individual $change");
    }
    core_config($baseConfig); $s = core_login('exporter');
    $loaded = core_http('viewer.php?action=detail&form=alpha&id=bbf_one', ['cookie' => $s['cookie']]);
    $fresh = $loaded['json']['submission']; $fresh['data']['answer'] = 'fresh-private-body';
    file_put_contents("$root/submissions/alpha/bbf_one.json", json_encode($fresh));
    $definition = json_decode(file_get_contents("$root/forms/alpha.json"), true); $definition['name'] = 'Fresh definition';
    file_put_contents("$root/forms/alpha.json", json_encode($definition));
    $r = core_print_audit($s, 'form=alpha&id=bbf_one', 200, 'allowed', 'completed', 1);
    core_check($r['json']['submission']['data']['answer'] === 'fresh-private-body' && $r['json']['form_def']['name'] === 'Fresh definition'
        && str_contains($r['headers'], 'no-store'), 'print reloads current record and definition, response not cacheable');
    core_seed('alpha');
    core_print_audit($s, 'form=beta&id=bbf_one', 403, 'denied', 'failed', 0);
    core_print_audit($s, 'form=alpha&id=bbf_missing', 404, 'allowed', 'failed', 0);
    foreach (['form=alpha&id[]=bbf_one', 'form=alpha&id=../bbf_one', 'form[]=alpha&id=bbf_one', 'form=../alpha&id=bbf_one'] as $query) {
        $r = core_http('viewer.php?action=print&' . $query, ['cookie' => $s['cookie']]);
        core_check(in_array($r['code'], [400, 403], true) && !str_contains($r['body'], '-private-body'), 'print rejects malformed form/id without data');
    }
    foreach (['rotate', 'remove', 'expire', 'revoke', 'scope', 'permissions'] as $change) {
        core_config($baseConfig); $s = core_login('reader'); $config = $baseConfig;
        switch ($change) {
            case 'rotate': $config['access_tokens'][0]['token'] = '5ce432c3d3ebe44ab80437901fadf869de1f14ee8cf691b52fbdf83425780dd2'; break;
            case 'remove': array_shift($config['access_tokens']); break;
            case 'expire': $config['access_tokens'][0]['expires_at'] = '2000-01-01T00:00:00Z'; break;
            case 'revoke': $config['access_tokens'][0]['revoked'] = true; break;
            case 'scope': $config['access_tokens'][0]['forms'] = []; break;
            case 'permissions': $config['access_tokens'][0]['permissions'] = []; break;
        }
        core_config($config);
        $result = core_http('viewer.php?action=detail&form=alpha&id=bbf_one', ['cookie' => $s['cookie']]); core_check($result['code'] === 403, "session re-resolves $change (HTTP {$result['code']})");
    }
    foreach (['auth_session_idle', 'auth_session_absolute'] as $setting) {
        core_config($baseConfig); $s = core_login('reader'); $config = $baseConfig; $config[$setting] = 1; core_config($config); sleep(2);
        core_check(core_http('viewer.php', ['cookie' => $s['cookie']])['code'] === 403, "$setting enforced");
    }
    foreach (['', 'c450a62a708923e103a244016c3da0943cb4903929b15be75fadaacc6df6b5d8'] as $newToken) {
        core_config($baseConfig); $s = core_login('admin'); $config = $baseConfig; $config['api_token'] = $newToken; core_config($config);
        core_check(core_http('editor.php', ['cookie' => $s['cookie']])['code'] === 403, 'legacy session invalidated after removal/rotation');
    }
    core_config($baseConfig); $s = core_login('reader');
    core_check(core_http('viewer.php', ['cookie' => $s['cookie'], 'headers' => ['X-BBF-Token' => 'bad']])['code'] === 403, 'explicit bad credential does not fall back');
    core_check(core_http('viewer.php', ['cookie' => $s['cookie']])['code'] === 403, 'bad credential clears previous management grant');
    foreach (['bad-date', 'invalid-calendar', 'missing-expiry', 'duplicate-id', 'duplicate-token', 'wrong-revoked', 'wrong-forms', 'wrong-permission', 'non-list', 'null-list'] as $case) {
        $config = $baseConfig;
        switch ($case) {
            case 'bad-date': $config['access_tokens'][0]['expires_at'] = 'tomorrow'; break;
            case 'invalid-calendar': $config['access_tokens'][0]['expires_at'] = '2099-02-30T00:00:00Z'; break;
            case 'missing-expiry': unset($config['access_tokens'][0]['expires_at']); break;
            case 'duplicate-id': $config['access_tokens'][1]['id'] = 'reader'; break;
            case 'duplicate-token': $config['access_tokens'][1]['token'] = $config['access_tokens'][0]['token']; break;
            case 'wrong-revoked': $config['access_tokens'][0]['revoked'] = 'false'; break;
            case 'wrong-forms': $config['access_tokens'][0]['forms'] = '*'; break;
            case 'wrong-permission': $config['access_tokens'][0]['permissions'] = ['admin']; break;
            case 'non-list': $config['access_tokens'] = ['x' => $config['access_tokens'][0]]; break; case 'null-list': $config['access_tokens'] = null; break;
        }
        core_config($config);
        core_check(core_http('viewer.php', core_header('reader'))['code'] === 403, "malformed registry closed: $case");
    }
    // Token guessing: ten wrong tokens per address, then 429 for wrong tokens; the right token always works,
    // so nobody can lock the admin out by sending bad tokens from a shared address.
    core_config($baseConfig);
    $signedIn = core_login('reader');
    $guessHeader = static fn(string $t): array => ['headers' => ['X-BBF-Token' => $t]];
    for ($i = 0; $i < 10; $i++) $guess = core_http('submissions.php?form=alpha', $guessHeader('guess-' . $i));
    core_check($guess['code'] === 403, 'wrong tokens below the limit are plain denials');
    // Review 2.1.5: no slot or wait. Wrong tokens get 429 at once, the right token always gets in, even while
    // someone at the same address keeps sending wrong ones (2.1.3–2.1.5 let the guesser take the admin's slot).
    $timed = static function (string $path, array $options = []): array {
        $start = microtime(true); $r = core_http($path, $options); $r['seconds'] = microtime(true) - $start; return $r;
    };
    $start = microtime(true);
    for ($i = 11; $i < 41; $i++) $burst[] = $timed('submissions.php?form=alpha', $guessHeader('guess-' . $i));
    $burstSeconds = microtime(true) - $start;
    core_check(array_unique(array_column($burst, 'code')) === [429], 'every wrong token while blocked is 429');
    core_check($burstSeconds < 6, sprintf('30 blocked attempts are answered without waiting (%.1f s total)', $burstSeconds));
    preg_match('/Retry-After: (\d+)/', $burst[29]['headers'], $retry);
    core_check(isset($retry[1]) && (int)$retry[1] >= 1 && (int)$retry[1] <= 900, 'Retry-After names when wrong tokens are counted again');
    $html = $timed('viewer.php', $guessHeader('guess-html'));
    core_check($html['code'] === 429 && str_contains($html['body'], 'Invalid token.') && !str_contains($html['body'], 'Not checked')
        && str_contains($html['body'], 'name="login_csrf"'), 'a blocked HTML sign-in shows the form saying the token was wrong, not JSON');
    core_check(core_http('viewer.php', ['cookie' => $signedIn['cookie']])['code'] === 200, 'an already signed-in browser is not affected by the block');
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        core_http('submissions.php?form=alpha', $guessHeader('attacker-' . $i)); // the guesser right before the admin
        $right = $timed('viewer.php', core_header('admin'));
        $codes[] = $right['code'];
    }
    core_check(array_unique($codes) === [200], 'the right admin token works on every attempt during an ongoing attack (' . implode(',', $codes) . ')');
    core_check($right['seconds'] < 1.5, sprintf('the right token is not delayed in PHP (%.1f s)', $right['seconds']));
    core_check(core_http('submissions.php?form=alpha', core_header('reader'))['code'] === 200, 'API integrations with a valid token keep working too');
    core_check(core_http('submissions.php?form=alpha', $guessHeader('guess-99'))['code'] === 429, 'a success does not reset the wrong-token limit');
    // Review 2.1.6: an expired or revoked token is answered like a wrong one while blocked (it once was valid).
    $staleConfig = $baseConfig;
    $staleConfig['access_tokens'][] = ['id' => 'expired', 'token' => 'fa267f6a634226df5cecac4f9b55fc5d47ae2fae28da8a573ad76ab949bb33f2', 'forms' => ['alpha'], 'permissions' => ['read'],
        'expires_at' => '2001-01-01T00:00:00Z', 'revoked' => false];
    $staleConfig['access_tokens'][] = ['id' => 'revoked', 'token' => '9806d4b292ce8435a27e53f1957d5d5ec84113aba751cb41dbd2fa05f7781b6c', 'forms' => ['alpha'], 'permissions' => ['read'],
        'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => true];
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($staleConfig, true) . ';'); // keeps the block
    clearstatcache();
    core_check(core_http('submissions.php?form=alpha', $guessHeader('fa267f6a634226df5cecac4f9b55fc5d47ae2fae28da8a573ad76ab949bb33f2'))['code'] === 429
        && core_http('submissions.php?form=alpha', $guessHeader('9806d4b292ce8435a27e53f1957d5d5ec84113aba751cb41dbd2fa05f7781b6c'))['code'] === 429,
        'review 2.1.6: an expired or revoked token gets 429 like any wrong token while blocked, not a 403 that reveals it');
    $weakConfig = $baseConfig; $weakConfig['api_token'] = 'abcdefghijklmnopqrstuvwxyz';
    core_config($weakConfig);
    core_check(core_http('viewer.php', $guessHeader('abcdefghijklmnopqrstuvwxyz'))['code'] !== 200, 'a non-hex api_token is never accepted');
    core_config(['api_token' => 'short'] + $baseConfig);
    core_check(core_http('viewer.php', $guessHeader('short'))['code'] === 403, 'api_token shorter than 32 characters is never accepted');
    core_check(core_http('viewer.php', core_header('reader'))['code'] === 200, 'a short api_token does not disable the access_tokens');
    // N4: one too-short access token is ignored on its own; it does not take api_token and the others down.
    $shortConfig = $baseConfig;
    $shortConfig['access_tokens'][] = ['id' => 'tiny', 'token' => 'fourteen-chars', 'forms' => ['alpha'], 'permissions' => ['read'],
        'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
    core_config($shortConfig);
    core_check(core_http('viewer.php', core_header('admin'))['code'] === 200, 'a short access_tokens entry does not lock out api_token');
    core_check(core_http('viewer.php', core_header('reader'))['code'] === 200, 'a short access_tokens entry does not lock out the other tokens');
    core_check(core_http('viewer.php', ['headers' => ['X-BBF-Token' => 'fourteen-chars']])['code'] === 403, 'the short token itself is never accepted');
    foreach ([16, 32] as $bytes) {
        $generatedAdmin = strtoupper(bin2hex(random_bytes($bytes)));
        core_config(['api_token' => $generatedAdmin] + $baseConfig);
        core_check(core_http('viewer.php', $guessHeader($generatedAdmin))['code'] === 200,
            'random uppercase ' . ($bytes * 2) . '-character hex admin authenticates');
    }
    core_config(['api_token' => 'YOUR_TOKEN_HERE_123'] + $baseConfig);
    $login = core_http('viewer.php');
    core_check($login['code'] === 403 && str_contains($login['body'], 'api_token must contain only hexadecimal'),
        'rejected admin format is explained even when scoped credentials remain usable');
    // Review 2.1.8: sign-in explains rejected formats and malformed registries without exposing secrets.
    foreach (['1qaz2wsx3edc4rfv', 'manchesterunited', 'correct-horse-battery-staple', 'YOUR_TOKEN_HERE_123',
        'pass1word2secret3', 'Bratislava-Petržalka'] as $password) {
        core_config(['api_token' => $password, 'access_tokens' => []] + $baseConfig);
        $login = core_http('viewer.php');
        core_check($login['code'] === 403 && str_contains($login['body'], 'only hexadecimal')
            && str_contains($login['body'], '32') && str_contains($login['body'], 'maintenance.php new-token')
            && !str_contains($login['body'], $password), 'sign-in explains invalid format without revealing the human password');
    }
    core_config(['access_tokens' => [['id' => 'broken']]] + $baseConfig);
    $login = core_http('viewer.php');
    core_check($login['code'] === 403 && str_contains($login['body'], 'malformed or duplicate record')
        && str_contains($login['body'], 'ALL tokens'), 'sign-in explains a registry that disables otherwise valid tokens');
    core_config(['api_token' => '', 'access_tokens' => [$staleConfig['access_tokens'][7], $staleConfig['access_tokens'][8]]] + $baseConfig);
    $login = core_http('viewer.php');
    core_check($login['code'] === 403 && str_contains($login['body'], 'expired or revoked'), 'sign-in explains when no active credential remains');
    core_config(['api_token' => 'nonhex-human-password', 'cookie_path' => 'relative/path'] + $baseConfig);
    $diagnostics = core_http('check.php', core_header('admin'));
    // The rejected admin cannot authorize diagnostics, even though its string is nonempty.
    core_check($diagnostics['code'] === 403, 'nonempty invalid api_token does not authorize diagnostics');
    $proxyConfig = ['cookie_path' => 'relative/path', 'trusted_proxies' => ['192.0.2.0/24']] + $baseConfig;
    core_config($proxyConfig);
    $plain = core_http('check.php', core_header('admin'));
    $spoofed = core_http('check.php', ['headers' => ['X-BBF-Token' => $baseConfig['api_token'],
        'X-Forwarded-For' => '198.51.100.44', 'CF-Connecting-IP' => '198.51.100.45']]);
    core_check($plain['code'] === 200 && $spoofed['code'] === 200
        && str_contains($spoofed['body'], 'cookie_path must be an absolute URL path')
        && str_contains($plain['body'], 'Forwarding headers are ignored')
        && str_contains($spoofed['body'], 'Forwarding headers are ignored')
        && !str_contains($spoofed['body'], '198.51.100.44') && !str_contains($spoofed['body'], '198.51.100.45'),
        'diagnostics report invalid cookie_path and never infer a proxy from untrusted forwarding headers');
    core_config($baseConfig);
    // Install a request-local deterministic delivery effect through the copied
    // bbf_functions.php test hook. Native outbound functions remain disabled.
    file_put_contents("$root/tests/retry-delivery-fixture.php", <<<'PHP'
<?php
if (!defined('BBF_LOADED') || realpath(getenv('BBF_TEST_FIXTURE_ROOT') ?: '') !== realpath(dirname(__DIR__))) {
    http_response_code(403); exit('Owned fixture only');
}
$GLOBALS['_bbf_delivery_effect'] = static function(array $job, array $payload): array {
    $root = dirname(__DIR__);
    $mode = (string)($payload['fixture_mode'] ?? 'invalid');
    file_put_contents("$root/data/retry-delivery-effects.jsonl",
        json_encode(['job' => $job['key'] ?? '', 'mode' => $mode], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    if ($mode === 'success') return ['ok' => true, 'state' => 'succeeded', 'stage' => 'action'];
    if ($mode === 'failure') return ['ok' => false, 'state' => 'failed', 'stage' => 'action',
        'retryable' => true, 'safe_message' => 'Fixture action remains retryable.'];
    return ['ok' => false, 'state' => 'failed', 'stage' => 'action', 'retryable' => false];
};
PHP
    );
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; require __DIR__ . "/tests/retry-delivery-fixture.php"; return ' . var_export($baseConfig, true) . ';');
    $all = core_login('all');
    $deliveryDir = "$root/submissions/.delivery/alpha";
    if (!is_dir($deliveryDir)) mkdir($deliveryDir, 0700, true);
    $deliveryPath = "$deliveryDir/bbf_one.json";
    $successPayload = ['fixture_mode' => 'success', 'secret' => 'private-effect-success'];
    $failurePayload = ['fixture_mode' => 'failure', 'secret' => 'private-effect-failure'];
    $deliveryLedger = ['version' => 1, 'submission_key' => 'alpha:bbf_one', 'created_at' => 1, 'updated_at' => 1,
        'jobs' => [
            'succeeded' => ['key' => 'succeeded', 'type' => 'action', 'state' => 'succeeded', 'attempts' => 1,
                'max_attempts' => 3, 'next_retry' => null, 'idempotent' => true, 'target' => 'private-target',
                'idempotency_key' => 'private-key', 'payload' => ['secret' => 'private-payload'],
                'last_result' => ['stage' => 'action', 'code' => 0, 'retryable' => false, 'message' => 'private-result', 'at' => 1]],
            'capped' => ['key' => 'capped', 'type' => 'webhook', 'state' => 'failed', 'attempts' => 3,
                'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => true,
                'last_result' => ['stage' => 'http', 'code' => 503, 'retryable' => true, 'message' => 'private-result', 'at' => 1]],
            'retryable' => ['key' => 'retryable', 'type' => 'webhook', 'state' => 'failed', 'attempts' => 1,
                'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => true,
                'last_result' => ['stage' => 'http', 'code' => 503, 'retryable' => true, 'message' => 'private-result', 'at' => 1]],
            'effect-success' => ['key' => 'effect-success', 'type' => 'action', 'state' => 'failed', 'attempts' => 1,
                'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => true, 'target' => 'private-effect-target',
                'idempotency_key' => 'private-effect-key', 'payload' => $successPayload,
                'payload_hash' => hash('sha256', json_encode($successPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'last_result' => ['stage' => 'action', 'code' => 0, 'retryable' => true, 'message' => 'private-effect-result', 'at' => 1]],
            'effect-failure' => ['key' => 'effect-failure', 'type' => 'action', 'state' => 'failed', 'attempts' => 1,
                'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => true, 'target' => 'private-effect-target',
                'idempotency_key' => 'private-effect-key', 'payload' => $failurePayload,
                'payload_hash' => hash('sha256', json_encode($failurePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'last_result' => ['stage' => 'action', 'code' => 0, 'retryable' => true, 'message' => 'private-effect-result', 'at' => 1]],
            'stale-confirm' => ['key' => 'stale-confirm', 'type' => 'action', 'state' => 'running', 'attempts' => 1,
                'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => false, 'target' => 'private-stale-target',
                'idempotency_key' => 'private-stale-key', 'payload' => $successPayload,
                'payload_hash' => hash('sha256', json_encode($successPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'lease_token' => 'private-stale-lease', 'lease_started' => 1, 'last_result' => null],
        ], 'events' => [], 'semantic_events' => []];
    file_put_contents($deliveryPath, json_encode($deliveryLedger, JSON_THROW_ON_ERROR));
    $detail = core_http('viewer.php?action=detail&form=alpha&id=bbf_one', core_header('reader'));
    $staleDetail = array_values(array_filter($detail['json']['delivery']['jobs'] ?? [],
        fn($job) => ($job['key'] ?? '') === 'stale-confirm'));
    core_check($detail['code'] === 200 && $detail['json']['delivery']['state'] === 'attention_required'
        && count($detail['json']['delivery']['jobs']) === 6
        && count(array_filter($detail['json']['delivery']['jobs'], fn($job) => $job['can_retry'])) === 0
        && ($staleDetail[0]['state'] ?? '') === 'ambiguous'
        && ($staleDetail[0]['requires_confirmation'] ?? false) === true,
        'viewer detail expires stale running lease to attention/confirmation without scoped retry controls');
    core_check(!str_contains($detail['body'], 'private-target') && !str_contains($detail['body'], 'private-key')
        && !str_contains($detail['body'], 'private-payload') && !str_contains($detail['body'], 'private-result')
        && !str_contains($detail['body'], 'private-effect'),
        'viewer delivery projection redacts targets, keys, payloads, and untrusted result text');
    $apiDetail = core_http('submissions.php?form=alpha&id=bbf_one', core_header('reader'));
    core_check($apiDetail['code'] === 200 && isset($apiDetail['json']['delivery'])
        && !isset($apiDetail['json']['submissions']) && !str_contains($apiDetail['body'], 'private-payload'),
        'stateless detail appends safe delivery projection without changing list responses');
    $adminViewer = core_login('admin');
    foreach ([
        ['scoped token', core_mutate('retry_delivery', $all, ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'retryable']), 403],
        ['cross-form', core_mutate('retry_delivery', $all, ['form' => 'beta', 'id' => 'bbf_one', 'job' => 'retryable']), 403],
        ['missing CSRF', core_mutate('retry_delivery', $adminViewer, ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'retryable'], 'POST', false), 403],
        ['method guard', core_mutate('retry_delivery', $adminViewer, ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'retryable'], 'GET'), 405],
    ] as [$label, $result, $expected]) core_check($result['code'] === $expected, "retry delivery $label denied");
    $effects = static function() use ($root): array {
        return is_file("$root/data/retry-delivery-effects.jsonl")
            ? array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file("$root/data/retry-delivery-effects.jsonl", FILE_IGNORE_NEW_LINES)) : [];
    };
    $beforeAudit = count(file("$root/logs/access-audit.php"));
    $successfulRetry = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'effect-success']);
    $successfulLedger = json_decode(file_get_contents($deliveryPath), true, 512, JSON_THROW_ON_ERROR);
    $successfulProjection = array_values(array_filter($successfulRetry['json']['delivery']['jobs'] ?? [],
        fn($job) => ($job['key'] ?? '') === 'effect-success'));
    $successfulAudit = array_map(fn($line) => json_decode($line, true), array_slice(file("$root/logs/access-audit.php"), $beforeAudit));
    core_check($successfulRetry['code'] === 200 && ($successfulRetry['json']['ok'] ?? false) === true
        && ($successfulLedger['jobs']['effect-success']['state'] ?? '') === 'succeeded'
        && ($successfulProjection[0]['state'] ?? '') === 'succeeded' && ($successfulProjection[0]['can_retry'] ?? true) === false,
        'eligible admin retry returns success only after durable succeeded transition');
    core_check($effects() === [['job' => 'effect-success', 'mode' => 'success']],
        'successful admin retry executes fixture effect exactly once');
    core_check(count($successfulAudit) === 2 && array_column($successfulAudit, 'result') === ['attempted', 'completed']
        && array_column($successfulAudit, 'result_count') === [0, 1],
        'successful durable retry alone records completed audit');
    core_check(!str_contains($successfulRetry['body'], 'private-effect'), 'successful retry response remains redacted');

    $successfulSnapshot = file_get_contents($deliveryPath);
    $beforeAudit = count(file("$root/logs/access-audit.php"));
    $repeatSucceeded = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'effect-success']);
    $repeatAudit = array_map(fn($line) => json_decode($line, true), array_slice(file("$root/logs/access-audit.php"), $beforeAudit));
    core_check($repeatSucceeded['code'] === 409 && ($repeatSucceeded['json']['ok'] ?? true) === false
        && file_get_contents($deliveryPath) === $successfulSnapshot && count($effects()) === 1,
        'durably succeeded retry never reruns the fixture effect');
    core_check(count($repeatAudit) === 2 && array_column($repeatAudit, 'result') === ['attempted', 'failed']
        && array_column($repeatAudit, 'result_count') === [0, 0],
        'rejected rerun records failed audit');

    $beforeAudit = count(file("$root/logs/access-audit.php"));
    $failedRetry = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'effect-failure']);
    $failedLedger = json_decode(file_get_contents($deliveryPath), true, 512, JSON_THROW_ON_ERROR);
    $failedProjection = array_values(array_filter($failedRetry['json']['delivery']['jobs'] ?? [],
        fn($job) => ($job['key'] ?? '') === 'effect-failure'));
    $failedAudit = array_map(fn($line) => json_decode($line, true), array_slice(file("$root/logs/access-audit.php"), $beforeAudit));
    core_check($failedRetry['code'] === 503 && ($failedRetry['json']['ok'] ?? true) === false
        && ($failedLedger['jobs']['effect-failure']['state'] ?? '') === 'failed'
        && ($failedLedger['jobs']['effect-failure']['attempts'] ?? 0) === 2
        && ($failedProjection[0]['state'] ?? '') === 'failed' && ($failedProjection[0]['can_retry'] ?? false) === true,
        'failed eligible admin retry returns non-success with durable retryable status');
    core_check($effects() === [['job' => 'effect-success', 'mode' => 'success'], ['job' => 'effect-failure', 'mode' => 'failure']],
        'failed admin retry executes only its deterministic fixture effect once');
    core_check(count($failedAudit) === 2 && array_column($failedAudit, 'result') === ['attempted', 'failed']
        && array_column($failedAudit, 'result_count') === [0, 0],
        'failed delivery effect records failed audit, never completed');
    core_check(!str_contains($failedRetry['body'], 'private-effect'), 'failed retry response remains redacted');

    $succeededBefore = file_get_contents($deliveryPath);
    $beforeAudit = count(file("$root/logs/access-audit.php"));
    $succeededRetry = core_mutate('retry_delivery', $adminViewer, ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'succeeded']);
    $retryAudit = array_map(fn($line) => json_decode($line, true), array_slice(file("$root/logs/access-audit.php"), $beforeAudit));
    core_check($succeededRetry['code'] === 409 && file_get_contents($deliveryPath) === $succeededBefore,
        'succeeded delivery is never changed or rerun');
    core_check(count($retryAudit) === 2 && array_column($retryAudit, 'action') === ['viewer_retry_delivery', 'viewer_retry_delivery']
        && array_column($retryAudit, 'result') === ['attempted', 'failed']
        && array_column($retryAudit, 'submission_ids') === [['bbf_one'], ['bbf_one']],
        'retry denial records exact audited operation and submission');
    $cappedRetry = core_mutate('retry_delivery', $adminViewer, ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'capped']);
    $cappedLedger = json_decode(file_get_contents($deliveryPath), true, 512, JSON_THROW_ON_ERROR);
    core_check($cappedRetry['code'] === 409 && $cappedLedger['jobs']['capped']['attempts'] === 3
        && $cappedLedger['jobs']['capped']['state'] === 'exhausted', 'attempt cap prevents delivery rerun');

    $adminDetail = core_http('viewer.php?action=detail&form=alpha&id=bbf_one', ['cookie' => $adminViewer['cookie']]);
    $adminStale = array_values(array_filter($adminDetail['json']['delivery']['jobs'] ?? [],
        fn($job) => ($job['key'] ?? '') === 'stale-confirm'));
    core_check($adminDetail['code'] === 200 && ($adminDetail['json']['delivery']['state'] ?? '') === 'attention_required'
        && ($adminStale[0]['state'] ?? '') === 'ambiguous' && ($adminStale[0]['can_retry'] ?? false) === true
        && ($adminStale[0]['requires_confirmation'] ?? false) === true,
        'admin detail exposes expired non-idempotent lease as retryable only with confirmation');
    $staleUnconfirmed = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'stale-confirm']);
    core_check($staleUnconfirmed['code'] === 409 && count($effects()) === 2,
        'admin cannot retry expired non-idempotent lease without confirmation');
    $staleConfirmed = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'stale-confirm', 'confirm_ambiguous' => true]);
    core_check($staleConfirmed['code'] === 200 && count($effects()) === 3
        && ($staleConfirmed['json']['delivery']['state'] ?? '') === 'attention_required',
        'confirmed admin retry executes expired non-idempotent job without leaving it processing');

    $directLedger = json_decode(file_get_contents($deliveryPath), true, 512, JSON_THROW_ON_ERROR);
    $directLedger['jobs']['stale-direct'] = ['key' => 'stale-direct', 'type' => 'action', 'state' => 'running',
        'attempts' => 1, 'max_attempts' => 3, 'next_retry' => 1, 'idempotent' => true,
        'target' => 'private-direct-target', 'idempotency_key' => 'private-direct-key', 'payload' => $successPayload,
        'payload_hash' => hash('sha256', json_encode($successPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'lease_token' => 'private-direct-lease', 'lease_started' => 1, 'last_result' => null];
    file_put_contents($deliveryPath, json_encode($directLedger, JSON_THROW_ON_ERROR));
    $directRetry = core_mutate('retry_delivery', $adminViewer,
        ['form' => 'alpha', 'id' => 'bbf_one', 'job' => 'stale-direct']);
    $directJobs = array_values(array_filter($directRetry['json']['delivery']['jobs'] ?? [],
        fn($job) => ($job['key'] ?? '') === 'stale-direct'));
    core_check($directRetry['code'] === 200 && count($effects()) === 4
        && ($directJobs[0]['state'] ?? '') === 'succeeded' && ($directJobs[0]['can_retry'] ?? true) === false,
        'retry endpoint itself expires and safely retries stale idempotent lease without indefinite processing');
    $audit = file_get_contents("$root/logs/access-audit.php");
    $rows = array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_slice(explode("\n", trim($audit)), 1));
    core_check(!str_contains($audit, 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f') && !str_contains($audit, 'c9ffddaa918a1b920038790fc128858957809e3c80165b8a8cbbe77c556883f2') && !str_contains($audit, '@') && !str_contains($audit, '-private-body') && !str_contains($audit, 'token='), 'audit excludes credentials, email, bodies and URLs');
    core_check(count(array_filter($rows, fn($r) => $r['action'] === 'viewer_bulk_delete' && $r['result'] === 'completed' && $r['result_count'] === 2)) === 1, 'audit counts actual bulk deletion');
    core_check(count(array_filter($rows, fn($r) => $r['decision'] === 'denied' && $r['result'] === 'attempted')) > 5, 'audit records denied attempts');
    core_check(count(array_filter($rows, fn($r) => $r['action'] === 'viewer_forward' && $r['decision'] === 'allowed' && $r['result'] === 'failed')) === 1, 'audit distinguishes authorized failed forward');
    core_check(core_http('logs/access-audit.php')['code'] === 404, 'audit direct HTTP protected');
    foreach (glob("$root/sessions/*") as $file) core_check(!str_contains(file_get_contents($file), 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f') && !str_contains(file_get_contents($file), 'c9ffddaa918a1b920038790fc128858957809e3c80165b8a8cbbe77c556883f2'), 'session files contain no raw credentials');
    rename("$root/logs/access-audit.php", "$root/logs/saved-audit.php"); mkdir("$root/logs/access-audit.php");
    foreach (['export', 'print', 'delete', 'bulk_delete', 'forward'] as $action) {
        $r = in_array($action, ['export', 'print'], true) ? core_http("viewer.php?action=$action&form=alpha&id=bbf_one", ['cookie' => $all['cookie']])
            : core_mutate($action, $all, ['form' => 'alpha', 'id' => 'bbf_one', 'ids' => ['bbf_one'], 'to' => 'never@example.invalid']);
        core_check($r['code'] === 503 && !str_contains($r['body'], 'alpha-private-body'), "audit IO failure closes $action");
    }
    core_check(core_http('submissions.php?form=alpha&format=csv', core_header('exporter'))['code'] === 503, 'audit IO closes stateless CSV');
    core_check(is_file("$root/submissions/alpha/bbf_one.json"), 'audit IO failure leaves response untouched');
    foreach ([['headers' => ['X-Test' => "x\r\nInjected: yes"]], ['cookie' => "x\r\nInjected: yes"], ['method' => "GET\r\nPOST"]] as $options) {
        $rejected = false; try { core_http('viewer.php', $options); } catch (RuntimeException $e) { $rejected = true; }
        core_check($rejected, 'helper rejects CRLF injection');
    }
    print "Core access baseline: $checks passed. No successful mail delivery attempted.\n";
    $baselineChecks = $checks;
    rmdir("$root/logs/access-audit.php"); rename("$root/logs/saved-audit.php", "$root/logs/access-audit.php");
    // Detect the actual fixture filesystem, not the OS name or a mocked resolver.
    file_put_contents("$root/forms/CaseProbe.json", '{}');
    $caseInsensitive = is_file("$root/forms/caseprobe.json");
    core_check(in_array('CaseProbe.json', scandir("$root/forms"), true), 'case capability probe preserves actual directory spelling');
    unlink("$root/forms/CaseProbe.json");
    print 'Filesystem case-insensitive lookup: ' . ($caseInsensitive ? 'yes (Windows alias regression exercised)' : 'no (case-collision fixtures enabled)') . "\n";
    $baseConfig['access_tokens'][] = ['id' => 'mixed', 'token' => 'b5a921301329fb1ef83d1585f72ccd7e38f04256a56c5d0cffdaed857d714e64',
        'forms' => ['ALPHA', 'UpperID', 'OrphanUP', 'ORPHAN', 'SplitID', 'CsvUP', 'CSVLOW', 'DbUP', 'dbup'],
        'permissions' => ['read', 'export', 'delete'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
    core_config($baseConfig);
    $mixed = core_login('mixed');
    $probePaths = ['viewer.php?action=submissions', 'viewer.php?action=detail&id=bbf_one',
        'viewer.php?action=stats', 'viewer.php?action=export', 'submissions.php?',
        'submissions.php?id=bbf_one', 'submissions.php?format=csv'];
    function core_case_reads(string $form, int $expected, string $label): void {
        global $probePaths;
        foreach ($probePaths as $path) {
            $r = core_http($path . '&form=' . $form, core_header('mixed'));
            core_check($r['code'] === $expected && ($expected === 200 || !str_contains($r['body'], '-private-body')),
                "$label $path HTTP $expected (got {$r['code']})");
        }
    }
    core_case_reads('alpha', 403, 'scope is never silently lowercased');
    core_case_reads('ALPHA', 403, 'case alias denied before read/count/export');
    core_case_reads('ORPHAN', 403, 'orphan storage case alias denied');
    foreach (['delete', 'bulk_delete'] as $action) {
        core_check(core_mutate($action, $mixed, ['form' => 'ALPHA', 'id' => 'bbf_one', 'ids' => ['bbf_one']])['code'] === 403, "case alias CSRF-valid $action denied");
        core_check(is_file("$root/submissions/alpha/bbf_one.json"), "case alias $action leaves alpha intact");
    }
    foreach (['UpperID', 'OrphanUP'] as $form) {
        if ($form === 'UpperID') file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => 'Historical uppercase', 'fields' => []]));
        core_seed($form);
        core_case_reads($form, 200, 'exact historical uppercase identity supported');
        core_case_reads(strtolower($form), 403, 'uppercase scope does not expand');
        core_seed($form, 'bbf_two');
        core_check(core_mutate('bulk_delete', $mixed, ['form' => $form, 'ids' => ['bbf_two']])['json']['deleted'] === 1, "$form exact uppercase bulk delete");
        core_check(core_mutate('delete', $mixed, ['form' => $form, 'id' => 'bbf_one'])['code'] === 200 && !is_file("$root/submissions/$form/bbf_one.json"), "$form exact uppercase delete");
    }
    // Cross-location collision is possible even on Windows (definition and data disagree).
    file_put_contents("$root/forms/SplitID.json", json_encode(['id' => 'SplitID', 'name' => 'collision-private-name']));
    core_seed('splitid');
    core_case_reads('SplitID', 403, 'definition/data case collision denied');
    foreach (['delete', 'bulk_delete'] as $action)
        core_check(core_mutate($action, $mixed, ['form' => 'SplitID', 'id' => 'bbf_one', 'ids' => ['bbf_one']])['code'] === 403, "split identity $action denied");
    foreach (['viewer.php', 'viewer.php?action=list_forms', 'viewer.php?action=dashboard'] as $path) {
        $r = core_http($path, core_header('mixed'));
        core_check($r['code'] === 200 && !str_contains($r['body'], 'collision-private-name') && !str_contains($r['body'], 'splitid-private-body'), "collision excluded from enumeration $path");
    }
    if (!$caseInsensitive) {
        file_put_contents("$root/forms/ALPHA.json", json_encode(['id' => 'ALPHA'])); core_seed('ALPHA');
        core_case_reads('ALPHA', 403, 'coexisting filesystem case collision denied');
        core_check(core_http('viewer.php?action=detail&form=alpha&id=bbf_one', core_header('all'))['code'] === 403, 'both collision spellings denied');
        core_check(core_mutate('delete', $mixed, ['form' => 'ALPHA', 'id' => 'bbf_one'])['code'] === 403, 'coexisting case collision delete denied');
        unlink("$root/forms/ALPHA.json"); bbf_test_remove_dir("$root/submissions/ALPHA");
    }
    $admin = core_login('admin', 'editor.php'); foreach (['load', 'preview_page'] as $action)
        core_check(core_http("editor.php?action=$action&form=ALPHA", core_header('admin'))['code'] === 403, "editor $action refuses case alias");
    foreach (['save', 'create', 'delete'] as $action)
        core_check(core_mutate($action . '&form=ALPHA', $admin, ['id' => 'ALPHA'], 'POST', true, 'editor.php')['code'] === 403, "editor $action refuses case alias");
    core_check(is_file("$root/forms/alpha.json"), 'editor alias mutations leave definition intact');
    // CSV has the same filename alias hazard, including definition-less historical IDs.
    foreach (['csvlow', 'CsvUP'] as $form) {
        $fp = fopen("$root/submissions/$form.csv", 'w');
        fputcsv($fp, ['_id', '_submitted', '_ip', '_user_agent', 'answer'], ',', '"', '');
        fputcsv($fp, ['bbf_one', '2026-09-08T10:00:00Z', '', '', "$form-private-body"], ',', '"', ''); fclose($fp);
    }
    $config = $baseConfig; $config['storage'] = 'csv'; core_config($config);
    core_case_reads('CSVLOW', 403, 'CSV orphan alias denied');
    core_case_reads('CsvUP', 200, 'CSV uppercase orphan retained');
    // Real SQLite with hostile NOCASE collation exercises shared count/list/detail/delete predicates.
    core_check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'SQLite driver required for real collation regression');
    $config['storage'] = 'sqlite'; $config['sqlite']['path'] = "$root/submissions/case.sqlite";
    $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE bbf_submissions (id TEXT, form_id TEXT COLLATE NOCASE, data TEXT, meta TEXT, created_at TEXT)');
    foreach (['DbUP', 'dbup'] as $form) foreach (['bbf_one', 'bbf_two'] as $id)
        $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([$id, $form,
            json_encode(['answer' => "$form-private-body"]), json_encode(['submitted' => '2026-09-08T10:00:00Z']), '2026-09-08 10:00:00']);
    core_config($config);
    core_check((int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE form_id = 'DbUP'")->fetchColumn() === 4, 'real NOCASE column reproduces unsafe ordinary equality');
    foreach ($probePaths as $path) {
        $r = core_http($path . '&form=DbUP', core_header('mixed'));
        core_check($r['code'] === 200 && !str_contains($r['body'], 'dbup-private-body'), "binary database boundary $path");
        if (isset($r['json']['total'])) core_check($r['json']['total'] === 2, "binary count $path");
    }
    core_check(core_mutate('delete', $mixed, ['form' => 'DbUP', 'id' => 'bbf_one'])['code'] === 200, 'binary database single deletion');
    core_check(core_mutate('bulk_delete', $mixed, ['form' => 'DbUP', 'ids' => ['bbf_two']])['json']['deleted'] === 1, 'binary database bulk deletion');
    core_check((int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE form_id COLLATE BINARY = 'dbup'")->fetchColumn() === 2, 'binary database deletes preserve differently cased records');
    core_check(core_http('submissions.php?form=DbUP&id=bbf_one', core_header('mixed'))['code'] === 404, 'deleted exact database identity absent despite case-insensitive sibling');
    $pdo = null; core_config($baseConfig);
    // Unknown action credentials must never be persisted, even for anonymous denials.
    foreach (['viewer.php', 'editor.php'] as $page) foreach (['admin', 'reader', 'anonymous'] as $who) {
        $secret = $who === 'admin' ? $baseConfig['api_token'] : 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f';
        foreach ([$secret, 'prefix-' . $secret . '-suffix'] as $action) {
            $r = core_http($page . '?action=' . rawurlencode($action), $who === 'anonymous' ? [] : core_header($who));
            $expected = $who === 'anonymous' || ($page === 'editor.php' && $who === 'reader') ? 403 : 400;
            core_check($r['code'] === $expected, "$page $who credential action rejected");
        }
    }
    $audit = file_get_contents("$root/logs/access-audit.php");
    $rows = array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_slice(explode("\n", trim($audit)), 1));
    foreach (['viewer_invalid', 'editor_invalid'] as $event) {
        core_check(count(array_filter($rows, fn($r) => $r['action'] === $event && $r['result'] === 'attempted')) === 6, "$event fixed allowlisted audit event");
        core_check(count(array_filter($rows, fn($r) => $r['action'] === $event && $r['principal_id'] === 'anonymous' && $r['decision'] === 'denied')) === 4, "$event anonymous denied audit");
    }
    define('BBF_LOADED', true); require_once dirname(__DIR__) . '/bbf_auth.php';
    bbf_audit_write($baseConfig, null, 'prefix-bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f-suffix', '', [], 'denied', 'failed', 0);
    $audit = file_get_contents("$root/logs/access-audit.php");
    core_check(str_contains($audit, 'prefix-[redacted]-suffix'), 'shared audit action redaction defense in depth');
    core_check(!str_contains($audit, 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f') && !str_contains($audit, 'c9ffddaa918a1b920038790fc128858957809e3c80165b8a8cbbe77c556883f2'), 'all unknown action paths exclude raw credentials from audit');
    print 'Core regression additions: ' . ($checks - $baselineChecks) . " passed. Total: $checks. MySQL runtime not exercised; shared MySQL predicate uses binary casts.\n";
    // Fixture-only replacement of disabled native mail(), NOT sendEmail/rendering/auth/audit.
    // The real viewer and bbf_functions.php run unchanged over helper-owned loopback HTTP.
    // PHP 8 permits redefining a disabled native function; no disabled_functions policy is relaxed.
    file_put_contents("$root/tests/forward-mail-fixture.php", <<<'PHP'
<?php
if (!defined('BBF_LOADED') || realpath(getenv('BBF_TEST_FIXTURE_ROOT') ?: '') !== realpath(dirname(__DIR__))) {
    http_response_code(403); exit('Owned fixture only');
}
if (function_exists('mail') || !in_array('mail', explode(',', ini_get('disable_functions')), true)) {
    throw new RuntimeException('Refusing to replace enabled native mail');
}
if (!function_exists('mail')) {
    function mail(string $to, string $subject, string $message, array|string $additional_headers = [], string $additional_params = ''): bool {
        $root = dirname(__DIR__);
        $mode = trim(file_get_contents("$root/data/forward-mail-mode"));
        $audit = file("$root/logs/access-audit.php", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $capture = compact('to', 'subject', 'message', 'additional_headers', 'additional_params', 'mode');
        $capture['audit_at_delivery'] = json_decode(end($audit), true, 512, JSON_THROW_ON_ERROR);
        $capture['disabled_functions'] = explode(',', ini_get('disable_functions'));
        $capture['userland_mail'] = (new ReflectionFunction('mail'))->isUserDefined();
        if (file_put_contents("$root/data/forward-mail.jsonl", json_encode($capture, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND) === false) {
            throw new RuntimeException('Fixture capture failed');
        }
        if ($mode === 'throw') throw new RuntimeException('Fixture delivery exception, never real mail');
        if (!in_array($mode, ['success', 'false'], true)) throw new RuntimeException('Invalid fixture delivery mode');
        return $mode === 'success';
    }
}
PHP
    );
    $mailConfig = $baseConfig;
    $mailConfig['mail'] = ['method' => 'mail', 'from_name' => 'Fixture sender', 'from_email' => 'sender@example.invalid'];
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; require __DIR__ . "/tests/forward-mail-fixture.php"; return ' . var_export($mailConfig, true) . ';');
    file_put_contents("$root/data/forward-mail-mode", 'success');
    $forwarder = core_login('exporter'); $deniedForwarder = core_login('reader');
    $forwardSub = ['id' => 'bbf_forward', 'form' => 'alpha', 'data' => [
        'answer' => ['<img src=x onerror=alert(1)>', '"quoted" & text'],
        'scalar' => '<b>literal</b>', 'email' => 'private-person@example.invalid',
        'items' => [['sku' => '<row-one>'], ['sku' => 'row-two']],
    ], 'meta' => ['submitted' => '2026-09-08T10:00:00Z']];
    file_put_contents("$root/submissions/alpha/bbf_forward.json", json_encode($forwardSub));
    $forwardDef = json_decode(file_get_contents("$root/forms/alpha.json"), true);
    $forwardDef['name'] = 'Forward <title> & fixture';
    $forwardDef['fields'][0]['label'] = 'Answer <label> & fixture';
    file_put_contents("$root/forms/alpha.json", json_encode($forwardDef));
    $forwardBody = ['form' => 'alpha', 'id' => 'bbf_forward', 'to' => 'first@example.invalid, second@example.invalid',
        'note' => "Private forward <note> & text\nSecond line"];
    $forwardHashes = [hash_file('sha256', "$root/submissions/alpha/bbf_forward.json"), hash_file('sha256', "$root/forms/alpha.json")];
    function core_forward_captures(): array {
        global $root;
        return is_file("$root/data/forward-mail.jsonl") ? array_map(fn($s) => json_decode($s, true, 512, JSON_THROW_ON_ERROR), file("$root/data/forward-mail.jsonl", FILE_IGNORE_NEW_LINES)) : [];
    }
    // Valid recipients: denial must precede the delivery boundary, not merely fail validation.
    foreach ([['read-only', $deniedForwarder, $forwardBody, true], ['missing CSRF', $forwarder, $forwardBody, false],
        ['cross-form', $forwarder, array_replace($forwardBody, ['form' => 'beta']), true]] as [$label, $session, $body, $csrf]) {
        $before = count(file("$root/logs/access-audit.php"));
        $r = core_mutate('forward', $session, $body, 'POST', $csrf);
        $auditPair = array_map(fn($s) => json_decode($s, true), array_slice(file("$root/logs/access-audit.php"), $before));
        core_check($r['code'] === 403 && core_forward_captures() === [], "forward $label denied before fixture delivery");
        core_check(count($auditPair) === 2 && $auditPair[0]['action'] === 'viewer_forward' && $auditPair[1]['action'] === 'viewer_forward'
            && array_column($auditPair, 'decision') === ['denied', 'denied'] && array_column($auditPair, 'result') === ['attempted', 'failed']
            && array_column($auditPair, 'result_count') === [0, 0], "forward $label exact denied audit pair");
    }
    $forwardFailures = [];
    foreach (['success', 'throw', 'false', 'success'] as $index => $mode) {
        file_put_contents("$root/data/forward-mail-mode", $mode);
        $before = count(file("$root/logs/access-audit.php"));
        $r = core_mutate('forward', $forwarder, $forwardBody);
        $captures = core_forward_captures();
        core_check(count($captures) === $index + 1, "forward $mode calls fixture mail exactly once");
        $captured = $captures[$index];
        core_check($captured['userland_mail'] && array_diff(['mail', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen',
            'stream_socket_client', 'socket_connect', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'], $captured['disabled_functions']) === [],
            "forward $mode captures only through userland fixture; all native outbound policies retained");
        core_check($captured['to'] === $forwardBody['to'] && $captured['additional_params'] === '-fsender@example.invalid'
            && str_contains($captured['additional_headers'], "From: Fixture sender <sender@example.invalid>\r\n")
            && str_contains($captured['additional_headers'], "Reply-To: sender@example.invalid\r\n")
            && str_contains($captured['additional_headers'], "Content-Type: text/html; charset=UTF-8\r\n"), "forward $mode real sendEmail recipient/header construction");
        core_check($captured['subject'] === '=?UTF-8?B?' . base64_encode($forwardDef['name'] . ' — bbf_forward') . '?=', "forward $mode actual encoded subject");
        // Bodies are quoted-printable (RFC 5322 998-char line limit); check the decoded HTML.
        core_check(str_contains($captured['additional_headers'], "Content-Transfer-Encoding: quoted-printable\r\n")
            && max(array_map('strlen', explode("\r\n", $captured['message']))) <= 76, "forward $mode body is quoted-printable with short lines");
        $html = quoted_printable_decode($captured['message']);
        core_check(str_starts_with($html, '<!DOCTYPE html><html><body') && str_ends_with($html, '</table></body></html>')
            && str_contains($html, 'Forward &lt;title&gt; &amp; fixture') && str_contains($html, 'Answer &lt;label&gt; &amp; fixture')
            && str_contains($html, 'bbf_forward') && str_contains($html, '2026-09-08T10:00:00Z')
            && str_contains($html, 'Private forward &lt;note&gt; &amp; text<br />')
            && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;, &quot;quoted&quot; &amp; text')
            && str_contains($html, '&quot;sku&quot;: &quot;&lt;row-one&gt;&quot;') && str_contains($html, 'white-space:pre-wrap')
            && !str_contains($html, '[object Object]') && !preg_match('/>Array(?:<|\s)/', $html)
            && str_contains($html, '&lt;b&gt;literal&lt;/b&gt;') && str_contains($html, 'private-person@example.invalid')
            && !preg_match('/<(?:img|b|note|label|title)(?:\s|>)/i', $html)
            && !str_contains($html, 'definition-secret') && !str_contains($html, 'delivery-secret'), "forward $mode captures actual complete rendered message with escaped arrays/scalars/labels/note");
        $auditPair = array_map(fn($s) => json_decode($s, true), array_slice(file("$root/logs/access-audit.php"), $before));
        core_check(count($auditPair) === 2 && $captured['audit_at_delivery'] === $auditPair[0]
            && $auditPair[0]['result'] === 'attempted' && $auditPair[0]['result_count'] === 0, "forward $mode audit attempted before delivery, no premature completion");
        $expectedCode = $mode === 'success' ? 200 : 502;
        $expectedResult = $mode === 'success' ? 'completed' : 'failed';
        $expectedCount = $mode === 'success' ? 1 : 0;
        $correct = $r['code'] === $expectedCode && ($mode === 'success' ? ($r['json']['ok'] ?? false) === true : !isset($r['json']['ok']))
            && array_column($auditPair, 'principal_id') === ['exporter', 'exporter']
            && array_column($auditPair, 'action') === ['viewer_forward', 'viewer_forward']
            && array_column($auditPair, 'form') === ['alpha', 'alpha']
            && array_column($auditPair, 'submission_ids') === [['bbf_forward'], ['bbf_forward']]
            && array_column($auditPair, 'decision') === ['allowed', 'allowed']
            && array_column($auditPair, 'result') === ['attempted', $expectedResult]
            && array_column($auditPair, 'result_count') === [0, $expectedCount];
        $evidence = "forward $mode expected HTTP $expectedCode/$expectedResult/$expectedCount, got HTTP {$r['code']}/{$auditPair[1]['result']}/{$auditPair[1]['result_count']}";
        if ($correct) core_check(true, $evidence);
        else { $forwardFailures[] = $evidence; print "FAIL $evidence\n"; }
    }
    core_check($forwardHashes === [hash_file('sha256', "$root/submissions/alpha/bbf_forward.json"), hash_file('sha256', "$root/forms/alpha.json")], 'forward success/failures leave stored response and definition unchanged');
    $audit = file_get_contents("$root/logs/access-audit.php");
    core_check(!str_contains($audit, '@') && !str_contains($audit, 'bcfda526e91745357e617477c8093a33df1ac961edd675af1ce07ce3ab4ef84f') && !str_contains($audit, 'c9ffddaa918a1b920038790fc128858957809e3c80165b8a8cbbe77c556883f2')
        && !str_contains($audit, 'Private forward') && !str_contains($audit, 'onerror') && !str_contains($audit, 'literal'), 'forward audit excludes recipients, notes, rendered data and credentials');
    core_check($forwardFailures === [], 'forward HTTP/audit delivery controls: ' . implode('; ', $forwardFailures));
    print "Core access including fixture-only forward: $checks passed. No real mail or external network.\n";
} finally { bbf_test_cleanup($root); }
