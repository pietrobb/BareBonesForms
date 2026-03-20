<?php
/**
 * BareBonesForms — Shared Functions  v1.0.1
 *
 * Used by submit.php (form processing) and payment.php (webhook handler).
 * Not meant to be accessed directly via browser.
 */
defined('BBF_LOADED') || exit;

// Safe string length (mbstring optional, falls back to strlen)
function safeStrlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

// Resolve template references ("use" + "prefix" on group fields).
// Clones template fields, prefixes their names, and adjusts internal show_if references.
function resolveTemplates(array $fields, array $templates): array {
    $result = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if ($type === 'group' && !empty($field['use']) && isset($templates[$field['use']])) {
            $prefix = $field['prefix'] ?? '';
            $tplFields = json_decode(json_encode($templates[$field['use']]), true);
            $tplNames = [];
            foreach ($tplFields as $tf) { $tplNames[$tf['name']] = true; }
            $field['fields'] = prefixFields($tplFields, $prefix, $tplNames);
            unset($field['use'], $field['prefix']);
        } elseif ($type === 'group' && !empty($field['fields'])) {
            $field['fields'] = resolveTemplates($field['fields'], $templates);
        }
        $result[] = $field;
    }
    return $result;
}

function prefixFields(array $fields, string $prefix, array $tplNames): array {
    $result = [];
    foreach ($fields as $field) {
        $field['name'] = $prefix . $field['name'];
        if (!empty($field['show_if'])) {
            $field['show_if'] = prefixCondition($field['show_if'], $prefix, $tplNames);
        }
        if (($field['type'] ?? '') === 'group' && !empty($field['fields'])) {
            $field['fields'] = prefixFields($field['fields'], $prefix, $tplNames);
        }
        $result[] = $field;
    }
    return $result;
}

function prefixCondition(array $cond, string $prefix, array $tplNames): array {
    if (!empty($cond['all'])) {
        $cond['all'] = array_map(fn($c) => prefixCondition($c, $prefix, $tplNames), $cond['all']);
        return $cond;
    }
    if (!empty($cond['any'])) {
        $cond['any'] = array_map(fn($c) => prefixCondition($c, $prefix, $tplNames), $cond['any']);
        return $cond;
    }
    if (!empty($cond['field']) && isset($tplNames[$cond['field']])) {
        $cond['field'] = $prefix . $cond['field'];
    }
    return $cond;
}

// Flatten nested group fields into a single array.
// Propagates the group's show_if to children so the server knows to skip them when hidden.
function flattenFields(array $fields, ?array $parentShowIf = null): array {
    $result = [];
    foreach ($fields as $f) {
        // Inherit parent group's show_if if child doesn't have its own
        if ($parentShowIf && empty($f['show_if'])) {
            $f['show_if'] = $parentShowIf;
        }
        $result[] = $f;
        if (($f['type'] ?? '') === 'group' && !empty($f['fields'])) {
            $groupShowIf = $f['show_if'] ?? $parentShowIf;
            foreach (flattenFields($f['fields'], $groupShowIf) as $child) {
                $result[] = $child;
            }
        }
    }
    return $result;
}

// Evaluate a show_if condition against submitted data.
// Mirrors the client-side _evalCondition / _compareValues logic.
function evalCondition(array $cond, array $input): bool {
    if (!empty($cond['all'])) {
        foreach ($cond['all'] as $c) {
            if (!evalCondition($c, $input)) return false;
        }
        return true;
    }
    if (!empty($cond['any'])) {
        foreach ($cond['any'] as $c) {
            if (evalCondition($c, $input)) return true;
        }
        return false;
    }
    if (!empty($cond['field'])) {
        $val = $input[$cond['field']] ?? '';
        return compareValues($val, $cond['value'] ?? null, $cond['op'] ?? '');
    }
    return true;
}

