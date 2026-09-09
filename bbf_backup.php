<?php
/** Protected form-scoped logical backup and explicit restore primitives. No configuration is loaded here. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_retention.php';
require_once __DIR__ . '/bbf_review.php';
require_once __DIR__ . '/bbf_versions.php';

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
    if (!in_array($effective['storage'], ['file', 'csv'], true)) {
        throw new RuntimeException('This backup slice supports file-backed review repositories only.');
    }
    return bbf_review_file_transaction($effective, $formId, false,
        static fn(array $document): array => $document);
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
        || !is_array($payload['audit'] ?? null) || !is_array($payload['access'] ?? null)) {
        throw new RuntimeException('Invalid backup bundle payload.');
    }
    return $document;
}

function bbf_backup_create(array $config, string $formId, ?int $now = null): array {
    if (bbf_auth_id($formId) === '' || !bbf_auth_form_identity($config, $formId)) {
        throw new RuntimeException('Backup form scope is unavailable or ambiguous.');
    }
    $effective = bbf_effective_storage_config($config, $formId);
    if ($effective['storage'] !== 'file') {
        throw new RuntimeException('This backup slice currently supports file submission storage only.');
    }
    $records = [];
    foreach (bbf_read_export($formId, $effective, PHP_INT_MAX, 0, null, null) as $record) {
        if (!is_string($record['id'] ?? null) || isset($records[$record['id']])) {
            throw new RuntimeException('Invalid or duplicate backup submission.');
        }
        $records[$record['id']] = $record;
    }
    ksort($records, SORT_STRING);
    $payload = ['version' => 1, 'kind' => 'logical-backup',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()), 'form' => $formId,
        'source_backend' => $effective['storage'], 'access' => bbf_backup_access_policy($config, $formId),
        'definition' => bbf_version_read_active(bbf_version_paths($config, $formId)),
        'versions' => bbf_backup_version_capture($config, $formId), 'records' => $records,
        'review' => bbf_backup_review_capture($config, $formId), 'audit' => bbf_backup_audit_capture($config, $formId)];
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
    if (($config['storage'] ?? 'file') !== 'file') throw new RuntimeException('This restore slice supports file storage only.');
    $forms = $config['forms_dir'] ?? __DIR__ . '/forms';
    $submissions = $config['submissions_dir'] ?? __DIR__ . '/submissions';
    $logs = $config['logs_dir'] ?? __DIR__ . '/logs';
    foreach ([$forms, $submissions, $logs] as $directory) {
        if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('Unsafe restore target directory.');
    }
    if (!bbf_auth_path_identity($forms, $formId . '.json', null)
        || !bbf_auth_path_identity($submissions, $formId, null)) {
        throw new RuntimeException('Ambiguous restore target identity.');
    }
    $review = $submissions . '/.review/' . $formId . '.json';
    $versions = $forms . '/.versions/' . $formId;
    if (file_exists($forms . '/' . $formId . '.json') || file_exists($submissions . '/' . $formId)
        || file_exists($review) || file_exists($versions)) return false;
    $audit = bbf_backup_audit_capture($config, $formId);
    return $audit === [];
}

function bbf_backup_restore_plan(array $config, string $path): array {
    $document = bbf_backup_bundle_read($config, $path);
    $payload = $document['payload'];
    if (bbf_backup_access_policy($config, $payload['form']) !== $payload['access']) {
        throw new RuntimeException('Restore access policy does not match the protected target.');
    }
    $empty = bbf_backup_target_empty($config, $payload);
    $base = ['version' => 1, 'dry_run' => true, 'form' => $payload['form'],
        'backend' => $config['storage'] ?? 'file', 'bundle_sha256' => $document['sha256'],
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
        if (is_file($path . '.lock') && !is_link($path . '.lock')) @unlink($path . '.lock');
    }
}

function bbf_backup_restore(array $config, string $path, string $confirmation): array {
    if (!preg_match('/\Arestore-[a-f0-9]{64}\z/D', $confirmation)) return ['ok' => false, 'reason' => 'confirmation'];
    $directory = bbf_backup_directory($config, false);
    $document = bbf_backup_bundle_read($config, $path);
    $formId = $document['payload']['form'];
    $lockPath = $directory . '/.' . $formId . '.restore';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !bbf_storage_private_mode($lockPath, 0600)) {
        if (is_resource($lock)) fclose($lock);
        return ['ok' => false, 'reason' => 'lock'];
    }
    $created = [];
    try {
        if (!flock($lock, LOCK_EX)) return ['ok' => false, 'reason' => 'lock'];
        $plan = bbf_backup_restore_plan($config, $path);
        if (!is_string($plan['confirmation']) || !hash_equals($plan['confirmation'], $confirmation)) {
            return ['ok' => false, 'reason' => 'confirmation', 'plan' => $plan];
        }
        $payload = $document['payload'];
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
        $formPath = $forms . '/' . $formId . '.json';
        if (!bbf_storage_write_json($formPath, $payload['definition'])) throw new RuntimeException('Cannot restore definition.');
        $created[] = $formPath;
        $submissionDir = $submissions . '/' . $formId;
        if (!mkdir($submissionDir, 0700) || !bbf_storage_private_mode($submissionDir, 0700)) throw new RuntimeException('Cannot restore submissions.');
        $created[] = $submissionDir;
        foreach ($payload['records'] as $id => $record) {
            if (bbf_auth_id((string)$id) === '' || !is_array($record) || ($record['id'] ?? null) !== (string)$id
                || ($record['form'] ?? null) !== $formId || !is_array($record['data'] ?? null) || !is_array($record['meta'] ?? null)) {
                throw new RuntimeException('Invalid backup submission.');
            }
            $recordPath = $submissionDir . '/' . $id . '.json';
            if (!bbf_storage_write_json($recordPath, $record)) throw new RuntimeException('Cannot restore submission.');
            $created[] = $recordPath;
        }
        $versionRoot = $forms . '/.versions';
        if (!is_dir($versionRoot) && !mkdir($versionRoot, 0700)) throw new RuntimeException('Cannot restore version root.');
        $created[] = $versionRoot;
        $versionDir = $versionRoot . '/' . $formId;
        if (!mkdir($versionDir, 0700)) throw new RuntimeException('Cannot restore version directory.');
        $created[] = $versionDir;
        foreach ($versions['blobs'] as $version => $definition) {
            $blob = $versionDir . '/' . $version . '.json';
            if (!bbf_storage_write_json($blob, $definition)) throw new RuntimeException('Cannot restore version blob.');
            $created[] = $blob;
        }
        $statePath = $versionDir . '/state.json';
        if (!bbf_storage_write_json($statePath, $state)) throw new RuntimeException('Cannot restore version state.');
        $created[] = $statePath;
        if ($review !== bbf_review_file_document(null)) {
            $reviewDir = $submissions . '/.review';
            if (!is_dir($reviewDir) && !mkdir($reviewDir, 0700)) throw new RuntimeException('Cannot restore review root.');
            $created[] = $reviewDir;
            $reviewPath = $reviewDir . '/' . $formId . '.json';
            if (!bbf_storage_write_json($reviewPath, $review)) throw new RuntimeException('Cannot restore review repository.');
            $created[] = $reviewPath;
        }
        foreach ($payload['audit'] as $entry) {
            if (!is_array($entry) || ($entry['form'] ?? null) !== $formId
                || bbf_auth_id($entry['principal_id'] ?? null) === '' || bbf_auth_id($entry['action'] ?? null) === ''
                || !is_array($entry['submission_ids'] ?? null) || !is_string($entry['decision'] ?? null)
                || !is_string($entry['result'] ?? null) || !is_int($entry['result_count'] ?? null)) {
                throw new RuntimeException('Invalid backup audit relationship.');
            }
            bbf_audit_write($config, ['id' => $entry['principal_id']], $entry['action'], $formId,
                $entry['submission_ids'], $entry['decision'], $entry['result'], $entry['result_count']);
        }
        bbf_audit_write($config, ['id' => 'restore-cli'], 'backup_restore', $formId,
            array_keys($payload['records']), 'allowed', 'completed', count($payload['records']));
        return ['ok' => true, 'form' => $formId, 'restored' => count($payload['records'])];
    } catch (Throwable $error) {
        bbf_backup_remove_created($created);
        error_log('BareBonesForms restore: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
