# BareBonesForms  v1.0.1

**Zero-build PHP forms for shared hosting.**

Define a form as JSON. Upload to hosting. Embed. Collect submissions. Done.

PHP 8.1+ · File / SQLite / MySQL / CSV · SMTP + Webhooks · 32 Languages · Shared-hosting friendly

**[Documentation →](docs.html)** · **[Sandbox →](sandbox.php)** · **[Viewer →](viewer.php)** · **[Editor →](editor.php)** · **[Installation Check →](check.php)** · **[Live Demos →](demo1.html)**

---

## Embed in 30 Seconds

```html
<div data-form="kontakt"></div>
<script src="bbf.js"></script>
```

That's it. Two lines. `bbf.js` auto-loads `bbf.css` from the same directory — no `<link>` tag needed. The form definition is fetched via `submit.php`, validated client-side, and submitted back to `submit.php`.

---

## What You Get

- **JSON-defined forms** — One file per form. IDE autocomplete via included JSON Schema.
- **Server-side validation** — Required, email, URL, tel, number, date, min/max, regex, options, email confirmation. The client validates too, but the server has the final word.
- **Four storage backends** — File (JSON), SQLite (WAL), MySQL/MariaDB, CSV. One config line.
- **Email notifications** — Confirmation to the submitter + notification to you. SMTP or PHP mail().
- **Signed webhooks** — HMAC-SHA256 via `X-BBF-Signature`. Integrate with n8n, Zapier, your own endpoints.
- **Custom actions** — Drop a PHP file in `actions/`, reference it in your form JSON. Your code, your rules.
- **Sandbox mode** — Test everything without storing, sending, or executing anything.
- **32 languages** — Validation messages and UI strings. Client-side and server-side. Add your own in minutes.
- **Cross-domain embedding** — Host forms on one server, embed on another. CORS handled.
- **CSRF protection** — Session-based HMAC tokens, automatic via `bbf.js`.
- **Multi-page forms** — Split forms with page breaks. Previous/Next navigation with per-page validation.
- **Conditional logic** — Show/hide fields based on other field values. Supports `all`/`any` compound conditions with nesting. Nine comparison operators: equals, not, gt, gte, lt, lte, contains, empty, not_empty. Hidden fields excluded from validation and submission. Optional smooth animations via `"animate_conditions": true`. Per-option `show_if` on radio/checkbox/select for dynamic option filtering.
- **Cross-field validation** — Form-level rules: `min_sum` (total of numeric fields) and `min_filled` (at least N fields filled). Client + server validated.
- **Field groups** — Container type with `show_if` — show or hide a whole section of fields with one condition. Children inherit the parent's visibility rule.
- **Reusable templates** — Define a set of fields once, reference it from multiple groups with `"use"` + `"prefix"`. One template, many instances.
- **Star ratings** — Accessible rating widget with keyboard and screen reader support.
- **Honeypot + rate limiting** — Built-in bot protection. No external services needed.
- **Submissions API** — List, filter, export as JSON or CSV. Token-authenticated. Quick export via `?last=7d`.
- **Submissions Viewer** — Built-in dashboard (`viewer.php`) for browsing, searching, and exporting submissions.
- **Form Editor** — Visual JSON editor (`editor.php`) with live preview, schema validation, and field snippets.

**Built for:** PHP shared hosting, small–medium websites, developers who want control.

**Not for:** Drag-and-drop form builders, enterprise workflow suites, or dashboards with seventeen menu items.

---

## Live Demos

Eight demos, each building on the previous. From "hello world" to business logic:

| # | Demo | What it shows |
|---|------|---------------|
| **1** | [Quick Feedback](demo1.html) | Baseline — text, email, radio, textarea, storage, CSRF + honeypot |
| **2** | [Event Registration](demo2.html) | Select, multi-checkbox, admin notification, email templates |
| **3** | [Bug Report](demo3.html) | Full pipeline — storage + notify + webhook + confirm email + HMAC |
| **4** | [Advanced Features](demo4.html) | **Core showcase** — multi-page, `show_if`, rating, `other`, confirm email, pattern validation, columns, sizes, date min/max |
| **5** | [Allergy Remedy Finder](demo5.html) | Groups, nested `all`/`any` conditions, cross-page condition evaluation, client-side scoring engine |
| **6** | [Print Shop Order](demo6.html) | Conditional product groups, variant price multipliers, volume discounts, live pricing sidebar |
| **7** | [Spanish Inquisition Quiz](demo7.html) | `shuffle` on options, post-submit results panel, scoring, `data-hide-on-success` |
| **8** | [Homeopathic Modalities](demo8.html) | **Reusable templates** — define fields once, use with `"use"` + `"prefix"` across multiple groups |

Demos 1–3 cover backend configuration. Demo 4 showcases the form engine's core. Demos 5–8 prove it can handle decision flows, business logic, reusable components, and interactive experiences — not just contact forms.

---

## Quick Start

```
1. Upload the barebonesforms/ folder to your hosting
2. Copy config.example.php → config.php
3. Fill in storage and mail settings
4. Open check.php in your browser — fix any errors it reports
5. Delete check.php — it exposes server details
6. Create a JSON file in forms/
7. Embed: <div data-form="kontakt"></div>
         <script src="bbf.js"></script>
8. Done.
```

---

## How It Works

```
You define this:          BareBonesForms handles the rest:
┌──────────────┐         ┌──────────────────────────────┐
│ kontakt.json │───────▶ │ ✓ Render HTML form           │
└──────────────┘         │ ✓ Client + server validation  │
                         │ ✓ Store submission             │
                         │ ✓ Confirmation email           │
                         │ ✓ Admin notification           │
                         │ ✓ Signed webhooks              │
                         │ ✓ Custom post-submit actions   │
                         └──────────────────────────────┘
```

