<?php
/** G8 F4 form-version repository and submission definition compatibility regression. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_versions.php';
require_once dirname(__DIR__) . '/bbf_read.php';

$checks = 0;
function versions_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    print "PASS $message\n";
}
function versions_definition(string $id, string $label, array $extra = []): array {
    return $extra + [
        '$schema' => 'form.schema.json', 'schema_version' => 1, 'id' => $id,
        'name' => 'Versioned ' . $id,
        'fields' => [
            ['name' => 'answer', 'type' => 'text', 'label' => $label, 'required' => true],
            ['name' => 'secret', 'type' => 'password', 'label' => 'Secret'],
        ],
        'on_submit' => ['store' => true],
    ];
}
function versions_write_form(string $root, array $definition): void {
    $path = $root . '/forms/' . $definition['id'] . '.json';
    $bytes = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write fixture form.');
}
function versions_config(string $root): array {
    return [
        'storage' => 'file', 'forms_dir' => $root . '/forms', 'submissions_dir' => $root . '/submissions',
        'templates_dir' => $root . '/templates', 'logs_dir' => $root . '/logs',
        'sqlite' => ['path' => $root . '/submissions/bbf.sqlite'],
        'mysql' => ['host' => '127.0.0.1', 'database' => 'unused', 'username' => 'unused', 'password' => '', 'charset' => 'utf8mb4'],
        'mail' => ['method' => 'mail', 'from_email' => 'noreply@example.invalid', 'from_name' => 'BBF'],
        'csrf' => false, 'allowed_origins' => [], 'rate_limit' => 1000, 'honeypot_field' => '_bbf_hp',
        'webhook_secret' => '', 'delivery' => ['max_attempts' => 3, 'retry_delay' => 60, 'lease_seconds' => 300],
        'stripe' => ['secret_key' => '', 'webhook_secret' => ''], 'error_notify' => '',
        'api_token' => 'fixture-admin-token', 'access_tokens' => [], 'auth_session_idle' => 1800, 'auth_session_absolute' => 28800,
        'diagnostic_base_url' => '', 'smoke_token' => '', 'smoke_email' => '', 'smoke_notify' => '',
        'store_ip' => false, 'store_user_agent' => false, 'sandbox' => false, 'lang' => 'en',
        'viewer' => ['site_name' => 'Test', 'logo_url' => ''],
    ];
}
function versions_install_config(string $root, array $config): void {
    $bytes = "<?php defined('BBF_LOADED') || exit; return " . var_export($config, true) . ";\n";
    if (file_put_contents($root . '/config.php', $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write fixture config.');
}
function versions_submission(string $root, string $formId): array {
    if (str_ends_with($formId, '-file')) {
        $paths = glob($root . '/submissions/' . $formId . '/bbf_*.json') ?: [];
        versions_check(count($paths) === 1, "$formId stored one file submission");
        return bbf_read_file($paths[0], $formId) ?? throw new RuntimeException('Cannot read file submission.');
    }
    if (str_ends_with($formId, '-csv')) {
        $rows = iterator_to_array(bbf_read_csv($formId, $root . '/submissions'));
        versions_check(count($rows) === 1, "$formId stored one CSV submission");
        return array_values($rows)[0];
    }
    $pdo = new PDO('sqlite:' . $root . '/submissions/bbf.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('SELECT id, form_id, data, meta FROM bbf_submissions WHERE form_id = ?');
    $stmt->execute([$formId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    versions_check(count($rows) === 1, "$formId stored one SQLite submission");
    return bbf_read_db_row($rows[0]) ?? throw new RuntimeException('Cannot read SQLite submission.');
}

$root = bbf_test_installation(dirname(__DIR__));
try {
    bbf_test_copy(dirname(__DIR__) . '/editor.php', "$root/editor.php");
    $config = versions_config($root);
    $base = versions_definition('history', 'Original label');
    versions_write_form($root, $base);

    $reordered = ['on_submit' => $base['on_submit'], 'fields' => $base['fields'], 'name' => $base['name'],
        'id' => $base['id'], 'schema_version' => 1, '$schema' => 'form.schema.json'];
    versions_check(bbf_version_id($base) === bbf_version_id($reordered), 'object key order does not change definition version');
    $changedOrder = $base;
    $changedOrder['fields'] = array_reverse($changedOrder['fields']);
    versions_check(bbf_version_id($base) !== bbf_version_id($changedOrder), 'field order changes definition version');

    $metadata = bbf_version_submission_metadata($base, 'history');
    versions_check(preg_match('/\Av1-[a-f0-9]{64}\z/D', $metadata['definition_version']) === 1,
        'submission version is a deterministic full SHA-256 identifier');
    versions_check(($metadata['form_definition']['fields'][0]['label'] ?? '') === 'Original label'
        && !isset($metadata['form_definition']['on_submit']) && !isset($metadata['form_definition']['fields'][0]['required']),
        'historical presentation keeps labels but excludes processing and validation configuration');

    $state0 = bbf_version_state($config, 'history');
    versions_check($state0['revision'] === 0 && $state0['published_version'] === bbf_version_id($base)
        && $state0['draft_version'] === $state0['published_version'], 'legacy active definition bootstraps revision zero');
    $oldVersion = $state0['published_version'];
    $draft = versions_definition('history', 'Draft label');
    $saved = bbf_version_save_draft($config, 'history', $draft, 0, 'editor-one');
    versions_check(($saved['ok'] ?? false) && $saved['revision'] === 1 && $saved['published_version'] === $oldVersion
        && $saved['draft_version'] === bbf_version_id($draft), 'draft save advances CAS state without publishing');
    versions_check(json_decode(file_get_contents($root . '/forms/history.json'), true)['fields'][0]['label'] === 'Original label',
        'draft save leaves active legacy JSON unchanged');

    $loser = versions_definition('history', 'Losing label');
    $conflict = bbf_version_save_draft($config, 'history', $loser, 0, 'editor-two');
    versions_check(!($conflict['ok'] ?? true) && ($conflict['reason'] ?? '') === 'conflict'
        && ($conflict['definition']['fields'][0]['label'] ?? '') === 'Draft label', 'stale draft save returns the winning snapshot');
    $published = bbf_version_publish($config, 'history', 1, 'editor-one');
    versions_check(($published['ok'] ?? false) && $published['revision'] === 2
        && $published['published_version'] === bbf_version_id($draft), 'publish advances revision and published version');
    versions_check(json_decode(file_get_contents($root . '/forms/history.json'), true)['fields'][0]['label'] === 'Draft label',
        'publish atomically updates the compatible active JSON');

    $history = bbf_version_history($config, 'history');
    versions_check(array_column($history, 'action') === ['bootstrap', 'draft', 'publish'], 'revision history records bootstrap, draft and publish');
    $rolled = bbf_version_rollback($config, 'history', $oldVersion, 2, 'editor-one');
    versions_check(($rolled['ok'] ?? false) && $rolled['revision'] === 3
        && $rolled['published_version'] === $oldVersion && $rolled['draft_version'] === $oldVersion,
        'rollback publishes an immutable historical version as a new revision');
    versions_check(json_decode(file_get_contents($root . '/forms/history.json'), true)['fields'][0]['label'] === 'Original label',
        'rollback restores historical active definition bytes semantically');
    $staleRollback = bbf_version_rollback($config, 'history', bbf_version_id($draft), 2, 'editor-two');
    versions_check(($staleRollback['reason'] ?? '') === 'conflict'
        && json_decode(file_get_contents($root . '/forms/history.json'), true)['fields'][0]['label'] === 'Original label',
        'stale rollback cannot overwrite the winner');

    $paths = bbf_version_paths($config, 'history');
    $pending = json_decode(file_get_contents($paths['state']), true, 512, JSON_THROW_ON_ERROR);
    $pending['draft_version'] = bbf_version_id($draft);
    $pending['pending'] = bbf_version_event(4, bbf_version_id($draft), 'publish', 'editor-one');
    file_put_contents($paths['state'], bbf_storage_json($pending, true));
    versions_write_form($root, $draft);
    $recovered = bbf_version_state($config, 'history');
    versions_check($recovered['revision'] === 4 && $recovered['published_version'] === bbf_version_id($draft)
        && !isset(json_decode(file_get_contents($paths['state']), true)['pending']),
        'completed active publication recovers a pending final state without replay');

    $external = versions_definition('history', 'External label');
    versions_write_form($root, $external);
    $externalState = bbf_version_state($config, 'history');
    $externalHistory = bbf_version_history($config, 'history');
    versions_check($externalState['revision'] === 5 && $externalState['published_version'] === bbf_version_id($external)
        && end($externalHistory)['action'] === 'external_publish',
        'out-of-band active edit is archived and invalidates stale revisions');

    $editorBase = versions_definition('editor-flow', 'Published editor label');
    versions_write_form($root, $editorBase);
    foreach (['submission-file' => 'file', 'submission-csv' => 'csv', 'submission-sqlite' => 'sqlite'] as $formId => $storage) {
        $definition = versions_definition($formId, 'Historical ' . strtoupper($storage), ['storage' => $storage]);
        versions_write_form($root, $definition);
    }
    $templateDefinition = versions_definition('template-file', 'Unused', ['storage' => 'file']);
    $templateDefinition['templates'] = ['contact' => [['name' => 'answer', 'type' => 'text', 'label' => 'Expanded template label', 'required' => true]]];
    $templateDefinition['fields'] = [['name' => 'contact', 'type' => 'group', 'use' => 'contact']];
    versions_write_form($root, $templateDefinition);
    versions_install_config($root, $config);

    $deleteRace = versions_definition('delete-race', 'Published delete race');
    versions_write_form($root, $deleteRace);
    bbf_version_state($config, 'delete-race');
    $deleteRaceDraft = versions_definition('delete-race', 'Draft delete race');
    bbf_version_save_draft($config, 'delete-race', $deleteRaceDraft, 0, 'editor-one');
    $raceWorker = <<<'PHP'
<?php
define('BBF_LOADED', true);
require __DIR__ . '/bbf_versions.php';
$config = require __DIR__ . '/config.php';
try {
    $result = $argv[1] === 'publish'
        ? bbf_version_publish($config, 'delete-race', 1, 'editor-two')
        : bbf_version_delete_active($config, 'delete-race');
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
}
PHP;
    file_put_contents($root . '/version-race-worker.php', $raceWorker);
    $racePaths = bbf_version_paths($config, 'delete-race');
    $raceLock = fopen($racePaths['state'] . '.lock', 'c');
    if (!$raceLock || !flock($raceLock, LOCK_EX)) throw new RuntimeException('Cannot prepare delete/publish race lock.');
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $publishProcess = proc_open([PHP_BINARY, $root . '/version-race-worker.php', 'publish'], $descriptors, $publishPipes, $root);
    $deleteProcess = proc_open([PHP_BINARY, $root . '/version-race-worker.php', 'delete'], $descriptors, $deletePipes, $root);
    if (!is_resource($publishProcess) || !is_resource($deleteProcess)) throw new RuntimeException('Cannot start delete/publish race workers.');
    fclose($publishPipes[0]);
    fclose($deletePipes[0]);
    usleep(100000);
    $bothBlocked = proc_get_status($publishProcess)['running'] && proc_get_status($deleteProcess)['running']
        && is_file($racePaths['active']);
    flock($raceLock, LOCK_UN);
    fclose($raceLock);
    $publishOutput = stream_get_contents($publishPipes[1]);
    $publishError = stream_get_contents($publishPipes[2]);
    $deleteOutput = stream_get_contents($deletePipes[1]);
    $deleteError = stream_get_contents($deletePipes[2]);
    foreach ([$publishPipes[1], $publishPipes[2], $deletePipes[1], $deletePipes[2]] as $pipe) fclose($pipe);
    proc_close($publishProcess);
    proc_close($deleteProcess);
    $publishResult = json_decode($publishOutput, true);
    $publishFinishedBeforeDelete = is_array($publishResult) && ($publishResult['ok'] ?? false) && $publishError === '';
    $deleteFinishedBeforePublish = $publishOutput === '' && trim($publishError) === 'Published form definition is unavailable.';
    versions_check($bothBlocked && json_decode($deleteOutput, true) === true && $deleteError === ''
        && ($publishFinishedBeforeDelete || $deleteFinishedBeforePublish) && !file_exists($racePaths['active']),
        'delete and publish serialize on one version-state lock without resurrecting the deleted definition');

    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    try {
        $editorUrl = 'http://127.0.0.1:' . $server['port'] . '/editor.php';
        $anonymous = bbf_test_http($server, $editorUrl . '?action=state&form=editor-flow');
        versions_check($anonymous['code'] === 403, 'anonymous editor version state is denied');
        $login = bbf_test_http($server, $editorUrl, null,
            ['headers' => ['X-BBF-Token' => $config['api_token']]]);
        preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $login['headers'], $cookie);
        preg_match('/const TOKEN = ("[^"]+");/', $login['body'], $csrfMatch);
        versions_check($login['code'] === 200 && isset($cookie[1], $csrfMatch[1]),
            'legacy admin establishes editor session and CSRF');
        $csrf = json_decode($csrfMatch[1], true, 512, JSON_THROW_ON_ERROR);
        $editorGet = static fn(string $action) => bbf_test_http($server,
            $editorUrl . '?action=' . $action . '&form=editor-flow', null, ['cookie' => $cookie[1]]);
        $editorPost = static function (string $action, int $revision, string $body, bool $withCsrf = true) use ($server, $editorUrl, $cookie, $csrf): array {
            $headers = ['Content-Type' => 'application/json', 'X-BBF-Revision' => (string)$revision];
            if ($withCsrf) $headers['X-BBF-CSRF'] = $csrf;
            return bbf_test_http($server, $editorUrl . '?action=' . $action . '&form=editor-flow', null,
                ['cookie' => $cookie[1], 'method' => 'POST', 'headers' => $headers, 'raw' => $body]);
        };
        $editorState = $editorGet('state');
        $editorOldVersion = $editorState['json']['published_version'] ?? '';
        versions_check($editorState['code'] === 200 && ($editorState['json']['revision'] ?? null) === 0
            && ($editorState['json']['definition']['fields'][0]['label'] ?? '') === 'Published editor label',
            'editor state bootstraps published definition and revision');
        $draftOne = versions_definition('editor-flow', 'Winning draft label');
        versions_check($editorPost('save', 0, json_encode($draftOne, JSON_THROW_ON_ERROR), false)['code'] === 403,
            'editor draft save requires CSRF');
        $savedDraft = $editorPost('save', 0, json_encode($draftOne, JSON_THROW_ON_ERROR));
        versions_check($savedDraft['code'] === 200 && ($savedDraft['json']['revision'] ?? null) === 1
            && json_decode(file_get_contents("$root/forms/editor-flow.json"), true)['fields'][0]['label'] === 'Published editor label',
            'editor save persists draft without publishing active definition');
        $losingDraft = versions_definition('editor-flow', 'Losing draft label');
        $staleSave = $editorPost('save', 0, json_encode($losingDraft, JSON_THROW_ON_ERROR));
        versions_check($staleSave['code'] === 409 && ($staleSave['json']['reason'] ?? '') === 'conflict'
            && ($staleSave['json']['definition']['fields'][0]['label'] ?? '') === 'Winning draft label',
            'stale editor save returns winning draft and cannot overwrite it');
        $invalidDraft = versions_definition('editor-flow', 'Invalid draft label');
        $invalidDraft['fields'][0]['name'] = 'bad name';
        $savedInvalidDraft = $editorPost('save', 1, json_encode($invalidDraft, JSON_THROW_ON_ERROR));
        versions_check($savedInvalidDraft['code'] === 200,
            'semantically invalid work may remain an unpublished draft');
        $invalidPublish = $editorPost('publish', 2, '');
        versions_check($invalidPublish['code'] === 422 && !empty($invalidPublish['json']['errors'])
            && json_decode(file_get_contents("$root/forms/editor-flow.json"), true)['fields'][0]['label'] === 'Published editor label',
            'server validation blocks invalid draft publication');
        $fixedDraft = versions_definition('editor-flow', 'Published winning label');
        $fixedDraft['fields'][0]['type'] = 'select';
        $fixedDraft['fields'][0]['options_from'] = 'options.php?source=fixture';
        versions_check($editorPost('save', 2, json_encode($fixedDraft, JSON_THROW_ON_ERROR))['code'] === 200,
            'valid options_from editor draft repairs unpublished validation errors');
        $stalePublish = $editorPost('publish', 2, '');
        versions_check($stalePublish['code'] === 409 && ($stalePublish['json']['revision'] ?? null) === 3
            && ($stalePublish['json']['definition']['fields'][0]['label'] ?? '') === 'Published winning label',
            'stale editor publish returns current winner without changing active definition');
        $publish = $editorPost('publish', 3, '');
        $publishedDefinition = json_decode(file_get_contents("$root/forms/editor-flow.json"), true);
        versions_check($publish['code'] === 200 && ($publish['json']['revision'] ?? null) === 4
            && ($publishedDefinition['fields'][0]['label'] ?? '') === 'Published winning label'
            && ($publishedDefinition['fields'][0]['options_from'] ?? '') === 'options.php?source=fixture',
            'current editor publishes valid options_from draft with compare-and-swap');
        $invalidRollback = $editorPost('rollback', 4, json_encode([
            'version' => $savedInvalidDraft['json']['draft_version'] ?? '',
        ], JSON_THROW_ON_ERROR));
        versions_check($invalidRollback['code'] === 422 && !empty($invalidRollback['json']['errors'])
            && json_decode(file_get_contents("$root/forms/editor-flow.json"), true)['fields'][0]['label'] === 'Published winning label',
            'editor rollback rejects an immutable historical draft that fails publication validation');
        $rollback = $editorPost('rollback', 4, json_encode(['version' => $editorOldVersion], JSON_THROW_ON_ERROR));
        versions_check($rollback['code'] === 200 && ($rollback['json']['revision'] ?? null) === 5
            && json_decode(file_get_contents("$root/forms/editor-flow.json"), true)['fields'][0]['label'] === 'Published editor label',
            'editor rollback publishes selected immutable history as a new revision');
        $editorHistory = $editorGet('history');
        versions_check($editorHistory['code'] === 200
            && array_column($editorHistory['json']['history'] ?? [], 'action') === ['bootstrap', 'draft', 'draft', 'draft', 'publish', 'rollback'],
            'editor history API returns complete ordered revision events');
        $auditRows = array_map(static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_slice(file("$root/logs/access-audit.php", FILE_IGNORE_NEW_LINES), 1));
        $editorAudit = array_values(array_filter($auditRows, static fn(array $row) => str_starts_with($row['action'], 'editor_')));
        versions_check(count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_save' && $row['result'] === 'completed')) === 3
            && count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_save' && $row['result'] === 'failed')) === 2
            && count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_publish' && $row['result'] === 'completed')) === 1
            && count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_publish' && $row['result'] === 'failed')) === 2
            && count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_rollback' && $row['result'] === 'completed')) === 1
            && count(array_filter($editorAudit, static fn(array $row) => $row['action'] === 'editor_rollback' && $row['result'] === 'failed')) === 1,
            'editor audit distinguishes denied, conflict, validation, and completed mutations');

        $malformedRaw = '{broken-editor-definition';
        file_put_contents("$root/forms/editor-repair.json", $malformedRaw);
        $repairState = bbf_test_http($server, $editorUrl . '?action=state&form=editor-repair', null, ['cookie' => $cookie[1]]);
        versions_check($repairState['code'] === 200 && ($repairState['json']['repair_required'] ?? false)
            && array_key_exists('revision', $repairState['json']) && $repairState['json']['revision'] === null
            && ($repairState['json']['definition_raw'] ?? '') === $malformedRaw,
            'malformed published definition enters explicit repair mode');
        $repairPost = static function (array $definition) use ($server, $editorUrl, $cookie, $csrf): array {
            return bbf_test_http($server, $editorUrl . '?action=save&form=editor-repair', null,
                ['cookie' => $cookie[1], 'method' => 'POST',
                    'headers' => ['Content-Type' => 'application/json', 'X-BBF-CSRF' => $csrf],
                    'raw' => json_encode($definition, JSON_THROW_ON_ERROR)]);
        };
        $invalidRepair = versions_definition('editor-repair', 'Invalid repair');
        $invalidRepair['fields'][0]['type'] = 'not-a-real-type';
        versions_check($repairPost($invalidRepair)['code'] === 422
            && file_get_contents("$root/forms/editor-repair.json") === $malformedRaw,
            'invalid repair is rejected without changing malformed source');
        $validRepair = versions_definition('editor-repair', 'Valid repair');
        $repaired = $repairPost($validRepair);
        versions_check($repaired['code'] === 200 && ($repaired['json']['repaired'] ?? false)
            && ($repaired['json']['revision'] ?? null) === 0
            && json_decode(file_get_contents("$root/forms/editor-repair.json"), true)['fields'][0]['label'] === 'Valid repair',
            'first valid locked repair bootstraps version state');
        $secondRepair = versions_definition('editor-repair', 'Must not overwrite');
        versions_check($repairPost($secondRepair)['code'] === 400
            && json_decode(file_get_contents("$root/forms/editor-repair.json"), true)['fields'][0]['label'] === 'Valid repair',
            'no-revision repair path closes after the first valid winner');

        foreach (['submission-file', 'submission-csv', 'submission-sqlite'] as $formId) {
            $answer = $formId === 'submission-csv' ? "'=literal" : 'stored-value';
            $response = bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=' . $formId,
                ['answer' => $answer, 'secret' => 'must-not-enter-definition']);
            versions_check($response['code'] === 200 && ($response['json']['status'] ?? '') === 'ok', "$formId HTTP submit succeeds in owned fixture");
            $submission = versions_submission($root, $formId);
            versions_check(preg_match('/\Av1-[a-f0-9]{64}\z/D', $submission['meta']['definition_version'] ?? '') === 1,
                "$formId preserves definition version through its backend");
            versions_check(($submission['meta']['form_definition']['fields'][0]['label'] ?? '') === 'Historical ' . strtoupper(substr($formId, 11))
                && !str_contains(json_encode($submission['meta']['form_definition'], JSON_THROW_ON_ERROR), 'must-not-enter-definition'),
                "$formId preserves safe historical definition without respondent values");
            $new = versions_definition($formId, 'Current changed label', ['storage' => substr($formId, 11)]);
            versions_check((bbf_version_submission_definition($submission, $new)['fields'][0]['label'] ?? '') === 'Historical ' . strtoupper(substr($formId, 11)),
                "$formId historical reader wins over current changed definition");
            if ($formId === 'submission-csv') versions_check(($submission['data']['answer'] ?? null) === "'=literal",
                'versioned CSV preserves a literal apostrophe before a formula character');
        }
        $templateResponse = bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=template-file',
            ['answer' => 'template-value']);
        versions_check($templateResponse['code'] === 200, 'template form HTTP submit succeeds');
        $templateSubmission = versions_submission($root, 'template-file');
        versions_check(($templateSubmission['meta']['definition_version'] ?? '') === bbf_version_id($templateDefinition),
            'template submission version matches the immutable raw published definition');
        versions_check(($templateSubmission['meta']['form_definition']['fields'][0]['fields'][0]['label'] ?? '') === 'Expanded template label',
            'template submission snapshot preserves expanded historical child labels');
    } finally {
        bbf_test_stop_server($server);
    }

    $legacy = ['id' => 'legacy', 'form' => 'history', 'data' => ['answer' => 'old'], 'meta' => ['submitted' => '2020-01-01T00:00:00Z']];
    versions_check(bbf_version_submission_definition($legacy, $external)['fields'][0]['label'] === 'External label',
        'legacy submission without version falls back to current definition');
    $hostile = $legacy;
    $hostile['meta']['form_definition'] = ['id' => 'other-form', 'fields' => [['name' => 'answer', 'label' => '<script>wrong</script>']]];
    versions_check(bbf_version_submission_definition($hostile, $external)['fields'][0]['label'] === 'External label',
        'cross-form historical snapshot is rejected');

    print "\nG8 version core: $checks checks passed.\n";
} finally {
    bbf_test_cleanup($root);
}
