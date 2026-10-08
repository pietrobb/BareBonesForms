<?php
/**
 * BareBonesForms — Shared Functions
 *
 * Used by submit.php (form processing) and payment.php (webhook handler).
 * Not meant to be accessed directly via browser.
 */
defined('BBF_LOADED') || exit; require_once __DIR__ . '/bbf_form.php'; require_once __DIR__ . '/bbf_alerts.php'; require_once __DIR__ . '/bbf_storage.php'; require_once __DIR__ . '/bbf_delivery.php'; require_once __DIR__ . '/bbf_outbox.php'; require_once __DIR__ . '/bbf_uploads.php';

// ─── Legacy names (2.1 API) — the logic lives in bbf_form.php ────

function safeStrlen(string $s): int { return bbf_strlen($s); }
function resolveTemplates(array $fields, array $templates): array { return bbf_resolve_templates($fields, $templates); }
function prefixFields(array $fields, string $prefix, array $tplNames): array { return bbf_prefix_fields($fields, $prefix, $tplNames); }
function prefixCondition(mixed $cond, string $prefix, array $tplNames): mixed { return bbf_prefix_condition($cond, $prefix, $tplNames); }
function flattenFields(array $fields, ?array $parentShowIf = null): array { return bbf_flatten_fields($fields, $parentShowIf); }
function evalCondition(mixed $cond, array $input): bool { return bbf_eval_condition($cond, $input); }
function compareValues($currentVal, $targetVal, string $op): bool { return bbf_compare_values($currentVal, $targetVal, $op); }
function validateFieldList(array $fields, string $path, array &$errors, array &$fieldNames, bool $insideRepeatable = false): void {
    bbf_definition_field_errors($fields, $path, $errors, $fieldNames, $insideRepeatable);
}
function validateFieldShapes(array $fields, array $input): array { return bbf_validate_shapes($fields, $input); }
function validate(array $fields, array $input): array { return bbf_validate_fields($fields, $input); }
function validateCrossFields(array $rules, array $data): array { return bbf_validate_cross($rules, $data); }

/**
 * options_from on the server (standalone mode): config 'options_resolver' (callable($source, $field): ?array) or, without
 * it, an HTTP GET of the source. A relative source is resolved against diagnostic_base_url, an operator-fixed URL, never
 * the request's Host header. A source that cannot be loaded leaves no valid value: the submission is refused.
 */
function bbf_options_resolver_from_config(array $config): callable {
    if (is_callable($config['options_resolver'] ?? null)) return $config['options_resolver'];
    $base = is_string($config['diagnostic_base_url'] ?? null) ? trim($config['diagnostic_base_url']) : '';
    return static function (string $source) use ($base): ?array {
        $url = bbf_options_source_url($source, $base);
        if ($url === null) {
            error_log("BareBonesForms: options_from \"$source\" cannot be checked on the server; set diagnostic_base_url or options_resolver in config.php.");
            return null;
        }
        [$status, $body] = bbf_uploads_http_get($url);
        $options = $status === 200 ? json_decode($body, true) : null;
        if (bbf_options_list($options) === null) {
            error_log("BareBonesForms: options_from \"$source\" did not return a JSON list of options (HTTP " . ($status ?? 'no response') . ').');
            return null;
        }
        return $options;
    };
}

/** Whether the server can check an options_from source with this config (check.php flags the ones it cannot). */
function bbf_options_source_checkable(array $config, string $source): bool {
    if (is_callable($config['options_resolver'] ?? null)) return true;
    $base = is_string($config['diagnostic_base_url'] ?? null) ? trim($config['diagnostic_base_url']) : '';
    return bbf_options_source_url($source, $base) !== null;
}

/** Absolute http(s) URL of an options_from source, or null when it is relative and no base URL is configured. */
function bbf_options_source_url(string $source, string $base): ?string {
    if (preg_match('~\Ahttps?://~i', $source)) return $source;
    if ($base === '' || !preg_match('~\A(https?://[^/?#]+)(/[^?#]*)?~i', $base, $m)) return null;
    if (str_starts_with($source, '//')) return null;
    if (str_starts_with($source, '/')) return $m[1] . $source;
    return rtrim($m[0], '/') . '/' . $source;
}

// ─── Email ───────────────────────────────────────────────────────

/** RFC 5322 "Name <address>": non-ASCII names are RFC 2047 encoded, names with specials ("Firma, s.r.o.") quoted. */
function bbf_mail_address_header(string $name, string $email): string {
    $name = trim(str_replace(["\r", "\n", "\0"], '', $name));
    $email = str_replace(["\r", "\n", "\0", '<', '>', ' '], '', $email);
    if ($name === '') return "<$email>";
    if (preg_match('/[^\x20-\x7E]/', $name)) {
        $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
    } elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $name)) {
        $name = '"' . addcslashes($name, '"\\') . '"';
    }
    return "$name <$email>";
}

/** Headers every outgoing message needs besides From/To/Subject; the body is sent quoted-printable (lines ≤ 76). */
function bbf_mail_standard_headers(string $fromEmail): array {
    $domain = strrpos($fromEmail, '@') !== false ? substr($fromEmail, strrpos($fromEmail, '@') + 1) : '';
    $domain = preg_replace('/[^a-zA-Z0-9.-]/', '', $domain) ?: 'localhost';
    return [
        'Date' => date('r'),
        'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'Content-Transfer-Encoding' => 'quoted-printable',
    ];
}

/**
 * Create $path with $content only if it does not exist yet, never exposing a partial file:
 * the content is written to a temp file first and then hard-linked (atomic, fails if taken).
 * Returns 'ok', 'exists' or 'error'.
 */
