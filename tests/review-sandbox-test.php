<?php
/** G2 sandbox boundary only, not the full access milestone gate. Owned fixtures only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0;
function sb_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    ++$checks; print "PASS $name\n";
}
$root = bbf_test_installation(dirname(__DIR__));
try {
    bbf_test_copy(dirname(__DIR__) . '/sandbox.php', "$root/sandbox.php");
    bbf_test_copy(__FILE__, "$root/tests/review-sandbox-test.php");
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    $base = ['sandbox' => true, 'api_token' => 'fixture-admin-987654321',
        'access_tokens' => [['id' => 'scoped', 'token' => 'fixture-scoped-123456789',
            'forms' => ['alpha'], 'permissions' => ['read', 'export', 'delete'],
            'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]],
        'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'templates_dir' => "$root/templates", 'logs_dir' => "$root/logs",
        'storage' => 'file', 'lang' => 'en', 'csrf' => true, 'rate_limit' => 1000,
        'honeypot_field' => '_bbf_hp', 'smoke_token' => 'fixture-smoke-456789',
        'allowed_origins' => ['https://embed.example.invalid'],
        'mail' => ['method' => 'mail', 'from_email' => 'sender@example.invalid']];
    function sb_config(array $config): void {
        global $root;
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        clearstatcache();
    }
    $form = ['id' => 'alpha', 'name' => 'Private sandbox fixture',
        'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text', 'required' => true]],
        'on_submit' => ['store' => true, 'actions' => [['type' => 'sandbox-sentinel']]]];
    file_put_contents("$root/forms/alpha.json", json_encode($form));
    $delivery = $form; $delivery['id'] = 'delivery';
    $delivery['on_submit'] += ['confirm_email' => ['to' => 'never@example.invalid', 'template' => 'fixture.html'],
        'notify' => ['to' => 'never@example.invalid', 'template' => 'fixture.html'],
        'webhooks' => ['https://never.example.invalid/hook'], 'redirect' => 'https://never.example.invalid/next'];
    file_put_contents("$root/forms/delivery.json", json_encode($delivery));
    $payment = $delivery; $payment['id'] = 'payment';
    $payment['on_submit']['payment'] = ['provider' => 'stripe', 'mode' => 'fixed', 'pricing_version' => 'sandbox-v1',
        'amount_minor' => 1200, 'currency' => 'eur'];
    file_put_contents("$root/forms/payment.json", json_encode($payment));
    file_put_contents("$root/templates/fixture.html", '<p>{answer}</p>');
    file_put_contents("$root/actions/sandbox-sentinel.php", '<?php file_put_contents($config["submissions_dir"] . "/action-ran", "executed"); $actionResponse["fixture_action"] = true;');
    // Fixture-only observer: exercises respondent state across management regeneration/revocation.
    file_put_contents("$root/tests/session-probe.php", '<?php session_start(); if (isset($_GET["seed"])) $_SESSION["respondent_draft"] = ["answer" => "keep-me"]; header("Content-Type: application/json"); echo json_encode(["draft" => $_SESSION["respondent_draft"] ?? null, "secret" => $_SESSION["bbf_secret"] ?? null]);');
    sb_config($base);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    function sb_http(string $path, array $options = []): array {
        global $server;
        return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/' . $path, null, $options);
    }
    function sb_cookie(array $r): string {
        preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $r['headers'], $m);
        if (!isset($m[1])) throw new RuntimeException('Missing session cookie');
        return $m[1];
    }
    function sb_login(string $cookie = ''): array {
        global $base;
        $r = sb_http('sandbox.php', ['cookie' => $cookie, 'headers' => ['X-BBF-Token' => $base['api_token']]]);
        sb_check($r['code'] === 200, 'admin header login');
        preg_match('/const sandboxCsrf = ("[a-f0-9]+")/', $r['body'], $m);
        sb_check(isset($m[1]), 'page exposes management CSRF, not credential');
        return ['cookie' => sb_cookie($r), 'csrf' => json_decode($m[1]), 'response' => $r];
    }
    function sb_post(array $s = [], bool $csrf = true, array $data = ['answer' => 'fixture answer']): array {
        return ['method' => 'POST', 'cookie' => $s['cookie'] ?? '',
            'headers' => ['Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] +
                ($csrf ? ['X-BBF-CSRF' => $s['csrf'] ?? 'wrong'] : []), 'raw' => json_encode($data)];
    }
    function sb_untouched(string $name): void {
        global $root;
        sb_check((glob("$root/submissions/*") ?: []) === [] && (glob("$root/data/*") ?: []) === []
            && !file_exists("$root/logs/.security_check") && (glob("$root/logs/ratelimit_*") ?: []) === [], $name);
    }
    sb_check(sb_http('tests/review-sandbox-test.php')['code'] === 403, 'suite refuses HTTP execution');
    foreach (['sandbox.php', 'sandbox.php?action=forms', 'sandbox.php?action=definition&form=alpha'] as $path)
        sb_check(sb_http($path)['code'] === 403, "anonymous loopback denied $path");
    foreach (['1', '0', '', '%5B%5D'] as $flag) {
        $path = $flag === '%5B%5D' ? 'submit.php?form=alpha&sandbox[]=1' : "submit.php?form=alpha&sandbox=$flag";
        sb_check(sb_http($path, sb_post())['code'] === 403, 'sandbox presence always requires authorization');
    }
    $bypass = sb_post(); $bypass['headers'] += ['X-BBF-Smoke-Token' => $base['smoke_token'], 'Origin' => $base['allowed_origins'][0]];
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', $bypass)['code'] === 403, 'smoke token and CORS never authorize sandbox');
    sb_untouched('unauthorized sandbox no storage, actions, rate-limit or security-check writes');
    $q = sb_http('sandbox.php?token=' . $base['api_token'] . '&form=' . $base['api_token'] . '&action=definition');
    sb_check($q['code'] === 303 && str_contains($q['headers'], "Location: sandbox.php\r\n") && $q['body'] === '', 'query credential exchanges to clean fixed URL');
    sb_check(!str_contains($q['headers'], $base['api_token']), 'query exchange does not reflect redundant credentials');
    sb_check(sb_http('sandbox.php', ['cookie' => sb_cookie($q)])['code'] === 200, 'clean redirect session authorizes page');
    $s = sb_login();
    sb_check(!str_contains($s['response']['body'], $base['api_token']) && str_contains($s['response']['headers'], 'no-store')
        && str_contains($s['response']['headers'], 'no-referrer') && str_contains(strtolower($s['response']['headers']), 'httponly'), 'page omits API secret and sets privacy/session headers');
    // Source contract for browser event ordering: capture must prevent BBF's earlier bubble listener.
    sb_check(str_contains($s['response']['body'], 'e.preventDefault(); e.stopImmediatePropagation();')
        && str_contains($s['response']['body'], '}, { capture: true });')
        && str_contains($s['response']['body'], "'X-BBF-CSRF': sandboxCsrf")
        && str_contains($s['response']['body'], 'submit.php?form=${encodeURIComponent(formId)}&sandbox=1'), 'UI suppresses real bubble handler and sends CSRF to sandbox-only URL (source contract)');
    foreach (['sandbox.php?action=forms', 'sandbox.php?action=definition&form=alpha'] as $path) {
        sb_check(sb_http($path, ['cookie' => $s['cookie']])['code'] === 200, "session GET $path");
        sb_check(sb_http($path, sb_post($s, false))['code'] === 403, "AJAX page POST missing CSRF $path");
        sb_check(sb_http($path, sb_post($s))['code'] === 200, "AJAX page POST valid CSRF $path");
    }
    // Compare exactly the two records added by each operation, not broad log substrings.
    function sb_audit_rows(): array {
        global $root;
        $lines = file("$root/logs/access-audit.php", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (array_shift($lines) !== '<?php http_response_code(404); exit; ?>') throw new RuntimeException('Audit guard missing');
        return array_map(static function ($line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $row['utc'] ?? '')) throw new RuntimeException('Invalid audit timestamp');
            unset($row['utc']);
            return $row;
        }, $lines);
    }
    function sb_audited(string $path, array $options, int $code, string $action, string $form,
        string $result, int $count, string $principal = 'legacy-admin', string $decision = 'allowed'): array {
        $before = sb_audit_rows();
        $r = sb_http($path, $options);
        sb_check($r['code'] === $code, "audit response $action/$form/$result ($code)");
        $row = ['principal_id' => $principal, 'action' => $action, 'form' => $form, 'submission_ids' => [],
            'decision' => $decision, 'result' => 'attempted', 'result_count' => 0];
        $end = $row; $end['result'] = $result; $end['result_count'] = $count;
        $after = sb_audit_rows();
        sb_check(array_slice($after, 0, count($before)) === $before
            && array_slice($after, count($before)) === [$row, $end], "exact audit pair $principal/$action/$form/$decision/$result/$count");
        return $r;
    }
    $session = ['cookie' => $s['cookie']];
    foreach ([$session, sb_post($s)] as $options) {
        $r = sb_audited('sandbox.php?action=definition&form=alpha', $options, 200, 'sandbox_definition', 'alpha', 'completed', 1);
        sb_check($r['json'] === $form, 'audited definition returns exact fixture');
        $r = sb_audited('sandbox.php?action=forms', $options, 200, 'sandbox_forms', '', 'completed', 3);
        sb_check(array_column($r['json'], 'id') === ['alpha', 'delivery', 'payment']
            && array_column($r['json'], 'fields') === [1, 1, 1], 'audited list has exact forms and field counts');
        $r = sb_audited('sandbox.php', $options, 200, 'sandbox_page', '', 'completed', 3);
        sb_check(substr_count($r['body'], '<option value=') === 3, 'page audit count matches rendered choices');
        $r = sb_audited('sandbox.php?action=definition&form=missing', $options, 404, 'sandbox_definition', 'missing', 'failed', 0);
        sb_check($r['json'] === ['error' => 'Form not found'], 'missing definition never discloses data');
    }
    foreach (['', 'form[]=alpha', 'form=alpha%2F..', 'form=Alpha'] as $query) {
        sb_audited('sandbox.php?action=definition&' . $query, $session, 400, 'sandbox_definition',
            $query === 'form=Alpha' ? 'Alpha' : '', 'failed', 0);
    }
    foreach (['{broken-secret', 'null', '[]', '{"fields":"bad-secret"}', '{"fields":[1]}', '{"name":[],"fields":[]}'] as $json) {
        file_put_contents("$root/forms/broken.json", $json);
        $r = sb_audited('sandbox.php?action=definition&form=broken', $session, 500, 'sandbox_definition', 'broken', 'failed', 0);
        sb_check($r['json'] === ['error' => 'Unable to load sandbox forms'], 'bad definition returns only generic error');
        sb_audited('sandbox.php?action=forms', $session, 500, 'sandbox_forms', '', 'failed', 0);
        sb_audited('sandbox.php', $session, 500, 'sandbox_page', '', 'failed', 0);
        // Reading alpha must not enumerate the unrelated broken definition.
        sb_audited('sandbox.php?action=definition&form=alpha', $session, 200, 'sandbox_definition', 'alpha', 'completed', 1);
    }
    unlink("$root/forms/broken.json"); mkdir("$root/forms/broken.json");
    sb_audited('sandbox.php?action=definition&form=broken', $session, 500, 'sandbox_definition', 'broken', 'failed', 0);
    rmdir("$root/forms/broken.json");
    file_put_contents("$root/forms/form.schema.json", '{not-a-definition');
    sb_audited('sandbox.php?action=forms', $session, 200, 'sandbox_forms', '', 'completed', 3);
    unlink("$root/forms/form.schema.json"); $original = file_get_contents("$root/forms/alpha.json"); foreach (['file', 'csv', 'sqlite', 'unavailable-backend'] as $backend) { $def = $form; $def['storage'] = $backend; file_put_contents("$root/forms/alpha.json", json_encode($def)); mkdir("$root/submissions/ALPHA"); mkdir("$root/submissions/ALPHA.csv"); mkdir("$root/submissions/bbf.sqlite"); $r = sb_audited('sandbox.php?action=definition&form=alpha', $session, 200, 'sandbox_definition', 'alpha', 'completed', 1); sb_check($r['json'] === $def, "$backend definition ignores backend decoys"); sb_audited('sandbox.php?action=forms', $session, 200, 'sandbox_forms', '', 'completed', 3); sb_audited('sandbox.php', $session, 200, 'sandbox_page', '', 'completed', 3); rmdir("$root/submissions/ALPHA"); rmdir("$root/submissions/ALPHA.csv"); rmdir("$root/submissions/bbf.sqlite"); } file_put_contents("$root/forms/alpha.json", $original);
    rename("$root/forms", "$root/saved-forms"); mkdir("$root/forms");
    sb_audited('sandbox.php?action=forms', $session, 200, 'sandbox_forms', '', 'completed', 0);
    sb_audited('sandbox.php', $session, 200, 'sandbox_page', '', 'completed', 0);
    rmdir("$root/forms");
    sb_audited('sandbox.php?action=forms', $session, 500, 'sandbox_forms', '', 'failed', 0);
    rename("$root/saved-forms", "$root/forms");
    $apiOperations = ['sandbox.php' => ['sandbox_page', ''], 'sandbox.php?action=forms' => ['sandbox_forms', ''],
        'sandbox.php?action=definition&form=alpha' => ['sandbox_definition', 'alpha']];
    foreach ($apiOperations as $path => [$operation, $id]) {
        sb_audited($path, [], 403, $operation, $id, 'failed', 0, 'anonymous', 'denied');
        sb_audited($path, ['headers' => ['X-BBF-Token' => $base['access_tokens'][0]['token']]],
            403, $operation, $id, 'failed', 0, 'scoped', 'denied');
        sb_audited($path, sb_post($s, false), 403, $operation, $id, 'failed', 0, 'legacy-admin', 'denied');
        sb_audited($path, $session + ['method' => 'PUT'], 405, $operation, $id, 'failed', 0);
    }
    foreach (['action=caller-secret-unknown', 'action[]=' . $base['api_token'], 'action=' . $base['api_token']] as $query) {
        foreach ([['legacy-admin', $session, 400], ['anonymous', [], 403],
            ['scoped', ['headers' => ['X-BBF-Token' => $base['access_tokens'][0]['token']]], 403]] as [$who, $options, $code]) {
            sb_audited('sandbox.php?' . $query, $options, $code, 'sandbox_invalid', '', 'failed', 0, $who, 'denied');
        }
    }
    $audit = file_get_contents("$root/logs/access-audit.php");
    sb_check(!str_contains($audit, 'caller-secret-unknown') && !str_contains($audit, 'broken-secret')
        && !str_contains($audit, 'bad-secret') && !str_contains($audit, $base['api_token'])
        && !str_contains($audit, $base['access_tokens'][0]['token']), 'API audit excludes unknown caller text, definitions and credentials');
    sb_untouched('all audited API operations have no real-submit side effects');
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', sb_post($s, false))['code'] === 403, 'submit missing management CSRF denied');
    $smokeAdmin = sb_post($s, false); $smokeAdmin['headers']['X-BBF-Smoke-Token'] = $base['smoke_token'];
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', $smokeAdmin)['code'] === 403, 'valid smoke header never replaces management CSRF');
    $bad = $s; $bad['csrf'] = 'wrong';
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', sb_post($bad))['code'] === 403, 'submit wrong management CSRF denied');
    $headerOnly = sb_post([], false); $headerOnly['headers']['X-BBF-Token'] = $base['api_token'];
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', $headerOnly)['code'] === 403, 'admin header alone cannot bypass session CSRF');
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', ['cookie' => $s['cookie']])['code'] === 405, 'sandbox submit requires POST');
    $previews = [];
    foreach (['alpha', 'delivery', 'payment'] as $id) {
        $r = sb_http("submit.php?form=$id&sandbox=1", sb_post($s));
        $previews[$id] = $r['json'] ?? [];
        sb_check($r['code'] === 200 && ($r['json']['sandbox'] ?? false) && ($r['json']['validation']['passed'] ?? false)
            && ($r['json']['on_submit_preview']['actions'][0]['file_exists'] ?? false), "authorized $id preview succeeds without real actions/delivery/payment");
    }
    $paymentPreview = $previews['payment']['on_submit_preview']['payment'] ?? [];
    sb_check(($paymentPreview['amount_minor'] ?? null) === 1200 && ($paymentPreview['currency'] ?? null) === 'EUR'
        && ($paymentPreview['pricing_mode'] ?? null) === 'fixed' && ($paymentPreview['pricing_version'] ?? null) === 'sandbox-v1'
        && ($paymentPreview['quote'] ?? null) === ['mode' => 'fixed', 'version' => 'sandbox-v1', 'amount_minor' => 1200,
            'minor_units' => 2, 'currency' => 'eur'], 'sandbox payment preview exposes the exact trusted fixed quote');
    $r = sb_http('submit.php?form=alpha&sandbox=1', sb_post($s, true, []));
    sb_check($r['code'] === 422 && ($r['json']['sandbox'] ?? false), 'invalid sandbox returns validation failure only');
    sb_untouched('authorized previews and CSRF denials leave public state untouched');
    // Fresh-config changes are tested on the same running process, never by restarting it.
    foreach (['rotate', 'remove'] as $change) {
        sb_config($base); $s = sb_login(); $c = $base;
        $c['api_token'] = $change === 'rotate' ? 'fixture-rotated-admin' : '';
        sb_config($c);
        sb_check(sb_http('sandbox.php', ['cookie' => $s['cookie']])['code'] === 403, "$change invalidates page session immediately");
        sb_check(sb_http('submit.php?form=alpha&sandbox=1', sb_post($s))['code'] === 403, "$change invalidates submit session immediately");
        sb_check(sb_http('sandbox.php', ['headers' => ['X-BBF-Token' => $base['api_token']]])['code'] === 403, "$change rejects old header token");
    }
    sb_config($base);
    foreach (['valid', 'revoked', 'expired'] as $state) {
        $c = $base;
        if ($state === 'revoked') $c['access_tokens'][0]['revoked'] = true;
        if ($state === 'expired') $c['access_tokens'][0]['expires_at'] = '2000-01-01T00:00:00Z';
        sb_config($c);
        $scoped = ['headers' => ['X-BBF-Token' => $base['access_tokens'][0]['token']]];
        foreach (['sandbox.php', 'sandbox.php?action=forms', 'sandbox.php?action=definition&form=alpha'] as $path)
            sb_check(sb_http($path, $scoped)['code'] === 403, "$state scoped token denied $path");
        $post = sb_post(); $post['headers'] += $scoped['headers'];
        sb_check(sb_http('submit.php?form=alpha&sandbox=1', $post)['code'] === 403, "$state scoped token denied submit");
        sb_check(sb_http('sandbox.php?token=' . $base['access_tokens'][0]['token'])['code'] === 403, "$state scoped query never exchanges as admin");
    }
    sb_config($base); $s = sb_login();
    $bad = sb_post($s); $bad['headers']['X-BBF-Token'] = 'invalid';
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', $bad)['code'] === 403, 'explicit bad credential never falls back to admin session');
    sb_check(sb_http('sandbox.php', ['cookie' => $s['cookie']])['code'] === 403, 'bad explicit credential clears management grant');
    $s = sb_login(); $c = $base; $c['sandbox'] = false; sb_config($c);
    foreach (['sandbox.php', 'sandbox.php?action=forms', 'sandbox.php?action=definition&form=alpha'] as $path)
        sb_check(sb_http($path, ['cookie' => $s['cookie']])['code'] === 403, "disabled page denied $path");
    foreach (['1', '0', ''] as $flag) {
        $post = sb_post($s); $post['headers']['X-BBF-Smoke-Token'] = $base['smoke_token'];
        sb_check(sb_http("submit.php?form=alpha&sandbox=$flag", $post)['code'] === 403, 'disabled requested sandbox denies even admin + CSRF + smoke');
    }
    sb_untouched('disabled/rotated/scoped requests never fall through to real writes/actions');
    // Fail closed if management audit cannot be durably recorded.
    sb_config($base); $s = sb_login();
    rename("$root/logs/access-audit.php", "$root/logs/saved-audit.php"); mkdir("$root/logs/access-audit.php");
    sb_check(sb_http('submit.php?form=alpha&sandbox=1', sb_post($s))['code'] === 503, 'audit failure denies before preview');
    rmdir("$root/logs/access-audit.php"); rename("$root/logs/saved-audit.php", "$root/logs/access-audit.php");
    sb_untouched('audit failure leaves public state untouched');
    $audit = file_get_contents("$root/logs/access-audit.php");
    sb_check(str_contains($audit, 'sandbox_submit') && str_contains($audit, '"decision":"denied"') && str_contains($audit, '"result":"completed"')
        && !str_contains($audit, $base['api_token']) && !str_contains($audit, 'fixture answer'), 'sandbox audit records outcomes without credentials or submitted values');
    // Normal public CSRF/storage/action behavior survives management login and removal.
    $public = sb_http('submit.php?action=csrf&form=alpha'); $cookie = sb_cookie($public);
    sb_check($public['code'] === 200 && !empty($public['json']['csrf_token']), 'public CSRF endpoint needs no admin');
    $before = sb_http('tests/session-probe.php?seed=1', ['cookie' => $cookie])['json'];
    $s = sb_login($cookie); $c = $base; $c['api_token'] = ''; sb_config($c);
    sb_check(sb_http('sandbox.php', ['cookie' => $s['cookie']])['code'] === 403, 'management removal denies reused public session');
    $after = sb_http('tests/session-probe.php', ['cookie' => $s['cookie']])['json'];
    sb_check($before === $after && $after['draft']['answer'] === 'keep-me' && !empty($after['secret']), 'non-management draft and respondent secret survive login/removal');
    $post = sb_post($s, false, ['answer' => 'public answer', '_bbf_csrf' => $public['json']['csrf_token']]);
    $r = sb_http('submit.php?form=alpha', $post);
    sb_check($r['code'] === 200 && ($r['json']['fixture_action'] ?? false) && !isset($r['json']['sandbox']), 'normal public submission still executes intended fixture action');
    $saved = glob("$root/submissions/alpha/*.json") ?: [];
    sb_check(count($saved) === 1 && json_decode(file_get_contents($saved[0]), true)['data']['answer'] === 'public answer'
        && is_file("$root/submissions/action-ran"), 'normal public submission persists exactly one record');

    // Full submit.php HTTP regressions, not an extracted CSRF predicate. Storage only:
    // no email, webhook, payment, redirect or custom action can be invoked by this form.
    $smoke = $form; $smoke['id'] = 'smoke'; $smoke['on_submit'] = ['store' => true];
    file_put_contents("$root/forms/smoke.json", json_encode($smoke));
    sb_config($base);
    function sb_smoke_case(string $name, string $path, array $post, bool $accepted = false): void {
        global $root;
        $before = glob("$root/submissions/smoke/*.json") ?: [];
        $r = sb_http($path, $post);
        sb_check($r['code'] === ($accepted ? 200 : 403) && ($r['json']['status'] ?? '') === ($accepted ? 'ok' : 'error')
            && !isset($r['json']['sandbox']) && ($accepted || ($r['json']['message'] ?? '') === 'Invalid or missing CSRF token.'), $name);
        $after = glob("$root/submissions/smoke/*.json") ?: [];
        $added = array_values(array_diff($after, $before));
        sb_check($accepted ? count($added) === 1 && count($after) === count($before) + 1
            && json_decode(file_get_contents($added[0]), true)['data']['answer'] === 'fixture answer'
            && json_decode(file_get_contents($added[0]), true)['id'] === ($r['json']['submission_id'] ?? '')
            : $after === $before, "$name: exact storage effect");
    }
    $path = 'submit.php?form=smoke'; $post = sb_post([], false);
    sb_smoke_case('missing smoke header still requires public CSRF', $path, $post);
    foreach (['smoke_token=' . $base['smoke_token'], 'smoke_token[]=x', 'smoke_token[0]=' . $base['smoke_token'],
        'smoke_token[nested][value]=x', 'smoke_token='] as $query) {
        sb_smoke_case('URL-only credential rejected: ' . explode('=', $query)[0], "$path&$query", $post);
    }
    foreach (['', 'wrong', '[]', '0', $base['smoke_token'] . ',wrong'] as $value) {
        $bad = $post; $bad['headers']['X-BBF-Smoke-Token'] = $value;
        sb_smoke_case('malformed/wrong header rejected: ' . json_encode($value), $path . '&smoke_token=' . $base['smoke_token'], $bad);
    }
    sb_smoke_case('JSON body smoke token is not a credential', $path,
        sb_post([], false, ['answer' => 'fixture answer', 'smoke_token' => $base['smoke_token']]));
    sb_smoke_case('form body smoke token is not a credential', $path, ['method' => 'POST',
        'raw' => http_build_query(['answer' => 'fixture answer', 'smoke_token' => $base['smoke_token']])]);
    $good = $post; $good['headers']['X-BBF-Smoke-Token'] = $base['smoke_token'];
    sb_smoke_case('valid header exempts public CSRF and stores fixture only', $path, $good, true);
    foreach ([null, '', [], [$base['smoke_token']], true, 123] as $value) {
        $c = $base; $c['smoke_token'] = $value; sb_config($c);
        sb_smoke_case('invalid configured smoke token fails closed: ' . json_encode($value), $path, $good);
    }
    $c = $base; unset($c['smoke_token']); sb_config($c);
    sb_smoke_case('absent configured smoke token fails closed', $path, $good);
    sb_config($base);
    // HTTP headers are strings; this fixture-only adapter additionally injects invalid
    // server-variable types before including the complete, unchanged copied handler.
    file_put_contents("$root/tests/smoke-header-shape.php", '<?php $shapes = [[], ["fixture-smoke-456789"], true, 123, new stdClass()]; $_SERVER["HTTP_X_BBF_SMOKE_TOKEN"] = $shapes[(int)($_GET["shape"] ?? 0)]; require dirname(__DIR__) . "/submit.php";');
    foreach (['empty array', 'nonempty array', 'boolean', 'integer', 'object'] as $i => $name) {
        sb_smoke_case("nonstring header $name fails closed", "tests/smoke-header-shape.php?form=smoke&shape=$i&smoke_token=" . $base['smoke_token'], $post);
    }
    $s = sb_login();
    sb_smoke_case('admin session and management CSRF do not exempt public CSRF', $path, sb_post($s));
    $admin = $post; $admin['headers']['X-BBF-Token'] = $base['api_token'];
    sb_smoke_case('admin header does not exempt public CSRF', $path, $admin);
    $cors = $post; $cors['headers']['Origin'] = $base['allowed_origins'][0];
    sb_smoke_case('allowed CORS origin retains public CSRF exemption', $path . '&smoke_token[]=x', $cors, true);
    $cors['headers']['Origin'] = 'https://other.example.invalid';
    sb_smoke_case('unlisted CORS origin still requires public CSRF', $path, $cors);
    $public = sb_http('submit.php?action=csrf&form=smoke');
    $csrf = sb_post(['cookie' => sb_cookie($public)], false,
        ['answer' => 'fixture answer', '_bbf_csrf' => $public['json']['csrf_token']]);
    sb_smoke_case('valid public CSRF works despite malformed URL smoke token', $path . '&smoke_token[]=x', $csrf, true);
    $c = $base; $c['csrf'] = false; sb_config($c);
    sb_smoke_case('disabled public CSRF retains existing behavior', $path . '&smoke_token[]=x', $post, true);
    sb_config($base);
    $r = sb_http('submit.php?form=alpha&sandbox=1&smoke_token[]=x', sb_post($s));
    sb_check($r['code'] === 200 && ($r['json']['sandbox'] ?? false), 'authorized sandbox ignores malformed URL smoke token');
    sb_check((glob("$root/submissions/alpha/*.json") ?: []) === $saved && count(glob("$root/submissions/smoke/*.json") ?: []) === 4,
        'smoke regressions wrote exactly four fixture records and left public baseline intact');
    $errors = is_file("$root/logs/php-error.log") ? file_get_contents("$root/logs/php-error.log") : '';
    sb_check(!str_contains($errors, 'TypeError') && !str_contains($errors, 'Fatal error'), 'malformed smoke credentials never cause an uncaught server error');
    print "Sandbox: $checks passed. Only owned loopback fixture HTTP; outbound functions disabled. UI event guard checked as source contract, not real-browser execution.\n";
} finally { bbf_test_cleanup($root); }
