<?php

declare(strict_types=1);

define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_storage.php';
require_once dirname(__DIR__) . '/bbf_outbox.php';

if (($argv[1] ?? '') === '--claim') {
    $result = bbf_outbox_claim($argv[2], 'webhook:0', 100);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}

$passed = 0;
$failed = 0;
function check_outbox(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS $message\n";
    } else {
        $failed++;
        echo "FAIL $message\n";
    }
}

$root = sys_get_temp_dir() . '/bbf-outbox-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);

$pathConfig = ['submissions_dir' => $root . '/uncreated-submissions'];
$existingPath = bbf_outbox_existing_path($pathConfig, 'contact_form', 'bbf_123');
check_outbox($existingPath === $root . '/uncreated-submissions/.delivery/contact_form/bbf_123.json',
    'existing path resolves the canonical ledger location');
check_outbox(!is_dir($root . '/uncreated-submissions'), 'existing path lookup does not create directories');
check_outbox(bbf_outbox_existing_path($pathConfig, '../contact', 'bbf_123') === ''
    && bbf_outbox_existing_path($pathConfig, 'contact', 'bad/id') === ''
    && bbf_outbox_existing_path($pathConfig, '', 'bbf_123') === '', 'existing path rejects unsafe or empty IDs');
$missingStatus = bbf_outbox_status($existingPath);
check_outbox($missingStatus === ['ok' => true, 'state' => 'none', 'settled' => true, 'jobs' => []],
    'missing ledger has a safe none status');
check_outbox(!is_dir($root . '/uncreated-submissions'), 'missing status lookup creates no directory');
$creatingPath = bbf_outbox_path($pathConfig, 'contact_form', 'bbf_123');
check_outbox($creatingPath === $existingPath && is_dir(dirname($creatingPath)), 'creating path retains legacy directory creation behavior');

$path = $root . '/response.delivery.json';
$jobs = [
    ['key' => 'confirm', 'type' => 'smtp', 'payload_hash' => hash('sha256', 'mail'), 'idempotency_key' => 'response:1:confirm', 'target' => 'u***@example.com', 'idempotent' => false,
        'payload' => ['recipient' => 'private@example.com', 'body' => 'private mail body']],
    ['key' => 'webhook:0', 'type' => 'webhook', 'payload_hash' => hash('sha256', 'hook'), 'idempotency_key' => 'response:1:webhook:0', 'target' => 'https://example.com/***', 'idempotent' => true,
        'payload' => ['url' => 'https://example.com/private', 'token' => 'payload-secret']],
];

$result = bbf_outbox_init($path, 'form:response-1', $jobs, 3, 10);
check_outbox($result['ok'] && $result['created'], 'ledger is durably created before side effects');
check_outbox(is_file($path), 'ledger file exists');
check_outbox(count($result['ledger']['jobs']) === 2, 'immutable job plan contains every action');
check_outbox($result['ledger']['jobs']['webhook:0']['idempotency_key'] === 'response:1:webhook:0', 'stable idempotency key is persisted');
check_outbox($result['ledger']['jobs']['confirm']['payload'] === $jobs[0]['payload'], 'optional execution payload is durably persisted');
$initialStatus = bbf_outbox_status($path);
check_outbox($initialStatus['ok'] && $initialStatus['state'] === 'processing' && !$initialStatus['settled'],
    'pending jobs aggregate as processing');
$initialJson = json_encode($initialStatus, JSON_THROW_ON_ERROR);
check_outbox(!str_contains($initialJson, 'private@example.com') && !str_contains($initialJson, 'payload-secret')
    && !str_contains($initialJson, 'payload_hash') && !str_contains($initialJson, 'idempotency')
    && !str_contains($initialJson, 'target') && !str_contains($initialJson, 'lease_token'),
    'status recursively redacts payloads, hashes, idempotency keys, targets, and leases');