One JSON file in, the whole pipeline out. No build step. No bundler. No twelve config files named slightly differently. One truth, one source, one JSON.

---

## Form JSON Reference

Every form uses `schema_version: 1`. The included `form.schema.json` provides IDE autocomplete.

```json
{
    "$schema": "form.schema.json",
    "schema_version": 1,
    "id": "kontakt",
    "name": "Contact Form",
    "description": "Optional description shown above the form.",
    "submit_label": "Send",
    "success_message": "Thank you!",
    "label_position": "top",
    "animate_conditions": true,

    "fields": [
        {
            "name": "email",
            "type": "email",
            "label": "Email",
            "placeholder": "john@example.com",
            "required": true
        }
    ],

    "on_submit": {
        "store": true,
        "confirm_email": {
            "to": "{{email}}",
            "subject": "Thanks, {{name}}!",
            "template": "confirm.html"
        },
        "notify": {
            "to": ["admin@example.com", "backup@example.com"],
            "subject": "New contact: {{name}}",
            "reply_to": "{{email}}",
            "template": "notify.html"
        },
        "webhooks": ["https://n8n.example.com/webhook/kontakt"],
        "redirect": "https://example.com/thank-you",
        "actions": [{ "type": "my_custom_action", "param": "value" }]
    }
}
```

### Email Reply-To

Both `confirm_email` and `notify` support an optional `reply_to` property with `{{field}}` interpolation:

```json
"confirm_email": {
    "to": "{{email}}",
    "subject": "We received your message",
    "reply_to": "support@example.com",
    "template": "confirm.html"
},
"notify": {
    "to": "admin@example.com",
    "subject": "New: {{name}}",
    "reply_to": "{{email}}",
    "template": "notify.html"
}
```

When `reply_to` is set on `notify`, the admin clicks Reply and responds directly to the submitter. When omitted, Reply-To defaults to `mail.from_email` from `config.php`.

### Field Types

| Type         | Renders as       | Validates                 |
|--------------|------------------|---------------------------|
| `text`       | Text input       | length, pattern           |
| `email`      | Email input      | email format, confirm     |
| `tel`        | Phone input      | phone pattern             |
| `url`        | URL input        | URL format                |
| `number`     | Number input     | numeric, min/max          |
| `date`       | Date picker      | date min/max              |
| `textarea`   | Textarea         | length                    |
| `select`     | Dropdown         | valid option, "other"     |
| `radio`      | Radio buttons    | valid option, "other"     |
| `checkbox`   | Checkboxes       | valid option, "other"     |
| `password`   | Password input   | length, pattern           |
| `hidden`     | Hidden input     | —                         |
| `rating`     | Star rating      | numeric, min/max          |
| `group`      | Field container  | — (children validated)    |
| `section`    | Visual divider   | — (not submitted)         |
| `page_break` | Multi-page split | — (not submitted)         |

### Field Properties

| Property          | Type    | Description                                          |
|-------------------|---------|------------------------------------------------------|
| `name`            | string  | Field identifier (required, unique)                  |
| `type`            | string  | Field type (default: `"text"`)                       |
| `label`           | string  | Display label                                        |
| `placeholder`     | string  | Placeholder text                                     |
| `description`     | string  | Help text below label                                |
| `required`        | boolean | Is required? (default: `false`)                      |
| `minlength`       | integer | Minimum character length                             |
| `maxlength`       | integer | Maximum character length                             |
| `min`             | number  | Minimum value (number/date)                          |
| `max`             | number  | Maximum value (number/date)                          |
| `pattern`         | string  | Regex validation pattern                             |
| `pattern_message` | string  | Custom error for pattern mismatch                    |
| `options`         | array   | Options for select/radio/checkbox. Object form: `{value, label, checked?}` |
| `options_from`    | string  | URL returning options as JSON `[{value, label}, ...]`. Use instead of static `options`. |
| `lookup`          | object  | Auto-fill other fields from API: `{url, trigger, map}`. See "Lookup" section. |
| `autocomplete_from` | string/object | Typeahead suggestions from URL. String shorthand or `{url, min_length, debounce}`. |
| `rows`            | integer | Textarea rows (default: 4)                           |
| `value`           | string/array | Default value. String for radio, array for checkbox (`["a","b"]`) |
| `label_position`  | string  | `"left"` puts label beside input instead of above    |
| `prefix`          | string  | Text before input (e.g. `"€"`)                       |
| `suffix`          | string  | Text after input (e.g. `"kg"`, `"ks"`)               |
| `size`            | string  | Field width: `"small"`, `"medium"`, `"large"`        |
| `css_class`       | string  | Custom CSS class on field wrapper                    |
| `columns`         | int/str | Radio/checkbox layout: `2`, `3`, or `"inline"`       |
| `readonly`        | boolean | Makes the field read-only                            |
| `other`           | boolean | Adds "Other" option with text input                  |
| `other_label`     | string  | Label for "Other" option (default: `"Other"`)        |
| `confirm`         | boolean | Adds confirmation field (email type)                 |
| `shuffle`         | boolean | Randomize option order (radio/checkbox/select) or child field order (group) |
| `autocomplete`    | string  | Browser autocomplete hint (`"email"`, `"tel"`, `"given-name"`, etc.) |
| `show_if`         | object  | Conditional visibility: `{field, value, op?}` or `{all: [...]}` / `{any: [...]}` |
| `title`           | string  | Title for section or group                           |
| `fields`          | array   | Child fields (group type only)                       |

---

## Embedding

### Basic (auto-init)

