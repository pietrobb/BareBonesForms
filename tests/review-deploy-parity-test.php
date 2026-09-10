<?php
/** Behavioral regression coverage for deterministic deployment packaging and parity. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = realpath(dirname(__DIR__));
$packager = $root === false ? false : $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'package-deploy.php';
$checks = 0;
$failures = [];
$skips = [];

function deploy_test_check(bool $condition, string $label): void
{
    global $checks, $failures;
    ++$checks;
    if (!$condition) {
        $failures[] = $label;
        fwrite(STDERR, "FAIL $label\n");
    }
}

/** @return array{code: int|null, stdout: string, stderr: string} */
function deploy_test_process(array $command, string $cwd, array $environmentOverrides = []): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($environmentOverrides as $name => $value) {
        $environment[$name] = $value;
    }
    $process = proc_open($command, $descriptors, $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return ['code' => null, 'stdout' => '', 'stderr' => 'cannot start child process'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return list<string> */
function deploy_test_files(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $info) {
        if (!$info->isFile() || $info->isLink()) {
            continue;
        }
        $relative = substr($info->getPathname(), strlen(rtrim($directory, '/\\')) + 1);
        $files[] = str_replace('\\', '/', $relative);
    }
    sort($files, SORT_STRING);
    return $files;
}

/** @return array<string, array{hash: string|false, size: int|false, mtime: int|false}> */
function deploy_test_snapshot(string $directory): array
{
    clearstatcache(true);
    $snapshot = [];
    foreach (deploy_test_files($directory) as $relative) {
        $path = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $snapshot[$relative] = [
            'hash' => hash_file('sha256', $path),
            'size' => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    return $snapshot;
}

function deploy_test_remove_tree(string $directory): void
{
    if (is_link($directory)) {
        @unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $info) {
        $path = $info->getPathname();
        if ($info->isLink()) {
            @unlink($path);
        } elseif ($info->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

/** @return list<string> */
function deploy_test_expected_files(): array
{
    $files = [
        '.bbf-package', '.gitignore', '.htaccess', 'LICENSE', 'README.md', 'actions/README.md',
        'api-psc.php', 'bbf-theme.css', 'bbf.css', 'bbf.js', 'bbf_auth.php', 'bbf_backup.php',
        'bbf_delivery.php', 'bbf_diagnostics.php', 'bbf_drafts.php', 'bbf_export.php', 'bbf_functions.php',
        'bbf_outbox.php', 'bbf_read.php', 'bbf_review.php', 'bbf_retention.php', 'bbf_storage.php', 'bbf_versions.php', 'check.php', 'config.example.php',
        'data/city-to-psc.json', 'data/psc-to-city.json', 'demo.css', 'demo.html',
        'demo1.html', 'demo2.html', 'demo3.html', 'demo4.html', 'demo5.html', 'demo6.html',
        'demo7.html', 'demo8.html', 'demo9.html', 'docs.html', 'editor.php', 'index.html',
        'logs/.gitkeep', 'maintenance.php', 'payment.php', 'sandbox.php', 'smoketest.php', 'submissions.php',
        'submissions/.gitkeep', 'submit.php', 'viewer.php',
    ];
    foreach ([
        'demo-advanced.json', 'demo-allergy.json', 'demo-csv.json', 'demo-file.json',
        'demo-modalities.json', 'demo-order.json', 'demo-psc.json', 'demo-quiz.json',
        'demo-webhook.json', 'form.schema.json', 'kontakt.json', 'newsletter.json',
    ] as $form) {
        $files[] = 'forms/' . $form;
    }
    foreach ([
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hi',
        'hu', 'id', 'it', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt', 'pt-br', 'ro', 'ru',
        'sk', 'sv', 'th', 'tlh', 'tr', 'uk', 'zh', 'zh-tw',
    ] as $locale) {
        $files[] = "lang/$locale.js";
        $files[] = "lang/$locale.php";
    }
    foreach (['confirm-order.html', 'confirm.html', 'notify-order.html', 'notify.html'] as $template) {
        $files[] = 'templates/' . $template;
    }
    sort($files, SORT_STRING);
    return $files;
}

if ($root === false || !is_file($packager) || is_link($packager)) {
    fwrite(STDERR, "FAIL deployment packager is absent or unsafe\n");
    exit(1);
}

$temp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'bbf-deploy-parity-' . bin2hex(random_bytes(8));
$destination = $temp . DIRECTORY_SEPARATOR . 'package';
$outside = $temp . DIRECTORY_SEPARATOR . 'outside';
if (!mkdir($temp, 0700) || !mkdir($outside, 0700)) {
    fwrite(STDERR, "FAIL cannot create disposable deployment fixture\n");
    exit(1);
}

try {
    $maintainedCheck = deploy_test_process([PHP_BINARY, $packager, '--check'], $root);
    deploy_test_check(
        $maintainedCheck['code'] === 0 && str_contains($maintainedCheck['stdout'], 'sorted path and SHA-256'),
        'maintained deploy/barebonesforms snapshot has exact source parity'
    );

    $build = deploy_test_process([PHP_BINARY, $packager, '--destination', $destination], $root);
    deploy_test_check($build['code'] === 0, 'packager builds a disposable destination');
    deploy_test_check(is_dir($destination) && !is_link($destination), 'build creates a real package directory');
    if (!is_dir($destination)) {
        throw new RuntimeException('Disposable package was not created: ' . $build['stderr']);
    }

    $expected = deploy_test_expected_files();
    $actual = deploy_test_files($destination);
    deploy_test_check($actual === $expected, 'package has the exact independently specified sorted file set');
    deploy_test_check(count($actual) === 130, 'package manifest contains exactly 130 files');

    foreach ([
        'bbf_auth.php', 'bbf_backup.php', 'bbf_delivery.php', 'bbf_diagnostics.php', 'bbf_drafts.php', 'bbf_export.php',
        'bbf_outbox.php', 'bbf_read.php', 'bbf_review.php', 'bbf_retention.php', 'bbf_storage.php', 'bbf_versions.php', 'maintenance.php', 'api-psc.php', 'demo9.html',
        'forms/demo-psc.json', 'data/city-to-psc.json', 'data/psc-to-city.json',
    ] as $required) {
        deploy_test_check(is_file($destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required)), "required runtime dependency is packaged: $required");
    }

    foreach ([
        'config.php', 'tests/review-ci-test.php', 'tools/package-deploy.php', '.env',
        'secrets/credentials.json', 'logs/runtime.log', 'submissions/kontakt/record.json',
        'forms/case-akut.json', 'forms/case-akut.map.json', 'forms/case-hrdlo.json',
        'forms/case-hrdlo.map.json', 'forms/case-nos.json', 'forms/case-nos.map.json',
        'preview-case-akut.html', 'preview-case-hrdlo.html', 'preview-case-nos.html',
        'docs/ESHOP-DESIGN.md', 'skills/example.md', 'models.yaml', 'ClaudeSupervisor_314.exe',
        'x.selected', 'data/psc-obci-sr-a-cr.csv', 'data/build-psc-index.php',
        'actions/test-echo-response.php',
    ] as $forbidden) {
        deploy_test_check(!file_exists($destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $forbidden)), "forbidden path is excluded: $forbidden");
    }

    deploy_test_check(deploy_test_files($destination . DIRECTORY_SEPARATOR . 'logs') === ['.gitkeep'], 'logs contains only an empty .gitkeep');
    deploy_test_check(deploy_test_files($destination . DIRECTORY_SEPARATOR . 'submissions') === ['.gitkeep'], 'submissions contains only an empty .gitkeep');
    deploy_test_check(filesize($destination . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '.gitkeep') === 0, 'logs .gitkeep is empty');
    deploy_test_check(filesize($destination . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . '.gitkeep') === 0, 'submissions .gitkeep is empty');
    deploy_test_check(
        file_get_contents($destination . DIRECTORY_SEPARATOR . '.gitignore') === "# BareBonesForms deployment-local files\n/config.php\n/logs/*\n!/logs/.gitkeep\n/submissions/*\n!/submissions/.gitkeep\n*.log\n.DS_Store\nThumbs.db\n",
        'package-specific .gitignore is generated deterministically'
    );
    deploy_test_check(
        file_get_contents($destination . DIRECTORY_SEPARATOR . '.bbf-package') === "BareBonesForms generated deployment package. Do not use this directory for live data.\n",
        'package ownership marker is generated deterministically'
    );

    $beforeCheck = deploy_test_snapshot($destination);
    $parity = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination=' . $destination], $root);
    $afterCheck = deploy_test_snapshot($destination);
    deploy_test_check($parity['code'] === 0 && str_contains($parity['stdout'], 'sorted path and SHA-256'), '--check accepts exact byte parity');
    deploy_test_check($afterCheck === $beforeCheck, '--check does not mutate package files or metadata');
    $siblingsAfterCheck = array_values(array_diff(scandir($temp) ?: [], ['.', '..', 'outside', 'package']));
    deploy_test_check($siblingsAfterCheck === [], '--check creates no staging or backup paths');

    $tampered = $destination . DIRECTORY_SEPARATOR . 'bbf.js';
    $original = file_get_contents($tampered);
    file_put_contents($tampered, $original . "\n/* parity tamper */\n");
    $tamperCheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $destination], $root);
    deploy_test_check($tamperCheck['code'] !== 0 && str_contains($tamperCheck['stderr'], 'changed: bbf.js'), 'byte tampering makes --check nonzero');
    file_put_contents($tampered, $original);

    $forbiddenConfig = $destination . DIRECTORY_SEPARATOR . 'config.php';
    $secretConfig = '<?php return ["secret" => true];';
    file_put_contents($forbiddenConfig, $secretConfig);
    $unexpectedCheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $destination], $root);
    deploy_test_check($unexpectedCheck['code'] !== 0 && str_contains($unexpectedCheck['stderr'], 'unexpected: config.php'), 'a forbidden extra file makes --check nonzero');
    $protectedSnapshot = deploy_test_snapshot($destination);
    $refusedReplace = deploy_test_process([PHP_BINARY, $packager, '--destination', $destination], $root);
    deploy_test_check(
        $refusedReplace['code'] !== 0 && str_contains($refusedReplace['stderr'], 'unexpected files'),
        'replacement refuses a package containing deployment-local files'
    );
    deploy_test_check(
        file_get_contents($forbiddenConfig) === $secretConfig && deploy_test_snapshot($destination) === $protectedSnapshot,
        'refused replacement preserves config and every package byte'
    );
    unlink($forbiddenConfig);

    file_put_contents($tampered, $original . "\n/* managed replacement */\n");
    $replace = deploy_test_process([PHP_BINARY, $packager, '--destination', $destination], $root);
    deploy_test_check($replace['code'] === 0, 'staged replacement repairs a marked package without local files');
    $recheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $destination], $root);
    deploy_test_check($recheck['code'] === 0, 'replaced package returns to exact parity');

    $beforeActivationFailure = deploy_test_snapshot($destination);
    $activationFailure = deploy_test_process(
        [PHP_BINARY, $packager, '--destination', $destination],
        $root,
        ['BBF_DEPLOY_TEST_FAIL_ACTIVATION' => '1']
    );
    deploy_test_check(
        $activationFailure['code'] !== 0 && str_contains($activationFailure['stderr'], 'Injected deployment activation failure'),
        'injected activation failure is reported as nonzero'
    );
    deploy_test_check(
        deploy_test_snapshot($destination) === $beforeActivationFailure,
        'activation failure rolls the original package back byte-for-byte'
    );
    $siblingsAfterRollback = array_values(array_diff(scandir($temp) ?: [], ['.', '..', 'outside', 'package']));
    deploy_test_check($siblingsAfterRollback === [], 'activation rollback removes staging and backup paths');

    $insideRepository = $root . DIRECTORY_SEPARATOR . 'forms';
    $outsideRootCheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $insideRepository], $root);
    deploy_test_check(
        $outsideRootCheck['code'] !== 0 && str_contains($outsideRootCheck['stderr'], 'repository-local destination'),
        'destination safety rejects repository paths outside deploy/barebonesforms'
    );

    file_put_contents($outside . DIRECTORY_SEPARATOR . 'sentinel.txt', 'outside must remain untouched');
    $createDirectoryLink = static function (string $target, string $link) use ($temp): bool {
        if (function_exists('symlink') && @symlink($target, $link)) {
            return true;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }
        if (str_contains($link, '"') || str_contains($target, '"')) {
            return false;
        }
        $command = 'mklink /J "' . $link . '" "' . $target . '"';
        $junction = deploy_test_process(['cmd.exe', '/D', '/S', '/C', $command], $temp);
        return $junction['code'] === 0 && is_dir($link);
    };
    $removeDirectoryLink = static function (string $link): void {
        is_link($link) ? @unlink($link) : @rmdir($link);
    };

    $link = $destination . DIRECTORY_SEPARATOR . 'outside-link';
    $linkCreated = $createDirectoryLink($outside, $link);
    if ($linkCreated) {
        $linkCheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $destination], $root);
        deploy_test_check($linkCheck['code'] !== 0 && str_contains(strtolower($linkCheck['stderr']), 'link'), 'parity rejects a link or junction inside the package');
        deploy_test_check(file_get_contents($outside . DIRECTORY_SEPARATOR . 'sentinel.txt') === 'outside must remain untouched', 'link rejection does not touch its outside target');
        $removeDirectoryLink($link);
    } else {
        $skips[] = 'link/junction package rejection (platform unavailable)';
    }

    $destinationLink = $temp . DIRECTORY_SEPARATOR . 'destination-link';
    $destinationLinkCreated = $createDirectoryLink($outside, $destinationLink);
    if ($destinationLinkCreated) {
        $destinationLinkCheck = deploy_test_process([PHP_BINARY, $packager, '--check', '--destination', $destinationLink], $root);
        deploy_test_check($destinationLinkCheck['code'] !== 0, 'destination safety rejects a link or junction destination');
        deploy_test_check(file_get_contents($outside . DIRECTORY_SEPARATOR . 'sentinel.txt') === 'outside must remain untouched', 'destination-link rejection does not touch its outside target');
        $removeDirectoryLink($destinationLink);
    } else {
        $skips[] = 'link/junction destination rejection (platform unavailable)';
    }
} catch (Throwable $error) {
    $failures[] = 'test harness exception: ' . $error->getMessage();
    fwrite(STDERR, 'FAIL test harness exception: ' . $error->getMessage() . "\n");
} finally {
    deploy_test_remove_tree($temp);
}

foreach ($skips as $skip) {
    print "SKIP $skip\n";
}
if ($failures !== []) {
    fwrite(STDERR, 'Deployment parity regression: ' . count($failures) . " failure(s), $checks checks.\n");
    exit(1);
}
print "Deployment parity regression: $checks checks passed, " . count($skips) . " skipped.\n";
