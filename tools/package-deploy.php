<?php
/**
 * Build or inspect the deterministic BareBonesForms deployment package.
 *
 * Usage:
 *   php tools/package-deploy.php
 *   php tools/package-deploy.php --check
 *   php tools/package-deploy.php [--check] --destination <directory>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

const BBF_DEPLOY_GITIGNORE = <<<'IGNORE'
# BareBonesForms deployment-local files
/config.php
/logs/*
!/logs/.gitkeep
/submissions/*
!/submissions/.gitkeep
*.log
.DS_Store
Thumbs.db
IGNORE;
const BBF_DEPLOY_MARKER = "BareBonesForms generated deployment package. Do not use this directory for live data.\n";

/** @return array<string, array{source?: string, content?: string}> */
function bbf_deploy_manifest(string $root): array
{
    // This is deliberately a closed list. Never replace it with a recursive root copy.
    $topLevel = [
        '.htaccess',
        'LICENSE',
        'README.md',
        'api-psc.php',
        'bbf-theme.css',
        'bbf.css',
        'bbf.js',
        'bbf_auth.php',
        'bbf_backup.php',
        'bbf_delivery.php',
        'bbf_diagnostics.php',
        'bbf_drafts.php',
        'bbf_export.php',
        'bbf_functions.php',
        'bbf_outbox.php',
        'bbf_read.php',
        'bbf_review.php',
        'bbf_retention.php',
        'bbf_storage.php',
        'bbf_versions.php',
        'check.php',
        'config.example.php',
        'demo.css',
        'demo.html',
        'demo1.html',
        'demo2.html',
        'demo3.html',
        'demo4.html',
        'demo5.html',
        'demo6.html',
        'demo7.html',
        'demo8.html',
        'demo9.html',
        'docs.html',
        'editor.php',
        'index.html',
        'maintenance.php',
        'payment.php',
        'sandbox.php',
        'smoketest.php',
        'submissions.php',
        'submit.php',
        'viewer.php',
    ];
    $stockForms = [
        'demo-advanced.json',
        'demo-allergy.json',
        'demo-csv.json',
        'demo-file.json',
        'demo-modalities.json',
        'demo-order.json',
        'demo-psc.json',
        'demo-quiz.json',
        'demo-webhook.json',
        'form.schema.json',
        'kontakt.json',
        'newsletter.json',
    ];
    $locales = [
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hi',
        'hu', 'id', 'it', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt', 'pt-br', 'ro', 'ru',
        'sk', 'sv', 'th', 'tlh', 'tr', 'uk', 'zh', 'zh-tw',
    ];
    $templates = ['confirm-order.html', 'confirm.html', 'notify-order.html', 'notify.html'];

    $manifest = [];
    foreach ($topLevel as $path) {
        $manifest[$path] = ['source' => $root . DIRECTORY_SEPARATOR . $path];
    }
    foreach ($stockForms as $name) {
        $path = 'forms/' . $name;
        $manifest[$path] = ['source' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)];
    }
    foreach ($locales as $locale) {
        foreach (['js', 'php'] as $extension) {
            $path = "lang/$locale.$extension";
            $manifest[$path] = ['source' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)];
        }
    }
    foreach ($templates as $name) {
        $path = 'templates/' . $name;
        $manifest[$path] = ['source' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)];
    }

    $manifest['.bbf-package'] = ['content' => BBF_DEPLOY_MARKER];
    $manifest['actions/README.md'] = ['source' => $root . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'README.md'];
    $manifest['data/city-to-psc.json'] = ['source' => $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'city-to-psc.json'];
    $manifest['data/psc-to-city.json'] = ['source' => $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'psc-to-city.json'];
    $manifest['.gitignore'] = ['content' => BBF_DEPLOY_GITIGNORE . "\n"];
    $manifest['logs/.gitkeep'] = ['content' => ''];
    $manifest['submissions/.gitkeep'] = ['content' => ''];

    ksort($manifest, SORT_STRING);
    return $manifest;
}