function bbf_create_file_exclusive(string $path, string $content): string {
    if (file_exists($path)) return 'exists';
    $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { @unlink($tmp); return 'error'; }
    try {
        if (@link($tmp, $path)) return 'ok';
        if (file_exists($path)) return 'exists';
        // No hard links on this filesystem: reserve the name exclusively, then atomically replace the empty placeholder.
        $reserve = @fopen($path, 'x');
        if ($reserve === false) return file_exists($path) ? 'exists' : 'error';
        fclose($reserve);
        if (@rename($tmp, $path)) return 'ok';
        @unlink($path);
        return 'error';
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

function sendEmail(string $to, string $subject, string $body, array $mailConfig, string $replyTo = ''): array {
    $to = str_replace(["\r", "\n", "\0"], '', $to);
    $subject = str_replace(["\r", "\n", "\0"], '', $subject);
    $addresses = array_values(array_filter(array_map('trim', explode(',', $to)),
        static fn($address) => filter_var($address, FILTER_VALIDATE_EMAIL)));
    if ($addresses === []) {
        return bbf_delivery_result(false, 'failed', 'recipient', 0, false, 'No valid recipients');
    }
    $to = implode(', ', $addresses);
    $replyTo = str_replace(["\r", "\n", "\0"], '', $replyTo);
    if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = $mailConfig['from_email'];
    $headers = [
        'From' => bbf_mail_address_header((string)($mailConfig['from_name'] ?? ''), (string)$mailConfig['from_email']),
        'Reply-To' => $replyTo,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
    ] + bbf_mail_standard_headers((string)$mailConfig['from_email']);
    $body = quoted_printable_encode($body);

    if (($mailConfig['method'] ?? 'mail') === 'smtp') {
        $result = sendSmtp($to, $subject, $body, $headers, $mailConfig);
    } else {
        $mailSubject = preg_match('/[^\x20-\x7E]/', $subject)
            ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
        $headerStr = '';
        foreach ($headers as $name => $value) $headerStr .= "$name: $value\r\n";
        // Envelope sender = From, so SPF/DMARC align with the site's domain (plain characters only: sendmail command line).
        $from = (string)$mailConfig['from_email'];
        $params = bbf_mail_envelope_sender($from) ? '-f' . $from : '';
        $sent = @mail($to, $mailSubject, $body, $headerStr, $params);
        $result = $sent
            ? bbf_delivery_result(true, 'accepted', 'mail', 0, false, 'Accepted by the local mail transport; inbox delivery is not guaranteed')
            : bbf_delivery_result(false, 'failed', 'mail', 0, true, 'Local mail transport failed');
    }
    if (!$result['ok']) {
        error_log('BareBonesForms delivery failed at ' . $result['stage']);
        $GLOBALS['_bbf_errors'][] = 'Delivery failed at ' . $result['stage'];
    }
    return $result;
}

function sendSmtp(string $to, string $subject, string $body, array $headers, array $config): array {
    $encodedSubject = preg_match('/[^\x20-\x7E]/', $subject)
        ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
    if (isset($headers['From']) && preg_match('/[^\x20-\x7E]/', $headers['From'])
        && preg_match('/^(.+?)(\s*<.+>)$/', $headers['From'], $match)) {
        $headers['From'] = '=?UTF-8?B?' . base64_encode($match[1]) . '?=' . $match[2];
    }
    return bbf_delivery_smtp([
        'recipients' => $to,
        'subject' => $encodedSubject,
        'headers' => $headers,
        'body' => $body,
    ], $config);
}

// ─── Webhooks ────────────────────────────────────────────────────

function fireWebhook(string $url, array $data, string $secret = '', string $idempotencyKey = ''): array {
    $result = bbf_delivery_webhook($url, $data, $secret, $idempotencyKey);
    if (!$result['ok']) {
        error_log('BareBonesForms webhook failed at ' . $result['stage'] . ' with status ' . $result['code']);
        $GLOBALS['_bbf_errors'][] = 'Webhook failed at ' . $result['stage'];
    }
    return $result;
}

// ─── Templates ───────────────────────────────────────────────────

function renderTemplate(string $templateFile, array $vars): string {
    $trustedHtmlVars = array_fill_keys([
        '_form', '_id', '_time', '_summary', '_payment_status', '_payment_id',
        '_payment_amount', '_payment_currency',
    ], true);
    if (!file_exists($templateFile)) {
        // Fallback: simple text
        $out = "<h2>Form submission</h2>";
        foreach ($vars as $k => $v) {
            if (isset($trustedHtmlVars[$k])) continue;
            $text = is_array($v) ? bbf_storage_json($v) : (is_scalar($v) || $v === null ? (string)$v : '');
            $out .= "<p><strong>" . htmlspecialchars($k) . ":</strong> " . htmlspecialchars($text) . "</p>";
        }
        return $out;
    }
    $template = file_get_contents($templateFile);

    // Parse only original template tokens; nested sections never reprocess respondent text.
    $parts = preg_split('/(\{\{[#^\/]?[\w-]+\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    // Only properly nested open/close pairs are sections; an unclosed or crossed tag stays literal text,
    // so a typo never hides the rest of the e-mail.
    $paired = bbf_template_section_pairs($parts);
    $out = ''; $stack = []; $visible = true;
    foreach ($parts as $i => $part) {
        if (!preg_match('/\A\{\{([#^\/]?)([\w-]+)\}\}\z/', $part, $m) || ($m[1] !== '' && !isset($paired[$i]))) {
            if ($visible) $out .= $part;
            continue;
        }
        $value = $vars[$m[2]] ?? '';
        if ($m[1] === '#' || $m[1] === '^') {
            $stack[] = $visible;
            $truthy = $value !== '' && $value !== '0' && $value !== null;
            $visible = $visible && ($m[1] === '#' ? $truthy : !$truthy);
        } elseif ($m[1] === '/') {
            $visible = array_pop($stack);
        } elseif ($visible && (is_string($value) || is_numeric($value))) {
            $out .= isset($trustedHtmlVars[$m[2]]) ? (string)$value : htmlspecialchars((string)$value);
        }
    }
    return $out;
}

/** Indexes of section tags in preg_split parts that form properly nested open/close pairs. */
function bbf_template_section_pairs(array $parts): array {
    $paired = []; $stack = [];
    foreach ($parts as $i => $part) {
        if (!preg_match('/\A\{\{([#^\/])([\w-]+)\}\}\z/', $part, $m)) continue;
        if ($m[1] !== '/') { $stack[] = [$i, $m[2]]; continue; }
        // A close tag matches the innermost open tag of the same name; open tags it skips are unclosed.
        for ($j = count($stack) - 1; $j >= 0 && $stack[$j][1] !== $m[2]; $j--);
        if ($j < 0) continue;
        $paired[$stack[$j][0]] = true; $paired[$i] = true;
        array_splice($stack, $j);
    }
    return $paired;
}

/** Template problems that render as literal text: unclosed, unmatched or crossed section tags and malformed tags. */
function bbf_template_warnings(string $template): array {
    $parts = preg_split('/(\{\{[#^\/]?[\w-]+\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    $paired = bbf_template_section_pairs($parts);
    $warnings = [];
    foreach ($parts as $i => $part) {
        if (preg_match('/\A\{\{([#^\/]?)([\w-]+)\}\}\z/', $part, $m)) {
            if ($m[1] !== '' && !isset($paired[$i])) $warnings[] = ($m[1] === '/' ? 'Unmatched closing tag ' : 'Unclosed section tag ') . $part . '; it is shown as text.';
        } elseif (preg_match_all('/\{\{\s*[#^\/]?\s*[\w-]+\s*\}\}/', $part, $loose)) {
            foreach ($loose[0] as $tag) $warnings[] = "Malformed tag $tag (spaces are not allowed); it is shown as text.";
        }
    }
    return $warnings;
}

/** on_submit.redirect after interpolation: only http(s) or a relative URL, never javascript:/data: or control characters.
 *  Field values are percent-encoded, so "Jana Nová" stays one query value and "&"/"#"/"/" cannot add parameters or change the target.
 *  Checkbox values are joined with ","; a field without a value becomes empty, never a literal "{{tags}}". A target that
 *  is only a field ("{{return_url}}") would let the respondent choose where to go (open redirect): no redirect then,
 *  the success message is shown. $system: template variables such as _id, _form and _time; they win over field values. */
function bbf_redirect_url(string $template, array $data, array $system = []): ?string {
    if (preg_match('/\A\s*\{\{\s*[\w-]+\s*\}\}\s*\z/', $template)) return null;
    $encoded = [];
    foreach (array_replace($data, $system) as $key => $value) { // array_merge would renumber a field named "7"
        if (is_array($value)) $value = implode(',', array_filter($value, 'is_scalar'));
        if (is_bool($value)) $value = $value ? '1' : '';
        if (is_string($value) || is_numeric($value)) $encoded[$key] = rawurlencode((string)$value);
    }
    $url = trim(preg_replace('/\{\{[\w-]+\}\}/', '', interpolate($template, $encoded)));
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
    if (preg_match('/\A([a-z][a-z0-9+.-]*):/i', $url, $m)) return in_array(strtolower($m[1]), ['http', 'https'], true) ? $url : null;
    return str_starts_with($url, '\\') || str_starts_with($url, '/\\') ? null : $url;
}

function interpolate(string $text, array $data): string {
    return preg_replace_callback('/\{\{([\w-]+)\}\}/', static function ($m) use ($data) {
        $value = $data[$m[1]] ?? '';
        return is_string($value) || is_numeric($value) ? (string)$value : '';
    }, $text);
}

function buildSummary(array $fields, array $data): string {
    return "<table style='border-collapse:collapse'>\n" . buildSummaryRows($fields, $data) . "\n</table>";
}

function buildSummaryRows(array $fields, array $data): string {
    $lines = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        // Skip non-data fields
        if (in_array($type, ['section', 'page_break', 'hidden'])) continue;
        // Recurse into static groups; repeatable groups remain one structured value.
        if ($type === 'group' && !empty($field['fields'])) {
            if (!empty($field['repeatable'])) {
                $value = $data[$field['name']] ?? [];
                if ($value === []) continue;
                $label = $field['label'] ?? $field['title'] ?? $field['name'];
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $lines[] = "<tr><td style='padding:4px 12px 4px 0;font-weight:bold;vertical-align:top'>"
                    . htmlspecialchars($label) . "</td><td style='padding:4px 0'>"
                    . htmlspecialchars($encoded) . "</td></tr>";
            } else {
                $lines[] = buildSummaryRows($field['fields'], $data);
            }
            continue;
        }
        $label = $field['label'] ?? $field['name'];
        $value = $data[$field['name']] ?? '';
        if ($type === 'file') $value = bbf_uploads_describe($value);
        if (is_array($value)) $value = implode(', ', $value);
        if ($value === '') continue; // skip empty optional fields
        $lines[] = "<tr><td style='padding:4px 12px 4px 0;font-weight:bold;vertical-align:top'>"
            . htmlspecialchars($label) . "</td><td style='padding:4px 0'>"
            . htmlspecialchars($value) . "</td></tr>";
    }
    return implode("\n", $lines); // one row per line keeps e-mail lines short
}

// ─── Durable delivery plans ─────────────────────────────────────

function bbf_delivery_payload_hash(array $payload): string {
    return hash('sha256', json_encode($payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function bbf_delivery_payment_template_bindings(): array {
    $nonce = bin2hex(random_bytes(16));
    $bindings = [];
    foreach (['_payment_status', '_payment_id', '_payment_amount', '_payment_currency'] as $name) {
        $bindings[$name] = '[[BBF_PAYMENT_' . $nonce . '_' . strtoupper(substr($name, 9)) . ']]';
    }
    return $bindings;
}

function bbf_delivery_finalize_submission(string $path, array $submission, ?int $now = null): array {
    $submission = bbf_record_public($submission); // planned payloads were prepared without it
    if (!is_array($submission['meta'] ?? null)
        || ($submission['meta']['payment_status'] ?? null) !== 'paid') return ['ok' => false, 'reason' => 'payment'];
    $now = bbf_outbox_now($now);
    return bbf_outbox_transaction($path, static function($ledger) use ($submission, $now): array {
        if (!is_array($ledger) || !is_array($ledger['jobs'] ?? null) || ($ledger['deleted'] ?? false) === true) {
            return ['result' => ['ok' => false, 'reason' => 'missing']];
        }
        $meta = $submission['meta'];
        $bindingValues = [
            '_payment_status' => 'paid',
            '_payment_id' => (string)($meta['payment_id'] ?? ''),
            '_payment_amount' => isset($meta['payment_amount_minor'], $meta['payment_minor_units'])
                ? bbfPaymentFormatMinor((int)$meta['payment_amount_minor'], (int)$meta['payment_minor_units'])
                : (string)($meta['payment_amount'] ?? ''),
            '_payment_currency' => strtoupper((string)($meta['payment_currency'] ?? '')),
        ];
        $finalized = ($ledger['submission_finalized'] ?? false) === true;
        foreach ($ledger['jobs'] as $key => &$job) {
            if (!is_array($job) || !is_array($job['payload'] ?? null)) {
                unset($job);
                return ['result' => ['ok' => false, 'reason' => 'payload']];
            }
            $hasSubmission = array_key_exists('submission', $job['payload']);
            $hasBindings = array_key_exists('_payment_bindings', $job['payload']);
            $legacyEmail = !$finalized && !$hasSubmission && !$hasBindings
                && in_array((string)($job['type'] ?? ''), ['email', 'smtp'], true);
            if (!$hasSubmission && !$hasBindings && !$legacyEmail) continue;
            try { $payloadHash = bbf_delivery_payload_hash($job['payload']); }
            catch (Throwable $error) {
                unset($job);
                return ['result' => ['ok' => false, 'reason' => 'payload']];
            }
            if (!preg_match('/\A[a-f0-9]{64}\z/', (string)($job['payload_hash'] ?? ''))
                || !hash_equals((string)$job['payload_hash'], $payloadHash)) {
                unset($job);
                return ['result' => ['ok' => false, 'reason' => 'payload']];
            }
            if ($finalized) {
                $planned = $job['payload']['submission'] ?? []; $current = $submission; unset($planned['meta']['actions'], $current['meta']['actions']); if ($hasBindings || ($hasSubmission && $planned !== $current)) {
                    unset($job);
                    return ['result' => ['ok' => false, 'reason' => 'conflict']];
                }
                continue;
            }
            if (($job['state'] ?? null) !== 'pending' || (int)($job['attempts'] ?? 0) !== 0) {
                unset($job);
                return ['result' => ['ok' => false, 'reason' => 'started']];
            }
            if ($hasSubmission) $job['payload']['submission'] = $submission;
            if ($hasBindings) {
                $bindings = $job['payload']['_payment_bindings'];
                if (!is_array($bindings) || $bindings === []
                    || array_diff(array_keys($bindings), array_keys($bindingValues))) {
                    unset($job);
                    return ['result' => ['ok' => false, 'reason' => 'payload']];
                }
                $tokens = []; $values = [];
                foreach ($bindings as $name => $token) {
                    if (!is_string($token) || !preg_match('/\A\[\[BBF_PAYMENT_[a-f0-9]{32}_[A-Z_]+\]\]\z/D', $token)) {
                        unset($job);
                        return ['result' => ['ok' => false, 'reason' => 'payload']];
                    }
                    $tokens[] = $token; $values[] = $bindingValues[$name];
                }
                unset($job['payload']['_payment_bindings']);
                foreach (['to', 'subject', 'body', 'reply_to'] as $field) {
                    if (isset($job['payload'][$field]) && is_string($job['payload'][$field])) {
                        $job['payload'][$field] = str_replace($tokens, $values, $job['payload'][$field]);
                    }
                }
            }
            if ($legacyEmail) {
                $checkoutId = (string)($meta['payment_checkout_session_id'] ?? '');
                $paymentId = (string)($meta['payment_id'] ?? '');
                if ($checkoutId === '' || $paymentId === '') {
                    unset($job);
                    return ['result' => ['ok' => false, 'reason' => 'payment']];
                }
                foreach (['to', 'subject', 'body', 'reply_to'] as $field) {
                    if (isset($job['payload'][$field]) && is_string($job['payload'][$field])) {
                        $job['payload'][$field] = str_replace($checkoutId, $paymentId, $job['payload'][$field]);
                    }
                }
            }
            $job['payload_hash'] = bbf_delivery_payload_hash($job['payload']);
            $job['updated_at'] = $now;
            $ledger['jobs'][$key] = $job;
        }
        unset($job);
        if ($finalized) return ['result' => ['ok' => true, 'changed' => false, 'ledger' => $ledger]];
        $ledger['submission_finalized'] = true;
        $ledger['updated_at'] = $now;
        return ['ledger' => $ledger, 'result' => ['ok' => true, 'changed' => true, 'ledger' => $ledger]];
    });
}

function bbf_delivery_template_data(array $form, array $submission, array $templateData = []): array {
    $data = is_array($submission['data'] ?? null) ? $submission['data'] : [];
    $templateData = array_replace($data, $templateData);
    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    if (!empty($form['templates'])) $fields = resolveTemplates($fields, (array)$form['templates']);
    foreach (flattenFields($fields) as $field) {
        if (($field['type'] ?? '') === 'file' && array_key_exists((string)($field['name'] ?? ''), $data)) {
            $templateData[$field['name']] = bbf_uploads_describe($data[$field['name']]);
            continue;
        }
        if (!in_array($field['type'] ?? 'text', ['select', 'radio', 'checkbox'], true)) continue;
        $name = (string)($field['name'] ?? '');
        if ($name === '' || !array_key_exists($name, $data)) continue;
        $selected = is_array($data[$name]) ? array_map('strval', $data[$name]) : [(string)$data[$name]];
        $labels = [];
        foreach ((array)($field['options'] ?? []) as $option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
            if (!in_array($value, $selected, true)) continue;
            $labels[] = is_array($option) ? (string)($option['label'] ?? $value) : $value;
        }
        if ($labels !== []) $templateData[$name . '_label'] = implode(', ', $labels);
    }
    return $templateData;
}

function bbf_delivery_sensitive_action_key($key): bool {
    if (!is_string($key)) return false;
    $normalized = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $key));
    return $normalized !== ''
        && preg_match('/secret|token|password|credential|authorization|apikey|privatekey|accesskey|bearer/', $normalized) === 1;
}

function bbf_delivery_sanitize_action_config(array $value, array &$sensitivePaths = [], array $path = []): array {
    $sanitized = [];
    foreach ($value as $key => $item) {
        $itemPath = array_merge($path, [$key]);
        if (bbf_delivery_sensitive_action_key($key)) {
            $sensitivePaths[] = $itemPath;
            continue;
        }
        $sanitized[$key] = is_array($item)
            ? bbf_delivery_sanitize_action_config($item, $sensitivePaths, $itemPath)
            : $item;
    }
    return $sanitized;
}

function bbf_delivery_action_path_value(array $source, array $path, bool &$found) {
    $value = $source;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            $found = false;
            return null;
        }
        $value = $value[$key];
    }
    $found = true;
    return $value;
}

function bbf_delivery_action_set_path(array &$target, array $path, $value): bool {
    if ($path === []) return false;
    $cursor =& $target;
    $last = array_pop($path);
    foreach ($path as $key) {
        if (!array_key_exists($key, $cursor) || !is_array($cursor[$key])) return false;
        $cursor =& $cursor[$key];
    }
    if (array_key_exists($last, $cursor)) return false;
    $cursor[$last] = $value;
    return true;
}

/** Resolve only the omitted sensitive values from the current trusted form action. */
function bbf_delivery_runtime_action(array $payload, array $config): array {
    $submission = is_array($payload['submission'] ?? null) ? $payload['submission'] : [];
    $formId = (string)($submission['form'] ?? '');
    $index = $payload['action_index'] ?? null;
    $persisted = is_array($payload['action'] ?? null) ? $payload['action'] : null;
    $expectedType = $payload['action_type'] ?? null;
    $sensitivePaths = $payload['action_sensitive_paths'] ?? null;
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId) || !is_int($index) || $index < 0
        || !is_array($persisted) || !is_string($expectedType) || !is_array($sensitivePaths)) {
        throw new RuntimeException('Invalid persisted action descriptor.');
    }
    $discardedPaths = [];
    $runtime = bbf_delivery_sanitize_action_config($persisted, $discardedPaths);
    if ($sensitivePaths === []) return $runtime;
    $formsDir = rtrim((string)($config['forms_dir'] ?? __DIR__ . '/forms'), '/\\');
    $raw = @file_get_contents($formsDir . '/' . $formId . '.json');
    try {
        $form = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable $error) {
        $form = null;
    }
    $trustedActions = is_array($form['on_submit']['actions'] ?? null) ? $form['on_submit']['actions'] : [];
    $trusted = $trustedActions[$index] ?? null;
    if (!is_array($trusted) || !is_string($trusted['type'] ?? null)
        || !hash_equals($expectedType, $trusted['type'])) {
        throw new RuntimeException('Trusted action source is missing or changed type.');
    }
    foreach ($sensitivePaths as $path) {
        if (!is_array($path) || $path === []) throw new RuntimeException('Invalid sensitive action path.');
        $found = false;
        $value = bbf_delivery_action_path_value($trusted, $path, $found);
        if (!$found || !bbf_delivery_action_set_path($runtime, $path, $value)) {
            throw new RuntimeException('Trusted sensitive action source is missing.');
        }
    }
    return $runtime;
}

