<?php
/**
 * File uploads (docs/FILE-UPLOAD-DESIGN.md, BBF 2.2): staging, capacity ledger with
 * write-ahead accounting, claim into a submit transaction, rollback, GC, downloads.
 * The uploads lock (<root>/.lock) is a leaf lock: never nested, never held across a
 * backend call, a network request, mail() or a child process.
 */
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_storage.php';
require_once __DIR__ . '/bbf_diagnostics.php';

/** extension => [canonical MIME, accepted finfo types, needs ZipArchive] */
const BBF_UPLOAD_TYPES = [
    'pdf'  => ['application/pdf', ['application/pdf'], false],
    'jpg'  => ['image/jpeg', ['image/jpeg'], false],
    'jpeg' => ['image/jpeg', ['image/jpeg'], false],
    'png'  => ['image/png', ['image/png'], false],
    'webp' => ['image/webp', ['image/webp'], false],
    'gif'  => ['image/gif', ['image/gif'], false],
    'txt'  => ['text/plain', ['text/plain'], false],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], true],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true],
    'odt'  => ['application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip'], true],
    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'], true],
    'heic' => ['image/heic', ['image/heic', 'image/heif'], false],
    'csv'  => ['text/csv', ['text/csv', 'text/plain', 'application/csv'], false],
    'zip'  => ['application/zip', ['application/zip'], false],
    'doc'  => ['application/msword', ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], false],
    'xls'  => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], false],
];
const BBF_UPLOAD_DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'docx', 'xlsx', 'odt', 'ods'];
const BBF_UPLOAD_HARD_DENY = ['phtml', 'phar', 'pht', 'shtml', 'cgi', 'pl', 'py', 'sh', 'jsp', 'inc',
    'html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'swf', 'htaccess', 'user.ini',
    'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi', 'msc', 'jar', 'vbs', 'vbe', 'jse', 'ps1', 'wsf', 'wsh', 'hta',
    'cpl', 'pif', 'reg', 'scf', 'lnk', 'url', 'app', 'dmg', 'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'iso', 'img', 'vhd', 'vhdx'];

// ─── Configuration and definitions ───────────────────────────────

function bbf_uploads_config(array $config): array {
    $u = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
    return $u + [
        'enabled' => false,
        'dir' => dirname(__DIR__) . '/barebonesforms-private/uploads',
        'allow_inside_web_root' => false,
        'max_file_size' => 10 * 1024 * 1024,
        'max_submission_size' => 25 * 1024 * 1024,
        'allowed_extensions' => BBF_UPLOAD_DEFAULT_EXTENSIONS,
        'staging_ttl' => 7200,
        'max_staging_bytes' => 500 * 1024 * 1024,
        'max_staging_entries' => 2000,
        'per_ip_staging_bytes' => 100 * 1024 * 1024,
        'per_ip_staging_entries' => 40,
        'max_stored_bytes' => 5 * 1024 * 1024 * 1024,
        'max_stored_files' => 50000,
        'min_free_disk' => 200 * 1024 * 1024,
        'rate_limit' => ['max' => 60, 'window' => 600],
    ];
}

function bbf_uploads_hook(string $point): bool {
    $hook = $GLOBALS['_bbf_tx_hook'] ?? null;
    return !is_callable($hook) || $hook('upload:' . $point) !== false;
}

function bbf_uploads_hard_denied(string $ext, string $name = ''): bool {
    $ext = strtolower($ext);
    if (str_starts_with($ext, 'php') || str_starts_with($ext, 'asp') || in_array($ext, BBF_UPLOAD_HARD_DENY, true)) return true;
    $lower = strtolower($name);
    return in_array($lower, ['.htaccess', 'htaccess', '.user.ini', 'user.ini'], true) || str_ends_with($lower, '.user.ini');
}

/** A definition's accept entry ('.pdf' or 'pdf') as a bare lowercase extension. */
function bbf_uploads_normalize_ext($value): ?string {
    if (!is_string($value)) return null;
    $ext = strtolower(ltrim(trim($value), '.'));
    return preg_match('/\A[a-z0-9]{1,10}\z/', $ext) ? $ext : null;
}

/** Integer bytes or "<n>KB" / "<n>MB", binary units. */
function bbf_uploads_parse_size($value): ?int {
    if (is_int($value)) return $value > 0 ? $value : null;
    if (!is_string($value) || !preg_match('/\A(\d{1,9})\s*(KB|MB)?\z/i', trim($value), $m)) return null;
    $bytes = (int)$m[1] * match (strtoupper($m[2] ?? '')) { 'KB' => 1024, 'MB' => 1048576, default => 1 };
    return $bytes > 0 ? $bytes : null;
}

function bbf_uploads_ini_bytes($value): int {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/\A(\d+)\s*([KMG]?)/i', $value, $m)) return 0;
    return (int)$m[1] * match (strtoupper($m[2])) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
}

/** Definition errors for one file field (validateFieldList). */
function bbf_uploads_definition_errors(array $field, string $prefix, bool $insideRepeatable): array {
    $errors = [];
    if ($insideRepeatable) $errors[] = "$prefix: File fields are not supported inside repeatable groups.";
    if (array_key_exists('accept', $field)) {
        if (!is_array($field['accept']) || !array_is_list($field['accept']) || $field['accept'] === []) {
            $errors[] = "$prefix.accept: Expected a non-empty list of extensions.";
        } else {
            foreach ($field['accept'] as $i => $entry) {
                $ext = bbf_uploads_normalize_ext($entry);
                if ($ext === null || !isset(BBF_UPLOAD_TYPES[$ext]) || bbf_uploads_hard_denied($ext)) {
                    $errors[] = "$prefix.accept[$i]: Extension is not in the server allowlist.";
                }
            }
        }
    }
    if (array_key_exists('max_size', $field) && bbf_uploads_parse_size($field['max_size']) === null) {
        $errors[] = "$prefix.max_size: Expected bytes or a size such as \"5MB\".";
    }
    if (array_key_exists('max_files', $field) && (!is_int($field['max_files']) || $field['max_files'] < 1 || $field['max_files'] > 20)) {
        $errors[] = "$prefix.max_files: Expected an integer from 1 through 20.";
    }
    return $errors;
}

/** Extensions the server accepts right now: configured, known, not denied, dependencies present. */
function bbf_uploads_effective_extensions(array $config): array {
    $u = bbf_uploads_config($config);
    $out = [];
    foreach ((array)$u['allowed_extensions'] as $entry) {
        $ext = bbf_uploads_normalize_ext($entry);
        if ($ext === null || !isset(BBF_UPLOAD_TYPES[$ext]) || bbf_uploads_hard_denied($ext)) continue;
        if (BBF_UPLOAD_TYPES[$ext][2] && !class_exists('ZipArchive')) continue;
        $out[$ext] = true;
    }
    return array_keys($out);
}

function bbf_uploads_field_accept(array $config, array $field): array {
    $effective = bbf_uploads_effective_extensions($config);
    if (!is_array($field['accept'] ?? null)) return $effective;
    $wanted = array_filter(array_map('bbf_uploads_normalize_ext', $field['accept']));
    return array_values(array_intersect(array_unique($wanted), $effective));
}

function bbf_uploads_field_max_size(array $config, array $field): int {
    $u = bbf_uploads_config($config);
    $limits = [max(1, (int)$u['max_file_size'])];
    $fieldMax = bbf_uploads_parse_size($field['max_size'] ?? null);
    if ($fieldMax !== null) $limits[] = $fieldMax;
    $uploadMax = bbf_uploads_ini_bytes(ini_get('upload_max_filesize'));
    if ($uploadMax > 0) $limits[] = $uploadMax;
    $postMax = bbf_uploads_ini_bytes(ini_get('post_max_size'));
    if ($postMax > 0) $limits[] = max(1, $postMax - 65536);
    return min($limits);
}

function bbf_uploads_field_max_files(array $field): int {
    return is_int($field['max_files'] ?? null) ? max(1, $field['max_files']) : 1;
}

/** Client projection of a file field: effective accept list (extensions + MIME types) and size. */
function bbf_uploads_client_field(array $config, array $field): array {
    $accept = bbf_uploads_field_accept($config, $field);
    $mimes = [];
    foreach ($accept as $ext) $mimes[BBF_UPLOAD_TYPES[$ext][0]] = true;
    $field['_bbf_accept'] = array_merge(array_map(static fn($e) => ".$e", $accept), array_keys($mimes));
    $field['_bbf_max_size'] = bbf_uploads_field_max_size($config, $field);
    $field['_bbf_uploads'] = !empty(bbf_uploads_config($config)['enabled']);
    return $field;
}

function bbf_uploads_file_fields(array $flatFields): array {
    $out = [];
    foreach ($flatFields as $field) {
        if (($field['type'] ?? '') === 'file' && is_string($field['name'] ?? null)) $out[$field['name']] = $field;
    }
    return $out;
}

function bbf_uploads_human_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/** "Name.pdf (180 KB), Other.png (2.1 MB)" for summaries, templates and CSV cells. */
function bbf_uploads_describe($value): string {
    if (!is_array($value)) return is_scalar($value) ? (string)$value : '';
    $parts = [];
    foreach ($value as $d) {
        if (is_array($d) && is_string($d['name'] ?? null)) $parts[] = $d['name'] . ' (' . bbf_uploads_human_size((int)($d['size'] ?? 0)) . ')';
    }
    return implode(', ', $parts);
}

function bbf_uploads_is_descriptor_list($value): bool {
    if (!is_array($value) || !array_is_list($value)) return false;
    foreach ($value as $d) {
        if (!is_array($d) || !is_string($d['id'] ?? null) || !preg_match('/\Af_[a-f0-9]{16}\z/D', $d['id'])) return false;
    }
    return true;
}

// ─── Names ───────────────────────────────────────────────────────

/** Sanitized display name: basename, valid UTF-8, no controls or bidi overrides, <= 200 bytes, extension kept. */
function bbf_uploads_sanitize_name(string $name): string {
    $name = (string)preg_replace('~\A.*[/\\\\]~s', '', $name);
    if (function_exists('mb_scrub')) {
        $name = mb_scrub($name, 'UTF-8');
    } elseif (!preg_match('//u', $name)) {
        $name = (string)preg_replace('/[\x80-\xFF]/', '_', $name);
    }
    $name = (string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        if (is_string($normalized)) $name = $normalized;
    }
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') $name = 'file';
    if (strlen($name) <= 200) return $name;
    $dot = strrpos($name, '.');
    $ext = $dot !== false && $dot > 0 && strlen($name) - $dot <= 21 ? substr($name, $dot) : '';
    $stem = $ext === '' ? $name : substr($name, 0, $dot);
    $budget = 200 - strlen($ext) - 3;
    if (function_exists('mb_strcut')) {
        $stem = mb_strcut($stem, 0, $budget, 'UTF-8');
    } else {
        $stem = substr($stem, 0, $budget);
        while ($stem !== '' && !preg_match('//u', $stem)) $stem = substr($stem, 0, -1);
    }
    $name = $stem . "\u{2026}" . $ext;
    return preg_match('//u', $name) ? $name : 'file' . $ext;
}

function bbf_uploads_name_ext(string $name): string {
    $dot = strrpos($name, '.');
    return $dot === false ? '' : strtolower(substr($name, $dot + 1));
}

// ─── Location, tree, secret ──────────────────────────────────────

function bbf_uploads_norm(string $path): string {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

/** True when $real lies inside the BBF install directory or the web server's document root. */
function bbf_uploads_inside_web(string $real): bool {
    $candidate = bbf_uploads_norm($real);
    foreach ([__DIR__, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $base) {
        if ($base === '' || ($resolved = realpath($base)) === false) continue;
        $base = bbf_uploads_norm($resolved);
        if ($candidate === $base || str_starts_with($candidate . '/', $base . '/')) return true;
    }
    return false;
}

function bbf_uploads_mkdir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir);
}

function bbf_uploads_prepare_tree(string $root): bool {
    foreach (['staging', 'deleting', 'restore'] as $sub) if (!bbf_uploads_mkdir("$root/$sub")) return false;
    if (!is_file("$root/.htaccess")) @file_put_contents("$root/.htaccess", "Require all denied\nDeny from all\n");
    return true;
}

/** The configured directory resolved to the data root, or null when it does not exist. Never creates anything. */
function bbf_uploads_existing_root(array $config): ?string {
    $u = bbf_uploads_config($config);
    $dir = realpath((string)$u['dir']);
    if ($dir === false || !is_dir($dir)) return null;
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $data = glob($dir . '/data-*', GLOB_ONLYDIR) ?: [];
    if (count($data) === 1 && preg_match('/\Adata-[a-f0-9]{16}\z/', basename($data[0]))) return str_replace('\\', '/', $data[0]);
    return count($data) === 0 ? $dir : null;
}

/**
 * Resolve (and create) the uploads root for writing. In web-root mode the root is one random
 * data-<16 hex> subdirectory, gated by a live HTTP probe. ['ok', 'root', 'code', 'error'].
 */
function bbf_uploads_root(array $config, bool $probe = true): array {
    $u = bbf_uploads_config($config);
    if (empty($u['enabled'])) return ['ok' => false, 'code' => 404, 'error' => 'File uploads are not enabled.'];
    $dir = (string)$u['dir'];
    if ($dir === '' || !bbf_uploads_mkdir($dir) || ($real = realpath($dir)) === false) {
        return ['ok' => false, 'code' => 503, 'error' => 'The upload directory cannot be created.'];
    }
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $inside = bbf_uploads_inside_web($real);
    $root = $real;
    if ($inside) {
        if (empty($u['allow_inside_web_root'])) {
            return ['ok' => false, 'code' => 503, 'error' => 'The upload directory is inside the web root. Move uploads.dir outside it.'];
        }
        if (bbf_diagnostic_base_url($config) === null) {
            return ['ok' => false, 'code' => 503, 'error' => 'allow_inside_web_root requires diagnostic_base_url.'];
        }
        $root = bbf_uploads_data_dir($real);
        if ($root === null) return ['ok' => false, 'code' => 503, 'error' => 'The upload directory must contain exactly one data-* subdirectory.'];
    }
    if (!bbf_uploads_prepare_tree($root)) return ['ok' => false, 'code' => 503, 'error' => 'The upload directory is not writable.'];
    if ($inside && $probe) {
        $result = bbf_uploads_probe($config, $root);
        if (!$result['ok']) return ['ok' => false, 'code' => 503, 'error' => $result['error']];
    }
    return ['ok' => true, 'root' => $root, 'inside' => $inside];
}

function bbf_uploads_data_dir(string $real): ?string {
    $fp = @fopen("$real/.lock", 'c');
    if (!$fp) return null;
    try {
        if (!flock($fp, LOCK_EX)) return null;
        $found = glob($real . '/data-*', GLOB_ONLYDIR) ?: [];
        if (count($found) > 1) return null;
        if (count($found) === 1) return preg_match('/\Adata-[a-f0-9]{16}\z/', basename($found[0])) ? str_replace('\\', '/', $found[0]) : null;
        $dir = $real . '/data-' . bin2hex(random_bytes(8));
        return @mkdir($dir, 0750) ? $dir : null;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** HTTP GET with a 3 s timeout: [status or null, body]. */
function bbf_uploads_http_get(string $url): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        return [$code === 0 ? null : $code, is_string($body) ? $body : '', $error];
    }
    $http_response_header = [];
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0]]), 0, 65536);
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)($headers[0] ?? ''), $m)) return [(int)$m[1], is_string($body) ? $body : '', ''];
    $error = error_get_last();
    return [null, '', (string)($error['message'] ?? 'no response')];
}

