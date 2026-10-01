<?php

declare(strict_types=1);

define('BBF_LOADED', true);
require_once __DIR__ . '/test-isolation-helper.php';
$installation = bbf_test_installation(dirname(__DIR__));
require_once $installation . '/bbf_functions.php';

$passed = 0;
$failed = 0;
function check_alert(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS $message\n";
    } else {
        $failed++;
        echo "FAIL $message\n";
    }
}

function alert_incidents(string $logs): array {
    $lines = is_file($logs . '/incidents.log') ? file($logs . '/incidents.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_map(static fn(string $line) => json_decode($line, true), $lines);
}

$root = sys_get_temp_dir() . '/bbf-alerts-' . bin2hex(random_bytes(6));
$logs = $root . '/logs';
mkdir($logs, 0700, true);
// Recording never sees error_notify here, so no shutdown email is attempted by this test process.
$recordConfig = ['logs_dir' => $logs, 'submissions_dir' => $root . '/submissions', 'forms_dir' => $root . '/forms'];
$notifyConfig = $recordConfig + ['error_notify' => 'admin@example.com', 'error_notify_interval' => 3600];

// ─── Recording and disabled flush ────────────────────────────────
bbf_alert_record($recordConfig, 'kontakt', 'Delivery failed: email', "line one\nline two");
$incidents = alert_incidents($logs);
check_alert(count($incidents) === 1 && $incidents[0]['form'] === 'kontakt' && $incidents[0]['type'] === 'Delivery failed: email'
    && $incidents[0]['detail'] === "line one\nline two", 'incident is appended as one JSON line');
bbf_alert_record($recordConfig, '../evil', "bad\r\ntype", 'x');
$incidents = alert_incidents($logs);
check_alert($incidents[1]['form'] === 'evil' && $incidents[1]['type'] === 'bad  type', 'form id and type are sanitized');
check_alert(bbf_alert_flush($recordConfig)['reason'] === 'disabled', 'flush without error_notify sends nothing');

// ─── Grouping, throttle, summary count ───────────────────────────
$mails = [];
$sender = static function (string $to, string $subject, string $body) use (&$mails): bool {
    $mails[] = compact('to', 'subject', 'body');
    return true;
};
$now = 1_800_000_000;
$first = bbf_alert_flush($notifyConfig, $sender, $now);
check_alert($first['sent'] && $first['groups'] === 2 && count($mails) === 1, 'one email covers every due group');
check_alert(str_contains($mails[0]['body'], '[kontakt] Delivery failed: email') && str_contains($mails[0]['body'], '[evil] bad  type'),
    'email lists form and problem type');
check_alert(bbf_alert_flush($notifyConfig, $sender, $now + 10)['reason'] === 'nothing_due' && count($mails) === 1, 'already reported incidents are not resent');

bbf_alert_record($recordConfig, 'kontakt', 'Delivery failed: email', 'again 1');
bbf_alert_record($recordConfig, 'kontakt', 'Delivery failed: email', 'again 2');
bbf_alert_record($recordConfig, 'newsletter', 'Form not found', 'gone');
$third = bbf_alert_flush($notifyConfig, $sender, $now + 60);
check_alert($third['sent'] && $third['groups'] === 1 && str_contains($mails[1]['body'], '[newsletter] Form not found')
    && !str_contains($mails[1]['body'], '[kontakt]'), 'a new group is sent at once while a throttled group waits');
check_alert(is_file($logs . '/.alerts_due') && (int)file_get_contents($logs . '/.alerts_due') === $now + 3600,
    'the due marker holds the time the throttled group may be sent');
$fourth = bbf_alert_flush($notifyConfig, $sender, $now + 3600);
check_alert($fourth['sent'] && str_contains($mails[2]['body'], '2× since') && str_contains($mails[2]['body'], 'again 2'),
    'throttled repeats are summarized with count and latest detail');
check_alert(!is_file($logs . '/.alerts_due'), 'due marker is removed when nothing is pending');

// ─── Send failure keeps incidents pending ────────────────────────
bbf_alert_record($recordConfig, 'kontakt', 'Storage failed', 'disk full');
$failing = static fn(): bool => false;
$failedSend = bbf_alert_flush($notifyConfig, $failing, $now + 4000);
check_alert($failedSend['reason'] === 'send_failed' && (int)file_get_contents($logs . '/.alerts_due') === $now + 4900,
    'failed send is retried after 15 minutes');
$retry = bbf_alert_flush($notifyConfig, $sender, $now + 4900);
check_alert($retry['sent'] && str_contains(end($mails)['body'], 'disk full'), 'pending incident is sent on the next flush');

// ─── Concurrent flush is skipped, never blocks ───────────────────
$lock = fopen($logs . '/.alerts.lock', 'c');
flock($lock, LOCK_EX);
$childScript = $root . '/flush-child.php';
file_put_contents($childScript, '<?php define("BBF_LOADED", true); require ' . var_export(dirname(__DIR__) . '/bbf_alerts.php', true)
    . '; echo bbf_alert_flush(' . var_export($notifyConfig, true) . ', fn() => true)["reason"];');
$busy = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childScript)));
flock($lock, LOCK_UN);
fclose($lock);
check_alert($busy === 'busy', 'a second flusher returns immediately while one is running');

