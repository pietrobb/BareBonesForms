<?php
/** Shared management access. No public-submission session keys are read or removed.
 * Load fresh config each request, then authenticate; never cache returned principals.
 * Stateless clients use bbf_authenticate($config, false). HTML login may opt into a
 * clean-URL exchange. Only the legacy nonempty api_token is an unrestricted admin.
 */
defined('BBF_LOADED') || exit;
function bbf_auth_load_config(string $path): array {
    ini_set('display_errors', '0');
    if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    $config = require $path;
    if (!is_array($config)) bbf_auth_fail(503);
    return $config;
}

function bbf_auth_headers(): void {
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
}

function bbf_auth_fail(int $code = 403): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $code === 503 ? 'Access audit unavailable.' : 'Access denied.']);
    exit;
}

function bbf_auth_id($value): string {
    return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $value) ? $value : '';
}

/** Invalid/duplicate records invalidate the entire registry, including legacy access. */
function bbf_auth_registry(array $config): array {
    $records = array_key_exists('access_tokens', $config) ? $config['access_tokens'] : [];
    if (!is_array($records) || !array_is_list($records)) return [];
    $registry = []; $secrets = [];
    $legacy = $config['api_token'] ?? '';
    if (!is_string($legacy)) return [];
    if ($legacy !== '') {
        $fp = hash('sha256', $legacy);
        $registry['legacy-admin'] = ['id' => 'legacy-admin', 'fingerprint' => $fp, 'admin' => true,
            'forms' => [], 'permissions' => [], 'expires' => PHP_INT_MAX, 'revoked' => false];
        $secrets[$fp] = true;
    }
    foreach ($records as $r) {
        if (!is_array($r) || bbf_auth_id($r['id'] ?? null) === '' || ($r['id'] ?? '') === 'legacy-admin'
            || !is_string($r['token'] ?? null) || $r['token'] === ''
            || !is_array($r['forms'] ?? null) || !array_is_list($r['forms'])
            || !is_array($r['permissions'] ?? null) || !array_is_list($r['permissions'])
            || !is_bool($r['revoked'] ?? null) || !is_string($r['expires_at'] ?? null)) return [];
        foreach ($r['forms'] as $id) if (bbf_auth_id($id) === '') return [];
        foreach ($r['permissions'] as $p) if (!in_array($p, ['read', 'export', 'delete', 'review'], true)) return [];
        if (count(array_unique($r['forms'])) !== count($r['forms'])
            || count(array_unique($r['permissions'])) !== count($r['permissions'])) return [];
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/D', $r['expires_at'])) return [];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $r['expires_at']);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) return [];
        $fp = hash('sha256', $r['token']);
        if (isset($registry[$r['id']]) || isset($secrets[$fp])) return [];
        $secrets[$fp] = true;
        $registry[$r['id']] = ['id' => $r['id'], 'fingerprint' => $fp, 'admin' => false,
            'forms' => $r['forms'], 'permissions' => $r['permissions'],
            'expires' => $date->getTimestamp(), 'revoked' => $r['revoked']];
    }
    foreach ($registry as $r) if (isset($secrets[hash('sha256', $r['id'])])) return []; return $registry;
}

function bbf_auth_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' =>
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) bbf_auth_fail(503);
    }
    // Retire pre-hardening grants; leave respondent CSRF and other public keys intact.
    unset($_SESSION['bbf_viewer_auth'], $_SESSION['bbf_editor_auth'],
        $_SESSION['bbf_viewer_token'], $_SESSION['bbf_editor_token']);
}

/** Returns a secret-free principal or null. Explicit bad credentials clear access,
 * never fall back to a session. Header presence wins even if empty/malformed.
 */
