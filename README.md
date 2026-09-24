# BareBonesForms

**Self-hosted PHP forms for shared hosting. No build step, no Composer, no npm, no SaaS.**

Define a form as JSON. Upload one folder. Embed with two lines. Submissions land on *your* server.

*Bare bones means: no form builder — forms are JSON files you write by hand (an optional JSON editor with live preview is included). No dependencies, no build step, no database required.*

PHP 8.1+ · File / SQLite / MySQL / CSV · SMTP + Webhooks · 32 Languages · ~22 KB gzipped JS

**[Download →](https://github.com/pietrobb/BareBonesForms/releases/latest)** · **[Documentation →](docs.html)** · **[Changelog →](CHANGELOG.md)** · **[Upgrading →](#upgrading)** · **[Live Demos →](demo1.html)**

### Why?

Hosted form services (Formspree, Tally, Typeform…) are convenient until you care about where your leads are stored, what it costs per month, or what happens when their API changes. Classic PHP form plugins drag in a framework, a database, and a build pipeline. BareBonesForms sits in between: drop a folder on any PHP host and you own everything — the forms, the data, the emails.

It has run in production on several business websites since spring 2026, collecting real leads in multiple languages.

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
- **JSON Editor** — Optional `editor.php`: a text editor for the form JSON with live preview, schema validation, and field snippets. Not a drag-and-drop form builder.
- **Save & resume drafts** — Respondents can save a long form and come back later with a resume code. Opt-in per form, allowlisted fields only.
- **Repeatable groups** — "Add another" rows (family members, line items, rooms…) with min/max limits, validated on the server.
- **Viewer inbox** — Mark submissions *new / in-progress / done*, add private notes and tags, filter by them.
- **Delivery log with retry** — Every email, webhook and action is recorded per submission. A failed delivery shows in the viewer with a Retry button instead of disappearing into a log.
- **Form versions** — Submissions keep the form definition they were filled in with, so old answers still display correctly after you edit the form.
- **Retention & backups** — Optional deletion of submissions older than N days (dry run, archive first, confirm by digest) and per-form logical backups with dry-run restore. See [Retention & Backups](#retention--backups).
- **Safe concurrent writes** — File storage writes each submission under an exclusive lock to a temp file and publishes it with an atomic rename. See [Concurrent submissions](#concurrent-submissions).
- **Visit attribution** — Optionally store UTM parameters, Google Ads click IDs (`gclid`, `gbraid`, `wbraid`), landing page and referrer with each lead. Optional Umami event on success.

**Built for:** PHP shared hosting, small–medium websites, developers who want control.

**Not for:** Drag-and-drop form builders, enterprise workflow suites, or dashboards with seventeen menu items.

**File uploads (2.2):** Opt-in private storage, per-file progress, authenticated viewer downloads, and backup/retention support. See [File Uploads](#file-uploads).

**Storage in one sentence:** file storage is the zero-setup default for small forms; use SQLite (also zero setup) once a form collects more than about a thousand submissions or you need reporting. See [Which backend should I use?](#which-backend-should-i-use)

---

## Live Demos

Nine demos, each building on the previous. From "hello world" to business logic:

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
| **9** | [PSČ Lookup](demo9.html) | **Lookup + autocomplete** — enter PSČ to auto-fill city, or type city for typeahead suggestions that auto-fill PSČ back. 4 094 postal codes, bidirectional. |

Demos 1–3 cover backend configuration. Demo 4 showcases the form engine's core. Demos 5–9 prove it can handle decision flows, business logic, reusable components, interactive experiences, and external data integration — not just contact forms.

---

## Quick Start

1. **Download** `barebonesforms-vX.Y.Z.zip` from [Releases](https://github.com/pietrobb/BareBonesForms/releases/latest) and unzip it.
   It contains only the files needed on a server — no tests, no dev tooling.
2. **Upload** the `barebonesforms/` folder to your hosting (e.g. `https://example.com/bbf/`).
3. **Configure:** copy `config.example.php` → `config.php`, fill in storage and mail settings, set a long random `api_token` (`php -r "echo bin2hex(random_bytes(32));"`) and `diagnostic_base_url` (the public URL of this folder, e.g. `https://example.com/bbf`).
4. **Check:** open `check.php` in the browser, sign in with your `api_token` and fix anything it reports. It requests real files inside `submissions/`, `logs/`, `templates/`… to prove your server does not serve them. Then **delete `check.php`** — it exposes server details.
5. **Create a form:** put a JSON file in `forms/` (start from `forms/kontakt.json`).
6. **Embed** it on any page:
   ```html
   <div data-form="kontakt"></div>
   <script src="/bbf/bbf.js"></script>
   ```
7. **Verify:** `php smoketest.php` (or the viewer at `viewer.php`) — every form should pass.

Before going live, run through the [Production Checklist](#production-checklist). You can delete the demos (`demo*.html`, `demo.css`, `index.html`, `forms/demo-*.json`, `api-psc.php`, `data/`) — they are only there to try things out.

> **Working from a git clone instead?** Don't upload the whole repository. Build the upload-ready folder with
> `php tools/package-deploy.php --destination ../barebonesforms` — it copies exactly the runtime files from a closed allowlist.

### Try it locally in 2 minutes

```bash
cd barebonesforms
cp config.example.php config.php        # set api_token; diagnostic_base_url = http://127.0.0.1:8000
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000/demo1.html`, submit the form, then see it in `http://127.0.0.1:8000/viewer.php`. Without a mail server the emails fail, but the submission is kept and the viewer offers **Retry**.

- `PHP_CLI_SERVER_WORKERS` (Linux/macOS) lets `check.php` request its own URLs; with a single worker those probes cannot complete. Windows has no workers: start a second `php -S 127.0.0.1:8001` in the same folder and set `diagnostic_base_url` to `http://127.0.0.1:8001`.
- `php -S` ignores `.htaccess`, so `submissions/` **is readable** over HTTP — `check.php` will say so. Use it only for local testing.

---

## Upgrading

Your data lives in places that an upgrade never needs to touch: **`config.php`**, **`forms/`**, **`templates/`** and **`submissions/`** (plus `actions/` if you wrote custom actions and `lang/` if you added a language). Also preserve your configured data directories, including **`uploads.dir`**, archives and backups; these are not replaceable code.

**Before you start:** read the [CHANGELOG](CHANGELOG.md) entries between your version and the new one. Items marked **Breaking** tell you exactly what to change.

1. **Back up** the whole installation folder (e.g. `tar -czf bbf-backup-$(date +%F).tgz bbf/`) **and any external data directories**, including `uploads.dir`. Uploaded bytes are not in the database or necessarily inside the installation; preserve their matching records too. See [Retention & Backups](#retention--backups).
2. **Preflight in a staging folder** that is not web-accessible: unzip the new release there, copy in your `forms/*.json` (and `templates/`, `actions/`), and create a temporary `config.php` from the new `config.example.php` *without* live credentials. Then run
   ```bash
   php smoketest.php
   ```
   Dry mode validates your existing forms against the new version without storing anything or sending mail. Fix every reported error in your form JSON — never bypass validation.
3. **Merge config:** compare your `config.php` with the new `config.example.php` and add the new keys you want. Keys you leave out fall back to safe defaults, so new features stay off until you opt in.
4. **Replace the code:** copy the new release over the live folder, **excluding** `config.php`, `forms/`, `templates/`, `submissions/`, `logs/` (and your own `actions/`/`lang/` files). If you customized `.htaccess`, merge it by hand. With SSH:
   ```bash
   rsync -a --exclude=config.php --exclude=forms/ --exclude=templates/ \
         --exclude=submissions/ --exclude=logs/ barebonesforms/ /path/to/bbf/
   cp barebonesforms/forms/form.schema.json /path/to/bbf/forms/   # keep editor autocomplete current
   ```
5. **Verify:** run `php smoketest.php` on the live folder, submit one real test form, and open it in `viewer.php`.

**Rolling back** = restoring the backup from step 1.

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

Every form uses `schema_version: 1`. The included `forms/form.schema.json` is a JSON Schema for the form file.

**Catch typos while you type, not at runtime.** Keep the `"$schema": "form.schema.json"` line at the top of each form. VS Code, PhpStorm and other editors that support JSON Schema then autocomplete property names and underline unknown properties, wrong types and invalid values as you type. The optional `editor.php` validates as you type with the same checks the server uses and refuses to publish a form with errors. Before deploying, `php smoketest.php` loads every form, checks it and runs generated test data through server-side validation (see [Smoke Test](#smoke-test)).

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
| `file`       | File picker + progress | type, size, count — see [File Uploads](#file-uploads) |
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
| `accept`          | array   | File extensions, e.g. `[".pdf", ".docx"]`; narrowed by server configuration |
| `max_size`        | int/string | Per-file limit: bytes or `"5MB"` (binary units)   |
| `max_files`       | integer | Files per field: 1–20, default 1                     |
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

## File Uploads

Available in 2.2, **off by default**. File fields work with File, SQLite, MySQL and CSV storage; the bytes always live in `uploads.dir`, not in the database. Use the normal `bbf.js` embed — no extra upload library.

### Setup and form JSON

1. Enable PHP `file_uploads` and the `fileinfo` extension. PHP must be able to write to its temporary upload directory and to `uploads.dir`, including under `open_basedir`. `ZipArchive` is also required for `.docx`, `.xlsx`, `.odt` and `.ods`; without it those types are unavailable.
2. Merge the `uploads` block from `config.example.php` into `config.php`, set `enabled` to `true`, and choose an absolute private directory. For a web root of `/home/user/public_html`, for example:

   ```php
   'uploads' => [
       'enabled' => true,
       'dir' => '/home/user/bbf-data/uploads',
       'allow_inside_web_root' => false,
       'max_file_size' => 10 * 1024 * 1024,
       'max_submission_size' => 25 * 1024 * 1024,
       'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'docx', 'xlsx', 'odt', 'ods'],
   ],
   ```

   Omitted upload settings keep their defaults (listed below). The default path is `dirname(__DIR__) . '/barebonesforms-private/uploads'`: outside the installation is **not necessarily outside the web root**, especially with an installation at `/public_html/bbf`.
3. Run `check.php` with uploads enabled, fix its directory/extension/limit warnings, then remove it again. Test a real upload, submit, and viewer download.

Save this as `forms/application.json`:

```json
{
    "$schema": "form.schema.json",
    "schema_version": 1,
    "id": "application",
    "name": "Job application",
    "fields": [
        { "name": "email", "type": "email", "label": "Email", "required": true },
        {
            "name": "cv",
            "type": "file",
            "label": "Your CV",
            "required": true,
            "accept": [".pdf", ".docx"],
            "max_size": "5MB",
            "max_files": 1
        }
    ],
    "on_submit": { "store": true }
}
```

- `accept` is a list of extensions, **not MIME wildcards**. Omit it to use the effective server allowlist. A field can narrow the server list, not expand it.
- `max_size` is per file: positive integer bytes or a string such as `"512KB"` / `"5MB"`. Units are binary (1 MB = 1,048,576 bytes). The effective limit is the smallest of this value, `uploads.max_file_size`, PHP `upload_max_filesize`, and PHP `post_max_size` minus 64 KiB for multipart overhead (where the PHP limits are set).
- `max_files` defaults to 1; values 2–20 enable multiple selection. `required` and `show_if` work as usual. File fields can be in ordinary groups, **not repeatable groups**.

### Limits and security

All these keys belong inside `config.php` → `uploads`; size settings below are integer bytes, time settings are seconds.

| Setting | Default | Meaning |
|---|---|---|
| `max_file_size` | 10 MiB | Per-file server ceiling |
| `max_submission_size` | 25 MiB | Combined file bytes in one submission |
| `staging_ttl` | 7200 | Unsubmitted uploads expire after 2 hours |
| `max_staging_bytes` / `max_staging_entries` | 500 MiB / 2000 | Global temporary-upload capacity |
| `per_ip_staging_bytes` / `per_ip_staging_entries` | 100 MiB / 40 | Temporary-upload capacity per IP |
| `max_stored_bytes` / `max_stored_files` | 5 GiB / 50000 | Global stored-file capacity |
| `min_free_disk` | 200 MiB | Free disk reserve after an upload |
| `rate_limit` | `['max' => 60, 'window' => 600]` | Upload requests per IP per 10 minutes, including rejected requests |

Default types are PDF, JPEG, PNG, WebP, GIF, TXT, and (with `ZipArchive`) DOCX, XLSX, ODT and ODS. The supported opt-in types are `heic`, `csv`, `zip`, `doc` and `xls`; add only the ones you need to `allowed_extensions`. Executables, scripts, HTML, SVG and macro-enabled Office extensions cannot be enabled. Files must be nonempty; the server checks extension and content MIME type. Office/ODF container checks reject common disguises and macro containers, but are a **plausibility filter, not malware scanning or proof of safe content**. Treat downloaded files as untrusted; there is no image re-encoding or EXIF/GPS removal.

**Keep uploads outside every web-served directory.** Do not expose the directory through an alias, symlink or another virtual host: PHP cannot detect all such mappings. Stored names are random IDs without extensions; original names are sanitized metadata only. If shared hosting makes private storage impossible, `allow_inside_web_root => true` is a weaker fallback: it requires `diagnostic_base_url`, uses a random `data-*` subdirectory and an HTTP sentinel probe cached for one hour. A reachable sentinel or a failed/unreachable probe blocks uploads. You must still configure server denial rules and prevent execution; the probe is not a guarantee against later rule changes or a server configured to execute every file.

PHP/web-server request buffering happens **before** BBF's quotas apply. Set `upload_max_filesize`, `post_max_size` and a matching web-server body limit (`client_max_body_size` on Nginx / `LimitRequestBody` on Apache). `min_free_disk` measures the whole filesystem, not your hosting account quota, and is skipped if disk space cannot be read. Set byte **and file-count** caps to fit your account's space/inode limits, with room for temporary files, backups and archives. Per-IP limits use `REMOTE_ADDR`; behind a reverse proxy/CDN, visitors may share the same limit. Distributed clients can still fill global staging capacity temporarily.

### What respondents and operators see

Selecting a file uploads it immediately, one file per request, with progress and remove/retry controls. The form cannot submit while an upload is running or while a failed row remains; retry it where offered, or remove it. Text answers are submitted separately, so an oversized upload does not send away the rest of the form.

Files stay temporary until submission. Short-lived, single-use tokens remain only in page memory; reloading loses them. The client warns near expiry; expired files must be uploaded again. On submit the server checks the staged files against the current field rules and total limit, then claims them as part of the submit transaction. A retry of the same submit on the same page uses the same idempotency key within the replay window rather than creating a second submission. Failed/uncommitted claims are rolled back or left for recovery; do not manually move files to repair a pending submit.

**Drafts do not save files or tokens**, even if the field is allowlisted. Attach files again after resuming. Authenticated sandbox uploads validate and discard file bytes; they do not produce downloadable submissions.

In `viewer.php`, submission details show names and sizes with download buttons. Downloads require sign-in and `read` permission for that form, check that the file belongs to the submission, and are access-audited. They are attachment downloads (`application/octet-stream`, `nosniff`, sandbox and no-store headers), not inline previews or public URLs.

CSV exports contain readable names and sizes; JSON exports retain descriptor arrays (`id`, `name`, `size`, `type`, `sha256`), **not file bytes or paths**. The CSV storage backend also preserves descriptors in its reserved `__bbf:files` column. Email templates show escaped names/sizes, with no attachments or download links. Webhooks/custom actions receive descriptors, not the files themselves. A custom action can resolve a file using `bbf_upload_path($config, $formId, $submissionId, $fileId)`; treat the result as read-only and handle `null` for a missing file. Never move, modify or delete managed files from an action.

There is no bulk ZIP download, public/signed file link, email attachment, chunked/resumable upload, S3 storage or multi-server upload support in 2.2. See [Retention & Backups](#retention--backups) for deletion, complete backups and cleanup commands.

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

### Theming with CSS variables (recommended)

All visual properties in `bbf.css` are controlled by `--bbf-*` CSS custom properties. Override them to theme your forms — no need to touch `bbf.css` itself:

```css
/* Quick brand theme — only override what you need */
.bbf-form-container {
    --bbf-focus: #2563eb;        /* focus ring color */
    --bbf-btn-bg: #2563eb;       /* submit button */
    --bbf-btn-hover: #1d4ed8;
    --bbf-radius: 8px;           /* rounded corners */
    --bbf-border: #cbd5e1;       /* softer borders */
    --bbf-font: "Inter", sans-serif;
}
```

Scope per form using `data-form`:

```css
[data-form="contact"] .bbf-form-container { --bbf-btn-bg: #059669; }
[data-form="quote"]   .bbf-form-container { --bbf-btn-bg: #7c3aed; }
```

**Key variables** (full list in `bbf.css` `:root` block):

| Variable | Default | Controls |
|----------|---------|----------|
| `--bbf-text` | `#1a1a1a` | Main text color |
| `--bbf-bg` | `#fff` | Input backgrounds |
| `--bbf-border` | `#d0d0d0` | Input borders |
| `--bbf-focus` | `#4a90d9` | Focus ring |
| `--bbf-btn-bg` | `#1a1a1a` | Submit button |
| `--bbf-radius` | `5px` | Border radius |
| `--bbf-spacing` | `16px` | Field gap |
| `--bbf-error` | `#dc3545` | Error color |
| `--bbf-success` | `#1a7a1a` | Success color |

> **AI models:** Search for `--bbf-` to find all customizable variables. Search for `.bbf-` for all CSS classes.

See [`bbf-theme.css`](bbf-theme.css) for complete example themes (dark mode, corporate, warm/earthy, minimal/borderless).

### Quick class override examples

```css
/* Target classes directly when variables aren't enough */
.bbf-title { font-family: Georgia, serif; font-size: 1.5rem; }
.bbf-star-active { color: #f59e0b; }
.bbf-labels-left .bbf-label { flex: 0 0 180px; }
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

Server-side validation and file upload error messages will be returned in the configured language. When a form has `data-lang` (or `{ lang }`), `bbf.js` sends that language with the submission and each upload, and the server answers in it if `lang/<code>.php` exists; otherwise it uses the configured language. Falls back to English for any missing keys.

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

Examples use `&token=` for brevity. In scripts, prefer the header `X-BBF-Token: YOUR_TOKEN` so the token doesn't end up in server logs or browser history.

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

### Which backend should I use?

- **File** stores one JSON file per submission (`submissions/<form>/<id>.json`). It needs nothing and is easy to inspect, back up and copy. The cost is reporting: "form X, last 30 days" means scanning and parsing a directory. That is fine for a contact form with a few hundred leads; it is the wrong tool beyond ~1000 submissions per form.
- **SQLite** is the answer for anything more than a trickle. Still zero setup (one file, WAL mode), but filtering, paging and exports are indexed SQL queries. Needs `pdo_sqlite`.
- **MySQL / MariaDB** when you already have a database server or expect high volume.
- **CSV** only if you want a spreadsheet-shaped file per form. It is append-oriented and cannot be used with payments.

Switching is `'storage' => 'sqlite'` in `config.php`. Existing file submissions are not migrated automatically, so pick the backend before you go live.

### Concurrent submissions

Two people submitting at the same moment cannot corrupt or lose each other's data:

- **File:** every submission gets its own file with a random ID (`bbf_` + 16 hex characters), so two submissions never write to the same file. Each write takes an exclusive `flock()` on a sidecar `.lock` file, writes the complete JSON to a temporary file in the same directory, flushes it and publishes it with an atomic `rename()`. A reader sees either no file or the complete file, never truncated JSON. Updates to an existing record (payment status, workflow notes) use the same lock + temp file + rename path, so they cannot interleave. See `bbf_storage_locked()` and `bbf_storage_replace()` in `bbf_storage.php`.
- **CSV:** appends run under the same exclusive lock, so rows from parallel requests are never interleaved.
- **SQLite / MySQL:** each submission is one `INSERT` inside the database's own locking; SQLite runs in WAL mode.

If storing fails for any reason (disk full, permissions, lock unavailable), the respondent gets an error instead of a success message, the admin gets an email (if `error_notify` is set), and no confirmation email or webhook is sent for a submission that was not saved. A lead does not silently disappear.

The rename is atomic, not a power-loss guarantee: BareBonesForms does not `fsync` the directory. For that level of durability use MySQL.

### Keep data outside the web root (recommended)

By default `submissions/` and `logs/` live inside the installation folder, because on much shared hosting that is the only place PHP can write. They are protected by `.htaccess`, and `check.php` requests real files there to prove the server refuses them. But "the folder is blocked" is one misconfigured vhost, a move to Nginx or a missing `AllowOverride` away from being false.

If your hosting lets you write to a directory above the web root, put the data there and nothing can be downloaded over HTTP, whatever the server configuration:

```php
// config.php (web root is /home/user/public_html, BBF is in /home/user/public_html/bbf)
'submissions_dir' => '/home/user/bbf-data/submissions',
'logs_dir'        => '/home/user/bbf-data/logs',
'drafts_dir'      => '/home/user/bbf-data/submissions/drafts',
'sqlite'          => ['path' => '/home/user/bbf-data/submissions/bbf.sqlite'],
```

Move all four paths together: `drafts_dir` and `sqlite.path` have their own settings and do not follow `submissions_dir`. Retention archives and backups already default to `../barebonesforms-private/`, outside the installation folder.

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

> **Apache only.** For Nginx, copy the equivalent rules from `.htaccess` into your `server {}` block and replace `BBF_BASE/` with the exact installation prefix. For `/bbf`, use patterns beginning `^/bbf/`; for a domain-root installation, remove `BBF_BASE/`. Do not block `lang/*.js`—only `lang/*.php` is private.
>
> Verify the active server configuration, not merely the file: request a disposable sentinel below `submissions/`, `logs/`, `templates/`, `actions/`, `backups/`, `tests/`, and `data/`, plus a form JSON and `lang/en.php`; every request must return 403 or 404. Confirm that `lang/en.js` still returns 200, then remove the sentinels. `check.php` reports direct `config.php` and directory-URL responses when `diagnostic_base_url` is configured, but directory denial can come from `autoindex off`; it does not replace these sentinel-file probes.

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
- Whether files inside `submissions/`, `logs/`, `templates/`, `actions/` and `forms/` are served over HTTP: it requests a shipped file (e.g. `templates/notify.html`) or writes a disposable sentinel file, requests it and deletes it (needs `diagnostic_base_url`). A 200 response with different content (a catch-all page such as `index.html`) is not counted as a leak; directories that do not exist (e.g. `tests/` in a release) are skipped. An empty 200 for `config.php` means PHP ran it and the `BBF_LOADED` guard stopped it — reported as a warning to add the server rule
- `config.php` accessibility via HTTP
- If `diagnostic_base_url` is missing or unreachable, the verdict says protection is **not verified** instead of "All checks passed"
- `BBF_LOADED` guard presence in `config.php`
- `display_errors` state
- Sandbox mode state
- Leftover diagnostic files (`phpinfo.php`, `test.php`, etc.)

Access control: `api_token` is required everywhere, including localhost. `check.php`, `viewer.php` and `editor.php` show a sign-in form; scripts can send the `X-BBF-Token` header instead.

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
# All forms (the token goes in a header, never in the URL)
curl -H "X-BBF-Smoke-Token: YOUR_TOKEN" "https://example.com/smoketest.php"

# Single form
curl -H "X-BBF-Smoke-Token: YOUR_TOKEN" "https://example.com/smoketest.php?form=kontakt"

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
curl -X POST -H "X-BBF-Smoke-Token: YOUR_TOKEN" "https://example.com/smoketest.php?live=1"
curl -X POST -H "X-BBF-Smoke-Token: YOUR_TOKEN" "https://example.com/smoketest.php?live=1&form=kontakt"

# CLI
php smoketest.php --live
```

Live mode requires `smoke_email`, a valid `smoke_token` and a fixed `diagnostic_base_url` in config — it refuses to run without them.

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
        "mode": "fixed",
        "pricing_version": "order-v1",
        "amount_minor": 4990,
        "currency": "eur",
        "product_name": "Order from {{customer_name}}",
        "success_url": "/thank-you.html",
        "cancel_url": "/order.html"
    }
}
```

Amounts are server-owned integer minor units (`4990` = EUR 49.90). Use `mode: "catalog"` for trusted product/quantity/option pricing, or `mode: "donation"` with an allowlisted `amount_field`, matching `minor_units`, and server-side minimum/maximum bounds. Never restore the legacy client-authoritative `amount` contract.

**Upgrading payment forms:** early payment forms (from `main` before September 2026) used a client-supplied `amount`/`amount_field`. That contract is gone. The [upgrade preflight](#upgrading) (`php smoketest.php` in staging) reports every payment form that needs `mode`, `pricing_version`, `amount_minor`, `catalog`, or donation bounds — migrate them before deploying.

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

Or with options (including `map` for auto-filling related fields):

```json
{
    "name": "city",
    "type": "text",
    "label": "Mesto",
    "autocomplete_from": {
        "url": "/api/cities.php?q={{value}}",
        "min_length": 2,
        "debounce": 300,
        "map": {
            "psc": "psc"
        }
    }
}
```

**Properties:**
- `url` — API endpoint. `{{value}}` is replaced with current input.
- `min_length` — Minimum characters before fetching (default: `2`).
- `debounce` — Delay in ms before fetching after typing stops (default: `300`).
- `map` — Optional. On selection, maps extra fields from the response item to form fields: `{ "formFieldName": "responseKey" }`. This enables bidirectional lookup — e.g. selecting a city also fills the PSČ field.

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

## Repeatable Groups

Let respondents add rows — attendees, line items, rooms, children. A `group` with `"repeatable": true` submits an array of objects; the server validates every row and the `min_items`/`max_items` bounds (max 100).

```json
{
    "name": "attendees", "type": "group", "label": "Attendees",
    "repeatable": true, "min_items": 1, "max_items": 5,
    "add_label": "Add attendee", "remove_label": "Remove",
    "fields": [
        { "name": "full_name", "type": "text", "label": "Name", "required": true },
        { "name": "email", "type": "email", "label": "E-mail" }
    ]
}
```

The viewer shows each row separately.

---

## Save & Resume Drafts

Long forms can offer **Save progress / Resume**. It is off by default and enabled per form:

```json
"drafts": {
    "enabled": true,
    "ttl_seconds": 604800,
    "fields": ["full_name", "company", "message"]
}
```

- Only fields listed in `fields` are ever written to a draft. Passwords, hidden fields, file fields and fields marked `"sensitive": true` are always excluded. Files must be attached again after resuming; neither file bytes nor upload tokens are saved in drafts.
- Saving returns a **resume code** (also remembered in the browser). The respondent enters it later to restore the form.
- Drafts expire after `ttl_seconds` (5 minutes to 30 days, default 7 days) and are stored in `drafts_dir` (default `submissions/drafts/`), never in your submissions.

---

## Viewer Inbox, Delivery Log & Retry

`viewer.php` is more than a list:

- **Workflow status** — mark each submission `new`, `in-progress` or `done`, add private notes and tags, filter by them and save filters. This metadata is kept separately and never leaks into exports or webhooks.
- **Delivery log** — each confirmation email, notification, webhook and custom action is recorded per submission with its result. If your SMTP server or webhook endpoint was down, you see it on the submission and can press **Retry**. The attempt limit and back-off are set in `config.php` → `delivery`.
- **Form versions** — every submission remembers the version of the form it was filled in with, so renaming or removing a field later doesn't break how old answers are displayed.
- **Scoped access** — besides the admin `api_token` you can issue per-form tokens with `read` / `export` / `delete` / `review` permissions and an expiry (`access_tokens` in `config.php`).

---

## Retention & Backups

Both are command-line only (`maintenance.php`) and every destructive step is a dry run first:

```bash
php maintenance.php backup --form=kontakt                 # integrity-checked logical backup
php maintenance.php restore --bundle=/path/to/bundle      # dry run: shows what would change
php maintenance.php retention --form=kontakt              # dry run: lists what would be deleted
php maintenance.php retention --form=kontakt --apply --confirm=<digest from the dry run>
```

**By default nothing is ever deleted.** Submissions are kept until you remove them. That is a deliberate choice: no one should lose leads because of a setting they didn't know about.

**Why you might want retention:** submissions contain names, e-mail addresses and whatever else your form asks for. Privacy laws such as the EU GDPR say personal data should not be kept longer than you need it, so many sites must be able to say "contact requests are deleted after 12 months". Retention lets you do that without writing your own cleanup script.

**How it works:**

```php
'retention' => [
    'enabled'     => true,
    'days'        => 365,          // delete submissions older than this
    'batch_limit' => 100,          // max records per run
    'archive_dir' => dirname(__DIR__) . '/barebonesforms-private/retention',
],
```

1. The dry run (`php maintenance.php retention --form=kontakt`) lists what would be deleted and prints a confirmation digest.
2. The destructive run needs `--apply` **and** that exact digest. If the set of records changed since the dry run (or the UTC day changed), the digest no longer matches and nothing is deleted.
3. Before deleting, the records are written to an integrity-checked archive in `retention.archive_dir`. The submission's workflow notes and delivery log are archived and removed together with it.

Retention works per form and with every storage backend. It is intentionally not unattended: a person runs the dry run and confirms it, typically once a month. Keep the archive and backup directories outside the web root.

### Uploaded files: deletion, archives and complete backups

Deleting a submission through the viewer/API or retention deletes its uploaded files too; interrupted file deletions are finished by upload cleanup. Deleting only a form definition in the editor does **not** delete its submissions or files. Abandoned payment submissions keep their files until the submission is deleted or retained out.

Retention dry runs include file count and bytes, and the confirmation digest covers file IDs. By default the archive preserves descriptors/hashes **but not file bytes**. To keep a temporary copy of the bytes, add both settings to your existing `retention` block:

```php
'archive_files' => true,
'archive_files_days' => 30,  // required with archive_files: integer 1–36500
```

These copies live beside the retention archive in a `.files` directory and are pruned by subsequent **applied retention runs while `archive_files` is enabled**, not by `uploads-cleanup` or a timer. Keep running retention if you rely on that expiry. The descriptor archive remains; arrange your own retention for archives and backups. A retention archive is not a substitute for a restorable BBF backup.

`backup --form=...` creates a JSON bundle plus, when files exist, a **`<bundle>.files/` sidecar**, containing `<submission_id>/<file_id>` bytes. Copy and protect the JSON and sidecar together, preserving their names. Backups verify file hashes; a database-only backup or JSON export is not a complete upload backup.

```bash
php maintenance.php backup --form=application
php maintenance.php restore --bundle=/private/backups/application.json
# Replace RESTORE_CONFIRMATION with the exact confirmation printed by the dry run.
php maintenance.php restore --bundle=/private/backups/application.json --apply --confirm=RESTORE_CONFIRMATION
```

Use the actual bundle path returned by `backup`. Restore requires an **empty target for that form**, including definition, records, versions, review/outbox data and upload directory. Enable/configure uploads on the target first; the storage backend and protected access policy must match the backup. The dry run checks sidecar presence, size, SHA-256 and stored-file capacity. Restore places files before publishing the form definition. Restoring a hosting database without its matching uploads can leave missing downloads and unreferenced directories; BBF cannot reconstruct absent bytes.

### Upload housekeeping and recovery

Run from the installation directory, or use an absolute path to `maintenance.php`; schedule these with cron if available:

```bash
php maintenance.php submit-recover
php maintenance.php uploads-cleanup
```

These commands **perform recovery/cleanup immediately**; they are not dry runs and take no `--apply`. Run `submit-recover` before changing storage settings. `uploads-cleanup` removes expired staging data, orphaned staging remnants and dead upload reservations, finishes eligible recorded deletions, recovers safe unfinished restore phases and recounts capacity. It reports unresolved work and directories without records — it does **not** blindly delete stored files just because a record cannot be found. Inspect its report even when the command succeeds. Ordinary upload traffic also performs staging cleanup, but a quiet site needs scheduled runs to remove expired temporary files promptly.

If cleanup reports an unfinished restore with unpublished records (`records_pending`), review and explicitly abort it before retrying restore:

```bash
php maintenance.php restore-abort --form=application
# Replace ABORT_CONFIRMATION with this dry run's exact confirmation.
php maintenance.php restore-abort --form=application --apply --confirm=ABORT_CONFIRMATION
```

This removes that unfinished restore's unpublished records, versions, review/outbox data and files together; it refuses once the form definition is published. Do not delete upload directories or edit the quota ledger by hand. `check.php` also reports effective limits, capacity warnings, pending deletions, unfinished restores and unresolved accounting entries; remove it again after diagnostics.

---

## Visit Attribution (UTM, Google Ads, Umami)

Optional. Stores where a lead came from with every submission — without adding hidden fields to each form.

1. Merge the keys from `config.attribution.example.php` into `config.php`. It enables `utm_*`, `gclid`/`gbraid`/`wbraid`, `landing_url`, `referrer` and `touch_at` as **system fields** that are added to every form automatically.
2. To capture the click ID on landing pages **without** a form, include `gclid.js` on every page:
   ```html
   <script src="/bbf/gclid.js" defer></script>
   ```
   Pages with a form don't need it — `bbf.js` loads the capture script itself.
3. With `'analytics' => ['umami' => true]` and an existing Umami tracker on the page, a successful submission sends a `form_submitted` event. Every successful submission also fires a `bbf:submitted` DOM event you can hook into yourself.

Upgrading an installation that already had its own hidden `gclid`/`utm_*` fields? `php tools/migrate-system-fields.php --fields=gclid,gbraid,wbraid` shows which form files would change; add `--apply` to write them.

---

## File Structure

What you upload (the release ZIP):

```
barebonesforms/
├── config.example.php  ← Copy to config.php, edit once
├── config.attribution.example.php ← Optional UTM / Google Ads keys to merge into config.php
├── submit.php          ← POST handler (also serves form definitions and drafts)
├── payment.php         ← Stripe webhook handler
├── submissions.php     ← API: list/export submissions
├── viewer.php          ← Submissions dashboard + inbox (optional, delete if unused)
├── editor.php          ← JSON editor with live preview (optional, delete if unused)
├── sandbox.php         ← Test forms without side effects
├── smoketest.php       ← Validate all forms (CLI or token-protected HTTP)
├── check.php           ← Installation diagnostics (delete after use)
├── maintenance.php     ← CLI: backup, restore, retention
├── bbf_*.php           ← Internal libraries (not web entry points)
├── bbf.js              ← Form renderer (zero dependencies)
├── bbf.css             ← Default styles with --bbf-* CSS variables (auto-loaded by bbf.js)
├── bbf-theme.css       ← Example themes: dark, corporate, warm, minimal (copy & customize)
├── bbf-context.js, gclid.js, bbf-analytics.js ← Optional visit attribution / Umami (auto-loaded when enabled)
├── .htaccess           ← Protects sensitive dirs (Apache)
├── docs.html           ← Full documentation (standalone)
├── lang/               ← Language packs (32 languages, .js for browser + .php for server)
├── forms/
│   ├── form.schema.json ← JSON Schema for IDE autocomplete
│   └── kontakt.json     ← Your form definitions
├── templates/          ← Email templates (confirm/notify)
├── actions/            ← Custom post-submit actions (optional)
├── tools/              ← CLI migration helpers
├── submissions/        ← Stored data (auto-created, never web-accessible)
├── logs/               ← Rate-limit and audit logs (auto-created)
└── demo*.html, index.html, api-psc.php, data/ ← Demos — safe to delete
```

The git repository additionally contains `tests/` and `tools/package-deploy.php`; see [Development](#development).

---

## Let AI Write Your Forms

The schema is simple enough that any AI can produce a valid form:

> "Create a BareBonesForms JSON (schema_version 1) for a job application form with name, email, phone, position dropdown, portfolio URL (optional), cover letter (textarea, min 50 chars), and GDPR consent checkbox."

Copy the JSON, save as `forms/job-application.json`, embed. The `form.schema.json` schema file gives AI and your IDE the exact format spec.

### Note for AI assistants

If you're an AI helping a user build, embed, or style a BareBonesForms form, read these files — in this order — before writing code:

1. **This README** — you're here. Gives you the concept, JSON structure, field types, and `on_submit` pipeline.
2. **[docs.html](docs.html)** — the full reference. Contains CSS class table (21 entries), `show_if` operators, `config.php` options, storage backends, per-form overrides, and everything not covered here.
3. **At least one demo** (`demo1.html`–`demo9.html`) — see real embedding in context. Demo 1 is the simplest; Demo 4 is the feature showcase; Demo 8 shows reusable templates.
4. **[form.schema.json](forms/form.schema.json)** — the machine-readable schema. Use it to validate JSON you generate.

**Common pitfalls to avoid:**
- Do not add a `<link>` tag for `bbf.css` — it is auto-loaded by `bbf.js`.
- To theme forms, override `--bbf-*` CSS variables on `.bbf-form-container` (see "Theming with CSS variables" section). Do not hardcode colors — use variables.
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
- [ ] `submissions/` and `logs/` not web-accessible — ideally moved outside the web root ([how](#keep-data-outside-the-web-root-recommended))
- [ ] Storage backend matches expected volume (SQLite or MySQL beyond ~1000 submissions per form)
- [ ] Retention period decided (`retention` in `config.php`) if your privacy policy promises one
- [ ] `allowed_origins` set if embedding cross-domain
- [ ] `error_notify` set for admin error alerts (max 1/day)
- [ ] `stripe` keys set if using payments
- [ ] `'sandbox' => false` for production
- [ ] `smoke_token` set if you want post-deploy smoke testing (leave empty to disable)
- [ ] `diagnostic_base_url` set and `check.php` run: all checks passed, protection **verified** (sign in with `api_token`)
- [ ] **`check.php` deleted after verification** — it exposes PHP version, extensions, paths, and config details
- [ ] `editor.php` deleted or protected — can modify form definitions
- [ ] `viewer.php` deleted or protected — exposes submission data
- [ ] Demos removed (`demo*.html`, `demo.css`, `index.html`, `forms/demo-*.json`, `api-psc.php`, `data/`) unless you want them public

---

## Requirements

- PHP 8.1+
- Extensions: `json`, `session` (required); `mbstring` recommended for complete Unicode case folding (the bundled PSČ demo has a Czech/Slovak fallback)
- `pdo_sqlite` or `pdo_mysql` (depending on storage backend)
- Any web hosting with PHP support

---

## Development

```bash
php tests/review-ci-test.php                               # the full CI gate: every PHP + Node suite, isolated temp installs
php tests/review-access-mysql-test.php                     # extra MariaDB/MySQL matrix (needs a local server)
php tools/package-deploy.php --destination ../barebonesforms   # build the upload-ready folder
```

Tests never touch your `config.php`, `forms/` or `submissions/` — each suite creates its own throw-away installation. Node 20+ and Python with `jsonschema` are needed for the browser-side and schema suites.

**Releasing:** add a section to [CHANGELOG.md](CHANGELOG.md), then push a `vX.Y.Z` tag. The [Release workflow](.github/workflows/release.yml) builds `barebonesforms-vX.Y.Z.zip` from the same allowlist and publishes it on GitHub Releases with the changelog section as release notes.

---

## The Genesis

On the eighth day of March, in the year of our Lord 2026, after dragging several godforsaken websites through migrations that would test the patience of a saint and the sanity of a PHP developer alike, I poured myself a glass and spoke plainly to the empty room: *Enough.*

For years those sites ran on MachForm, and it did its job. My quarrel was never with the product — it was with the way of life it demanded. Every form lived inside a database. Every upgrade was an operation of its own, and each site ended up on its own version, wanting its own PHP. Moving a form from one host to another was never a matter of copying a file; it was export, import, and prayer.

BareBonesForms is what emerged from that resolve. A form is one JSON file. No database unless you want one. An upgrade replaces the code and leaves your forms and submissions alone. Moving to another host means copying a folder. Simplicity ain't a weakness — it's the only honest architecture.

Now, since I hold the belief that others in this wretched camp of web development may suffer the same afflictions — perhaps you, too, went looking for a MachForm alternative that lives in plain files — I'm putting this out in the open. Take it. Use it. Bend it to your purposes. It's MIT licensed.

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
