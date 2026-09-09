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
    bbf_audit_write($sourceConfig, ['id' => 'fixture'], 'viewer_read', 'alpha', ['bbf_alpha'], 'allowed', 'completed', 1);
    bbf_audit_write($sourceConfig, ['id' => 'fixture'], 'viewer_read', 'beta', ['bbf_beta'], 'allowed', 'completed', 1);

    $created = bbf_backup_create($sourceConfig, 'alpha', strtotime('2026-09-09T12:00:00Z'));
    $bytes = file_get_contents($created['path']);
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    backup_check(($created['ok'] ?? false) === true && is_file($created['path'])
        && hash_equals($document['sha256'], hash('sha256', bbf_storage_json($document['payload']))),
        'backup is a private integrity-checked logical bundle');
    backup_check(!str_contains($bytes, 'SOURCE-SECRET-MUST-NOT-BE-BACKED-UP')
        && ($document['payload']['access']['principals'][0]['id'] ?? null) === 'reviewer',
        'bundle preserves applicable access boundaries without credentials');

    $targetAccess = $access;
    $targetAccess[0]['token'] = 'DIFFERENT-TARGET-SECRET';
    $targetConfig = ['storage' => 'file', 'forms_dir' => "$target/forms", 'submissions_dir' => "$target/submissions",
        'logs_dir' => "$target/logs", 'api_token' => '', 'access_tokens' => $targetAccess,
        'backup' => ['directory' => $backupDir]];
    bbf_audit_write($targetConfig, ['id' => 'fixture'], 'viewer_read', 'beta', ['bbf_beta'], 'allowed', 'completed', 1);
    $plan = bbf_backup_restore_plan($targetConfig, $created['path']);
    backup_check(($plan['dry_run'] ?? false) === true && ($plan['empty'] ?? false) === true
        && preg_match('/\Arestore-[a-f0-9]{64}\z/D', $plan['confirmation'] ?? '') === 1,
        'restore defaults to a deterministic dry-run plan on an empty target');
    $wrong = bbf_backup_restore($targetConfig, $created['path'], 'restore-' . str_repeat('0', 64));
    backup_check(($wrong['reason'] ?? null) === 'confirmation' && !is_file("$target/forms/alpha.json"),
        'wrong restore confirmation leaves the target untouched');

    $tampered = $backupDir . '/tampered.json';
    file_put_contents($tampered, str_replace('private-alpha', 'tampered-alpha', $bytes));
    $threw = false;
    try { bbf_backup_restore_plan($targetConfig, $tampered); } catch (RuntimeException $error) { $threw = true; }
    backup_check($threw && !is_file("$target/forms/alpha.json"), 'tampered bundle fails integrity validation before target access');

    $restored = bbf_backup_restore($targetConfig, $created['path'], $plan['confirmation']);
    $restoredRecord = bbf_read_file("$target/submissions/alpha/bbf_alpha.json", 'alpha');
    $review = bbf_review_file_transaction($targetConfig, 'alpha', false, static fn(array $document): array => $document);
    $version = bbf_version_state($targetConfig, 'alpha');
    $audit = file_get_contents("$target/logs/access-audit.php");
    backup_check(($restored['ok'] ?? false) === true && $restoredRecord === $record,
        'confirmed restore recreates the exact logical submission on the empty target');
    backup_check(($review['records']['bbf_alpha']['notes'] ?? null) === 'private note'
        && isset($review['filters']['reviewer']['mine'], $review['deleted']['bbf_deleted']),
        'restore preserves review notes, saved filters and tombstones');
    backup_check($version['definition']['name'] === 'Alpha draft' && count(bbf_version_history($targetConfig, 'alpha')) === 2,
        'restore preserves published definition, draft and complete version history');
    backup_check(str_contains($audit, 'bbf_alpha') && substr_count($audit, 'bbf_beta') === 1,
        'restore preserves source form audit relationships without replacing unrelated target audit');
    $occupied = bbf_backup_restore_plan($targetConfig, $created['path']);
    backup_check(($occupied['empty'] ?? true) === false && ($occupied['confirmation'] ?? null) === null,
        'restore refuses an already populated target instead of merging or overwriting');
} finally {
    bbf_test_cleanup($backupDir);
    bbf_test_cleanup($source);
    bbf_test_cleanup($target);
}
print "Backup review tests: $checks passed\n";
