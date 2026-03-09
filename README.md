# BareBonesForms

**Zero-build PHP forms for shared hosting.**

Define a form as JSON. Upload to hosting. Embed. Collect submissions. Done.

PHP 8.1+ · File / SQLite / MySQL / CSV · SMTP + Webhooks · 32 Languages · Shared-hosting friendly

**[Documentation →](docs.html)** · **[Sandbox →](sandbox.php)** · **[Installation Check →](check.php)** · **[Live Demos →](demo1.html)**

---

## Embed in 30 Seconds

```html
<div data-form="kontakt"></div>
<script src="bbf.js"></script>
```

That's it. Two lines. `bbf.js` auto-loads `bbf.css` from the same directory — no `<link>` tag needed. The form loads from `forms/kontakt.json`, validates, and submits to `submit.php`.

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
- **Conditional logic** — Show/hide fields based on other field values. Supports `all`/`any` compound conditions with nesting. Nine comparison operators: equals, not, gt, gte, lt, lte, contains, empty, not_empty. Hidden fields excluded from validation and submission.
- **Field groups** — Container type with `show_if` — show or hide a whole section of fields with one condition. Children inherit the parent's visibility rule.
- **Reusable templates** — Define a set of fields once, reference it from multiple groups with `"use"` + `"prefix"`. One template, many instances.
- **Star ratings** — Accessible rating widget with keyboard and screen reader support.
- **Honeypot + rate limiting** — Built-in bot protection. No external services needed.
- **Submissions API** — List, filter, export as JSON or CSV. Token-authenticated.

**Built for:** PHP shared hosting, small–medium websites, developers who want control.

**Not for:** Drag-and-drop form builders, enterprise workflow suites, or dashboards with seventeen menu items.

---

## Live Demos

Seven demos, each building on the previous. From "hello world" to business logic:

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
4. Create a JSON file in forms/
5. Embed: <div data-form="kontakt"></div>
         <script src="bbf.js"></script>
6. Done. Pour yourself a glass — beverage of your choosing —
   and sit back. You've earned it.
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
            "to": "admin@example.com",
            "subject": "New contact: {{name}}",
            "template": "notify.html"
        },
        "webhooks": ["https://n8n.example.com/webhook/kontakt"],
        "redirect": "https://example.com/thank-you",
        "actions": [{ "type": "my_custom_action", "param": "value" }]
    }
}
```

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
| `options`         | array   | Options for select/radio/checkbox                    |
| `rows`            | integer | Textarea rows (default: 4)                           |
| `value`           | string  | Default/preset value                                 |
| `size`            | string  | Field width: `"small"`, `"medium"`, `"large"`        |
| `css_class`       | string  | Custom CSS class on field wrapper                    |
| `columns`         | int/str | Radio/checkbox layout: `2`, `3`, or `"inline"`       |
| `readonly`        | boolean | Makes the field read-only                            |
| `other`           | boolean | Adds "Other" option with text input                  |
| `other_label`     | string  | Label for "Other" option (default: `"Other"`)        |
| `confirm`         | boolean | Adds confirmation field (email type)                 |
| `shuffle`         | boolean | Randomize option order (radio/checkbox/select) or child field order (group) |
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
        hideOnSuccess: true
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

## Language Support

BareBonesForms ships with **28 language packs** — both client-side (validation messages, button labels, status text) and server-side (validation error messages returned by the API).

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
```

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
| **SQL injection**         | PDO prepared statements                                        |
| **CSV formula injection** | Prefix sanitization for `=`, `+`, `-`, `@`                     |
| **Directory access**      | `.htaccess` blocks `/submissions/`, `/logs/`, `config.php`     |

### Privacy

```php
'store_ip'         => false,
'store_user_agent' => false,
```

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

---

## Email Templates

Templates live in `templates/` and use `{{field_name}}` placeholders. All values are HTML-escaped.

| Variable       | Value                    |
|----------------|--------------------------|
| `{{_form}}`    | Form name                |
| `{{_id}}`      | Submission ID            |
| `{{_time}}`    | Submission timestamp     |
| `{{_summary}}` | HTML table of all fields |

---

## File Structure

```
barebonesforms/
├── config.example.php  ← Copy to config.php, edit once
├── submit.php          ← POST handler
├── submissions.php     ← API: list/export submissions
├── sandbox.php         ← Test forms without side effects
├── check.php           ← Installation diagnostics
├── docs.html           ← Full documentation (standalone)
├── bbf.js              ← Form renderer (zero dependencies)
├── bbf.css             ← Default styles (optional)
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
- [ ] `'sandbox' => false` for production
- [ ] `check.php` run, all checks passed
- [ ] `check.php` deleted after verification

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
