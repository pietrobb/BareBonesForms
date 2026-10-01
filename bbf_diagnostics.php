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
    $cleanup = [$root => bbf_diagnostic_stale_cleanup($root)];
    foreach (['submissions', 'logs'] as $label) $cleanup[$label] = bbf_diagnostic_stale_cleanup($root . '/' . $label);
    $unverified['cleanup'] = $cleanup;
    $cleanupDetail = '';
    foreach ($cleanup as $result) {
        if ($result['unreclaimed'] !== []) $cleanupDetail .= ' Stale diagnostic fixtures cannot be reclaimed; inspect cleanup paths/owners and remove confirmed fixtures under their original uid or ask the operator to correct ownership.';
        if ($result['limited']) $cleanupDetail .= ' Stale fixture cleanup reached its matching-name/removal limit; rerun diagnostics to continue.';
    }
    $unverified['detail'] .= $cleanupDetail;
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
    $restoreSignals = bbf_diagnostic_signal_cleanup($removeDir);
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
        $responses = [];
        foreach (['dot-file', 'submissions', 'logs'] as $label) {
            $fixture = $fixtures[$label];
            $path = $label === 'dot-file' ? "$name/.rewrite.txt" : "$label/" . basename($fixture['path'] ?? '');
            $code = $fixture ? bbf_diagnostic_probe($config, $path, $body) : null;
            $leaked = $fixture && $code === 200 && $body === $fixture['content'];
            $status = $leaked ? 'error' : ($verified && $fixture && in_array($code, [403, 404], true) ? 'verified' : 'unverified');
            $checks[$label] = $status;
            $responses[$label] = $code;
            if ($leaked) $leaks[] = $label;
        }
        if ($leaks !== []) return ['status' => 'error', 'checks' => $checks, 'cleanup' => $cleanup, 'detail' => 'Fresh harmless fixture publicly readable in: ' . implode(', ', $leaks) . '. mod_rewrite / AllowOverride or equivalent server rules are missing or incomplete.' . $cleanupDetail];
        if ($verified && !in_array('unverified', $checks, true)) return ['status' => 'verified', 'checks' => $checks, 'cleanup' => $cleanup, 'detail' => 'Fresh dot-file, submissions and logs fixtures denied while the control was served. Only these paths at diagnostic_base_url are verified, not every server rule.' . $cleanupDetail];
        $reason = $controlCode === null ? 'Control request failed or the fixture could not be created.'
            : ($controlCode >= 300 && $controlCode < 400 ? "Control returned HTTP $controlCode redirect (not followed). Set diagnostic_base_url to the final installation URL, including HTTPS and its subpath."
            : ($controlCode === 200 && !$verified ? 'Control returned HTTP 200 with an invalid fixture marker (SPA/fallback or wrong installation URL). Set diagnostic_base_url to the actual installation and exclude diagnostic paths from catch-all routing.'
            : "Control returned HTTP $controlCode; it must serve the fresh marker with HTTP 200."));
        foreach ($checks as $label => $status) if ($status === 'unverified') {
            $code = $responses[$label];
            $responseDetail = $code === 200 ? 'HTTP 200 invalid fixture marker (SPA/fallback)'
                : ($code !== null && $code >= 300 && $code < 400 ? "HTTP $code redirect (not followed)" : ($code === null ? 'no response/fixture' : "HTTP $code"));
            $reason .= " $label protection was not verified: $responseDetail; check server denial rules and diagnostic_base_url routing.";
        }
        $unverified['detail'] .= ' ' . $reason;
        return $unverified + ['checks' => $checks];
    } finally {
        foreach ($fixtures as $fixture) if ($fixture) bbf_diagnostic_fixture_remove($fixture);
        $removeDir();
        $restoreSignals();
    }
}