function compareValues($currentVal, $targetVal, string $op): bool {
    if ($op === 'empty') {
        return is_array($currentVal) ? count($currentVal) === 0 : ($currentVal === '' || $currentVal === null);
    }
    if ($op === 'not_empty') {
        return is_array($currentVal) ? count($currentVal) > 0 : ($currentVal !== '' && $currentVal !== null);
    }

    $targets = is_array($targetVal) ? array_map('strval', $targetVal) : ($targetVal !== null ? [(string)$targetVal] : []);

    if ($op === 'contains') {
        if (is_array($currentVal)) {
            foreach ($targets as $t) {
                foreach ($currentVal as $v) {
                    if (str_contains((string)$v, $t)) return true;
                }
            }
            return false;
        }
        foreach ($targets as $t) {
            if (str_contains((string)$currentVal, $t)) return true;
        }
        return false;
    }

    if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
        $num = is_numeric($currentVal) ? (float)$currentVal : null;
        $tgt = !empty($targets) && is_numeric($targets[0]) ? (float)$targets[0] : null;
        if ($num === null || $tgt === null) return false;
        return match($op) {
            'gt'  => $num > $tgt,
            'gte' => $num >= $tgt,
            'lt'  => $num < $tgt,
            'lte' => $num <= $tgt,
        };
    }

    if ($op === 'not') {
        if (is_array($currentVal)) {
            foreach ($currentVal as $v) {
                if (in_array((string)$v, $targets, true)) return false;
            }
            return true;
        }
        return !in_array((string)$currentVal, $targets, true);
    }

    // Default: equals
    if (is_array($currentVal)) {
        foreach ($currentVal as $v) {
            if (in_array((string)$v, $targets, true)) return true;
        }
        return false;
    }
    return in_array((string)$currentVal, $targets, true);
}

// ─── Email ───────────────────────────────────────────────────────

