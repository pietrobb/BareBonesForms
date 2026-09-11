<?php

declare(strict_types=1);

function bbf_outbox_now(?int $now = null): int {
    return $now ?? time();
}

function bbf_outbox_safe_stage(string $stage): string {
    static $allowed = [
        'action', 'auth_login', 'auth_password', 'auth_password_write', 'auth_user', 'auth_user_write',
        'auth_write', 'connect', 'data', 'data_write', 'delivery', 'ehlo', 'ehlo_tls', 'ehlo_tls_write',
        'ehlo_write', 'final_reply', 'greeting', 'http', 'lease', 'mail_from', 'mail_from_write',
        'message_write', 'recipient', 'recipient_write', 'starttls', 'starttls_write', 'tls_handshake',
        'transport',
    ];
    $stage = strtolower($stage);
    return in_array($stage, $allowed, true) ? $stage : 'delivery';
}

function bbf_outbox_safe_text(string $message, int $limit = 240): string {
    $message = trim((string)preg_replace('/[\x00-\x1f\x7f]/', ' ', $message));
    return substr($message, 0, $limit);
}

function bbf_outbox_fallback_message(string $state, string $stage, int $code): string {
    if ($state === 'succeeded') {
        if ($stage === 'http') return 'Remote service accepted delivery.';
        if ($stage === 'final_reply') return 'SMTP server accepted delivery.';
        if ($stage === 'action') return 'Action completed.';
        return 'Delivery completed.';
    }
    if ($state === 'ambiguous' || $stage === 'lease' || $stage === 'message_write'
        || ($stage === 'final_reply' && $code === 0)) {
        return 'Delivery outcome is uncertain; retry may duplicate the side effect.';
    }
    if ($stage === 'http') {
        if (in_array($code, [408, 425, 429], true) || ($code >= 500 && $code < 600)) {
            return 'Remote service is temporarily unavailable.';
        }
        return 'Remote service rejected delivery.';
    }
    if (str_starts_with($stage, 'auth')) return 'SMTP authentication failed.';
    if (str_starts_with($stage, 'recipient')) return 'SMTP recipient was rejected.';
    if ($stage === 'connect' || $stage === 'transport' || $stage === 'tls_handshake') {
        return 'SMTP transport failed.';
    }
    if ($stage === 'action') return 'Action failed.';
    return 'Delivery failed.';
}

function bbf_outbox_result_projection($result, string $jobState): ?array {
    if (!is_array($result)) return null;
    $stage = bbf_outbox_safe_stage((string)($result['stage'] ?? 'delivery'));
    $code = max(0, min(999, (int)($result['code'] ?? 0)));
    $state = in_array($jobState, ['succeeded', 'failed', 'ambiguous', 'exhausted'], true) ? $jobState : 'failed';
    $message = ($result['safe'] ?? false) === true
        ? bbf_outbox_safe_text((string)($result['message'] ?? ''))
        : bbf_outbox_fallback_message($state, $stage, $code);
    if ($message === '') $message = bbf_outbox_fallback_message($state, $stage, $code);
    return [
        'stage' => $stage,
        'code' => $code,
        'retryable' => ($result['retryable'] ?? false) === true,
        'message' => $message,
        'at' => max(0, (int)($result['at'] ?? 0)),
    ];
}