/** Build the complete immutable execution plan before any delivery effect runs. */
function bbf_delivery_prepare_jobs(array $form, array $submission, array $config, array $templateData = []): array {
    $submission = bbf_record_public($submission);
    $paymentBindings = is_array($templateData['_bbf_payment_bindings'] ?? null)
        ? $templateData['_bbf_payment_bindings'] : [];
    unset($templateData['_bbf_payment_bindings']);
    $formId = (string)($submission['form'] ?? $form['id'] ?? 'form');
    $submissionId = (string)($submission['id'] ?? 'submission');
    $prefix = $formId . ':' . $submissionId;
    $data = is_array($submission['data'] ?? null) ? $submission['data'] : [];
    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    if (!empty($form['templates'])) $fields = resolveTemplates($fields, (array)$form['templates']);
    $templateData = bbf_delivery_template_data(array_replace($form, ['fields' => $fields]), $submission, $templateData);
    $timestamp = (string)($submission['meta']['submitted'] ?? date('c'));
    $templateVars = array_replace($templateData, [
        '_form' => (string)($form['name'] ?? $formId),
        '_id' => $submissionId,
        '_time' => $timestamp,
        '_summary' => buildSummary($fields, $data),
    ]);
    $templatesDir = rtrim((string)($config['templates_dir'] ?? __DIR__ . '/templates'), '/\\');
    $onSubmit = is_array($form['on_submit'] ?? null) ? $form['on_submit'] : [];
    $jobs = [];

    $add = static function(string $key, string $type, array $payload, string $target, bool $idempotent) use (&$jobs, $prefix): void {
        $jobs[] = [
            'key' => $key,
            'type' => $type,
            'payload' => $payload,
            'payload_hash' => bbf_delivery_payload_hash($payload),
            'idempotency_key' => $prefix . ':' . $key,
            'target' => $target,
            'idempotent' => $idempotent,
        ];
    };

    if (is_array($onSubmit['confirm_email'] ?? null) && $onSubmit['confirm_email'] !== []) {
        $email = $onSubmit['confirm_email'];
        $payload = [
            'to' => interpolate((string)($email['to'] ?? ''), $data),
            'subject' => interpolate((string)($email['subject'] ?? 'Thank you'), $templateVars),
            'body' => renderTemplate($templatesDir . '/' . basename((string)($email['template'] ?? 'confirm.html')), $templateVars),
            'reply_to' => isset($email['reply_to']) ? interpolate((string)$email['reply_to'], $data) : '',
        ];
        if ($paymentBindings !== []) $payload['_payment_bindings'] = $paymentBindings;
        $add('confirm', 'email', $payload, 'respondent-email', false);
    }

    if (is_array($onSubmit['notify'] ?? null) && $onSubmit['notify'] !== []) {
        $email = $onSubmit['notify'];
        $recipients = $email['to'] ?? '';
        if (is_array($recipients)) {
            $recipients = implode(', ', array_map(static fn($recipient) => interpolate((string)$recipient, $data), $recipients));
        } else {
            $recipients = interpolate((string)$recipients, $data);
        }
        $payload = [
            'to' => $recipients,
            'subject' => interpolate((string)($email['subject'] ?? "New submission: $formId"), $templateVars),
            'body' => renderTemplate($templatesDir . '/' . basename((string)($email['template'] ?? 'notify.html')), $templateVars),
            'reply_to' => isset($email['reply_to']) ? interpolate((string)$email['reply_to'], $data) : '',
        ];
        if ($paymentBindings !== []) $payload['_payment_bindings'] = $paymentBindings;
        $add('notify', 'email', $payload, 'owner-email', false);
    }

    foreach ((array)($onSubmit['webhooks'] ?? []) as $index => $url) {
        $url = (string)$url;
        $payload = ['url' => $url, 'submission' => $submission];
        $add('webhook:' . $index, 'webhook', $payload,
            (string)(parse_url($url, PHP_URL_HOST) ?: 'webhook'), true);
    }

    foreach ((array)($onSubmit['actions'] ?? []) as $index => $action) {
        if (!is_array($action)) $action = [];
        $sensitivePaths = [];
        $sanitizedAction = bbf_delivery_sanitize_action_config($action, $sensitivePaths);
        $payload = [
            'action_index' => (int)$index,
            'action_type' => (string)($action['type'] ?? ''),
            'action_sensitive_paths' => $sensitivePaths,
            'action' => $sanitizedAction,
            'submission' => $submission,
        ];
        $add('action:' . $index, 'action', $payload, (string)($action['type'] ?? 'action'),
            ($action['idempotent'] ?? false) === true);
    }

    return $jobs;
}