function bbf_deploy_normalize(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    return rtrim($normalized, '/');
}

function bbf_deploy_same_path(string $left, string $right): bool
{
    $left = bbf_deploy_normalize($left);
    $right = bbf_deploy_normalize($right);
    return DIRECTORY_SEPARATOR === '\\'
        ? strcasecmp($left, $right) === 0
        : $left === $right;
}

/** Detect symbolic links and Windows junctions/reparse paths that resolve elsewhere. */
function bbf_deploy_is_linklike(string $path): bool
{
    if (is_link($path)) {
        return true;
    }
    $resolved = realpath($path);
    return $resolved !== false && !bbf_deploy_same_path($path, $resolved);
}

function bbf_deploy_is_within(string $path, string $root): bool
{
    $path = bbf_deploy_normalize($path);
    $root = bbf_deploy_normalize($root);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function bbf_deploy_assert_relative(string $path): void
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
        || str_starts_with($path, '/') || preg_match('/(?:^|\/)\.\.?(?:\/|$)/', $path)) {
        throw new RuntimeException("Unsafe manifest path: $path");
    }
}

/** Reject links in every existing component so neither reads nor removals can escape. */
function bbf_deploy_assert_no_link_components(string $path): void
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (DIRECTORY_SEPARATOR === '\\') {
        if (str_starts_with($path, '\\\\')) {
            throw new RuntimeException("UNC paths are not supported: $path");
        }
        if (!preg_match('/^[A-Za-z]:\\\\/', $path)) {
            throw new RuntimeException("Path must be absolute: $path");
        }
        $current = substr($path, 0, 3);
        $parts = preg_split('/[\\\\\/]+/', substr($path, 3), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } else {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException("Path must be absolute: $path");
        }
        $current = DIRECTORY_SEPARATOR;
        $parts = preg_split('/[\\\\\/]+/', substr($path, 1), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    foreach ($parts as $part) {
        $current = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $part;
        if (bbf_deploy_is_linklike($current)) {
            throw new RuntimeException("Links and junctions are not allowed in package paths: $current");
        }
        if (!file_exists($current)) {
            break;
        }
    }
}

/** @param array<string, array{source?: string, content?: string}> $manifest */
function bbf_deploy_validate_manifest(array $manifest, string $root): void
{
    $requiredDependencies = [
        'api-psc.php' => ['data/city-to-psc.json', 'data/psc-to-city.json'],
        'bbf_functions.php' => ['bbf_delivery.php', 'bbf_outbox.php', 'bbf_storage.php'],
        'check.php' => ['bbf_auth.php', 'bbf_diagnostics.php'],
        'submissions.php' => ['bbf_auth.php', 'bbf_export.php', 'bbf_outbox.php', 'bbf_read.php'],
        'submit.php' => ['bbf_auth.php', 'bbf_functions.php'],
        'viewer.php' => ['bbf_auth.php', 'bbf_export.php', 'bbf_functions.php', 'bbf_read.php', 'bbf_review.php'],
    ];

    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        throw new RuntimeException("Source root is not a directory: $root");
    }
    bbf_deploy_assert_no_link_components($realRoot);

    foreach ($manifest as $destination => $entry) {
        bbf_deploy_assert_relative($destination);
        if ((isset($entry['source']) ? 1 : 0) + (array_key_exists('content', $entry) ? 1 : 0) !== 1) {
            throw new RuntimeException("Manifest entry must have exactly one byte source: $destination");
        }
        if (!isset($entry['source'])) {
            continue;
        }

        $source = $entry['source'];
        bbf_deploy_assert_no_link_components($source);
        $realSource = realpath($source);
        if ($realSource === false || !is_file($realSource) || bbf_deploy_is_linklike($source)) {
            throw new RuntimeException("Manifest source is not a regular file: $source");
        }
        if (!bbf_deploy_is_within($realSource, $realRoot)) {
            throw new RuntimeException("Manifest source escapes the repository root: $source");
        }
    }

    foreach ($requiredDependencies as $owner => $dependencies) {
        if (!isset($manifest[$owner])) {
            continue;
        }
        foreach ($dependencies as $dependency) {
            if (!isset($manifest[$dependency])) {
                throw new RuntimeException("Manifest dependency missing for $owner: $dependency");
            }
        }
    }
}