check_outbox(array_keys($initialStatus['jobs'][0]) === [
    'key', 'type', 'state', 'attempts', 'max_attempts', 'next_retry', 'last_result', 'can_retry', 'requires_confirmation',
], 'status job projection contains only the safe required fields before history exists');

$again = bbf_outbox_init($path, 'changed-key', [], 9, 20);
check_outbox($again['ok'] && !$again['created'], 'replay does not replace existing job plan');
check_outbox(count($again['ledger']['jobs']) === 2, 'replay preserves original jobs');
check_outbox($again['ledger']['jobs']['confirm']['payload'] === $jobs[0]['payload'], 'replay preserves immutable execution payload');

$succeededPath = $root . '/succeeded.json';
bbf_outbox_init($succeededPath, 'form:succeeded', [$jobs[0]], 3, 1);
$succeededClaim = bbf_outbox_claim($succeededPath, 'confirm', 2);
bbf_outbox_complete($succeededPath, 'confirm', $succeededClaim['token'], ['ok' => true, 'stage' => 'final_reply', 'code' => 250], 3);
$succeededStatus = bbf_outbox_status($succeededPath);
check_outbox($succeededStatus['state'] === 'succeeded' && $succeededStatus['settled'], 'all successful jobs aggregate as succeeded and settled');
$succeededBeforeRetry = file_get_contents($succeededPath);
$succeededRetry = bbf_outbox_retry($succeededPath, 'confirm', true, 4);
check_outbox(!$succeededRetry['ok'] && $succeededRetry['reason'] === 'succeeded'
    && file_get_contents($succeededPath) === $succeededBeforeRetry, 'retry never changes a succeeded job');

$claim = bbf_outbox_claim($path, 'confirm', 20);
check_outbox($claim['ok'] && strlen($claim['token']) === 32, 'pending job receives durable lease token');
$second = bbf_outbox_claim($path, 'confirm', 20);
check_outbox(!$second['ok'] && $second['reason'] === 'running', 'running job cannot be claimed twice');
$wrong = bbf_outbox_complete($path, 'confirm', 'wrong', ['ok' => true], 21);
check_outbox(!$wrong['ok'] && $wrong['reason'] === 'lease', 'wrong worker cannot complete a lease');
$done = bbf_outbox_complete($path, 'confirm', $claim['token'], ['ok' => true, 'stage' => 'final_reply', 'code' => 250], 22);
check_outbox($done['ok'] && $done['job']['state'] === 'succeeded', 'successful outcome is checkpointed');
$afterDone = bbf_outbox_claim($path, 'confirm', 30);
check_outbox(!$afterDone['ok'] && $afterDone['reason'] === 'succeeded', 'completed side effect is never reclaimed');

$claim = bbf_outbox_claim($path, 'webhook:0', 100);
$failedAttempt = bbf_outbox_complete($path, 'webhook:0', $claim['token'], [
    'ok' => false, 'state' => 'failed', 'stage' => 'http', 'code' => 503, 'retryable' => true,
    'message' => 'Authorization: secret-token', 'safe_message' => 'Remote service unavailable',
], 101, 10);
check_outbox($failedAttempt['ok'] && $failedAttempt['job']['state'] === 'failed', 'retryable failure is persisted');
check_outbox($failedAttempt['job']['next_retry'] === 111, 'bounded retry receives next-attempt time');
check_outbox(!str_contains(json_encode($failedAttempt), 'secret-token'), 'raw transport secrets are not persisted');
$retryStatus = bbf_outbox_status($path);
check_outbox($retryStatus['state'] === 'retry_scheduled' && $retryStatus['jobs'][1]['can_retry']
    && $retryStatus['jobs'][1]['next_retry'] === 111, 'retryable failure aggregates as retry scheduled');
check_outbox($retryStatus['jobs'][1]['last_result']['message'] === 'Remote service unavailable',
    'explicit server-controlled safe message is observable');
