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
        'forms/form.schema.json'], true)
        && !preg_match('#\A(?:submissions|logs|templates|actions|tests)/bbf-check-[0-9a-f]{32}\.txt\z#D', $path))) return null;
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
