<?php
/** Opt-in respondent draft storage. Bearer handles are never persisted in plaintext. */
defined('BBF_LOADED') || exit;

require_once __DIR__ . '/bbf_storage.php';

function bbf_draft_policy(array $form): ?array {
    $drafts = $form['drafts'] ?? null;
    if (!is_array($drafts) || ($drafts['enabled'] ?? false) !== true) return null;
    return [
        'ttl_seconds' => (int)($drafts['ttl_seconds'] ?? 604800),
        'fields' => array_values($drafts['fields'] ?? []),
    ];
}

function bbf_draft_handle(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function bbf_draft_valid_handle(string $handle): bool {
    return preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $handle) === 1;
}

function bbf_draft_dir(array $config): string {
    return $config['drafts_dir'] ?? (($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/drafts');
}

function bbf_draft_path(array $config, string $handle): string {
    if (!bbf_draft_valid_handle($handle)) throw new InvalidArgumentException('Invalid draft handle.');
    return bbf_draft_dir($config) . '/' . hash('sha256', $handle) . '.json';
}

function bbf_draft_prepare_dir(array $config): bool {
    $dir = bbf_draft_dir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    return bbf_storage_private_mode($dir, 0700);
}

/** Fixed 16-shard lock pool bounds sidecars while serializing every operation for one payload. */
function bbf_draft_locked(array $config, string $path, callable $operation): bool {
    $name = basename($path, '.json');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $name)) return false;
    return bbf_storage_locked(bbf_draft_dir($config) . '/.lock-' . $name[0], $operation);
}

/** Return only explicitly allowlisted ordinary fields; controls and sensitive classes fail closed. */
function bbf_draft_filter(array $form, array $flatFields, array $input): array {
    $policy = bbf_draft_policy($form);
    if ($policy === null) return [];
    $allow = array_fill_keys($policy['fields'], true);
    $declared = [];
    foreach ($flatFields as $field) {
        if (is_array($field) && is_string($field['name'] ?? null)) $declared[$field['name']] = true;
    }
    $data = [];
    foreach ($flatFields as $field) {
        if (!is_array($field) || !is_string($field['name'] ?? null)) continue;
        $name = $field['name'];
        $type = $field['type'] ?? 'text';
        if (!isset($allow[$name]) || !array_key_exists($name, $input)
            || in_array($type, ['password', 'hidden', 'section', 'page_break', 'group'], true)
            || !empty($field['sensitive'])) continue;
        $value = $input[$name];
        if (is_string($value)) $value = trim($value);
        if (is_scalar($value) || $value === null) {
            $data[$name] = $value;
        } elseif ($type === 'checkbox' && is_array($value) && array_is_list($value)) {
            $safe = [];
            foreach ($value as $item) {
                if (!is_scalar($item) && $item !== null) { $safe = []; break; }
                $safe[] = $item;
            }
            if ($safe !== [] || $value === []) $data[$name] = $safe;
        }
        if (!array_key_exists($name, $data) || empty($field['other'])) continue;
        $selected = is_array($data[$name])
            ? in_array('__other__', array_map('strval', $data[$name]), true)
            : (string)$data[$name] === '__other__';
        $otherName = $name . '_other';
        if (!$selected || isset($declared[$otherName]) || !array_key_exists($otherName, $input)) continue;
        $otherValue = $input[$otherName];
        if (is_string($otherValue)) $otherValue = trim($otherValue);
        if (is_scalar($otherValue) || $otherValue === null) $data[$otherName] = $otherValue;
    }
    return $data;
}

function bbf_draft_read_record(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Cannot read draft.');
    $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record) || !is_string($record['form'] ?? null) || !is_array($record['data'] ?? null)
        || !is_int($record['created_at'] ?? null) || !is_int($record['updated_at'] ?? null)
        || !is_int($record['expires_at'] ?? null)) throw new RuntimeException('Invalid draft record.');
    return $record;
}

function bbf_draft_queue_path(array $config): string {
    return bbf_draft_dir($config) . '/.cleanup-queue';
}

function bbf_draft_queue_epoch(array $config): int {
    $path = bbf_draft_dir($config) . '/.cleanup-epoch';
    $raw = is_file($path) ? file_get_contents($path) : false;
    return is_string($raw) && preg_match('/\A[0-9]+\z/D', $raw) ? (int)$raw : 0;
}

