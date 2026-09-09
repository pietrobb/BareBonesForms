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

/** A failed connection or redirect is not evidence that server access is denied. */
function bbf_diagnostic_probe(array $config, string $path): ?int {
    $base = bbf_diagnostic_base_url($config);
    if ($base === null || !in_array($path, ['config.php', 'submissions/', 'logs/',
        'templates/', 'actions/', 'forms/', 'tests/'], true)) return null;
    $context = stream_context_create(['http' => [
        'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $http_response_header = [];
    @file_get_contents($base . '/' . $path, false, $context);
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $http_response_header[0] ?? '', $match)) {
        return (int)$match[1];
    }
    return null;
}