function sendEmail(string $to, string $subject, string $body, array $mailConfig, string $replyTo = ''): void {
    if (empty($to)) return;

    // Sanitize headers to prevent injection
    $to = str_replace(["\r", "\n", "\0"], '', $to);
    $subject = str_replace(["\r", "\n", "\0"], '', $subject);

    // Validate all addresses (supports comma-separated list)
    $addresses = array_map('trim', explode(',', $to));
    $addresses = array_filter($addresses, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
    if (empty($addresses)) return;
    $to = implode(', ', $addresses);

    // Reply-To: use explicit override if valid, otherwise fall back to from_email
    $replyTo = str_replace(["\r", "\n", "\0"], '', $replyTo);
    if (empty($replyTo) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $mailConfig['from_email'];
    }

    $from = $mailConfig['from_name'] . ' <' . $mailConfig['from_email'] . '>';
    $headers = [
        'From'         => $from,
        'Reply-To'     => $replyTo,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
    ];

    if ($mailConfig['method'] === 'smtp') {
        sendSmtp($to, $subject, $body, $headers, $mailConfig);
    } else {
        // RFC 2047 encode subject if it contains non-ASCII
        $mailSubject = (preg_match('/[^\x20-\x7E]/', $subject))
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
        $headerStr = '';
        foreach ($headers as $k => $v) {
            $headerStr .= "$k: $v\r\n";
        }
        $sent = @mail($to, $mailSubject, $body, $headerStr);
        if (!$sent) {
            error_log("BareBonesForms: mail() failed for recipient $to");
            $GLOBALS['_bbf_errors'][] = "mail() failed for $to";
        }
    }
}

function sendSmtp(string $to, string $subject, string $body, array $headers, array $c): void {
    // Minimal SMTP client — no dependencies needed
    $socket = @fsockopen(
        ($c['smtp_enc'] === 'ssl' ? 'ssl://' : '') . $c['smtp_host'],
        $c['smtp_port'],
        $errno, $errstr, 10
    );
    if (!$socket) {
        error_log("BareBonesForms SMTP connect failed: $errstr");
        $GLOBALS['_bbf_errors'][] = "SMTP connect failed: $errstr";
        return;
    }

    $read = function() use ($socket) { return fgets($socket, 512); };
    $write = function(string $cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };

    $read(); // greeting
    $write("EHLO " . gethostname());
    while ($line = $read()) { if (substr($line, 3, 1) === ' ') break; }

    if ($c['smtp_enc'] === 'tls') {
        $write("STARTTLS");
        $read();
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO " . gethostname());
        while ($line = $read()) { if (substr($line, 3, 1) === ' ') break; }
    }

    $write("AUTH LOGIN");
    $read();
    $write(base64_encode($c['smtp_user']));
    $read();
    $write(base64_encode($c['smtp_pass']));
    $read();

    // Use from_email for envelope sender (not smtp_user)
    $sender = $c['from_email'] ?? $c['smtp_user'];
    $write("MAIL FROM:<$sender>");
    $read();
    foreach (array_map('trim', explode(',', $to)) as $rcpt) {
        $write("RCPT TO:<$rcpt>");
        $read();
    }
    $write("DATA");
    $read();

    // RFC 2047 encode subject if it contains non-ASCII
    $encodedSubject = (preg_match('/[^\x20-\x7E]/', $subject))
        ? '=?UTF-8?B?' . base64_encode($subject) . '?='
        : $subject;

    $headerStr = "To: $to\r\nSubject: $encodedSubject\r\n";
    foreach ($headers as $k => $v) {
        // RFC 2047 encode From header if it contains non-ASCII
        if ($k === 'From' && preg_match('/[^\x20-\x7E]/', $v)) {
            // Encode only the display name part
            if (preg_match('/^(.+?)(\s*<.+>)$/', $v, $m)) {
                $v = '=?UTF-8?B?' . base64_encode($m[1]) . '?=' . $m[2];
            }
        }
        $headerStr .= "$k: $v\r\n";
    }
    $write($headerStr . "\r\n" . $body . "\r\n.");
    $read();
    $write("QUIT");
    fclose($socket);
}

// ─── Webhooks ────────────────────────────────────────────────────

function fireWebhook(string $url, array $data, string $secret = ''): void {
    // SSRF protection: block internal/private network targets
    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return;
    $host = $parsed['host'] ?? '';
    if ($host === '') return;
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        error_log("BareBonesForms: Webhook blocked — target resolves to private/reserved IP: $host → $ip");
        $GLOBALS['_bbf_errors'][] = "Webhook blocked — private/reserved IP: $host → $ip";
        return;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $headers = "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n";
    if ($secret !== '') {
        $signature = hash_hmac('sha256', $json, $secret);
        $headers .= "X-BBF-Signature: sha256=$signature\r\n";
    }
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => $headers,
            'content' => $json,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ];
    $result = @file_get_contents($url, false, stream_context_create($opts));
    if ($result === false) {
        error_log("BareBonesForms: Webhook failed for $url");
        $GLOBALS['_bbf_errors'][] = "Webhook failed for $url";
    }
}

// ─── Templates ───────────────────────────────────────────────────