$early = bbf_outbox_claim($path, 'webhook:0', 110);
check_outbox(!$early['ok'] && $early['reason'] === 'backoff', 'backoff prevents early retry');
$retryClaim = bbf_outbox_claim($path, 'webhook:0', 111);
check_outbox($retryClaim['ok'] && $retryClaim['job']['attempts'] === 2, 'retry claims next bounded attempt');
$retryFailed = bbf_outbox_complete($path, 'webhook:0', $retryClaim['token'], [
    'ok' => false, 'state' => 'failed', 'stage' => 'http', 'code' => 503, 'retryable' => true,
], 112, 1);
$thirdClaim = bbf_outbox_claim($path, 'webhook:0', 113);
$exhausted = bbf_outbox_complete($path, 'webhook:0', $thirdClaim['token'], [
    'ok' => false, 'state' => 'failed', 'stage' => 'http', 'code' => 503, 'retryable' => true,
], 114, 1);
check_outbox($exhausted['job']['state'] === 'exhausted', 'retry cap transitions job to exhausted');
check_outbox(!bbf_outbox_claim($path, 'webhook:0', 200)['ok'], 'exhausted job cannot be reclaimed');
check_outbox(!bbf_outbox_retry($path, 'webhook:0', false, 200)['ok'], 'operator retry cannot bypass hard attempt cap');
$exhaustedStatus = bbf_outbox_status($path);
check_outbox($exhaustedStatus['state'] === 'attention_required' && !$exhaustedStatus['jobs'][1]['can_retry']
    && $exhaustedStatus['jobs'][1]['next_retry'] === null, 'exhaustion requires attention and has no retry schedule');

$ambiguousPath = $root . '/ambiguous.json';
bbf_outbox_init($ambiguousPath, 'form:response-2', $jobs, 3, 1);
$claim = bbf_outbox_claim($ambiguousPath, 'confirm', 2);
$expired = bbf_outbox_expire_leases($ambiguousPath, 5, 10);
check_outbox($expired['ok'] && $expired['changed'] === ['confirm'], 'stale running lease becomes ambiguous');
$automatic = bbf_outbox_claim($ambiguousPath, 'confirm', 11);
check_outbox(!$automatic['ok'] && $automatic['reason'] === 'ambiguous', 'ambiguous SMTP is not automatically replayed');
$unconfirmed = bbf_outbox_retry($ambiguousPath, 'confirm', false, 11);
check_outbox(!$unconfirmed['ok'] && $unconfirmed['reason'] === 'confirmation_required',
    'non-idempotent ambiguous action requires explicit confirmation');
$ambiguousStatus = bbf_outbox_status($ambiguousPath);
check_outbox($ambiguousStatus['state'] === 'attention_required'
    && $ambiguousStatus['jobs'][0]['can_retry'] && $ambiguousStatus['jobs'][0]['requires_confirmation'],
    'ambiguous non-idempotent status exposes safe confirmation controls');
$confirmed = bbf_outbox_retry($ambiguousPath, 'confirm', true, 11);
check_outbox($confirmed['ok'] && $confirmed['job']['state'] === 'pending', 'operator-confirmed ambiguous action can be retried');

$idempotentPath = $root . '/idempotent.json';
bbf_outbox_init($idempotentPath, 'form:response-3', $jobs, 3, 1);
$claim = bbf_outbox_claim($idempotentPath, 'webhook:0', 2);
bbf_outbox_expire_leases($idempotentPath, 5, 10);
$retry = bbf_outbox_retry($idempotentPath, 'webhook:0', false, 11);
check_outbox($retry['ok'], 'stable-idempotency webhook may recover an ambiguous lease');