function bbf_authenticate(array $config, bool $session = true, bool $html = false): ?array {
    bbf_auth_headers();
    $registry = bbf_auth_registry($config);
    if ($session) bbf_auth_session();
    $explicit = array_key_exists('HTTP_X_BBF_TOKEN', $_SERVER) || array_key_exists('token', $_GET);
    $provided = $_SERVER['HTTP_X_BBF_TOKEN'] ?? ($_GET['token'] ?? null);
    $principal = null; $now = time();
    if ($explicit) {
        if (is_string($provided) && $provided !== '') {
            $fp = hash('sha256', $provided);
            foreach ($registry as $r) if (hash_equals($r['fingerprint'], $fp)) $principal = $r;
        }
    } elseif ($session) {
        $s = $_SESSION['bbf_access'] ?? [];
        $idle = max(1, min(86400, (int)($config['auth_session_idle'] ?? 1800)));
        $absolute = max(1, min(604800, (int)($config['auth_session_absolute'] ?? 28800)));
        if (is_array($s) && isset($s['id'], $s['fingerprint'], $s['created'], $s['seen'])
            && is_string($s['id']) && is_string($s['fingerprint'])
            && $now - $s['seen'] < $idle && $now - $s['created'] < $absolute) {
            $r = $registry[$s['id']] ?? null;
            if ($r && hash_equals($r['fingerprint'], $s['fingerprint'])) $principal = $r;
        }
    }
    if ($principal && ($principal['revoked'] || $principal['expires'] <= $now)) $principal = null;
    if ($session) {
        if (!$principal) unset($_SESSION['bbf_access']);
        elseif ($explicit) {
            $old = $_SESSION['bbf_access'] ?? []; $csrf = (($old['id'] ?? '') === $principal['id'] && ($old['fingerprint'] ?? '') === $principal['fingerprint']) ? ($old['csrf'] ?? bin2hex(random_bytes(32))) : bin2hex(random_bytes(32)); if (!session_regenerate_id(true)) bbf_auth_fail(503);
            $_SESSION['bbf_access'] = ['id' => $principal['id'], 'fingerprint' => $principal['fingerprint'],
                'created' => $now, 'seen' => $now, 'csrf' => $csrf];
        } else $_SESSION['bbf_access']['seen'] = $now;
    }
    if ($principal && $session && $html && array_key_exists('token', $_GET)) {
        bbf_audit_write($config, $principal, 'login', '', [], 'allowed', 'attempted', 0);
        bbf_audit_write($config, $principal, 'login', '', [], 'allowed', 'completed', 0);
        // Never reflect arbitrary query values (including redundant credentials).
        $query = [];
        // Omit all caller-controlled values so even credentials smuggled into another parameter are not reflected.
        if (($_GET['action'] ?? '') === 'preview_page') $query['action'] = 'preview_page';
        $path = basename($_SERVER['SCRIPT_NAME']);
        header('Location: ' . $path . ($query ? '?' . http_build_query($query) : ''), true, 303);
        exit;
    }
    return $principal;
}

function bbf_auth_can(?array $principal, string $form, string $permission = 'read'): bool {
    return $principal !== null && ($principal['admin'] ||
        (in_array($form, $principal['forms'], true) && in_array($permission, $principal['permissions'], true)));
}

