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
// Loaded before everything else so a fatal error in any later include still reaches the admin.
require_once __DIR__ . '/bbf_alerts.php';
register_shutdown_function('bbf_alert_fatal_guard');

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
// Query parameters are plain strings; ?form[]=x is a malformed request, not a server fault worth an alert.
foreach (['form', 'action', 'lang', 'field'] as $_bbfParam) {
    if (isset($_GET[$_bbfParam]) && !is_string($_GET[$_bbfParam])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
        exit;
    }
}
unset($_bbfParam);

require_once __DIR__ . '/bbf_functions.php';
require_once __DIR__ . '/bbf_versions.php';
require_once __DIR__ . '/bbf_drafts.php'; require_once __DIR__ . '/bbf_context.php';
require_once __DIR__ . '/bbf_read.php'; require_once __DIR__ . '/bbf_submit_tx.php';

require_once __DIR__ . '/bbf_auth.php'; $config = bbf_auth_load_config(__DIR__ . '/config.php');
bbf_alert_flush_if_due($config);


// A requested sandbox never falls back to real processing, even when disabled.
$isSandbox = array_key_exists('sandbox', $_GET);
if ($isSandbox) {
    bbf_auth_headers();
    if (empty($config['sandbox'])) bbf_auth_fail();
    $principal = bbf_authenticate($config);
    bbf_access_begin($config, $principal, 'sandbox_submit', bbf_auth_id($_GET['form'] ?? null), [], true, true);
}
// ─── Daily security self-check (non-blocking, log-only) ─────────
$_bbfCheckFile = ($config['logs_dir'] ?? __DIR__ . '/logs') . '/.security_check';
if (!$isSandbox && (!file_exists($_bbfCheckFile) || filemtime($_bbfCheckFile) < time() - 86400)) {
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
    if ((!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) && bbf_trusted_proxies($config) === [])
        $_bbfWarnings[] = 'Requests arrive through a proxy (X-Forwarded-For / CF-Connecting-IP) but trusted_proxies is empty: all visitors share the proxy address for rate limits and the sign-in limit.';
    if ($_bbfWarnings) {
        error_log('BareBonesForms security check (' . count($_bbfWarnings) . ' warning(s)):');
        foreach ($_bbfWarnings as $_w) error_log('  ⚠ ' . $_w);
    }
    unset($_bbfWarnings, $_w);
}
unset($_bbfCheckFile);

// ─── Server-side i18n ────────────────────────────────────────────
$langCode = $config['lang'] ?? 'en';
// bbf.js sends the form's language (?lang=); it wins when that server pack exists.
$requestLang = $_GET['lang'] ?? null;
if (is_string($requestLang) && preg_match('/\A[a-z]{2,3}(-[a-z]{2})?\z/D', $requestLang) && is_file(__DIR__ . "/lang/$requestLang.php")) {
    $langCode = $requestLang;
}
unset($requestLang);
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
function ensureSession(bool $create = true): void {
    static $loaded = false;
    if ($loaded || session_status() === PHP_SESSION_ACTIVE) return;
    // The respondent session holds only the CSRF secret: scripts never need it, so keep it HttpOnly.
    // Own cookie name limited to the installation folder: with strict session IDs a shared PHPSESSID on "/" would
    // replace another PHP application's session on the same domain and sign its users out.
    // On HTTPS (also behind a TLS-ending proxy listed in trusted_proxies) it is SameSite=None; Secure, so a form
    // embedded in an iframe on another site can be submitted (the CSRF token, not the cookie, stops a foreign page).
    $config = $GLOBALS['config'] ?? [];
    $https = bbf_request_https($config);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('BBFSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => bbf_cookie_path($config),
        'secure' => $https, 'httponly' => true, 'samesite' => $https ? 'None' : '']);
    if (!$create) {
        // Validate an existing session without emitting cookies; commit activity only after valid CSRF.
        $_SESSION = [];
        $id = $_COOKIE['BBFSID'] ?? '';
        if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9,-]{1,256}\z/D', $id)) return;
        if (ini_get('session.save_handler') !== 'files') {
            session_id($id);
            session_start(['use_cookies' => false, 'use_strict_mode' => false]);
            $loaded = true;
            return;
        }
        $parts = explode(';', session_save_path());
        $path = array_pop($parts) ?: sys_get_temp_dir();
        $depth = $parts ? (int)$parts[0] : 0;
        if ($depth > strlen($id)) return;
        for ($i = 0; $i < $depth; $i++) $path .= '/' . $id[$i];
        $sessionFile = @fopen($path . '/sess_' . $id, 'r+b');
        if (!$sessionFile || !flock($sessionFile, LOCK_EX)) { if (is_resource($sessionFile)) fclose($sessionFile); return; }
        // An existing-file handler prevents creation if GC won the race; the lock covers validation and renewal.
        session_set_save_handler(new class($sessionFile) implements SessionHandlerInterface {
            public function __construct(private $file) {}
            public function open(string $path, string $name): bool { return true; }
            public function close(): bool { flock($this->file, LOCK_UN); fclose($this->file); return true; }
            public function read(string $id): string|false { return stream_get_contents($this->file); }
            public function write(string $id, string $data): bool {
                return rewind($this->file) && fwrite($this->file, $data) === strlen($data)
                    && ftruncate($this->file, strlen($data)) && fflush($this->file);
            }
            public function destroy(string $id): bool { return false; }
            public function gc(int $max_lifetime): int|false { return 0; }
        });
        session_id($id);
        session_start(['use_cookies' => false, 'use_strict_mode' => false]);
        $loaded = true;
        return;
    }
    session_start();
    if (empty($_SESSION['bbf_secret'])) {
        $_SESSION['bbf_secret'] = bin2hex(random_bytes(32));
    }
    // Only the secret is needed; release the session lock now so slow SMTP/webhooks in this
    // request never block other tabs of the same visitor. $_SESSION stays readable.
    session_write_close();
    $loaded = true;
}

/** Read/validate/renew under the handler's lock, then release it before any slow processing. */
function bbf_submit_csrf_valid(string $formId, $token): bool {
    ensureSession(false);
    $secret = $_SESSION['bbf_secret'] ?? null;
    $valid = session_status() === PHP_SESSION_ACTIVE && is_string($secret) && $secret !== ''
        && is_string($token) && hash_equals(hash_hmac('sha256', $formId, $secret), $token);
    if (session_status() === PHP_SESSION_ACTIVE) {
        if ($valid) {
            // Force a handler write, including handlers without updateTimestamp/lazy-write support.
            $_SESSION['bbf_activity'] = microtime(true);
            session_write_close();
        } else session_abort(); // no writes, new secrets or replacement cookies for rejected CSRF
    }
    return $valid;
}