function bbf_outbox_transaction(string $path, callable $operation) {
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return ['ok' => false, 'reason' => 'lock'];
    try {
        if (!flock($lock, LOCK_EX)) return ['ok' => false, 'reason' => 'lock'];
        $ledger = null;
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw === false) return ['ok' => false, 'reason' => 'read'];
            try {
                $ledger = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                return ['ok' => false, 'reason' => 'decode'];
            }
            if (!is_array($ledger)) return ['ok' => false, 'reason' => 'decode'];
        }
        $change = $operation($ledger);
        if (!is_array($change) || !array_key_exists('result', $change)) {
            return ['ok' => false, 'reason' => 'operation'];
        }
        if (array_key_exists('ledger', $change)) {
            try {
                $json = json_encode($change['ledger'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                return ['ok' => false, 'reason' => 'encode'];
            }
            $write = $GLOBALS['_bbf_outbox_write'] ?? null;
            $written = is_callable($write)
                ? $write($path, $json)
                : (function_exists('bbf_storage_replace') && bbf_storage_replace($path,
                    static fn($fp) => bbf_storage_write_all($fp, $json)));
            if ($written !== true) return ['ok' => false, 'reason' => 'persist'];
        }
        return $change['result'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_outbox_delete_submissions(array $deletions, ?callable $beforeDelete = null): array {
    if ($deletions === []) return ['ok' => true, 'deleted' => 0];

    $items = [];
    $lockPaths = [];
    foreach ($deletions as $deletion) {
        if (!is_array($deletion) || !is_string($deletion['path'] ?? null)
            || !is_callable($deletion['delete'] ?? null)) {
            return ['ok' => false, 'reason' => 'operation', 'deleted' => 0];
        }
        $path = $deletion['path'];
        if ($path !== '') {
            // Lock even absent ledgers so creation cannot race preflight and primary deletion.
            $lockPaths[$path] = $path;
            $lockPaths[$path . '.events'] = $path . '.events';
        }
        $items[] = ['delete' => $deletion['delete'],
            'paths' => $path === '' ? [] : [$path, $path . '.events']];
    }

    ksort($lockPaths, SORT_STRING);
    $locks = [];
    try {
        foreach ($lockPaths as $ledgerPath) {
            $directory = dirname($ledgerPath);
            if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
                || is_link($directory)) {
                return ['ok' => false, 'reason' => 'lock', 'deleted' => 0];
            }
            $lock = @fopen($ledgerPath . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) fclose($lock);
                return ['ok' => false, 'reason' => 'lock', 'deleted' => 0];
            }
            $locks[] = $lock;
        }

        $snapshots = [];
        foreach ($lockPaths as $ledgerPath) {
            clearstatcache(true, $ledgerPath);
            $exists = is_file($ledgerPath);
            $raw = null;
            if ($exists) {
                $raw = file_get_contents($ledgerPath);
                if ($raw === false) return ['ok' => false, 'reason' => 'read', 'deleted' => 0];
                try {
                    $ledger = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $error) {
                    return ['ok' => false, 'reason' => 'decode', 'deleted' => 0];
                }
                if (!is_array($ledger) || !is_array($ledger['jobs'] ?? null)) {
                    return ['ok' => false, 'reason' => 'decode', 'deleted' => 0];
                }
                foreach ($ledger['jobs'] as $job) {
                    if (!is_array($job)) return ['ok' => false, 'reason' => 'decode', 'deleted' => 0];
                    if (($job['state'] ?? null) === 'running') {
                        return ['ok' => false, 'reason' => 'running', 'deleted' => 0];
                    }
                }
            }
            $snapshots[$ledgerPath] = ['exists' => $exists, 'bytes' => $raw];
        }
        if ($beforeDelete !== null) {
            try {
                if ($beforeDelete($snapshots) !== true) {
                    return ['ok' => false, 'reason' => 'archive', 'deleted' => 0];
                }
            } catch (Throwable $error) {
                error_log('BareBonesForms delete archive: ' . $error->getMessage());
                return ['ok' => false, 'reason' => 'archive', 'deleted' => 0];
            }
        }

        $tombstone = ['version' => 1, 'deleted' => true, 'jobs' => [], 'events' => [], 'semantic_events' => []];
        try {
            $json = json_encode($tombstone, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            return ['ok' => false, 'reason' => 'encode', 'deleted' => 0];
        }
        $writeBytes = static function (string $ledgerPath, string $bytes): bool {
            try {
                $write = $GLOBALS['_bbf_outbox_write'] ?? null;
                return is_callable($write)
                    ? $write($ledgerPath, $bytes) === true
                    : (function_exists('bbf_storage_replace') && bbf_storage_replace($ledgerPath,
                        static fn($fp) => bbf_storage_write_all($fp, $bytes)));
            } catch (Throwable $error) {
                return false;
            }
        };

        $attempted = [];
        foreach ($lockPaths as $ledgerPath) {
            $attempted[] = $ledgerPath;
            if ($writeBytes($ledgerPath, $json)) continue;

            $rollbackOk = true;
            foreach (array_reverse($attempted) as $attemptedPath) {
                $snapshot = $snapshots[$attemptedPath];
                clearstatcache(true, $attemptedPath);
                $currentExists = is_file($attemptedPath);
                if ($snapshot['exists']) {
                    $current = $currentExists ? file_get_contents($attemptedPath) : false;
                    if ($current === $snapshot['bytes']) continue;
                    if (!$writeBytes($attemptedPath, $snapshot['bytes'])) $rollbackOk = false;
                } elseif ($currentExists && !@unlink($attemptedPath)) {
                    $rollbackOk = false;
                }
            }
            return ['ok' => false, 'reason' => $rollbackOk ? 'persist' : 'rollback', 'deleted' => 0];
        }

        $restoreItems = static function (int $start) use ($items, $snapshots, $writeBytes): bool {
            $ok = true; $paths = [];
            foreach (array_slice($items, $start) as $item) foreach ($item['paths'] as $path) $paths[$path] = $path;
            foreach ($paths as $path) {
                $snapshot = $snapshots[$path];
                clearstatcache(true, $path);
                if ($snapshot['exists']) {
                    if (file_get_contents($path) !== $snapshot['bytes'] && !$writeBytes($path, $snapshot['bytes'])) $ok = false;
                } elseif (is_file($path) && !@unlink($path)) $ok = false;
            }
            return $ok;
        };
        $deleted = 0;
        foreach ($items as $index => $item) {
            try { $result = ($item['delete'])(); }
            catch (Throwable $error) { $result = null; }
            if (!is_array($result) || ($result['ok'] ?? false) !== true
                || !is_bool($result['deleted'] ?? null)) {
                $didDelete = is_array($result) && ($result['deleted'] ?? false) === true;
                $ambiguous = is_array($result) && ($result['ambiguous'] ?? false) === true;
                if ($didDelete) $deleted++;
                // A lost database commit acknowledgement may mean the primary is gone. Keep its privacy tombstone.
                $restored = $restoreItems($index + (($didDelete || $ambiguous) ? 1 : 0));
                $reason = $ambiguous ? 'ambiguous' : 'primary';
                $failure = ['ok' => false, 'reason' => $restored ? $reason : 'rollback', 'deleted' => $deleted];
                if ($ambiguous) $failure['ambiguous'] = true;
                return $failure;
            }
            if ($result['deleted']) $deleted++;
        }
        return ['ok' => true, 'deleted' => $deleted];
    } finally {
        foreach (array_reverse($locks) as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function bbf_outbox_delete_submission(string $path, callable $deletePrimary): array {
    return bbf_outbox_delete_submissions([['path' => $path, 'delete' => $deletePrimary]]);
}

function bbf_outbox_job(array $job, int $now, int $maxAttempts): array {
    $key = (string)($job['key'] ?? '');
    if ($key === '' || !preg_match('/\A[a-zA-Z0-9._:-]+\z/', $key)) {
        throw new InvalidArgumentException('Invalid outbox job key.');
    }
    $normalized = [
        'key' => $key,
        'type' => substr((string)($job['type'] ?? 'action'), 0, 32),
        'payload_hash' => preg_match('/\A[a-f0-9]{64}\z/', (string)($job['payload_hash'] ?? ''))
            ? (string)$job['payload_hash'] : hash('sha256', ''),
        'idempotency_key' => substr((string)($job['idempotency_key'] ?? $key), 0, 160),
        'target' => substr((string)($job['target'] ?? ''), 0, 160),
        'idempotent' => ($job['idempotent'] ?? false) === true,
        'state' => 'pending',
        'attempts' => 0,
        'max_attempts' => max(1, min(10, $maxAttempts)),
        'next_retry' => $now,
        'lease_token' => null,
        'lease_started' => null,
        'last_result' => null,
        'history' => [],
        'updated_at' => $now,
    ];
    if (array_key_exists('payload', $job)) {
        if (!is_array($job['payload'])) throw new InvalidArgumentException('Invalid outbox job payload.');
        $normalized['payload'] = $job['payload'];
    }
    return $normalized;
}

function bbf_outbox_init(string $path, string $submissionKey, array $jobs, int $maxAttempts = 3, ?int $now = null, array $context = []): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($submissionKey, $jobs, $maxAttempts, $now, $context): array {
        if (is_array($ledger)) {
            return ['result' => ['ok' => true, 'created' => false, 'ledger' => $ledger]];
        }
        $normalized = [];
        foreach ($jobs as $job) {
            $item = bbf_outbox_job((array)$job, $now, $maxAttempts);
            if (isset($normalized[$item['key']])) throw new InvalidArgumentException('Duplicate outbox job key.');
            $normalized[$item['key']] = $item;
        }
        $ledger = [
            'version' => 1,
            'submission_key' => $submissionKey,
            'created_at' => $now,
            'updated_at' => $now,
            'jobs' => $normalized,
            'events' => [],
            'semantic_events' => [],
        ];
        if ($context !== []) $ledger['context'] = $context;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'created' => true, 'ledger' => $ledger]];
    });
}

function bbf_outbox_read(string $path): array {
    return bbf_outbox_transaction($path, static fn($ledger) => [
        'result' => is_array($ledger) ? ['ok' => true, 'ledger' => $ledger] : ['ok' => false, 'reason' => 'missing'],
    ]);
}

function bbf_outbox_status(string $path): array {
    $empty = ['ok' => true, 'state' => 'none', 'settled' => true, 'jobs' => []];
    if ($path === '' || !is_file($path)) return $empty;
    $raw = @file_get_contents($path);
    if ($raw === false) return ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
    try {
        $ledger = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
    }
    if (!is_array($ledger) || !is_array($ledger['jobs'] ?? null)) {
        return ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
    }

    $jobs = [];
    $settled = true;
    $hasAttention = false;
    $hasProcessing = false;
    $hasRetry = false;
    foreach ($ledger['jobs'] as $ledgerKey => $stored) {
        if (!is_array($stored)) {
            $hasAttention = true;
            $settled = false;
            continue;
        }
        $key = (string)($stored['key'] ?? $ledgerKey);
        if ($key === '' || strlen($key) > 160 || !preg_match('/\A[a-zA-Z0-9._:-]+\z/', $key)) $key = 'job';
        $type = strtolower((string)($stored['type'] ?? 'action'));
        if ($type === '' || strlen($type) > 32 || !preg_match('/\A[a-z0-9._:-]+\z/', $type)) $type = 'action';
        $state = (string)($stored['state'] ?? 'failed');
        if (!in_array($state, ['pending', 'running', 'succeeded', 'failed', 'ambiguous', 'exhausted'], true)) {
            $state = 'failed';
        }
        $attempts = max(0, (int)($stored['attempts'] ?? 0));
        $maxAttempts = max(1, (int)($stored['max_attempts'] ?? 1));
        $retryable = ($stored['last_result']['retryable'] ?? false) === true;
        $requiresConfirmation = $state === 'ambiguous' && (($stored['idempotent'] ?? false) !== true);
        $canRetry = $attempts < $maxAttempts
            && (($state === 'failed' && $retryable) || $state === 'ambiguous');
        $nextRetry = isset($stored['next_retry']) && is_numeric($stored['next_retry'])
            ? max(0, (int)$stored['next_retry']) : null;
        $lastResult = bbf_outbox_result_projection($stored['last_result'] ?? null, $state);
        $projection = [
            'key' => $key,
            'type' => $type,
            'state' => $state,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'next_retry' => $nextRetry,
            'last_result' => $lastResult,
            'can_retry' => $canRetry,
            'requires_confirmation' => $requiresConfirmation,
        ];
        $history = [];
        foreach (array_slice(is_array($stored['history'] ?? null) ? array_values($stored['history']) : [], -10) as $entry) {
            if (!is_array($entry)) continue;
            $entryState = in_array(($entry['state'] ?? ''), ['succeeded', 'failed', 'ambiguous', 'exhausted'], true)
                ? (string)$entry['state'] : 'failed';
            $safeEntry = bbf_outbox_result_projection($entry, $entryState);
            if ($safeEntry === null) continue;
            $safeEntry['attempt'] = max(0, (int)($entry['attempt'] ?? 0));
            $safeEntry['state'] = $entryState;
            $history[] = $safeEntry;
        }
        if ($history !== []) $projection['history'] = $history;
        $jobs[] = $projection;

        if ($state !== 'succeeded') $settled = false;
        if (in_array($state, ['ambiguous', 'exhausted'], true) || ($state === 'failed' && !$canRetry)) {
            $hasAttention = true;
        } elseif (in_array($state, ['pending', 'running'], true)) {
            $hasProcessing = true;
        } elseif ($state === 'failed') {
            $hasRetry = true;
        }
    }
    $state = $hasAttention ? 'attention_required'
        : ($hasProcessing ? 'processing' : ($hasRetry ? 'retry_scheduled' : 'succeeded'));
    return ['ok' => true, 'state' => $state, 'settled' => $settled, 'jobs' => $jobs];
}

function bbf_outbox_settlement(string $path): array {
    return bbf_outbox_transaction($path, static function($ledger): array {
        if (!is_array($ledger) || !is_array($ledger['jobs'] ?? null)) {
            return ['result' => ['ok' => false, 'reason' => 'missing']];
        }
        $unsettled = [];
        foreach ($ledger['jobs'] as $key => $job) {
            if (($job['state'] ?? null) !== 'succeeded') $unsettled[] = (string)$key;
        }
        return ['result' => ['ok' => true, 'settled' => $unsettled === [], 'unsettled' => $unsettled]];
    });
}

function bbf_outbox_claim(string $path, string $jobKey, ?int $now = null): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($jobKey, $now): array {
        if (!is_array($ledger) || !isset($ledger['jobs'][$jobKey])) return ['result' => ['ok' => false, 'reason' => 'missing']];
        $job = $ledger['jobs'][$jobKey];
        if (!in_array($job['state'], ['pending', 'failed'], true)) return ['result' => ['ok' => false, 'reason' => $job['state']]];
        if ($job['state'] === 'failed' && !($job['last_result']['retryable'] ?? false)) return ['result' => ['ok' => false, 'reason' => 'terminal']];
        if (($job['next_retry'] ?? 0) > $now) return ['result' => ['ok' => false, 'reason' => 'backoff']];
        if ((int)$job['attempts'] >= (int)$job['max_attempts']) return ['result' => ['ok' => false, 'reason' => 'exhausted']];
        $token = bin2hex(random_bytes(16));
        $job['state'] = 'running';
        $job['attempts']++;
        $job['lease_token'] = $token;
        $job['lease_started'] = $now;
        $job['updated_at'] = $now;
        $ledger['jobs'][$jobKey] = $job;
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'token' => $token, 'job' => $job]];
    });
}