/** Web-root mode: a sentinel file must not be reachable over HTTP. Cached for one hour per resolved path. */
function bbf_uploads_probe(array $config, string $root): array {
    $cachePath = "$root/.probe.json";
    $lock = @fopen("$root/.probe.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return ['ok' => false, 'error' => 'Upload probe lock unavailable.'];
    try {
        $cache = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cache) && ($cache['path'] ?? null) === $root && time() - (int)($cache['checked'] ?? 0) < 3600) {
            return ['ok' => (bool)$cache['ok'], 'error' => (string)($cache['error'] ?? '')];
        }
        $url = bbf_uploads_probe_url($config, $root);
        $result = ['ok' => false, 'error' => 'The upload directory has no web address under diagnostic_base_url.'];
        if ($url !== null) {
            $name = 'bbf-probe-' . bin2hex(random_bytes(16)) . '.txt';
            $content = bin2hex(random_bytes(16));
            if (@file_put_contents("$root/$name", $content) === false) {
                $result = ['ok' => false, 'error' => 'Cannot write the upload probe sentinel.'];
            } else {
                [$code, $body, $error] = bbf_uploads_http_get("$url/$name");
                @unlink("$root/$name");
                if ($code === null) {
                    $result = ['ok' => false, 'error' => "Probe could not reach $url/$name: $error. The server may not be able to reach its own domain (firewall, NAT). Set diagnostic_base_url to an address the server can reach."];
                } elseif ($code === 200 && str_contains($body, $content)) {
                    $result = ['ok' => false, 'error' => "Upload directory is publicly reachable at $url/ (HTTP 200)."];
                } else {
                    $result = ['ok' => true, 'error' => ''];
                }
            }
        }
        @file_put_contents($cachePath, json_encode($result + ['path' => $root, 'checked' => time()]));
        if (!$result['ok'] && function_exists('bbfNotifyError')) bbfNotifyError('uploads', 'Uploads blocked', $result['error'], $config);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bbf_uploads_probe_url(array $config, string $root): ?string {
    $base = bbf_diagnostic_base_url($config);
    if ($base === null) return null;
    $normRoot = bbf_uploads_norm($root);
    $install = realpath(__DIR__);
    if ($install !== false && str_starts_with($normRoot . '/', bbf_uploads_norm($install) . '/')) {
        return $base . substr(str_replace('\\', '/', $root), strlen(rtrim(str_replace('\\', '/', $install), '/')));
    }
    $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docroot !== false && str_starts_with($normRoot . '/', bbf_uploads_norm($docroot) . '/')) {
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        return $origin . substr(str_replace('\\', '/', $root), strlen(rtrim(str_replace('\\', '/', $docroot), '/')));
    }
    return null;
}

function bbf_uploads_secret(string $root): string {
    $path = "$root/.secret";
    if (!is_file($path)) {
        $fp = @fopen($path, 'xb');
        if ($fp) {
            $ok = bbf_storage_write_all($fp, bin2hex(random_bytes(32))) && fflush($fp);
            fclose($fp);
            @chmod($path, 0600);
            if (!$ok) { @unlink($path); throw new RuntimeException('Cannot write the uploads secret.'); }
        }
    }
    for ($i = 0; $i < 20; $i++) {
        $secret = @file_get_contents($path);
        if (is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/D', $secret)) return $secret;
        usleep(10000);
    }
    throw new RuntimeException('The uploads secret is unreadable.');
}

function bbf_uploads_ip_hmac(string $root, string $ip): string {
    return hash_hmac('sha256', $ip, hash_hmac('sha256', 'bbf-upload-ip', bbf_uploads_secret($root)));
}

// ─── Leaf lock and capacity ledger ───────────────────────────────

/** Run $fn under the uploads leaf lock. Inside a submit transaction the wait is bounded by its deadline. */
function bbf_uploads_locked(string $root, callable $fn) {
    static $depth = 0;
    if ($depth > 0) throw new LogicException('The uploads lock is a leaf lock and cannot be nested.');
    $fp = @fopen("$root/.lock", 'c');
    if (!$fp) throw new RuntimeException('Cannot open the uploads lock.');
    try {
        if (!bbf_storage_lock_exclusive($fp)) throw new RuntimeException('The uploads lock is busy.');
        $depth++;
        try { return $fn(); } finally { $depth--; }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function bbf_uploads_ledger_empty(): array {
    return ['v' => 1, 'staging' => ['bytes' => 0, 'entries' => 0, 'ip' => []], 'stored' => ['bytes' => 0, 'files' => 0],
        'reservations' => [], 'wal' => [], 'gc_at' => 0, 'notified' => []];
}

/** Read under the lock. An unreadable ledger fails closed. */
function bbf_uploads_ledger_read(string $root): array {
    $path = "$root/.quota.json";
    clearstatcache(true, $path);
    if (!file_exists($path)) return bbf_uploads_ledger_empty();
    $raw = @file_get_contents($path);
    $ledger = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($ledger) || ($ledger['v'] ?? null) !== 1) throw new RuntimeException('The upload ledger is unreadable.');
    $ledger = array_replace(bbf_uploads_ledger_empty(), $ledger);
    foreach (['reservations', 'wal', 'notified'] as $key) if (!is_array($ledger[$key])) $ledger[$key] = [];
    if (!is_array($ledger['staging']['ip'] ?? null)) $ledger['staging']['ip'] = [];
    return $ledger;
}

/** Temp file, fsync, rename; a failed rename is decided by the target's content. */
function bbf_uploads_ledger_write(string $root, array $ledger): bool {
    if (!bbf_uploads_hook('ledger_write')) return false;
    $path = "$root/.quota.json";
    try {
        $bytes = json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return false;
    }
    $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
    $fp = @fopen($temp, 'xb');
    try {
        if (!$fp || !bbf_storage_write_all($fp, $bytes) || !fflush($fp)) return false;
        if (function_exists('fsync')) @fsync($fp);
        $closed = fclose($fp);
        $fp = null;
        if (!$closed) return false;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@rename($temp, $path)) {
                bbf_uploads_fsync_dir($root);
                return true;
            }
            clearstatcache(true, $path);
            if (@file_get_contents($path) === $bytes) return true;
            usleep(10000);
        }
        return false;
    } finally {
        if (is_resource($fp)) fclose($fp);
        if (is_file($temp)) @unlink($temp);
    }
}

function bbf_uploads_fsync_dir(string $dir): void {
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('fsync')) return;
    $fp = @fopen($dir, 'r');
    if ($fp) { @fsync($fp); fclose($fp); }
}