function renderTemplate(string $templateFile, array $vars): string {
    if (!file_exists($templateFile)) {
        // Fallback: simple text
        $out = "<h2>Form submission</h2>";
        foreach ($vars as $k => $v) {
            if (str_starts_with($k, '_')) continue;
            $out .= "<p><strong>" . htmlspecialchars($k) . ":</strong> " . htmlspecialchars($v) . "</p>";
        }
        return $out;
    }
    $template = file_get_contents($templateFile);

    // Conditional sections: {{#var}}...{{/var}} — shown only if var is truthy/non-empty
    $template = preg_replace_callback('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function($m) use ($vars) {
        $val = $vars[$m[1]] ?? '';
        return ($val !== '' && $val !== '0' && $val !== null) ? $m[2] : '';
    }, $template);

    // Inverted sections: {{^var}}...{{/var}} — shown only if var is falsy/empty
    $template = preg_replace_callback('/\{\{\^(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function($m) use ($vars) {
        $val = $vars[$m[1]] ?? '';
        return ($val === '' || $val === '0' || $val === null) ? $m[2] : '';
    }, $template);

    // Variable substitution
    foreach ($vars as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            // Internal variables (_summary, _time, etc.) contain trusted HTML — don't escape
            $safe = str_starts_with($key, '_') ? (string)$value : htmlspecialchars((string)$value);
            $template = str_replace('{{' . $key . '}}', $safe, $template);
        }
    }

    // Clean up any remaining unreplaced tags
    $template = preg_replace('/\{\{[a-zA-Z_]\w*\}\}/', '', $template);

    return $template;
}

function interpolate(string $text, array $data): string {
    foreach ($data as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            $text = str_replace('{{' . $key . '}}', (string)$value, $text);
        }
    }
    return $text;
}

function buildSummary(array $fields, array $data): string {
    return "<table style='border-collapse:collapse'>" . buildSummaryRows($fields, $data) . "</table>";
}

function buildSummaryRows(array $fields, array $data): string {
    $lines = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        // Skip non-data fields
        if (in_array($type, ['section', 'page_break', 'hidden'])) continue;
        // Recurse into groups — show children, not the group itself
        if ($type === 'group' && !empty($field['fields'])) {
            $lines[] = buildSummaryRows($field['fields'], $data);
            continue;
        }
        $label = $field['label'] ?? $field['name'];
        $value = $data[$field['name']] ?? '';
        if (is_array($value)) $value = implode(', ', $value);
        if ($value === '') continue; // skip empty optional fields
        $lines[] = "<tr><td style='padding:4px 12px 4px 0;font-weight:bold;vertical-align:top'>"
            . htmlspecialchars($label) . "</td><td style='padding:4px 0'>"
            . htmlspecialchars($value) . "</td></tr>";
    }
    return implode('', $lines);
}

// ─── Stripe ──────────────────────────────────────────────────────

/**
 * Create a Stripe Checkout Session. Returns the checkout URL or null on failure.
 * No SDK — just a single HTTPS POST to the Stripe API.
 */
