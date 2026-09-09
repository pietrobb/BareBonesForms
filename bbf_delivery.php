<?php

declare(strict_types=1);

function bbf_delivery_result(
    bool $ok,
    string $state,
    string $stage,
    int $code = 0,
    bool $retryable = false,
    string $message = ''
): array {
    return [
        'ok' => $ok,
        'state' => $state,
        'stage' => $stage,
        'code' => $code,
        'retryable' => $retryable,
        'message' => $message,
    ];
}

function bbf_delivery_smtp_body(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);
    return implode("\r\n", $lines);
}

function bbf_delivery_smtp_read(callable $read): array {
    $lines = [];
    $code = 0;
    do {
        $line = $read();
        if (!is_string($line) || $line === '') {
            return ['code' => 0, 'lines' => $lines];
        }
        $lines[] = rtrim($line, "\r\n");
        if (!preg_match('/^(\d{3})([ -])/', $line, $match)) {
            return ['code' => 0, 'lines' => $lines];
        }
        $code = (int)$match[1];
        $more = $match[2] === '-';
    } while ($more);
    return ['code' => $code, 'lines' => $lines];
}

function bbf_delivery_smtp_write_all(callable $write, string $bytes): bool {
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = $write(substr($bytes, $offset));
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

function bbf_delivery_smtp_failure(string $stage, int $code, string $message = ''): array {
    return bbf_delivery_result(false, 'failed', $stage, $code, $code === 0 || ($code >= 400 && $code < 500), $message);
}

function bbf_delivery_smtp(array $message, array $config, array $io = []): array {
    $socket = null;
    if ($io === []) {
        $host = (string)($config['smtp_host'] ?? '');
        $port = (int)($config['smtp_port'] ?? 0);
        $enc = (string)($config['smtp_enc'] ?? '');
        $socket = @fsockopen(($enc === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
        if (!is_resource($socket)) {
            return bbf_delivery_result(false, 'failed', 'connect', 0, true, 'SMTP connection failed');
        }
        stream_set_timeout($socket, 10);
        $io = [
            'read' => static fn() => fgets($socket, 4096),
            'write' => static fn(string $bytes) => fwrite($socket, $bytes),
            'tls' => static fn() => @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT),
            'close' => static fn() => fclose($socket),
        ];
    }

    $read = $io['read'] ?? null;
    $write = $io['write'] ?? null;
    $tls = $io['tls'] ?? static fn() => false;
    $close = $io['close'] ?? static fn() => null;
    if (!is_callable($read) || !is_callable($write)) {
        return bbf_delivery_result(false, 'failed', 'transport', 0, false, 'Invalid SMTP transport');
    }

    $finish = static function(array $result) use ($close): array {
        if (is_callable($close)) {
            $close();
        }
        return $result;
    };
    $command = static function(string $line) use ($write): bool {
        return bbf_delivery_smtp_write_all($write, $line . "\r\n");
    };
    $expect = static function(string $stage, array $codes) use ($read): array {
        $response = bbf_delivery_smtp_read($read);
        if (!in_array($response['code'], $codes, true)) {
            return bbf_delivery_smtp_failure($stage, $response['code']);
        }
        return bbf_delivery_result(true, 'accepted', $stage, $response['code']);
    };

    $result = $expect('greeting', [220]);
    if (!$result['ok']) return $finish($result);
    if (!$command('EHLO ' . (gethostname() ?: 'localhost'))) return $finish(bbf_delivery_smtp_failure('ehlo_write', 0));
    $result = $expect('ehlo', [250]);
    if (!$result['ok']) return $finish($result);

    if (($config['smtp_enc'] ?? '') === 'tls') {
        if (!$command('STARTTLS')) return $finish(bbf_delivery_smtp_failure('starttls_write', 0));
        $result = $expect('starttls', [220]);
        if (!$result['ok']) return $finish($result);
        if ($tls() !== true) return $finish(bbf_delivery_result(false, 'failed', 'tls_handshake', 0, true, 'TLS handshake failed'));
        if (!$command('EHLO ' . (gethostname() ?: 'localhost'))) return $finish(bbf_delivery_smtp_failure('ehlo_tls_write', 0));
        $result = $expect('ehlo_tls', [250]);
        if (!$result['ok']) return $finish($result);
    }

    $user = (string)($config['smtp_user'] ?? '');
    if ($user !== '') {
        if (!$command('AUTH LOGIN')) return $finish(bbf_delivery_smtp_failure('auth_write', 0));
        $result = $expect('auth_login', [334]);
        if (!$result['ok']) return $finish($result);
        if (!$command(base64_encode($user))) return $finish(bbf_delivery_smtp_failure('auth_user_write', 0));
        $result = $expect('auth_user', [334]);
        if (!$result['ok']) return $finish($result);
        if (!$command(base64_encode((string)($config['smtp_pass'] ?? '')))) return $finish(bbf_delivery_smtp_failure('auth_password_write', 0));
        $result = $expect('auth_password', [235]);
        if (!$result['ok']) return $finish($result);
    }

    $sender = (string)($config['from_email'] ?? $user);
    if (!$command('MAIL FROM:<' . $sender . '>')) return $finish(bbf_delivery_smtp_failure('mail_from_write', 0));
    $result = $expect('mail_from', [250]);
    if (!$result['ok']) return $finish($result);

    $recipients = $message['recipients'] ?? [];
    if (is_string($recipients)) $recipients = array_map('trim', explode(',', $recipients));
    $recipients = array_values(array_filter((array)$recipients, static fn($value) => filter_var($value, FILTER_VALIDATE_EMAIL)));
    if ($recipients === []) return $finish(bbf_delivery_result(false, 'failed', 'recipient', 0, false, 'No valid recipients'));
    foreach ($recipients as $recipient) {
        if (!$command('RCPT TO:<' . $recipient . '>')) return $finish(bbf_delivery_smtp_failure('recipient_write', 0));
        $result = $expect('recipient', [250, 251]);
        if (!$result['ok']) return $finish($result);
    }

    if (!$command('DATA')) return $finish(bbf_delivery_smtp_failure('data_write', 0));
    $result = $expect('data', [354]);
    if (!$result['ok']) return $finish($result);

    $headers = (array)($message['headers'] ?? []);
    $headerText = 'To: ' . implode(', ', $recipients) . "\r\n";
    $headerText .= 'Subject: ' . str_replace(["\r", "\n", "\0"], '', (string)($message['subject'] ?? '')) . "\r\n";
    foreach ($headers as $name => $value) {
        $headerText .= str_replace(["\r", "\n", "\0"], '', (string)$name) . ': '
            . str_replace(["\r", "\n", "\0"], '', (string)$value) . "\r\n";
    }
    $payload = $headerText . "\r\n" . bbf_delivery_smtp_body((string)($message['body'] ?? '')) . "\r\n.\r\n";
    if (!bbf_delivery_smtp_write_all($write, $payload)) {
        return $finish(bbf_delivery_result(false, 'ambiguous', 'message_write', 0, false, 'SMTP message write was incomplete; automatic retry may duplicate delivery'));
    }
    $response = bbf_delivery_smtp_read($read);
    if ($response['code'] === 0) {
        return $finish(bbf_delivery_result(false, 'ambiguous', 'final_reply', 0, false, 'SMTP final acceptance is unknown; automatic retry may duplicate delivery'));
    }
    if ($response['code'] !== 250) {
        return $finish(bbf_delivery_smtp_failure('final_reply', $response['code']));
    }
    $command('QUIT');
    return $finish(bbf_delivery_result(true, 'accepted', 'final_reply', 250, false, 'Accepted by SMTP server; inbox delivery and exactly-once delivery are not guaranteed'));
}

function bbf_delivery_webhook_status(int $status): array {
    if ($status >= 200 && $status < 300) {
        return bbf_delivery_result(true, 'accepted', 'http', $status);
    }
    $retryable = in_array($status, [408, 425, 429], true) || ($status >= 500 && $status < 600);
    return bbf_delivery_result(false, 'failed', 'http', $status, $retryable);
}

function bbf_delivery_webhook_target(string $url): ?array {
    if ($url === ''
        || preg_match('/[\x00-\x20\x7f]/', $url)
        || str_contains($url, '\\')
        || str_contains($url, '#')
        || preg_match('/%(?![0-9A-Fa-f]{2})/', $url)
        || preg_match('/%(?:0[0-9A-Fa-f]|1[0-9A-Fa-f]|7[fF])/', $url)
        || !preg_match('~\Ahttps://([^/?#]+)~i', $url, $authorityMatch)
    ) {
        return null;
    }

    try {
        $parsed = parse_url($url);
    } catch (ValueError $exception) {
        return null;
    }
    if (!is_array($parsed)
        || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
        || ($parsed['host'] ?? '') === ''
        || isset($parsed['user'])
        || isset($parsed['pass'])
        || array_key_exists('fragment', $parsed)
        || (array_key_exists('port', $parsed) && $parsed['port'] !== 443)
    ) {
        return null;
    }

    $parsedHost = (string)$parsed['host'];
    $isIpv6 = str_starts_with($parsedHost, '[') && str_ends_with($parsedHost, ']');
    if ($isIpv6) {
        $host = substr($parsedHost, 1, -1);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $hostPart = '[' . $host . ']';
    } else {
        if (str_contains($parsedHost, '[') || str_contains($parsedHost, ']') || str_contains($parsedHost, ':')) {
            return null;
        }
        $host = $parsedHost;
        $hostPart = $parsedHost;
        $isIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (!$isIpv4) {
            $dnsHost = rtrim($host, '.');
            if ($dnsHost === '' || strlen($dnsHost) > 253 || preg_match('/^[0-9.]+$/', $dnsHost)) {
                return null;
            }
            foreach (explode('.', $dnsHost) as $label) {
                if ($label === '' || strlen($label) > 63 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label)) {
                    return null;
                }
            }
        }
    }

    $hasPort = array_key_exists('port', $parsed);
    $authority = $authorityMatch[1];
    if ($authority !== $hostPart . ($hasPort ? ':443' : '')) {
        return null;
    }

    return [
        'parsed' => $parsed,
        'host' => rtrim($host, '.'),
        'host_header' => $authority,
        'literal_ip' => filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : '',
    ];
}

function bbf_delivery_ip_in_cidr(string $packedIp, string $network, int $prefix): bool {
    $packedNetwork = inet_pton($network);
    if ($packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }
    $wholeBytes = intdiv($prefix, 8);
    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }
    $remainingBits = $prefix % 8;
    if ($remainingBits === 0) {
        return true;
    }
    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

function bbf_delivery_webhook_ip_is_global(string $ip): bool {
    $packed = inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        $blocked = [
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.31.196.0', 24],
            ['192.52.193.0', 24],
            ['192.88.99.0', 24],
            ['192.168.0.0', 16],
            ['192.175.48.0', 24],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ];
    } else {
        if (!bbf_delivery_ip_in_cidr($packed, '2000::', 3)) {
            return false;
        }
        $blocked = [
            ['2001::', 23],
            ['2001:db8::', 32],
            ['2002::', 16],
            ['2620:4f:8000::', 48],
            ['3fff::', 20],
        ];
    }

    foreach ($blocked as [$network, $prefix]) {
        if (bbf_delivery_ip_in_cidr($packed, $network, $prefix)) {
            return false;
        }
    }
    return true;
}

