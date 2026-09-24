<?php
/**
 * Submit transactions (spec: docs/SUBMIT-TRANSACTIONS.md). Idempotent submit keyed by a
 * client key: intents under <submissions_dir>/.submit/<form>/, liveness by flock, never age.
 * No lock of this module is ever held across mail(), SMTP, webhooks or child processes.
 */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_functions.php';
require_once __DIR__ . '/bbf_auth.php';
require_once __DIR__ . '/bbf_read.php';
require_once __DIR__ . '/bbf_versions.php';
require_once __DIR__ . '/bbf_context.php';

const BBF_TX_STATES = ['open', 'committed', 'complete', 'aborted'];

// ─── Test hook, deadline, notices ────────────────────────────────

/** Tests-only fault injection: a hook returning false injects a failure at $point. */
function bbf_tx_hook(string $point): bool {
    $hook = $GLOBALS['_bbf_tx_hook'] ?? null;
    return !is_callable($hook) || $hook($point) !== false;
}

function bbf_tx_deadline_start(array $config): void {
    $GLOBALS['_bbf_tx_deadline'] = microtime(true) + max(1, (int)($config['submit_transaction_timeout'] ?? 30));
    // File and CSV storage locks switch to bounded LOCK_NB waits while this is set.
    $GLOBALS['_bbf_storage_deadline'] = $GLOBALS['_bbf_tx_deadline'];
}

function bbf_tx_deadline_clear(): void {
    unset($GLOBALS['_bbf_tx_deadline'], $GLOBALS['_bbf_storage_deadline']);
}

function bbf_tx_remaining(): float {
    $deadline = $GLOBALS['_bbf_tx_deadline'] ?? null;
    return $deadline === null ? 30.0 : max(0.0, $deadline - microtime(true));
}

/** Owner notifications use mail(); queue them until every lock of this module is released. */
function bbf_tx_notice(string $formId, string $context, string $detail): void {
    $GLOBALS['_bbf_tx_notices'][] = [$formId, $context, $detail];
}

function bbf_tx_flush_notices(array $config): void {
    $notices = $GLOBALS['_bbf_tx_notices'] ?? [];
    $GLOBALS['_bbf_tx_notices'] = [];
    foreach ($notices as [$formId, $context, $detail]) {
        error_log("BareBonesForms submit transaction ($formId): $context — $detail");
        bbfNotifyError($formId, $context, $detail, $config);
    }
}

// ─── Paths, secret, fingerprints ─────────────────────────────────

function bbf_tx_valid_key($key): bool {
    return is_string($key) && preg_match('/\A[a-f0-9]{32}\z/D', $key) === 1;
}

function bbf_tx_root(array $config): string {
    return rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\') . '/.submit';
}

function bbf_tx_form_dir(array $config, string $formId, bool $create): ?string {
    $dir = bbf_tx_root($config) . '/' . $formId;
    if (is_dir($dir)) return $dir;
    if (!$create) return null;
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create submit intent directory.');
    return $dir;
}

function bbf_tx_paths(string $dir, string $k): array {
    return ['lock' => "$dir/$k.lock", 'state' => "$dir/$k.json", 'deliver' => "$dir/$k.deliver", 'dir' => $dir, 'k' => $k];
}

function bbf_tx_secret(array $config): string {
    $root = bbf_tx_root($config);
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Cannot create submit directory.');
    $path = "$root/.secret";
    if (!is_file($path)) {
        $fp = @fopen($path, 'xb');
        if ($fp) {
            $ok = bbf_storage_write_all($fp, bin2hex(random_bytes(32))) && fflush($fp);
            fclose($fp);
            @chmod($path, 0600);
            if (!$ok) { @unlink($path); throw new RuntimeException('Cannot write submit secret.'); }
        }
    }
    for ($i = 0; $i < 20; $i++) {
        $secret = @file_get_contents($path);
        if (is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/D', $secret)) return $secret;
        usleep(10000); // a concurrent creator is still writing
    }
    throw new RuntimeException('Submit secret is unreadable.');
}

function bbf_tx_canonical($value) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return array_map('bbf_tx_canonical', $value);
}

