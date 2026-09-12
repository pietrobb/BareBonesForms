<?php
/** Protected form-scoped logical backup and explicit restore primitives. No configuration is loaded here. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_retention.php';
require_once __DIR__ . '/bbf_review.php';
require_once __DIR__ . '/bbf_versions.php';
require_once __DIR__ . '/bbf_outbox.php';

function bbf_backup_policy(array $config): array {
    $value = $config['backup'] ?? null;
    if (!is_array($value) || array_keys($value) !== ['directory']
        || !is_string($value['directory']) || $value['directory'] === '') {
        throw new InvalidArgumentException('Invalid backup configuration.');
    }
    return $value;
}

function bbf_backup_directory(array $config, bool $create): string {
    $policy = bbf_backup_policy($config);
    $path = bbf_retention_archive_path($config, ['archive_dir' => $policy['directory']]);
    if (!file_exists($path) && $create && !@mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    if (($create || file_exists($path))
        && (is_link($path) || !is_dir($path) || !bbf_storage_private_mode($path, 0700))) {
        throw new RuntimeException('Unsafe backup directory.');
    }
    return $path;
}

/** Return only authorization boundaries. Credential values and their hashes never enter a bundle. */
function bbf_backup_access_policy(array $config, string $formId): array {
    $registry = bbf_auth_registry($config);
    $legacy = $config['api_token'] ?? '';
    $records = $config['access_tokens'] ?? [];
    $expected = ($legacy === '' ? 0 : 1) + (is_array($records) ? count($records) : 0);
    if (!is_string($legacy) || !is_array($records) || count($registry) !== $expected) {
        throw new RuntimeException('Invalid access policy cannot be backed up or restored.');
    }
    $principals = [];
    foreach ($records as $record) {
        if (!in_array($formId, $record['forms'], true)) continue;
        $permissions = $record['permissions'];
        sort($permissions, SORT_STRING);
        $principals[] = ['id' => $record['id'], 'permissions' => $permissions,
            'expires_at' => $record['expires_at'], 'revoked' => $record['revoked']];
    }
    usort($principals, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return ['legacy_admin' => $legacy !== '', 'principals' => $principals];
}

function bbf_backup_audit_capture(array $config, string $formId): array {
    $path = rtrim($config['logs_dir'] ?? __DIR__ . '/logs', '/\\') . '/access-audit.php';
    if (!is_file($path)) return [];
    if (is_link($path)) throw new RuntimeException('Unsafe audit log.');
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || array_shift($lines) !== '<?php http_response_code(404); exit; ?>') {
        throw new RuntimeException('Invalid audit log.');
    }
    $entries = [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($entry) || !is_string($entry['form'] ?? null)) throw new RuntimeException('Invalid audit entry.');
        if ($entry['form'] === $formId) $entries[] = $entry;
    }
    return $entries;
}

function bbf_backup_version_capture(array $config, string $formId): array {
    return bbf_version_with_state($config, $formId, static function (array $paths, array $state): array {
        $versions = [$state['published_version'] => true, $state['draft_version'] => true];
        foreach ($state['history'] as $event) {
            if (!is_array($event) || !is_string($event['version'] ?? null)) {
                throw new RuntimeException('Invalid version history.');
            }
            $versions[$event['version']] = true;
        }
        $blobs = [];
        foreach (array_keys($versions) as $version) $blobs[$version] = bbf_version_read_blob($paths, $version);
        ksort($blobs, SORT_STRING);
        return ['active' => bbf_version_read_active($paths), 'state' => $state, 'blobs' => $blobs];
    });
}

function bbf_backup_review_capture(array $config, string $formId): array {
    $effective = bbf_effective_storage_config($config, $formId);
    if (in_array($effective['storage'], ['file', 'csv'], true)) {
        return bbf_review_file_transaction($effective, $formId, false,
            static fn(array $document): array => $document);
    }
    $pdo = bbf_review_db($effective);
    $document = bbf_review_file_document(null);
    $records = $pdo->prepare('SELECT * FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
        . ' ORDER BY submission_id');
    $records->execute([$formId]);
    while (($row = $records->fetch(PDO::FETCH_ASSOC)) !== false) {
        $id = (string)$row['submission_id'];
        if ((int)$row['deleted'] === 1) $document['deleted'][$id] = true;
        else $document['records'][$id] = bbf_review_row($row);
    }
    $filters = $pdo->prepare('SELECT * FROM bbf_review_filter WHERE ' . bbf_auth_form_sql($pdo)
        . ' ORDER BY principal_id, filter_id');
    $filters->execute([$formId]);
    while (($row = $filters->fetch(PDO::FETCH_ASSOC)) !== false) {
        $filter = bbf_review_filter_row($row);
        $id = $filter['id']; unset($filter['id']);
        $document['filters'][(string)$row['principal_id']][$id] = $filter;
    }
    return bbf_review_file_document($document);
}