function bbf_uploads_charge(array &$l, string $loc, int $bytes, string $ip, int $sign): void {
    if ($loc === 'staging') {
        $l['staging']['bytes'] = max(0, (int)$l['staging']['bytes'] + $sign * $bytes);
        $l['staging']['entries'] = max(0, (int)$l['staging']['entries'] + $sign);
        if ($ip !== '') {
            $entry = $l['staging']['ip'][$ip] ?? ['bytes' => 0, 'entries' => 0];
            $entry = ['bytes' => max(0, $entry['bytes'] + $sign * $bytes), 'entries' => max(0, $entry['entries'] + $sign)];
            if ($entry['bytes'] === 0 && $entry['entries'] === 0) unset($l['staging']['ip'][$ip]);
            else $l['staging']['ip'][$ip] = $entry;
        }
    } elseif ($loc === 'stored') {
        $l['stored']['bytes'] = max(0, (int)$l['stored']['bytes'] + $sign * $bytes);
        $l['stored']['files'] = max(0, (int)$l['stored']['files'] + $sign);
    }
}

/**
 * Write-ahead accounting: charge every destination (the source stays charged), record the entry
 * and persist before anything moves. Returns the entry ID, or null with $l reloaded from disk.
 * Item: ['from' => ['loc', 'path'], 'to' => ['loc', 'path'], 'bytes', 'ip', 'meta' => ?array, 'meta_path'].
 */
function bbf_uploads_wal_begin(string $root, array &$l, string $op, string $owner, array $items): ?string {
    $id = bin2hex(random_bytes(6));
    foreach ($items as $item) bbf_uploads_charge($l, $item['to']['loc'], $item['bytes'], $item['ip'], 1);
    $l['wal'][$id] = ['op' => $op, 'owner' => $owner, 'created' => time(), 'items' => $items];
    if (bbf_uploads_hook("wal_$op") && bbf_uploads_ledger_write($root, $l)) return $id;
    $l = bbf_uploads_ledger_read($root);
    return null;
}

/**
 * Resolve a write-ahead entry by where each file really is: the location without the bytes is
 * uncharged, staging metadata follows the bytes. In memory only; the caller writes the ledger.
 */
function bbf_uploads_wal_settle(string $root, array &$l, string $id): void {
    foreach ((array)($l['wal'][$id]['items'] ?? []) as $item) {
        $to = $root . '/' . $item['to']['path'];
        $from = $item['from']['loc'] !== 'none' ? $root . '/' . $item['from']['path'] : null;
        clearstatcache();
        $atTo = is_file($to);
        $atFrom = $from !== null && is_file($from);
        $landed = null;
        if ($atTo) {
            if ($from !== null) bbf_uploads_charge($l, $item['from']['loc'], $item['bytes'], $item['ip'], -1);
            $landed = $item['to']['loc'];
        } elseif ($atFrom) {
            bbf_uploads_charge($l, $item['to']['loc'], $item['bytes'], $item['ip'], -1);
            $landed = $item['from']['loc'];
        } else {
            bbf_uploads_charge($l, $item['to']['loc'], $item['bytes'], $item['ip'], -1);
            if ($from !== null) bbf_uploads_charge($l, $item['from']['loc'], $item['bytes'], $item['ip'], -1);
        }
        $metaPath = $root . '/' . $item['meta_path'];
        if ($landed === 'staging') {
            if (!is_file($metaPath) && is_array($item['meta'] ?? null)) bbf_uploads_write_meta($metaPath, $item['meta']);
        } elseif (is_file($metaPath)) {
            @unlink($metaPath);
        }
    }
    unset($l['wal'][$id]);
}

/** Relative paths listed by unresolved write-ahead entries of owners other than $owner. */
function bbf_uploads_wal_paths(array $l, ?string $exceptOwner = null): array {
    $paths = [];
    foreach ($l['wal'] as $entry) {
        if ($exceptOwner !== null && ($entry['owner'] ?? null) === $exceptOwner) continue;
        foreach ((array)($entry['items'] ?? []) as $item) {
            foreach (['from', 'to'] as $side) if (($item[$side]['loc'] ?? 'none') !== 'none') $paths[$item[$side]['path']] = true;
            if (isset($item['meta_path'])) $paths[$item['meta_path']] = true;
        }
    }
    return $paths;
}

/** Resolve every write-ahead entry of $owner. Called by whoever holds that owner's lock. */
function bbf_uploads_settle_owner(array $config, string $owner): bool {
    $root = bbf_uploads_existing_root($config);
    if ($root === null || !is_file("$root/.quota.json")) return true;
    try {
        return bbf_uploads_locked($root, static function () use ($root, $owner): bool {
            $l = bbf_uploads_ledger_read($root);
            $ids = array_keys(array_filter($l['wal'], static fn($e) => ($e['owner'] ?? null) === $owner));
            if ($ids === []) return true;
            foreach ($ids as $id) bbf_uploads_wal_settle($root, $l, (string)$id);
            return bbf_uploads_ledger_write($root, $l);
        });
    } catch (Throwable $error) {
        error_log('BareBonesForms uploads: settle failed: ' . $error->getMessage());
        return false;
    }
}

/**
 * Rename with the outcome rule (section 4.5), for fresh targets only:
 * 'moved' (target exists) | 'failed' (source still there after retries) | 'lost' (neither exists).
 */
function bbf_uploads_move(string $from, string $to): string {
    foreach ([0, 100, 300, 900] as $delay) {
        if ($delay > 0) usleep($delay * 1000);
        $renamed = bbf_uploads_hook('rename') && @rename($from, $to);
        clearstatcache(true, $from);
        clearstatcache(true, $to);
        if ($renamed || file_exists($to)) return 'moved';
        if (!file_exists($from)) return 'lost';
    }
    return 'failed';
}

function bbf_uploads_write_meta(string $path, array $meta): bool {
    try {
        $json = bbf_storage_json($meta);
    } catch (JsonException $error) {
        return false;
    }
    return bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json), 0640)
        || (PHP_OS_FAMILY === 'Windows' && bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json)));
}

function bbf_uploads_read_meta(string $path): ?array {
    clearstatcache(true, $path);
    if (!is_file($path)) return null;
    $meta = json_decode((string)@file_get_contents($path), true);
    return is_array($meta) && is_string($meta['file_id'] ?? null) ? $meta : null;
}

// ─── Garbage collection and recount ──────────────────────────────

/** A reservation is dead when its owner lock can be taken (or its lock file is gone). */
function bbf_uploads_reservation_dead(string $root, string $r, array $res): bool {
    if (($res['kind'] ?? 'upload') === 'restore') {
        $form = (string)($res['form'] ?? '');
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) return true;
        $lockPath = (string)($res['lock'] ?? '');
        if ($lockPath === '' || !is_file($lockPath)) return true;
    } else {
        $lockPath = "$root/staging/.incoming-$r.lock";
        if (!is_file($lockPath)) return true;
    }
    $fp = @fopen($lockPath, 'r+b');
    if (!$fp) return !is_file($lockPath);
    $free = flock($fp, LOCK_EX | LOCK_NB);
    if ($free) flock($fp, LOCK_UN);
    fclose($fp);
    return $free;
}

/**
 * Under the uploads lock: drop dead reservations (resolving their write-ahead entries), expire staging,
 * remove inconsistent pairs not listed in a write-ahead entry, then recount staging exactly.
 */
function bbf_uploads_gc_locked(string $root, array &$l, array $u): void {
    foreach ($l['reservations'] as $r => $res) {
        if (!bbf_uploads_reservation_dead($root, (string)$r, (array)$res)) continue;
        $owner = ($res['kind'] ?? 'upload') === 'restore' ? 'restore:' . ($res['form'] ?? '') : "r:$r";
        foreach ($l['wal'] as $id => $entry) {
            if (($entry['owner'] ?? null) === $owner) bbf_uploads_wal_settle($root, $l, (string)$id);
        }
        if (($res['kind'] ?? 'upload') === 'upload') {
            $incoming = "$root/staging/.incoming-$r";
            if (is_file($incoming) && !@unlink($incoming)) continue; // keep the reservation until the bytes are gone
            @unlink("$incoming.lock");
        }
        unset($l['reservations'][$r]);
    }
    $listed = bbf_uploads_wal_paths($l);
    $now = time();
    $staging = "$root/staging";
    $bytes = 0;
    $entries = 0;
    $perIp = [];
    foreach (scandir($staging) ?: [] as $name) {
        if (preg_match('/\A\.incoming-([a-f0-9]{16})(\.lock)?\z/', $name, $m)) {
            if (isset($l['reservations'][$m[1]])) continue;
            if (!empty($m[2]) && !bbf_uploads_reservation_dead($root, $m[1], ['kind' => 'upload'])) continue;
            @unlink("$staging/$name");
            continue;
        }
        if (preg_match('/\A([a-f0-9]{64})\.json\z/', $name, $m)) {
            if (!is_file("$staging/$m[1]") && !isset($listed["staging/$name"])) @unlink("$staging/$name");
            continue;
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/', $name)) continue;
        $rel = "staging/$name";
        $meta = bbf_uploads_read_meta("$staging/$name.json");
        if (!isset($listed[$rel]) && ($meta === null || (int)($meta['expires_at'] ?? 0) < $now)) {
            if (@unlink("$staging/$name")) {
                @unlink("$staging/$name.json");
                continue;
            }
        }
        $size = (int)@filesize("$staging/$name");
        $bytes += $size;
        $entries++;
        $ip = (string)($meta['ip_hmac'] ?? '');
        if ($ip !== '') {
            $perIp[$ip]['bytes'] = ($perIp[$ip]['bytes'] ?? 0) + $size;
            $perIp[$ip]['entries'] = ($perIp[$ip]['entries'] ?? 0) + 1;
        }
    }
    // Unresolved entries keep their charge on staging even where the bytes are elsewhere.
    foreach ($l['wal'] as $entry) {
        foreach ((array)($entry['items'] ?? []) as $item) {
            foreach (['from', 'to'] as $side) {
                if (($item[$side]['loc'] ?? '') !== 'staging' || is_file($root . '/' . $item[$side]['path'])) continue;
                $bytes += $item['bytes'];
                $entries++;
                if ($item['ip'] !== '') {
                    $perIp[$item['ip']]['bytes'] = ($perIp[$item['ip']]['bytes'] ?? 0) + $item['bytes'];
                    $perIp[$item['ip']]['entries'] = ($perIp[$item['ip']]['entries'] ?? 0) + 1;
                }
            }
        }
    }
    $l['staging'] = ['bytes' => $bytes, 'entries' => $entries, 'ip' => $perIp];
    $l['gc_at'] = $now;
}

/** Stored usage from the tree plus unresolved write-ahead charges. CLI and check.php only. */
function bbf_uploads_recount_stored_locked(string $root, array &$l): array {
    $bytes = 0;
    $files = 0;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $formDir) {
        $form = basename($formDir);
        if (in_array($form, ['staging', 'deleting', 'restore'], true) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) continue;
        foreach (glob($formDir . '/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            foreach (glob($subDir . '/f_*') ?: [] as $file) {
                if (!is_file($file)) continue;
                $bytes += (int)filesize($file);
                $files++;
            }
        }
    }
    foreach ($l['wal'] as $entry) {
        foreach ((array)($entry['items'] ?? []) as $item) {
            foreach (['from', 'to'] as $side) {
                if (($item[$side]['loc'] ?? '') !== 'stored' || is_file($root . '/' . $item[$side]['path'])) continue;
                $bytes += $item['bytes'];
                $files++;
            }
        }
    }
    foreach ($l['reservations'] as $res) {
        if (($res['kind'] ?? '') === 'restore') { $bytes += (int)$res['bytes']; $files += (int)($res['files'] ?? 0); }
    }
    $before = $l['stored'];
    $l['stored'] = ['bytes' => $bytes, 'files' => $files];
    return ['before' => $before, 'after' => $l['stored']];
}

