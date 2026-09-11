<?php
/** G9 F6 protected logical backup and explicit verified restore; disposable file fixtures only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_backup.php';
require_once dirname(__DIR__) . '/bbf_versions.php';
require_once dirname(__DIR__) . '/bbf_review.php';

$checks = 0;
function backup_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    print "PASS $message\n";
}

function backup_cli(string $root, array $arguments): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, "$root/maintenance.php", ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start maintenance CLI fixture.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function backup_write_config(string $root, array $config): void {
    $bytes = "<?php\ndefined('BBF_LOADED') || exit;\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents("$root/config.php", $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write fixture configuration.');
    }
}

$source = bbf_test_installation(dirname(__DIR__));
$target = bbf_test_installation(dirname(__DIR__));
$backupDir = dirname($source) . '/bbf logical backups ' . bin2hex(random_bytes(8));
$GLOBALS['bbf_test_roots'][$backupDir] = true;
try {
    foreach ([$source, $target] as $root) {
        bbf_test_remove_dir("$root/forms");
        mkdir("$root/forms", 0700);
    }
    $definition = ['id' => 'alpha', 'name' => 'Alpha', 'storage' => 'file',
        'fields' => [['name' => 'answer', 'type' => 'text']]];
    file_put_contents("$source/forms/alpha.json", json_encode($definition, JSON_THROW_ON_ERROR));
    file_put_contents("$source/forms/beta.json", json_encode(['id' => 'beta', 'name' => 'Beta', 'storage' => 'file',
        'fields' => [['name' => 'answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
    mkdir("$source/submissions/alpha", 0700);
    mkdir("$source/submissions/beta", 0700);
    $record = ['id' => 'bbf_alpha', 'form' => 'alpha', 'data' => ['answer' => 'private-alpha'],
        'meta' => ['submitted' => '2026-09-09T00:00:00Z']];
    file_put_contents("$source/submissions/alpha/bbf_alpha.json", json_encode($record, JSON_THROW_ON_ERROR));
    file_put_contents("$source/submissions/beta/bbf_beta.json", json_encode(['id' => 'bbf_beta', 'form' => 'beta',
        'data' => ['answer' => 'private-beta'], 'meta' => ['submitted' => '2026-09-09T00:00:00Z']], JSON_THROW_ON_ERROR));

    $access = [['id' => 'reviewer', 'token' => 'SOURCE-SECRET-MUST-NOT-BE-BACKED-UP', 'forms' => ['alpha'],
        'permissions' => ['read', 'review'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]];
    $sourceConfig = ['storage' => 'file', 'forms_dir' => "$source/forms", 'submissions_dir' => "$source/submissions",
        'logs_dir' => "$source/logs", 'api_token' => '', 'access_tokens' => $access,
        'backup' => ['directory' => $backupDir]];
    $state = bbf_version_state($sourceConfig, 'alpha');
    $draft = $definition; $draft['name'] = 'Alpha draft';
    backup_check((bbf_version_save_draft($sourceConfig, 'alpha', $draft, $state['revision'], 'editor')['ok'] ?? false) === true,
        'source fixture has unpublished version history');
    backup_check((bbf_review_update($sourceConfig, 'alpha', 'bbf_alpha', 'reviewer',
        ['status' => 'done', 'notes' => 'private note', 'tags' => ['important']], 0)['ok'] ?? false) === true,
        'source fixture has review metadata');
    backup_check((bbf_review_filter_save($sourceConfig, 'alpha', 'reviewer', 'mine', 'Mine',
        ['status' => 'done'], 0)['ok'] ?? false) === true, 'source fixture has a saved filter');
    backup_check((bbf_review_delete_records($sourceConfig, 'alpha', ['bbf_deleted'])['ok'] ?? false) === true,
        'source fixture has a review tombstone');
    $sourceDelivery = bbf_outbox_path($sourceConfig, 'alpha', 'bbf_alpha');
    $deliveryJobs = [['key' => 'notify', 'type' => 'smtp', 'payload_hash' => hash('sha256', 'private-alpha'),
        'idempotency_key' => 'alpha:bbf_alpha:notify', 'target' => 'owner-email', 'idempotent' => false]];
    backup_check((bbf_outbox_init($sourceDelivery, 'alpha:bbf_alpha', $deliveryJobs, 3, 10)['ok'] ?? false) === true
        && (bbf_outbox_init($sourceDelivery . '.events', 'alpha:bbf_alpha:events', [], 3, 10)['ok'] ?? false) === true
        && (bbf_outbox_record_event($sourceDelivery . '.events', 'evt-alpha', 'paid-alpha', 'paid', 11)['ok'] ?? false) === true,
        'source fixture has delivery and payment-event ledgers');
    bbf_audit_write($sourceConfig, ['id' => 'fixture'], 'viewer_read', 'alpha', ['bbf_alpha'], 'allowed', 'completed', 1);
    bbf_audit_write($sourceConfig, ['id' => 'fixture'], 'viewer_read', 'beta', ['bbf_beta'], 'allowed', 'completed', 1);

    backup_write_config($source, $sourceConfig);
    $corruptPath = "$source/submissions/alpha/bbf_corrupt.json";
    file_put_contents($corruptPath, '{"id":"bbf_corrupt"');
    $corruptRejected = false;
    try { bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T11:59:59Z')); }
    catch (RuntimeException $error) { $corruptRejected = str_contains($error->getMessage(), 'Invalid stored submission'); }
    unset($error);
    backup_check($corruptRejected && !is_dir($backupDir),
        'file backup rejects a malformed source record before publishing a bundle');
    unlink($corruptPath);
    $createdRun = backup_cli($source, ['backup', '--form=alpha']);
    $created = json_decode($createdRun['stdout'], true, 512, JSON_THROW_ON_ERROR);
    backup_check($createdRun['code'] === 0 && ($created['ok'] ?? false) === true
        && ($created['form'] ?? null) === 'alpha', 'maintenance CLI creates a form-scoped logical backup');
    $bytes = file_get_contents($created['path']);
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    backup_check(($created['ok'] ?? false) === true && is_file($created['path'])
        && hash_equals($document['sha256'], hash('sha256', bbf_storage_json($document['payload']))),
        'backup is a private integrity-checked logical bundle');
    backup_check(PHP_OS_FAMILY === 'Windows' || ((fileperms($created['path']) & 0777) === 0600
        && (fileperms(dirname($created['path'])) & 0777) === 0700),
        'backup bundle and private directory enforce owner-only POSIX modes where supported');
    backup_check(!str_contains($bytes, 'SOURCE-SECRET-MUST-NOT-BE-BACKED-UP')
        && ($document['payload']['access']['principals'][0]['id'] ?? null) === 'reviewer',
        'bundle preserves applicable access boundaries without credentials');
    backup_check(isset($document['payload']['delivery']['bbf_alpha']['ledger'],
        $document['payload']['delivery']['bbf_alpha']['events']),
        'bundle preserves delivery and payment-event relationships');
    $runningClaim = bbf_outbox_claim($sourceDelivery, 'notify', 12);
    $runningRejected = false;
    try { bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T12:00:01Z')); }
    catch (RuntimeException $error) { $runningRejected = str_contains($error->getMessage(), 'Running'); }
    backup_check(($runningClaim['ok'] ?? false) === true && $runningRejected,
        'backup refuses an active delivery lease instead of capturing an ambiguous side effect');
    bbf_outbox_complete($sourceDelivery, 'notify', $runningClaim['token'],
        ['ok' => true, 'state' => 'succeeded', 'stage' => 'final_reply'], 13);

    $targetAccess = $access;
    $targetAccess[0]['token'] = 'DIFFERENT-TARGET-SECRET';
    $targetConfig = ['storage' => 'file', 'forms_dir' => "$target/forms", 'submissions_dir' => "$target/submissions",
        'logs_dir' => "$target/logs", 'api_token' => '', 'access_tokens' => $targetAccess,
        'backup' => ['directory' => $backupDir]];
    backup_write_config($target, $targetConfig);
    bbf_audit_write($targetConfig, ['id' => 'fixture'], 'viewer_read', 'beta', ['bbf_beta'], 'allowed', 'completed', 1);
    $planRun = backup_cli($target, ['restore', '--bundle=' . $created['path']]);
    $plan = json_decode($planRun['stdout'], true, 512, JSON_THROW_ON_ERROR);
    backup_check($planRun['code'] === 0 && ($plan['dry_run'] ?? false) === true && ($plan['empty'] ?? false) === true
        && preg_match('/\Arestore-[a-f0-9]{64}\z/D', $plan['confirmation'] ?? '') === 1,
        'restore defaults to a deterministic dry-run plan on an empty target');
    $wrongRun = backup_cli($target, ['restore', '--bundle=' . $created['path'], '--apply',
        '--confirm=restore-' . str_repeat('0', 64)]);
    $wrong = json_decode($wrongRun['stdout'], true, 512, JSON_THROW_ON_ERROR);
    backup_check($wrongRun['code'] === 1 && ($wrong['reason'] ?? null) === 'confirmation'
        && !is_file("$target/forms/alpha.json"),
        'wrong restore confirmation leaves the target untouched');

    $tampered = $backupDir . '/tampered.json';
    file_put_contents($tampered, str_replace('private-alpha', 'tampered-alpha', $bytes));
    $threw = false;
    try { bbf_backup_restore_plan($targetConfig, $tampered); } catch (RuntimeException $error) { $threw = true; }
    backup_check($threw && !is_file("$target/forms/alpha.json"), 'tampered bundle fails integrity validation before target access');

    $restoredRun = backup_cli($target, ['restore', '--bundle=' . $created['path'], '--apply',
        '--confirm=' . $plan['confirmation']]);
    $restored = json_decode($restoredRun['stdout'], true, 512, JSON_THROW_ON_ERROR);
    $restoredRecord = bbf_read_file("$target/submissions/alpha/bbf_alpha.json", 'alpha');
    $review = bbf_review_file_transaction($targetConfig, 'alpha', false, static fn(array $document): array => $document);
    $version = bbf_version_state($targetConfig, 'alpha');
    $audit = file_get_contents("$target/logs/access-audit.php");
    backup_check($restoredRun['code'] === 0 && ($restored['ok'] ?? false) === true && $restoredRecord === $record,
        'confirmed restore recreates the exact logical submission on the empty target');
    backup_check(($review['records']['bbf_alpha']['notes'] ?? null) === 'private note'
        && isset($review['filters']['reviewer']['mine'], $review['deleted']['bbf_deleted']),
        'restore preserves review notes, saved filters and tombstones');
    $targetDelivery = bbf_outbox_existing_path($targetConfig, 'alpha', 'bbf_alpha');
    backup_check((bbf_outbox_read($targetDelivery)['ledger'] ?? null) === ($document['payload']['delivery']['bbf_alpha']['ledger'] ?? null)
        && (bbf_outbox_read($targetDelivery . '.events')['ledger'] ?? null) === ($document['payload']['delivery']['bbf_alpha']['events'] ?? null),
        'restore preserves exact delivery and payment-event ledger relationships');
    backup_check($version['definition']['name'] === 'Alpha draft' && count(bbf_version_history($targetConfig, 'alpha')) === 2,
        'restore preserves published definition, draft and complete version history');
    $auditEntries = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_slice(file("$target/logs/access-audit.php", FILE_IGNORE_NEW_LINES), 1));
    backup_check(str_contains($audit, 'bbf_alpha') && substr_count($audit, 'bbf_beta') === 1
        && in_array($document['payload']['audit'][0], $auditEntries, true),
        'restore preserves exact source audit records without replacing unrelated target audit');
    $republished = false;
    $auditRaceRejected = false;
    try {
        bbf_backup_audit_restore($targetConfig, $document['payload'], static function () use (&$republished): void {
            $republished = true;
        });
    } catch (RuntimeException $error) {
        $auditRaceRejected = str_contains($error->getMessage(), 'no longer empty');
    }
    backup_check($auditRaceRejected && !$republished && file_get_contents("$target/logs/access-audit.php") === $audit,
        'final audit lock rejects a concurrently populated form scope without appending or publishing');
    $occupied = bbf_backup_restore_plan($targetConfig, $created['path']);
    backup_check(($occupied['empty'] ?? true) === false && ($occupied['confirmation'] ?? null) === null,
        'restore refuses an already populated target instead of merging or overwriting');

    $faultTarget = bbf_test_installation(dirname(__DIR__));
    bbf_test_remove_dir("$faultTarget/forms"); mkdir("$faultTarget/forms", 0700);
    mkdir("$faultTarget/forms/.versions", 0700);
    $faultConfig = ['storage' => 'file', 'forms_dir' => "$faultTarget/forms", 'submissions_dir' => "$faultTarget/submissions",
        'logs_dir' => "$faultTarget/logs", 'api_token' => '', 'access_tokens' => $targetAccess,
        'backup' => ['directory' => $backupDir]];
    $auditRollback = false;
    try {
        bbf_backup_audit_restore($faultConfig, ['audit' => [], 'form' => 'alpha', 'records' => []],
            static function (): void { throw new RuntimeException('injected publish failure'); });
    } catch (RuntimeException $error) {
        $auditRollback = $error->getMessage() === 'injected publish failure';
    }
    $emptyAuditPath = "$faultTarget/logs/access-audit.php";
    backup_check($auditRollback && bbf_backup_audit_capture($faultConfig, 'alpha') === []
        && file_get_contents($emptyAuditPath) === "<?php http_response_code(404); exit; ?>\n",
        'failed publication leaves a valid empty guarded audit log without post-unlock unlink');
    unlink($emptyAuditPath);
    $faultPlan = bbf_backup_restore_plan($faultConfig, $created['path']);
    mkdir("$faultTarget/logs/access-audit.php", 0700);
    $faulted = bbf_backup_restore($faultConfig, $created['path'], $faultPlan['confirmation']);
    backup_check(($faulted['reason'] ?? null) === 'storage' && !is_file("$faultTarget/forms/alpha.json")
        && !file_exists("$faultTarget/submissions/alpha") && !file_exists("$faultTarget/forms/.versions/alpha")
        && is_dir("$faultTarget/forms/.versions") && !file_exists("$faultTarget/submissions/.delivery/alpha")
        && is_dir("$faultTarget/logs/access-audit.php"),
        'late restore failure rolls back form data while preserving pre-existing target directories');
    rmdir("$faultTarget/logs/access-audit.php");
    $faultRetry = bbf_backup_restore($faultConfig, $created['path'], $faultPlan['confirmation']);
    backup_check(($faultRetry['ok'] ?? false) === true,
        'rolled-back empty target accepts the same reviewed confirmation on retry');
    bbf_test_cleanup($faultTarget);

    $raceTarget = bbf_test_installation(dirname(__DIR__));
    bbf_test_remove_dir("$raceTarget/forms"); mkdir("$raceTarget/forms", 0700);
    $raceConfig = ['storage' => 'file', 'forms_dir' => "$raceTarget/forms", 'submissions_dir' => "$raceTarget/submissions",
        'logs_dir' => "$raceTarget/logs", 'api_token' => '', 'access_tokens' => $targetAccess,
        'backup' => ['directory' => $backupDir]];
    backup_write_config($raceTarget, $raceConfig);
    $racePlan = bbf_backup_restore_plan($raceConfig, $created['path']);
    $raceLock = fopen($backupDir . '/.alpha.restore', 'c');
    if (!$raceLock || !flock($raceLock, LOCK_EX)) throw new RuntimeException('Cannot hold restore race lock.');
    $processes = []; $processPipes = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, "$raceTarget/maintenance.php", 'restore', '--bundle=' . $created['path'],
            '--apply', '--confirm=' . $racePlan['confirmation']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raceTarget);
        if (!is_resource($process)) throw new RuntimeException('Cannot start restore race worker.');
        fclose($pipes[0]); $processes[] = $process; $processPipes[] = $pipes;
    }
    usleep(100000); flock($raceLock, LOCK_UN); fclose($raceLock);
    $raceResults = [];
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($processPipes[$index][1]); fclose($processPipes[$index][1]);
        $stderr = stream_get_contents($processPipes[$index][2]); fclose($processPipes[$index][2]);
        $raceResults[] = ['code' => proc_close($process), 'json' => json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
            'stderr' => $stderr];
    }
    $winners = array_filter($raceResults, static fn(array $result): bool => $result['code'] === 0 && ($result['json']['ok'] ?? false));
    $losers = array_filter($raceResults, static fn(array $result): bool => $result['code'] === 1
        && ($result['json']['reason'] ?? null) === 'confirmation');
    backup_check(count($winners) === 1 && count($losers) === 1
        && bbf_read_file("$raceTarget/submissions/alpha/bbf_alpha.json", 'alpha') === $record,
        'concurrent confirmed restores serialize to one winner and one non-overwriting occupied-target rejection');
    bbf_test_cleanup($raceTarget);
} finally {
    bbf_test_cleanup($backupDir);
    bbf_test_cleanup($source);
    bbf_test_cleanup($target);
}

$portableBackends = ['csv'];
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) $portableBackends[] = 'sqlite';
foreach ($portableBackends as $backend) {
    $source = bbf_test_installation(dirname(__DIR__));
    $target = bbf_test_installation(dirname(__DIR__));
    $backupDir = dirname($source) . "/bbf $backend backups " . bin2hex(random_bytes(8));
    $GLOBALS['bbf_test_roots'][$backupDir] = true;
    try {
        foreach ([$source, $target] as $root) { bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700); }
        $definition = ['id' => 'alpha', 'name' => "Alpha $backend", 'storage' => $backend,
            'fields' => [['name' => 'answer', 'type' => 'text']]];
        $historicalDefinition = ['id' => 'alpha', 'name' => "Historical Alpha $backend", 'storage' => $backend,
            'fields' => [['name' => 'answer', 'type' => 'text'], ['name' => 'retired_answer', 'type' => 'text']]];
        file_put_contents("$source/forms/alpha.json", json_encode($definition, JSON_THROW_ON_ERROR));
        $record = ['id' => '123', 'form' => 'alpha',
            'data' => ['answer' => '=private-formula', 'retired_answer' => 'historical-only'],
            'meta' => ['submitted' => '2026-09-09T01:02:03Z', 'ip' => '', 'user_agent' => 'fixture',
                'definition_version' => bbf_version_id($historicalDefinition), 'form_definition' => $historicalDefinition]];
        if ($backend === 'csv') {
            $fp = fopen("$source/submissions/alpha.csv", 'wb');
            fputcsv($fp, ['_id', '_submitted', '_ip', '_user_agent', '__bbf:definition_version',
                '__bbf:form_definition', '__bbf:csv_escaped_fields', 'answer', 'retired_answer'], ',', '"', '');
            fputcsv($fp, [$record['id'], $record['meta']['submitted'], '', 'fixture',
                $record['meta']['definition_version'], bbf_storage_json($historicalDefinition), bbf_storage_json(['answer']),
                "'=private-formula", 'historical-only'], ',', '"', '');
            fclose($fp);
        } else {
            $pdo = new PDO("sqlite:$source/submissions/bbf.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT COLLATE BINARY NOT NULL,
                data TEXT NOT NULL, meta TEXT NOT NULL, created_at TEXT NOT NULL)');
            $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([$record['id'], 'alpha',
                bbf_storage_json($record['data']), bbf_storage_json($record['meta']), $record['meta']['submitted']]);
            $pdo = null;
        }
        $access = [['id' => 'reviewer', 'token' => 'PORTABLE-SOURCE-SECRET', 'forms' => ['alpha'],
            'permissions' => ['read', 'review'], 'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false]];
        $sourceConfig = ['storage' => 'file', 'forms_dir' => "$source/forms", 'submissions_dir' => "$source/submissions",
            'logs_dir' => "$source/logs", 'sqlite' => ['path' => "$source/submissions/bbf.sqlite"],
            'api_token' => '', 'access_tokens' => $access, 'backup' => ['directory' => $backupDir]];
        bbf_version_state($sourceConfig, 'alpha');
        backup_check((bbf_review_update($sourceConfig, 'alpha', $record['id'], 'reviewer',
            ['notes' => "$backend note"], 0)['ok'] ?? false) === true
            && (bbf_review_filter_save($sourceConfig, 'alpha', 'reviewer', 'mine', 'Mine', ['status' => 'new'], 0)['ok'] ?? false) === true
            && (bbf_review_delete_records($sourceConfig, 'alpha', ['bbf_deleted'])['ok'] ?? false) === true,
            "$backend source has review, filter and tombstone relationships");
        $deliveryPath = bbf_outbox_path($sourceConfig, 'alpha', $record['id']);
        bbf_outbox_init($deliveryPath, 'alpha:' . $record['id'], [], 3, 10);
        bbf_audit_write($sourceConfig, ['id' => 'fixture'], 'viewer_read', 'alpha', [$record['id']], 'allowed', 'completed', 1);
        $corruptRejected = false;
        if ($backend === 'csv') {
            $storagePath = "$source/submissions/alpha.csv";
            $cleanBytes = file_get_contents($storagePath);
            $fp = fopen($storagePath, 'ab');
            fputcsv($fp, array_fill(0, 10, 'corrupt'), ',', '"', '');
            fclose($fp);
            try { bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T11:59:59Z')); }
            catch (RuntimeException $error) { $corruptRejected = str_contains($error->getMessage(), 'Invalid stored CSV submission'); }
            unset($error);
            file_put_contents($storagePath, $cleanBytes);
            $truncatedBytes = $cleanBytes . 'bbf_truncated,2026-09-09T01:02:04Z,,,,,,"unterminated';
            file_put_contents($storagePath, $truncatedBytes);
            $truncatedRejected = false;
            try { bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T11:59:59Z')); }
            catch (RuntimeException $error) { $truncatedRejected = str_contains($error->getMessage(), 'Invalid stored CSV submission'); }
            unset($error);
            backup_check($truncatedRejected && file_get_contents($storagePath) === $truncatedBytes && !is_dir($backupDir),
                'csv backup rejects an unterminated quoted record before publication and preserves exact source bytes');
            file_put_contents($storagePath, $cleanBytes);
        } else {
            $pdo = new PDO("sqlite:$source/submissions/bbf.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute(
                ['corrupt', 'alpha', '{', '{}', '2026-09-09T01:02:04Z']);
            try { bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T11:59:59Z')); }
            catch (RuntimeException $error) { $corruptRejected = str_contains($error->getMessage(), 'Invalid stored database submission'); }
            unset($error);
            $pdo->prepare('DELETE FROM bbf_submissions WHERE id = ?')->execute(['corrupt']);
            $pdo = null;
        }
        backup_check($corruptRejected && !is_dir($backupDir),
            "$backend backup rejects a malformed source record before publishing a bundle");
        $bundle = bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T12:00:00Z'));
        $targetAccess = $access; $targetAccess[0]['token'] = 'PORTABLE-TARGET-SECRET';
        $targetConfig = ['storage' => 'file', 'forms_dir' => "$target/forms", 'submissions_dir' => "$target/submissions",
            'logs_dir' => "$target/logs", 'sqlite' => ['path' => "$target/submissions/bbf.sqlite"],
            'api_token' => '', 'access_tokens' => $targetAccess, 'backup' => ['directory' => $backupDir]];
        $plan = bbf_backup_restore_plan($targetConfig, $bundle['path']);
        backup_check(($plan['empty'] ?? false) === true && is_string($plan['confirmation']),
            "$backend restore plans against an empty backend target without mutation");
        if ($backend === 'sqlite') {
            mkdir("$target/logs/access-audit.php", 0700);
            $faulted = bbf_backup_restore($targetConfig, $bundle['path'], $plan['confirmation']);
            backup_check(($faulted['reason'] ?? null) === 'storage' && !is_file("$target/forms/alpha.json")
                && !is_file("$target/submissions/bbf.sqlite") && !is_file("$target/logs/access-audit.php"),
                'sqlite failure after database commit compensates exact-form SQL and all created files');
            rmdir("$target/logs/access-audit.php");
        }
        $restored = bbf_backup_restore($targetConfig, $bundle['path'], $plan['confirmation']);
        $targetEffective = bbf_effective_storage_config($targetConfig, 'alpha');
        $records = iterator_to_array(bbf_read_export('alpha', $targetEffective, PHP_INT_MAX, 0, null, null), false);
        backup_check(($restored['ok'] ?? false) === true && count($records) === 1 && $records[0] === $record
            && ($records[0]['data']['retired_answer'] ?? null) === 'historical-only'
            && ($records[0]['meta']['form_definition']['name'] ?? null) === "Historical Alpha $backend",
            "$backend restore recreates the exact historical logical submission and retired schema data");
        $bundleDocument = bbf_backup_bundle_read($targetConfig, $bundle['path']);
        backup_check(bbf_backup_review_capture($targetConfig, 'alpha') === $bundleDocument['payload']['review'],
            "$backend restore preserves review rows, filters and tombstones");
        backup_check((bbf_outbox_read(bbf_outbox_existing_path($targetConfig, 'alpha', $record['id']))['ledger'] ?? null)
            === ($bundleDocument['payload']['delivery'][(int)$record['id']]['ledger'] ?? null)
            && bbf_version_state($targetConfig, 'alpha')['definition']['name'] === "Alpha $backend",
            "$backend restore preserves delivery and version relationships");
        $occupied = bbf_backup_restore_plan($targetConfig, $bundle['path']);
        backup_check(($occupied['empty'] ?? true) === false && ($occupied['confirmation'] ?? null) === null,
            "$backend restore refuses a populated target");
    } finally {
        bbf_test_cleanup($backupDir);
        bbf_test_cleanup($source);
        bbf_test_cleanup($target);
    }
}
print "Backup review tests: $checks passed\n";