function bbf_backup_delivery_capture(array $config, string $formId, array $records): array {
    $delivery = [];
    foreach (array_keys($records) as $submissionId) {
        $path = bbf_outbox_existing_path($config, $formId, $submissionId);
        if ($path === '') throw new RuntimeException('Invalid backup delivery relationship.');
        foreach (['ledger' => '', 'events' => '.events'] as $kind => $suffix) {
            $ledgerPath = $path . $suffix;
            if (!file_exists($ledgerPath)) continue;
            if (is_link($ledgerPath) || !is_file($ledgerPath)) throw new RuntimeException('Unsafe delivery ledger.');
            $result = bbf_outbox_read($ledgerPath);
            $ledger = $result['ledger'] ?? null;
            $expectedKey = $formId . ':' . $submissionId . ($kind === 'events' ? ':events' : '');
            if (!($result['ok'] ?? false) || !is_array($ledger) || ($ledger['version'] ?? null) !== 1
                || ($ledger['submission_key'] ?? null) !== $expectedKey || !is_array($ledger['jobs'] ?? null)
                || ($ledger['deleted'] ?? false) === true) {
                throw new RuntimeException('Invalid backup delivery relationship.');
            }
            foreach ($ledger['jobs'] as $job) {
                if (!is_array($job) || ($job['state'] ?? null) === 'running') {
                    throw new RuntimeException('Running or invalid delivery cannot be backed up safely.');
                }
            }
            $delivery[$submissionId][$kind] = $ledger;
        }
    }
    ksort($delivery, SORT_STRING);
    return $delivery;
}

function bbf_backup_bundle_read(array $config, string $path): array {
    $directory = bbf_backup_directory($config, false);
    $realDirectory = realpath($directory);
    $realPath = realpath($path);
    if ($realDirectory === false || $realPath === false || is_link($path) || !is_file($path)
        || !bbf_retention_path_within($realPath, $realDirectory)) {
        throw new RuntimeException('Unsafe backup bundle path.');
    }
    $bytes = file_get_contents($realPath);
    if ($bytes === false) throw new RuntimeException('Cannot read backup bundle.');
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($document) || ($document['version'] ?? null) !== 1
        || !is_string($document['sha256'] ?? null) || !is_array($document['payload'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $document['sha256'])
        || !hash_equals($document['sha256'], hash('sha256', bbf_storage_json($document['payload'])))) {
        throw new RuntimeException('Backup bundle integrity failed.');
    }
    $payload = $document['payload'];
    if (($payload['version'] ?? null) !== 1 || ($payload['kind'] ?? null) !== 'logical-backup'
        || bbf_auth_id($payload['form'] ?? null) === '' || !is_array($payload['records'] ?? null)
        || !is_array($payload['review'] ?? null) || !is_array($payload['versions'] ?? null)
        || !is_array($payload['audit'] ?? null) || !is_array($payload['access'] ?? null)
        || !is_array($payload['delivery'] ?? null)) {
        throw new RuntimeException('Invalid backup bundle payload.');
    }
    bbf_backup_record_order($payload);
    return $document;
}

function bbf_backup_record_order(array $payload): array {
    $records = $payload['records'] ?? [];
    $recordIds = array_map(static fn($id): string => (string)$id, array_keys($records));
    if (array_key_exists('record_order', $payload)) {
        $order = $payload['record_order'];
        if (!is_array($order) || !array_is_list($order)
            || count(array_filter($order, 'is_string')) !== count($order)
            || count(array_unique($order)) !== count($order)) {
            throw new RuntimeException('Invalid backup record order.');
        }
        $sortedOrder = $order;
        $sortedIds = $recordIds;
        sort($sortedOrder, SORT_STRING);
        sort($sortedIds, SORT_STRING);
        if ($sortedOrder !== $sortedIds) throw new RuntimeException('Invalid backup record order.');
        return $order;
    }
    $ordered = array_values($records);
    usort($ordered, static function (array $left, array $right): int {
        $leftTime = bbf_retention_timestamp($left['meta']['submitted'] ?? null) ?? PHP_INT_MIN;
        $rightTime = bbf_retention_timestamp($right['meta']['submitted'] ?? null) ?? PHP_INT_MIN;
        return ($rightTime <=> $leftTime) ?: strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
    });
    return array_map(static fn(array $record): string => (string)$record['id'], $ordered);
}