/** P: HMAC over the raw request body without _bbf_* fields and the honeypot. Definition-independent. */
function bbf_tx_payload_fingerprint(array $config, string $formId, array $input): string {
    $honeypot = (string)($config['honeypot_field'] ?? '');
    foreach (array_keys($input) as $name) {
        if (str_starts_with((string)$name, '_bbf_') || ($honeypot !== '' && (string)$name === $honeypot)) unset($input[$name]);
    }
    $canonical = json_encode(bbf_tx_canonical(['form' => $formId, 'body' => $input]),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash_hmac('sha256', $canonical, bbf_tx_secret($config));
}

function bbf_tx_storage_fingerprint(array $storeConfig): string {
    $backend = (string)$storeConfig['storage'];
    if ($backend === 'mysql') {
        $db = (array)($storeConfig['mysql'] ?? []);
        $location = hash('sha256', implode("\0", [(string)($db['host'] ?? ''), (string)($db['port'] ?? ''),
            (string)($db['database'] ?? ''), 'bbf_submissions']));
    } else {
        $dir = (string)($storeConfig['submissions_dir'] ?? __DIR__ . '/submissions');
        $path = $backend === 'sqlite' ? (string)($storeConfig['sqlite']['path'] ?? "$dir/bbf.sqlite") : $dir;
        $real = realpath($path);
        $location = str_replace('\\', '/', $real !== false ? $real : $path);
    }
    return $backend . ':' . $location;
}

/** Rebuild the backend configuration the intent was created with. */
function bbf_tx_store_config(array $config, string $formId, array $state): array {
    return bbf_effective_storage_config($config, $formId, ['storage' => (string)($state['backend'] ?? '')]);
}

// ─── Intent locks and state files ────────────────────────────────

/** Run $fn under the form's leaf lock. Never held across a backend, network call or child process. */
function bbf_tx_leaf(string $dir, callable $fn) {
    $leaf = @fopen("$dir/.lock", 'c');
    if (!$leaf) throw new RuntimeException('Cannot open submit leaf lock.');
    try {
        if (!flock($leaf, LOCK_EX)) throw new RuntimeException('Cannot lock submit leaf lock.');
        return $fn();
    } finally {
        flock($leaf, LOCK_UN);
        fclose($leaf);
    }
}

/** Create the intent and its first state. Returns ['ok' => bool, 'reason' => 'exists'|'state', 'h' => handle]. */
function bbf_tx_create(array $config, string $formId, string $k, array $state): array {
    $paths = bbf_tx_paths(bbf_tx_form_dir($config, $formId, true), $k);
    return bbf_tx_leaf($paths['dir'], static function () use ($paths, $state): array {
        $fp = @fopen($paths['lock'], 'xb');
        if (!$fp) {
            clearstatcache(true, $paths['lock']);
            if (file_exists($paths['lock'])) return ['ok' => false, 'reason' => 'exists'];
            throw new RuntimeException('Cannot create submit intent lock.');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new LogicException('A freshly created intent lock is contended.');
        }
        $h = $paths + ['fp' => $fp, 'bytes' => null];
        bbf_tx_hook('intent_created');
        if (!bbf_tx_write_state($h, $state)) {
            // No state was published: the intent never existed.
            @unlink($paths['state']);
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($paths['lock']);
            return ['ok' => false, 'reason' => 'state'];
        }
        return ['ok' => true, 'h' => $h];
    });
}

/** Take over an existing intent with LOCK_NB. Returns ['status' => 'none'|'busy'|'taken', 'h' => handle]. */
function bbf_tx_take(array $config, string $formId, string $k): array {
    $dir = bbf_tx_form_dir($config, $formId, false);
    if ($dir === null) return ['status' => 'none'];
    $paths = bbf_tx_paths($dir, $k);
    clearstatcache(true, $paths['lock']);
    if (!file_exists($paths['lock'])) return ['status' => 'none'];
    return bbf_tx_leaf($dir, static function () use ($paths): array {
        clearstatcache(true, $paths['lock']);
        if (!file_exists($paths['lock'])) return ['status' => 'none'];
        $fp = @fopen($paths['lock'], 'r+b');
        if (!$fp) {
            clearstatcache(true, $paths['lock']);
            if (!file_exists($paths['lock'])) return ['status' => 'none'];
            throw new RuntimeException('Cannot open submit intent lock.');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return ['status' => 'busy'];
        }
        return ['status' => 'taken', 'h' => $paths + ['fp' => $fp, 'bytes' => null]];
    });
}

function bbf_tx_release(array &$h): void {
    if (is_resource($h['fp'] ?? null)) {
        flock($h['fp'], LOCK_UN);
        fclose($h['fp']);
    }
    $h['fp'] = null;
}

/** Delete an intent the caller holds: state, marker, then the lock file itself. */
function bbf_tx_delete(array &$h): void {
    bbf_tx_leaf($h['dir'], static function () use (&$h): void {
        @unlink($h['state']);
        bbf_tx_hook('intent_state_deleted');
        @unlink($h['deliver']);
        bbf_tx_release($h);
        @unlink($h['lock']);
    });
}

/** Only the lock holder reads its state. ['kind' => 'none'|'ok'|'unreadable', 'state' => ?array] */
function bbf_tx_read_state(array &$h): array {
    foreach (glob($h['state'] . '.tmp-*') ?: [] as $leftover) @unlink($leftover); // from a writer that died
    clearstatcache(true, $h['state']);
    if (!file_exists($h['state'])) {
        $h['bytes'] = null;
        return ['kind' => 'none', 'state' => null];
    }
    $raw = @file_get_contents($h['state']);
    if ($raw === false) return ['kind' => 'unreadable', 'state' => null];
    $state = json_decode($raw, true);
    if (!is_array($state) || ($state['v'] ?? null) !== 1 || !in_array($state['state'] ?? null, BBF_TX_STATES, true)
        || !is_string($state['submission_id'] ?? null) || !preg_match('/\Abbf_[a-f0-9]{16}\z/D', $state['submission_id'])
        || !is_string($state['P'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $state['P'])
        || !is_int($state['created'] ?? null) || !is_string($state['backend'] ?? null)) {
        return ['kind' => 'unreadable', 'state' => null];
    }
    $h['bytes'] = $raw;
    return ['kind' => 'ok', 'state' => $state];
}

/**
 * Atomic state replacement by the lock holder. A failed rename is decided by content,
 * never by existence: the target usually still holds the previous state.
 */
function bbf_tx_write_state(array &$h, array $state): bool {
    $path = $h['state'];
    try {
        $bytes = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return false;
    }
    $previous = $h['bytes'];
    $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
    $fp = @fopen($temp, 'xb');
    try {
        if (!$fp || !bbf_storage_write_all($fp, $bytes) || !fflush($fp)) return false;
        if (!bbf_tx_hook('state_temp')) return false;
        if (function_exists('fsync')) @fsync($fp);
        $closed = fclose($fp);
        $fp = null;
        if (!$closed) return false;
        bbf_tx_hook('state_fsync');
        foreach ([0, 100, 300, 900] as $delay) {
            if ($delay > 0) usleep($delay * 1000);
            $renamed = bbf_tx_hook('state_rename') && @rename($temp, $path);
            clearstatcache(true, $path);
            if ($renamed) {
                bbf_tx_fsync_dir(dirname($path));
                $h['bytes'] = $bytes;
                bbf_tx_hook('state_renamed');
                return true;
            }
            $current = file_exists($path) ? @file_get_contents($path) : null;
            if ($current === $bytes) {
                $h['bytes'] = $bytes;
                return true;
            }
            clearstatcache(true, $temp);
            if ($current === false || $current !== $previous || !is_file($temp)) return false;
        }
        return false;
    } finally {
        if (is_resource($fp)) fclose($fp);
        if (is_file($temp)) @unlink($temp);
    }
}

function bbf_tx_fsync_dir(string $dir): void {
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('fsync')) return;
    $fp = @fopen($dir, 'r');
    if ($fp) {
        @fsync($fp);
        fclose($fp);
    }
}

function bbf_tx_marker_create(array $h): bool {
    $fp = @fopen($h['deliver'], 'c');
    if (!$fp) return false;
    fclose($fp);
    return bbf_tx_hook('marker_created');
}

/** Common state fields plus $extra; drops keys that do not belong to $name. */
function bbf_tx_state(array $state, string $name, array $extra = []): array {
    $common = array_intersect_key($state, array_flip(['v', 'submission_id', 'P', 'created', 'definition_version',
        'storage_fingerprint', 'backend', 'payment']));
    return ['state' => $name] + $extra + $common;
}

// ─── Backend: MySQL connections, record existence, record read ───

/** A non-persistent MySQL connection whose every wait ends before the transaction deadline. */
function bbf_tx_mysql_connect(array $db, ?array &$info = null): PDO {
    $r = max(1, (int)ceil(bbf_tx_remaining()));
    if (@ini_set('mysqlnd.net_read_timeout', (string)$r) === false) {
        error_log('BareBonesForms: mysqlnd.net_read_timeout cannot be changed on this host.');
    }
    $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}"
        . (isset($db['port']) && $db['port'] !== '' ? ';port=' . (int)$db['port'] : '');
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => min(5, $r), PDO::ATTR_PERSISTENT => false,
    ]);
    $s = max(1, $r - 2); // the server gives up before the client
    $statements = ["SET SESSION innodb_lock_wait_timeout = $s", "SET SESSION lock_wait_timeout = $s"];
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') !== false) $statements[] = "SET SESSION max_statement_time = $s";
    foreach ($statements as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $error) { error_log('BareBonesForms: ' . $sql . ' failed: ' . $error->getMessage()); }
    }
    $info = [
        'connection_id' => (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'server_started' => bbf_tx_mysql_server_started($pdo),
    ];
    return $pdo;
}