```html
<div data-form="kontakt"></div>
<script src="/path/to/bbf.js"></script>
```

`bbf.js` auto-loads `bbf.css` from the same directory. No separate `<link>` tag required.

### Manual

```html
<div id="my-form"></div>
<script src="/path/to/bbf.js"></script>
<script>
    BBF.render('kontakt', '#my-form', {
        showTitle: false,
        hideOnSuccess: true,
        lang: 'sk',
        onSuccess: function(response) { /* handle response */ },
        onError: function(response) { /* handle error */ }
    });
</script>
```

### Cross-domain

```html
<div id="my-form"></div>
<script>
    BBF.render('kontakt', '#my-form', {
        baseUrl: 'https://forms.example.com/barebonesforms/'
    });
</script>
```

Add the origin to `allowed_origins` in `config.php`. Fail to do so, and CORS will deny your request.

---

## Generated HTML & CSS Classes

When `bbf.js` renders a form, it produces this DOM structure. These are the classes you target for styling:

```html
<div class="bbf-form-container">                   <!-- outer wrapper (the div you placed) -->
  <form class="bbf-form bbf-labels-top">            <!-- form element; labels-top/left/right -->
    <h2 class="bbf-title">Form Title</h2>           <!-- if data-show-title="true" -->
    <p class="bbf-description">Description</p>

    <!-- ── Text / email / number / date / textarea / select ── -->
    <div class="bbf-field bbf-field-text">           <!-- bbf-field-{type} for each type -->
      <label class="bbf-label">Name <span class="bbf-required">*</span></label>
      <p class="bbf-field-desc">Help text</p>
      <input class="bbf-input">                      <!-- or <textarea>, <select> -->
      <div class="bbf-field-error"></div>             <!-- error message (empty until invalid) -->
    </div>

    <!-- ── Radio / checkbox ── -->
    <fieldset class="bbf-fieldset bbf-field bbf-field-radio">
      <legend class="bbf-label">Choose one</legend>
      <div class="bbf-options bbf-columns-2">        <!-- columns-2 / columns-3 / columns-inline -->
        <label class="bbf-option"><input type="radio"> Option A</label>
        <label class="bbf-option"><input type="radio"> Option B</label>
        <label class="bbf-option bbf-option-other"><input type="radio"> Other
          <input class="bbf-input bbf-other-input">
        </label>
      </div>
      <div class="bbf-field-error"></div>
    </fieldset>

    <!-- ── Rating ── -->
    <div class="bbf-field bbf-field-rating">
      <div class="bbf-rating-stars">
        <span class="bbf-star bbf-star-active">★</span>  <!-- bbf-star-hover on mouseover -->
      </div>
    </div>

    <!-- ── Section divider ── -->
    <div class="bbf-field bbf-section">
      <h3 class="bbf-section-title">Section</h3>
      <p class="bbf-section-desc">Description</p>
    </div>

    <!-- ── Group (field container with show_if) ── -->
    <div class="bbf-field bbf-group">
      <h4 class="bbf-group-title">Group Title</h4>
      <!-- child fields rendered here -->
    </div>

    <!-- ── Multi-page navigation ── -->
    <div class="bbf-page"><!-- page content --></div>
    <div class="bbf-field bbf-page-nav">
      <button class="bbf-prev">Previous</button>
      <span class="bbf-page-indicator">Page 1 of 3</span>
      <button class="bbf-next">Next</button>
      <button class="bbf-submit">Submit</button>
    </div>

    <!-- ── Single-page submit ── -->
    <div class="bbf-field bbf-submit-wrap">
      <button class="bbf-submit">Submit</button>
    </div>

    <div class="bbf-message bbf-success">Thank you!</div>  <!-- or bbf-error -->
  </form>
</div>
```

### Size and state modifiers

| Class | Applied to | Meaning |
|-------|-----------|---------|
| `bbf-size-small` | `.bbf-field` | Field width ~33% |
| `bbf-size-medium` | `.bbf-field` | Field width ~50% |
| `bbf-has-error` | `.bbf-field` | Validation failed — added/removed dynamically |
| `bbf-readonly` | `.bbf-input` | Read-only field |
| `bbf-labels-top` | `.bbf-form` | Labels above fields (default) |
| `bbf-labels-left` | `.bbf-form` | Labels left of fields |

### Quick styling examples

```css
/* Change submit button */
.bbf-submit { background: #2563eb; border-radius: 8px; }

/* Error state */
.bbf-has-error .bbf-input { border-color: #ef4444; }

/* Section titles */
.bbf-section-title { font-size: 1.25rem; color: #1a1a1a; }

/* Star rating colors */
.bbf-star-active { color: #f59e0b; }
```

> **Full CSS class reference with 21 entries** → see [docs.html](docs.html) § Styling & CSS Classes.
> **Working examples** → open any [demo page](demo1.html) and inspect the generated HTML.

---

## Language Support

BareBonesForms ships with **32 language packs** — both client-side (validation messages, button labels, status text) and server-side (validation error messages returned by the API).

### Included Languages

