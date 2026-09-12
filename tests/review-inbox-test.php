<?php
// G7 review-repository regression. Endpoint/UI coverage is added in the following G7 tasks.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_review.php';

$checks = 0;
function inbox_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    print "PASS $label\n";
}

$auth = bbf_auth_registry(['api_token' => '', 'access_tokens' => [[
    'id' => 'reviewer', 'token' => 'reviewer-secret', 'forms' => ['alpha'],
    'permissions' => ['read', 'review'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false,
], [
    'id' => '0', 'token' => 'zero-principal-secret', 'forms' => ['0'],
    'permissions' => ['read', 'review'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false,
]]]);
inbox_check(($auth['reviewer']['permissions'] ?? []) === ['read', 'review'], 'review permission is a valid scoped capability');
inbox_check(($auth['0']['id'] ?? null) === '0' && ($auth['0']['forms'] ?? null) === ['0'],
    'numeric-only valid principal and form IDs survive the real auth registry');
inbox_check(!bbf_auth_can(['admin' => false, 'forms' => ['alpha'], 'permissions' => ['read']], 'alpha', 'review'),
    'read permission does not silently grant review mutation');

$storages = ['file', 'csv'];
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) $storages[] = 'sqlite';
foreach ($storages as $storage) {
    $root = bbf_test_installation(dirname(__DIR__));
    try {
        bbf_test_remove_dir("$root/forms");
        mkdir("$root/forms", 0700);
        foreach (['alpha', 'beta', 'gamma'] as $form) {
            file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => ucfirst($form), 'fields' => []], JSON_THROW_ON_ERROR));
        }
        $config = ['storage' => $storage, 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
            'sqlite' => ['path' => "$root/submissions/bbf.sqlite"]];
        $original = json_encode(['id' => 'bbf_one', 'form' => 'alpha', 'data' => ['answer' => 'original'],
            'meta' => ['submitted' => '2026-09-09T00:00:00Z']], JSON_THROW_ON_ERROR);
        $originalPath = '';
        $pdo = null;
        if ($storage === 'file') {
            mkdir("$root/submissions/alpha", 0700);
            $originalPath = "$root/submissions/alpha/bbf_one.json";
            file_put_contents($originalPath, $original);
        } elseif ($storage === 'csv') {
            $originalPath = "$root/submissions/alpha.csv";
            file_put_contents($originalPath, "_id,_submitted,answer\nbbf_one,2026-09-09T00:00:00Z,original\n");
        } else {
            $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT, data TEXT, meta TEXT, created_at TEXT)');
            $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
                'bbf_one', 'alpha', '{"answer":"original"}', '{"submitted":"2026-09-09T00:00:00Z"}', '2026-09-09 00:00:00']);
            $pdo->exec('CREATE TABLE bbf_submission_review (
                form_id TEXT COLLATE BINARY NOT NULL, submission_id TEXT COLLATE BINARY NOT NULL,
                status TEXT NOT NULL, notes TEXT NOT NULL, tags TEXT NOT NULL, revision INTEGER NOT NULL,
                updated_at TEXT NOT NULL, updated_by TEXT NOT NULL, PRIMARY KEY (form_id, submission_id))');
            $pdo->prepare('INSERT INTO bbf_submission_review VALUES (?,?,?,?,?,?,?,?)')->execute([
                'alpha', 'bbf_legacy', 'in-progress', 'legacy note', '["legacy"]', 1,
                '2026-09-08T00:00:00Z', 'reviewer']);
        }
        $originalBytes = $originalPath !== '' ? file_get_contents($originalPath) : null;

        inbox_check(bbf_review_get($config, 'alpha', 'bbf_one') === bbf_review_default(), "$storage absent metadata defaults to new");
        if ($storage === 'sqlite') {
            $columns = $pdo->query("PRAGMA table_info('bbf_submission_review')")->fetchAll(PDO::FETCH_ASSOC);
            inbox_check(in_array('deleted', array_column($columns, 'name'), true)
                && bbf_review_get($config, 'alpha', 'bbf_legacy')['notes'] === 'legacy note',
                'sqlite pre-tombstone G7 schema migrates additively and preserves its historical row');
        }
        $first = bbf_review_update($config, 'alpha', 'bbf_one', 'reviewer', [
            'status' => 'in-progress', 'notes' => "Private note\nsecond line", 'tags' => ['urgent', 'blue'],
        ], 0);
        inbox_check(($first['ok'] ?? false) && ($first['review']['revision'] ?? null) === 1
            && $first['review']['status'] === 'in-progress' && $first['review']['tags'] === ['urgent', 'blue']
            && $first['review']['updated_by'] === $auth['reviewer']['id'],
            "$storage initial review update persists validated metadata");
        $loaded = bbf_review_get($config, 'alpha', 'bbf_one');
        inbox_check($loaded === $first['review'], "$storage review metadata survives reload");

        $stale = bbf_review_update($config, 'alpha', 'bbf_one', 'reviewer', ['notes' => 'stale overwrite'], 0);
        inbox_check(!($stale['ok'] ?? true) && ($stale['reason'] ?? '') === 'conflict'
            && ($stale['review']['notes'] ?? '') === "Private note\nsecond line"
            && bbf_review_get($config, 'alpha', 'bbf_one') === $loaded,
            "$storage stale revision is rejected without overwriting the winner");
        $second = bbf_review_update($config, 'alpha', 'bbf_one', 'reviewer', ['status' => 'done', 'tags' => ['blue', 'blue']], 1);
        inbox_check(($second['ok'] ?? false) && $second['review']['revision'] === 2
            && $second['review']['status'] === 'done' && $second['review']['tags'] === ['blue'],
            "$storage current revision updates atomically and de-duplicates tags");
        foreach (['123', '0'] as $numericId) {
            $numeric = bbf_review_update($config, 'alpha', $numericId, 'reviewer', ['notes' => "numeric-id-$numericId"], 0);
            inbox_check(($numeric['ok'] ?? false) && bbf_review_get($config, 'alpha', $numericId) === $numeric['review'],
                "$storage numeric-only valid submission ID $numericId survives repository reload");
        }
        $zeroPrincipal = bbf_review_update($config, 'alpha', 'bbf_zero_principal', $auth['0']['id'], ['notes' => 'zero-principal'], 0);
        inbox_check(($zeroPrincipal['ok'] ?? false) && bbf_review_get($config, 'alpha', 'bbf_zero_principal')['updated_by'] === '0',
            "$storage numeric-only valid principal ID survives repository reload");

        foreach ([
            ['status' => 'closed'], ['notes' => ["not scalar"]], ['tags' => ['ok', "bad\nvalue"]],
            ['tags' => array_fill(0, 21, 'tag')], ['unknown' => true], [],
        ] as $badPatch) {
            $bad = bbf_review_update($config, 'alpha', 'bbf_one', 'reviewer', $badPatch, 2);
            inbox_check(!($bad['ok'] ?? true) && ($bad['reason'] ?? '') === 'invalid', "$storage invalid review payload is rejected");
        }
        inbox_check(bbf_review_get($config, 'alpha', 'bbf_one') === $second['review'], "$storage invalid payloads leave durable state unchanged");

        $otherForm = bbf_review_update($config, 'beta', 'bbf_one', 'reviewer', ['notes' => 'beta-only'], 0);
        inbox_check(($otherForm['ok'] ?? false) && bbf_review_get($config, 'alpha', 'bbf_one')['notes'] !== 'beta-only',
            "$storage form identity isolates same-named submission metadata");
        $records = bbf_review_records($config, 'alpha', ['bbf_one', 'bbf_missing']);
        inbox_check(array_keys($records) === ['bbf_one'] && $records['bbf_one']['revision'] === 2,
            "$storage batch read returns only persisted review rows");

        inbox_check(bbf_review_filters($config, 'alpha', 'reviewer') === [], "$storage saved filters initially empty");
        $saved = bbf_review_filter_save($config, 'alpha', 'reviewer', 'daily', '  Daily triage  ', [
            'q' => '  Žluťoučký 😀  ', 'from' => '2026-09-01', 'to' => '2026-09-09',
            'status' => 'in-progress', 'tags' => ['urgent', 'urgent', 'blue'],
        ], 0);
        inbox_check(($saved['ok'] ?? false) && ($saved['filter'] ?? null) === [
            'id' => 'daily', 'name' => 'Daily triage', 'criteria' => ['q' => 'Žluťoučký 😀',
                'from' => '2026-09-01', 'to' => '2026-09-09', 'status' => 'in-progress', 'tags' => ['urgent', 'blue']],
            'revision' => 1, 'updated_at' => $saved['filter']['updated_at'],
        ], "$storage saved filter validates and normalizes criteria");
        inbox_check(bbf_review_filters($config, 'alpha', 'reviewer') === [$saved['filter']],
            "$storage principal-scoped saved filter survives reload");
        inbox_check(bbf_review_filters($config, 'alpha', '0') === []
            && bbf_review_filters($config, 'beta', 'reviewer') === [],
            "$storage saved filters do not leak across principal or form scope");
        $staleFilter = bbf_review_filter_save($config, 'alpha', 'reviewer', 'daily', 'stale', [], 0);
        inbox_check(!($staleFilter['ok'] ?? true) && ($staleFilter['reason'] ?? '') === 'conflict'
            && ($staleFilter['filter'] ?? null) === $saved['filter'],
            "$storage stale saved-filter write returns the durable winner");
        $changedFilter = bbf_review_filter_save($config, 'alpha', 'reviewer', 'daily', 'Done today',
            ['status' => 'done'], 1);
        inbox_check(($changedFilter['ok'] ?? false) && $changedFilter['filter']['revision'] === 2
            && bbf_review_filters($config, 'alpha', 'reviewer') === [$changedFilter['filter']],
            "$storage current saved-filter revision updates atomically");
        $numericFilter = bbf_review_filter_save($config, 'alpha', '0', '0', 'Zero IDs', [], 0);
        inbox_check(($numericFilter['ok'] ?? false) && bbf_review_filters($config, 'alpha', '0')[0]['id'] === '0',
            "$storage numeric-only principal and filter IDs survive reload");
        foreach ([
            ['', []], ["bad\nname", []], ['bad-query', ['q' => [1]]],
            ['bad-date', ['from' => '2026-02-30']], ['bad-status', ['status' => 'closed']],
            ['bad-tags', ['tags' => ["bad\nvalue"]]], ['unknown', ['other' => true]],
        ] as [$badName, $badCriteria]) {
            $bad = bbf_review_filter_save($config, 'alpha', 'reviewer', 'invalid', $badName, $badCriteria, 0);
            inbox_check(!($bad['ok'] ?? true) && ($bad['reason'] ?? '') === 'invalid',
                "$storage invalid saved filter is rejected");
        }
        $staleDelete = bbf_review_filter_delete($config, 'alpha', 'reviewer', 'daily', 1);
        inbox_check(!($staleDelete['ok'] ?? true) && ($staleDelete['reason'] ?? '') === 'conflict'
            && $staleDelete['filter'] === $changedFilter['filter'],
            "$storage stale saved-filter delete preserves and returns the winner");
        $deletedFilter = bbf_review_filter_delete($config, 'alpha', 'reviewer', 'daily', 2);
        inbox_check(($deletedFilter['ok'] ?? false) && bbf_review_filters($config, 'alpha', 'reviewer') === []
            && (bbf_review_filter_delete($config, 'alpha', 'reviewer', 'daily', 2)['reason'] ?? '') === 'not_found',
            "$storage current saved-filter delete is durable and missing delete is explicit");

        if ($originalPath !== '') {
            inbox_check(file_get_contents($originalPath) === $originalBytes && !str_contains($originalBytes, 'Private note'),
                "$storage review writes never alter or expose original response bytes");
            $sidecar = file_get_contents("$root/submissions/.review/alpha.json");
            inbox_check(is_string($sidecar) && str_contains($sidecar, 'Private note') && !str_contains($originalBytes, 'urgent'),
                "$storage metadata is stored only in the dedicated sidecar");
            $malformedPath = "$root/submissions/.review/gamma.json";
            $malformed = '{"version":1,"records":{"bbf_bad":null}}';
            file_put_contents($malformedPath, $malformed);
            $threw = false;
            try { bbf_review_get($config, 'gamma', 'bbf_bad'); } catch (RuntimeException $error) { $threw = true; }
            $refused = bbf_review_update($config, 'gamma', 'bbf_bad', 'reviewer', ['notes' => 'must-not-rewrite'], 0);
            inbox_check($threw && !($refused['ok'] ?? true) && file_get_contents($malformedPath) === $malformed,
                "$storage malformed null record fails closed without rewriting the repository");
            file_put_contents($malformedPath, 'null');
            $threw = false;
            try { bbf_review_get($config, 'gamma', 'bbf_bad'); } catch (RuntimeException $error) { $threw = true; }
            $refused = bbf_review_update($config, 'gamma', 'bbf_bad', 'reviewer', ['notes' => 'must-not-rewrite'], 0);
            inbox_check($threw && !($refused['ok'] ?? true) && file_get_contents($malformedPath) === 'null',
                "$storage top-level null repository fails closed without rewriting bytes");
            $nullFilters = '{"version":1,"records":[],"filters":null}';
            file_put_contents($malformedPath, $nullFilters);
            $threw = false;
            try { bbf_review_filters($config, 'gamma', 'reviewer'); } catch (RuntimeException $error) { $threw = true; }
            $refused = bbf_review_filter_save($config, 'gamma', 'reviewer', 'bad', 'must-not-rewrite', [], 0);
            inbox_check($threw && !($refused['ok'] ?? true) && file_get_contents($malformedPath) === $nullFilters,
                "$storage null saved-filter repository fails closed without rewriting bytes");
            $badTimestamp = '{"version":1,"records":[],"filters":{"reviewer":{"bad":{"name":"bad","criteria":[],"revision":1,"updated_at":"not-a-time"}}}}';
            file_put_contents($malformedPath, $badTimestamp);
            $threw = false;
            try { bbf_review_filters($config, 'gamma', 'reviewer'); } catch (RuntimeException $error) { $threw = true; }
            inbox_check($threw && file_get_contents($malformedPath) === $badTimestamp,
                "$storage malformed saved-filter timestamp fails closed without rewriting bytes");
        } else {
            $row = $pdo->query("SELECT data, meta FROM bbf_submissions WHERE id='bbf_one'")->fetch(PDO::FETCH_ASSOC);
            inbox_check($row === ['data' => '{"answer":"original"}', 'meta' => '{"submitted":"2026-09-09T00:00:00Z"}'],
                'sqlite review table leaves original response row unchanged');
            inbox_check((int)$pdo->query('SELECT COUNT(*) FROM bbf_submission_review')->fetchColumn() === 6,
                'sqlite review rows use a dedicated table');
        }

        $deleted = bbf_review_delete_records($config, 'alpha', ['bbf_one', 'bbf_never_reviewed']);
        inbox_check(($deleted['ok'] ?? false) && ($deleted['deleted'] ?? null) === 1
            && bbf_review_get($config, 'alpha', 'bbf_one') === bbf_review_default(),
            "$storage review metadata can be purged with submission deletion");
        $resurrect = bbf_review_update($config, 'alpha', 'bbf_one', 'reviewer', ['notes' => 'late write'], 0);
        $createAfterDelete = bbf_review_update($config, 'alpha', 'bbf_never_reviewed', 'reviewer', ['notes' => 'late create'], 0);
        inbox_check(($resurrect['reason'] ?? '') === 'not_found' && ($createAfterDelete['reason'] ?? '') === 'not_found'
            && bbf_review_get($config, 'alpha', 'bbf_one') === bbf_review_default()
            && bbf_review_get($config, 'alpha', 'bbf_never_reviewed') === bbf_review_default(),
            "$storage deletion tombstones reject late update and create without metadata resurrection");
        $pdo = null;
    } finally {
        $pdo = null;
        bbf_test_cleanup($root);
    }
}
$root = bbf_test_installation(dirname(__DIR__));
try {
    bbf_test_copy(dirname(__DIR__) . '/viewer.php', "$root/viewer.php");
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    foreach (['alpha', 'beta'] as $form) {
        file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => ucfirst($form),
            'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
        mkdir("$root/submissions/$form", 0700);
    }
    foreach (['bbf_one', 'bbf_two', 'bbf_three', 'bbf_four'] as $index => $id) {
        $path = "$root/submissions/alpha/$id.json";
        file_put_contents($path, json_encode(['id' => $id, 'form' => 'alpha',
            'data' => ['answer' => "public-$id"], 'meta' => ['submitted' => "2026-09-0" . ($index + 1) . "T00:00:00Z"]], JSON_THROW_ON_ERROR));
        touch($path, 1000 + $index);
    }
    for ($index = 0; $index < 105; $index++) {
        $id = sprintf('bbf_bulk_%03d', $index); $path = "$root/submissions/alpha/$id.json";
        file_put_contents($path, json_encode(['id' => $id, 'form' => 'alpha', 'data' => ['answer' => "bulk-$index"],
            'meta' => ['submitted' => '2026-08-01T00:00:00Z']], JSON_THROW_ON_ERROR));
        touch($path, 500 + $index);
    }
    file_put_contents("$root/submissions/beta/bbf_one.json", json_encode(['id' => 'bbf_one', 'form' => 'beta',
        'data' => ['answer' => 'beta-private'], 'meta' => ['submitted' => '2026-09-09T00:00:00Z']], JSON_THROW_ON_ERROR));
    $tokens = [
        ['id' => 'reader', 'token' => 'reader-secret-123456789', 'forms' => ['alpha'], 'permissions' => ['read'],
            'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false],
        ['id' => 'reviewer', 'token' => 'reviewer-secret-123456789', 'forms' => ['alpha'],
            'permissions' => ['read', 'export', 'delete', 'review'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false],
        ['id' => 'other', 'token' => 'other-secret-123456789', 'forms' => ['alpha'], 'permissions' => ['read', 'review'],
            'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false],
    ];
    $endpointConfig = ['api_token' => '', 'access_tokens' => $tokens, 'storage' => 'file',
        'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs",
        'mail' => ['method' => 'mail']];
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($endpointConfig, true) . ';');
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $http = static function (string $path, array $options = []) use (&$server): array {
        return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/' . $path, null, $options);
    };
    $login = static function (string $id) use ($http, $tokens): array {
        $token = array_values(array_filter($tokens, static fn($row) => $row['id'] === $id))[0]['token'];
        $response = $http('viewer.php', ['headers' => ['X-BBF-Token' => $token]]);
        preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $response['headers'], $cookie);
        preg_match('/const TOKEN = ("[^"]+");/', $response['body'], $csrf);
        inbox_check($response['code'] === 200 && isset($cookie[1], $csrf[1]), "$id viewer session provides CSRF");
        return ['cookie' => $cookie[1], 'csrf' => json_decode($csrf[1], true), 'token' => $token];
    };
    $mutate = static function (string $action, array $session, array $body, string $method = 'POST', bool $csrf = true) use ($http): array {
        return $http("viewer.php?action=$action", ['method' => $method, 'cookie' => $session['cookie'],
            'headers' => ['Content-Type' => 'application/json'] + ($csrf ? ['X-BBF-Viewer-Token' => $session['csrf']] : []),
            'raw' => json_encode($body, JSON_THROW_ON_ERROR)]);
    };
    $reader = $login('reader'); $reviewer = $login('reviewer'); $other = $login('other');
    $oversized = str_repeat('x', 70000);
    inbox_check($http('viewer.php?action=review_update', ['method' => 'POST', 'raw' => $oversized])['code'] === 403,
        'unauthenticated mutation is denied before its oversized body is processed');
    inbox_check($http('viewer.php?action=review_update', ['method' => 'POST', 'cookie' => $reviewer['cookie'],
        'headers' => ['Content-Type' => 'application/json', 'X-BBF-Viewer-Token' => $reviewer['csrf']], 'raw' => $oversized])['code'] === 413,
        'authenticated mutation body is capped before JSON decoding');
    inbox_check($http('viewer.php?action=review_update', ['method' => 'POST', 'cookie' => $reviewer['cookie'],
        'headers' => ['Content-Type' => 'application/json', 'X-BBF-Viewer-Token' => $reviewer['csrf']], 'raw' => '{'])['code'] === 400,
        'malformed bounded mutation body is rejected and audited');
    $readerList = $http('viewer.php?action=submissions&form=alpha', ['headers' => ['X-BBF-Token' => $reader['token']]]);
    $readerDetail = $http('viewer.php?action=detail&form=alpha&id=bbf_two', ['headers' => ['X-BBF-Token' => $reader['token']]]);
    inbox_check($readerList['code'] === 200 && $readerDetail['code'] === 200
        && !str_contains($readerList['body'] . $readerDetail['body'], '"review"'),
        'read-only viewer contracts omit internal review metadata');
    inbox_check($http('viewer.php?action=submissions&form=alpha&status=done',
        ['headers' => ['X-BBF-Token' => $reader['token']]])['code'] === 403
        && $http('viewer.php?action=submissions&form=alpha&review=1',
            ['headers' => ['X-BBF-Token' => $reader['token']]])['code'] === 403,
        'read-only principal cannot request or infer review metadata');
    $legacyReviewerList = $http('viewer.php?action=submissions&form=alpha&limit=1', ['cookie' => $reviewer['cookie']]);
    $reviewList = $http('viewer.php?action=submissions&form=alpha&review=1&limit=1', ['cookie' => $reviewer['cookie']]);
    inbox_check($reviewList['code'] === 200 && isset($reviewList['json']['submissions'][0]['review'])
        && !isset($legacyReviewerList['json']['submissions'][0]['review']),
        'authorized viewer explicitly opts into review list metadata without changing legacy list shape');
    foreach ([
        ['bbf_two', ['status' => 'done', 'notes' => 'private-note-two', 'tags' => ['urgent']], 0],
        ['bbf_three', ['status' => 'in-progress', 'notes' => 'private-note-three', 'tags' => ['urgent']], 0],
        ['bbf_four', ['status' => 'done', 'notes' => 'private-note-four', 'tags' => ['urgent', 'blue']], 0],
    ] as [$id, $patch, $revision]) {
        $updated = $mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => $id,
            'revision' => $revision, 'patch' => $patch]);
        inbox_check($updated['code'] === 200 && ($updated['json']['review']['revision'] ?? null) === 1,
            "HTTP review update persists $id");
    }
    $filtered = $http('viewer.php?action=submissions&form=alpha&status=done&tags%5B%5D=urgent&limit=1&offset=1',
        ['cookie' => $reviewer['cookie']]);
    inbox_check($filtered['code'] === 200 && $filtered['json']['total'] === 2
        && count($filtered['json']['submissions']) === 1
        && $filtered['json']['submissions'][0]['id'] === 'bbf_two'
        && $filtered['json']['submissions'][0]['review']['notes'] === 'private-note-two',
        'status and tag filters run before pagination and attach exact review rows');
    $batched = $http('viewer.php?action=submissions&form=alpha&status=new&limit=1&offset=105',
        ['cookie' => $reviewer['cookie']]);
    inbox_check($batched['code'] === 200 && $batched['json']['total'] === 106
        && count($batched['json']['submissions']) === 1
        && $batched['json']['submissions'][0]['review'] === bbf_review_default(),
        'filtered file viewer crosses multiple 100-row batches with exact count and page');
    $detail = $http('viewer.php?action=detail&form=alpha&id=bbf_three', ['cookie' => $reviewer['cookie']]);
    inbox_check($detail['code'] === 200 && $detail['json']['review']['status'] === 'in-progress'
        && $detail['json']['review']['updated_by'] === 'reviewer', 'authorized detail exposes its separate review record');
    foreach (['status=closed', 'tags=urgent', 'from=2026-02-30', 'q%5B%5D=x'] as $bad) {
        inbox_check($http("viewer.php?action=submissions&form=alpha&$bad", ['cookie' => $reviewer['cookie']])['code'] === 400,
            'invalid HTTP review filter is rejected');
    }
    $auditBeforeConflict = count(file("$root/logs/access-audit.php"));
    $winner = $mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => 'bbf_two', 'revision' => 0,
        'patch' => ['notes' => 'stale']]);
    inbox_check($winner['code'] === 409 && $winner['json']['review']['notes'] === 'private-note-two',
        'HTTP stale review update returns the durable winner');
    $conflictAudit = array_map(static fn($line) => json_decode($line, true),
        array_slice(file("$root/logs/access-audit.php"), $auditBeforeConflict));
    inbox_check(count($conflictAudit) === 2 && $conflictAudit[1]['result'] === 'failed'
        && $conflictAudit[1]['result_count'] === 1, 'conflict audit counts the protected winner returned to the client');
    inbox_check($mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => 'bbf_two', 'revision' => 1,
        'patch' => ['notes' => 'x'], 'unexpected' => true])['code'] === 400, 'HTTP review mutation rejects unknown payload fields');
    inbox_check($mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => 'bbf_two', 'revision' => 1,
        'patch' => ['notes' => 'x']], 'GET')['code'] === 405, 'HTTP review mutation requires POST');
    inbox_check($mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => 'bbf_two', 'revision' => 1,
        'patch' => ['notes' => 'x']], 'POST', false)['code'] === 403, 'HTTP review mutation requires CSRF');
    inbox_check($mutate('review_update', $reviewer, ['form' => 'beta', 'id' => 'bbf_one', 'revision' => 0,
        'patch' => ['notes' => 'cross-form']])['code'] === 403, 'HTTP review mutation denies cross-form scope before reading data');
    $saved = $mutate('review_filter_save', $reviewer, ['form' => 'alpha', 'id' => 'triage', 'name' => 'Triage',
        'criteria' => ['status' => 'done', 'tags' => ['urgent']], 'revision' => 0]);
    $ownFilters = $http('viewer.php?action=review_filters&form=alpha', ['cookie' => $reviewer['cookie']]);
    $otherFilters = $http('viewer.php?action=review_filters&form=alpha', ['cookie' => $other['cookie']]);
    inbox_check($saved['code'] === 200 && $ownFilters['json']['filters'][0]['id'] === 'triage'
        && $otherFilters['json']['filters'] === [], 'saved-filter API isolates principal and form scope');
    inbox_check($mutate('review_filter_delete', $reviewer, ['form' => 'alpha', 'id' => 'triage', 'revision' => 0])['code'] === 400,
        'saved-filter delete validates revision payload');
    inbox_check($mutate('review_filter_delete', $reviewer, ['form' => 'alpha', 'id' => 'triage', 'revision' => 1])['code'] === 200,
        'saved-filter delete succeeds at current revision');
    $stateless = $http('submissions.php?form=alpha', ['headers' => ['X-BBF-Token' => $reviewer['token']]]);
    $export = $http('viewer.php?action=export&form=alpha', ['cookie' => $reviewer['cookie']]);
    inbox_check($stateless['code'] === 200 && $export['code'] === 200
        && !str_contains($stateless['body'] . $export['body'], 'private-note'),
        'review notes never enter submissions.php or CSV export contracts');
    $deleted = $mutate('delete', $reviewer, ['form' => 'alpha', 'id' => 'bbf_two']);
    inbox_check($deleted['code'] === 200 && !is_file("$root/submissions/alpha/bbf_two.json"),
        'viewer deletion removes the primary response');
    $sidecar = json_decode(file_get_contents("$root/submissions/.review/alpha.json"), true, 512, JSON_THROW_ON_ERROR);
    inbox_check(!isset($sidecar['records']['bbf_two']) && ($sidecar['deleted']['bbf_two'] ?? false) === true
        && !str_contains(json_encode($sidecar, JSON_THROW_ON_ERROR), 'private-note-two'),
        'viewer deletion scrubs review metadata and leaves a resurrection tombstone');
    $preparedRetry = $mutate('review_update', $reviewer, ['form' => 'alpha', 'id' => 'bbf_one', 'revision' => 0,
        'patch' => ['notes' => 'retry-purge-secret']]);
    inbox_check($preparedRetry['code'] === 200, 'retry-safe purge fixture has persisted metadata');
    $reviewPath = "$root/submissions/.review/alpha.json"; $reviewBackup = $reviewPath . '.saved';
    rename($reviewPath, $reviewBackup); mkdir($reviewPath, 0700);
    $primaryBeforeFailedPurge = file_get_contents("$root/submissions/alpha/bbf_one.json");
    $failedPurge = $mutate('delete', $reviewer, ['form' => 'alpha', 'id' => 'bbf_one']);
    inbox_check($failedPurge['code'] === 503 && is_file("$root/submissions/alpha/bbf_one.json")
        && file_get_contents("$root/submissions/alpha/bbf_one.json") === $primaryBeforeFailedPurge,
        '6129-F04 failed metadata tombstone preserves the exact primary response');
    rmdir($reviewPath); rename($reviewBackup, $reviewPath);
    $retriedPurge = $mutate('delete', $reviewer, ['form' => 'alpha', 'id' => 'bbf_one']);
    $retriedSidecar = json_decode(file_get_contents($reviewPath), true, 512, JSON_THROW_ON_ERROR);
    inbox_check($retriedPurge['code'] === 200 && !is_file("$root/submissions/alpha/bbf_one.json")
        && !isset($retriedSidecar['records']['bbf_one'])
        && ($retriedSidecar['deleted']['bbf_one'] ?? false) === true
        && !str_contains(json_encode($retriedSidecar, JSON_THROW_ON_ERROR), 'retry-purge-secret'),
        '6129-F04 delete retry atomically removes the retained primary and private review metadata');
    $audit = file_get_contents("$root/logs/access-audit.php");
    inbox_check(str_contains($audit, 'viewer_review_update') && str_contains($audit, 'viewer_review_filter_save')
        && !str_contains($audit, 'private-note'), 'review API mutations are audited without notes or tags');
} finally {
    bbf_test_cleanup($root);
}
print "Review inbox: $checks passed, 0 failed. MySQL requires the disposable MariaDB acceptance fixture.\n";
