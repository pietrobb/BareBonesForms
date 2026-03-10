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

// ─── Handle checkout.session.completed ──────────────────────────
if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $submissionId = $session['metadata']['bbf_submission_id'] ?? '';
    $formId       = $session['metadata']['bbf_form_id'] ?? '';

    if (!$submissionId || !$formId) {
        error_log('BareBonesForms: Stripe webhook missing bbf metadata');
        http_response_code(200); // acknowledge to avoid retries
        echo json_encode(['received' => true, 'warning' => 'missing metadata']);
        exit;
    }

    // Sanitize IDs
    $formId = preg_replace('/[^a-zA-Z0-9_-]/', '', $formId);

    // Shared functions (sendEmail, renderTemplate, fireWebhook, etc.)
    require_once __DIR__ . '/bbf_functions.php';

    // Update payment status
    $updated = updateSubmissionPayment($submissionId, $formId, 'paid', $session, $config);
    if (!$updated) {
        error_log("BareBonesForms: Failed to update payment status for $submissionId");
    }

    // Load form definition to process deferred on_submit actions
    $formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
    $formFile = $formsDir . '/' . $formId . '.json';
    if (!file_exists($formFile)) {
        error_log("BareBonesForms: Form definition not found: $formId");
        http_response_code(200);
        echo json_encode(['received' => true, 'warning' => 'form not found']);
        exit;
    }

    $form = json_decode(file_get_contents($formFile), true);
    if (!$form) {
        http_response_code(200);
        echo json_encode(['received' => true, 'warning' => 'form parse error']);
        exit;
    }

    // Resolve templates in fields
    if (!empty($form['templates']) && !empty($form['fields'])) {
        $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
    }

    // Load submission data
    $submission = loadSubmission($submissionId, $formId, $config);
    if (!$submission) {
        error_log("BareBonesForms: Submission not found: $submissionId");
        http_response_code(200);
        echo json_encode(['received' => true, 'warning' => 'submission not found']);
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
    $templateData = $data;
    $flatFields = flattenFields($form['fields']);
    foreach ($flatFields as $f) {
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

    // Add payment info to template data
    $templateData['_payment_status'] = 'paid';
    $templateData['_payment_id'] = $session['payment_intent'] ?? $session['id'] ?? '';
    $templateData['_payment_amount'] = number_format(($session['amount_total'] ?? 0) / 100, 2);
    $templateData['_payment_currency'] = strtoupper($session['currency'] ?? '');

    // ─── Confirmation email to respondent ───────────────────────
    if (!empty($onSubmit['confirm_email'])) {
        $ce = $onSubmit['confirm_email'];
        $to = interpolate($ce['to'], $data);
        $subject = interpolate($ce['subject'] ?? 'Thank you', $data);
        $body = renderTemplate(
            ($config['templates_dir'] ?? __DIR__ . '/templates') . '/' . basename($ce['template'] ?? 'confirm.html'),
            array_merge($templateData, [
                '_form'    => $form['name'] ?? $formId,
                '_id'      => $submissionId,
                '_time'    => $timestamp,
                '_summary' => buildSummary($form['fields'], $data),
            ])
        );
        sendEmail($to, $subject, $body, $config['mail']);
    }

    // ─── Notification email to owner ────────────────────────────
    if (!empty($onSubmit['notify'])) {
        $n = $onSubmit['notify'];
        $toRaw = $n['to'];
        if (is_array($toRaw)) {
            $toRaw = implode(', ', array_map(fn($t) => interpolate($t, $data), $toRaw));
        } else {
            $toRaw = interpolate($toRaw, $data);
        }
        $subject = interpolate($n['subject'] ?? "New submission: $formId", $data);
        $body = renderTemplate(
            ($config['templates_dir'] ?? __DIR__ . '/templates') . '/' . basename($n['template'] ?? 'notify.html'),
            array_merge($templateData, [
                '_form'     => $form['name'] ?? $formId,
                '_id'       => $submissionId,
                '_time'     => $timestamp,
                '_summary'  => buildSummary($form['fields'], $data),
            ])
        );
        sendEmail($toRaw, $subject, $body, $config['mail']);
    }

    // ─── Webhooks (signed) ──────────────────────────────────────
    if (!empty($onSubmit['webhooks'])) {
        $webhookPayloadSecret = $config['webhook_secret'] ?? '';
        // Include payment info in webhook payload
        $submission['meta']['payment_status'] = 'paid';
        $submission['meta']['payment_id'] = $session['payment_intent'] ?? $session['id'] ?? '';
        $submission['meta']['payment_amount'] = ($session['amount_total'] ?? 0) / 100;
        $submission['meta']['payment_currency'] = $session['currency'] ?? '';
        foreach ($onSubmit['webhooks'] as $url) {
            fireWebhook($url, $submission, $webhookPayloadSecret);
        }
    }

    // ─── Custom actions ─────────────────────────────────────────
    if (!empty($onSubmit['actions'])) {
        foreach ($onSubmit['actions'] as $action) {
            $actionFile = __DIR__ . '/actions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $action['type'] ?? '') . '.php';
            if (file_exists($actionFile)) {
                include $actionFile;
            }
        }
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
    $storage = $config['storage'] ?? 'file';

    switch ($storage) {
        case 'file':
            $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/' . $formId . '/' . $id . '.json';
            if (!file_exists($file)) return null;
            return json_decode(file_get_contents($file), true);

        case 'sqlite':
            try {
                $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
                $pdo = new PDO("sqlite:$dbFile");
                $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ?");
                $stmt->execute([$id]);
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
                $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ?");
                $stmt->execute([$id]);
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
