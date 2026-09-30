<?php
/**
 * Admin incident alerts. Recording is a local append that never fails the caller; the email goes out
 * later (after the response, or from `php maintenance.php alerts`), grouped and throttled per form + type.
 * Files in logs_dir: incidents.log (one JSON line per incident), .alerts_state.json, .alerts_due, .alerts.lock.
 */
defined('BBF_LOADED') || exit;

const BBF_ALERT_MAX_PER_HOUR = 6;

function bbf_alert_dir(array $config): string {
    return rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\');
}

/** Append one incident; schedules an email after the response when error_notify is set. */
function bbf_alert_record(array $config, string $formId, string $type, string $detail): void {
    $formId = substr((string)preg_replace('/[^a-zA-Z0-9_-]/', '', $formId), 0, 80);
    if ($formId === '') $formId = '-';
    $type = substr(str_replace(["\r", "\n", "\0"], ' ', $type), 0, 120);
    $detail = substr(str_replace("\0", '', $detail), 0, 2000);
    error_log("BareBonesForms incident ($formId): $type — " . str_replace(["\r", "\n"], ' ', $detail));
    try {
        $dir = bbf_alert_dir($config);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        // Without error_notify nothing ever reads (and rotates) the log, so cap it here too.
        if ((int)@filesize($dir . '/incidents.log') > 5 * 1048576) @rename($dir . '/incidents.log', $dir . '/incidents.log.1');
        $line = json_encode(['at' => time(), 'form' => $formId, 'type' => $type, 'detail' => $detail],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($line)) @file_put_contents($dir . '/incidents.log', $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $error) {
        // The error_log line above is the fallback record.
    }
    bbf_alert_schedule_flush($config);
}

/** Send pending alerts once, at the end of this request, after the client already has its response. */
function bbf_alert_schedule_flush(array $config): void {
    static $scheduled = false;
    if ($scheduled || trim((string)($config['error_notify'] ?? '')) === '') return;
    $scheduled = true;
    register_shutdown_function(static function () use ($config): void {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { bbf_alert_flush($config); } catch (Throwable $error) { error_log('BareBonesForms alerts: ' . $error->getMessage()); }
    });
}

/** Cheap per-request check: throttled alerts that have become due are sent after this response. */
function bbf_alert_flush_if_due(array $config): void {
    $due = bbf_alert_dir($config) . '/.alerts_due';
    if (is_file($due) && (int)@file_get_contents($due) <= time()) bbf_alert_schedule_flush($config);
}

/** Shutdown guard for fatal PHP errors (parse errors in included files, uncaught exceptions, memory). */
function bbf_alert_fatal_guard(): void {
    $error = error_get_last();
    if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $config = $GLOBALS['config'] ?? null;
    if (!is_array($config)) {
        try { $config = (static fn() => include __DIR__ . '/config.php')(); } catch (Throwable $e) { $config = null; }
    }
    if (!is_array($config)) return;
    $formId = $GLOBALS['formId'] ?? ($_GET['form'] ?? '-');
    $formId = is_string($formId) ? (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $formId) : '';
    // The id comes from the URL: an attacker who can crash the request (e.g. a huge JSON body) must not mint
    // a fresh alert group (= a fresh email) per request, so only real forms keep their name.
    $formsDir = rtrim((string)($config['forms_dir'] ?? __DIR__ . '/forms'), '/\\');
    if ($formId === '' || !(is_file("$formsDir/$formId.json") || bbf_alert_form_known($config, $formId))) $formId = '-';
    $message = strtok((string)$error['message'], "\n");
    bbf_alert_record($config, $formId, 'PHP fatal error',
        $message . ' in ' . basename((string)$error['file']) . ':' . (int)$error['line']);
}

/** Read new incidents, email every group whose throttle has elapsed. Only one process flushes at a time. */
function bbf_alert_flush(array $config, ?callable $send = null, ?int $now = null): array {
    $to = trim((string)($config['error_notify'] ?? ''));
    if ($to === '') return ['sent' => false, 'reason' => 'disabled', 'groups' => 0];
    $now ??= time();
    $interval = max(60, (int)($config['error_notify_interval'] ?? 3600));
    $dir = bbf_alert_dir($config);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $lock = @fopen($dir . '/.alerts.lock', 'c');
    if ($lock === false) return ['sent' => false, 'reason' => 'lock', 'groups' => 0];
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['sent' => false, 'reason' => 'busy', 'groups' => 0];
    }
    try {
        $statePath = $dir . '/.alerts_state.json';
        $state = json_decode((string)@file_get_contents($statePath), true);
        if (!is_array($state) || !is_array($state['groups'] ?? null)) $state = ['offset' => 0, 'groups' => []];
        $state['offset'] = bbf_alert_collect($dir . '/incidents.log', (int)($state['offset'] ?? 0), $state['groups']);

        $due = [];
        $nextDue = null;
        foreach ($state['groups'] as $key => $group) {
            if (($group['count'] ?? 0) < 1) {
                if ($now - (int)($group['last_sent'] ?? 0) > 30 * 86400) unset($state['groups'][$key]);
                continue;
            }
            $at = (int)($group['last_sent'] ?? 0) + $interval;
            if ($at <= $now) $due[$key] = $group;
            else $nextDue = min($nextDue ?? $at, $at);
        }

        // Hard ceiling across all groups, whatever the cause: at most BBF_ALERT_MAX_PER_HOUR emails per hour.
        // Anything due beyond it waits and goes out folded into the next email.
        $recent = array_values(array_filter(array_map('intval', (array)($state['sent_at'] ?? [])), static fn(int $at) => $at > $now - 3600));
        $state['sent_at'] = $recent;
        $result = ['sent' => false, 'reason' => 'nothing_due', 'groups' => 0];
        if ($due !== [] && count($recent) >= BBF_ALERT_MAX_PER_HOUR) {
            $nextDue = min($nextDue ?? PHP_INT_MAX, min($recent) + 3600);
            $result = ['sent' => false, 'reason' => 'hourly_cap', 'groups' => count($due)];
            $due = [];
        }
        if ($due !== []) {
            [$subject, $body] = bbf_alert_message($config, $due, $interval);
            $sent = false;
            try { $sent = (bool)($send ?? 'bbf_alert_send')($to, $subject, $body, $config); } catch (Throwable $error) { $sent = false; }
            if ($sent) {
                foreach ($due as $key => $group) {
                    $state['groups'][$key] = ['form' => $group['form'], 'type' => $group['type'], 'count' => 0, 'last_sent' => $now];
                }
                $state['sent_at'][] = $now;
                $result = ['sent' => true, 'reason' => 'sent', 'groups' => count($due)];
            } else {
                $nextDue = min($nextDue ?? $now + 900, $now + 900);
                $result = ['sent' => false, 'reason' => 'send_failed', 'groups' => count($due)];
                error_log('BareBonesForms alerts: notification email could not be sent; will retry.');
            }
        }

        $tmp = $statePath . '.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $statePath);
        }
        $dueFile = $dir . '/.alerts_due';
        if ($nextDue !== null) @file_put_contents($dueFile, (string)$nextDue);
        elseif (is_file($dueFile)) @unlink($dueFile);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Fold incidents after $offset into $groups; returns the new offset. Rotates the log past 1 MB. */
function bbf_alert_collect(string $log, int $offset, array &$groups): int {
    $handle = @fopen($log, 'c+');
    if ($handle === false) return $offset;
    flock($handle, LOCK_EX);
    $size = (int)(fstat($handle)['size'] ?? 0);
    if ($offset > $size) $offset = 0;
    fseek($handle, $offset);
    while (($line = fgets($handle)) !== false && str_ends_with($line, "\n")) {
        $offset += strlen($line);
        $incident = json_decode($line, true);
        if (!is_array($incident) || !is_string($incident['form'] ?? null) || !is_string($incident['type'] ?? null)) continue;
        $key = $incident['form'] . '|' . $incident['type'];
        $group = $groups[$key] ?? ['form' => $incident['form'], 'type' => $incident['type'], 'count' => 0, 'last_sent' => 0];
        if (($group['count'] ?? 0) === 0) $group['first_at'] = (int)($incident['at'] ?? 0);
        $group['count'] = (int)($group['count'] ?? 0) + 1;
        $group['last_at'] = (int)($incident['at'] ?? 0);
        $group['detail'] = (string)($incident['detail'] ?? '');
        $groups[$key] = $group;
    }
    if ($offset > 1048576) {
        rewind($handle);
        @file_put_contents($log . '.1', (string)stream_get_contents($handle));
        ftruncate($handle, 0);
        $offset = 0;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $offset;
}

/**
 * Site label for alerts. The admin-configured From domain comes first: SERVER_NAME can echo the visitor's
 * Host header (Apache, UseCanonicalName Off), and cron has none at all. Whatever is used is reduced to
 * hostname characters, so it can never carry text or headers into the subject.
 */
function bbf_alert_site(array $config): string {
    $from = (string)($config['mail']['from_email'] ?? '');
    $candidates = [filter_var($from, FILTER_VALIDATE_EMAIL) ? substr($from, strrpos($from, '@') + 1) : '',
        (string)($_SERVER['SERVER_NAME'] ?? ''), (string)gethostname()];
    foreach ($candidates as $candidate) {
        $candidate = substr((string)preg_replace('/[^a-zA-Z0-9.-]/', '', $candidate), 0, 100);
        if ($candidate !== '') return $candidate;
    }
    return 'server';
}

/** error_notify → valid addresses only; CR/LF can never split it into extra headers or recipients. */
function bbf_alert_recipients(string $to): array {
    return array_values(array_filter(array_map('trim', explode(',', str_replace(["\r", "\n", "\0"], '', $to))),
        static fn($address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false));
}

function bbf_alert_message(array $config, array $groups, int $interval): array {
    $host = bbf_alert_site($config);
    $total = array_sum(array_column($groups, 'count'));
    $subject = 'BareBonesForms: ' . count($groups) . ' problem' . (count($groups) === 1 ? '' : 's') . " on $host";
    $body = "BareBonesForms on $host recorded problems that need your attention.\n\n";
    foreach ($groups as $group) {
        $count = (int)$group['count'];
        $body .= '[' . $group['form'] . '] ' . $group['type']
            . ($count > 1 ? " — {$count}× since " . date('Y-m-d H:i', (int)($group['first_at'] ?? 0)) : '') . "\n"
            . '  ' . date('Y-m-d H:i:s', (int)($group['last_at'] ?? 0)) . ': ' . $group['detail'] . "\n\n";
    }
    $types = array_column($groups, 'type');
    $body .= "Total incidents in this report: $total.\n"
        . (array_diff($types, ['Update available']) !== []
            ? 'The same problem on the same form is reported at most once every ' . bbf_alert_duration($interval) . ".\n" : '')
        . (in_array('Update available', $types, true) ? "Update available: one notice per new version.\n" : '')
        . 'Full history: incidents.log in logs_dir (' . bbf_alert_dir($config) . ").\n";
    return [$subject, $body];
}

function bbf_alert_duration(int $seconds): string {
    if ($seconds % 86400 === 0) return ($seconds / 86400) . ' day(s)';
    if ($seconds % 3600 === 0) return ($seconds / 3600) . ' hour(s)';
    return round($seconds / 60) . ' minute(s)';
}

/** mail() envelope sender goes onto the sendmail command line: plain address characters only, no whitespace or quotes
 * (FILTER_VALIDATE_EMAIL admits both). Not when the host's sendmail_path already sets one: a second -f is rejected
 * by some wrappers and overrides the host's sender in others. */
function bbf_mail_envelope_sender(string $from, ?string $sendmailPath = null): bool {
    $sendmailPath ??= (string)ini_get('sendmail_path');
    return !preg_match('/(?:\A|\s)-f/', $sendmailPath) && (bool)preg_match('/\A[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\z/', $from);
}

/** Configured SMTP first (if any); PHP mail() as the fallback, so a broken SMTP can still report itself. */
function bbf_alert_send(string $to, string $subject, string $body, array $config): bool {
    $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
    $addresses = bbf_alert_recipients($to);
    if ($addresses === []) return false;
    if (($mail['method'] ?? 'mail') === 'smtp' && function_exists('sendEmail')) {
        try {
            $html = '<pre style="font:13px/1.4 monospace;white-space:pre-wrap">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
            if ((sendEmail(implode(', ', $addresses), $subject, $html, $mail)['ok'] ?? false) === true) return true;
        } catch (Throwable $error) {
            // Fall through to mail().
        }
    }
    $from = (string)($mail['from_email'] ?? 'noreply@example.com');
    $fromName = str_replace(["\r", "\n", "\0"], '', (string)($mail['from_name'] ?? 'BareBonesForms'));
    $encodedSubject = preg_match('/[^\x20-\x7E]/', $subject) ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
    $fromHeader = function_exists('bbf_mail_address_header') ? bbf_mail_address_header($fromName, $from)
        : "$fromName <" . str_replace(["\r", "\n", "\0"], '', $from) . '>';
    $headers = "From: $fromHeader\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (function_exists('bbf_mail_standard_headers')) {
        foreach (bbf_mail_standard_headers($from) as $name => $value) $headers .= "$name: $value\r\n";
        $body = quoted_printable_encode($body);
    }
    // Envelope sender = From, so SPF/DMARC align with the site's domain instead of the hosting account's.
    $params = bbf_mail_envelope_sender($from) ? '-f' . $from : '';
    return function_exists('mail') && @mail(implode(', ', $addresses), $encodedSubject, $body, $headers, $params);
}

/**
 * Daily health check (cron): every form definition, writable data folders, SMTP login, and deliveries
 * from the last 7 days still waiting for the admin. Each problem is recorded as an incident.
 * Also asks GitHub once whether a newer release exists (bbf_update_check).
 */
function bbf_alert_selfcheck(array $config, ?callable $smtpProbe = null, ?callable $fetchLatest = null): array {
    $problems = [];
    $forms = 0;
    $formsDir = rtrim((string)($config['forms_dir'] ?? __DIR__ . '/forms'), '/\\');
    foreach (glob($formsDir . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (str_ends_with($id, '.map') || $id === 'form.schema') continue;
        $forms++;
        $definition = json_decode((string)@file_get_contents($file), true);
        $errors = !is_array($definition) ? ['not valid JSON']
            : (($definition['id'] ?? null) !== $id ? ['id does not match the file name']
            : (empty($definition['fields']) ? ['no fields'] : validateFormDefinition($definition)));
        if ($errors !== []) $problems[] = [$id, 'Invalid form definition', 'Self-check: ' . implode('; ', array_slice($errors, 0, 5))];
    }

    foreach (['submissions_dir' => $config['submissions_dir'] ?? __DIR__ . '/submissions', 'logs_dir' => bbf_alert_dir($config)] as $key => $dir) {
        if (!is_dir((string)$dir) || !is_writable((string)$dir)) {
            $problems[] = ['-', 'Data folder not writable', "Self-check: $key ($dir) is missing or not writable; submissions or logs cannot be saved."];
        }
    }

    if (function_exists('bbf_auth_config_problems')) {
        foreach (bbf_auth_config_problems($config) as $problem) {
            if ($problem['level'] === 'error') $problems[] = ['-', 'Access configuration problem', 'Self-check: ' . $problem['message']];
        }
    }

    // Security rules and docs: an upgrade by an older upgrader (or FTP) can leave the release's new rules out.
    require_once __DIR__ . '/bbf_upgrade.php';
    foreach (bbf_htaccess_missing_rules(__DIR__) as $missing) $problems[] = ['-', 'Security rules missing', "Self-check: $missing"];
    if (function_exists('bbf_diagnostic_probe')) {
        foreach (['README.md', 'CHANGELOG.md'] as $doc) {
            if (!is_file(__DIR__ . "/$doc") || bbf_diagnostic_probe($config, $doc, $body) !== 200) continue;
            if ($body !== substr((string)file_get_contents(__DIR__ . "/$doc"), 0, 1048576)) continue; // a catch-all page, not the file
            $problems[] = ['-', 'Docs publicly readable', "Self-check: $doc is served over HTTP and reveals the installed version. Add the *.md rule from .htaccess (Nginx: see its comments) or delete the file."];
        }
    }

    $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
    if (($mail['method'] ?? 'mail') === 'smtp') {
        $smtpProbe ??= static fn(array $mailConfig): array => bbf_delivery_smtp(['probe' => true], $mailConfig);
        $probe = $smtpProbe($mail);
        if (empty($probe['ok'])) {
            $problems[] = ['-', 'SMTP check failed', 'Self-check: login to ' . ($mail['smtp_host'] ?? '?') . ':' . ($mail['smtp_port'] ?? '?')
                . " failed at stage '" . ($probe['stage'] ?? '?') . "'" . (!empty($probe['code']) ? ' (code ' . (int)$probe['code'] . ')' : '')
                . '. Emails to you and to respondents will not be sent until this is fixed.'];
        }
    }

    $waiting = [];
    $root = rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\') . '/.delivery';
    foreach (glob($root . '/*/*.json') ?: [] as $path) {
        if ((int)@filemtime($path) < time() - 7 * 86400) continue;
        $state = bbf_outbox_status($path)['state'] ?? 'attention_required';
        if (in_array($state, ['attention_required', 'retry_scheduled'], true)) $waiting[basename(dirname($path))][] = basename($path, '.json');
    }
    foreach ($waiting as $form => $ids) {
        $problems[] = [(string)$form, 'Deliveries waiting for action', 'Self-check: ' . count($ids) . ' recent submission(s) have an undelivered email, webhook or action. '
            . 'Open them in the viewer and retry: ' . implode(', ', array_slice($ids, 0, 5)) . (count($ids) > 5 ? ', …' : '')];
    }

    foreach ($problems as [$form, $type, $detail]) bbf_alert_record($config, $form, $type, $detail);
    require_once __DIR__ . '/bbf_upgrade.php';
    return [
        'ok' => $problems === [],
        'forms_checked' => $forms,
        'problems' => array_map(static fn(array $p) => ['form' => $p[0], 'type' => $p[1], 'detail' => $p[2]], $problems),
        'update' => bbf_update_check($config, $fetchLatest),
        'notify' => trim((string)($config['error_notify'] ?? '')) === '' ? 'error_notify is empty: problems are only logged, no email is sent' : 'enabled',
    ];
}

/** A 404 is worth an alert only for a form that has existed here; random IDs from bots are not. */
function bbf_alert_form_known(array $config, string $formId): bool {
    $root = rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\');
    return is_file(bbf_alert_dir($config) . '/.forms_seen/' . $formId)
        || is_dir($root . '/' . $formId) || is_file($root . '/' . $formId . '.csv') || is_dir($root . '/.delivery/' . $formId);
}

function bbf_alert_form_seen(array $config, string $formId): void {
    $marker = bbf_alert_dir($config) . '/.forms_seen/' . $formId;
    if (is_file($marker)) return;
    if (!is_dir(dirname($marker))) @mkdir(dirname($marker), 0700, true);
    @touch($marker);
}

function bbf_alert_job_label(array $job): string {
    $type = (string)($job['type'] ?? '');
    // Confirmation and owner notification fail together when SMTP is down: name them apart, not as two identical lines.
    if ($type === 'email' || $type === 'smtp') return match ((string)($job['key'] ?? '')) {
        'confirm' => 'confirmation email', 'notify' => 'notification email', default => 'email' };
    if ($type === 'action') return 'action ' . substr((string)preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($job['target'] ?? '')), 0, 60);
    return $type === 'webhook' ? 'webhook' : 'delivery';
}

/** Record a failed delivery attempt (email, webhook, action) with what the admin can do next. */
function bbf_alert_delivery_failure(array $config, string $formId, string $submissionId, string $label, array $outcome, string $state): void {
    $stage = (string)($outcome['stage'] ?? 'delivery');
    $code = (int)($outcome['code'] ?? 0);
    $next = match ($state) {
        'pending', 'failed' => 'retry it from the viewer once the cause is fixed',
        'ambiguous' => 'check whether it arrived before retrying (it may have been delivered)',
        'inline' => 'the form does not store submissions, so this one cannot be retried',
        default => 'automatic attempts are exhausted; retry it from the viewer once the cause is fixed',
    };
    bbf_alert_record($config, $formId, "Delivery failed: $label",
        ($submissionId !== '' ? "Submission $submissionId: " : '') . "$label failed at stage '$stage'"
        . ($code > 0 ? " (code $code)" : '') . ". The submission itself is " . ($state === 'inline' ? 'not stored' : 'saved')
        . "; $next.");
}