function bbf_backup_create(array $config, string $formId, ?int $now = null): array {
    if (bbf_auth_id($formId) === '' || !bbf_auth_form_identity($config, $formId)) {
        throw new RuntimeException('Backup form scope is unavailable or ambiguous.');
    }
    $versions = bbf_backup_version_capture($config, $formId);
    $effective = bbf_effective_storage_config($config, $formId, $versions['active']);
    $records = [];
    $recordOrder = [];
    foreach (bbf_read_export($formId, $effective, PHP_INT_MAX, 0, null, null, null, true) as $record) {
        if (!is_string($record['id'] ?? null) || isset($records[$record['id']])) {
            throw new RuntimeException('Invalid or duplicate backup submission.');
        }
        $records[$record['id']] = $record;
        $recordOrder[] = $record['id'];
    }
    ksort($records, SORT_STRING);
    $payload = ['version' => 1, 'kind' => 'logical-backup',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()), 'form' => $formId,
        'source_backend' => $effective['storage'], 'access' => bbf_backup_access_policy($config, $formId),
        'definition' => $versions['active'], 'versions' => $versions, 'records' => $records,
        'record_order' => $recordOrder, 'review' => bbf_backup_review_capture($config, $formId),
        'delivery' => bbf_backup_delivery_capture($config, $formId, $records),
        'audit' => bbf_backup_audit_capture($config, $formId)];
    $verifiedRecords = [];
    $verifiedOrder = [];
    foreach (bbf_read_export($formId, $effective, PHP_INT_MAX, 0, null, null, null, true) as $record) {
        if (!is_string($record['id'] ?? null) || isset($verifiedRecords[$record['id']])) {
            throw new RuntimeException('Invalid or duplicate backup submission.');
        }
        $verifiedRecords[$record['id']] = $record;
        $verifiedOrder[] = $record['id'];
    }
    ksort($verifiedRecords, SORT_STRING);
    $verifiedVersions = bbf_backup_version_capture($config, $formId);
    if ($verifiedRecords !== $payload['records'] || $verifiedOrder !== $payload['record_order']
        || $verifiedVersions['active'] !== $payload['definition']
        || $verifiedVersions !== $payload['versions']
        || bbf_backup_review_capture($config, $formId) !== $payload['review']
        || bbf_backup_delivery_capture($config, $formId, $verifiedRecords) !== $payload['delivery']
        || bbf_backup_audit_capture($config, $formId) !== $payload['audit']) {
        throw new RuntimeException('Backup source changed during capture; retry.');
    }
    $payloadJson = bbf_storage_json($payload);
    $sha = hash('sha256', $payloadJson);
    $bytes = bbf_storage_json(['version' => 1, 'sha256' => $sha, 'payload' => $payload], true);
    $directory = bbf_backup_directory($config, true);
    $path = $directory . '/' . $formId . '-' . gmdate('Ymd-His', $now ?? time()) . '-' . substr($sha, 0, 16) . '.json';
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Unsafe backup path.');
    if (is_file($path)) {
        if (file_get_contents($path) !== $bytes) throw new RuntimeException('Backup name collision.');
    } elseif (!bbf_storage_locked($path, static fn(): bool => bbf_storage_replace($path,
        static fn($fp): bool => bbf_storage_write_all($fp, $bytes), 0600))) {
        throw new RuntimeException('Cannot persist backup bundle.');
    }
    return ['ok' => true, 'path' => $path, 'sha256' => $sha, 'form' => $formId, 'count' => count($records)];
}