/**
 * maintenance.php uploads-cleanup: finish deletion intents, GC, exact recount, report what needs a human.
 * $existsFor(form, id) and $fingerprintFor(form) come from the submit transaction module; without
 * them deletion intents and directories without records are left alone.
 */
function bbf_uploads_cleanup(array $config, ?callable $existsFor = null, ?callable $fingerprintFor = null): array {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return ['ok' => true, 'note' => 'No upload directory.'];
    $u = bbf_uploads_config($config);
    $restores = bbf_uploads_restore_sweep($config, $root);
    $pendingDeletions = $existsFor && $fingerprintFor ? bbf_uploads_deletion_sweep($root, $existsFor, $fingerprintFor) : [];
    // Directories without a record are reported, never deleted (a restored older database may still need them).
    $unreferenced = [];
    if ($existsFor) {
        $listed = [];
        foreach (glob("$root/deleting/*.json") ?: [] as $intentPath) {
            $state = json_decode((string)@file_get_contents($intentPath), true);
            foreach ((array)($state['submission_ids'] ?? []) as $id) $listed[($state['form'] ?? '') . '/' . $id] = true;
        }
        foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $formDir) {
            $form = basename($formDir);
            if (in_array($form, ['staging', 'deleting', 'restore'], true) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) continue;
            if (is_file("$root/restore/$form.json")) continue; // under restore
            foreach (glob("$formDir/*", GLOB_ONLYDIR) ?: [] as $subDir) {
                $id = basename($subDir);
                if (!isset($listed["$form/$id"]) && $existsFor($form, $id) === 'not_found') $unreferenced[] = "$form/$id";
            }
        }
    }
    $report = bbf_uploads_locked($root, static function () use ($root, $u): array {
        $l = bbf_uploads_ledger_read($root);
        bbf_uploads_gc_locked($root, $l, $u);
        $stored = bbf_uploads_recount_stored_locked($root, $l);
        $ok = bbf_uploads_ledger_write($root, $l);
        $wal = [];
        foreach ($l['wal'] as $id => $entry) $wal[] = ['id' => $id, 'op' => $entry['op'], 'owner' => $entry['owner'], 'created' => gmdate('c', (int)$entry['created'])];
        $live = [];
        foreach ($l['reservations'] as $r => $res) $live[] = ['r' => $r, 'kind' => $res['kind'] ?? 'upload', 'bytes' => (int)$res['bytes']];
        return ['ok' => $ok, 'staging' => $l['staging']['bytes'], 'staging_entries' => $l['staging']['entries'],
            'stored' => $stored['after'], 'stored_before_recount' => $stored['before'], 'unresolved_write_ahead' => $wal, 'live_reservations' => $live];
    });
    return $report + ['pending_deletions' => $pendingDeletions, 'directories_without_record' => $unreferenced,
        'unfinished_restores' => array_filter($restores, static fn($outcome) => in_array($outcome, ['running', 'records_pending', 'failed'], true))];
}

/**
 * check.php rows [name, pass, detail, level] (section 12). Recounts the ledger under the lock and
 * reports what needs a human; it never finishes deletions or restores. $existsFor(form, id) enables
 * the unreferenced-directory report (bounded to 200 directories).
 */
function bbf_uploads_diagnostics(array $config, ?callable $existsFor = null): array {
    $u = bbf_uploads_config($config);
    if (empty($u['enabled'])) return [['File uploads', true, 'Disabled (uploads.enabled = false).', 'info']];
    $rows = [];
    $mb = static fn($bytes) => bbf_uploads_human_size((int)$bytes);
    $rows[] = ['file_uploads = On', (bool)ini_get('file_uploads'), 'PHP must accept multipart uploads.', 'error'];
    $rows[] = ['fileinfo extension', class_exists('finfo'), 'Required: the file type is checked by content.', 'error'];
    $rows[] = ['ZipArchive extension', class_exists('ZipArchive'), 'Without it .docx, .xlsx, .odt and .ods are not accepted.', 'warn'];
    $rows[] = ['zlib.output_compression off', !ini_get('zlib.output_compression'), 'Downloads switch it off per request; on some hosts that is not allowed.', 'warn'];
    $effective = bbf_uploads_field_max_size($config, []);
    $rows[] = ['Effective file size limit', $effective >= (int)$u['max_file_size'],
        'uploads.max_file_size ' . $mb($u['max_file_size']) . ', effective ' . $mb($effective)
        . ' (upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . ').', 'warn'];
    $resolved = bbf_uploads_root($config);
    if (!$resolved['ok']) {
        $rows[] = ['Upload directory', false, $resolved['error'], 'error'];
        return $rows;
    }
    $root = $resolved['root'];
    $rows[] = ['Upload directory', !$resolved['inside'], $resolved['inside']
        ? 'Inside the web root (opted in); the HTTP probe confirmed files there are not served. Moving it outside is safer.'
        : 'Outside the web root.', 'warn'];
    $rows[] = ['Upload directory writable', is_writable($root) && is_writable("$root/staging"), $root, 'error'];
    if ((string)ini_get('open_basedir') !== '') $rows[] = ['Reachable under open_basedir', true, (string)ini_get('open_basedir'), 'info'];
    $free = function_exists('disk_free_space') ? @disk_free_space($root) : false;
    $rows[] = ['disk_free_space available', $free !== false, 'Without it the min_free_disk guard cannot work.', 'warn'];
    if ($free !== false) $rows[] = ['Free disk space', $free >= (int)$u['min_free_disk'], $mb($free) . ' free, uploads.min_free_disk ' . $mb($u['min_free_disk']) . ' (whole disk).', 'error'];
    try {
        $state = bbf_uploads_locked($root, static function () use ($root, $u): array {
            $l = bbf_uploads_ledger_read($root);
            bbf_uploads_gc_locked($root, $l, $u);
            bbf_uploads_recount_stored_locked($root, $l);
            if (!bbf_uploads_ledger_write($root, $l)) throw new RuntimeException('The recounted ledger could not be written.');
            return $l;
        });
    } catch (Throwable $error) {
        $rows[] = ['Upload ledger', false, $error->getMessage(), 'error'];
        return $rows;
    }
    foreach ([['Staging bytes', $state['staging']['bytes'], $u['max_staging_bytes'], true],
        ['Staging entries', $state['staging']['entries'], $u['max_staging_entries'], false],
        ['Stored bytes', $state['stored']['bytes'], $u['max_stored_bytes'], true],
        ['Stored files', $state['stored']['files'], $u['max_stored_files'], false]] as [$name, $used, $max, $bytes]) {
        $rows[] = [$name . ' below 80 %', $used < 0.8 * (int)$max,
            ($bytes ? $mb($used) . ' of ' . $mb($max) : "$used of $max") . ' (recounted).', 'warn'];
    }
    $rows[] = ['Unresolved write-ahead entries', $state['wal'] === [], count($state['wal']) . ' entry(ies); the owner\'s recovery or uploads-cleanup resolves them.', 'warn'];
    $live = array_count_values(array_map(static fn($res) => (string)($res['kind'] ?? 'upload'), $state['reservations']));
    $rows[] = ['Live reservations', true, ($live['upload'] ?? 0) . ' upload(s), ' . ($live['restore'] ?? 0) . ' restore(s); dead ones were dropped.', 'info'];
    $deletions = count(glob("$root/deleting/*.json") ?: []);
    $rows[] = ['Pending deletion intents', $deletions === 0, "$deletions; run php maintenance.php uploads-cleanup.", 'warn'];
    $restoring = [];
    foreach (glob("$root/restore/*.json") ?: [] as $path) $restoring[basename($path, '.json')] = true;
    $rows[] = ['Unfinished restores', $restoring === [], $restoring === [] ? 'None.'
        : implode(', ', array_keys($restoring)) . '; run uploads-cleanup, or php maintenance.php restore-abort --form=<id>.', 'warn'];
    if ($existsFor !== null) {
        $unreferenced = [];
        $seen = 0;
        foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $formDir) {
            $form = basename($formDir);
            if (in_array($form, ['staging', 'deleting', 'restore'], true) || isset($restoring[$form]) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) continue;
            foreach (glob("$formDir/*", GLOB_ONLYDIR) ?: [] as $subDir) {
                if (++$seen > 200) break 2;
                if ($existsFor($form, basename($subDir)) === 'not_found') $unreferenced[] = "$form/" . basename($subDir);
            }
        }
        $rows[] = ['Directories without a record', $unreferenced === [], $unreferenced === [] ? 'None' . ($seen > 200 ? ' among the first 200.' : '.')
            : implode(', ', array_slice($unreferenced, 0, 20)) . ' — reported only, never deleted automatically.', 'warn'];
    }
    return $rows;
}

// ─── Upload (section 4.2) ────────────────────────────────────────

function bbf_uploads_rate_limit(array $config, string $ip): bool {
    $u = bbf_uploads_config($config);
    $max = max(1, (int)($u['rate_limit']['max'] ?? 60));
    $window = max(1, (int)($u['rate_limit']['window'] ?? 600));
    $dir = (string)($config['logs_dir'] ?? __DIR__ . '/logs');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return true;
    $fp = @fopen($dir . '/ratelimit_upload_' . md5($ip) . '.json', 'c+');
    if (!$fp) return true;
    try {
        if (!flock($fp, LOCK_EX)) return true;
        $now = time();
        $entries = array_values(array_filter((array)json_decode((string)stream_get_contents($fp), true),
            static fn($t) => is_int($t) && $t > $now - $window));
        if (count($entries) >= $max) return false;
        $entries[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($entries));
        fflush($fp);
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function bbf_uploads_error_message(int $error): string {
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was received.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server cannot store uploads right now.',
        UPLOAD_ERR_EXTENSION => 'The server refused this upload.',
        default => 'The upload failed.',
    };
}

/** Structural plausibility filter for OOXML and ODF (section 6). Null = plausible, else the reason. */
function bbf_uploads_office_check(string $path, string $ext): ?string {
    if (!class_exists('ZipArchive')) return 'This file type needs the ZipArchive extension on the server.';
    $zip = new ZipArchive();
    if ($zip->open($path, defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0) !== true) return 'The file is not a valid document.';
    try {
        $count = $zip->numFiles;
        if ($count < 1 || $count > 2000) return 'The file is not a valid document.';
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $lower = strtolower($name);
            if (str_ends_with($lower, 'vbaproject.bin')) return 'Documents with macros cannot be uploaded.';
            if (in_array($ext, ['odt', 'ods'], true) && (str_starts_with($name, 'Basic/') || str_starts_with($name, 'Scripts/'))) {
                return 'Documents with macros cannot be uploaded.';
            }
            $found[$name] = true;
        }
        $required = match ($ext) {
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            default => ['mimetype'],
        };
        foreach ($required as $name) if (!isset($found[$name])) return 'The file is not a valid document.';
        if (in_array($ext, ['odt', 'ods'], true)) {
            $expected = $ext === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/vnd.oasis.opendocument.spreadsheet';
            if (trim((string)$zip->getFromName('mimetype', 100)) !== $expected) return 'The file is not a valid document.';
        }
        return null;
    } finally {
        $zip->close();
    }
}

