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
    payment_check(payment_schema_errors(array_diff_key($fixed, ['provider' => true]), $matrixFields) === [],
        'omitted provider preserves the historical Stripe default');
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
        'unsupported lowercase currency' => array_replace($fixed, ['currency' => 'xxx']),
        'catalog unknown product field' => array_replace_recursive($smallCatalog, ['catalog' => ['product_field' => 'missing']]),
    ] as $label => $badPayment) {
        payment_check(payment_schema_errors($badPayment, $matrixFields) !== [], "$label definition rejected");
    }

    $fixedQuote = $hasResolver ? bbfResolvePaymentQuote($fixed, []) : null;
    payment_check(($fixedQuote['amount_minor'] ?? null) === 1250 && ($fixedQuote['mode'] ?? null) === 'fixed', 'fixed mode quote uses integer minor units');
    $fixedJpy = bbfResolvePaymentQuote(array_replace($fixed, ['currency' => 'jpy', 'amount_minor' => 1000]), []);
    $catalogKwd = bbfResolvePaymentQuote(array_replace($smallCatalog, ['currency' => 'kwd']),
        ['product' => 'one', 'qty' => '1', 'size' => 'small']);
    payment_check($fixedJpy['minor_units'] === 0 && bbfPaymentFormatMinor($fixedJpy['amount_minor'], $fixedJpy['minor_units']) === '1000',
        'fixed JPY quote uses the verified zero-decimal exponent');
    payment_check($catalogKwd['minor_units'] === 3 && bbfPaymentFormatMinor($catalogKwd['amount_minor'], $catalogKwd['minor_units']) === '0.250',
        'catalog KWD quote uses the verified three-decimal exponent');
    $fixedMga = bbfResolvePaymentQuote(array_replace($fixed, ['currency' => 'mga', 'amount_minor' => 10]), []);
    $fixedUgx = bbfResolvePaymentQuote(array_replace($fixed, ['currency' => 'ugx', 'amount_minor' => 500]), []);
    payment_check($fixedMga['minor_units'] === 0 && bbfPaymentFormatMinor(10, 0) === '10',
        'fixed MGA quote uses the verified zero-decimal exponent');
    payment_check($fixedUgx['minor_units'] === 2 && bbfPaymentFormatMinor(500, 2) === '5.00',
        'fixed UGX quote preserves the Stripe two-decimal compatibility representation');
    payment_check(payment_schema_errors(array_replace($fixed, ['currency' => 'ugx', 'amount_minor' => 501]), $matrixFields) !== []
        && payment_throws(static fn() => bbfResolvePaymentQuote(array_replace($fixed, ['currency' => 'isk', 'amount_minor' => 501]), [])),
        'UGX and ISK fixed charges reject fractional whole-unit amounts');
    payment_check(payment_schema_errors(array_replace($donation, ['currency' => 'jpy']), $matrixFields) !== [],
        'explicit donation minor units must match the currency exponent');
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
    $ugxDonation = array_replace($donation, ['currency' => 'ugx', 'minor_units' => 2]);
    $ugxDonationQuote = bbfResolvePaymentQuote($ugxDonation, ['amount' => '5.00']);
    payment_check($ugxDonationQuote['amount_minor'] === 500
        && payment_throws(static fn() => bbfResolvePaymentQuote($ugxDonation, ['amount' => '5.01'])),
        'UGX donation accepts whole units and rejects fractional charge amounts');
    $ugxCatalog = $smallCatalog;
    $ugxCatalog['currency'] = 'ugx';
    $ugxCatalog['catalog']['products']['one']['unit_amount_minor'] = 500;
    $ugxCatalogQuote = bbfResolvePaymentQuote($ugxCatalog, ['product' => 'one', 'qty' => '1', 'size' => 'small']);
    $ugxCatalog['catalog']['products']['one']['unit_amount_minor'] = 501;
    payment_check($ugxCatalogQuote['amount_minor'] === 500
        && payment_throws(static fn() => bbfResolvePaymentQuote($ugxCatalog, ['product' => 'one', 'qty' => '1', 'size' => 'small'])),
        'UGX catalog accepts whole units and rejects fractional calculated amounts');

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
    $fixtureConfig = require "$root/config.php";
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

    $finalizePlanPath = bbf_outbox_path(['submissions_dir' => "$root/submissions"], 'finalize', 'bbf_finalize');
    file_put_contents("$root/templates/payment-finalize.html",
        '{{_payment_status}}|{{_payment_id}}|{{_payment_amount}}|{{_payment_currency}}');
    $finalizeSubmission = ['id' => 'bbf_finalize', 'form' => 'finalize', 'data' => ['answer' => 'fixture'],
        'meta' => ['payment_status' => 'pending']];
    $finalizeForm = ['id' => 'finalize', 'fields' => [], 'on_submit' => [
        'confirm_email' => ['to' => 'fixture@example.test', 'template' => 'payment-finalize.html'],
        'webhooks' => ['https://webhook.invalid/fixture'], 'actions' => [['type' => 'payment-probe']],
    ]];
    $finalizeBindings = bbf_delivery_payment_template_bindings();
    $finalizeJobs = bbf_delivery_prepare_jobs($finalizeForm, $finalizeSubmission, $fixtureConfig,
        $finalizeBindings + ['_bbf_payment_bindings' => $finalizeBindings]);
    bbf_outbox_init($finalizePlanPath, 'finalize:bbf_finalize', $finalizeJobs, 3, 10);
    $finalizeSubmission['meta'] = ['payment_status' => 'paid', 'payment_id' => 'pi_finalize',
        'payment_amount_minor' => 1250, 'payment_minor_units' => 2, 'payment_currency' => 'eur'];
    $finalizeResult = bbf_delivery_finalize_submission($finalizePlanPath, $finalizeSubmission, 11);
    $finalizedJobs = bbf_outbox_read($finalizePlanPath)['ledger']['jobs'] ?? [];
    $finalizedPayloads = array_column(array_values($finalizedJobs), 'payload');
    payment_check(($finalizeResult['ok'] ?? false) && count($finalizedPayloads) === 3
        && count(array_filter($finalizedPayloads, static fn(array $payload): bool =>
            ($payload['submission']['meta'] ?? null) === $finalizeSubmission['meta'])) === 2
        && ($finalizedJobs['confirm']['payload']['body'] ?? null) === 'paid|pi_finalize|12.50|EUR'
        && !isset($finalizedJobs['confirm']['payload']['_payment_bindings'])
        && count(array_filter($finalizedJobs, static fn(array $job): bool =>
            hash_equals($job['payload_hash'], bbf_delivery_payload_hash($job['payload'])))) === 3,
        '6129-F02 paid finalization atomically refreshes email webhook and action payloads and hashes');
    $legacyPlanPath = bbf_outbox_path(['submissions_dir' => "$root/submissions"], 'legacy-finalize', 'bbf_legacy');
    $legacyPending = ['id' => 'bbf_legacy', 'form' => 'legacy-finalize', 'data' => [], 'meta' => ['payment_status' => 'pending']];
    $legacyForm = ['id' => 'legacy-finalize', 'fields' => [], 'on_submit' => [
        'confirm_email' => ['to' => 'fixture@example.test', 'template' => 'payment-finalize.html'],
    ]];
    $legacyJobs = bbf_delivery_prepare_jobs($legacyForm, $legacyPending, $fixtureConfig, [
        '_payment_status' => 'paid', '_payment_id' => 'cs_legacy',
        '_payment_amount' => '12.50', '_payment_currency' => 'EUR',
    ]);
    bbf_outbox_init($legacyPlanPath, 'legacy-finalize:bbf_legacy', $legacyJobs, 3, 10);
    $legacyPaid = $legacyPending;
    $legacyPaid['meta'] = ['payment_status' => 'paid', 'payment_checkout_session_id' => 'cs_legacy',
        'payment_id' => 'pi_legacy', 'payment_amount_minor' => 1250, 'payment_minor_units' => 2,
        'payment_currency' => 'eur'];
    $legacyFinalized = bbf_delivery_finalize_submission($legacyPlanPath, $legacyPaid, 11);
    $legacyFinalizedJob = bbf_outbox_read($legacyPlanPath)['ledger']['jobs']['confirm'] ?? [];
    payment_check(($legacyFinalized['ok'] ?? false)
        && ($legacyFinalizedJob['payload']['body'] ?? null) === 'paid|pi_legacy|12.50|EUR'
        && hash_equals($legacyFinalizedJob['payload_hash'], bbf_delivery_payload_hash($legacyFinalizedJob['payload'])),
        '6129-F02 pre-upgrade pending email replaces the persisted Checkout ID before delivery');

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
    $outboxPath = bbf_outbox_path(['submissions_dir' => "$root/submissions"], 'demo-order', $submissionId);
    $submittedOutboxRaw = is_file($outboxPath) ? file_get_contents($outboxPath) : '';
    $submittedLedger = $submittedOutboxRaw !== ''
        ? json_decode($submittedOutboxRaw, true, 512, JSON_THROW_ON_ERROR)
        : null;
    payment_check(is_array($submittedLedger) && ($submittedLedger['context'] ?? null) === ['storage' => 'file']
        && array_keys($submittedLedger['jobs'] ?? []) === ['action:0'],
        'real payment submit persists its complete immutable delivery plan and original backend before redirect');
    $finalizeFaultPath = "$root/submissions/.delivery/demo-order/finalize-fault.json";
    file_put_contents($finalizeFaultPath, $submittedOutboxRaw);
    $finalizeFaultBefore = file_get_contents($finalizeFaultPath);
    $paidFixture = $stored;
    $paidFixture['meta']['payment_status'] = 'paid';
    $paidFixture['meta']['payment_id'] = 'pi_fixture';
    $GLOBALS['_bbf_outbox_write'] = static fn() => false;
    $finalizeFault = bbf_delivery_finalize_submission($finalizeFaultPath, $paidFixture);
    unset($GLOBALS['_bbf_outbox_write']);
    payment_check(($finalizeFault['reason'] ?? null) === 'persist'
        && file_get_contents($finalizeFaultPath) === $finalizeFaultBefore,
        '6129-F02 paid payload persistence failure preserves the exact pending plan before any effect');
    $tamperedPlanPath = "$root/submissions/.delivery/demo-order/finalize-tampered.json";
    $tamperedPlan = $submittedLedger;
    $tamperedPlan['jobs']['action:0']['payload']['action_type'] = 'tampered-action';
    file_put_contents($tamperedPlanPath, bbf_storage_json($tamperedPlan));
    $tamperedPlanBytes = file_get_contents($tamperedPlanPath);
    $tamperedFinalize = bbf_delivery_finalize_submission($tamperedPlanPath, $paidFixture);
    payment_check(($tamperedFinalize['reason'] ?? null) === 'payload'
        && file_get_contents($tamperedPlanPath) === $tamperedPlanBytes,
        '6129-F02 tampered pending payload cannot be legitimized during paid finalization');

    $baseSession = ['id' => 'cs_trusted_fixture', 'payment_intent' => 'pi_fixture', 'amount_total' => 1125,
        'currency' => 'eur', 'payment_status' => 'paid',
        'metadata' => ['bbf_submission_id' => $submissionId, 'bbf_form_id' => 'demo-order']];
    $pending = $stored;
    $signaturePayload = json_encode(['id' => 'evt_signature_rotation', 'type' => 'customer.created', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
    $signatureTime = time();
    $validSignature = hash_hmac('sha256', $signatureTime . '.' . $signaturePayload, 'fixture-webhook-secret');
    foreach ([
        "t=$signatureTime,v1=$validSignature,v1=" . str_repeat('0', 64),
        "t=$signatureTime,v1=" . str_repeat('0', 64) . ",v1=$validSignature",
    ] as $position => $signatureHeader) {
        $signed = bbf_test_http($server, $base . 'payment.php', null, ['raw' => $signaturePayload,
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => $signatureHeader]]);
        payment_check($signed['code'] === 200, "Stripe rotation accepts valid v1 at position $position");
    }
    $invalidSignatureHeader = "t=$signatureTime,v1=" . str_repeat('0', 64) . ',v1=' . str_repeat('1', 64);
    $invalidSigned = bbf_test_http($server, $base . 'payment.php', null, ['raw' => $signaturePayload,
        'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => $invalidSignatureHeader]]);
    payment_check($invalidSigned['code'] === 400, 'Stripe rejects multiple invalid v1 signatures');
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

    // The actual submit-created plan must survive definition and routing drift before the callback.
    file_put_contents("$root/forms/demo-order.json", '{"id":');
    @unlink("$root/data/action.json");
    $submitPlanCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_submit_plan_drift');
    payment_check($submitPlanCallback['code'] === 200 && is_file("$root/data/action.json")
        && (bbf_outbox_settlement($outboxPath)['settled'] ?? false),
        'real submit plan settles after the current form becomes malformed without manual ledger seeding');
    $paidPlanAction = is_file("$root/data/action.json")
        ? json_decode(file_get_contents("$root/data/action.json"), true) : null;
    payment_check(($paidPlanAction['meta']['payment_status'] ?? null) === 'paid'
        && ($paidPlanAction['meta']['payment_id'] ?? null) === 'pi_fixture',
        '6129-F02 paid callback delivers the durable paid status and payment identity from the finalized plan');
    file_put_contents("$root/forms/demo-order.json", json_encode($demo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($recordPath) file_put_contents($recordPath, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    file_put_contents($outboxPath, $submittedOutboxRaw);
    @unlink("$root/data/action.json");

    // A provider event is acknowledged only after every deferred job has a durable success checkpoint.
    $outbox = bbf_outbox_read($outboxPath);
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
    @unlink($outboxPath);
    @unlink($outboxPath . '.lock');
    @unlink("$root/data/action.json");
    file_put_contents($outboxPath, $submittedOutboxRaw);
    file_put_contents("$root/forms/demo-order.json", '{"id":');
    $duplicateDbPath = "$root/submissions/bbf.sqlite";
    $contextLedger = json_decode(file_get_contents($outboxPath), true, 512, JSON_THROW_ON_ERROR);
    $contextLedger['context'] = ['unexpected' => 'file'];
    file_put_contents($outboxPath, bbf_storage_json($contextLedger));
    $invalidContextCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_invalid_context');
    payment_check($invalidContextCallback['code'] === 500 && !is_file("$root/data/action.json"),
        'invalid ledger context fails closed without legacy backend probing');
    $contextLedger['context'] = ['storage' => 'sqlite'];
    file_put_contents($outboxPath, bbf_storage_json($contextLedger));
    $missingSqliteCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_missing_sqlite');
    payment_check($missingSqliteCallback['code'] === 500 && !is_file($duplicateDbPath),
        'context-directed SQLite lookup does not create a missing database');
    unset($contextLedger['context']);
    file_put_contents($outboxPath, bbf_storage_json($contextLedger));
    $duplicateDb = new PDO('sqlite:' . $duplicateDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $duplicateDb->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, data TEXT NOT NULL, meta TEXT NOT NULL)');
    $duplicateInsert = $duplicateDb->prepare('INSERT INTO bbf_submissions (id, form_id, data, meta) VALUES (?, ?, ?, ?)');
    $duplicateInsert->execute([$submissionId, 'demo-order', bbf_storage_json($pending['data']), bbf_storage_json($pending['meta'])]);
    $ambiguousBackendCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_ambiguous_backend');
    payment_check($ambiguousBackendCallback['code'] === 500 && !is_file("$root/data/action.json"),
        'context-free ledger fails closed when the submission exists in multiple backends');
    $duplicateDb->exec('DELETE FROM bbf_submissions');
    $duplicateInsert = null;
    $duplicateDb = null;
    $persistedPlanCallback = payment_callback($server, $base, $baseSession, 'fixture-webhook-secret',
        'checkout.session.async_payment_succeeded', 'evt_persisted_action');
    $persistedPlanOk = $persistedPlanCallback['code'] === 200 && is_file("$root/data/action.json")
        && (bbf_outbox_settlement($outboxPath)['settled'] ?? false);
    payment_check($persistedPlanOk,
        'callback recovers and settles a context-free immutable plan after current form becomes malformed'
        . ($persistedPlanOk ? '' : ' (HTTP ' . $persistedPlanCallback['code'] . ': ' . $persistedPlanCallback['body']
        . '; action: ' . (is_file("$root/data/action.json") ? file_get_contents("$root/data/action.json") : 'missing')
        . '; ledger: ' . (is_file($outboxPath) ? file_get_contents($outboxPath) : 'missing') . ')'));
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
    $freshLedger = json_decode(file_get_contents($outboxPath), true, 512, JSON_THROW_ON_ERROR);
    payment_check(($freshLedger['context'] ?? null) === ['storage' => 'file'],
        'new payment ledger persists only its effective storage backend context');
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
