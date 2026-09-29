<?php
/** End-to-end: release manifest, `maintenance.php upgrade` dry run/apply/rollback, refusals, update check. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

$repo = dirname(__DIR__);
$passed = 0;
$failed = 0;
function check_upgrade(bool $condition, string $message): void {
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
}

function upgrade_run(array $command, string $cwd, array $env = []): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env + getenv());
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'out' => $out, 'err' => $err, 'json' => json_decode((string)$out, true)];
}

function upgrade_copy(string $from, string $to): void {
    mkdir($to, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . substr($item->getPathname(), strlen($from));
        $item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
    }
}

function upgrade_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/** Every file except logs/ (the upgrade writes its backup and the smoke test its audit there). */
function upgrade_snapshot(string $dir): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
        if ($item->isFile() && !str_starts_with($relative, 'logs/')) $files[$relative] = hash_file('sha256', $item->getPathname());
    }
    ksort($files);
    return $files;
}

/** Recompute the manifest of a hand-edited package: hashes, removed files, extra code files, version. */
function upgrade_remanifest(string $dir, string $version, array $addCode = []): void {
    $manifest = json_decode(file_get_contents("$dir/.bbf-manifest.json"), true);
    $manifest['version'] = $version;
    foreach ($addCode as $path) $manifest['files'][$path] = ['sha256' => '', 'kind' => 'code'];
    foreach ($manifest['files'] as $path => $file) {
        if (!is_file("$dir/$path")) { unset($manifest['files'][$path]); continue; }
        $manifest['files'][$path]['sha256'] = hash_file('sha256', "$dir/$path");
    }
    ksort($manifest['files']);
    file_put_contents("$dir/.bbf-manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/bbf-upgrade-test-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700);
try {
    // ─── Release manifest ───────────────────────────────────────────
    $build = upgrade_run([PHP_BINARY, "$repo/tools/package-deploy.php", '--version', '2.1.0', '--destination', "$tmp/old"], $repo);
    check_upgrade($build['code'] === 0, 'packager builds a versioned package');
    $manifest = json_decode((string)@file_get_contents("$tmp/old/.bbf-manifest.json"), true);
    check_upgrade(($manifest['version'] ?? null) === '2.1.0' && ($manifest['name'] ?? null) === 'BareBonesForms', 'manifest carries name and version');
    $kinds = array_map(static fn($f) => $f['kind'], $manifest['files'] ?? []);
    check_upgrade(($kinds['templates/confirm.html'] ?? '') === 'seed' && ($kinds['logs/.gitkeep'] ?? '') === 'seed', 'templates and .gitkeep are seeds');
    check_upgrade(($kinds['forms/kontakt.json'] ?? '') === 'sample' && ($kinds['demo1.html'] ?? '') === 'extra' && ($kinds['README.md'] ?? '') === 'extra'
        && ($kinds['payment.php'] ?? '') === 'code', 'demo forms are samples; docs and demo pages are extras; endpoints are code');
    check_upgrade(($kinds['bbf.js'] ?? '') === 'code' && ($kinds['forms/form.schema.json'] ?? '') === 'code' && ($kinds['config.example.php'] ?? '') === 'code',
        'code, schema and config example are code');
    check_upgrade(($kinds['.htaccess'] ?? '') === 'seed', '.htaccess is a seed: host lines such as AddHandler survive upgrades');
    check_upgrade(($kinds['check.php'] ?? '') === 'extra' && ($kinds['api-psc.php'] ?? '') === 'extra' && ($kinds['data/psc-to-city.json'] ?? '') === 'extra',
        'files README tells you to delete are extras, never added back');
    check_upgrade(!isset($kinds['config.php']) && !isset($kinds['.bbf-package']) && !isset($kinds['.bbf-manifest.json']), 'manifest never lists config.php, the marker or itself');
    $sameHashes = true;
    foreach ($manifest['files'] ?? [] as $path => $file) $sameHashes = $sameHashes && hash_file('sha256', "$tmp/old/$path") === $file['sha256'];
    check_upgrade($sameHashes, 'every manifest hash matches the packaged file');
    $bad = upgrade_run([PHP_BINARY, "$repo/tools/package-deploy.php", '--version', 'v2', '--destination', "$tmp/bad"], $repo);
    check_upgrade($bad['code'] === 2 && !is_dir("$tmp/bad"), 'packager rejects a malformed --version');
    unlink("$tmp/old/.bbf-package");

    // ─── An installed 2.1.0 site with local data and edits ──────────
    upgrade_copy("$tmp/old", "$tmp/site");
    copy("$tmp/site/config.example.php", "$tmp/site/config.php");
    file_put_contents("$tmp/site/templates/confirm.html", '<p>My own confirmation email</p>');
    copy("$tmp/site/forms/kontakt.json", "$tmp/site/forms/mine.json");
    file_put_contents("$tmp/site/forms/mine.json", str_replace('"kontakt"', '"mine"', file_get_contents("$tmp/site/forms/mine.json")));
    mkdir("$tmp/site/submissions/kontakt", 0700, true);
    file_put_contents("$tmp/site/submissions/kontakt/record.json", '{"id":"x"}');
    $version = upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/site");
    check_upgrade(trim($version['out']) === 'BareBonesForms 2.1.0', 'maintenance.php version reports the installed release');
    $before = upgrade_snapshot("$tmp/site");

    // ─── A 2.2.0 package ─────────────────────────────────────────────
    upgrade_copy("$tmp/old", "$tmp/new");
    file_put_contents("$tmp/new/bbf.js", "\n/* 2.2.0 */\n", FILE_APPEND);
    file_put_contents("$tmp/new/templates/notify.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/templates/confirm.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/newfile.php", "<?php\n// added in 2.2.0\n");
    unlink("$tmp/new/demo10.html");
    file_put_contents("$tmp/new/config.example.php", preg_replace('/^return \[\r?\n/m', "return [\n    'brand_new_setting' => true,\n", file_get_contents("$tmp/new/config.example.php"), 1));
    file_put_contents("$tmp/new/CHANGELOG.md", preg_replace('/^(?=## \[)/m',
        "## [2.2.0] - 2026-10-01\n\n### Breaking\n- **Renamed `old_key` to `new_key`.** Rename it in config.php.\n\n", file_get_contents("$tmp/new/CHANGELOG.md"), 1));
    upgrade_remanifest("$tmp/new", '2.2.0', ['newfile.php']);

    $dry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/site");
    $plan = $dry['json'] ?? [];
    check_upgrade($dry['code'] === 0 && ($plan['ok'] ?? false) && $plan['from'] === '2.1.0' && $plan['to'] === '2.2.0', 'dry run plans 2.1.0 -> 2.2.0');
    check_upgrade(($plan['files']['add'] ?? 0) === 1 && ($plan['files']['replace'] ?? 0) === 4, 'adds the new file; replaces bbf.js, config example, CHANGELOG, untouched notify.html and nothing else');
    check_upgrade(($plan['added'] ?? []) === ['newfile.php'] && in_array('bbf.js', $plan['replaced'] ?? [], true), 'dry run names the files it adds and replaces');
    check_upgrade(($plan['files']['remove'] ?? []) === ['demo10.html'], 'an unchanged file dropped from the release is removed');
    check_upgrade(($plan['files']['keep_yours'] ?? []) === ['templates/confirm.html'], 'your edited template is kept');
    check_upgrade(($plan['new_config_settings'] ?? []) === ['brand_new_setting'], 'new config settings are listed');
    // The fixture reuses the real CHANGELOG, so releases after 2.1.0 contribute their own Breaking items too.
    $breaking = $plan['breaking'] ?? [];
    $breakingVersions = array_map(static fn($item) => strstr((string)$item, ':', true), $breaking);
    check_upgrade(str_starts_with($breaking[0] ?? '', '2.2.0: **Renamed') && count(array_keys($breakingVersions, '2.2.0', true)) === 1
        && array_filter($breakingVersions, static fn($v) => !is_string($v) || version_compare($v, '2.1.0', '<=')) === [],
        'Breaking items since the installed version are listed');
    check_upgrade(($plan['check']['status'] ?? '') === 'passed', 'new code passes the smoke test against the live forms');
    check_upgrade(upgrade_snapshot("$tmp/site") === $before, 'dry run changes nothing');

    $wrong = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new", '--apply', '--confirm=' . str_repeat('0', 64)], "$tmp/site");
    check_upgrade($wrong['code'] === 1 && upgrade_snapshot("$tmp/site") === $before, 'a wrong digest is refused without changes');

    $injected = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new", '--apply', '--confirm=' . $plan['confirm']], "$tmp/site", ['BBF_UPGRADE_TEST_FAIL' => '1']);
    check_upgrade($injected['code'] === 1 && ($injected['json']['rolled_back'] ?? false) === true, 'a failure after writing files is rolled back');
    check_upgrade(upgrade_snapshot("$tmp/site") === $before, 'rollback restores every byte and removes added files');

    // A process killed mid-upgrade leaves a journal without completed_at; upgrade-rollback still undoes it.
    upgrade_copy("$tmp/site", "$tmp/killed");
    upgrade_rmtree("$tmp/killed/logs/upgrades");
    $killedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/killed")['json'] ?? [];
    upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new", '--apply', '--confirm=' . ($killedPlan['confirm'] ?? '')], "$tmp/killed", ['BBF_UPGRADE_TEST_FAIL' => 'kill']);
    $killedBackup = glob("$tmp/killed/logs/upgrades/*", GLOB_ONLYDIR)[0] ?? '';
    $killedRollback = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$killedBackup"], "$tmp/killed")['json'] ?? [];
    check_upgrade(($killedRollback['unfinished_upgrade'] ?? false) === true, 'an interrupted upgrade can be rolled back');
    $killedApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$killedBackup", '--apply', '--confirm=' . ($killedRollback['confirm'] ?? '')], "$tmp/killed");
    check_upgrade($killedApply['code'] === 0 && upgrade_snapshot("$tmp/killed") === $before, 'the interrupted upgrade is fully undone');

    $apply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new", '--apply', '--confirm=' . $plan['confirm']], "$tmp/site");
    check_upgrade($apply['code'] === 0 && ($apply['json']['ok'] ?? false), 'upgrade applies' . ($apply['code'] === 0 ? '' : ': ' . $apply['out'] . $apply['err']));
    $after = upgrade_snapshot("$tmp/site");
    check_upgrade($after['bbf.js'] === hash_file('sha256', "$tmp/new/bbf.js") && isset($after['newfile.php']) && !isset($after['demo10.html']), 'code is replaced, added and removed');
    check_upgrade($after['templates/notify.html'] === hash_file('sha256', "$tmp/new/templates/notify.html"), 'an untouched template is updated');
    check_upgrade(file_get_contents("$tmp/site/templates/confirm.html") === '<p>My own confirmation email</p>', 'your template survives');
    foreach (['config.php', 'forms/mine.json', 'forms/kontakt.json', 'submissions/kontakt/record.json'] as $path) {
        check_upgrade($after[$path] === $before[$path], "$path is untouched");
    }
    $version = upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/site");
    check_upgrade(trim($version['out']) === 'BareBonesForms 2.2.0', 'installed version is now 2.2.0');
    $backup = (string)($apply['json']['backup'] ?? '');
    check_upgrade(is_file("$backup/upgrade.json") && is_file("$backup/files/bbf.js.bak") && str_starts_with(str_replace('\\', '/', $backup), "$tmp/site/logs/upgrades/"),
        'backup and journal are kept in logs_dir/upgrades');
    $again = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/site");
    check_upgrade(($again['json']['up_to_date'] ?? false) === true && !isset($again['json']['next']), 're-running the same package reports up to date');

    // ─── Manual rollback ─────────────────────────────────────────────
    $rollbackPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$backup"], "$tmp/site");
    check_upgrade(($rollbackPlan['json']['rollback'] ?? '') === '2.2.0 -> 2.1.0' && upgrade_snapshot("$tmp/site") === $after, 'rollback dry run describes and changes nothing');
    $rollback = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$backup", '--apply', '--confirm=' . ($rollbackPlan['json']['confirm'] ?? '')], "$tmp/site");
    check_upgrade($rollback['code'] === 0 && upgrade_snapshot("$tmp/site") === $before, 'rollback returns the site to its exact pre-upgrade state');
    $twice = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$backup"], "$tmp/site");
    check_upgrade($twice['code'] === 1 && str_contains($twice['json']['error'] ?? '', 'already rolled back'), 'a backup cannot be rolled back twice');

    // ─── Refusals ────────────────────────────────────────────────────
    $sums = "$tmp/pkg.zip";
    file_put_contents($sums, 'not really a zip');
    $mismatch = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$sums", '--checksum=' . str_repeat('a', 64)], "$tmp/site");
    check_upgrade($mismatch['code'] === 1 && str_contains($mismatch['err'], 'does not match --checksum'), 'a package that does not match the published SHA-256 is refused');
    $matched = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$sums", '--checksum=' . hash_file('sha256', $sums)], "$tmp/site");
    check_upgrade(!str_contains($matched['err'], '--checksum'), 'a matching --checksum passes on to the package checks');
    upgrade_copy("$tmp/new", "$tmp/damaged");
    file_put_contents("$tmp/damaged/bbf.js", "tampered\n", FILE_APPEND);
    $damaged = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/damaged"], "$tmp/site");
    check_upgrade($damaged['code'] === 1 && str_contains($damaged['err'], 'does not match its checksum'), 'a damaged package is refused');

    upgrade_copy("$tmp/new", "$tmp/older");
    upgrade_remanifest("$tmp/older", '2.0.9');
    $older = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/older"], "$tmp/site");
    check_upgrade($older['code'] === 1 && str_contains(implode(' ', $older['json']['problems'] ?? []), 'older than the installed'), 'a downgrade is refused');

    upgrade_copy("$tmp/new", "$tmp/syntax");
    file_put_contents("$tmp/syntax/newfile.php", "<?php\nfunction broken( {\n");
    upgrade_remanifest("$tmp/syntax", '2.2.0');
    $syntax = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/syntax"], "$tmp/site");
    check_upgrade($syntax['code'] === 1 && str_contains(implode(' ', $syntax['json']['problems'] ?? []), 'newfile.php'), 'a PHP syntax error in the package blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/smoke");
    file_put_contents("$tmp/smoke/smoketest.php", "<?php\necho \"  \u{2717} kontakt (3 fields)\\n\";\nexit(1);\n");
    upgrade_remanifest("$tmp/smoke", '2.2.0');
    $smoke = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/smoke"], "$tmp/site");
    check_upgrade($smoke['code'] === 1 && ($smoke['json']['check']['new_failures'] ?? []) === ['kontakt'], 'a form that would start failing blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/codeonly");
    foreach ((json_decode(file_get_contents("$tmp/codeonly/.bbf-manifest.json"), true)['files']) as $path => $file) {
        if ($file['kind'] !== 'code') unlink("$tmp/codeonly/$path");
    }
    $codeOnly = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/codeonly"], "$tmp/site");
    check_upgrade(($codeOnly['json']['ok'] ?? false) && !isset($codeOnly['json']['files']['keep_yours']) && ($codeOnly['json']['files']['remove'] ?? []) === ['demo10.html'],
        'the code-only upgrade package works; only files dropped from the release are removed');
    // Applied, it records only what it installed: the untouched 2.1.0 notify.html stays recognisably ours.
    upgrade_copy("$tmp/site", "$tmp/cosite");
    $coPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/codeonly"], "$tmp/cosite")['json'] ?? [];
    $coApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/codeonly", '--apply', '--confirm=' . ($coPlan['confirm'] ?? '')], "$tmp/cosite");
    $coManifest = json_decode((string)file_get_contents("$tmp/cosite/.bbf-manifest.json"), true);
    check_upgrade($coApply['code'] === 0 && ($coManifest['version'] ?? '') === '2.2.0'
        && ($coManifest['files']['templates/notify.html']['sha256'] ?? '') === hash_file('sha256', "$tmp/cosite/templates/notify.html"),
        'a code-only upgrade keeps the checksum of templates it did not install');
    $coNext = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/cosite")['json'] ?? [];
    check_upgrade(in_array('templates/notify.html', $coNext['replaced'] ?? [], true) && ($coNext['files']['keep_yours'] ?? []) === ['templates/confirm.html'],
        'the next full package still updates the untouched template and keeps yours');

    // A lean live site without demo pages, docs and sample forms does not get them back.
    upgrade_copy("$tmp/site", "$tmp/lean");
    foreach (['demo1.html', 'README.md', 'forms/newsletter.json'] as $path) unlink("$tmp/lean/$path");
    $lean = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/lean");
    check_upgrade(($lean['json']['ok'] ?? false) && ($lean['json']['files']['add'] ?? -1) === 1, 'missing demo pages, docs and sample forms are not added back');

    // ─── First upgrade of a pre-2.1 installation (no manifest), run from the unpacked release ─
    upgrade_copy("$tmp/site", "$tmp/legacy");
    upgrade_rmtree("$tmp/legacy/logs");
    mkdir("$tmp/legacy/logs", 0700);
    unlink("$tmp/legacy/.bbf-manifest.json");
    $legacyBefore = upgrade_snapshot("$tmp/legacy");
    $legacyDry = upgrade_run([PHP_BINARY, "$tmp/new/tools/upgrade.php", "--install=$tmp/legacy"], $tmp);
    $legacyPlan = $legacyDry['json'] ?? [];
    check_upgrade(($legacyPlan['ok'] ?? false) && $legacyPlan['from'] === 'unknown' && str_contains($legacyPlan['next'] ?? '', '--apply --confirm='),
        'tools/upgrade.php plans an upgrade of an installation without a manifest');
    check_upgrade(in_array('templates/notify.html', $legacyPlan['files']['keep_yours'] ?? [], true) && !isset($legacyPlan['files']['remove']),
        'without a manifest, differing templates are kept and nothing is removed');
    check_upgrade(upgrade_snapshot("$tmp/legacy") === $legacyBefore, 'the legacy dry run changes nothing');
    $legacyApply = upgrade_run([PHP_BINARY, "$tmp/new/tools/upgrade.php", "--install=$tmp/legacy", '--apply', '--confirm=' . ($legacyPlan['confirm'] ?? '')], $tmp);
    $legacyVersion = upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/legacy");
    check_upgrade($legacyApply['code'] === 0 && trim($legacyVersion['out']) === 'BareBonesForms 2.2.0', 'the legacy installation is upgraded to 2.2.0');
    $insideInstall = upgrade_run([PHP_BINARY, "$tmp/legacy/tools/upgrade.php", "--install=$tmp/legacy"], $tmp);
    check_upgrade($insideInstall['code'] === 2, 'tools/upgrade.php refuses to use the installation as its own package');

    $notPackage = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$repo/templates"], "$tmp/site");
    check_upgrade($notPackage['code'] === 1 && str_contains($notPackage['err'], 'Not a BareBonesForms release package'), 'a folder without a manifest is refused');
    check_upgrade(upgrade_snapshot("$tmp/site") === $before, 'refused upgrades change nothing');

    // ─── Update check ────────────────────────────────────────────────
    $script = "$tmp/update-check.php";
    file_put_contents($script, '<?php define("BBF_LOADED", true); require ' . var_export("$tmp/site/bbf_alerts.php", true) . '; require ' . var_export("$tmp/site/bbf_upgrade.php", true) . ";\n"
        . '$c = ["logs_dir" => ' . var_export("$tmp/site/logs", true) . "];\n"
        . 'echo json_encode([bbf_update_check($c, fn() => "v9.0.0"), bbf_update_check($c, fn() => "v9.0.0"), bbf_update_check($c, fn() => "v2.1.0"),'
        . ' bbf_update_check($c, fn() => null), bbf_update_check($c + ["update_check" => false], fn() => "v9.0.0")]);');
    $update = upgrade_run([PHP_BINARY, $script], $tmp);
    $states = array_column($update['json'] ?? [], 'status');
    check_upgrade($states === ['available', 'available', 'current', 'unavailable', 'disabled'], 'update check: available, current, unavailable, disabled');
    $incidents = array_filter(file("$tmp/site/logs/incidents.log") ?: [], static fn($line) => str_contains($line, 'Update available'));
    check_upgrade(count($incidents) === 1 && str_contains(reset($incidents), '9.0.0'), 'a new release is reported once, not every day');
} catch (Throwable $error) {
    check_upgrade(false, 'harness exception: ' . $error->getMessage());
} finally {
    upgrade_rmtree($tmp);
}

echo "\nUpgrade: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