function bbf_outbox_complete(string $path, string $jobKey, string $token, array $outcome, ?int $now = null, int $retryDelay = 60): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($jobKey, $token, $outcome, $now, $retryDelay): array {
        if (!is_array($ledger) || !isset($ledger['jobs'][$jobKey])) return ['result' => ['ok' => false, 'reason' => 'missing']];
        $job = $ledger['jobs'][$jobKey];
        if ($job['state'] !== 'running' || !hash_equals((string)$job['lease_token'], $token)) {
            return ['result' => ['ok' => false, 'reason' => 'lease']];
        }
        $state = (string)($outcome['state'] ?? (($outcome['ok'] ?? false) ? 'succeeded' : 'failed'));
        if (($outcome['ok'] ?? false) === true) $state = 'succeeded';
        if (!in_array($state, ['succeeded', 'failed', 'ambiguous'], true)) $state = 'failed';
        $retryable = $state === 'failed' && (($outcome['retryable'] ?? false) === true);
        $maxAttempts = max(1, (int)($job['max_attempts'] ?? 1));
        $canSchedule = $retryable && (int)($job['attempts'] ?? 0) < $maxAttempts;
        if ($retryable && !$canSchedule) $state = 'exhausted';
        $stage = bbf_outbox_safe_stage((string)($outcome['stage'] ?? 'delivery'));
        $code = max(0, min(999, (int)($outcome['code'] ?? 0)));
        $safeMessage = isset($outcome['safe_message']) && is_string($outcome['safe_message'])
            ? bbf_outbox_safe_text($outcome['safe_message']) : '';
        if ($safeMessage === '') $safeMessage = bbf_outbox_fallback_message($state, $stage, $code);
        $job['state'] = $state;
        $job['last_result'] = [
            'stage' => $stage,
            'code' => $code,
            'retryable' => $retryable,
            'message' => $safeMessage,
            'at' => $now,
            'safe' => true,
        ];
        $history = [];
        foreach (array_slice(is_array($job['history'] ?? null) ? array_values($job['history']) : [], -9) as $entry) {
            if (!is_array($entry)) continue;
            $entryState = in_array(($entry['state'] ?? ''), ['succeeded', 'failed', 'ambiguous', 'exhausted'], true)
                ? (string)$entry['state'] : 'failed';
            $safeEntry = bbf_outbox_result_projection($entry, $entryState);
            if ($safeEntry === null) continue;
            $safeEntry['attempt'] = max(0, (int)($entry['attempt'] ?? 0));
            $safeEntry['state'] = $entryState;
            $safeEntry['safe'] = true;
            $history[] = $safeEntry;
        }
        $history[] = $job['last_result'] + [
            'attempt' => max(0, (int)($job['attempts'] ?? 0)),
            'state' => $state,
        ];
        $job['history'] = array_slice($history, -10);
        $job['next_retry'] = $canSchedule ? $now + max(1, min(86400, $retryDelay)) : null;
        $job['lease_token'] = null;
        $job['lease_started'] = null;
        $job['updated_at'] = $now;
        $ledger['jobs'][$jobKey] = $job;
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'job' => $job]];
    });
}

