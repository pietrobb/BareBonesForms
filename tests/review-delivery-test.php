<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bbf_delivery.php';

$passed = 0;
$failed = 0;
function check_delivery(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS $message\n";
    } else {
        $failed++;
        echo "FAIL $message\n";
    }
}

function smtp_io(array $responses, array &$writes, bool $tls = true, int $chunk = 0): array {
    return [
        'read' => static function() use (&$responses) {
            return array_shift($responses) ?? false;
        },
        'write' => static function(string $bytes) use (&$writes, $chunk): int {
            $length = $chunk > 0 ? min($chunk, strlen($bytes)) : strlen($bytes);
            $writes[] = substr($bytes, 0, $length);
            return $length;
        },
        'tls' => static fn() => $tls,
        'close' => static fn() => null,
    ];
}

$stuffed = bbf_delivery_smtp_body("first\n.second\r\n..third\rfourth");
check_delivery($stuffed === "first\r\n..second\r\n...third\r\nfourth", 'SMTP body normalizes CRLF and dot-stuffs every leading dot');
check_delivery(!str_contains($stuffed, "\r\n.\r\n"), 'dot-stuffed body cannot terminate DATA early');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n", "250 queued\r\n"];
$result = bbf_delivery_smtp([
    'recipients' => ['user@example.com'],
    'subject' => 'Test',
    'headers' => ['From' => 'Sender <sender@example.com>'],
    'body' => ".first\n..second",
], [
    'smtp_enc' => '',
    'smtp_user' => '',
    'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery($result['ok'] && $result['state'] === 'accepted' && $result['code'] === 250, 'successful SMTP exchange reports server acceptance');
$wire = implode('', $writes);
check_delivery(str_contains($wire, "\r\n..first\r\n...second\r\n.\r\n"), 'SMTP wire payload is dot-stuffed');
check_delivery(str_contains($result['message'], 'not guaranteed'), 'SMTP result discloses inbox and exactly-once limitation');

$writes = [];
$responses = [];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'greeting' && $result['code'] === 0 && $result['retryable'] && $writes === [], 'EOF before SMTP greeting is retryable before any message transmission');

$writes = [];
$responses = ["220 ready\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
$wire = implode('', $writes);
check_delivery(!$result['ok'] && $result['stage'] === 'ehlo' && $result['code'] === 0 && $result['retryable'] && str_starts_with($wire, 'EHLO ') && !str_contains($wire, "\r\nDATA\r\n"), 'EOF after EHLO is retryable before DATA body transmission');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "454 TLS unavailable\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => 'tls', 'smtp_user' => 'secret-user', 'smtp_pass' => 'secret-pass', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'starttls' && $result['code'] === 454, 'STARTTLS refusal fails at protocol stage');
check_delivery(!str_contains(implode('', $writes), base64_encode('secret-user')), 'credentials are not sent after STARTTLS refusal');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "220 begin tls\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => 'tls', 'smtp_user' => 'secret-user', 'smtp_pass' => 'secret-pass', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes, false));
check_delivery(!$result['ok'] && $result['stage'] === 'tls_handshake' && $result['retryable'], 'TLS handshake failure is explicit and retryable');
check_delivery(!str_contains(implode('', $writes), base64_encode('secret-user')), 'credentials are not sent after TLS handshake failure');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "334 user\r\n", "334 password\r\n", "535 denied\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => 'user', 'smtp_pass' => 'password', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'auth_password' && $result['code'] === 535, 'SMTP authentication rejection is not reported as success');
check_delivery(!$result['retryable'], 'permanent SMTP authentication rejection is not automatically retryable');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "450 mailbox busy\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'recipient' && $result['code'] === 450, 'temporary RCPT rejection is detected');
check_delivery($result['retryable'], 'temporary RCPT rejection is retryable');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "550 no mailbox\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && !$result['retryable'] && $result['code'] === 550, 'permanent RCPT rejection is terminal');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "554 data denied\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'data' && $result['code'] === 554, 'DATA command rejection is detected before message bytes');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com', 'body' => 'body'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['state'] === 'ambiguous' && $result['stage'] === 'final_reply', 'missing final SMTP reply is ambiguous');
check_delivery(!$result['retryable'], 'ambiguous SMTP acceptance is not automatically retried');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n", "451 later\r\n"];
$result = bbf_delivery_smtp(['recipients' => 'user@example.com', 'body' => 'body'], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes, true, 3));
check_delivery(!$result['ok'] && $result['stage'] === 'final_reply' && $result['code'] === 451, 'partial transport writes are completed and final SMTP rejection is parsed');
check_delivery($result['retryable'], 'temporary final SMTP rejection is retryable');

