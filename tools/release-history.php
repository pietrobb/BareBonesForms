<?php
/**
 * Records the file checksums of a published release in tools/release-history.json. The packager copies them
 * into every new manifest as "history", so an upgrade recognises any earlier published version of a file as
 * unmodified. After publishing a release, run it with that release's manifest and commit the result:
 *   php tools/release-history.php <unpacked-release>/.bbf-manifest.json
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');
$file = __DIR__ . '/release-history.json';
$history = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
if (!is_array($history) || count($argv) < 2) {
    fwrite(STDERR, "Usage: php tools/release-history.php <.bbf-manifest.json> [...]\n");
    exit(2);
}
foreach (array_slice($argv, 1) as $path) {
    $manifest = json_decode((string)@file_get_contents($path), true);
    if (!is_array($manifest['files'] ?? null)) {
        fwrite(STDERR, "Not a release manifest: $path\n");
        exit(1);
    }
    foreach ($manifest['files'] as $name => $entry) {
        foreach ([$entry['sha256'] ?? null, ...($entry['history'] ?? [])] as $hash) {
            if (is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D', $hash) && !in_array($hash, $history[$name] ?? [], true)) $history[$name][] = $hash;
        }
    }
    echo 'Recorded ' . ($manifest['version'] ?? '?') . ': ' . count($manifest['files']) . " files.\n";
}
ksort($history, SORT_STRING);
file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