function bbf_tx_mysql_server_started(PDO $pdo): int {
    $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $row = $pdo->query("SHOW GLOBAL STATUS LIKE 'Uptime'")->fetch(PDO::FETCH_NUM);
    return $now - (int)($row[1] ?? 0);
}

/**
 * Before trusting not_found, end the original INSERT: a statement the client stopped waiting
 * for can still commit on the server. Returns false when that cannot be proven.
 */
function bbf_tx_mysql_end_original(PDO $pdo, ?array $original): bool {
    if (!is_array($original) || !is_int($original['connection_id'] ?? null)) return true;
    if (abs(bbf_tx_mysql_server_started($pdo) - (int)($original['server_started'] ?? 0)) > 5) return true; // restarted
    $id = $original['connection_id'];
    if ((int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn() === $id) return true;
    try {
        $pdo->exec('KILL CONNECTION ' . $id);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1094) return false; // 1094 = unknown thread: already gone
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ?');
    while (true) {
        $stmt->execute([$id]);
        $listed = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();
        if (!$listed) return true;
        if (bbf_tx_remaining() <= 0) return false;
        usleep(100000);
    }
}

/** 'exists' | 'not_found' | 'unavailable'. Bounded by the transaction deadline. */
function bbf_record_exists(array $storeConfig, string $formId, string $id, ?array $mysqlOriginal = null): string {
    if (!bbf_tx_hook('record_exists')) return 'unavailable';
    $dir = (string)($storeConfig['submissions_dir'] ?? __DIR__ . '/submissions');
    try {
        switch ($storeConfig['storage']) {
            case 'file':
                if (!is_dir($dir)) return 'unavailable';
                $path = "$dir/$formId/$id.json";
                clearstatcache(true, $path);
                return file_exists($path) ? 'exists' : 'not_found';
            case 'csv':
                if (!is_dir($dir)) return 'unavailable';
                foreach (bbf_read_csv($formId, $dir, null, null, null, $id, true) as $ignored) return 'exists';
                return 'not_found';
            case 'sqlite':
                $path = $storeConfig['sqlite']['path'] ?? "$dir/bbf.sqlite";
                if (!is_dir(dirname($path))) return 'unavailable';
                if (!file_exists($path)) return 'not_found';
                $pdo = new PDO("sqlite:$path", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA busy_timeout = ' . max(1, (int)(bbf_tx_remaining() * 1000)));
                $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'bbf_submissions'")->fetchColumn();
                if ((int)$exists === 0) return 'not_found';
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM bbf_submissions WHERE id = ? AND form_id = ?');
                $stmt->execute([$id, $formId]);
                return (int)$stmt->fetchColumn() > 0 ? 'exists' : 'not_found';
            case 'mysql':
                $pdo = bbf_tx_mysql_connect($storeConfig['mysql']);
                if (!bbf_tx_hook('mysql_before_lookup')) return 'unavailable';
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM bbf_submissions WHERE id = ? AND BINARY form_id = BINARY ?');
                try {
                    $stmt->execute([$id, $formId]);
                    if ((int)$stmt->fetchColumn() > 0) return 'exists';
                } catch (PDOException $error) {
                    if (($error->errorInfo[0] ?? '') !== '42S02') throw $error;
                    // No table: no record yet, but a late CREATE+INSERT must still be ended.
                }
                if (!bbf_tx_mysql_end_original($pdo, $mysqlOriginal)) return 'unavailable';
                try {
                    $stmt->execute([$id, $formId]);
                    return (int)$stmt->fetchColumn() > 0 ? 'exists' : 'not_found';
                } catch (PDOException $error) {
                    if (($error->errorInfo[0] ?? '') === '42S02') return 'not_found';
                    throw $error;
                }
        }
    } catch (Throwable $error) {
        error_log('BareBonesForms: record existence check failed: ' . $error->getMessage());
    }
    return 'unavailable';
}

/** Read one stored record; null when missing. Throws when the backend is unavailable. */
function bbf_tx_read_record(array $storeConfig, string $formId, string $id): ?array {
    $dir = (string)($storeConfig['submissions_dir'] ?? __DIR__ . '/submissions');
    if ($storeConfig['storage'] === 'file') return bbf_read_file("$dir/$formId/$id.json", $formId);
    if ($storeConfig['storage'] === 'csv') {
        foreach (bbf_read_csv($formId, $dir, null, null, null, $id) as $sub) return $sub;
        return null;
    }
    $pdo = $storeConfig['storage'] === 'mysql' ? bbf_tx_mysql_connect($storeConfig['mysql']) : bbf_read_db_connect($storeConfig);
    if ($pdo === null) return null;
    foreach (bbf_read_db($pdo, $formId, null, null, null, $id) as $sub) return $sub;
    return null;
}

/** Deleting a submission deletes its intent and marker (spec §9). A locked intent is skipped; it expires with the TTL. */
function bbf_tx_forget(array $config, string $formId, $k): void {
    if (!is_string($k) || !preg_match('/\A[a-f0-9]{64}\z/D', $k)) return;
    try {
        $taken = bbf_tx_take($config, $formId, $k);
        if (($taken['status'] ?? '') === 'taken') bbf_tx_delete($taken['h']);
    } catch (Throwable $error) {
        error_log('BareBonesForms: submit intent not deleted with its submission: ' . $error->getMessage());
    }
}

/**
 * Viewer, API and retention deletion: the records go through $deleteRecords (the existing
 * coordinated deletion); their files (upload spec §10) and transaction intents follow.
 * $records: id => record when the caller already read them (retention), else they are read here.
 */
function bbf_submissions_delete(array $config, string $formId, array $ids, callable $deleteRecords, ?array $records = null): array {
    $storeConfig = bbf_effective_storage_config($config, $formId);
    $fingerprint = bbf_tx_storage_fingerprint($storeConfig);
    $keys = [];
    foreach ($ids as $id) {
        try {
            $record = $records !== null ? ($records[$id] ?? null) : bbf_tx_read_record($storeConfig, $formId, $id);
        } catch (Throwable $error) {
            $record = null;
        }
        if (is_string($record['meta']['submit_key_hash'] ?? null)) $keys[$id] = $record['meta']['submit_key_hash'];
    }
    $intent = null;
    try {
        $intent = bbf_uploads_deletion_begin($config, $formId, $ids, $fingerprint);
    } catch (Throwable $error) {
        // Never blocks the record deletion; cleanup reports directories without records.
        error_log('BareBonesForms uploads: deletion intent not written: ' . $error->getMessage());
    }
    $result = $deleteRecords();
    $existence = [];
    $exists = static function (string $id) use (&$existence, $storeConfig, $formId): string {
        return $existence[$id] ??= bbf_record_exists($storeConfig, $formId, $id);
    };
    foreach ($keys as $id => $k) if ($exists((string)$id) === 'not_found') bbf_tx_forget($config, $formId, $k);
    if ($intent !== null) bbf_uploads_deletion_finish($intent, $exists, $fingerprint);
    return $result;
}

/** The definition a submission was made with: the current one if unchanged, else the stored blob. */
function bbf_tx_definition(array $config, string $formId, string $version): ?array {
    $resolve = static function (array $form): array {
        if (!empty($form['templates'])) $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
        return $form;
    };
    try {
        $path = ($config['forms_dir'] ?? __DIR__ . '/forms') . "/$formId.json";
        if (is_file($path)) {
            $form = json_decode((string)file_get_contents($path), true);
            if (is_array($form) && ($form['id'] ?? null) === $formId) {
                $form = bbfSystemDefinition($form, $config);
                if (bbf_version_id($form) === $version) return $resolve($form);
            }
        }
        return $resolve(bbf_version_read_blob(bbf_version_paths($config, $formId), $version));
    } catch (Throwable $error) {
        error_log('BareBonesForms: submission definition unavailable: ' . $error->getMessage());
        return null;
    }
}

// ─── Stripe (step G) ─────────────────────────────────────────────

function bbf_stripe_checkout_fields(array $params): array {
    $fields = [
        'mode' => 'payment',
        'success_url' => $params['success_url'],
        'cancel_url' => $params['cancel_url'],
        'line_items[0][price_data][currency]' => $params['currency'],
        'line_items[0][price_data][unit_amount]' => $params['amount'],
        'line_items[0][price_data][product_data][name]' => $params['product_name'],
        'line_items[0][quantity]' => 1,
    ];
    foreach ($params['metadata'] as $k => $v) $fields["metadata[$k]"] = $v;
    if (!empty($params['customer_email']) && filter_var($params['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $fields['customer_email'] = $params['customer_email'];
    }
    return $fields;
}

/**
 * One Checkout call with a fixed Idempotency-Key. Result:
 * 'ok' (session) | 'retry' (no response, timeout, 409, 429) | 'indeterminate' (5xx) | 'rejected' (other 4xx).
 */
function bbf_stripe_create_checkout(string $secretKey, array $params, ?callable $transport, string $idempotencyKey, float $timeout): array {
    $fields = bbf_stripe_checkout_fields($params);
    $timeout = max(1.0, min(10.0, $timeout));
    $status = 0;
    $data = null;
    try {
        if ($transport !== null) {
            $data = $transport($fields, $secretKey, ['idempotency_key' => $idempotencyKey, 'timeout' => $timeout]);
            $status = is_array($data) ? (int)($data['_status'] ?? 200) : 0;
        } else {
            [$status, $data] = bbf_stripe_http($secretKey, $fields, $idempotencyKey, $timeout);
        }
    } catch (Throwable $error) {
        error_log('BareBonesForms: Stripe request failed: ' . $error->getMessage());
        return ['result' => 'retry', 'status' => 0];
    }
    if ($status >= 200 && $status < 300 && is_array($data)
        && is_string($data['id'] ?? null) && $data['id'] !== '' && is_string($data['url'] ?? null) && $data['url'] !== '') {
        return ['result' => 'ok', 'status' => $status, 'session' => [
            'id' => $data['id'], 'url' => $data['url'],
            'expires_at' => is_int($data['expires_at'] ?? null) ? $data['expires_at'] : time() + 86400,
        ]];
    }
    $message = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
    if ($status === 0 || $status === 409 || $status === 429) return ['result' => 'retry', 'status' => $status];
    if ($status >= 500) return ['result' => 'indeterminate', 'status' => $status, 'message' => $message];
    return ['result' => 'rejected', 'status' => $status, 'message' => $message];
}

function bbf_stripe_http(string $secretKey, array $fields, string $idempotencyKey, float $timeout): array {
    $body = http_build_query($fields);
    $headers = ["Authorization: Bearer $secretKey", 'Content-Type: application/x-www-form-urlencoded', "Idempotency-Key: $idempotencyKey"];
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int)(min(5.0, $timeout) * 1000)]);
        $result = curl_exec($ch);
        $status = $result === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return [$status, is_string($result) ? json_decode($result, true) : null];
    }
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $http_response_header = [];
    $result = @file_get_contents('https://api.stripe.com/v1/checkout/sessions', false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
    $status = $result !== false && preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)($responseHeaders[0] ?? ''), $m) ? (int)$m[1] : 0;
    return [$status, is_string($result) ? json_decode($result, true) : null];
}