$writes = [];
$responses = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n"];
$result = bbf_delivery_smtp(['recipients' => ['invalid']], [
    'smtp_enc' => '', 'smtp_user' => '', 'from_email' => 'sender@example.com',
], smtp_io($responses, $writes));
check_delivery(!$result['ok'] && $result['stage'] === 'recipient' && !$result['retryable'], 'SMTP rejects an empty validated recipient set as terminal');

foreach ([200, 204, 299] as $status) {
    $result = bbf_delivery_webhook_status($status);
    check_delivery($result['ok'] && $result['state'] === 'accepted', "HTTP $status is webhook success");
}
foreach ([300, 400, 404] as $status) {
    $result = bbf_delivery_webhook_status($status);
    check_delivery(!$result['ok'] && !$result['retryable'], "HTTP $status is terminal webhook failure");
}
foreach ([408, 425, 429, 500, 503] as $status) {
    $result = bbf_delivery_webhook_status($status);
    check_delivery(!$result['ok'] && $result['retryable'], "HTTP $status is retryable webhook failure");
}

$resolverCalls = 0;
$publicResolver = static function(string $host) use (&$resolverCalls): array {
    $resolverCalls++;
    return ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
};
$captured = [];
$result = bbf_delivery_webhook('https://example.com/hook', ['value' => 'ž'], 'top-secret', 'submission:42:webhook:0',
    static function(string $url, string $json, array $headers) use (&$captured): array {
        $captured = compact('url', 'json', 'headers');
        return ['status' => 503];
    },
    $publicResolver
);
check_delivery($resolverCalls === 1, 'injected resolver is called exactly once before the compatible three-argument transport');
check_delivery(!$result['ok'] && $result['code'] === 503 && $result['retryable'], 'webhook adapter propagates non-2xx status');
check_delivery(in_array('X-BBF-Idempotency-Key: submission:42:webhook:0', $captured['headers'], true), 'webhook sends stable idempotency key');
$signatureHeaders = array_values(array_filter($captured['headers'], static fn($line) => str_starts_with($line, 'X-BBF-Signature: sha256=')));
$expectedSignature = 'X-BBF-Signature: sha256=' . hash_hmac('sha256', $captured['json'], 'top-secret');
check_delivery($signatureHeaders === [$expectedSignature], 'webhook signs the exact transmitted JSON payload');
check_delivery(!str_contains(json_encode($result), 'top-secret'), 'structured webhook result does not expose secret');

$dnsTransportCalls = 0;
$dnsAnswers = [[], ['127.0.0.1']];
$dnsResolver = static function () use (&$dnsAnswers): array { return array_shift($dnsAnswers); };
$temporaryDns = bbf_delivery_webhook('https://hooks.example/hook', [], '', '',
    static function () use (&$dnsTransportCalls): array { $dnsTransportCalls++; return ['status' => 200]; }, $dnsResolver);
$unsafeRetry = bbf_delivery_webhook('https://hooks.example/hook', [], '', '',
    static function () use (&$dnsTransportCalls): array { $dnsTransportCalls++; return ['status' => 200]; }, $dnsResolver);
check_delivery(!$temporaryDns['ok'] && $temporaryDns['stage'] === 'dns' && $temporaryDns['retryable'],
    'temporary DNS resolution failure is retryable');
