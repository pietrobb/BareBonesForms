<?php
/** Shared bounded readers. No configuration loading or authorization decisions. */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_storage.php';

/** Literal, case-insensitive search of values only (never JSON keys or SQL patterns). */
function bbf_read_search(array $data, string $q): bool {
    if ($q === '') return true;
    foreach ($data as $value) {
        $parts = [];
        $flatten = static function ($v) use (&$flatten, &$parts): void {
            if (is_array($v)) { foreach ($v as $item) $flatten($item); }
            else $parts[] = (string)$v;
        };
        $flatten($value);
        $flatten = null; // Clear the captured reference; unset leaves the closure cycle alive.
        if (mb_stripos(implode(' ', $parts), $q) !== false) return true;
    }
    return false;
}

/** Compare stored calendar timestamps, accepting both ISO T and SQL space separators.
 * As before, date filters use the stored calendar date, not UTC conversion of offsets.
 */
function bbf_read_date(string $value): string {
    return str_replace('T', ' ', substr($value, 0, 19));
}

function bbf_read_bounds(?string $from, ?string $to): array {
    return [$from ? bbf_read_date($from) : null,
        $to ? date('Y-m-d', strtotime($to . ' +1 day')) : null];
}

function bbf_read_matches(array $sub, array $bounds, ?string $q): bool {
    $submitted = bbf_read_date((string)($sub['meta']['submitted'] ?? ''));
    return (!$bounds[0] || strcmp($submitted, $bounds[0]) >= 0)
        && (!$bounds[1] || strcmp($submitted, $bounds[1]) < 0)
        && ($q === null || bbf_read_search($sub['data'] ?? [], $q));
}

/** Lock the stable sidecar BEFORE testing/opening the target, through target close.
 * An absent parent cannot contain a target; readers never create storage directories.
 */