// ─── CORS ───────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($config['allowed_origins'])) {
    // The response differs per Origin, so shared caches must not reuse it across origins.
    header('Vary: Origin');
    if (in_array($origin, $config['allowed_origins'], true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-BBF-CSRF');
    http_response_code(204);
    exit;
}

// ─── GET endpoints (CSRF token, form definition) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? ''; if ($action === 'context') { echo json_encode(bbfClientConfiguration($config), JSON_UNESCAPED_UNICODE); exit; }

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
        if (!file_exists($defFile)) {
            bbf_submit_form_incident($config, $defFormId, true, 'The page asked for this form, but its definition file is missing.');
            respond(404, "Form '$defFormId' not found.");
        }
        // Strip server-side config from response — client doesn't need
        // webhook URLs, email addresses, actions, or storage settings
        $def = json_decode(file_get_contents($defFile), true);
        if (!is_array($def) || ($def['id'] ?? null) !== $defFormId) {
            bbf_submit_form_incident($config, $defFormId, false, 'The definition file is not valid JSON or its id does not match the file name; visitors cannot load the form.');
            respond(500, 'Invalid form definition.');
        }
        bbf_alert_form_seen($config, $defFormId);
        $def = bbfSystemDefinition($def, $config); $def['_bbf_client'] = bbfClientConfiguration($config); unset($def['on_submit'], $def['storage']);
        $def['fields'] = bbf_submit_client_file_fields($config, (array)($def['fields'] ?? []));
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

// ─── File uploads: one file per request, before any body parsing (design §4.2) ─
if (($_GET['action'] ?? '') === 'upload') bbf_submit_upload($config, $formId, $isSandbox);
if (($_GET['action'] ?? '') === 'upload_delete') bbf_submit_upload_delete($config, $formId, $isSandbox);

// ─── Parse input ────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = bbf_submit_json_body() ?? [];
} else {
    $input = $_POST;
}
if (!is_array($input)) $input = [];
// Storage, e-mail and JSON all need UTF-8; broken bytes are the client's error (422), not a server crash.
if (!bbfValidUtf8Deep($input)) respond(422, msg('invalidUtf8'));

// ─── Submit transaction: step 0 and step A (no definition, no CSRF, no session) ─
$rawInput = $input;
$submitKey = $input['_bbf_submit_key'] ?? null;
unset($input['_bbf_submit_key']);
if ($submitKey !== null && !bbf_tx_valid_key($submitKey)) respond(400, 'Invalid submit key.');
if (bbf_submit_duplicate_token($input)) respond(400, 'The same uploaded file is listed more than once.');
$txKey = $submitKey !== null ? hash('sha256', $submitKey) : null;
$isDraftAction = in_array($_GET['action'] ?? '', ['draft_save', 'draft_load', 'draft_delete'], true);
if (!$isSandbox && !$isDraftAction && $txKey !== null) bbf_submit_step_a($config, $formId, $txKey, $rawInput);

$formFile = $config['forms_dir'] . "/$formId.json";
if (!file_exists($formFile)) {
    bbf_submit_form_incident($config, $formId, true, 'A visitor submitted this form, but its definition file is missing; the submission was rejected.');
    respond(404, "Form '$formId' not found.");
}

$form = json_decode(file_get_contents($formFile), true);
if (!$form || ($form['id'] ?? null) !== $formId || empty($form['fields'])) {
    bbf_submit_form_incident($config, $formId, false, 'The definition file is not valid JSON, has no fields, or its id does not match; a submission was rejected.');
    respond(500, 'Invalid form definition.');
}

// ─── Validate form schema ───────────────────────────────────────
$schemaErrors = validateFormDefinition($form);
if (!empty($schemaErrors)) {
    bbf_submit_form_incident($config, $formId, false, 'Schema errors, a submission was rejected: ' . implode('; ', array_slice($schemaErrors, 0, 5)));
    respond(500, 'Invalid form definition.', ['schema_errors' => $schemaErrors]);
}
$form = bbfSystemDefinition($form, $config); $publishedDefinitionVersion = bbf_version_id($form);
$versionedForm = $form; // exactly the definition hashed into definition_version

// ─── Honeypot check ─────────────────────────────────────────────
$hpField = $config['honeypot_field'];
if (!empty($input[$hpField])) {
    // Bot filled the honeypot — pretend success
    respond(200, 'OK', ['submission_id' => 'bbf_' . bin2hex(random_bytes(8))]);
}
unset($input[$hpField]);

// ─── CSRF validation ────────────────────────────────────────────
// Skip public CSRF only for a valid nonempty smoke-token header (used by smoketest.php).
$_smokeToken = $_SERVER['HTTP_X_BBF_SMOKE_TOKEN'] ?? '';
$_smokeAuth  = bbf_smoke_token($config) !== null // an invalid-format smoke_token is no credential
               && is_string($_smokeToken) && $_smokeToken !== '' && hash_equals(bbf_smoke_token($config), $_smokeToken);
$isCorsRequest = !empty($origin) && !empty($config['allowed_origins'])
    && in_array($origin, $config['allowed_origins'], true);
if (!$isSandbox && ($config['csrf'] ?? true) && !$isCorsRequest && !$_smokeAuth) {
    $csrfToken = $input['_bbf_csrf'] ?? null;
    if (!bbf_submit_csrf_valid($formId, $csrfToken)) {
        respond(403, msg('csrfInvalid'));
    }
}
unset($input['_bbf_csrf']);

// A stale draft UI is a policy conflict, not expired CSRF; do not spend the submission budget.
if (!$isSandbox && $isDraftAction && bbf_draft_policy($form) === null)
    respond(409, msg('draftDisabled'), ['reason' => 'disabled']);

// ─── Rate limiting (file-based, with locking) ───────────────────
$ip = bbf_client_ip($config);
$rateLimitOk = $isSandbox || checkRateLimit($ip, $config['rate_limit'], $config['logs_dir']);
if (!$rateLimitOk) {
    respond(429, msg('tooManyRequests'));
}

// ─── Resolve templates ─────────────────────────────────────────
if (!empty($form['templates'])) {
    $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
}

// ─── Flatten group fields ───────────────────────────────────────
$flatFields = flattenFields($form['fields']); $input = bbfSystemInput($flatFields, $input);

// ─── Opt-in respondent drafts ───────────────────────────────────
$draftAction = $_GET['action'] ?? '';
if (!$isSandbox && in_array($draftAction, ['draft_save', 'draft_load', 'draft_delete'], true)) {
    // Disabled policy was rejected before rate limiting; only enabled draft operations reach here.
    bbf_draft_cleanup($config);
    $handle = is_string($input['_bbf_draft_handle'] ?? null) ? $input['_bbf_draft_handle'] : '';
    if ($draftAction === 'draft_save') {
        $result = bbf_draft_save($config, $form, $flatFields, $input, $handle, null, $ip);
    } elseif ($draftAction === 'draft_load') {
        $result = bbf_draft_load($config, $form, $handle);
    } else {
        $result = bbf_draft_delete($config, $form, $handle);
    }
    if ($result['ok'] ?? false) respond($draftAction === 'draft_save' && $handle === '' ? 201 : 200, 'OK', $result);
    $reason = $result['reason'] ?? 'storage';
    if ($reason === 'expired') respond(410, msg('draftExpired'), ['reason' => $reason]);
    if ($reason === 'not_found') respond(404, msg('draftNotFound'), ['reason' => $reason]);
    if ($reason === 'too_large') respond(413, msg('draftTooLarge'), ['reason' => $reason]);
    if ($reason === 'quota') respond(429, msg('draftQuota'), ['reason' => $reason] + (isset($result['retry_after']) ? ['retry_after' => $result['retry_after']] : []));
    respond(500, msg('uploadTemporary'), ['reason' => $reason]);
}

// ─── Validate and normalize once for sandbox and production ─────
$shapeErrors = validateFieldShapes($flatFields, $input);
$errors = validate($flatFields, $input);
$normalizedData = $shapeErrors ? [] : collectData($flatFields, $input);
if (!$shapeErrors) {
    $errors = array_replace($errors, validateCrossFields($form['validations'] ?? [], $normalizedData));
}

