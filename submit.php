<?php
/**
 * BareBonesForms — Submission Handler
 *
 * Receives POST data, validates, stores, emails, webhooks.
 * That's it. Nothing else.
 *
 * Usage: POST to submit.php?form=kontakt
 */

// ─── Bootstrap ──────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // Never leak errors to browser — log only
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Missing config.php. Copy config.example.php to config.php and edit it.']);
    exit;
}

// Check required extensions
$missing = [];
if (!extension_loaded('json'))     $missing[] = 'json';
if (!extension_loaded('session'))  $missing[] = 'session';
if (!empty($missing)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Missing PHP extensions: ' . implode(', ', $missing)]);
    exit;
}

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

$config = require __DIR__ . '/config.php';

// ─── Daily security self-check (non-blocking, log-only) ─────────
$_bbfCheckFile = ($config['logs_dir'] ?? __DIR__ . '/logs') . '/.security_check';
if (!file_exists($_bbfCheckFile) || filemtime($_bbfCheckFile) < time() - 86400) {
    @file_put_contents($_bbfCheckFile, date('c'));
    $_bbfWarnings = [];
    if (file_exists(__DIR__ . '/check.php'))
        $_bbfWarnings[] = 'check.php still exists — it exposes server details. Delete it after verification.';
    if (!empty($config['sandbox']))
        $_bbfWarnings[] = 'Sandbox mode is ON. Disable for production: \'sandbox\' => false';
    if (empty($config['api_token']))
        $_bbfWarnings[] = 'api_token is empty — submissions API is unprotected.';
    if (empty($config['webhook_secret']) && array_filter(glob(($config['forms_dir'] ?? __DIR__ . '/forms') . '/*.json'), function($f) {
        $d = @json_decode(@file_get_contents($f), true);
        return !empty($d['on_submit']['webhooks']);
    }))
        $_bbfWarnings[] = 'webhook_secret is empty — webhook payloads will be unsigned.';
    if (!file_exists(__DIR__ . '/.htaccess'))
        $_bbfWarnings[] = '.htaccess is missing — config.php, submissions/, and logs/ may be web-accessible.';
    if (ini_get('display_errors') && strtolower(ini_get('display_errors')) !== 'off' && ini_get('display_errors') !== '0')
        $_bbfWarnings[] = 'PHP display_errors is ON — error messages may leak paths and credentials to browsers.';
    if ($_bbfWarnings) {
        error_log('BareBonesForms security check (' . count($_bbfWarnings) . ' warning(s)):');
        foreach ($_bbfWarnings as $_w) error_log('  ⚠ ' . $_w);
    }
    unset($_bbfWarnings, $_w);
}
unset($_bbfCheckFile);

// ─── Server-side i18n ────────────────────────────────────────────
$langCode = $config['lang'] ?? 'en';
$langFile = __DIR__ . '/lang/' . preg_replace('/[^a-z0-9-]/', '', $langCode) . '.php';
$messages = file_exists($langFile) ? require $langFile : [];
// Fallback to English if language file is missing or incomplete
if ($langCode !== 'en') {
    $enFile = __DIR__ . '/lang/en.php';
    $enMessages = file_exists($enFile) ? require $enFile : [];
    $messages = array_merge($enMessages, $messages);
}

function msg(string $key, array $params = []): string {
    global $messages;
    $text = $messages[$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

header('Content-Type: application/json; charset=utf-8');

// Start session only when needed (CSRF token or same-origin POST)
function ensureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        if (empty($_SESSION['bbf_secret'])) {
            $_SESSION['bbf_secret'] = bin2hex(random_bytes(32));
        }
    }
}