| Code    | Language                       | Code    | Language                        |
|---------|--------------------------------|---------|----------------------------------|
| `en`    | English (default)              | `nl`    | Nederlands (Dutch)               |
| `de`    | Deutsch (German)               | `pl`    | Polski (Polish)                  |
| `fr`    | Français (French)              | `cs`    | Čeština (Czech)                  |
| `es`    | Español (Spanish)              | `sk`    | Slovenčina (Slovak)              |
| `it`    | Italiano (Italian)             | `hu`    | Magyar (Hungarian)               |
| `pt`    | Português (Portuguese)         | `ro`    | Română (Romanian)                |
| `pt-br` | Português Brasileiro          | `bg`    | Български (Bulgarian)            |
| `sv`    | Svenska (Swedish)              | `el`    | Ελληνικά (Greek)                 |
| `da`    | Dansk (Danish)                 | `tr`    | Türkçe (Turkish)                 |
| `nb`    | Norsk bokmål (Norwegian)       | `ru`    | Русский (Russian)                |
| `fi`    | Suomi (Finnish)                | `uk`    | Українська (Ukrainian)           |
| `et`    | Eesti (Estonian)               | `id`    | Bahasa Indonesia                 |
| `ja`    | 日本語 (Japanese)              | `ko`    | 한국어 (Korean)                   |
| `zh`    | 中文简体 (Chinese Simplified)   | `zh-tw` | 繁體中文 (Chinese Traditional)    |
| `ar`    | العربية (Arabic)               | `hi`    | हिन्दी (Hindi)                   |
| `th`    | ไทย (Thai)                     | `tlh`   | tlhIngan Hol (Klingon) — Qapla'! |

### Client-side Usage

```html
<script src="bbf.js"></script>
<script src="lang/de.js"></script>
<div data-form="kontakt" data-lang="de"></div>
```

Or set language via JavaScript:

```javascript
BBF.render('kontakt', '#my-form', { lang: 'de' });
```

### Server-side Usage

Set in `config.php`:

```php
'lang' => 'de',
```

Server-side validation error messages will be returned in the configured language. Falls back to English for any missing keys.

### Adding Your Own Language

Create two files in the `lang/` directory:

**Client-side** (`lang/xx.js`):
```javascript
BBF.registerLang('xx', {
    loading:       'Loading…',
    submitDefault: 'Submit',
    required:      '{label} is required.',
    // ... see lang/en.js for all 25 keys
});
```

**Server-side** (`lang/xx.php`):
```php
<?php
return [
    'required'      => '{label} is required.',
    'invalidEmail'  => '{label} must be a valid email.',
    // ... see lang/en.php for all 14 keys
];
```

Use `lang/en.js` and `lang/en.php` as reference — they contain every key with English defaults.

---

## Submissions API

```bash
# List all (JSON, paginated)
GET submissions.php?form=kontakt&token=YOUR_TOKEN

# Pagination
GET submissions.php?form=kontakt&token=YOUR_TOKEN&limit=20&offset=40

# Date filter
GET submissions.php?form=kontakt&token=YOUR_TOKEN&from=2024-01-01&to=2024-12-31

# Single submission
GET submissions.php?form=kontakt&id=bbf_a1b2c3d4&token=YOUR_TOKEN

# Export CSV
GET submissions.php?form=kontakt&format=csv&token=YOUR_TOKEN

# Quick export with ?last= shorthand
GET submissions.php?form=kontakt&format=csv&token=YOUR_TOKEN&last=7d
GET submissions.php?form=kontakt&format=csv&token=YOUR_TOKEN&last=24h
GET submissions.php?form=kontakt&format=csv&token=YOUR_TOKEN&last=50
```

### Quick export: `?last=`

| Value | Meaning |
|-------|---------|
| `7d`  | Last 7 days |
| `24h` | Last 24 hours |
| `2w`  | Last 2 weeks |
| `3m`  | Last 3 months |
| `50`  | Last 50 submissions (sets `limit`) |

Time-based values set `from` automatically. Plain numbers set `limit`. Ignored if `from` is already provided.

Set `api_token` in `config.php`. Pass via header (recommended) or query param:
```bash
curl -H "X-BBF-Token: your-secret-token" "submissions.php?form=kontakt"
```

---

## Storage

Four backends. One config line:

```php
'storage' => 'file',   // or 'sqlite', 'mysql', 'csv'
```

|                 | File (JSON)          | SQLite               | MySQL / MariaDB      | CSV                  |
|-----------------|----------------------|----------------------|----------------------|----------------------|
| **Setup**       | Zero config          | Zero config          | Requires database    | Zero config          |
| **Performance** | < 1000/form          | < 100k/form          | Any volume           | < 5000/form          |
| **Queries**     | Basic (list, CSV)    | Full SQL             | Full SQL             | Sequential only      |
| **Best for**    | Prototypes, small    | Medium sites         | Production           | Spreadsheet export   |

---

## Security

| Protection                | How                                                            |
|---------------------------|----------------------------------------------------------------|
| **CSRF tokens**           | Session-based HMAC, automatic via `bbf.js`                     |
| **Honeypot**              | Hidden field catches bots                                      |
| **Rate limiting**         | File-locked per-IP counter (default: 10/min)                   |
| **Server-side validation**| All field rules enforced on server                             |
| **Input sanitization**    | `htmlspecialchars()` in all email templates                     |
| **Signed webhooks**       | HMAC-SHA256 via `X-BBF-Signature`                              |
| **CORS control**          | Configurable `allowed_origins`                                 |
| **API authentication**    | Token required for `submissions.php`                           |
| **Smoke test isolation**  | Token-protected, rate-limited, returns 404 on failure          |
| **SQL injection**         | PDO prepared statements                                        |
| **CSV formula injection** | Prefix sanitization for `=`, `+`, `-`, `@`                     |
| **Directory access**      | `.htaccess` blocks 7 directories + all dotfiles (see below)    |
| **Config protection**     | `defined('BBF_LOADED')` guard + `.htaccess` deny on all `config*` files |
| **Template traversal**    | `basename()` on all template paths — blocks `../../config.php` |
| **SSRF protection**       | Webhook URLs validated — private/reserved IPs blocked           |
| **Error suppression**     | `display_errors` forced OFF — errors logged, never shown       |
| **Definition stripping**  | API form endpoint strips `on_submit` and `storage` from response |

