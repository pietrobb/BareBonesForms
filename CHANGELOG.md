# Changelog

All notable changes to BareBonesForms. Upgrade steps are in the [README](README.md#upgrading).
Items marked **Breaking** need action when you upgrade an existing installation.

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