/** Step 8 on PHP's temporary file: name, size, extension, content. */
function bbf_uploads_validate_file(array $config, array $field, string $tmp, string $clientName, int $size): array {
    $name = bbf_uploads_sanitize_name($clientName);
    $ext = bbf_uploads_name_ext($name);
    if ($size <= 0) return ['ok' => false, 'code' => 422, 'message' => 'The file is empty.'];
    $max = bbf_uploads_field_max_size($config, $field);
    if ($size > $max) return ['ok' => false, 'code' => 413, 'message' => 'The file is larger than ' . bbf_uploads_human_size($max) . '.'];
    if ($ext === '' || bbf_uploads_hard_denied($ext, $name) || !in_array($ext, bbf_uploads_field_accept($config, $field), true)) {
        return ['ok' => false, 'code' => 422, 'message' => 'This file type is not accepted here.'];
    }
    if (!class_exists('finfo')) return ['ok' => false, 'code' => 503, 'message' => 'The server cannot check uploads (fileinfo is missing).'];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    // A password-protected OOXML file is an OLE compound file, not a ZIP.
    if (in_array($ext, ['docx', 'xlsx'], true) && (str_starts_with($mime, 'application/CDFV2')
        || in_array($mime, ['application/encrypted', 'application/x-ole-storage'], true))) {
        return ['ok' => false, 'code' => 422, 'message' => 'Encrypted documents cannot be uploaded. Please remove the password and try again.'];
    }
    if (!in_array($mime, BBF_UPLOAD_TYPES[$ext][1], true)) {
        return ['ok' => false, 'code' => 422, 'message' => 'The file content does not match its type.'];
    }
    if (BBF_UPLOAD_TYPES[$ext][2]) {
        $reason = bbf_uploads_office_check($tmp, $ext);
        if ($reason !== null) return ['ok' => false, 'code' => 422, 'message' => $reason];
    }
    $sha = hash_file('sha256', $tmp);
    if (!is_string($sha)) return ['ok' => false, 'code' => 503, 'message' => 'The upload could not be read.'];
    return ['ok' => true, 'name' => $name, 'ext' => $ext, 'size' => $size, 'type' => BBF_UPLOAD_TYPES[$ext][0], 'sha256' => $sha];
}

/** Capacity decision for a new reservation: null = admitted, otherwise the refused limit. */
function bbf_uploads_admit(array $l, array $u, string $root, int $size, string $ip): ?string {
    $reservedBytes = $reservedCount = $ipBytes = $ipCount = 0;
    foreach ($l['reservations'] as $res) {
        if (($res['kind'] ?? '') !== 'upload') continue;
        $reservedBytes += (int)$res['bytes'];
        $reservedCount++;
        if (($res['ip_hmac'] ?? '') === $ip) { $ipBytes += (int)$res['bytes']; $ipCount++; }
    }
    $mine = $l['staging']['ip'][$ip] ?? ['bytes' => 0, 'entries' => 0];
    if ($l['staging']['bytes'] + $reservedBytes + $size > (int)$u['max_staging_bytes']
        || $l['staging']['entries'] + $reservedCount + 1 > (int)$u['max_staging_entries']) return 'staging';
    if ($mine['bytes'] + $ipBytes + $size > (int)$u['per_ip_staging_bytes']
        || $mine['entries'] + $ipCount + 1 > (int)$u['per_ip_staging_entries']) return 'ip';
    $free = function_exists('disk_free_space') ? @disk_free_space($root) : false;
    if ($free !== false && $free - $size < (int)$u['min_free_disk']) return 'disk';
    return null;
}

/** Notify the admin at most once per day per limit. Mail is sent after the lock is released. */
function bbf_uploads_limit_notice(array &$l, string $limit): ?string {
    $day = date('Y-m-d');
    if (($l['notified'][$limit] ?? '') === $day) return null;
    $l['notified'][$limit] = $day;
    return $limit;
}

/**
 * Steps 9–11: reserve, move PHP's temp file into staging, publish. Returns the token response
 * or ['ok' => false, 'code', 'message'].
 */
function bbf_uploads_store(array $config, string $root, string $formId, string $fieldName, string $tmp, array $file, string $ip, bool $isUploaded = true): array {
    $u = bbf_uploads_config($config);
    $ipHmac = bbf_uploads_ip_hmac($root, $ip);
    $r = bin2hex(random_bytes(8));
    $incoming = "$root/staging/.incoming-$r";
    $ownerLock = null;
    $notice = null;
    try {
        // Step 9: capacity and owned reservation.
        $reserved = bbf_uploads_locked($root, static function () use ($root, $u, $file, $ipHmac, $r, $incoming, &$ownerLock, &$notice) {
            $l = bbf_uploads_ledger_read($root);
            if (time() - (int)$l['gc_at'] > 300) bbf_uploads_gc_locked($root, $l, $u);
            $refused = bbf_uploads_admit($l, $u, $root, $file['size'], $ipHmac);
            if ($refused === 'staging' || $refused === 'ip') {
                bbf_uploads_gc_locked($root, $l, $u); // leaked reservations of crashed requests cannot block uploads
                $refused = bbf_uploads_admit($l, $u, $root, $file['size'], $ipHmac);
            }
            if ($refused !== null) {
                if ($refused !== 'ip') $notice = bbf_uploads_limit_notice($l, $refused);
                bbf_uploads_ledger_write($root, $l);
                return $refused;
            }
            $lock = @fopen("$incoming.lock", 'xb');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'io';
            $l['reservations'][$r] = ['kind' => 'upload', 'bytes' => $file['size'], 'ip_hmac' => $ipHmac, 'created' => time()];
            if (!bbf_uploads_hook('reserved') || !bbf_uploads_ledger_write($root, $l)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink("$incoming.lock");
                return 'io';
            }
            $ownerLock = $lock;
            return null;
        });
        if ($notice !== null) bbfNotifyError('uploads', 'Upload limit reached', "The $notice upload limit was reached; uploads are being refused.", $config);
        if ($reserved !== null) {
            return match ($reserved) {
                'ip' => ['ok' => false, 'code' => 429, 'message' => 'Too many files uploaded from your connection. Please try again later.'],
                'io' => ['ok' => false, 'code' => 503, 'message' => 'Temporary storage problem. Please try again.'],
                default => ['ok' => false, 'code' => 507, 'message' => 'The server cannot accept more uploads right now. Please try again later.'],
            };
        }
        // Step 10: outside the lock; the reservation covers the bytes being written.
        $moved = bbf_uploads_hook('before_incoming') && ($isUploaded ? @move_uploaded_file($tmp, $incoming) : @copy($tmp, $incoming));
        if (!bbf_uploads_hook('after_incoming')) $moved = false;
        @chmod($incoming, 0640);
        // Step 11: publish.
        $token = bin2hex(random_bytes(16));
        $hash = hash('sha256', $token);
        $now = time();
        $meta = ['v' => 1, 'form' => $formId, 'field' => $fieldName, 'name' => $file['name'], 'size' => $file['size'],
            'type' => $file['type'], 'ext' => $file['ext'], 'sha256' => $file['sha256'], 'file_id' => 'f_' . bin2hex(random_bytes(8)),
            'created' => $now, 'expires_at' => $now + max(60, (int)$u['staging_ttl']), 'ip_hmac' => $ipHmac];
        $published = bbf_uploads_locked($root, static function () use ($root, $r, $incoming, $hash, $meta, $moved, $ipHmac, &$ownerLock): bool {
            $l = bbf_uploads_ledger_read($root);
            $ok = false;
            if ($moved && is_file($incoming) && filesize($incoming) === $meta['size']) {
                $item = ['from' => ['loc' => 'none', 'path' => ''], 'to' => ['loc' => 'staging', 'path' => "staging/$hash"],
                    'bytes' => $meta['size'], 'ip' => $ipHmac, 'meta' => $meta, 'meta_path' => "staging/$hash.json"];
                $wal = bbf_uploads_wal_begin($root, $l, 'publish', "r:$r", [$item]);
                if ($wal !== null) {
                    $ok = bbf_uploads_write_meta("$root/staging/$hash.json", $meta)
                        && bbf_uploads_hook('publish_meta') && bbf_uploads_move($incoming, "$root/staging/$hash") === 'moved';
                    bbf_uploads_wal_settle($root, $l, $wal);
                    $ok = $ok && is_file("$root/staging/$hash") && is_file("$root/staging/$hash.json");
                }
            }
            clearstatcache(true, $incoming);
            if (!$ok && is_file($incoming) && !@unlink($incoming)) {
                // Keep the reservation: its owner lock is released at request end, GC removes both.
                bbf_uploads_ledger_write($root, $l);
                return false;
            }
            unset($l['reservations'][$r]);
            if (!bbf_uploads_ledger_write($root, $l)) return $ok; // the write-ahead charge stays until the reservation is found dead
            if (is_resource($ownerLock)) { flock($ownerLock, LOCK_UN); fclose($ownerLock); }
            $ownerLock = null;
            @unlink("$incoming.lock");
            return $ok;
        });
        if (!$published) return ['ok' => false, 'code' => 503, 'message' => 'Temporary storage problem. Please try again.'];
        return ['ok' => true, 'token' => $token, 'expires_at' => gmdate('c', $meta['expires_at']),
            'file' => ['name' => $meta['name'], 'size' => $meta['size'], 'type' => $meta['type']]];
    } finally {
        if (is_resource($ownerLock)) { flock($ownerLock, LOCK_UN); fclose($ownerLock); }
    }
}

/** action=upload_delete: remove one staged entry of this form. */
function bbf_uploads_delete_staged(array $config, string $root, string $formId, string $token): array {
    $hash = hash('sha256', $token);
    return bbf_uploads_locked($root, static function () use ($root, $formId, $hash): array {
        $l = bbf_uploads_ledger_read($root);
        $bytes = "$root/staging/$hash";
        $metaPath = "$bytes.json";
        $meta = bbf_uploads_read_meta($metaPath);
        if ($meta === null || ($meta['form'] ?? null) !== $formId) return ['ok' => false, 'code' => 404, 'message' => 'Upload not found.'];
        $listed = bbf_uploads_wal_paths($l);
        if (isset($listed["staging/$hash"]) || isset($listed["staging/$hash.json"])) {
            return ['ok' => false, 'code' => 503, 'message' => 'This file is busy. Please try again.', 'retry_after' => 5];
        }
        $unlinked = !is_file($bytes) || (bbf_uploads_hook('unlink') && @unlink($bytes));
        @unlink($metaPath);
        if ($unlinked) bbf_uploads_charge($l, 'staging', (int)$meta['size'], (string)($meta['ip_hmac'] ?? ''), -1);
        bbf_uploads_ledger_write($root, $l);
        return ['ok' => true];
    });
}

// ─── Sandbox tokens (section 4.8) ────────────────────────────────