// ─── Sandbox mode ───────────────────────────────────────────────
// Authorization and management CSRF were checked before any public processing.
// Preview exits unconditionally; no storage, delivery, payment or action execution.
if ($isSandbox) {
    $data = $normalizedData;
    $submissionId = 'bbf_test_' . bin2hex(random_bytes(4));
    $fileFields = bbf_uploads_file_fields($flatFields);
    if (!$errors && $fileFields) {
        $sandboxPlan = bbf_uploads_plan($config, $formId, $submissionId, $fileFields, $data, true);
        if (!$sandboxPlan['ok']) $errors = $sandboxPlan['errors'];
    }
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
    // Same variables as real delivery, including option labels such as {{plan_label}}.
    $templateData = bbf_delivery_template_data($form, ['data' => $data]);
    $subjectVars = array_replace($templateData, ['_form' => $form['name'] ?? $formId, '_id' => $submissionId, '_time' => $timestamp]);
    $preview = [];
    $previewConfig = bbf_effective_storage_config($config, $formId, $form);
    $effectiveStorage = $previewConfig['storage'];
    $preview['store'] = [
        'enabled' => ($onSubmit['store'] ?? true) !== false,
        'backend' => $effectiveStorage,
    ];

    if (!empty($onSubmit['confirm_email'])) {
        $ce = $onSubmit['confirm_email'];
        $preview['confirm_email'] = [
            'to'      => interpolate($ce['to'], $data),
            'subject' => interpolate($ce['subject'] ?? 'Thank you', $subjectVars),
            'reply_to' => isset($ce['reply_to']) ? interpolate($ce['reply_to'], $data) : $config['mail']['from_email'],
            'template' => $ce['template'] ?? 'confirm.html',
            'body_preview' => renderTemplate(
                $config['templates_dir'] . '/' . basename($ce['template'] ?? 'confirm.html'),
                array_replace($templateData, ['_form' => $form['name'] ?? $formId, '_id' => $submissionId])
            ),
        ];
    }

    if (!empty($onSubmit['notify'])) {
        $n = $onSubmit['notify'];
        $preview['notify'] = [
            'to'      => ($_smokeAuth && !empty($config['smoke_email']))
                          ? ($config['smoke_notify'] ?? $config['smoke_email'] ?? '')
                          : (is_array($n['to']) ? implode(', ', array_map(fn($t) => interpolate($t, $data), $n['to'])) : interpolate($n['to'], $data)),
            'subject' => interpolate($n['subject'] ?? "New submission: $formId", $subjectVars),
            'reply_to' => isset($n['reply_to']) ? interpolate($n['reply_to'], $data) : $config['mail']['from_email'],
            'template' => $n['template'] ?? 'notify.html',
            'body_preview' => renderTemplate(
                $config['templates_dir'] . '/' . basename($n['template'] ?? 'notify.html'),
                array_replace($templateData, [
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
        $preview['redirect'] = bbf_redirect_url($onSubmit['redirect'], $data, ['_id' => $submissionId, '_form' => $form['name'] ?? $formId, '_time' => $timestamp]);
    }

    if (!empty($onSubmit['payment'])) {
        try {
            $quote = bbfResolvePaymentQuote($onSubmit['payment'], $data);
            $preview['payment'] = [
                'provider' => $onSubmit['payment']['provider'] ?? 'stripe',
                'amount_minor' => $quote['amount_minor'],
                'minor_units' => $quote['minor_units'],
                'currency' => strtoupper($quote['currency']),
                'pricing_mode' => $quote['mode'],
                'pricing_version' => $quote['version'],
                'quote' => $quote['snapshot'],
                'product_name' => interpolate($onSubmit['payment']['product_name'] ?? ($form['name'] ?? $formId), $data),
            ];
            $sandboxResult['meta'] = ['payment_status' => 'pending'];
        } catch (InvalidArgumentException $error) {
            $errors['_payment'] = $error->getMessage();
            $sandboxResult['status'] = 'error';
            $sandboxResult['message'] = 'Validation failed';
            $sandboxResult['validation']['passed'] = false;
            $sandboxResult['validation']['errors'] = $errors;
        }
    }

    $sandboxResult['on_submit_preview'] = $preview; bbf_access_finish(0, empty($errors));

    http_response_code(empty($errors) ? 200 : 422);
    echo json_encode($sandboxResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Production: reject invalid submissions ─────────────────────
if (!empty($errors)) {
    respond(422, msg('validationFailed'), ['errors' => $errors]);
}

// ─── Use the same normalized visible data validated above ────────
$data = $normalizedData;
$submissionId = 'bbf_' . bin2hex(random_bytes(8));
$timestamp = date('c');
$redirectVars = ['_id' => $submissionId, '_form' => $form['name'] ?? $formId, '_time' => $timestamp]; // {{_id}} etc. in on_submit.redirect

// ─── Step C: plan the uploaded files; descriptors replace the tokens in the record ─
$uploadPlan = null;
$fileFields = bbf_uploads_file_fields($flatFields);
if ($fileFields) {
    $planned = bbf_uploads_plan($config, $formId, $submissionId, $fileFields, $data, false);
    if (!$planned['ok']) {
        respond($planned['code'], $planned['code'] === 422 ? msg('validationFailed') : 'File uploads are unavailable.', ['errors' => $planned['errors']]);
    }
    $uploadPlan = $planned['plan'];
    if ($uploadPlan !== null && ($form['on_submit']['store'] ?? true) === false) {
        respond(500, 'File fields require stored submissions.');
    }
}

$meta = ['submitted' => $timestamp] + bbf_version_submission_metadata($form, $formId, $publishedDefinitionVersion);
if ($config['store_ip'] ?? true) {
    $meta['ip'] = $ip;
}
if ($config['store_user_agent'] ?? true) {
    $meta['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

// Resolve and persist the server-authoritative quote before any Checkout request.
$paymentQuote = null;
if (!empty($form['on_submit']['payment'])) {
    try {
        $paymentQuote = bbfResolvePaymentQuote($form['on_submit']['payment'], $data);
    } catch (InvalidArgumentException $error) {
        respond(422, 'Invalid payment selection.', ['errors' => ['_payment' => $error->getMessage()]]);
    }
    $meta['payment_status'] = 'pending';
    $meta['payment_expected_amount_minor'] = $paymentQuote['amount_minor'];
    $meta['payment_expected_currency'] = $paymentQuote['currency'];
    $meta['payment_pricing_mode'] = $paymentQuote['mode'];
    $meta['payment_pricing_version'] = $paymentQuote['version'];
    $meta['payment_quote'] = $paymentQuote['snapshot'];
}

// ─── Process on_submit (store, email, webhooks, actions) ────────
$onSubmit = $form['on_submit'] ?? [];
$GLOBALS['_bbf_errors'] = [];  // collect non-fatal errors for admin notification

$storeConfig = bbf_effective_storage_config($config, $formId, $form);
$storeEnabled = ($onSubmit['store'] ?? true) !== false;
$isPayment = !empty($onSubmit['payment']);
if ($isPayment && (!$storeEnabled || $storeConfig['storage'] === 'csv')) {
    respond(500, 'Payment requires durable file, SQLite or MySQL storage with store enabled.');
}
if ($storeEnabled) {
    // A request without a key gets a server-generated one: its intent serves recovery only.
    $txKey ??= hash('sha256', bin2hex(random_bytes(16)));
    $meta['submit_key_hash'] = $txKey;
}

$submission = [
    'id'        => $submissionId,
    'form'      => $formId,
    'data'      => $data,
    'meta'      => $meta,
];

// ─── Payment: freeze every Checkout parameter before anything is stored ─
$checkoutParams = null;
if ($isPayment) {
    $payment = $onSubmit['payment'];
    $provider = $payment['provider'] ?? 'stripe';
    if ($provider !== 'stripe') respond(500, "Unsupported payment provider: $provider");
    // A missing stripe.secret_key keeps the lead: the record is stored, then answered as payment_unavailable.
    // Referer and Host differ between retries; a retry must send Stripe identical parameters.
    $baseHost = (bbf_request_https($config) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $referer = $_SERVER['HTTP_REFERER'] ?? $baseHost;
    $successUrl = $payment['success_url'] ?? $referer;
    $cancelUrl  = $payment['cancel_url'] ?? $referer;
    if (!str_starts_with($successUrl, 'http')) $successUrl = rtrim($baseHost, '/') . '/' . ltrim($successUrl, '/');
    if (!str_starts_with($cancelUrl, 'http'))  $cancelUrl  = rtrim($baseHost, '/') . '/' . ltrim($cancelUrl, '/');
    $checkoutParams = [
        'amount'       => $paymentQuote['amount_minor'],
        'currency'     => $paymentQuote['currency'],
        'product_name' => interpolate($payment['product_name'] ?? ($form['name'] ?? $formId), $data),
        'success_url'  => $successUrl,
        'cancel_url'   => $cancelUrl,
        'metadata'     => ['bbf_submission_id' => $submissionId, 'bbf_form_id' => $formId],
        'customer_email' => $data[$payment['email_field'] ?? 'email'] ?? null,
    ];
    // Recovery after a definition change finishes the payment with the exact definition used now.
    try {
        $versionPaths = bbf_version_paths($config, $formId);
        bbf_version_prepare_directory($versionPaths);
        bbf_version_store_blob($versionPaths, $versionedForm);
    } catch (Throwable $error) {
        error_log('BareBonesForms: definition version for payment recovery not stored: ' . $error->getMessage());
    }
}

// ─── Prepare ordinary delivery before any submission persistence ─
$deliveryJobs = [];
$actionResponse = []; // Actions can add custom fields to the first response.
$deliveryStatus = ['ok' => true, 'state' => 'none', 'settled' => true, 'jobs' => []];
$deliveryAttention = ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
if (!$isPayment) {
    $templateData = bbf_delivery_template_data($form, $submission);
    $deliveryForm = $form;
    // Smoke submissions must use the safe recipient override in either execution mode.
    $smokeNotifyOverride = $_smokeAuth ? ($config['smoke_notify'] ?? $config['smoke_email'] ?? '') : '';
    if ($smokeNotifyOverride !== '' && is_array($deliveryForm['on_submit']['notify'] ?? null)) {
        $deliveryForm['on_submit']['notify']['to'] = $smokeNotifyOverride;
    }
    try {
        $deliveryJobs = bbf_delivery_prepare_jobs($deliveryForm, $submission, $storeConfig, $templateData);
    } catch (Throwable $error) {
        error_log('BareBonesForms: Delivery plan preparation failed for ' . $submissionId);
        if ($storeEnabled) respond(500, msg('submitAgain'));
        $deliveryStatus = $deliveryAttention + [
            'durable' => false,
            'retry_available' => false,
            'note' => 'No submission or retry record was stored; delivery cannot be administered from the viewer.',
        ];
        $extra = ['delivery' => $deliveryStatus];
        if (!empty($onSubmit['redirect'])) $extra['redirect'] = bbf_redirect_url($onSubmit['redirect'], $data, $redirectVars);
        respond(202, 'OK', $extra);
    }
}

if (!$storeEnabled) {
    $inlineResults = [];
    foreach ($deliveryJobs as $job) {
        $outcome = bbf_delivery_execute_job($job, $storeConfig, $actionResponse);
        if (empty($outcome['ok'])) bbf_alert_delivery_failure($config, $formId, '', bbf_alert_job_label($job), $outcome, 'inline');
        $inlineResults[] = ['job' => $job, 'outcome' => $outcome];
    }
    $deliveryStatus = bbf_delivery_inline_status($inlineResults);
} else {
    // Unencodable data (invalid UTF-8) fails every attempt: a permanent 500, never a retryable intent.
    try { bbf_storage_json($submission); } catch (JsonException $error) { respond(500, msg('submitAgain')); }
    // ─── Step B tail: open the intent (spec §5) ─────────────────
    ignore_user_abort(true);
    $tx = null;
    $mysqlPdo = null;
    try {
        $txState = [
            'v' => 1, 'state' => 'open', 'submission_id' => $submissionId,
            'P' => bbf_tx_payload_fingerprint($config, $formId, $rawInput),
            'created' => time(), 'definition_version' => $publishedDefinitionVersion,
            'storage_fingerprint' => bbf_tx_storage_fingerprint($storeConfig), 'backend' => $storeConfig['storage'],
            'payment' => $isPayment,
        ];
        if ($uploadPlan !== null) $txState['files'] = $uploadPlan;
        if ($isPayment) {
            $txState['checkout'] = $checkoutParams;
        } else {
            $txState['response'] = ['submission_id' => $submissionId];
            if (!empty($onSubmit['redirect'])) $txState['response']['redirect'] = bbf_redirect_url($onSubmit['redirect'], $data, $redirectVars);
        }
        for ($attempt = 0; $tx === null; $attempt++) {
            bbf_tx_deadline_start($config);
            $txState['deadline'] = (int)ceil($GLOBALS['_bbf_tx_deadline']);
            if ($storeConfig['storage'] === 'mysql') {
                $mysqlPdo = bbf_tx_mysql_connect($storeConfig['mysql'], $dbInfo);
                $txState['db'] = $dbInfo;
            }
            $created = bbf_tx_create($config, $formId, $txKey, $txState);
            if ($created['ok']) {
                $tx = $created['h'];
                break;
            }
            $mysqlPdo = null;
            bbf_tx_deadline_clear();
            if ($created['reason'] !== 'exists' || $attempt >= 2) throw new RuntimeException('Submit intent could not be created.');
            // A concurrent request with the same key created the intent meanwhile: back to step A.
            bbf_submit_step_a($config, $formId, $txKey, $rawInput);
        }
    } catch (Throwable $error) {
        error_log('BareBonesForms: submit transaction could not start: ' . $error->getMessage());
        $mysqlPdo = null;
        bbf_tx_deadline_clear();
        respond(503, msg('submitAgain'));
    }
    if (bbf_tx_remaining() <= 0) bbf_submit_tx_stop($tx, bbf_tx_state($txState, 'aborted'), 503, 'Submission could not be saved. Please submit again.');

    // ─── Step D: claim the planned files into <form>/<submission_id>/ ─
    if ($uploadPlan !== null) {
        $claim = bbf_uploads_claim($config, bbf_uploads_tx_owner($formId, $txKey), $uploadPlan);
        if (!$claim['ok']) {
            $mysqlPdo = null;
            $extra = isset($claim['retry_after']) ? ['retry_after' => $claim['retry_after']] : [];
            if ($claim['code'] === 422) $extra['errors'] = ['_uploads' => $claim['message']];
            // Only a confirmed complete undo may end the transaction; otherwise recovery finishes it.
            bbf_submit_tx_stop($tx, $claim['undone'] ? bbf_tx_state($txState, 'aborted') : null, $claim['code'], $claim['message'], $extra);
        }
    }

    $outboxPath = '';
    if ($deliveryJobs !== []) {
        $outboxPath = bbf_outbox_path($storeConfig, $formId, $submissionId);
        $maxAttempts = (int)($storeConfig['delivery']['max_attempts'] ?? 3);
        $initialized = $outboxPath !== ''
            ? bbf_outbox_init($outboxPath, "$formId:$submissionId", $deliveryJobs, $maxAttempts)
            : ['ok' => false, 'reason' => 'path'];
        if (!($initialized['ok'] ?? false)) {
            error_log('BareBonesForms: Delivery plan persistence failed for ' . $submissionId);
            $mysqlPdo = null;
            bbf_submit_tx_stop($tx, bbf_submit_tx_aborted($config, $formId, $txKey, $txState), 500, 'Submission delivery could not be saved. Please try again later.');
        }
    }

    // ─── Step E: store; step F: decide ──────────────────────────
    // Non-payment: the complete immutable ledger exists before storage; no delivery effect can run before this succeeds.
    bbf_tx_hook('before_store');
    // Test hooks: 'store' = backend refused without writing; 'store_result' = written, but reported as failed.
    $stored = bbf_tx_hook('store') && store($submission, $storeConfig, $form['fields'], $mysqlPdo) && bbf_tx_hook('store_result');
    $mysqlPdo = null; // closed before any late-commit kill from a new connection
    if (!$stored) {
        bbf_tx_notice($formId, 'Storage failed', $storeConfig['storage'] . ' backend returned false');
        $existence = bbf_record_exists($storeConfig, $formId, $submissionId, $txState['db'] ?? null);
        $storageError = [];
        if ($outboxPath !== '') {
            $deliveryStatus = bbf_delivery_abort_for_storage($outboxPath, $deliveryJobs, $storeConfig);
            $storageError['delivery'] = $deliveryStatus;
            if (($deliveryStatus['state'] ?? '') !== 'attention_required') {
                error_log('BareBonesForms: Storage-failure ledger could not be made attention-required for ' . $submissionId);
            }
        }
        if ($existence !== 'exists') {
            bbf_submit_tx_stop($tx, $existence === 'not_found' ? bbf_submit_tx_aborted($config, $formId, $txKey, $txState) : null,
                503, 'Submission could not be saved. Please submit again.', $storageError);
        }
    }
    bbf_tx_hook('after_store');

    if ($isPayment) {
        $committed = bbf_tx_state($txState, 'committed', ['committed_at' => time(), 'checkout' => $checkoutParams]);
        if (!bbf_tx_write_state($tx, $committed)) bbf_submit_tx_stop($tx, null, 503, 'Payment could not be prepared. Please try again.', ['code' => 'submit_pending', 'retry_after' => 5]);
        bbf_tx_hook('after_committed');
        // ─── Step G: finish the payment; the redirect goes only to this key's holder ─
        $finished = bbf_tx_finish_payment($config, $formId, $tx, $committed, $form);
        bbf_submit_tx_end($tx, $config, $formId);
        bbf_submit_payment_response($finished, false);
    }

    if (!bbf_tx_marker_create($tx) || !bbf_tx_write_state($tx, bbf_tx_state($txState, 'complete', ['response' => $txState['response']]))) {
        bbf_submit_tx_stop($tx, null, 503, 'Your submission could not be confirmed. Please submit again; it will not be duplicated.',
            ['code' => 'submit_pending', 'retry_after' => 5]);
    }
    bbf_tx_hook('after_complete');

    // ─── Step H: release, deliver, respond (today's order) ──────
    bbf_submit_tx_end($tx, $config, $formId);
    if ($stored && $outboxPath !== '') {
        foreach ($deliveryJobs as $job) {
            bbf_delivery_run_job($outboxPath, (string)$job['key'], $storeConfig, $actionResponse);
        }
        $deliveryStatus = bbf_outbox_status($outboxPath);
        if (!($deliveryStatus['ok'] ?? false)) $deliveryStatus = $deliveryAttention;
    }
    @unlink($tx['deliver']);
}

// ─── Success / accepted with unsettled delivery ─────────────────
$extra = $storeEnabled ? array_merge(['submission_id' => $submissionId], $actionResponse) : $actionResponse;
// Form-level redirect (action redirect takes precedence if set)
if (empty($extra['redirect']) && !empty($onSubmit['redirect'])) {
    $extra['redirect'] = bbf_redirect_url($onSubmit['redirect'], $data, $redirectVars);
}
// Always assign the trusted projection last so actions cannot expose or replace delivery state.
$extra['delivery'] = $deliveryStatus;
respond(($deliveryStatus['settled'] ?? false) ? 200 : 202, 'OK', $extra);


// ═════════════════════════════════════════════════════════════════
// Functions
// ═════════════════════════════════════════════════════════════════

function respond(int $code, string $message, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['status' => $code < 400 ? 'ok' : 'error', 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** A missing or broken form is an admin incident; a 404 only for a form that has existed here (not bot noise). */
function bbf_submit_form_incident(array $config, string $formId, bool $missing, string $detail): void {
    global $isSandbox;
    if (!empty($isSandbox)) return;
    if (!$missing) {
        bbf_alert_record($config, $formId, 'Invalid form definition', $detail);
    } elseif (bbf_alert_form_known($config, $formId)) {
        bbf_alert_record($config, $formId, 'Form not found', $detail);
    }
}

// ─── Submit transactions (docs/SUBMIT-TRANSACTIONS.md) ───────────

/** Release the intent, send queued owner notices, and schedule bounded opportunistic recovery. */
function bbf_submit_tx_end(array &$tx, array $config, string $formId): void {
    bbf_tx_release($tx);
    bbf_tx_deadline_clear();
    bbf_tx_flush_notices($config);
    static $scheduled = false;
    $budget = (int)($config['submit_recovery_budget'] ?? 5);
    if ($scheduled || $budget <= 0) return;
    $scheduled = true;
    register_shutdown_function(static function () use ($config, $formId, $budget): void {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        bbf_tx_sweep($config, $formId, [$budget, 1.0]);
    });
}

/** Stop the transaction before its next effect: optional final state, release, respond. */
function bbf_submit_tx_stop(array &$tx, ?array $next, int $code, string $message, array $extra = []): never {
    global $config, $formId;
    if ($next !== null && bbf_tx_write_state($tx, $next) && $next['state'] === 'aborted') @unlink($tx['deliver']);
    bbf_submit_tx_end($tx, $config, $formId);
    respond($code, $message, $extra);
}

function bbf_submit_payment_response(array $finished, bool $replay): never {
    $state = $finished['state'];
    $response = (array)($state['response'] ?? []);
    $flag = $replay ? ['already_submitted' => true] : [];
    if ($finished['retry'] ?? false) {
        respond(503, msg('submitAgain'),
            ['code' => 'submit_pending', 'retry_after' => 5] + $flag);
    }
    if (isset($response['payment_unavailable'])) {
        respond(502, msg('paymentFailed'),
            ['code' => 'payment_unavailable', 'submission_id' => $state['submission_id']] + $flag);
    }
    respond(200, 'OK', $flag + ['submission_id' => $state['submission_id'], 'redirect' => $response['redirect'] ?? null]);
}

/** The aborted state after a claim: files go back to staging first. A failed rollback keeps the intent open (null). */
function bbf_submit_tx_aborted(array $config, string $formId, string $k, array $txState): ?array {
    if (!empty($txState['files']) && !bbf_uploads_rollback($config, bbf_uploads_tx_owner($formId, $k), $txState['files'])) return null;
    return bbf_tx_state($txState, 'aborted');
}

// ─── File uploads (docs/FILE-UPLOAD-DESIGN.md §4.2) ──────────────

/** Step 0: one upload token may appear only once in the whole body. Nothing definition-dependent. */
function bbf_submit_duplicate_token(array $input): bool {
    $seen = [];
    foreach ($input as $value) {
        if (!is_array($value)) continue;
        array_walk_recursive($value, static function ($item) use (&$seen, &$duplicate): void {
            if (!is_string($item) || !preg_match('/\A[a-f0-9]{32}\z/D', $item)) return;
            if (isset($seen[$item])) $duplicate = true;
            $seen[$item] = true;
        });
        if (!empty($duplicate)) return true;
    }
    return false;
}

/** File fields in the public definition carry the effective accept list and size limit. */
function bbf_submit_client_file_fields(array $config, array $fields): array {
    foreach ($fields as &$field) {
        if (!is_array($field)) continue;
        if (($field['type'] ?? '') === 'file') $field = bbf_uploads_client_field($config, $field);
        elseif (is_array($field['fields'] ?? null)) $field['fields'] = bbf_submit_client_file_fields($config, $field['fields']);
    }
    return $fields;
}

/** Upload CSRF travels in X-BBF-CSRF so it survives a post_max_size overflow. Same exemptions as submit. */
function bbf_submit_upload_csrf(array $config, string $formId): void {
    global $origin;
    $smoke = $_SERVER['HTTP_X_BBF_SMOKE_TOKEN'] ?? '';
    $smokeAuth = bbf_smoke_token($config) !== null
        && is_string($smoke) && $smoke !== '' && hash_equals(bbf_smoke_token($config), $smoke);
    $cors = !empty($origin) && in_array($origin, (array)($config['allowed_origins'] ?? []), true);
    if (!($config['csrf'] ?? true) || $cors || $smokeAuth) return;
    $token = $_SERVER['HTTP_X_BBF_CSRF'] ?? null;
    if (!bbf_submit_csrf_valid($formId, $token)) {
        respond(403, msg('csrfInvalid'));
    }
}

/** The published definition's file field named in ?field=, or a 4xx response. */
function bbf_submit_upload_field(array $config, string $formId): array {
    $path = $config['forms_dir'] . "/$formId.json";
    if (!is_file($path)) {
        // Unknown anonymous upload targets are not evidence of a broken published form.
        respond(404, "Form '$formId' not found.");
    }
    $form = json_decode((string)file_get_contents($path), true);
    if (!is_array($form) || ($form['id'] ?? null) !== $formId || empty($form['fields']) || validateFormDefinition($form)) {
        bbf_submit_form_incident($config, $formId, false, 'The definition is invalid; a file upload was rejected.');
        respond(500, 'Invalid form definition.');
    }
    $form = bbfSystemDefinition($form, $config);
    if (!empty($form['templates'])) $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
    $name = $_GET['field'] ?? '';
    $fields = is_string($name) ? bbf_uploads_file_fields(flattenFields($form['fields'])) : [];
    if (!isset($fields[$name])) respond(400, 'This field does not accept files.');
    return $fields[$name];
}

/** Upload storage not ready: the respondent gets a translated message, the setup advice goes to the error log (the sandbox is the admin). */
function bbf_submit_upload_unavailable(array $root, bool $isSandbox): never {
    if ($isSandbox) respond($root['code'], $root['error']);
    if ($root['code'] === 404) respond(404, msg('uploadDisabled'), ['reason' => 'disabled']);
    bbf_uploads_config_error($GLOBALS['config'], $root['error']);
    respond($root['code'], msg('uploadCannotStore'));
}

function bbf_submit_upload(array $config, string $formId, bool $isSandbox): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, 'Method not allowed.');
    if (empty(bbf_uploads_config($config)['enabled'])) bbf_submit_upload_unavailable(['code' => 404, 'error' => 'File uploads are not enabled.'], $isSandbox);
    if (!$isSandbox) bbf_submit_upload_csrf($config, $formId);
    $field = bbf_submit_upload_field($config, $formId);
    $ip = bbf_client_ip($config);
    // Authenticated attempts count, including content refusals, but never rejected CSRF.
    if (!$isSandbox && !bbf_uploads_rate_limit($config, $ip)) respond(429, msg('uploadRateLimit'), ['retry_after' => 60]);
    $root = bbf_uploads_root($config, !$isSandbox);
    if (!$root['ok']) bbf_submit_upload_unavailable($root, $isSandbox);
    $postMax = bbf_uploads_ini_bytes(ini_get('post_max_size'));
    if ($postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) respond(413, msg('uploadServerLimit'));
    $file = $_FILES['file'] ?? null;
    if (count($_FILES) !== 1 || !is_array($file) || is_array($file['name'] ?? null) || !is_int($file['error'] ?? null)) {
        respond(400, msg('uploadNoFile'));
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $code = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 413,
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 503,
            default => 400,
        };
        respond($code, bbf_uploads_error_message($file['error']));
    }
    $checked = bbf_uploads_validate_file($config, $field, (string)$file['tmp_name'], (string)$file['name'], (int)$file['size']);
    if (!$checked['ok']) respond($checked['code'], $checked['message']);
    if ($isSandbox) {
        $expires = time() + max(60, (int)bbf_uploads_config($config)['staging_ttl']);
        $token = bbf_uploads_sandbox_token($root['root'], ['form' => $formId, 'field' => $field['name'], 'name' => $checked['name'],
            'size' => $checked['size'], 'type' => $checked['type'], 'ext' => $checked['ext'], 'exp' => $expires]);
        bbf_access_finish(0, true);
        respond(200, 'OK', ['token' => $token, 'expires_at' => gmdate('c', $expires), 'sandbox' => true,
            'file' => ['name' => $checked['name'], 'size' => $checked['size'], 'type' => $checked['type']]]);
    }
    try {
        $stored = bbf_uploads_store($config, $root['root'], $formId, (string)$field['name'], (string)$file['tmp_name'], $checked, $ip);
    } catch (Throwable $error) {
        error_log('BareBonesForms upload failed: ' . $error->getMessage());
        respond(503, msg('uploadTemporary'));
    }
    if (!$stored['ok']) respond($stored['code'], $stored['message'], $stored['code'] === 429 ? ['retry_after' => 600] : []);
    unset($stored['ok']);
    respond(200, 'OK', $stored);
}

/**
 * Decode a JSON request body without letting a stranger exhaust memory. Files use the upload endpoint,
 * so 1 MB is far above any real submission; the container cap stops tiny-array bodies (1 MB of "[1],"
 * would otherwise cost ~60 MB) from crashing PHP before the rate limit even runs.
 */
function bbf_submit_json_body(): mixed {
    $max = 1048576;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) respond(413, msg('requestTooLarge'));
    $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max || substr_count($raw, '{') + substr_count($raw, '[') > 20000) {
        respond(413, msg('requestTooLarge'));
    }
    return json_decode($raw, true, 64);
}

function bbf_submit_upload_delete(array $config, string $formId, bool $isSandbox): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, 'Method not allowed.');
    if (empty(bbf_uploads_config($config)['enabled'])) bbf_submit_upload_unavailable(['code' => 404, 'error' => 'File uploads are not enabled.'], $isSandbox);
    if (!$isSandbox) bbf_submit_upload_csrf($config, $formId);
    if (!is_file($config['forms_dir'] . "/$formId.json")) respond(404, "Form '$formId' not found.");
    $root = bbf_uploads_root($config, false);
    if (!$root['ok']) bbf_submit_upload_unavailable($root, $isSandbox);
    $body = bbf_submit_json_body();
    $token = is_array($body) ? ($body['token'] ?? null) : null;
    if ($isSandbox) {
        bbf_access_finish(0, true);
        respond(200, 'OK');
    }
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) respond(400, 'Invalid upload token.');
    try {
        $result = bbf_uploads_delete_staged($config, $root['root'], $formId, $token);
    } catch (Throwable $error) {
        error_log('BareBonesForms upload delete failed: ' . $error->getMessage());
        respond(503, msg('uploadTemporary'), ['retry_after' => 5]);
    }
    if (!$result['ok']) respond($result['code'], $result['message'], isset($result['retry_after']) ? ['retry_after' => $result['retry_after']] : []);
    respond(200, 'OK');
}

/**
 * Step A: look up the key before definition, CSRF and session. Responds for a known key,
 * returns (to step B) for an unknown, deleted or aborted one. Creates no file for an unknown key.
 */
function bbf_submit_step_a(array $config, string $formId, string $k, array $rawInput): void {
    try {
        $dir = bbf_tx_form_dir($config, $formId, false);
        if ($dir === null || !file_exists(bbf_tx_paths($dir, $k)['lock'])) return;
        if (!checkRateLimit('replay:' . $k, (int)($config['submit_replay_rate'] ?? 30), $config['logs_dir'])) {
            respond(429, msg('tooManyRequests'), ['retry_after' => 60]);
        }
        $taken = bbf_tx_take($config, $formId, $k);
        if ($taken['status'] === 'none') return;
        if ($taken['status'] === 'busy') {
            respond(202, msg('submitProcessing'), ['status' => 'processing', 'retry_after' => 2]);
        }
        $h = $taken['h'];
        $read = bbf_tx_read_state($h);
        if ($read['kind'] === 'none') { bbf_tx_delete($h); return; }
        if ($read['kind'] === 'unreadable') {
            bbf_tx_release($h);
            bbfNotifyError($formId, 'Submit state unreadable', "Intent $k cannot be read; a human must decide.", $config);
            respond(503, msg('submitUnconfirmed'), ['code' => 'submit_state_unreadable']);
        }
        $state = $read['state'];
        if (in_array($state['state'], ['open', 'committed'], true)) {
            // Exactly one inline recovery, then an answer; never a loop.
            $state = bbf_tx_recover($config, $formId, $h, $state) ?? $state;
        }
        if ($state['state'] === 'aborted') { bbf_tx_delete($h); bbf_tx_flush_notices($config); return; }
        bbf_submit_tx_end($h, $config, $formId);
        if ($state['state'] !== 'complete') {
            respond(503, msg('submitProcessing'), ['code' => 'submit_pending', 'retry_after' => 5]);
        }
        bbf_submit_replay($config, $formId, $state, $h, $rawInput);
    } catch (Throwable $error) {
        error_log('BareBonesForms: submit key lookup failed: ' . $error->getMessage());
        respond(503, msg('submitAgain'), ['code' => 'submit_pending', 'retry_after' => 5]);
    }
}

/** Replay of a complete intent (spec §6). Never validates, never re-runs attempted delivery. */
function bbf_submit_replay(array $config, string $formId, array $state, array $h, array $rawInput): never {
    $id = $state['submission_id'];
    if (!hash_equals($state['P'], bbf_tx_payload_fingerprint($config, $formId, $rawInput))) {
        respond(409, 'This form was already submitted. Changes made after that were not saved.',
            ['code' => 'already_submitted_different', 'submission_id' => $id]);
    }
    $storeConfig = bbf_tx_store_config($config, $formId, $state);
    if (empty($state['payment'])) {
        bbf_tx_run_unattempted($config, $formId, $state);
        @unlink($h['deliver']);
        $outbox = bbf_outbox_existing_path($storeConfig, $formId, $id);
        respond(200, 'OK', ['already_submitted' => true] + (array)$state['response'] + ['delivery' => bbf_outbox_status($outbox)]);
    }
    if (isset($state['response']['payment_unavailable'])) bbf_submit_payment_response(['state' => $state], true);
    try {
        $record = bbf_tx_read_record($storeConfig, $formId, $id);
    } catch (Throwable $error) {
        $record = null;
    }
    if ($record === null) {
        respond(503, msg('submitProcessing'),
            ['code' => 'submit_pending', 'retry_after' => 5, 'already_submitted' => true, 'submission_id' => $id]);
    }
    $status = $record['meta']['payment_status'] ?? 'pending';
    if ($status === 'paid') respond(200, 'OK', ['already_submitted' => true, 'submission_id' => $id, 'payment_status' => 'paid']);
    if ($status === 'pending' && time() < (int)($state['session']['expires_at'] ?? 0)) {
        respond(200, 'OK', ['already_submitted' => true, 'submission_id' => $id, 'redirect' => $state['session']['url']]);
    }
    bbfNotifyError($formId, 'Payment not resumed', "Submission $id: the respondent retried after the payment $status or expired.", $config);
    bbf_submit_payment_response(['state' => ['submission_id' => $id, 'response' => ['payment_unavailable' => 'expired']]], true);
}

// validateFieldList, validateFormDefinition, validate → moved to bbf_functions.php

function collectData(array $fields, array $input): array {
    $data = [];
    $input = bbfVisibleInput($fields, $input);
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        // Skip non-data fields; repeatable groups preserve structured rows.
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            if (!empty($field['show_if']) && !evalCondition($field['show_if'], $input)) continue;
            $childFields = flattenFields($field['fields'] ?? []);
            $rows = [];
            foreach ($input[$name] ?? [] as $row) {
                $rows[] = collectData($childFields, bbfRepeatableRowInput($childFields, $input, $row));
            }
            $data[$name] = $rows;
            continue;
        }

        // Skip conditionally hidden fields — evaluate the condition server-side
        if (!empty($field['show_if']) && !evalCondition($field['show_if'], $input)) {
            continue;
        }

        $value = !empty($field['_bbf_system']) ? ($input[$name] ?? '') : bbfNormalizeInputValue($input[$name] ?? '', $type);

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

function store(array $submission, array $config, array $formFields = [], ?PDO $mysql = null): bool {
    switch ($config['storage']) {
        case 'mysql':
            return storeMysql($submission, $config['mysql'], $mysql);
        case 'sqlite':
            return storeSqlite($submission, $config);
        case 'csv':
            return storeCsv($submission, $config['submissions_dir'], $formFields);
        default:
            return storeFile($submission, $config['submissions_dir']);
    }
}

function storeFile(array $submission, string $dir): bool {
    try { bbf_storage_json($submission); } catch (JsonException $e) { return false; } $formDir = $dir . '/' . $submission['form'];
    if (!is_dir($formDir) && !@mkdir($formDir, 0755, true) && !is_dir($formDir)) {
        error_log("BareBonesForms: Cannot create directory $formDir");
        return false;
    }
    $file = $formDir . '/' . $submission['id'] . '.json';
    $result = bbf_storage_write_json($file, $submission);
    if ($result === false) {
        error_log("BareBonesForms: Failed to write $file");
        return false;
    }
    return true;
}

function storeMysql(array $submission, array $dbConfig, ?PDO $pdo = null): bool {
    try {
        bbf_storage_json($submission); $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        // Inside a submit transaction the connection was opened (with timeouts) before the intent.
        $pdo ??= new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
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
            bbf_storage_json($submission['data']),
            bbf_storage_json($submission['meta']),
        ]);
        return true;
    } catch (PDOException | JsonException $e) {
        error_log("BareBonesForms MySQL error: " . $e->getMessage());
        return false;
    }
}

function storeSqlite(array $submission, array $config): bool {
    try {
        bbf_storage_json($submission); $dbFile = $config['sqlite']['path'] ?? $config['submissions_dir'] . '/bbf.sqlite';
        $dir = dirname($dbFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

        $pdo = new PDO("sqlite:$dbFile", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        if (isset($GLOBALS['_bbf_tx_deadline'])) $pdo->exec('PRAGMA busy_timeout = ' . max(1, (int)(bbf_tx_remaining() * 1000)));
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
            bbf_storage_json($submission['data']),
            bbf_storage_json($submission['meta']),
            $submission['meta']['submitted'] ?? date('c'),
        ]);
        return true;
    } catch (PDOException | JsonException $e) {
        error_log("BareBonesForms SQLite error: " . $e->getMessage());
        return false;
    }
}

function csvNeedsSanitize(string $val): bool {
    return $val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true);
}

