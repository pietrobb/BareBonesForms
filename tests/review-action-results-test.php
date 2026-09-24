<?php
/** Isolated action/outbox tests. Fake cURL under php -n; never loads workspace config or real records. */
if (PHP_SAPI !== 'cli') exit('CLI only');
if (function_exists('curl_exec')) {
    $process = proc_open([PHP_BINARY, '-n', __FILE__], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    exit(is_resource($process) ? proc_close($process) : 1);
}
define('BBF_LOADED', true);
$root = sys_get_temp_dir() . '/bbf-action-results-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
foreach (['actions', 'config', 'forms', 'logs', 'submissions/test'] as $dir) mkdir("$root/$dir", 0700, true);
foreach (['bbf_functions.php', 'bbf_storage.php', 'bbf_delivery.php', 'bbf_outbox.php', 'bbf_uploads.php', 'bbf_diagnostics.php'] as $file) {
    copy(dirname(__DIR__) . '/' . $file, "$root/$file");
}
copy(dirname(__DIR__) . '/actions/google-ads-conversion.php', "$root/actions/google-ads-conversion.php");
require "$root/bbf_functions.php";
$checks = 0; $failed = 0;
function check_action(bool $ok, string $label): void {
    global $checks, $failed;
    ++$checks;
    if (!$ok) ++$failed;
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function remove_action_fixture(string $path): void {
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = "$path/$name";
        if (is_dir($child) && !is_link($child)) remove_action_fixture($child); else unlink($child);
    }
    rmdir($path);
}
// No cURL extension is loaded. These functions cannot open sockets.
foreach (['CURLOPT_POST', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_TIMEOUT', 'CURLOPT_HTTPHEADER', 'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i + 1);
$GLOBALS['curl_queue'] = []; $GLOBALS['curl_calls'] = [];
if (!function_exists('curl_init')) {
function curl_init($url) { return (object)['url' => $url, 'options' => [], 'response' => []]; }
function curl_setopt_array($ch, $options) { $ch->options = $options; return true; }
function curl_setopt($ch, $key, $value) { $ch->options[$key] = $value; return true; }
function curl_exec($ch) {
    $GLOBALS['curl_calls'][] = ['url' => $ch->url, 'options' => $ch->options];
    if ($GLOBALS['curl_queue'] === []) throw new RuntimeException('Unexpected offline transport call');
    $ch->response = array_shift($GLOBALS['curl_queue']);
    return $ch->response['raw'];
}
function curl_getinfo($ch, $key) { return $ch->response['http']; }
function curl_errno($ch) { return $ch->response['errno'] ?? 0; }
function curl_error($ch) { return $ch->response['error'] ?? ''; }
function curl_close($ch) { $ch->closed = true; }
}
function gads_response($body, int $http = 200, int $errno = 0): array {
    return ['raw' => is_string($body) ? $body : json_encode($body), 'http' => $http, 'errno' => $errno, 'error' => $errno ? 'Offline timeout' : ''];
}
function run_gads(array $data, array $history = []) {
    global $root, $config;
    $submission = ['id' => 'gads', 'form' => 'test', 'data' => $data, 'meta' => ['actions' => $history]];
    $action = ['customer_id' => '123-456', 'conversion_action_id' => '987', 'conversion_value' => 50, 'currency_code' => 'EUR'];
    return include "$root/actions/google-ads-conversion.php";
}
$config = ['storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs"];
try {
    $codes = [
        'skip' => 'return;',
        'legacy' => '$actionResponse["kept"] = true;',
        'explicit' => 'return ["status" => "ok", "detail" => ["receipt" => "abc"]];',
        'error' => 'return ["status" => "error", "detail" => ["error" => "Rejected"]];',
        'exception' => 'throw new RuntimeException("Fixture exception");',
        'php-error' => 'throw new Error("Fixture PHP error");',
        'invalid' => 'return false;',
        'bad-metadata' => 'return ["status" => "ok", "detail" => ["bad" => "\xFF"]];',
    ];
    foreach ($codes as $name => $code) file_put_contents("$root/actions/$name.php", '<?php $GLOBALS["effects"][] = ' . var_export($name, true) . '; ' . $code);
    $GLOBALS['effects'] = [];
    $submission = ['id' => 'one', 'form' => 'test', 'data' => ['keep' => 'unchanged'], 'meta' => ['submitted' => 'original', 'payment_status' => 'paid']];
    $recordPath = "$root/submissions/test/one.json";
    bbf_storage_write_json($recordPath, $submission);
    $form = ['fields' => [], 'on_submit' => ['actions' => array_map(static fn($type) => ['type' => $type], array_keys($codes))]];
    $jobs = bbf_delivery_prepare_jobs($form, $submission, $config);
    $path = bbf_outbox_path($config, 'test', 'one');
    bbf_outbox_init($path, 'test:one', $jobs);
    $response = [];
    foreach ($jobs as $job) bbf_delivery_run_job($path, $job['key'], $config, $response);
    $record = json_decode(file_get_contents($recordPath), true);
    $actions = array_column($record['meta']['actions'], null, 'action');
    check_action(count($GLOBALS['effects']) === 8, 'Throwable failures do not stop subsequent actions');
    check_action(!isset($actions['skip']) && count($actions) === 6, 'null skip and failed metadata encoding add no entries');
    check_action($actions['legacy']['status'] === 'ok' && ($response['kept'] ?? false), 'fallthrough 1 is success and response side channel survives');
    check_action($actions['explicit']['detail'] === ['receipt' => 'abc'], 'structured action detail survives');
    check_action($actions['error']['status'] === 'error' && $actions['error']['detail']['error'] === 'Rejected', 'explicit error recorded');
    check_action($actions['exception']['detail']['error'] === 'Fixture exception' && $actions['php-error']['detail']['exception'] === 'Error', 'Exception and PHP Error captured as error detail');
    check_action($actions['invalid']['status'] === 'error', 'invalid action contract fails explicitly'); $retryJob = $jobs[3]; $retryJob['idempotent'] = true; check_action(bbf_delivery_execute_job($retryJob, $config)['retryable'] === true, 'structured errors preserve retries for idempotent actions'); check_action(bbf_delivery_action_result(['status' => 'error', 'retryable' => false], true)['retryable'] === false, 'explicit no-retry outcome overrides idempotent job');
    check_action($record['data'] === $submission['data'] && $record['meta']['payment_status'] === 'paid' && $record['meta']['submitted'] === 'original', 'metadata projection preserves visitor and payment fields');
    check_action((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $actions['legacy']['at']), 'UTC action timestamp contract');
    check_action(str_contains(file_get_contents($recordPath), '"detail": {}'), 'empty detail serializes as an object'); $objects = json_decode(file_get_contents($recordPath)); $objects->data->map = new stdClass; $objects->data->numeric = (object)['0' => 'x']; $objects->meta->actions[0]->detail->nested = new stdClass; file_put_contents($recordPath, json_encode($objects)); bbfRecordActionResult('one', 'test', 'shape-probe', 'ok', ['nested' => new stdClass], $config); $preserved = json_decode(file_get_contents($recordPath)); check_action($preserved->data->map instanceof stdClass && $preserved->data->numeric instanceof stdClass && $preserved->meta->actions[0]->detail->nested instanceof stdClass, 'metadata append preserves unrelated empty and numeric-key objects');
    $ledger = bbf_outbox_read($path)['ledger']; $effectsBeforeReplay = count($GLOBALS['effects']);
    check_action($ledger['jobs']['action:7']['state'] === 'succeeded', 'metadata encoding failure cannot undo durable success');
    foreach ($jobs as $job) bbf_delivery_run_job($path, $job['key'], $config, $response);
    check_action(count($GLOBALS['effects']) === $effectsBeforeReplay, 'repeated runner invocation does not replay settled or terminal actions');
    check_action(!bbf_outbox_retry($path, 'action:7', true)['ok'], 'metadata failure creates no retry route');
    check_action(!str_contains(json_encode(bbf_outbox_status($path)), 'Fixture exception'), 'public delivery projection does not leak private action detail');
    check_action(!bbfRecordActionResult('../one', 'test', 'x', 'ok', [], $config), 'unsafe submission ID rejected');
    check_action(!bbfRecordActionResult('one', '../test', 'x', 'ok', [], $config), 'unsafe form ID rejected');
    foreach (['sqlite', 'mysql', 'csv'] as $backend) {
        $before = file_get_contents($recordPath);
        check_action(bbfRecordActionResult('one', 'test', 'x', 'ok', [], array_replace($config, ['storage' => $backend]))
            && file_get_contents($recordPath) === $before, "$backend metadata is an explicit no-op");
    }
    // Built-in adapters use the same runner projection, without external services.
    foreach (['email', 'webhook'] as $type) {
        $payload = ['fixture' => true];
        $job = ['key' => $type, 'type' => $type, 'payload' => $payload, 'payload_hash' => bbf_delivery_payload_hash($payload)];
        $p = "$root/$type.json";
        bbf_outbox_init($p, 'test:one', [$job]);
        $GLOBALS['_bbf_delivery_effect'] = static fn() => bbf_delivery_result($type === 'webhook', $type === 'webhook' ? 'accepted' : 'failed', $type === 'webhook' ? 'http' : 'connect', 0, false);
        bbf_delivery_run_job($p, $type, $config);
    }
    unset($GLOBALS['_bbf_delivery_effect']);
    $actions = array_column(json_decode(file_get_contents($recordPath), true)['meta']['actions'], null, 'action');
    check_action($actions['email']['status'] === 'error' && $actions['webhook']['status'] === 'ok', 'email and webhook results share meta.actions');

    check_action(run_gads([]) === null && $GLOBALS['curl_calls'] === [], 'organic Google submission skips without credentials or transport');
    $r = run_gads(['gclid' => 'CLICK']);
    check_action($r['status'] === 'error' && str_contains($r['detail']['error'], 'Missing'), 'missing Google credentials is an explicit error');
    $credsPath = "$root/config/google-ads-credentials.php";
    file_put_contents($credsPath, '<?php return [];');
    check_action(run_gads(['gclid' => 'CLICK'])['status'] === 'error', 'incomplete Google credentials is an error');
    file_put_contents($credsPath, '<?php return ["refresh_token" => "r", "client_id" => "i", "client_secret" => "s", "developer_token" => "d"];');
    foreach ([gads_response(['error' => 'invalid_grant'], 400), gads_response([], 0, 28), gads_response('not-json'), gads_response(['access_token' => 'bad-status'], 403)] as $reply) {
        $GLOBALS['curl_queue'] = [$reply];
        $r = run_gads(['gclid' => 'CLICK']);
        check_action($r['status'] === 'error' && $GLOBALS['curl_queue'] === [] && $r['detail']['http_status'] === $reply['http'], 'OAuth rejection/transport/malformed response fails explicitly');
    }
    foreach (['gclid', 'gbraid', 'wbraid'] as $type) {
        $GLOBALS['curl_calls'] = [];
        $GLOBALS['curl_queue'] = [gads_response(['access_token' => 'token']), gads_response(['results' => [['accepted' => true]]])];
        $data = $type === 'gclid' ? ['gclid' => 'FIRST', 'gbraid' => 'SECOND', 'wbraid' => 'THIRD'] : [$type => 'BRAID'];
        $r = run_gads($data);
        $calls = $GLOBALS['curl_calls'];
        $payload = json_decode($calls[1]['options'][CURLOPT_POSTFIELDS], true);
        $conversion = $payload['conversions'][0];
        check_action($r['status'] === 'ok' && $r['detail']['api_version'] === 'v24' && $r['detail']['click_id_type'] === $type && $r['detail']['error'] === null, "$type strict success and result detail");
        check_action($calls[1]['url'] === 'https://googleads.googleapis.com/v24/customers/123456:uploadClickConversions'
            && $payload['partialFailure'] === true && $conversion[$type] === $data[$type]
            && $conversion['conversionAction'] === 'customers/123456/conversionActions/987'
            && $conversion['conversionValue'] === 50 && $conversion['currencyCode'] === 'EUR'
            && (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $conversion['conversionDateTime'])
            && ($type === 'gclid' ? !isset($conversion['conversionEnvironment'], $conversion['gbraid'], $conversion['wbraid']) : $conversion['conversionEnvironment'] === 'WEB'), "$type preserves baseline payload and priority");
        check_action(str_contains($calls[0]['options'][CURLOPT_POSTFIELDS], 'grant_type=refresh_token')
            && in_array('developer-token: d', $calls[1]['options'][CURLOPT_HTTPHEADER], true), 'OAuth form and API transport headers preserved');
    }
    foreach ([gads_response(['results' => [['x' => 1]], 'partialFailureError' => ['message' => 'partial']]),
        gads_response(['results' => []]), gads_response(['results' => [['x' => 1]]], 201),
        gads_response(str_repeat('X', 450), 500), gads_response('broken json'), gads_response([], 0, 28)] as $reply) {
        $GLOBALS['curl_queue'] = [gads_response(['access_token' => 'token']), $reply];
        $r = run_gads(['wbraid' => 'CLICK']);
        check_action($r['status'] === 'error' && strlen($r['detail']['error']) <= 300
            && $r['detail']['curl_errno'] === $reply['errno'] && $r['detail']['http_status'] === $reply['http'], 'upload partial/empty/non-200/malformed/network failure is not success');
    }
    $GLOBALS['curl_queue'] = [gads_response('raw-body', 503, 28)];
    $transport = _bbfGadsPost('https://offline.invalid', []);
    check_action($transport['raw_body'] === 'raw-body' && $transport['http_code'] === 503 && $transport['curl_errno'] === 28 && $transport['curl_error'] === 'Offline timeout', 'transport exposes raw body, HTTP code and cURL error before close');
    foreach (['ok', 'error', 'unknown'] as $status) {
        $before = count($GLOBALS['curl_calls']);
        check_action(run_gads(['gclid' => 'CLICK'], [['action' => 'google-ads-conversion', 'status' => $status]]) === null
            && count($GLOBALS['curl_calls']) === $before, "existing Google $status entry always prevents upload");
    }
    // Immutable job snapshot has no history; the file now has a previous Google error.
    bbfRecordActionResult('one', 'test', 'google-ads-conversion', 'error', ['error' => 'previous'], $config);
    $googleJobs = bbf_delivery_prepare_jobs(['on_submit' => ['actions' => [['type' => 'google-ads-conversion']]]],
        array_replace($submission, ['data' => ['gclid' => 'CLICK']]), $config);
    $before = count($GLOBALS['curl_calls']);
    $r = bbf_delivery_execute_job($googleJobs[0], $config);
    check_action($r['ok'] && $r['action_result'] === null && count($GLOBALS['curl_calls']) === $before, 'runner refreshes persisted history before Google idempotence check');
} finally {
    remove_action_fixture($root);
}
print "$checks checks, $failed failures\n";
exit($failed ? 1 : 0);