$event = bbf_outbox_record_event($idempotentPath, 'evt_1', 'checkout:cs_1:paid', 'paid', 12);
check_outbox($event['ok'] && !$event['duplicate'] && !$event['semantic_duplicate'], 'first payment event is recorded');
$duplicate = bbf_outbox_record_event($idempotentPath, 'evt_1', 'checkout:cs_1:paid', 'paid', 13);
check_outbox($duplicate['duplicate'], 'same payment event ID is deduplicated');
$semantic = bbf_outbox_record_event($idempotentPath, 'evt_2', 'checkout:cs_1:paid', 'paid', 14);
check_outbox(!$semantic['duplicate'] && $semantic['semantic_duplicate'], 'different event ID for same Checkout transition is semantically deduplicated');
$read = bbf_outbox_read($idempotentPath);
check_outbox($read['ok'] && count($read['ledger']['events']) === 2, 'event ledger survives process reload');
check_outbox(!str_contains(file_get_contents($idempotentPath), 'evt_1'), 'external payment event IDs are stored as hashes');

$noLedgerDeleted = false;
$noLedgerDelete = bbf_outbox_delete_submission($root . '/never-created.json', static function () use (&$noLedgerDeleted): array {
    $noLedgerDeleted = true;
    return ['ok' => true, 'deleted' => true];
});
$noLedgerTombstone = json_decode(file_get_contents($root . '/never-created.json'), true, 512, JSON_THROW_ON_ERROR);
check_outbox($noLedgerDelete['ok'] && $noLedgerDelete['deleted'] === 1 && $noLedgerDeleted
    && ($noLedgerTombstone['deleted'] ?? false) === true && ($noLedgerTombstone['jobs'] ?? null) === [],
    'submission without a prior ledger deletes normally and leaves a privacy-safe anti-resurrection tombstone');

$deletePath = $root . '/delete-safe.json';
$deleteSecret = 'delete-private-payload-and-identifier';
bbf_outbox_init($deletePath, 'private:submission:key', [[
    'key' => 'delete-job', 'type' => 'action', 'payload_hash' => hash('sha256', $deleteSecret),
    'idempotency_key' => 'private-delete-idempotency', 'target' => 'private-delete-target',
    'idempotent' => true, 'payload' => ['secret' => $deleteSecret],
]], 3, 1);
$deleteClaim = bbf_outbox_claim($deletePath, 'delete-job', 2);
bbf_outbox_complete($deletePath, 'delete-job', $deleteClaim['token'], ['ok' => true, 'stage' => 'action'], 3);
bbf_outbox_init($deletePath . '.events', 'private:submission:events', [], 3, 1);
bbf_outbox_record_event($deletePath . '.events', 'private-event-id', 'private-semantic-id', 'paid', 2);
$primaryDeleted = false;
$deleteResult = bbf_outbox_delete_submission($deletePath, static function () use (&$primaryDeleted): array {
    $primaryDeleted = true;
    return ['ok' => true, 'deleted' => true];
});
$deleteLedger = json_decode(file_get_contents($deletePath), true, 512, JSON_THROW_ON_ERROR);
$deleteEvents = json_decode(file_get_contents($deletePath . '.events'), true, 512, JSON_THROW_ON_ERROR);
$deleteBytes = file_get_contents($deletePath) . file_get_contents($deletePath . '.events');
check_outbox($deleteResult === ['ok' => true, 'deleted' => 1] && $primaryDeleted,
    'coordinated deletion removes the primary record while ledger locks are held');
check_outbox($deleteLedger === $deleteEvents && $deleteLedger === [
    'version' => 1, 'deleted' => true, 'jobs' => [], 'events' => [], 'semantic_events' => [],
], 'delivery and payment-event ledgers become privacy-safe empty tombstones');
check_outbox(!str_contains($deleteBytes, 'private') && !str_contains($deleteBytes, $deleteSecret)
    && !str_contains($deleteBytes, hash('sha256', $deleteSecret)),
    'deletion tombstones retain no response data, targets, payloads, hashes, or idempotency keys');
