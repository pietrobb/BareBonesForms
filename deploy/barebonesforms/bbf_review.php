<?php
/** Viewer-only submission workflow metadata. Never merge this data into respondent records or exports. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_auth.php';
require_once __DIR__ . '/bbf_storage.php';
require_once __DIR__ . '/bbf_read.php';

function bbf_review_default(): array {
    return ['status' => 'new', 'notes' => '', 'tags' => [], 'revision' => 0,
        'updated_at' => null, 'updated_by' => null];
}

function bbf_review_valid_text($value, int $maxBytes): bool {
    return is_string($value) && strlen($value) <= $maxBytes && preg_match('//u', $value) === 1;
}

function bbf_review_timestamp($value): bool {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count']));
}

function bbf_review_tags($value): ?array {
    if (!is_array($value) || !array_is_list($value) || count($value) > 20) return null;
    $tags = [];
    foreach ($value as $tag) {
        if (!bbf_review_valid_text($tag, 64)) return null;
        $tag = trim($tag);
        if ($tag === '' || preg_match('/[\x00-\x1f\x7f]/u', $tag)) return null;
        if (!in_array($tag, $tags, true)) $tags[] = $tag;
    }
    return $tags;
}

function bbf_review_filter_criteria($value): ?array {
    if (!is_array($value) || array_diff(array_keys($value), ['q', 'from', 'to', 'status', 'tags'])) return null;
    $criteria = [];
    if (array_key_exists('q', $value)) {
        if (!bbf_review_valid_text($value['q'], 1000)) return null;
        $q = trim($value['q']);
        if ($q !== '') $criteria['q'] = $q;
    }
    foreach (['from', 'to'] as $key) {
        if (!array_key_exists($key, $value)) continue;
        if (!is_string($value[$key]) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value[$key], $date)
            || !checkdate((int)$date[2], (int)$date[3], (int)$date[1])) return null;
        $criteria[$key] = $value[$key];
    }
    if (array_key_exists('status', $value)) {
        if (!in_array($value['status'], ['new', 'in-progress', 'done'], true)) return null;
        $criteria['status'] = $value['status'];
    }
    if (array_key_exists('tags', $value)) {
        $tags = bbf_review_tags($value['tags']);
        if ($tags === null) return null;
        if ($tags !== []) $criteria['tags'] = $tags;
    }
    return $criteria;
}

function bbf_review_filter_record($value): array {
    if (!is_array($value) || !bbf_review_valid_text($value['name'] ?? null, 120)
        || trim($value['name']) === '' || preg_match('/[\x00-\x1f\x7f]/u', $value['name'])
        || ($criteria = bbf_review_filter_criteria($value['criteria'] ?? null)) === null
        || !is_int($value['revision'] ?? null) || $value['revision'] < 1
        || !bbf_review_timestamp($value['updated_at'] ?? null)) {
        throw new RuntimeException('Invalid stored review filter.');
    }
    return ['name' => trim($value['name']), 'criteria' => $criteria,
        'revision' => $value['revision'], 'updated_at' => $value['updated_at']];
}

function bbf_review_record($value): array {
    if ($value === null) return bbf_review_default();
    if (!is_array($value)
        || !in_array($value['status'] ?? null, ['new', 'in-progress', 'done'], true)
        || !bbf_review_valid_text($value['notes'] ?? null, 20000)
        || ($tags = bbf_review_tags($value['tags'] ?? null)) === null
        || !is_int($value['revision'] ?? null) || $value['revision'] < 1
        || !bbf_review_timestamp($value['updated_at'] ?? null)
        || bbf_auth_id($value['updated_by'] ?? null) === '') {
        throw new RuntimeException('Invalid stored review metadata.');
    }
    return ['status' => $value['status'], 'notes' => $value['notes'], 'tags' => $tags,
        'revision' => $value['revision'], 'updated_at' => $value['updated_at'],
        'updated_by' => $value['updated_by']];
}

function bbf_review_patch(array $patch): ?array {
    if ($patch === [] || array_diff(array_keys($patch), ['status', 'notes', 'tags'])) return null;
    $clean = [];
    if (array_key_exists('status', $patch)) {
        if (!in_array($patch['status'], ['new', 'in-progress', 'done'], true)) return null;
        $clean['status'] = $patch['status'];
    }
    if (array_key_exists('notes', $patch)) {
        if (!bbf_review_valid_text($patch['notes'], 20000)) return null;
        $clean['notes'] = $patch['notes'];
    }
    if (array_key_exists('tags', $patch)) {
        $tags = bbf_review_tags($patch['tags']);
        if ($tags === null) return null;
        $clean['tags'] = $tags;
    }
    return $clean;
}

function bbf_review_file_path(array $config, string $formId): string {
    return rtrim($config['submissions_dir'] ?? __DIR__ . '/submissions', '/\\') . '/.review/' . $formId . '.json';
}

function bbf_review_file_document($value): array {
    if ($value === null) return ['version' => 1, 'records' => [], 'filters' => [], 'deleted' => []];
    if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_array($value['records'] ?? null)
        || (array_key_exists('filters', $value) && !is_array($value['filters']))
        || (array_key_exists('deleted', $value) && !is_array($value['deleted']))) {
        throw new RuntimeException('Invalid review repository.');
    }
    foreach ($value['records'] as $id => $record) {
        $safeId = is_int($id) ? (string)$id : $id;
        if ($record === null || bbf_auth_id($safeId) === '') throw new RuntimeException('Invalid review repository record.');
        $value['records'][$id] = bbf_review_record($record);
    }
    $filters = $value['filters'] ?? [];
    foreach ($filters as $principalId => $principalFilters) {
        $safePrincipalId = is_int($principalId) ? (string)$principalId : $principalId;
        if (bbf_auth_id($safePrincipalId) === '' || !is_array($principalFilters)) {
            throw new RuntimeException('Invalid review filter principal.');
        }
        foreach ($principalFilters as $filterId => $filter) {
            $safeFilterId = is_int($filterId) ? (string)$filterId : $filterId;
            if ($filter === null || bbf_auth_id($safeFilterId) === '') throw new RuntimeException('Invalid review filter ID.');
            $filters[$principalId][$filterId] = bbf_review_filter_record($filter);
        }
    }
    $deleted = $value['deleted'] ?? [];
    foreach ($deleted as $id => $marker) {
        $safeId = is_int($id) ? (string)$id : $id;
        if (bbf_auth_id($safeId) === '' || $marker !== true) throw new RuntimeException('Invalid review deletion marker.');
    }
    return ['version' => 1, 'records' => $value['records'], 'filters' => $filters, 'deleted' => $deleted];
}

function bbf_review_file_transaction(array $config, string $formId, bool $write, callable $operation,
    ?callable $afterPersist = null) {
    $path = bbf_review_file_path($config, $formId);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!$write) return $operation(bbf_review_file_document(null));
        $parent = dirname($dir);
        if (!is_dir($parent) || is_link($parent)) throw new RuntimeException('Unsafe review repository parent.');
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create review repository directory.');
        }
        if (is_link($dir)) throw new RuntimeException('Unsafe review repository directory.');
    }
    $lockPath = $path . '.lock';
    if (is_link($dir) || is_link($path) || is_link($lockPath)
        || (file_exists($path) && !is_file($path)) || (file_exists($lockPath) && !is_file($lockPath))) {
        throw new RuntimeException('Unsafe review repository path.');
    }
    $lock = @fopen($lockPath, 'c');
    if (!$lock) throw new RuntimeException('Cannot open review repository lock.');
    $lockStat = fstat($lock);
    if (!is_array($lockStat) || ($lockStat['mode'] & 0170000) !== 0100000) {
        fclose($lock);
        throw new RuntimeException('Unsafe review repository lock.');
    }
    try {
        if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Cannot lock review repository.');
        clearstatcache(true, $path);
        clearstatcache(true, $lockPath);
        if (is_link($dir) || is_link($path) || is_link($lockPath)
            || (file_exists($path) && !is_file($path))) throw new RuntimeException('Review repository path changed while locking.');
        $document = null; $raw = null;
        $exists = is_file($path);
        if ($exists) {
            $raw = file_get_contents($path);
            if ($raw === false) throw new RuntimeException('Cannot read review repository.');
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if ($document === null) throw new RuntimeException('Invalid review repository.');
        }
        $document = bbf_review_file_document($document);
        $result = $operation($document);
        if ($write && ($result['ok'] ?? false)) {
            $json = bbf_storage_json($document, true);
            if (!bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json))) {
                throw new RuntimeException('Cannot persist review repository.');
            }
            if ($afterPersist !== null) {
                try { $primary = $afterPersist(null); }
                catch (Throwable $error) { $primary = ['ok' => false, 'deleted' => false, 'reason' => 'primary']; }
                if (!is_array($primary) || ($primary['ok'] ?? false) !== true) {
                    $restored = $exists
                        ? bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $raw))
                        : (!is_file($path) || @unlink($path));
                    return ['ok' => false, 'reason' => $restored ? ($primary['reason'] ?? 'primary') : 'rollback',
                        'primary' => is_array($primary) ? $primary : null];
                }
                $result['primary'] = $primary;
            }
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_review_sqlite_schema(PDO $pdo, string $table, array $types, array $primary, array $binary): void {
    $rows = $pdo->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) $columns[$row['name']] = $row;
    foreach ($types as $name => $type) {
        if (strtoupper($columns[$name]['type'] ?? '') !== $type || (int)($columns[$name]['notnull'] ?? 0) !== 1) {
            throw new RuntimeException("Unsafe $table schema.");
        }
    }
    $actualPrimary = [];
    foreach ($columns as $name => $column) if ((int)$column['pk'] > 0) $actualPrimary[(int)$column['pk']] = $name;
    ksort($actualPrimary);
    if (array_values($actualPrimary) !== $primary) throw new RuntimeException("Unsafe $table primary key.");
    $sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
    foreach ($binary as $name) {
        if (!preg_match('/(?:^|[,(])\s*' . preg_quote($name, '/') . '\s+TEXT\s+COLLATE\s+BINARY\b/i', $sql)) {
            throw new RuntimeException("Unsafe $table identifier collation.");
        }
    }
}

function bbf_review_mysql_schema(PDO $pdo, string $table, array $types, array $collations,
    array $lengths, array $primary): void {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLLATION_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]); $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $columns[$row['COLUMN_NAME']] = $row;
    foreach ($types as $name => $type) {
        if (($columns[$name]['DATA_TYPE'] ?? '') !== $type || ($columns[$name]['IS_NULLABLE'] ?? '') !== 'NO'
            || (array_key_exists($name, $collations) && ($columns[$name]['COLLATION_NAME'] ?? null) !== $collations[$name])
            || (isset($lengths[$name]) && (int)($columns[$name]['CHARACTER_MAXIMUM_LENGTH'] ?? 0) < $lengths[$name])) {
            throw new RuntimeException("Unsafe $table schema.");
        }
    }
    $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $engine->execute([$table]);
    if (strtoupper((string)$engine->fetchColumn()) !== 'INNODB') throw new RuntimeException("Unsafe $table storage engine.");
    $key = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
    $key->execute([$table]);
    if ($key->fetchAll(PDO::FETCH_COLUMN) !== $primary) throw new RuntimeException("Unsafe $table primary key.");
}

function bbf_review_db(array $config): PDO {
    $pdo = bbf_read_db_connect($config);
    if (!$pdo) throw new RuntimeException('Review database is unavailable.');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS bbf_submission_review (
            form_id TEXT COLLATE BINARY NOT NULL,
            submission_id TEXT COLLATE BINARY NOT NULL,
            status TEXT NOT NULL,
            notes TEXT NOT NULL,
            tags TEXT NOT NULL,
            revision INTEGER NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL,
            deleted INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (form_id, submission_id)
        )');
        $columns = $pdo->query("PRAGMA table_info('bbf_submission_review')")->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('deleted', array_column($columns, 'name'), true)) {
            try {
                $pdo->exec('ALTER TABLE bbf_submission_review ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0');
            } catch (Throwable $migrationError) {
                $columns = $pdo->query("PRAGMA table_info('bbf_submission_review')")->fetchAll(PDO::FETCH_ASSOC);
                if (!in_array('deleted', array_column($columns, 'name'), true)) throw $migrationError;
            }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS bbf_review_filter (
            form_id TEXT COLLATE BINARY NOT NULL,
            principal_id TEXT COLLATE BINARY NOT NULL,
            filter_id TEXT COLLATE BINARY NOT NULL,
            name TEXT NOT NULL,
            criteria TEXT NOT NULL,
            revision INTEGER NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (form_id, principal_id, filter_id)
        )');
        bbf_review_sqlite_schema($pdo, 'bbf_submission_review', [
            'form_id' => 'TEXT', 'submission_id' => 'TEXT', 'status' => 'TEXT', 'notes' => 'TEXT',
            'tags' => 'TEXT', 'revision' => 'INTEGER', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT', 'deleted' => 'INTEGER',
        ], ['form_id', 'submission_id'], ['form_id', 'submission_id']);
        bbf_review_sqlite_schema($pdo, 'bbf_review_filter', [
            'form_id' => 'TEXT', 'principal_id' => 'TEXT', 'filter_id' => 'TEXT', 'name' => 'TEXT',
            'criteria' => 'TEXT', 'revision' => 'INTEGER', 'updated_at' => 'TEXT',
        ], ['form_id', 'principal_id', 'filter_id'], ['form_id', 'principal_id', 'filter_id']);
    } elseif ($driver === 'mysql') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS bbf_submission_review (
            form_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            submission_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            tags LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            updated_at VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            updated_by VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (form_id, submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $columns = $pdo->query("SHOW COLUMNS FROM bbf_submission_review")->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('deleted', array_column($columns, 'Field'), true)) {
            try {
                $pdo->exec('ALTER TABLE bbf_submission_review ADD COLUMN deleted TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $migrationError) {
                $columns = $pdo->query("SHOW COLUMNS FROM bbf_submission_review")->fetchAll(PDO::FETCH_ASSOC);
                if (!in_array('deleted', array_column($columns, 'Field'), true)) throw $migrationError;
            }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS bbf_review_filter (
            form_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            principal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            filter_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            criteria LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            updated_at VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (form_id, principal_id, filter_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        bbf_review_mysql_schema($pdo, 'bbf_submission_review', [
            'form_id' => 'varchar', 'submission_id' => 'varchar', 'status' => 'varchar', 'notes' => 'text',
            'tags' => 'longtext', 'revision' => 'bigint', 'updated_at' => 'varchar', 'updated_by' => 'varchar', 'deleted' => 'tinyint',
        ], ['form_id' => 'ascii_bin', 'submission_id' => 'ascii_bin', 'status' => 'ascii_bin',
            'notes' => 'utf8mb4_bin', 'tags' => 'utf8mb4_bin', 'updated_at' => 'ascii_bin', 'updated_by' => 'ascii_bin'],
            ['form_id' => 128, 'submission_id' => 128, 'status' => 16, 'updated_at' => 20, 'updated_by' => 128],
            ['form_id', 'submission_id']);
        bbf_review_mysql_schema($pdo, 'bbf_review_filter', [
            'form_id' => 'varchar', 'principal_id' => 'varchar', 'filter_id' => 'varchar', 'name' => 'varchar',
            'criteria' => 'longtext', 'revision' => 'bigint', 'updated_at' => 'varchar',
        ], ['form_id' => 'ascii_bin', 'principal_id' => 'ascii_bin', 'filter_id' => 'ascii_bin',
            'name' => 'utf8mb4_bin', 'criteria' => 'utf8mb4_bin', 'updated_at' => 'ascii_bin'],
            ['form_id' => 128, 'principal_id' => 128, 'filter_id' => 128, 'name' => 120, 'updated_at' => 20],
            ['form_id', 'principal_id', 'filter_id']);
    } else {
        throw new RuntimeException('Unsupported review database driver.');
    }
    return $pdo;
}

function bbf_review_row(array $row): array {
    $tags = json_decode($row['tags'] ?? '', true, 32, JSON_THROW_ON_ERROR);
    return bbf_review_record(['status' => $row['status'] ?? null, 'notes' => $row['notes'] ?? null,
        'tags' => $tags, 'revision' => isset($row['revision']) ? (int)$row['revision'] : null,
        'updated_at' => $row['updated_at'] ?? null, 'updated_by' => $row['updated_by'] ?? null]);
}

/** Return persisted records keyed by submission ID. Missing IDs deliberately retain the default new state. */
function bbf_review_records(array $config, string $formId, array $submissionIds): array {
    if (bbf_auth_id($formId) === '') throw new InvalidArgumentException('Invalid review form ID.');
    $ids = [];
    foreach ($submissionIds as $id) {
        if (bbf_auth_id($id) === '') throw new InvalidArgumentException('Invalid review submission ID.');
        $ids[$id] = $id;
    }
    if ($ids === []) return [];
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        return bbf_review_file_transaction($config, $formId, false, static function (array $document) use ($ids): array {
            return array_intersect_key($document['records'], $ids);
        });
    }
    $pdo = bbf_review_db($config);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT * FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
        . " AND deleted = 0 AND submission_id IN ($placeholders)");
    $stmt->execute(array_merge([$formId], array_values($ids)));
    $records = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) $records[$row['submission_id']] = bbf_review_row($row);
    return $records;
}