/** File-only metadata projection. Other backends deliberately remain a no-op. */
function bbfRecordActionResult(string $submissionId, string $formId, string $action, string $status,
    array $detail, array $config): bool {
    try {
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $submissionId)
            || !in_array($status, ['ok', 'error'], true) || $action === '') return false;
        $config = bbf_effective_storage_config($config, $formId, ['storage' => $config['storage'] ?? 'file']);
        if ($config['storage'] !== 'file') return true;
        $path = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $submissionId . '.json';
        return bbf_storage_locked($path, static function() use ($path, $submissionId, $formId, $action, $status, $detail): bool {
            if (!is_file($path)) return false;
            $record = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            if (!($record instanceof stdClass) || ($record->id ?? null) !== $submissionId || ($record->form ?? null) !== $formId
                || !(($record->meta ?? null) instanceof stdClass)) return false;
            if (isset($record->meta->actions) && !is_array($record->meta->actions)) return false;
            // Decode objects as objects: a metadata projection must never turn
            // empty or numeric-key objects in visitor data or old details into arrays.
            if (!isset($record->meta->actions)) {
                $record->meta->actions = [];
            } $record->meta->actions[] = ['action' => $action, 'status' => $status,
                'at' => gmdate('Y-m-d\TH:i:s\Z'), 'detail' => (object)$detail];
            $json = bbf_storage_json($record, true);
            return bbf_storage_replace($path, static fn($fp) => bbf_storage_write_all($fp, $json));
        });
    } catch (Throwable $error) {
        return false;
    }
}

/** Refresh only action history, never the immutable visitor payload, before an action's idempotence check. */
function bbf_delivery_refresh_action_metadata(array $submission, array $config): array {
    $id = (string)($submission['id'] ?? '');
    $form = (string)($submission['form'] ?? '');
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $id) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $form)) return $submission;
    // The runner already receives the resolved backend; mutable form definitions cannot gate an immutable job.
    if (($config['storage'] ?? 'file') !== 'file') return $submission;
    $path = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $form . '/' . $id . '.json';
    if (!is_file($path)) return $submission;
    $ok = bbf_storage_locked($path, static function() use ($path, $id, $form, &$submission): bool {
        $record = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['id'] ?? null) !== $id || ($record['form'] ?? null) !== $form) return false;
        $history = $record['meta']['actions'] ?? [];
        if (!is_array($history)) return false;
        $submission['meta']['actions'] = array_merge((array)($submission['meta']['actions'] ?? []), $history);
        return true;
    });
    if (!$ok) throw new RuntimeException('Cannot read current action history safely.');
    return $submission;
}

/** Execute one immutable delivery job without creating or updating durable state. */
function bbf_delivery_execute_job(array $job, array $config, array &$actionResponse = []): array {
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : null;
    $outcome = null;
    if ($payload === null || !preg_match('/\A[a-f0-9]{64}\z/', (string)($job['payload_hash'] ?? ''))) {
        $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Invalid immutable delivery payload');
    } else {
        try {
            $actualHash = bbf_delivery_payload_hash($payload);
            if (!hash_equals((string)$job['payload_hash'], $actualHash)) {
                $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Immutable delivery payload mismatch');
            }
        } catch (Throwable $error) {
            $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Invalid immutable delivery payload');
        }
    }

    if ($outcome === null) {
        try {
            $fixtureEffect = $GLOBALS['_bbf_delivery_effect'] ?? null;
            if (is_callable($fixtureEffect)) {
                $outcome = $fixtureEffect($job, $payload);
            } else {
                $type = (string)($job['type'] ?? '');
                if ($type === 'email' || $type === 'smtp') {
                    $outcome = sendEmail(
                        (string)($payload['to'] ?? ''),
                        (string)($payload['subject'] ?? ''),
                        (string)($payload['body'] ?? ''),
                        is_array($config['mail'] ?? null) ? $config['mail'] : [],
                        (string)($payload['reply_to'] ?? '')
                    );
                } elseif ($type === 'webhook') {
                    $delivery = is_array($config['delivery'] ?? null) ? $config['delivery'] : [];
                    $outcome = bbf_delivery_webhook(
                        (string)($payload['url'] ?? ''),
                        is_array($payload['submission'] ?? null) ? $payload['submission'] : [],
                        (string)($config['webhook_secret'] ?? ''),
                        (string)($job['idempotency_key'] ?? ''),
                        is_callable($delivery['webhook_transport'] ?? null) ? $delivery['webhook_transport'] : null,
                        is_callable($delivery['webhook_resolver'] ?? null) ? $delivery['webhook_resolver'] : null,
                        is_callable($delivery['webhook_stream_transport'] ?? null) ? $delivery['webhook_stream_transport'] : null
                    );
                } elseif ($type === 'action') {
                    try {
                        $action = bbf_delivery_runtime_action($payload, $config);
                    } catch (Throwable $error) {
                        $outcome = bbf_delivery_result(false, 'failed', 'action', 0, false, 'Trusted action configuration unavailable');
                    }
                    if ($outcome === null) {
                        $submission = is_array($payload['submission'] ?? null) ? $payload['submission'] : [];
                        $actionType = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($action['type'] ?? ''));
                        $actionFile = __DIR__ . '/actions/' . $actionType . '.php';
                        if ($actionType === '' || !is_file($actionFile)) throw new RuntimeException('Action handler not found.');
                        $submission = bbf_delivery_refresh_action_metadata($submission, $config);
                        $result = (static function(string $__actionFile, array $config, array $action, array $submission,
                            array &$actionResponse) {
                            return include $__actionFile;
                        })($actionFile, $config, $action, $submission, $actionResponse);
                        $outcome = bbf_delivery_action_result($result, ($job['idempotent'] ?? false) === true);
                    }
                } else {
                    $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Unknown delivery job type');
                }
            }
        } catch (Throwable $error) {
            $retryable = (string)($job['type'] ?? '') !== 'action' || ($job['idempotent'] ?? false) === true;
            $outcome = bbf_delivery_result(false, 'failed',
                (string)($job['type'] ?? '') === 'action' ? 'action' : 'delivery', 0, $retryable, 'Delivery adapter failed');
            $outcome['action_result'] = ['status' => 'error', 'detail' => [
                'error' => substr($error->getMessage(), 0, 300), 'exception' => get_class($error),
            ]];
        }
    }

    if (!is_array($outcome)) {
        $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Invalid delivery outcome');
    }
    if ((string)($job['type'] ?? '') === 'action' && ($job['idempotent'] ?? false) !== true
        && !($outcome['ok'] ?? false)) {
        $outcome['retryable'] = false;
    }
    return $outcome;
}

/** Return a redacted, explicitly non-durable projection of synchronous delivery results. */
function bbf_delivery_inline_status(array $results): array {
    $jobs = [];
    $succeeded = true;
    foreach ($results as $result) {
        $job = is_array($result['job'] ?? null) ? $result['job'] : [];
        $outcome = is_array($result['outcome'] ?? null)
            ? $result['outcome'] : bbf_delivery_result(false, 'failed', 'delivery');
        $state = ($outcome['ok'] ?? false) === true ? 'succeeded' : (string)($outcome['state'] ?? 'failed');
        if (!in_array($state, ['succeeded', 'failed', 'ambiguous'], true)) $state = 'failed';
        if ($state !== 'succeeded') $succeeded = false;
        $outcome['at'] = time();
        $projection = bbf_outbox_result_projection($outcome, $state);
        if (is_array($projection)) $projection['retryable'] = false;
        $jobs[] = [
            'key' => preg_match('/\A[a-zA-Z0-9._:-]+\z/', (string)($job['key'] ?? ''))
                ? (string)$job['key'] : 'job',
            'type' => preg_match('/\A[a-z0-9._:-]+\z/', (string)($job['type'] ?? ''))
                ? (string)$job['type'] : 'action',
            'state' => $state,
            'attempts' => 1,
            'last_result' => $projection,
            'can_retry' => false,
            'requires_confirmation' => false,
        ];
    }
    return [
        'ok' => $succeeded,
        'state' => $results === [] ? 'none' : ($succeeded ? 'succeeded' : 'attention_required'),
        'settled' => $succeeded,
        'durable' => false,
        'retry_available' => false,
        'note' => 'No submission or retry record was stored; delivery cannot be administered from the viewer.',
        'jobs' => $jobs,
    ];
}

/** Mark every newly initialized job terminal when submission storage fails before effects begin. */
function bbf_delivery_abort_for_storage(string $path, array $jobs, array $config): array {
    foreach ($jobs as $job) {
        $key = (string)($job['key'] ?? '');
        if ($key === '') continue;
        $claim = bbf_outbox_claim($path, $key);
        if (!($claim['ok'] ?? false)) continue;
        $outcome = bbf_delivery_result(false, 'failed', 'storage', 0, false);
        $outcome['safe_message'] = 'Submission storage failed before delivery; no side effect was attempted.';
        bbf_outbox_complete($path, $key, (string)$claim['token'], $outcome, null,
            (int)($config['delivery']['retry_delay'] ?? 60));
    }
    return bbf_outbox_status($path);
}