check_delivery(!$unsafeRetry['ok'] && $unsafeRetry['stage'] === 'url' && !$unsafeRetry['retryable'] && $dnsTransportCalls === 0,
    'each DNS retry revalidates SSRF safety and rejects a newly private answer before transport');

$invalidUrls = [
    'http://example.com/hook' => 'HTTP scheme',
    'file:///tmp/hook' => 'non-HTTP scheme',
    'https://user@example.com/hook' => 'userinfo',
    'https://user:pass@example.com/hook' => 'password userinfo',
    'https://example.com/hook#fragment' => 'fragment',
    'https://example.com:444/hook' => 'non-default port',
    'https://example.com:0443/hook' => 'non-canonical port',
    "https://example.com/line\nbreak" => 'control character',
    'https://example.com/%0d%0aHost:internal' => 'encoded control characters',
    'https://example.com\\@127.0.0.1/hook' => 'backslash authority confusion',
    'https://exa_mple.com/hook' => 'underscore in host',
    'https://-example.com/hook' => 'leading host hyphen',
    'https://example..com/hook' => 'empty host label',
    'https://%65xample.com/hook' => 'encoded host',
    'https://0177.0.0.1/hook' => 'non-canonical numeric host',
    'https://[2001:db8::1/hook' => 'broken IPv6 brackets',
    'https://example.com/%zz' => 'malformed percent escape',
];
$invalidTransportCalls = 0;
foreach ($invalidUrls as $invalidUrl => $reason) {
    $result = bbf_delivery_webhook(
        $invalidUrl,
        [],
        '',
        '',
        static function() use (&$invalidTransportCalls): array {
            $invalidTransportCalls++;
            return ['status' => 200];
        },
        static fn(string $host): array => ['93.184.216.34']
    );
    check_delivery(!$result['ok'] && $result['stage'] === 'url', "webhook rejects $reason before transport");
}
check_delivery($invalidTransportCalls === 0, 'no invalid URL reaches the injected transport');

$ipMatrix = [
    '0.0.0.0' => false,
    '10.1.2.3' => false,
    '100.64.0.1' => false,
    '127.0.0.1' => false,
    '169.254.1.1' => false,
    '172.16.0.1' => false,
    '192.0.0.1' => false,
    '192.0.2.1' => false,
    '192.31.196.1' => false,
    '192.52.193.1' => false,
    '192.88.99.1' => false,
    '192.168.1.1' => false,
    '192.175.48.1' => false,
    '198.18.0.1' => false,
    '198.51.100.1' => false,
    '203.0.113.1' => false,
    '224.0.0.1' => false,
    '239.255.255.255' => false,
    '240.0.0.1' => false,
    '255.255.255.255' => false,
    '1.1.1.1' => true,
    '8.8.8.8' => true,
    '::' => false,
    '::1' => false,
    '::ffff:8.8.8.8' => false,
    '64:ff9b::808:808' => false,
    '64:ff9b:1::1' => false,
    '100::1' => false,
    '2001::1' => false,
    '2001:db8::1' => false,
    '2002::1' => false,
    '2620:4f:8000::1' => false,
    '3fff::1' => false,
    'fc00::1' => false,
    'fdff::1' => false,
    'fe80::1' => false,
    'fec0::1' => false,
    'ff02::1' => false,
    '2001:4860:4860::8888' => true,
    '2606:4700:4700::1111' => true,
];
foreach ($ipMatrix as $ip => $expectedGlobal) {
    check_delivery(
        bbf_delivery_webhook_ip_is_global($ip) === $expectedGlobal,
        $ip . ($expectedGlobal ? ' is accepted as global' : ' is rejected as non-global or special')
    );
}

$mixedResolverCalls = 0;
$mixedTransportCalls = 0;
$result = bbf_delivery_webhook(
    'https://hooks.example/hook',
    [],
    '',
    '',
    static function() use (&$mixedTransportCalls): array {
        $mixedTransportCalls++;
        return ['status' => 200];
    },
    static function(string $host) use (&$mixedResolverCalls): array {
        $mixedResolverCalls++;
        return [
            ['type' => 'A', 'ip' => '93.184.216.34'],
            ['type' => 'AAAA', 'ipv6' => '::1'],
        ];
    }
);
check_delivery(!$result['ok'] && $result['stage'] === 'url', 'one non-global answer rejects the entire A/AAAA answer set');
check_delivery($mixedResolverCalls === 1 && $mixedTransportCalls === 0, 'all DNS answers are validated once before any transport');

