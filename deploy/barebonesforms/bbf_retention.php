<?php
/** Safe-off retention planning primitives. No configuration is loaded here. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_auth.php';
require_once __DIR__ . '/bbf_read.php';

function bbf_retention_policy(array $config): array {
    if (!array_key_exists('retention', $config)) {
        return ['enabled' => false, 'days' => 0, 'archive_dir' => '', 'batch_limit' => 100];
    }
    $value = $config['retention'];
    if (!is_array($value) || array_diff(array_keys($value), ['enabled', 'days', 'archive_dir', 'batch_limit'])
        || !is_bool($value['enabled'] ?? null)) {
        throw new InvalidArgumentException('Invalid retention configuration.');
    }
    $days = $value['days'] ?? 0;
    $archiveDir = $value['archive_dir'] ?? '';
    $batchLimit = $value['batch_limit'] ?? 100;
    if (!is_int($days) || $days < 0 || $days > 36500 || !is_string($archiveDir)
        || !is_int($batchLimit) || $batchLimit < 1 || $batchLimit > 100) {
        throw new InvalidArgumentException('Invalid retention configuration.');
    }
    if ($value['enabled'] && ($days < 1 || $archiveDir === '')) {
        throw new InvalidArgumentException('Enabled retention requires days and a private archive directory.');
    }
    return ['enabled' => $value['enabled'], 'days' => $days,
        'archive_dir' => $archiveDir, 'batch_limit' => $batchLimit];
}

function bbf_retention_timestamp($value): ?int {
    if (!is_string($value)
        || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](\d{2}):(\d{2}))\z/D', $value, $parts)
        || (isset($parts[1]) && ((int)$parts[1] > 14 || (int)$parts[2] > 59
            || ((int)$parts[1] === 14 && (int)$parts[2] !== 0)))) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $date->getTimestamp();
}

function bbf_retention_plan(array $config, string $formId, ?int $now = null): array {
    if (bbf_auth_id($formId) === '') throw new InvalidArgumentException('Invalid retention form ID.');
    $policy = bbf_retention_policy($config);
    $base = ['version' => 1, 'enabled' => $policy['enabled'], 'dry_run' => true,
        'form' => $formId, 'backend' => null, 'archive_dir' => null, 'as_of' => null, 'cutoff' => null, 'count' => 0,
        'ids' => [], 'confirmation' => null];
    if (!$policy['enabled']) return $base;

    $allowedBackends = ['file', 'csv', 'sqlite', 'mysql'];
    $globalBackend = $config['storage'] ?? 'file';
    if (!is_string($globalBackend) || !in_array($globalBackend, $allowedBackends, true)) {
        throw new InvalidArgumentException('Invalid retention storage backend.');
    }
    $formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
    $formPath = rtrim($formsDir, '/\\') . '/' . $formId . '.json';
    if (!bbf_auth_path_identity($formsDir, $formId . '.json', false) || !is_file($formPath)) {
        throw new RuntimeException('Retention form definition is unavailable.');
    }
    $raw = file_get_contents($formPath);
    $form = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($form) || ($form['id'] ?? null) !== $formId || !is_array($form['fields'] ?? null)) {
        throw new RuntimeException('Invalid retention form definition.');
    }
    if (array_key_exists('storage', $form)
        && (!is_string($form['storage']) || !in_array($form['storage'], $allowedBackends, true))) {
        throw new InvalidArgumentException('Invalid retention form storage backend.');
    }
    $effective = bbf_effective_storage_config($config, $formId, $form);
    if (!bbf_auth_form_identity($config, $formId)) {
        throw new RuntimeException('Retention storage identity is ambiguous.');
    }
    if ($effective['storage'] === 'mysql') {
        $pdo = bbf_read_db_connect($effective);
        if (!$pdo) throw new RuntimeException('Retention database is unavailable.');
        bbf_retention_mysql_primary_schema($pdo);
        $pdo = null;
    }
    $archiveDir = bbf_retention_archive_path($config, $policy);
    $now = $now ?? time();
    // A UTC-day anchor keeps the documented dry-run/apply digest stable across processes that day.
    $asOf = intdiv($now, 86400) * 86400;
    $cutoff = $asOf - ($policy['days'] * 86400);
    $ids = [];
    foreach (bbf_read_export($formId, $effective, PHP_INT_MAX, 0, null, null) as $submission) {
        $submitted = bbf_retention_timestamp($submission['meta']['submitted'] ?? null);
        if ($submitted === null || $submitted >= $cutoff) continue;
        $ids[$submission['id']] = $submission['id'];
        if (count($ids) > $policy['batch_limit']) {
            krsort($ids, SORT_STRING);
            unset($ids[array_key_first($ids)]);
        }
    }
    ksort($ids, SORT_STRING);
    $ids = array_values($ids);
    $payload = ['version' => 1, 'form' => $formId, 'backend' => $effective['storage'],
        'archive_dir' => $archiveDir, 'as_of' => gmdate('Y-m-d\TH:i:s\Z', $asOf),
        'cutoff' => gmdate('Y-m-d\TH:i:s\Z', $cutoff), 'ids' => $ids];
    return array_replace($base, [
        'backend' => $effective['storage'], 'archive_dir' => $archiveDir,
        'as_of' => $payload['as_of'], 'cutoff' => $payload['cutoff'],
        'count' => count($ids), 'ids' => $ids,
        'confirmation' => 'retention-' . hash('sha256', bbf_storage_json($payload)),
    ]);
}

function bbf_retention_path_within(string $path, string $root): bool {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}

function bbf_retention_archive_path(array $config, array $policy): string {
    $path = rtrim(str_replace('\\', '/', $policy['archive_dir']), '/');
    if (!preg_match('~\A(?:[A-Za-z]:/|/)~D', $path) || basename($path) === '.' || basename($path) === '..') {
        throw new RuntimeException('Retention archive directory must be an absolute path.');
    }
    $parent = dirname($path);
    $realParent = realpath($parent);
    if ($realParent === false || is_link($parent) || !is_dir($parent)
        || !bbf_retention_path_within($parent, $realParent) || !bbf_retention_path_within($realParent, $parent)) {
        throw new RuntimeException('Unsafe retention archive parent.');
    }
    foreach ([__DIR__, $config['forms_dir'] ?? __DIR__ . '/forms',
        $config['submissions_dir'] ?? __DIR__ . '/submissions', $config['logs_dir'] ?? __DIR__ . '/logs'] as $protected) {
        $resolved = realpath($protected) ?: $protected;
        if (bbf_retention_path_within($path, $resolved)) {
            throw new RuntimeException('Retention archives must be outside application data paths.');
        }
    }
    if (file_exists($path) || is_link($path)) {
        if (is_link($path) || !is_dir($path) || !bbf_storage_private_mode($path, 0700)) {
            throw new RuntimeException('Unsafe retention archive directory.');
        }
    }
    return $path;
}

function bbf_retention_archive_directory(array $config, array $policy): string {
    $path = bbf_retention_archive_path($config, $policy);
    if (!file_exists($path) && !@mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create retention archive directory.');
    }
    if (is_link($path) || !is_dir($path) || !bbf_storage_private_mode($path, 0700)) {
        throw new RuntimeException('Unsafe retention archive directory.');
    }
    return $path;
}

function bbf_retention_mysql_primary_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bbf_submissions'")->fetchColumn();
    if (strtoupper((string)$engine) !== 'INNODB') {
        throw new RuntimeException('Retention requires an InnoDB submissions table.');
    }
}

function bbf_retention_capture_records(array $config, string $formId, array $ids): array {
    $wanted = array_fill_keys($ids, true);
    $records = [];
    if ($config['storage'] === 'file') {
        $dir = rtrim($config['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/' . $formId;
        foreach ($ids as $id) {
            $record = bbf_read_file($dir . '/' . $id . '.json', $formId);
            if ($record !== null) $records[$id] = $record;
        }
    } elseif ($config['storage'] === 'csv') {
        foreach (bbf_read_csv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions') as $record) {
            if (isset($wanted[$record['id']])) $records[$record['id']] = $record;
            if (count($records) === count($wanted)) break;
        }
    } else {
        $pdo = bbf_read_db_connect($config);
        if (!$pdo) throw new RuntimeException('Retention database is unavailable.');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT id, form_id, data, meta FROM bbf_submissions WHERE '
            . bbf_auth_form_sql($pdo) . " AND id IN ($placeholders)");
        $stmt->execute(array_merge([$formId], $ids));
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $record = bbf_read_db_row($row);
            if ($record !== null && isset($wanted[$record['id']])) $records[$record['id']] = $record;
        }
    }
    ksort($records, SORT_STRING);
    if (array_keys($records) !== $ids) throw new RuntimeException('Retention candidates changed before archive.');
    return $records;
}

function bbf_retention_archive_write(array $config, array $policy, array $plan, array $records,
    array $reviews, array $deliverySnapshots): string {
    $delivery = [];
    foreach ($plan['ids'] as $id) {
        $base = bbf_outbox_existing_path($config, $plan['form'], $id);
        foreach (['ledger' => $base, 'events' => $base . '.events'] as $name => $path) {
            if (isset($deliverySnapshots[$path]) && $deliverySnapshots[$path]['exists']) {
                $delivery[$id][$name] = base64_encode($deliverySnapshots[$path]['bytes']);
            }
        }
    }
    $payload = ['version' => 1, 'kind' => 'retention', 'created_at' => $plan['as_of'],
        'plan' => $plan, 'records' => $records, 'reviews' => $reviews, 'delivery' => $delivery];
    $payloadJson = bbf_storage_json($payload);
    $document = ['version' => 1, 'sha256' => hash('sha256', $payloadJson), 'payload' => $payload];
    $bytes = bbf_storage_json($document, true);
    $dir = bbf_retention_archive_directory($config, $policy);
    $path = $dir . '/' . $plan['form'] . '-' . substr($plan['confirmation'], -16) . '.json';
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Unsafe retention archive path.');
    if (is_file($path)) {
        $existing = file_get_contents($path);
        if ($existing === $bytes) return $path;
        throw new RuntimeException('Retention archive name collision.');
    }
    if (!bbf_storage_locked($path, static fn() => bbf_storage_replace($path,
        static fn($fp) => bbf_storage_write_all($fp, $bytes), 0600))) {
        throw new RuntimeException('Cannot persist retention archive.');
    }
    return $path;
}

function bbf_retention_delete_file(string $path, string $formId, string $id, array $expected): array {
    $deleted = false;
    $ok = bbf_storage_locked($path, static function () use ($path, $formId, $id, $expected, &$deleted): bool {
        if (!is_file($path) || is_link($path)) return false;
        $raw = file_get_contents($path);
        if ($raw === false) return false;
        $record = json_decode($raw, true);
        if (!is_array($record) || ($record['id'] ?? null) !== $id || ($record['form'] ?? null) !== $formId
            || $record !== $expected) return false;
        $deleted = @unlink($path);
        return $deleted;
    });
    return ['ok' => $ok, 'deleted' => $ok && $deleted];
}

function bbf_retention_delete_csv(array $config, string $formId, array $expected): array {
    $path = rtrim($config['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/' . $formId . '.csv';
    $deleted = [];
    $ok = bbf_storage_locked($path, static function () use ($path, $formId, $expected, &$deleted): bool {
        if (!is_file($path) || is_link($path)) return false;
        $found = [];
        $written = bbf_storage_replace($path, static function ($out) use ($path, $formId, $expected, &$found): bool {
            $in = @fopen($path, 'rb');
            if (!$in) return false;
            try {
                $headers = fgetcsv($in, 0, ',', '"', '');
                if (!is_array($headers) || !in_array('_id', $headers, true) || count(array_unique($headers)) !== count($headers)
                    || !bbf_storage_write_csv($out, $headers)) return false;
                while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
                    $record = bbf_read_csv_record($headers, $row, $formId);
                    $id = $record['id'] ?? null;
                    if (is_string($id) && array_key_exists($id, $expected)) {
                        if ($record !== $expected[$id]) return false;
                        $found[$id] = true;
                    } elseif (!bbf_storage_write_csv($out, $row)) return false;
                }
                return feof($in) && count($found) === count($expected);
            } finally { fclose($in); }
        });
        if (!$written) return false;
        $deleted = $found;
        return true;
    });
    return ['ok' => $ok, 'deleted_ids' => $ok ? $deleted : []];
}

function bbf_retention_delete_db(PDO $pdo, string $formId, string $id, array $expected): array {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $stmt = $pdo->prepare('SELECT id, form_id, data, meta FROM bbf_submissions WHERE '
        . bbf_auth_form_sql($pdo) . ' AND id = ?' . ($mysql ? ' FOR UPDATE' : ''));
    $stmt->execute([$formId, $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $record = $row ? bbf_read_db_row($row) : null;
    if ($record !== $expected) return ['ok' => false, 'deleted' => false];
    $delete = $pdo->prepare('DELETE FROM bbf_submissions WHERE ' . bbf_auth_form_sql($pdo) . ' AND id = ?');
    $delete->execute([$formId, $id]);
    return ['ok' => $delete->rowCount() === 1, 'deleted' => $delete->rowCount() === 1];
}

function bbf_retention_audit_begin(array $config, array $principal, string $formId, array $ids): void {
    bbf_audit_write($config, $principal, 'retention_apply', $formId, $ids, 'allowed', 'attempted', 0);
    $GLOBALS['bbf_retention_audit_operation'] = compact('config', 'principal', 'formId', 'ids');
    if (empty($GLOBALS['bbf_retention_audit_shutdown_registered'])) {
        $GLOBALS['bbf_retention_audit_shutdown_registered'] = true;
        register_shutdown_function(static function (): void {
            if (isset($GLOBALS['bbf_retention_audit_operation'])) bbf_retention_audit_finish(0, false);
        });
    }
}

function bbf_retention_audit_finish(int $count, bool $success): void {
    $operation = $GLOBALS['bbf_retention_audit_operation'] ?? null;
    if (!is_array($operation)) return;
    unset($GLOBALS['bbf_retention_audit_operation']);
    bbf_audit_write($operation['config'], $operation['principal'], 'retention_apply',
        $operation['formId'], $operation['ids'], 'allowed', $success ? 'completed' : 'failed', $count);
}

function bbf_retention_apply(array $config, string $formId, string $confirmation, ?int $now = null): array {
    require_once __DIR__ . '/bbf_outbox.php';
    require_once __DIR__ . '/bbf_review.php';
    $policy = bbf_retention_policy($config);
    if (!$policy['enabled']) return ['ok' => false, 'reason' => 'disabled'];
    if (!preg_match('/\Aretention-[a-f0-9]{64}\z/D', $confirmation)) return ['ok' => false, 'reason' => 'confirmation'];
    $now = $now ?? time();
    $preflight = bbf_retention_plan($config, $formId, $now);
    if (!is_string($preflight['confirmation']) || !hash_equals($preflight['confirmation'], $confirmation)) {
        return ['ok' => false, 'reason' => 'confirmation', 'plan' => $preflight];
    }
    $archiveDir = bbf_retention_archive_directory($config, $policy);
    $lockPath = $archiveDir . '/.' . $formId . '.retention';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !bbf_storage_private_mode($lockPath, 0600)) {
        if (is_resource($lock)) fclose($lock);
        return ['ok' => false, 'reason' => 'lock'];
    }
    $principal = ['id' => 'retention-cli'];
    try {
        if (!flock($lock, LOCK_EX)) return ['ok' => false, 'reason' => 'lock'];
        $plan = bbf_retention_plan($config, $formId, $now);
        if (!is_string($plan['confirmation']) || !hash_equals($plan['confirmation'], $confirmation)) {
            return ['ok' => false, 'reason' => 'confirmation', 'plan' => $plan];
        }
        bbf_retention_audit_begin($config, $principal, $formId, $plan['ids']);
        if ($plan['ids'] === []) {
            bbf_retention_audit_finish(0, true);
            return ['ok' => true, 'deleted' => 0, 'archive' => null];
        }
        $effective = bbf_effective_storage_config($config, $formId);
        if (($effective['storage'] ?? null) !== $plan['backend']) {
            throw new RuntimeException('Retention backend changed after confirmation.');
        }
        $records = bbf_retention_capture_records($effective, $formId, $plan['ids']);
        $activeReviews = bbf_review_records($config, $formId, $plan['ids']);
        $reviews = [];
        foreach ($plan['ids'] as $id) $reviews[$id] = $activeReviews[$id] ?? null;
        $deletions = [];
        if ($effective['storage'] === 'file') {
            $dir = rtrim($effective['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/' . $formId;
            foreach ($records as $id => $record) {
                $deletions[] = ['path' => bbf_outbox_existing_path($config, $formId, $id),
                    'delete' => static function () use ($config, $formId, $id, $reviews, $dir, $record): array {
                        $coordinated = bbf_review_delete_records_if($config, $formId, [$id => $reviews[$id]],
                            static fn() => bbf_retention_delete_file($dir . '/' . $id . '.json', $formId, $id, $record));
                        return $coordinated['primary'] ?? ['ok' => false, 'deleted' => false,
                            'reason' => $coordinated['reason'] ?? 'review'];
                    }];
            }
        } elseif ($effective['storage'] === 'csv') {
            $ran = false; $csvResult = null;
            foreach ($records as $id => $record) {
                $deletions[] = ['path' => bbf_outbox_existing_path($config, $formId, $id),
                    'delete' => static function () use (&$ran, &$csvResult, $config, $formId, $reviews,
                        $effective, $records, $id): array {
                        if (!$ran) {
                            $ran = true;
                            $coordinated = bbf_review_delete_records_if($config, $formId, $reviews,
                                static fn() => bbf_retention_delete_csv($effective, $formId, $records));
                            $csvResult = $coordinated['primary'] ?? ['ok' => false,
                                'reason' => $coordinated['reason'] ?? 'review'];
                        }
                        return ['ok' => ($csvResult['ok'] ?? false) === true,
                            'deleted' => isset($csvResult['deleted_ids'][$id]),
                            'reason' => $csvResult['reason'] ?? null];
                    }];
            }
        } else {
            foreach ($records as $id => $record) {
                $deletions[] = ['path' => bbf_outbox_existing_path($config, $formId, $id),
                    'delete' => static function () use ($config, $formId, $id, $reviews, $record): array {
                        $coordinated = bbf_review_delete_records_if($config, $formId, [$id => $reviews[$id]],
                            static fn(PDO $pdo) => bbf_retention_delete_db($pdo, $formId, $id, $record));
                        return $coordinated['primary'] ?? ['ok' => false, 'deleted' => false,
                            'reason' => $coordinated['reason'] ?? 'review'];
                    }];
            }
        }
        $archive = null;
        $result = bbf_outbox_delete_submissions($deletions,
            static function (array $snapshots) use ($config, $policy, $plan, $records, $reviews, &$archive): bool {
                $archive = bbf_retention_archive_write($config, $policy, $plan, $records, $reviews, $snapshots);
                return true;
            });
        if (!($result['ok'] ?? false) || ($result['deleted'] ?? 0) !== count($records)) {
            bbf_retention_audit_finish((int)($result['deleted'] ?? 0), false);
            return ['ok' => false, 'reason' => $result['reason'] ?? 'delete',
                'deleted' => (int)($result['deleted'] ?? 0), 'archive' => $archive];
        }
        bbf_retention_audit_finish(count($records), true);
        return ['ok' => true, 'deleted' => count($records), 'archive' => $archive];
    } catch (Throwable $error) {
        error_log('BareBonesForms retention: ' . $error->getMessage());
        if (isset($GLOBALS['bbf_retention_audit_operation'])) bbf_retention_audit_finish(0, false);
        return ['ok' => false, 'reason' => 'storage'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