function bbf_delivery_webhook_addresses(array $target, ?callable $resolver): array {
    if ($target['literal_ip'] !== '') {
        $answers = [$target['literal_ip']];
    } else {
        $resolver ??= static function(string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            return is_array($records) ? $records : [];
        };
        try {
            $answers = $resolver($target['host']);
        } catch (Throwable $exception) {
            $answers = [];
        }
        if (!is_array($answers)) {
            $answers = [];
        }
    }

    $addresses = [];
    foreach ($answers as $answer) {
        if (is_string($answer)) {
            $ip = $answer;
        } elseif (is_array($answer) && isset($answer['ip']) && is_string($answer['ip'])) {
            $ip = $answer['ip'];
        } elseif (is_array($answer) && isset($answer['ipv6']) && is_string($answer['ipv6'])) {
            $ip = $answer['ipv6'];
        } elseif (is_array($answer) && strtoupper((string)($answer['type'] ?? '')) === 'CNAME') {
            continue;
        } else {
            return ['ok' => false, 'addresses' => [], 'message' => 'Webhook DNS answer is invalid'];
        }
        $packed = inet_pton($ip);
        if ($packed === false || !bbf_delivery_webhook_ip_is_global($ip)) {
            return ['ok' => false, 'addresses' => [], 'message' => 'Webhook target is not public'];
        }
        $canonical = inet_ntop($packed);
        if (is_string($canonical)) {
            $addresses[$canonical] = true;
        }
    }

    return $addresses === []
        ? ['ok' => false, 'addresses' => [], 'message' => 'Webhook DNS resolution failed']
        : ['ok' => true, 'addresses' => array_keys($addresses), 'message' => ''];
}