function bbf_read_locked(string $path, callable $read) {
    if (!is_dir(dirname($path))) return null;
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open submission read lock.');
    try {
        if (!flock($lock, LOCK_SH)) throw new RuntimeException('Cannot lock submission for reading.');
        clearstatcache(true, $path);
        return $read($path);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_read_file(string $path, string $formId): ?array {
    return bbf_read_locked($path, static function ($path) use ($formId): ?array {
        if (!file_exists($path)) return null;
        $fp = @fopen($path, 'rb');
        if (!$fp) throw new RuntimeException('Cannot open submission.');
        try {
            $raw = stream_get_contents($fp);
            if ($raw === false || !feof($fp)) throw new RuntimeException('Cannot read submission.');
        } finally { fclose($fp); }
        $sub = json_decode($raw, true);
        // Malformed stored records are omitted consistently from page/count/export/detail.
        if (!is_array($sub) || !is_array($sub['data'] ?? null) || !is_array($sub['meta'] ?? null)
            || ($sub['form'] ?? null) !== $formId || ($sub['id'] ?? null) !== basename($path, '.json')) return null;
        return $sub;
    });
}

/** O(N) filename/mtime metadata, O(one record) decoded payload. Equal mtimes retain
 * the original glob order. Each file is stable while read, not a directory-wide snapshot.
 */
function bbf_read_files(string $formId, string $dir, ?string $from = null, ?string $to = null, ?string $q = null, bool $strict = false): Generator {
    $paths = glob("$dir/$formId/bbf_*.json");
    if ($paths === false) throw new RuntimeException('Cannot enumerate submissions.');
    $index = [];
    foreach ($paths as $order => $path) {
        $mtime = bbf_read_locked($path, static function ($path) {
            if (!file_exists($path)) return null;
            $mtime = filemtime($path);
            if ($mtime === false) throw new RuntimeException('Cannot stat submission.');
            return $mtime;
        });
        if ($mtime !== null) $index[] = [$path, $mtime, $order];
    }
    unset($paths);
    usort($index, static fn($a, $b) => ($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));
    $bounds = bbf_read_bounds($from, $to);
    foreach ($index as [$path]) {
        $sub = bbf_read_file($path, $formId);
        if ($sub === null && $strict) throw new RuntimeException('Invalid stored submission.');
        if ($sub !== null && bbf_read_matches($sub, $bounds, $q)) yield $sub;
    }
}

function bbf_read_csv_record_syntax_valid($fp, int $start, int $end): bool {
    if ($end < $start || fseek($fp, $start) !== 0) return false;
    $raw = stream_get_contents($fp, $end - $start);
    if ($raw === false || strlen($raw) !== $end - $start || fseek($fp, $end) !== 0) return false;
    $quoted = false;
    $length = strlen($raw);
    for ($i = 0; $i < $length; $i++) {
        if ($raw[$i] !== '"') continue;
        if ($quoted && $i + 1 < $length && $raw[$i + 1] === '"') {
            $i++;
            continue;
        }
        $quoted = !$quoted;
    }
    return !$quoted;
}

function bbf_read_csv_record(array $headers, array $row, string $formId): ?array {
    if ($row === [null] || count($row) > count($headers)) return null;
    $mapped = array_combine($headers, array_pad($row, count($headers), ''));
    if (($mapped['_id'] ?? '') === '') return null;
    $data = array_diff_key($mapped, array_flip(['_id', '_submitted', '_ip', '_user_agent',
        '__bbf:definition_version', '__bbf:form_definition', '__bbf:csv_escaped_fields', '__bbf:structured_fields']));
    $escapedFields = null;
    if (($mapped['__bbf:csv_escaped_fields'] ?? '') !== '') {
        $decoded = json_decode($mapped['__bbf:csv_escaped_fields'], true);
        if (is_array($decoded) && array_is_list($decoded)
            && count(array_filter($decoded, 'is_string')) === count($decoded)) $escapedFields = $decoded;
    }
    $structuredFields = [];
    if (($mapped['__bbf:structured_fields'] ?? '') !== '') {
        $decoded = json_decode($mapped['__bbf:structured_fields'], true);
        if (!is_array($decoded) || !array_is_list($decoded)
            || count(array_filter($decoded, 'is_string')) !== count($decoded)) return null;
        $structuredFields = array_fill_keys($decoded, true);
    }
    foreach ($data as $name => &$value) {
        if (isset($value[1]) && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)
            && ($escapedFields === null || in_array($name, $escapedFields, true))) $value = substr($value, 1);
        if (isset($structuredFields[$name])) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || !array_is_list($decoded)) return null;
            $value = $decoded;
        }
    }
    unset($value);
    $meta = ['submitted' => $mapped['_submitted'] ?? '', 'ip' => $mapped['_ip'] ?? '',
        'user_agent' => $mapped['_user_agent'] ?? ''];
    if (preg_match('/\Av1-[a-f0-9]{64}\z/D', $mapped['__bbf:definition_version'] ?? '')) {
        $meta['definition_version'] = $mapped['__bbf:definition_version'];
    }
    if (($mapped['__bbf:form_definition'] ?? '') !== '') {
        $snapshot = json_decode($mapped['__bbf:form_definition'], true);
        if (is_array($snapshot)) $meta['form_definition'] = $snapshot;
    }
    return ['id' => $mapped['_id'], 'form' => $formId, 'data' => $data, 'meta' => $meta];
}

/** Logical CSV records, including multiline fields. Newest-first iteration uses an
 * on-disk fixed-width offset index, never reverse physical lines or all-record arrays.
 * The sidecar is held across BOTH passes, including target/index close and early exit.
 */