/**
 * Step G: finish a committed payment, idempotently. Holds the intent lock; no mail is sent here.
 * Returns ['state' => latest state, 'retry' => bool].
 */
function bbf_tx_finish_payment(array $config, string $formId, array &$h, array $state, ?array $form = null): array {
    $id = $state['submission_id'];
    $retry = ['state' => $state, 'retry' => true];
    if (!is_array($state['session'] ?? null)) {
        if (time() > (int)($state['committed_at'] ?? 0) + 23 * 3600) {
            // Stripe keeps idempotency keys for 24 h only; a late call could create a second session.
            bbf_tx_notice($formId, 'Payment not started', "Submission $id: payment preparation expired; no Checkout call was made.");
            $complete = bbf_tx_state($state, 'complete', ['response' => ['submission_id' => $id, 'payment_unavailable' => 'expired']]);
            return bbf_tx_write_state($h, $complete) ? ['state' => $complete, 'retry' => false] : $retry;
        }
        $secret = (string)($config['stripe']['secret_key'] ?? '');
        $transport = $config['stripe']['transport'] ?? null;
        $key = 'bbf-checkout-' . $id;
        $call = $secret === '' ? ['result' => 'rejected', 'status' => 0, 'message' => 'stripe.secret_key is not set']
            : bbf_stripe_create_checkout($secret, (array)$state['checkout'], is_callable($transport) ? $transport : null,
                $key, bbf_tx_remaining());
        if (!bbf_tx_hook('stripe_answered')) return $retry;
        if ($call['result'] === 'retry') return $retry;
        if ($call['result'] !== 'ok') {
            $kind = $call['result'] === 'indeterminate' ? 'indeterminate' : 'rejected';
            bbf_tx_notice($formId, 'Payment could not be started',
                "Submission $id: Stripe answered HTTP {$call['status']} ($kind); Idempotency-Key $key. Check the Stripe dashboard for a session with bbf_submission_id=$id.");
            $complete = bbf_tx_state($state, 'complete', ['response' => ['submission_id' => $id, 'payment_unavailable' => $kind]]);
            return bbf_tx_write_state($h, $complete) ? ['state' => $complete, 'retry' => false] : $retry;
        }
        $state['session'] = $call['session'];
        if (!bbf_tx_write_state($h, $state)) return $retry;
    }
    if (!bbf_tx_hook('payment_session_written')) return $retry;

    try {
        $storeConfig = bbf_tx_store_config($config, $formId, $state);
        $record = bbf_tx_read_record($storeConfig, $formId, $id);
        if ($record === null) return $retry;
        if (($record['meta']['payment_checkout_session_id'] ?? null) !== $state['session']['id']) {
            if (!updateSubmissionPaymentMetadata($id, $formId, ['payment_checkout_session_id' => $state['session']['id']],
                $config, ['storage' => $storeConfig['storage']])) return $retry;
            $record['meta']['payment_checkout_session_id'] = $state['session']['id'];
        }
        if (!bbf_tx_hook('payment_meta_written')) return $retry;
        $form ??= bbf_tx_definition($config, $formId, (string)$state['definition_version']);
        if ($form === null) return $retry;
        $bindings = bbf_delivery_payment_template_bindings();
        $templateData = bbf_delivery_template_data($form, $record, $bindings + ['_bbf_payment_bindings' => $bindings]);
        $jobs = bbf_delivery_prepare_jobs($form, $record, $storeConfig, $templateData);
        $outboxPath = bbf_outbox_path($storeConfig, $formId, $id);
        $plan = $outboxPath !== '' ? bbf_outbox_init($outboxPath, "$formId:$id", $jobs,
            (int)($storeConfig['delivery']['max_attempts'] ?? 3), null, ['storage' => $storeConfig['storage']]) : ['ok' => false];
        if (!($plan['ok'] ?? false)) return $retry;
        if (!bbf_tx_hook('payment_plan_written')) return $retry;
    } catch (Throwable $error) {
        error_log('BareBonesForms: payment finish failed: ' . $error->getMessage());
        return $retry;
    }
    $complete = bbf_tx_state($state, 'complete', ['response' => ['submission_id' => $id, 'redirect' => $state['session']['url']],
        'session' => $state['session']]);
    return bbf_tx_write_state($h, $complete) ? ['state' => $complete, 'retry' => false] : $retry;
}

