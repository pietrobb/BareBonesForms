<?php
/** G8 F5 respondent-draft privacy, expiry and isolation regression. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_functions.php';
require_once dirname(__DIR__) . '/bbf_drafts.php';
require_once __DIR__ . '/test-isolation-helper.php';

$checks = 0;
function drafts_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    print "PASS $message\n";
}
function drafts_remove(string $path): void {
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path), ['.', '..']) as $name) {
        $child = $path . '/' . $name;
        is_dir($child) ? drafts_remove($child) : unlink($child);
    }
    rmdir($path);
}
class DraftChmodDenyStream {
    public $context;
    public static array $files = [];
    private string $path = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        if (str_contains($mode, 'x') && isset(self::$files[$path])) return false;
        $this->path = $path; self::$files[$path] = '';
        return true;
    }
    public function stream_write(string $data): int { self::$files[$this->path] .= $data; return strlen($data); }
    public function stream_flush(): bool { return true; }
    public function stream_close(): void {}
    public function stream_stat(): array { return self::stat(strlen(self::$files[$this->path] ?? '')); }
    public function url_stat(string $path, int $flags): array|false {
        return isset(self::$files[$path]) ? self::stat(strlen(self::$files[$path])) : false;
    }
    public function stream_metadata(string $path, int $option, mixed $value): bool { return false; }
    public function unlink(string $path): bool { unset(self::$files[$path]); return true; }
    private static function stat(int $size): array {
        return [0 => 0, 1 => 0, 2 => 0100600, 3 => 1, 4 => 0, 5 => 0, 6 => 0, 7 => $size,
            8 => 0, 9 => 0, 10 => 0, 11 => -1, 12 => -1, 'mode' => 0100600, 'size' => $size];
    }
}

$root = rtrim(sys_get_temp_dir(), '/\\') . '/bbf-drafts-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create draft fixture.');
register_shutdown_function(static fn() => drafts_remove($root));
$config = ['submissions_dir' => $root . '/submissions', 'drafts_dir' => $root . '/private-drafts'];
$fields = [
    ['name' => 'name', 'type' => 'text'],
    ['name' => 'choices', 'type' => 'checkbox', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
    ['name' => 'symptoms', 'type' => 'textarea', 'sensitive' => true],
    ['name' => 'password', 'type' => 'password'],
    ['name' => 'amount', 'type' => 'number'],
    ['name' => 'internal', 'type' => 'hidden', 'value' => 'server'],
];
$form = [
    'schema_version' => 1, 'id' => 'consultation', 'fields' => $fields,
    'drafts' => ['enabled' => true, 'ttl_seconds' => 300, 'fields' => ['name', 'choices']],
    'on_submit' => ['payment' => ['provider' => 'stripe', 'mode' => 'donation', 'pricing_version' => 'v1',
        'currency' => 'eur', 'amount_field' => 'amount', 'minor_units' => 2,
        'min_amount_minor' => 100, 'max_amount_minor' => 10000]],
];

drafts_check(validateFormDefinition($form) === [], 'valid opt-in policy accepts only ordinary allowlisted fields');
foreach ([
    'symptoms' => 'explicitly sensitive health field',
    'password' => 'password field',
    'amount' => 'payment quote field',
    'internal' => 'hidden field',
] as $field => $description) {
    $invalid = $form;
    $invalid['drafts']['fields'][] = $field;
    drafts_check((bool)array_filter(validateFormDefinition($invalid), static fn($error) => str_contains($error, 'cannot be persisted')),
        "$description cannot be draft-allowlisted");
}
$invalid = $form; $invalid['drafts']['ttl_seconds'] = 299;
drafts_check((bool)array_filter(validateFormDefinition($invalid), static fn($error) => str_contains($error, '300 through 2592000')),
    'draft TTL has a bounded validation floor');
$invalid = $form; $invalid['drafts'] = ['enabled' => false, 'fields' => 'name'];
drafts_check((bool)array_filter(validateFormDefinition($invalid), static fn($error) => str_contains($error, 'Expected a list')),
    'disabled draft policy still rejects malformed allowlist type');
$invalid = $form; $invalid['fields'][2]['sensitive'] = 'false';
drafts_check((bool)array_filter(validateFormDefinition($invalid), static fn($error) => str_contains($error, 'sensitive: Expected boolean')),
    'sensitive marker requires an actual boolean');
$disabled = $form; unset($disabled['drafts']);
drafts_check(bbf_draft_policy($disabled) === null, 'forms remain draft-disabled by default');
$unknownHandle = str_repeat('Z', 43);
$unknownPath = bbf_draft_path($config, $unknownHandle);
drafts_check((bbf_draft_load($config, $form, $unknownHandle)['reason'] ?? '') === 'not_found'
    && (bbf_draft_delete($config, $form, $unknownHandle)['reason'] ?? '') === 'not_found'
    && (bbf_draft_save($config, $form, $fields, ['name' => 'X'], $unknownHandle)['reason'] ?? '') === 'not_found'
    && !file_exists($unknownPath . '.lock'), 'unknown valid bearers do not create persistent lock sidecars');

$input = ['name' => " Alice ", 'choices' => ['a', 'b'], 'symptoms' => 'private health history',
    'password' => 'secret', 'amount' => '99.99', 'internal' => 'attacker', 'unknown' => 'drop me'];
$saved = bbf_draft_save($config, $form, $fields, $input, '', 1000);
drafts_check(($saved['ok'] ?? false) && bbf_draft_valid_handle($saved['handle']), 'new draft returns an unguessable 256-bit bearer handle');
drafts_check($saved['data'] === ['name' => 'Alice', 'choices' => ['a', 'b']], 'save persists only allowlisted nonsensitive values');
$path = bbf_draft_path($config, $saved['handle']);
$record = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
drafts_check(!str_contains($path, $saved['handle']) && !str_contains(file_get_contents($path), $saved['handle']),
    'plaintext bearer handle is absent from both path and record');
$queuePath = bbf_draft_queue_path($config);
$privateModes = is_dir(bbf_draft_dir($config)) && is_file($path) && is_file($queuePath);
if (PHP_OS_FAMILY !== 'Windows') {
    clearstatcache(true, bbf_draft_dir($config)); clearstatcache(true, $path); clearstatcache(true, $queuePath);
    $privateModes = (fileperms(bbf_draft_dir($config)) & 0777) === 0700
        && (fileperms($path) & 0777) === 0600 && (fileperms($queuePath) & 0777) === 0600;
}
drafts_check($privateModes && !bbf_storage_private_mode($root . '/missing-private-path', 0600),
    'save itself publishes a private directory, payload and cleanup queue while missing chmod targets fail closed');
drafts_check(stream_wrapper_register('bbfchmoddeny', DraftChmodDenyStream::class), 'chmod-denial fault stream registered');
$chmodDenied = bbf_storage_replace('bbfchmoddeny://payload', static fn($fp): bool => bbf_storage_write_all($fp, 'private'), 0600);
drafts_check(!$chmodDenied && DraftChmodDenyStream::$files === [],
    'private-mode failure prevents target publication and cleans the unpublished temporary payload');
stream_wrapper_unregister('bbfchmoddeny');
drafts_check($record['created_at'] === 1000 && $record['expires_at'] === 1300, 'save records deterministic creation and expiry timestamps');

$loaded = bbf_draft_load($config, $form, $saved['handle'], 1100);
drafts_check(($loaded['ok'] ?? false) && $loaded['data'] === $saved['data'], 'valid bearer resumes its exact draft');
$tightenedForm = $form;
$tightenedForm['drafts']['fields'] = ['name'];
$tightenedForm['fields'][0]['sensitive'] = true;
$tightened = bbf_draft_load($config, $tightenedForm, $saved['handle'], 1100);
drafts_check(($tightened['ok'] ?? false) && $tightened['data'] === [],
    'resume reapplies the current sensitive and allowlist policy to historical draft data');
$otherForm = $form; $otherForm['id'] = 'other-form';
drafts_check((bbf_draft_load($config, $otherForm, $saved['handle'], 1100)['reason'] ?? '') === 'not_found',
    'bearer cannot cross exact form identity');
$updated = bbf_draft_save($config, $form, $fields, ['name' => 'Bob'], $saved['handle'], 1200);
drafts_check(($updated['ok'] ?? false) && $updated['data'] === ['name' => 'Bob'], 'same bearer atomically replaces its own filtered data');
$updatedRecord = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
drafts_check($updatedRecord['created_at'] === 1000 && $updatedRecord['updated_at'] === 1200 && $updatedRecord['expires_at'] === 1500,
    'update preserves creation and refreshes sliding expiry');
for ($updateIndex = 1; $updateIndex <= 25; $updateIndex++) {
    $manyUpdates = bbf_draft_save($config, $form, $fields, ['name' => "Update $updateIndex"], $saved['handle'], 1200 + $updateIndex);
    if (!($manyUpdates['ok'] ?? false)) throw new RuntimeException('Repeated draft update failed.');
}
$indexedLines = file(bbf_draft_queue_path($config), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
drafts_check(count($indexedLines) === 1 && ($manyUpdates['data']['name'] ?? '') === 'Update 25',
    'twenty-five updates retain one cleanup index record for the live payload');
$replayConfig = ['drafts_dir' => $root . '/replay'];
$replaySave = bbf_draft_save($replayConfig, $form, $fields, ['name' => 'Replay-safe'], '', 1000);
$replayQueue = bbf_draft_queue_path($replayConfig);
file_put_contents($replayQueue, file_get_contents($replayQueue), FILE_APPEND);
bbf_draft_cleanup($replayConfig, 1100, 10);
$replayLines = file($replayQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
drafts_check(count($replayLines) === 1
    && (bbf_draft_load($replayConfig, $form, $replaySave['handle'], 1100)['data']['name'] ?? '') === 'Replay-safe',
    'complete epoch cycle collapses interrupted-replay duplicate indexes without losing the live draft');

$expiring = bbf_draft_save($config, $form, $fields, ['name' => 'Expired'], '', 2000);
drafts_check((bbf_draft_load($config, $form, $expiring['handle'], 2300)['reason'] ?? '') === 'expired'
    && !is_file(bbf_draft_path($config, $expiring['handle'])), 'expired resume fails closed and deletes payload');
$cleanupOne = bbf_draft_save($config, $form, $fields, ['name' => 'Cleanup'], '', 3000);
$cleanupTwo = bbf_draft_save($config, $form, $fields, ['name' => 'Keep'], '', 3400);
drafts_check(bbf_draft_cleanup($config, 3301) >= 1 && !is_file(bbf_draft_path($config, $cleanupOne['handle']))
    && is_file(bbf_draft_path($config, $cleanupTwo['handle']))
    && count(file(bbf_draft_queue_path($config), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 1,
    'full cleanup cycle removes expired payloads and keeps exactly one index record per live draft');
$fairConfig = ['drafts_dir' => $root . '/fairness'];
bbf_draft_prepare_dir($fairConfig);
$fairQueue = '';
foreach ([0 => 5000, 1 => 5000, 2 => 4000, 3 => 4000, 4 => 4000] as $index => $expires) {
    $hash = str_pad((string)$index, 64, '0', STR_PAD_LEFT);
    $record = ['form' => 'consultation', 'created_at' => 3000, 'updated_at' => 3000,
        'expires_at' => $expires, 'data' => ['name' => "Fair $index"]];
    file_put_contents(bbf_draft_dir($fairConfig) . '/' . $hash . '.json', bbf_storage_json($record));
    $fairQueue .= $expires . ' ' . $hash . "\n";
}
file_put_contents(bbf_draft_queue_path($fairConfig), $fairQueue);
bbf_storage_private_mode(bbf_draft_queue_path($fairConfig), 0600);
$fairRemoved = bbf_draft_cleanup($fairConfig, 4500, 2);
$expectedCursor = strlen(implode('', array_slice(explode("\n", trim($fairQueue)), 0, 2))) + 2;
drafts_check((int)file_get_contents(bbf_draft_dir($fairConfig) . '/.cleanup-cursor') === $expectedCursor
    && is_file(bbf_draft_dir($fairConfig) . '/.cleanup-work'),
    'limit two advances exactly two fixed queue records without scanning or completing the work queue');
for ($pass = 1; $pass < 8; $pass++) {
    $removedThisPass = bbf_draft_cleanup($fairConfig, 4500, 2);
    drafts_check($removedThisPass <= 2, "cleanup pass $pass processes at most its indexed-record budget");
    $fairRemoved += $removedThisPass;
}
$fairPayloads = glob(bbf_draft_dir($fairConfig) . '/*.json') ?: [];
$cursorPath = bbf_draft_dir($fairConfig) . '/.cleanup-cursor';
$activeQueue = bbf_draft_queue_path($fairConfig);
$activeLines = file($activeQueue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$cleanupStatePrivate = is_file($cursorPath) && is_file($activeQueue) && !is_file(bbf_draft_dir($fairConfig) . '/.cleanup-work')
    && count($activeLines) === 2;
if (PHP_OS_FAMILY !== 'Windows') {
    clearstatcache(true, $cursorPath); clearstatcache(true, $activeQueue);
    $cleanupStatePrivate = (fileperms($cursorPath) & 0777) === 0600 && (fileperms($activeQueue) & 0777) === 0600;
}
drafts_check($fairRemoved === 3 && count($fairPayloads) === 2 && $cleanupStatePrivate,
    'repeated bounded queue cleanup reaches expired drafts beyond its limit and publishes private state');

$raceConfig = ['drafts_dir' => $root . '/race'];
bbf_draft_prepare_dir($raceConfig);
$workerPath = $root . '/draft-race-worker.php';
$worker = "<?php\ndefine('BBF_LOADED', true);\nrequire " . var_export(dirname(__DIR__) . '/bbf_drafts.php', true) . ";\n"
    . '$config = ' . var_export($raceConfig, true) . ";\n"
    . '$form = ' . var_export($form, true) . ";\n"
    . '$fields = ' . var_export($fields, true) . ";\n"
    . "if ((\$argv[1] ?? '') === 'save') echo json_encode(bbf_draft_save(\$config, \$form, \$fields, ['name' => 'Concurrent'], '', 4400));\n"
    . "else echo json_encode(['removed' => bbf_draft_cleanup(\$config, 4500, 2)]);\n";
file_put_contents($workerPath, $worker);
$queueLock = fopen(bbf_draft_queue_path($raceConfig) . '.lock', 'c');
if (!$queueLock || !flock($queueLock, LOCK_EX)) throw new RuntimeException('Cannot hold draft queue race lock.');
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$saveProcess = proc_open([PHP_BINARY, $workerPath, 'save'], $descriptors, $savePipes, $root);
$cleanupProcess = proc_open([PHP_BINARY, $workerPath, 'cleanup'], $descriptors, $cleanupPipes, $root);
if (!is_resource($saveProcess) || !is_resource($cleanupProcess)) throw new RuntimeException('Cannot start draft race workers.');
fclose($savePipes[0]); fclose($cleanupPipes[0]); usleep(100000);
$bothBlocked = proc_get_status($saveProcess)['running'] && proc_get_status($cleanupProcess)['running'];
flock($queueLock, LOCK_UN); fclose($queueLock);
$saveOutput = stream_get_contents($savePipes[1]); $saveError = stream_get_contents($savePipes[2]);
$cleanupOutput = stream_get_contents($cleanupPipes[1]); $cleanupError = stream_get_contents($cleanupPipes[2]);
foreach ([$savePipes[1], $savePipes[2], $cleanupPipes[1], $cleanupPipes[2]] as $pipe) fclose($pipe);
proc_close($saveProcess); proc_close($cleanupProcess);
$raceSave = json_decode($saveOutput, true); $raceCleanup = json_decode($cleanupOutput, true);
$raceLoad = is_array($raceSave) ? bbf_draft_load($raceConfig, $form, $raceSave['handle'] ?? '', 4500) : [];
drafts_check($bothBlocked && $saveError === '' && $cleanupError === '' && ($raceSave['ok'] ?? false)
    && is_int($raceCleanup['removed'] ?? null) && ($raceLoad['data']['name'] ?? '') === 'Concurrent',
    'concurrent save and cleanup serialize on queue then shard locks without losing the indexed live draft');

$deletePath = bbf_draft_path($config, $cleanupTwo['handle']);
drafts_check((bbf_draft_delete($config, $form, $cleanupTwo['handle'])['ok'] ?? false) && !is_file($deletePath), 'bearer deletes its exact saved draft');
drafts_check((bbf_draft_load($config, $form, $cleanupTwo['handle'])['reason'] ?? '') === 'not_found', 'deleted draft cannot be resumed');
for ($index = 0; $index < 32; $index++) {
    $cycle = bbf_draft_save($config, $form, $fields, ['name' => "Cycle $index"]);
    if (!($cycle['ok'] ?? false) || !(bbf_draft_delete($config, $form, $cycle['handle'])['ok'] ?? false)) {
        throw new RuntimeException('Draft lifecycle fixture failed.');
    }
}
$shardLocks = glob(bbf_draft_dir($config) . '/.lock-*.lock') ?: [];
$payloadLocks = glob(bbf_draft_dir($config) . '/*.json.lock') ?: [];
drafts_check(count($shardLocks) <= 16 && $payloadLocks === [], 'repeated lifecycle leaves only the bounded shard lock pool');
drafts_check((bbf_draft_save($config, $form, $fields, ['name' => 'X'], 'short')['reason'] ?? '') === 'not_found',
    'malformed handles never reach storage paths');

$httpRoot = bbf_test_installation(dirname(__DIR__));
$httpConfig = [
    'storage' => 'file', 'forms_dir' => $httpRoot . '/forms', 'submissions_dir' => $httpRoot . '/submissions',
    'drafts_dir' => $httpRoot . '/submissions/drafts', 'templates_dir' => $httpRoot . '/templates', 'logs_dir' => $httpRoot . '/logs',
    'csrf' => true, 'allowed_origins' => [], 'rate_limit' => 1000, 'honeypot_field' => '_bbf_hp',
    'mail' => ['method' => 'mail', 'from_email' => 'noreply@example.invalid', 'from_name' => 'BBF'],
    'delivery' => ['max_attempts' => 3, 'retry_delay' => 60, 'lease_seconds' => 300],
    'webhook_secret' => '', 'stripe' => ['secret_key' => '', 'webhook_secret' => ''], 'error_notify' => '',
    'api_token' => 'fixture-token', 'access_tokens' => [], 'store_ip' => false, 'store_user_agent' => false,
    'sandbox' => false, 'lang' => 'en',
];
file_put_contents($httpRoot . '/config.php', "<?php defined('BBF_LOADED') || exit; return " . var_export($httpConfig, true) . ";\n");
$httpForm = $form; unset($httpForm['on_submit']);
file_put_contents($httpRoot . '/forms/consultation.json', json_encode($httpForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$wrongForm = $httpForm; $wrongForm['id'] = 'other-form';
file_put_contents($httpRoot . '/forms/other-form.json', json_encode($wrongForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$offForm = $httpForm; $offForm['id'] = 'drafts-off'; unset($offForm['drafts']);
file_put_contents($httpRoot . '/forms/drafts-off.json', json_encode($offForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$mismatchForm = $httpForm; $mismatchForm['id'] = 'shared-internal-id';
file_put_contents($httpRoot . '/forms/route-a.json', json_encode($mismatchForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($httpRoot . '/forms/route-b.json', json_encode($mismatchForm, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$server = bbf_test_start_server($httpRoot, '127.0.0.1', bbf_test_port());
try {
    $baseUrl = 'http://127.0.0.1:' . $server['port'] . '/submit.php';
    drafts_check(bbf_test_http($server, $baseUrl . '?form=route-a&action=definition')['code'] === 500,
        'definition endpoint rejects filename and internal form ID mismatch');
    $mismatchPost = bbf_test_http($server, $baseUrl . '?form=route-b&action=draft_save', null,
        ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'raw' => '{}']);
    drafts_check($mismatchPost['code'] === 500, 'draft endpoint rejects duplicate internal identity under another route');
    $csrfResponse = bbf_test_http($server, $baseUrl . '?form=consultation&action=csrf');
    preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $csrfResponse['headers'], $cookie);
    $csrf = $csrfResponse['json']['csrf_token'] ?? '';
    drafts_check($csrfResponse['code'] === 200 && isset($cookie[1]) && is_string($csrf) && $csrf !== '',
        'draft HTTP flow obtains same-origin CSRF session');
    $post = static function (string $formId, string $action, array $body, bool $withCsrf = true, string $token = '') use ($server, $baseUrl, $cookie, $csrf): array {
        if ($withCsrf) $body['_bbf_csrf'] = $token !== '' ? $token : $csrf;
        return bbf_test_http($server, $baseUrl . '?form=' . rawurlencode($formId) . '&action=' . $action, null,
            ['method' => 'POST', 'cookie' => $cookie[1], 'headers' => ['Content-Type' => 'application/json'],
                'raw' => json_encode($body, JSON_THROW_ON_ERROR)]);
    };
    drafts_check($post('consultation', 'draft_save', ['name' => 'Alice'], false)['code'] === 403,
        'draft mutation requires same-origin CSRF');
    $httpSave = $post('consultation', 'draft_save', ['name' => ' Alice ', 'symptoms' => 'private', 'password' => 'secret']);
    $httpHandle = $httpSave['json']['handle'] ?? '';
    drafts_check($httpSave['code'] === 201 && bbf_draft_valid_handle($httpHandle)
        && ($httpSave['json']['data'] ?? null) === ['name' => 'Alice'], 'HTTP create returns handle and server-filtered data');
    $httpLoad = $post('consultation', 'draft_load', ['_bbf_draft_handle' => $httpHandle]);
    drafts_check($httpLoad['code'] === 200 && ($httpLoad['json']['data']['name'] ?? '') === 'Alice', 'HTTP resume returns exact saved payload');
    drafts_check($post('other-form', 'draft_load', ['_bbf_draft_handle' => $httpHandle])['code'] === 403,
        'CSRF tokens are also bound to the exact form ID');
    $otherCsrf = bbf_test_http($server, $baseUrl . '?form=other-form&action=csrf', null, ['cookie' => $cookie[1]])['json']['csrf_token'] ?? '';
    drafts_check($post('other-form', 'draft_load', ['_bbf_draft_handle' => $httpHandle], true, $otherCsrf)['code'] === 404,
        'HTTP resume cannot cross form IDs even with valid form-specific CSRF');
    $offCsrf = bbf_test_http($server, $baseUrl . '?form=drafts-off&action=csrf', null, ['cookie' => $cookie[1]])['json']['csrf_token'] ?? '';
    drafts_check($post('drafts-off', 'draft_save', ['name' => 'Alice'], true, $offCsrf)['code'] === 404,
        'HTTP draft API is unavailable unless explicitly enabled');
    drafts_check($post('consultation', 'draft_load', ['_bbf_draft_handle' => 'short'])['code'] === 404,
        'HTTP malformed bearer fails without path access');
    $httpDelete = $post('consultation', 'draft_delete', ['_bbf_draft_handle' => $httpHandle]);
    drafts_check($httpDelete['code'] === 200 && $post('consultation', 'draft_load', ['_bbf_draft_handle' => $httpHandle])['code'] === 404,
        'HTTP delete revokes subsequent resume');
} finally {
    bbf_test_stop_server($server);
}

print "\nRespondent draft checks passed: $checks\n";
