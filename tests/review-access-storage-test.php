<?php
// G2 authorization matrix over disposable file/CSV/SQLite fixtures, not storage remediation.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0;
function access_storage_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    print "PASS $label\n";
}
access_storage_check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'SQLite driver required; no skipped backend');
foreach (['file', 'csv', 'sqlite'] as $storage) {
    $root = bbf_test_installation(dirname(__DIR__));
    $server = null;
    try {
        bbf_test_copy(dirname(__DIR__) . '/viewer.php', "$root/viewer.php");
        bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
        $tokens = [];
        foreach (['reader' => ['read'], 'exporter' => ['read', 'export'], 'deleter' => ['read', 'delete']] as $id => $permissions) {
            $tokens[] = ['id' => $id, 'token' => "storage-fixture-secret-$id", 'forms' => ['alpha'],
                'permissions' => $permissions, 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
        }
        $config = ['storage' => $storage, 'api_token' => 'storage-fixture-admin', 'access_tokens' => $tokens,
            'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs",
            'sqlite' => ['path' => "$root/submissions/bbf.sqlite"], 'lang' => 'en'];
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        $pdo = null;
        if ($storage === 'sqlite') {
            $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT, data TEXT, meta TEXT, created_at TEXT)');
        }
        foreach (['alpha', 'beta'] as $form) {
            file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => "$form-name",
                'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
            $record = ['id' => "bbf_$form", 'form' => $form, 'data' => ['answer' => "$form-confidential-answer"],
                'meta' => ['submitted' => '2026-09-08T12:00:00Z']];
            if ($storage === 'file') {
                mkdir("$root/submissions/$form", 0700);
                file_put_contents("$root/submissions/$form/bbf_$form.json", json_encode($record, JSON_THROW_ON_ERROR));
            } elseif ($storage === 'csv') {
                $fp = fopen("$root/submissions/$form.csv", 'w');
                fputcsv($fp, ['_id', '_submitted', '_ip', '_user_agent', 'answer'], ',', '"', '');
                fputcsv($fp, [$record['id'], $record['meta']['submitted'], '', '', $record['data']['answer']], ',', '"', '');
                fclose($fp);
            } else {
                $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
                    $record['id'], $form, json_encode($record['data']), json_encode($record['meta']), $record['meta']['submitted']]);
            }
        }
        $pdo = null;
        $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
        $base = 'http://127.0.0.1:' . $server['port'] . '/';
        $http = static fn(string $path, array $options = []) => bbf_test_http($server, $base . $path, null, $options);
        $header = static fn(string $id) => ['headers' => ['X-BBF-Token' => "storage-fixture-secret-$id"]];
        foreach (['viewer.php?action=submissions', 'viewer.php?action=detail', 'submissions.php'] as $path) {
            $r = $http($path . (str_contains($path, '?') ? '&' : '?') . 'form=alpha&id=bbf_alpha', $header('reader'));
            access_storage_check($r['code'] === 200 && str_contains($r['body'], 'alpha-confidential-answer'), "$storage authorized read $path");
            $r = $http($path . (str_contains($path, '?') ? '&' : '?') . 'form=beta&id=bbf_beta', $header('reader'));
            access_storage_check($r['code'] === 403 && !str_contains($r['body'], 'beta-confidential-answer'), "$storage cross-form read denied $path");
        }
        $r = $http('viewer.php?action=dashboard', $header('reader'));
        access_storage_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 1 && !str_contains($r['body'], 'beta'), "$storage dashboard filters before aggregates");
        foreach (['viewer.php?action=export&form=', 'submissions.php?format=csv&form='] as $path) {
            $r = $http($path . 'alpha', $header('reader'));
            access_storage_check($r['code'] === 403, "$storage read permission alone cannot export $path");
            $r = $http($path . 'alpha', $header('exporter'));
            access_storage_check($r['code'] === 200 && str_contains($r['body'], 'alpha-confidential-answer'), "$storage permitted export $path");
            $r = $http($path . 'beta', $header('exporter'));
            access_storage_check($r['code'] === 403 && !str_contains($r['body'], 'beta-confidential-answer'), "$storage cross-form export denied $path");
        }
        foreach (['reader', 'deleter'] as $id) {
            $login = $http('viewer.php', $header($id));
            preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $login['headers'], $cookie);
            preg_match('/const TOKEN = ("[^"]+");/', $login['body'], $csrf);
            access_storage_check($login['code'] === 200 && isset($cookie[1], $csrf[1]), "$storage $id session login");
            $options = ['cookie' => $cookie[1], 'method' => 'POST', 'headers' => ['Content-Type' => 'application/json',
                'X-BBF-CSRF' => json_decode($csrf[1], true)]];
            foreach (['delete', 'bulk_delete'] as $action) {
                $options['raw'] = json_encode(['form' => 'beta', 'id' => 'bbf_beta', 'ids' => ['bbf_beta']]);
                access_storage_check($http("viewer.php?action=$action", $options)['code'] === 403, "$storage $id cross-form $action denied");
            }
            $options['raw'] = json_encode(['form' => 'alpha', 'id' => 'bbf_alpha']);
            $expected = $id === 'reader' ? 403 : ($storage === 'csv' ? 400 : 200);
            access_storage_check($http('viewer.php?action=delete', $options)['code'] === $expected,
                "$storage $id delete obeys permission and existing CSV capability");
        }
        if ($storage !== 'csv') {
            $fixturePdo = $storage === 'sqlite'
                ? new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : null;
            $seedDeleteRecord = static function (string $id) use ($storage, $root, $fixturePdo): void {
                $record = ['id' => $id, 'form' => 'alpha', 'data' => ['answer' => "private-$id"],
                    'meta' => ['submitted' => '2026-09-08T12:00:00Z']];
                if ($storage === 'file') file_put_contents("$root/submissions/alpha/$id.json", json_encode($record, JSON_THROW_ON_ERROR));
                else $fixturePdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
                    $id, 'alpha', json_encode($record['data']), json_encode($record['meta']), $record['meta']['submitted']]);
            };
            $recordExists = static function (string $id) use ($storage, $root, $fixturePdo): bool {
                if ($storage === 'file') { clearstatcache(true, "$root/submissions/alpha/$id.json"); return is_file("$root/submissions/alpha/$id.json"); }
                $stmt = $fixturePdo->prepare('SELECT COUNT(*) FROM bbf_submissions WHERE id = ? AND form_id = ?');
                $stmt->execute([$id, 'alpha']);
                return (int)$stmt->fetchColumn() === 1;
            };
            $deliveryDir = "$root/submissions/.delivery/alpha";
            if (!is_dir($deliveryDir)) mkdir($deliveryDir, 0700, true);
            $seedLedger = static function (string $id, string $state) use ($deliveryDir): string {
                $secret = "private-ledger-$id";
                $job = ['key' => 'job', 'type' => 'action', 'state' => $state, 'attempts' => 1,
                    'max_attempts' => 3, 'next_retry' => null, 'idempotent' => true,
                    'target' => "target-$secret", 'idempotency_key' => "key-$secret",
                    'payload_hash' => hash('sha256', $secret), 'payload' => ['response' => $secret],
                    'lease_token' => $state === 'running' ? "lease-$secret" : null,
                    'lease_started' => $state === 'running' ? time() : null,
                    'last_result' => $state === 'succeeded' ? ['stage' => 'action', 'code' => 0, 'retryable' => false, 'at' => 1] : null];
                $ledger = ['version' => 1, 'submission_key' => "alpha:$id:$secret", 'jobs' => ['job' => $job],
                    'events' => [], 'semantic_events' => []];
                $path = "$deliveryDir/$id.json";
                file_put_contents($path, json_encode($ledger, JSON_THROW_ON_ERROR));
                $events = ['version' => 1, 'submission_key' => "alpha:$id:events:$secret", 'jobs' => [],
                    'events' => [hash('sha256', $secret) => ['status' => 'paid']],
                    'semantic_events' => [hash('sha256', "semantic-$secret") => ['status' => 'paid']]];
                file_put_contents($path . '.events', json_encode($events, JSON_THROW_ON_ERROR));
                return $secret;
            };
            foreach (['bbf_delete_safe', 'bbf_delete_running', 'bbf_bulk_safe', 'bbf_bulk_running'] as $id) $seedDeleteRecord($id);
            $safeSecret = $seedLedger('bbf_delete_safe', 'succeeded');
            $runningSecret = $seedLedger('bbf_delete_running', 'running');
            $seedLedger('bbf_bulk_safe', 'succeeded');
            $seedLedger('bbf_bulk_running', 'running');

            $options['raw'] = json_encode(['form' => 'alpha', 'id' => 'bbf_delete_running']);
            $runningDelete = $http('viewer.php?action=delete', $options);
            access_storage_check($runningDelete['code'] === 409 && $recordExists('bbf_delete_running')
                && str_contains(file_get_contents("$deliveryDir/bbf_delete_running.json"), $runningSecret)
                && !str_contains($runningDelete['body'], $runningSecret), "$storage running single deletion refused without disclosure");

            $options['raw'] = json_encode(['form' => 'alpha', 'id' => 'bbf_delete_safe']);
            $safeDelete = $http('viewer.php?action=delete', $options);
            $safeLedgerBytes = file_get_contents("$deliveryDir/bbf_delete_safe.json")
                . file_get_contents("$deliveryDir/bbf_delete_safe.json.events");
            $safeTombstone = json_decode(file_get_contents("$deliveryDir/bbf_delete_safe.json"), true, 512, JSON_THROW_ON_ERROR);
            access_storage_check($safeDelete['code'] === 200 && !$recordExists('bbf_delete_safe')
                && ($safeTombstone['deleted'] ?? false) === true && $safeTombstone['jobs'] === []
                && $safeTombstone['events'] === [] && $safeTombstone['semantic_events'] === []
                && !str_contains($safeLedgerBytes, $safeSecret) && !str_contains($safeLedgerBytes, hash('sha256', $safeSecret)),
                "$storage single deletion tombstones delivery and payment ledgers without identifiers");

            $options['raw'] = json_encode(['form' => 'alpha', 'ids' => ['bbf_bulk_safe', 'bbf_bulk_running']]);
            $bulkBlocked = $http('viewer.php?action=bulk_delete', $options);
            access_storage_check($bulkBlocked['code'] === 409 && $recordExists('bbf_bulk_safe') && $recordExists('bbf_bulk_running'),
                "$storage bulk deletion is all-or-nothing while any delivery runs");
            $bulkRunningPath = "$deliveryDir/bbf_bulk_running.json";
            $bulkRunningLedger = json_decode(file_get_contents($bulkRunningPath), true, 512, JSON_THROW_ON_ERROR);
            $bulkRunningLedger['jobs']['job']['state'] = 'failed';
            $bulkRunningLedger['jobs']['job']['lease_token'] = null;
            $bulkRunningLedger['jobs']['job']['lease_started'] = null;
            file_put_contents($bulkRunningPath, json_encode($bulkRunningLedger, JSON_THROW_ON_ERROR));
            $bulkDeleted = $http('viewer.php?action=bulk_delete', $options);
            $bulkBytes = file_get_contents("$deliveryDir/bbf_bulk_safe.json") . file_get_contents("$deliveryDir/bbf_bulk_safe.json.events")
                . file_get_contents("$deliveryDir/bbf_bulk_running.json") . file_get_contents("$deliveryDir/bbf_bulk_running.json.events");
            access_storage_check($bulkDeleted['code'] === 200 && ($bulkDeleted['json']['deleted'] ?? null) === 2
                && !$recordExists('bbf_bulk_safe') && !$recordExists('bbf_bulk_running')
                && !str_contains($bulkBytes, 'private-ledger-') && !str_contains($bulkBytes, 'payload_hash')
                && !str_contains($bulkBytes, 'idempotency_key') && !str_contains($bulkBytes, 'target'),
                "$storage bulk deletion proceeds after leases stop and purges all ledger identifiers");

            if ($storage === 'sqlite') {
                foreach (['bbf_partial_first', 'bbf_partial_second'] as $id) {
                    $seedDeleteRecord($id);
                    $seedLedger($id, 'succeeded');
                }
                $partialSecondOriginal = [file_get_contents("$deliveryDir/bbf_partial_second.json"),
                    file_get_contents("$deliveryDir/bbf_partial_second.json.events")];
                $fixturePdo->exec("CREATE TRIGGER bbf_fixture_partial_delete BEFORE DELETE ON bbf_submissions
                    WHEN OLD.id = 'bbf_partial_second' BEGIN SELECT RAISE(FAIL, 'fixture primary failure'); END");
                $beforeAudit = count(file("$root/logs/access-audit.php"));
                $options['raw'] = json_encode(['form' => 'alpha', 'ids' => ['bbf_partial_first', 'bbf_partial_second']]);
                $partial = $http('viewer.php?action=bulk_delete', $options);
                $partialAudit = array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                    array_slice(file("$root/logs/access-audit.php"), $beforeAudit));
                $partialFirstTombstones = true;
                foreach (["$deliveryDir/bbf_partial_first.json", "$deliveryDir/bbf_partial_first.json.events"] as $ledgerPath) {
                    $ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
                    $partialFirstTombstones = $partialFirstTombstones && ($ledger['deleted'] ?? false) === true
                        && $ledger['jobs'] === [] && $ledger['events'] === [] && $ledger['semantic_events'] === [];
                }
                $partialSecondRestored = file_get_contents("$deliveryDir/bbf_partial_second.json") === $partialSecondOriginal[0]
                    && file_get_contents("$deliveryDir/bbf_partial_second.json.events") === $partialSecondOriginal[1];
                access_storage_check($partial['code'] === 503 && ($partial['json']['deleted'] ?? null) === 1
                    && !array_key_exists('_audit_count', $partial['json'])
                    && !$recordExists('bbf_partial_first') && $recordExists('bbf_partial_second')
                    && $partialFirstTombstones && $partialSecondRestored,
                    'sqlite partial primary failure tombstones the committed delete and exactly restores delivery for the retained primary');
                access_storage_check(count($partialAudit) === 2
                    && array_column($partialAudit, 'action') === ['viewer_bulk_delete', 'viewer_bulk_delete']
                    && array_column($partialAudit, 'result') === ['attempted', 'failed']
                    && array_column($partialAudit, 'result_count') === [0, 1],
                    'sqlite partial bulk failure records the actual committed count in the failed audit');
                $fixturePdo->exec('DROP TRIGGER bbf_fixture_partial_delete');
            }
            $seedDeleteRecord = null;
            $recordExists = null;
            $fixturePdo = null;
        }
        $r = $http('submissions.php?form=beta&id=bbf_beta', ['headers' => ['X-BBF-Token' => $config['api_token']]]);
        access_storage_check($r['code'] === 200 && str_contains($r['body'], 'beta-confidential-answer'), "$storage denied mutation leaves cross-form record intact");
        $r = $http('submissions.php?form=alpha&id=bbf_alpha', $header('reader'));
        access_storage_check($r['code'] === ($storage === 'csv' ? 200 : 404), "$storage allowed deletion effect verified by independent API");
        $audit = file_get_contents("$root/logs/access-audit.php");
        access_storage_check(is_string($audit) && str_starts_with($audit, '<?php http_response_code(404); exit; ?>') && str_contains($audit, '"action":"api_detail"') && str_contains($audit, '"action":"viewer_delete"') && str_contains($audit, '"decision":"denied"') && str_contains($audit, '"result":"completed"') && !str_contains($audit, 'storage-fixture-') && !str_contains($audit, 'confidential-answer'), "$storage audit contains actual allowed/denied operations without secrets or answers");
    } finally {
        $pdo = null;
        bbf_test_stop_server($server);
        bbf_test_cleanup($root);
    }
}
print "Access storage matrix: $checks passed, 0 failed. MySQL not exercised (no disposable server configured).\n";
