<?php
/**
 * Versioned upgrades. A release package carries .bbf-manifest.json: the version plus the SHA-256 and kind of
 * every file. "code" is always installed. "seed" (email templates, .gitkeep) is added when missing and updated
 * only while you never changed it. "sample" (demo forms) and "extra" (docs, demo pages) are never added to an
 * installation that lacks them; samples you changed are kept. Nothing outside the manifest —
 * config.php, your forms and templates, submissions, logs — is ever touched.
 * CLI only, through `php maintenance.php upgrade` / `upgrade-rollback`; selfcheck uses bbf_update_check().
 */
defined('BBF_LOADED') || exit;
// Run as the package upgrader, stdout carries the JSON result: PHP notices (display_errors=On on XAMPP/Windows
// hosts, e.g. from config.php) go to stderr so they cannot corrupt it. Also covers a 2.1.3/2.1.4 parent.
if (defined('BBF_UPGRADE_DELEGATED')) ini_set('display_errors', 'stderr');

const BBF_MANIFEST = '.bbf-manifest.json';
const BBF_UPGRADE_RESULT = "\n--BBF-UPGRADE-RESULT--\n";
const BBF_RELEASES_LATEST = 'https://api.github.com/repos/pietrobb/BareBonesForms/releases/latest';

/** Installed release version, or 'dev' for a git checkout / package built without --version. */
function bbf_version(): string {
    return bbf_upgrade_manifest(__DIR__)['version'] ?? 'dev';
}

function bbf_upgrade_manifest(string $dir): ?array {
    $raw = @file_get_contents($dir . '/' . BBF_MANIFEST);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    return bbf_upgrade_manifest_valid($manifest) ? $manifest : null;
}

function bbf_upgrade_manifest_valid(mixed $manifest): bool {
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'BareBonesForms' || !is_array($manifest['files'] ?? null)
        || $manifest['files'] === [] || !is_string($manifest['version'] ?? null)
        || !preg_match('/\A(?:\d+\.\d+\.\d+|dev)\z/D', $manifest['version'])) return false;
    foreach ($manifest['files'] as $path => $file) {
        if (!is_string($path) || !bbf_upgrade_safe_path($path) || !is_array($file)
            || !in_array($file['kind'] ?? null, ['code', 'seed', 'sample', 'extra'], true)
            || !is_string($file['sha256'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $file['sha256'])) return false;
        // Optional: checksums of the same file in earlier releases.
        if (isset($file['history']) && (!is_array($file['history']) || !array_is_list($file['history'])
            || array_filter($file['history'], static fn($h) => !is_string($h) || !preg_match('/\A[0-9a-f]{64}\z/D', $h)) !== [])) return false;
    }
    return true;
}

/** Relative names inside the installation only; never config.php or the manifest itself. */
function bbf_upgrade_safe_path(string $path): bool {
    if (!preg_match('/\A[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\z/D', $path) || in_array(strtolower($path), [BBF_MANIFEST, 'config.php'], true)) return false;
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') return false;
    }
    return true;
}

function bbf_upgrade_rmtree(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($dir);
}

/** Write via a sibling temp file + rename, so a reader never sees a half-written file; keeps existing permissions. */
function bbf_upgrade_put(string $target, string $data): void {
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException("Cannot create folder $dir");
    $temp = $dir . '/.' . basename($target) . '.bbf-new-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temp, $data) !== strlen($data)) {
        @unlink($temp);
        throw new RuntimeException("Cannot write $target");
    }
    @chmod($temp, is_file($target) ? fileperms($target) & 0777 : 0644);
    // Windows refuses a rename while another process briefly reads the target (a web request, an antivirus scan).
    for ($try = 0; $try < 5; $try++) {
        if (@rename($temp, $target)) return;
        usleep(200000);
    }
    @unlink($temp);
    // Windows cannot rename over the maintenance.php that runs this upgrade (the parent process holds it open);
    // writing into it works. Only that CLI entry point, never a library the web could load half-written.
    if (basename($target) !== 'maintenance.php' || !is_file($target) || @file_put_contents($target, $data, LOCK_EX) !== strlen($data)
        || hash_file('sha256', $target) !== hash('sha256', $data)) {
        throw new RuntimeException("Cannot replace $target");
    }
}

/**
 * Unpack and verify a release ZIP (or an unpacked release folder) into a private temp folder.
 * @return array{dir: string, manifest: array, present: array<string, true>}
 */