/** Canonical spelling and no links; null type leaves regular-file validation to the definition reader. */
function bbf_auth_path_identity($dir, string $expected, ?bool $directory = false): bool {
    if (!is_string($dir) || $dir === '') return false;
    $exists = file_exists($dir) || is_link($dir);
    if ($exists && (is_link($dir) || !is_dir($dir))) return false;
    $entries = $exists ? @scandir($dir) : [];
    if ($entries === false) return false;
    foreach ($entries as $entry) {
        if (strcasecmp($entry, $expected) !== 0) continue;
        if ($entry !== $expected || is_link($dir . '/' . $entry) || ($directory !== null && ($directory ? !is_dir($dir . '/' . $entry) : !is_file($dir . '/' . $entry)))) return false;
    } return true;
}
/** Definition management must not parse JSON or depend on response storage. Callers must reject nonregular files. */
function bbf_auth_definition_identity(array $config, string $form): bool {
    return bbf_auth_id($form) !== '' && bbf_auth_path_identity($config['forms_dir'] ?? __DIR__ . '/forms', "$form.json", null);
}
/** Response authorization additionally validates the definition and effective store; exact-scope orphans remain valid. */
function bbf_auth_form_identity(array $config, string $form): bool {
    if (!bbf_auth_definition_identity($config, $form) || !bbf_auth_path_identity($config['forms_dir'] ?? __DIR__ . '/forms', "$form.json")) return false;
    try { require_once __DIR__ . '/bbf_storage.php'; $effective = bbf_effective_storage_config($config, $form); } catch (Throwable $error) { return false; }
    $storage = $effective['storage']; if ($storage === 'file' || $storage === 'csv') return bbf_auth_path_identity($effective['submissions_dir'] ?? __DIR__ . '/submissions', $form . ($storage === 'csv' ? '.csv' : ''), $storage === 'file');
    if ($storage === 'sqlite') { $path = $effective['sqlite']['path'] ?? ($effective['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite'; return is_string($path) && $path !== '' && bbf_auth_path_identity(dirname($path), basename($path)); } return true;
}

/** Explicit byte equality overrides even a case-insensitive database column collation. */
function bbf_auth_form_sql(PDO $pdo): string {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'CAST(form_id AS BINARY) = CAST(? AS BINARY)'
        : 'form_id COLLATE BINARY = ?';
}

function bbf_auth_csrf(): string {
    return $_SESSION['bbf_access']['csrf'] ?? '';
}

function bbf_auth_csrf_valid(): bool {
    $provided = $_SERVER['HTTP_X_BBF_CSRF'] ?? $_SERVER['HTTP_X_BBF_VIEWER_TOKEN'] ?? $_SERVER['HTTP_X_BBF_EDITOR_TOKEN'] ?? '';
    return is_string($provided) && bbf_auth_csrf() !== '' && hash_equals(bbf_auth_csrf(), $provided);
}

/** Guarded PHP log, exclusive locked append + flush. No URLs, bodies or addresses.
 * Any preflight failure returns 503 BEFORE reading/exporting/mutating protected data.
 * A later IO failure cannot undo a mutation: its durable attempt remains evidence.
 */
function bbf_audit_write(array $config, ?array $principal, string $action, string $form,
    array $ids, string $decision, string $result, int $count): void {
    $dir = $config['logs_dir'] ?? __DIR__ . '/logs';
    $file = $dir . '/access-audit.php';
    $guard = "<?php http_response_code(404); exit; ?>\n";
    if (!is_dir($dir) || is_link($dir) || is_link($file)) bbf_auth_fail(503);
    $fp = @fopen($file, 'c+b');
    if (!$fp) bbf_auth_fail(503);
    $ok = false;
    try {
        if (!flock($fp, LOCK_EX)) bbf_auth_fail(503);
        $stat = fstat($fp);
        if (($stat['mode'] & 0170000) !== 0100000) bbf_auth_fail(503);
        if ($stat['size'] === 0) {
            if (fwrite($fp, $guard) !== strlen($guard)) bbf_auth_fail(503);
            @chmod($file, 0600);
        } elseif (fread($fp, strlen($guard)) !== $guard) bbf_auth_fail(503);
        $entry = ['utc' => gmdate('Y-m-d\TH:i:s\Z'), 'principal_id' => $principal['id'] ?? 'anonymous',
            'action' => bbf_auth_id($action), 'form' => bbf_auth_id($form),
            'submission_ids' => array_values(array_filter(array_map('bbf_auth_id', array_slice($ids, 0, 100)))),
            'decision' => $decision, 'result' => $result, 'result_count' => max(0, $count)];
        // Even a hostile ID chosen to equal a configured credential cannot log it.
        // Redact identifier values before encoding, never JSON keys or numeric counts.
        $secrets = [$config['api_token'] ?? ''];
        foreach (is_array($config['access_tokens'] ?? null) ? $config['access_tokens'] : [] as $r)
            if (is_array($r)) $secrets[] = $r['token'] ?? '';
        foreach ($secrets as $secret) if (is_string($secret) && $secret !== '') {
            foreach (['principal_id', 'action', 'form', 'decision', 'result'] as $key) $entry[$key] = str_replace($secret, '[redacted]', $entry[$key]);
            foreach ($entry['submission_ids'] as &$id) $id = str_replace($secret, '[redacted]', $id); unset($id);
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $ok = fseek($fp, 0, SEEK_END) === 0 && fwrite($fp, $line) === strlen($line) && fflush($fp);
    } finally { flock($fp, LOCK_UN); fclose($fp); }
    if (!$ok) bbf_auth_fail(503);
}

/** Call once before any protected operation; finish before releasing data.
 * Shutdown records failed if execution exits/throws without an explicit finish.
 */
function bbf_access_begin(array $config, ?array $principal, string $action, string $form = '',
    array $permissions = [], bool $admin = false, bool $mutation = false, array $ids = []): void {
    $allowed = $principal !== null && (!$admin || $principal['admin']);
    foreach ($permissions as $permission) $allowed = $allowed && bbf_auth_can($principal, $form, $permission);
    $allowed = $allowed && (!$permissions || bbf_auth_form_identity($config, $form)); $methodOK = !$mutation || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $allowed = $allowed && $methodOK && (!$mutation || bbf_auth_csrf_valid());
    bbf_audit_write($config, $principal, $action, $form, $ids, $allowed ? 'allowed' : 'denied', 'attempted', 0);
    if (!$allowed) {
        bbf_audit_write($config, $principal, $action, $form, $ids, 'denied', 'failed', 0);
        bbf_auth_fail($methodOK || !$principal || ($admin && !$principal['admin']) ? 403 : 405);
    }
    $GLOBALS['bbf_access_operation'] = compact('config', 'principal', 'action', 'form', 'ids');
    register_shutdown_function(static function (): void {
        if (isset($GLOBALS['bbf_access_operation'])) bbf_access_finish(0, false);
    });
}

function bbf_access_finish(int $count = 0, bool $success = true): void {
    $op = $GLOBALS['bbf_access_operation'] ?? null;
    if (!$op) return;
    unset($GLOBALS['bbf_access_operation']);
    bbf_audit_write($op['config'], $op['principal'], $op['action'], $op['form'], $op['ids'],
        'allowed', $success ? 'completed' : 'failed', $count);
}

/** Presentation only: no delivery/storage/default/lookup/secret definitions. */
function bbf_auth_presentation(?array $def, ?array $principal): ?array {
    if (!$def || ($principal['admin'] ?? false)) return $def;
    $walk = static function (array $fields) use (&$walk): array {
        $out = [];
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $safe = [];
            foreach (['name', 'label', 'type'] as $k) if (is_string($f[$k] ?? null)) $safe[$k] = $f[$k];
            if (is_array($f['fields'] ?? null)) $safe['fields'] = $walk($f['fields']);
            $out[] = $safe;
        }
        return $out;
    };
    return ['id' => is_string($def['id'] ?? null) ? $def['id'] : '',
        'name' => is_string($def['name'] ?? null) ? $def['name'] : '',
        'fields' => $walk(is_array($def['fields'] ?? null) ? $def['fields'] : [])];
}
