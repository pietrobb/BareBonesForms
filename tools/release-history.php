<?php
/**
 * Checksums of published releases in tools/release-history.json. The packager copies them into every new manifest
 * as "history", so an upgrade recognises any earlier published version of a file as unmodified.
 *   php tools/release-history.php --tag=v2.1.2          record a published tag (read from git; run after tagging)
 *   php tools/release-history.php --check               CI: fail while a published v2.1.0+ tag (other than HEAD's) is not recorded
 *   php tools/release-history.php <.bbf-manifest.json>  record an unpacked release
 * A release file is the git blob of its tag (the packager copies sources verbatim); generated files are skipped.
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');
$root = dirname(__DIR__);
$file = __DIR__ . '/release-history.json';
$history = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$args = array_slice($argv, 1);
if (!is_array($history) || $args === []) {
    fwrite(STDERR, "Usage: php tools/release-history.php --tag=vX.Y.Z | --check | <.bbf-manifest.json> [...]\n");
    exit(2);
}

/** Paths of the current package, minus files the packager generates instead of copying. */
function history_package_paths(string $root): array {
    $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/bbf-history-' . bin2hex(random_bytes(4));
    $process = proc_open([PHP_BINARY, "$root/tools/package-deploy.php", '--destination', $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $manifest = json_decode((string)@file_get_contents("$tmp/.bbf-manifest.json"), true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    @rmdir($tmp);
    if ($code !== 0 || !is_array($manifest['files'] ?? null)) throw new RuntimeException('The packager failed: ' . trim((string)$err));
    return array_values(array_diff(array_keys($manifest['files']), ['.gitignore', 'logs/.gitkeep', 'submissions/.gitkeep']));
}

/** SHA-256 of every path as committed in $tag (paths the tag does not have are left out). */
function history_tag_hashes(string $root, string $tag, array $paths): array {
    $process = proc_open(['git', '-C', $root, 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('git is not available.');
    $hashes = [];
    foreach ($paths as $path) {
        fwrite($pipes[0], "$tag:$path\n");
        fflush($pipes[0]);
        $header = (string)fgets($pipes[1]);
        if (!preg_match('/\A[0-9a-f]+ blob (\d+)\n\z/', $header, $m)) continue; // "missing" or not a file
        $data = '';
        while (strlen($data) < (int)$m[1] && !feof($pipes[1])) $data .= fread($pipes[1], (int)$m[1] - strlen($data));
        fgets($pipes[1]); // trailing newline
        $hashes[$path] = hash('sha256', $data);
    }
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
    proc_close($process);
    return $hashes;
}

/** Published tags of the manifest era (2.1.0 and newer), oldest first. */
function history_tags(string $root): array {
    $out = shell_exec('git -C ' . escapeshellarg($root) . ' tag --list "v*"');
    $tags = array_values(array_filter(preg_split('/\R/', trim((string)$out)) ?: [], static fn($t) => preg_match('/\Av\d+\.\d+\.\d+\z/', $t) && version_compare(substr($t, 1), '2.1.0', '>=')));
    usort($tags, static fn($a, $b) => version_compare(substr($a, 1), substr($b, 1)));
    return $tags;
}

$add = static function (string $name, string $hash) use (&$history): bool {
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $hash) || in_array($hash, $history[$name] ?? [], true)) return false;
    $history[$name][] = $hash;
    return true;
};

try {
    if ($args === ['--check']) {
        $tags = history_tags($root);
        if ($tags === []) throw new RuntimeException('No v2.1.0+ tags found. Fetch the tags (CI: actions/checkout with fetch-depth: 0).');
        // The release being built from this commit is recorded after it is published, for the next one.
        $building = preg_split('/\R/', trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' tag --points-at HEAD'))) ?: [];
        $tags = array_values(array_diff($tags, $building));
        $paths = history_package_paths($root);
        $missing = [];
        foreach ($tags as $tag) {
            $gaps = array_keys(array_filter(history_tag_hashes($root, $tag, $paths), static fn($h, $p) => !in_array($h, $history[$p] ?? [], true), ARRAY_FILTER_USE_BOTH));
            if ($gaps !== []) $missing[] = "$tag (" . count($gaps) . ' files, e.g. ' . implode(', ', array_slice($gaps, 0, 3)) . ')';
        }
        if ($missing !== []) {
            fwrite(STDERR, 'tools/release-history.json lacks published releases: ' . implode('; ', $missing) . ".\nRecord each with php tools/release-history.php --tag=<tag> and commit the file; otherwise upgrades report unchanged files as edited by you.\n");
            exit(1);
        }
        echo 'Release history covers ' . implode(', ', $tags) . ".\n";
        exit(0);
    }
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--tag=')) {
            $tag = substr($arg, 6);
            if (!preg_match('/\Av\d+\.\d+\.\d+\z/', $tag)) throw new RuntimeException("Not a release tag: $tag");
            $hashes = history_tag_hashes($root, $tag, history_package_paths($root));
            if ($hashes === []) throw new RuntimeException("Tag $tag not found in git.");
            $added = 0;
            foreach ($hashes as $name => $hash) $added += $add($name, $hash) ? 1 : 0;
            echo "Recorded $tag: " . count($hashes) . " files ($added new checksums).\n";
            continue;
        }
        $manifest = json_decode((string)@file_get_contents($arg), true);
        if (!is_array($manifest['files'] ?? null)) throw new RuntimeException("Not a release manifest: $arg");
        foreach ($manifest['files'] as $name => $entry) {
            foreach ([$entry['sha256'] ?? null, ...($entry['history'] ?? [])] as $hash) if (is_string($hash)) $add($name, $hash);
        }
        echo 'Recorded ' . ($manifest['version'] ?? '?') . ': ' . count($manifest['files']) . " files.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
ksort($history, SORT_STRING);
file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