/** Claim and execute one persisted immutable delivery job. */
function bbf_delivery_run_job(string $path, string $jobKey, array $config, array &$actionResponse = [], bool $alreadyRetried = false, bool $firstAttemptOnly = false): array {
    // The caller may already have moved a failed/ambiguous job to pending. The runner never retries state itself.
    // A manual retry has the admin watching the result, so only automatic attempts raise an alert.
    $claim = bbf_outbox_claim($path, $jobKey, null, $firstAttemptOnly);
    if (!($claim['ok'] ?? false)) {
        $reason = (string)($claim['reason'] ?? 'unavailable');
        return [
            'ok' => $reason === 'succeeded',
            'executed' => false,
            'reason' => $reason,
            'job' => is_array($claim['job'] ?? null) ? $claim['job'] : null,
        ];
    }

    $job = is_array($claim['job'] ?? null) ? $claim['job'] : []; if (in_array($claim['context']['storage'] ?? null, ['file', 'csv', 'sqlite', 'mysql'], true)) $config['storage'] = $claim['context']['storage'];
    $outcome = bbf_delivery_execute_job($job, $config, $actionResponse);
    $retryDelay = (int)($config['delivery']['retry_delay'] ?? 60);
    $completed = bbf_outbox_complete($path, $jobKey, (string)$claim['token'], $outcome, null, $retryDelay);
    [$formId, $submissionId] = array_pad(explode(':', (string)($claim['submission_key'] ?? ''), 2), 2, '');
    if (empty($outcome['ok']) && !$alreadyRetried) {
        bbf_alert_delivery_failure($config, $formId, $submissionId, bbf_alert_job_label($job), $outcome,
            (string)($completed['job']['state'] ?? 'failed'));
    }
    // Best-effort projection only AFTER delivery settlement. Never feed metadata failure into retry state.
    try {
        $result = array_key_exists('action_result', $outcome) ? $outcome['action_result'] : [
            'status' => !empty($outcome['ok']) ? 'ok' : 'error',
            'detail' => ['stage' => $outcome['stage'] ?? 'delivery', 'code' => $outcome['code'] ?? 0,
                'error' => !empty($outcome['ok']) ? null : bbf_outbox_fallback_message(
                    (string)($outcome['state'] ?? 'failed'), (string)($outcome['stage'] ?? 'delivery'), (int)($outcome['code'] ?? 0))],
        ];
        if (is_array($result)) {
            $actionName = ($job['type'] ?? '') === 'action' ? (string)($job['target'] ?? 'action')
                : (in_array($job['type'] ?? '', ['email', 'smtp'], true) ? 'email' : (string)($job['type'] ?? 'delivery'));
            if (!bbfRecordActionResult($submissionId, $formId, $actionName, $result['status'], (array)$result['detail'], $config)) {
                error_log('BareBonesForms: Action metadata not recorded; delivery will not be replayed for this failure.');
            }
        }
    } catch (Throwable $metadataError) {
        error_log('BareBonesForms: Action metadata projection failed; delivery state is unchanged.');
    }
    if (!($completed['ok'] ?? false)) {
        return ['ok' => false, 'executed' => true, 'reason' => 'completion_' . (string)($completed['reason'] ?? 'failed')];
    }
    return [
        'ok' => ($completed['job']['state'] ?? null) === 'succeeded',
        'executed' => true,
        'reason' => (string)($completed['job']['state'] ?? 'failed'),
        'job' => $completed['job'],
    ];
}

/**
 * Cron worker: run every delivery job whose automatic retry is due (failed with a retryable
 * result, attempts left, next_retry reached). Ambiguous and terminal jobs stay manual.
 * Ledgers touched in the last $quietSeconds are skipped so a submit still in flight owns its jobs.
 * Every failed attempt rewrites its ledger and retries are at most a day apart, so ledgers untouched for
 * $horizonSeconds have no automatic retry left: they are not retried (the viewer can still retry them manually).
 * skipped_old counts only those that still hold an undelivered job; whether an old ledger is fully delivered
 * is remembered by mtime in .delivery/.old-ledgers.json, so each old ledger is read once, not on every run.
 */
function bbf_delivery_retry_due(array $config, int $quietSeconds = 120, ?int $now = null, int $horizonSeconds = 7 * 86400): array {
    $now ??= time();
    $report = ['ok' => true, 'checked' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped_in_flight' => 0, 'skipped_old' => 0];
    $root = rtrim((string)($config['submissions_dir'] ?? __DIR__ . '/submissions'), '/\\') . '/.delivery';
    $cacheFile = $root . '/.old-ledgers.json';
    $cache = is_file($cacheFile) ? json_decode((string)@file_get_contents($cacheFile), true) : [];
    if (!is_array($cache)) $cache = [];
    $oldSeen = [];
    foreach (glob($root . '/*/*.json') ?: [] as $path) {
        $mtime = (int)@filemtime($path);
        if ($mtime > $now - $quietSeconds) { $report['skipped_in_flight']++; continue; }
        if ($mtime < $now - $horizonSeconds) {
            $key = basename(dirname($path)) . '/' . basename($path);
            $entry = $cache[$key] ?? null;
            if (!is_array($entry) || ($entry[0] ?? null) !== $mtime || !is_bool($entry[1] ?? null)) {
                $read = bbf_outbox_read($path);
                $ledger = $read['ledger'] ?? null;
                if (!($read['ok'] ?? false) || !is_array($ledger['jobs'] ?? null)) {
                    // Unreadable (locked, damaged, half-written): it may still hold an undelivered job. Count it and
                    // read it again next run; caching it would call it delivered forever.
                    $report['skipped_old']++;
                    continue;
                }
                $open = ($ledger['deleted'] ?? false) !== true
                    && array_filter($ledger['jobs'], static fn($job) => !is_array($job) || ($job['state'] ?? '') !== 'succeeded') !== [];
                $entry = [$mtime, $open];
            }
            $oldSeen[$key] = $entry;
            if ($entry[1]) $report['skipped_old']++;
            continue;
        }
        $formId = basename(dirname($path));
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) continue;
        $read = bbf_outbox_read($path);
        $ledger = $read['ledger'] ?? null;
        if (!($read['ok'] ?? false) || !is_array($ledger['jobs'] ?? null) || ($ledger['deleted'] ?? false) === true) continue;
        $report['checked']++;
        try {
            $deliveryConfig = bbf_effective_storage_config($config, $formId);
        } catch (Throwable $error) {
            $report['ok'] = false;
            continue;
        }
        foreach ($ledger['jobs'] as $key => $job) {
            if (!is_array($job) || ($job['state'] ?? '') !== 'failed' || ($job['last_result']['retryable'] ?? false) !== true
                || (int)($job['attempts'] ?? 0) >= (int)($job['max_attempts'] ?? 1) || (int)($job['next_retry'] ?? 0) > $now) continue;
            $actionResponse = [];
            $run = bbf_delivery_run_job($path, (string)$key, $deliveryConfig, $actionResponse);
            if (!($run['executed'] ?? false)) continue;
            $report['attempted']++;
            $report[($run['ok'] ?? false) ? 'succeeded' : 'failed']++;
        }
    }
    if ($oldSeen != $cache && is_dir($root)) {
        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($oldSeen, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $cacheFile)) @unlink($tmp);
    }
    if ($report['skipped_old'] > 0) {
        $report['note'] = $report['skipped_old'] . ' delivery record(s) older than ' . intdiv($horizonSeconds, 86400)
            . ' days still have an undelivered delivery and no automatic retry left. Retry them by hand in the viewer.';
    }
    return $report;
}

// ─── Trusted payment pricing ─────────────────────────────────────

function bbfPaymentFieldOptions(array $field): array {
    $values = [];
    foreach ($field['options'] ?? [] as $option) {
        $values[] = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
    }
    return $values;
}

function bbfPaymentCurrencyMinorUnits(string $currency): int {
    return match ($currency) {
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' => 0,
        'bhd', 'jod', 'kwd', 'omr', 'tnd' => 3,
        'aed', 'afn', 'all', 'amd', 'ang', 'aoa', 'ars', 'aud', 'awg', 'azn', 'bam', 'bbd', 'bdt', 'bmd', 'bnd',
        'bob', 'brl', 'bsd', 'bwp', 'byn', 'bzd', 'cad', 'cdf', 'chf', 'cny', 'cop', 'crc', 'cve', 'czk', 'dkk',
        'dop', 'dzd', 'egp', 'etb', 'eur', 'fjd', 'fkp', 'gbp', 'gel', 'gip', 'gmd', 'gtq', 'gyd', 'hkd', 'hnl',
        'htg', 'huf', 'idr', 'ils', 'inr', 'isk', 'jmd', 'kes', 'kgs', 'khr', 'kyd', 'kzt', 'lak', 'lbp', 'lkr',
        'lrd', 'lsl', 'mad', 'mdl', 'mkd', 'mmk', 'mnt', 'mop', 'mur', 'mvr', 'mwk', 'mxn', 'myr', 'mzn', 'nad',
        'ngn', 'nio', 'nok', 'npr', 'nzd', 'pab', 'pen', 'pgk', 'php', 'pkr', 'pln', 'qar', 'ron', 'rsd', 'rub',
        'sar', 'sbd', 'scr', 'sek', 'sgd', 'shp', 'sle', 'sos', 'srd', 'std', 'szl', 'thb', 'tjs', 'top', 'try',
        'ttd', 'twd', 'tzs', 'uah', 'ugx', 'usd', 'uyu', 'uzs', 'wst', 'xcd', 'xcg', 'yer', 'zar', 'zmw' => 2,
        default => throw new InvalidArgumentException('Unsupported payment currency.'),
    };
}

function bbfPaymentValidateChargeAmount(string $currency, int $amountMinor): void {
    if (in_array($currency, ['isk', 'ugx'], true) && $amountMinor % 100 !== 0) {
        throw new InvalidArgumentException('Payment currency does not support fractional charge amounts.');
    }
}

