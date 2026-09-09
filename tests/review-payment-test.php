<?php
/** G4 R1.2 trusted pricing. CLI-only, disposable local installation, no live services. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
if (getenv('BBF_TEST_LEASE') || getenv('BBF_TEST_LEASE_KEY')) throw new RuntimeException('Requires a fresh owned fixture.');

$checks = 0;
$failures = 0;
function payment_check(bool $ok, string $label): void {
    ++$GLOBALS['checks'];
    if ($ok) { print "PASS $label\n"; return; }
    ++$GLOBALS['failures'];
    print "FAIL $label\n";
}
function payment_throws(callable $operation): bool {
    try { $operation(); return false; } catch (InvalidArgumentException $error) { return true; }
}
function payment_schema_errors(array $payment, array $fields): array {
    $form = ['id' => 'matrix', 'fields' => $fields, 'on_submit' => ['store' => true, 'payment' => $payment]];
    return validateFormDefinition($form);
}
function payment_callback(array $server, string $base, array $session, string $secret,
    string $type = 'checkout.session.completed', string $eventId = 'evt_fixture'): array {
    $payload = json_encode(['id' => $eventId, 'type' => $type, 'data' => ['object' => $session]], JSON_THROW_ON_ERROR);
    $time = time();
    $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);
    return bbf_test_http($server, $base . 'payment.php', null, [
        'raw' => $payload,
        'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => $signature],
    ]);
}
function payment_concurrent_transitions(string $root, string $configPath, string $id, string $formId): array {
    $children = [];
    foreach (array_merge(array_fill(0, 8, 'failed'), array_fill(0, 8, 'paid')) as $status) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/payment-transition-worker.php")
            . ' ' . escapeshellarg($configPath) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($id)
            . ' ' . escapeshellarg($formId) . ' ' . escapeshellarg($status);
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (is_resource($process)) $children[] = [$process, $pipes];
    }
    $ok = 0;
    foreach ($children as [$process, $pipes]) {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode($output, true);
        if ($code === 0 && ($result['ok'] ?? false) && in_array($result['status'] ?? null, ['failed', 'paid'], true)) ++$ok;
        elseif ($error !== '') print "Transition worker error: $error\n";
    }
    return ['started' => count($children), 'ok' => $ok];
}

$source = dirname(__DIR__);
$root = bbf_test_installation($source);
$server = null;
try {
    define('BBF_LOADED', true);
    require "$root/bbf_functions.php";

    $demo = json_decode(file_get_contents("$root/forms/demo-order.json"), true, 512, JSON_THROW_ON_ERROR);
    $catalogPayment = $demo['on_submit']['payment'];
    $demoFields = $demo['fields'];
    $resolvedFields = flattenFields($demoFields);
    $validData = [
        'product' => 'business_cards', 'cards_qty' => '250', 'cards_paper' => 'standard',
        'cards_finish' => 'matte', 'cards_sides' => 'single', 'order_total' => '0.01',
        'delivery_country' => 'pickup', 'payment_method' => 'card',
        'customer_name' => 'Trusted Quote', 'email' => 'quote@example.test', 'gdpr_consent' => ['agreed'],
    ];

    $hasResolver = function_exists('bbfResolvePaymentQuote');
    payment_check($hasResolver, 'trusted quote resolver exists');
    $quote = null;
    if ($hasResolver) {
        try { $quote = bbfResolvePaymentQuote($catalogPayment, $validData); } catch (Throwable $error) {}
    }
    payment_check(($quote['amount_minor'] ?? null) === 1125 && ($quote['currency'] ?? null) === 'eur',
        'demo catalog resolves configured EUR 11.25 independently of tampered order_total');
    $tampered = $validData; $tampered['order_total'] = '999999.99';
    $tamperedQuote = null;
    if ($hasResolver) {
        try { $tamperedQuote = bbfResolvePaymentQuote($catalogPayment, $tampered); } catch (Throwable $error) {}
    }
    payment_check($quote !== null && $tamperedQuote === $quote, 'respondent order_total cannot alter trusted quote or snapshot');

    foreach ([
        'unknown product' => array_replace($validData, ['product' => 'not_configured']),
        'fractional quantity' => array_replace($validData, ['cards_qty' => '250.5']),
        'quantity below minimum' => array_replace($validData, ['cards_qty' => '99']),
        'quantity above maximum' => array_replace($validData, ['cards_qty' => '10001']),
        'unknown configured option' => array_replace($validData, ['cards_paper' => 'forged']),
    ] as $label => $badData) {
        payment_check($hasResolver && payment_throws(static fn() => bbfResolvePaymentQuote($catalogPayment, $badData)), "$label rejected");
    }
    $inactiveProduct = $catalogPayment;
    if (isset($inactiveProduct['catalog']['products']['business_cards'])) $inactiveProduct['catalog']['products']['business_cards']['active'] = false;
    payment_check($hasResolver && payment_throws(static fn() => bbfResolvePaymentQuote($inactiveProduct, $validData)), 'inactive product rejected');
    $inactiveOption = $catalogPayment;
    if (isset($inactiveOption['catalog']['products']['business_cards']['options']['cards_paper']['standard'])) {
        $inactiveOption['catalog']['products']['business_cards']['options']['cards_paper']['standard']['active'] = false;
    }
    payment_check($hasResolver && payment_throws(static fn() => bbfResolvePaymentQuote($inactiveOption, $validData)), 'inactive option rejected');

    $matrixFields = [
        ['name' => 'amount', 'type' => 'number'], ['name' => 'product', 'type' => 'select', 'options' => ['one']],
        ['name' => 'qty', 'type' => 'number'], ['name' => 'size', 'type' => 'radio', 'options' => ['small']],
    ];
    $fixed = ['provider' => 'stripe', 'mode' => 'fixed', 'pricing_version' => 'fixed-v1', 'currency' => 'usd', 'amount_minor' => 1250];
    $donation = ['provider' => 'stripe', 'mode' => 'donation', 'pricing_version' => 'donation-v1', 'currency' => 'eur',
        'minor_units' => 2, 'amount_field' => 'amount', 'min_amount_minor' => 100, 'max_amount_minor' => 50000];
    $smallCatalog = ['provider' => 'stripe', 'mode' => 'catalog', 'pricing_version' => 'catalog-v1', 'currency' => 'eur',
        'catalog' => ['product_field' => 'product', 'products' => ['one' => ['active' => true, 'quantity_field' => 'qty',
            'min_quantity' => 1, 'max_quantity' => 10, 'unit_amount_minor' => 250,
            'options' => ['size' => ['small' => ['active' => true, 'multiplier_bps' => 10000]]]]]]];
    payment_check(payment_schema_errors($fixed, $matrixFields) === [], 'fixed mode definition accepted');
    payment_check(payment_schema_errors($donation, $matrixFields) === [], 'donation mode definition accepted');
    payment_check(payment_schema_errors($smallCatalog, $matrixFields) === [], 'catalog mode definition accepted');
    foreach ([
        'missing explicit mode' => array_diff_key($fixed, ['mode' => true]),
        'legacy fixed decimal amount' => array_replace(array_diff_key($fixed, ['amount_minor' => true]), ['amount' => 12.5]),
        'amount_field in fixed mode' => array_replace($fixed, ['amount_field' => 'amount']),
        'amount_field in catalog mode' => array_replace($smallCatalog, ['amount_field' => 'amount']),
        'donation missing minimum' => array_diff_key($donation, ['min_amount_minor' => true]),
        'donation reversed range' => array_replace($donation, ['min_amount_minor' => 60000]),
        'invalid currency' => array_replace($fixed, ['currency' => 'EURO']),
        'catalog unknown product field' => array_replace_recursive($smallCatalog, ['catalog' => ['product_field' => 'missing']]),
    ] as $label => $badPayment) {
        payment_check(payment_schema_errors($badPayment, $matrixFields) !== [], "$label definition rejected");
    }

    $fixedQuote = $hasResolver ? bbfResolvePaymentQuote($fixed, []) : null;
    payment_check(($fixedQuote['amount_minor'] ?? null) === 1250 && ($fixedQuote['mode'] ?? null) === 'fixed', 'fixed mode quote uses integer minor units');
    foreach (['10.05' => 1005, '1' => 100, '1.2' => 120, '500.00' => 50000] as $decimal => $minor) {
        $donationQuote = null;
        if ($hasResolver) { try { $donationQuote = bbfResolvePaymentQuote($donation, ['amount' => $decimal]); } catch (Throwable $error) {} }
        payment_check(($donationQuote['amount_minor'] ?? null) === $minor, "donation exact decimal conversion $decimal");
    }
    foreach (['10.005', '1e2', '0.99', '500.01', '-2', ' 10.00 '] as $decimal) {
        payment_check($hasResolver && payment_throws(static fn() => bbfResolvePaymentQuote($donation, ['amount' => $decimal])),
            "donation value $decimal rejected without float coercion");
    }

    $zeroUnitDonation = array_replace($donation, ['currency' => 'jpy', 'minor_units' => 0,
        'min_amount_minor' => 1, 'max_amount_minor' => 1000]);
    $threeUnitDonation = array_replace($donation, ['currency' => 'kwd', 'minor_units' => 3,
        'min_amount_minor' => 1, 'max_amount_minor' => 100000]);
    $zeroQuote = bbfResolvePaymentQuote($zeroUnitDonation, ['amount' => '500']);
    $threeQuote = bbfResolvePaymentQuote($threeUnitDonation, ['amount' => '1.250']);
    payment_check(($zeroQuote['amount_minor'] ?? null) === 500 && ($zeroQuote['snapshot']['minor_units'] ?? null) === 0
        && bbfPaymentFormatMinor(500, 0) === '500', 'zero-decimal trusted quote and display preserve 500 JPY');
    payment_check(($threeQuote['amount_minor'] ?? null) === 1250 && ($threeQuote['snapshot']['minor_units'] ?? null) === 3
        && bbfPaymentFormatMinor(1250, 3) === '1.250', 'three-decimal trusted quote and display preserve 1.250 KWD');

    $minorForm = ['id' => 'minor-format', 'storage' => 'file', 'fields' => [], 'on_submit' => ['store' => true]];
    file_put_contents("$root/forms/minor-format.json", bbf_storage_json($minorForm));
    mkdir("$root/submissions/minor-format", 0700, true);
    $minorConfig = ['storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions"];
    foreach ([['bbf_zero', 500, 0, 500], ['bbf_three', 1250, 3, 1.25]] as [$id, $amountMinor, $units, $amount]) {
        file_put_contents("$root/submissions/minor-format/$id.json", bbf_storage_json([
            'id' => $id, 'form' => 'minor-format', 'data' => [], 'meta' => ['payment_status' => 'pending'],
        ]));
        $transition = transitionSubmissionPayment($id, 'minor-format', 'paid', [
            'id' => 'cs_' . $id, 'payment_intent' => 'pi_' . $id, 'amount_total' => $amountMinor, 'currency' => 'jpy',
        ], $minorConfig, $units);
        $storedMinor = json_decode(file_get_contents("$root/submissions/minor-format/$id.json"), true, 512, JSON_THROW_ON_ERROR);
        payment_check(($transition['ok'] ?? false) && ($storedMinor['meta']['payment_amount_minor'] ?? null) === $amountMinor
            && ($storedMinor['meta']['payment_minor_units'] ?? null) === $units
            && ($storedMinor['meta']['payment_amount'] ?? null) === $amount,
            "$units-decimal payment metadata uses the trusted exponent");
    }

    // The copied helper removes live delivery configuration. Add only a local action probe.
    $demo['on_submit']['actions'] = [['type' => 'payment-probe']];
    file_put_contents("$root/forms/demo-order.json", json_encode($demo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    file_put_contents("$root/actions/payment-probe.php", <<<'PHP'
<?php
$path = __DIR__ . '/../data/action.json';
$previous = is_file($path) ? json_decode(file_get_contents($path), true) : [];
$submission['_action_count'] = (int)($previous['_action_count'] ?? 0) + 1;
file_put_contents($path, json_encode($submission, JSON_THROW_ON_ERROR));
PHP);
    $rootLiteral = var_export(str_replace('\\', '/', $root), true);
    $configPhp = '<?php defined("BBF_LOADED") || exit; $root = ' . $rootLiteral . '; return ' . "[\n"
        . "'storage'=>'file','forms_dir'=>\$root.'/forms','submissions_dir'=>\$root.'/submissions','logs_dir'=>\$root.'/logs','templates_dir'=>\$root.'/templates',\n"
        . "'csrf'=>false,'sandbox'=>false,'honeypot_field'=>'_hp','rate_limit'=>1000,'lang'=>'en','store_ip'=>false,'store_user_agent'=>false,'error_notify'=>'',\n"
        . "'mail'=>['method'=>'mail','from_email'=>'fixture@example.test','from_name'=>'Fixture'],\n"
        . "'stripe'=>['secret_key'=>'sk_test_local_only','webhook_secret'=>'fixture-webhook-secret','transport'=>static function(array \$fields, string \$secret) use (\$root): array {\n"
        . "  \$files=glob(\$root.'/submissions/demo-order/*.json') ?: []; \$stored=\$files ? json_decode(file_get_contents(end(\$files)),true) : null;\n"
        . "  file_put_contents(\$root.'/data/checkout.json', json_encode(['fields'=>\$fields,'secret'=>\$secret,'stored'=>\$stored], JSON_THROW_ON_ERROR));\n"
        . "  return ['id'=>'cs_trusted_fixture','url'=>'https://checkout.local/session/cs_trusted_fixture'];\n"
        . "}]];";
    file_put_contents("$root/config.php", $configPhp);
    file_put_contents("$root/tests/payment-transition-worker.php", <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') exit(2);
define('BBF_LOADED', true);
$config = require $argv[1];
require $argv[2] . '/bbf_functions.php';
$result = transitionSubmissionPayment($argv[3], $argv[4], $argv[5], [
    'id' => 'cs_trusted_fixture', 'payment_intent' => 'pi_fixture',
    'amount_total' => 1125, 'currency' => 'eur',
], $config);
echo json_encode($result, JSON_THROW_ON_ERROR);
exit(($result['ok'] ?? false) ? 0 : 1);
PHP);

    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $base = 'http://127.0.0.1:' . $server['port'] . '/';
    $response = bbf_test_http($server, $base . 'submit.php?form=demo-order', $validData);
    $httpError = is_file("$root/logs/php-error.log") ? trim(file_get_contents("$root/logs/php-error.log")) : '';
    $checkout = is_file("$root/data/checkout.json") ? json_decode(file_get_contents("$root/data/checkout.json"), true) : null;
    $records = glob("$root/submissions/demo-order/*.json") ?: [];
    $recordPath = $records ? end($records) : '';
    $stored = $recordPath ? json_decode(file_get_contents($recordPath), true) : null;
    $submissionId = $stored['id'] ?? '';
    payment_check($response['code'] === 200 && ($response['json']['redirect'] ?? '') === 'https://checkout.local/session/cs_trusted_fixture',
        'submission uses local mock Checkout transport (HTTP ' . $response['code'] . ': ' . $response['body'] . '; log: ' . $httpError . ')');
    payment_check(($checkout['fields']['line_items[0][price_data][unit_amount]'] ?? null) === 1125,
        'tampered order_total cannot alter Checkout unit amount');
    $beforeCheckoutMeta = $checkout['stored']['meta'] ?? [];
    payment_check(($beforeCheckoutMeta['payment_status'] ?? null) === 'pending'
        && ($beforeCheckoutMeta['payment_expected_amount_minor'] ?? null) === 1125
        && ($beforeCheckoutMeta['payment_expected_currency'] ?? null) === 'eur',
        'trusted expected amount and currency persist before Checkout transport runs');
    payment_check(($beforeCheckoutMeta['payment_pricing_mode'] ?? null) === 'catalog'
        && ($beforeCheckoutMeta['payment_pricing_version'] ?? null) === 'demo-print-v1'
        && is_array($beforeCheckoutMeta['payment_quote'] ?? null),
        'pricing mode version and quote snapshot persist before Checkout');
    payment_check(($stored['meta']['payment_checkout_session_id'] ?? null) === 'cs_trusted_fixture',
        'trusted Checkout session identity persists before redirect');

    $baseSession = ['id' => 'cs_trusted_fixture', 'payment_intent' => 'pi_fixture', 'amount_total' => 1125,
        'currency' => 'eur', 'payment_status' => 'paid',
        'metadata' => ['bbf_submission_id' => $submissionId, 'bbf_form_id' => 'demo-order']];
    $pending = $stored;
    foreach ([
        'amount' => array_replace($baseSession, ['amount_total' => 1]),
        'currency' => array_replace($baseSession, ['currency' => 'usd']),
        'session' => array_replace($baseSession, ['id' => 'cs_attacker']),
    ] as $mismatch => $session) {
        if ($recordPath) file_put_contents($recordPath, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        @unlink("$root/data/action.json");
        $callback = payment_callback($server, $base, $session, 'fixture-webhook-secret');
        $after = $recordPath ? json_decode(file_get_contents($recordPath), true) : null;
        payment_check($callback['code'] >= 400 && $callback['code'] < 600, "$mismatch mismatch callback returns non-2xx");
        payment_check(($after['meta']['payment_status'] ?? null) === 'pending', "$mismatch mismatch leaves payment pending");
        payment_check(!is_file("$root/data/action.json"), "$mismatch mismatch runs no deferred action");
    }

    // A provider event is acknowledged only after every deferred job has a durable success checkpoint.
    $outboxPath = bbf_outbox_path(['submissions_dir' => "$root/submissions"], 'demo-order', $submissionId);
    $outbox = bbf_outbox_init($outboxPath, "demo-order:$submissionId", bbf_outbox_jobs($demo, $pending), 3);
    $runningClaim = bbf_outbox_claim($outboxPath, 'action:0');
    $runningCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.completed', 'evt_running_action');
    payment_check(($outbox['ok'] ?? false) && ($runningClaim['ok'] ?? false) && $runningCallback['code'] === 503,
        'running delivery checkpoint returns retryable HTTP 503');
    bbf_outbox_complete($outboxPath, 'action:0', $runningClaim['token'],
        ['ok' => false, 'state' => 'ambiguous', 'stage' => 'action', 'retryable' => false]);
    $ambiguousCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_ambiguous_action');
    payment_check($ambiguousCallback['code'] === 503 && !is_file("$root/data/action.json"),
        'ambiguous delivery checkpoint is not acknowledged or automatically replayed');
    $changedForm = $demo;
    unset($changedForm['on_submit']['actions']);
    file_put_contents("$root/forms/demo-order.json", json_encode($changedForm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $orphanedCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_orphaned_action');
    payment_check($orphanedCallback['code'] === 503,
        'immutable unsettled ledger job blocks acknowledgement after form definition drift');
    file_put_contents("$root/forms/demo-order.json", json_encode($demo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    @unlink($outboxPath);
    @unlink($outboxPath . '.lock');

    bbf_outbox_init($outboxPath, "demo-order:$submissionId", bbf_outbox_jobs($demo, $pending), 3);
    $failedClaim = bbf_outbox_claim($outboxPath, 'action:0');
    bbf_outbox_complete($outboxPath, 'action:0', $failedClaim['token'],
        ['ok' => false, 'state' => 'failed', 'stage' => 'action', 'retryable' => true], null, 3600);
    $failedCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_failed_action');
    payment_check($failedCallback['code'] === 503, 'failed delivery checkpoint returns HTTP 503 during backoff');
    @unlink($outboxPath);
    @unlink($outboxPath . '.lock');

    bbf_outbox_init($outboxPath, "demo-order:$submissionId", bbf_outbox_jobs($demo, $pending), 1);
    $exhaustedClaim = bbf_outbox_claim($outboxPath, 'action:0');
    bbf_outbox_complete($outboxPath, 'action:0', $exhaustedClaim['token'],
        ['ok' => false, 'state' => 'failed', 'stage' => 'action', 'retryable' => true]);
    $exhaustedCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_exhausted_action');
    payment_check($exhaustedCallback['code'] === 503, 'exhausted delivery checkpoint is not acknowledged as complete');
    @unlink($outboxPath);
    @unlink($outboxPath . '.lock');

    if ($recordPath) file_put_contents($recordPath, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    @unlink("$root/data/action.json");
    $callback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret');
    $after = $recordPath ? json_decode(file_get_contents($recordPath), true) : null;
    payment_check($callback['code'] === 200 && ($after['meta']['payment_status'] ?? null) === 'paid'
        && ($after['meta']['payment_amount_minor'] ?? null) === 1125, 'matching callback marks trusted payment paid'
        . ' (HTTP ' . $callback['code'] . ': ' . $callback['body'] . '; ledger: ' . file_get_contents($outboxPath) . ')');
    payment_check(is_file("$root/data/action.json"), 'matching callback runs deferred action after durable paid update');
    $firstAction = json_decode(file_get_contents("$root/data/action.json"), true);
    $replay = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret');
    $afterReplay = json_decode(file_get_contents("$root/data/action.json"), true);
    payment_check($replay['code'] === 200 && ($afterReplay['_action_count'] ?? 0) === 1,
        'same payment event replay skips completed action checkpoint');
    $semanticReplay = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_second_success');
    $afterSemanticReplay = json_decode(file_get_contents("$root/data/action.json"), true);
    payment_check($semanticReplay['code'] === 200 && ($afterSemanticReplay['_action_count'] ?? 0) === 1,
        'different success event for same Checkout session skips completed action');
    $lateFailure = payment_callback($server, $base, array_replace($baseSession, ['payment_status' => 'unpaid']),
        'fixture-webhook-secret', 'checkout.session.async_payment_failed', 'evt_late_failure');
    $afterFailure = $recordPath ? json_decode(file_get_contents($recordPath), true) : null;
    payment_check($lateFailure['code'] === 200 && ($afterFailure['meta']['payment_status'] ?? null) === 'paid',
        'late asynchronous failure cannot downgrade paid state');

    if ($recordPath) file_put_contents($recordPath, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $fileRace = payment_concurrent_transitions($root, "$root/config.php", $submissionId, 'demo-order');
    $fileAfterRace = $recordPath ? json_decode(file_get_contents($recordPath), true) : null;
    payment_check($fileRace === ['started' => 16, 'ok' => 16]
        && ($fileAfterRace['meta']['payment_status'] ?? null) === 'paid',
        'concurrent file paid/failed transitions are serialized and paid remains terminal');

    $sqliteForm = 'payment-sqlite';
    $sqliteId = 'bbf_sqlite_payment';
    $sqlitePath = "$root/data/payment.sqlite";
    file_put_contents("$root/forms/$sqliteForm.json", json_encode(['storage' => 'sqlite'], JSON_THROW_ON_ERROR));
    $sqliteConfig = [
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'sqlite' => ['path' => $sqlitePath],
    ];
    file_put_contents("$root/tests/payment-sqlite-config.php",
        '<?php defined("BBF_LOADED") || exit; return ' . var_export($sqliteConfig, true) . ';');
    $sqlite = new PDO("sqlite:$sqlitePath", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->exec("CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, data TEXT NOT NULL, meta TEXT, created_at TEXT)");
    $sqliteInsert = $sqlite->prepare('INSERT INTO bbf_submissions (id, form_id, data, meta, created_at) VALUES (?, ?, ?, ?, ?)');
    $sqliteInsert->execute([$sqliteId, $sqliteForm, '{}', json_encode($pending['meta'], JSON_THROW_ON_ERROR), date('c')]);
    $sqliteInsert = null;
    $sqlite = null;
    $sqliteRace = payment_concurrent_transitions($root, "$root/tests/payment-sqlite-config.php", $sqliteId, $sqliteForm);
    $sqlite = new PDO("sqlite:$sqlitePath");
    $sqliteStatement = $sqlite->query("SELECT meta FROM bbf_submissions WHERE id = '$sqliteId'");
    $sqliteMeta = json_decode((string)$sqliteStatement->fetchColumn(), true);
    payment_check($sqliteRace === ['started' => 16, 'ok' => 16]
        && ($sqliteMeta['payment_status'] ?? null) === 'paid',
        'concurrent SQLite paid/failed transitions lock the row state and paid remains terminal');
    $sqliteStatement = null;
    $sqlite = null;

    print "Payment regression: $checks checks, $failures failures.\n";
} finally {
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
exit($failures === 0 ? 0 : 1);
