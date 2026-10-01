<?php
/** Fixed, operator-configured targets for installation and smoke diagnostics. */
defined('BBF_LOADED') || exit;

function bbf_diagnostic_base_url(array $config): ?string {
    $url = $config['diagnostic_base_url'] ?? null;
    if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) return null;
    return rtrim($url, '/');
}

/**
 * A failed connection or redirect is not evidence that server access is denied.
 * $body receives the (size-capped) response so callers can tell a served file from a fallback page.
 */
function bbf_diagnostic_probe(array $config, string $path, ?string &$body = null): ?int {
    $body = null;
    $base = bbf_diagnostic_base_url($config);
    if ($base === null || (!in_array($path, ['config.php', 'submissions/', 'logs/',
        'templates/', 'actions/', 'forms/', 'tests/', 'templates/notify.html', 'actions/README.md',
        'forms/form.schema.json', 'README.md', 'CHANGELOG.md', 'bbf_auth.php', 'config/'], true)
        && !preg_match('#\A(?:submissions|logs|templates|actions|tests|config)/bbf-check-[0-9a-f]{32}\.txt\z#D', $path)
        && !preg_match('#\Abbf-check-[0-9a-f]{32}/(?:control|\.rewrite)\.txt\z#D', $path))) return null;
    // Prefer cURL: shared hosts commonly set allow_url_fopen=0, which silently disables streams.
    if (function_exists('curl_init')) {
        $response = '';
        $ch = curl_init($base . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
                if (strlen($response) >= 1048576) return 0;
                $response .= substr($chunk, 0, 1048576 - strlen($response));
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 0) return null;
        $body = $response;
        return $code;
    }
    $context = stream_context_create(['http' => [
        'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $http_response_header = [];
    $response = @file_get_contents($base . '/' . $path, false, $context, 0, 1048576);
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $http_response_header[0] ?? '', $match)) {
        $body = is_string($response) ? $response : '';
        return (int)$match[1];
    }
    return null;
}

/** Test rewrite execution and data-directory protection using fresh harmless fixtures.
 * A readable control prevents blanket denial or a wrong URL from falsely proving mod_rewrite.
 * No config, environment, submission or other operator data is requested by this probe.
 */
function bbf_diagnostic_rewrite(array $config, string $root = __DIR__): array {
    $unverified = ['status' => 'unverified', 'detail' => 'Not verified: fresh dot-file, submissions and logs fixtures need a reachable diagnostic_base_url, a served control and writable non-symlink web directories. Structural .htaccess rules alone are not assurance. Nginx needs equivalent server rules.'];
    if (!bbf_diagnostic_safe_dir($root)) return $unverified;
    bbf_diagnostic_stale_cleanup($root);
    foreach (['submissions', 'logs'] as $label) bbf_diagnostic_stale_cleanup($root . '/' . $label);
    if (bbf_diagnostic_base_url($config) === null || !is_writable($root)) return $unverified;
    $name = 'bbf-check-' . bin2hex(random_bytes(16));
    $dir = rtrim($root, '/\\') . '/' . $name;
    if (!@mkdir($dir, 0755)) return $unverified;
    @chmod($dir, 0755); // CLI umask must not hide the harmless control from the web worker.
    $fixtures = [];
    $removeDir = static function () use ($dir, &$fixtures): void {
        foreach ($fixtures as $fixture) if ($fixture) bbf_diagnostic_fixture_remove($fixture);
        if (bbf_diagnostic_safe_dir($dir)) @rmdir($dir);
    };
    register_shutdown_function($removeDir);
    try {
        $fixtures['control'] = bbf_diagnostic_fixture($dir, 'control.txt', substr($name, 10), 'control');
        $fixtures['dot-file'] = bbf_diagnostic_fixture($dir, '.rewrite.txt', substr($name, 10), 'rewrite');
        foreach (['submissions', 'logs'] as $label) {
            $token = bin2hex(random_bytes(16));
            $fixtures[$label] = bbf_diagnostic_fixture($root . '/' . $label, "bbf-check-$token.txt", $token, 'data');
        }
        $control = $fixtures['control'];
        $controlCode = $control ? bbf_diagnostic_probe($config, "$name/control.txt", $controlBody) : null;
        $verified = $controlCode === 200 && $controlBody === ($control['content'] ?? null);
        $leaks = [];
        $checks = [];
        foreach (['dot-file', 'submissions', 'logs'] as $label) {
            $fixture = $fixtures[$label];
            $path = $label === 'dot-file' ? "$name/.rewrite.txt" : "$label/" . basename($fixture['path'] ?? '');
            $code = $fixture ? bbf_diagnostic_probe($config, $path, $body) : null;
            $leaked = $fixture && $code === 200 && $body === $fixture['content'];
            $status = $leaked ? 'error' : ($verified && $fixture && in_array($code, [403, 404], true) ? 'verified' : 'unverified');
            $checks[$label] = $status;
            if ($leaked) $leaks[] = $label;
        }
        if ($leaks !== []) return ['status' => 'error', 'checks' => $checks, 'detail' => 'Fresh harmless fixture publicly readable in: ' . implode(', ', $leaks) . '. mod_rewrite / AllowOverride or equivalent server rules are missing or incomplete.'];
        if ($verified && !in_array('unverified', $checks, true)) return ['status' => 'verified', 'checks' => $checks, 'detail' => 'Fresh dot-file, submissions and logs fixtures denied while the control was served. Only these paths at diagnostic_base_url are verified, not every server rule.'];
        return $unverified + ['checks' => $checks];
    } finally {
        foreach ($fixtures as $fixture) if ($fixture) bbf_diagnostic_fixture_remove($fixture);
        $removeDir();
    }
}

/** Refuse symlinks in every existing path component, not only the final directory. */
function bbf_diagnostic_safe_dir(string $dir): bool {
    if (!is_dir($dir)) return false;
    for ($path = rtrim($dir, '/\\'); $path !== ''; $path = dirname($path)) {
        if (@is_link($path)) return false; // open_basedir may forbid inspecting ancestors outside its boundary
        if (dirname($path) === $path) break;
    }
    return true;
}

function bbf_diagnostic_fixture(string $dir, string $name, string $token, string $kind): ?array {
    if (!bbf_diagnostic_safe_dir($dir) || !is_writable($dir)) return null;
    $path = $dir . '/' . $name;
    $content = "BBF diagnostic fixture v1 $token $kind\n";
    $fp = @fopen($path, 'xb');
    if (!$fp) return null;
    $fixture = ['path' => $path, 'content' => $content];
    register_shutdown_function(static fn() => bbf_diagnostic_fixture_remove($fixture));
    try { $written = fwrite($fp, $content); } finally { fclose($fp); }
    @chmod($path, 0644); // Non-secret bytes must be readable by a separate web uid to test denial.
    if ($written !== strlen($content)) { bbf_diagnostic_fixture_remove($fixture); return null; }
    return $fixture;
}

/** Never unlink a replaced file, a symlink, or unfamiliar content. */
function bbf_diagnostic_fixture_remove(array $fixture): void {
    $path = $fixture['path'];
    clearstatcache(true, $path);
    if (!bbf_diagnostic_safe_dir(dirname($path)) || is_link($path) || !is_file($path)
        || @filesize($path) !== strlen($fixture['content'])) return;
    if (@file_get_contents($path) === $fixture['content']) @unlink($path);
}

/** Best effort after SIGKILL: bounded scan, age >= one hour, exact owned format, no recursion. */
function bbf_diagnostic_stale_cleanup(string $dir): void {
    if (!bbf_diagnostic_safe_dir($dir)) return;
    $scanned = $removed = 0;
    try {
        foreach (new DirectoryIterator($dir) as $entry) {
            if (++$scanned > 256 || $removed >= 16) break;
            $name = $entry->getFilename();
            if (!preg_match('/\Abbf-check-([0-9a-f]{32})(\.txt)?\z/D', $name, $m)) continue;
            $path = $dir . '/' . $name;
            if (is_link($path) || (int)@filemtime($path) > time() - 3600) continue;
            if (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid()) continue;
            if (isset($m[2])) {
                bbf_diagnostic_fixture_remove(['path' => $path, 'content' => "BBF diagnostic fixture v1 {$m[1]} data\n"]);
            } elseif (bbf_diagnostic_safe_dir($path)) {
                // Only a complete known pair proves ownership. Unknown/partial directories stay untouched.
                $control = "BBF diagnostic fixture v1 {$m[1]} control\n";
                $rewrite = "BBF diagnostic fixture v1 {$m[1]} rewrite\n";
                if (is_link($path . '/control.txt') || is_link($path . '/.rewrite.txt')
                    || @filesize($path . '/control.txt') !== strlen($control)
                    || @filesize($path . '/.rewrite.txt') !== strlen($rewrite)
                    || @file_get_contents($path . '/control.txt') !== $control
                    || @file_get_contents($path . '/.rewrite.txt') !== $rewrite) continue;
                if (count(scandir($path) ?: []) !== 4) continue;
                bbf_diagnostic_fixture_remove(['path' => $path . '/control.txt', 'content' => $control]);
                bbf_diagnostic_fixture_remove(['path' => $path . '/.rewrite.txt', 'content' => $rewrite]);
                @rmdir($path);
            }
            $removed++;
        }
    } catch (Throwable $error) { /* Cleanup must not break diagnostics. */ }
}

/** Cron cannot infer web-worker write access from its own effective uid or root privileges. */
function bbf_diagnostic_audit(array $config): array {
    $dir = rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\');
    $file = $dir . '/access-audit.php';
    clearstatcache(true, $file);
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $owner = is_file($file) ? @fileowner($file) : null;
    $identity = ['effective_uid' => $uid, 'file_owner' => $owner];
    if (!bbf_diagnostic_safe_dir($dir) || is_link($file) || (file_exists($file) && !is_file($file)))
        return $identity + ['status' => 'error', 'detail' => 'The audit target is missing, non-regular or a symlink.'];
    if ($uid !== null && $owner !== null && $owner !== false && $uid !== $owner)
        return $identity + ['status' => 'unverified', 'detail' => 'Cron/current uid differs from the audit owner. Web-worker audit write access is not verified; run authenticated web diagnostics under that worker. No operator file was changed.'];
    $problem = bbf_audit_problem($config);
    if ($problem !== null) return $identity + ['status' => 'error', 'detail' => $problem];
    return $identity + ['status' => PHP_SAPI === 'cli' ? 'unverified' : 'verified', 'detail' => PHP_SAPI === 'cli'
        ? 'Audit preflight passed for the current CLI identity only. Web-worker identity and write access are not verified.'
        : 'Audit preflight passed for this web-worker identity.'];
}