function bbf_outbox_expire_leases(string $path, int $leaseSeconds, ?int $now = null): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($leaseSeconds, $now): array {
        if (!is_array($ledger)) return ['result' => ['ok' => false, 'reason' => 'missing']];
        $changed = [];
        foreach ($ledger['jobs'] as $key => &$job) {
            if ($job['state'] === 'running' && (int)$job['lease_started'] + max(1, $leaseSeconds) <= $now) {
                $job['state'] = 'ambiguous';
                $job['last_result'] = ['stage' => 'lease', 'code' => 0, 'retryable' => false,
                    'message' => 'Worker ended without a durable outcome; retry may duplicate the side effect.', 'at' => $now];
                $job['lease_token'] = null;
                $job['lease_started'] = null;
                $job['updated_at'] = $now;
                $changed[] = $key;
            }
        }
        unset($job);
        if ($changed === []) return ['result' => ['ok' => true, 'changed' => []]];
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'changed' => $changed]];
    });
}

function bbf_outbox_retry(string $path, string $jobKey, bool $confirmAmbiguous = false, ?int $now = null): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($jobKey, $confirmAmbiguous, $now): array {
        if (!is_array($ledger) || !isset($ledger['jobs'][$jobKey])) return ['result' => ['ok' => false, 'reason' => 'missing']];
        $job = $ledger['jobs'][$jobKey];
        $state = (string)($job['state'] ?? 'failed');
        if ($state === 'succeeded') return ['result' => ['ok' => false, 'reason' => 'succeeded']];
        $attempts = max(0, (int)($job['attempts'] ?? 0));
        $maxAttempts = max(1, (int)($job['max_attempts'] ?? 1));
        $allowed = $state === 'failed' && (($job['last_result']['retryable'] ?? false) === true);
        if ($state === 'ambiguous') {
            if (($job['idempotent'] ?? false) !== true && !$confirmAmbiguous) {
                return ['result' => ['ok' => false, 'reason' => 'confirmation_required']];
            }
            $allowed = true;
        }
        if (!$allowed) return ['result' => ['ok' => false, 'reason' => $state]];
        if ($attempts >= $maxAttempts) {
            $job['state'] = 'exhausted';
            $job['next_retry'] = null;
        } else {
            $job['state'] = 'pending';
            $job['next_retry'] = $now;
        }
        $job['updated_at'] = $now;
        $ledger['jobs'][$jobKey] = $job;
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => [
            'ok' => $job['state'] === 'pending',
            'reason' => $job['state'] === 'pending' ? null : 'exhausted',
            'job' => $job,
        ]];
    });
}