// ─── Decide (step F) and recovery ────────────────────────────────

/** Mark an orphan plan attention_required exactly like today's storage-failure path. */
function bbf_tx_abort_outbox(array $storeConfig, string $formId, string $id): void {
    $path = bbf_outbox_existing_path($storeConfig, $formId, $id);
    if ($path === '' || !is_file($path)) return;
    $ledger = bbf_outbox_read($path);
    $jobs = is_array($ledger['ledger']['jobs'] ?? null) ? array_values($ledger['ledger']['jobs']) : [];
    bbf_delivery_abort_for_storage($path, $jobs, $storeConfig);
}

/**
 * Decide an open intent from the record's existence and write the next state.
 * Returns the new state, or null when the intent stays open (unavailable or a failed write).
 */
function bbf_tx_decide_open(array $config, string $formId, array &$h, array $state, string $existence): ?array {
    if ($existence === 'exists') {
        if (!empty($state['payment'])) {
            $next = bbf_tx_state($state, 'committed', ['committed_at' => time(), 'checkout' => $state['checkout']]);
        } else {
            if (!bbf_tx_marker_create($h)) return null;
            $next = bbf_tx_state($state, 'complete', ['response' => $state['response']]);
        }
        return bbf_tx_write_state($h, $next) ? $next : null;
    }
    if ($existence === 'not_found') {
        // Claimed files go back to staging first (unexpired ones stay usable for a retry); a failed rollback stays open.
        if (!empty($state['files']) && !bbf_uploads_rollback($config, bbf_uploads_tx_owner($formId, $h['k']), $state['files'])) return null;
        $next = bbf_tx_state($state, 'aborted');
        if (!bbf_tx_write_state($h, $next)) return null;
        @unlink($h['deliver']);
        return $next;
    }
    return null;
}