function createStripeCheckout(string $secretKey, array $params): ?string {
    $postFields = [
        'mode'                            => 'payment',
        'success_url'                     => $params['success_url'],
        'cancel_url'                      => $params['cancel_url'],
        'line_items[0][price_data][currency]'                  => $params['currency'],
        'line_items[0][price_data][unit_amount]'               => $params['amount'],
        'line_items[0][price_data][product_data][name]'        => $params['product_name'],
        'line_items[0][quantity]'                              => 1,
    ];

    // Pass metadata so we can identify the submission in the webhook
    foreach ($params['metadata'] as $k => $v) {
        $postFields["metadata[$k]"] = $v;
    }

    // Pre-fill customer email if available
    if (!empty($params['customer_email']) && filter_var($params['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $postFields['customer_email'] = $params['customer_email'];
    }

    $body = http_build_query($postFields);
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Bearer $secretKey\r\n"
                       . "Content-Type: application/x-www-form-urlencoded\r\n"
                       . 'Content-Length: ' . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ];

    $result = @file_get_contents('https://api.stripe.com/v1/checkout/sessions', false, stream_context_create($opts));
    if ($result === false) {
        error_log('BareBonesForms: Stripe API request failed (network error)');
        return null;
    }

    $data = json_decode($result, true);
    if (!empty($data['url'])) {
        return $data['url'];
    }

    error_log('BareBonesForms: Stripe API error: ' . ($data['error']['message'] ?? 'unknown'));
    return null;
}

/**
 * Update a submission's payment status.
 * Supports file and SQLite/MySQL backends. CSV is not supported (append-only).
 */
function updateSubmissionPayment(string $submissionId, string $formId, string $status, array $stripeData, array $config): bool {
    $storage = $config['storage'] ?? 'file';

    switch ($storage) {
        case 'file':
            $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $submissionId . '.json';
            if (!file_exists($file)) return false;
            $submission = json_decode(file_get_contents($file), true);
            if (!$submission) return false;
            $submission['meta']['payment_status'] = $status;
            $submission['meta']['payment_id'] = $stripeData['payment_intent'] ?? $stripeData['id'] ?? '';
            $submission['meta']['payment_amount'] = ($stripeData['amount_total'] ?? 0) / 100;
            $submission['meta']['payment_currency'] = $stripeData['currency'] ?? '';
            $submission['meta']['payment_time'] = date('c');
            return file_put_contents($file, json_encode($submission, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;

        case 'sqlite':
            try {
                $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
                $pdo = new PDO("sqlite:$dbFile", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare("SELECT meta FROM bbf_submissions WHERE id = ?");
                $stmt->execute([$submissionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return false;
                $meta = json_decode($row['meta'], true) ?? [];
                $meta['payment_status'] = $status;
                $meta['payment_id'] = $stripeData['payment_intent'] ?? $stripeData['id'] ?? '';
                $meta['payment_amount'] = ($stripeData['amount_total'] ?? 0) / 100;
                $meta['payment_currency'] = $stripeData['currency'] ?? '';
                $meta['payment_time'] = date('c');
                $stmt = $pdo->prepare("UPDATE bbf_submissions SET meta = ? WHERE id = ?");
                $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $submissionId]);
                return true;
            } catch (PDOException $e) {
                error_log("BareBonesForms: Payment update SQLite error: " . $e->getMessage());
                return false;
            }

        case 'mysql':
            try {
                $db = $config['mysql'];
                $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}";
                $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare("SELECT meta FROM bbf_submissions WHERE id = ?");
                $stmt->execute([$submissionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return false;
                $meta = json_decode($row['meta'], true) ?? [];
                $meta['payment_status'] = $status;
                $meta['payment_id'] = $stripeData['payment_intent'] ?? $stripeData['id'] ?? '';
                $meta['payment_amount'] = ($stripeData['amount_total'] ?? 0) / 100;
                $meta['payment_currency'] = $stripeData['currency'] ?? '';
                $meta['payment_time'] = date('c');
                $stmt = $pdo->prepare("UPDATE bbf_submissions SET meta = ? WHERE id = ?");
                $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $submissionId]);
                return true;
            } catch (PDOException $e) {
                error_log("BareBonesForms: Payment update MySQL error: " . $e->getMessage());
                return false;
            }

        default:
            error_log("BareBonesForms: Payment status update not supported for '$storage' backend");
            return false;
    }
}

// ─── Error notification ──────────────────────────────────────────

/**
 * Notify admin about a processing error (max once per 24 h).
 *
 * Uses mail() directly (not sendEmail/sendSmtp) to avoid recursion
 * when the SMTP connection itself is the cause of the error.
 */
// ─── Validation ─────────────────────────────────────────────────

function validateFormDefinition(array $form): array {
    $errors = [];

    if (isset($form['schema_version']) && $form['schema_version'] !== 1) {
        $errors[] = "Unsupported schema_version: {$form['schema_version']}. Expected: 1.";
    }

    if (empty($form['id'])) {
        $errors[] = 'Missing required property: id.';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $form['id'])) {
        $errors[] = 'Invalid id format. Use only a-z, 0-9, hyphens, underscores.';
    }

    if (empty($form['fields']) || !is_array($form['fields'])) {
        $errors[] = 'Missing or empty required property: fields.';
        return $errors;
    }

    // Resolve templates before validating field list
    $fieldsToValidate = $form['fields'];
    if (!empty($form['templates'])) {
        $fieldsToValidate = resolveTemplates($fieldsToValidate, $form['templates']);
    }

    $fieldNames = [];
    validateFieldList($fieldsToValidate, 'fields', $errors, $fieldNames);

    if (isset($form['on_submit'])) {
        $os = $form['on_submit'];
        if (isset($os['confirm_email']) && empty($os['confirm_email']['to'])) {
            $errors[] = 'on_submit.confirm_email: Missing required property: to.';
        }
        if (isset($os['notify']) && empty($os['notify']['to'])) {
            $errors[] = 'on_submit.notify: Missing required property: to.';
        }
        if (isset($os['actions']) && is_array($os['actions'])) {
            foreach ($os['actions'] as $j => $action) {
                if (empty($action['type'])) {
                    $errors[] = "on_submit.actions[$j]: Missing required property: type.";
                }
            }
        }
    }

    return $errors;
}

function validateFieldList(array $fields, string $path, array &$errors, array &$fieldNames): void {
    $validTypes = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'hidden', 'password', 'section', 'page_break', 'rating', 'group'];

    foreach ($fields as $i => $field) {
        $prefix = "{$path}[{$i}]";

        if (empty($field['name'])) {
            $errors[] = "$prefix: Missing required property: name.";
            continue;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $field['name'])) {
            $errors[] = "$prefix: Invalid name format: {$field['name']}.";
        }

        if (in_array($field['name'], $fieldNames, true)) {
            $errors[] = "$prefix: Duplicate field name: {$field['name']}.";
        }
        $fieldNames[] = $field['name'];

        $type = $field['type'] ?? 'text';
        if (!in_array($type, $validTypes, true)) {
            $errors[] = "$prefix: Invalid type: $type.";
        }

        // Layout-only types — skip further validation
        if (in_array($type, ['section', 'page_break'], true)) continue;

        // Group — recurse into children
        if ($type === 'group') {
            if (!empty($field['fields']) && is_array($field['fields'])) {
                validateFieldList($field['fields'], "$prefix.fields", $errors, $fieldNames);
            }
            continue;
        }

        if (in_array($type, ['select', 'radio', 'checkbox'], true) && empty($field['options'])) {
            $errors[] = "$prefix: Type '$type' requires options.";
        }

        // Validate regex patterns
        if (!empty($field['pattern'])) {
            if (@preg_match('/' . $field['pattern'] . '/', '') === false) {
                $errors[] = "$prefix: Invalid regex pattern: {$field['pattern']}";
            }
        }
    }
}