$deletedEventBytes = file_get_contents($deletePath . '.events');
$lateDeletedEvent = bbf_outbox_record_event($deletePath . '.events', 'late-private-event', 'late-private-semantic', 'paid', 4);
check_outbox(!$lateDeletedEvent['ok'] && $lateDeletedEvent['reason'] === 'deleted'
    && file_get_contents($deletePath . '.events') === $deletedEventBytes,
    'late payment event cannot repopulate a deletion tombstone');
check_outbox(!bbf_outbox_claim($deletePath, 'delete-job', 4)['ok']
    && !bbf_outbox_retry($deletePath, 'delete-job', true, 4)['ok'],
    'succeeded jobs cannot be reclaimed or retried after deletion');
$reinitializeDeleted = bbf_outbox_init($deletePath, 'new-private-key', [$jobs[1]], 3, 5);
check_outbox($reinitializeDeleted['ok'] && !$reinitializeDeleted['created']
    && $reinitializeDeleted['ledger']['jobs'] === [], 'deletion tombstone prevents later job-plan recreation');

$runningDeletePath = $root . '/delete-running.json';
bbf_outbox_init($runningDeletePath, 'form:running', [$jobs[1]], 3, 1);
bbf_outbox_claim($runningDeletePath, 'webhook:0', 2);
$runningBefore = file_get_contents($runningDeletePath);
$runningPrimaryCalled = false;
$runningDelete = bbf_outbox_delete_submission($runningDeletePath, static function () use (&$runningPrimaryCalled): array {
    $runningPrimaryCalled = true;
    return ['ok' => true, 'deleted' => true];
});
check_outbox(!$runningDelete['ok'] && $runningDelete['reason'] === 'running' && !$runningPrimaryCalled
    && file_get_contents($runningDeletePath) === $runningBefore,
    'running job refuses deletion before the primary record or ledger changes');

$bulkSafePath = $root . '/bulk-safe.json';
$bulkRunningPath = $root . '/bulk-running.json';
bbf_outbox_init($bulkSafePath, 'form:bulk-safe', [], 3, 1);
bbf_outbox_init($bulkRunningPath, 'form:bulk-running', [$jobs[1]], 3, 1);
$bulkRunningClaim = bbf_outbox_claim($bulkRunningPath, 'webhook:0', 2);
$bulkCalls = 0;
$bulkDeletions = [
    ['path' => $bulkSafePath, 'delete' => static function () use (&$bulkCalls): array { $bulkCalls++; return ['ok' => true, 'deleted' => true]; }],
    ['path' => $bulkRunningPath, 'delete' => static function () use (&$bulkCalls): array { $bulkCalls++; return ['ok' => true, 'deleted' => true]; }],
];
$bulkBlocked = bbf_outbox_delete_submissions($bulkDeletions);
check_outbox(!$bulkBlocked['ok'] && $bulkBlocked['reason'] === 'running' && $bulkCalls === 0,
    'bulk deletion preflights every ledger and refuses all primary deletes when one job runs');
bbf_outbox_complete($bulkRunningPath, 'webhook:0', $bulkRunningClaim['token'], ['ok' => true, 'stage' => 'http'], 3);
$bulkDeleted = bbf_outbox_delete_submissions($bulkDeletions);
check_outbox($bulkDeleted === ['ok' => true, 'deleted' => 2] && $bulkCalls === 2
    && json_decode(file_get_contents($bulkSafePath), true)['jobs'] === []
    && json_decode(file_get_contents($bulkRunningPath), true)['jobs'] === [],
    'bulk deletion proceeds only after all jobs stop and tombstones every protected ledger');

$deleteFaultPath = $root . '/delete-persist-fault.json';
bbf_outbox_init($deleteFaultPath, 'private:fault-id', [$jobs[1]], 3, 1);
$deleteFaultOriginal = file_get_contents($deleteFaultPath);
$deleteFaultPrimaryCalled = false;
$GLOBALS['_bbf_outbox_write'] = static fn() => false;
$deleteFault = bbf_outbox_delete_submission($deleteFaultPath,
    static function () use (&$deleteFaultPrimaryCalled): array {
        $deleteFaultPrimaryCalled = true;
        return ['ok' => true, 'deleted' => true];
    });