/** Caller holds the cleanup-queue lock. */
function bbf_draft_queue_append_locked(array $config, string $path, int $epoch): bool {
    $hash = basename($path, '.json');
    if ($epoch < 0 || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) return false;
    $queue = bbf_draft_queue_path($config);
    $fp = @fopen($queue, 'ab');
    if (!$fp) return false;
    $ok = false;
    try {
        if (!bbf_storage_private_mode($queue, 0600)) return false;
        $ok = bbf_storage_write_all($fp, $epoch . ' ' . $hash . "\n") && fflush($fp);
    } finally {
        $closed = fclose($fp);
    }
    return $ok && $closed;
}

function bbf_draft_save(array $config, array $form, array $flatFields, array $input, string $handle = '', ?int $now = null): array {
    $policy = bbf_draft_policy($form);
    if ($policy === null) return ['ok' => false, 'reason' => 'disabled'];
    $now ??= time();
    $new = $handle === '';
    if ($new) $handle = bbf_draft_handle();
    if (!bbf_draft_valid_handle($handle)) return ['ok' => false, 'reason' => 'not_found'];
    if (!bbf_draft_prepare_dir($config)) return ['ok' => false, 'reason' => 'storage'];
    $path = bbf_draft_path($config, $handle);
    if (!$new && !is_file($path)) return ['ok' => false, 'reason' => 'not_found'];
    $result = ['ok' => false, 'reason' => 'storage'];
    $locked = bbf_storage_locked(bbf_draft_queue_path($config), static function () use (&$result, $path, $new, $config, $form, $flatFields, $input, $handle, $now, $policy): bool {
        return bbf_draft_locked($config, $path, static function () use (&$result, $path, $new, $config, $form, $flatFields, $input, $handle, $now, $policy): bool {
            $existing = bbf_draft_read_record($path);
            if (!$new && ($existing === null || !hash_equals((string)$form['id'], (string)$existing['form']))) {
                $result = ['ok' => false, 'reason' => 'not_found'];
                return true;
            }
            if ($existing !== null && $existing['expires_at'] <= $now) {
                @unlink($path);
                $result = ['ok' => false, 'reason' => 'expired'];
                return true;
            }
            $record = [
                'form' => (string)$form['id'],
                'created_at' => $existing['created_at'] ?? $now,
                'updated_at' => $now,
                'expires_at' => $now + $policy['ttl_seconds'],
                'queue_epoch' => $existing['queue_epoch'] ?? bbf_draft_queue_epoch($config),
                'data' => bbf_draft_filter($form, $flatFields, $input),
            ];
            $json = bbf_storage_json($record, true);
            if (($new && !bbf_draft_queue_append_locked($config, $path, $record['queue_epoch']))
                || !bbf_storage_replace($path, static fn($fp): bool => bbf_storage_write_all($fp, $json), 0600)) return false;
            $result = ['ok' => true, 'handle' => $handle, 'expires_at' => gmdate('c', $record['expires_at']), 'data' => $record['data']];
            return true;
        });
    });
    return $locked ? $result : ['ok' => false, 'reason' => 'storage'];
}

function bbf_draft_load(array $config, array $form, string $handle, ?int $now = null): array {
    if (bbf_draft_policy($form) === null) return ['ok' => false, 'reason' => 'disabled'];
    if (!bbf_draft_valid_handle($handle)) return ['ok' => false, 'reason' => 'not_found'];
    $now ??= time();
    $path = bbf_draft_path($config, $handle);
    if (!is_file($path)) return ['ok' => false, 'reason' => 'not_found'];
    $result = ['ok' => false, 'reason' => 'not_found'];
    $locked = bbf_draft_locked($config, $path, static function () use (&$result, $path, $form, $now): bool {
        $record = bbf_draft_read_record($path);
        if ($record === null || !hash_equals((string)$form['id'], (string)$record['form'])) return true;
        if ($record['expires_at'] <= $now) {
            @unlink($path);
            $result = ['ok' => false, 'reason' => 'expired'];
            return true;
        }
        $flatFields = flattenFields($form['fields'] ?? []);
        $result = ['ok' => true, 'expires_at' => gmdate('c', $record['expires_at']),
            'data' => bbf_draft_filter($form, $flatFields, $record['data'])];
        return true;
    });
    return $locked ? $result : ['ok' => false, 'reason' => 'storage'];
}