function bbfValidatePaymentDefinition(array $payment, array $fields): array {
    $errors = [];
    $prefix = 'on_submit.payment';
    $fieldMap = [];
    foreach (flattenFields($fields) as $field) {
        if (isset($field['name'])) $fieldMap[$field['name']] = $field;
    }
    $mode = $payment['mode'] ?? null;
    if (($payment['provider'] ?? 'stripe') !== 'stripe') $errors[] = "$prefix.provider: Only stripe is supported.";
    if (!in_array($mode, ['fixed', 'catalog', 'donation'], true)) {
        $errors[] = "$prefix.mode: Expected fixed, catalog or donation.";
    }
    if (!isset($payment['pricing_version']) || (!is_string($payment['pricing_version']) && !is_int($payment['pricing_version']))
        || (string)$payment['pricing_version'] === '') {
        $errors[] = "$prefix.pricing_version: A non-empty string or integer is required.";
    }
    $currency = $payment['currency'] ?? null;
    if (!is_string($currency) || !preg_match('/\A[a-z]{3}\z/', $currency)) {
        $errors[] = "$prefix.currency: Expected a lowercase three-letter currency code.";
    } else {
        try {
            $currencyMinorUnits = bbfPaymentCurrencyMinorUnits($currency);
        } catch (InvalidArgumentException $error) {
            $currencyMinorUnits = null;
            $errors[] = "$prefix.currency: Unsupported payment currency.";
        }
        if (array_key_exists('minor_units', $payment)
            && (!is_int($payment['minor_units']) || $payment['minor_units'] !== $currencyMinorUnits)) {
            $errors[] = "$prefix.minor_units: Must match the currency exponent.";
        }
    }
    if ($mode !== 'donation' && array_key_exists('amount_field', $payment)) {
        $errors[] = "$prefix.amount_field: Allowed only in donation mode.";
    }
    if (array_key_exists('amount', $payment)) {
        $errors[] = "$prefix.amount: Decimal configured amounts are unsupported; use integer minor units.";
    }

    if ($mode === 'fixed') {
        if (!is_int($payment['amount_minor'] ?? null) || $payment['amount_minor'] <= 0) {
            $errors[] = "$prefix.amount_minor: A positive integer is required in fixed mode.";
        } elseif (in_array($currency, ['isk', 'ugx'], true) && $payment['amount_minor'] % 100 !== 0) {
            $errors[] = "$prefix.amount_minor: This currency requires a whole-unit charge amount.";
        }
        if (isset($payment['catalog']) || isset($payment['min_amount_minor']) || isset($payment['max_amount_minor'])) {
            $errors[] = "$prefix: Fixed mode cannot contain catalog or donation bounds.";
        }
    } elseif ($mode === 'donation') {
        $amountField = $payment['amount_field'] ?? null;
        if (!is_string($amountField) || !isset($fieldMap[$amountField])) {
            $errors[] = "$prefix.amount_field: Must reference a defined form field.";
        }
        $minorUnits = $payment['minor_units'] ?? null;
        if (!is_int($minorUnits) || $minorUnits < 0 || $minorUnits > 3) {
            $errors[] = "$prefix.minor_units: Expected an integer from 0 through 3.";
        }
        $minimum = $payment['min_amount_minor'] ?? null;
        $maximum = $payment['max_amount_minor'] ?? null;
        if (!is_int($minimum) || $minimum <= 0) $errors[] = "$prefix.min_amount_minor: A positive integer is required.";
        if (!is_int($maximum) || $maximum <= 0) $errors[] = "$prefix.max_amount_minor: A positive integer is required.";
        if (is_int($minimum) && is_int($maximum) && $minimum > $maximum) {
            $errors[] = "$prefix: Donation minimum cannot exceed maximum.";
        }
        if (isset($payment['amount_minor']) || isset($payment['catalog'])) {
            $errors[] = "$prefix: Donation mode cannot contain fixed or catalog pricing.";
        }
    } elseif ($mode === 'catalog') {
        if (isset($payment['amount_minor']) || isset($payment['min_amount_minor']) || isset($payment['max_amount_minor'])) {
            $errors[] = "$prefix: Catalog mode cannot contain fixed or donation pricing.";
        }
        $catalog = $payment['catalog'] ?? null;
        if (!is_array($catalog)) {
            $errors[] = "$prefix.catalog: An object is required.";
            return $errors;
        }
        $productField = $catalog['product_field'] ?? null;
        if (!is_string($productField) || !isset($fieldMap[$productField])) {
            $errors[] = "$prefix.catalog.product_field: Must reference a defined form field.";
        }
        $products = $catalog['products'] ?? null;
        if (!is_array($products) || !$products) {
            $errors[] = "$prefix.catalog.products: At least one product is required.";
            return $errors;
        }
        $productValues = isset($fieldMap[$productField]) ? bbfPaymentFieldOptions($fieldMap[$productField]) : [];
        foreach ($products as $productValue => $product) {
            $path = "$prefix.catalog.products.$productValue";
            if (!is_string($productValue) || $productValue === '' || !is_array($product)) {
                $errors[] = "$path: Invalid product definition.";
                continue;
            }
            if ($productValues && !in_array($productValue, $productValues, true)) $errors[] = "$path: Product is not a form option.";
            if (isset($product['active']) && !is_bool($product['active'])) $errors[] = "$path.active: Expected boolean.";
            $quantityField = $product['quantity_field'] ?? null;
            if (!is_string($quantityField) || !isset($fieldMap[$quantityField])) $errors[] = "$path.quantity_field: Must reference a defined form field.";
            foreach (['min_quantity', 'max_quantity', 'unit_amount_minor'] as $integerKey) {
                if (!is_int($product[$integerKey] ?? null) || $product[$integerKey] <= 0) $errors[] = "$path.$integerKey: A positive integer is required.";
            }
            if (is_int($product['min_quantity'] ?? null) && is_int($product['max_quantity'] ?? null)
                && $product['min_quantity'] > $product['max_quantity']) $errors[] = "$path: Minimum quantity cannot exceed maximum.";
            if (!is_array($product['options'] ?? null) || !$product['options']) $errors[] = "$path.options: At least one configured option field is required.";
            foreach (($product['options'] ?? []) as $optionField => $selections) {
                $optionPath = "$path.options.$optionField";
                if (!is_string($optionField) || !isset($fieldMap[$optionField])) {
                    $errors[] = "$optionPath: Must reference a defined form field.";
                    continue;
                }
                if (!is_array($selections) || !$selections) {
                    $errors[] = "$optionPath: At least one selection is required.";
                    continue;
                }
                $allowed = bbfPaymentFieldOptions($fieldMap[$optionField]);
                foreach ($selections as $selection => $definition) {
                    $selectionPath = "$optionPath.$selection";
                    if (!in_array((string)$selection, $allowed, true)) $errors[] = "$selectionPath: Selection is not a form option.";
                    if (!is_array($definition)) { $errors[] = "$selectionPath: Invalid selection definition."; continue; }
                    if (isset($definition['active']) && !is_bool($definition['active'])) $errors[] = "$selectionPath.active: Expected boolean.";
                    if (!is_int($definition['multiplier_bps'] ?? null) || $definition['multiplier_bps'] <= 0
                        || $definition['multiplier_bps'] > 1000000) $errors[] = "$selectionPath.multiplier_bps: Expected an integer from 1 through 1000000.";
                }
            }
        }
        foreach (($catalog['discounts'] ?? []) as $index => $discount) {
            if (!is_array($discount) || !is_int($discount['min_quantity'] ?? null) || $discount['min_quantity'] <= 0
                || !is_int($discount['discount_bps'] ?? null) || $discount['discount_bps'] < 0 || $discount['discount_bps'] >= 10000) {
                $errors[] = "$prefix.catalog.discounts[$index]: Invalid quantity or basis-point discount.";
            }
        }
        foreach (($catalog['fees'] ?? []) as $index => $fee) {
            $feePath = "$prefix.catalog.fees[$index]";
            if (!is_array($fee) || !is_string($fee['name'] ?? null) || $fee['name'] === '') $errors[] = "$feePath.name: A name is required.";
            $feeFields = $fee['fields'] ?? null;
            if (!is_array($feeFields) || !$feeFields) { $errors[] = "$feePath.fields: At least one field is required."; continue; }
            foreach ($feeFields as $feeField) if (!is_string($feeField) || !isset($fieldMap[$feeField])) $errors[] = "$feePath.fields: Unknown form field.";
            if (!is_array($fee['amounts'] ?? null) || !$fee['amounts']) { $errors[] = "$feePath.amounts: At least one amount is required."; continue; }
            foreach ($fee['amounts'] as $combination => $definition) {
                if (!is_string($combination) || count(explode('|', $combination)) !== count($feeFields) || !is_array($definition)
                    || !is_int($definition['amount_minor'] ?? null) || $definition['amount_minor'] < 0) {
                    $errors[] = "$feePath.amounts: Invalid combination or minor-unit amount.";
                    continue;
                }
                if (isset($definition['active']) && !is_bool($definition['active'])) $errors[] = "$feePath.amounts.$combination.active: Expected boolean.";
                foreach (explode('|', $combination) as $position => $value) {
                    $feeField = $feeFields[$position] ?? '';
                    if ($value !== '' && isset($fieldMap[$feeField]) && !in_array($value, bbfPaymentFieldOptions($fieldMap[$feeField]), true)) {
                        $errors[] = "$feePath.amounts.$combination: Value is not a form option.";
                    }
                }
            }
        }
    }
    return $errors;
}

function bbfPaymentDecimalToMinor($value, int $minorUnits): int {
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Payment amount must be a plain decimal string.');
    $decimal = (string)$value;
    $pattern = $minorUnits === 0 ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A(?:0|[1-9][0-9]*)(?:\.([0-9]{1,' . $minorUnits . '}))?\z/';
    if (!preg_match($pattern, $decimal, $match)) throw new InvalidArgumentException('Payment amount has invalid decimal precision.');
    [$whole] = explode('.', $decimal, 2);
    $fraction = $minorUnits ? str_pad($match[1] ?? '', $minorUnits, '0') : '';
    $scale = 10 ** $minorUnits;
    if ((int)$whole > intdiv(PHP_INT_MAX - (int)($fraction === '' ? 0 : $fraction), $scale)) {
        throw new InvalidArgumentException('Payment amount is too large.');
    }
    return ((int)$whole * $scale) + (int)($fraction === '' ? 0 : $fraction);
}

function bbfPaymentFormatMinor(int $amountMinor, int $minorUnits): string {
    if ($minorUnits < 0 || $minorUnits > 3) throw new InvalidArgumentException('Invalid payment minor units.');
    return number_format($amountMinor / (10 ** $minorUnits), $minorUnits, '.', '');
}

function bbfPaymentGcd(int $left, int $right): int {
    while ($right !== 0) { $next = $left % $right; $left = $right; $right = $next; }
    return max(1, $left);
}

function bbfPaymentMultiplyFraction(int $numerator, int $denominator, int $factor, int $divisor): array {
    $gcd = bbfPaymentGcd($numerator, $divisor); $numerator = intdiv($numerator, $gcd); $divisor = intdiv($divisor, $gcd);
    $gcd = bbfPaymentGcd($factor, $denominator); $factor = intdiv($factor, $gcd); $denominator = intdiv($denominator, $gcd);
    if ($factor !== 0 && $numerator > intdiv(PHP_INT_MAX, $factor)) throw new InvalidArgumentException('Calculated payment amount is too large.');
    if ($divisor !== 0 && $denominator > intdiv(PHP_INT_MAX, $divisor)) throw new InvalidArgumentException('Calculated payment precision is too large.');
    $numerator *= $factor; $denominator *= $divisor;
    $gcd = bbfPaymentGcd($numerator, $denominator);
    return [intdiv($numerator, $gcd), intdiv($denominator, $gcd)];
}