function bbf_read_csv(string $formId, string $dir, ?string $from = null, ?string $to = null, ?string $q = null, ?string $id = null, bool $strict = false): Generator {
    $path = "$dir/$formId.csv";
    if (!is_dir(dirname($path))) return;
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open CSV read lock.');
    $fp = $index = null;
    try {
        if (!flock($lock, LOCK_SH)) throw new RuntimeException('Cannot lock CSV for reading.');
        clearstatcache(true, $path);
        if (!file_exists($path)) return;
        $fp = @fopen($path, 'rb');
        if (!$fp) throw new RuntimeException('Cannot open CSV submissions.');
        $headers = fgetcsv($fp, 0, ',', '"', '');
        if ($headers === false) {
            if (!feof($fp)) throw new RuntimeException('Cannot read CSV headers.');
            return;
        }
        if (!in_array('_id', $headers, true) || !in_array('_submitted', $headers, true)
            || count(array_unique($headers)) !== count($headers)) throw new RuntimeException('Invalid CSV headers.');
        $bounds = bbf_read_bounds($from, $to);
        if ($id === null) {
            $index = tmpfile();
            if ($index === false) throw new RuntimeException('Cannot create CSV read index.');
        }
        $count = 0;
        while (true) {
            $position = ftell($fp);
            if ($position === false) throw new RuntimeException('Cannot locate CSV record.');
            $row = fgetcsv($fp, 0, ',', '"', '');
            if ($row === false) break;
            $end = ftell($fp);
            if ($end === false || ($strict && !bbf_read_csv_record_syntax_valid($fp, $position, $end))) {
                throw new RuntimeException('Invalid stored CSV submission.');
            }
            $sub = bbf_read_csv_record($headers, $row, $formId);
            if ($sub === null && $strict) throw new RuntimeException('Invalid stored CSV submission.');
            if ($sub === null || ($id !== null && $sub['id'] !== $id) || !bbf_read_matches($sub, $bounds, $q)) continue;
            if ($id !== null) { yield $sub; return; }
            if (!bbf_storage_write_all($index, sprintf('%020d', $position))) throw new RuntimeException('Cannot write CSV read index.');
            $count++;
        }
        if (!feof($fp)) throw new RuntimeException('Cannot finish reading CSV.');
        if ($id !== null) return;
        if (!fflush($index)) throw new RuntimeException('Cannot flush CSV read index.');
        for ($n = $count - 1; $n >= 0; $n--) {
            // Each index entry is exactly twenty decimal bytes.
            if (fseek($index, $n * 20) !== 0) throw new RuntimeException('Cannot seek CSV read index.');
            $entry = fread($index, 20);
            if ($entry === false || strlen($entry) !== 20 || !ctype_digit($entry)) throw new RuntimeException('Cannot read CSV index.');
            if (fseek($fp, (int)substr($entry, 0, 20)) !== 0) throw new RuntimeException('Cannot seek CSV record.');
            $row = fgetcsv($fp, 0, ',', '"', '');
            if ($row === false) throw new RuntimeException('Cannot reread CSV record.');
            $sub = bbf_read_csv_record($headers, $row, $formId);
            if ($sub === null) throw new RuntimeException('CSV record changed during read.');
            yield $sub;
        }
    } finally {
        if (is_resource($index)) fclose($index);
        if (is_resource($fp)) fclose($fp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Count every match but retain only the requested page payload. */
function bbf_read_page(iterable $rows, int $limit, int $offset, ?int &$total): array {
    $total = 0;
    $page = [];
    foreach ($rows as $row) {
        if ($total >= $offset && count($page) < $limit) $page[] = $row;
        $total++;
    }
    return $page;
}

function bbf_read_slice(iterable $rows, int $limit, int $offset): Generator {
    $seen = $sent = 0;
    foreach ($rows as $row) {
        if ($seen++ < $offset) continue;
        if ($sent >= $limit) break;
        yield $row;
        if (++$sent >= $limit) break;
    }
}

function bbf_read_db_connect(array $config): ?PDO {
    if (($config['storage'] ?? '') === 'mysql') {
        $db = $config['mysql'];
        return new PDO("mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}", $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    $path = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
    if (!file_exists($path)) return null;
    return new (class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : 'PDO')("sqlite:$path", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Same shape policy as file records: objects/lists (including empty) are valid;
 * malformed JSON, scalar JSON and SQL NULL are not empty records. No IO exceptions
 * are caught here: only invalid stored values may be omitted.
 */
function bbf_read_db_json($json): ?array {
    if (!is_string($json)) return null;
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function bbf_read_db_row(array $row): ?array {
    $data = bbf_read_db_json($row['data']);
    $meta = bbf_read_db_json($row['meta']);
    if ($data === null || $meta === null) return null;
    return ['id' => $row['id'], 'form' => $row['form_id'], 'data' => $data, 'meta' => $meta];
}

/** SQLite can run the exact PHP decoder in WHERE, before COUNT/LIMIT/OFFSET.
 * No dependency on SQLite JSON1/version-specific JSON semantics. Register once per
 * live connection without retaining closed PDOs. MySQL/MariaDB cannot register this
 * callback and differ in JSON validity/depth rules, so use bounded streaming there.
 */
function bbf_read_db_sql_eligibility(PDO $pdo): bool {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return false;
    static $registered = null; $registered ??= new WeakMap();
    if (!isset($registered[$pdo])) {
        if (!$pdo->{method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction'}('bbf_read_valid_record', static fn($data, $meta) =>
            (int)(bbf_read_db_json($data) !== null && bbf_read_db_json($meta) !== null), 2)) {
            throw new RuntimeException('Cannot register submission eligibility predicate.');
        }
        $registered[$pdo] = true;
    }
    return true;
}

function bbf_read_db_where(PDO $pdo, string $formId, ?string $from, ?string $to, ?string $id = null, bool $strict = false): array {
    $where = [bbf_auth_form_sql($pdo)];
    $params = [$formId];
    if (!$strict && bbf_read_db_sql_eligibility($pdo)) $where[] = 'bbf_read_valid_record(data, meta) = 1';
    if ($id !== null) { $where[] = 'id = ?'; $params[] = $id; }
    // Identical wall-clock predicate for SQLite ISO timestamps and MySQL DATETIME.
    foreach (bbf_read_bounds($from, $to) as $i => $bound) {
        if ($bound !== null) {
            $where[] = "REPLACE(SUBSTR(created_at, 1, 19), 'T', ' ') " . ($i === 0 ? '>=' : '<') . ' ?';
            $params[] = $bound;
        }
    }
    return [implode(' AND ', $where), $params];
}

/** MySQL must not buffer the entire result in PDO before PHP begins iteration.
 * Only push pagination into SQL when both eligibility and search are SQL predicates.
 * Otherwise skip/count valid matches while streaming, retaining one decoded record.
 */
function bbf_read_db(PDO $pdo, string $formId, ?string $from = null, ?string $to = null, ?string $q = null, ?string $id = null, ?int $limit = null, int $offset = 0, bool $strict = false): Generator {
    [$where, $params] = bbf_read_db_where($pdo, $formId, $from, $to, $id, $strict);
    $sql = "SELECT * FROM bbf_submissions WHERE $where ORDER BY created_at DESC";
    $sqlPage = bbf_read_db_sql_eligibility($pdo) && ($q === null || $q === '');
    if ($sqlPage && $limit !== null) { $sql .= ' LIMIT ? OFFSET ?'; $params[] = $limit; $params[] = $offset; }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $buffered = $mysql ? $pdo->getAttribute($bufferAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) : null;
    $stmt = null;
    $seen = $sent = 0;
    try {
        if ($mysql) $pdo->setAttribute($bufferAttribute, false);
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sub = bbf_read_db_row($row);
            if ($sub === null && $strict) throw new RuntimeException('Invalid stored database submission.');
            if ($sub === null || ($q !== null && !bbf_read_search($sub['data'], $q))) continue;
            if (!$sqlPage && $limit !== null) {
                if ($seen++ < $offset) continue;
                if ($sent >= $limit) break;
            }
            yield $sub;
            if (!$sqlPage && $limit !== null && ++$sent >= $limit) break;
        }
    } finally {
        if ($stmt) $stmt->closeCursor();
        if ($mysql) $pdo->setAttribute($bufferAttribute, $buffered);
    }
}

function bbf_read_db_page(PDO $pdo, string $formId, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    if (($q !== null && $q !== '') || !bbf_read_db_sql_eligibility($pdo)) {
        return bbf_read_page(bbf_read_db($pdo, $formId, $from, $to, $q), $limit, $offset, $total);
    }
    [$where, $params] = bbf_read_db_where($pdo, $formId, $from, $to);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bbf_submissions WHERE $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    return $limit <= 0 ? [] : iterator_to_array(bbf_read_db($pdo, $formId, $from, $to, null, null, $limit, $offset), false);
}

/** Config is resolved by the caller, preserving per-form routing and byte-exact scope. */
function bbf_read_export(string $formId, array $config, int $limit, int $offset, ?string $from, ?string $to, ?string $q = null, bool $strict = false): Generator {
    $storage = $config['storage'] ?? 'file';
    $dir = $config['submissions_dir'] ?? __DIR__ . '/submissions';
    if ($storage === 'file') $rows = bbf_read_files($formId, $dir, $from, $to, $q, $strict);
    elseif ($storage === 'csv') $rows = bbf_read_csv($formId, $dir, $from, $to, $q, null, $strict);
    else {
        $pdo = bbf_read_db_connect($config);
        if (!$pdo) return;
        $rows = bbf_read_db($pdo, $formId, $from, $to, $q, null, null, 0, $strict);
    }
    yield from bbf_read_slice($rows, $limit, $offset);
}