// ─── CORS ───────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($config['allowed_origins'])) {
    if (in_array($origin, $config['allowed_origins'], true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// ─── GET endpoints (CSRF token, form definition) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // CSRF token — needs session
    if (($config['csrf'] ?? true) && $action === 'csrf') {
        ensureSession();
        $csrfFormId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['form'] ?? '');
        if ($csrfFormId && !empty($_SESSION['bbf_secret'])) {
            echo json_encode(['csrf_token' => hash_hmac('sha256', $csrfFormId, $_SESSION['bbf_secret'])]);
            exit;
        }
    }

    // Form definition endpoint (same CORS as submit — enables cross-domain embedding)
    if ($action === 'definition') {
        $defFormId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['form'] ?? '');
        if (!$defFormId) respond(400, 'Missing ?form= parameter.');
        $defFile = $config['forms_dir'] . "/$defFormId.json";
        if (!file_exists($defFile)) respond(404, "Form '$defFormId' not found.");
        // Strip server-side config from response — client doesn't need
        // webhook URLs, email addresses, actions, or storage settings
        $def = json_decode(file_get_contents($defFile), true);
        if ($def !== null) {
            unset($def['on_submit'], $def['storage']);
        }
        echo json_encode($def, JSON_UNESCAPED_UNICODE);
        exit;
    }

    respond(405, 'Method not allowed.');
}

// ─── Only POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Method not allowed.');
}

// ─── Load form definition ───────────────────────────────────────
$formId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['form'] ?? '');
if (!$formId) {
    respond(400, 'Missing ?form= parameter.');
}

$formFile = $config['forms_dir'] . "/$formId.json";
if (!file_exists($formFile)) {
    respond(404, "Form '$formId' not found.");
}

$form = json_decode(file_get_contents($formFile), true);
if (!$form || empty($form['fields'])) {
    respond(500, 'Invalid form definition.');
}

// ─── Validate form schema ───────────────────────────────────────
$schemaErrors = validateFormDefinition($form);
if (!empty($schemaErrors)) {
    error_log('BareBonesForms schema errors in ' . $formId . ': ' . implode('; ', $schemaErrors));
    respond(500, 'Invalid form definition.', ['schema_errors' => $schemaErrors]);
}

// ─── Parse input ────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

// ─── Honeypot check ─────────────────────────────────────────────
$hpField = $config['honeypot_field'];
if (!empty($input[$hpField])) {
    // Bot filled the honeypot — pretend success
    respond(200, 'OK', ['submission_id' => 'bbf_' . bin2hex(random_bytes(8))]);
}
unset($input[$hpField]);

// ─── CSRF validation ────────────────────────────────────────────
$isCorsRequest = !empty($origin) && !empty($config['allowed_origins'])
    && in_array($origin, $config['allowed_origins'], true);
if (($config['csrf'] ?? true) && !$isCorsRequest) {
    ensureSession();
    $csrfToken = $input['_bbf_csrf'] ?? '';
    if (empty($_SESSION['bbf_secret'])
        || !hash_equals(hash_hmac('sha256', $formId, $_SESSION['bbf_secret']), $csrfToken)) {
        respond(403, 'Invalid or missing CSRF token.');
    }
}
unset($input['_bbf_csrf']);

// ─── Rate limiting (file-based, with locking) ───────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitOk = checkRateLimit($ip, $config['rate_limit'], $config['logs_dir']);
if (!$rateLimitOk) {
    respond(429, 'Too many submissions. Try again later.');
}

// ─── Resolve templates ─────────────────────────────────────────
if (!empty($form['templates'])) {
    $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
}

// ─── Flatten group fields ───────────────────────────────────────
$flatFields = flattenFields($form['fields']);

// ─── Validate ───────────────────────────────────────────────────
$errors = validate($flatFields, $input);