function bbf_delivery_webhook_response_status(string $response): int {
    $offset = 0;
    for ($count = 0; $count < 10; $count++) {
        $remaining = substr($response, $offset);
        if (!preg_match('/\AHTTP\/\d(?:\.\d)?[ \t]+([1-5][0-9]{2})(?:[ \t][^\r\n]*)?\r?\n/', $remaining, $match)) {
            return 0;
        }
        $crlfEnd = strpos($remaining, "\r\n\r\n");
        $lfEnd = strpos($remaining, "\n\n");
        if ($crlfEnd !== false) {
            $blockLength = $crlfEnd + 4;
        } elseif ($lfEnd !== false) {
            $blockLength = $lfEnd + 2;
        } else {
            return 0;
        }
        $status = (int)$match[1];
        if ($status < 100 || $status >= 200) {
            return $status;
        }
        $offset += $blockLength;
    }
    return 0;
}

function bbf_delivery_webhook_stream_request(string $endpoint, string $request, array $contextOptions) {
    $context = stream_context_create($contextOptions);
    $socket = @stream_socket_client($endpoint, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) {
        return false;
    }
    stream_set_timeout($socket, 5);
    $written = bbf_delivery_smtp_write_all(static fn(string $bytes) => fwrite($socket, $bytes), $request);
    if (!$written) {
        fclose($socket);
        return false;
    }

    $response = '';
    for ($block = 0; $block < 10; $block++) {
        $statusLine = fgets($socket, 4096);
        if (!is_string($statusLine)) {
            fclose($socket);
            return false;
        }
        $headerBlock = $statusLine;
        while (!str_ends_with($headerBlock, "\r\n\r\n") && !str_ends_with($headerBlock, "\n\n")) {
            if (strlen($headerBlock) > 65536) {
                fclose($socket);
                return false;
            }
            $line = fgets($socket, 4096);
            if (!is_string($line)) {
                fclose($socket);
                return false;
            }
            $headerBlock .= $line;
        }
        $response .= $headerBlock;
        if (!preg_match('/\AHTTP\/\d(?:\.\d)?[ \t]+([1-5][0-9]{2})/', $headerBlock, $match)
            || (int)$match[1] < 100
            || (int)$match[1] >= 200
        ) {
            fclose($socket);
            return $response;
        }
    }
    fclose($socket);
    return false;
}