function csvSanitize(string $val): string {
    // Prevent CSV formula injection (Excel/Sheets interpret =, +, -, @, tab, CR as formulas)
    if (csvNeedsSanitize($val)) {
        return "'" . $val;
    }
    return $val;
}

function storeCsv(array $submission, string $dir, array $formFields): bool {
    try { bbf_storage_json($submission); bbf_storage_json($formFields); }
    catch (JsonException $e) { return false; }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $file = $dir . '/' . $submission['form'] . '.csv';
    $metaCols = ['_id', '_submitted', '_ip', '_user_agent'];
    $versionCols = isset($submission['meta']['definition_version'], $submission['meta']['form_definition'])
        ? ['__bbf:definition_version', '__bbf:form_definition', '__bbf:csv_escaped_fields'] : [];
    $fieldNames = [];
    $structuredFields = [];
    $fileFields = [];
    foreach (flattenFields($formFields) as $field) {
        $type = $field['type'] ?? 'text';
        if ($type === 'group' && !empty($field['repeatable']) && isset($field['name'])) {
            $structuredFields[$field['name']] = true;
        }
        if ($type === 'file' && isset($field['name'])) $fileFields[$field['name']] = true;
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;
        if (isset($field['name'])) $fieldNames[] = $field['name'];
    }
    if ($structuredFields !== []) $versionCols[] = '__bbf:structured_fields';
    // File descriptors: a readable cell per field, the full descriptors in one reserved column.
    $fileDescriptors = [];
    foreach ($submission['data'] as $name => $value) {
        if (isset($fileFields[$name]) || bbf_uploads_is_descriptor_list($value) && $value !== []) {
            $fileDescriptors[$name] = is_array($value) ? $value : [];
            $submission['data'][$name] = bbf_uploads_describe($value);
        }
    }
    if (array_filter($fileDescriptors)) $versionCols[] = '__bbf:files';
    $fieldNames = array_values(array_unique(array_merge($fieldNames, array_keys($submission['data']))));
    $escapedFields = [];
    foreach ($submission['data'] as $name => $value) {
        $cell = isset($structuredFields[$name]) ? bbf_storage_json($value)
            : (is_array($value) ? implode('; ', $value) : (string)$value);
        if (csvNeedsSanitize($cell)) $escapedFields[] = $name;
    }
    // Reserved metadata columns cannot also represent respondent values.
    if (array_intersect(array_merge($metaCols, $versionCols), $fieldNames)) return false;
    return bbf_storage_locked($file, static function () use ($file, $submission, $metaCols, $versionCols, $fieldNames, $escapedFields, $structuredFields, $fileDescriptors): bool {
        $source = null;
        try {
            $headers = $metaCols;
            if (file_exists($file)) {
                $source = fopen($file, 'rb');
                if (!$source) return false;
                $stat = fstat($source);
                if ($stat === false) return false;
                if ($stat['size'] > 0) {
                    $headerStart = ftell($source);
                    $headers = fgetcsv($source, 0, ',', '"', '');
                    $headerEnd = ftell($source);
                    if ($headerStart === false || $headerEnd === false
                        || !bbf_storage_csv_record_syntax_valid($source, $headerStart, $headerEnd)
                        || !is_array($headers) || array_slice($headers, 0, 4) !== $metaCols
                        || count(array_unique($headers)) !== count($headers)) return false;
                    bbf_storage_json($headers);
                }
            }
            // Preserve historical order, including deleted/renamed fields; only append new names.
            $union = array_values(array_unique(array_merge($headers, $fieldNames, $versionCols)));
            return bbf_storage_replace($file, static function ($out) use ($source, $headers, $union, $submission, $escapedFields, $structuredFields, $fileDescriptors): bool {
                if (!bbf_storage_write_csv($out, $union)) return false;
                if ($source) {
                    while (true) {
                        $start = ftell($source);
                        $old = fgetcsv($source, 0, ',', '"', '');
                        if ($old === false) break;
                        $end = ftell($source);
                        // Refuse ambiguous/truncated records rather than silently discarding cells.
                        if ($start === false || $end === false
                            || !bbf_storage_csv_record_syntax_valid($source, $start, $end)
                            || count($old) !== count($headers)) return false;
                        bbf_storage_json($old);
                        $old = array_pad($old, count($union), '');
                        if (!bbf_storage_write_csv($out, $old)) return false;
                    }
                    if (!feof($source) || !fclose($source)) return false;
                }
                $row = [
                    $submission['id'],
                    $submission['meta']['submitted'] ?? '',
                    $submission['meta']['ip'] ?? '',
                    csvSanitize($submission['meta']['user_agent'] ?? ''),
                ];
                foreach (array_slice($union, 4) as $name) {
                    if ($name === '__bbf:definition_version') {
                        $value = $submission['meta']['definition_version'] ?? '';
                    } elseif ($name === '__bbf:form_definition') {
                        $value = isset($submission['meta']['form_definition'])
                            ? bbf_storage_json($submission['meta']['form_definition']) : '';
                    } elseif ($name === '__bbf:csv_escaped_fields') {
                        $value = bbf_storage_json($escapedFields);
                    } elseif ($name === '__bbf:structured_fields') {
                        $value = bbf_storage_json(array_keys($structuredFields));
                    } elseif ($name === '__bbf:files') {
                        $value = array_filter($fileDescriptors) ? bbf_storage_json(array_filter($fileDescriptors)) : '';
                    } else {
                        $value = $submission['data'][$name] ?? '';
                    }
                    $row[] = csvSanitize(isset($structuredFields[$name]) ? bbf_storage_json($value)
                        : (is_array($value) ? implode('; ', $value) : (string)$value));
                }
                return bbf_storage_write_csv($out, $row);
            });
        } finally {
            if (is_resource($source)) fclose($source);
        }
    });
    // The replacement helper checks fflush/close/rename before reporting success.
    // Readers see either the complete old schema or the complete union schema.
    // Stable sidecar locking serializes both first creation and concurrent migrations.
    // Existing CSV array and formula-escaping contracts are unchanged.
}

// sendEmail, sendSmtp, fireWebhook, renderTemplate, interpolate,
// buildSummary, buildSummaryRows, updateSubmissionPayment,
// bbfNotifyError → moved to bbf_functions.php

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

