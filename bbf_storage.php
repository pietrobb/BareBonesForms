<?php
/** Shared storage resolution and checked replacement primitives. No configuration is loaded here. */
defined('BBF_LOADED') || exit;

/**
 * Return the full config with the effective backend, preserving all connection/path settings.
 * A valid form override wins; invalid overrides are ignored (the historical submit rule).
 * Missing definitions fall back to the global backend so historical submissions remain readable.
 * An existing unreadable/invalid definition fails closed rather than selecting the wrong store.
 * This resolves configuration, not a historical backend migration or authorization decision.
 */
function bbf_effective_storage_config(array $config, string $formId, ?array $form = null): array {
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) {
        throw new InvalidArgumentException('Invalid storage form ID.');
    }
    if ($form === null) {
        $path = ($config['forms_dir'] ?? __DIR__ . '/forms') . '/' . $formId . '.json';
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            if ($raw === false) throw new RuntimeException('Cannot read storage form definition.');
            $form = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($form)) throw new RuntimeException('Invalid storage form definition.');
        }
    }
    $allowed = ['file', 'csv', 'sqlite', 'mysql'];
    $backend = $form['storage'] ?? null;
    if (!in_array($backend, $allowed, true)) $backend = $config['storage'] ?? 'file';
    // The old store() default used file for an unrecognized global backend.
    $config['storage'] = in_array($backend, $allowed, true) ? $backend : 'file';
    return $config;
}

/** Encode before opening ANY persistence target, including non-JSON backends. */
function bbf_storage_json($value, bool $pretty = false): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));
}

/** Apply and, where POSIX modes are meaningful, verify private access bits. */
function bbf_storage_private_mode(string $path, int $mode): bool {
    if (!@chmod($path, $mode)) return false;
    if (PHP_OS_FAMILY === 'Windows') return true;
    clearstatcache(true, $path);
    $actual = @fileperms($path);
    return $actual !== false && ($actual & 0777) === $mode;
}