function bbfPaymentRoundFraction(int $numerator, int $denominator): int {
    $whole = intdiv($numerator, $denominator);
    $remainder = $numerator % $denominator;
    return $remainder >= intdiv($denominator + 1, 2) ? $whole + 1 : $whole;
}

function bbfPaymentScalar(array $data, string $field): string {
    $value = $data[$field] ?? null;
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException("Invalid payment selection: $field.");
    return (string)$value;
}

function bbfResolvePaymentQuote(array $payment, array $data): array {
    $mode = $payment['mode'] ?? '';
    $currency = $payment['currency'] ?? '';
    $version = $payment['pricing_version'] ?? '';
    if (!in_array($mode, ['fixed', 'catalog', 'donation'], true) || !is_string($currency)
        || !preg_match('/\A[a-z]{3}\z/', $currency) || (string)$version === '') {
        throw new InvalidArgumentException('Invalid payment definition.');
    }
    if ($mode === 'fixed') {
        $amount = $payment['amount_minor'] ?? null;
        if (!is_int($amount) || $amount <= 0 || isset($payment['amount_field'])) throw new InvalidArgumentException('Invalid fixed payment definition.');
        $minorUnits = bbfPaymentCurrencyMinorUnits($currency);
        bbfPaymentValidateChargeAmount($currency, $amount);
        return ['amount_minor' => $amount, 'minor_units' => $minorUnits, 'currency' => $currency, 'mode' => $mode, 'version' => $version,
            'snapshot' => ['mode' => $mode, 'version' => $version, 'amount_minor' => $amount, 'minor_units' => $minorUnits, 'currency' => $currency]];
    }
    if ($mode === 'donation') {
        if (!isset($payment['amount_field']) || !is_string($payment['amount_field']) || !is_int($payment['minor_units'] ?? null)
            || $payment['minor_units'] !== bbfPaymentCurrencyMinorUnits($currency)
            || !is_int($payment['min_amount_minor'] ?? null) || !is_int($payment['max_amount_minor'] ?? null)) {
            throw new InvalidArgumentException('Invalid donation payment definition.');
        }
        $raw = $data[$payment['amount_field']] ?? null;
        $amount = bbfPaymentDecimalToMinor($raw, $payment['minor_units']);
        bbfPaymentValidateChargeAmount($currency, $amount);
        if ($amount < $payment['min_amount_minor'] || $amount > $payment['max_amount_minor']) {
            throw new InvalidArgumentException('Donation amount is outside the configured range.');
        }
        return ['amount_minor' => $amount, 'minor_units' => $payment['minor_units'], 'currency' => $currency, 'mode' => $mode, 'version' => $version,
            'snapshot' => ['mode' => $mode, 'version' => $version, 'amount_field' => $payment['amount_field'],
                'amount_minor' => $amount, 'minor_units' => $payment['minor_units'], 'currency' => $currency]];
    }

    if (isset($payment['amount_field'])) throw new InvalidArgumentException('Catalog payment cannot use a respondent amount field.');
    $catalog = $payment['catalog'] ?? null;
    if (!is_array($catalog) || !is_string($catalog['product_field'] ?? null) || !is_array($catalog['products'] ?? null)) {
        throw new InvalidArgumentException('Invalid catalog payment definition.');
    }
    $productValue = bbfPaymentScalar($data, $catalog['product_field']);
    $product = $catalog['products'][$productValue] ?? null;
    if (!is_array($product) || ($product['active'] ?? true) !== true) throw new InvalidArgumentException('Unknown or inactive payment product.');
    $quantityRaw = bbfPaymentScalar($data, (string)($product['quantity_field'] ?? ''));
    if (!preg_match('/\A[1-9][0-9]*\z/', $quantityRaw)) throw new InvalidArgumentException('Payment quantity must be a positive integer.');
    $quantity = (int)$quantityRaw;
    if ((string)$quantity !== ltrim($quantityRaw, '0') || $quantity < ($product['min_quantity'] ?? PHP_INT_MAX)
        || $quantity > ($product['max_quantity'] ?? -1) || !is_int($product['unit_amount_minor'] ?? null)
        || $product['unit_amount_minor'] <= 0) throw new InvalidArgumentException('Payment quantity is outside the configured range.');
    if ($product['unit_amount_minor'] > intdiv(PHP_INT_MAX, $quantity)) throw new InvalidArgumentException('Calculated payment amount is too large.');
    $numerator = $product['unit_amount_minor'] * $quantity;
    $denominator = 1;
    $selectedOptions = [];
    foreach (($product['options'] ?? []) as $field => $selections) {
        $selection = bbfPaymentScalar($data, (string)$field);
        $definition = is_array($selections) ? ($selections[$selection] ?? null) : null;
        if (!is_array($definition) || ($definition['active'] ?? true) !== true || !is_int($definition['multiplier_bps'] ?? null)
            || $definition['multiplier_bps'] <= 0) throw new InvalidArgumentException('Unknown or inactive payment option.');
        [$numerator, $denominator] = bbfPaymentMultiplyFraction($numerator, $denominator, $definition['multiplier_bps'], 10000);
        $selectedOptions[$field] = ['value' => $selection, 'multiplier_bps' => $definition['multiplier_bps']];
    }
    $discountBps = 0;
    $discountThreshold = 0;
    foreach (($catalog['discounts'] ?? []) as $discount) {
        if (is_array($discount) && is_int($discount['min_quantity'] ?? null) && is_int($discount['discount_bps'] ?? null)
            && $quantity >= $discount['min_quantity'] && $discount['min_quantity'] >= $discountThreshold) {
            $discountThreshold = $discount['min_quantity'];
            $discountBps = $discount['discount_bps'];
        }
    }
    if ($discountBps < 0 || $discountBps >= 10000) throw new InvalidArgumentException('Invalid catalog discount.');
    if ($discountBps > 0) [$numerator, $denominator] = bbfPaymentMultiplyFraction($numerator, $denominator, 10000 - $discountBps, 10000);
    $feesMinor = 0;
    $selectedFees = [];
    foreach (($catalog['fees'] ?? []) as $fee) {
        if (!is_array($fee) || !is_array($fee['fields'] ?? null) || !is_array($fee['amounts'] ?? null)) throw new InvalidArgumentException('Invalid catalog fee.');
        $values = [];
        foreach ($fee['fields'] as $field) {
            $value = $data[$field] ?? '';
            if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid catalog fee selection.');
            $values[] = (string)$value;
        }
        $combination = implode('|', $values);
        $definition = $fee['amounts'][$combination] ?? null;
        if (!is_array($definition) || ($definition['active'] ?? true) !== true || !is_int($definition['amount_minor'] ?? null)
            || $definition['amount_minor'] < 0 || $feesMinor > PHP_INT_MAX - $definition['amount_minor']) {
            throw new InvalidArgumentException('Unknown, inactive or invalid catalog fee selection.');
        }
        $feesMinor += $definition['amount_minor'];
        $selectedFees[] = ['name' => (string)($fee['name'] ?? ''), 'selection' => $combination, 'amount_minor' => $definition['amount_minor']];
    }
    if ($feesMinor > intdiv(PHP_INT_MAX - $numerator, max(1, $denominator))) throw new InvalidArgumentException('Calculated payment amount is too large.');
    $amount = bbfPaymentRoundFraction($numerator + ($feesMinor * $denominator), $denominator);
    if ($amount <= 0) throw new InvalidArgumentException('Calculated payment amount must be positive.');
    bbfPaymentValidateChargeAmount($currency, $amount);
    $minorUnits = bbfPaymentCurrencyMinorUnits($currency);
    $snapshot = ['mode' => $mode, 'version' => $version, 'currency' => $currency, 'minor_units' => $minorUnits, 'product' => $productValue,
        'quantity_field' => $product['quantity_field'], 'quantity' => $quantity, 'unit_amount_minor' => $product['unit_amount_minor'],
        'options' => $selectedOptions, 'discount_bps' => $discountBps, 'fees' => $selectedFees, 'amount_minor' => $amount];
    return ['amount_minor' => $amount, 'minor_units' => $minorUnits, 'currency' => $currency, 'mode' => $mode, 'version' => $version, 'snapshot' => $snapshot];
}

function bbfPaymentSessionMatches(array $submission, array $session, bool $requirePaid = true): bool {
    $meta = $submission['meta'] ?? null;
    if (!is_array($meta) || !in_array($meta['payment_status'] ?? null, ['pending', 'paid', 'failed'], true)) return false;
    $expectedAmount = $meta['payment_expected_amount_minor'] ?? null;
    $actualAmount = $session['amount_total'] ?? null;
    $expectedCurrency = $meta['payment_expected_currency'] ?? null;
    $actualCurrency = $session['currency'] ?? null;
    $expectedSession = $meta['payment_checkout_session_id'] ?? null;
    $actualSession = $session['id'] ?? null;
    if (!is_int($expectedAmount) || !is_int($actualAmount) || $expectedAmount !== $actualAmount
        || !is_string($expectedCurrency) || !is_string($actualCurrency) || $expectedCurrency !== strtolower($actualCurrency)
        || !is_string($expectedSession) || $expectedSession === '' || !is_string($actualSession) || !hash_equals($expectedSession, $actualSession)) return false;
    if ($requirePaid && ($session['payment_status'] ?? null) !== 'paid') return false;
    $metadata = $session['metadata'] ?? null;
    return is_array($metadata) && ($metadata['bbf_submission_id'] ?? null) === ($submission['id'] ?? null)
        && ($metadata['bbf_form_id'] ?? null) === ($submission['form'] ?? null);
}

// ─── Stripe ──────────────────────────────────────────────────────

function bbf_payment_merge_transition(array $meta, array $payment): array {
    $current = in_array($meta['payment_status'] ?? null, ['pending', 'failed', 'paid'], true)
        ? $meta['payment_status'] : 'pending';
    $requested = $payment['payment_status'] ?? null;
    if (!in_array($requested, ['pending', 'failed', 'paid'], true)) {
        throw new InvalidArgumentException('Invalid payment status transition.');
    }
    $effective = $current === 'paid' || $requested === 'paid'
        ? 'paid'
        : ($current === 'failed' || $requested === 'failed' ? 'failed' : 'pending');
    if ($effective === $current) return ['status' => $current, 'changed' => false, 'meta' => $meta];
    $payment['payment_status'] = $effective;
    return ['status' => $effective, 'changed' => true, 'meta' => array_replace($meta, $payment)];
}