function bbf_outbox_record_event(string $path, string $eventId, string $semanticKey, string $status, ?int $now = null): array {
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($eventId, $semanticKey, $status, $now): array {
        if (!is_array($ledger)) return ['result' => ['ok' => false, 'reason' => 'missing']];
        if (($ledger['deleted'] ?? false) === true) return ['result' => ['ok' => false, 'reason' => 'deleted']];
        $eventHash = hash('sha256', $eventId);
        $semanticHash = hash('sha256', $semanticKey);
        if (isset($ledger['events'][$eventHash])) return ['result' => ['ok' => true, 'duplicate' => true, 'semantic_duplicate' => true]];
        $semanticDuplicate = isset($ledger['semantic_events'][$semanticHash]);
        $ledger['events'][$eventHash] = ['semantic' => $semanticHash, 'status' => $status, 'at' => $now];
        if (!$semanticDuplicate) $ledger['semantic_events'][$semanticHash] = ['status' => $status, 'at' => $now];
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'duplicate' => false, 'semantic_duplicate' => $semanticDuplicate]];
    });
}

function bbf_outbox_existing_path(array $config, string $formId, string $submissionId): string {
    if ($formId === '' || $submissionId === '' || strlen($formId) > 160 || strlen($submissionId) > 160
        || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)
        || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $submissionId)) {
        return '';
    }
    $root = rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\');
    if ($root === '') return '';
    return $root . '/.delivery/' . $formId . '/' . $submissionId . '.json';
}