// ─── Log rotation ────────────────────────────────────────────────
$line = json_encode(['at' => $now, 'form' => 'bulk', 'type' => 'Bulk', 'detail' => str_repeat('x', 200)]) . "\n";
file_put_contents($logs . '/incidents.log', str_repeat($line, 5000), FILE_APPEND);
$bulk = bbf_alert_flush($notifyConfig, $sender, $now + 9000);
check_alert($bulk['sent'] && str_contains(end($mails)['body'], '5000×'), 'bulk incidents fold into one group');
check_alert(filesize($logs . '/incidents.log') === 0 && filesize($logs . '/incidents.log.1') > 1048576, 'log rotates after 1 MB');
bbf_alert_record($recordConfig, 'after', 'Rotation', 'next');
check_alert(bbf_alert_flush($notifyConfig, $sender, $now + 9100)['sent'] && str_contains(end($mails)['body'], '[after] Rotation'),
    'incidents after rotation are still read');

// ─── Known-form check for 404 alerts ─────────────────────────────
check_alert(!bbf_alert_form_known($recordConfig, 'random123'), 'never-seen form is not known (bot noise is not alerted)');
bbf_alert_form_seen($recordConfig, 'seenform');
check_alert(bbf_alert_form_known($recordConfig, 'seenform'), 'a served definition marks the form as known');
mkdir($root . '/submissions/stored', 0700, true);
check_alert(bbf_alert_form_known($recordConfig, 'stored'), 'a form with stored submissions is known');

// ─── Delivery failure from the runner ────────────────────────────
$outbox = $root . '/submissions/.delivery/kontakt/bbf_1.json';
mkdir(dirname($outbox), 0700, true);
$payload = ['to' => 'someone@example.com', 'subject' => 's', 'body' => 'b'];
$jobs = [['key' => 'notify', 'type' => 'email', 'payload' => $payload, 'payload_hash' => bbf_delivery_payload_hash($payload),
    'idempotency_key' => 'kontakt:bbf_1:notify', 'target' => 's***@example.com', 'idempotent' => false]];
check_alert(bbf_outbox_init($outbox, 'kontakt:bbf_1', $jobs, 3)['ok'] ?? false, 'fixture outbox created');
$GLOBALS['_bbf_delivery_effect'] = static fn() => bbf_delivery_result(false, 'failed', 'auth_password', 535, false, 'auth');
$before = count(alert_incidents($logs));
bbf_delivery_run_job($outbox, 'notify', $recordConfig);
$incidents = alert_incidents($logs);
$last = end($incidents);
check_alert(count($incidents) === $before + 1 && $last['form'] === 'kontakt' && $last['type'] === 'Delivery failed: notification email'
    && str_contains($last['detail'], 'bbf_1') && str_contains($last['detail'], "'auth_password' (code 535)"), 'failed automatic delivery records an incident');
// SMTP down: confirmation + notification of one submission are two distinguishable incidents, never two identical lines.
$outbox2 = $root . '/submissions/.delivery/kontakt/bbf_2.json';
$jobs2 = [];
foreach (['confirm', 'notify'] as $key) $jobs2[] = ['key' => $key, 'type' => 'email', 'payload' => $payload, 'payload_hash' => bbf_delivery_payload_hash($payload),
    'idempotency_key' => "kontakt:bbf_2:$key", 'target' => 's***@example.com', 'idempotent' => false];
check_alert(bbf_outbox_init($outbox2, 'kontakt:bbf_2', $jobs2, 3)['ok'] ?? false, 'fixture outbox with two emails created');
$before = count(alert_incidents($logs));
bbf_delivery_run_job($outbox2, 'confirm', $recordConfig);
bbf_delivery_run_job($outbox2, 'notify', $recordConfig);
$pair = array_slice(alert_incidents($logs), $before);
check_alert(count($pair) === 2 && $pair[0]['type'] === 'Delivery failed: confirmation email' && $pair[1]['type'] === 'Delivery failed: notification email'
    && $pair[0]['detail'] !== $pair[1]['detail'], 'two failed emails of one submission are distinct incident lines');
