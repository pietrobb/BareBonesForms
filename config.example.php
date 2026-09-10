<?php
/**
 * BareBonesForms — Configuration
 *
 * Copy this file to config.php and edit.
 * BareBonesForms will not run without config.php.
 */

// Security: prevent direct browser access (only included by BBF scripts)
defined('BBF_LOADED') || exit;

return [

    // ─── Storage ────────────────────────────────────────────────
    // "file"   = JSON files in /submissions (zero config, works everywhere)
    // "sqlite" = SQLite database (file-based, zero config, SQL capable)
    // "mysql"  = MySQL / MariaDB (fill in credentials below)
    // "csv"    = CSV files in /submissions (one file per form, human-readable)
    'storage' => 'file',

    'sqlite' => [
        'path' => __DIR__ . '/submissions/bbf.sqlite',
    ],

    'mysql' => [
        'host'     => 'localhost',
        'database' => 'barebones_forms',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // ─── Email ──────────────────────────────────────────────────
    // Uses PHP mail() by default. For production, use SMTP.
    'mail' => [
        'method'    => 'mail', // "mail" or "smtp"
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_enc'  => 'tls', // "tls" or "ssl"
        'from_email' => 'noreply@example.com',
        'from_name'  => 'BareBonesForms',
    ],

    // ─── Security ───────────────────────────────────────────────
    // CSRF protection (session-based, same-origin only)
    // Disable if you only use cross-origin embedding
    'csrf' => true,

    // Allowed origins for CORS (empty = same origin only)
    'allowed_origins' => [],

    // Rate limiting: max submissions per IP per minute
    'rate_limit' => 10,

    // Honeypot field name (anti-spam, hidden field)
    'honeypot_field' => '_bbf_hp',

    // Webhook signing secret (HMAC-SHA256)
    // When set, all webhook POSTs include X-BBF-Signature header
    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'webhook_secret' => '',

    // Durable delivery retries for stored submissions. Attempts are capped at 10.
    // Webhooks require HTTPS on port 443; store=false delivery is synchronous and not retryable.
    'delivery' => [
        'max_attempts' => 3,
        'retry_delay'  => 60,
        'lease_seconds' => 300,
    ],

    // ─── Stripe (payments) ──────────────────────────────────────
    // Required only if you use on_submit.payment in any form.
    // Get keys from https://dashboard.stripe.com/apikeys
    'stripe' => [
        'secret_key'     => '',  // sk_test_... or sk_live_...
        'webhook_secret' => '',  // whsec_... (from Stripe → Webhooks → Signing secret)
    ],

    // ─── Error notifications ─────────────────────────────────────
    // Email address to notify when form processing fails
    // (storage, email, or webhook errors). Max one email per 24 hours.
    // Leave empty to disable.
    'error_notify' => '',

    // ─── API ────────────────────────────────────────────────────
    // Legacy unrestricted admin; required even on loopback. Prefer X-BBF-Token over URL credentials.
    // Scoped records: ['id'=>'reader-1', 'token'=>'RANDOM_SECRET', 'forms'=>['contact'], 'permissions'=>['read'], 'expires_at'=>'2027-01-01T00:00:00Z', 'revoked'=>false].
    // Exact form IDs; read+export for CSV/forward, read+delete for deletion, read+review for inbox metadata; empty lists grant nothing. Malformed/duplicate records disable all access. Editor is legacy-admin only.
    'api_token' => '', 'access_tokens' => [], 'auth_session_idle' => 1800, 'auth_session_absolute' => 28800, // Seconds; config is re-resolved each request. logs_dir/access-audit.php must be writable; failure blocks access.

    // Fixed operator-owned installation URL for check.php probes and live smoke POSTs.
    // No Host-derived fallback or redirects; empty disables outgoing diagnostics.
    'diagnostic_base_url' => '', // e.g. https://forms.example.com/bbf (no credentials/query/fragment)
    // Smoke uses a SEPARATE credential, only in X-BBF-Smoke-Token; never URL tokens.
    // Dry: GET smoketest.php; live (real storage/emails): POST smoketest.php?live=1.
    // Admin/scoped cookies and X-BBF-Token cannot authorize smoke. Config refreshed per request.
    // Empty disables HTTP smoke; generate: php -r "echo bin2hex(random_bytes(32));"
    // Tokens must be 1–512 printable ASCII characters, without whitespace/control bytes.
    // CLI is trusted local access: php smoketest.php [form_id] [--live]; live needs a valid token.
    // Access is audited in logs_dir/access-audit.php; audit failure blocks work before outgoing requests.
    // Live email fields/confirmations use smoke_email; admin notices use smoke_notify (or smoke_email).
    'smoke_token'  => '',
    'smoke_email'  => '', // "submitter" — confirm emails go here
    'smoke_notify' => '', // "admin" — notify emails go here (test Reply-To)

    // ─── Privacy ────────────────────────────────────────────────
    // What metadata to store with each submission (GDPR consideration)
    'store_ip'         => true,   // set false to stop storing IP addresses
    'store_user_agent' => true,   // set false to stop storing user-agent strings
    // Respondent drafts are enabled per form. This protected directory stores only allowlisted fields.
    'drafts_dir'       => __DIR__ . '/submissions/drafts',
    // Retention is OFF unless explicitly enabled. Run `php maintenance.php retention --form=FORM` first;
    // destructive runs require `--apply --confirm=EXACT_DIGEST`. Keep archives outside the web application and data paths.
    'retention' => [
        'enabled' => false,
        'days' => 0,
        'batch_limit' => 100,
        'archive_dir' => dirname(__DIR__) . '/barebonesforms-private/retention',
    ],
    // Logical backups are form-scoped, integrity checked and contain no access credentials.
    // Keep this directory private. Restore is a dry run unless --apply and its exact confirmation are supplied.
    'backup' => [
        'directory' => dirname(__DIR__) . '/barebonesforms-private/backups',
    ],

    // ─── Sandbox ─────────────────────────────────────────────────
    // Enable sandbox mode for testing forms without side effects.
    // When enabled, sandbox.php lets you test validation, preview
    // emails/webhooks, and submit without storing or sending anything.
    // Disable in production.
    'sandbox' => false,

    // ─── Language ───────────────────────────────────────────────
    // Server-side validation messages language.
    // Language files live in /lang (en.php, de.php, sk.php, …).
    // Client-side language is set per form via data-lang attribute.
    'lang' => 'en',

    // ─── Viewer branding ────────────────────────────────────────
    // Customize the viewer header with your own branding
    'viewer' => [
        'site_name' => 'BareBonesForms',  // Shown in viewer header
        'logo_url'  => '',                // URL to logo image (optional)
    ],

    // ─── Paths ──────────────────────────────────────────────────
    'forms_dir'       => __DIR__ . '/forms',
    'submissions_dir' => __DIR__ . '/submissions',
    'templates_dir'   => __DIR__ . '/templates',
    'logs_dir'        => __DIR__ . '/logs',

];