function bbf_uploads_b64(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function bbf_uploads_sandbox_key(string $root): string {
    return hash_hmac('sha256', 'bbf-upload-sandbox', bbf_uploads_secret($root));
}

function bbf_uploads_sandbox_token(string $root, array $payload): string {
    $body = bbf_uploads_b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return 'sbx.' . $body . '.' . hash_hmac('sha256', $body, bbf_uploads_sandbox_key($root));
}

function bbf_uploads_sandbox_verify(string $root, $token, string $formId, string $field): ?array {
    if (!is_string($token) || !preg_match('/\Asbx\.([A-Za-z0-9_-]{1,2000})\.([a-f0-9]{64})\z/D', $token, $m)) return null;
    if (!hash_equals(hash_hmac('sha256', $m[1], bbf_uploads_sandbox_key($root)), $m[2])) return null;
    $payload = json_decode((string)base64_decode(strtr($m[1], '-_', '+/')), true);
    if (!is_array($payload) || ($payload['form'] ?? null) !== $formId || ($payload['field'] ?? null) !== $field
        || (int)($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

// ─── Submit: plan (step C), claim (step D), rollback (section 4.4) ─

/** Owner string of a submit transaction in write-ahead entries. */
function bbf_uploads_tx_owner(string $formId, string $k): string {
    return "tx:$formId:$k";
}

/**
 * Step C: resolve the tokens of visible file fields against staging (or signed sandbox tokens)
 * and the definition used for this submit. Replaces token lists in $data with descriptors.
 * ['ok' => true, 'plan' => ?array] | ['ok' => false, 'code' => 422|503, 'errors' => [...]]
 */
function bbf_uploads_plan(array $config, string $formId, string $submissionId, array $fileFields, array &$data, bool $sandbox): array {
    $wanted = [];
    foreach ($fileFields as $name => $field) {
        if (!array_key_exists($name, $data)) continue;
        $tokens = is_array($data[$name]) ? array_values($data[$name]) : ($data[$name] === '' ? [] : [$data[$name]]);
        if ($tokens === []) { $data[$name] = []; continue; }
        $wanted[$name] = $tokens;
    }
    if ($wanted === []) return ['ok' => true, 'plan' => null];
    $root = bbf_uploads_root($config, !$sandbox);
    if (!$root['ok']) return ['ok' => false, 'code' => 503, 'errors' => ['_uploads' => $root['error']]];
    $root = $root['root'];
    $u = bbf_uploads_config($config);
    $errors = [];
    $items = [];
    $total = 0;
    $again = 'The uploaded file expired or is no longer available. Please upload it again.';
    foreach ($wanted as $name => $tokens) {
        $field = $fileFields[$name];
        $label = (string)($field['label'] ?? $name);
        $accept = bbf_uploads_field_accept($config, $field);
        $max = bbf_uploads_field_max_size($config, $field);
        $descriptors = [];
        foreach ($tokens as $token) {
            if ($sandbox) {
                $meta = bbf_uploads_sandbox_verify($root, $token, $formId, $name);
                if ($meta === null) { $errors[$name] = $again; break; }
                $meta += ['file_id' => 'f_' . substr(hash('sha256', (string)$token), 0, 16), 'sha256' => ''];
            } else {
                if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) { $errors[$name] = $again; break; }
                $hash = hash('sha256', $token);
                $meta = bbf_uploads_read_meta("$root/staging/$hash.json");
                if ($meta === null || ($meta['form'] ?? null) !== $formId || ($meta['field'] ?? null) !== $name
                    || (int)($meta['expires_at'] ?? 0) < time() || !is_file("$root/staging/$hash")) { $errors[$name] = $again; break; }
                $items[] = ['hash' => $hash, 'file_id' => $meta['file_id'], 'meta' => $meta];
            }
            if (!in_array((string)($meta['ext'] ?? ''), $accept, true)) { $errors[$name] = "$label: this file type is no longer accepted. Please upload a different file."; break; }
            if ((int)$meta['size'] > $max) { $errors[$name] = "$label: the file is larger than " . bbf_uploads_human_size($max) . '.'; break; }
            $total += (int)$meta['size'];
            $descriptors[] = ['id' => $meta['file_id'], 'name' => $meta['name'], 'size' => (int)$meta['size'],
                'type' => $meta['type'], 'sha256' => $meta['sha256']];
        }
        $data[$name] = $descriptors;
    }
    if ($errors === [] && $total > (int)$u['max_submission_size']) {
        $errors['_uploads'] = 'The files together are larger than ' . bbf_uploads_human_size((int)$u['max_submission_size']) . '.';
    }
    if ($errors !== []) return ['ok' => false, 'code' => 422, 'errors' => $errors];
    return ['ok' => true, 'plan' => $sandbox || $items === [] ? null : ['dir' => "$formId/$submissionId", 'items' => $items]];
}

/**
 * Step D: move every planned entry into <form>/<submission_id>/ in one critical section.
 * ['ok' => true] | ['ok' => false, 'code' => 422|503|507, 'undone' => bool, 'message']
 */
function bbf_uploads_claim(array $config, string $owner, array $plan): array {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return ['ok' => false, 'code' => 503, 'undone' => true, 'message' => 'Temporary storage problem. Please try again.'];
    $u = bbf_uploads_config($config);
    $notice = null;
    try {
        $result = bbf_uploads_locked($root, static function () use ($root, $u, $owner, $plan, &$notice): array {
            $l = bbf_uploads_ledger_read($root);
            foreach ($l['wal'] as $id => $entry) if (($entry['owner'] ?? null) === $owner) bbf_uploads_wal_settle($root, $l, (string)$id);
            $listed = bbf_uploads_wal_paths($l, $owner);
            $again = 'The uploaded file expired or is no longer available. Please upload it again.';
            $items = [];
            $total = 0;
            foreach ($plan['items'] as $p) {
                $rel = 'staging/' . $p['hash'];
                if (isset($listed[$rel]) || isset($listed["$rel.json"])) {
                    return ['ok' => false, 'code' => 503, 'undone' => true, 'retry_after' => 5, 'message' => 'Your files are busy. Please try again in a moment.'];
                }
                $meta = bbf_uploads_read_meta("$root/$rel.json");
                if ($meta === null || !is_file("$root/$rel") || $meta['file_id'] !== $p['file_id'] || ($meta['form'] ?? null) !== ($p['meta']['form'] ?? '')
                    || (int)$meta['size'] !== (int)$p['meta']['size'] || (int)($meta['expires_at'] ?? 0) < time()) {
                    return ['ok' => false, 'code' => 422, 'undone' => true, 'message' => $again];
                }
                $items[] = ['from' => ['loc' => 'staging', 'path' => $rel], 'to' => ['loc' => 'stored', 'path' => $plan['dir'] . '/' . $p['file_id']],
                    'bytes' => (int)$meta['size'], 'ip' => (string)($meta['ip_hmac'] ?? ''), 'meta' => $meta, 'meta_path' => "$rel.json"];
                $total += (int)$meta['size'];
            }
            [$storedBytes, $storedFiles] = bbf_uploads_stored_committed($l);
            if ($storedBytes + $total > (int)$u['max_stored_bytes'] || $storedFiles + count($items) > (int)$u['max_stored_files']) {
                $notice = bbf_uploads_limit_notice($l, 'stored');
                bbf_uploads_ledger_write($root, $l);
                return ['ok' => false, 'code' => 507, 'undone' => true, 'message' => 'The server cannot store more files right now. Please try again later.'];
            }
            $wal = bbf_uploads_wal_begin($root, $l, 'claim', $owner, $items);
            if ($wal === null) return ['ok' => false, 'code' => 503, 'undone' => true, 'message' => 'Temporary storage problem. Please try again.'];
            $target = $root . '/' . $plan['dir'];
            $failed = null;
            if (!bbf_uploads_mkdir(dirname($target)) || !bbf_uploads_hook('claim_mkdir') || !@mkdir($target, 0750)) {
                $failed = 'failed';
            } else {
                $moved = [];
                foreach ($items as $item) {
                    $outcome = bbf_uploads_hook('claim_move') ? bbf_uploads_move("$root/{$item['from']['path']}", "$root/{$item['to']['path']}") : 'failed';
                    if ($outcome !== 'moved') { $failed = $outcome; break; }
                    $moved[] = $item;
                }
                if ($failed === null) {
                    bbf_uploads_hook('claim_moved');
                    bbf_uploads_wal_settle($root, $l, $wal);
                    bbf_uploads_ledger_write($root, $l); // on failure the entry stays for this owner's recovery
                    return ['ok' => true];
                }
                // Undo our own moves before releasing the lock.
                $undone = $failed !== 'lost';
                foreach (array_reverse($moved) as $item) {
                    if (!bbf_uploads_hook('claim_undo') || bbf_uploads_move("$root/{$item['to']['path']}", "$root/{$item['from']['path']}") !== 'moved') $undone = false;
                }
                if (!$undone) {
                    error_log("BareBonesForms uploads: claim undo incomplete for $owner; the transaction stays open.");
                    return ['ok' => false, 'code' => 503, 'undone' => false, 'message' => 'Temporary storage problem. Please try again.'];
                }
            }
            @rmdir($target);
            bbf_uploads_wal_settle($root, $l, $wal);
            bbf_uploads_ledger_write($root, $l); // on failure the entry stays: an over-count this owner resolves later
            return ['ok' => false, 'code' => 503, 'undone' => true, 'message' => 'Temporary storage problem. Please try again.'];
        });
    } catch (Throwable $error) {
        error_log('BareBonesForms uploads: claim failed: ' . $error->getMessage());
        return ['ok' => false, 'code' => 503, 'undone' => true, 'message' => 'Temporary storage problem. Please try again.'];
    }
    if ($notice !== null) bbf_tx_notice('uploads', 'Upload limit reached', 'The stored-files limit was reached; submissions with files are being refused.');
    return $result;
}

/**
 * Rollback of a claimed plan (step F or recovery): files go back to staging while unexpired,
 * expired ones are unlinked. False = not complete; the transaction must stay open.
 */
function bbf_uploads_rollback(array $config, string $owner, array $plan): bool {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return !is_dir((string)bbf_uploads_config($config)['dir']);
    try {
        return bbf_uploads_locked($root, static function () use ($root, $owner, $plan): bool {
            $l = bbf_uploads_ledger_read($root);
            foreach ($l['wal'] as $id => $entry) if (($entry['owner'] ?? null) === $owner) bbf_uploads_wal_settle($root, $l, (string)$id);
            $now = time();
            $items = [];
            $expired = [];
            foreach ($plan['items'] as $p) {
                $final = $plan['dir'] . '/' . $p['file_id'];
                if (!is_file("$root/$final")) continue; // never moved, or removed by a deletion
                if ((int)($p['meta']['expires_at'] ?? 0) < $now) { $expired[] = [$final, (int)$p['meta']['size']]; continue; }
                $items[] = ['from' => ['loc' => 'stored', 'path' => $final], 'to' => ['loc' => 'staging', 'path' => 'staging/' . $p['hash']],
                    'bytes' => (int)$p['meta']['size'], 'ip' => (string)($p['meta']['ip_hmac'] ?? ''), 'meta' => $p['meta'],
                    'meta_path' => 'staging/' . $p['hash'] . '.json'];
            }
            $ok = true;
            foreach ($expired as [$final, $size]) {
                if (@unlink("$root/$final")) bbf_uploads_charge($l, 'stored', $size, '', -1);
                else $ok = false;
            }
            if ($items !== []) {
                $wal = bbf_uploads_wal_begin($root, $l, 'rollback', $owner, $items);
                if ($wal === null) return false;
                foreach ($items as $item) {
                    $moved = bbf_uploads_write_meta("$root/{$item['meta_path']}", $item['meta']) && bbf_uploads_hook('rollback_move')
                        && bbf_uploads_move("$root/{$item['from']['path']}", "$root/{$item['to']['path']}") === 'moved';
                    if (!$moved) { $ok = false; break; }
                }
                if (!$ok) return false; // the write-ahead entry stays; recovery settles it and retries
                bbf_uploads_hook('rollback_moved');
                bbf_uploads_wal_settle($root, $l, $wal);
            }
            if ($ok) @rmdir($root . '/' . $plan['dir']);
            return bbf_uploads_ledger_write($root, $l) && $ok;
        });
    } catch (Throwable $error) {
        error_log('BareBonesForms uploads: rollback failed: ' . $error->getMessage());
        return false;
    }
}

// ─── Deleting submissions (section 10) ───────────────────────────

/**
 * Step 1: one deletion intent per call, listing only IDs that have an upload directory, written
 * under the uploads lock. Its own lock (held by the caller until it finishes) is its liveness.
 * Returns null when there is nothing to do; throws when the intent cannot be written.
 */
function bbf_uploads_deletion_begin(array $config, string $formId, array $ids, string $fingerprint): ?array {
    $root = bbf_uploads_existing_root($config);
    if ($root === null || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) return null;
    $withFiles = [];
    foreach ($ids as $id) {
        if (is_string($id) && preg_match('/\A[a-zA-Z0-9_]+\z/', $id) && is_dir("$root/$formId/$id")) $withFiles[$id] = $id;
    }
    if ($withFiles === []) return null;
    $dir = "$root/deleting";
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('Cannot create the deletion directory.');
    $batch = bin2hex(random_bytes(8));
    $lock = @fopen("$dir/$batch.lock", 'xb');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Cannot lock the deletion intent.');
    $intent = ['root' => $root, 'batch' => $batch, 'lock' => $lock];
    $state = ['form' => $formId, 'submission_ids' => array_values($withFiles), 'storage_fingerprint' => $fingerprint, 'created' => time()];
    $written = bbf_uploads_locked($root, static fn() => bbf_uploads_hook('deletion_intent')
        && bbf_uploads_write_meta("$dir/$batch.json", $state));
    if (!$written) {
        bbf_uploads_deletion_release($intent, true);
        throw new RuntimeException('Cannot write the deletion intent.');
    }
    bbf_uploads_fsync_dir($dir);
    return $intent;
}

function bbf_uploads_deletion_release(array $intent, bool $removeLock): void {
    if (is_resource($intent['lock'] ?? null)) {
        flock($intent['lock'], LOCK_UN);
        fclose($intent['lock']);
    }
    if ($removeLock) @unlink("{$intent['root']}/deleting/{$intent['batch']}.lock");
}

/**
 * Steps 3–4, also used by cleanup; the caller holds the intent's lock. Per listed ID:
 * record 'not_found' → unlink its files in chunks of up to 200 per uploads-lock section with
 * ledger updates, then its directory; 'exists' → the deletion never reached it, keep the files;
 * 'unavailable' or a changed storage fingerprint → touch nothing and keep the intent.
 * $exists(string $id): 'exists'|'not_found'|'unavailable'. Returns true when the intent is gone.
 */
function bbf_uploads_deletion_process(string $root, string $batch, callable $exists, string $fingerprint): bool {
    $path = "$root/deleting/$batch.json";
    clearstatcache(true, $path);
    if (!is_file($path)) return true; // died between the lock and the state: there never was an intent
    $state = json_decode((string)@file_get_contents($path), true);
    $form = (string)($state['form'] ?? '');
    if (!is_array($state['submission_ids'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) return false;
    if (($state['storage_fingerprint'] ?? null) !== $fingerprint) return false;
    $gone = [];
    foreach ($state['submission_ids'] as $id) {
        if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $id)) continue;
        $existence = $exists($id);
        if ($existence === 'unavailable') return false;
        if ($existence === 'not_found') $gone[] = $id;
    }
    foreach ($gone as $id) {
        $subDir = "$root/$form/$id";
        foreach (array_chunk(glob("$subDir/f_*") ?: [], 200) as $chunk) {
            if (!bbf_uploads_hook('deletion_files')) return false;
            $written = bbf_uploads_locked($root, static function () use ($root, $chunk): bool {
                $l = bbf_uploads_ledger_read($root);
                foreach ($chunk as $file) {
                    clearstatcache(true, $file);
                    $size = (int)@filesize($file);
                    if (@unlink($file)) bbf_uploads_charge($l, 'stored', $size, '', -1);
                }
                return bbf_uploads_ledger_write($root, $l); // a failed write over-counts; the recount corrects it
            });
            if (!$written) return false;
        }
        foreach (scandir($subDir) ?: [] as $name) if (is_file("$subDir/$name")) @unlink("$subDir/$name");
        @rmdir($subDir);
        clearstatcache(true, $subDir);
        if (is_dir($subDir)) return false;
    }
    if (!@unlink($path) && is_file($path)) return false;
    bbf_uploads_fsync_dir("$root/deleting");
    return true;
}

/** Steps 3–4 for the intent this request created; leftovers are finished by uploads-cleanup. */
function bbf_uploads_deletion_finish(array $intent, callable $exists, string $fingerprint): bool {
    try {
        $done = bbf_uploads_deletion_process($intent['root'], $intent['batch'], $exists, $fingerprint);
    } catch (Throwable $error) {
        error_log('BareBonesForms uploads: deletion left for cleanup: ' . $error->getMessage());
        $done = false;
    }
    bbf_uploads_deletion_release($intent, $done);
    return $done;
}

/** Cleanup: finish every deletion intent whose lock can be taken. Returns the batches still pending. */
function bbf_uploads_deletion_sweep(string $root, callable $existsFor, callable $fingerprintFor): array {
    $pending = [];
    foreach (glob("$root/deleting/*.lock") ?: [] as $lockPath) {
        $batch = basename($lockPath, '.lock');
        if (!preg_match('/\A[a-f0-9]{16}\z/', $batch)) continue;
        $lock = @fopen($lockPath, 'r+b');
        if (!$lock) continue;
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $pending[] = $batch; continue; }
        $state = json_decode((string)@file_get_contents("$root/deleting/$batch.json"), true);
        $form = is_array($state) ? (string)($state['form'] ?? '') : '';
        try {
            $done = $form === '' && !is_file("$root/deleting/$batch.json")
                ? true
                : bbf_uploads_deletion_process($root, $batch, static fn(string $id) => $existsFor($form, $id), $fingerprintFor($form));
        } catch (Throwable $error) {
            error_log('BareBonesForms uploads: deletion cleanup failed: ' . $error->getMessage());
            $done = false;
        }
        bbf_uploads_deletion_release(['root' => $root, 'batch' => $batch, 'lock' => $lock], $done);
        if (!$done) $pending[] = $batch;
    }
    return $pending;
}

// ─── Backup and restore (section 10) ─────────────────────────────

/** Stored usage plus bytes and files reserved by running restores: what a new claim must fit beside. */
function bbf_uploads_stored_committed(array $l): array {
    $bytes = (int)$l['stored']['bytes'];
    $files = (int)$l['stored']['files'];
    foreach ($l['reservations'] as $res) {
        if (($res['kind'] ?? '') !== 'restore') continue;
        $bytes += (int)($res['bytes'] ?? 0);
        $files += (int)($res['files'] ?? 0);
    }
    return [$bytes, $files];
}

/** Every uploaded file descriptor in a record. */
function bbf_uploads_record_files(array $record): array {
    $files = [];
    foreach ((array)($record['data'] ?? []) as $value) {
        if (!bbf_uploads_is_descriptor_list($value)) continue;
        foreach ($value as $descriptor) $files[] = $descriptor;
    }
    return $files;
}

/** Streamed copy, hashed while copying; the target is removed unless the hash matches. */
function bbf_uploads_copy_verified(string $source, string $target, string $sha256): bool {
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $sha256)) return false;
    $in = @fopen($source, 'rb');
    $out = $in ? @fopen($target, 'wb') : false;
    $hash = hash_init('sha256');
    $ok = $in && $out;
    while ($ok && !feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === false) { $ok = false; break; }
        hash_update($hash, $chunk);
        $ok = bbf_storage_write_all($out, $chunk);
    }
    if (is_resource($in)) fclose($in);
    if (is_resource($out)) { $ok = fflush($out) && $ok; fclose($out); }
    $ok = $ok && hash_equals($sha256, hash_final($hash));
    if ($ok) @chmod($target, 0600);
    elseif ($out) @unlink($target);
    return $ok;
}

