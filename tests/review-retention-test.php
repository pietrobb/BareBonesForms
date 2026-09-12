<?php
/** G9 F6 safe-off retention, archive and restore regression; disposable fixtures only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_retention.php';
require_once dirname(__DIR__) . '/bbf_review.php';
require_once dirname(__DIR__) . '/bbf_outbox.php';

$checks = 0;
function retention_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    print "PASS $message\n";
}
function retention_record(string $id, string $form, string $submitted): array {
    return ['id' => $id, 'form' => $form, 'data' => ['answer' => "private-$id"],
        'meta' => ['submitted' => $submitted, 'definition_version' => 'v1-' . str_repeat('a', 64)]];
}
function retention_seed(string $root, string $storage, array $records): array {
    foreach (['alpha', 'beta'] as $form) {
        file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => ucfirst($form),
            'storage' => $storage, 'fields' => [['name' => 'answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
    }
    $pdo = null;
    if ($storage === 'file') {
        foreach (['alpha', 'beta'] as $form) mkdir("$root/submissions/$form", 0700);
        foreach ($records as $record) file_put_contents("$root/submissions/{$record['form']}/{$record['id']}.json",
            json_encode($record, JSON_THROW_ON_ERROR));
    } elseif ($storage === 'csv') {
        $handles = [];
        foreach (['alpha', 'beta'] as $form) {
            $handles[$form] = fopen("$root/submissions/$form.csv", 'wb');
            fputcsv($handles[$form], ['_id', '_submitted', '_ip', '_user_agent', '__bbf:definition_version', 'answer'], ',', '"', '');
        }
        foreach ($records as $record) fputcsv($handles[$record['form']], [$record['id'], $record['meta']['submitted'], '', '',
            $record['meta']['definition_version'], $record['data']['answer']], ',', '"', '');
        foreach ($handles as $handle) fclose($handle);
    } else {
        $pdo = new PDO("sqlite:$root/submissions/bbf.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT COLLATE NOCASE, data TEXT, meta TEXT, created_at TEXT)');
        $insert = $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)');
        foreach ($records as $record) $insert->execute([$record['id'], $record['form'], json_encode($record['data'], JSON_THROW_ON_ERROR),
            json_encode($record['meta'], JSON_THROW_ON_ERROR), str_replace('T', ' ', rtrim($record['meta']['submitted'], 'Z'))]);
    }
    return [$pdo];
}

retention_check(bbf_retention_policy([]) === ['enabled' => false, 'days' => 0, 'archive_dir' => '', 'batch_limit' => 100],
    'missing retention configuration is safely disabled');
foreach ([true, [], ['enabled' => 'yes'], ['enabled' => true, 'days' => 0, 'archive_dir' => '/tmp'],
    ['enabled' => true, 'days' => 30, 'archive_dir' => ''], ['enabled' => false, 'extra' => true],
    ['enabled' => false, 'days' => 0, 'archive_dir' => '', 'batch_limit' => 101]] as $invalid) {
    $threw = false;
    try { bbf_retention_policy(['retention' => $invalid]); } catch (InvalidArgumentException $error) { $threw = true; }
    retention_check($threw, 'malformed or unsafe retention configuration fails closed');
}
$disabled = bbf_retention_plan(['retention' => ['enabled' => false]], 'alpha', strtotime('2026-09-10T00:00:00Z'));
retention_check($disabled['enabled'] === false && $disabled['count'] === 0 && $disabled['ids'] === []
    && $disabled['confirmation'] === null, 'disabled retention performs no storage discovery and yields no confirmation');

$storages = ['file', 'csv'];
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) $storages[] = 'sqlite';
$now = strtotime('2026-09-10T00:00:00Z');
foreach ($storages as $storage) {
    $root = bbf_test_installation(dirname(__DIR__));
    $archiveRoot = dirname($root) . '/bbf retention archives ' . bin2hex(random_bytes(8));
    $GLOBALS['bbf_test_roots'][$archiveRoot] = true;
    $pdo = null;
    try {
        bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
        $records = [
            retention_record('bbf_old_b', 'alpha', '2020-01-02T00:00:00Z'),
            retention_record('bbf_recent', 'alpha', '2026-09-09T00:00:01Z'),
            retention_record('bbf_boundary', 'alpha', '2026-08-11T00:00:00Z'),
            retention_record('bbf_invalid_time', 'alpha', 'not-a-time'),
            retention_record('bbf_invalid_date', 'alpha', '2020-02-30T00:00:00Z'),
            retention_record('bbf_invalid_offset', 'alpha', '2020-01-01T00:00:00+24:00'),
            retention_record('bbf_old_a', 'alpha', '2020-01-01T00:00:00+00:00'),
            retention_record('bbf_other_form', 'beta', '2020-01-01T00:00:00Z'),
        ];
        [$pdo] = retention_seed($root, $storage, $records);
        $config = ['storage' => $storage, 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
            'logs_dir' => "$root/logs", 'sqlite' => ['path' => "$root/submissions/bbf.sqlite"],
            'retention' => ['enabled' => true, 'days' => 30, 'archive_dir' => $archiveRoot, 'batch_limit' => 100]];
        if ($storage === 'file' || $storage === 'csv') {
            $canonical = "$root/submissions/alpha" . ($storage === 'csv' ? '.csv' : '');
            $temporary = "$root/submissions/alpha-case-swap" . ($storage === 'csv' ? '.csv' : '');
            $alias = "$root/submissions/ALPHA" . ($storage === 'csv' ? '.csv' : '');
            rename($canonical, $temporary); rename($temporary, $alias);
            $threw = false;
            try { bbf_retention_plan($config, 'alpha', $now); } catch (RuntimeException $error) { $threw = true; }
            retention_check($threw, "$storage case-alias storage target fails closed before planning or deletion");
            rename($alias, $temporary); rename($temporary, $canonical);
        }
        $badStorage = $config; $badStorage['storage'] = 'typo';
        $threw = false;
        try { bbf_retention_plan($badStorage, 'alpha', $now); } catch (InvalidArgumentException $error) { $threw = true; }
        retention_check($threw, "$storage malformed global backend fails closed before retention planning");
        $before = $storage === 'file'
            ? array_map('file_get_contents', glob("$root/submissions/alpha/*.json"))
            : ($storage === 'csv' ? file_get_contents("$root/submissions/alpha.csv")
                : (int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn());
        $plan = bbf_retention_plan($config, 'alpha', $now);
        retention_check($plan['backend'] === $storage && $plan['cutoff'] === '2026-08-11T00:00:00Z'
            && $plan['ids'] === ['bbf_old_a', 'bbf_old_b'] && $plan['count'] === 2
            && preg_match('/\Aretention-[a-f0-9]{64}\z/D', $plan['confirmation']) === 1,
            "$storage dry-run selects only strictly expired exact-form records in deterministic order");
        retention_check($plan === bbf_retention_plan($config, 'alpha', $now + 3600),
            "$storage repeated same-UTC-day dry-run has a stable bound confirmation digest");
        $alternateArchive = dirname($root) . '/bbf alternate retention ' . bin2hex(random_bytes(8));
        $GLOBALS['bbf_test_roots'][$alternateArchive] = true;
        $changedArchive = $config; $changedArchive['retention']['archive_dir'] = $alternateArchive;
        $changedDestination = bbf_retention_apply($changedArchive, 'alpha', $plan['confirmation'], $now);
        retention_check(($changedDestination['reason'] ?? '') === 'confirmation' && !file_exists($alternateArchive),
            "$storage confirmation binds the reviewed archive destination without mutating a replacement path");
        bbf_test_cleanup($alternateArchive);
        $limited = $config; $limited['retention']['batch_limit'] = 1;
        retention_check(bbf_retention_plan($limited, 'alpha', $now)['ids'] === ['bbf_old_a'],
            "$storage configured batch limit bounds one deterministic retention application");
        $after = $storage === 'file'
            ? array_map('file_get_contents', glob("$root/submissions/alpha/*.json"))
            : ($storage === 'csv' ? file_get_contents("$root/submissions/alpha.csv")
                : (int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn());
        retention_check($after === $before, "$storage dry-run leaves every primary byte or row unchanged");
        retention_check(bbf_retention_plan($config, 'beta', $now)['ids'] === ['bbf_other_form'],
            "$storage same-age records remain isolated by exact form ID");

        $review = bbf_review_update($config, 'alpha', 'bbf_old_a', 'retention-reviewer',
            ['status' => 'done', 'notes' => "archived private note\nsecond line", 'tags' => ['archive']], 0);
        retention_check(($review['ok'] ?? false) === true, "$storage disposable review relationship is seeded");
        $capturedReview = ['bbf_old_a' => $review['review']];
        $concurrentReview = bbf_review_update($config, 'alpha', 'bbf_old_a', 'concurrent-reviewer',
            ['notes' => 'concurrent review must survive stale purge'], 1);
        $staleReviewPurge = bbf_review_delete_records_if($config, 'alpha', $capturedReview);
        retention_check(($concurrentReview['ok'] ?? false) === true && ($staleReviewPurge['reason'] ?? '') === 'conflict'
            && bbf_review_get($config, 'alpha', 'bbf_old_a')['notes'] === 'concurrent review must survive stale purge',
            "$storage conditional review purge preserves a revision created after archive capture");
        $currentReview = bbf_review_get($config, 'alpha', 'bbf_old_a');
        $reviewBytesBefore = in_array($storage, ['file', 'csv'], true)
            ? file_get_contents(bbf_review_file_path($config, 'alpha')) : null;
        $coordinatedFailure = bbf_review_delete_records_if($config, 'alpha', ['bbf_old_a' => $currentReview],
            static fn($pdo) => ['ok' => false, 'deleted' => false, 'reason' => 'fixture-primary']);
        retention_check(($coordinatedFailure['reason'] ?? '') === 'fixture-primary'
            && bbf_review_get($config, 'alpha', 'bbf_old_a') === $currentReview
            && ($reviewBytesBefore === null || file_get_contents(bbf_review_file_path($config, 'alpha')) === $reviewBytesBefore),
            "$storage failed primary deletion restores or rolls back the exact active review relationship");
        $outboxPath = bbf_outbox_path($config, 'alpha', 'bbf_old_a');
        $outbox = bbf_outbox_init($outboxPath, 'alpha:bbf_old_a', [[
            'key' => 'archive-job', 'type' => 'action', 'payload_hash' => str_repeat('b', 64),
            'idempotency_key' => 'archive-job', 'target' => 'fixture', 'idempotent' => true,
        ]], 3, $now);
        retention_check(($outbox['ok'] ?? false) === true, "$storage disposable delivery relationship is seeded");
        if ($storage === 'file') {
            $absentPath = bbf_outbox_existing_path($config, 'alpha', 'bbf_recent');
            $sawAbsentLocks = false; $primaryCalled = false;
            $absentPreflight = bbf_outbox_delete_submissions([['path' => $absentPath,
                'delete' => static function () use (&$primaryCalled): array { $primaryCalled = true; return ['ok' => true, 'deleted' => true]; }]],
                static function (array $snapshots) use ($absentPath, &$sawAbsentLocks): bool {
                    $sawAbsentLocks = isset($snapshots[$absentPath], $snapshots[$absentPath . '.events'])
                        && !$snapshots[$absentPath]['exists'] && !$snapshots[$absentPath . '.events']['exists'];
                    return false;
                });
            retention_check(($absentPreflight['reason'] ?? '') === 'archive' && $sawAbsentLocks && !$primaryCalled
                && !is_file($absentPath), 'absent delivery paths are locked and snapshotted before primary deletion');
            $unsafe = $config; $unsafe['retention']['archive_dir'] = "$root/submissions/retention-archives";
            $threw = false;
            try { bbf_retention_apply($unsafe, 'alpha', $plan['confirmation'], $now); } catch (RuntimeException $error) { $threw = true; }
            retention_check($threw && bbf_retention_plan($config, 'alpha', $now) === $plan,
                'archive path inside application data is refused before any deletion');
            $claim = bbf_outbox_claim($outboxPath, 'archive-job', $now);
            $running = bbf_retention_apply($config, 'alpha', $plan['confirmation'], $now);
            retention_check(($claim['ok'] ?? false) === true && ($running['reason'] ?? '') === 'running'
                && ($running['deleted'] ?? -1) === 0 && bbf_retention_plan($config, 'alpha', $now) === $plan,
                'running delivery blocks retention before archive or primary deletion');
            bbf_outbox_complete($outboxPath, 'archive-job', $claim['token'],
                ['ok' => true, 'state' => 'succeeded', 'stage' => 'action', 'retryable' => false], $now);
            $retryRecords = bbf_retention_capture_records($config, 'alpha', $plan['ids']);
            $retryReviews = bbf_review_records($config, 'alpha', $plan['ids']);
            foreach ($plan['ids'] as $id) if (!isset($retryReviews[$id])) $retryReviews[$id] = null;
            ksort($retryReviews, SORT_STRING);
            $retrySnapshots = [];
            foreach ($plan['ids'] as $id) {
                $base = bbf_outbox_existing_path($config, 'alpha', $id);
                foreach ([$base, $base . '.events'] as $path) {
                    $retrySnapshots[$path] = ['exists' => is_file($path),
                        'bytes' => is_file($path) ? file_get_contents($path) : null];
                }
            }
            $retryArchive = bbf_retention_archive_write($config, bbf_retention_policy($config), $plan,
                $retryRecords, $retryReviews, $retrySnapshots);
            $retryBytes = file_get_contents($retryArchive);
            $sameRetryArchive = bbf_retention_archive_write($config, bbf_retention_policy($config), $plan,
                $retryRecords, $retryReviews, $retrySnapshots);
            $retryDocument = json_decode($retryBytes, true, 512, JSON_THROW_ON_ERROR);
            retention_check($sameRetryArchive === $retryArchive && file_get_contents($sameRetryArchive) === $retryBytes
                && ($retryDocument['payload']['created_at'] ?? null) === $plan['as_of'],
                'unchanged same-day retry reuses byte-identical archive evidence without a self-collision');
            unlink($retryArchive);
            bbf_retention_archive_directory($config, bbf_retention_policy($config));
            $collision = $archiveRoot . '/alpha-' . substr($plan['confirmation'], -16) . '.json';
            file_put_contents($collision, 'fixture collision');
            $archiveFailure = bbf_retention_apply($config, 'alpha', $plan['confirmation'], $now);
            retention_check(($archiveFailure['reason'] ?? '') === 'archive' && ($archiveFailure['deleted'] ?? -1) === 0
                && file_get_contents($collision) === 'fixture collision' && bbf_retention_plan($config, 'alpha', $now) === $plan,
                'archive collision fails closed without replacing evidence or deleting primaries');
            unlink($collision);
        }
        $wrong = bbf_retention_apply($config, 'alpha', 'retention-' . str_repeat('0', 64), $now);
        retention_check(($wrong['reason'] ?? '') === 'confirmation' && bbf_retention_plan($config, 'alpha', $now) === $plan,
            "$storage wrong confirmation cannot delete or alter the current plan");
        $applied = bbf_retention_apply($config, 'alpha', $plan['confirmation'], $now);
        retention_check(($applied['ok'] ?? false) === true && ($applied['deleted'] ?? null) === 2
            && is_string($applied['archive'] ?? null) && is_file($applied['archive']),
            "$storage exact confirmation archives before deleting the selected batch");
        $archiveBytes = file_get_contents($applied['archive']);
        $archive = json_decode($archiveBytes, true, 512, JSON_THROW_ON_ERROR);
        retention_check(($archive['version'] ?? null) === 1 && hash_equals($archive['sha256'],
            hash('sha256', bbf_storage_json($archive['payload'])))
            && array_keys($archive['payload']['records']) === ['bbf_old_a', 'bbf_old_b']
            && ($archive['payload']['reviews']['bbf_old_a']['notes'] ?? '') === 'concurrent review must survive stale purge'
            && str_contains((string)base64_decode($archive['payload']['delivery']['bbf_old_a']['ledger'] ?? '', true), 'archive-job'),
            "$storage private archive has verified integrity and preserves response, version, note and delivery relationships");
        retention_check(PHP_OS_FAMILY === 'Windows' || ((fileperms($applied['archive']) & 0777) === 0600
            && (fileperms(dirname($applied['archive'])) & 0777) === 0700),
            "$storage archive file and directory are private where POSIX modes apply");
        $remaining = bbf_retention_plan($config, 'alpha', $now);
        retention_check($remaining['ids'] === [] && bbf_retention_plan($config, 'beta', $now)['ids'] === ['bbf_other_form'],
            "$storage confirmed apply deletes only planned exact-form expired records");
        retention_check(bbf_review_get($config, 'alpha', 'bbf_old_a') === bbf_review_default()
            && (bbf_review_update($config, 'alpha', 'bbf_old_a', 'late-reviewer', ['notes' => 'late'], 0)['reason'] ?? '') === 'not_found',
            "$storage retention tombstone removes notes and rejects late metadata resurrection");
        $tombstone = bbf_outbox_read($outboxPath);
        retention_check(($tombstone['ledger']['deleted'] ?? false) === true && ($tombstone['ledger']['jobs'] ?? null) === [],
            "$storage retention tombstones delivery before primary deletion");
        $auditLines = file("$root/logs/access-audit.php", FILE_IGNORE_NEW_LINES);
        $audit = array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_slice($auditLines, -2));
        retention_check(count($audit) === 2 && $audit[0]['action'] === 'retention_apply' && $audit[0]['result'] === 'attempted'
            && $audit[1]['result'] === 'completed' && $audit[1]['result_count'] === 2
            && $audit[0]['submission_ids'] === ['bbf_old_a', 'bbf_old_b'],
            "$storage destructive retention records attempted and completed audit with exact IDs");
        $stale = bbf_retention_apply($config, 'alpha', $plan['confirmation'], $now);
        retention_check(($stale['reason'] ?? '') === 'confirmation' && ($stale['plan']['ids'] ?? null) === [],
            "$storage consumed confirmation cannot authorize a changed plan");
        $pdo = null;
    } finally {
        $pdo = null;
        bbf_test_cleanup($archiveRoot);
        bbf_test_cleanup($root);
    }
}

$auditRoot = bbf_test_installation(dirname(__DIR__));
$auditArchive = dirname($auditRoot) . '/bbf retention audit ' . bin2hex(random_bytes(8));
$GLOBALS['bbf_test_roots'][$auditArchive] = true;
try {
    bbf_test_remove_dir("$auditRoot/forms"); mkdir("$auditRoot/forms", 0700);
    $auditRecords = [];
    for ($index = 0; $index < 100; $index++) {
        $auditRecords[] = retention_record('bbf_audit_' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
            'alpha', '2020-01-01T00:00:00Z');
    }
    retention_seed($auditRoot, 'file', $auditRecords);
    $auditConfig = ['storage' => 'file', 'forms_dir' => "$auditRoot/forms",
        'submissions_dir' => "$auditRoot/submissions", 'logs_dir' => "$auditRoot/logs",
        'retention' => ['enabled' => true, 'days' => 30, 'archive_dir' => $auditArchive, 'batch_limit' => 100]];
    $auditPlan = bbf_retention_plan($auditConfig, 'alpha', $now);
    $auditApplied = bbf_retention_apply($auditConfig, 'alpha', $auditPlan['confirmation'], $now);
    $auditLines = file("$auditRoot/logs/access-audit.php", FILE_IGNORE_NEW_LINES);
    $completedAudit = json_decode($auditLines[count($auditLines) - 1], true, 512, JSON_THROW_ON_ERROR);
    retention_check(($auditApplied['ok'] ?? false) === true && ($auditApplied['deleted'] ?? 0) === 100
        && count($completedAudit['submission_ids'] ?? []) === 100 && ($completedAudit['result_count'] ?? 0) === 100,
        'maximum retention batch records every affected ID and exact result count in terminal audit');
} finally {
    bbf_test_cleanup($auditArchive);
    bbf_test_cleanup($auditRoot);
}

$shutdownRoot = bbf_test_installation(dirname(__DIR__));
try {
    $shutdownScript = <<<'PHP'
<?php
define('BBF_LOADED', true);
require __DIR__ . '/bbf_retention.php';
$config = ['logs_dir' => __DIR__ . '/logs'];
bbf_retention_audit_begin($config, ['id' => 'retention-cli'], 'alpha', ['bbf_shutdown']);
exit(7);
PHP;
    file_put_contents("$shutdownRoot/retention-shutdown.php", $shutdownScript);
    $pipes = [];
    $process = proc_open([PHP_BINARY, "$shutdownRoot/retention-shutdown.php"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $shutdownRoot);
    if (!is_resource($process)) throw new RuntimeException('Cannot start retention shutdown fixture.');
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $shutdownLines = file("$shutdownRoot/logs/access-audit.php", FILE_IGNORE_NEW_LINES);
    $shutdownAudit = array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_slice($shutdownLines, -2));
    retention_check($exit === 7 && count($shutdownAudit) === 2
        && $shutdownAudit[0]['result'] === 'attempted' && $shutdownAudit[1]['result'] === 'failed'
        && $shutdownAudit[1]['submission_ids'] === ['bbf_shutdown'],
        'abnormal process exit after attempted retention records a terminal failed audit');
} finally {
    bbf_test_cleanup($shutdownRoot);
}

$corruptRoot = bbf_test_installation(dirname(__DIR__));
$corruptArchive = dirname($corruptRoot) . '/bbf corrupt retention ' . bin2hex(random_bytes(8));
$GLOBALS['bbf_test_roots'][$corruptArchive] = true;
try {
    bbf_test_remove_dir("$corruptRoot/forms"); mkdir("$corruptRoot/forms", 0700);
    file_put_contents("$corruptRoot/forms/alpha.json", json_encode(['id' => 'alpha', 'storage' => 'csv',
        'fields' => [['name' => 'answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
    $corruptPath = "$corruptRoot/submissions/alpha.csv";
    $corruptBytes = "_id,_submitted,_ip,_user_agent,answer\n"
        . "bbf_old,2020-01-01T00:00:00Z,ip,ua,\"unterminated\n"
        . "bbf_recent,2026-09-09T00:00:01Z,ip,ua,recent\n";
    file_put_contents($corruptPath, $corruptBytes);
    $corruptConfig = ['storage' => 'csv', 'forms_dir' => "$corruptRoot/forms",
        'submissions_dir' => "$corruptRoot/submissions", 'logs_dir' => "$corruptRoot/logs",
        'retention' => ['enabled' => true, 'days' => 30, 'archive_dir' => $corruptArchive, 'batch_limit' => 100]];
    $corruptRejected = false;
    try { bbf_retention_plan($corruptConfig, 'alpha', $now); }
    catch (RuntimeException $error) { $corruptRejected = str_contains($error->getMessage(), 'Invalid stored CSV submission'); }
    $tolerantRecords = iterator_to_array(bbf_read_csv('alpha', "$corruptRoot/submissions"), false);
    retention_check(count($tolerantRecords) === 1 && ($tolerantRecords[0]['id'] ?? null) === 'bbf_old'
        && str_contains($tolerantRecords[0]['data']['answer'] ?? '', 'bbf_recent'),
        '6129-F01 fixture proves permissive CSV parsing swallows the later physical record');
    $deleteResult = bbf_retention_delete_csv($corruptConfig, 'alpha', [
        'bbf_old' => $tolerantRecords[0],
    ]);
    retention_check($corruptRejected && ($deleteResult['ok'] ?? true) === false
        && file_get_contents($corruptPath) === $corruptBytes && !is_dir($corruptArchive),
        '6129-F01 malformed CSV blocks retention planning and deletion without changing source bytes or publishing an archive');
} finally {
    bbf_test_cleanup($corruptArchive);
    bbf_test_cleanup($corruptRoot);
}

$numericRoot = bbf_test_installation(dirname(__DIR__));
$numericArchive = dirname($numericRoot) . '/bbf numeric retention ' . bin2hex(random_bytes(8));
$GLOBALS['bbf_test_roots'][$numericArchive] = true;
try {
    bbf_test_remove_dir("$numericRoot/forms"); mkdir("$numericRoot/forms", 0700);
    file_put_contents("$numericRoot/forms/alpha.json", json_encode(['id' => 'alpha', 'storage' => 'csv',
        'fields' => [['name' => 'answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
    $numericRecord = retention_record('123', 'alpha', '2020-01-01T00:00:00Z');
    $numericCsv = fopen("$numericRoot/submissions/alpha.csv", 'wb');
    fputcsv($numericCsv, ['_id', '_submitted', '_ip', '_user_agent', '__bbf:definition_version', 'answer'], ',', '"', '');
    fputcsv($numericCsv, ['123', $numericRecord['meta']['submitted'], '', '',
        $numericRecord['meta']['definition_version'], $numericRecord['data']['answer']], ',', '"', '');
    fclose($numericCsv);
    $numericConfig = ['storage' => 'csv', 'forms_dir' => "$numericRoot/forms",
        'submissions_dir' => "$numericRoot/submissions", 'logs_dir' => "$numericRoot/logs",
        'retention' => ['enabled' => true, 'days' => 30, 'archive_dir' => $numericArchive, 'batch_limit' => 100]];
    $numericReview = bbf_review_update($numericConfig, 'alpha', '123', 'reviewer', ['notes' => 'private numeric note'], 0);
    $numericPlan = bbf_retention_plan($numericConfig, 'alpha', $now);
    $numericApplied = bbf_retention_apply($numericConfig, 'alpha', $numericPlan['confirmation'], $now);
    retention_check(($numericReview['ok'] ?? false) === true,
        '6129-F06 numeric-string submission ID can own review metadata');
    retention_check($numericPlan['ids'] === ['123'],
        '6129-F06 numeric-string submission ID is retained as a string in the reviewed plan');
    retention_check(($numericApplied['ok'] ?? false) === true && ($numericApplied['deleted'] ?? 0) === 1,
        '6129-F06 numeric-string submission ID survives capture maps and completes retention apply');
    $numericArchiveDocument = json_decode(file_get_contents($numericApplied['archive']), true, 512, JSON_THROW_ON_ERROR);
    retention_check(($numericArchiveDocument['payload']['records'][123]['id'] ?? null) === '123'
        && ($numericArchiveDocument['payload']['reviews'][123]['notes'] ?? null) === 'private numeric note'
        && hash_equals($numericArchiveDocument['sha256'], hash('sha256', bbf_storage_json($numericArchiveDocument['payload']))),
        '6129-F06 numeric-string archive preserves the exact response and private review relationship with valid integrity');
    retention_check(iterator_to_array(bbf_read_csv('alpha', "$numericRoot/submissions"), false) === [],
        '6129-F06 numeric-string primary record is deleted only after archive publication');
    retention_check(bbf_review_get($numericConfig, 'alpha', '123') === bbf_review_default(),
        '6129-F06 numeric-string review metadata is tombstoned with its primary record');
} finally {
    bbf_test_cleanup($numericArchive);
    bbf_test_cleanup($numericRoot);
}

$threw = false;
try { bbf_retention_plan([], '../operator', $now); } catch (InvalidArgumentException $error) { $threw = true; }
retention_check($threw, 'invalid form scope is rejected before storage access');
print "Retention review tests: $checks passed\n";