// ─── Sandbox mode ───────────────────────────────────────────────
$isSandbox = !empty($_GET['sandbox']) && ($config['sandbox'] ?? false);
if ($isSandbox) {
    $data = collectData($flatFields, $input);
    $submissionId = 'bbf_test_' . bin2hex(random_bytes(4));
    $timestamp = date('c');
    $onSubmit = $form['on_submit'] ?? [];

    $sandboxResult = [
        'status'  => empty($errors) ? 'ok' : 'error',
        'message' => empty($errors) ? 'Validation passed' : 'Validation failed',
        'sandbox' => true,
        'submission_id' => $submissionId,
        'validation' => [
            'passed' => empty($errors),
            'errors' => $errors,
            'field_count' => count($form['fields']),
        ],
        'data' => $data,
    ];

    // Preview what would happen on_submit
    $preview = [];
    $effectiveStorage = (!empty($form['storage']) && in_array($form['storage'], ['file', 'csv', 'sqlite', 'mysql'], true))
        ? $form['storage'] : $config['storage'];
    $preview['store'] = [
        'enabled' => ($onSubmit['store'] ?? true) !== false,
        'backend' => $effectiveStorage,
    ];

    if (!empty($onSubmit['confirm_email'])) {
        $ce = $onSubmit['confirm_email'];
        $preview['confirm_email'] = [
            'to'      => interpolate($ce['to'], $data),
            'subject' => interpolate($ce['subject'] ?? 'Thank you', $data),
            'template' => $ce['template'] ?? 'confirm.html',
            'body_preview' => renderTemplate(
                $config['templates_dir'] . '/' . basename($ce['template'] ?? 'confirm.html'),
                array_merge($data, ['_form' => $form['name'] ?? $formId, '_id' => $submissionId])
            ),
        ];
    }

    if (!empty($onSubmit['notify'])) {
        $n = $onSubmit['notify'];
        $preview['notify'] = [
            'to'      => is_array($n['to']) ? implode(', ', array_map(fn($t) => interpolate($t, $data), $n['to'])) : interpolate($n['to'], $data),
            'subject' => interpolate($n['subject'] ?? "New submission: $formId", $data),
            'template' => $n['template'] ?? 'notify.html',
            'body_preview' => renderTemplate(
                $config['templates_dir'] . '/' . basename($n['template'] ?? 'notify.html'),
                array_merge($data, [
                    '_form'    => $form['name'] ?? $formId,
                    '_id'      => $submissionId,
                    '_time'    => $timestamp,
                    '_summary' => buildSummary($form['fields'], $data),
                ])
            ),
        ];
    }

    if (!empty($onSubmit['webhooks'])) {
        $preview['webhooks'] = $onSubmit['webhooks'];
    }

    if (!empty($onSubmit['actions'])) {
        $preview['actions'] = array_map(fn($a) => [
            'type' => $a['type'] ?? '?',
            'file_exists' => file_exists(__DIR__ . '/actions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $a['type'] ?? '') . '.php'),
        ], $onSubmit['actions']);
    }

    if (!empty($onSubmit['redirect'])) {
        $preview['redirect'] = interpolate($onSubmit['redirect'], $data);
    }

    $sandboxResult['on_submit_preview'] = $preview;

    http_response_code(empty($errors) ? 200 : 422);
    echo json_encode($sandboxResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Cross-field validations ────────────────────────────────────
if (!empty($form['validations'])) {
    foreach ($form['validations'] as $rule) {
        $ruleFields = $rule['fields'] ?? [];
        $ruleType = $rule['type'] ?? '';
        $ruleMin = $rule['min'] ?? 1;
        $ruleMsg = $rule['message'] ?? 'Validation failed';

        if ($ruleType === 'min_sum') {
            $sum = 0;
            foreach ($ruleFields as $fn) {
                $sum += floatval($input[$fn] ?? 0);
            }
            if ($sum < $ruleMin) {
                $errors['_cross_' . implode('_', $ruleFields)] = $ruleMsg;
            }
        } elseif ($ruleType === 'min_filled') {
            $filled = 0;
            foreach ($ruleFields as $fn) {
                $v = $input[$fn] ?? '';
                if ($v !== '' && $v !== null && !(is_array($v) && empty($v))) $filled++;
            }
            if ($filled < $ruleMin) {
                $errors['_cross_' . implode('_', $ruleFields)] = $ruleMsg;
            }
        }
    }
}

// ─── Production: reject invalid submissions ─────────────────────
if (!empty($errors)) {
    respond(422, 'Validation failed.', ['errors' => $errors]);
}

// ─── Sanitize & collect data ────────────────────────────────────
$data = collectData($flatFields, $input);
$submissionId = 'bbf_' . bin2hex(random_bytes(8));
$timestamp = date('c');

$meta = ['submitted' => $timestamp];
if ($config['store_ip'] ?? true) {
    $meta['ip'] = $ip;
}
if ($config['store_user_agent'] ?? true) {
    $meta['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

$submission = [
    'id'        => $submissionId,
    'form'      => $formId,
    'data'      => $data,
    'meta'      => $meta,
];

// ─── Store ──────────────────────────────────────────────────────
$onSubmit = $form['on_submit'] ?? [];

if (($onSubmit['store'] ?? true) !== false) {
    $storeConfig = $config;
    if (!empty($form['storage']) && in_array($form['storage'], ['file', 'csv', 'sqlite', 'mysql'], true)) {
        $storeConfig['storage'] = $form['storage'];
    }
    if (!store($submission, $storeConfig, $form['fields'])) {
        respond(500, 'Submission could not be saved. Please try again later.');
    }
}

// ─── Resolve option labels for templates ────────────────────────
$templateData = $data;
foreach ($form['fields'] as $f) {
    if (!in_array($f['type'] ?? 'text', ['select', 'radio', 'checkbox'], true)) continue;
    $name = $f['name'] ?? '';
    if ($name === '' || !isset($data[$name])) continue;
    $val = $data[$name];
    $labels = [];
    foreach ($f['options'] ?? [] as $opt) {
        if (is_array($opt)) {
            $optVal = (string)($opt['value'] ?? '');
            $optLabel = $opt['label'] ?? $optVal;
        } else {
            $optVal = (string)$opt;
            $optLabel = $optVal;
        }
        if (is_array($val)) {
            if (in_array($optVal, $val, true)) $labels[] = $optLabel;
        } elseif ((string)$val === $optVal) {
            $labels[] = $optLabel;
        }
    }
    if (!empty($labels)) {
        $templateData[$name . '_label'] = implode(', ', $labels);
    }
}

// ─── Confirmation email to respondent ───────────────────────────
if (!empty($onSubmit['confirm_email'])) {
    $ce = $onSubmit['confirm_email'];
    $to = interpolate($ce['to'], $data);
    $subject = interpolate($ce['subject'] ?? 'Thank you', $data);
    $body = renderTemplate(
        $config['templates_dir'] . '/' . basename($ce['template'] ?? 'confirm.html'),
        array_merge($templateData, [
            '_form'    => $form['name'] ?? $formId,
            '_id'      => $submissionId,
            '_time'    => $timestamp,
            '_summary' => buildSummary($form['fields'], $data),
        ])
    );
    sendEmail($to, $subject, $body, $config['mail']);
}

// ─── Notification email to owner ────────────────────────────────
if (!empty($onSubmit['notify'])) {
    $n = $onSubmit['notify'];
    $toRaw = $n['to'];
    if (is_array($toRaw)) {
        $toRaw = implode(', ', array_map(fn($t) => interpolate($t, $data), $toRaw));
    } else {
        $toRaw = interpolate($toRaw, $data);
    }
    $to = $toRaw;
    $subject = interpolate($n['subject'] ?? "New submission: $formId", $data);

    $body = renderTemplate(
        $config['templates_dir'] . '/' . basename($n['template'] ?? 'notify.html'),
        array_merge($templateData, [
            '_form'     => $form['name'] ?? $formId,
            '_id'       => $submissionId,
            '_time'     => $timestamp,
            '_summary'  => buildSummary($form['fields'], $data),
        ])
    );
    sendEmail($to, $subject, $body, $config['mail']);
}

// ─── Webhooks (signed) ──────────────────────────────────────────
if (!empty($onSubmit['webhooks'])) {
    $webhookSecret = $config['webhook_secret'] ?? '';
    foreach ($onSubmit['webhooks'] as $url) {
        fireWebhook($url, $submission, $webhookSecret);
    }
}

// ─── Custom actions (trusted server-side extensions) ────────────
if (!empty($onSubmit['actions'])) {
    foreach ($onSubmit['actions'] as $action) {
        $actionFile = __DIR__ . '/actions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $action['type'] ?? '') . '.php';
        if (file_exists($actionFile)) {
            include $actionFile;
            // Action file receives: $action, $submission, $config
        }
    }
}

// ─── Success ────────────────────────────────────────────────────
$redirect = $onSubmit['redirect'] ?? null;
if ($redirect) {
    respond(200, 'OK', ['submission_id' => $submissionId, 'redirect' => interpolate($redirect, $data)]);
} else {
    respond(200, 'OK', ['submission_id' => $submissionId]);
}


// ═════════════════════════════════════════════════════════════════
// Functions
// ═════════════════════════════════════════════════════════════════

function respond(int $code, string $message, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['status' => $code < 400 ? 'ok' : 'error', 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
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

function collectData(array $fields, array $input): array {
    $data = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        // Skip non-data fields
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;

        $name = $field['name'];

        // Skip conditionally hidden fields — evaluate the condition server-side
        if (!empty($field['show_if']) && !evalCondition($field['show_if'], $input)) {
            continue;
        }

        $value = $input[$name] ?? '';
        if (is_string($value)) {
            $value = trim($value);
        }

        // Resolve "other" option: if value is __other__, use the _other text field
        if (!empty($field['other']) && $value === '__other__') {
            $otherValue = trim((string)($input[$name . '_other'] ?? ''));
            $value = $otherValue !== '' ? $otherValue : 'Other';
        }
        // For checkbox arrays with __other__
        if (is_array($value) && !empty($field['other'])) {
            $value = array_map(function($v) use ($input, $name) {
                if ($v === '__other__') {
                    $ov = trim((string)($input[$name . '_other'] ?? ''));
                    return $ov !== '' ? $ov : 'Other';
                }
                return $v;
            }, $value);
        }

        $data[$name] = $value;
    }
    return $data;
}

function store(array $submission, array $config, array $formFields = []): bool {
    switch ($config['storage']) {
        case 'mysql':
            return storeMysql($submission, $config['mysql']);
        case 'sqlite':
            return storeSqlite($submission, $config);
        case 'csv':
            return storeCsv($submission, $config['submissions_dir'], $formFields);
        default:
            return storeFile($submission, $config['submissions_dir']);
    }
}

function storeFile(array $submission, string $dir): bool {
    $formDir = $dir . '/' . $submission['form'];
    if (!is_dir($formDir) && !mkdir($formDir, 0755, true)) {
        error_log("BareBonesForms: Cannot create directory $formDir");
        return false;
    }
    $file = $formDir . '/' . $submission['id'] . '.json';
    $result = file_put_contents($file, json_encode($submission, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($result === false) {
        error_log("BareBonesForms: Failed to write $file");
        return false;
    }
    return true;
}

function storeMysql(array $submission, array $dbConfig): bool {
    try {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Auto-create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS bbf_submissions (
            id VARCHAR(30) PRIMARY KEY,
            form_id VARCHAR(100) NOT NULL,
            data JSON NOT NULL,
            meta JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form (form_id),
            INDEX idx_created (created_at)
        )");

        $stmt = $pdo->prepare("INSERT INTO bbf_submissions (id, form_id, data, meta) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $submission['id'],
            $submission['form'],
            json_encode($submission['data'], JSON_UNESCAPED_UNICODE),
            json_encode($submission['meta'], JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("BareBonesForms MySQL error: " . $e->getMessage());
        return false;
    }
}

function storeSqlite(array $submission, array $config): bool {
    try {
        $dbFile = $config['sqlite']['path'] ?? $config['submissions_dir'] . '/bbf.sqlite';
        $dir = dirname($dbFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $pdo = new PDO("sqlite:$dbFile", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("PRAGMA journal_mode=WAL");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bbf_submissions (
            id TEXT PRIMARY KEY,
            form_id TEXT NOT NULL,
            data TEXT NOT NULL,
            meta TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_form ON bbf_submissions(form_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_created ON bbf_submissions(created_at)");

        $stmt = $pdo->prepare("INSERT INTO bbf_submissions (id, form_id, data, meta, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $submission['id'],
            $submission['form'],
            json_encode($submission['data'], JSON_UNESCAPED_UNICODE),
            json_encode($submission['meta'], JSON_UNESCAPED_UNICODE),
            $submission['meta']['submitted'] ?? date('c'),
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("BareBonesForms SQLite error: " . $e->getMessage());
        return false;
    }
}

function csvSanitize(string $val): string {
    // Prevent CSV formula injection (Excel/Sheets interpret =, +, -, @, tab, CR as formulas)
    if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $val;
    }
    return $val;
}

function storeCsv(array $submission, string $dir, array $formFields): bool {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        error_log("BareBonesForms: Cannot create directory $dir");
        return false;
    }

    $file = $dir . '/' . $submission['form'] . '.csv';
    $fieldNames = array_column($formFields, 'name');

    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        error_log("BareBonesForms: Cannot open/lock $file");
        return false;
    }

    // Write headers if file is empty
    fseek($fp, 0, SEEK_END);
    if (ftell($fp) === 0) {
        $headers = array_merge(['_id', '_submitted', '_ip', '_user_agent'], $fieldNames);
        fputcsv($fp, $headers);
    }

    $row = [
        $submission['id'],
        $submission['meta']['submitted'] ?? '',
        $submission['meta']['ip'] ?? '',
        csvSanitize($submission['meta']['user_agent'] ?? ''),
    ];
    foreach ($fieldNames as $name) {
        $val = $submission['data'][$name] ?? '';
        $val = is_array($val) ? implode('; ', $val) : $val;
        $row[] = csvSanitize($val);
    }
    fputcsv($fp, $row);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function sendEmail(string $to, string $subject, string $body, array $mailConfig): void {
    if (empty($to)) return;

    // Sanitize headers to prevent injection
    $to = str_replace(["\r", "\n", "\0"], '', $to);
    $subject = str_replace(["\r", "\n", "\0"], '', $subject);

    // Validate all addresses (supports comma-separated list)
    $addresses = array_map('trim', explode(',', $to));
    $addresses = array_filter($addresses, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
    if (empty($addresses)) return;
    $to = implode(', ', $addresses);

    $from = $mailConfig['from_name'] . ' <' . $mailConfig['from_email'] . '>';
    $headers = [
        'From'         => $from,
        'Reply-To'     => $mailConfig['from_email'],
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
    $write("RCPT TO:<$to>");
    $read();
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
    }
}

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

function checkRateLimit(string $ip, int $maxPerMinute, string $logsDir): bool {
    if (!is_dir($logsDir)) mkdir($logsDir, 0755, true);
    $file = $logsDir . '/ratelimit_' . md5($ip) . '.json';
    $now = time();
    $window = 60;

    $fp = fopen($file, 'c+');
    if (!$fp) return true;

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }

    $content = stream_get_contents($fp);
    $entries = $content ? (json_decode($content, true) ?? []) : [];
    $entries = array_filter($entries, fn($t) => $t > ($now - $window));

    if (count($entries) >= $maxPerMinute) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $entries[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($entries)));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