function bbf_review_get(array $config, string $formId, string $submissionId): array {
    $records = bbf_review_records($config, $formId, [$submissionId]);
    return $records[$submissionId] ?? bbf_review_default();
}

/** Optimistic update. A stale expected revision returns the current safe record without writing. */
function bbf_review_update(array $config, string $formId, string $submissionId, string $principalId,
    array $patch, int $expectedRevision): array {
    if (bbf_auth_id($formId) === '' || bbf_auth_id($submissionId) === '' || bbf_auth_id($principalId) === ''
        || $expectedRevision < 0 || ($patch = bbf_review_patch($patch)) === null) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    $config = bbf_effective_storage_config($config, $formId);
    $apply = static function (array $current) use ($patch, $expectedRevision, $principalId): array {
        if ($current['revision'] !== $expectedRevision) return ['ok' => false, 'reason' => 'conflict', 'review' => $current];
        $next = array_replace($current, $patch);
        $next['revision'] = $expectedRevision + 1;
        $next['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $next['updated_by'] = $principalId;
        return ['ok' => true, 'review' => $next];
    };
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        try {
            return bbf_review_file_transaction($config, $formId, true,
                static function (array &$document) use ($submissionId, $apply): array {
                    if (isset($document['deleted'][$submissionId])) return ['ok' => false, 'reason' => 'not_found'];
                    $result = $apply(isset($document['records'][$submissionId])
                        ? bbf_review_record($document['records'][$submissionId]) : bbf_review_default());
                    if ($result['ok']) $document['records'][$submissionId] = $result['review'];
                    return $result;
                });
        } catch (Throwable $error) {
            error_log('BareBonesForms review update: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'storage'];
        }
    }
    $pdo = null;
    try {
        $pdo = bbf_review_db($config);
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql && $expectedRevision === 0) {
            $result = $apply(bbf_review_default());
            $review = $result['review'];
            $write = $pdo->prepare('INSERT INTO bbf_submission_review
                (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE revision = bbf_submission_review.revision');
            $write->execute([$formId, $submissionId, $review['status'], $review['notes'],
                bbf_storage_json($review['tags']), $review['revision'], $review['updated_at'], $review['updated_by']]);
            if ($write->rowCount() === 1) return $result;
            $winner = $pdo->prepare('SELECT * FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
                . ' AND submission_id = ?');
            $winner->execute([$formId, $submissionId]);
            $row = $winner->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['deleted'] === 1) return ['ok' => false, 'reason' => 'not_found'];
            if ($row) return ['ok' => false, 'reason' => 'conflict', 'review' => bbf_review_row($row)];
            throw new RuntimeException('Cannot classify concurrent review creation.');
        }
        if ($mysql) $pdo->beginTransaction(); else $pdo->exec('BEGIN IMMEDIATE');
        $stmt = $pdo->prepare('SELECT * FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
            . ' AND submission_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$formId, $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['deleted'] === 1) { $pdo->rollBack(); return ['ok' => false, 'reason' => 'not_found']; }
        $result = $apply($row ? bbf_review_row($row) : bbf_review_default());
        if (!$result['ok']) { $pdo->rollBack(); return $result; }
        $review = $result['review'];
        $tags = bbf_storage_json($review['tags']);
        if ($row) {
            $write = $pdo->prepare('UPDATE bbf_submission_review SET status=?, notes=?, tags=?, revision=?, updated_at=?, updated_by=? WHERE '
                . bbf_auth_form_sql($pdo) . ' AND submission_id=? AND revision=? AND deleted=0');
            $write->execute([$review['status'], $review['notes'], $tags, $review['revision'], $review['updated_at'],
                $review['updated_by'], $formId, $submissionId, $expectedRevision]);
            if ($write->rowCount() !== 1) throw new RuntimeException('Review revision changed during update.');
        } else {
            $write = $pdo->prepare('INSERT INTO bbf_submission_review
                (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
            $write->execute([$formId, $submissionId, $review['status'], $review['notes'], $tags,
                $review['revision'], $review['updated_at'], $review['updated_by']]);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        if ($expectedRevision === 0) {
            try {
                $winner = bbf_review_get($config, $formId, $submissionId);
                if ($winner['revision'] > 0) return ['ok' => false, 'reason' => 'conflict', 'review' => $winner];
            } catch (Throwable $ignored) {}
        }
        error_log('BareBonesForms review update: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    }
}

function bbf_review_filter_row(array $row): array {
    $criteria = json_decode($row['criteria'] ?? '', true, 32, JSON_THROW_ON_ERROR);
    return ['id' => $row['filter_id']] + bbf_review_filter_record(['name' => $row['name'] ?? null,
        'criteria' => $criteria, 'revision' => isset($row['revision']) ? (int)$row['revision'] : null,
        'updated_at' => $row['updated_at'] ?? null]);
}

function bbf_review_principal_sql(PDO $pdo): string {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'CAST(principal_id AS BINARY) = CAST(? AS BINARY)'
        : 'principal_id COLLATE BINARY = ?';
}

/** Saved filters are scoped to both the authenticated principal and exact form ID. */
function bbf_review_filters(array $config, string $formId, string $principalId): array {
    if (bbf_auth_id($formId) === '' || bbf_auth_id($principalId) === '') {
        throw new InvalidArgumentException('Invalid review filter scope.');
    }
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        return bbf_review_file_transaction($config, $formId, false,
            static function (array $document) use ($principalId): array {
                $stored = $document['filters'][$principalId] ?? [];
                uksort($stored, static fn($a, $b) => strcmp((string)$a, (string)$b));
                $filters = [];
                foreach ($stored as $id => $filter) {
                    $filters[] = ['id' => (string)$id] + bbf_review_filter_record($filter);
                }
                return $filters;
            });
    }
    $pdo = bbf_review_db($config);
    $stmt = $pdo->prepare('SELECT filter_id, name, criteria, revision, updated_at FROM bbf_review_filter WHERE '
        . bbf_auth_form_sql($pdo) . ' AND ' . bbf_review_principal_sql($pdo) . ' ORDER BY filter_id');
    $stmt->execute([$formId, $principalId]);
    $filters = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) $filters[] = bbf_review_filter_row($row);
    return $filters;
}

/** Create or update a saved filter with optimistic revision checking. */
function bbf_review_filter_save(array $config, string $formId, string $principalId, string $filterId,
    string $name, array $criteria, int $expectedRevision): array {
    if (bbf_auth_id($formId) === '' || bbf_auth_id($principalId) === '' || bbf_auth_id($filterId) === ''
        || $expectedRevision < 0 || !bbf_review_valid_text($name, 120) || trim($name) === ''
        || preg_match('/[\x00-\x1f\x7f]/u', $name)
        || ($criteria = bbf_review_filter_criteria($criteria)) === null) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    $name = trim($name);
    $apply = static function (?array $current) use ($filterId, $name, $criteria, $expectedRevision): array {
        if (($current['revision'] ?? 0) !== $expectedRevision) {
            return ['ok' => false, 'reason' => 'conflict', 'filter' => $current === null ? null : ['id' => $filterId] + $current];
        }
        $next = ['name' => $name, 'criteria' => $criteria, 'revision' => $expectedRevision + 1,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        return ['ok' => true, 'filter' => ['id' => $filterId] + $next, 'stored' => $next];
    };
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        try {
            $result = bbf_review_file_transaction($config, $formId, true,
                static function (array &$document) use ($principalId, $filterId, $apply): array {
                    $current = isset($document['filters'][$principalId][$filterId])
                        ? bbf_review_filter_record($document['filters'][$principalId][$filterId]) : null;
                    $result = $apply($current);
                    if ($result['ok']) $document['filters'][$principalId][$filterId] = $result['stored'];
                    unset($result['stored']);
                    return $result;
                });
            return $result;
        } catch (Throwable $error) {
            error_log('BareBonesForms review filter save: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'storage'];
        }
    }
    $pdo = null;
    try {
        $pdo = bbf_review_db($config);
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql && $expectedRevision === 0) {
            $result = $apply(null); $stored = $result['stored'];
            $write = $pdo->prepare('INSERT INTO bbf_review_filter
                (form_id, principal_id, filter_id, name, criteria, revision, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE revision = bbf_review_filter.revision');
            $write->execute([$formId, $principalId, $filterId, $stored['name'], bbf_storage_json($stored['criteria']),
                $stored['revision'], $stored['updated_at']]);
            unset($result['stored']);
            if ($write->rowCount() === 1) return $result;
            $winner = $pdo->prepare('SELECT filter_id, name, criteria, revision, updated_at FROM bbf_review_filter WHERE '
                . bbf_auth_form_sql($pdo) . ' AND ' . bbf_review_principal_sql($pdo) . ' AND filter_id = ?');
            $winner->execute([$formId, $principalId, $filterId]);
            $row = $winner->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['ok' => false, 'reason' => 'conflict', 'filter' => bbf_review_filter_row($row)];
            throw new RuntimeException('Cannot classify concurrent review filter creation.');
        }
        if ($mysql) $pdo->beginTransaction(); else $pdo->exec('BEGIN IMMEDIATE');
        $stmt = $pdo->prepare('SELECT filter_id, name, criteria, revision, updated_at FROM bbf_review_filter WHERE '
            . bbf_auth_form_sql($pdo) . ' AND ' . bbf_review_principal_sql($pdo) . ' AND filter_id = ?'
            . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$formId, $principalId, $filterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row ? bbf_review_filter_record(bbf_review_filter_row($row)) : null;
        $result = $apply($current);
        if (!$result['ok']) { $pdo->rollBack(); return $result; }
        $stored = $result['stored'];
        if ($row) {
            $write = $pdo->prepare('UPDATE bbf_review_filter SET name=?, criteria=?, revision=?, updated_at=? WHERE '
                . bbf_auth_form_sql($pdo) . ' AND ' . bbf_review_principal_sql($pdo) . ' AND filter_id=? AND revision=?');
            $write->execute([$stored['name'], bbf_storage_json($stored['criteria']), $stored['revision'], $stored['updated_at'],
                $formId, $principalId, $filterId, $expectedRevision]);
            if ($write->rowCount() !== 1) throw new RuntimeException('Review filter revision changed during update.');
        } else {
            $write = $pdo->prepare('INSERT INTO bbf_review_filter
                (form_id, principal_id, filter_id, name, criteria, revision, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $write->execute([$formId, $principalId, $filterId, $stored['name'], bbf_storage_json($stored['criteria']),
                $stored['revision'], $stored['updated_at']]);
        }
        $pdo->commit(); unset($result['stored']); return $result;
    } catch (Throwable $error) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('BareBonesForms review filter save: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    }
}

function bbf_review_filter_delete(array $config, string $formId, string $principalId, string $filterId,
    int $expectedRevision): array {
    if (bbf_auth_id($formId) === '' || bbf_auth_id($principalId) === '' || bbf_auth_id($filterId) === ''
        || $expectedRevision < 1) return ['ok' => false, 'reason' => 'invalid'];
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        try {
            return bbf_review_file_transaction($config, $formId, true,
                static function (array &$document) use ($principalId, $filterId, $expectedRevision): array {
                    if (!isset($document['filters'][$principalId][$filterId])) return ['ok' => false, 'reason' => 'not_found'];
                    $current = bbf_review_filter_record($document['filters'][$principalId][$filterId]);
                    if ($current['revision'] !== $expectedRevision) {
                        return ['ok' => false, 'reason' => 'conflict', 'filter' => ['id' => $filterId] + $current];
                    }
                    unset($document['filters'][$principalId][$filterId]);
                    if ($document['filters'][$principalId] === []) unset($document['filters'][$principalId]);
                    return ['ok' => true, 'deleted' => 1];
                });
        } catch (Throwable $error) {
            error_log('BareBonesForms review filter delete: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'storage'];
        }
    }
    $pdo = null;
    try {
        $pdo = bbf_review_db($config); $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) $pdo->beginTransaction(); else $pdo->exec('BEGIN IMMEDIATE');
        $stmt = $pdo->prepare('SELECT filter_id, name, criteria, revision, updated_at FROM bbf_review_filter WHERE '
            . bbf_auth_form_sql($pdo) . ' AND ' . bbf_review_principal_sql($pdo) . ' AND filter_id = ?'
            . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$formId, $principalId, $filterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); return ['ok' => false, 'reason' => 'not_found']; }
        $current = bbf_review_filter_row($row);
        if ($current['revision'] !== $expectedRevision) {
            $pdo->rollBack(); return ['ok' => false, 'reason' => 'conflict', 'filter' => $current];
        }
        $delete = $pdo->prepare('DELETE FROM bbf_review_filter WHERE ' . bbf_auth_form_sql($pdo) . ' AND '
            . bbf_review_principal_sql($pdo) . ' AND filter_id=? AND revision=?');
        $delete->execute([$formId, $principalId, $filterId, $expectedRevision]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Review filter revision changed during delete.');
        $pdo->commit(); return ['ok' => true, 'deleted' => 1];
    } catch (Throwable $error) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('BareBonesForms review filter delete: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    }
}

function bbf_review_delete_records(array $config, string $formId, array $submissionIds): array {
    if (bbf_auth_id($formId) === '') return ['ok' => false, 'reason' => 'invalid'];
    $ids = [];
    foreach ($submissionIds as $id) {
        if (bbf_auth_id($id) === '') return ['ok' => false, 'reason' => 'invalid'];
        $ids[$id] = $id;
    }
    if ($ids === []) return ['ok' => true, 'deleted' => 0];
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        try {
            return bbf_review_file_transaction($config, $formId, true,
                static function (array &$document) use ($ids): array {
                    $deleted = 0;
                    foreach ($ids as $id) {
                        if (isset($document['records'][$id])) { unset($document['records'][$id]); $deleted++; }
                        $document['deleted'][$id] = true;
                    }
                    return ['ok' => true, 'deleted' => $deleted];
                });
        } catch (Throwable $error) {
            error_log('BareBonesForms review delete: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'storage'];
        }
    }
    $pdo = null;
    try {
        $pdo = bbf_review_db($config); $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $ids = array_values($ids); sort($ids, SORT_STRING);
        $deleted = 0; $now = gmdate('Y-m-d\TH:i:s\Z'); $emptyTags = bbf_storage_json([]);
        if ($mysql) {
            $pdo->beginTransaction();
            $tombstone = $pdo->prepare('INSERT INTO bbf_submission_review
                (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    status=IF(deleted=0,VALUES(status),status), notes=IF(deleted=0,VALUES(notes),notes),
                    tags=IF(deleted=0,VALUES(tags),tags), revision=IF(deleted=0,0,revision),
                    updated_at=IF(deleted=0,VALUES(updated_at),updated_at),
                    updated_by=IF(deleted=0,VALUES(updated_by),updated_by), deleted=1');
            foreach ($ids as $id) {
                $tombstone->execute([$formId, $id, 'new', '', $emptyTags, $now, 'system']);
                if ($tombstone->rowCount() === 2) $deleted++;
            }
            $pdo->commit(); return ['ok' => true, 'deleted' => $deleted];
        }
        $pdo->exec('BEGIN IMMEDIATE');
        $select = $pdo->prepare('SELECT deleted FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
            . ' AND submission_id=?');
        $update = $pdo->prepare('UPDATE bbf_submission_review SET status=?, notes=?, tags=?, revision=0,
            updated_at=?, updated_by=?, deleted=1 WHERE ' . bbf_auth_form_sql($pdo) . ' AND submission_id=?');
        $insert = $pdo->prepare('INSERT INTO bbf_submission_review
            (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1)');
        foreach ($ids as $id) {
            $select->execute([$formId, $id]); $row = $select->fetch(PDO::FETCH_ASSOC); $select->closeCursor();
            if ($row) {
                if ((int)$row['deleted'] === 0) $deleted++;
                $update->execute(['new', '', $emptyTags, $now, 'system', $formId, $id]);
            } else {
                $insert->execute([$formId, $id, 'new', '', $emptyTags, $now, 'system']);
            }
        }
        $pdo->commit(); return ['ok' => true, 'deleted' => $deleted];
    } catch (Throwable $error) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('BareBonesForms review delete: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    }
}

/** Delete only the exact active review revisions captured in an archive; concurrent changes survive. */
function bbf_review_delete_records_if(array $config, string $formId, array $expected,
    ?callable $deletePrimary = null): array {
    if (bbf_auth_id($formId) === '' || $expected === []) return ['ok' => false, 'reason' => 'invalid'];
    $normalized = [];
    foreach ($expected as $id => $record) {
        if (!is_string($id) || bbf_auth_id($id) === '' || ($record !== null && !is_array($record))) {
            return ['ok' => false, 'reason' => 'invalid'];
        }
        try { $normalized[$id] = $record === null ? null : bbf_review_record($record); }
        catch (Throwable $error) { return ['ok' => false, 'reason' => 'invalid']; }
    }
    ksort($normalized, SORT_STRING);
    $config = bbf_effective_storage_config($config, $formId);
    if (in_array($config['storage'], ['file', 'csv'], true)) {
        try {
            return bbf_review_file_transaction($config, $formId, true,
                static function (array &$document) use ($normalized): array {
                    $deleted = 0;
                    foreach ($normalized as $id => $expectedRecord) {
                        $current = array_key_exists($id, $document['records'])
                            ? bbf_review_record($document['records'][$id]) : null;
                        if ($current !== $expectedRecord) return ['ok' => false, 'reason' => 'conflict'];
                    }
                    foreach ($normalized as $id => $expectedRecord) {
                        if ($expectedRecord !== null) { unset($document['records'][$id]); $deleted++; }
                        $document['deleted'][$id] = true;
                    }
                    return ['ok' => true, 'deleted' => $deleted];
                }, $deletePrimary);
        } catch (Throwable $error) {
            error_log('BareBonesForms conditional review delete: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'storage'];
        }
    }
    $pdo = null;
    try {
        $pdo = bbf_review_db($config);
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) $pdo->beginTransaction(); else $pdo->exec('BEGIN IMMEDIATE');
        $select = $pdo->prepare('SELECT * FROM bbf_submission_review WHERE ' . bbf_auth_form_sql($pdo)
            . ' AND submission_id=?' . ($mysql ? ' FOR UPDATE' : ''));
        $active = [];
        foreach ($normalized as $id => $expectedRecord) {
            $select->execute([$formId, $id]);
            $row = $select->fetch(PDO::FETCH_ASSOC); $select->closeCursor();
            $current = $row && (int)$row['deleted'] === 0 ? bbf_review_row($row) : null;
            if ($current !== $expectedRecord) { $pdo->rollBack(); return ['ok' => false, 'reason' => 'conflict']; }
            if ($current !== null) $active[$id] = true;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z'); $emptyTags = bbf_storage_json([]);
        if ($mysql) {
            $write = $pdo->prepare('INSERT INTO bbf_submission_review
                (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1)
                ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes), tags=VALUES(tags),
                    revision=0, updated_at=VALUES(updated_at), updated_by=VALUES(updated_by), deleted=1');
        } else {
            $write = $pdo->prepare('INSERT INTO bbf_submission_review
                (form_id, submission_id, status, notes, tags, revision, updated_at, updated_by, deleted)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1)
                ON CONFLICT(form_id, submission_id) DO UPDATE SET status=excluded.status, notes=excluded.notes,
                    tags=excluded.tags, revision=0, updated_at=excluded.updated_at, updated_by=excluded.updated_by, deleted=1');
        }
        foreach ($normalized as $id => $expectedRecord) {
            $write->execute([$formId, $id, 'new', '', $emptyTags, $now, 'system']);
        }
        $primary = null;
        if ($deletePrimary !== null) {
            try { $primary = $deletePrimary($pdo); }
            catch (Throwable $error) { $primary = ['ok' => false, 'deleted' => false, 'reason' => 'primary']; }
            if (!is_array($primary) || ($primary['ok'] ?? false) !== true) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => $primary['reason'] ?? 'primary',
                    'primary' => is_array($primary) ? $primary : null];
            }
        }
        try { $pdo->commit(); }
        catch (Throwable $error) {
            error_log('BareBonesForms conditional review commit: ' . $error->getMessage());
            return ['ok' => false, 'reason' => 'ambiguous', 'primary' => [
                'ok' => false, 'deleted' => null, 'ambiguous' => true,
            ]];
        }
        $result = ['ok' => true, 'deleted' => count($active)];
        if ($primary !== null) $result['primary'] = $primary;
        return $result;
    } catch (Throwable $error) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('BareBonesForms conditional review delete: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'storage'];
    }
}
