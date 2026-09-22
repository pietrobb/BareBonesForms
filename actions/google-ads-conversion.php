<?php
/** Google Ads click conversion action. No retries here: the BBF outbox owns delivery. */

// Includes may run more than once in a worker (multiple forms/actions).
if (!function_exists('_bbfGadsPost')) {
    function _bbfGadsPost(string $url, array $data, array $headers = [], string $contentType = 'application/json'): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers ?: ['Content-Type: ' . $contentType],
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $contentType === 'application/x-www-form-urlencoded'
            ? http_build_query($data) : json_encode($data));
        $response = curl_exec($ch);
        // Capture everything before closing the handle, including network errors.
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        $raw = is_string($response) ? $response : '';
        $decoded = json_decode($raw, true);
        return ['http_code' => $httpCode, 'curl_errno' => $curlErrno, 'curl_error' => $curlError,
            'raw_body' => $raw, 'body' => is_array($decoded) ? $decoded : []];
    }
}

foreach ((array)($submission['meta']['actions'] ?? []) as $_bbfPrevious) {
    if (is_array($_bbfPrevious) && ($_bbfPrevious['action'] ?? null) === 'google-ads-conversion') {
        return; // ANY previous entry, even an error, prohibits another upload.
    }
}

$_bbfData = $submission['data'] ?? [];
$_bbfIdType = '';
$_bbfIdVal = '';
foreach (['gclid', 'gbraid', 'wbraid'] as $_bbfKey) {
    $_bbfCandidate = trim((string)($_bbfData[$_bbfKey] ?? ''));
    if ($_bbfCandidate !== '' && preg_match('/^[A-Za-z0-9_-]{1,512}$/', $_bbfCandidate)) {
        $_bbfIdType = $_bbfKey;
        $_bbfIdVal = $_bbfCandidate;
        break;
    }
}
if ($_bbfIdType === '') return; // Organic visitor.

$_bbfDetail = ['api_version' => 'v24', 'http_status' => 0, 'click_id_type' => $_bbfIdType,
    'curl_errno' => 0, 'error' => null];
$_bbfFailure = static function(string $error, array $transport = []) use ($_bbfDetail): array {
    return ['status' => 'error', 'retryable' => false, 'detail' => array_replace($_bbfDetail, [
        'http_status' => (int)($transport['http_code'] ?? 0),
        'curl_errno' => (int)($transport['curl_errno'] ?? 0),
        'error' => substr($error, 0, 300),
    ])];
};
$_bbfTransportError = static function(array $transport, string $fallback): string {
    if (!empty($transport['curl_errno'])) return (string)($transport['curl_error'] ?? $fallback);
    return (string)($transport['raw_body'] ?? '') !== '' ? (string)$transport['raw_body'] : $fallback;
};

try {
    $credsFile = __DIR__ . '/../config/google-ads-credentials.php';
    if (!is_file($credsFile)) return $_bbfFailure('Missing Google Ads credentials file');
    if (!defined('BBF_LOADED')) define('BBF_LOADED', true);
    $creds = require $credsFile;
    foreach (['refresh_token', 'client_id', 'client_secret', 'developer_token'] as $_bbfRequired) {
        if (!is_array($creds) || !is_string($creds[$_bbfRequired] ?? null) || trim($creds[$_bbfRequired]) === '') {
            return $_bbfFailure('Incomplete Google Ads credentials: ' . $_bbfRequired);
        }
    }

    $tokenResponse = _bbfGadsPost('https://oauth2.googleapis.com/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $creds['refresh_token'],
        'client_id' => $creds['client_id'],
        'client_secret' => $creds['client_secret'],
    ], [], 'application/x-www-form-urlencoded');
    if ($tokenResponse['http_code'] !== 200 || $tokenResponse['curl_errno'] !== 0
        || !is_string($tokenResponse['body']['access_token'] ?? null) || $tokenResponse['body']['access_token'] === '') {
        return $_bbfFailure($_bbfTransportError($tokenResponse, 'OAuth2 token refresh failed'), $tokenResponse);
    }
    $accessToken = $tokenResponse['body']['access_token'];

    // Payload, API version and identifier priority intentionally match the deployed v24 baseline.
    $customerId = preg_replace('/[^0-9]/', '', $action['customer_id'] ?? '');
    $conversionActionId = $action['conversion_action_id'] ?? '';
    $conversionValue = (float)($action['conversion_value'] ?? 0);
    $currencyCode = $action['currency_code'] ?? 'EUR';
    $conversionActionResource = "customers/{$customerId}/conversionActions/{$conversionActionId}";
    $conversionDateTime = (new DateTime('now', new DateTimeZone('Europe/Bratislava')))->format('Y-m-d H:i:sP');
    $conversion = [
        $_bbfIdType => $_bbfIdVal,
        'conversionAction' => $conversionActionResource,
        'conversionDateTime' => $conversionDateTime,
        'conversionValue' => $conversionValue,
        'currencyCode' => $currencyCode,
    ];
    if ($_bbfIdType !== 'gclid') $conversion['conversionEnvironment'] = 'WEB';
    $apiUrl = "https://googleads.googleapis.com/v24/customers/{$customerId}:uploadClickConversions";
    $payload = ['conversions' => [$conversion], 'partialFailure' => true];
    $result = _bbfGadsPost($apiUrl, $payload, [
        'Authorization: Bearer ' . $accessToken,
        'developer-token: ' . ($creds['developer_token'] ?? ''),
        'Content-Type: application/json',
    ]);
    $ok = $result['http_code'] === 200 && $result['curl_errno'] === 0
        && is_array($result['body']['results'] ?? null) && $result['body']['results'] !== []
        && empty($result['body']['partialFailureError']);
    $outcome = $ok ? ['status' => 'ok', 'detail' => array_replace($_bbfDetail, ['http_status' => 200])]
        : $_bbfFailure($_bbfTransportError($result, 'Conversion upload failed: missing results'), $result);
} catch (Throwable $error) {
    return $_bbfFailure('Google Ads action failed: ' . $error->getMessage());
}

// Logging is also metadata: a logging failure must not turn an accepted upload into an error/retry.
try {
    $logDir = $config['logs_dir'] ?? __DIR__ . '/../logs';
    $logEntry = date('c') . ' | form=' . ($submission['form'] ?? '?')
        . ' | ' . $_bbfIdType . '=' . substr($_bbfIdVal, 0, 20) . '...'
        . ' | status=' . ($ok ? 'OK' : 'FAIL') . ' | response=' . json_encode($result['body']) . "\n";
    @file_put_contents($logDir . '/google-ads-conversions.log', $logEntry, FILE_APPEND | LOCK_EX);
} catch (Throwable $logError) {
    // Outcome remains authoritative.
}
return $outcome;
