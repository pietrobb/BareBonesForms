<?php
/** Mission #41 durable submit delivery. CLI-only, disposable local installation, no live services. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
if (getenv('BBF_TEST_LEASE') || getenv('BBF_TEST_LEASE_KEY')) throw new RuntimeException('Requires a fresh owned fixture.');

$checks = 0;
$failures = 0;
function submit_delivery_check(bool $ok, string $label): void {
    ++$GLOBALS['checks'];
    if ($ok) { print "PASS $label\n"; return; }
    ++$GLOBALS['failures'];
    print "FAIL $label\n";
}
function submit_delivery_config(string $root, bool $outboxFault = false): void {
    $config = [
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'templates_dir' => "$root/templates", 'csrf' => false,
        'sandbox' => false, 'honeypot_field' => '_hp', 'rate_limit' => 1000, 'lang' => 'en',
        'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
        'mail' => ['method' => 'smtp', 'from_email' => 'fixture@example.test', 'from_name' => 'Fixture',
            'smtp_host' => 'never-used.invalid', 'smtp_port' => 587, 'smtp_user' => 'smtp-user',
            'smtp_pass' => 'smtp-secret', 'smtp_enc' => 'tls'],
        'webhook_secret' => 'webhook-secret',
        'delivery' => ['max_attempts' => 3, 'lease_seconds' => 30, 'retry_delay' => 30],
        'stripe' => ['secret_key' => '', 'webhook_secret' => ''],
    ];
    $prefix = '<?php defined("BBF_LOADED") || exit; ';
    if ($outboxFault) $prefix .= '$GLOBALS["_bbf_outbox_write"] = static fn() => false; ';
    file_put_contents("$root/config.php", $prefix . 'return ' . var_export($config, true) . ';');
}

$source = dirname(__DIR__);
$root = bbf_test_installation($source);
$server = null;
try {
    file_put_contents("$root/templates/delivery-fixture.html",
        '<h1>{{_form}}</h1><p>{{choice_label}}</p><aside>{{_respondent_note}}</aside>'
        . '<div>{{_summary}}</div><b>{{_payment_status}}</b>');
    file_put_contents("$root/actions/delivery-probe.php", <<<'PHP'
<?php
$counterRoot = $action['counter_root'] ?? $config['submissions_dir'];
$counter = rtrim($counterRoot, '/\\') . '/' . ($action['counter'] ?? 'effects.json');
if (($action['fail'] ?? false) === true) throw new RuntimeException('Fixture action failure.');
$state = is_file($counter) ? json_decode(file_get_contents($counter), true) : [];
$ledgerPath = $config['submissions_dir'] . '/.delivery/' . $submission['form'] . '/' . $submission['id'] . '.json';
$ledger = is_file($ledgerPath) ? json_decode(file_get_contents($ledgerPath), true) : null;
if (!isset($state['first_ledger_jobs'])) {
    $state['first_ledger_jobs'] = is_array($ledger['jobs'] ?? null) ? count($ledger['jobs']) : -1;
    $state['first_states'] = is_array($ledger['jobs'] ?? null) ? array_column($ledger['jobs'], 'state', 'key') : [];
    $state['all_payloads'] = is_array($ledger['jobs'] ?? null)
        && count(array_filter($ledger['jobs'], static fn($job) => is_array($job['payload'] ?? null))) === count($ledger['jobs']);
}
$state['count'] = (int)($state['count'] ?? 0) + 1;
$state['submission'] = $submission;
$state['action'] = $action;
file_put_contents($counter, json_encode($state, JSON_THROW_ON_ERROR));
$actionResponse['custom_action'] = $action['response'] ?? 'preserved';
$actionResponse['redirect'] = '/action-wins';
PHP);

    define('BBF_LOADED', true);
    require "$root/bbf_functions.php";

    $submission = [
        'id' => 'bbf_immutable', 'form' => 'delivery-fixture',
        'data' => [
            'email' => 'person@example.test', 'choice' => 'b',
            '_respondent_note' => '<a href="javascript:alert(1)" onclick="alert(2)">hostile note</a>',
        ],
        'meta' => ['submitted' => '2026-10-11T12:13:14+00:00'],
    ];
    $form = [
        'id' => 'delivery-fixture', 'name' => 'Fixture Form',
        'fields' => [['name' => 'group', 'type' => 'group', 'fields' => [[
            'name' => 'choice', 'label' => 'Choice', 'type' => 'select',
            'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']],
        ]]]],
        'on_submit' => [
            'confirm_email' => ['to' => '{{email}}', 'subject' => 'Confirm {{choice}}',
                'reply_to' => 'reply@example.test', 'template' => 'delivery-fixture.html'],
            'notify' => ['to' => ['owner@example.test', '{{email}}'], 'subject' => 'Notify {{choice}}',
                'template' => 'delivery-fixture.html'],
            'webhooks' => ['https://hooks.example.test/original'],
            'actions' => [['type' => 'delivery-probe', 'counter' => 'direct-effects.json',
                'response' => 'direct-preserved', 'endpoint' => 'original-endpoint', 'publicKey' => 'original-public-key',
                'Api_Token' => 'original-action-token', 'apiKey' => 'original-api-key',
                'Authorization' => 'original-authorization',
                'nested' => [
                    'label' => 'original-label', 'dbPassword' => 'original-password',
                    'clientSecret' => 'original-client-secret', 'PRIVATE-Key' => 'original-private-key',
                    'access_key' => 'original-access-key', 'bEaReR-value' => 'original-bearer',
                    'service-CREDENTIAL' => 'original-credential',
                ], 'idempotent' => true]],
        ],
    ];
    $config = [
        'forms_dir' => "$root/forms", 'templates_dir' => "$root/templates", 'submissions_dir' => "$root/submissions",
        'mail' => ['method' => 'smtp', 'smtp_pass' => 'smtp-secret', 'from_email' => 'fixture@example.test', 'from_name' => 'Fixture'],
        'webhook_secret' => 'webhook-secret', 'private_config_secret' => 'never-persist-this',
        'delivery' => ['max_attempts' => 3, 'retry_delay' => 30],
    ];
    file_put_contents("$root/forms/delivery-fixture.json", json_encode($form, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $jobs = bbf_delivery_prepare_jobs($form, $submission, $config, ['_payment_status' => 'fixture-paid']);
    $byKey = array_column($jobs, null, 'key');
    submit_delivery_check(array_keys($byKey) === ['confirm', 'notify', 'webhook:0', 'action:0'],
        'preparation returns the complete stable job plan');
    submit_delivery_check(str_contains($byKey['confirm']['payload']['body'], 'Beta')
        && $byKey['confirm']['payload']['reply_to'] === 'reply@example.test'
        && $byKey['notify']['payload']['to'] === 'owner@example.test, person@example.test',
        'rendered recipients, reply-to, body, and nested option labels are immutable payload data');
    $confirmBody = $byKey['confirm']['payload']['body'];
    $escapedRespondentNote = htmlspecialchars(
        $submission['data']['_respondent_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    submit_delivery_check(str_contains($confirmBody, '<aside>' . $escapedRespondentNote . '</aside>')
        && !str_contains($confirmBody, '<aside><a href='),
        'underscore-prefixed respondent template values are HTML escaped');
    submit_delivery_check(str_contains($confirmBody, "<div><table style='border-collapse:collapse'>")
        && str_contains($confirmBody, '</table></div>'),
        'allowlisted internal summary markup remains trusted HTML');
    $serializedJobs = json_encode($jobs, JSON_THROW_ON_ERROR);
    submit_delivery_check(!str_contains($serializedJobs, 'smtp-secret')
        && !str_contains($serializedJobs, 'webhook-secret') && !str_contains($serializedJobs, 'never-persist-this'),
        'prepared descriptors persist no runtime configuration secrets');
    $originalActionSecrets = [
        'original-action-token', 'original-api-key', 'original-authorization', 'original-password',
        'original-client-secret', 'original-private-key', 'original-access-key', 'original-bearer',
        'original-credential',
    ];
    $planOmitsActionSecrets = true;
    foreach ($originalActionSecrets as $secretValue) {
        $planOmitsActionSecrets = $planOmitsActionSecrets && !str_contains($serializedJobs, $secretValue);
    }
    $expectedSensitivePaths = [
        ['Api_Token'], ['apiKey'], ['Authorization'], ['nested', 'dbPassword'],
        ['nested', 'clientSecret'], ['nested', 'PRIVATE-Key'], ['nested', 'access_key'],
        ['nested', 'bEaReR-value'], ['nested', 'service-CREDENTIAL'],
    ];
    submit_delivery_check($planOmitsActionSecrets
        && ($byKey['action:0']['payload']['action_sensitive_paths'] ?? []) === $expectedSensitivePaths,
        'normalized camel-case and separated secret keys are omitted from the prepared action plan');
    submit_delivery_check(($byKey['action:0']['payload']['action']['endpoint'] ?? '') === 'original-endpoint'
        && ($byKey['action:0']['payload']['action']['publicKey'] ?? '') === 'original-public-key'
        && ($byKey['action:0']['payload']['action']['nested']['label'] ?? '') === 'original-label',
        'prepared action plan preserves immutable non-secret configuration');
    $hashesValid = true;
    foreach ($jobs as $job) $hashesValid = $hashesValid
        && hash_equals($job['payload_hash'], bbf_delivery_payload_hash($job['payload']))
        && $job['idempotency_key'] === 'delivery-fixture:bbf_immutable:' . $job['key'];
    submit_delivery_check($hashesValid, 'every descriptor has an exact payload hash and stable idempotency key');

    $observePath = bbf_outbox_path($config, 'delivery-fixture', 'bbf_observe');
    $observeSubmission = array_replace($submission, ['id' => 'bbf_observe']);
    $observeJobs = bbf_delivery_prepare_jobs($form, $observeSubmission, $config);
    bbf_outbox_init($observePath, 'delivery-fixture:bbf_observe', $observeJobs, 3);
    $observed = [];
    $GLOBALS['_bbf_delivery_effect'] = static function(array $job, array $payload) use (&$observed, $observePath): array {
        $ledger = json_decode(file_get_contents($observePath), true);
        $observed = ['count' => count($ledger['jobs'] ?? []), 'state' => $ledger['jobs'][$job['key']]['state'] ?? '',
            'payload' => $payload, 'token' => $ledger['jobs'][$job['key']]['lease_token'] ?? null];
        return bbf_delivery_result(true, 'succeeded', $job['type'] === 'action' ? 'action' : 'delivery');
    };
    $observedRun = bbf_delivery_run_job($observePath, 'confirm', $config);
    unset($GLOBALS['_bbf_delivery_effect']);
    submit_delivery_check($observedRun['ok'] && $observed['count'] === 4 && $observed['state'] === 'running'
        && is_string($observed['token']) && $observed['payload'] === $observeJobs[0]['payload'],
        'fixture hook runs only after a durable claim and observes the full ledger');

    $mixedPath = bbf_outbox_path($config, 'delivery-fixture', $submission['id']);
    $mixedJobs = [$byKey['webhook:0'], $byKey['action:0']];
    bbf_outbox_init($mixedPath, 'delivery-fixture:bbf_immutable', $mixedJobs, 3);
    $form['on_submit']['webhooks'][0] = 'https://hooks.example.test/drifted';
    $form['on_submit']['actions'][0]['endpoint'] = 'drifted-endpoint';
    $form['on_submit']['actions'][0]['publicKey'] = 'drifted-public-key';
    $form['on_submit']['actions'][0]['Api_Token'] = 'rotated-action-token';
    $form['on_submit']['actions'][0]['apiKey'] = 'rotated-api-key';
    $form['on_submit']['actions'][0]['Authorization'] = 'rotated-authorization';
    $form['on_submit']['actions'][0]['nested']['label'] = 'drifted-label';
    $form['on_submit']['actions'][0]['nested']['dbPassword'] = 'rotated-password';
    $form['on_submit']['actions'][0]['nested']['clientSecret'] = 'rotated-client-secret';
    $form['on_submit']['actions'][0]['nested']['PRIVATE-Key'] = 'rotated-private-key';
    $form['on_submit']['actions'][0]['nested']['access_key'] = 'rotated-access-key';
    $form['on_submit']['actions'][0]['nested']['bEaReR-value'] = 'rotated-bearer';
    $form['on_submit']['actions'][0]['nested']['service-CREDENTIAL'] = 'rotated-credential';
    file_put_contents("$root/forms/delivery-fixture.json", json_encode($form, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $submission['data']['choice'] = 'a';
    $webhookCalls = [];
    $config['delivery']['webhook_resolver'] = static fn(string $host): array => ['93.184.216.34'];
    $config['delivery']['webhook_transport'] = static function(string $url, string $json, array $headers) use (&$webhookCalls): array {
        $webhookCalls[] = compact('url', 'json', 'headers');
        return ['status' => count($webhookCalls) === 1 ? 503 : 204];
    };
    $actionResponse = [];
    $actionRun = bbf_delivery_run_job($mixedPath, 'action:0', $config, $actionResponse);
    $firstWebhook = bbf_delivery_run_job($mixedPath, 'webhook:0', $config, $actionResponse);
    $backoffWebhook = bbf_delivery_run_job($mixedPath, 'webhook:0', $config, $actionResponse);
    submit_delivery_check($actionRun['ok'] && $actionResponse['custom_action'] === 'direct-preserved'
        && $actionResponse['redirect'] === '/action-wins', 'custom action response is preserved through the shared runner');
    submit_delivery_check(!$firstWebhook['ok'] && !$backoffWebhook['ok'] && !$backoffWebhook['executed']
        && $backoffWebhook['reason'] === 'backoff' && count($webhookCalls) === 1,
        'retryable webhook failure enters backoff without an early duplicate effect');
    $failedLedger = bbf_outbox_read($mixedPath)['ledger'];
    $nextRetry = $failedLedger['jobs']['webhook:0']['next_retry'];
    $failedAt = $failedLedger['jobs']['webhook:0']['last_result']['at'];
    submit_delivery_check($nextRetry === $failedAt + 30 && $failedLedger['jobs']['webhook:0']['attempts'] === 1,
        'configured backoff is persisted exactly after the first attempt');
    bbf_outbox_retry($mixedPath, 'webhook:0');
    $secondWebhook = bbf_delivery_run_job($mixedPath, 'webhook:0', $config, $actionResponse, true);
    $duplicateActionResponse = [];
    $duplicateAction = bbf_delivery_run_job($mixedPath, 'action:0', $config, $duplicateActionResponse);
    $directEffects = json_decode(file_get_contents("$root/submissions/direct-effects.json"), true);
    submit_delivery_check($secondWebhook['ok'] && count($webhookCalls) === 2
        && $webhookCalls[0]['url'] === 'https://hooks.example.test/original'
        && json_decode($webhookCalls[0]['json'], true) === $byKey['webhook:0']['payload']['submission'],
        'retry executes the originally persisted webhook URL and exact submission despite form drift');
    $idempotencyHeader = 'X-BBF-Idempotency-Key: delivery-fixture:bbf_immutable:webhook:0';
    submit_delivery_check(in_array($idempotencyHeader, $webhookCalls[0]['headers'], true)
        && in_array($idempotencyHeader, $webhookCalls[1]['headers'], true),
        'webhook retries reuse the stable persisted idempotency key');
    submit_delivery_check($duplicateAction['ok'] && !$duplicateAction['executed']
        && ($directEffects['count'] ?? 0) === 1 && $duplicateActionResponse === [],
        'successful action is not duplicated while another job is retried');
    submit_delivery_check(($directEffects['action']['Api_Token'] ?? '') === 'rotated-action-token'
        && ($directEffects['action']['apiKey'] ?? '') === 'rotated-api-key'
        && ($directEffects['action']['Authorization'] ?? '') === 'rotated-authorization'
        && ($directEffects['action']['nested']['dbPassword'] ?? '') === 'rotated-password'
        && ($directEffects['action']['nested']['clientSecret'] ?? '') === 'rotated-client-secret'
        && ($directEffects['action']['nested']['PRIVATE-Key'] ?? '') === 'rotated-private-key'
        && ($directEffects['action']['nested']['access_key'] ?? '') === 'rotated-access-key'
        && ($directEffects['action']['nested']['bEaReR-value'] ?? '') === 'rotated-bearer'
        && ($directEffects['action']['nested']['service-CREDENTIAL'] ?? '') === 'rotated-credential'
        && ($directEffects['action']['endpoint'] ?? '') === 'original-endpoint'
        && ($directEffects['action']['publicKey'] ?? '') === 'original-public-key'
        && ($directEffects['action']['nested']['label'] ?? '') === 'original-label',
        'action retry resolves normalized nested secrets while preserving immutable non-secret configuration');
    $rotatedActionSecrets = [
        'rotated-action-token', 'rotated-api-key', 'rotated-authorization', 'rotated-password',
        'rotated-client-secret', 'rotated-private-key', 'rotated-access-key', 'rotated-bearer',
        'rotated-credential',
    ];
    $ledgerOmitsActionSecrets = true;
    $mixedLedgerJson = file_get_contents($mixedPath);
    foreach (array_merge($originalActionSecrets, $rotatedActionSecrets) as $secretValue) {
        $ledgerOmitsActionSecrets = $ledgerOmitsActionSecrets && !str_contains($mixedLedgerJson, $secretValue);
    }
    submit_delivery_check($ledgerOmitsActionSecrets,
        'delivery ledger persists neither planned nor runtime-resolved action secret values');
    $safeMixed = bbf_outbox_status($mixedPath);
    $safeJson = json_encode($safeMixed, JSON_THROW_ON_ERROR);
    submit_delivery_check($safeMixed['settled'] && !str_contains($safeJson, 'person@example.test')
        && !str_contains($safeJson, 'hooks.example.test') && !str_contains($safeJson, 'idempotency'),
        'delivery status recursively redacts immutable payload and routing details');

    $capPath = bbf_outbox_path($config, 'delivery-fixture', 'bbf_cap');
    $capSubmission = array_replace($byKey['webhook:0']['payload']['submission'], ['id' => 'bbf_cap']);
    $capForm = ['id' => 'delivery-fixture', 'fields' => [], 'on_submit' => ['webhooks' => ['https://hooks.example.test/cap']]];
    $capJobs = bbf_delivery_prepare_jobs($capForm, $capSubmission, $config);
    bbf_outbox_init($capPath, 'delivery-fixture:bbf_cap', $capJobs, 2);
    $capEffects = 0;
    $GLOBALS['_bbf_delivery_effect'] = static function() use (&$capEffects): array {
        ++$capEffects;
        return bbf_delivery_result(false, 'failed', 'http', 503, true);
    };
    $capOne = bbf_delivery_run_job($capPath, 'webhook:0', $config);
    $capBackoff = bbf_delivery_run_job($capPath, 'webhook:0', $config);
    bbf_outbox_retry($capPath, 'webhook:0');
    $capTwo = bbf_delivery_run_job($capPath, 'webhook:0', $config, $actionResponse, true);
    $capRetry = bbf_outbox_retry($capPath, 'webhook:0');
    $capThree = bbf_delivery_run_job($capPath, 'webhook:0', $config);
    unset($GLOBALS['_bbf_delivery_effect']);
    $capLedger = bbf_outbox_read($capPath)['ledger']['jobs']['webhook:0'];
    submit_delivery_check(!$capOne['ok'] && $capBackoff['reason'] === 'backoff' && !$capTwo['ok']
        && $capLedger['state'] === 'exhausted' && $capLedger['attempts'] === 2
        && !$capRetry['ok'] && !$capThree['executed'] && $capEffects === 2,
        'runner honors the exact attempt cap and never executes an exhausted job');

    $httpForm = [
        'schema_version' => 1, 'id' => 'submit-delivery', 'name' => 'Submit Delivery',
        'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text', 'required' => true]],
        'on_submit' => ['store' => true, 'actions' => [
            ['type' => 'delivery-probe', 'counter' => 'http-effects.json', 'response' => 'http-preserved'],
            ['type' => 'delivery-probe', 'counter' => 'http-effects.json', 'response' => 'http-preserved'],
        ], 'redirect' => '/form-fallback'],
    ];
    file_put_contents("$root/forms/submit-delivery.json", json_encode($httpForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    submit_delivery_config($root);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $http = bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=submit-delivery', ['answer' => 'stored']);
    $httpEffects = is_file("$root/submissions/http-effects.json")
        ? json_decode(file_get_contents("$root/submissions/http-effects.json"), true) : [];
    submit_delivery_check($http['code'] === 200 && ($http['json']['status'] ?? '') === 'ok'
        && ($http['json']['custom_action'] ?? '') === 'http-preserved'
        && ($http['json']['redirect'] ?? '') === '/action-wins'
        && ($http['json']['delivery']['settled'] ?? false),
        'ordinary submit executes actions through the shared runner and preserves action redirect precedence');
    submit_delivery_check(($httpEffects['count'] ?? 0) === 2 && ($httpEffects['first_ledger_jobs'] ?? 0) === 2
        && ($httpEffects['first_states']['action:0'] ?? '') === 'running'
        && ($httpEffects['first_states']['action:1'] ?? '') === 'pending' && ($httpEffects['all_payloads'] ?? false),
        'first ordinary-submit effect observes the complete durable immutable ledger');
    $httpJson = json_encode($http['json']['delivery'] ?? [], JSON_THROW_ON_ERROR);
    submit_delivery_check(!str_contains($httpJson, 'stored') && !str_contains($httpJson, 'delivery-probe')
        && !str_contains($httpJson, 'payload') && !str_contains($httpJson, 'idempotency'),
        'ordinary submit returns only the redacted delivery projection');

    $beforeFaultEffects = (int)($httpEffects['count'] ?? 0);
    $beforeFaultSubmissions = count(glob("$root/submissions/submit-delivery/*.json") ?: []);
    submit_delivery_config($root, true);
    $fault = bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=submit-delivery', ['answer' => 'fault-stored']);
    $afterFaultEffects = json_decode(file_get_contents("$root/submissions/http-effects.json"), true);
    $afterFaultSubmissions = count(glob("$root/submissions/submit-delivery/*.json") ?: []);
    submit_delivery_check($fault['code'] === 500 && ($fault['json']['status'] ?? '') === 'error'
        && !isset($fault['json']['submission_id']) && !isset($fault['json']['delivery']),
        'ledger initialization persistence fault returns 500 without a false accepted response');
    submit_delivery_check($afterFaultSubmissions === $beforeFaultSubmissions
        && ($afterFaultEffects['count'] ?? 0) === $beforeFaultEffects,
        'ledger initialization persistence fault stores no submission and executes zero effects');

    submit_delivery_config($root);
    $storageFaultForm = $httpForm;
    $storageFaultForm['id'] = 'submit-storage-fault';
    $storageFaultForm['name'] = 'Submit Storage Fault';
    file_put_contents("$root/forms/submit-storage-fault.json",
        json_encode($storageFaultForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents("$root/submissions/submit-storage-fault", 'blocks form directory creation');
    $beforeStorageFaultEffects = (int)($afterFaultEffects['count'] ?? 0);
    $storageFault = bbf_test_http($server,
        'http://127.0.0.1:' . $server['port'] . '/submit.php?form=submit-storage-fault', ['answer' => 'storage-fault']);
    $afterStorageFaultEffects = json_decode(file_get_contents("$root/submissions/http-effects.json"), true);
    $storageFaultLedgers = glob("$root/submissions/.delivery/submit-storage-fault/*.json") ?: [];
    $storageFaultStatus = count($storageFaultLedgers) === 1 ? bbf_outbox_status($storageFaultLedgers[0]) : [];
    $storageFaultRaw = count($storageFaultLedgers) === 1
        ? json_decode(file_get_contents($storageFaultLedgers[0]), true) : [];
    $storageJobsTerminal = ($storageFaultRaw['jobs'] ?? []) !== [];
    foreach (($storageFaultRaw['jobs'] ?? []) as $job) {
        $storageJobsTerminal = $storageJobsTerminal && ($job['state'] ?? '') === 'failed'
            && ($job['last_result']['retryable'] ?? true) === false
            && str_contains((string)($job['last_result']['message'] ?? ''), 'storage failed before delivery');
    }
    submit_delivery_check($storageFault['code'] === 500 && ($storageFault['json']['status'] ?? '') === 'error'
        && ($storageFault['json']['delivery']['state'] ?? '') === 'attention_required'
        && !($storageFault['json']['delivery']['settled'] ?? true),
        'storage failure after ledger initialization returns 500 with attention-required delivery');
    submit_delivery_check(count($storageFaultLedgers) === 1 && $storageJobsTerminal
        && ($storageFaultStatus['state'] ?? '') === 'attention_required'
        && ($afterStorageFaultEffects['count'] ?? 0) === $beforeStorageFaultEffects,
        'storage failure executes zero effects and leaves no pending, running, or settled orphan ledger');

    $effectRoot = "$root/effects";
    mkdir($effectRoot, 0700, true);
    $noStoreForm = [
        'schema_version' => 1, 'id' => 'submit-no-store', 'name' => 'Submit No Store',
        'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text', 'required' => true]],
        'on_submit' => ['store' => false, 'actions' => [[
            'type' => 'delivery-probe', 'counter_root' => $effectRoot,
            'counter' => 'no-store-success.json', 'response' => 'no-store-preserved',
        ]], 'redirect' => '/form-fallback'],
    ];
    file_put_contents("$root/forms/submit-no-store.json", json_encode($noStoreForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $privateSuccess = 'private-no-store-success-7f4d';
    $noStore = bbf_test_http($server,
        'http://127.0.0.1:' . $server['port'] . '/submit.php?form=submit-no-store', ['answer' => $privateSuccess]);
    $noStoreEffect = is_file("$effectRoot/no-store-success.json")
        ? json_decode(file_get_contents("$effectRoot/no-store-success.json"), true) : [];
    submit_delivery_check($noStore['code'] === 200 && ($noStore['json']['status'] ?? '') === 'ok'
        && ($noStore['json']['custom_action'] ?? '') === 'no-store-preserved'
        && ($noStore['json']['redirect'] ?? '') === '/action-wins'
        && !isset($noStore['json']['submission_id'])
        && ($noStore['json']['delivery']['state'] ?? '') === 'succeeded'
        && ($noStore['json']['delivery']['durable'] ?? true) === false
        && ($noStore['json']['delivery']['retry_available'] ?? true) === false
        && ($noStoreEffect['count'] ?? 0) === 1,
        'store=false executes its action in memory and preserves actionResponse without claiming durability');
    submit_delivery_check((glob("$root/submissions/submit-no-store/*.json") ?: []) === []
        && !is_dir("$root/submissions/.delivery/submit-no-store"),
        'store=false success creates no submission response file or delivery ledger');

    $noStoreFailureForm = $noStoreForm;
    $noStoreFailureForm['id'] = 'submit-no-store-fail';
    $noStoreFailureForm['name'] = 'Submit No Store Failure';
    $noStoreFailureForm['on_submit']['actions'] = [[
        'type' => 'delivery-probe', 'counter_root' => $effectRoot,
        'counter' => 'no-store-failure.json', 'response' => 'partial-preserved',
    ], [
        'type' => 'delivery-probe', 'counter_root' => $effectRoot,
        'counter' => 'must-not-exist.json', 'fail' => true,
    ]];
    file_put_contents("$root/forms/submit-no-store-fail.json",
        json_encode($noStoreFailureForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $privateFailure = 'private-no-store-failure-9a2c';
    $noStoreFailure = bbf_test_http($server,
        'http://127.0.0.1:' . $server['port'] . '/submit.php?form=submit-no-store-fail', ['answer' => $privateFailure]);
    $noStoreFailureEffect = is_file("$effectRoot/no-store-failure.json")
        ? json_decode(file_get_contents("$effectRoot/no-store-failure.json"), true) : [];
    $noStoreFailureJson = json_encode($noStoreFailure['json'], JSON_THROW_ON_ERROR);
    submit_delivery_check($noStoreFailure['code'] === 202 && ($noStoreFailure['json']['status'] ?? '') === 'ok'
        && ($noStoreFailure['json']['custom_action'] ?? '') === 'partial-preserved'
        && ($noStoreFailure['json']['redirect'] ?? '') === '/action-wins'
        && !isset($noStoreFailure['json']['submission_id'])
        && ($noStoreFailure['json']['delivery']['state'] ?? '') === 'attention_required'
        && ($noStoreFailure['json']['delivery']['durable'] ?? true) === false
        && ($noStoreFailure['json']['delivery']['retry_available'] ?? true) === false
        && ($noStoreFailureEffect['count'] ?? 0) === 1 && !is_file("$effectRoot/must-not-exist.json"),
        'store=false partial failure returns safe accepted attention state with no retry controls');
    submit_delivery_check(!str_contains($noStoreFailureJson, $privateFailure)
        && !str_contains($noStoreFailureJson, 'payload') && !str_contains($noStoreFailureJson, 'idempotency')
        && (glob("$root/submissions/submit-no-store-fail/*.json") ?: []) === []
        && !is_dir("$root/submissions/.delivery/submit-no-store-fail"),
        'store=false failure response is redacted and creates no durable submission or jobs');

    $privateDataFound = false;
    $submissionFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        "$root/submissions", FilesystemIterator::SKIP_DOTS));
    foreach ($submissionFiles as $submissionFile) {
        if (!$submissionFile->isFile()) continue;
        $contents = file_get_contents($submissionFile->getPathname());
        if (is_string($contents) && (str_contains($contents, $privateSuccess) || str_contains($contents, $privateFailure))) {
            $privateDataFound = true;
            break;
        }
    }
    submit_delivery_check(!$privateDataFound,
        'store=false respondent data is absent from every file under submissions');

    print "Submit delivery regression: $checks checks, $failures failures.\n";
} finally {
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
exit($failures === 0 ? 0 : 1);