unset($GLOBALS['_bbf_outbox_write']);
check_outbox(!$deleteFault['ok'] && $deleteFault['reason'] === 'persist' && $deleteFault['deleted'] === 0
    && !$deleteFaultPrimaryCalled && file_get_contents($deleteFaultPath) === $deleteFaultOriginal,
    'first tombstone persistence fault invokes no primary callback and preserves exact private ledger bytes');

$midFaultPath = $root . '/delete-mid-persist-fault.json';
bbf_outbox_init($midFaultPath, 'private:mid-fault-id', [$jobs[1]], 3, 1);
bbf_outbox_init($midFaultPath . '.events', 'private:mid-fault-events', [], 3, 1);
$midFaultOriginal = [file_get_contents($midFaultPath), file_get_contents($midFaultPath . '.events')];
$midFaultWrites = 0;
$midFaultPrimaryCalled = false;
$GLOBALS['_bbf_outbox_write'] = static function (string $path, string $bytes) use (&$midFaultWrites): bool {
    $midFaultWrites++;
    if ($midFaultWrites === 2) return false;
    return bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $bytes));
};
$midFault = bbf_outbox_delete_submission($midFaultPath,
    static function () use (&$midFaultPrimaryCalled): array {
        $midFaultPrimaryCalled = true;
        return ['ok' => true, 'deleted' => true];
    });
unset($GLOBALS['_bbf_outbox_write']);
check_outbox(!$midFault['ok'] && $midFault['reason'] === 'persist' && $midFault['deleted'] === 0
    && !$midFaultPrimaryCalled && $midFaultWrites === 3
    && file_get_contents($midFaultPath) === $midFaultOriginal[0]
    && file_get_contents($midFaultPath . '.events') === $midFaultOriginal[1],
    'mid-tombstone fault rolls every attempted ledger back to exact original bytes before any primary callback');

$partialPaths = [$root . '/delete-partial-first.json', $root . '/delete-partial-second.json'];
$partialPrimaryPaths = [$root . '/primary-partial-first.json', $root . '/primary-partial-second.json'];
$partialOriginal = [];
foreach ($partialPaths as $index => $partialPath) {
    bbf_outbox_init($partialPath, "private:partial-$index", [$jobs[1]], 3, 1);
    bbf_outbox_init($partialPath . '.events', "private:partial-events-$index", [], 3, 1);
    $partialOriginal[$index] = [file_get_contents($partialPath), file_get_contents($partialPath . '.events')];
    file_put_contents($partialPrimaryPaths[$index], "private-primary-$index");
}
$partialDelete = bbf_outbox_delete_submissions([
    ['path' => $partialPaths[0], 'delete' => static function () use ($partialPrimaryPaths): array {
        $deleted = unlink($partialPrimaryPaths[0]);
        return ['ok' => $deleted, 'deleted' => $deleted];
    }],
    ['path' => $partialPaths[1], 'delete' => static fn(): array => ['ok' => false, 'deleted' => false]],
]);
$tombstone = ['version' => 1, 'deleted' => true, 'jobs' => [], 'events' => [], 'semantic_events' => []];
$firstTombstoned = json_decode(file_get_contents($partialPaths[0]), true, 512, JSON_THROW_ON_ERROR) === $tombstone
    && json_decode(file_get_contents($partialPaths[0] . '.events'), true, 512, JSON_THROW_ON_ERROR) === $tombstone;
$secondRestored = file_get_contents($partialPaths[1]) === $partialOriginal[1][0]
    && file_get_contents($partialPaths[1] . '.events') === $partialOriginal[1][1];