/** submission_id => file_id => {size, sha256} of every file referenced by the records. */
function bbf_uploads_backup_manifest(array $records): array {
    $manifest = [];
    foreach ($records as $id => $record) {
        foreach (bbf_uploads_record_files((array)$record) as $descriptor) {
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/', (string)$id) || !preg_match('/\A[a-f0-9]{64}\z/D', (string)($descriptor['sha256'] ?? ''))) {
                throw new RuntimeException('Invalid file descriptor in backup record.');
            }
            $manifest[(string)$id][$descriptor['id']] = ['size' => max(0, (int)($descriptor['size'] ?? 0)), 'sha256' => $descriptor['sha256']];
        }
    }
    ksort($manifest, SORT_STRING);
    return $manifest;
}

function bbf_uploads_manifest_totals(array $manifest): array {
    $bytes = 0;
    $files = 0;
    foreach ($manifest as $entries) foreach ($entries as $entry) { $bytes += (int)$entry['size']; $files++; }
    return [$bytes, $files];
}

function bbf_uploads_remove_tree(string $dir): void {
    if (is_link($dir) || !is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = "$dir/$name";
        if (is_dir($path) && !is_link($path)) bbf_uploads_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

/** Backup sidecar <bundle>.files/<submission_id>/<file_id>: written complete under a temporary name, then renamed. */
function bbf_uploads_backup_files(array $config, string $formId, array $manifest, string $filesDir): void {
    if ($manifest === []) return;
    if (is_dir($filesDir)) {
        // Same bundle name means the same payload; the sidecar must still verify.
        foreach ($manifest as $id => $entries) foreach ($entries as $fileId => $entry) {
            if (!is_file("$filesDir/$id/$fileId") || !hash_equals($entry['sha256'], (string)hash_file('sha256', "$filesDir/$id/$fileId"))) {
                throw new RuntimeException('Existing backup file sidecar does not match.');
            }
        }
        return;
    }
    $tmp = $filesDir . '.tmp-' . bin2hex(random_bytes(6));
    try {
        foreach ($manifest as $id => $entries) {
            if (!@mkdir("$tmp/$id", 0700, true)) throw new RuntimeException('Cannot create the backup file sidecar.');
            foreach ($entries as $fileId => $entry) {
                $source = bbf_upload_path($config, $formId, (string)$id, (string)$fileId);
                if ($source === null) throw new RuntimeException("Backup: file $fileId of $id is missing.");
                if (!bbf_uploads_copy_verified($source, "$tmp/$id/$fileId", $entry['sha256'])) {
                    throw new RuntimeException("Backup: file $fileId of $id could not be copied intact.");
                }
            }
        }
        if (!@rename($tmp, $filesDir)) throw new RuntimeException('Cannot publish the backup file sidecar.');
    } catch (Throwable $error) {
        bbf_uploads_remove_tree($tmp);
        throw $error;
    }
}

function bbf_uploads_restore_state(string $root, string $formId): ?array {
    $path = "$root/restore/$formId.json";
    clearstatcache(true, $path);
    if (!is_file($path)) return null;
    $state = json_decode((string)@file_get_contents($path), true);
    return is_array($state) ? $state : ['phase' => 'unreadable'];
}

/** Restore needs an empty uploads target too: no <form>/ directory and no unfinished restore state. */
function bbf_uploads_restore_target_empty(array $config, string $formId): bool {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return true;
    return !file_exists("$root/$formId") && !file_exists("$root/restore/$formId.json") && !file_exists("$root/restore/$formId.tmp");
}

/** Dry run (step 1): every sidecar file present with the right size and hash, uploads enabled, capacity available. */
function bbf_uploads_restore_check(array $config, array $manifest, string $filesDir): array {
    [$bytes, $files] = bbf_uploads_manifest_totals($manifest);
    $result = ['ok' => true, 'error' => null, 'files' => $files, 'bytes' => $bytes];
    if ($manifest === []) return $result;
    $u = bbf_uploads_config($config);
    if (empty($u['enabled'])) return ['ok' => false, 'error' => 'The bundle contains files, but uploads are not enabled.'] + $result;
    foreach ($manifest as $id => $entries) foreach ($entries as $fileId => $entry) {
        $path = "$filesDir/$id/$fileId";
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) return ['ok' => false, 'error' => "Sidecar file $id/$fileId is missing."] + $result;
        if (filesize($path) !== (int)$entry['size'] || !hash_equals($entry['sha256'], (string)hash_file('sha256', $path))) {
            return ['ok' => false, 'error' => "Sidecar file $id/$fileId does not match its descriptor."] + $result;
        }
    }
    $root = bbf_uploads_existing_root($config);
    $l = $root !== null ? bbf_uploads_ledger_read($root) : bbf_uploads_ledger_empty();
    [$storedBytes, $storedFiles] = bbf_uploads_stored_committed($l);
    if ($storedBytes + $bytes > (int)$u['max_stored_bytes'] || $storedFiles + $files > (int)$u['max_stored_files']) {
        return ['ok' => false, 'error' => 'Not enough stored-file capacity for this restore.'] + $result;
    }
    return $result;
}

/**
 * Steps 2–4. The caller holds the per-form restore lock ($lockPath) for the whole run.
 * Reserve, then write state phase "files"; copy into restore/<form>.tmp verifying hashes;
 * under the uploads lock move the tree to <form>/ with write-ahead accounting, turn the
 * reservation into stored usage and set phase "records". Throws; the caller then calls
 * bbf_uploads_restore_remove().
 */
function bbf_uploads_restore_files(array $config, string $formId, array $manifest, string $filesDir, string $lockPath, array $extra = []): void {
    if ($manifest === []) return;
    $resolved = bbf_uploads_root($config);
    if (!$resolved['ok']) throw new RuntimeException($resolved['error']);
    $root = $resolved['root'];
    $u = bbf_uploads_config($config);
    [$bytes, $files] = bbf_uploads_manifest_totals($manifest);
    $statePath = "$root/restore/$formId.json";
    $tmp = "$root/restore/$formId.tmp";
    $r = bin2hex(random_bytes(8));
    $state = ['phase' => 'files', 'form' => $formId, 'files' => $manifest, 'reservation' => $r, 'lock' => $lockPath, 'created' => time()] + $extra;
    bbf_uploads_locked($root, static function () use ($root, $u, $r, $formId, $lockPath, $bytes, $files, $statePath, $state): void {
        $l = bbf_uploads_ledger_read($root);
        bbf_uploads_gc_locked($root, $l, $u);
        [$storedBytes, $storedFiles] = bbf_uploads_stored_committed($l);
        if ($storedBytes + $bytes > (int)$u['max_stored_bytes'] || $storedFiles + $files > (int)$u['max_stored_files']) {
            throw new RuntimeException('Not enough stored-file capacity for this restore.');
        }
        $l['reservations'][$r] = ['kind' => 'restore', 'form' => $formId, 'lock' => $lockPath, 'bytes' => $bytes,
            'files' => $files, 'ip_hmac' => '', 'created' => time()];
        if (!bbf_uploads_ledger_write($root, $l)) throw new RuntimeException('Cannot reserve restore capacity.');
        // A crash here leaves a reservation without a state; its owner lock is then free, so GC drops it.
        if (!bbf_uploads_hook('restore_reserved') || !bbf_uploads_write_meta($statePath, $state)) {
            throw new RuntimeException('Cannot write the restore state.');
        }
        bbf_uploads_fsync_dir("$root/restore");
    });
    foreach ($manifest as $id => $entries) {
        if (!is_dir("$tmp/$id") && !@mkdir("$tmp/$id", 0700, true)) throw new RuntimeException('Cannot create the restore directory.');
        foreach ($entries as $fileId => $entry) {
            if (!bbf_uploads_hook('restore_copy') || !bbf_uploads_copy_verified("$filesDir/$id/$fileId", "$tmp/$id/$fileId", $entry['sha256'])) {
                throw new RuntimeException("Restore: file $id/$fileId could not be copied intact.");
            }
        }
    }
    bbf_uploads_locked($root, static function () use ($root, $formId, $manifest, $tmp, $r, $statePath, $state): void {
        $l = bbf_uploads_ledger_read($root);
        $items = [];
        foreach ($manifest as $id => $entries) foreach ($entries as $fileId => $entry) {
            $items[] = ['from' => ['loc' => 'none', 'path' => "restore/$formId.tmp/$id/$fileId"],
                'to' => ['loc' => 'stored', 'path' => "$formId/$id/$fileId"], 'bytes' => (int)$entry['size'], 'ip' => '', 'meta_path' => ''];
        }
        $wal = bbf_uploads_wal_begin($root, $l, 'restore', "restore:$formId", $items);
        if ($wal === null) throw new RuntimeException('Cannot write the restore write-ahead entry.');
        $moved = bbf_uploads_move($tmp, "$root/$formId");
        bbf_uploads_hook('restore_moved');
        bbf_uploads_wal_settle($root, $l, $wal);
        if ($moved === 'moved') unset($l['reservations'][$r]);
        if (!bbf_uploads_ledger_write($root, $l)) throw new RuntimeException('Cannot account the restored files.');
        if ($moved !== 'moved') throw new RuntimeException('Cannot move the restored files into place.');
        bbf_uploads_fsync_dir($root);
        if (!bbf_uploads_hook('restore_phase_records') || !bbf_uploads_write_meta($statePath, ['phase' => 'records'] + $state)) {
            throw new RuntimeException('Cannot advance the restore state.');
        }
    });
}

/** Remove the restored files of $formId (staging copy and final tree), release its reservation, exact ledger, no state. */
function bbf_uploads_restore_remove(array $config, string $formId): bool {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return true;
    return bbf_uploads_locked($root, static function () use ($root, $formId): bool {
        $l = bbf_uploads_ledger_read($root);
        foreach ($l['wal'] as $id => $entry) {
            if (($entry['owner'] ?? null) === "restore:$formId") bbf_uploads_wal_settle($root, $l, (string)$id);
        }
        foreach ($l['reservations'] as $r => $res) {
            if (($res['kind'] ?? '') === 'restore' && ($res['form'] ?? null) === $formId) unset($l['reservations'][$r]);
        }
        bbf_uploads_remove_tree("$root/restore/$formId.tmp");
        $formDir = "$root/$formId";
        foreach (glob("$formDir/*", GLOB_ONLYDIR) ?: [] as $subDir) {
            foreach (glob("$subDir/f_*") ?: [] as $file) {
                clearstatcache(true, $file);
                $size = (int)@filesize($file);
                if (@unlink($file)) bbf_uploads_charge($l, 'stored', $size, '', -1);
            }
            @rmdir($subDir);
        }
        @rmdir($formDir);
        $written = bbf_uploads_ledger_write($root, $l);
        clearstatcache();
        if (!$written || file_exists($formDir) || file_exists("$root/restore/$formId.tmp")) return false;
        return @unlink("$root/restore/$formId.json") || !is_file("$root/restore/$formId.json");
    });
}

/** Step 7: the definition is published; the restore state is no longer needed. */
function bbf_uploads_restore_finish(array $config, string $formId): void {
    $root = bbf_uploads_existing_root($config);
    if ($root !== null) @unlink("$root/restore/$formId.json");
}

/**
 * Recovery of an unfinished restore; the caller holds the per-form restore lock.
 * Published → drop the state. Phase "files" → no record exists yet: remove the files.
 * Phase "records" → nothing is deleted; `maintenance.php restore-abort` removes records and files together.
 * Returns 'none' | 'cleared' | 'removed' | 'records_pending' | 'failed'.
 */
function bbf_uploads_restore_recover(array $config, string $formId, bool $published): string {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return 'none';
    $state = bbf_uploads_restore_state($root, $formId);
    if ($state === null) return 'none';
    if ($published) {
        bbf_uploads_settle_owner($config, "restore:$formId");
        bbf_uploads_restore_finish($config, $formId);
        return 'cleared';
    }
    if (($state['phase'] ?? '') !== 'files') return 'records_pending';
    return bbf_uploads_restore_remove($config, $formId) ? 'removed' : 'failed';
}

/** Cleanup: recover each unfinished restore whose lock can be taken. form => outcome (or 'running'). */
function bbf_uploads_restore_sweep(array $config, string $root): array {
    $formsDir = (string)($config['forms_dir'] ?? __DIR__ . '/forms');
    $report = [];
    foreach (glob("$root/restore/*.json") ?: [] as $statePath) {
        $formId = basename($statePath, '.json');
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) continue;
        $state = bbf_uploads_restore_state($root, $formId);
        $lockPath = (string)($state['lock'] ?? '');
        $lock = $lockPath !== '' && is_file($lockPath) ? @fopen($lockPath, 'r+b') : false;
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $report[$formId] = 'running'; continue; }
        try {
            $report[$formId] = bbf_uploads_restore_recover($config, $formId, is_file("$formsDir/$formId.json"));
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
    return $report;
}

// ─── Stored files: paths, downloads ──────────────────────────────

/** Read-only path of a stored file for custom actions; null when it does not exist. Never move, change or delete it. */
function bbf_upload_path(array $config, string $formId, string $submissionId, string $fileId): ?string {
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $submissionId)
        || !preg_match('/\Af_[a-f0-9]{16}\z/D', $fileId)) return null;
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return null;
    $path = "$root/$formId/$submissionId/$fileId";
    $real = realpath($path);
    if ($real === false || !is_file($real)) return null;
    $normRoot = bbf_uploads_norm((string)realpath($root));
    return str_starts_with(bbf_uploads_norm($real), $normRoot . '/') ? $real : null;
}

/** Content-Disposition with an ASCII fallback and an RFC 5987 UTF-8 name. */
function bbf_uploads_content_disposition(string $name): string {
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $name);
    if ($ascii === '' || $ascii === null) $ascii = 'file';
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

/** Stream one stored file as a download. The caller has authorized the request. */
function bbf_uploads_stream(string $path, array $descriptor): void {
    while (ob_get_level() > 0) @ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    if (function_exists('set_time_limit')) @set_time_limit(0);
    header_remove('Content-Encoding');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . bbf_uploads_content_disposition((string)($descriptor['name'] ?? 'file')));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}
