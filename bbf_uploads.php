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

/** maintenance.php uploads-cleanup: GC, exact recount, report what needs a human. */
function bbf_uploads_cleanup(array $config): array {
    $root = bbf_uploads_existing_root($config);
    if ($root === null) return ['ok' => true, 'note' => 'No upload directory.'];
    $u = bbf_uploads_config($config);
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
    return $report;
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
            if ($l['stored']['bytes'] + $total > (int)$u['max_stored_bytes'] || $l['stored']['files'] + count($items) > (int)$u['max_stored_files']) {
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