/**
 * Update a submission's payment status.
 * Supports file and SQLite/MySQL backends. CSV is not supported (append-only).
 */
function updateSubmissionPaymentMetadata(string $submissionId, string $formId, array $payment, array $config, ?array $form = null): bool {
    try { bbf_storage_json($payment); $config = bbf_effective_storage_config($config, $formId, $form); } catch (Throwable $e) { return false; }
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $submissionId)) return false;
    $storage = $config['storage'];
    if ($storage === 'file') {
        $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $submissionId . '.json';
        return bbf_storage_update_payment_file($file, $submissionId, $formId, $payment);
    }
    if (!in_array($storage, ['sqlite', 'mysql'], true)) {
        error_log("BareBonesForms: Payment metadata update not supported for '$storage' backend");
        return false;
    }
    try {
        if ($storage === 'sqlite') {
            $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
            $pdo = new PDO("sqlite:$dbFile", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $where = 'form_id = ?';
        } else {
            $db = $config['mysql'];
            $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}";
            $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $where = 'BINARY form_id = BINARY ?';
        }
        $stmt = $pdo->prepare("SELECT meta FROM bbf_submissions WHERE id = ? AND $where");
        $stmt->execute([$submissionId, $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $meta = json_decode($row['meta'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meta)) return false;
        $meta = array_replace($meta, $payment);
        $stmt = $pdo->prepare("UPDATE bbf_submissions SET meta = ? WHERE id = ? AND $where");
        return $stmt->execute([bbf_storage_json($meta), $submissionId, $formId]) && $stmt->rowCount() === 1;
    } catch (PDOException | JsonException $e) {
        error_log("BareBonesForms: Payment metadata update error: " . $e->getMessage());
        return false;
    }
}

function updateSubmissionPayment(string $submissionId, string $formId, string $status, array $stripeData, array $config): bool {
    return (transitionSubmissionPayment($submissionId, $formId, $status, $stripeData, $config)['ok'] ?? false) === true;
}

/** Atomically update payment state and return the effective monotonic status. */
function transitionSubmissionPayment(string $submissionId, string $formId, string $status, array $stripeData, array $config,
    int $minorUnits = 2, ?array $form = null): array {
    $amountMinor = $stripeData['amount_total'] ?? 0;
    if ($minorUnits < 0 || $minorUnits > 3) return ['ok' => false, 'reason' => 'minor_units'];
    $payment = [
        'payment_status' => $status,
        'payment_id' => $stripeData['payment_intent'] ?? $stripeData['id'] ?? '',
        'payment_amount_minor' => $amountMinor,
        'payment_minor_units' => $minorUnits,
        'payment_amount' => is_int($amountMinor) ? $amountMinor / (10 ** $minorUnits) : 0,
        'payment_currency' => $stripeData['currency'] ?? '',
        'payment_time' => date('c'),
    ];
    try {
        bbf_storage_json($payment);
        $config = bbf_effective_storage_config($config, $formId, $form);
    } catch (Throwable $error) {
        return ['ok' => false, 'reason' => 'configuration'];
    }
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $submissionId)) return ['ok' => false, 'reason' => 'id'];
    $storage = $config['storage'];
    if ($storage === 'file') {
        $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $submissionId . '.json';
        return bbf_storage_transition_payment_file($file, $submissionId, $formId, $payment);
    }
    if (!in_array($storage, ['sqlite', 'mysql'], true)) {
        error_log("BareBonesForms: Payment transition not supported for '$storage' backend");
        return ['ok' => false, 'reason' => 'backend'];
    }

    $pdo = null;
    $transaction = false;
    try {
        if ($storage === 'sqlite') {
            $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
            $pdo = new PDO("sqlite:$dbFile", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('BEGIN IMMEDIATE');
            $transaction = true;
            $where = 'form_id = ?';
            $lockClause = '';
        } else {
            $db = $config['mysql'];
            $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}";
            $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bbf_submissions'")->fetchColumn();
            if (!is_string($engine) || strtoupper($engine) !== 'INNODB') {
                throw new RuntimeException('Payment transitions require an InnoDB submissions table.');
            }
            $pdo->beginTransaction();
            $transaction = true;
            $where = 'BINARY form_id = BINARY ?';
            $lockClause = ' FOR UPDATE';
        }
        $stmt = $pdo->prepare("SELECT meta FROM bbf_submissions WHERE id = ? AND $where$lockClause");
        $stmt->execute([$submissionId, $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Submission not found.');
        $meta = json_decode($row['meta'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meta)) throw new RuntimeException('Invalid payment metadata.');
        $transition = bbf_payment_merge_transition($meta, $payment);
        if ($transition['changed']) {
            $stmt = $pdo->prepare("UPDATE bbf_submissions SET meta = ? WHERE id = ? AND $where");
            if (!$stmt->execute([bbf_storage_json($transition['meta']), $submissionId, $formId])) {
                throw new RuntimeException('Payment update failed.');
            }
        }
        if ($storage === 'sqlite') $pdo->exec('COMMIT'); else $pdo->commit();
        $transaction = false;
        return ['ok' => true, 'status' => $transition['status'], 'changed' => $transition['changed']];
    } catch (Throwable $error) {
        if ($transaction && $pdo instanceof PDO) {
            try {
                if ($storage === 'sqlite') $pdo->exec('ROLLBACK'); elseif ($pdo->inTransaction()) $pdo->rollBack();
            } catch (Throwable $ignored) {}
        }
        error_log('BareBonesForms: Payment transition error: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'update'];
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

/** Fields that affect a server-authoritative payment quote are never respondent-draft eligible. */
function bbfDraftPaymentFields($payment): array {
    if (!is_array($payment)) return [];
    $fields = [];
    if (is_string($payment['amount_field'] ?? null)) $fields[$payment['amount_field']] = true;
    $catalog = $payment['catalog'] ?? null;
    if (!is_array($catalog)) return $fields;
    if (is_string($catalog['product_field'] ?? null)) $fields[$catalog['product_field']] = true;
    foreach (($catalog['products'] ?? []) as $product) {
        if (!is_array($product)) continue;
        if (is_string($product['quantity_field'] ?? null)) $fields[$product['quantity_field']] = true;
        foreach (array_keys(is_array($product['options'] ?? null) ? $product['options'] : []) as $name) {
            if (is_string($name)) $fields[$name] = true;
        }
    }
    foreach (($catalog['fees'] ?? []) as $fee) {
        foreach (is_array($fee['fields'] ?? null) ? $fee['fields'] : [] as $name) {
            if (is_string($name)) $fields[$name] = true;
        }
    }
    return $fields;
}

/** Definition errors for the standalone mode: the field rules (bbf_definition_errors) plus drafts and on_submit. */
function validateFormDefinition(array $form): array {
    $errors = bbf_definition_errors($form);
    if (empty($form['fields']) || !is_array($form['fields'])) return $errors;
    $fieldsToValidate = !empty($form['templates']) ? resolveTemplates($form['fields'], $form['templates']) : $form['fields'];

    if (isset($form['drafts'])) {
        $drafts = $form['drafts'];
        if (!is_array($drafts) || array_is_list($drafts)) {
            $errors[] = 'drafts: Expected an object.';
        } else {
            if (!is_bool($drafts['enabled'] ?? null)) $errors[] = 'drafts.enabled: Expected boolean.';
            if (isset($drafts['ttl_seconds']) && (!is_int($drafts['ttl_seconds'])
                || $drafts['ttl_seconds'] < 300 || $drafts['ttl_seconds'] > 2592000)) {
                $errors[] = 'drafts.ttl_seconds: Expected 300 through 2592000 seconds.';
            }
            $allowed = $drafts['fields'] ?? null;
            if (array_key_exists('fields', $drafts) && (!is_array($allowed) || !array_is_list($allowed))) {
                $errors[] = 'drafts.fields: Expected a list.';
            } elseif (($drafts['enabled'] ?? false) === true && (!is_array($allowed) || $allowed === [])) {
                $errors[] = 'drafts.fields: Enabled drafts require a non-empty field allowlist.';
            } elseif (is_array($allowed) && array_is_list($allowed)) {
                $fieldMap = [];
                foreach (flattenFields($fieldsToValidate) as $field) if (is_string($field['name'] ?? null)) $fieldMap[$field['name']] = $field;
                $paymentFields = bbfDraftPaymentFields($form['on_submit']['payment'] ?? null);
                $seen = [];
                foreach ($allowed as $index => $name) {
                    if (!is_string($name) || !isset($fieldMap[$name])) {
                        $errors[] = "drafts.fields[$index]: Unknown form field.";
                        continue;
                    }
                    if (isset($seen[$name])) $errors[] = "drafts.fields[$index]: Duplicate field.";
                    $seen[$name] = true;
                    $type = $fieldMap[$name]['type'] ?? 'text';
                    if (in_array($type, ['password', 'hidden', 'section', 'page_break', 'group', 'file'], true)
                        || !empty($fieldMap[$name]['sensitive']) || isset($paymentFields[$name])) {
                        $errors[] = "drafts.fields[$index]: Sensitive or payment field cannot be persisted.";
                    }
                }
            }
        }
    }

    if (isset($form['on_submit'])) {
        $os = $form['on_submit'];
        if (isset($os['confirm_email']) && empty($os['confirm_email']['to'])) {
            $errors[] = 'on_submit.confirm_email: Missing required property: to.';
        }
        if (isset($os['notify']) && empty($os['notify']['to'])) {
            $errors[] = 'on_submit.notify: Missing required property: to.';
        }
        if (isset($os['payment'])) {
            if (!is_array($os['payment'])) $errors[] = 'on_submit.payment: Expected an object.';
            else $errors = array_merge($errors, bbfValidatePaymentDefinition($os['payment'], $fieldsToValidate));
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

// ─── Error Notifications ────────────────────────────────────────

/** Kept for existing callers: records an incident; the email is sent later by bbf_alerts.php. */
function bbfNotifyError(string $formId, string $context, string $detail, array $config): void {
    bbf_alert_record($config, $formId, $context, $detail);
}