function bbf_draft_delete(array $config, array $form, string $handle): array {
    if (bbf_draft_policy($form) === null) return ['ok' => false, 'reason' => 'disabled'];
    if (!bbf_draft_valid_handle($handle)) return ['ok' => false, 'reason' => 'not_found'];
    $path = bbf_draft_path($config, $handle);
    if (!is_file($path)) return ['ok' => false, 'reason' => 'not_found'];
    $result = ['ok' => false, 'reason' => 'not_found'];
    $locked = bbf_draft_locked($config, $path, static function () use (&$result, $path, $form): bool {
        $record = bbf_draft_read_record($path);
        if ($record === null || !hash_equals((string)$form['id'], (string)$record['form'])) return true;
        if (!@unlink($path) && is_file($path)) return false;
        $result = ['ok' => true];
        return true;
    });
    return $locked ? $result : ['ok' => false, 'reason' => 'storage'];
}

/** Process at most $limit indexed records using an O(1) byte cursor; never enumerate the draft directory. */
function bbf_draft_cleanup(array $config, ?int $now = null, int $limit = 100): int {
    $now ??= time();
    $limit = max(0, $limit);
    if ($limit === 0 || !bbf_draft_prepare_dir($config)) return 0;
    $removed = 0;
    $queuePath = bbf_draft_queue_path($config);
    $workPath = bbf_draft_dir($config) . '/.cleanup-work';
    $completed = bbf_storage_locked($queuePath, static function () use ($config, $queuePath, $workPath, $now, $limit, &$removed): bool {
        $cursorPath = bbf_draft_dir($config) . '/.cleanup-cursor';
        $epochPath = bbf_draft_dir($config) . '/.cleanup-epoch';
        if (!is_file($workPath)) {
            if (!is_file($queuePath)) return true;
            $nextEpoch = bbf_draft_queue_epoch($config) + 1;
            if ($nextEpoch <= 0 || !bbf_storage_private_mode($queuePath, 0600)
                || !bbf_storage_replace($cursorPath, static fn($out): bool => bbf_storage_write_all($out, '0'), 0600)
                || !bbf_storage_replace($epochPath, static fn($out): bool => bbf_storage_write_all($out, (string)$nextEpoch), 0600)
                || !@rename($queuePath, $workPath) || !bbf_storage_private_mode($workPath, 0600)) return false;
        }
        $activeEpoch = bbf_draft_queue_epoch($config);
        $fp = @fopen($workPath, 'rb');
        if (!$fp || !bbf_storage_private_mode($workPath, 0600)) { if ($fp) fclose($fp); return false; }
        $stat = fstat($fp);
        $size = is_array($stat) ? (int)$stat['size'] : 0;
        $cursorRaw = is_file($cursorPath) ? file_get_contents($cursorPath) : false;
        $cursor = is_string($cursorRaw) && preg_match('/\A[0-9]+\z/D', $cursorRaw) ? (int)$cursorRaw : 0;
        if ($cursor < 0 || $cursor > $size || fseek($fp, $cursor) !== 0) { fclose($fp); return false; }
        for ($examined = 0; $examined < $limit && $cursor < $size; $examined++) {
            $line = fgets($fp, 128);
            if ($line === false) break;
            $position = ftell($fp);
            if ($position === false || $position <= $cursor) { fclose($fp); return false; }
            $cursor = $position;
            if (!preg_match('/\A([0-9]{1,20}) ([a-f0-9]{64})\n?\z/D', $line, $match)) continue;
            $path = bbf_draft_dir($config) . '/' . $match[2] . '.json';
            $handled = bbf_draft_locked($config, $path, static function () use ($config, $path, $now, $activeEpoch, &$removed): bool {
                try { $record = bbf_draft_read_record($path); } catch (Throwable $error) { return false; }
                if ($record === null) return true;
                if ($record['expires_at'] <= $now) {
                    if (!@unlink($path) && is_file($path)) return false;
                    $removed++;
                    return true;
                }
                if (($record['queue_epoch'] ?? -1) >= $activeEpoch) return true;
                if (!bbf_draft_queue_append_locked($config, $path, $activeEpoch)) return false;
                $record['queue_epoch'] = $activeEpoch;
                $json = bbf_storage_json($record, true);
                return bbf_storage_replace($path, static fn($out): bool => bbf_storage_write_all($out, $json), 0600);
            });
            if (!$handled) { fclose($fp); return false; }
        }
        if (!fclose($fp)) return false;
        if ($cursor >= $size) {
            if (!@unlink($workPath) && is_file($workPath)) return false;
            $cursor = 0;
        }
        return bbf_storage_replace($cursorPath, static fn($out): bool => bbf_storage_write_all($out, (string)$cursor), 0600);
    });
    return $completed ? $removed : 0;
}
