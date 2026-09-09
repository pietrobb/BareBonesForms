<?php
/**
 * BareBonesForms — Submission Handler  v1.0.1
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

require_once __DIR__ . '/bbf_functions.php';
require_once __DIR__ . '/bbf_versions.php';
require_once __DIR__ . '/bbf_drafts.php';

require_once __DIR__ . '/bbf_auth.php'; $config = bbf_auth_load_config(__DIR__ . '/config.php');


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
        if (!is_array($def) || ($def['id'] ?? null) !== $defFormId) respond(500, 'Invalid form definition.');
        unset($def['on_submit'], $def['storage']);
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
if (!$form || ($form['id'] ?? null) !== $formId || empty($form['fields'])) {
    respond(500, 'Invalid form definition.');
}

// ─── Validate form schema ───────────────────────────────────────
$schemaErrors = validateFormDefinition($form);
if (!empty($schemaErrors)) {
    error_log('BareBonesForms schema errors in ' . $formId . ': ' . implode('; ', $schemaErrors));
    respond(500, 'Invalid form definition.', ['schema_errors' => $schemaErrors]);
}
$publishedDefinitionVersion = bbf_version_id($form);

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
// Skip public CSRF only for a valid nonempty smoke-token header (used by smoketest.php).
$_smokeToken = $_SERVER['HTTP_X_BBF_SMOKE_TOKEN'] ?? '';
$_smokeAuth  = is_string($config['smoke_token'] ?? null) && $config['smoke_token'] !== ''
               && is_string($_smokeToken) && $_smokeToken !== '' && hash_equals($config['smoke_token'], $_smokeToken);
$isCorsRequest = !empty($origin) && !empty($config['allowed_origins'])
    && in_array($origin, $config['allowed_origins'], true);
if (!$isSandbox && ($config['csrf'] ?? true) && !$isCorsRequest && !$_smokeAuth) {
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
$rateLimitOk = $isSandbox || checkRateLimit($ip, $config['rate_limit'], $config['logs_dir']);
if (!$rateLimitOk) {
    respond(429, 'Too many submissions. Try again later.');
}

// ─── Resolve templates ─────────────────────────────────────────
if (!empty($form['templates'])) {
    $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
}

// ─── Flatten group fields ───────────────────────────────────────
$flatFields = flattenFields($form['fields']);

// ─── Opt-in respondent drafts ───────────────────────────────────
$draftAction = $_GET['action'] ?? '';
if (!$isSandbox && in_array($draftAction, ['draft_save', 'draft_load', 'draft_delete'], true)) {
    if (bbf_draft_policy($form) === null) respond(404, 'Respondent drafts are not enabled for this form.');
    bbf_draft_cleanup($config);
    $handle = is_string($input['_bbf_draft_handle'] ?? null) ? $input['_bbf_draft_handle'] : '';
    if ($draftAction === 'draft_save') {
        $result = bbf_draft_save($config, $form, $flatFields, $input, $handle);
    } elseif ($draftAction === 'draft_load') {
        $result = bbf_draft_load($config, $form, $handle);
    } else {
        $result = bbf_draft_delete($config, $form, $handle);
    }
    if ($result['ok'] ?? false) respond($draftAction === 'draft_save' && $handle === '' ? 201 : 200, 'OK', $result);
    $reason = $result['reason'] ?? 'storage';
    if ($reason === 'expired') respond(410, 'Draft has expired.', ['reason' => $reason]);
    if ($reason === 'not_found') respond(404, 'Draft not found.', ['reason' => $reason]);
    respond(500, 'Draft storage failed.', ['reason' => $reason]);
}

// ─── Validate ───────────────────────────────────────────────────
$errors = validate($flatFields, $input);

// ─── Sandbox mode ───────────────────────────────────────────────
// Authorization and management CSRF were checked before any public processing.
// Preview exits unconditionally; no storage, delivery, payment or action execution.
if ($isSandbox) {
    $data = validateFieldShapes($flatFields, $input) ? [] : collectData($flatFields, $input);
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
            'subject' => interpolate($ce['subject'] ?? 'Thank you', $data),
            'reply_to' => isset($ce['reply_to']) ? interpolate($ce['reply_to'], $data) : $config['mail']['from_email'],
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
            'to'      => ($_smokeAuth && !empty($config['smoke_email']))
                          ? ($config['smoke_notify'] ?? $config['smoke_email'] ?? '')
                          : (is_array($n['to']) ? implode(', ', array_map(fn($t) => interpolate($t, $data), $n['to'])) : interpolate($n['to'], $data)),
            'subject' => interpolate($n['subject'] ?? "New submission: $formId", $data),
            'reply_to' => isset($n['reply_to']) ? interpolate($n['reply_to'], $data) : $config['mail']['from_email'],
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

    if (!empty($onSubmit['payment'])) {
        try {
            $quote = bbfResolvePaymentQuote($onSubmit['payment'], $data);
            $preview['payment'] = [
                'provider' => $onSubmit['payment']['provider'] ?? 'stripe',
                'amount_minor' => $quote['amount_minor'],
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

$submission = [
    'id'        => $submissionId,
    'form'      => $formId,
    'data'      => $data,
    'meta'      => $meta,
];

// ─── Process on_submit (store, email, webhooks, actions) ────────
$onSubmit = $form['on_submit'] ?? [];
$GLOBALS['_bbf_errors'] = [];  // collect non-fatal errors for admin notification

$storeConfig = bbf_effective_storage_config($config, $formId, $form);
$storeEnabled = ($onSubmit['store'] ?? true) !== false;
if (!empty($onSubmit['payment']) && (!$storeEnabled || $storeConfig['storage'] === 'csv')) {
    respond(500, 'Payment requires durable file, SQLite or MySQL storage with store enabled.');
}
// Payment keeps its existing submission-first flow; payment delivery is deferred to payment.php.
if (!empty($onSubmit['payment']) && !store($submission, $storeConfig, $form['fields'])) {
    bbfNotifyError($formId, 'Storage failed', $storeConfig['storage'] . ' backend returned false', $config);
    respond(500, 'Submission could not be saved. Please try again later.');
}

// ─── Payment (Stripe Checkout) ───────────────────────────────────
if (!empty($onSubmit['payment'])) {
    $payment = $onSubmit['payment'];
    $provider = $payment['provider'] ?? 'stripe';

    if ($provider === 'stripe') {
        $stripeKey = $config['stripe']['secret_key'] ?? '';
        if ($stripeKey === '') {
            bbfNotifyError($formId, 'Payment config error', 'stripe.secret_key is not set in config.php', $config);
            respond(500, 'Payment is not configured. Please contact the site administrator.');
        }

        $productName = interpolate($payment['product_name'] ?? ($form['name'] ?? $formId), $data);

        // Build success/cancel URLs
        $baseHost = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $referer = $_SERVER['HTTP_REFERER'] ?? $baseHost;
        $successUrl = $payment['success_url'] ?? $referer;
        $cancelUrl  = $payment['cancel_url'] ?? $referer;
        // Relative URLs → absolute
        if (!str_starts_with($successUrl, 'http')) $successUrl = rtrim($baseHost, '/') . '/' . ltrim($successUrl, '/');
        if (!str_starts_with($cancelUrl, 'http'))  $cancelUrl  = rtrim($baseHost, '/') . '/' . ltrim($cancelUrl, '/');

        $checkout = createStripeCheckout($stripeKey, [
            'amount'       => $paymentQuote['amount_minor'],
            'currency'     => $paymentQuote['currency'],
            'product_name' => $productName,
            'success_url'  => $successUrl,
            'cancel_url'   => $cancelUrl,
            'metadata'     => [
                'bbf_submission_id' => $submissionId,
                'bbf_form_id'       => $formId,
            ],
            'customer_email' => $data[$payment['email_field'] ?? 'email'] ?? null,
        ], $config['stripe']['transport'] ?? null);

        if (!$checkout) {
            bbfNotifyError($formId, 'Stripe error', 'Checkout session creation failed', $config);
            respond(500, 'Payment session could not be created. Please try again later.');
        }
        if (!updateSubmissionPaymentMetadata($submissionId, $formId,
            ['payment_checkout_session_id' => $checkout['id']], $config)) {
            bbfNotifyError($formId, 'Payment persistence error', 'Checkout session ID could not be saved', $config);
            respond(500, 'Payment session could not be saved. Please try again later.');
        }

        // Payment forms: emails/webhooks are deferred until payment confirmation (via payment.php webhook)
        respond(200, 'OK', ['submission_id' => $submissionId, 'redirect' => $checkout['url']]);
    }

    respond(500, "Unsupported payment provider: $provider");
}

// ─── Prepare ordinary delivery before any submission persistence ─
$templateData = bbf_delivery_template_data($form, $submission);
$deliveryForm = $form;
// Smoke submissions must use the safe recipient override in either execution mode.
$smokeNotifyOverride = $_smokeAuth ? ($config['smoke_notify'] ?? $config['smoke_email'] ?? '') : '';
if ($smokeNotifyOverride !== '' && is_array($deliveryForm['on_submit']['notify'] ?? null)) {
    $deliveryForm['on_submit']['notify']['to'] = $smokeNotifyOverride;
}
$deliveryAttention = ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
try {
    $deliveryJobs = bbf_delivery_prepare_jobs($deliveryForm, $submission, $storeConfig, $templateData);
} catch (Throwable $error) {
    error_log('BareBonesForms: Delivery plan preparation failed for ' . $submissionId);
    if ($storeEnabled) respond(500, 'Submission delivery could not be prepared. Please try again later.');
    $deliveryStatus = $deliveryAttention + [
        'durable' => false,
        'retry_available' => false,
        'note' => 'No submission or retry record was stored; delivery cannot be administered from the viewer.',
    ];
    $extra = ['delivery' => $deliveryStatus];
    if (!empty($onSubmit['redirect'])) $extra['redirect'] = interpolate($onSubmit['redirect'], $data);
    respond(202, 'OK', $extra);
}

$actionResponse = []; // Actions can add custom fields to the first response.
$deliveryStatus = ['ok' => true, 'state' => 'none', 'settled' => true, 'jobs' => []];
if (!$storeEnabled) {
    $inlineResults = [];
    foreach ($deliveryJobs as $job) {
        $inlineResults[] = [
            'job' => $job,
            'outcome' => bbf_delivery_execute_job($job, $storeConfig, $actionResponse),
        ];
    }
    $deliveryStatus = bbf_delivery_inline_status($inlineResults);
} else {
    $outboxPath = '';
    if ($deliveryJobs !== []) {
        $outboxPath = bbf_outbox_path($storeConfig, $formId, $submissionId);
        $maxAttempts = (int)($storeConfig['delivery']['max_attempts'] ?? 3);
        $initialized = $outboxPath !== ''
            ? bbf_outbox_init($outboxPath, "$formId:$submissionId", $deliveryJobs, $maxAttempts)
            : ['ok' => false, 'reason' => 'path'];
        if (!($initialized['ok'] ?? false)) {
            error_log('BareBonesForms: Delivery plan persistence failed for ' . $submissionId);
            respond(500, 'Submission delivery could not be saved. Please try again later.');
        }
    }

    // The complete immutable ledger exists before storage; no delivery effect can run before this succeeds.
    if (!store($submission, $storeConfig, $form['fields'])) {
        bbfNotifyError($formId, 'Storage failed', $storeConfig['storage'] . ' backend returned false', $config);
        $storageError = [];
        if ($outboxPath !== '') {
            $deliveryStatus = bbf_delivery_abort_for_storage($outboxPath, $deliveryJobs, $storeConfig);
            $storageError['delivery'] = $deliveryStatus;
            if (($deliveryStatus['state'] ?? '') !== 'attention_required') {
                error_log('BareBonesForms: Storage-failure ledger could not be made attention-required for ' . $submissionId);
            }
        }
        respond(500, 'Submission could not be saved. Please try again later.', $storageError);
    }

    if ($outboxPath !== '') {
        foreach ($deliveryJobs as $job) {
            bbf_delivery_run_job($outboxPath, (string)$job['key'], $storeConfig, $actionResponse);
        }
        $deliveryStatus = bbf_outbox_status($outboxPath);
        if (!($deliveryStatus['ok'] ?? false)) $deliveryStatus = $deliveryAttention;
    }
}

// ─── Success / accepted with unsettled delivery ─────────────────
$extra = $storeEnabled ? array_merge(['submission_id' => $submissionId], $actionResponse) : $actionResponse;
// Form-level redirect (action redirect takes precedence if set)
if (empty($extra['redirect']) && !empty($onSubmit['redirect'])) {
    $extra['redirect'] = interpolate($onSubmit['redirect'], $data);
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

// validateFieldList, validateFormDefinition, validate → moved to bbf_functions.php

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

function storeMysql(array $submission, array $dbConfig): bool {
    try {
        bbf_storage_json($submission); $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
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
    foreach (flattenFields($formFields) as $field) {
        if (in_array($field['type'] ?? 'text', ['section', 'page_break', 'group'], true)) continue;
        if (isset($field['name'])) $fieldNames[] = $field['name'];
    }
    $fieldNames = array_values(array_unique(array_merge($fieldNames, array_keys($submission['data']))));
    $escapedFields = [];
    foreach ($submission['data'] as $name => $value) {
        $cell = is_array($value) ? implode('; ', $value) : (string)$value;
        if (csvNeedsSanitize($cell)) $escapedFields[] = $name;
    }
    // Reserved metadata columns cannot also represent respondent values.
    if (array_intersect(array_merge($metaCols, $versionCols), $fieldNames)) return false;
    return bbf_storage_locked($file, static function () use ($file, $submission, $metaCols, $versionCols, $fieldNames, $escapedFields): bool {
        $source = null;
        try {
            $headers = $metaCols;
            if (file_exists($file)) {
                $source = fopen($file, 'rb');
                if (!$source) return false;
                $stat = fstat($source);
                if ($stat === false) return false;
                if ($stat['size'] > 0) {
                    $headers = fgetcsv($source, 0, ',', '"', '');
                    if (!is_array($headers) || array_slice($headers, 0, 4) !== $metaCols
                        || count(array_unique($headers)) !== count($headers)) return false;
                    bbf_storage_json($headers);
                }
            }
            // Preserve historical order, including deleted/renamed fields; only append new names.
            $union = array_values(array_unique(array_merge($headers, $fieldNames, $versionCols)));
            return bbf_storage_replace($file, static function ($out) use ($source, $headers, $union, $submission, $escapedFields): bool {
                if (!bbf_storage_write_csv($out, $union)) return false;
                if ($source) {
                    while (($old = fgetcsv($source, 0, ',', '"', '')) !== false) {
                        // Refuse ambiguous/truncated records rather than silently discarding cells.
                        if (count($old) !== count($headers)) return false;
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
                    } else {
                        $value = $submission['data'][$name] ?? '';
                    }
                    $row[] = csvSanitize(is_array($value) ? implode('; ', $value) : (string)$value);
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
// buildSummary, buildSummaryRows, createStripeCheckout, updateSubmissionPayment,
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