/** Scoped signal cleanup where pcntl is available; restore and delegate existing handlers. */
function bbf_diagnostic_signal_cleanup(callable $cleanup): callable {
    if (PHP_SAPI !== 'cli' || !function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')
        || !function_exists('pcntl_async_signals')) return static function (): void {};
    $previous = [];
    $handlers = [];
    $async = pcntl_async_signals();
    $active = true;
    $restore = static function () use (&$active, &$handlers, &$previous, $async): void {
        if (!$active) return;
        $active = false;
        foreach ($previous as $signal => $handler)
            if (pcntl_signal_get_handler($signal) === $handlers[$signal]) pcntl_signal($signal, $handler);
        pcntl_async_signals($async);
    };
    foreach ([SIGTERM, SIGINT] as $signal) {
        $previous[$signal] = pcntl_signal_get_handler($signal);
        $handlers[$signal] = static function (int $received) use ($cleanup, $restore, &$previous): void {
            $cleanup();
            $handler = $previous[$received];
            $restore();
            if (is_callable($handler)) { $handler($received); return; }
            if ($handler === SIG_IGN) return;
            // Exit invokes shutdown cleanup too; SIGKILL necessarily relies on the later stale sweep.
            exit(128 + $received);
        };
        pcntl_signal($signal, $handlers[$signal]);
    }
    pcntl_async_signals(true);
    return $restore;
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

/** SIGKILL recovery: bound matching names, not unrelated form directories; never recurse. */
function bbf_diagnostic_stale_cleanup(string $dir): array {
    $report = ['removed' => 0, 'unreclaimed' => [], 'limited' => false];
    if (!bbf_diagnostic_safe_dir($dir)) return $report;
    $matched = 0;
    try {
        foreach (new GlobIterator($dir . '/bbf-check-*', FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if (!preg_match('/\Abbf-check-([0-9a-f]{32})(\.txt)?\z/D', $name, $m)) continue;
            if (++$matched > 256 || $report['removed'] >= 16) { $report['limited'] = true; break; }
            $path = $dir . '/' . $name;
            if (is_link($path) || (int)@filemtime($path) > time() - 3600) continue;
            $owner = @fileowner($path);
            $different = function_exists('posix_geteuid') && $owner !== posix_geteuid();
            if (isset($m[2])) {
                $content = "BBF diagnostic fixture v1 {$m[1]} data\n";
                if (!is_file($path) || @filesize($path) !== strlen($content)
                    || @file_get_contents($path) !== $content) {
                    if ($different && !is_readable($path)) $report['unreclaimed'][] = ['path' => $path, 'owner' => $owner];
                    continue;
                }
                bbf_diagnostic_fixture_remove(['path' => $path, 'content' => $content]);
            } elseif (bbf_diagnostic_safe_dir($path)) {
                // Only a complete known pair proves ownership. Unknown/partial directories stay untouched.
                $control = "BBF diagnostic fixture v1 {$m[1]} control\n";
                $rewrite = "BBF diagnostic fixture v1 {$m[1]} rewrite\n";
                if (is_link($path . '/control.txt') || is_link($path . '/.rewrite.txt')
                    || @filesize($path . '/control.txt') !== strlen($control)
                    || @filesize($path . '/.rewrite.txt') !== strlen($rewrite)
                    || @file_get_contents($path . '/control.txt') !== $control
                    || @file_get_contents($path . '/.rewrite.txt') !== $rewrite) {
                    if ($different && !is_readable($path . '/control.txt')) $report['unreclaimed'][] = ['path' => $path, 'owner' => $owner];
                    continue;
                }
                if (count(@scandir($path) ?: []) !== 4) continue;
                bbf_diagnostic_fixture_remove(['path' => $path . '/control.txt', 'content' => $control]);
                bbf_diagnostic_fixture_remove(['path' => $path . '/.rewrite.txt', 'content' => $rewrite]);
                @rmdir($path);
            } else continue;
            clearstatcache(true, $path);
            if (file_exists($path)) $report['unreclaimed'][] = ['path' => $path, 'owner' => $owner];
            else $report['removed']++;
        }
    } catch (Throwable $error) { $report['limited'] = true; }
    return $report;
}

/** Cron cannot infer web-worker write access from its own effective uid or root privileges. */
function bbf_diagnostic_audit(array $config): array {
    $dir = rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\');
    $file = $dir . '/access-audit.php';
    clearstatcache(true, $file);
    // Resolve deployment aliases in ancestors, but never accept a linked audit target or logs leaf.
    $resolved = realpath($dir);
    if (!is_link($dir) && $resolved !== false) {
        $dir = $resolved;
        $file = $dir . '/access-audit.php';
        $config['logs_dir'] = $dir;
    }
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $owner = is_file($file) ? @fileowner($file) : null;
    $identity = ['effective_uid' => $uid, 'file_owner' => $owner];
    if (!bbf_diagnostic_safe_dir($dir) || is_link($file) || (file_exists($file) && !is_file($file)))
        return $identity + ['status' => 'error', 'detail' => 'The audit target is missing, non-regular or a symlink.'];
    if (PHP_OS_FAMILY !== 'Windows' && ((int)@fileperms($dir) & 0222) === 0)
        return $identity + ['status' => 'error', 'detail' => 'The logs directory has no write permission bits.'];
    if (is_file($file)) {
        if (PHP_OS_FAMILY !== 'Windows' && ((int)@fileperms($file) & 0222) === 0)
            return $identity + ['status' => 'error', 'detail' => 'The existing access-audit.php is not a writable regular file.'];
        $guard = "<?php http_response_code(404); exit; ?>\n";
        $prefix = @file_get_contents($file, false, null, 0, strlen($guard));
        if ($prefix !== false && $prefix !== '' && $prefix !== $guard)
            return $identity + ['status' => 'error', 'detail' => 'The existing access-audit.php has an invalid PHP guard.'];
        if ($prefix === false)
            return $identity + ['status' => 'unverified', 'detail' => 'The audit PHP guard cannot be read under the current uid; verify ownership, permissions and guard under the web worker. No operator file was changed.'];
    }
    if ($uid !== null && $owner !== null && $owner !== false && $uid !== $owner)
        return $identity + ['status' => 'unverified', 'detail' => 'Cron/current uid differs from the audit owner. Web-worker audit write access is not verified; run authenticated web diagnostics under that worker. No operator file was changed.'];
    if ($owner === 0)
        return $identity + ['status' => 'unverified', 'detail' => 'The audit log is root-owned. Root write access does not verify the web worker; check ownership and permissions under the actual web-worker uid. No operator file was changed.'];
    $problem = bbf_audit_problem($config);
    if ($problem !== null) return $identity + ['status' => 'error', 'detail' => $problem];
    return $identity + ['status' => PHP_SAPI === 'cli' ? 'unverified' : 'verified', 'detail' => PHP_SAPI === 'cli'
        ? 'Audit preflight passed for the current CLI identity only. Web-worker identity and write access are not verified.'
        : 'Audit preflight passed for this web-worker identity.'];
}