### What `.htaccess` blocks

The included `.htaccess` protects seven directories, all config files, editor backup files, and dotfiles:

```
Blocked directories:  submissions/, logs/, templates/, actions/, backups/, forms/*.json, lang/*.php
Blocked files:        config* (all variants), *.bak, *.swp, *.save, *.orig, *~
Blocked dotfiles:     .git/, .env, .htpasswd — everything starting with a dot
Directory listing:    OFF globally (Options -Indexes)
```

> **Apache only.** For Nginx, equivalent rules are documented as comments inside `.htaccess`. Copy them into your `server {}` block — fail to do so and your credentials will be visible to anyone who asks.

### Daily security self-check

`submit.php` runs a lightweight self-check once per day (non-blocking, log-only). It writes warnings to PHP's `error_log` if it detects:

- `check.php` still exists on the server
- Sandbox mode is ON
- `api_token` is empty
- `webhook_secret` is empty and at least one form uses webhooks
- `.htaccess` is missing
- `display_errors` is ON in PHP config

No forms are blocked. No users are affected. The warnings appear in your server's PHP error log — check it periodically, or set up log monitoring.

### `check.php` — installation diagnostics

Run `check.php` after installation to verify your setup. It tests:

- PHP version, extensions, and configuration
- Storage backend connectivity (file/SQLite/MySQL/CSV)
- Form JSON validity and field definitions
- **Active HTTP probes** on 5 directories (submissions, logs, templates, actions, forms) to verify they're blocked
- `config.php` accessibility via HTTP
- `BBF_LOADED` guard presence in `config.php`
- `display_errors` state
- Sandbox mode state
- Leftover diagnostic files (`phpinfo.php`, `test.php`, etc.)

Access control: localhost = unrestricted. Remote = requires `?token=<api_token>`.

**Delete `check.php` after verification** — it exposes PHP version, extensions, directory paths, storage details, and form structure.

### Privacy

```php
'store_ip'         => false,
'store_user_agent' => false,
```

### Error notifications

```php
'error_notify' => 'admin@example.com',
```

When form processing fails (storage, email, or webhook errors), the admin receives an email — at most once per 24 hours. Sent via `mail()` directly, so it works even when SMTP is the problem. Errors are always logged to `error_log` regardless.

---

## Smoke Test

`smoketest.php` validates all your forms in one request — generates valid test data for every field type, runs it through the same validation pipeline as `submit.php`, and reports pass/fail per form. Protected by a dedicated token.

### Setup

In `config.php`:

```php
// Generate: php -r "echo bin2hex(random_bytes(16));"
'smoke_token'  => 'a1b2c3d4e5f6...',      // required — endpoint is dead without it
'smoke_email'  => 'tester@example.com',    // "submitter" — receives confirmation emails
'smoke_notify' => 'admin@example.com',     // "admin" — receives notify emails (test Reply-To)
```

Use two different addresses to test reply\_to: the notification arrives at `smoke_notify`, you click Reply, and it goes to `smoke_email`. If `smoke_notify` is empty, it falls back to `smoke_email`.

### Dry Run (default)

Validates forms in-process. No emails sent, nothing stored, no side effects.

```bash
# All forms
curl "https://example.com/smoketest.php?token=YOUR_TOKEN"

# Single form
curl "https://example.com/smoketest.php?token=YOUR_TOKEN&form=kontakt"

# CLI (no token needed — you already have server access)
php smoketest.php
php smoketest.php kontakt
```

Response:

```json
{
    "status": "ok",
    "mode": "dry",
    "summary": "10/10 forms passed (dry run)",
    "forms": [
        { "form": "kontakt", "status": "ok", "fields_tested": 6 },
        { "form": "newsletter", "status": "ok", "fields_tested": 1 }
    ]
}
```

### Live Mode

Submits forms through the full `submit.php` pipeline — stores submissions, sends real emails, fires webhooks. Confirmation emails go to `smoke_email` (the test submitter), notification emails go to `smoke_notify` (the test admin). Reply-To on the notification points back to `smoke_email`, so clicking Reply lets you verify the full flow.

```bash
curl "https://example.com/smoketest.php?token=YOUR_TOKEN&live=1"
curl "https://example.com/smoketest.php?token=YOUR_TOKEN&live=1&form=kontakt"

# CLI
php smoketest.php --live
```

Live mode requires `smoke_email` in config — refuses to run without it.

### What gets tested

For each form JSON in `forms/`:

1. **JSON parsing** — valid JSON?
2. **Schema validation** — required properties, field names, types, options
3. **Template resolution** — `use` + `prefix` groups expanded correctly
4. **Data generation** — valid values per field type (email, tel, date, select, etc.)
5. **Server-side validation** — required, pattern, min/max, options, conditionals
6. **Email templates** — referenced template files exist on disk

In live mode, additionally: storage write, email delivery, webhook dispatch.

### Security

