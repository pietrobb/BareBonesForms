<?php
/** Immutable form definitions, editor revision state and historical submission presentation. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_storage.php';

function bbf_version_form_id(string $formId): string {
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $formId)) {
        throw new InvalidArgumentException('Invalid form version ID.');
    }
    return $formId;
}

function bbf_version_normalize($value) {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('bbf_version_normalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as &$item) $item = bbf_version_normalize($item);
    unset($item);
    return $value;
}

function bbf_version_json(array $definition, bool $pretty = false): string {
    return json_encode(bbf_version_normalize($definition), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));
}

function bbf_version_id(array $definition): string {
    return 'v1-' . hash('sha256', bbf_version_json($definition));
}

function bbf_version_validate_definition(array $definition, string $formId): void {
    if (($definition['id'] ?? null) !== $formId || !is_array($definition['fields'] ?? null)) {
        throw new InvalidArgumentException('Definition identity or fields are invalid.');
    }
}

function bbf_version_presentation(array $definition): array {
    $walk = static function (array $fields) use (&$walk): array {
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null)
                || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $field['name'])) continue;
            $safe = ['name' => $field['name']];
            foreach (['label', 'type', 'title'] as $key) {
                if (is_string($field[$key] ?? null)) $safe[$key] = $field[$key];
            }
            if (is_array($field['fields'] ?? null)) $safe['fields'] = $walk($field['fields']);
            $result[] = $safe;
        }
        return $result;
    };
    return [
        'id' => is_string($definition['id'] ?? null) ? $definition['id'] : '',
        'name' => is_string($definition['name'] ?? null) ? $definition['name'] : '',
        'fields' => $walk(is_array($definition['fields'] ?? null) ? $definition['fields'] : []),
    ];
}

function bbf_version_submission_metadata(array $definition, string $formId, ?string $publishedVersion = null): array {
    bbf_version_validate_definition($definition, $formId);
    $version = $publishedVersion ?? bbf_version_id($definition);
    if (!preg_match('/\Av1-[a-f0-9]{64}\z/D', $version)) throw new InvalidArgumentException('Invalid published definition version.');
    return [
        'definition_version' => $version,
        'form_definition' => bbf_version_presentation($definition),
    ];
}

function bbf_version_submission_definition(array $submission, ?array $current = null): ?array {
    $formId = is_string($submission['form'] ?? null) ? $submission['form'] : '';
    $snapshot = $submission['meta']['form_definition'] ?? null;
    if (is_array($snapshot) && ($snapshot['id'] ?? null) === $formId && is_array($snapshot['fields'] ?? null)) {
        return bbf_version_presentation($snapshot);
    }
    return $current;
}

function bbf_version_exact_child(string $parent, string $name, bool $directory): bool {
    if (!is_dir($parent) || is_link($parent)) return false;
    $entries = scandir($parent);
    if ($entries === false) return false;
    foreach ($entries as $entry) {
        if (strcasecmp($entry, $name) !== 0) continue;
        $path = $parent . '/' . $entry;
        return $entry === $name && !is_link($path) && ($directory ? is_dir($path) : is_file($path));
    }
    return false;
}

function bbf_version_paths(array $config, string $formId): array {
    $formId = bbf_version_form_id($formId);
    $forms = $config['forms_dir'] ?? __DIR__ . '/forms';
    if (!is_dir($forms) || is_link($forms)) throw new RuntimeException('Unsafe forms directory.');
    return [
        'forms' => $forms,
        'active' => $forms . '/' . $formId . '.json',
        'root' => $forms . '/.versions',
        'dir' => $forms . '/.versions/' . $formId,
        'state' => $forms . '/.versions/' . $formId . '/state.json',
    ];
}

function bbf_version_prepare_directory(array $paths): void {
    foreach ([[$paths['root'], $paths['forms'], '.versions'], [$paths['dir'], $paths['root'], basename($paths['dir'])]] as [$path, $parent, $name]) {
        if (!file_exists($path)) {
            if (!bbf_version_exact_child($parent, $name, true) && !@mkdir($path, 0700)) {
                throw new RuntimeException('Cannot create form version repository.');
            }
        }
        if (!bbf_version_exact_child($parent, $name, true)) throw new RuntimeException('Unsafe form version repository.');
    }
}

function bbf_version_read_active(array $paths): array {
    $name = basename($paths['active']);
    if (!bbf_version_exact_child($paths['forms'], $name, false)) throw new RuntimeException('Published form definition is unavailable.');
    $lock = @fopen($paths['active'] . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open published definition lock.');
    try {
        if (!flock($lock, LOCK_SH)) throw new RuntimeException('Cannot lock published definition.');
        $raw = file_get_contents($paths['active']);
        if ($raw === false) throw new RuntimeException('Cannot read published definition.');
        $definition = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($definition)) throw new RuntimeException('Invalid published definition.');
        bbf_version_validate_definition($definition, basename($paths['active'], '.json'));
        return $definition;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_version_blob_path(array $paths, string $version): string {
    if (!preg_match('/\Av1-[a-f0-9]{64}\z/D', $version)) throw new RuntimeException('Invalid definition version.');
    return $paths['dir'] . '/' . $version . '.json';
}

function bbf_version_store_blob(array $paths, array $definition): string {
    $version = bbf_version_id($definition);
    $path = bbf_version_blob_path($paths, $version);
    if (file_exists($path)) {
        $existing = bbf_version_read_blob($paths, $version);
        if (bbf_version_id($existing) !== $version) throw new RuntimeException('Definition version collision.');
        return $version;
    }
    $bytes = bbf_version_json($definition, true);
    $temp = $path . '.tmp-' . bin2hex(random_bytes(12));
    $fp = @fopen($temp, 'xb');
    try {
        if (!$fp || !bbf_storage_write_all($fp, $bytes) || !fflush($fp) || !fclose($fp)) {
            $fp = null;
            throw new RuntimeException('Cannot write immutable definition version.');
        }
        $fp = null;
        if (!@rename($temp, $path)) throw new RuntimeException('Cannot publish immutable definition version.');
        @chmod($path, 0600);
    } finally {
        if (is_resource($fp)) fclose($fp);
        if (is_file($temp)) @unlink($temp);
    }
    return $version;
}

function bbf_version_read_blob(array $paths, string $version): array {
    $path = bbf_version_blob_path($paths, $version);
    if (!bbf_version_exact_child($paths['dir'], basename($path), false)) throw new RuntimeException('Definition version is unavailable.');
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Cannot read definition version.');
    $definition = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($definition) || bbf_version_id($definition) !== $version) throw new RuntimeException('Definition version integrity failed.');
    bbf_version_validate_definition($definition, basename($paths['dir']));
    return $definition;
}

function bbf_version_validate_state(array $state, string $formId): array {
    if (($state['form'] ?? null) !== $formId || !is_int($state['revision'] ?? null) || $state['revision'] < 0
        || !is_string($state['published_version'] ?? null) || !is_string($state['draft_version'] ?? null)
        || !is_array($state['history'] ?? null) || !array_is_list($state['history'])) {
        throw new RuntimeException('Invalid form version state.');
    }
    if (isset($state['pending'])) {
        $pending = $state['pending'];
        if (!is_array($pending) || ($pending['revision'] ?? null) !== $state['revision'] + 1
            || !is_string($pending['version'] ?? null) || !in_array($pending['action'] ?? null, ['publish', 'rollback'], true)
            || !is_string($pending['at'] ?? null) || !is_string($pending['by'] ?? null)) {
            throw new RuntimeException('Invalid pending form publication.');
        }
    }
    return $state;
}

function bbf_version_write_state(array $paths, array $state): void {
    $bytes = bbf_storage_json($state, true);
    if (!bbf_storage_replace($paths['state'], static fn($fp) => bbf_storage_write_all($fp, $bytes))) {
        throw new RuntimeException('Cannot write form version state.');
    }
}

function bbf_version_event(int $revision, string $version, string $action, string $actor): array {
    return ['revision' => $revision, 'version' => $version, 'action' => $action,
        'at' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $actor) ? $actor : 'legacy-admin'];
}

function bbf_version_load_state_locked(array $paths): array {
    $formId = basename($paths['dir']);
    $active = bbf_version_read_active($paths);
    $activeVersion = bbf_version_store_blob($paths, $active);
    if (!is_file($paths['state'])) {
        $state = ['form' => $formId, 'revision' => 0, 'published_version' => $activeVersion,
            'draft_version' => $activeVersion, 'history' => [bbf_version_event(0, $activeVersion, 'bootstrap', 'legacy-admin')]];
        bbf_version_write_state($paths, $state);
        return $state;
    }
    $raw = file_get_contents($paths['state']);
    if ($raw === false) throw new RuntimeException('Cannot read form version state.');
    $state = bbf_version_validate_state(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $formId);
    bbf_version_read_blob($paths, $state['published_version']);
    bbf_version_read_blob($paths, $state['draft_version']);
    if (isset($state['pending'])) {
        $pending = $state['pending'];
        bbf_version_read_blob($paths, $pending['version']);
        unset($state['pending']);
        if ($activeVersion === $pending['version']) {
            $state['revision'] = $pending['revision'];
            $state['published_version'] = $pending['version'];
            if ($pending['action'] === 'rollback') $state['draft_version'] = $pending['version'];
            $state['history'][] = $pending;
        }
        bbf_version_write_state($paths, $state);
    }
    if ($activeVersion !== $state['published_version']) {
        $state['revision']++;
        $state['published_version'] = $activeVersion;
        $state['draft_version'] = $activeVersion;
        $state['history'][] = bbf_version_event($state['revision'], $activeVersion, 'external_publish', 'legacy-admin');
        bbf_version_write_state($paths, $state);
    }
    return $state;
}

function bbf_version_with_state(array $config, string $formId, callable $operation) {
    $paths = bbf_version_paths($config, $formId);
    bbf_version_prepare_directory($paths);
    $lock = @fopen($paths['state'] . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open form version state lock.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock form version state.');
        $state = bbf_version_load_state_locked($paths);
        return $operation($paths, $state);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_version_public_state(array $paths, array $state): array {
    return ['form' => $state['form'], 'revision' => $state['revision'],
        'published_version' => $state['published_version'], 'draft_version' => $state['draft_version']];
}

function bbf_version_state(array $config, string $formId): array {
    return bbf_version_with_state($config, $formId, static function (array $paths, array $state): array {
        $result = bbf_version_public_state($paths, $state);
        $result['definition'] = bbf_version_read_blob($paths, $state['draft_version']);
        return $result;
    });
}

function bbf_version_history(array $config, string $formId): array {
    return bbf_version_with_state($config, $formId, static fn(array $paths, array $state): array => $state['history']);
}

function bbf_version_conflict(array $paths, array $state): array {
    $result = ['ok' => false, 'reason' => 'conflict'] + bbf_version_public_state($paths, $state);
    $result['definition'] = bbf_version_read_blob($paths, $state['draft_version']);
    return $result;
}

function bbf_version_save_draft(array $config, string $formId, array $definition, int $expectedRevision, string $actor): array {
    bbf_version_validate_definition($definition, $formId);
    return bbf_version_with_state($config, $formId, static function (array $paths, array $state) use ($definition, $expectedRevision, $actor): array {
        if ($state['revision'] !== $expectedRevision) return bbf_version_conflict($paths, $state);
        $version = bbf_version_store_blob($paths, $definition);
        $state['revision']++;
        $state['draft_version'] = $version;
        $state['history'][] = bbf_version_event($state['revision'], $version, 'draft', $actor);
        bbf_version_write_state($paths, $state);
        return ['ok' => true] + bbf_version_public_state($paths, $state);
    });
}

function bbf_version_publish_locked(array $paths, array $state, string $version, string $action, string $actor): array {
    $definition = bbf_version_read_blob($paths, $version);
    $event = bbf_version_event($state['revision'] + 1, $version, $action, $actor);
    $prepared = $state;
    $prepared['pending'] = $event;
    bbf_version_write_state($paths, $prepared);
    $bytes = bbf_version_json($definition, true);
    $published = bbf_storage_locked($paths['active'], static fn() => bbf_storage_replace($paths['active'],
        static fn($fp) => bbf_storage_write_all($fp, $bytes)));
    if (!$published) {
        try { bbf_version_write_state($paths, $state); } catch (Throwable $ignored) {}
        throw new RuntimeException($action === 'rollback' ? 'Cannot roll back form definition.' : 'Cannot publish form definition.');
    }
    $state['revision'] = $event['revision'];
    $state['published_version'] = $version;
    if ($action === 'rollback') $state['draft_version'] = $version;
    $state['history'][] = $event;
    try {
        bbf_version_write_state($paths, $state);
        $recoveryPending = false;
    } catch (Throwable $ignored) {
        $recoveryPending = true;
    }
    return ['ok' => true, 'recovery_pending' => $recoveryPending] + bbf_version_public_state($paths, $state);
}

function bbf_version_publish(array $config, string $formId, int $expectedRevision, string $actor): array {
    return bbf_version_with_state($config, $formId, static function (array $paths, array $state) use ($expectedRevision, $actor): array {
        if ($state['revision'] !== $expectedRevision) return bbf_version_conflict($paths, $state);
        return bbf_version_publish_locked($paths, $state, $state['draft_version'], 'publish', $actor);
    });
}

function bbf_version_rollback(array $config, string $formId, string $version, int $expectedRevision, string $actor): array {
    return bbf_version_with_state($config, $formId, static function (array $paths, array $state) use ($version, $expectedRevision, $actor): array {
        if ($state['revision'] !== $expectedRevision) return bbf_version_conflict($paths, $state);
        return bbf_version_publish_locked($paths, $state, $version, 'rollback', $actor);
    });
}

function bbf_version_delete_active(array $config, string $formId): bool {
    return bbf_version_with_state($config, $formId, static function (array $paths): bool {
        return bbf_storage_locked($paths['active'], static function () use ($paths): bool {
            return bbf_version_exact_child($paths['forms'], basename($paths['active']), false)
                && @unlink($paths['active']);
        });
    });
}