function bbf_backup_target_empty(array $config, array $payload): bool {
    $formId = $payload['form'];
    $storage = $config['storage'] ?? 'file';
    if (!in_array($storage, ['file', 'csv', 'sqlite', 'mysql'], true)) {
        throw new RuntimeException('Unsupported restore backend.');
    }
    $forms = $config['forms_dir'] ?? __DIR__ . '/forms';
    $submissions = $config['submissions_dir'] ?? __DIR__ . '/submissions';
    $logs = $config['logs_dir'] ?? __DIR__ . '/logs';
    foreach ([$forms, $submissions, $logs] as $directory) {
        if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('Unsafe restore target directory.');
    }
    $storageName = $formId . ($storage === 'csv' ? '.csv' : '');
    if (!bbf_auth_path_identity($forms, $formId . '.json', null)
        || !bbf_auth_path_identity($submissions, $storageName, null)) {
        throw new RuntimeException('Ambiguous restore target identity.');
    }
    foreach ([$forms . '/.versions', $submissions . '/.review', $submissions . '/.delivery'] as $sharedRoot) {
        if ((file_exists($sharedRoot) || is_link($sharedRoot)) && (is_link($sharedRoot) || !is_dir($sharedRoot))) {
            throw new RuntimeException('Unsafe nested restore target directory.');
        }
    }
    $review = $submissions . '/.review/' . $formId . '.json';
    $versions = $forms . '/.versions/' . $formId;
    $delivery = $submissions . '/.delivery/' . $formId;
    $primary = $storage === 'file' ? $submissions . '/' . $formId
        : ($storage === 'csv' ? $submissions . '/' . $formId . '.csv' : null);
    if (file_exists($forms . '/' . $formId . '.json') || ($primary !== null && file_exists($primary))
        || file_exists($review) || file_exists($versions) || file_exists($delivery)) return false;
    if (in_array($storage, ['sqlite', 'mysql'], true)) {
        $pdo = bbf_read_db_connect($config);
        if ($pdo) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            foreach (['bbf_submissions', 'bbf_submission_review', 'bbf_review_filter'] as $table) {
                if ($driver === 'sqlite') {
                    $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
                } else {
                    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                    $check->execute([$table]); $exists = $check->fetchColumn();
                }
                if (!(int)$exists) continue;
                if ($table === 'bbf_submissions' && $driver === 'mysql') bbf_retention_mysql_primary_schema($pdo);
                $count = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE " . bbf_auth_form_sql($pdo));
                $count->execute([$formId]);
                if ((int)$count->fetchColumn() !== 0) return false;
            }
        }
    }
    return bbf_backup_audit_capture($config, $formId) === [];
}

function bbf_backup_restore_plan(array $config, string $path): array {
    $document = bbf_backup_bundle_read($config, $path);
    $payload = $document['payload'];
    $effective = bbf_effective_storage_config($config, $payload['form'], $payload['definition'] ?? null);
    if ($effective['storage'] !== ($payload['source_backend'] ?? null)) {
        throw new RuntimeException('Restore backend does not match the logical backup source.');
    }
    if (bbf_backup_access_policy($config, $payload['form']) !== $payload['access']) {
        throw new RuntimeException('Restore access policy does not match the protected target.');
    }
    $empty = bbf_backup_target_empty($effective, $payload);
    $base = ['version' => 1, 'dry_run' => true, 'form' => $payload['form'],
        'backend' => $effective['storage'], 'bundle_sha256' => $document['sha256'],
        'count' => count($payload['records']), 'empty' => $empty, 'confirmation' => null];
    if (!$empty) return $base;
    $bound = ['version' => 1, 'form' => $base['form'], 'backend' => $base['backend'],
        'bundle_sha256' => $base['bundle_sha256'], 'count' => $base['count'], 'access' => $payload['access']];
    $base['confirmation'] = 'restore-' . hash('sha256', bbf_storage_json($bound));
    return $base;
}

function bbf_backup_remove_created(array $paths): void {
    foreach (array_reverse($paths) as $path) {
        if (is_file($path) && !is_link($path)) @unlink($path);
        elseif (is_dir($path) && !is_link($path)) @rmdir($path);
    }
}

/** Restore-private files are unreachable until the final definition publish; the form restore lock serializes them. */
function bbf_backup_write_unpublished_json(string $path, array $value): bool {
    try {
        $json = bbf_storage_json($value, true);
        return bbf_storage_replace($path, static fn($fp): bool => bbf_storage_write_all($fp, $json), 0600);
    } catch (Throwable $error) {
        error_log('BareBonesForms restore JSON write error: ' . $error->getMessage());
        return false;
    }
}