/** Recovery for one taken intent (spec §7). Caller releases the handle. Returns the resulting state or null. */
function bbf_tx_recover(array $config, string $formId, array &$h, array $state): ?array {
    bbf_tx_deadline_start($config);
    try {
        // Write-ahead upload entries of this transaction are resolved only by its own lock holder.
        bbf_uploads_settle_owner($config, bbf_uploads_tx_owner($formId, $h['k']));
        if ($state['state'] === 'open') {
            try { $storeConfig = bbf_tx_store_config($config, $formId, $state); } catch (Throwable $error) { return $state; }
            if (bbf_tx_storage_fingerprint($storeConfig) !== ($state['storage_fingerprint'] ?? null)) return $state;
            $existence = bbf_record_exists($storeConfig, $formId, $state['submission_id'], $state['db'] ?? null);
            if ($existence === 'not_found') bbf_tx_abort_outbox($storeConfig, $formId, $state['submission_id']);
            $next = bbf_tx_decide_open($config, $formId, $h, $state, $existence);
            if ($next === null) return $state;
            $state = $next;
        }
        if ($state['state'] === 'committed') {
            $state = bbf_tx_finish_payment($config, $formId, $h, $state)['state'];
        }
        return $state;
    } finally {
        bbf_tx_deadline_clear();
    }
}