/** @return array{destination: string, check: bool} */
function bbf_deploy_arguments(array $arguments, string $defaultDestination): array
{
    $check = false;
    $destination = null;
    for ($index = 0; $index < count($arguments); ++$index) {
        $argument = $arguments[$index];
        if ($argument === '--check') {
            if ($check) {
                throw new InvalidArgumentException('Duplicate --check option.');
            }
            $check = true;
            continue;
        }
        if ($argument === '--destination') {
            if ($destination !== null || !isset($arguments[$index + 1])) {
                throw new InvalidArgumentException('--destination requires exactly one path.');
            }
            $destination = $arguments[++$index];
            continue;
        }
        if (str_starts_with($argument, '--destination=')) {
            if ($destination !== null) {
                throw new InvalidArgumentException('Duplicate --destination option.');
            }
            $destination = substr($argument, strlen('--destination='));
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            print "Usage: php tools/package-deploy.php [--check] [--destination <directory>]\n";
            exit(0);
        }
        throw new InvalidArgumentException("Unknown argument: $argument");
    }

    if ($destination === '') {
        throw new InvalidArgumentException('Destination must not be empty.');
    }
    return ['destination' => $destination ?? $defaultDestination, 'check' => $check];
}

function bbf_deploy_absolute_destination(string $destination, string $root, string $defaultDestination): string
{
    if (str_contains($destination, "\0")) {
        throw new RuntimeException('Destination contains a null byte.');
    }
    $isAbsolute = DIRECTORY_SEPARATOR === '\\'
        ? (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $destination)
        : str_starts_with($destination, '/');
    if (!$isAbsolute) {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new RuntimeException('Cannot resolve the current working directory.');
        }
        $destination = $cwd . DIRECTORY_SEPARATOR . $destination;
    }

    $destination = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destination), '/\\');
    $leaf = basename($destination);
    $parent = dirname($destination);
    if ($leaf === '' || $leaf === '.' || $leaf === '..') {
        throw new RuntimeException("Unsafe destination: $destination");
    }
    bbf_deploy_assert_no_link_components($parent);
    $realParent = realpath($parent);
    if ($realParent === false || !is_dir($realParent)) {
        throw new RuntimeException("Destination parent must already exist: $parent");
    }
    $resolved = $realParent . DIRECTORY_SEPARATOR . $leaf;
    bbf_deploy_assert_no_link_components($resolved);

    $realRoot = realpath($root);
    $realDefaultParent = realpath(dirname($defaultDestination));
    if ($realRoot === false || $realDefaultParent === false) {
        throw new RuntimeException('Cannot resolve repository deployment paths.');
    }
    $resolvedDefault = $realDefaultParent . DIRECTORY_SEPARATOR . basename($defaultDestination);
    if (bbf_deploy_is_within($resolved, $realRoot) && !bbf_deploy_same_path($resolved, $resolvedDefault)) {
        throw new RuntimeException('A repository-local destination is allowed only at deploy/barebonesforms.');
    }
    if (file_exists($resolved) || is_link($resolved)) {
        if (bbf_deploy_is_linklike($resolved) || !is_dir($resolved)) {
            throw new RuntimeException("Destination must be a real directory or absent: $resolved");
        }
        $realResolved = realpath($resolved);
        if ($realResolved === false || !bbf_deploy_same_path($realResolved, $resolved)) {
            throw new RuntimeException("Destination resolves through an unexpected path: $resolved");
        }
    }
    return $resolved;
}