$privateTransportCalls = 0;
$result = bbf_delivery_webhook(
    'https://hooks.example/hook',
    [],
    '',
    '',
    static function() use (&$privateTransportCalls): array {
        $privateTransportCalls++;
        return ['status' => 200];
    },
    static fn(string $host): array => ['127.0.0.1']
);
check_delivery(!$result['ok'] && $privateTransportCalls === 0, 'injected high-level transport cannot bypass target validation');

$lowLevel = [];
$rebindResolverCalls = 0;
$result = bbf_delivery_webhook(
    'https://Hooks.Example:443/path/to/hook?source=test',
    ['event' => 'created'],
    '',
    '',
    null,
    static function(string $host) use (&$rebindResolverCalls): array {
        $rebindResolverCalls++;
        return [
            ['type' => 'A', 'ip' => '93.184.216.34'],
            ['type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
        ];
    },
    static function(string $endpoint, string $request, array $options) use (&$lowLevel): string {
        $lowLevel = compact('endpoint', 'request', 'options');
        return "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1/private\r\nContent-Length: 0\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
    }
);
check_delivery($rebindResolverCalls === 1, 'real transport path performs one DNS resolution');
check_delivery($lowLevel['endpoint'] === 'tls://93.184.216.34:443', 'transport connects to one validated numeric IP so DNS rebinding cannot occur');
check_delivery(!str_contains($lowLevel['endpoint'], 'Hooks.Example'), 'transport endpoint never re-resolves the original hostname');
check_delivery(str_starts_with($lowLevel['request'], "POST /path/to/hook?source=test HTTP/1.1\r\n"), 'numeric socket sends the original path and query');
check_delivery(str_contains($lowLevel['request'], "\r\nHost: Hooks.Example:443\r\n"), 'numeric socket preserves the original HTTP Host');
check_delivery($lowLevel['options']['http']['follow_location'] === 0 && $lowLevel['options']['http']['max_redirects'] === 0, 'transport context explicitly disables redirects');
check_delivery(
    $lowLevel['options']['ssl']['verify_peer'] === true
        && $lowLevel['options']['ssl']['verify_peer_name'] === true
        && $lowLevel['options']['ssl']['allow_self_signed'] === false,
    'TLS context explicitly verifies the peer and certificate name'
);
check_delivery(
    $lowLevel['options']['ssl']['peer_name'] === 'Hooks.Example'
        && $lowLevel['options']['ssl']['SNI_enabled'] === true
        && $lowLevel['options']['ssl']['SNI_server_name'] === 'Hooks.Example',
    'TLS peer_name and SNI preserve the original hostname'
);
check_delivery(!$result['ok'] && $result['code'] === 302 && !$result['retryable'], 'redirect is not followed and only its final response status is used');
check_delivery(
    bbf_delivery_webhook_response_status("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 204 No Content\r\n\r\n") === 204,
    'HTTP parser skips informational responses and parses one final status'
);

$result = bbf_delivery_webhook(
    'https://example.com/hook',
    ["bad" => "\xB1\x31"],
    '',
    '',
    static fn() => ['status' => 200],
    static fn(string $host): array => ['93.184.216.34']
);
check_delivery(!$result['ok'] && $result['stage'] === 'encode', 'webhook rejects invalid UTF-8 payload');
$result = bbf_delivery_webhook(
    'https://example.com/hook',
    [],
    '',
    '',
    null,
    static fn(string $host): array => ['93.184.216.34'],
    static fn() => false
);
check_delivery(!$result['ok'] && $result['stage'] === 'network' && $result['retryable'], 'webhook transport failure is retryable');

printf("RESULT: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