unset($GLOBALS['_bbf_delivery_effect']);

// ─── SMTP probe ──────────────────────────────────────────────────
$writes = [];
$responses = ["220 hi\r\n", "250 ok\r\n", "334 u\r\n", "334 p\r\n", "235 ok\r\n", "221 bye\r\n"];
$io = ['read' => static function () use (&$responses) { return array_shift($responses) ?? false; },
    'write' => static function (string $bytes) use (&$writes): int { $writes[] = $bytes; return strlen($bytes); },
    'close' => static fn() => null];
$probe = bbf_delivery_smtp(['probe' => true], ['smtp_user' => 'u', 'smtp_pass' => 'p'], $io);
check_alert($probe['ok'] && $probe['stage'] === 'probe' && end($writes) === "QUIT\r\n"
    && !str_contains(implode('', $writes), 'MAIL FROM'), 'SMTP probe logs in and quits without sending');
$responses = ["220 hi\r\n", "250 ok\r\n", "334 u\r\n", "334 p\r\n", "535 bad\r\n"];
$badProbe = bbf_delivery_smtp(['probe' => true], ['smtp_user' => 'u', 'smtp_pass' => 'p'], $io);
check_alert(!$badProbe['ok'] && $badProbe['stage'] === 'auth_password' && $badProbe['code'] === 535, 'SMTP probe reports a wrong password');

// ─── Self-check ──────────────────────────────────────────────────
mkdir($root . '/forms', 0700, true);
copy(dirname(__DIR__) . '/forms/kontakt.json', $root . '/forms/kontakt.json');
file_put_contents($root . '/forms/broken.json', '{"id": "broken", ');
file_put_contents($root . '/forms/kontakt.map.json', '{}');
file_put_contents($root . '/forms/form.schema.json', '{}');
$ledger = json_decode((string)file_get_contents($outbox), true);
$ledger['jobs']['notify']['state'] = 'exhausted';
file_put_contents($outbox, json_encode($ledger));
$selfConfig = $recordConfig + ['mail' => ['method' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587]];
$before = count(alert_incidents($logs));
$report = bbf_alert_selfcheck($selfConfig, static fn() => bbf_delivery_result(false, 'failed', 'auth_password', 535));
$types = array_map(static fn(array $p) => $p['form'] . '|' . $p['type'], $report['problems']);
sort($types);
check_alert(!$report['ok'] && $report['forms_checked'] === 2, 'self-check reads form definitions but skips map and schema files');
check_alert($types === ['-|Access configuration problem', '-|SMTP check failed', 'broken|Invalid form definition', 'kontakt|Deliveries waiting for action'],
    'self-check finds unavailable access, the broken form, the SMTP failure and the stuck delivery: ' . implode(', ', $types));
check_alert(count(alert_incidents($logs)) === $before + 4, 'every self-check problem, including unavailable access, is recorded as an incident');
check_alert(str_contains($report['notify'], 'only logged'), 'self-check warns when error_notify is empty');
check_alert(($report['audit']['status'] ?? '') === 'unverified' && str_contains($report['audit']['detail'], 'CLI identity'),
    'CLI audit success does not claim web-worker write access');
touch($outbox, time() - 8 * 86400);
touch($outbox2, time() - 8 * 86400);
$quiet = bbf_alert_selfcheck($recordConfig);
check_alert(!in_array('Deliveries waiting for action', array_column($quiet['problems'], 'type'), true), 'deliveries older than 7 days stop nagging');

// ─── Fatal PHP error guard ───────────────────────────────────────
$script = $root . '/fatal.php';
file_put_contents($script, '<?php define("BBF_LOADED", true); require ' . var_export(dirname(__DIR__) . '/bbf_alerts.php', true) . ";\n"
    . '$config = ' . var_export($recordConfig, true) . "; \$formId = 'kontakt';\n"
    . "register_shutdown_function('bbf_alert_fatal_guard');\nbbf_this_function_does_not_exist();\n");
shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script));
$incidents = alert_incidents($logs);
$last = end($incidents);
check_alert($last['type'] === 'PHP fatal error' && $last['form'] === 'kontakt' && str_contains($last['detail'], 'bbf_this_function_does_not_exist'),
    'a fatal error is recorded with the form id');

