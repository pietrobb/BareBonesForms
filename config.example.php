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
    // Token for submissions.php access (REQUIRED)
    // submissions.php is blocked until you set a token here.
    // Generate: php -r "echo bin2hex(random_bytes(16));"
    'api_token' => '',

    // ─── Smoke Test ──────────────────────────────────────────────
    // Token for smoketest.php — validates all forms with generated test data.
    // Leave empty to disable. Generate: php -r "echo bin2hex(random_bytes(16));"
    //
    //   Dry run (default):  smoketest.php?token=TOKEN
    //   Live (real emails):  smoketest.php?token=TOKEN&live=1
    //
    // In live mode, smoke_email acts as the form submitter (fills all email
    // fields, receives confirmation emails). smoke_notify receives admin
    // notifications — use a different address to test reply_to properly.
    // If smoke_notify is empty, it falls back to smoke_email.
    'smoke_token'  => '',
    'smoke_email'  => '', // "submitter" — confirm emails go here
    'smoke_notify' => '', // "admin" — notify emails go here (test Reply-To)

    // ─── Privacy ────────────────────────────────────────────────
    // What metadata to store with each submission (GDPR consideration)
    'store_ip'         => true,   // set false to stop storing IP addresses
    'store_user_agent' => true,   // set false to stop storing user-agent strings

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