check_outbox($partialDelete === ['ok' => false, 'reason' => 'primary', 'deleted' => 1]
    && !is_file($partialPrimaryPaths[0]) && is_file($partialPrimaryPaths[1])
    && $firstTombstoned && $secondRestored,
    'later primary failure tombstones committed deletes but restores delivery for every retained primary');

$ambiguousPaths = [$root . '/delete-ambiguous-first.json', $root . '/delete-ambiguous-second.json'];
$ambiguousOriginal = [];
foreach ($ambiguousPaths as $index => $ambiguousPath) {
    bbf_outbox_init($ambiguousPath, "private:ambiguous-$index", [$jobs[1]], 3, 1);
    $ambiguousOriginal[$index] = file_get_contents($ambiguousPath);
}
$ambiguousCalls = 0;
$ambiguousDelete = bbf_outbox_delete_submissions([
    ['path' => $ambiguousPaths[0], 'delete' => static function () use (&$ambiguousCalls): array {
        $ambiguousCalls++;
        return ['ok' => false, 'deleted' => null, 'ambiguous' => true];
    }],
    ['path' => $ambiguousPaths[1], 'delete' => static function () use (&$ambiguousCalls): array {
        $ambiguousCalls++;
        return ['ok' => true, 'deleted' => true];
    }],
]);
check_outbox($ambiguousDelete === ['ok' => false, 'reason' => 'ambiguous', 'deleted' => 0, 'ambiguous' => true]
    && $ambiguousCalls === 1
    && json_decode(file_get_contents($ambiguousPaths[0]), true, 512, JSON_THROW_ON_ERROR) === $tombstone
    && file_get_contents($ambiguousPaths[1]) === $ambiguousOriginal[1],
    'ambiguous primary commit keeps its privacy tombstone and restores only later untouched deliveries');

$legacyPath = $root . '/legacy-v1.json';
$legacyLedger = [
    'version' => 1,
    'jobs' => ['legacy' => [
        'key' => 'legacy', 'type' => 'webhook', 'state' => 'failed', 'attempts' => 1, 'max_attempts' => 3,
        'next_retry' => 123, 'idempotent' => true, 'lease_token' => 'legacy-lease-secret',
        'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'legacy-idempotency-secret',
        'target' => 'private-person@example.com', 'payload' => ['secret' => 'legacy-payload-secret'],
        'last_result' => ['stage' => 'http', 'code' => 503, 'retryable' => true,
            'message' => 'legacy-private-message', 'at' => 122],
    ]],
];
file_put_contents($legacyPath, json_encode($legacyLedger, JSON_THROW_ON_ERROR));
$legacyStatus = bbf_outbox_status($legacyPath);
$legacyJson = json_encode($legacyStatus, JSON_THROW_ON_ERROR);
check_outbox($legacyStatus['ok'] && $legacyStatus['state'] === 'retry_scheduled'
    && $legacyStatus['jobs'][0]['last_result']['message'] === 'Remote service is temporarily unavailable.',
    'v1 ledger remains observable through safe stage/code fallback');
check_outbox(!str_contains($legacyJson, 'legacy-private-message') && !str_contains($legacyJson, 'legacy-lease-secret')
    && !str_contains($legacyJson, 'legacy-payload-secret') && !str_contains($legacyJson, 'legacy-idempotency-secret')
    && !str_contains($legacyJson, 'private-person'), 'legacy projection redacts all non-allowlisted data');

