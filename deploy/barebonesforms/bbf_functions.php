<?php
/**
 * BareBonesForms — Shared Functions  v1.0.1
 *
 * Used by submit.php (form processing) and payment.php (webhook handler).
 * Not meant to be accessed directly via browser.
 */
defined('BBF_LOADED') || exit; require_once __DIR__ . '/bbf_storage.php'; require_once __DIR__ . '/bbf_delivery.php'; require_once __DIR__ . '/bbf_outbox.php';

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
            $pendingFields = $tplFields;
            while ($pendingFields) {
                $templateField = array_pop($pendingFields);
                $tplNames[$templateField['name']] = true;
                foreach ($templateField['fields'] ?? [] as $nestedField) {
                    $pendingFields[] = $nestedField;
                }
            }
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
        if (is_array($field['options'] ?? null)) {
            foreach ($field['options'] as &$option) {
                if (is_array($option) && !empty($option['show_if'])) {
                    $option['show_if'] = prefixCondition($option['show_if'], $prefix, $tplNames);
                }
            }
            unset($option);
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
// Propagates all ancestor show_if conditions so hidden groups skip their children server-side.
function flattenFields(array $fields, ?array $parentShowIf = null): array {
    $result = [];
    foreach ($fields as $f) {
        $localShowIf = !empty($f['show_if']) ? $f['show_if'] : null;
        if ($parentShowIf && $localShowIf) {
            $f['show_if'] = ['all' => [$parentShowIf, $localShowIf]];
        } elseif ($parentShowIf) {
            $f['show_if'] = $parentShowIf;
        }
        $result[] = $f;
        if (($f['type'] ?? '') === 'group' && empty($f['repeatable']) && !empty($f['fields'])) {
            $groupShowIf = !empty($f['show_if']) ? $f['show_if'] : null;
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
        'From' => $mailConfig['from_name'] . ' <' . $mailConfig['from_email'] . '>',
        'Reply-To' => $replyTo,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
    ];

    if (($mailConfig['method'] ?? 'mail') === 'smtp') {
        $result = sendSmtp($to, $subject, $body, $headers, $mailConfig);
    } else {
        $mailSubject = preg_match('/[^\x20-\x7E]/', $subject)
            ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
        $headerStr = '';
        foreach ($headers as $name => $value) $headerStr .= "$name: $value\r\n";
        $sent = @mail($to, $mailSubject, $body, $headerStr);
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
            // Only fixed internal variables may contain trusted HTML.
            $safe = isset($trustedHtmlVars[$key]) ? (string)$value : htmlspecialchars((string)$value);
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
        if (is_array($value)) $value = implode(', ', $value);
        if ($value === '') continue; // skip empty optional fields
        $lines[] = "<tr><td style='padding:4px 12px 4px 0;font-weight:bold;vertical-align:top'>"
            . htmlspecialchars($label) . "</td><td style='padding:4px 0'>"
            . htmlspecialchars($value) . "</td></tr>";
    }
    return implode('', $lines);
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
                if ($hasBindings || ($hasSubmission && $job['payload']['submission'] !== $submission)) {
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
    $templateVars = array_merge($templateData, [
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
            'subject' => interpolate((string)($email['subject'] ?? 'Thank you'), $data),
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
            'subject' => interpolate((string)($email['subject'] ?? "New submission: $formId"), $data),
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
                        (static function(string $__actionFile, array $config, array $action, array $submission,
                            array &$actionResponse): void {
                            include $__actionFile;
                        })($actionFile, $config, $action, $submission, $actionResponse);
                        $outcome = bbf_delivery_result(true, 'succeeded', 'action');
                    }
                } else {
                    $outcome = bbf_delivery_result(false, 'failed', 'delivery', 0, false, 'Unknown delivery job type');
                }
            }
        } catch (Throwable $error) {
            $retryable = (string)($job['type'] ?? '') !== 'action' || ($job['idempotent'] ?? false) === true;
            $outcome = bbf_delivery_result(false, 'failed',
                (string)($job['type'] ?? '') === 'action' ? 'action' : 'delivery', 0, $retryable, 'Delivery adapter failed');
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
function bbf_delivery_run_job(string $path, string $jobKey, array $config, array &$actionResponse = [], bool $alreadyRetried = false): array {
    // The caller may already have moved a failed/ambiguous job to pending. The runner never retries state itself.
    unset($alreadyRetried);
    $claim = bbf_outbox_claim($path, $jobKey);
    if (!($claim['ok'] ?? false)) {
        $reason = (string)($claim['reason'] ?? 'unavailable');
        return [
            'ok' => $reason === 'succeeded',
            'executed' => false,
            'reason' => $reason,
            'job' => is_array($claim['job'] ?? null) ? $claim['job'] : null,
        ];
    }

    $job = is_array($claim['job'] ?? null) ? $claim['job'] : [];
    $outcome = bbf_delivery_execute_job($job, $config, $actionResponse);
    $retryDelay = (int)($config['delivery']['retry_delay'] ?? 60);
    $completed = bbf_outbox_complete($path, $jobKey, (string)$claim['token'], $outcome, null, $retryDelay);
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

/**
 * Create a Stripe Checkout Session. Returns its trusted ID and URL, or null on failure.
 * No SDK — just a single HTTPS POST to the Stripe API. A callable transport allows
 * an installation to supply an explicit local adapter without changing pricing authority.
 */
function createStripeCheckout(string $secretKey, array $params, ?callable $transport = null): ?array {
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

    if ($transport !== null) {
        try {
            $data = $transport($postFields, $secretKey);
            return is_array($data) && is_string($data['id'] ?? null) && $data['id'] !== ''
                && is_string($data['url'] ?? null) && $data['url'] !== '' ? $data : null;
        } catch (Throwable $error) {
            error_log('BareBonesForms: Stripe transport failed: ' . $error->getMessage());
            return null;
        }
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
    if (is_string($data['id'] ?? null) && $data['id'] !== '' && is_string($data['url'] ?? null) && $data['url'] !== '') {
        return ['id' => $data['id'], 'url' => $data['url']];
    }

    error_log('BareBonesForms: Stripe API error: ' . ($data['error']['message'] ?? 'unknown'));
    return null;
}

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
function updateSubmissionPaymentMetadata(string $submissionId, string $formId, array $payment, array $config): bool {
    try { bbf_storage_json($payment); $config = bbf_effective_storage_config($config, $formId); } catch (Throwable $e) { return false; }
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

    if (isset($form['validations'])) {
        if (!is_array($form['validations']) || !array_is_list($form['validations'])) {
            $errors[] = 'validations: Expected a list.';
        } else {
            foreach ($form['validations'] as $index => $rule) {
                $prefix = "validations[$index]";
                if (!is_array($rule) || array_is_list($rule)) {
                    $errors[] = "$prefix: Expected an object.";
                    continue;
                }
                if (!in_array($rule['type'] ?? null, ['min_sum', 'min_filled'], true)) {
                    $errors[] = "$prefix.type: Expected min_sum or min_filled.";
                }
                $names = $rule['fields'] ?? null;
                if (!is_array($names) || !array_is_list($names) || $names === []) {
                    $errors[] = "$prefix.fields: Expected a non-empty list.";
                } else {
                    foreach ($names as $fieldIndex => $name) {
                        if (!is_string($name) || !in_array($name, $fieldNames, true)) {
                            $errors[] = "$prefix.fields[$fieldIndex]: Unknown form field.";
                        }
                    }
                }
                $minimum = $rule['min'] ?? 1;
                if (!is_int($minimum) && !is_float($minimum) || $minimum < 0) {
                    $errors[] = "$prefix.min: Expected a non-negative number.";
                }
                if (isset($rule['message']) && !is_string($rule['message'])) {
                    $errors[] = "$prefix.message: Expected string.";
                }
            }
        }
    }

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
                    if (in_array($type, ['password', 'hidden', 'section', 'page_break', 'group'], true)
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

function validateFieldList(array $fields, string $path, array &$errors, array &$fieldNames, bool $insideRepeatable = false): void {
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
        if (isset($field['sensitive']) && !is_bool($field['sensitive'])) {
            $errors[] = "$prefix.sensitive: Expected boolean.";
        }

        // Layout-only types — skip further validation
        if (in_array($type, ['section', 'page_break'], true)) continue;

        // Group — recurse into children and validate bounded repeatable rows.
        if ($type === 'group') {
            $repeatable = $field['repeatable'] ?? false;
            if (!is_bool($repeatable)) {
                $errors[] = "$prefix.repeatable: Expected boolean.";
                $repeatable = false;
            }
            if ($repeatable && $insideRepeatable) {
                $errors[] = "$prefix.repeatable: Nested repeatable groups are not supported.";
            }
            if ($repeatable) {
                $minItems = $field['min_items'] ?? 1;
                $maxItems = $field['max_items'] ?? 10;
                if (!is_int($minItems) || $minItems < 0 || $minItems > 100) {
                    $errors[] = "$prefix.min_items: Expected an integer from 0 through 100.";
                }
                if (!is_int($maxItems) || $maxItems < 1 || $maxItems > 100) {
                    $errors[] = "$prefix.max_items: Expected an integer from 1 through 100.";
                }
                if (is_int($minItems) && is_int($maxItems) && $minItems > $maxItems) {
                    $errors[] = "$prefix: min_items cannot exceed max_items.";
                }
            } elseif (array_key_exists('min_items', $field) || array_key_exists('max_items', $field)
                || array_key_exists('add_label', $field) || array_key_exists('remove_label', $field)) {
                $errors[] = "$prefix: Repeatable controls require repeatable: true.";
            }
            foreach (['add_label', 'remove_label'] as $labelKey) {
                if (isset($field[$labelKey]) && !is_string($field[$labelKey])) {
                    $errors[] = "$prefix.$labelKey: Expected string.";
                }
            }
            if ($repeatable && (empty($field['fields']) || !is_array($field['fields']))) {
                $errors[] = "$prefix: Repeatable group requires non-empty fields.";
            } elseif (!empty($field['fields']) && is_array($field['fields'])) {
                validateFieldList($field['fields'], "$prefix.fields", $errors, $fieldNames, $insideRepeatable || $repeatable);
            }
            continue;
        }
        if (array_key_exists('repeatable', $field) || array_key_exists('min_items', $field) || array_key_exists('max_items', $field)
            || array_key_exists('add_label', $field) || array_key_exists('remove_label', $field)) {
            $errors[] = "$prefix: Repeatable properties require type 'group'.";
        }

        if (in_array($type, ['select', 'radio', 'checkbox'], true) && empty($field['options']) && empty($field['options_from'])) {
            $errors[] = "$prefix: Type '$type' requires options or options_from.";
        }

        // Validate regex patterns
        if (!empty($field['pattern'])) {
            if (@preg_match('/' . $field['pattern'] . '/', '') === false) {
                $errors[] = "$prefix: Invalid regex pattern: {$field['pattern']}";
            }
        }
    }
}

function bbfRepeatableRowInput(array $fields, array $input, array $row): array {
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;
        $name = $field['name'] ?? '';
        if (!is_string($name) || $name === '') continue;
        unset($input[$name]);
        if (!empty($field['other'])) unset($input[$name . '_other']);
        if ($type === 'email' && !empty($field['confirm'])) unset($input[$name . '_confirm']);
    }
    return array_replace($input, $row);
}

function validateFieldShapes(array $fields, array $input): array {
    $errors = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            $rows = $input[$name] ?? [];
            $valid = is_array($rows) && array_is_list($rows);
            $childFields = flattenFields(is_array($field['fields'] ?? null) ? $field['fields'] : []);
            $allowedKeys = [];
            foreach ($childFields as $child) {
                $childType = $child['type'] ?? 'text';
                if (in_array($childType, ['section', 'page_break', 'group'], true)) continue;
                $childName = $child['name'] ?? '';
                if (!is_string($childName) || $childName === '') continue;
                $allowedKeys[$childName] = true;
                if (!empty($child['other'])) $allowedKeys[$childName . '_other'] = true;
                if ($childType === 'email' && !empty($child['confirm'])) $allowedKeys[$childName . '_confirm'] = true;
            }
            if ($valid) {
                foreach ($rows as $row) {
                    if (!is_array($row) || ($row !== [] && array_is_list($row))
                        || array_diff_key($row, $allowedKeys)
                        || validateFieldShapes($childFields, bbfRepeatableRowInput($childFields, $input, $row))) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) $errors[$name] = msg('invalidFormat', ['label' => $field['label'] ?? $name]);
            continue;
        }
        // The client sends a single selection as a scalar, repeated selections as an array.
        $multi = $type === 'checkbox' || ($type === 'select' && !empty($field['multiple']));
        $inputs = [$name => $multi];
        if (!empty($field['other'])) $inputs[$name . '_other'] = false;
        if ($type === 'email' && !empty($field['confirm'])) $inputs[$name . '_confirm'] = false;

        foreach ($inputs as $key => $allowsArray) {
            $raw = $input[$key] ?? '';
            $valid = is_scalar($raw);
            if (is_array($raw) && $allowsArray) {
                $valid = true;
                foreach ($raw as $item) {
                    if (!is_scalar($item)) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                $errors[$name] = msg('invalidFormat', ['label' => $field['label'] ?? $name]);
            }
        }
    }
    return $errors;
}

function validate(array $fields, array $input): array {
    // Check all data shapes before conditions, casts, or optional/hidden-field skips.
    $errors = validateFieldShapes($fields, $input);
    if ($errors) return $errors;
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';

        // Skip non-data field types; repeatable groups are structured data fields.
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name  = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            if (!empty($field['show_if']) && !evalCondition($field['show_if'], $input)) continue;
            $rows = $input[$name] ?? [];
            $count = count($rows);
            $minItems = $field['min_items'] ?? 1;
            $maxItems = $field['max_items'] ?? 10;
            $label = $field['label'] ?? $field['title'] ?? $name;
            if ($count < $minItems) {
                $errors[$name] = msg('repeatableMin', ['label' => $label, 'min' => $minItems]);
                continue;
            }
            if ($count > $maxItems) {
                $errors[$name] = msg('repeatableMax', ['label' => $label, 'max' => $maxItems]);
                continue;
            }
            $childFields = flattenFields($field['fields'] ?? []);
            foreach ($rows as $index => $row) {
                foreach (validate($childFields, bbfRepeatableRowInput($childFields, $input, $row)) as $childName => $message) {
                    $errors[$name . '.' . $index . '.' . $childName] = $message;
                }
            }
            continue;
        }

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
                    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $dateParts)
                        || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
                        $errors[$name] = msg('invalidFormat', ['label' => $label]);
                        break;
                    }
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
                if (is_array($opt) && !empty($opt['show_if']) && !evalCondition($opt['show_if'], $input)) continue;
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

function bbfCrossFieldFilled($value): bool {
    if (is_array($value)) {
        foreach ($value as $item) if (bbfCrossFieldFilled($item)) return true;
        return false;
    }
    return is_string($value) ? trim($value) !== '' : $value !== null;
}

function validateCrossFields(array $rules, array $data): array {
    $errors = [];
    foreach ($rules as $rule) {
        $fields = is_array($rule['fields'] ?? null) ? $rule['fields'] : [];
        $type = $rule['type'] ?? '';
        $minimum = $rule['min'] ?? 1;
        $valid = true;
        if ($type === 'min_sum') {
            $sum = 0.0;
            foreach ($fields as $name) {
                $value = $data[$name] ?? null;
                if (is_scalar($value) && is_numeric($value)) $sum += (float)$value;
            }
            $valid = $sum >= $minimum;
        } elseif ($type === 'min_filled') {
            $filled = 0;
            foreach ($fields as $name) {
                $value = $data[$name] ?? null;
                if (bbfCrossFieldFilled($value)) $filled++;
            }
            $valid = $filled >= $minimum;
        }
        if (!$valid) {
            $errors['_cross_' . implode('_', $fields)] = $rule['message'] ?? 'Validation failed';
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
