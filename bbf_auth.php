<?php
/** Shared management access. No public-submission session keys are read or removed.
 * Load fresh config each request, then authenticate; never cache returned principals.
 * Stateless clients use bbf_authenticate($config, false) (header or ?token=). Browser sessions start only from
 * the POST sign-in form or the X-BBF-Token header. Only the legacy nonempty api_token is an unrestricted admin.
 */
defined('BBF_LOADED') || exit;
const BBF_AUTH_MIN_TOKEN = 32;
const BBF_AUTH_MAX_FAILURES = 10;   // wrong tokens per client address ...
const BBF_AUTH_FAILURE_WINDOW = 900; // ... per 15 minutes; then the client is blocked: wrong tokens get 429 at once.
                                     // A correct token is always accepted, so nobody sharing the address can lock the
                                     // operator out. Generate random secrets with maintenance.php new-token; format alone
                                     // cannot establish their randomness.
const BBF_AUTH_MAX_CLIENTS = 5000;   // cap on remembered client addresses in logs/.auth_failures.json
const BBF_AUTH_SESSION_NAME = 'BBFADMIN'; // management sign-in cookie; public forms keep PHP's default session

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
    // No clickjacking: management pages may only be framed by themselves (editor preview, viewer downloads).
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

function bbf_auth_fail(int $code = 403, string $detail = ''): void {
    http_response_code($code);
    $login = $GLOBALS['bbf_auth_login_page'] ?? null;
    if ($code === 503) {
        // HTTP failures are public, even when audit preflight fails before authorization.
        $message = 'Access audit unavailable. Ask the operator to run php maintenance.php selfcheck.' . (PHP_SAPI === 'cli' && $detail !== '' ? ' ' . $detail : '');
        if (is_array($login)) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>BareBonesForms: unavailable</title></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px">'
                . '<h1>Temporarily unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></body></html>';
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message]);
        }
        exit;
    }
    if (($code === 403 || $code === 429) && is_array($login)) {
        // Management pages get a usable sign-in form instead of a bare JSON error.
        header('Content-Type: text/html; charset=utf-8');
        $loginCsrf = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!is_string($_SESSION['bbf_login_csrf'] ?? null)) $_SESSION['bbf_login_csrf'] = bin2hex(random_bytes(32));
            $loginCsrf = $_SESSION['bbf_login_csrf'];
        }
        $hint = 'Enter your access token.'
            . ' If sign-in is unavailable, ask the operator to run <code>php maintenance.php selfcheck</code>.';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>BareBonesForms: sign in</title><style>body{font-family:system-ui,sans-serif;max-width:420px;margin:12vh auto;padding:0 20px;color:#1a1a2e}'
            . 'input,button{font:inherit;padding:8px 10px;width:100%;box-sizing:border-box;margin-top:8px}code{background:#f1f3f5;padding:1px 4px;border-radius:3px}</style></head><body>'
            . '<h1>Sign in</h1>'
            . (!empty($login['failed']) ? '<p role="alert" style="color:#c92a2a"><strong>Invalid token.</strong> It was not accepted for this page.</p>' : '')
            . (!empty($login['expired']) ? '<p role="alert" style="color:#c92a2a"><strong>The sign-in form expired.</strong> Enter the token again.</p>' : '')
            . (!empty($login['throttled']) ? '<p role="alert" style="color:#c92a2a"><strong>Invalid token.</strong> ' . htmlspecialchars($login['throttled'], ENT_QUOTES) . '</p>' : '')
            . (!empty($login['url_token']) ? '<p role="alert" style="color:#c92a2a"><strong>Tokens in the address are not accepted.</strong> Sign in with this form (the <code>?token=</code> link no longer works).</p>' : '')
            . '<p>' . $hint . '</p>'
            . '<form method="post" action="' . htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? ''), ENT_QUOTES) . '">'
            . '<input type="hidden" name="login_csrf" value="' . htmlspecialchars($loginCsrf, ENT_QUOTES) . '">'
            . '<label for="bbf-token">Access token</label><input id="bbf-token" name="token" type="password" autocomplete="current-password" required autofocus>'
            . '<button type="submit">Sign in</button></form></body></html>';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    // A CSV/export link clicked on another site (webmail, chat) arrives cross-site and its ?token= is ignored: say so.
    echo json_encode(['error' => !empty($GLOBALS['bbf_auth_cross_site_token'])
        ? 'Access denied. A ?token= link opened from another site is not accepted. Paste the link into the address bar'
            . ' or open it from a bookmark, or send the token in the X-BBF-Token header.'
        : 'Access denied.']);
    exit;
}