/** A stable sidecar survives atomic rename. Never unlink it: waiters may hold its inode. */
function bbf_storage_locked(string $path, callable $operation): bool {
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return false;
    try {
        if (!flock($lock, LOCK_EX)) return false;
        return $operation() === true;
    } catch (Throwable $error) {
        error_log('BareBonesForms storage error: ' . $error->getMessage());
        return false;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Write a sibling temporary file, flush and close it, then publish by rename.
 * Caller holds the stable sidecar lock for read/modify/write operations.
 * On any pre-publication error the existing file is unchanged; no unlink fallback.
 * Atomic visibility, not a promise of directory-fsync/power-loss durability.
 */
function bbf_storage_replace(string $path, callable $write, ?int $createMode = null): bool {
    $temp = $path . '.tmp-' . bin2hex(random_bytes(12));
    $fp = null;
    try {
        $fp = @fopen($temp, 'xb');
        if (!$fp) return false;
        if ($write($fp) !== true || !fflush($fp)) return false;
        // Private callers set the temporary file's mode before atomic publication.
        if ($createMode !== null) {
            if (!bbf_storage_private_mode($temp, $createMode)) return false;
        } elseif (is_file($path)) {
            $mode = fileperms($path);
            if ($mode === false || !chmod($temp, $mode & 0777)) return false;
        }
        $closed = fclose($fp);
        $fp = null;
        if (!$closed) return false;
        // Windows readers without delete-sharing can briefly block publication. Retry only
        // this rename (100ms sleep budget), with the same temp and caller's lock still held.
        // Other platforms keep the single attempt; never unlink the destination or replay $write.
        for ($attempt = 0; ; ++$attempt) {
            if (@rename($temp, $path)) return true;
            if (PHP_OS_FAMILY !== 'Windows' || $attempt >= 10) return false;
            usleep(10000);
        }
    } catch (Throwable $error) {
        error_log('BareBonesForms atomic write error: ' . $error->getMessage());
        return false;
    } finally {
        if (is_resource($fp)) fclose($fp);
        if (is_file($temp)) @unlink($temp);
    }
}

function bbf_storage_write_all($fp, string $bytes): bool {
    $length = strlen($bytes);
    for ($offset = 0; $offset < $length; $offset += $written) {
        $written = fwrite($fp, substr($bytes, $offset));
        if ($written === false || $written === 0) return false;
    }
    return true;
}

/** Native CSV encoding, buffered one record at a time; check every destination byte. */
function bbf_storage_csv_record_syntax_valid($fp, int $start, int $end): bool {
    if ($end < $start || fseek($fp, $start) !== 0) return false;
    $raw = stream_get_contents($fp, $end - $start);
    if ($raw === false || strlen($raw) !== $end - $start || fseek($fp, $end) !== 0) return false;
    if (str_ends_with($raw, "\n")) $raw = substr($raw, 0, -1);
    if (str_ends_with($raw, "\r")) $raw = substr($raw, 0, -1);
    $length = strlen($raw);
    $offset = 0;
    while (true) {
        if ($offset === $length) return true;
        if ($raw[$offset] === '"') {
            $offset++;
            $closed = false;
            while ($offset < $length) {
                if ($raw[$offset] !== '"') {
                    $offset++;
                    continue;
                }
                if ($offset + 1 < $length && $raw[$offset + 1] === '"') {
                    $offset += 2;
                    continue;
                }
                $offset++;
                $closed = true;
                break;
            }
            if (!$closed || ($offset < $length && $raw[$offset] !== ',')) return false;
        } else {
            while ($offset < $length && $raw[$offset] !== ',') {
                if ($raw[$offset] === '"' || $raw[$offset] === "\r" || $raw[$offset] === "\n") return false;
                $offset++;
            }
        }
        if ($offset === $length) return true;
        $offset++;
    }
}

function bbf_storage_write_csv($fp, array $row): bool {
    $buffer = fopen('php://memory', 'w+b');
    if (!$buffer) return false;
    try {
        $length = fputcsv($buffer, $row, ',', '"', '');
        if ($length === false || $length === 0 || !rewind($buffer)) return false;
        $bytes = stream_get_contents($buffer);
        return $bytes !== false && strlen($bytes) === $length && bbf_storage_write_all($fp, $bytes);
    } finally {
        fclose($buffer);
    }
}

function bbf_storage_write_json(string $path, array $record): bool {
    try {
        $json = bbf_storage_json($record, true);
        return bbf_storage_locked($path, static fn() => bbf_storage_replace($path,
            static fn($fp) => bbf_storage_write_all($fp, $json)));
    } catch (Throwable $error) {
        error_log('BareBonesForms JSON write error: ' . $error->getMessage());
        return false;
    }
}

/** File payment metadata update: preserve the original on read/encode/write failure. */
function bbf_storage_update_payment_file(string $path, string $id, string $formId, array $payment): bool {
    return bbf_storage_locked($path, static function () use ($path, $id, $formId, $payment): bool {
        if (!is_file($path)) return false;
        $raw = file_get_contents($path);
        if ($raw === false) return false;
        $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['id'] ?? null) !== $id || ($record['form'] ?? null) !== $formId
            || !is_array($record['meta'] ?? null)) return false;
        $record['meta'] = array_replace($record['meta'], $payment);
        $json = bbf_storage_json($record, true);
        return bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json));
    });
}

/** Atomically apply a monotonic payment transition while holding the record's stable lock. */
function bbf_storage_transition_payment_file(string $path, string $id, string $formId, array $payment): array {
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return ['ok' => false, 'reason' => 'lock'];
    try {
        if (!flock($lock, LOCK_EX)) return ['ok' => false, 'reason' => 'lock'];
        if (!is_file($path)) return ['ok' => false, 'reason' => 'missing'];
        $raw = file_get_contents($path);
        if ($raw === false) return ['ok' => false, 'reason' => 'read'];
        $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['id'] ?? null) !== $id || ($record['form'] ?? null) !== $formId
            || !is_array($record['meta'] ?? null)) return ['ok' => false, 'reason' => 'record'];
        $transition = bbf_payment_merge_transition($record['meta'], $payment);
        if (!($transition['changed'] ?? false)) return ['ok' => true, 'status' => $transition['status'], 'changed' => false];
        $record['meta'] = $transition['meta'];
        $json = bbf_storage_json($record, true);
        if (!bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json))) {
            return ['ok' => false, 'reason' => 'persist'];
        }
        return ['ok' => true, 'status' => $transition['status'], 'changed' => true];
    } catch (Throwable $error) {
        error_log('BareBonesForms payment transition error: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'exception'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