/** @return list<string> */
function bbf_deploy_files(string $directory): array
{
    if (bbf_deploy_is_linklike($directory) || !is_dir($directory)) {
        throw new RuntimeException("Package destination is not a real directory: $directory");
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $info) {
        $path = $info->getPathname();
        if ($info->isLink() || bbf_deploy_is_linklike($path)) {
            throw new RuntimeException("Package contains a link or junction: $path");
        }
        if ($info->isDir()) {
            continue;
        }
        if (!$info->isFile()) {
            throw new RuntimeException("Package contains a non-regular entry: $path");
        }
        $relative = substr($path, strlen(rtrim($directory, '/\\')) + 1);
        $files[] = str_replace('\\', '/', $relative);
    }
    sort($files, SORT_STRING);
    return $files;
}

/** @param array<string, array{source?: string, content?: string}> $manifest
 *  @return list<string>
 */
function bbf_deploy_compare(string $destination, array $manifest): array
{
    if (!is_dir($destination) || bbf_deploy_is_linklike($destination)) {
        return ["destination is missing or unsafe: $destination"];
    }

    $expected = array_keys($manifest);
    sort($expected, SORT_STRING);
    $actual = bbf_deploy_files($destination);
    $errors = [];
    foreach (array_diff($expected, $actual) as $path) {
        $errors[] = "missing: $path";
    }
    foreach (array_diff($actual, $expected) as $path) {
        $errors[] = "unexpected: $path";
    }
    foreach (array_intersect($expected, $actual) as $path) {
        $entry = $manifest[$path];
        $expectedHash = isset($entry['source'])
            ? hash_file('sha256', $entry['source'])
            : hash('sha256', $entry['content']);
        $actualHash = hash_file('sha256', $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($expectedHash === false || $actualHash === false || !hash_equals($expectedHash, $actualHash)) {
            $errors[] = "changed: $path";
        }
    }
    sort($errors, SORT_STRING);
    return $errors;
}

/** Delete only an internally-created or link-free tree. */
function bbf_deploy_remove_tree(string $directory): void
{
    if (!file_exists($directory) && !is_link($directory)) {
        return;
    }
    if (bbf_deploy_is_linklike($directory) || !is_dir($directory)) {
        throw new RuntimeException("Refusing to recursively remove an unsafe path: $directory");
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $info) {
        $path = $info->getPathname();
        if ($info->isLink() || bbf_deploy_is_linklike($path)) {
            throw new RuntimeException("Refusing to remove a tree containing a link or junction: $path");
        }
        $ok = $info->isDir() ? rmdir($path) : unlink($path);
        if (!$ok) {
            throw new RuntimeException("Cannot remove package path: $path");
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException("Cannot remove package directory: $directory");
    }
}

/** @param array<string, array{source?: string, content?: string}> $manifest */
function bbf_deploy_assert_managed_destination(string $destination, array $manifest): void
{
    if (!is_dir($destination)) {
        return;
    }

    $actual = bbf_deploy_files($destination);
    $expected = array_keys($manifest);
    sort($expected, SORT_STRING);
    $markerPath = $destination . DIRECTORY_SEPARATOR . '.bbf-package';
    if (is_file($markerPath) && file_get_contents($markerPath) === BBF_DEPLOY_MARKER) {
        $unexpected = array_values(array_diff($actual, $expected));
        if ($unexpected === []) {
            return;
        }
        throw new RuntimeException('Refusing to replace a managed package with unexpected files: ' . implode(', ', $unexpected));
    }

    $legacyExpected = array_values(array_diff($expected, ['.bbf-package']));
    if ($actual === $legacyExpected) {
        return;
    }
    throw new RuntimeException('Refusing to replace an unmarked or modified destination. Build to an absent directory instead.');
}

/** @param array<string, array{source?: string, content?: string}> $manifest */
function bbf_deploy_build(string $destination, array $manifest): void
{
    bbf_deploy_assert_managed_destination($destination, $manifest);
    $parent = dirname($destination);
    $suffix = bin2hex(random_bytes(8));
    $stage = $parent . DIRECTORY_SEPARATOR . '.barebonesforms-stage-' . $suffix;
    $backup = $parent . DIRECTORY_SEPARATOR . '.barebonesforms-backup-' . $suffix;
    $destinationMoved = false;
    if (file_exists($stage) || is_link($stage) || file_exists($backup) || is_link($backup)) {
        throw new RuntimeException('Generated staging path unexpectedly exists.');
    }
    if (!mkdir($stage, 0700)) {
        throw new RuntimeException("Cannot create staging directory: $stage");
    }

    try {
        foreach ($manifest as $relative => $entry) {
            $target = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetParent = dirname($target);
            if (!is_dir($targetParent) && !mkdir($targetParent, 0775, true) && !is_dir($targetParent)) {
                throw new RuntimeException("Cannot create staging directory: $targetParent");
            }
            $ok = isset($entry['source'])
                ? copy($entry['source'], $target)
                : file_put_contents($target, $entry['content'], LOCK_EX) !== false;
            if (!$ok) {
                throw new RuntimeException("Cannot stage package file: $relative");
            }
        }

        $stageErrors = bbf_deploy_compare($stage, $manifest);
        if ($stageErrors !== []) {
            throw new RuntimeException("Staged package failed parity:\n  " . implode("\n  ", $stageErrors));
        }

        $hadDestination = is_dir($destination);
        if ($hadDestination) {
            // Scan first: replacing an existing package must never traverse or preserve a link.
            bbf_deploy_files($destination);
            if (!rename($destination, $backup)) {
                throw new RuntimeException("Cannot move existing package aside: $destination");
            }
            $destinationMoved = true;
        }

        $tempRoot = realpath(sys_get_temp_dir());
        if (getenv('BBF_DEPLOY_TEST_FAIL_ACTIVATION') === '1'
            && $tempRoot !== false && bbf_deploy_is_within($destination, $tempRoot)) {
            throw new RuntimeException('Injected deployment activation failure.');
        }
        if (!rename($stage, $destination)) {
            throw new RuntimeException("Cannot activate staged package: $destination");
        }

        if ($hadDestination) {
            bbf_deploy_remove_tree($backup);
            $destinationMoved = false;
        }
    } catch (Throwable $error) {
        if ($destinationMoved && is_dir($backup) && !file_exists($destination)) {
            if (!rename($backup, $destination)) {
                throw new RuntimeException(
                    $error->getMessage() . "; rollback failed, original package remains at $backup",
                    0,
                    $error
                );
            }
            $destinationMoved = false;
        }
        if (is_dir($stage) && !is_link($stage)) {
            try {
                bbf_deploy_remove_tree($stage);
            } catch (Throwable $cleanupError) {
                throw new RuntimeException($error->getMessage() . '; staging cleanup failed: ' . $cleanupError->getMessage(), 0, $error);
            }
        }
        throw $error;
    }
}

try {
    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        throw new RuntimeException('Cannot resolve repository root.');
    }
    $defaultDestination = $root . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'barebonesforms';
    $options = bbf_deploy_arguments(array_slice($argv, 1), $defaultDestination);
    $destination = bbf_deploy_absolute_destination($options['destination'], $root, $defaultDestination);
    $manifest = bbf_deploy_manifest($root);
    bbf_deploy_validate_manifest($manifest, $root);

    if ($options['check']) {
        $errors = bbf_deploy_compare($destination, $manifest);
        if ($errors !== []) {
            fwrite(STDERR, "Deployment parity failed for $destination:\n  " . implode("\n  ", $errors) . "\n");
            exit(1);
        }
        print 'Deployment parity OK: ' . count($manifest) . " files match by sorted path and SHA-256.\n";
        exit(0);
    }

    bbf_deploy_build($destination, $manifest);
    print 'Deployment package replaced safely: ' . count($manifest) . " files at $destination.\n";
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php tools/package-deploy.php [--check] [--destination <directory>]\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Deployment packaging failed: ' . $error->getMessage() . "\n");
    exit(1);
}