function bbf_outbox_path(array $config, string $formId, string $submissionId): string {
    $path = bbf_outbox_existing_path($config, $formId, $submissionId);
    if ($path === '') return '';
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return '';
    return $path;
}

function bbf_outbox_jobs(array $form, array $submission): array {
    $onSubmit = (array)($form['on_submit'] ?? []);
    $payloadHash = hash('sha256', json_encode([$submission['id'] ?? '', $submission['data'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $prefix = (string)($submission['form'] ?? 'form') . ':' . (string)($submission['id'] ?? 'submission');
    $jobs = [];
    if (!empty($onSubmit['confirm_email'])) $jobs[] = ['key' => 'confirm', 'type' => 'smtp', 'payload_hash' => $payloadHash,
        'idempotency_key' => $prefix . ':confirm', 'target' => 'respondent-email', 'idempotent' => false];
    if (!empty($onSubmit['notify'])) $jobs[] = ['key' => 'notify', 'type' => 'smtp', 'payload_hash' => $payloadHash,
        'idempotency_key' => $prefix . ':notify', 'target' => 'owner-email', 'idempotent' => false];
    foreach ((array)($onSubmit['webhooks'] ?? []) as $index => $url) {
        $host = parse_url((string)$url, PHP_URL_HOST) ?: 'webhook';
        $jobs[] = ['key' => 'webhook:' . $index, 'type' => 'webhook', 'payload_hash' => $payloadHash,
            'idempotency_key' => $prefix . ':webhook:' . $index, 'target' => (string)$host, 'idempotent' => true];
    }
    foreach ((array)($onSubmit['actions'] ?? []) as $index => $action) {
        $jobs[] = ['key' => 'action:' . $index, 'type' => 'action', 'payload_hash' => $payloadHash,
            'idempotency_key' => $prefix . ':action:' . $index, 'target' => (string)($action['type'] ?? 'action'),
            'idempotent' => ($action['idempotent'] ?? false) === true];
    }
    return $jobs;
}
