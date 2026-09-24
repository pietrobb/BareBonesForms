# Changelog

All notable changes to BareBonesForms. Upgrade steps are in the [README](README.md#upgrading).
Items marked **Breaking** need action when you upgrade an existing installation.

## [Unreleased]

### Fixed
- **Translations:** all 32 language packs now carry the messages for drafts, repeatable groups, submit retries and file uploads (they showed in English). Server upload errors come from `lang/*.php`, and the server answers in the form's `data-lang` when that pack exists. A new CI suite fails when any pack misses a message or a placeholder.

### Documentation
- README now answers the questions a first reviewer asks before trusting file storage: how concurrent submissions are written (lock, temp file, atomic rename), when to switch from files to SQLite or MySQL, how to keep submissions outside the web root, what retention does and why you might want it, and how the JSON Schema catches typos in the editor. File uploads are listed as not supported yet.

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