function bbf_delivery_webhook(
    string $url,
    array $data,
    string $secret = '',
    string $idempotencyKey = '',
    ?callable $transport = null,
    ?callable $resolver = null,
    ?callable $streamTransport = null
): array {
    $target = bbf_delivery_webhook_target($url);
    if ($target === null) {
        return bbf_delivery_result(false, 'failed', 'url', 0, false, 'Invalid webhook URL');
    }
    $resolved = bbf_delivery_webhook_addresses($target, $resolver);
    if (!$resolved['ok']) {
        return bbf_delivery_result(false, 'failed', 'url', 0, false, $resolved['message']);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return bbf_delivery_result(false, 'failed', 'encode', 0, false, 'Webhook payload encoding failed');
    }
    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json),
    ];
    if ($secret !== '') $headers[] = 'X-BBF-Signature: sha256=' . hash_hmac('sha256', $json, $secret);
    if ($idempotencyKey !== '') $headers[] = 'X-BBF-Idempotency-Key: ' . preg_replace('/[^A-Za-z0-9._:-]/', '', $idempotencyKey);

    if ($transport !== null) {
        $response = $transport($url, $json, $headers);
        if (!is_array($response) || !isset($response['status'])) {
            return bbf_delivery_result(false, 'failed', 'network', 0, true, 'Webhook transport failed');
        }
        return bbf_delivery_webhook_status((int)$response['status']);
    }

    $parsed = $target['parsed'];
    $requestTarget = (string)($parsed['path'] ?? '');
    if ($requestTarget === '') $requestTarget = '/';
    if (array_key_exists('query', $parsed)) $requestTarget .= '?' . $parsed['query'];
    $requestHeaders = array_merge(
        ['Host: ' . $target['host_header']],
        $headers,
        ['Connection: close']
    );
    $request = 'POST ' . $requestTarget . " HTTP/1.1\r\n"
        . implode("\r\n", $requestHeaders) . "\r\n\r\n" . $json;

    $ip = $resolved['addresses'][0];
    $endpoint = 'tls://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':443';
    $contextOptions = [
        'http' => [
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $target['host'],
            'SNI_enabled' => true,
            'SNI_server_name' => $target['host'],
            'alpn_protocols' => 'http/1.1',
        ],
    ];

    $streamTransport ??= 'bbf_delivery_webhook_stream_request';
    try {
        $response = $streamTransport($endpoint, $request, $contextOptions);
    } catch (Throwable $exception) {
        $response = false;
    }
    if (!is_string($response)) {
        return bbf_delivery_result(false, 'failed', 'network', 0, true, 'Webhook transport failed');
    }
    $status = bbf_delivery_webhook_response_status($response);
    return $status > 0
        ? bbf_delivery_webhook_status($status)
        : bbf_delivery_result(false, 'failed', 'network', 0, true, 'Webhook response status missing');
}