// ─── Abuse: nobody outside can turn alerts into a mail cannon ────
// A crash under a made-up form id (e.g. a huge JSON body to ?form=random) must not open a new alert group per request.
foreach (['random1', 'random2', '../../x'] as $fake) {
    file_put_contents($script, str_replace("\$formId = 'kontakt'", '$formId = ' . var_export($fake, true), (string)file_get_contents($script)));
    shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script));
    file_put_contents($script, str_replace('$formId = ' . var_export($fake, true), "\$formId = 'kontakt'", (string)file_get_contents($script)));
}
$tail = array_slice(alert_incidents($logs), -3);
check_alert(array_column($tail, 'form') === ['-', '-', '-'], 'a crash under an unknown form id is filed under "-", not a new group per id');

check_alert(bbf_alert_recipients("a@example.com\r\nBcc: evil@example.net, b@example.com") === ['b@example.com'],
    'CR/LF in error_notify cannot add a Bcc or any other header');
check_alert(bbf_alert_recipients('evil@example.net>, x') === [], 'invalid recipient text is dropped');
$savedServer = $_SERVER['SERVER_NAME'] ?? null;
$_SERVER['SERVER_NAME'] = "phish.example\r\nBcc: evil@example.net";
[$subject] = bbf_alert_message(['logs_dir' => $logs], ['k' => ['form' => 'f', 'type' => 't', 'count' => 1, 'detail' => 'd']], 3600);
check_alert(preg_match('/^BareBonesForms: 1 problem on [a-zA-Z0-9.-]+$/', $subject) === 1, 'a hostile Host header cannot inject into the subject: ' . $subject);
[$subject] = bbf_alert_message(['logs_dir' => $logs, 'mail' => ['from_email' => 'noreply@shop.example']], ['k' => ['form' => 'f', 'type' => 't', 'count' => 1, 'detail' => 'd']], 3600);
check_alert(str_ends_with($subject, ' on shop.example'), 'the configured From domain beats the request Host header');
if ($savedServer === null) unset($_SERVER['SERVER_NAME']); else $_SERVER['SERVER_NAME'] = $savedServer;
$update = ['form' => '-', 'type' => 'Update available', 'count' => 1, 'detail' => 'd'];
[, $body] = bbf_alert_message(['logs_dir' => $logs], ['u' => $update], 3600);
check_alert(str_contains($body, 'one notice per new version') && !str_contains($body, 'at most once every'),
    'an update-only report says one notice per new version, not the hourly limit');
[, $body] = bbf_alert_message(['logs_dir' => $logs], ['u' => $update, 'k' => ['form' => 'f', 'type' => 't', 'count' => 1, 'detail' => 'd']], 3600);
check_alert(str_contains($body, 'one notice per new version') && str_contains($body, 'at most once every 1 hour'),
    'a mixed report states both rules');

$capLogs = $root . '/cap-logs';
mkdir($capLogs, 0700, true);
$capRecord = ['logs_dir' => $capLogs, 'submissions_dir' => $root . '/submissions', 'forms_dir' => $root . '/forms'];
$capNotify = $capRecord + ['error_notify' => 'admin@example.com', 'error_notify_interval' => 3600];
$capMails = [];
$capSender = static function (string $to, string $subject, string $body) use (&$capMails): bool { $capMails[] = $body; return true; };
$reasons = [];
for ($i = 1; $i <= 8; $i++) {
    bbf_alert_record($capRecord, "form$i", 'Form not found', "hit $i");
    $reasons[] = bbf_alert_flush($capNotify, $capSender, $now + $i * 60)['reason'];
}
check_alert(count($capMails) === BBF_ALERT_MAX_PER_HOUR && array_slice($reasons, -2) === ['hourly_cap', 'hourly_cap'],
    'no more than ' . BBF_ALERT_MAX_PER_HOUR . ' alert emails per hour, however many groups appear: ' . implode(',', $reasons));
check_alert((int)file_get_contents($capLogs . '/.alerts_due') === $now + 60 + 3600, 'capped alerts are due when the oldest email leaves the hour');
$afterCap = bbf_alert_flush($capNotify, $capSender, $now + 60 + 3600);
check_alert($afterCap['sent'] && $afterCap['groups'] === 2 && str_contains(end($capMails), '[form7]') && str_contains(end($capMails), '[form8]'),
    'held-back alerts arrive later in one email, nothing is lost');

$bigLogs = $root . '/big-logs';
mkdir($bigLogs, 0700, true);
file_put_contents($bigLogs . '/incidents.log', str_repeat('x', 5 * 1048576 + 10));
bbf_alert_record(['logs_dir' => $bigLogs], 'kontakt', 'Flood', 'x');
check_alert(filesize($bigLogs . '/incidents.log') < 1000 && is_file($bigLogs . '/incidents.log.1'),
    'without error_notify the incident log is still capped (no disk fill by flooding)');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
