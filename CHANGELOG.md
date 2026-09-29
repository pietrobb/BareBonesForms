# Changelog

All notable changes to BareBonesForms. Upgrade steps are in the [README](README.md#upgrading).
Items marked **Breaking** need action when you upgrade an existing installation.

## [2.1.1] — 2026-09-29

Fixes from an independent code review of 2.1.0.

### Breaking
- **Access tokens shorter than 16 characters are no longer accepted** (`api_token` and `access_tokens`). A guessable token such as `admin123` was a working admin credential. If you cannot sign in after upgrading, set a long random token: `php -r "echo bin2hex(random_bytes(32));"`. A short entry in `access_tokens` disables the whole list, like any other malformed entry.

### Fixed
- **Enter on page 1 of a multi-page form submitted the whole form**, skipping the remaining pages. Enter now moves to the next page; only the last page submits.
- **A `pattern` containing `/` (e.g. `^\d{2}/\d{2}/\d{4}$`) made every submission fail with 500 "Invalid form definition".** Patterns are now compiled safely on the server and use Unicode mode on both sides, so diacritics count the same in the browser and on the server.
- **Opening `check.php` on a new SQLite installation broke the viewer** ("Cannot read submissions.") until the first submission: it created an empty `bbf.sqlite`. It no longer creates the file, and the viewer treats an empty file as "no submissions yet".
- **`submit.php?form[]=x` and an array `_bbf_csrf` caused a PHP fatal error** and a false incident email to the admin. Malformed parameters now get 400; text that is not valid UTF-8 gets 422 instead of 500.
- **The Stripe webhook could answer 503 forever** once one confirmation email or webhook failed permanently (for example a typo in the customer's domain), so Stripe would eventually disable the endpoint and stop marking payments as paid. It now asks Stripe to retry only while a retry can still help; permanent failures are alerted and visible in the viewer.
- **Chained `show_if` (A → B → C) was evaluated differently in the browser and on the server**, so the server could demand a hidden required field, and a forged value in a hidden field could satisfy a condition. Both sides now treat hidden fields as empty and settle the chain the same way; values of hidden fields are neither validated nor stored.
- **Cross-field rules (`min_sum`, `min_filled`) rejected by the server showed only a generic error.** Their message is now shown.
- **Phone validation differed:** `(555) 123-4567` was refused in the browser but accepted by the server. Both use the same rule now.
- **Resetting a form after a successful submit cleared default values.** Defaults are restored.
- **An email address like `"a,b@evil.test,c"@example.com` passed validation** and was then split into several recipients, so the confirmation also went to a foreign address. Quoted local parts and separators are refused.
- **Emails were not fully RFC 5322 compliant:** `Date` and `Message-ID` headers were missing, bodies are now quoted-printable (the summary of a long form exceeded the 998-character line limit), and a sender name with a comma ("Firma, s.r.o.") is quoted instead of turning into two addresses.
- **Upgrades:** the dry run now names the files it adds and replaces. `check.php`, `api-psc.php` and `data/`, which README tells you to delete, are no longer put back. `.htaccess` is kept when you added lines to it (e.g. cPanel `AddHandler` for the PHP version). An upgrade from the code-only `-upgrade.zip` no longer records templates it did not install, which kept them from ever being updated. An upgrade killed half-way can be undone with `upgrade-rollback`, and a rollback that failed can be run again.
- **Viewer:** a Sign out button; management pages can no longer be framed by other sites (clickjacking); the CSV export starts with a UTF-8 BOM so Excel shows diacritics correctly.
- **Sign-in:** after 10 wrong tokens from one address in 15 minutes, further attempts get 429 until the window passes.
- **Accessibility:** Next/Back in multi-page forms moves focus to the first field of the new page, removing a repeatable row keeps focus next to it, and hint text has WCAG AA contrast (`--bbf-text-light` is now `#6b6b6b`).
- **Schema:** field-level `suffix`, `label_position` and a display `prefix` such as `"€"` are documented and now accepted by `form.schema.json`.

### Added
- **`php maintenance.php deliveries-retry`** runs failed emails, webhooks and actions whose automatic retry is due (the retry time was computed, but nothing ran it). Run it from cron every few minutes.
- **Language packs load themselves.** `data-lang="sk"` is enough; `bbf.js` fetches `lang/sk.js` like it fetches `bbf.css` unless the page already loaded it.
- **`trusted_proxies`** in `config.php`: behind Cloudflare or a reverse proxy, rate limits and stored IPs use the visitor's address from `X-Forwarded-For` instead of lumping everyone together under the proxy's address.
- **Verifiable releases.** Each release publishes `SHA256SUMS` and signed build provenance (`gh attestation verify barebonesforms-vX.Y.Z.zip -R pietrobb/BareBonesForms`). `php maintenance.php upgrade --package=<zip> --checksum=<sha256>` refuses a ZIP that is not the published one.
- CI runs the regression suite on PHP 8.2, 8.3 and 8.4 (plus the 8.1 minimum-version gate) and now includes the `error_notify` alert tests.

## [2.1.0] — 2026-09-29

### Added
- **File uploads.** Fields of `type: "file"` upload each file immediately with progress, remove and retry, check type and size on the server, and keep the bytes in a private `uploads.dir` outside the web root. The viewer downloads them only for signed-in users; export, deletion, retention and backup/restore include them. Off by default: enable `uploads` in `config.php`. See [File Uploads](README.md#file-uploads).
- **Safe submit retries.** `bbf.js` sends one submit key per filled form. A retry after a timeout or lost connection returns the original result instead of creating a second submission, sending emails twice or starting a second payment, and a submit interrupted by a crash is finished by later requests or by `php maintenance.php submit-recover`. New optional settings: `submit_replay_ttl`, `submit_transaction_timeout`, `submit_replay_rate`, `submit_recovery_budget`. Pages that still load an older cached `bbf.js` keep working.
- **Problem alerts by email (`error_notify`).** BareBonesForms now tells you when a form stops working instead of leaving you to find out from a customer: a form that worked before is missing or its definition is invalid, a notification email, webhook or action fails, storage or a payment fails, uploads are blocked, or `submit.php` hits a PHP fatal error. Every incident goes to `logs_dir/incidents.log`; the email is sent after the visitor has a response, through your SMTP with a PHP `mail()` fallback, grouped into one message and throttled per form and problem (`error_notify_interval`, default one hour), with a hard ceiling of 6 alert emails per hour. New commands: `php maintenance.php selfcheck` (daily cron: forms, writable folders, SMTP login, stuck deliveries), `alerts`, `alerts-test`. Recipients come only from `config.php` and visitors cannot write or multiply alert emails; see [Error notifications](README.md#error-notifications). Replaces the previous single global 24-hour notification.
- **One-command upgrades.** Releases now know their version (`php maintenance.php version`, shown in `check.php` and the viewer) and ship `.bbf-manifest.json` with the checksum of every file. `php maintenance.php upgrade --package=<release.zip>` verifies the package, runs the new smoke test against your forms, lints the new PHP files, and lists new config settings and **Breaking** changes since your version — without changing anything. With `--apply --confirm=<digest>` it backs up every file it touches to `logs_dir/upgrades/`, replaces only files the release owns, keeps sample forms and email templates you edited, removes obsolete unchanged files, re-runs the smoke test and rolls back by itself on failure. `upgrade-rollback --backup=<folder>` undoes a finished upgrade. Installations older than 2.1.0 upgrade with `php barebonesforms/tools/upgrade.php --install=<folder>` from the unpacked release. Each release also has a code-only `-upgrade.zip` that is safe to upload over FTP. See [Upgrading](README.md#upgrading).
- **Update notices.** `php maintenance.php selfcheck` asks GitHub for the latest release and tells `error_notify` once per new version. `'update_check' => false` turns it off.
- **Demo 10: Retro Guestbook** — a 1998 GeoCities guestbook, styled only with `--bbf-*` variables and a few `.bbf-*` selectors in the page. The form is plain JSON with `show_if` sections, a rating and "other" options; the server validates and stores it like any other form. All animation stops under `prefers-reduced-motion`.

### Fixed
- **A crafted JSON body could crash `submit.php`.** About 8 MB of tiny nested arrays exhausted PHP memory before the rate limit ran, so anyone could make requests fail cheaply. JSON bodies over 1 MB or with more than 20,000 objects/arrays are now refused with 413 before decoding; real submissions are far below this (files use the upload endpoint).
- **A restore without files that died before publishing left reachable records.** Its unpublished records could be read through the viewer and the API, and neither a new restore nor `restore-abort` could clean them up. Every restore now writes a marker in `submissions/.restore/` before its first record; while it exists the records stay unreachable, and `restore-abort` removes them.
- **Windows:** publishing a JSON file retries a blocked rename for up to about 1 second instead of 100 ms, so a reader or antivirus scan holding the file no longer fails a write.
- **Translations:** all 34 language packs now carry the messages for drafts, repeatable groups, submit retries and file uploads (they showed in English). Server upload errors come from `lang/*.php`, and the server answers in the form's `data-lang` when that pack exists. A new CI suite fails when any pack misses a message or a placeholder. Lithuanian and Latvian packs preserve their original airdomes.sk messages and now ship with the package.
- **Form load errors:** when loading a form definition fails with anything other than 404, `bbf.js` now shows the server's message (for example "Missing config.php. Copy config.example.php to config.php and edit it.") instead of a misleading "Form not found". A 404 still shows the translated "not found" message.

### Documentation
- README now answers the questions a first reviewer asks before trusting file storage: how concurrent submissions are written (lock, temp file, atomic rename), when to switch from files to SQLite or MySQL, how to keep submissions outside the web root, what retention does and why you might want it, and how the JSON Schema catches typos in the editor.

## [2.0.4] — 2026-09-23

Found by installing 2.0.3 on real shared hosting (Hetzner, Apache, PHP 8.2).

### Fixed
- **`check.php` could not verify anything on hosts with `allow_url_fopen=0`** (common on shared hosting). The HTTP probes now use cURL when available, like the smoke test already did. When `diagnostic_base_url` is set but the request fails, the message says so instead of asking you to set it.

### Documentation
- **The Genesis** (README and `docs.html`) now names MachForm as the system BareBonesForms replaced, and says plainly what the move was about: forms in files instead of a database, upgrades that leave data alone, and forms you can carry from host to host.

## [2.0.3] — 2026-09-23

Fixes from a second first-install test of 2.0.2.

### Fixed
- **`check.php` reported a false ERROR for `tests/`**, which does not exist in a release: `php -S` (and Nginx with an SPA `try_files … /index.html` fallback) answer a missing file with `index.html` and HTTP 200. Missing directories are now skipped, and a 200 whose content is not the probed file counts as a fallback page, not a leak.
- **`config.php` answering an empty HTTP 200** (PHP ran it and the `BBF_LOADED` guard stopped it) is now a warning — nothing leaked, but the server rule is still missing — instead of an error.
- **Sign-in page** is titled "Sign in" instead of "Access denied." before the first attempt, and says "Invalid token" after a rejected one.

### Changed
- `config.example.php`: `api_token` and `diagnostic_base_url` moved to the top of the array (same values).
- README and docs: one line on what "bare bones" means; `editor.php` is described as a JSON editor with live preview, not a form builder. "Try it locally" explains how to run the `check.php` probes on Windows, where `php -S` has no workers.

## [2.0.2] — 2026-09-23

Fixes from a first-install test that followed the README step by step.

### Fixed
- **`check.php` could report a false "blocked".** It only requested directory URLs, which return 403/404 even on servers that serve the files inside (Nginx without rules, `php -S`). It now requests a real file in each protected directory — a shipped one such as `templates/notify.html`, or a disposable sentinel it writes and deletes — and fails when the file is readable. Without a reachable `diagnostic_base_url` the verdict now says protection is **not verified** instead of "All checks passed".
- **Sign-in for `check.php`, `viewer.php` and `editor.php`.** They showed a bare `{"error":"Access denied."}`; they now show a sign-in form (token sent by POST, not in the URL). `check.php` without `config.php` says so instead of "Access denied". README and docs no longer claim localhost is unrestricted — a token has always been required.
- **Smoke test failed right after install** on the PSČ demo: generated text ignored `pattern` / `maxlength`. Test values now satisfy the field's rules, and the demo placeholder (`811 01`) no longer contradicts its own pattern.
- **`php tools/package-deploy.php --destination ../barebonesforms`** (the command from the README) was rejected; relative paths with `..` now work.
- **Release ZIP root folder had mode 0700**, so unpacking it over SSH could make everything return 403. It is now 0755.
- The ZIP now includes `CHANGELOG.md`, which the README's upgrade steps link to.
- A select with a placeholder returned to its first option instead of the placeholder after a successful submit.

### Changed
- `config.example.php` starts with the four settings a new install must change, and the crowded token/attribution lines are split into commented entries (same values).
- README: "Try it locally in 2 minutes" with `php -S`.

## [2.0.1] — 2026-09-23

Documentation-only release. No code changes; upgrading from 2.0.0 means replacing `docs.html`.

### Fixed
- **`docs.html` payments section** still described the removed client-supplied `amount` / `amount_field` contract. It now documents server-owned pricing (`fixed`, `catalog`, `donation`) with validated examples.
- **`docs.html` Nginx rules** now match `.htaccess`: installation-path prefix, `bbf_functions.php`, `tests/`, `data/`, backup-file variants and all dotfiles are blocked, while `lang/*.js` stays public (the old rules blocked the whole `lang/` directory, which broke translations). Added a way to verify the rules on the live server.

### Added
- `docs.html` now covers everything in 2.0.0 and the March features it was missing: upgrading, repeatable groups, drafts, lookup / autocomplete, `options_from`, viewer inbox and delivery retry, form versions, scoped tokens, smoke test, retention and backups, visit attribution, `reply_to`, action response override, `onSuccess` / `onError`, `bbf:submitted`.

## [2.0.0] — 2026-09-23

Everything since v1.0.1 (March 2026): six months of production use on several business sites, a full security/reliability review, and the features below.

### Breaking
- **Payments use server-owned prices.** The client-supplied `amount` / `amount_field` contract is removed. Payment forms need `mode` (`fixed`, `catalog` or `donation`), `pricing_version` and integer `amount_minor` (e.g. `4990` = 49.90). `php smoketest.php` reports every form that needs migration.
- **Smoke test token moved to a header.** HTTP smoke tests use `X-BBF-Smoke-Token`; `?token=` in the URL no longer works. Live mode is `POST smoketest.php?live=1` and needs `diagnostic_base_url` in `config.php`.
- **Webhooks require HTTPS** on port 443. Plain `http://` webhook URLs are rejected.
- **Own hidden `gclid` / `utm_*` fields:** if your forms carry them and you enable the new `system_fields`, run `php tools/migrate-system-fields.php --fields=...` to remove the duplicates (dry run first).
- **Nginx users:** the example rules now use a `BBF_BASE/` prefix — replace it with your installation path (see README → Security).

### Added
- **Repeatable groups** — `"repeatable": true` on a group with `min_items` / `max_items`.
- **Save & resume drafts** — opt-in per form, allowlisted fields only, resume code, expiry.
- **Viewer inbox** — status (new / in-progress / done), private notes, tags and filters.
- **Delivery log and retry** — every email, webhook and custom action is recorded per submission; failed ones can be retried from the viewer. Limits in `config.php` → `delivery`.
- **Form versions** — submissions keep the form definition they were filled in with.
- **Retention and backups** — `php maintenance.php backup | restore | retention`, dry run first, confirmation digest for destructive steps. Retention is off by default.
- **Scoped access tokens** — per-form tokens with `read` / `export` / `delete` / `review` permissions and expiry (`access_tokens`).
- **Visit attribution** — optional `system_fields` (UTM, `gclid`, `gbraid`, `wbraid`, landing page, referrer), `bbf:submitted` DOM event, optional Umami `form_submitted` event.
- **Stripe Checkout payments** — fixed, catalog and donation modes; emails and webhooks wait for payment confirmation.
- **Lookup, autocomplete and dynamic options** — `lookup`, `autocomplete_from`, `options_from`; demo 9 (postal code ↔ city).
- **Smoke test** — `smoketest.php` dry and live modes, separate `smoke_email` / `smoke_notify`.
- **Viewer** — dashboard, card/table views, search, saved filters, bulk delete, forwarding by email, print.
- Custom action response override (`$actionResponse`) and `onSuccess` / `onError` callbacks.
- Configurable `reply_to` for email actions; custom radio/checkbox styling; theming via CSS variables.
- **Release ZIP** — each tagged release publishes `barebonesforms-vX.Y.Z.zip` containing only the files that belong on a server.

### Changed
- Storage writes are locked and fail safely on partial writes instead of corrupting data.
- Exports and the viewer stream large data sets instead of loading everything into memory.
- CI runs the full suite on PHP 8.1 and 8.2 and against a real MariaDB.
- The upload-ready package is no longer committed to the repository (`deploy/barebonesforms/` was a generated copy). Download it from Releases or build it with `php tools/package-deploy.php`.

### Fixed
- Many edge cases found in review: CSV quoting and formula escaping, conditional logic normalization, numeric/rating validation, draft and repeatable combinations, viewer back navigation, smoke test on hosts with `allow_url_fopen=0`, accessibility issues.

## [1.0.1] — 2026-03-10
- Bug fixes after the first public release.

## [1.0.0] — 2026-03-09
- First public release.