function validate(array $fields, array $input): array {
    $errors = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';

        // Skip non-data field types
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;

        $name  = $field['name'];

        // Skip conditionally hidden fields — evaluate the condition server-side
        if (!empty($field['show_if']) && !evalCondition($field['show_if'], $input)) {
            continue;
        }

        $raw   = $input[$name] ?? '';
        $value = is_array($raw) ? $raw : trim((string)$raw);
        $label = $field['label'] ?? $name;

        // Required
        if (!empty($field['required'])) {
            if ((is_array($value) && count($value) === 0) || (!is_array($value) && $value === '')) {
                $errors[$name] = msg('required', ['label' => $label]);
                continue;
            }
        }

        // Optional and empty — skip further checks
        if (!is_array($value) && $value === '') continue;
        if (is_array($value) && count($value) === 0) continue;

        // Type-based validation (only for scalar values)
        if (!is_array($value)) {
            switch ($type) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = msg('invalidEmail', ['label' => $label]);
                    }
                    // Email confirmation
                    if (!empty($field['confirm'])) {
                        $confirmVal = trim((string)($input[$name . '_confirm'] ?? ''));
                        if ($value !== $confirmVal) {
                            $errors[$name] = msg('emailMismatch', ['label' => $label]);
                        }
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $errors[$name] = msg('invalidUrl', ['label' => $label]);
                    }
                    break;
                case 'number':
                case 'rating':
                    if (!is_numeric($value)) {
                        $errors[$name] = msg('invalidNumber', ['label' => $label]);
                    }
                    if (isset($field['min']) && $value < $field['min']) {
                        $errors[$name] = msg('numberMin', ['label' => $label, 'min' => $field['min']]);
                    }
                    if (isset($field['max']) && $value > $field['max']) {
                        $errors[$name] = msg('numberMax', ['label' => $label, 'max' => $field['max']]);
                    }
                    break;
                case 'tel':
                    if (!preg_match('/^[+]?[0-9\s\-().]{6,20}$/', $value)) {
                        $errors[$name] = msg('invalidTel', ['label' => $label]);
                    }
                    break;
                case 'date':
                    if (!empty($field['min']) && $value < $field['min']) {
                        $errors[$name] = msg('dateMin', ['label' => $label, 'min' => $field['min']]);
                    }
                    if (!empty($field['max']) && $value > $field['max']) {
                        $errors[$name] = msg('dateMax', ['label' => $label, 'max' => $field['max']]);
                    }
                    break;
            }

            // Pattern (regex)
            if (!empty($field['pattern']) && !preg_match('/' . $field['pattern'] . '/', $value)) {
                $errors[$name] = $field['pattern_message'] ?? msg('invalidFormat', ['label' => $label]);
            }

            // Min/max length
            if (isset($field['minlength']) && safeStrlen($value) < $field['minlength']) {
                $errors[$name] = msg('tooShort', ['label' => $label, 'min' => $field['minlength']]);
            }
            if (isset($field['maxlength']) && safeStrlen($value) > $field['maxlength']) {
                $errors[$name] = msg('tooLong', ['label' => $label, 'max' => $field['maxlength']]);
            }
        }

        // Options (select, radio, checkbox)
        if (!empty($field['options'])) {
            // Support both string options ["A","B"] and object options [{value:"a",label:"A"}]
            $validOptions = [];
            foreach ($field['options'] as $opt) {
                $validOptions[] = is_array($opt) ? (string)($opt['value'] ?? '') : (string)$opt;
            }
            // Allow __other__ sentinel when field has "other": true
            if (!empty($field['other'])) {
                $validOptions[] = '__other__';
            }
            $selected = is_array($value) ? $value : [$value];
            foreach ($selected as $sel) {
                if (!in_array((string)$sel, $validOptions, true)) {
                    $errors[$name] = msg('invalidOption', ['label' => $label]);
                }
            }
        }
    }
    return $errors;
}