function bbf_upgrade_stage(string $package): array {
    $stage = rtrim(sys_get_temp_dir(), '/\\') . '/bbf-upgrade-' . bin2hex(random_bytes(6));
    if (!@mkdir($stage, 0700)) throw new RuntimeException('Cannot create a staging folder in ' . sys_get_temp_dir());
    try {
        if (is_dir($package)) {
            $root = rtrim($package, '/\\');
            if (!is_file($root . '/' . BBF_MANIFEST) && is_file($root . '/barebonesforms/' . BBF_MANIFEST)) $root .= '/barebonesforms';
            $manifest = bbf_upgrade_manifest($root);
            $read = static fn(string $path): ?string => is_file("$root/$path") && !is_link("$root/$path") ? (string)file_get_contents("$root/$path") : null;
        } elseif (is_file($package)) {
            if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is missing. Unzip the package and pass the folder: --package=<folder>.');
            $zip = new ZipArchive();
            if ($zip->open($package, ZipArchive::RDONLY) !== true) throw new RuntimeException("Cannot open $package as a ZIP file.");
            $prefix = $zip->locateName(BBF_MANIFEST) !== false ? '' : 'barebonesforms/';
            $raw = $zip->getFromName($prefix . BBF_MANIFEST);
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            $manifest = bbf_upgrade_manifest_valid($manifest) ? $manifest : null;
            $read = static fn(string $path): ?string => is_string($data = $zip->getFromName($prefix . $path)) ? $data : null;
        } else {
            throw new RuntimeException("Package not found: $package");
        }
        if ($manifest === null) {
            throw new RuntimeException('Not a BareBonesForms release package (' . BBF_MANIFEST . ' is missing or invalid). Use a release ZIP from GitHub, version 2.1.0 or newer.');
        }
        $present = [];
        foreach ($manifest['files'] as $path => $file) {
            $data = $read($path);
            // The code-only ZIP ships .htaccess as .htaccess.dist, so an FTP upload never replaces host lines.
            if ($data === null && $path === '.htaccess') $data = $read('.htaccess.dist');
            if ($data === null) {
                if ($file['kind'] !== 'code') continue; // the -upgrade ZIP deliberately ships code only
                throw new RuntimeException("Package is incomplete: $path is missing.");
            }
            if (!hash_equals($file['sha256'], hash('sha256', $data))) throw new RuntimeException("Package is damaged: $path does not match its checksum.");
            $target = "$stage/$path";
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) throw new RuntimeException("Cannot stage $path");
            if (file_put_contents($target, $data) !== strlen($data)) throw new RuntimeException("Cannot stage $path");
            $present[$path] = true;
        }
        return ['dir' => $stage, 'manifest' => $manifest, 'present' => $present];
    } catch (Throwable $error) {
        bbf_upgrade_rmtree($stage);
        throw $error;
    }
}

/** Top-level keys of a config.example.php, including commented-out optional ones. */
function bbf_upgrade_config_keys(string $file): array {
    $source = @file_get_contents($file);
    if (!is_string($source)) return [];
    preg_match_all('/^ {4}(?:\/\/ ?)?\'([a-z][a-z0-9_]*)\'\s*=>/m', $source, $match);
    return array_values(array_unique($match[1]));
}

/** "Breaking" items of every CHANGELOG section newer than $from, up to $to. */
function bbf_upgrade_breaking(string $changelog, string $from, string $to): array {
    $notes = [];
    foreach (array_slice(preg_split('/^## \[/m', $changelog) ?: [], 1) as $section) {
        $version = strstr($section, ']', true);
        if ($version !== 'Unreleased') {
            if (!is_string($version) || !preg_match('/\A\d+\.\d+\.\d+\z/D', $version)) continue;
            if (preg_match('/\A\d+\.\d+\.\d+\z/D', $from) && version_compare($version, $from, '<=')) continue;
            if (version_compare($version, $to, '>')) continue;
        }
        $breaking = false;
        foreach (explode("\n", str_replace("\r", '', $section)) as $line) {
            if (str_starts_with($line, '### ')) { $breaking = stripos($line, 'breaking') !== false; continue; }
            if (str_starts_with($line, '- ') && ($breaking || str_starts_with($line, '- **Breaking'))) {
                $text = substr($line, 2);
                $notes[] = "$version: " . (strlen($text) > 300 ? rtrim(substr($text, 0, 297)) . '...' : $text);
            }
        }
    }
    return $notes;
}