$historyPath = $root . '/history.json';
bbf_outbox_init($historyPath, 'form:history', [$jobs[1]], 3, 1);
$historyLedger = bbf_outbox_read($historyPath)['ledger'];
$historyLedger['jobs']['webhook:0']['state'] = 'running';
$historyLedger['jobs']['webhook:0']['attempts'] = 1;
$historyLedger['jobs']['webhook:0']['lease_token'] = 'history-token';
$historyLedger['jobs']['webhook:0']['history'] = [];
for ($i = 1; $i <= 12; $i++) {
    $historyLedger['jobs']['webhook:0']['history'][] = [
        'state' => 'failed', 'attempt' => $i, 'stage' => 'http', 'code' => 503,
        'retryable' => true, 'message' => 'unsafe-history-secret-' . $i, 'at' => $i,
    ];
}
file_put_contents($historyPath, json_encode($historyLedger, JSON_THROW_ON_ERROR));
$historyComplete = bbf_outbox_complete($historyPath, 'webhook:0', 'history-token', [
    'ok' => false, 'state' => 'failed', 'stage' => 'private-adapter-stage', 'code' => 500,
    'retryable' => true, 'message' => 'raw-adapter-secret',
], 50, 5);
$historyStatus = bbf_outbox_status($historyPath);
$historyJson = json_encode($historyStatus, JSON_THROW_ON_ERROR);
check_outbox($historyComplete['ok'] && count($historyComplete['job']['history']) === 10
    && count($historyStatus['jobs'][0]['history']) === 10, 'attempt history is durably bounded to ten safe entries');
check_outbox($historyStatus['jobs'][0]['last_result']['stage'] === 'delivery'
    && $historyStatus['jobs'][0]['last_result']['message'] === 'Delivery failed.'
    && !str_contains($historyJson, 'raw-adapter-secret') && !str_contains($historyJson, 'unsafe-history-secret'),
    'raw adapter and legacy history messages are replaced by whitelisted safe fallbacks');
check_outbox(!str_contains(file_get_contents($historyPath), 'raw-adapter-secret')
    && !str_contains(file_get_contents($historyPath), 'unsafe-history-secret'), 'only safe attempt messages are persisted');

$invalidPayloadThrown = false;
try {
    bbf_outbox_job(['key' => 'invalid-payload', 'payload' => 'secret'], 1, 3);
} catch (InvalidArgumentException $error) {
    $invalidPayloadThrown = true;
}
check_outbox($invalidPayloadThrown, 'non-array execution payload is rejected');

$malformedPath = $root . '/malformed.json';
file_put_contents($malformedPath, '{not-json');
check_outbox(bbf_outbox_status($malformedPath) === [
    'ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => [],
], 'malformed ledger fails closed as attention required');

$faultPath = $root . '/fault.json';
$GLOBALS['_bbf_outbox_write'] = static fn() => false;
$fault = bbf_outbox_init($faultPath, 'form:response-fault', $jobs, 3, 1);
unset($GLOBALS['_bbf_outbox_write']);
check_outbox(!$fault['ok'] && $fault['reason'] === 'persist', 'pre-effect persistence failure is reported');
check_outbox(!is_file($faultPath), 'pre-effect persistence failure leaves no published ledger');

$concurrentPath = $root . '/concurrent.json';
bbf_outbox_init($concurrentPath, 'form:response-concurrent', [$jobs[1]], 3, 1);
$children = [];
for ($i = 0; $i < 8; $i++) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --claim ' . escapeshellarg($concurrentPath);
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (is_resource($process)) $children[] = [$process, $pipes];
}
$wins = 0;
foreach ($children as [$process, $pipes]) {
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $decoded = json_decode($output, true);
    if (($decoded['ok'] ?? false) === true) $wins++;
}
check_outbox(count($children) === 8, 'concurrency workers started');
check_outbox($wins === 1, 'concurrent workers obtain exactly one durable claim');
$read = bbf_outbox_read($concurrentPath);
check_outbox($read['ledger']['jobs']['webhook:0']['attempts'] === 1, 'concurrent claims increment attempt count once');

$removeTree = static function(string $directory) use (&$removeTree): void {
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $item = $directory . '/' . $entry;
        if (is_dir($item)) $removeTree($item); else @unlink($item);
    }
    @rmdir($directory);
};
$removeTree($root);
printf("RESULT: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
