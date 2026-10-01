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
    file_put_contents("$tmp/site/config.php", str_replace("'api_token' => '',", "'api_token' => 'a9c4e72b608df315e7a2b8c19df0365e',", file_get_contents("$tmp/site/config.example.php")));
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
    // Keep the running entry point unchanged here; atomic replacement of a held CLI file is tested separately.
    // A Windows operator can use the unpacked release's tools/upgrade.php when maintenance.php changes.
    file_put_contents("$tmp/new/templates/notify.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/templates/confirm.html", "\n<!-- 2.2.0 -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/docs.html", "\n<!-- updated documentation -->\n", FILE_APPEND);
    file_put_contents("$tmp/new/newfile.php", "<?php\n// added in 2.2.0\n");
    unlink("$tmp/new/demo10.html");
    file_put_contents("$tmp/new/config.example.php", preg_replace('/^return \[\r?\n/m', "return [\n    'brand_new_setting' => true,\n", file_get_contents("$tmp/new/config.example.php"), 1));
    file_put_contents("$tmp/new/CHANGELOG.md", preg_replace('/^(?=## \[)/m',
        "## [2.2.0] - 2026-10-01\n\n### Breaking\n- **Renamed `old_key` to `new_key`.** Rename it in config.php.\n\n", file_get_contents("$tmp/new/CHANGELOG.md"), 1));
    upgrade_remanifest("$tmp/new", '2.2.0', ['newfile.php']);

    $dry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/site");
    $plan = $dry['json'] ?? [];
    check_upgrade($dry['code'] === 0 && ($plan['ok'] ?? false) && $plan['from'] === '2.1.0' && $plan['to'] === '2.2.0', 'dry run plans 2.1.0 -> 2.2.0');
    check_upgrade(($plan['files']['add'] ?? 0) === 1 && ($plan['files']['replace'] ?? 0) === 5, 'adds the new file; replaces bbf.js, config example, CHANGELOG, docs.html, untouched notify.html and nothing else');
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
    check_upgrade($wrong['code'] !== 0 && upgrade_snapshot("$tmp/site") === $before, 'a wrong digest is refused without changes');

    check_upgrade(!str_contains((string)file_get_contents("$repo/bbf_upgrade.php"), 'getenv('), 'the upgrader has no environment-controlled test hooks');

    // A package whose smoke test passes when staged but fails once installed: the real post-upgrade check trips.
    upgrade_copy("$tmp/new", "$tmp/postfail");
    file_put_contents("$tmp/postfail/smoketest.php", "<?php\nif (basename(__DIR__) !== 'site') { echo \"1/1 forms passed\\n\"; exit(0); }\necho \"  \u{2717} kontakt (3 fields)\\n\";\nexit(1);\n");
    upgrade_remanifest("$tmp/postfail", '2.2.0', ['newfile.php']);
    $pfPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/postfail"], "$tmp/site")['json'] ?? [];
    $injected = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/postfail", '--apply', '--confirm=' . ($pfPlan['confirm'] ?? '')], "$tmp/site");
    check_upgrade($injected['code'] !== 0 && ($injected['json']['rolled_back'] ?? false) === true, 'a failure after writing files is rolled back');
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
    check_upgrade($after['maintenance.php'] === $before['maintenance.php'], 'an unchanged running maintenance.php is left intact');
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
    check_upgrade($retryFail['code'] !== 0 && $retryVersion === 'BareBonesForms 2.2.0', 'a failed rollback leaves the new manifest in place');
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
    check_upgrade($twice['code'] !== 0 && str_contains($twice['json']['error'] ?? '', 'already rolled back'), 'a backup cannot be rolled back twice');

    // ─── Refusals ────────────────────────────────────────────────────
    $sums = "$tmp/pkg.zip";
    file_put_contents($sums, 'not really a zip');
    $mismatch = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$sums", '--checksum=' . str_repeat('a', 64)], "$tmp/site");
    check_upgrade($mismatch['code'] !== 0 && str_contains($mismatch['err'], 'does not match --checksum'), 'a package that does not match the published SHA-256 is refused');
    $matched = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$sums", '--checksum=' . hash_file('sha256', $sums)], "$tmp/site");
    check_upgrade(!str_contains($matched['err'], '--checksum'), 'a matching --checksum passes on to the package checks');
    upgrade_copy("$tmp/new", "$tmp/damaged");
    file_put_contents("$tmp/damaged/bbf.js", "tampered\n", FILE_APPEND);
    $damaged = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/damaged"], "$tmp/site");
    check_upgrade($damaged['code'] !== 0 && str_contains($damaged['err'], 'does not match its checksum'), 'a damaged package is refused');

    upgrade_copy("$tmp/new", "$tmp/older");
    upgrade_remanifest("$tmp/older", '2.0.9');
    $older = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/older"], "$tmp/site");
    check_upgrade($older['code'] !== 0 && str_contains(implode(' ', $older['json']['problems'] ?? []), 'older than the installed'), 'a downgrade is refused');

    upgrade_copy("$tmp/new", "$tmp/syntax");
    file_put_contents("$tmp/syntax/newfile.php", "<?php\nfunction broken( {\n");
    upgrade_remanifest("$tmp/syntax", '2.2.0');
    $syntax = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/syntax"], "$tmp/site");
    check_upgrade($syntax['code'] !== 0 && str_contains(implode(' ', $syntax['json']['problems'] ?? []), 'newfile.php'), 'a PHP syntax error in the package blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/smoke");
    file_put_contents("$tmp/smoke/smoketest.php", "<?php\necho \"  \u{2717} kontakt (3 fields)\\n\";\nexit(1);\n");
    upgrade_remanifest("$tmp/smoke", '2.2.0');
    $smoke = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/smoke"], "$tmp/site");
    check_upgrade($smoke['code'] !== 0 && ($smoke['json']['check']['new_failures'] ?? []) === ['kontakt'], 'a form that would start failing blocks the upgrade');

    upgrade_copy("$tmp/new", "$tmp/codeonly");
    foreach ((json_decode(file_get_contents("$tmp/codeonly/.bbf-manifest.json"), true)['files']) as $path => $file) {
        if ($file['kind'] !== 'code' && !in_array($path, ['docs.html', 'check.php', 'CHANGELOG.md'], true)) unlink("$tmp/codeonly/$path");
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
    check_upgrade(hash_file('sha256', "$tmp/cosite/docs.html") === hash_file('sha256', "$tmp/new/docs.html"),
        'an upgrade ZIP refreshes existing documentation without restoring missing docs');
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
    // Review 2.1.6: an .htaccess.dist of the previous release is brought up to date even when only comments changed.
    upgrade_copy("$tmp/site", "$tmp/hta4");
    file_put_contents("$tmp/hta4/.htaccess", "AddHandler application/x-httpd-php84 .php\n", FILE_APPEND);
    copy("$tmp/new/.htaccess", "$tmp/hta4/.htaccess.dist");
    $distPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly3"], "$tmp/hta4")['json'] ?? [];
    $distRefresh = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/codeonly3", '--apply', '--confirm=' . ($distPlan['confirm'] ?? '')], "$tmp/hta4")['json'] ?? [];
    check_upgrade(($distRefresh['ok'] ?? false) && in_array('.htaccess.dist', $distPlan['replaced'] ?? [], true)
        && @file_get_contents("$tmp/hta4/.htaccess.dist") === file_get_contents("$tmp/codeonly3/.htaccess.dist")
        && !str_contains(implode(' ', $distPlan['notices'] ?? []), 'security rules'),
        'review 2.1.6: an older .htaccess.dist is replaced by this release\'s, without a security-rules notice');
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
    $htaBefore = file_get_contents("$tmp/hta/.htaccess");
    file_put_contents("$tmp/hta/.htaccess", file_get_contents("$tmp/new/.htaccess") . "AddHandler application/x-httpd-php84 .php\n");
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [],
        'review 2.1.6: an earlier release\'s .htaccess.dist whose rules your .htaccess already has raises no daily warning');
    file_put_contents("$tmp/hta/.htaccess", $htaBefore);
    file_put_contents("$tmp/hta/.htaccess.dist", str_replace("\n", "\r\n", (string)file_get_contents("$tmp/codeonly2/.htaccess.dist")));
    $current = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null;
    check_upgrade(!str_contains(implode(' ', $current ?? []), 'earlier release') && str_contains(implode(' ', $current ?? []), 'compare it with .htaccess.dist'),
        'the current release\'s .htaccess.dist is used, also with CRLF line endings from an FTP transfer');
    unlink("$tmp/hta/.htaccess.dist");
    file_put_contents("$tmp/hta/.htaccess", file_get_contents("$repo/.htaccess"));
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], 'the shipped .htaccess has every essential rule');
    file_put_contents("$tmp/hta/.htaccess", str_replace('<FilesMatch "\\.md$">' . "\n    Require all denied", '<FilesMatch "\\.md$">' . "\n    Require all granted", str_replace("\r\n", "\n", file_get_contents("$repo/.htaccess"))));
    $grantedMd = upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? [];
    check_upgrade(str_contains(implode(' ', $grantedMd), '\\.md$') && str_contains(implode(' ', $grantedMd), 'Require all denied'), 'Require all granted inside the md block fails even with denied directives in other blocks');
    $stockHta = str_replace("\r\n", "\n", file_get_contents("$repo/.htaccess"));
    $rewriteAt = strpos($stockHta, 'RewriteEngine On');
    $rewriteWrapped = substr($stockHta, 0, $rewriteAt) . "<IfModule mod_rewrite.c>\n" . substr($stockHta, $rewriteAt) . "</IfModule>\n";
    $authWrapped = preg_replace('/(<FilesMatch [^\n]+>\n.*?<\/FilesMatch>)/s', "<IfModule mod_authz_core.c>\n$1\n</IfModule>", $rewriteWrapped);
    $benignHta = [
        'lowercase engine value' => str_replace('RewriteEngine On', 'RewriteEngine on', $stockHta),
        'directive case only' => str_replace(['FilesMatch', 'RewriteRule', 'RewriteEngine On', 'Require all denied'], ['filesmatch', 'rewriterule', 'rewriteengine ON', 'require ALL DENIED'], $stockHta),
        'rewrite source-name guard' => $rewriteWrapped,
        'rewrite module-name guard' => str_replace('mod_rewrite.c', 'rewrite_module', $rewriteWrapped),
        'separate authz and rewrite guards' => $authWrapped,
        'authz module-name guard' => str_replace('mod_authz_core.c', 'authz_core_module', $authWrapped),
        'authz guard inside FilesMatch' => str_replace('Require all denied', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>", $stockHta),
    ];
    foreach ($benignHta as $name => $text) {
        file_put_contents("$tmp/hta/.htaccess", $text);
        check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], "benign htaccess variant: $name");
    }
    $unsafeHta = [
        'request condition on config denial' => str_replace('RewriteRule ^config/', "RewriteCond %{REMOTE_ADDR} ^127\\.0\\.0\\.1$\nRewriteRule ^config/", $rewriteWrapped),
        'condition separated by engine directive' => str_replace('RewriteRule ^config/', "RewriteCond %{REMOTE_ADDR} ^127\\.0\\.0\\.1$\nRewriteEngine on\nRewriteRule ^config/", $rewriteWrapped),
        'request-dependent If scope' => "<If \"%{REMOTE_ADDR} == '127.0.0.1'\">\n$authWrapped</If>\n",
        'negated rewrite module guard' => str_replace('mod_rewrite.c', '!mod_rewrite.c', $rewriteWrapped),
        'unrelated module guard' => str_replace('mod_rewrite.c', 'mod_headers.c', $rewriteWrapped),
        'unrecognized module identifier case' => str_replace('mod_rewrite.c', 'MOD_REWRITE.C', $rewriteWrapped),
        'weaker authz body inside known guard' => str_replace('Require all denied', 'Require all granted', $authWrapped),
        'missing forbidden flag' => str_replace('RewriteRule ^config/ - [F,L]', 'RewriteRule ^config/ - [L]', $rewriteWrapped),
        'case-sensitive path changed' => str_replace('RewriteRule ^config/', 'RewriteRule ^Config/', $stockHta),
        'engine disabled' => str_replace('RewriteEngine On', 'RewriteEngine off', $rewriteWrapped),
        'unclosed module guard' => str_replace('</IfModule>', '', $rewriteWrapped),
    ];
    foreach ($unsafeHta as $name => $text) {
        file_put_contents("$tmp/hta/.htaccess", $text);
        check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? []) !== [], "unsafe htaccess protection fails: $name");
    }
    // The plan uses the same semantic comparison, not only the selfcheck helper.
    upgrade_copy("$tmp/site", "$tmp/wrappedsite");
    file_put_contents("$tmp/wrappedsite/.htaccess", $authWrapped . "AddHandler application/x-httpd-php84 .php\n");
    $wrappedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/wrappedsite")['json'] ?? [];
    check_upgrade(($wrappedPlan['ok'] ?? false) && !in_array('.htaccess.dist', $wrappedPlan['added'] ?? [], true)
        && !str_contains(implode(' ', $wrappedPlan['notices'] ?? []), 'security rules'), 'safe module wrappers do not produce an upgrade security-rules warning');
    unlink("$tmp/hta/.htaccess");
    check_upgrade((upgrade_run([PHP_BINARY, $missingScript, "$tmp/hta"], $tmp)['json'] ?? null) === [], 'without .htaccess (Nginx) nothing is reported here');

    // Stock dist from every known legacy tag is refreshed even when .htaccess already matches the target release.
    upgrade_copy("$tmp/site", "$tmp/legacydist");
    $distTags = preg_split('/\\R/', trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' tag --list "v2.*"'))) ?: [];
    foreach ($distTags as $tag) {
        $legacyHta = upgrade_run(['git', '-C', $repo, 'show', "$tag:.htaccess"], $repo);
        if ($legacyHta['code'] !== 0 || $legacyHta['out'] === file_get_contents("$tmp/new/.htaccess")) continue;
        file_put_contents("$tmp/legacydist/.htaccess.dist", $legacyHta['out']);
        $legacyDistPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/legacydist")['json'] ?? [];
        check_upgrade(in_array('.htaccess.dist', $legacyDistPlan['replaced'] ?? [], true), "$tag stock .htaccess.dist is refreshed with unchanged current .htaccess");
    }
    file_put_contents("$tmp/legacydist/.htaccess.dist", "# My custom dist\nOptions +Indexes\n");
    $customDistPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/legacydist")['json'] ?? [];
    check_upgrade(!in_array('.htaccess.dist', $customDistPlan['replaced'] ?? [], true) && str_contains(implode(' ', $customDistPlan['notices'] ?? []), 'unrecognized'), 'locally edited dist is preserved with an actionable notice');

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

    // Review 2.1.9: never fall back to truncating the running entry point when atomic replacement fails.
    upgrade_copy("$tmp/site", "$tmp/entrysite");
    upgrade_copy("$tmp/new", "$tmp/entrypkg");
    file_put_contents("$tmp/entrypkg/maintenance.php", "\n// entry-point replacement regression\n", FILE_APPEND);
    upgrade_remanifest("$tmp/entrypkg", '2.2.0');
    $entryBefore = upgrade_snapshot("$tmp/entrysite");
    $entryPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/entrypkg"], "$tmp/entrysite")['json'] ?? [];
    $entryApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/entrypkg", '--apply', '--confirm=' . ($entryPlan['confirm'] ?? '')], "$tmp/entrysite");
    if (PHP_OS_FAMILY === 'Windows') {
        check_upgrade($entryApply['code'] !== 0 && ($entryApply['json']['rolled_back'] ?? false)
            && upgrade_snapshot("$tmp/entrysite") === $entryBefore && str_contains($entryApply['json']['error'] ?? '', 'Cannot atomically replace')
            && str_contains($entryApply['json']['error'] ?? '', 'tools/upgrade.php'), 'held maintenance.php fails closed, rolls back byte-for-byte and names a safe external entry point');
        $entryPlan = upgrade_run([PHP_BINARY, "$tmp/entrypkg/tools/upgrade.php", "--install=$tmp/entrysite"], $tmp)['json'] ?? [];
        $entryApply = upgrade_run([PHP_BINARY, "$tmp/entrypkg/tools/upgrade.php", "--install=$tmp/entrysite", '--apply', '--confirm=' . ($entryPlan['confirm'] ?? '')], $tmp);
    }
    check_upgrade($entryApply['code'] === 0 && hash_file('sha256', "$tmp/entrysite/maintenance.php") === hash_file('sha256', "$tmp/entrypkg/maintenance.php"), 'an unheld maintenance.php is replaced atomically from the external release entry point (or native rename)');
    $externalRollback = "$tmp/external-rollback.php";
    file_put_contents($externalRollback, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true)
        . '; $r = bbf_upgrade_rollback($argv[1], $argv[2] ?? null); echo json_encode($r); exit($r["ok"] ? 0 : 1);');
    $entryBackup = $entryApply['json']['backup'] ?? '';
    $entryRollbackPlan = upgrade_run([PHP_BINARY, $externalRollback, $entryBackup], $tmp)['json'] ?? [];
    $entryRollback = upgrade_run([PHP_BINARY, $externalRollback, $entryBackup, $entryRollbackPlan['confirm'] ?? ''], $tmp);
    check_upgrade($entryRollback['code'] === 0 && upgrade_snapshot("$tmp/entrysite") === $entryBefore, 'external rollback atomically restores the entry point and the exact original installation');

    // Review 2.1.8: exit status remains authoritative even after a valid result and >20 notices.
    upgrade_copy("$tmp/deleg", "$tmp/crashpkg");
    file_put_contents("$tmp/crashpkg/bbf_upgrade.php", "\n" . 'register_shutdown_function(static function () { for ($i = 0; $i < 30; $i++) fwrite(STDERR, "shutdown notice $i\n"); exit(7); });' . "\n", FILE_APPEND);
    upgrade_remanifest("$tmp/crashpkg", '2.2.0');
    $crash = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/crashpkg"], "$tmp/site");
    check_upgrade($crash['code'] !== 0 && ($crash['json']['ok'] ?? true) === false && ($crash['json']['upgrader_exit_code'] ?? null) === 7
        && count($crash['json']['php_messages'] ?? []) === 20 && str_contains(implode(' ', $crash['json']['php_messages'] ?? []), 'exit code 7'),
        'a shutdown crash after valid JSON survives the 20-message cap and gives a nonzero CLI exit');

    // A reported refusal exits 1 by contract; that is not a crash, with or without an error field.
    upgrade_copy("$tmp/deleg", "$tmp/refusalpkg");
    foreach ([['ok' => false, 'problems' => ['expected refusal']], ['ok' => false, 'error' => 'expected refusal']] as $refusalResult) {
        file_put_contents("$tmp/refusalpkg/bbf_upgrade.php", '<?php function bbf_upgrade(...$args): array { return ' . var_export($refusalResult, true) . '; }');
        upgrade_remanifest("$tmp/refusalpkg", '2.2.0');
        $refusal = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/refusalpkg"], "$tmp/site");
        check_upgrade($refusal['code'] !== 0 && ($refusal['json']['upgrader_exit_code'] ?? null) === 1
            && ($refusal['json']['error'] ?? null) === ($refusalResult['error'] ?? null) && !isset($refusal['json']['php_messages']), 'structured delegated ok:false / exit 1 is preserved without a crash warning');
    }
    foreach (['missing ok' => "return [];", 'nonboolean ok' => "return ['ok' => 'false'];", 'invalid JSON' => 'echo "\\n--BBF-UPGRADE-RESULT--\\n{broken"; exit(1);',
        'no result' => 'fwrite(STDERR, "subprocess exploded"); exit(3);'] as $name => $body) {
        file_put_contents("$tmp/refusalpkg/bbf_upgrade.php", '<?php function bbf_upgrade(...$args): array { ' . $body . ' }');
        upgrade_remanifest("$tmp/refusalpkg", '2.2.0');
        $malformed = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/refusalpkg"], "$tmp/site");
        check_upgrade($malformed['code'] !== 0 && str_contains($malformed['err'], 'Upgrade failed:') && !isset($malformed['json']['next']), "genuinely failed/malformed delegated subprocess is rejected: $name");
    }
    file_put_contents("$tmp/refusalpkg/bbf_upgrade.php", '<?php register_shutdown_function(static function () { exit(7); }); function bbf_upgrade(...$args): array { return ["ok" => false, "error" => "expected refusal"]; }');
    upgrade_remanifest("$tmp/refusalpkg", '2.2.0');
    $refusalCrash = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/refusalpkg"], "$tmp/site");
    check_upgrade($refusalCrash['code'] !== 0 && ($refusalCrash['json']['upgrader_exit_code'] ?? null) === 7
        && str_contains(implode(' ', $refusalCrash['json']['php_messages'] ?? []), 'exit code 7'), 'a real shutdown failure after ok:false still gets a crash warning');

    // Strict form-definition errors are visible even when the generated-data smoke test misses them.
    upgrade_copy("$tmp/site", "$tmp/badcondition");
    file_put_contents("$tmp/badcondition/forms/badcondition.json", json_encode(['id' => 'badcondition', 'fields' => [
        ['name' => 'choice', 'type' => 'text', 'show_if' => ['all' => ['field' => 'other', 'value' => 'yes']]],
    ]]));
    $definitionPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/badcondition")['json'] ?? [];
    check_upgrade(str_contains(implode(' ', $definitionPlan['notices'] ?? []), 'badcondition.json')
        && str_contains(implode(' ', $definitionPlan['notices'] ?? []), 'show_if.all')
        && str_contains(implode(' ', $definitionPlan['notices'] ?? []), 'Fix this definition'), 'upgrade names malformed show_if objects and gives a repair action');

    // The package auth policy invalidates the last admin; installation stays updated but access is explicitly blocked.
    upgrade_copy("$tmp/site", "$tmp/blockedsite");
    file_put_contents("$tmp/blockedsite/config.php", str_replace("'api_token' => 'a9c4e72b608df315e7a2b8c19df0365e',", "'api_token' => 'a9c4e72b608df315e7a2b8c1',", file_get_contents("$tmp/blockedsite/config.php")));
    upgrade_copy("$tmp/new", "$tmp/blockedpkg");
    file_put_contents("$tmp/blockedpkg/bbf_auth.php", str_replace('function bbf_auth_registry(array $config): array {',
        'function bbf_auth_registry(array $config): array { if (strlen($config["api_token"] ?? "") < 32) return [];', file_get_contents("$tmp/blockedpkg/bbf_auth.php")));
    upgrade_remanifest("$tmp/blockedpkg", '2.2.0');
    $blockedBefore = upgrade_snapshot("$tmp/blockedsite");
    $blockedDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/blockedpkg"], "$tmp/blockedsite");
    $blockedPlan = $blockedDry['json'] ?? [];
    check_upgrade($blockedDry['code'] !== 0 && ($blockedPlan['ok'] ?? true) === false && ($blockedPlan['access_blocked'] ?? false)
        && !isset($blockedPlan['next']) && isset($blockedPlan['confirm']) && str_contains(implode(' ', $blockedPlan['problems'] ?? []), '32 hexadecimal')
        && upgrade_snapshot("$tmp/blockedsite") === $blockedBefore, 'admin-lockout dry run fails with an actionable repair, no apply command, a digest and no changes');
    file_put_contents("$tmp/blockedpkg/bbf_upgrade.php", "\n// force package delegation\n", FILE_APPEND);
    upgrade_remanifest("$tmp/blockedpkg", '2.2.0');
    $blockedDry = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/blockedpkg"], "$tmp/blockedsite");
    $blockedPlan = $blockedDry['json'] ?? [];
    check_upgrade($blockedDry['code'] !== 0 && ($blockedPlan['ok'] ?? true) === false && !isset($blockedPlan['next'])
        && ($blockedPlan['upgrader_exit_code'] ?? null) === 1 && !isset($blockedPlan['error']) && !isset($blockedPlan['php_messages']), 'delegated lockout plan fails normally without inventing a subprocess crash');
    $blockedApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/blockedpkg", '--apply', '--confirm=' . ($blockedPlan['confirm'] ?? '')], "$tmp/blockedsite");
    check_upgrade($blockedApply['code'] !== 0 && ($blockedApply['json']['ok'] ?? true) === false && ($blockedApply['json']['code_updated'] ?? false)
        && ($blockedApply['json']['access_blocked'] ?? false) && str_contains($blockedApply['json']['error'] ?? '', '32 hexadecimal')
        && (json_decode(file_get_contents("$tmp/blockedsite/.bbf-manifest.json"), true)['version'] ?? '') === '2.2.0',
        'invalidated last admin returns code_updated/access_blocked, ok:false and nonzero exit without reverting code');
    check_upgrade(($blockedApply['json']['upgrader_exit_code'] ?? null) === 1 && !isset($blockedApply['json']['php_messages'])
        && !str_contains($blockedApply['json']['error'] ?? '', 'exit code'), 'explicit apply of the blocked plan preserves the access error, not a crash warning');
    $blockedBackup = $blockedApply['json']['backup'] ?? '';
    $blockedUndo = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$blockedBackup"], "$tmp/blockedsite")['json'] ?? [];
    $blockedRollback = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade-rollback', "--backup=$blockedBackup", '--apply', '--confirm=' . ($blockedUndo['confirm'] ?? '')], "$tmp/blockedsite");
    check_upgrade($blockedRollback['code'] === 0 && upgrade_snapshot("$tmp/blockedsite") === $blockedBefore, 'the CLI operator can explicitly roll back after an access-blocked apply');
    file_put_contents("$tmp/blockedsite/config.php", str_replace('a9c4e72b608df315e7a2b8c1', 'a9c4e72b608df315e7a2b8c19df0365e', file_get_contents("$tmp/blockedsite/config.php")));
    $repairedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/blockedpkg"], "$tmp/blockedsite");
    check_upgrade($repairedPlan['code'] === 0 && ($repairedPlan['json']['ok'] ?? false) && !($repairedPlan['json']['access_blocked'] ?? true)
        && isset($repairedPlan['json']['next']), 'repairing the configured admin makes the new-code dry run actionable again');

    $workflow = file_get_contents("$repo/.github/workflows/release.yml");
    check_upgrade(str_contains($workflow, '--draft --title') && str_contains($workflow, 'cmp "$RUNNER_TEMP/SHA256SUMS" "$check/SHA256SUMS"')
        && strpos($workflow, 'gh release create') < strpos($workflow, 'gh release download')
        && strpos($workflow, 'sha256sum -c SHA256SUMS)', strpos($workflow, 'gh release download')) < strpos($workflow, 'gh release edit')
        && str_contains($workflow, '--draft=false') && !str_contains($workflow, 'continue-on-error:'),
        'release creates a draft, downloads/checks uploaded artifacts, and only then publishes; verification failure leaves draft');

    // ─── Release history covers every published release (CI gate) ───
    $tagList = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' tag --list "v2.*"'));
    if ($tagList !== '') {
        $historyCheck = upgrade_run([PHP_BINARY, "$repo/tools/release-history.php", '--check'], $repo);
        check_upgrade($historyCheck['code'] === 0, 'tools/release-history.json records every published release: ' . trim($historyCheck['out'] . $historyCheck['err']));
    }

    // ─── Dry run warns when the new code would ignore a token ────────
    upgrade_copy("$tmp/site", "$tmp/tokens");
    file_put_contents("$tmp/tokens/config.php", preg_replace("/'access_tokens' => \[\],/", "'access_tokens' => [['id' => 'short-one', 'token' => 'abc', 'forms' => [], 'permissions' => ['read'], 'revoked' => false, 'expires_at' => '2030-01-01T00:00:00Z']],",
        str_replace("'api_token' => '',", "'api_token' => 'a9c4e72b608df315e7a2b8c19df0365e',", file_get_contents("$tmp/tokens/config.php"))));
    $tokenPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/tokens")['json'] ?? [];
    check_upgrade(str_contains(implode(' ', $tokenPlan['access_warnings'] ?? []), 'short-one') && !str_contains(json_encode($tokenPlan), "'abc'"),
        'the dry run names a token the new version will ignore');
    // Review 2.1.5: an unverified package runs none of its code, but the installed code still reports the token.
    $unverifiedPlan = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', "--package=$tmp/new"], "$tmp/tokens")['json'] ?? [];
    check_upgrade(($unverifiedPlan['check']['status'] ?? '') === 'skipped' && str_contains(implode(' ', $unverifiedPlan['access_warnings'] ?? []), 'short-one')
        && str_starts_with((string)($unverifiedPlan['access_warnings_by'] ?? ''), 'installed version'),
        'a dry run without --checksum still names the ignored token (judged by the installed code): ' . json_encode($unverifiedPlan['access_warnings'] ?? null));
    // Review 2.1.6: the result of --apply names the ignored token again, judged by the code now installed.
    $tokenApply = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new", '--apply', '--confirm=' . ($tokenPlan['confirm'] ?? '')], "$tmp/tokens")['json'] ?? [];
    check_upgrade(($tokenApply['ok'] ?? false) === true && str_contains(implode(' ', $tokenApply['access_warnings'] ?? []), 'short-one'),
        'the --apply result repeats the access warnings: ' . json_encode($tokenApply['access_warnings'] ?? $tokenApply));
    // CLI -d security restrictions and runtime diagnostics must survive every PHP child launch.
    $iniProbe = "$tmp/child-ini-probe.php";
    file_put_contents($iniProbe, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true)
        . '; $r = bbf_upgrade_run([PHP_BINARY, "-r", \'echo json_encode(["memory" => ini_get("memory_limit"), "reporting" => error_reporting(), "disabled" => function_exists("exec"), "basedir" => ini_get("open_basedir")]);\'], $argv[1]); echo json_encode($r);');
    $basedir = "$repo" . PATH_SEPARATOR . $tmp . PATH_SEPARATOR . str_replace('\\', '/', sys_get_temp_dir());
    $iniProbeRun = upgrade_run([PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'error_reporting=0', '-d', 'disable_functions=exec', '-d', 'open_basedir="' . $basedir . '"', $iniProbe, $tmp], $tmp);
    $childIni = json_decode($iniProbeRun['json']['out'] ?? '', true);
    check_upgrade($iniProbeRun['code'] === 0 && ($iniProbeRun['json']['exit'] ?? null) === 0 && ($childIni['memory'] ?? null) === '96M'
        && ($childIni['reporting'] ?? null) === 0 && ($childIni['disabled'] ?? true) === false && ($childIni['basedir'] ?? null) === $basedir,
        'PHP children preserve explicit -d resource, diagnostic and security settings');
    $quietDry = upgrade_run([PHP_BINARY, '-d', 'error_reporting=0', '-d', 'display_errors=1', 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/deleg"], "$tmp/nsite");
    check_upgrade($quietDry['code'] === 0 && !isset($quietDry['json']['php_messages']), '-d error_reporting=0 also survives the actual delegated upgrade path');

    // Review 2.1.9: real CLI boundaries, only disposable fixture installations.
    $noProcBefore = upgrade_snapshot("$tmp/site");
    foreach ([[], ['--apply', '--confirm=' . $plan['confirm']]] as $applyArgs) {
        foreach ([['maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], ["$tmp/new/tools/upgrade.php", "--install=$tmp/site"]] as $entryArgs) {
            $noProc = upgrade_run(array_merge([PHP_BINARY, '-d', 'disable_functions=proc_open'], $entryArgs, $applyArgs), "$tmp/site");
            check_upgrade($noProc['code'] === 4 && ($noProc['json']['ok'] ?? true) === false
                && ($noProc['json']['access_checked'] ?? true) === false && !isset($noProc['json']['next'])
                && str_contains(implode(' ', $noProc['json']['problems'] ?? []), 'Enable proc_open')
                && upgrade_snapshot("$tmp/site") === $noProcBefore, 'disabled proc_open refuses dry run/apply at both entry points without changing files');
        }
    }
    foreach ([[], [['id' => 'invalid', 'token' => 'short', 'forms' => [], 'permissions' => ['read'], 'revoked' => false, 'expires_at' => '2030-01-01T00:00:00Z']]] as $records) {
        upgrade_copy("$tmp/site", "$tmp/no-access");
        if (!defined('BBF_LOADED')) define('BBF_LOADED', true);
        $noAccessConfig = require "$tmp/no-access/config.php";
        $noAccessConfig['api_token'] = ''; $noAccessConfig['access_tokens'] = $records;
        file_put_contents("$tmp/no-access/config.php", '<?php return ' . var_export($noAccessConfig, true) . ';');
        $noAccessBefore = upgrade_snapshot("$tmp/no-access");
        $noAccess = upgrade_run([PHP_BINARY, 'maintenance.php', 'upgrade', '--trust-package', "--package=$tmp/new"], "$tmp/no-access");
        check_upgrade($noAccess['code'] === 4 && ($noAccess['json']['access_checked'] ?? false) && ($noAccess['json']['access_blocked'] ?? false)
            && !isset($noAccess['json']['next']) && upgrade_snapshot("$tmp/no-access") === $noAccessBefore, 'empty or all-invalid access credentials refuse preflight without changes');
        upgrade_rmtree("$tmp/no-access");
    }
    $defaultReporting = upgrade_run([PHP_BINARY, '-n', $iniProbe, $tmp], $tmp);
    $defaultChild = json_decode($defaultReporting['json']['out'] ?? '', true);
    $defaultMask = upgrade_run([PHP_BINARY, '-n', '-r', 'echo json_encode(error_reporting());'], $tmp)['json'];
    check_upgrade(($defaultReporting['json']['exit'] ?? null) === 0 && ($defaultChild['reporting'] ?? null) === $defaultMask
        && $defaultMask > 0, 'php -n empty error_reporting INI retains the actual nonzero child mask');
    mkdir("$tmp/private-temp", 0700);
    $tempProbe = "$tmp/temp-probe.php";
    file_put_contents($tempProbe, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true)
        . '; echo json_encode(bbf_upgrade_run([PHP_BINARY, "-r", \'$f = tempnam(sys_get_temp_dir(), "probe"); echo json_encode(["ini" => ini_get("sys_temp_dir"), "temp" => sys_get_temp_dir(), "file" => $f]); unlink($f);\'], $argv[1]));');
    $tempRun = upgrade_run([PHP_BINARY, '-n', '-d', 'sys_temp_dir=' . "$tmp/private-temp", '-d', 'open_basedir="' . $repo . PATH_SEPARATOR . $tmp . '"', $tempProbe, $tmp], $tmp);
    $tempChild = json_decode($tempRun['json']['out'] ?? '', true);
    check_upgrade(($tempRun['json']['exit'] ?? null) === 0 && ($tempChild['ini'] ?? null) === "$tmp/private-temp"
        && str_starts_with(str_replace('\\', '/', $tempChild['file'] ?? ''), "$tmp/private-temp/"), 'restricted open_basedir child uses propagated writable sys_temp_dir');
    $uncProbe = "$tmp/unc-probe.php";
    file_put_contents($uncProbe, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true)
        . '; ini_set("open_basedir", $argv[2]); echo json_encode(bbf_upgrade_run([PHP_BINARY, "-r", \'echo json_encode(ini_get("open_basedir"));\'], $argv[1]));');
    $unc = '\\\\srv\\share'; // INI semantics only: never connects to this share.
    $uncBasedir = $tmp . PATH_SEPARATOR . $unc;
    $uncRun = upgrade_run([PHP_BINARY, '-n', '-d', 'sys_temp_dir=' . $tmp, $uncProbe, $tmp, $uncBasedir], $tmp);
    check_upgrade(($uncRun['json']['exit'] ?? null) === 0 && json_decode($uncRun['json']['out'] ?? '', true) === $uncBasedir,
        'child INI propagation preserves the UNC double-backslash prefix exactly');
    check_upgrade($wrong['code'] === 3 && $older['code'] === 3 && $syntax['code'] === 3 && $smoke['code'] === 3,
        'CLI exit 3 identifies rejected confirmation, version, syntax and smoke preflight');
    check_upgrade($injected['code'] === 5 && $retryFail['code'] === 5, 'CLI exit 5 identifies failed apply and failed rollback');
    check_upgrade($damaged['code'] === 1 && $crash['code'] === 1 && $malformed['code'] === 1, 'CLI exit 1 identifies damaged package and subprocess runtime failures');
    check_upgrade($blockedDry['code'] === 4 && $blockedApply['code'] === 4, 'CLI exit 4 identifies known admin lockout before and after apply');
    $externalWrong = upgrade_run([PHP_BINARY, "$tmp/new/tools/upgrade.php", "--install=$tmp/site", '--apply', '--confirm=' . str_repeat('0', 64)], $tmp);
    check_upgrade($externalWrong['code'] === 3, 'external upgrader shares confirmation-refusal exit 3');
    $externalUsage = upgrade_run([PHP_BINARY, "$tmp/new/tools/upgrade.php"], "$tmp/site");
    check_upgrade($externalUsage['code'] === 2, 'external upgrader requires explicit install even when cwd contains config.php');

    // A token check that cannot run (config.php dies) says so instead of reporting "no problems".
    upgrade_copy("$tmp/site", "$tmp/deadcfg");
    file_put_contents("$tmp/deadcfg/config.php", "<?php\nfwrite(STDERR, 'config exploded'); exit(3);\n");
    $unitScript = "$tmp/upgrade-unit.php";
    file_put_contents($unitScript, '<?php define("BBF_LOADED", true); require ' . var_export("$repo/bbf_upgrade.php", true) . ';'
        . ' $long = "## [9.9.9]\n### Breaking\n- " . str_repeat("Filler sentence here. ", 40) . "Set a random token.\n";'
        . ' echo json_encode(["dead" => bbf_upgrade_access_warnings($argv[1], $argv[2]), "note" => bbf_upgrade_breaking($long, "9.9.8", "9.9.9")[0] ?? ""]);');
    $unit = upgrade_run([PHP_BINARY, $unitScript, $repo, "$tmp/deadcfg"], $tmp)['json'] ?? [];
    $dead = $unit['dead'] ?? [];
    check_upgrade(count($dead) >= 1 && str_contains($dead[0], 'exit code 3') && str_contains($dead[0], 'config exploded'),
        'a crashed token check is reported with its exit code: ' . json_encode($dead));
    // A long Breaking note ends at a full sentence, not in the middle of the instruction.
    $note = (string)($unit['note'] ?? '');
    check_upgrade(str_ends_with($note, 'here. ...') && strlen($note) < 720, 'a long Breaking note is cut at a sentence end: ' . substr($note, -40));

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
    check_upgrade($notPackage['code'] !== 0 && str_contains($notPackage['err'], 'Not a BareBonesForms release package'), 'a folder without a manifest is refused');
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