/**
 * The visitor's address. Behind a reverse proxy or Cloudflare every request comes from the proxy, so list the
 * proxy addresses/CIDR ranges in 'trusted_proxies'; X-Forwarded-For is then read right to left and the first
 * address that is not a trusted proxy wins. Without trusted_proxies the header is ignored (it is forgeable).
 */
function bbf_client_ip(array $config): string {
    $remote = bbf_ip_normalize((string)($_SERVER['REMOTE_ADDR'] ?? '')) ?? (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $trusted = bbf_trusted_proxies($config);
    if ($trusted === [] || !bbf_ip_in_list($remote, $trusted)) return $remote;
    $chain = array_reverse(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    foreach ($chain as $hop) {
        $hop = bbf_ip_normalize($hop);
        if ($hop === null) break;
        if (!bbf_ip_in_list($hop, $trusted)) return $hop;
    }
    return $remote;
}

/** Canonical address: "1.2.3.4:80", "[::1]:80" and IPv4-mapped "::ffff:1.2.3.4" all become the plain address; null if not an IP. */
function bbf_ip_normalize(string $value): ?string {
    $value = trim($value);
    if (preg_match('/\A\[([^\]]+)\](?::\d+)?\z/', $value, $m)) $value = $m[1];
    elseif (preg_match('/\A(\d{1,3}(?:\.\d{1,3}){3}):\d+\z/', $value, $m)) $value = $m[1];
    $packed = @inet_pton($value);
    if ($packed === false) return null;
    if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) return inet_ntop(substr($packed, 12));
    return inet_ntop($packed);
}

/** A proxy entry is an address or address/prefix with a numeric prefix in range for its family. */
function bbf_proxy_entry_valid($entry): bool {
    if (!is_string($entry)) return false;
    [$net, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
    $packed = @inet_pton(trim($net));
    if ($packed === false) return false;
    return $bits === null || (preg_match('/\A\d{1,3}\z/', $bits) && (int)$bits <= strlen($packed) * 8);
}

/** Valid trusted_proxies entries only: a typo such as "10.0.0.0/" must never widen into "trust everyone". */
function bbf_trusted_proxies(array $config): array {
    return array_values(array_filter((array)($config['trusted_proxies'] ?? []), 'bbf_proxy_entry_valid'));
}

function bbf_ip_in_list(string $ip, array $list): bool {
    $packed = @inet_pton(bbf_ip_normalize($ip) ?? '');
    if ($packed === false) return false;
    foreach ($list as $entry) {
        if (!bbf_proxy_entry_valid($entry)) continue;
        [$net, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
        $netPacked = inet_pton(trim($net));
        $bits = $bits === null ? strlen($netPacked) * 8 : (int)$bits;
        if (strlen($netPacked) === 16 && str_starts_with($netPacked, str_repeat("\0", 10) . "\xff\xff")) {
            $netPacked = substr($netPacked, 12); // "::ffff:10.0.0.0/104" is the IPv4 range 10.0.0.0/8
            $bits = max(0, $bits - 96);
        }
        if (strlen($netPacked) !== strlen($packed)) continue;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) continue;
        $rest = $bits % 8;
        if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($netPacked[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) return true;
    }
    return false;
}

/**
 * Runs $fn($state, $key, $now) under an exclusive lock on logs/.auth_failures.json. $state is
 * ['failures' => [client => [unix times]]] (a 2.1.2 file holds only the failures map; 2.1.3–2.1.5 also kept
 * 'slots', which are dropped). $fn returns [result, changed]; a changed state is written back. $fallback when there is no file.
 */
function bbf_auth_throttle_state(array $config, callable $fn, $fallback) {
    $dir = rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\');
    if (!is_dir($dir)) return $fallback;
    $fp = @fopen($dir . '/.auth_failures.json', 'c+');
    if (!$fp) return $fallback; // an unwritable log folder must not lock the operator out
    try {
        flock($fp, LOCK_EX);
        $now = microtime(true);
        $raw = json_decode((string)stream_get_contents($fp), true);
        $raw = is_array($raw) ? $raw : [];
        $state = ['failures' => array_key_exists('failures', $raw) || array_key_exists('slots', $raw)
            ? (is_array($raw['failures'] ?? null) ? $raw['failures'] : []) : $raw];
        foreach ($state['failures'] as $client => $times) {
            $times = array_values(array_filter(is_array($times) ? $times : [], static fn($t) => is_int($t) && $t > $now - BBF_AUTH_FAILURE_WINDOW));
            if ($times === []) unset($state['failures'][$client]); else $state['failures'][$client] = $times;
        }
        // One IPv6 host usually owns a whole /64, so rotating addresses inside it counts as one client.
        $ip = bbf_client_ip($config);
        $packed = @inet_pton($ip);
        $key = hash('sha256', $packed !== false && strlen($packed) === 16 ? substr($packed, 0, 8) : $ip);
        [$result, $changed] = $fn($state, $key, $now);
        if ($changed) {
            // Bounded file: beyond BBF_AUTH_MAX_CLIENTS addresses the clients with the oldest last failure are forgotten.
            if (count($state['failures']) > BBF_AUTH_MAX_CLIENTS) {
                uasort($state['failures'], static fn(array $a, array $b): int => max($b) <=> max($a));
                $state['failures'] = array_slice($state['failures'], 0, BBF_AUTH_MAX_CLIENTS, true);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
            fflush($fp);
        }
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Records one wrong token for this client. Returns false when the client was already blocked (answer 429). */
function bbf_auth_throttle(array $config, bool $failed = true): bool {
    return bbf_auth_throttle_state($config, static function (array &$state, string $key) use ($failed): array {
        $allowed = count($state['failures'][$key] ?? []) < BBF_AUTH_MAX_FAILURES;
        if ($failed) $state['failures'][$key] = array_slice([...($state['failures'][$key] ?? []), time()], -BBF_AUTH_MAX_FAILURES);
        return [$allowed, $failed];
    }, true);
}

/** Seconds until the oldest recorded failure of this client leaves the window (Retry-After for a blocked client). */
function bbf_auth_retry_after(array $config): int {
    return (int)bbf_auth_throttle_state($config, static function (array &$state, string $key, float $now): array {
        $times = $state['failures'][$key] ?? [];
        return [$times === [] ? 1 : max(1, (int)ceil(min($times) + BBF_AUTH_FAILURE_WINDOW - $now)), false];
    }, 1);
}

/** The token was checked and was wrong; this address has too many recent wrong tokens. */
function bbf_auth_throttled(int $retryAfter): void {
    $retryAfter = max(1, $retryAfter);
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    $message = 'Wrong access token, and too many wrong tokens came from this address. Wrong tokens are refused'
        . ' for up to ' . (int)ceil($retryAfter / 60) . ' min; the correct token still works.';
    if (is_array($GLOBALS['bbf_auth_login_page'] ?? null)) {
        $GLOBALS['bbf_auth_login_page']['throttled'] = $message;
        bbf_auth_fail(429);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

/** Credential format only; this does not prove randomness. Generate secrets with maintenance.php new-token. */
function bbf_auth_token_usable($token): bool {
    return is_string($token) && strlen($token) >= BBF_AUTH_MIN_TOKEN && preg_match('/\A[0-9a-fA-F]+\z/D', $token) === 1;
}

/** Browsers mark cross-site requests (an <img> or link on another site); only scripts, bookmarks and same-origin pages may use ?token=. */
function bbf_auth_query_token_usable(): bool {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    return $site === null || in_array($site, ['same-origin', 'none'], true);
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
    // Tokens outside the hex credential format are ignored individually.
    if (bbf_auth_token_usable($legacy)) {
        $fp = hash('sha256', $legacy);
        $registry['legacy-admin'] = ['id' => 'legacy-admin', 'fingerprint' => $fp, 'admin' => true,
            'forms' => [], 'permissions' => [], 'expires' => PHP_INT_MAX, 'revoked' => false];
        $secrets[$fp] = true;
    }
    foreach ($records as $r) {
        // An invalid token format is skipped like such an api_token; other credentials remain usable.
        if (is_array($r) && is_string($r['token'] ?? null) && !bbf_auth_token_usable($r['token'])) continue;
        if (!is_array($r) || bbf_auth_id($r['id'] ?? null) === '' || ($r['id'] ?? '') === 'legacy-admin'
            || !is_string($r['token'] ?? null)
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

/** Deliberately scoped-only installations are supported; a missing active credential is blocked. */
function bbf_auth_access_blocked(array $config): bool {
    $active = array_filter(bbf_auth_registry($config), static fn(array $r): bool => !$r['revoked'] && $r['expires'] > time());
    return $active === [] || (!empty($config['api_token']) && !isset($active['legacy-admin']));
}

/** access_tokens records with invalid credential format (skipped by bbf_auth_registry). */
function bbf_auth_short_records(array $config): array {
    $records = $config['access_tokens'] ?? [];
    if (!is_array($records)) return [];
    return array_values(array_filter($records, static fn($r): bool =>
        is_array($r) && is_string($r['token'] ?? null) && !bbf_auth_token_usable($r['token'])));
}

/**
 * Plain-language problems with the access configuration, for check.php, selfcheck and the upgrade preview.
 * Each item: ['level' => 'error'|'warn', 'message' => string]. Never includes a token value.
 */
function bbf_auth_config_problems(array $config): array {
    $out = [];
    $legacy = $config['api_token'] ?? '';
    $records = array_key_exists('access_tokens', $config) ? $config['access_tokens'] : [];
    if (!is_string($legacy)) $out[] = ['level' => 'error', 'message' => 'api_token must be a string; all access is disabled.'];
    elseif ($legacy !== '' && !bbf_auth_token_usable($legacy))
        $out[] = ['level' => 'error', 'message' => 'api_token must contain only hexadecimal characters (0-9, a-f, A-F) and be at least ' . BBF_AUTH_MIN_TOKEN . ' characters long; it is ignored. Generate a random value with php maintenance.php new-token.'];
    $short = bbf_auth_short_records($config);
    if ($short !== []) {
        $ids = array_map(static fn(array $r): string => bbf_auth_id($r['id'] ?? null) ?: '(no id)', $short);
        $out[] = ['level' => 'warn', 'message' => 'access_tokens: ' . implode(', ', $ids) . ' ha' . (count($ids) === 1 ? 's' : 've')
            . ' a token outside the required hexadecimal format (0-9, a-f, A-F; at least ' . BBF_AUTH_MIN_TOKEN . ' characters) and '
            . (count($ids) === 1 ? 'is' : 'are') . ' ignored. Generate random values with php maintenance.php new-token.'];
    }
    if (is_string($legacy)) {
        $usable = (bbf_auth_token_usable($legacy) ? 1 : 0)
            + (is_array($records) ? count($records) - count($short) : 0);
        if (!is_array($records) || !array_is_list($records) || ($usable > 0 && count(bbf_auth_registry($config)) !== $usable))
            $out[] = ['level' => 'error', 'message' => 'access_tokens contains a malformed or duplicate record; ALL tokens, including api_token, are disabled until it is fixed.'];
        elseif ($usable === 0) $out[] = ['level' => 'error', 'message' => 'No usable access token: viewer, editor and submissions API are locked. Set api_token (at least ' . BBF_AUTH_MIN_TOKEN . ' hexadecimal characters). Generate a random value with php maintenance.php new-token.'];
    }
    $registry = bbf_auth_registry($config);
    if ($registry !== [] && !array_filter($registry, static fn(array $r): bool => !$r['revoked'] && $r['expires'] > time()))
        $out[] = ['level' => 'error', 'message' => 'No active access token: all usable tokens are expired or revoked; management access is blocked.'];
    $smoke = $config['smoke_token'] ?? '';
    foreach (['api_token' => $legacy, 'smoke_token' => $smoke] as $name => $value) {
        if (is_string($value) && $value !== '' && rtrim($value) !== $value)
            $out[] = ['level' => 'warn', 'message' => $name . ' contains trailing whitespace; remove it from the configured value. Tokens are matched exactly, never trimmed.'];
    }
    foreach ($short as $record) {
        if (rtrim($record['token']) !== $record['token'])
            $out[] = ['level' => 'warn', 'message' => 'access_tokens: ' . (bbf_auth_id($record['id'] ?? null) ?: '(no id)') . ' contains trailing whitespace; remove it from the configured value. Tokens are matched exactly, never trimmed.'];
    }
    if ($smoke !== '' && $smoke !== null && bbf_smoke_token($config) === null)
        $out[] = ['level' => 'warn', 'message' => 'smoke_token must be a string containing only hexadecimal characters (0-9, a-f, A-F), at least ' . BBF_AUTH_MIN_TOKEN
            . ' characters long; it is ignored: HTTP smoke tests are off. Generate a random value with php maintenance.php new-token.'];
    $proxies = $config['trusted_proxies'] ?? [];
    $bad = array_filter(is_array($proxies) ? $proxies : [$proxies], static fn($e): bool => !bbf_proxy_entry_valid($e));
    if ($bad !== []) $out[] = ['level' => 'error', 'message' => 'trusted_proxies has invalid entries that are ignored: '
        . implode(', ', array_map(static fn($e): string => is_string($e) ? '"' . $e . '"' : gettype($e), $bad)) . '. Use an address or address/prefix, e.g. "10.0.0.0/8".'];
    if (!bbf_cookie_path_valid($config)) $out[] = ['level' => 'error', 'message' => 'cookie_path must be an absolute URL path beginning with /, without spaces, control characters, semicolons, ? or #; an empty string uses the installation path. The invalid value is ignored.'];
    return $out;
}

/**
 * Path of the session cookies: the installation folder as the browser sees it. PHP knows only its own path, so behind
 * a reverse proxy that serves the folder under another path (/forms/ -> /bbf/) set 'cookie_path' => '/forms/'.
 */
function bbf_cookie_path_valid(array $config): bool {
    if (!array_key_exists('cookie_path', $config)) return true;
    $path = $config['cookie_path'];
    return is_string($path) && ($path === '' || preg_match('~\A/[A-Za-z0-9._\~!$&\'()*+,=:@%/-]*\z~', $path) === 1);
}

function bbf_cookie_path(array $config): string {
    $path = $config['cookie_path'] ?? '';
    if ($path !== '' && bbf_cookie_path_valid($config)) return rtrim($path, '/') . '/';
    return rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
}

/**
 * The browser talks HTTPS. A proxy that ends TLS (Cloudflare, a load balancer) talks plain HTTP to PHP; its
 * X-Forwarded-Proto is believed only from an address listed in trusted_proxies (anyone else could forge it).
 */
function bbf_request_https(array $config): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $trusted = bbf_trusted_proxies($config);
    if ($trusted === [] || !bbf_ip_in_list((string)($_SERVER['REMOTE_ADDR'] ?? ''), $trusted)) return false;
    return strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
}

function bbf_auth_session(array $config = []): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        // Own cookie name and path: other PHP apps on the domain (PHPSESSID) neither see nor overwrite the sign-in.
        session_name(BBF_AUTH_SESSION_NAME);
        session_set_cookie_params(['lifetime' => 0, 'path' => bbf_cookie_path($config), 'secure' => bbf_request_https($config),
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
    if ($session) bbf_auth_session($config);
    // HTML management pages accept the sign-in form POST so the token stays out of URLs and logs.
    $formLogin = $html && $session && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('token', $_POST);
    if ($html) {
        // Configuration diagnostics belong to authenticated checks and CLI selfcheck, not sign-in.
        $GLOBALS['bbf_auth_login_page'] = [];
    }
    // Login CSRF: the form carries a per-session value, so another site cannot sign the browser in with its token.
    if ($formLogin && !(is_string($_POST['login_csrf'] ?? null) && is_string($_SESSION['bbf_login_csrf'] ?? null)
            && hash_equals($_SESSION['bbf_login_csrf'], $_POST['login_csrf']))) {
        $formLogin = false;
        $GLOBALS['bbf_auth_login_page']['expired'] = true;
    }
    // ?token= is only for stateless API calls (scripts, CSV links). It never signs a browser session in (login CSRF
    // through a link), and a cross-site <img>/link cannot spend the victim address's wrong-token budget.
    $queryToken = !$session && array_key_exists('token', $_GET) && bbf_auth_query_token_usable();
    if (!$session && array_key_exists('token', $_GET) && !$queryToken) $GLOBALS['bbf_auth_cross_site_token'] = true;
    if ($html && array_key_exists('token', $_GET) && !$formLogin && !array_key_exists('HTTP_X_BBF_TOKEN', $_SERVER))
        $GLOBALS['bbf_auth_login_page']['url_token'] = true;
    $explicit = array_key_exists('HTTP_X_BBF_TOKEN', $_SERVER) || $queryToken || $formLogin;
    $provided = $_SERVER['HTTP_X_BBF_TOKEN'] ?? ($queryToken ? $_GET['token'] : ($formLogin ? $_POST['token'] : null));
    $principal = null; $now = time();
    if ($explicit) {
        // The correct token always gets in, so a guesser sharing the address (NAT, IPv6 /64, proxy without
        // trusted_proxies) cannot lock the operator out. Only wrong tokens are limited; since the answer then shows
        // whether a guess was right, use randomly generated secrets; hex format alone does not prove randomness.
        if (is_string($provided) && $provided !== '') {
            $fp = hash('sha256', $provided);
            foreach ($registry as $r) if (hash_equals($r['fingerprint'], $fp)) $principal = $r;
        }
        // An expired or revoked token is a wrong token: answered like one (429 when blocked), so the answer does not
        // reveal that it once was valid (review 2.1.6).
        if ($principal && ($principal['revoked'] || $principal['expires'] <= $now)) $principal = null;
        if (!$principal && !bbf_auth_throttle($config, true)) bbf_auth_throttled(bbf_auth_retry_after($config));
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
    if ($html && $explicit && !$principal) $GLOBALS['bbf_auth_login_page']['failed'] = true;
    if ($session) {
        if (!$principal) unset($_SESSION['bbf_access']);
        elseif ($explicit) {
            $old = $_SESSION['bbf_access'] ?? []; $csrf = (($old['id'] ?? '') === $principal['id'] && ($old['fingerprint'] ?? '') === $principal['fingerprint']) ? ($old['csrf'] ?? bin2hex(random_bytes(32))) : bin2hex(random_bytes(32)); if (!session_regenerate_id(true)) bbf_auth_fail(503);
            $_SESSION['bbf_access'] = ['id' => $principal['id'], 'fingerprint' => $principal['fingerprint'],
                'created' => $now, 'seen' => $now, 'csrf' => $csrf];
        } else $_SESSION['bbf_access']['seen'] = $now;
    }
    if ($principal && $session && $html && $formLogin) {
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
/** Written by restore before its first record and removed after publish or a clean rollback; files or not. */
function bbf_auth_restore_marker(array $config, string $form): string {
    return rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\') . "/.restore/$form.json";
}
/** Unpublished leftovers of a restore that died after writing records stay unreachable until restore-abort (upload spec §10). */
function bbf_auth_restore_pending(array $config, string $form): bool {
    if (is_file(($config['forms_dir'] ?? __DIR__ . '/forms') . "/$form.json")) return false;
    if (is_file(bbf_auth_restore_marker($config, $form))) return true;
    if (!is_array($config['uploads'] ?? null) || (string)($config['uploads']['dir'] ?? '') === '') return false;
    require_once __DIR__ . '/bbf_uploads.php';
    $root = bbf_uploads_existing_root($config);
    return $root !== null && is_file("$root/restore/$form.json");
}
/** Response authorization additionally validates the definition and effective store; exact-scope orphans remain valid. */
function bbf_auth_form_identity(array $config, string $form): bool {
    if (!bbf_auth_definition_identity($config, $form) || !bbf_auth_path_identity($config['forms_dir'] ?? __DIR__ . '/forms', "$form.json")) return false;
    if (bbf_auth_restore_pending($config, $form)) return false;
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

/** The configured smoke_token when it meets the same hexadecimal format as management credentials. */
function bbf_smoke_token(array $config): ?string {
    $token = $config['smoke_token'] ?? null;
    return bbf_auth_token_usable($token) ? $token : null;
}

/** Configured tokens to redact from audit entries. Tokens shorter than BBF_AUTH_MIN_TOKEN (access tokens and
 * smoke_token alike) are never accepted, so they are no credential; redacting "co" would only mangle "contact". */
function bbf_audit_secrets(array $config): array {
    $secrets = [$config['api_token'] ?? '', $config['smoke_token'] ?? ''];
    foreach (is_array($config['access_tokens'] ?? null) ? $config['access_tokens'] : [] as $r)
        if (is_array($r)) $secrets[] = $r['token'] ?? '';
    return array_values(array_filter($secrets, static fn($s): bool => is_string($s) && strlen($s) >= BBF_AUTH_MIN_TOKEN));
}

/** Read-only preflight: never create, append, rotate or chmod an operator's audit file.
 * Permission bits catch read-only files even when CLI runs as root; ACLs are checked by PHP too.
 */
function bbf_audit_problem(array $config): ?string {
    $dir = rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\');
    $file = $dir . '/access-audit.php';
    clearstatcache(true, $dir); clearstatcache(true, $file);
    if (is_link($dir) || is_link($file)) return 'The logs directory or access-audit.php is a symlink, which is refused.';
    if (!is_dir($dir) || !is_writable($dir) || (PHP_OS_FAMILY !== 'Windows' && ((int)@fileperms($dir) & 0222) === 0))
        return 'The logs directory is missing or not writable by PHP.';
    if (!file_exists($file)) return null; // creation is possible; do not create it just to check
    if (!is_file($file) || !is_writable($file) || (PHP_OS_FAMILY !== 'Windows' && ((int)@fileperms($file) & 0222) === 0))
        return 'The existing access-audit.php is not a writable regular file.';
    $fp = @fopen($file, 'r+b'); // test write access without writing a byte
    if (!$fp) return 'The existing access-audit.php cannot be opened for writing by PHP.';
    try {
        $stat = fstat($fp);
        $guard = "<?php http_response_code(404); exit; ?>\n";
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000) return 'The audit file is not a regular file.';
        if ($stat['size'] !== 0 && fread($fp, strlen($guard)) !== $guard) return 'The existing access-audit.php has an invalid PHP guard.';
    } finally { fclose($fp); }
    return null;
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
    if (!is_dir($dir)) {
        error_log("BareBonesForms: logs directory missing: $dir");
        bbf_auth_fail(503, 'The logs directory does not exist. Create it (writable by PHP) or set logs_dir in config.php; check.php shows the exact path.');
    }
    if (is_link($dir) || is_link($file)) bbf_auth_fail(503, 'The logs directory or access-audit.php is a symlink, which is refused.');
    $fp = @fopen($file, 'c+b');
    if (!$fp) {
        error_log("BareBonesForms: cannot open access audit log: $file");
        bbf_auth_fail(503, 'access-audit.php in the logs directory is not writable by PHP; check.php shows the exact path.');
    }
    $ok = false;
    try {
        if (!flock($fp, LOCK_EX)) bbf_auth_fail(503);
        $stat = fstat($fp);
        if (($stat['mode'] & 0170000) !== 0100000) bbf_auth_fail(503);
        if ($stat['size'] === 0) {
            if (fwrite($fp, $guard) !== strlen($guard)) bbf_auth_fail(503);
            @chmod($file, 0600);
        } elseif (fread($fp, strlen($guard)) !== $guard) {
            bbf_auth_fail(503);
        } elseif ($stat['size'] > max(65536, (int)($config['audit_max_bytes'] ?? 20 * 1048576))) {
            // Bounded growth: keep one previous generation (still guarded, so never web-readable).
            rewind($fp);
            // access-audit.1.php keeps the .php extension, so the guard protects it even without .htaccess.
            $previous = $dir . '/access-audit.1.php';
            $tmp = $dir . '/access-audit.1.' . bin2hex(random_bytes(4)) . '.tmp';
            $out = @fopen($tmp, 'xb');
            $copied = $out && stream_copy_to_stream($fp, $out) === $stat['size'] && fflush($out);
            if ($out) fclose($out);
            if (!$copied || !@rename($tmp, $previous)) { @unlink($tmp); bbf_auth_fail(503); }
            @chmod($previous, 0600);
            if (!ftruncate($fp, strlen($guard))) bbf_auth_fail(503);
        }
        // 2.1.2 rotated to access-audit.php.1, which only .htaccess protects; move it to the guarded name once.
        if (is_file($file . '.1') && !is_link($file . '.1')) {
            if (is_file($dir . '/access-audit.1.php') || !@rename($file . '.1', $dir . '/access-audit.1.php')) @unlink($file . '.1');
        }
        $entry = ['utc' => gmdate('Y-m-d\TH:i:s\Z'), 'principal_id' => $principal['id'] ?? 'anonymous',
            'action' => bbf_auth_id($action), 'form' => bbf_auth_id($form),
            'submission_ids' => array_values(array_filter(array_map('bbf_auth_id', array_slice($ids, 0, 100)))),
            'decision' => $decision, 'result' => $result, 'result_count' => max(0, $count)];
        // Even a hostile ID chosen to equal a configured credential cannot log it.
        // Redact identifier values before encoding, never JSON keys or numeric counts.
        foreach (bbf_audit_secrets($config) as $secret) {
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
            foreach (['name', 'label', 'type', 'title'] as $k) if (is_string($f[$k] ?? null)) $safe[$k] = $f[$k];
            if (is_bool($f['repeatable'] ?? null)) $safe['repeatable'] = $f['repeatable'];
            if (is_array($f['fields'] ?? null)) $safe['fields'] = $walk($f['fields']);
            $out[] = $safe;
        }
        return $out;
    };
    return ['id' => is_string($def['id'] ?? null) ? $def['id'] : '',
        'name' => is_string($def['name'] ?? null) ? $def['name'] : '',
        'fields' => $walk(is_array($def['fields'] ?? null) ? $def['fields'] : [])];
}