/** Run outbox jobs that were never attempted (a crash between complete and delivery). */
function bbf_tx_run_unattempted(array $config, string $formId, array $state): void {
    try {
        $storeConfig = bbf_tx_store_config($config, $formId, $state);
        $path = bbf_outbox_existing_path($storeConfig, $formId, $state['submission_id']);
        if ($path === '' || !is_file($path)) return;
        $ledger = bbf_outbox_read($path);
        foreach ((array)($ledger['ledger']['jobs'] ?? []) as $key => $job) {
            if (($job['state'] ?? null) !== 'pending' || (int)($job['attempts'] ?? 0) !== 0) continue;
            $ignored = [];
            bbf_delivery_run_job($path, (string)$key, $storeConfig, $ignored, false, true);
        }
    } catch (Throwable $error) {
        error_log('BareBonesForms: delivery recovery failed: ' . $error->getMessage());
    }
}

/**
 * Opportunistic / CLI recovery pass. $budget = [intents, seconds]; null = unlimited (CLI).
 * Returns a report list of [form, k, action].
 */
function bbf_tx_sweep(array $config, ?string $onlyForm = null, ?array $budget = null): array {
    $root = bbf_tx_root($config);
    if (!is_dir($root)) return [];
    $ttl = max(60, (int)($config['submit_replay_ttl'] ?? 86400));
    $lease = max(1, (int)($config['delivery']['lease_seconds'] ?? 300));
    $dirs = $onlyForm !== null ? [$root . '/' . $onlyForm] : (glob($root . '/*', GLOB_ONLYDIR) ?: []);
    $candidates = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $formId = basename($dir);
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) continue;
        foreach (glob($dir . '/*.lock') ?: [] as $lock) {
            $k = basename($lock, '.lock');
            if (preg_match('/\A[a-f0-9]{64}\z/D', $k)) $candidates[] = [$formId, $k];
        }
    }
    if ($budget !== null) shuffle($candidates);
    $started = microtime(true);
    $report = [];
    $done = 0;
    foreach ($candidates as [$formId, $k]) {
        if ($budget !== null && ($done >= $budget[0] || microtime(true) - $started > $budget[1])) break;
        $done++;
        try {
            $taken = bbf_tx_take($config, $formId, $k);
            if ($taken['status'] !== 'taken') {
                if ($taken['status'] === 'busy') $report[] = [$formId, $k, 'skipped: locked'];
                continue;
            }
            $h = $taken['h'];
            $read = bbf_tx_read_state($h);
            if ($read['kind'] === 'none') {
                bbf_tx_delete($h);
                $report[] = [$formId, $k, 'deleted: no state'];
                continue;
            }
            if ($read['kind'] === 'unreadable') {
                bbf_tx_release($h);
                $report[] = [$formId, $k, 'skipped: unreadable state'];
                continue;
            }
            $state = $read['state'];
            $before = $state['state'];
            if (in_array($before, ['open', 'committed'], true)) $state = bbf_tx_recover($config, $formId, $h, $state) ?? $state;
            elseif (!bbf_uploads_settle_owner($config, bbf_uploads_tx_owner($formId, $k))) {
                bbf_tx_release($h);
                $report[] = [$formId, $k, 'skipped: upload ledger entry unresolved'];
                continue;
            }
            $age = time() - (int)$state['created'];
            if (in_array($state['state'], ['complete', 'aborted'], true) && $age > $ttl) {
                bbf_tx_delete($h);
                $report[] = [$formId, $k, "deleted: $state[state] after TTL"];
                continue;
            }
            clearstatcache(true, $h['deliver']);
            $marker = $state['state'] === 'complete' && is_file($h['deliver']) ? filemtime($h['deliver']) : false;
            bbf_tx_release($h);
            bbf_tx_flush_notices($config);
            // The age only keeps recovery from running jobs out of order while a live original is between two jobs.
            if ($marker !== false && time() - $marker > $lease) {
                bbf_tx_run_unattempted($config, $formId, $state);
                @unlink(bbf_tx_paths(bbf_tx_root($config) . '/' . $formId, $k)['deliver']);
                $report[] = [$formId, $k, 'delivered never-attempted jobs'];
            } elseif ($before !== $state['state']) {
                $report[] = [$formId, $k, "$before -> $state[state]"];
            } elseif (in_array($state['state'], ['open', 'committed'], true)) {
                $report[] = [$formId, $k, "skipped: still $state[state] (backend unavailable?)"];
            }
        } catch (Throwable $error) {
            error_log('BareBonesForms: submit recovery error: ' . $error->getMessage());
            $report[] = [$formId, $k, 'error: ' . $error->getMessage()];
        }
    }
    return $report;
}