function bbf_backup_restore_csv(array $config, array $payload, array &$created): void {
    $headers = ['_id', '_submitted', '_ip', '_user_agent'];
    $fields = [];
    $withVersions = false;
    $withStructured = false;
    foreach ($payload['records'] as $record) {
        foreach ($record['data'] as $name => $value) {
            if (!is_string($name) || in_array($name, $headers, true) || str_starts_with($name, '__bbf:')
                || (!is_scalar($value) && $value !== null && !is_array($value))) {
                throw new RuntimeException('Invalid CSV backup field.');
            }
            $fields[$name] = true;
            if (is_array($value)) $withStructured = true;
        }
        if (isset($record['meta']['definition_version'], $record['meta']['form_definition'])) $withVersions = true;
    }
    ksort($fields, SORT_STRING);
    if ($withVersions) $headers = array_merge($headers,
        ['__bbf:definition_version', '__bbf:form_definition', '__bbf:csv_escaped_fields']);
    if ($withStructured) $headers[] = '__bbf:structured_fields';
    $dataOffset = count($headers);
    $headers = array_merge($headers, array_keys($fields));
    $path = rtrim($config['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/' . $payload['form'] . '.csv';
    $created[] = $path;
    $writeOrder = array_reverse(bbf_backup_record_order($payload));
    $written = bbf_storage_replace($path, static function ($fp) use ($payload, $writeOrder, $headers, $withVersions, $withStructured, $dataOffset): bool {
        if (!bbf_storage_write_csv($fp, $headers)) return false;
            foreach ($writeOrder as $recordId) {
                $record = $payload['records'][$recordId];
                $escaped = [];
                $structured = [];
                foreach ($record['data'] as $name => $value) {
                    $cell = is_array($value) ? bbf_storage_json($value) : (string)($value ?? '');
                    if (is_array($value)) $structured[] = $name;
                    if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) $escaped[] = $name;
                }
                $row = [(string)$record['id'], (string)($record['meta']['submitted'] ?? ''),
                    (string)($record['meta']['ip'] ?? ''), (string)($record['meta']['user_agent'] ?? '')];
                if ($withVersions) {
                    $row[] = (string)($record['meta']['definition_version'] ?? '');
                    $row[] = isset($record['meta']['form_definition']) ? bbf_storage_json($record['meta']['form_definition']) : '';
                    $row[] = bbf_storage_json($escaped);
                }
                if ($withStructured) $row[] = bbf_storage_json($structured);
                foreach (array_slice($headers, $dataOffset) as $name) {
                    $value = $record['data'][$name] ?? '';
                    $cell = is_array($value) ? bbf_storage_json($value) : (string)$value;
                    $row[] = in_array($name, $escaped, true) ? "'" . $cell : $cell;
                }
                if (!bbf_storage_write_csv($fp, $row)) return false;
            }
        return true;
    }, 0600);
    if (!$written) throw new RuntimeException('Cannot restore CSV submissions.');
}

function bbf_backup_restore_db(array $config, array $payload, array &$created): void {
    if (($config['storage'] ?? '') === 'sqlite') {
        $path = $config['sqlite']['path'] ?? rtrim($config['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/bbf.sqlite';
        if (is_link($path) || !is_dir(dirname($path)) || is_link(dirname($path))) throw new RuntimeException('Unsafe restore database path.');
        if (!file_exists($path)) {
            $bootstrap = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $bootstrap = null; $created[] = $path;
        }
    }
    $pdo = bbf_review_db($config);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS bbf_submissions (
            id VARCHAR(30) PRIMARY KEY, form_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            data JSON NOT NULL, meta JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form (form_id), INDEX idx_created (created_at)
        ) ENGINE=InnoDB');
        bbf_retention_mysql_primary_schema($pdo);
        $pdo->beginTransaction();
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bbf_submissions (
            id TEXT PRIMARY KEY, form_id TEXT COLLATE BINARY NOT NULL, data TEXT NOT NULL,
            meta TEXT NOT NULL, created_at TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_form ON bbf_submissions(form_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_created ON bbf_submissions(created_at)');
        $pdo->exec('BEGIN IMMEDIATE');
    }
    try {
        $insert = $pdo->prepare('INSERT INTO bbf_submissions (id, form_id, data, meta, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($payload['records'] as $record) {
            $submitted = (string)($record['meta']['submitted'] ?? gmdate('Y-m-d\TH:i:s\Z'));
            $insert->execute([$record['id'], $payload['form'], bbf_storage_json($record['data']),
                bbf_storage_json($record['meta']), bbf_read_date($submitted)]);
        }
        $review = bbf_review_file_document($payload['review']);
        $reviewInsert = $pdo->prepare('INSERT INTO bbf_submission_review
            (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($review['records'] as $id => $record) {
            $reviewInsert->execute([$payload['form'], $id, $record['status'], $record['notes'],
                bbf_storage_json($record['tags']), $record['revision'], $record['updated_at'], $record['updated_by'], 0]);
        }
        foreach ($review['deleted'] as $id => $marker) {
            $reviewInsert->execute([$payload['form'], $id, 'new', '', bbf_storage_json([]), 0,
                $payload['created_at'], 'restore-cli', 1]);
        }
        $filterInsert = $pdo->prepare('INSERT INTO bbf_review_filter
            (form_id, principal_id, filter_id, name, criteria, revision, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($review['filters'] as $principalId => $filters) foreach ($filters as $id => $filter) {
            $filterInsert->execute([$payload['form'], $principalId, $id, $filter['name'],
                bbf_storage_json($filter['criteria']), $filter['revision'], $filter['updated_at']]);
        }
        $mysql ? $pdo->commit() : $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        if ($mysql ? $pdo->inTransaction() : true) {
            try { $mysql ? $pdo->rollBack() : $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        }
        throw $error;
    }
}

function bbf_backup_restore_db_cleanup(array $config, string $formId): bool {
    $mysql = false;
    try {
        $pdo = bbf_read_db_connect($config);
        if (!$pdo) return true;
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $mysql ? $pdo->beginTransaction() : $pdo->exec('BEGIN IMMEDIATE');
        foreach (['bbf_review_filter', 'bbf_submission_review', 'bbf_submissions'] as $table) {
            $delete = $pdo->prepare("DELETE FROM $table WHERE " . bbf_auth_form_sql($pdo));
            $delete->execute([$formId]);
        }
        $mysql ? $pdo->commit() : $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO) {
            try { $mysql ? $pdo->rollBack() : $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        }
        return false;
    }
}

function bbf_backup_audit_restore(array $config, array $payload, callable $publish): void {
    $entries = $payload['audit'];
    $submissionIds = array_map(static fn($id): string => (string)$id, array_keys($payload['records']));
    $entries[] = ['utc' => gmdate('Y-m-d\TH:i:s\Z'), 'principal_id' => 'restore-cli',
        'action' => 'backup_restore', 'form' => $payload['form'],
        'submission_ids' => $submissionIds, 'decision' => 'allowed',
        'result' => 'completed', 'result_count' => count($payload['records'])];
    $secrets = [$config['api_token'] ?? ''];
    foreach (is_array($config['access_tokens'] ?? null) ? $config['access_tokens'] : [] as $record) {
        if (is_array($record)) $secrets[] = $record['token'] ?? '';
    }
    $lines = '';
    foreach ($entries as $entry) {
        if (!is_array($entry) || !bbf_review_timestamp($entry['utc'] ?? null)
            || bbf_auth_id($entry['principal_id'] ?? null) === '' || bbf_auth_id($entry['action'] ?? null) === ''
            || ($entry['form'] ?? null) !== $payload['form'] || !is_array($entry['submission_ids'] ?? null)
            || !is_string($entry['decision'] ?? null) || !is_string($entry['result'] ?? null)
            || !is_int($entry['result_count'] ?? null)) {
            throw new RuntimeException('Invalid backup audit relationship.');
        }
        foreach ($entry['submission_ids'] as $id) if (bbf_auth_id($id) === '') {
            throw new RuntimeException('Invalid backup audit submission relationship.');
        }
        foreach ($secrets as $secret) if (is_string($secret) && $secret !== '') {
            foreach (['principal_id', 'action', 'form', 'decision', 'result'] as $key) {
                $entry[$key] = str_replace($secret, '[redacted]', $entry[$key]);
            }
            foreach ($entry['submission_ids'] as &$id) $id = str_replace($secret, '[redacted]', $id);
            unset($id);
        }
        $lines .= bbf_storage_json($entry) . "\n";
    }
    $directory = $config['logs_dir'] ?? __DIR__ . '/logs';
    $path = rtrim($directory, '/\\') . '/access-audit.php';
    $guard = "<?php http_response_code(404); exit; ?>\n";
    if (!is_dir($directory) || is_link($directory) || is_link($path)) throw new RuntimeException('Unsafe audit log.');
    $fp = @fopen($path, 'c+b');
    if (!$fp) throw new RuntimeException('Cannot open audit log.');
    $size = -1; $failure = null;
    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('Cannot lock audit log.');
        $stat = fstat($fp); $size = is_array($stat) ? (int)$stat['size'] : -1;
        if ($size < 0 || ($size > 0 && (rewind($fp) === false || fread($fp, strlen($guard)) !== $guard))) {
            throw new RuntimeException('Invalid audit log.');
        }
        if ($size > 0) {
            $existing = stream_get_contents($fp);
            if ($existing === false) throw new RuntimeException('Cannot read audit log.');
            foreach (explode("\n", $existing) as $line) {
                if ($line === '') continue;
                $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($entry) || !is_string($entry['form'] ?? null)) {
                    throw new RuntimeException('Invalid audit entry.');
                }
                if ($entry['form'] === $payload['form']) {
                    throw new RuntimeException('Restore target audit scope is no longer empty.');
                }
            }
        }
        $bytes = ($size === 0 ? $guard : '') . $lines;
        if (fseek($fp, 0, SEEK_END) !== 0 || !bbf_storage_write_all($fp, $bytes) || !fflush($fp)) {
            throw new RuntimeException('Cannot append audit log.');
        }
        @chmod($path, 0600);
        $publish();
    } catch (Throwable $error) {
        $rollback = $size === 0 ? $guard : '';
        if ($size >= 0 && (!ftruncate($fp, $size) || ($rollback !== ''
            && (rewind($fp) === false || !bbf_storage_write_all($fp, $rollback))) || !fflush($fp))) {
            $failure = new RuntimeException('Cannot roll back audit log.', 0, $error);
        } else {
            $failure = $error;
        }
    } finally {
        flock($fp, LOCK_UN); fclose($fp);
    }
    if ($failure) throw $failure;
}

function bbf_backup_delivery_validate(array $payload): array {
    $delivery = $payload['delivery'];
    foreach ($delivery as $submissionId => $ledgers) {
        $submissionId = is_int($submissionId) ? (string)$submissionId : $submissionId;
        if (bbf_auth_id($submissionId) === '' || !isset($payload['records'][$submissionId]) || !is_array($ledgers)
            || array_diff(array_keys($ledgers), ['ledger', 'events'])) {
            throw new RuntimeException('Invalid backup delivery relationship.');
        }
        foreach ($ledgers as $kind => $ledger) {
            $expectedKey = $payload['form'] . ':' . $submissionId . ($kind === 'events' ? ':events' : '');
            if (!is_array($ledger) || ($ledger['version'] ?? null) !== 1
                || ($ledger['submission_key'] ?? null) !== $expectedKey || !is_array($ledger['jobs'] ?? null)
                || ($ledger['deleted'] ?? false) === true) {
                throw new RuntimeException('Invalid backup delivery relationship.');
            }
            foreach ($ledger['jobs'] as $job) {
                if (!is_array($job) || ($job['state'] ?? null) === 'running') {
                    throw new RuntimeException('Running or invalid delivery cannot be restored safely.');
                }
            }
        }
    }
    return $delivery;
}

function bbf_backup_restore(array $config, string $path, string $confirmation): array {
    if (!preg_match('/\Arestore-[a-f0-9]{64}\z/D', $confirmation)) return ['ok' => false, 'reason' => 'confirmation'];
    $directory = bbf_backup_directory($config, false);
    $document = bbf_backup_bundle_read($config, $path);
    $formId = $document['payload']['form'];
    $lockPath = $directory . '/.' . $formId . '.restore';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
        return ['ok' => false, 'reason' => 'lock'];
    }
    $lock = @fopen($lockPath, 'c');
    if (!$lock || is_link($lockPath) || !bbf_storage_private_mode($lockPath, 0600)) {
        if (is_resource($lock)) fclose($lock);
        return ['ok' => false, 'reason' => 'lock'];
    }
    $created = [];
    $databaseRestored = false;
    try {
        if (!flock($lock, LOCK_EX)) return ['ok' => false, 'reason' => 'lock'];
        $plan = bbf_backup_restore_plan($config, $path);
        if (!is_string($plan['confirmation']) || !hash_equals($plan['confirmation'], $confirmation)) {
            return ['ok' => false, 'reason' => 'confirmation', 'plan' => $plan];
        }
        $payload = $document['payload'];
        $config = bbf_effective_storage_config($config, $formId, $payload['definition'] ?? null);
        $forms = $config['forms_dir'] ?? __DIR__ . '/forms';
        $submissions = $config['submissions_dir'] ?? __DIR__ . '/submissions';
        bbf_version_validate_definition($payload['definition'], $formId);
        $versions = $payload['versions'];
        $state = bbf_version_validate_state($versions['state'] ?? [], $formId);
        if (($versions['active'] ?? null) !== $payload['definition'] || !is_array($versions['blobs'] ?? null)) {
            throw new RuntimeException('Invalid backup version relationship.');
        }
        foreach ($versions['blobs'] as $version => $definition) {
            if (!is_array($definition) || bbf_version_id($definition) !== $version) throw new RuntimeException('Invalid backup version blob.');
        }
        $review = bbf_review_file_document($payload['review']);
        $delivery = bbf_backup_delivery_validate($payload);
        foreach ($payload['records'] as $id => $record) {
            if (bbf_auth_id((string)$id) === '' || !is_array($record) || ($record['id'] ?? null) !== (string)$id
                || ($record['form'] ?? null) !== $formId || !is_array($record['data'] ?? null) || !is_array($record['meta'] ?? null)) {
                throw new RuntimeException('Invalid backup submission.');
            }
        }
        $formPath = $forms . '/' . $formId . '.json';
        $backend = $config['storage'] ?? 'file';
        if ($backend === 'file') {
            $submissionDir = $submissions . '/' . $formId;
            if (!@mkdir($submissionDir, 0700)) throw new RuntimeException('Cannot restore submissions.');
            $created[] = $submissionDir;
            if (!bbf_storage_private_mode($submissionDir, 0700)) throw new RuntimeException('Cannot restore submissions.');
            foreach ($payload['records'] as $id => $record) {
                $recordPath = $submissionDir . '/' . $id . '.json';
                if (!bbf_backup_write_unpublished_json($recordPath, $record)) throw new RuntimeException('Cannot restore submission.');
                $created[] = $recordPath;
            }
        } elseif ($backend === 'csv') {
            bbf_backup_restore_csv($config, $payload, $created);
        } else {
            bbf_backup_restore_db($config, $payload, $created);
            $databaseRestored = true;
        }
        $versionRoot = $forms . '/.versions';
        if (!is_dir($versionRoot)) {
            if (!@mkdir($versionRoot, 0700)) throw new RuntimeException('Cannot restore version root.');
            $created[] = $versionRoot;
        }
        $versionDir = $versionRoot . '/' . $formId;
        if (!@mkdir($versionDir, 0700)) throw new RuntimeException('Cannot restore version directory.');
        $created[] = $versionDir;
        foreach ($versions['blobs'] as $version => $definition) {
            $blob = $versionDir . '/' . $version . '.json';
            if (!bbf_backup_write_unpublished_json($blob, $definition)) throw new RuntimeException('Cannot restore version blob.');
            $created[] = $blob;
        }
        $statePath = $versionDir . '/state.json';
        if (!bbf_backup_write_unpublished_json($statePath, $state)) throw new RuntimeException('Cannot restore version state.');
        $created[] = $statePath;
        if (in_array($backend, ['file', 'csv'], true) && $review !== bbf_review_file_document(null)) {
            $reviewDir = $submissions . '/.review';
            if (!is_dir($reviewDir)) {
                if (!@mkdir($reviewDir, 0700)) throw new RuntimeException('Cannot restore review root.');
                $created[] = $reviewDir;
            }
            $reviewPath = $reviewDir . '/' . $formId . '.json';
            if (!bbf_backup_write_unpublished_json($reviewPath, $review)) throw new RuntimeException('Cannot restore review repository.');
            $created[] = $reviewPath;
        }
        if ($delivery !== []) {
            $deliveryRoot = $submissions . '/.delivery';
            if (!is_dir($deliveryRoot)) {
                if (!@mkdir($deliveryRoot, 0700)) throw new RuntimeException('Cannot restore delivery root.');
                $created[] = $deliveryRoot;
            }
            $deliveryDir = $deliveryRoot . '/' . $formId;
            if (!@mkdir($deliveryDir, 0700)) throw new RuntimeException('Cannot restore delivery directory.');
            $created[] = $deliveryDir;
            foreach ($delivery as $submissionId => $ledgers) {
                foreach ($ledgers as $kind => $ledger) {
                    $ledgerPath = $deliveryDir . '/' . $submissionId . '.json' . ($kind === 'events' ? '.events' : '');
                    if (!bbf_backup_write_unpublished_json($ledgerPath, $ledger)) throw new RuntimeException('Cannot restore delivery ledger.');
                    $created[] = $ledgerPath;
                }
            }
        }
        bbf_backup_audit_restore($config, $payload, static function () use ($formPath, $payload, &$created): void {
            if (!bbf_storage_write_json($formPath, $payload['definition'])) {
                throw new RuntimeException('Cannot publish restored definition.');
            }
            $created[] = $formPath;
        });
        return ['ok' => true, 'form' => $formId, 'restored' => count($payload['records'])];
    } catch (Throwable $error) {
        $databaseClean = !$databaseRestored || bbf_backup_restore_db_cleanup($config, $formId);
        bbf_backup_remove_created($created);
        error_log('BareBonesForms restore: ' . $error->getMessage());
        return ['ok' => false, 'reason' => $databaseClean ? 'storage' : 'rollback'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
