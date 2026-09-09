<?php
/**
 * BareBonesForms — Payment Webhook Handler  v1.0.1
 *
 * Receives Stripe webhook events (checkout.session.completed),
 * updates submission payment_status, and triggers deferred
 * emails/webhooks/actions.
 *
 * Stripe Dashboard → Webhooks → Add endpoint:
 *   URL: https://yoursite.com/path/payment.php
 *   Events: checkout.session.completed
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
define('BBF_LOADED', true);

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit;
}

$config = require __DIR__ . '/config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// Read raw payload
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify Stripe webhook signature
$webhookSecret = $config['stripe']['webhook_secret'] ?? '';
if ($webhookSecret === '') {
    error_log('BareBonesForms: payment.php called but stripe.webhook_secret is not configured');
    http_response_code(500);
    exit;
}

if (!verifyStripeSignature($payload, $sigHeader, $webhookSecret)) {
    error_log('BareBonesForms: Invalid Stripe webhook signature');
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

// ─── Handle Checkout payment events ─────────────────────────────
$paymentEventTypes = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'];
if (in_array($event['type'], $paymentEventTypes, true)) {
    $eventId = $event['id'] ?? '';
    if (!is_string($eventId) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $eventId)) {
        http_response_code(400);
        echo json_encode(['received' => false, 'error' => 'invalid event id']);
        exit;
    }
    $session = $event['data']['object'] ?? [];
    $submissionId = $session['metadata']['bbf_submission_id'] ?? '';
    $formId       = $session['metadata']['bbf_form_id'] ?? '';

    if (!is_string($submissionId) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $submissionId)
        || !is_string($formId) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $formId)) {
        error_log('BareBonesForms: Stripe webhook missing or invalid bbf metadata');
        http_response_code(400);
        echo json_encode(['received' => false, 'error' => 'invalid metadata']);
        exit;
    }

    // Shared functions (storage, trusted expectation verification, delivery actions).
    require_once __DIR__ . '/bbf_functions.php';
    $expectedSubmission = loadSubmission($submissionId, $formId, $config);
    if (!$expectedSubmission) {
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'submission unavailable']);
        exit;
    }
    $expectedMeta = $expectedSubmission['meta'] ?? null;
    if (!is_array($expectedMeta)
        || !isset($expectedMeta['payment_expected_amount_minor'], $expectedMeta['payment_expected_currency'], $expectedMeta['payment_checkout_session_id'])) {
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'stored payment expectation unavailable']);
        exit;
    }
    $expectedMinorUnits = $expectedMeta['payment_quote']['minor_units'] ?? 2;
    if (!is_int($expectedMinorUnits) || $expectedMinorUnits < 0 || $expectedMinorUnits > 3) {
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'stored payment exponent unavailable']);
        exit;
    }
    $isFailure = $event['type'] === 'checkout.session.async_payment_failed';
    $isSuccess = !$isFailure && ($session['payment_status'] ?? null) === 'paid';
    if (!bbfPaymentSessionMatches($expectedSubmission, $session, $isSuccess)) {
        error_log("BareBonesForms: Stripe callback does not match persisted expectation for $submissionId");
        http_response_code(409);
        echo json_encode(['received' => false, 'error' => 'payment expectation mismatch']);
        exit;
    }

    $formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
    $formFile = $formsDir . '/' . $formId . '.json';
    $formRaw = is_file($formFile) ? file_get_contents($formFile) : false;
    $form = $formRaw !== false ? json_decode($formRaw, true) : null;
    if (!is_array($form)) {
        error_log("BareBonesForms: Form definition unavailable: $formId");
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'form unavailable']);
        exit;
    }

    $outboxPath = bbf_outbox_path($config, $formId, $submissionId);
    $eventOutboxPath = $outboxPath !== '' ? $outboxPath . '.events' : '';
    $maxAttempts = (int)($config['delivery']['max_attempts'] ?? 3);
    // Payment event deduplication must be durable before transition, but the final
    // delivery plan is not known until paid metadata and template data are ready.
    $eventOutbox = $eventOutboxPath !== ''
        ? bbf_outbox_init($eventOutboxPath, "$formId:$submissionId:events", [], $maxAttempts)
        : ['ok' => false];
    if (!($eventOutbox['ok'] ?? false)) {
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'delivery state unavailable']);
        exit;
    }
    bbf_outbox_expire_leases($eventOutboxPath, (int)($config['delivery']['lease_seconds'] ?? 300));
    $targetStatus = $isFailure ? 'failed' : ($isSuccess ? 'paid' : 'pending');
    $recorded = bbf_outbox_record_event($eventOutboxPath, $eventId, (string)$session['id'] . ':' . $targetStatus, $targetStatus);
    if (!($recorded['ok'] ?? false)) {
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'payment event persistence failed']);
        exit;
    }

    $transition = transitionSubmissionPayment($submissionId, $formId, $targetStatus, $session, $config, $expectedMinorUnits);
    if (!($transition['ok'] ?? false)) {
        error_log("BareBonesForms: Failed to update payment status for $submissionId");
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'payment state update failed']);
        exit;
    }
    $effectiveStatus = $transition['status'];
    if ($isFailure || !$isSuccess) {
        http_response_code(200);
        echo json_encode(['received' => true, 'payment_status' => $effectiveStatus]);
        exit;
    }

    // Resolve templates in fields
    if (!empty($form['templates']) && !empty($form['fields'])) {
        $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
    }

    // Load submission data
    $submission = loadSubmission($submissionId, $formId, $config);
    if (!$submission) {
        error_log("BareBonesForms: Submission unavailable after payment update: $submissionId");
        http_response_code(500);
        echo json_encode(['received' => false, 'error' => 'submission unavailable']);
        exit;
    }

    $data = $submission['data'] ?? [];
    $onSubmit = $form['on_submit'] ?? [];
    $timestamp = $submission['meta']['submitted'] ?? date('c');

    // ─── Server-side i18n (for email templates) ─────────────────
    $langCode = $config['lang'] ?? 'en';
    $langFile = __DIR__ . '/lang/' . preg_replace('/[^a-z0-9-]/', '', $langCode) . '.php';
    $messages = file_exists($langFile) ? require $langFile : [];

    // ─── Resolve option labels for templates ────────────────────
    $templateData = bbf_delivery_template_data($form, $submission);

    // Add payment info to template data
    $templateData['_payment_status'] = 'paid';
    $templateData['_payment_id'] = $session['payment_intent'] ?? $session['id'] ?? '';
    $templateData['_payment_amount'] = bbfPaymentFormatMinor((int)$expectedMeta['payment_expected_amount_minor'], $expectedMinorUnits);
    $templateData['_payment_currency'] = strtoupper($session['currency'] ?? '');
    // Only now is the final immutable effect plan complete. The event ledger above
    // intentionally contains no delivery jobs and cannot freeze a coarse plan.
    try {
        $jobs = bbf_delivery_prepare_jobs($form, $submission, $config, $templateData);
    } catch (Throwable $error) {
        error_log("BareBonesForms: Failed to prepare paid delivery for $submissionId");
        http_response_code(503);
        echo json_encode(['received' => false, 'error' => 'delivery remains unsettled']);
        exit;
    }
    $outbox = $outboxPath !== ''
        ? bbf_outbox_init($outboxPath, "$formId:$submissionId", $jobs, $maxAttempts)
        : ['ok' => false];
    if (!($outbox['ok'] ?? false)) {
        http_response_code(503);
        echo json_encode(['received' => false, 'error' => 'delivery remains unsettled']);
        exit;
    }
    $leases = bbf_outbox_expire_leases($outboxPath, (int)($config['delivery']['lease_seconds'] ?? 300));
    if (!($leases['ok'] ?? false)) {
        http_response_code(503);
        echo json_encode(['received' => false, 'error' => 'delivery remains unsettled']);
        exit;
    }

    $actionResponse = [];
    foreach ($jobs as $job) {
        bbf_delivery_run_job($outboxPath, (string)$job['key'], $config, $actionResponse);
    }

    // Provider acknowledgement depends only on the durable ledger, never on an
    // in-memory adapter return that may disagree with its persisted completion.
    $settlement = bbf_outbox_settlement($outboxPath);
    $retryableFailure = !($settlement['ok'] ?? false) || !($settlement['settled'] ?? false);
    if ($retryableFailure) {
        http_response_code(503);
        echo json_encode(['received' => false, 'error' => 'delivery remains unsettled']);
        exit;
    }
    error_log("BareBonesForms: Payment confirmed for $submissionId (form: $formId)");
}

// Acknowledge all events (even unhandled ones) to prevent Stripe retries
http_response_code(200);
echo json_encode(['received' => true]);
exit;


// ═════════════════════════════════════════════════════════════════
// Functions
// ═════════════════════════════════════════════════════════════════

/**
 * Verify Stripe webhook signature (HMAC-SHA256).
 */
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $item) {
        $kv = explode('=', $item, 2);
        if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
    }

    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';
    if ($timestamp === '' || $signature === '') return false;

    // Reject timestamps older than 5 minutes (replay protection)
    if (abs(time() - (int)$timestamp) > 300) return false;

    $signedPayload = "$timestamp.$payload";
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Load a submission from storage.
 */
