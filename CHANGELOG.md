# Changelog

All notable changes to BareBonesForms. Upgrade steps are in the [README](README.md#upgrading).
Items marked **Breaking** need action when you upgrade an existing installation.

## [2.1.8] — 2026-09-30

Fixes from the review of 2.1.7.

### Security
- **Breaking: access tokens now require at least 32 hexadecimal characters.** This applies to `api_token`, scoped `access_tokens` and `smoke_token`. Passwords, UUIDs, base64 strings and shorter tokens are ignored. Generate each credential with `php maintenance.php new-token`: it prints 64 hex characters from 32 cryptographically random bytes. The password dictionary and entropy estimator are removed. Format validation cannot establish random origin: never invent a token by hand, use repeated characters or copy a sample value. Correct tokens still authenticate even when wrong guesses from the same address are blocked.
- **Upgrade lockout is not success:** if the new policy leaves no usable administrative token, the apply result explicitly reports that code was updated but administrative access is blocked, returns `ok: false` and exits nonzero. Replace the token in `config.php`, then run `php check.php` or `php maintenance.php selfcheck`. The sign-in page explains the token requirements; diagnostics no longer mark a rejected `api_token` as configured correctly.
- **Release integrity:** releases remain drafts until downloaded assets match the build's `SHA256SUMS`; only then are they published.

### Fixed
- **Concurrent CSRF recovery:** uploads and drafts share a pending token refresh. A rejected upload does not create a session or replace its cookie, and does not consume the upload quota. Removing a file during recovery prevents its retry.
- **Upload errors:** disabled uploads and unknown forms do not flood the error log or masquerade as broken storage. Genuine storage problems remain diagnosable.
- **Numeric field names in email templates:** confirmation and notification bodies preserve keys such as `"1"` and `"5"`, while still escaping respondent values.
- **Upgrade/security rules:** stock legacy `.htaccess.dist` files are recognized across published releases; rule checks preserve block context instead of treating all lines as one unordered set. Child-process exit codes survive long PHP-notice output.
- **Documentation:** upgrade ZIPs include `docs.html` so old installations receive the current token and upgrade instructions.
- **Proxy/configuration:** invalid `cookie_path` is reported; client-supplied forwarding headers do not create trusted-proxy warnings, and Stripe return URLs use trusted TLS-proxy detection.
- **Respondent messages:** payment gateway failures use the selected language; disabled drafts are distinguished from missing drafts.

### Upgrading
- **Breaking: `show_if.value` cannot be an object.** This validation was introduced in 2.1.7 but omitted from its Breaking notes. Use a scalar or a list of scalars. Upgrade preflight validates existing forms with the new code and names incompatible definitions before replacing files.
- Replace incompatible credentials before upgrading. Use `--checksum` so the package's upgrader and diagnostics evaluate the new policy. FTP uploads do not run preflight: generate tokens, validate forms, and run diagnostics yourself. No production installation is upgraded automatically.

## [2.1.7] — 2026-09-30

Fixes from the review of 2.1.6.

### Security
- **Breaking: a token that is easy to guess is refused.** Sample values (`your-secret-token`, `change-me`), words, names, years, keyboard runs (`qwerty`) and repeats are ignored like a too-short token. Set a random one: `php -r "echo bin2hex(random_bytes(24));"`. After the upgrade run `php check.php` or `php maintenance.php selfcheck`: they name every ignored token. Details: the right token always signs in, even from an address blocked for wrong tokens, so the token itself must withstand fast guessing (about 1500 tries per second were measured). Each token now gets a pessimistic estimate of the guessing work, where words (also Capitalised or in leetspeak), sample-value parts, runs, years and repeats cost only a few bits; it needs at least 44 bits (2^44 guesses at 1500/s take about 370 years) and must not consist of such parts only. `password12345678`, `adminadminadmin1`, `qwertyuiopasdfgh`, `Summer2026!Summer` and `my-super-secret-api-token` are refused; of 1 000 000 random tokens of each kind (16 and 32 hex digits, base64, UUID, password-manager output) none was. The documentation no longer shows a sample token.
- **An expired or revoked token** sent from a blocked address is answered `429` like any other wrong token, instead of `403`, which told that it had once been valid.
- **A CSV/export link opened from another site** (webmail, chat) explains why its `?token=` is ignored instead of a bare "Access denied.".
- **Release workflow:** after publishing, the release files are downloaded again from GitHub and checked against the `SHA256SUMS` made when they were built, not only between the build and publish jobs.

### Fixed
- **Drafts and file uploads after an expired session:** saving, loading or deleting a draft, uploading and removing a file refresh the CSRF token once after `403` and repeat the request, as sending the form does. Before, every draft save failed in a tab opened before the upgrade to 2.1.6 (cookie `BBFSID`) or more than 24 minutes ago, and the advice to reload lost the answers the draft was meant to keep.
- **A field with a numeric name** (`"7"`, `"2024"`) is filled into `redirect` again (`{{7}}`); 2.1.6 left it empty.
- **Security-rules notice after an upgrade:** an `.htaccess.dist` left by any earlier release is replaced by this release's, and `.htaccess` is compared by its rules only, so a release that changes only comments raises no daily "Security rules missing".
- **`docs.html`** no longer shows a version in its title (a code-only upgrade does not replace it, so it showed an old one).
- **Behind a reverse proxy:** new `cookie_path` for a proxy that serves the folder under another path (`/forms/` → `/bbf/`), where every submission used to fail with `403`. `X-Forwarded-Proto: https` from a proxy listed in `trusted_proxies` counts as HTTPS, so cookies are `Secure` and forms in iframes on other sites work behind a proxy that ends TLS.
- **`"redirect": "#done"` on a page with `<base href>`** (single-page apps) stays on the page instead of navigating to the base address.
- **`show_if` with an object as `value`** is reported by the definition check (the browser and the server compared it differently). `form.schema.json` accepts `"value": 5`, `true` and `"op": null`, which the engine has accepted since 2.1.6.
- **Restoring a backup made with a now-refused token** names that token and says how to replace it, instead of "Restore access policy does not match".
- **Upgrade:** the `--apply` result repeats the access warnings, judged by the code just installed. A token check or package upgrader that crashes reports its exit code instead of looking like success. A long **Breaking** note ends at a full sentence instead of being cut in the middle.
- **Upload storage not ready** (folder in the web root, not writable): the respondent gets "The server cannot store uploads right now." in their language; the setup advice goes to the PHP error log (and still to the editor's sandbox).
- **E-mail confirmation box** is labelled from the language pack (`emailConfirm`, all 34 languages) instead of an English "Confirm …" guessed from another message.

### Upgrading from 2.1.6 or older
Use `--checksum`: then the 2.1.7 upgrader inside the package plans and applies the upgrade and names every token 2.1.7 will ignore, in the dry run and again in the `--apply` result. Without it the installed upgrader does all the work and judges tokens by its own, older rules. The process you start is always the installed version, so under 2.1.5 or older it can still hang on very many PHP notices (turn `display_errors` off for the command: `php -d display_errors=0 maintenance.php upgrade …`). In any case run `php check.php` or `php maintenance.php selfcheck` after the upgrade.

## [2.1.6] — 2026-09-30

Fixes from the review of 2.1.5.

### Security
- **The right token always signs in again (as in 2.1.2).** In 2.1.3–2.1.5 a blocked address got one token check per 2 seconds, and whoever came first took it: someone sharing the admin's address (NAT, IPv6 /64, a proxy or Cloudflare without `trusted_proxies`) could keep the admin and scripts using `submissions.php` out by sending wrong tokens every fraction of a second. The claim in 2.1.5 that this was no longer possible was wrong. Now only wrong tokens are limited (10 per 15 minutes per address, then `429` at once with `Retry-After`); a correct token is always checked and accepted. The protection against guessing is the token itself, see the next item. A blocked wrong token says "Invalid token." instead of "Not checked.".
- **Breaking: a token with an obvious pattern is refused.** `api_token`, `access_tokens` and `smoke_token` still need at least 16 characters, and now also must not be a pattern: fewer than 5 different characters (`aaaa…`, `abab…`) or mostly consecutive/repeated characters (`1234…`, `abcd…`, `9876…`). Such a token is ignored like a too-short one; check.php, selfcheck and the upgrade dry run name it. A random token (e.g. `php -r "echo bin2hex(random_bytes(16));"`) is never affected.
- **Respondent cookie `BBFSID`.** The CSRF session cookie is now named `BBFSID` and limited to the installation folder. Before, it was the shared `PHPSESSID` on `/`, and strict session IDs replaced the session of another PHP application on the same domain, signing its users out whenever a page with a form was loaded. On HTTPS it is `SameSite=None; Secure`, so a form embedded in an iframe on another site can be submitted. 2.1.4/2.1.5 claimed this worked with the browser default, but it did not (browsers that block third-party cookies still block it; embed with `BBF.render` on the page instead).
- **Nginx rules:** `forms/`, `README.md`, `CHANGELOG.md` and `.bbf-manifest.json` are denied by `^~` / `=` locations, which win over any regex location. A static-file cache rule such as `location ~* \.(css|js|json)$` placed earlier used to serve form definitions. Paste the remaining `location ~` lines above your other regex locations (see the comments in `.htaccess`).

### Fixed
- **`show_if` with `"op": null` or `"field": null`** (written by generators and editors for "not set") is treated as a missing key again. 2.1.5 rejected such a form and every submission answered 500.
- **`show_if` with a number** (`"value": 5`) now matches the field value `"5"` in the browser too, as on the server. The field stayed hidden in the browser while the server required it, so the form could not be sent.
- **Upgrade could hang** when the smoke test or the package upgrader printed more than about 64 KB of PHP notices (e.g. deprecations from `config.php`): the parent read one pipe to the end while the child waited on the other. stderr now goes to a temporary file.
- **Upgrade dry run without `--checksum`** lists access warnings again (tokens that would be ignored), judged by the installed version (`access_warnings_by`).
- **`.htaccess.dist`** left by any earlier release is recognised by comparing it with this release's checksum from the manifest (also after an FTP transfer changed line endings). A release that only changes comments in `.htaccess` no longer reports "changed security rules".
- **Redirect that does not leave the page** (`"redirect": "#done"`, or a target answering 204 No Content) no longer leaves the submit button disabled: the success message is shown.
- **`{{_id}}`, `{{_form}}` and `{{_time}}` in `redirect`** are filled in; `{{_id}}` used to come out empty.
- **`maintenance.php deliveries-retry`:** an old record that cannot be read (locked, damaged) is counted as not checked and read again next time instead of being cached as delivered.
- **Server messages in the respondent's language:** drafts, uploads and a cross-field rule without its own `message` (new text `crossFieldInvalid` in all 34 languages) no longer answer in English.
- **Smoke test:** a `minlength` above 5000 characters (up to 1 MB) is met with plain text instead of always failing.
- **Alert mail:** a report that only says "Update available" no longer claims the problem is repeated at most once an hour; it says "one notice per new version".

## [2.1.5] — 2026-09-29

Fixes from the review of 2.1.4.

### Security
- **Sign-in limit can no longer lock the admin out or tie up PHP.** In 2.1.3/2.1.4 a blocked address waited in PHP (up to 10 s, holding a PHP worker and the session lock) and, once that queue was full, even the right token got 429 without being checked; one address could hold every worker of a small host and stall the public forms. Now PHP never waits: a blocked address still gets one token check per 2 seconds (right or wrong), every other attempt is answered `429` at once with the exact `Retry-After` (1–2 s instead of 900), so the right token gets in at the next free check. A signed-in browser is not affected. A blocked HTML sign-in shows the sign-in form with the wait instead of JSON.
- **Breaking: `?token=` no longer signs a browser in.** `viewer.php`, `editor.php` and `sandbox.php` ignore a token in the URL (login CSRF through a link, and an `<img src="viewer.php?token=x">` on another site spending your address's wrong-token budget); they show the sign-in form with a notice. Sign in with the form or send `X-BBF-Token`. The sandbox has its own sign-in form. `submissions.php?token=` (scripts, CSV links) keeps working, except for requests a browser marks as coming from another site (`Sec-Fetch-Site: cross-site`), which cannot use it.
- **Breaking (since 2.1.3): a redirect target that is only a field (`"redirect": "{{return_url}}"`) is not followed.** Field values in `redirect` are percent-encoded since 2.1.3 so a respondent cannot change the target (open redirect); such a template led to a relative 404 in 2.1.3/2.1.4. Now it shows the success message instead. Use a fixed target with the value as a parameter, e.g. `"/thanks?ref={{ref}}"`.
- **Upgrade dry run no longer runs unverified package code.** Since 2.1.3 a dry run started the package's upgrader and smoke test (with your `config.php` loaded) after checking the package only against its own manifest, which proves integrity, not origin. Now package code runs only with `--checksum=<SHA-256 from SHA256SUMS>` (or `--trust-package` for a package you built yourself). Without it the dry run checks checksums and PHP syntax only, reports `check: skipped` and says how to verify; `--apply` then uses the installed upgrader. The printed next command keeps `--checksum`. Takes effect from the next upgrade after 2.1.5 is installed (the running `maintenance.php` decides).
- **Upgrade on Windows:** only `maintenance.php` (held open by the running upgrade) may be written in place when renaming over it fails; any other file that cannot be renamed (after 5 retries, e.g. while a web request reads it) fails the upgrade and it rolls back, so the web never loads a half-written library.
- `submit.php`'s daily self-check logs a warning when requests arrive through a proxy (X-Forwarded-For / CF-Connecting-IP) while `trusted_proxies` is empty (check.php already showed it).

### Fixed
- **Upgrade with PHP notices:** with `display_errors=On` in the CLI (XAMPP, some Windows hosts) one notice, e.g. from `config.php`, broke the package upgrader's JSON result: the dry run failed, and a notice during `--apply` reported a finished upgrade as failed. Notices now go to stderr, the result is read after a marker line, and notices are listed under `php_messages`. The package side of this fix already applies to the upgrade from 2.1.4 to 2.1.5.
- **`smoketest --live` never sends to a made-up address.** When an email field has a `pattern` that `smoke_email` does not match, the smoke test used to generate a matching address (e.g. `a@firma.sk`, a real domain) and the confirmation email went there. Now that form fails with "smoke_email does not match the pattern of email field …" and nothing is submitted; set `smoke_email` to your own address that matches.
- **Smoke test data:** an extreme `pattern` (`(a{1000}){1000}`) or `minlength` no longer exhausts memory (generated values are capped at 5000 characters); a required field never gets an empty value (`^a*$`, a "Choose…" option with value `""` is skipped).
- **Audit log:** a `smoke_token` shorter than 16 characters is not a credential (live smoke refuses it, check.php/selfcheck warn), so it no longer masks ordinary words such as "contact" in the audit log.
- **"Back" after a redirect no longer stores a duplicate.** Returning from the thank-you page (back/forward cache) showed the form still filled in with the button enabled, and one click stored the same answers again under a new key. The restored form is now cleared (with the success message), and the next fill gets its own key.
- **Malformed `show_if` no longer breaks every submission.** A condition such as `"all": {…}` (object instead of a list), a string, or a non-string `field`/`op` made `submit.php` answer 500 on every submission while the browser ignored it. The server now evaluates such a condition like the browser (ignored), and the definition check (editor, smoketest, selfcheck, `submit.php`) names it, e.g. `fields[3].show_if.all: Expected a list of conditions`. An unknown `op` still means "equals" on both sides, so working forms are not rejected.
- **Redirect with `{{field}}`:** a checkbox value (joined with `,`) or a missing value (empty) no longer leaves a literal `{{tags}}` in the URL.
- **`\S` inside a character class** (e.g. `[^\S\r\n]`, "whitespace but no line break") now matches like the browser on the server too (a no-break space counted as non-space in PHP).
- **`maintenance.php deliveries-retry`:** `skipped_old` now counts only old records that still have an undelivered job; delivered ledgers are no longer reported as "not checked". The result is cached in `.delivery/.old-ledgers.json`, so repeated runs do not reread every old record.
- **Nginx rules:** the documented `location` blocks use `^~` prefixes, so `config/`, `tests/` and the `bbf_*.php` libraries stay denied even when the server's `location ~ \.php$` block comes first (a plain regex location lost to it).
- **Server messages in the respondent's language:** "too many requests" (429), "request too large" (413), save failures (500/503), "still being processed" and the payment-start/status 503 answers now come from `lang/*.php` instead of fixed English text.
- **Respondent session cookie** (`PHPSESSID`, CSRF only) is now HttpOnly, Secure on HTTPS and uses strict session IDs. SameSite stays at the browser default so forms embedded in an iframe keep working.
- **Editor:** creating a form with a non-text `name` or `id` answers 400 instead of a PHP TypeError 500.
- **Release workflow:** the third-party `setup-php` action now runs only in a read-only build job; attestation and publishing run in a separate job that uses only GitHub's own actions and re-checks `SHA256SUMS` before signing.
- **Tests:** the editor-preview security test no longer races the iframe load (it failed about 1 in 5 runs on slow machines and could block a release).
- **selfcheck / check.php:** an `.htaccess.dist` left by an earlier release (e.g. an older FTP upgrade) is ignored and named instead of recommending its older, weaker rules; the essential rules are always checked.
- **Docs:** README and docs recommend `--checksum` with `SHA256SUMS`, say that the code-only ZIP contains `CHANGELOG.md`, and recommend `tools/upgrade.php` from the new release for installations on 2.1.0–2.1.2 (their own upgrader is older).

## [2.1.4] — 2026-09-29

### Fixed
- **Upgrade on Windows:** `maintenance.php upgrade --apply` failed with "Cannot replace …/maintenance.php" whenever the release changed `maintenance.php`, because Windows cannot rename a file over the script that is running; the upgrade and the rollback now write such a file in place and verify it by checksum. The automatic rollback no longer reports an error for a file the failed upgrade never changed. Since 2.1.3 the upgrade runs the upgrader shipped in the new package, so this already applies to the upgrade from 2.1.3 to 2.1.4. Linux hosting was not affected.

- **Lithuanian:** the pack takes over the wording used in production on airdomes.pro ("teisingas el. pašto adresas", "negali viršyti {max}", "Patikrinkite įvestus duomenis."), so an upgrade no longer replaces a site's better strings with weaker ones; "negali viršyti {max} simbolių" no longer depends on the gender of the field name. A new test keeps every message the browser and the server both show identical in `lang/xx.js` and `lang/xx.php` (lt, sk, cs, en fully; older divergent wording in other packs is listed and frozen).

### Security
- **Server rules:** `.htaccess` and the Nginx rules deny every internal library `bbf_*.php` (before only `bbf_functions.php`) and the `config/` folder with action credentials (only `config*` files were denied). Nothing leaked before (the libraries print nothing and credential files are PHP), this is defence in depth. `check.php` probes `bbf_auth.php` and a sentinel file in `config/`; check.php and selfcheck report an `.htaccess` that lacks these rules. If you keep your own `.htaccess`, copy the two changed lines from `.htaccess.dist`; on Nginx replace the `bbf_functions.php` line with `location ~ ^/BBF_BASE/bbf_[^/]*\.php$ { deny all; }`.

## [2.1.3] — 2026-09-29

Fixes from the review of 2.1.2.

### Fixed
- **Forms:** a `redirect` with `{{field}}` URL-encodes the value, so "Jana Nová" no longer cancels the redirect and `&`/`#` cannot add parameters. `show_if` that depends on a file field is re-evaluated after the upload. `bbf.js` no longer uses `Object.hasOwn` (Safari before 15.4 broke on every parameterised error message). `\s` in `pattern` matches a non-breaking space on the server as in the browser; `{"any": []}`/`{"all": []}` evaluate the same in the browser and on the server. The submit button is enabled again when the visitor comes back with Back after a redirect. Server messages ("Validation failed." and others) are translated into the form's language. `smoketest.php` builds test values from `pattern`, not only from `placeholder`.
- **Sign-in:** while an address is blocked after 10 wrong tokens, every token, right or wrong, is checked only after a wait (one per 2 seconds per address, at most 10 seconds queued, beyond that 429 without a check), so the 200/429 answer no longer lets a guesser test tokens at full server speed. The sign-in form is protected against login CSRF. The management session uses its own `BBFADMIN` cookie limited to the installation folder instead of the domain-wide `PHPSESSID` (everyone signs in once more after the upgrade). A too-short access token no longer masks words in the audit log (`co` turned `contact` into `[redacted]ntact`). The rotated audit log is `access-audit.1.php` (guarded by its `.php` extension); an `access-audit.php.1` from 2.1.2 is renamed on the next write.
- **Upgrades:** `php maintenance.php upgrade` runs the upgrader shipped in the new package (after its checksums are verified), so fixes to the upgrade itself apply to the very upgrade that brings them; the result says `"upgrader": "package X.Y.Z"`. This takes effect from the next upgrade after 2.1.3 is installed. A `.htaccess` with lines of your own is still kept, but when the release changes its rules the upgrade writes them next to it as `.htaccess.dist` and says so; `check.php` and `selfcheck` list rule lines still missing from your `.htaccess`. `CHANGELOG.md` is now a doc file (kept out of the way like README) and ships in the upgrade ZIP, so the dry run lists the Breaking notes of the new release. `check.php` and `selfcheck` report a publicly readable `README.md` or `CHANGELOG.md`. The Nginx rule in the comments is limited to the installation folder (`location ~ ^/BBF_BASE/.*\.md$`), so it no longer blocks `.md` files of the rest of the site.
- **Server:** `mail()` adds the envelope sender (`-f`) only when the host's `sendmail_path` does not already set one (a second `-f` was rejected or overrode the host's sender). A viewer export or list with empty `from=`, `to=` or `status=` means "no filter" instead of 400. `deliveries-retry` reports how many delivery records older than 7 days it did not check (`skipped_old` with a note) and how many were still in flight. New setting `drafts_per_ip_hour` (default 30): one address can start only that many new drafts per hour (429 with `retry_after`), so a single visitor can no longer fill `drafts_max` for every form.
- **Releases:** CI fails while `tools/release-history.json` lacks the checksums of a published release (`php tools/release-history.php --tag=vX.Y.Z` records them from git). `release.yml` has no workflow-wide token rights: the CI gate job can only read Actions, only the packaging job can write.

## [2.1.2] — 2026-09-29

Fixes from the independent re-review of 2.1.1.

### Fixed
- **Sign-in:** the correct token is always accepted, even while its address is throttled after wrong attempts (a proxy or office could lock everyone out). A token shorter than 16 characters in `access_tokens` is now ignored on its own instead of disabling every token including `api_token`; `check.php`, `selfcheck` and the upgrade dry run name such tokens. An invalid `trusted_proxies` entry (e.g. `10.0.0.0/`) is ignored and reported instead of trusting everyone; `X-Forwarded-For` with a port or an `::ffff:` address is read correctly. The wrong-token counter file stays bounded.
- **Upgrades:** a failed `upgrade-rollback` can be run again (the manifest of the older version is restored last). Files of earlier releases count as unmodified, so an upgrade from 2.1.0 no longer reports README and docs as "edited by you". The code-only `-upgrade.zip` now carries `check.php` and the new rules as `.htaccess.dist`. Releases are built only from a commit whose CI passed, with pinned GitHub Actions.
- **Forms:** conditions inside repeatable rows react to changes of ordinary fields; `\d` in a `pattern` matches only ASCII digits in the browser and on the server; a `select` default that is not one of its options leaves the field empty; payment and unparseable-number errors are shown; file fields count for `min_filled`; "Other" text hides again on reset; `$&` in labels stays literal; `lang` codes such as `pt-BR` and `zh-TW` load their pack regardless of case.
- **Accessibility:** a page change is announced to screen readers, removing a repeatable row does not leave focus on another Remove button, hint text in the dark theme has AA contrast; "Other", rating and page-status texts are translatable.
- **Viewer and export:** the export holds exactly what the filtered list shows (search, dates, review status and tags; review filters need the review permission); an invalid date gives 400 instead of an empty file; `bom=0` omits the UTF-8 BOM for scripts. Rows with the same time keep a stable order across pages; long page lists show "…"; forwarding keeps an answer `0`; `?lang` and sandbox labels are escaped.
- **Server:** `submissions.php` treats an empty SQLite file as "no submissions" instead of 500; `mail()` sets the envelope sender (`-f`) so bounces reach `mail_from`; `deliveries-retry` no longer reads the whole delivery history; the editor creates a form atomically and never overwrites an existing one; `logs/access-audit.php` rotates at `audit_max_bytes` (20 MB); a missing `logs/` is named in the 503; the session lock is released before submit processing; CORS responses send `Vary: Origin`; redirects after submit accept only http(s) or relative targets; the form id is URL-encoded in every request; failed confirmation and notification emails are separate incidents.
- **Drafts:** at most `drafts_max` stored drafts (default 10 000, new ones get 429) and 256 KB per draft (413).
- **Information leak:** `README.md` and `CHANGELOG.md` revealed the installed version. `.htaccess` now denies `*.md` and `check.php` reports a readable `README.md` as an error. On Nginx add `location ~ \.md$ { deny all; }` (see README).
- Docs: `docs.html` showed version 2.0.4; the FTP upgrade steps describe what the upgrade ZIP really contains; file uploads are attributed to 2.1.0.

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
