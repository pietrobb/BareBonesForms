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
    $errFile = tempnam(sys_get_temp_dir(), 'bbft'); // stderr in a file: two pipes read in turn can deadlock
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $cwd, $env + getenv());
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    $err = (string)file_get_contents($errFile);
    unlink($errFile);
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
    // maintenance.php is the script running the upgrade: Windows cannot rename over it (2.1.3 failed there).
    file_put_contents("$tmp/new/maintenance.php", "\n// 2.2.0\n", FILE_APPEND);
    file_put_contents("$tmp/new/templates/notify.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/templates/confirm.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/newfile.php", "<?php\n// added in 2.2.0\n");
    unlink("$tmp/new/demo10.html");
    file_put_contents("$tmp/new/config.example.php", preg_replace('/^return \[\r?\n/m', "return [\n    'brand_new_setting' => true,\n", file_get_contents("$tmp/new/config.example.php"), 1));
    file_put_contents("$tmp/new/CHANGELOG.md", preg_replace('/^(?=## \[)/m',
        "## [2.2.0] - 2026-10-01\n\n### Breaking\n- **Renamed `old_key` to `new_key`.** Rename it in config.php.\n\n", file_get_contents("$tmp/new/CHANGELOG.md"), 1));
    upgrade_remanifest("$tmp/new", '2.2.0', ['newfile.php']);

    $dry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/site");
    $plan = $dry['json'] ?? [];
    check_upgrade($dry['code'] === 0 && ($plan['ok'] ?? false) && $plan['from'] === '2.1.0' && $plan['to'] === '2.2.0', 'dry run plans 2.1.0 -> 2.2.0');
    check_upgrade(($plan['files']['add'] ?? 0) === 1 && ($plan['files']['replace'] ?? 0) === 5, 'adds the new file; replaces bbf.js, maintenance.php, config example, CHANGELOG, untouched notify.html and nothing else');
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

    $wrong = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new", '--apply', '--confirm=' . str_repeat('0', 64)], "$tmp/site");
    check_upgrade($wrong['code'] === 1 && upgrade_snapshot("$tmp/site") === $before, 'a wrong digest is refused without changes');

    check_upgrade(!str_contains((string)file_get_contents("$repo/bbf_upgrade.php"), 'getenv('), 'the upgrader has no environment-controlled test hooks');

    // A package whose smoke test passes when staged but fails once installed: the real post-upgrade check trips.
    upgrade_copy("$tmp/new", "$tmp/postfail");
    file_put_contents("$tmp/postfail/smoketest.php", "<?php\nif (basename(__DIR__) !== 'site') { echo \"1/1 forms passed\\n\"; exit(0); }\necho \"  \u{2717} kontakt (3 fields)\\n\";\nexit(1);\n");
    upgrade_remanifest("$tmp/postfail", '2.2.0', ['newfile.php']);
    $pfPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/postfail"], "$tmp/site")['json'] ?? [];
    $injected = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/postfail", '--apply', '--confirm=' . ($pfPlan['confirm'] ?? '')], "$tmp/site");
    check_upgrade($injected['code'] === 1 && ($injected['json']['rolled_back'] ?? false) === true, 'a failure after writing files is rolled back');
    check_upgrade(upgrade_snapshot("$tmp/site") === $before, 'rollback restores every byte and removes added files');

    // A process killed mid-upgrade leaves a journal without completed_at; upgrade-rollback still undoes it.
    upgrade_copy("$tmp/site", "$tmp/killed");
    upgrade_rmtree("$tmp/killed/logs/upgrades");
    $killedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/killed")['json'] ?? [];
    upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new", '--apply', '--confirm=' . ($killedPlan['confirm'] ?? '')], "$tmp/killed");
    $killedBackup = glob("$tmp/killed/logs/upgrades/*", GLOB_ONLYDIR)[0] ?? '';
    $killedJournal = json_decode((string)file_get_contents("$killedBackup/upgrade.json"), true);
    unset($killedJournal['completed_at']); // what a process killed after its last write leaves behind
    file_put_contents("$killedBackup/upgrade.json", json_encode($killedJournal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $killedRollback = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$killedBackup"], "$tmp/killed")['json'] ?? [];
    check_upgrade(($killedRollback['unfinished_upgrade'] ?? false) === true, 'an interrupted upgrade can be rolled back');
    $killedApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$killedBackup", '--apply', '--confirm=' . ($killedRollback['confirm'] ?? '')], "$tmp/killed");
    check_upgrade($killedApply['code'] === 0 && upgrade_snapshot("$tmp/killed") === $before, 'the interrupted upgrade is fully undone');

    $apply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new", '--apply', '--confirm=' . $plan['confirm']], "$tmp/site");
    check_upgrade($apply['code'] === 0 && ($apply['json']['ok'] ?? false), 'upgrade applies' . ($apply['code'] === 0 ? '' : ': ' . $apply['out'] . $apply['err']));
    $after = upgrade_snapshot("$tmp/site");
    check_upgrade($after['bbf.js'] === hash_file('sha256', "$tmp/new/bbf.js") && isset($after['newfile.php']) && !isset($after['demo10.html']), 'code is replaced, added and removed');
    check_upgrade($after['maintenance.php'] === hash_file('sha256', "$tmp/new/maintenance.php"), 'the running maintenance.php is replaced too');
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
    $again = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/site");
    check_upgrade(($again['json']['up_to_date'] ?? false) === true && !isset($again['json']['next']), 're-running the same package reports up to date');

    // ─── A rollback that fails part-way can be run again ─────────────
    upgrade_copy("$tmp/site", "$tmp/retry");
    $retryBackup = "$tmp/retry/logs/upgrades/" . basename($backup);
    $retryJournal = json_decode((string)file_get_contents("$retryBackup/upgrade.json"), true);
    $retryJournal['install'] = "$tmp/retry";
    file_put_contents("$retryBackup/upgrade.json", json_encode($retryJournal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename("$retryBackup/files/bbf.js.bak", "$tmp/bbf.js.bak.hidden");
    $retryPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$retryBackup"], "$tmp/retry")['json'] ?? [];
    $retryFail = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$retryBackup", '--apply', '--confirm=' . ($retryPlan['confirm'] ?? '')], "$tmp/retry");
    $retryVersion = trim(upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/retry")['out']);
    check_upgrade($retryFail['code'] === 1 && $retryVersion === 'BareBonesForms 2.2.0', 'a failed rollback leaves the new manifest in place');
    rename("$tmp/bbf.js.bak.hidden", "$retryBackup/files/bbf.js.bak");
    $retryPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$retryBackup"], "$tmp/retry")['json'] ?? [];
    $retryOk = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$retryBackup", '--apply', '--confirm=' . ($retryPlan['confirm'] ?? '')], "$tmp/retry");
    $retrySnap = upgrade_snapshot("$tmp/retry");
    check_upgrade($retryOk['code'] === 0 && $retrySnap === $before, 'the rollback can be repeated and then restores the exact pre-upgrade state');

    // ─── A rollback does not rewrite files the failed upgrade never reached ─
    upgrade_copy("$tmp/old", "$tmp/unreached");
    $unreachedBackup = "$tmp/unreached-backup";
    mkdir("$unreachedBackup/files", 0700, true);
    copy("$tmp/unreached/bbf.js", "$unreachedBackup/files/bbf.js.bak");
    file_put_contents("$unreachedBackup/upgrade.json", json_encode(['from' => '2.1.0', 'to' => '2.2.0', 'install' => "$tmp/unreached",
        'started_at' => date('c'), 'written' => ['bbf.js'], 'removed' => [], 'saved' => ['bbf.js']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    chmod("$tmp/unreached/bbf.js", 0444); // cannot be replaced (Windows: read-only; elsewhere the folder below)
    chmod("$tmp/unreached", 0555);
    $unreachedPlan = upgrade_run([PHP_BINARY, "$tmp/site/maintenance.php", 'upgrade-rollback', "--backup=$unreachedBackup"], "$tmp/unreached")['json'] ?? [];
    $unreached = upgrade_run([PHP_BINARY, "$tmp/site/maintenance.php", 'upgrade-rollback', "--backup=$unreachedBackup", '--apply', '--confirm=' . ($unreachedPlan['confirm'] ?? '')], "$tmp/unreached");
    chmod("$tmp/unreached", 0755);
    chmod("$tmp/unreached/bbf.js", 0644);
    check_upgrade($unreached['code'] === 0 && ($unreached['json']['errors'] ?? null) === [], 'an unchanged file is not reported as a rollback error'
        . ($unreached['code'] === 0 ? '' : ': ' . $unreached['out'] . $unreached['err']));

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
    $damaged = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/damaged"], "$tmp/site");
    check_upgrade($damaged['code'] === 1 && str_contains($damaged['err'], 'does not match its checksum'), 'a damaged package is refused');

    upgrade_copy("$tmp/new", "$tmp/older");
    upgrade_remanifest("$tmp/older", '2.0.9');
    $older = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/older"], "$tmp/site");
    check_upgrade($older['code'] === 1 && str_contains(implode(' ', $older['json']['problems'] ?? []), 'older than the installed'), 'a downgrade is refused');

    upgrade_copy("$tmp/new", "$tmp/syntax");
    file_put_contents("$tmp/syntax/newfile.php", "<?php\nfunction broken( {\n");
    upgrade_remanifest("$tmp/syntax", '2.2.0');
    $syntax = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/syntax"], "$tmp/site");
    check_upgrade($syntax['code'] === 1 && str_contains(implode(' ', $syntax['json']['problems'] ?? []), 'newfile.php'), 'a PHP syntax error in the package blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/smoke");
    file_put_contents("$tmp/smoke/smoketest.php", "<?php\necho \"  \u{2717} kontakt (3 fields)\\n\";\nexit(1);\n");
    upgrade_remanifest("$tmp/smoke", '2.2.0');
    $smoke = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/smoke"], "$tmp/site");
    check_upgrade($smoke['code'] === 1 && ($smoke['json']['check']['new_failures'] ?? []) === ['kontakt'], 'a form that would start failing blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/codeonly");
    foreach ((json_decode(file_get_contents("$tmp/codeonly/.bbf-manifest.json"), true)['files']) as $path => $file) {
        if ($file['kind'] !== 'code') unlink("$tmp/codeonly/$path");
    }
    $codeOnly = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly"], "$tmp/site");
    check_upgrade(($codeOnly['json']['ok'] ?? false) && !isset($codeOnly['json']['files']['keep_yours']) && ($codeOnly['json']['files']['remove'] ?? []) === ['demo10.html'],
        'the code-only upgrade package works; only files dropped from the release are removed');
    // Applied, it records only what it installed: the untouched 2.1.0 notify.html stays recognisably ours.
    upgrade_copy("$tmp/site", "$tmp/cosite");
    $coPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly"], "$tmp/cosite")['json'] ?? [];
    $coApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly", '--apply', '--confirm=' . ($coPlan['confirm'] ?? '')], "$tmp/cosite");
    $coManifest = json_decode((string)file_get_contents("$tmp/cosite/.bbf-manifest.json"), true);
    check_upgrade($coApply['code'] === 0 && ($coManifest['version'] ?? '') === '2.2.0'
        && ($coManifest['files']['templates/notify.html']['sha256'] ?? '') === hash_file('sha256', "$tmp/cosite/templates/notify.html"),
        'a code-only upgrade keeps the checksum of templates it did not install');
    $coNext = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/cosite")['json'] ?? [];
    check_upgrade(in_array('templates/notify.html', $coNext['replaced'] ?? [], true) && ($coNext['files']['keep_yours'] ?? []) === ['templates/confirm.html'],
        'the next full package still updates the untouched template and keeps yours');

    // A lean live site without demo pages, docs and sample forms does not get them back.
    upgrade_copy("$tmp/site", "$tmp/lean");
    foreach (['demo1.html', 'README.md', 'forms/newsletter.json'] as $path) unlink("$tmp/lean/$path");
    $lean = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/lean");
    check_upgrade(($lean['json']['ok'] ?? false) && ($lean['json']['files']['add'] ?? -1) === 1, 'missing demo pages, docs and sample forms are not added back');

    // ─── Release history: a file the 2.1.0 upgrader mis-recorded is still recognised as ours ─
    upgrade_copy("$tmp/site", "$tmp/hsite");
    $hManifest = json_decode((string)file_get_contents("$tmp/hsite/.bbf-manifest.json"), true);
    foreach (['README.md', 'templates/notify.html'] as $path) $hManifest['files'][$path]['sha256'] = str_repeat('e', 64); // recorded, never on disk
    file_put_contents("$tmp/hsite/.bbf-manifest.json", json_encode($hManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    upgrade_copy("$tmp/new", "$tmp/hist");
    file_put_contents("$tmp/hist/README.md", "\n2.2.0 docs\n", FILE_APPEND);
    upgrade_remanifest("$tmp/hist", '2.2.0', ['newfile.php']);
    $noHistory = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/hist"], "$tmp/hsite")['json'] ?? [];
    check_upgrade(in_array('README.md', $noHistory['files']['overwrite_local_edits'] ?? [], true)
        && in_array('templates/notify.html', $noHistory['files']['keep_yours'] ?? [], true), 'without history a mis-recorded checksum looks like a local edit');
    $histManifest = json_decode((string)file_get_contents("$tmp/hist/.bbf-manifest.json"), true);
    foreach (['README.md', 'templates/notify.html'] as $path) $histManifest['files'][$path]['history'] = [hash_file('sha256', "$tmp/old/$path")];
    file_put_contents("$tmp/hist/.bbf-manifest.json", json_encode($histManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $withHistory = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/hist"], "$tmp/hsite")['json'] ?? [];
    check_upgrade(($withHistory['ok'] ?? false) && !in_array('README.md', $withHistory['files']['overwrite_local_edits'] ?? [], true)
        && in_array('templates/notify.html', $withHistory['replaced'] ?? [], true)
        && ($withHistory['files']['keep_yours'] ?? []) === ['templates/confirm.html'],
        'a file matching an earlier published release is ours: updated, not reported as locally edited');
    $packaged = json_decode((string)file_get_contents("$tmp/old/.bbf-manifest.json"), true);
    $historyFile = json_decode((string)@file_get_contents("$repo/tools/release-history.json"), true) ?: [];
    check_upgrade(isset($historyFile['README.md']) && array_values(array_diff($historyFile['README.md'], [$packaged['files']['README.md']['sha256']])) === ($packaged['files']['README.md']['history'] ?? []),
        'the packager copies tools/release-history.json into the manifest');

    // ─── Code-only ZIP: .htaccess arrives as .htaccess.dist ──────────
    upgrade_copy("$tmp/codeonly", "$tmp/codeonly2");
    file_put_contents("$tmp/codeonly2/.htaccess.dist", str_replace("\r\n", "\n", (string)file_get_contents("$tmp/new/.htaccess")) . "\n# 2.2.0 rule\nHeader always set X-BBF-Rule-220 \"1\"\n");
    $coManifest2 = json_decode((string)file_get_contents("$tmp/codeonly2/.bbf-manifest.json"), true);
    $coManifest2['files']['.htaccess']['sha256'] = hash_file('sha256', "$tmp/codeonly2/.htaccess.dist");
    file_put_contents("$tmp/codeonly2/.bbf-manifest.json", json_encode($coManifest2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $dist = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly2"], "$tmp/site")['json'] ?? [];
    check_upgrade(($dist['ok'] ?? false) && in_array('.htaccess', $dist['replaced'] ?? [], true), 'an unchanged .htaccess is updated from .htaccess.dist of the code-only ZIP');
    upgrade_copy("$tmp/site", "$tmp/hta");
    file_put_contents("$tmp/hta/.htaccess", "AddHandler application/x-httpd-php84 .php\n", FILE_APPEND);
    $distYours = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly2"], "$tmp/hta")['json'] ?? [];
    check_upgrade(in_array('.htaccess', $distYours['files']['keep_yours'] ?? [], true), 'an .htaccess with your own lines is kept');
    check_upgrade(in_array('.htaccess.dist', $distYours['added'] ?? [], true) && str_contains(implode(' ', $distYours['notices'] ?? []), '.htaccess.dist'),
        'the new rules are written next to your .htaccess as .htaccess.dist and the plan says so');
    $htaOwn = file_get_contents("$tmp/hta/.htaccess");
    $htaApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly2", '--apply', '--confirm=' . ($distYours['confirm'] ?? '')], "$tmp/hta");
    check_upgrade($htaApply['code'] === 0 && file_get_contents("$tmp/hta/.htaccess") === $htaOwn
        && @file_get_contents("$tmp/hta/.htaccess.dist") === file_get_contents("$tmp/codeonly2/.htaccess.dist")
        && str_contains(implode(' ', $htaApply['json']['notices'] ?? []), '.htaccess.dist'), 'the upgrade keeps your .htaccess, writes .htaccess.dist and repeats the notice');
    // Review 2.1.5: a release that only rewords comments in .htaccess raises no alarm about security rules.
    upgrade_copy("$tmp/codeonly", "$tmp/codeonly3");
    file_put_contents("$tmp/codeonly3/.htaccess.dist", "# Reworded comment of a later release\n" . file_get_contents("$tmp/new/.htaccess"));
    $coManifest3 = json_decode((string)file_get_contents("$tmp/codeonly3/.bbf-manifest.json"), true);
    $coManifest3['files']['.htaccess']['sha256'] = hash_file('sha256', "$tmp/codeonly3/.htaccess.dist");
    file_put_contents("$tmp/codeonly3/.bbf-manifest.json", json_encode($coManifest3, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    upgrade_copy("$tmp/site", "$tmp/hta3");
    file_put_contents("$tmp/hta3/.htaccess", "AddHandler application/x-httpd-php84 .php\n", FILE_APPEND);
    $commentsOnly = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly3"], "$tmp/hta3")['json'] ?? [];
    check_upgrade(($commentsOnly['ok'] ?? false) && in_array('.htaccess', $commentsOnly['files']['keep_yours'] ?? [], true)
        && !in_array('.htaccess.dist', $commentsOnly['added'] ?? [], true) && !str_contains(implode(' ', $commentsOnly['notices'] ?? []), 'security rules'),
        'changed comments only: your .htaccess is kept without a security-rules notice');
    $missingScript = "$tmp/missing-rules.php";
    file_put_contents($missingScript, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true) . '; echo json_encode(bbf_htaccess_missing_rules($argv[1]));');
    $missing = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(count($missing ?? []) === 1 && str_contains($missing[0], 'X-BBF-Rule-220') && !str_contains($missing[0], 'AddHandler'),
        'check.php/selfcheck name the release rule still missing from your .htaccess, not your own lines');
    file_put_contents("$tmp/hta/.htaccess", $htaOwn . "Header always set X-BBF-Rule-220 \"1\"\n");
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], 'once the rule is copied over nothing is reported');
    unlink("$tmp/hta/.htaccess.dist");
    file_put_contents("$tmp/hta/.htaccess", "Options -Indexes\n");
    $noMd = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(count($noMd ?? []) === 1 && str_contains($noMd[0], '\.md$'), 'without .htaccess.dist an .htaccess lacking the *.md rule is reported');
    check_upgrade(str_contains($noMd[0] ?? '', '^bbf_.*\.php$') && str_contains($noMd[0] ?? '', 'RewriteRule ^config/'),
        'and so are the bbf_*.php and config/ rules');
    // Review 2.1.4: an .htaccess.dist from an earlier release must not recommend its older, weaker rule.
    file_put_contents("$tmp/hta/.htaccess.dist", "Options -Indexes\n<FilesMatch \"^config\">\nRequire all denied\n</FilesMatch>\n");
    $stale = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(str_contains(implode(' ', $stale ?? []), 'earlier release') && str_contains(implode(' ', $stale ?? []), '^bbf_.*\.php$')
        && !str_contains(implode(' ', $stale ?? []), '<FilesMatch "^config">'), 'a stale .htaccess.dist is ignored and named; the current rules are recommended');
    // Review 2.1.5: a 2.1.4-era .htaccess.dist has every essential rule; the manifest still tells it from the current one.
    copy("$tmp/new/.htaccess", "$tmp/hta/.htaccess.dist");
    $recent = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(str_contains(implode(' ', $recent ?? []), 'earlier release'), 'an .htaccess.dist of an earlier release with all essential rules is recognized by its hash');
    file_put_contents("$tmp/hta/.htaccess.dist", str_replace("\n", "\r\n", (string)file_get_contents("$tmp/codeonly2/.htaccess.dist")));
    $current = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(!str_contains(implode(' ', $current ?? []), 'earlier release') && str_contains(implode(' ', $current ?? []), 'compare it with .htaccess.dist'),
        'the current release\'s .htaccess.dist is used, also with CRLF line endings from an FTP transfer');
    unlink("$tmp/hta/.htaccess.dist");
    file_put_contents("$tmp/hta/.htaccess", file_get_contents("$repo/.htaccess"));
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], 'the shipped .htaccess has every essential rule');
    unlink("$tmp/hta/.htaccess");
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], 'without .htaccess (Nginx) nothing is reported here');

    // ─── maintenance.php runs the upgrader of the package, not the installed one ─
    upgrade_copy("$tmp/new", "$tmp/deleg");
    $delegCode = file_get_contents("$tmp/deleg/bbf_upgrade.php");
    file_put_contents("$tmp/deleg/bbf_upgrade.php", str_replace("'notices' => \$notices,", "'notices' => array_merge(\$notices, ['planned by the 2.2.0 upgrader']),", $delegCode, $replaced));
    upgrade_remanifest("$tmp/deleg", '2.2.0', ['newfile.php']);
    upgrade_copy("$tmp/site", "$tmp/dsite");
    $delegDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg"], "$tmp/dsite");
    $delegPlan = $delegDry['json'] ?? [];
    check_upgrade($replaced === 1 && $delegDry['code'] === 0 && ($delegPlan['upgrader'] ?? '') === 'package 2.2.0'
        && in_array('planned by the 2.2.0 upgrader', $delegPlan['notices'] ?? [], true), 'the dry run is planned by the upgrader shipped in the package');
    $delegApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg", '--apply', '--confirm=' . ($delegPlan['confirm'] ?? '')], "$tmp/dsite");
    check_upgrade($delegApply['code'] === 0 && ($delegApply['json']['upgrader'] ?? '') === 'package 2.2.0'
        && trim(upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/dsite")['out']) === 'BareBonesForms 2.2.0'
        && hash_file('sha256', "$tmp/dsite/bbf_upgrade.php") === hash_file('sha256', "$tmp/deleg/bbf_upgrade.php"), 'and applied by it: the site is on 2.2.0 with the new upgrader');
    $sameDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/site")['json'] ?? [];
    check_upgrade(($sameDry['ok'] ?? false) && !isset($sameDry['upgrader']), 'an identical upgrader in the package runs in-process');

    // ─── Review 2.1.4: an unverified package's code never runs in a dry run ─
    upgrade_copy("$tmp/deleg", "$tmp/evil");
    $ran = "$tmp/package-code-ran.txt";
    foreach (['bbf_upgrade.php', 'smoketest.php', 'bbf_auth.php', 'bbf_functions.php'] as $file) {
        $code = file_get_contents("$tmp/evil/$file");
        file_put_contents("$tmp/evil/$file", preg_replace('/\A<\?php/', '<?php file_put_contents(' . var_export($ran, true) . ', basename(__FILE__) . "\n", FILE_APPEND);', $code, 1));
    }
    upgrade_remanifest("$tmp/evil", '2.2.1');
    upgrade_copy("$tmp/site", "$tmp/esite");
    $evilDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/evil"], "$tmp/esite");
    check_upgrade($evilDry['code'] === 0 && !is_file($ran), 'a dry run without --checksum/--trust-package runs none of the package code: ' . @file_get_contents($ran));
    check_upgrade(($evilDry['json']['check']['status'] ?? '') === 'skipped' && !isset($evilDry['json']['upgrader'])
        && str_contains(implode(' ', $evilDry['json']['notices'] ?? []), '--checksum=') && !str_contains($evilDry['json']['next'] ?? '', '--trust-package'),
        'it says the package is not verified and how to verify it (--checksum from SHA256SUMS)');
    $evilTrusted = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/evil"], "$tmp/esite");
    check_upgrade($evilTrusted['code'] === 0 && is_file($ran) && ($evilTrusted['json']['upgrader'] ?? '') === 'package 2.2.1'
        && str_contains($evilTrusted['json']['next'] ?? '', '--trust-package'), 'with --trust-package the package upgrader and smoke test run');
    @unlink($ran);
    $unverifiedApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/evil", '--apply', '--confirm=' . ($evilDry['json']['confirm'] ?? '')], "$tmp/esite");
    check_upgrade($unverifiedApply['code'] === 0 && ($unverifiedApply['json']['ok'] ?? false) && !isset($unverifiedApply['json']['upgrader']),
        '--apply of the unverified dry run is the operator\'s decision: applied by the installed upgrader, digest matches');
    @unlink($ran);

    // ─── Review 2.1.4: PHP notices (display_errors=On) do not break the package upgrader ─
    upgrade_copy("$tmp/site", "$tmp/nsite");
    file_put_contents("$tmp/nsite/config.php", preg_replace('/\A<\?php/', '<?php trigger_error("bbf-test-notice from config.php", E_USER_NOTICE);', file_get_contents("$tmp/nsite/config.php"), 1));
    mkdir("$tmp/ini");
    file_put_contents("$tmp/ini/zz-bbf-test.ini", "display_errors=1\nerror_reporting=E_ALL\n");
    $iniEnv = ['PHP_INI_SCAN_DIR' => "$tmp/ini"];
    $noisyDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg"], "$tmp/nsite", $iniEnv);
    check_upgrade($noisyDry['code'] === 0 && ($noisyDry['json']['upgrader'] ?? '') === 'package 2.2.0'
        && str_contains(implode(' ', $noisyDry['json']['php_messages'] ?? []), 'bbf-test-notice'),
        'a notice from config.php with display_errors=On is reported, not parsed as the result' . ($noisyDry['code'] === 0 ? '' : ': ' . substr($noisyDry['out'] . $noisyDry['err'], 0, 300)));
    $noisyApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg", '--apply', '--confirm=' . ($noisyDry['json']['confirm'] ?? '')], "$tmp/nsite", $iniEnv);
    check_upgrade($noisyApply['code'] === 0 && ($noisyApply['json']['ok'] ?? false)
        && trim(upgrade_run([PHP_BINARY, 'maintenance.php', 'version'], "$tmp/nsite")['out']) === 'BareBonesForms 2.2.0',
        'and --apply with the notice succeeds and says so' . ($noisyApply['code'] === 0 ? '' : ': ' . substr($noisyApply['out'] . $noisyApply['err'], 0, 300)));
    // Review 2.1.5: more than a pipe buffer (~64 KB) of notices must not deadlock the parent reading stdout first.
    upgrade_copy("$tmp/site", "$tmp/flood");
    file_put_contents("$tmp/flood/config.php", preg_replace('/\A<\?php/', '<?php for ($i = 0; $i < 2000; $i++) trigger_error("bbf-test-flood " . str_repeat("x", 80), E_USER_DEPRECATED);',
        file_get_contents("$tmp/flood/config.php"), 1));
    $start = microtime(true);
    $floodDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg"], "$tmp/flood", $iniEnv);
    check_upgrade($floodDry['code'] === 0 && ($floodDry['json']['upgrader'] ?? '') === 'package 2.2.0' && count($floodDry['json']['php_messages'] ?? []) === 20,
        sprintf('200 KB of notices on stderr do not hang the upgrade (%.1f s)', microtime(true) - $start)
        . ($floodDry['code'] === 0 ? '' : ': ' . substr($floodDry['out'] . $floodDry['err'], 0, 300)));
    $floodApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg", '--apply', '--confirm=' . ($floodDry['json']['confirm'] ?? '')], "$tmp/flood", $iniEnv);
    check_upgrade($floodApply['code'] === 0 && ($floodApply['json']['ok'] ?? false),
        'and --apply with the flood of notices (smoke test included) finishes' . ($floodApply['code'] === 0 ? '' : ': ' . substr($floodApply['out'] . $floodApply['err'], 0, 300)));

    // ─── Review 2.1.4: only maintenance.php may be written in place (Windows); a library never ─
    upgrade_copy("$tmp/deleg", "$tmp/lockpkg");
    file_put_contents("$tmp/lockpkg/bbf_functions.php", "\n// 2.2.2 change\n", FILE_APPEND);
    upgrade_remanifest("$tmp/lockpkg", '2.2.2');
    $lockDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/lockpkg"], "$tmp/dsite")['json'] ?? [];
    $libBefore = hash_file('sha256', "$tmp/dsite/bbf_functions.php");
    $held = fopen("$tmp/dsite/bbf_functions.php", 'r'); // on Windows an open handle blocks renaming over the file
    $locked = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/lockpkg", '--apply', '--confirm=' . ($lockDry['confirm'] ?? '')], "$tmp/dsite");
    fclose($held);
    if (PHP_OS_FAMILY === 'Windows') {
        check_upgrade($locked['code'] !== 0 && hash_file('sha256', "$tmp/dsite/bbf_functions.php") === $libBefore && ($locked['json']['rolled_back'] ?? false) === true && ($locked['json']['rollback_errors'] ?? null) === [],
            'a library that cannot be renamed is not written in place: the upgrade fails and rolls back (' . json_encode([$locked['json']['error'] ?? $locked['err'], $locked['json']['rollback_errors'] ?? null]) . ')');
    } else {
        check_upgrade($locked['code'] === 0 && hash_file('sha256', "$tmp/dsite/bbf_functions.php") === hash_file('sha256', "$tmp/lockpkg/bbf_functions.php"),
            'an open handle does not block the atomic rename outside Windows');
    }

    // ─── Release history covers every published release (CI gate) ───
    $tagList = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' tag --list "v2.*"'));
    if ($tagList !== '') {
        $historyCheck = upgrade_run([PHP_BINARY, "$repo/tools/release-history.php", '--check'], $repo);
        check_upgrade($historyCheck['code'] === 0, 'tools/release-history.json records every published release: ' . trim($historyCheck['out'] . $historyCheck['err']));
    }

    // ─── Dry run warns when the new code would ignore a token ────────
    upgrade_copy("$tmp/site", "$tmp/tokens");
    file_put_contents("$tmp/tokens/config.php", preg_replace("/'access_tokens' => \[\],/", "'access_tokens' => [['id' => 'short-one', 'token' => 'abc', 'forms' => [], 'permissions' => ['read'], 'revoked' => false, 'expires_at' => '2030-01-01T00:00:00Z']],",
        str_replace("'api_token' => '',", "'api_token' => 'a-long-enough-admin-token-0123',", file_get_contents("$tmp/tokens/config.php"))));
    $tokenPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/tokens")['json'] ?? [];
    check_upgrade(str_contains(implode(' ', $tokenPlan['access_warnings'] ?? []), 'short-one') && !str_contains(json_encode($tokenPlan), "'abc'"),
        'the dry run names a token the new version will ignore');
    // Review 2.1.5: an unverified package runs none of its code, but the installed code still reports the token.
    $unverifiedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/tokens")['json'] ?? [];
    check_upgrade(($unverifiedPlan['check']['status'] ?? '') === 'skipped' && str_contains(implode(' ', $unverifiedPlan['access_warnings'] ?? []), 'short-one')
        && str_starts_with((string)($unverifiedPlan['access_warnings_by'] ?? ''), 'installed version'),
        'a dry run without --checksum still names the ignored token (judged by the installed code): ' . json_encode($unverifiedPlan['access_warnings'] ?? null));

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