| Protection | How |
|---|---|
| **Token required** | `smoke_token` must be set in config AND provided in the request |
| **Dead when empty** | `smoke_token => ''` = endpoint returns 404 (not 403 — doesn't reveal it exists) |
| **Timing-safe** | Token compared via `hash_equals()` — immune to timing attacks |
| **Rate limited** | 5 failed attempts per minute per IP, then 429 |
| **No email abuse** | Live mode overrides all recipients with `smoke_email` — can't be used to send mail to arbitrary addresses |
| **CSRF bypass** | `smoke_token` bypasses CSRF only for sandbox/smoke requests — doesn't weaken normal form submissions |

### When to use what

| Scenario | Mode |
|---|---|
| After editing a form JSON | Dry run — instant validation, no side effects |
| After deploying to production | Dry run — verify all forms load and validate |
| Testing email delivery / SMTP config | Live — real emails arrive in your inbox |
| Testing webhook integrations | Live — webhooks fire with test data |
| Automated CI/CD pipeline | Dry run via CLI — exit code 0 = all passed |

### Do NOT

- **Do not use a weak token.** Generate with `php -r "echo bin2hex(random_bytes(16));"` — that's 32 hex chars, 128 bits of entropy.
- **Do not put the token in URLs you share.** It's a secret. Use it from your browser, curl, or CI — not in public links.
- **Do not leave `smoke_email` or `smoke_notify` pointing to someone else's address.** They receive every test email from every form.
- **Do not rely on live mode as a monitoring tool.** It creates real submissions — use dry run for regular checks.

---

## Payments (Stripe)

Collect payments via Stripe Checkout — no SDK, no build step. Card data never touches your server.

```json
"on_submit": {
    "payment": {
        "provider": "stripe",
        "amount_field": "order_total",
        "currency": "eur",
        "product_name": "Order from {{customer_name}}",
        "success_url": "/thank-you.html",
        "cancel_url": "/order.html"
    }
}
```

**Setup:** Add `stripe.secret_key` and `stripe.webhook_secret` to `config.php`. Register `payment.php` as a webhook endpoint in [Stripe Dashboard](https://dashboard.stripe.com/webhooks) (event: `checkout.session.completed`).

**Flow:** Form submit → store with `pending` → redirect to Stripe → user pays → Stripe webhook → status updated to `paid` → emails/webhooks fire.

Emails and webhooks are **deferred until payment is confirmed** — the customer and admin are notified only after successful payment.

**Note:** Requires `file`, `sqlite`, or `mysql` storage (not CSV — it's append-only and can't update payment status).

---

## Lookup (Auto-fill from API)

Enter a value in one field (e.g. company ID), fetch data from an API, and auto-fill other form fields.

```json
{
    "name": "ico",
    "type": "text",
    "label": "IČO",
    "lookup": {
        "url": "/api/company.php?ico={{value}}",
        "trigger": 8,
        "map": {
            "company_name": "name",
            "street": "address.street",
            "city": "address.city",
            "zip": "address.zip",
            "dic": "dic",
            "ic_dph": "ic_dph"
        }
    }
}
```

**Properties:**
- `url` — API endpoint. `{{value}}` is replaced with the field's current value (URL-encoded).
- `trigger` — When to fire: `"blur"` (default, on field leave), `"change"`, or a **number** (auto-trigger when the field reaches N characters — use `8` for IČO).
- `map` — Maps **form field names** to **response JSON keys**. Supports dot notation for nested objects (e.g. `"address.street"` reads `response.address.street`).

**How it works:**
1. User types a value (e.g. `12345678`)
2. When trigger fires, `bbf.js` fetches the URL
3. Response JSON is parsed, mapped fields are auto-filled
4. Each filled field fires `input` and `change` events (so conditionals and other logic react)
5. If the fetch fails or returns 404, nothing happens (no error shown)

**API response example** (for the config above):
```json
{
    "name": "ACME s.r.o.",
    "address": { "street": "Hlavná 1", "city": "Bratislava", "zip": "81101" },
    "dic": "2020123456",
    "ic_dph": "SK2020123456"
}
```

---

## Autocomplete (Typeahead Suggestions)

Show a dropdown of suggestions as the user types, fetched from an external URL.

```json
{
    "name": "city",
    "type": "text",
    "label": "Mesto",
    "autocomplete_from": "/api/cities.php?q={{value}}"
}
```

Or with options:

```json
{
    "name": "city",
    "type": "text",
    "label": "Mesto",
    "autocomplete_from": {
        "url": "/api/cities.php?q={{value}}",
        "min_length": 2,
        "debounce": 300
    }
}
```

**Properties:**
- `url` — API endpoint. `{{value}}` is replaced with current input.
- `min_length` — Minimum characters before fetching (default: `2`).
- `debounce` — Delay in ms before fetching after typing stops (default: `300`).

**API response format:** Same as `options_from` — array of `{value, label}` objects:
```json
[
    {"value": "Bratislava", "label": "Bratislava"},
    {"value": "Banská Bystrica", "label": "Banská Bystrica"}
]
```

**Keyboard navigation:** Arrow keys to navigate, Enter to select, Escape to close.

---

## Dynamic Options (`options_from`)

Load select, radio, or checkbox options from an external URL at render time. Use instead of static `options` for dynamic data (product categories, countries, inventory, etc.).

```json
{
    "name": "country",
    "type": "select",
    "label": "Country",
    "required": true,
    "options_from": "/api/countries.php"
}
```

The URL must return a JSON array of `{value, label}` objects — the same format as static `options`:

```json
[
    {"value": "SK", "label": "Slovakia"},
    {"value": "CZ", "label": "Czech Republic"},
    {"value": "DE", "label": "Germany"}
]
```

**How it works:**
- `bbf.js` fetches all `options_from` URLs in parallel before rendering the form
- Options are populated into the field exactly like static `options`
- Server-side option validation is skipped for `options_from` fields (the server doesn't know the valid values)
- If the fetch fails, the field renders with an empty options list and a warning in the console
- Works with `select`, `radio`, and `checkbox` field types
- Use `options_from` OR `options`, not both. `options_from` takes precedence.

**Endpoint requirements:**
- Must return `Content-Type: application/json`
- Must return an array of `{value, label}` objects
- Can be any URL (relative or absolute, same-origin or cross-origin with CORS)

---

## Custom Actions

```json
"actions": [{ "type": "log_to_crm", "crm_url": "https://..." }]
```

```php
// actions/log_to_crm.php — receives $action, $submission, $config
$url = $action['crm_url'] ?? '';
if ($url) {
    file_get_contents($url . '?' . http_build_query($submission['data']));
}
```

Actions are **server-side trusted code** with full PHP access. Only place code you trust in `actions/`.

### Action Response Override

Custom actions can inject fields into the JSON response returned to the client via the `$actionResponse` array:

```php
// actions/create-order.php
$orderId = 'ORD-' . strtoupper(substr($submission['id'], 4, 8));
$actionResponse['order_id'] = $orderId;
$actionResponse['redirect'] = '/thank-you?order=' . $orderId;
```

The client receives these fields merged into the standard response:

```json
{
    "status": "ok",
    "message": "OK",
    "submission_id": "bbf_a1b2c3d4...",
    "order_id": "ORD-A1B2C3D4",
    "redirect": "/thank-you?order=ORD-A1B2C3D4"
}
```

**Rules:**
- `$actionResponse` is a plain PHP array available to all action files
- Custom fields are merged into the response — `submission_id` and `status` are always present
- If an action sets `$actionResponse['redirect']`, it takes precedence over `on_submit.redirect`
- Multiple actions can write to `$actionResponse` — later actions overwrite earlier ones

### onSuccess / onError Callbacks

Handle the submission response in JavaScript via `onSuccess` and `onError` callbacks:

```javascript
BBF.render('order', '#checkout', {
    onSuccess: function(response) {
        // response contains all standard + custom action fields
        console.log('Order ID:', response.order_id);
        document.getElementById('order-result').textContent = 'Order ' + response.order_id + ' placed!';
        return false; // return false to skip default success handling (message, redirect, reset)
    },
    onError: function(response) {
        console.error('Submission failed:', response.message);
        // default error handling still runs after this callback
    }
});
```

**`onSuccess(response)`:**
- Called when `response.status === 'ok'` (not called for sandbox previews)
- Receives the full response object including custom action fields
- Return `false` to skip default handling (success message, redirect, form reset)
- Return anything else (or nothing) to continue with default handling

**`onError(response)`:**
- Called when submission fails (validation errors, server errors)
- Default error handling (showing field errors, error message) always runs after the callback

---

## Email Templates

Templates live in `templates/` and use `{{field_name}}` placeholders. All values are HTML-escaped.

| Variable       | Value                    |
|----------------|--------------------------|
| `{{_form}}`    | Form name                |
| `{{_id}}`      | Submission ID            |
| `{{_time}}`    | Submission timestamp     |
| `{{_summary}}` | HTML table of all fields |

### Conditional Sections

Show or hide parts of a template based on whether a field has a value:

```
{{#quantity}}Ordered: {{quantity}} ks{{/quantity}}     ← shown only if quantity is non-empty
{{^gift_note}}No gift note provided.{{/gift_note}}    ← shown only if gift_note IS empty
```

| Syntax | Meaning |
|--------|---------|
| `{{#var}}...{{/var}}` | Show block if `var` is truthy (non-empty) |
| `{{^var}}...{{/var}}` | Show block if `var` is falsy (empty) |

Unreplaced `{{tags}}` are automatically removed from the output.

Option labels are available as `{{fieldname_label}}` — resolves to the display label instead of the raw value:

```
Payment: {{payment_method_label}}   →  "Cash on delivery (+€1)"
```

---

## Conditional Options

Individual radio/checkbox options can have their own `show_if`:

```json
{
  "name": "shipping",
  "type": "radio",
  "options": [
    {"value": "post", "label": "Slovenská pošta", "show_if": {"field": "country", "value": "SK"}},
    {"value": "ceska_posta", "label": "Česká pošta", "show_if": {"field": "country", "value": "CZ"}},
    {"value": "pickup", "label": "Osobný odber"}
  ]
}
```

Options without `show_if` are always visible. Hidden options are automatically unchecked.

---

## Cross-Field Validation

Form-level `validations` array for rules that span multiple fields:

```json
{
  "validations": [
    {
      "type": "min_sum",
      "fields": ["qty_book1", "qty_book2", "qty_book3"],
      "min": 1,
      "message": "Objednajte aspoň jednu knihu."
    },
    {
      "type": "min_filled",
      "fields": ["phone", "email"],
      "min": 1,
      "message": "Vyplňte telefón alebo email."
    }
  ]
}
```

| Type | Rule |
|------|------|
| `min_sum` | Sum of numeric field values must be ≥ `min` |
| `min_filled` | At least `min` fields must be non-empty |

Validated both client-side (error in form message area) and server-side (422 response).

---

## File Structure

```
barebonesforms/
├── config.example.php  ← Copy to config.php, edit once
├── submit.php          ← POST handler
├── payment.php         ← Stripe webhook handler
├── bbf_functions.php   ← Shared functions (internal)
├── submissions.php     ← API: list/export submissions
├── sandbox.php         ← Test forms without side effects
├── smoketest.php       ← Validate all forms in one request (token-protected)
├── viewer.php          ← Submissions dashboard (optional, delete if unused)
├── editor.php          ← Visual JSON form editor (optional, delete if unused)
├── check.php           ← Installation diagnostics
├── docs.html           ← Full documentation (standalone)
├── bbf.js              ← Form renderer (zero dependencies)
├── bbf.css             ← Default styles (auto-loaded by bbf.js)
├── .htaccess           ← Protects sensitive dirs (Apache)
├── lang/               ← Language packs (32 languages)
│   ├── en.js / en.php  ← English (reference)
│   ├── de.js / de.php  ← German
│   └── ...             ← 24 more languages
├── forms/
│   ├── form.schema.json ← JSON Schema for IDE autocomplete
│   └── kontakt.json     ← Your form definitions
├── templates/
│   ├── confirm.html    ← Email to submitter
│   └── notify.html     ← Email to admin
├── submissions/        ← Stored data (auto-created)
├── logs/               ← Rate limit logs (auto-created)
└── actions/            ← Custom post-submit actions (optional)
```

---

## Let AI Write Your Forms

The schema is simple enough that any AI can produce a valid form:

> "Create a BareBonesForms JSON (schema_version 1) for a job application form with name, email, phone, position dropdown, portfolio URL (optional), cover letter (textarea, min 50 chars), and GDPR consent checkbox."

Copy the JSON, save as `forms/job-application.json`, embed. The `form.schema.json` schema file gives AI and your IDE the exact format spec.

### Note for AI assistants

If you're an AI helping a user build, embed, or style a BareBonesForms form, read these files — in this order — before writing code:

1. **This README** — you're here. Gives you the concept, JSON structure, field types, and `on_submit` pipeline.
2. **[docs.html](docs.html)** — the full reference. Contains CSS class table (21 entries), `show_if` operators, `config.php` options, storage backends, per-form overrides, and everything not covered here.
3. **At least one demo** (`demo1.html`–`demo8.html`) — see real embedding in context. Demo 1 is the simplest; Demo 4 is the feature showcase; Demo 8 shows reusable templates.
4. **[form.schema.json](forms/form.schema.json)** — the machine-readable schema. Use it to validate JSON you generate.

**Common pitfalls to avoid:**
- Do not add a `<link>` tag for `bbf.css` — it is auto-loaded by `bbf.js`.
- The generated HTML uses `bbf-` prefixed classes (see "Generated HTML & CSS Classes" above). Do not guess class names — they are listed in this README and in docs.html.
- All validation is enforced server-side. Client-side validation is a convenience, not a guarantee.
- `on_submit.store` defaults to `true`. Email and webhooks require SMTP/webhook configuration in `config.php`.

**E-shop integration features:**
- **Lookup / auto-fill** (`lookup`): Enter IČO → API fetches company data → auto-fills name, address, VAT. Trigger on blur or after N characters. See "Lookup" section.
- **Autocomplete** (`autocomplete_from`): Typeahead suggestions from URL as user types. Keyboard navigation, debounced. See "Autocomplete" section.
- **Dynamic options** (`options_from`): Load select/radio/checkbox options from a URL. Use for product categories, countries, variants, etc. See "Dynamic Options" section.
- **Action response override** (`$actionResponse`): Custom actions can inject fields (order ID, redirect URL, etc.) into the JSON response. See "Action Response Override" section.
- **Callbacks** (`onSuccess`, `onError`): Handle submission results in JavaScript. `onSuccess` receives the full response including custom action fields. Return `false` to skip default handling. See "onSuccess / onError Callbacks" section.
- **Inline embedding**: BBF renders into any existing DOM element — no wrapper needed. Use `BBF.render('form-id', '#your-div')` for checkout forms inside e-shop layouts.

---

## Production Checklist

- [ ] `config.example.php` → `config.php` with all settings filled in
- [ ] Strong `api_token` for submissions API
- [ ] `webhook_secret` if using webhooks
- [ ] SMTP configured for reliable email delivery
- [ ] `.htaccess` verified (or Nginx/LiteSpeed equivalent)
- [ ] HTTPS enabled
- [ ] `rate_limit` set for your traffic
- [ ] `submissions/` and `logs/` not web-accessible
- [ ] `allowed_origins` set if embedding cross-domain
- [ ] `error_notify` set for admin error alerts (max 1/day)
- [ ] `stripe` keys set if using payments
- [ ] `'sandbox' => false` for production
- [ ] `smoke_token` set if you want post-deploy smoke testing (leave empty to disable)
- [ ] `check.php` run, all checks passed *(remote access requires `?token=<api_token>`)*
- [ ] **`check.php` deleted after verification** — it exposes PHP version, extensions, paths, and config details
- [ ] `editor.php` deleted or protected — can modify form definitions
- [ ] `viewer.php` deleted or protected — exposes submission data

---

## Requirements

- PHP 8.1+
- Extensions: `json`, `session` (required), `mbstring` (recommended)
- `pdo_sqlite` or `pdo_mysql` (depending on storage backend)
- Any web hosting with PHP support

---

## The Genesis

On the eighth day of March, in the year of our Lord 2026, after dragging several godforsaken websites — each shackled to a different version of some commercial form system — through migrations that would test the patience of a saint and the sanity of a PHP developer alike, I poured myself a glass and spoke plainly to the empty room: *Enough.*

Each site running its own damned version. Each version demanding a different PHP. Each update breaking something that worked perfectly fine the day before.

BareBonesForms is what emerged from that resolve. JSON in, form out, submissions stored. Simplicity ain't a weakness — it's the only honest architecture.

Now, since I hold the belief that others in this wretched camp of web development may suffer the same afflictions, I'm putting this out in the open. Take it. Use it. Bend it to your purposes. It's MIT licensed.

---

## A Word on Generosity

You owe me nothing for this. But should you find this tool has saved you time or frustration — there are two ways to tip your hat:

🎵 **[Rogue Bard](https://roguebard.net/)** — My music. Jazz, blues, rock — wrapped in philosophical lyrics.

📖 **[The Last Trial](https://lasttrial.art/)** — A dark fantasy graphic novel. 200+ illustrated panels, an original soundtrack, and a story where even the noblest deed comes at a price.

Entirely voluntary. A man who shares his work appreciates knowing it didn't vanish into the void.

---

## License

MIT — do whatever you want with it.

The code is yours. The responsibility is yours. The glass is yours to fill.