function loadSubmission(string $id, string $formId, array $config): ?array {
    try { $config = bbf_effective_storage_config($config, $formId); } catch (Throwable $e) { return null; } $storage = $config['storage'];

    switch ($storage) {
        case 'file':
            $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $id . '.json';
            if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $id) || !is_file($file)) return null;
            $record = json_decode(file_get_contents($file), true); return is_array($record) && ($record['id'] ?? null) === $id && ($record['form'] ?? null) === $formId ? $record : null;

        case 'sqlite':
            try {
                $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
                $pdo = new PDO("sqlite:$dbFile");
                $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND " . ($storage === 'mysql' ? 'BINARY form_id = BINARY ?' : 'form_id = ?'));
                $stmt->execute([$id, $formId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return null;
                return [
                    'id'   => $row['id'],
                    'form' => $row['form_id'],
                    'data' => json_decode($row['data'], true) ?? [],
                    'meta' => json_decode($row['meta'], true) ?? [],
                ];
            } catch (PDOException $e) { return null; }

        case 'mysql':
            try {
                $db = $config['mysql'];
                $pdo = new PDO("mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}", $db['username'], $db['password']);
                $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND " . ($storage === 'mysql' ? 'BINARY form_id = BINARY ?' : 'form_id = ?'));
                $stmt->execute([$id, $formId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return null;
                return [
                    'id'   => $row['id'],
                    'form' => $row['form_id'],
                    'data' => json_decode($row['data'], true) ?? [],
                    'meta' => json_decode($row['meta'], true) ?? [],
                ];
            } catch (PDOException $e) { return null; }

        default:
            return null;
    }
}