// ─── Error Notifications ────────────────────────────────────────

function bbfNotifyError(string $formId, string $context, string $detail, array $config): void {
    $to = $config['error_notify'] ?? '';
    if ($to === '') return;

    $logsDir = $config['logs_dir'] ?? __DIR__ . '/logs';
    $throttleFile = $logsDir . '/.error_notify';

    // Throttle: max one notification per 24 hours
    if (file_exists($throttleFile) && filemtime($throttleFile) > time() - 86400) {
        return;
    }

    // Touch throttle file before sending (prevents retries if mail is slow)
    if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
    @file_put_contents($throttleFile, date('c'));

    $from = $config['mail']['from_email'] ?? 'noreply@example.com';
    $fromName = $config['mail']['from_name'] ?? 'BareBonesForms';
    $subject = "BareBonesForms error: $context ($formId)";

    $body = "A processing error occurred on your BareBonesForms installation.\n\n"
          . "Form:    $formId\n"
          . "Error:   $context\n"
          . "Detail:  $detail\n"
          . "Time:    " . date('Y-m-d H:i:s T') . "\n"
          . "Server:  " . ($_SERVER['SERVER_NAME'] ?? gethostname()) . "\n\n"
          . "This notification is sent at most once per 24 hours.\n"
          . "Check your PHP error_log for the full history.";

    $headers = "From: $fromName <$from>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($to, $subject, $body, $headers);
}
