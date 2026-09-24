<?php
/** Submit transactions (docs/SUBMIT-TRANSACTIONS.md §12). CLI-only, disposable installation, mock Stripe. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
if (getenv('BBF_TEST_LEASE') || getenv('BBF_TEST_LEASE_KEY')) throw new RuntimeException('Requires a fresh owned fixture.');

$checks = 0;
$failures = 0;
function tx_check(bool $ok, string $label): void {
    ++$GLOBALS['checks'];
    if ($ok) { print "PASS $label\n"; return; }
    ++$GLOBALS['failures'];
    print "FAIL $label\n";
}

function tx_config(string $root, array $overrides = []): void {
    $config = array_replace([
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'templates_dir' => "$root/templates", 'csrf' => false,
        'sandbox' => false, 'honeypot_field' => '_hp', 'rate_limit' => 100000, 'lang' => 'en',
        'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
        'mail' => ['method' => 'mail', 'from_email' => 'fixture@example.test', 'from_name' => 'Fixture'],
        'webhook_secret' => '', 'delivery' => ['max_attempts' => 3, 'lease_seconds' => 1, 'retry_delay' => 30],
        'submit_transaction_timeout' => 10, 'submit_replay_rate' => 30, 'submit_recovery_budget' => 0,
        'sqlite' => ['path' => "$root/submissions/bbf.sqlite"],
    ], $overrides);
    $php = '<?php defined("BBF_LOADED") || exit; $root = ' . var_export($root, true) . ';'
        // Fault hook: {"point", "action": "exit"|"fail", "skip": n, "once": bool}
        . '$GLOBALS["_bbf_tx_hook"] = static function (string $point) use ($root) {'
        . '  $file = "$root/fault.json"; if (!is_file($file)) return true;'
        . '  $fault = json_decode(file_get_contents($file), true); if (!is_array($fault) || $fault["point"] !== $point) return true;'
        . '  if (($fault["skip"] ?? 0) > 0) { $fault["skip"]--; file_put_contents($file, json_encode($fault)); return true; }'
        . '  if ($fault["once"] ?? true) @unlink($file);'
        . '  if ($fault["action"] === "exit") exit;'
        . '  return false; };'
        . '$config = ' . var_export($config, true) . ';'
        // Mock Stripe that honours Idempotency-Key like Stripe does.
        . '$config["stripe"] = ["secret_key" => "sk_test_local", "webhook_secret" => "whsec_local", "transport" => static function (array $fields, string $secret, array $options = []) use ($root) {'
        . '  $file = "$root/stripe-mock.json"; $db = is_file($file) ? json_decode(file_get_contents($file), true) : [];'
        . '  $db += ["calls" => 0, "sessions" => [], "keys" => [], "modes" => []]; $db["calls"]++;'
        . '  $key = (string)($options["idempotency_key"] ?? ""); $save = static function () use (&$db, $file) { file_put_contents($file, json_encode($db)); };'
        . '  if ($key !== "" && isset($db["keys"][$key])) { $cached = $db["keys"][$key]; $save();'
        . '    return $cached["params"] === $fields ? $cached["response"] : ["_status" => 400, "error" => ["type" => "idempotency_error"]]; }'
        . '  $mode = array_shift($db["modes"]) ?? "ok";'
        . '  if ($mode === "timeout") { $save(); throw new RuntimeException("mock timeout"); }'
        . '  if ($mode === "429") { $save(); return ["_status" => 429]; }'
        . '  $id = "cs_test_" . count($db["sessions"]); $session = ["id" => $id, "url" => "https://checkout.test/$id", "expires_at" => time() + 3600];'
        . '  $db["sessions"][] = $session; $response = $mode === "500" ? ["_status" => 500, "error" => ["message" => "mock"]] : $session;'
        . '  $db["keys"][$key] = ["params" => $fields, "response" => $response]; $save(); return $response; }];'
        . 'return $config;';
    if (file_put_contents("$root/config.php", $php) === false) throw new RuntimeException('Cannot write fixture config.');
}

function tx_form(string $root, string $id, array $extra = []): void {
    $form = array_replace_recursive([
        'id' => $id, 'name' => "Form $id",
        'fields' => [['name' => 'answer', 'type' => 'text', 'label' => 'Answer', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email']],
        'on_submit' => ['actions' => [['type' => 'tx-probe', 'counter' => "$root/effects.json"]]],
    ], $extra);
    file_put_contents("$root/forms/$id.json", json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function tx_key(): string { return bin2hex(random_bytes(16)); }

function tx_post(array $server, string $form, array $body, ?string $key, array $headers = []): array {
    if ($key !== null) $body['_bbf_submit_key'] = $key;
    return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=' . $form, null,
        ['raw' => json_encode($body), 'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'] + $headers, 'timeout' => 60]);
}

function tx_fault(string $root, string $point, string $action = 'exit', int $skip = 0, bool $once = true): void {
    file_put_contents("$root/fault.json", json_encode(['point' => $point, 'action' => $action, 'skip' => $skip, 'once' => $once]));
}

function tx_clear(string $root): void { @unlink("$root/fault.json"); }

function tx_records(string $root, string $form): array {
    return glob("$root/submissions/$form/bbf_*.json") ?: [];
}

function tx_effects(string $root): array {
    $file = "$root/effects.json";
    return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function tx_effect_count(string $root, ?string $id = null): int {
    $effects = tx_effects($root);
    return $id === null ? array_sum($effects) : (int)($effects[$id] ?? 0);
}

function tx_intent_path(string $root, string $form, string $key): string {
    return "$root/submissions/.submit/$form/" . hash('sha256', $key);
}

function tx_intent(string $root, string $form, string $key): ?array {
    $path = tx_intent_path($root, $form, $key) . '.json';
    return is_file($path) ? json_decode(file_get_contents($path), true) : null;
}

function tx_recover(string $root): string {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/maintenance.php") . ' submit-recover 2>&1', $output);
    return implode("\n", $output);
}

function tx_stripe(string $root): array {
    $file = "$root/stripe-mock.json";
    return is_file($file) ? json_decode(file_get_contents($file), true) : ['calls' => 0, 'sessions' => []];
}

function tx_stripe_modes(string $root, array $modes): void {
    $db = tx_stripe($root);
    $db['modes'] = $modes;
    file_put_contents("$root/stripe-mock.json", json_encode($db));
}

$source = dirname(__DIR__);
$root = bbf_test_installation($source);
$server = null;
try {
    foreach (['bbf_submit_tx.php', 'bbf_context.php'] as $file) {
        if (!is_file("$root/$file")) copy("$source/$file", "$root/$file");
    }
    file_put_contents("$root/actions/tx-probe.php", <<<'PHP'
<?php
$counter = $action['counter'];
$effects = is_file($counter) ? json_decode(file_get_contents($counter), true) : [];
$effects[$submission['id']] = (int)($effects[$submission['id']] ?? 0) + 1;
file_put_contents($counter, json_encode($effects));
$actionResponse['probe'] = 'first-response-only';
PHP);
    tx_config($root);
    tx_form($root, 'tx');
    tx_form($root, 'txsql', ['storage' => 'sqlite']);
    tx_form($root, 'txcsv', ['storage' => 'csv']);
    tx_form($root, 'txpay', ['on_submit' => ['actions' => [], 'payment' => ['provider' => 'stripe', 'mode' => 'fixed',
        'pricing_version' => 'fixed-v1', 'currency' => 'eur', 'amount_minor' => 1250, 'success_url' => '/thanks', 'cancel_url' => '/cancel']]]);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());

    // ── Basic replay ────────────────────────────────────────────
    $key = tx_key();
    $first = tx_post($server, 'tx', ['answer' => 'one'], $key);
    $id = $first['json']['submission_id'] ?? '';
    tx_check($first['code'] === 200 && ($first['json']['probe'] ?? '') === 'first-response-only' && count(tx_records($root, 'tx')) === 1
        && tx_effect_count($root, $id) === 1, 'first submit stores one record and delivers once (first response keeps action fields)');
    tx_check((tx_intent($root, 'tx', $key)['state'] ?? '') === 'complete' && !is_file(tx_intent_path($root, 'tx', $key) . '.deliver'),
        'intent is complete and its delivery marker is gone');
    $stored = json_decode((string)@file_get_contents(tx_records($root, 'tx')[0] ?? '-'), true);
    tx_check(($stored['meta']['submit_key_hash'] ?? '') === hash('sha256', $key), 'record meta carries submit_key_hash');
    $replay = tx_post($server, 'tx', ['answer' => 'one'], $key);
    tx_check($replay['code'] === 200 && ($replay['json']['already_submitted'] ?? false) === true
        && ($replay['json']['submission_id'] ?? '') === $id && !isset($replay['json']['probe'])
        && count(tx_records($root, 'tx')) === 1 && tx_effect_count($root, $id) === 1,
        'retry with the same key replays: no second record, no second delivery');
    $different = tx_post($server, 'tx', ['answer' => 'changed'], $key);
    tx_check($different['code'] === 409 && ($different['json']['code'] ?? '') === 'already_submitted_different'
        && ($different['json']['submission_id'] ?? '') === $id, 'same key with a different payload → 409 already_submitted_different');
    $csrfOnly = tx_post($server, 'tx', ['answer' => 'one', '_bbf_csrf' => 'whatever', '_hp' => ''], $key);
    tx_check($csrfOnly['code'] === 200, 'fingerprint ignores _bbf_* fields and the honeypot');
    tx_check(tx_post($server, 'tx', ['answer' => 'x'], 'NOT-A-KEY')['code'] === 400, 'malformed key → 400');

    // #24 no key: today's behaviour, still with an intent for recovery.
    $before = count(tx_records($root, 'tx'));
    $a = tx_post($server, 'tx', ['answer' => 'keyless'], null);
    $b = tx_post($server, 'tx', ['answer' => 'keyless'], null);
    tx_check($a['code'] === 200 && $b['code'] === 200 && count(tx_records($root, 'tx')) === $before + 2, '#24 no key: every request is a new submission');

    // ── #1 crash after lock created, before the first state ────
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'intent_created');
    tx_post($server, 'tx', ['answer' => 'c1'], $key);
    tx_check(is_file(tx_intent_path($root, 'tx', $key) . '.lock') && !is_file(tx_intent_path($root, 'tx', $key) . '.json'),
        '#1 crash leaves a lock file without state');
    $retry = tx_post($server, 'tx', ['answer' => 'c1'], $key);
    tx_check($retry['code'] === 200 && count(tx_records($root, 'tx')) === $before + 1, '#1 retry → treated as no intent, one record');

    // ── #2a state replace fails, old state stays ───────────────
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'state_rename', 'fail', 1, false); // first rename (open) passes, every later one fails
    $failed = tx_post($server, 'tx', ['answer' => 'c2a'], $key);
    tx_clear($root);
    $intent = tx_intent($root, 'tx', $key);
    tx_check($failed['code'] === 503 && ($intent['state'] ?? '') === 'open',
        '#2a failed replace is a failure, not success: 503 and the old (open) state stays');
    $newId = $intent['submission_id'] ?? '';
    tx_check(tx_effect_count($root, $newId) === 0, '#2a no delivery ran after the failed state write');
    $retry = tx_post($server, 'tx', ['answer' => 'c2a'], $key);
    tx_check($retry['code'] === 200 && ($retry['json']['already_submitted'] ?? false) && ($retry['json']['submission_id'] ?? '') === $newId
        && count(tx_records($root, 'tx')) === $before + 1 && tx_effect_count($root, $newId) === 1,
        '#2a retry: inline recovery finds the record → complete → replay runs the never-attempted job once');

    // ── #3 crash after open, before outbox init ─────────────────
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'state_renamed');
    tx_post($server, 'tx', ['answer' => 'c3'], $key);
    $abortedId = tx_intent($root, 'tx', $key)['submission_id'] ?? '';
    $retry = tx_post($server, 'tx', ['answer' => 'c3'], $key);
    tx_check($retry['code'] === 200 && empty($retry['json']['already_submitted']) && ($retry['json']['submission_id'] ?? '') !== $abortedId
        && count(tx_records($root, 'tx')) === $before + 1, '#3 not_found → aborted; retry creates one record with a new ID');

    // ── #4 crash after outbox init, before store() ──────────────
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'before_store');
    tx_post($server, 'tx', ['answer' => 'c4'], $key);
    $orphanId = tx_intent($root, 'tx', $key)['submission_id'] ?? '';
    $retry = tx_post($server, 'tx', ['answer' => 'c4'], $key);
    $orphan = json_decode((string)@file_get_contents("$root/submissions/.delivery/tx/$orphanId.json"), true);
    $orphanJob = $orphan['jobs']['action:0'] ?? [];
    tx_check($retry['code'] === 200 && count(tx_records($root, 'tx')) === $before + 1 && tx_effect_count($root, $orphanId) === 0
        && ($orphanJob['state'] ?? '') === 'failed', '#4 orphan outbox is attention_required and never delivered; retry → one record');

    // ── #5 store() fails: written / not written / backend down ──
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'store', 'fail');
    $notWritten = tx_post($server, 'tx', ['answer' => 'c5a'], $key);
    tx_check($notWritten['code'] === 503 && (tx_intent($root, 'tx', $key)['state'] ?? '') === 'aborted', '#5 not written → aborted, 503');
    $retry = tx_post($server, 'tx', ['answer' => 'c5a'], $key);
    tx_check($retry['code'] === 200 && count(tx_records($root, 'tx')) === $before + 1, '#5 retry after aborted → one record');

    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'store_result', 'fail');
    $written = tx_post($server, 'tx', ['answer' => 'c5b'], $key);
    $writtenId = $written['json']['submission_id'] ?? '';
    tx_check(in_array($written['code'], [200, 202], true) && $writtenId !== '' && (tx_intent($root, 'tx', $key)['state'] ?? '') === 'complete'
        && count(tx_records($root, 'tx')) === $before + 1, '#5 written but reported failed → exists → complete');
    $retry = tx_post($server, 'tx', ['answer' => 'c5b'], $key);
    tx_check($retry['code'] === 200 && ($retry['json']['submission_id'] ?? '') === $writtenId && tx_effect_count($root, $writtenId) === 0
        && count(tx_records($root, 'tx')) === $before + 1, '#5 replay never re-runs the storage-aborted job');

    // #5 backend down / #34: an open intent whose backend is unavailable, reached through step A
    $key = tx_key();
    tx_fault($root, 'before_store');
    tx_post($server, 'tx', ['answer' => 'c34'], $key);
    tx_fault($root, 'record_exists', 'fail', 0, false);
    $pending = tx_post($server, 'tx', ['answer' => 'c34'], $key);
    tx_clear($root);
    tx_check($pending['code'] === 503 && ($pending['json']['code'] ?? '') === 'submit_pending'
        && (tx_intent($root, 'tx', $key)['state'] ?? '') === 'open', '#34 open + unavailable: one inline recovery, then 503 submit_pending; nothing deleted');
    $retry = tx_post($server, 'tx', ['answer' => 'c34'], $key);
    tx_check($retry['code'] === 200 && empty($retry['json']['already_submitted']), '#34 backend back → not_found → aborted → new submission');

    // ── #6 crash after store(), before complete ─────────────────
    $key = tx_key();
    $before = count(tx_records($root, 'tx'));
    tx_fault($root, 'after_store');
    tx_post($server, 'tx', ['answer' => 'c6'], $key);
    $id6 = tx_intent($root, 'tx', $key)['submission_id'] ?? '';
    $retry = tx_post($server, 'tx', ['answer' => 'c6'], $key);
    tx_check($retry['code'] === 200 && ($retry['json']['already_submitted'] ?? false) && ($retry['json']['submission_id'] ?? '') === $id6
        && count(tx_records($root, 'tx')) === $before + 1 && tx_effect_count($root, $id6) === 1,
        '#6 recovery → complete; never-attempted job runs once; replay 200');

    // ── #7 crash after complete, before delivery ────────────────
    $key = tx_key();
    tx_fault($root, 'after_complete');
    tx_post($server, 'tx', ['answer' => 'c7'], $key);
    $id7 = tx_intent($root, 'tx', $key)['submission_id'] ?? '';
    tx_check(tx_effect_count($root, $id7) === 0 && is_file(tx_intent_path($root, 'tx', $key) . '.deliver'), '#7 crash leaves the delivery marker');
    tx_post($server, 'tx', ['answer' => 'c7'], $key);
    tx_post($server, 'tx', ['answer' => 'c7'], $key);
    tx_check(tx_effect_count($root, $id7) === 1 && !is_file(tx_intent_path($root, 'tx', $key) . '.deliver'),
        '#7 replays run the never-attempted job exactly once');

    // ── #31 crash after complete, no retry → submit-recover ─────
    $key = tx_key();
    tx_fault($root, 'after_complete');
    tx_post($server, 'tx', ['answer' => 'c31'], $key);
    $id31 = tx_intent($root, 'tx', $key)['submission_id'] ?? '';
    tx_recover($root);
    tx_check(tx_effect_count($root, $id31) === 0, '#31 recovery leaves a young marker alone (original may be between jobs)');
    sleep(2);
    $output = tx_recover($root);
    tx_recover($root);
    tx_check(tx_effect_count($root, $id31) === 1 && !is_file(tx_intent_path($root, 'tx', $key) . '.deliver'),
        '#31 submit-recover runs each never-attempted job exactly once and deletes the marker: ' . trim($output));

    // ── #14 crash during intent deletion ────────────────────────
    $key = tx_key();
    tx_fault($root, 'store', 'fail');
    tx_post($server, 'tx', ['answer' => 'c14'], $key); // aborted
    tx_fault($root, 'intent_state_deleted');
    tx_post($server, 'tx', ['answer' => 'c14'], $key); // step A deletes the aborted intent and dies
    $before = count(tx_records($root, 'tx'));
    $retry = tx_post($server, 'tx', ['answer' => 'c14'], $key);
    tx_check($retry['code'] === 200 && count(tx_records($root, 'tx')) === $before + 1, '#14 lock without state → no intent → one record');

    // ── #15 concurrent request / #16 stalled owner ──────────────
    $key = tx_key();
    tx_post($server, 'tx', ['answer' => 'c15'], $key);
    $lock = fopen(tx_intent_path($root, 'tx', $key) . '.lock', 'r+b');
    flock($lock, LOCK_EX);
    $busy = tx_post($server, 'tx', ['answer' => 'c15'], $key);
    tx_check($busy['code'] === 202 && ($busy['json']['status'] ?? '') === 'processing' && ($busy['json']['retry_after'] ?? 0) === 2,
        '#15 lock held by the original → 202 processing');
    flock($lock, LOCK_UN);
    fclose($lock);

    $key = tx_key();
    tx_fault($root, 'before_store');
    tx_post($server, 'tx', ['answer' => 'c16'], $key);
    $lock = fopen(tx_intent_path($root, 'tx', $key) . '.lock', 'r+b');
    flock($lock, LOCK_EX);
    $output = tx_recover($root);
    tx_check(str_contains($output, 'skipped: locked') && (tx_intent($root, 'tx', $key)['state'] ?? '') === 'open',
        '#16 stalled owner: recovery skips it whatever the age; nothing deleted');
    flock($lock, LOCK_UN);
    fclose($lock);
    tx_recover($root);

    // ── #19 replay rate limit ───────────────────────────────────
    $key = tx_key();
    tx_post($server, 'tx', ['answer' => 'c19'], $key);
    $codes = [];
    for ($i = 0; $i < 31; $i++) $codes[] = tx_post($server, 'tx', ['answer' => 'c19'], $key)['code'];
    $other = tx_post($server, 'tx', ['answer' => 'c19-other'], tx_key());
    tx_check(in_array(429, $codes, true) && $codes[0] === 200 && $other['code'] === 200, '#19 31 replays in a minute → 429; other keys unaffected');

    // ── #23 unreadable state ────────────────────────────────────
    $key = tx_key();
    tx_post($server, 'tx', ['answer' => 'c23'], $key);
    $before = count(tx_records($root, 'tx'));
    file_put_contents(tx_intent_path($root, 'tx', $key) . '.json', '{broken');
    $unreadable = tx_post($server, 'tx', ['answer' => 'c23'], $key);
    tx_check($unreadable['code'] === 503 && ($unreadable['json']['code'] ?? '') === 'submit_state_unreadable'
        && count(tx_records($root, 'tx')) === $before, '#23 unreadable state → 503, no second record');

    // ── #25 replay after TTL ────────────────────────────────────
    $key = tx_key();
    tx_post($server, 'tx', ['answer' => 'c25'], $key);
    $statePath = tx_intent_path($root, 'tx', $key) . '.json';
    $state = json_decode(file_get_contents($statePath), true);
    $state['created'] = time() - 200000;
    file_put_contents($statePath, json_encode($state));
    tx_recover($root);
    $before = count(tx_records($root, 'tx'));
    $late = tx_post($server, 'tx', ['answer' => 'c25'], $key);
    tx_check($late['code'] === 200 && empty($late['json']['already_submitted'])
        && count(tx_records($root, 'tx')) === $before + 1, '#25 after the TTL the intent is deleted and a retry is new (documented)');

    // ── #30 random keys create no files before the per-IP limit ─
    $submitDir = "$root/submissions/.submit/tx";
    $locksBefore = count(glob("$submitDir/*.lock") ?: []);
    $limitsBefore = count(glob("$root/logs/ratelimit_*") ?: []);
    for ($i = 0; $i < 40; $i++) tx_post($server, 'tx', ['answer' => ''], tx_key()); // invalid: 422 after the key lookup
    tx_check(count(glob("$submitDir/*.lock") ?: []) === $locksBefore && count(glob("$root/logs/ratelimit_*") ?: []) <= $limitsBefore + 1,
        '#30 random keys create no intent or replay bucket');

    // ── #18 definition deleted between attempts ─────────────────
    $key = tx_key();
    $firstDef = tx_post($server, 'tx', ['answer' => 'c18'], $key);
    $definition = file_get_contents("$root/forms/tx.json");
    unlink("$root/forms/tx.json");
    $replay = tx_post($server, 'tx', ['answer' => 'c18'], $key);
    file_put_contents("$root/forms/tx.json", $definition);
    tx_check($replay['code'] === 200 && ($replay['json']['submission_id'] ?? '') === ($firstDef['json']['submission_id'] ?? '-'),
        '#18 definition deleted → replay still 200');

    // ── SQLite and CSV backends ─────────────────────────────────
    foreach (['txsql', 'txcsv'] as $form) {
        $key = tx_key();
        tx_fault($root, 'after_store');
        tx_post($server, $form, ['answer' => "$form-6"], $key);
        $retry = tx_post($server, $form, ['answer' => "$form-6"], $key);
        $again = tx_post($server, $form, ['answer' => "$form-6"], $key);
        $sid = $retry['json']['submission_id'] ?? '';
        tx_check($retry['code'] === 200 && ($retry['json']['already_submitted'] ?? false) && ($again['json']['submission_id'] ?? '') === $sid
            && tx_effect_count($root, $sid) === 1, "$form: crash after store → recovery finds the record; one delivery");
        $key = tx_key();
        tx_fault($root, 'before_store');
        tx_post($server, $form, ['answer' => "$form-4"], $key);
        $retry = tx_post($server, $form, ['answer' => "$form-4"], $key);
        tx_check($retry['code'] === 200 && empty($retry['json']['already_submitted']), "$form: crash before store → not_found → new submission");
    }

    // ── #17 session expired: replay without CSRF, new key still needs it ─
    tx_config($root, ['csrf' => true]);
    $csrf = bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=tx&action=csrf');
    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $csrf['headers'], $cookie);
    $key = tx_key();
    $withCsrf = tx_post($server, 'tx', ['answer' => 'c17', '_bbf_csrf' => $csrf['json']['csrf_token'] ?? ''], $key, ['Cookie' => $cookie[1] ?? '']);
    $noSession = tx_post($server, 'tx', ['answer' => 'c17'], $key);
    $newKey = tx_post($server, 'tx', ['answer' => 'c17'], tx_key());
    tx_check($withCsrf['code'] === 200 && $noSession['code'] === 200 && ($noSession['json']['already_submitted'] ?? false)
        && $newKey['code'] === 403, '#17 replay works without session/CSRF; a new key still needs CSRF');
    tx_config($root);

    // ── Payments ────────────────────────────────────────────────
    $stripeCalls = static fn() => (int)(tx_stripe($root)['calls'] ?? 0);
    $stripeSessions = static fn() => count(tx_stripe($root)['sessions'] ?? []);
    $payRecords = static fn() => count(tx_records($root, 'txpay'));

    $key = tx_key();
    $pay = tx_post($server, 'txpay', ['answer' => 'p1', 'email' => 'payer@example.test'], $key);
    $replay = tx_post($server, 'txpay', ['answer' => 'p1', 'email' => 'payer@example.test'], $key);
    $payId = $pay['json']['submission_id'] ?? '';
    $record = json_decode((string)@file_get_contents("$root/submissions/txpay/$payId.json"), true);
    tx_check($pay['code'] === 200 && str_starts_with((string)($pay['json']['redirect'] ?? ''), 'https://checkout.test/')
        && ($replay['json']['redirect'] ?? '') === $pay['json']['redirect'] && $stripeCalls() === 1 && $payRecords() === 1
        && ($record['meta']['payment_checkout_session_id'] ?? '') !== '' && is_file("$root/submissions/.delivery/txpay/$payId.json"),
        'payment: one session, session ID in meta, paid plan stored; replay returns the same redirect without a Stripe call');

    // #9 Stripe 2xx, crash before the session is written; #13 different Referer on the retry
    $key = tx_key();
    $sessions = $stripeSessions();
    tx_fault($root, 'stripe_answered');
    tx_post($server, 'txpay', ['answer' => 'p9'], $key, ['Referer' => 'https://one.example/form']);
    $retry = tx_post($server, 'txpay', ['answer' => 'p9'], $key, ['Referer' => 'https://two.example/other']);
    tx_check($retry['code'] === 200 && $stripeSessions() === $sessions + 1 && str_starts_with((string)($retry['json']['redirect'] ?? ''), 'https://checkout.test/'),
        '#9/#13 retry reuses the key and frozen parameters → the same session, no idempotency error');

    // #10 Stripe creates the session and answers 500
    $key = tx_key();
    tx_stripe_modes($root, ['500']);
    $calls = $stripeCalls();
    $indeterminate = tx_post($server, 'txpay', ['answer' => 'p10'], $key);
    $replay = tx_post($server, 'txpay', ['answer' => 'p10'], $key);
    tx_check($indeterminate['code'] === 502 && ($indeterminate['json']['code'] ?? '') === 'payment_unavailable'
        && ($replay['json']['code'] ?? '') === 'payment_unavailable' && $stripeCalls() === $calls + 1
        && (tx_intent($root, 'txpay', $key)['response']['payment_unavailable'] ?? '') === 'indeterminate',
        '#10 5xx → indeterminate: no second call with any key; replay shows the payment-not-started message');

    // #11 timeout and #32 429 → same key, stays committed, next retry gets the session
    foreach (['timeout' => '#11', '429' => '#32'] as $mode => $label) {
        $key = tx_key();
        tx_stripe_modes($root, [(string)$mode]); // PHP turns the '429' array key into an int
        $sessions = $stripeSessions();
        $first = tx_post($server, 'txpay', ['answer' => "p-$mode"], $key);
        $state = tx_intent($root, 'txpay', $key)['state'] ?? '';
        $retry = tx_post($server, 'txpay', ['answer' => "p-$mode"], $key);
        tx_check($first['code'] === 503 && $state === 'committed' && $retry['code'] === 200 && $stripeSessions() === $sessions + 1,
            "$label Stripe $mode → 503, stays committed; the retry gets one session"
            . " [first {$first['code']} state $state retry {$retry['code']} sessions " . ($stripeSessions() - $sessions) . ']');
    }

    // #8 and #12: crash after store / after session / after meta / after plan
    foreach (['after_store' => '#8', 'payment_session_written' => '#12a', 'payment_meta_written' => '#12b', 'payment_plan_written' => '#12c'] as $point => $label) {
        $key = tx_key();
        $sessions = $stripeSessions();
        $records = $payRecords();
        tx_fault($root, $point);
        tx_post($server, 'txpay', ['answer' => "p-$point"], $key);
        $retry = tx_post($server, 'txpay', ['answer' => "p-$point"], $key);
        $again = tx_post($server, 'txpay', ['answer' => "p-$point"], $key);
        $sid = $retry['json']['submission_id'] ?? '';
        $meta = json_decode((string)@file_get_contents("$root/submissions/txpay/$sid.json"), true)['meta'] ?? [];
        tx_check($retry['code'] === 200 && ($again['json']['redirect'] ?? '-') === ($retry['json']['redirect'] ?? '')
            && $stripeSessions() === $sessions + 1 && $payRecords() === $records + 1
            && ($meta['payment_checkout_session_id'] ?? '') !== '' && is_file("$root/submissions/.delivery/txpay/$sid.json"),
            "$label crash at $point → finished idempotently; one session, one record, plan created");
    }

    // #33 committed older than 23 h without a session → no Stripe call
    $key = tx_key();
    tx_fault($root, 'after_committed');
    tx_post($server, 'txpay', ['answer' => 'p33'], $key);
    $statePath = tx_intent_path($root, 'txpay', $key) . '.json';
    $state = json_decode(file_get_contents($statePath), true);
    $state['committed_at'] = time() - 24 * 3600;
    file_put_contents($statePath, json_encode($state));
    $calls = $stripeCalls();
    $expired = tx_post($server, 'txpay', ['answer' => 'p33'], $key);
    tx_check($expired['code'] === 502 && $stripeCalls() === $calls
        && (tx_intent($root, 'txpay', $key)['response']['payment_unavailable'] ?? '') === 'expired',
        '#33 committed older than 23 h → no Stripe call, payment_unavailable expired');

    // #18 payment: definition deleted → payment status read via the stored backend
    $key = tx_key();
    tx_post($server, 'txpay', ['answer' => 'p18'], $key);
    $definition = file_get_contents("$root/forms/txpay.json");
    unlink("$root/forms/txpay.json");
    $replay = tx_post($server, 'txpay', ['answer' => 'p18'], $key);
    file_put_contents("$root/forms/txpay.json", $definition);
    tx_check($replay['code'] === 200 && str_starts_with((string)($replay['json']['redirect'] ?? ''), 'https://checkout.test/'),
        '#18 payment replay after the definition was deleted → same redirect');

    // Opportunistic recovery on later submits (budget > 0).
    // Recovery picks random intents; a budget above the ~40 intents left by earlier checks makes one pass cover all of them.
    tx_config($root, ['submit_recovery_budget' => 100]);
    $key = tx_key();
    tx_fault($root, 'after_store');
    tx_post($server, 'tx', ['answer' => 'opp'], $key);
    for ($i = 0; $i < 12 && (tx_intent($root, 'tx', $key)['state'] ?? '') === 'open'; $i++) tx_post($server, 'tx', ['answer' => "opp-$i"], tx_key());
    tx_check((tx_intent($root, 'tx', $key)['state'] ?? '') === 'complete', 'opportunistic recovery on later submits completes a crashed intent');
    tx_config($root);
} finally {
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
print "Submit transaction regression: $checks checks, $failures failures.\n";
exit($failures ? 1 : 0);