/**
 * Dry-run smoke test of the code in $codeDir against the live forms and templates of $install.
 * @return array{exit: int, failing: list<string>, summary: string}|null  null when child processes are unavailable
 */
function bbf_upgrade_smoke(string $codeDir, string $install): ?array {
    if (!function_exists('proc_open') || PHP_BINARY === '' || !is_file("$codeDir/smoketest.php")) return null;
    if ($codeDir !== $install) {
        // The staged code reads the real config; paths it leaves to __DIR__ defaults still point at the installation.
        $defaults = ['forms_dir' => "$install/forms", 'templates_dir' => "$install/templates", 'logs_dir' => "$install/logs", 'submissions_dir' => "$install/submissions"];
        file_put_contents("$codeDir/config.php", "<?php\ndefined('BBF_LOADED') || exit;\n\$config = require " . var_export("$install/config.php", true)
            . ";\nreturn is_array(\$config) ? \$config + " . var_export($defaults, true) . " : \$config;\n");
    }
    $process = @proc_open([PHP_BINARY, 'smoketest.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $codeDir);
    if (!is_resource($process)) return null;
    fclose($pipes[0]);
    $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $output = (string)preg_replace('/\e\[[0-9;]*m/', '', $output);
    preg_match_all('/^\s*\x{2717} (\S+) \(/mu', $output, $failing);
    $summary = preg_match('/\d+\/\d+ forms passed[^\n]*/', $output, $match) ? $match[0] : trim(substr($output, -300));
    return ['exit' => $exit, 'failing' => $failing[1], 'summary' => $summary];
}

/** Parse errors in the PHP files an upgrade would write, checked with `php -l` before anything changes. */
function bbf_upgrade_lint(string $stageDir, array $paths): ?array {
    if (!function_exists('proc_open') || PHP_BINARY === '') return null;
    $errors = [];
    foreach ($paths as $path) {
        if (!str_ends_with($path, '.php')) continue;
        $process = @proc_open([PHP_BINARY, '-l', "$stageDir/$path"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $stageDir);
        if (!is_resource($process)) return null;
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) $errors[] = "$path: " . trim(str_replace($stageDir . '/', '', $output));
    }
    return $errors;
}

/**
 * Access problems of config.php judged by the NEW code (a token too short for it, a malformed access_tokens
 * record, bad trusted_proxies), so a dry run shows before the upgrade whether anyone would be locked out.
 */
function bbf_upgrade_access_warnings(string $stageDir, string $install): array {
    if (!function_exists('proc_open') || PHP_BINARY === '' || !is_file("$stageDir/bbf_auth.php") || !is_file("$install/config.php")) return [];
    $script = 'define("BBF_LOADED", true); require $argv[1]; $c = require $argv[2];'
        . ' echo json_encode(is_array($c) && function_exists("bbf_auth_config_problems") ? array_column(bbf_auth_config_problems($c), "message") : []);';
    $process = @proc_open([PHP_BINARY, '-r', $script, "$stageDir/bbf_auth.php", "$install/config.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $install);
    if (!is_resource($process)) return [];
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $warnings = json_decode($out, true);
    return is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [];
}

/** What an upgrade to the staged package would do. Nothing is changed. */
function bbf_upgrade_plan(array $stage, string $install, bool $runPackageCode = true): array {
    $new = $stage['manifest'];
    $old = bbf_upgrade_manifest($install);
    $from = $old['version'] ?? 'unknown';
    $to = $new['version'];
    $problems = [];
    if ($to === 'dev') $problems[] = 'The package has no release version. Use a release ZIP from GitHub.';
    if (preg_match('/\A\d/', $from) && $to !== 'dev' && version_compare($to, $from, '<')) {
        $problems[] = "The package ($to) is older than the installed version ($from). To undo an upgrade, use upgrade-rollback.";
    }
    $php = (string)($new['requires_php'] ?? '8.1');
    if (version_compare(PHP_VERSION, $php, '<')) $problems[] = "Version $to needs PHP $php or newer; this is PHP " . PHP_VERSION . '.';

    $files = ['add' => [], 'replace' => [], 'remove' => [], 'overwrite_local_edits' => [], 'keep_yours' => [], 'keep_obsolete' => []];
    foreach ($new['files'] as $path => $file) {
        if (!isset($stage['present'][$path])) continue;
        $current = is_file("$install/$path") ? hash_file('sha256', "$install/$path") : null;
        if ($current === $file['sha256']) continue;
        $recorded = $old['files'][$path]['sha256'] ?? null;
        // Any published version of the file counts as ours, not only the recorded one: the 2.1.0 upgrader recorded
        // new checksums for files a code-only package never wrote (README.md, docs.html, templates).
        $ours = $current !== null && ($current === $recorded || in_array($current, $file['history'] ?? [], true));
        $kind = $file['kind'];
        // Sample forms and docs/demo pages you do not have stay absent: a live site does not grow new endpoints.
        if ($current === null && ($kind === 'sample' || $kind === 'extra')) continue;
        if (($kind === 'seed' || $kind === 'sample') && $current !== null && !$ours) {
            $files['keep_yours'][] = $path; // you changed it (or we cannot tell): yours stays
            continue;
        }
        $files[$current === null ? 'add' : 'replace'][] = $path;
        if (($kind === 'code' || $kind === 'extra') && $current !== null && $recorded !== null && !$ours) $files['overwrite_local_edits'][] = $path;
    }
    foreach ($old['files'] ?? [] as $path => $file) {
        if (!in_array($file['kind'], ['code', 'extra'], true) || isset($new['files'][$path]) || !is_file("$install/$path")) continue;
        $files[in_array(hash_file('sha256', "$install/$path"), [$file['sha256'], ...($file['history'] ?? [])], true) ? 'remove' : 'keep_obsolete'][] = $path;
    }
    // Your .htaccess (host lines such as AddHandler) is kept, but new security rules of the release must still
    // reach you: they are written next to it as .htaccess.dist and the plan says so.
    $notices = [];
    $htaccess = $new['files']['.htaccess']['sha256'] ?? null;
    if (in_array('.htaccess', $files['keep_yours'], true) && $htaccess !== ($old['files']['.htaccess']['sha256'] ?? null)) {
        $dist = is_file("$install/.htaccess.dist") ? hash_file('sha256', "$install/.htaccess.dist") : null;
        if ($dist !== $htaccess) {
            if (!copy("{$stage['dir']}/.htaccess", "{$stage['dir']}/.htaccess.dist")) throw new RuntimeException('Cannot stage .htaccess.dist');
            $files[$dist === null ? 'add' : 'replace'][] = '.htaccess.dist';
        }
        $notices[] = 'Your .htaccess has lines of your own, so it is kept. This release changed its security rules: compare .htaccess with .htaccess.dist and copy the new rules over (selfcheck reports rules still missing).';
    }

    $lint = $problems === [] ? bbf_upgrade_lint($stage['dir'], array_merge($files['add'], $files['replace'])) : [];
    if ($lint) $problems[] = 'PHP syntax errors in the package: ' . implode('; ', $lint);
    $check = ['status' => 'not run'];
    if (!$runPackageCode) {
        $check = ['status' => 'skipped', 'reason' => 'the package is not verified, so none of its code was run (no smoke test, no access check by the new code)'];
        $notices[] = 'Package not verified: only its file checksums and PHP syntax were checked. To let the dry run test the new code with your forms (and use the package\'s upgrader), '
            . 'add --checksum=<SHA-256 of the ZIP from SHA256SUMS on the GitHub release page>, or --trust-package for a package you built yourself.';
    } elseif ($problems === []) {
        $after = bbf_upgrade_smoke($stage['dir'], $install);
        if ($after === null) {
            $check = ['status' => 'skipped', 'reason' => 'this PHP cannot start child processes (proc_open); run php smoketest.php yourself after the upgrade'];
        } elseif ($after['exit'] === 0) {
            $check = ['status' => 'passed', 'summary' => $after['summary']];
        } else {
            $before = bbf_upgrade_smoke($install, $install);
            $newFailures = $after['failing'] === [] ? ['(the smoke test did not run: ' . $after['summary'] . ')']
                : array_values(array_diff($after['failing'], $before['failing'] ?? []));
            $check = ['status' => $newFailures === [] ? 'passed' : 'failed', 'summary' => $after['summary'],
                'already_failing' => $before['failing'] ?? [], 'new_failures' => $newFailures];
            if ($newFailures !== []) $problems[] = 'With the new version these forms fail the smoke test (they pass now): ' . implode(', ', $newFailures);
        }
    }

    $changes = array_sum(array_map('count', [$files['add'], $files['replace'], $files['remove']]));
    $plan = [
        'ok' => $problems === [],
        'from' => $from,
        'to' => $to,
        'install' => $install,
        'files' => ['add' => count($files['add']), 'replace' => count($files['replace'])]
            + array_filter(array_diff_key($files, ['add' => 1, 'replace' => 1])),
        'added' => $files['add'],
        'replaced' => $files['replace'],
        'new_config_settings' => is_file("$install/config.example.php")
            ? array_values(array_diff(bbf_upgrade_config_keys("{$stage['dir']}/config.example.php"), bbf_upgrade_config_keys("$install/config.example.php"))) : [],
        'breaking' => bbf_upgrade_breaking((string)@file_get_contents("{$stage['dir']}/CHANGELOG.md"), $from, $to),
        'access_warnings' => $runPackageCode ? bbf_upgrade_access_warnings($stage['dir'], $install) : [],
        'notices' => $notices,
        'check' => $check,
        'problems' => $problems,
        'up_to_date' => $changes === 0 && $from === $to,
        'confirm' => hash('sha256', json_encode([$from, $to, $new['files'], $files, $install])),
    ];
    $plan['_files'] = $files;
    return $plan;
}

/**
 * `maintenance.php upgrade` runs the upgrader shipped in the PACKAGE when it differs from the installed one, so a
 * fix to the upgrade itself (new file kinds, .htaccess.dist, release history) applies to the very upgrade that
 * ships it. Returns null when the package's upgrader is this one or PHP cannot start a child: then run in-process.
 * Stable contract with later versions: bbf_upgrade(array $config, string $package, ?string $confirm, string $install, bool $verified).
 * Package code runs only for a $verified package (--checksum matched the published SHA256SUMS, or --trust-package).
 */
function bbf_upgrade_delegate(string $package, ?string $confirm, string $install, bool $verified = false): ?array {
    if (!$verified || defined('BBF_UPGRADE_DELEGATED') || !function_exists('proc_open') || PHP_BINARY === '') return null;
    $package = realpath($package) ?: $package; // the child runs in the installation folder
    $stage = bbf_upgrade_stage($package); // verifies every checksum before any package code runs
    try {
        $new = "{$stage['dir']}/bbf_upgrade.php";
        if (!is_file($new) || hash_file('sha256', $new) === hash_file('sha256', __FILE__)) return null;
        // Notices (display_errors=On, e.g. from config.php) go to stderr; the result follows a marker line.
        $script = 'ini_set("display_errors", "stderr"); define("BBF_LOADED", true); define("BBF_UPGRADE_DELEGATED", true); require $argv[1];'
            . ' $c = (static fn() => require $argv[2])();'
            . ' if (!is_array($c)) { fwrite(STDERR, "Cannot read config.php."); exit(1); }'
            . ' try { $r = bbf_upgrade($c, $argv[3], $argv[4] === "" ? null : $argv[4], $argv[5], true);'
            . ' echo ' . var_export(BBF_UPGRADE_RESULT, true) . ', json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }'
            . ' catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }';
        $process = @proc_open([PHP_BINARY, '-r', $script, $new, "$install/config.php", $package, $confirm ?? '', $install],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $install);
        if (!is_resource($process)) return null;
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $at = strrpos($out, BBF_UPGRADE_RESULT);
        $result = $at === false ? null : json_decode(substr($out, $at + strlen(BBF_UPGRADE_RESULT)), true);
        if (!is_array($result)) throw new RuntimeException(trim($err . "\n" . $out) !== '' ? trim($err . "\n" . $out) : 'the upgrader of the package ' . $stage['manifest']['version'] . ' gave no result.');
        $result['upgrader'] = 'package ' . $stage['manifest']['version'];
        // PHP notices printed along the way are shown, but they do not turn a finished upgrade into a failure.
        $messages = array_values(array_filter(array_map('trim', explode("\n", trim($err . "\n" . substr($out, 0, $at)))), 'strlen'));
        if ($messages !== []) $result['php_messages'] = array_slice($messages, 0, 20);
        return $result;
    } finally {
        bbf_upgrade_rmtree($stage['dir']);
    }
}

/** Dry run without $confirm; with the plan's digest it backs up, upgrades, verifies and rolls back on failure. */
function bbf_upgrade(array $config, string $package, ?string $confirm, string $install = __DIR__, bool $verified = true): array {
    $stage = bbf_upgrade_stage($package);
    try {
        // An unverified package's code never runs in a dry run ("just looking"); --apply is the decision to run it.
        $plan = bbf_upgrade_plan($stage, $install, $verified || $confirm !== null);
        $files = $plan['_files'];
        unset($plan['_files']);
        if (!$plan['ok'] || $plan['up_to_date']) return $plan;
        if ($confirm === null) return $plan;
        if (!hash_equals($plan['confirm'], $confirm)) return ['ok' => false, 'error' => 'The installation or package changed since the dry run. Run the dry run again.'];
        return bbf_upgrade_apply($config, $stage, $plan, $files, $install);
    } finally {
        bbf_upgrade_rmtree($stage['dir']);
    }
}

function bbf_upgrade_apply(array $config, array $stage, array $plan, array $files, string $install): array {
    $backup = rtrim((string)($config['logs_dir'] ?? "$install/logs"), '/\\') . '/upgrades/' . date('Ymd-His') . "-{$plan['from']}-to-{$plan['to']}-" . bin2hex(random_bytes(2));
    if (!@mkdir("$backup/files", 0700, true)) throw new RuntimeException("Cannot create the backup folder $backup");
    $written = array_merge($files['add'], $files['replace']);
    $journal = ['from' => $plan['from'], 'to' => $plan['to'], 'install' => $install, 'started_at' => date('c'),
        'written' => array_merge($written, [BBF_MANIFEST]), 'removed' => $files['remove'], 'saved' => []];
    // Every file that will be overwritten or deleted is saved before the first change.
    foreach (array_merge($files['replace'], $files['remove'], [BBF_MANIFEST]) as $path) {
        if (!is_file("$install/$path")) continue;
        $copy = "$backup/files/$path.bak";
        if ((!is_dir(dirname($copy)) && !mkdir(dirname($copy), 0700, true)) || !copy("$install/$path", $copy)
            || hash_file('sha256', $copy) !== hash_file('sha256', "$install/$path")) {
            throw new RuntimeException("Cannot back up $path; nothing was changed.");
        }
        $journal['saved'][] = $path;
    }
    if (!file_put_contents("$backup/upgrade.json", json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        throw new RuntimeException('Cannot write the upgrade journal; nothing was changed.');
    }
    try {
        foreach ($written as $path) bbf_upgrade_put("$install/$path", (string)file_get_contents("{$stage['dir']}/$path"));
        foreach ($files['remove'] as $path) {
            if (!@unlink("$install/$path")) throw new RuntimeException("Cannot remove obsolete $path");
        }
        bbf_upgrade_put("$install/" . BBF_MANIFEST, json_encode(bbf_upgrade_installed_manifest($stage['manifest'], bbf_upgrade_manifest($install), $install),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $after = bbf_upgrade_smoke($install, $install);
        if ($after !== null) {
            $newFailures = array_diff($after['failing'], $plan['check']['already_failing'] ?? []);
            if ($newFailures !== [] || ($after['exit'] !== 0 && $after['failing'] === [])) {
                throw new RuntimeException('The smoke test fails after the upgrade: ' . ($newFailures ? implode(', ', $newFailures) : $after['summary']));
            }
        }
    } catch (Throwable $error) {
        $rollback = bbf_upgrade_restore($backup);
        return ['ok' => false, 'error' => 'Upgrade failed: ' . $error->getMessage(),
            'rolled_back' => $rollback['ok'], 'rollback_errors' => $rollback['errors'], 'backup' => $backup];
    }
    $journal['completed_at'] = date('c');
    @file_put_contents("$backup/upgrade.json", json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return [
        'ok' => true,
        'upgraded' => "{$plan['from']} -> {$plan['to']}",
        'files' => $plan['files'],
        'new_config_settings' => $plan['new_config_settings'],
        'breaking' => $plan['breaking'],
        'notices' => $plan['notices'],
        'check' => $after === null ? 'skipped: run php smoketest.php' : $after['summary'],
        'backup' => $backup,
        'undo' => "php maintenance.php upgrade-rollback --backup=$backup",
    ];
}

/**
 * The manifest to record: what is actually on disk. A file the upgrade did not write (not in a code-only package,
 * kept because you edited it, a sample you do not have) keeps its previous checksum, so the next upgrade still
 * recognises an unchanged template as ours and updates it.
 */
function bbf_upgrade_installed_manifest(array $new, ?array $old, string $install): array {
    $files = [];
    foreach ($new['files'] as $path => $file) {
        if (is_file("$install/$path") && hash_file('sha256', "$install/$path") === $file['sha256']) {
            $files[$path] = $file;
        } elseif (isset($old['files'][$path])) {
            $files[$path] = ['sha256' => $old['files'][$path]['sha256'], 'kind' => $file['kind']];
        }
    }
    $new['files'] = $files;
    return $new;
}

function bbf_upgrade_journal(string $backup): array {
    $journal = json_decode((string)@file_get_contents("$backup/upgrade.json"), true);
    $valid = is_array($journal) && is_string($journal['install'] ?? null) && is_dir($journal['install']);
    foreach (['written', 'removed', 'saved'] as $list) {
        $valid = $valid && is_array($journal[$list] ?? null);
        foreach ($valid ? $journal[$list] : [] as $path) {
            $valid = $valid && is_string($path) && ($path === BBF_MANIFEST || bbf_upgrade_safe_path($path));
        }
    }
    if (!$valid) throw new RuntimeException("Not an upgrade backup: $backup");
    return $journal;
}

/** Put every saved file back and delete what the upgrade added. */
function bbf_upgrade_restore(string $backup): array {
    $journal = bbf_upgrade_journal($backup);
    $install = $journal['install'];
    $errors = [];
    foreach ($journal['written'] as $path) {
        if (!in_array($path, $journal['saved'], true) && is_file("$install/$path") && !@unlink("$install/$path")) $errors[] = "cannot remove $path";
    }
    // The manifest goes back last and only after everything else did: while any file is still the new version,
    // the installation keeps reporting that version, so a failed rollback can simply be run again.
    $saved = array_values(array_diff($journal['saved'], [BBF_MANIFEST]));
    foreach ($saved as $path) {
        try {
            $data = file_get_contents("$backup/files/$path.bak");
            if (!is_string($data)) throw new RuntimeException("backup copy of $path is missing");
            // A file the failed upgrade never got to is already the saved version: nothing to restore.
            if (is_file("$install/$path") && hash_file('sha256', "$install/$path") === hash('sha256', $data)) continue;
            bbf_upgrade_put("$install/$path", $data);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
    if ($errors === [] && in_array(BBF_MANIFEST, $journal['saved'], true)) {
        try {
            $data = file_get_contents("$backup/files/" . BBF_MANIFEST . '.bak');
            if (!is_string($data)) throw new RuntimeException('backup copy of ' . BBF_MANIFEST . ' is missing');
            bbf_upgrade_put("$install/" . BBF_MANIFEST, $data);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
    // A rollback that failed part-way stays retryable: only a clean one is recorded as done.
    if ($errors === []) {
        $journal['rolled_back_at'] = date('c');
        unset($journal['rollback_errors']);
    } else {
        $journal['rollback_errors'] = $errors;
    }
    @file_put_contents("$backup/upgrade.json", json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['ok' => $errors === [], 'errors' => $errors];
}

/** Undo a completed upgrade from its backup folder (dry run without $confirm). */
function bbf_upgrade_rollback(string $backup, ?string $confirm): array {
    $backup = rtrim($backup, '/\\');
    $journal = bbf_upgrade_journal($backup);
    $installed = bbf_upgrade_manifest($journal['install'])['version'] ?? 'unknown';
    if (isset($journal['rolled_back_at'])) return ['ok' => false, 'error' => 'This upgrade was already rolled back at ' . $journal['rolled_back_at'] . '.'];
    // An upgrade that never completed (killed mid-way, or its automatic rollback failed) may have stopped before
    // or after writing the new manifest; either version means the installation is still that upgrade's.
    // A rollback that already failed part-way (2.1.1 put the old manifest back first) may also show the old version.
    $unfinished = !isset($journal['completed_at']);
    $either = $unfinished || isset($journal['rollback_errors']);
    if ($either ? !in_array($installed, [$journal['from'], $journal['to']], true) : $installed !== $journal['to']) {
        return ['ok' => false, 'error' => "The installation is at $installed, but this backup undoes the upgrade to {$journal['to']}. Roll back the newer upgrade first."];
    }
    $plan = ['ok' => true, 'rollback' => "{$journal['to']} -> {$journal['from']}", 'install' => $journal['install'],
        'unfinished_upgrade' => $unfinished,
        'restore' => count($journal['saved']), 'delete' => count(array_diff($journal['written'], $journal['saved'])),
        'confirm' => hash('sha256', (string)file_get_contents("$backup/upgrade.json"))];
    if ($confirm === null) return $plan;
    if (!hash_equals($plan['confirm'], $confirm)) return ['ok' => false, 'error' => 'The backup changed since the dry run. Run the dry run again.'];
    $result = bbf_upgrade_restore($backup);
    return ['ok' => $result['ok'], 'rolled_back' => $plan['rollback'], 'errors' => $result['errors']];
}

/**
 * Rules of this release your .htaccess lacks, for check.php and selfcheck. Compared line by line with .htaccess.dist
 * when present (the upgrade writes it next to a .htaccess you edited), otherwise with the essential rules below.
 * Without a .htaccess (Nginx and others) nothing is reported here; check.php probes the server instead.
 */
function bbf_htaccess_missing_rules(string $install): array {
    $own = @file_get_contents("$install/.htaccess");
    if (!is_string($own)) return [];
    $norm = static fn(string $line): string => (string)preg_replace('/\s+/', ' ', trim($line));
    $have = array_flip(array_map($norm, preg_split('/\R/', $own) ?: []));
    $dist = @file_get_contents("$install/.htaccess.dist");
    $essential = ['<FilesMatch "\.md$">', // README.md/CHANGELOG.md reveal the installed version
        '<FilesMatch "^config|^bbf_.*\.php$">', 'RewriteRule ^config/ - [F,L]']; // libraries and credentials (2.1.4)
    // An .htaccess.dist left by an earlier release (e.g. an older FTP upgrade) would recommend its older, weaker
    // rules: one that lacks a rule every current release has is ignored and named.
    $distLines = is_string($dist) ? array_flip(array_map($norm, preg_split('/\R/', $dist) ?: [])) : [];
    $stale = is_string($dist) && array_diff_key(array_flip($essential), $distLines) !== [];
    $wanted = is_string($dist) && !$stale
        ? [...array_filter(array_map($norm, preg_split('/\R/', $dist) ?: []), static fn(string $line): bool => $line !== '' && $line[0] !== '#'), ...$essential]
        : $essential;
    $missing = array_values(array_filter(array_unique($wanted), static fn(string $line): bool => !isset($have[$line])));
    $out = $stale ? ['.htaccess.dist is from an earlier release and is ignored; delete it (the current rules are in the release ZIP\'s .htaccess / .htaccess.dist).'] : [];
    if ($missing === []) return $out;
    return [...$out, count($missing) . ' rule line(s) of this release are missing from .htaccess' . (is_string($dist) && !$stale ? ' (compare it with .htaccess.dist and copy them over)' : '')
        . ': ' . implode(' | ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? ' | ...' : '')];
}

/**
 * Daily (from selfcheck): is a newer release on GitHub? Records one "Update available" incident per new
 * version, so error_notify hears about it once. Turn off with 'update_check' => false.
 */
function bbf_update_check(array $config, ?callable $fetchLatest = null): array {
    if (($config['update_check'] ?? true) === false) return ['status' => 'disabled'];
    $installed = bbf_version();
    if ($installed === 'dev') return ['status' => 'skipped', 'reason' => 'not installed from a release package'];
    $tag = ($fetchLatest ?? 'bbf_update_fetch_latest')();
    if (!is_string($tag) || !preg_match('/\Av?(\d+\.\d+\.\d+)\z/D', $tag, $match)) return ['status' => 'unavailable', 'installed' => $installed];
    $latest = $match[1];
    if (version_compare($latest, $installed, '<=')) return ['status' => 'current', 'installed' => $installed];
    $stateFile = rtrim((string)($config['logs_dir'] ?? __DIR__ . '/logs'), '/\\') . '/.update_state.json';
    $state = json_decode((string)@file_get_contents($stateFile), true);
    if (($state['notified'] ?? null) !== $latest && function_exists('bbf_alert_record')) {
        bbf_alert_record($config, '-', 'Update available', "BareBonesForms $latest is available (installed: $installed). "
            . "Release notes: https://github.com/pietrobb/BareBonesForms/releases/tag/v$latest — upgrade with "
            . "php maintenance.php upgrade --package=barebonesforms-v$latest.zip");
        @file_put_contents($stateFile, json_encode(['notified' => $latest, 'at' => time()]), LOCK_EX);
    }
    return ['status' => 'available', 'installed' => $installed, 'latest' => $latest];
}

function bbf_update_fetch_latest(): ?string {
    $headers = ['User-Agent: BareBonesForms-update-check', 'Accept: application/vnd.github+json'];
    if (function_exists('curl_init')) {
        $curl = curl_init(BBF_RELEASES_LATEST);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($curl);
        $ok = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200;
        curl_close($curl);
    } else {
        $body = @file_get_contents(BBF_RELEASES_LATEST, false, stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers)]]));
        $ok = is_string($body);
    }
    $release = $ok && is_string($body) ? json_decode($body, true) : null;
    return is_string($release['tag_name'] ?? null) ? $release['tag_name'] : null;
}
