<?php
/** G10 final read-only finding-to-test inventory and CI registration gate. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$checks = 0;
$failures = [];

function acceptance_check(bool $condition, string $label): void
{
    global $checks, $failures;
    ++$checks;
    if (!$condition) {
        $failures[] = $label;
        print "FAIL $label\n";
    }
}

function acceptance_source(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    acceptance_check(is_file($path) && !is_link($path), "$relative is a regular test artifact");
    $source = is_file($path) ? file_get_contents($path) : false;
    acceptance_check(is_string($source) && $source !== '', "$relative is non-empty and readable");
    return is_string($source) ? $source : '';
}

$inventory = [
    'R1.1 viewer HTML/attribute injection' => [['tests/review-security.test.js', 'table title retains full malicious value']],
    'R1.2 server-authoritative pricing' => [['tests/review-payment-test.php', 'trusted quote']],
    'R1.3 payment event durability/checkpoints' => [['tests/review-payment-test.php', 'same payment event replay skips completed action checkpoint']],
    'R1.4 typed scalar/multivalue validation' => [['tests/review-validation-test.php', 'scalar array bypass rejected']],
    'R2.1 locked failure-aware CSV evolution' => [['tests/review-storage-test.php', 'CSV stable historical/new union header']],
    'R2.2 JSON encoding and atomic failure propagation' => [['tests/review-storage-test.php', 'invalid UTF-8']],
    'R2.3 effective per-form storage routing' => [['tests/review-storage-routing-test.php', 'effective stores, not five-row decoys']],
    'R2.4 expanded and historical exports' => [['tests/review-export-test.php', 'stable expanded columns plus historical union']],
    'R2.5 SMTP/webhook protocol failures' => [['tests/review-delivery-test.php', 'dot-stuffs every leading dot']],
    'R2.6 non-overlapping editor saves' => [['tests/editor-save.test.js', 'cannot overlap validation, save, or refresh']],
    'R3.localhost no implicit loopback authorization' => [['tests/review-access-core-test.php', 'anonymous loopback denied']],
    'R3.sessions runtime token invalidation' => [['tests/review-access-core-test.php', 'session re-resolves']],
    'R3.sandbox submit authorization parity' => [['tests/review-sandbox-test.php', 'sandbox presence always requires authorization']],
    'R3.diagnostics fixed trusted target' => [['tests/review-diagnostics-test.php', 'Host and localhost name cannot supply a missing target']],
    'R3.tests/R5.tests disposable CLI isolation' => [['tests/review-test-isolation.php', 'ordinary HTTP invocation denied']],
    'R4.viewer stale-delete and filter navigation' => [['tests/viewer-navigation.test.js', 'delete response cannot overwrite']],
    'R4.groups parent-child condition AND' => [['tests/review-conditions-test.php', 'parent-child AND']],
    'R4.options option/rating initialization' => [['tests/review-renderer.test.js', 'rating updates dispatch input and change']],
    'R4.lookup latest autocomplete result wins' => [['tests/review-renderer.test.js', 'latest lookup query wins']],
    'R4.pages invalid-field page and reset visibility' => [['tests/review-renderer.test.js', 'first invalid input reveals']],
    'R4.a11y keyboard rows/cards and Shift+Tab' => [
        ['tests/viewer-navigation.test.js', 'keyboardSurfaces'],
        ['tests/editor-save.test.js', 'Shift+Tab is not intercepted'],
    ],
    'R4.demo5 successful-submit result profile' => [['tests/review-renderer.test.js', 'demo5 renders once, scores the submitted payload']],
    'R5.arch shared storage/field contracts' => [['tests/review-storage-routing-test.php', 'effective backend']],
    'R5.performance measured streaming pagination' => [['tests/review-storage-benchmark.php', 'realistic large datasets']],
    'R5.CI failure propagation and deploy parity' => [['tests/review-ci-test.php', 'failure propagation self-test']],
    'F1 viewer work inbox' => [['tests/review-inbox-test.php', 'saved filter validates and normalizes criteria']],
    'F2 scoped expiring revocable tokens' => [
        ['tests/review-access-storage-test.php', 'read permission alone cannot export'],
        ['tests/review-access-ui.test.js', 'permission'],
    ],
    'F3 observable retryable delivery' => [['tests/review-outbox-test.php', 'retry']],
    'F4 version history/conflict/rollback' => [['tests/review-versions-test.php', 'stale draft save returns the winning snapshot']],
    'F5 opt-in private expiring drafts' => [['tests/review-drafts-test.php', 'sensitive']],
    'F6 retention/archive/backup/restore' => [
        ['tests/review-retention-test.php', 'safe-off retention'],
        ['tests/review-backup-test.php', 'protected logical backup'],
    ],
    'F7 bounded structured repeatable groups' => [
        ['tests/review-repeatable-test.php', 'nested scalar child'],
        ['tests/review-repeatable.test.js', 'stable'],
    ],
];

$sources = [];
foreach ($inventory as $finding => $evidence) {
    acceptance_check($evidence !== [], "$finding has mapped evidence");
    foreach ($evidence as [$relative, $anchor]) {
        $sources[$relative] ??= acceptance_source($root, $relative);
        acceptance_check(str_contains($sources[$relative], $anchor), "$finding anchor exists in $relative");
    }
}
acceptance_check(count($inventory) === 32, 'complete inventory contains all 32 manifest R/F findings');

$missionInventory = [
    'G1.1 inert preview and renderer error sinks' => [
        'fixes' => [['editor.php', 'error.textContent = ex instanceof Error'], ['bbf.js', "error.textContent = this._t('loadError'"], ['sandbox.php', "error.textContent = 'Failed to load form: '"]],
        'positive' => [['tests/review-security.test.js', 'renderer and sandbox load failures keep hostile messages inert in real Chromium']],
        'failure' => [['tests/review-security.test.js', 'editor preview renders hostile errors as text inside an opaque-origin sandbox']],
    ],
    'G1.2 opaque editor preview origin boundary' => [
        'fixes' => [['editor.php', 'sandbox="allow-scripts"']],
        'positive' => [['tests/review-security.test.js', 'Preview must not combine scripts with the administration origin']],
        'failure' => [['tests/review-security.test.js', 'Hostile markup must remain inert text']],
    ],
    'G1.3 hostile selector and error payload execution prevention' => [
        'fixes' => [['editor.php', 'e.source !== window.parent'], ['bbf.js', "error.className = 'bbf-error'"]],
        'positive' => [['tests/review-security.test.js', 'rendererText']],
        'failure' => [['tests/review-security.test.js', 'assert.equal(check.xss, 0)']],
    ],
    'G1.4 editor and renderer stale response compatibility' => [
        'fixes' => [['bbf.js', 'container._bbfRenderRequest'], ['editor.php', 'state.formRevision']],
        'positive' => [['tests/editor-save.test.js', 'late publish and validation responses cannot alter a newer form session']],
        'failure' => [['tests/review-renderer.test.js', 'latest render request owns its container when an older request fails late']],
    ],
    'G2.1 strict backup inventory separate from tolerant reads' => [
        'fixes' => [['bbf_read.php', 'bool $strict = false'], ['bbf_backup.php', 'PHP_INT_MAX, 0, null, null, null, true']],
        'positive' => [['tests/review-backup-test.php', 'backup is a private integrity-checked logical bundle']],
        'failure' => [['tests/review-backup-test.php', 'file backup rejects a malformed source record before publishing a bundle']],
    ],
    'G2.2 malformed file CSV SQLite and MySQL records fail closed' => [
        'fixes' => [['bbf_read.php', "throw new RuntimeException('Invalid stored submission.')"], ['bbf_read.php', "throw new RuntimeException('Invalid stored CSV submission.')"]],
        'positive' => [['tests/review-backup-test.php', 'restore recreates the exact historical logical submission']],
        'failure' => [['tests/review-backup-test.php', 'csv backup rejects an unterminated quoted record before publication and preserves exact source bytes'], ['tests/review-access-mysql-test.php', 'strict backup rejects malformed real MySQL data/meta before publication and preserves exact source row']],
    ],
    'G2.3 complete relationship-preserving restore' => [
        'fixes' => [['bbf_backup.php', "'delivery' => bbf_backup_delivery_capture"], ['bbf_backup.php', "'review' => bbf_backup_review_capture"]],
        'positive' => [['tests/review-backup-test.php', 'restore preserves exact delivery and payment-event ledger relationships']],
        'failure' => [['tests/review-backup-test.php', 'restore refuses an already populated target']],
    ],
    'G2.4 backup fault concurrency mutation and cleanup safety' => [
        'fixes' => [['bbf_backup.php', 'bbf_backup_bundle_read'], ['bbf_backup.php', 'bbf_backup_delivery_capture']],
        'positive' => [['tests/review-backup-test.php', 'concurrent confirmed restores serialize to one winner']],
        'failure' => [['tests/review-backup-test.php', 'tampered bundle fails integrity validation before target access'], ['tests/review-backup-test.php', 'backup refuses an active delivery lease']],
    ],
    'G3.1 currently visible option validation with row context' => [
        'fixes' => [['bbf_functions.php', 'function validate'], ['bbf_functions.php', 'evalCondition($opt[\'show_if\'], $input)']],
        'positive' => [['tests/review-validation-test.php', 'repeatable option condition uses its row context']],
        'failure' => [['tests/review-validation-test.php', 'conditionally hidden option is rejected']],
    ],
    'G3.2 normalized visible cross-field rules' => [
        'fixes' => [['bbf_functions.php', 'function validateCrossFields'], ['submit.php', '$data = $normalizedData']],
        'positive' => [['tests/review-validation-test.php', 'sandbox applies cross-field rules to the same normalized data']],
        'failure' => [['tests/review-validation-test.php', 'cross-field rules use only normalized visible data']],
    ],
    'G3.3 real calendar date validation' => [
        'fixes' => [['bbf_functions.php', 'checkdate((int)$dateParts[2]']],
        'positive' => [['tests/review-validation-test.php', 'date accepts real calendar value']],
        'failure' => [['tests/review-validation-test.php', 'date rejects invalid calendar value']],
    ],
    'G3.4 structured fallback email escaping' => [
        'fixes' => [['bbf_functions.php', '$text = is_array($v) ? bbf_storage_json($v)']],
        'positive' => [['tests/review-validation-test.php', 'missing email template renders structured values as escaped text']],
        'failure' => [['tests/review-security.test.js', 'forward email escapes multivalue answers']],
    ],
    'G3.5 multivalue repeatable and historical validation compatibility' => [
        'fixes' => [['bbf_functions.php', 'Support both string options'], ['submit.php', 'bbfRepeatableRowInput']],
        'positive' => [['tests/review-repeatable-test.php', 'valid rows pass row-local field and condition validation']],
        'failure' => [['tests/review-repeatable-test.php', "'nested scalar child' =>"]],
    ],
    'G4.1 retryable DNS with SSRF revalidation' => [
        'fixes' => [['bbf_delivery.php', 'function bbf_delivery_webhook']],
        'positive' => [['tests/review-delivery-test.php', 'temporary DNS resolution failure is retryable']],
        'failure' => [['tests/review-delivery-test.php', 'each DNS retry revalidates SSRF safety and rejects a newly private answer']],
    ],
    'G4.2 Stripe multiple v1 signature rotation' => [
        'fixes' => [['payment.php', 'foreach ($signatures as $signature)']],
        'positive' => [['tests/review-payment-test.php', 'Stripe rotation accepts valid v1 at position']],
        'failure' => [['tests/review-payment-test.php', 'Stripe rejects multiple invalid v1 signatures']],
    ],
    'G4.3 immutable paid outbox survives form and backend drift' => [
        'fixes' => [['submit.php', '$paymentJobs = bbf_delivery_prepare_jobs'], ['submit.php', '$paymentOutboxPath = bbf_outbox_path']],
        'positive' => [['tests/review-payment-test.php', 'real payment submit persists its complete immutable delivery plan and original backend before redirect']],
        'failure' => [['tests/review-payment-test.php', 'real submit plan settles after the current form becomes malformed without manual ledger seeding']],
    ],
    'G4.4 explicit currency exponent and minor-unit contracts' => [
        'fixes' => [['bbf_functions.php', 'function bbfPaymentCurrencyMinorUnits'], ['bbf_functions.php', 'function bbfPaymentValidateChargeAmount']],
        'positive' => [['tests/review-payment-test.php', 'fixed JPY quote uses the verified zero-decimal exponent']],
        'failure' => [['tests/review-payment-test.php', 'UGX and ISK fixed charges reject fractional whole-unit amounts']],
    ],
    'G4.5 sandbox amount_minor and minor_units contract' => [
        'fixes' => [['bbf.js', '_renderSandboxPreview'], ['submit.php', '\'amount_minor\' => $quote[\'amount_minor\']']],
        'positive' => [['tests/review-renderer.test.js', 'sandbox payment preview consumes amount_minor and currency exponent contract']],
        'failure' => [['tests/review-payment-test.php', "'legacy fixed decimal amount' =>"]],
    ],
    'G5.1 current draft allowlist and sensitivity policy' => [
        'fixes' => [['bbf_drafts.php', 'bbf_draft_filter($form, $flatFields, $record[\'data\'])']],
        'positive' => [['tests/review-drafts-test.php', 'save persists only allowlisted nonsensitive values']],
        'failure' => [['tests/review-drafts-test.php', 'resume reapplies the current sensitive and allowlist policy to historical draft data']],
    ],
    'G5.2 atomic draft restore and rating accessibility' => [
        'fixes' => [['bbf.js', '_draftApply: function']],
        'positive' => [['tests/review-renderer.test.js', 'draft restore applies all values before conditions and synchronizes rating accessibility']],
        'failure' => [['tests/review-renderer.test.js', 'Undefined literal']],
    ],
    'G5.3 row-local repeatable lookup and autocomplete' => [
        'fixes' => [['bbf.js', 'row._bbfPrefix + match[3]']],
        'positive' => [['tests/review-renderer.test.js', 'repeatable lookup and autocomplete mappings stay inside their originating row']],
        'failure' => [['tests/review-repeatable.test.js', 'repeatable lookup and autocomplete mappings stay inside their originating row']],
    ],
    'G5.4 sandbox structured repeatable payload' => [
        'fixes' => [['sandbox.php', 'BBF._collectRepeatableGroups'], ['bbf.js', '_collectRepeatableGroups: function']],
        'positive' => [['tests/review-renderer.test.js', 'sandbox submit serializes repeatable rows with the production collector']],
        'failure' => [['tests/review-repeatable-test.php', 'isolated submit HTTP stores rows and rejects malformed payloads before persistence']],
    ],
    'G5.5 safe readable nested viewer print and forward values' => [
        'fixes' => [['viewer.php', 'function viewerValueText'], ['viewer.php', 'white-space:pre-wrap']],
        'positive' => [['tests/review-security.test.js', 'viewer HTML sinks are inert in real Chromium']],
        'failure' => [['tests/review-security.test.js', 'forward email escapes multivalue answers']],
    ],
    'G5.6 latest request wins across renderer sandbox and drafts' => [
        'fixes' => [['bbf.js', 'container._bbfRenderRequest'], ['sandbox.php', 'let loadRequest = 0']],
        'positive' => [['tests/review-renderer.test.js', 'sandbox latest form load wins when the older definition resolves last']],
        'failure' => [['tests/review-drafts.test.js', 'newer resume response wins and stale callback cannot overwrite fields']],
    ],
    'G6.1 Nginx base path private files and sentinel verification' => [
        'fixes' => [['.htaccess', '^/BBF_BASE/config'], ['README.md', 'does not replace these sentinel-file probes']],
        'positive' => [['tests/review-acceptance-test.php', 'Nginx guidance protects config and backup variants']],
        'failure' => [['tests/review-acceptance-test.php', '(?:bak|swp|save|orig|old)']],
    ],
    'G6.2 server-owned payment example and pre-deploy upgrade check' => [
        'fixes' => [['README.md', 'Run the new release\'s `php smoketest.php`'], ['README.md', 'Never restore the legacy client-authoritative']],
        'positive' => [['tests/review-acceptance-test.php', 'payment documentation uses server-owned pricing']],
        'failure' => [['tests/review-payment-test.php', "'legacy fixed decimal amount' =>"]],
    ],
    'G6.3 complete runtime and historical Draft 2020-12 schema' => [
        'fixes' => [['forms/form.schema.json', 'historical value-as-label shorthand'], ['forms/form.schema.json', 'Mode-specific requirements mirror runtime validation']],
        'positive' => [['tests/review-acceptance-test.php', 'historical string options']],
        'failure' => [['tests/review-acceptance-test.php', 'negative payment contracts passed']],
    ],
    'G6.4 scalar PSČ API and no-mbstring fallback' => [
        'fixes' => [['api-psc.php', 'Invalid query parameter'], ['api-psc.php', "if (!function_exists('mb_strtolower'))"]],
        'positive' => [['tests/review-acceptance-test.php', 'PSČ city lookup works with Unicode fallback']],
        'failure' => [['tests/review-acceptance-test.php', 'with HTTP 400 and no runtime warning']],
    ],
    'G6.5 real PHP 8.1 CI compatibility gate' => [
        'fixes' => [['.github/workflows/lint.yml', 'PHP 8.1 minimum-version gate'], ['.github/workflows/lint.yml', "php-version: '8.1'"]],
        'positive' => [['tests/review-acceptance-test.php', 'workflow runs the complete regression gate on declared PHP 8.1 minimum']],
        'failure' => [['tests/review-acceptance-test.php', 'pinned Draft 2020-12 schema test dependency']],
    ],
];
$missionSources = [];
$missionTestFiles = [];
foreach ($missionInventory as $finding => $evidenceByRole) {
    acceptance_check(array_keys($evidenceByRole) === ['fixes', 'positive', 'failure'],
        "$finding maps fixes, positive behavior and failure behavior");
    foreach ($evidenceByRole as $role => $evidence) {
        acceptance_check($evidence !== [], "$finding has $role evidence");
        foreach ($evidence as [$relative, $anchor]) {
            $missionSources[$relative] ??= acceptance_source($root, $relative);
            acceptance_check(str_contains($missionSources[$relative], $anchor), "$finding $role anchor exists in $relative");
            if (str_starts_with($relative, 'tests/')) $missionTestFiles[$relative] = true;
        }
    }
}
acceptance_check(count($missionInventory) === 29, 'fresh G1-G6 inventory maps all 29 manifest substeps');

$review6129Inventory = [
    '6129-F01 strict CSV mutation and retention preflight' => [
        'fixes' => [['bbf_storage.php', 'function bbf_storage_csv_record_syntax_valid'], ['submit.php', 'bbf_storage_csv_record_syntax_valid($source'], ['bbf_retention.php', 'bbf_storage_csv_record_syntax_valid($in']],
        'positive' => [['tests/review-storage-test.php', 'historical multiline/quoted values retained and padded']],
        'failure' => [['tests/review-storage-test.php', '6129-F01 unterminated historical CSV'], ['tests/review-storage-test.php', '6129-F01 balanced quotes with trailing non-delimiter text'], ['tests/review-backup-test.php', '6129-F01 strict backup rejects a balanced but illegally quoted CSV header'], ['tests/review-retention-test.php', '6129-F01 fixture proves permissive CSV parsing swallows'], ['tests/review-retention-test.php', '6129-F01 malformed CSV blocks retention']],
    ],
    '6129-F02 finalized paid delivery payload' => [
        'fixes' => [['bbf_functions.php', 'function bbf_delivery_finalize_submission'], ['payment.php', 'bbf_delivery_finalize_submission($outboxPath, $submission)']],
        'positive' => [['tests/review-payment-test.php', '6129-F02 paid callback delivers the durable paid status and payment identity'], ['tests/review-payment-test.php', '6129-F02 paid finalization atomically refreshes email webhook and action payloads and hashes'], ['tests/review-payment-test.php', '6129-F02 pre-upgrade pending email replaces the persisted Checkout ID before delivery']],
        'failure' => [['tests/review-payment-test.php', '6129-F02 paid payload persistence failure preserves the exact pending plan before any effect'], ['tests/review-payment-test.php', '6129-F02 tampered pending payload cannot be legitimized during paid finalization']],
    ],
    '6129-F03 normalized conditional validation and collection' => [
        'fixes' => [['bbf_functions.php', 'function bbfNormalizeInputValue'], ['submit.php', "bbfNormalizeInputValue(\$input[\$name] ?? '')"], ['bbf_functions.php', 'bbfRepeatableRowInput($childFields, $input, $row)']],
        'positive' => [['tests/review-validation-test.php', '6129-F03 collection and conditions share normalized scalar input']],
        'failure' => [['tests/review-conditions-test.php', '6129-F03 normalized scalar condition enforces a required field'], ['tests/review-conditions-test.php', '6129-F03 repeatable conditions use normalized row-local values']],
    ],
    '6129-F04 coordinated viewer review deletion' => [
        'fixes' => [['viewer.php', 'bbf_review_records($config, $formId, array_values($ids))'], ['viewer.php', 'bbf_review_delete_records_if($config, $formId']],
        'positive' => [['tests/review-inbox-test.php', '6129-F04 delete retry atomically removes the retained primary and private review metadata']],
        'failure' => [['tests/review-read-consistency-test.php', '6129-F04 viewer review-storage failure preserves the primary response'], ['tests/review-inbox-test.php', '6129-F04 failed metadata tombstone preserves the exact primary response']],
    ],
    '6129-F05 CSV backup restore chronological latest order' => [
        'fixes' => [['bbf_backup.php', "'record_order' => \$recordOrder"], ['bbf_backup.php', 'array_reverse(bbf_backup_record_order($payload))']],
        'positive' => [['tests/review-backup-test.php', '6129-F05 CSV restore preserves chronological latest N semantics'], ['tests/review-backup-test.php', '6129-F05 legacy bundle without record_order derives chronological latest N semantics']],
        'failure' => [['tests/review-backup-test.php', '6129-F05 duplicate or incomplete backup record order fails closed']],
    ],
    '6129-F06 numeric-string retention IDs' => [
        'fixes' => [['bbf_retention.php', '$capturedIds = array_map'], ['bbf_review.php', '$id = is_int($id) ? (string)$id : $id']],
        'positive' => [['tests/review-retention-test.php', 'dry-run selects only strictly expired exact-form records'], ['tests/review-retention-test.php', '6129-F06 numeric-string archive preserves the exact response']],
        'failure' => [['tests/review-retention-test.php', '6129-F06 numeric-string submission ID survives capture maps']],
    ],
    '6129-F07 draft Other companion privacy and restoration' => [
        'fixes' => [['bbf_functions.php', 'Generated Other companion name collides with declared field'], ['bbf_drafts.php', 'isset($declared[$otherName])'], ['bbf.js', "body[field.name + '_other']"]],
        'positive' => [['tests/review-drafts-test.php', '6129-F07 save persists only allowlisted nonsensitive values'], ['tests/review-drafts-test.php', '6129-F07 valid bearer restores the exact Other companion text'], ['tests/review-drafts-test.php', '6129-F07 radio and select Other companions survive'], ['tests/review-drafts.test.js', '6129-F07 draft restore follows the main Other selection'], ['tests/review-drafts.test.js', '6129-F07 scalar radio and select Other companions collect']],
        'failure' => [['tests/review-drafts-test.php', '6129-F07 declared sensitive field cannot collide'], ['tests/review-drafts-test.php', '6129-F07 draft filter rejects a colliding sensitive companion'], ['tests/review-drafts-test.php', '6129-F07 repeatable child cannot collide'], ['tests/review-drafts.test.js', '6129-F07 client restore cannot overwrite a colliding sensitive field'], ['tests/review-drafts-test.php', '6129-F07 unselected Other companion text is discarded'], ['tests/review-drafts-test.php', '6129-F07 tightened scalar-field privacy removes radio and select'], ['tests/review-drafts-test.php', '6129-F07 tightened main-field privacy removes both']],
    ],
    '6129-F08 typed lookup and autocomplete option mapping' => [
        'fixes' => [['bbf.js', '_applyMappedValue: function'], ['bbf.js', 'self._applyMappedValue(formEl, formField, val)']],
        'positive' => [['tests/review-renderer.test.js', '6129-F08 lookup selects the matching radio'], ['tests/review-renderer.test.js', '6129-F08 lookup checks every mapped checkbox']],
        'failure' => [['tests/review-renderer.test.js', '6129-F08 lookup leaves the sibling repeatable row untouched'], ['tests/review-renderer.test.js', '6129-F08 autocomplete replaces the radio selection without mutating option values'], ['tests/review-renderer.test.js', '6129-F08 autocomplete clears stale checkbox selections inside its row'], ['tests/review-renderer.test.js', '6129-F08 autocomplete leaves the sibling repeatable row untouched']],
    ],
    '6129-F09 frontend cross-field zero minimum and hidden values' => [
        'fixes' => [['bbf.js', 'rule.min ?? 1'], ['bbf.js', '_getFieldValue(formEl, name, true)']],
        'positive' => [['tests/review-renderer.test.js', 'explicit zero minimum accepts empty visible values'], ['tests/review-renderer.test.js', 'nonempty checkbox arrays count as one filled field']],
        'failure' => [['tests/review-renderer.test.js', 'positive minimum rejects empty visible values despite hidden stale data'], ['tests/review-renderer.test.js', 'whitespace is not filled'], ['tests/review-renderer.test.js', 'checkbox arrays are not numeric scalars']],
    ],
    '6129-F10 shared dynamic preview option preparation' => [
        'fixes' => [['bbf.js', '_prepareFormDefinition: async function'], ['editor.php', 'await BBF._prepareFormDefinition(def'], ['sandbox.php', 'await BBF._prepareFormDefinition(formDef']],
        'positive' => [['tests/review-renderer.test.js', '6129-F10 current preview receives dynamic options'], ['tests/review-renderer.test.js', '6129-F10 sandbox preview awaits options_from before rendering']],
        'failure' => [['tests/review-renderer.test.js', '6129-F10 stale preview cannot apply late dynamic options']],
    ],
    '6129-F11 unique embedded form instance namespaces' => [
        'fixes' => [['bbf.js', "'data-bbf-instance'"], ['bbf.js', "const fieldId = `\${idPrefix || 'bbf'}-\${field.name}`"]],
        'positive' => [['tests/review-renderer.test.js', '6129-F11 simultaneous form instances namespace every label and ARIA id']],
        'failure' => [['tests/review-renderer.test.js', '6129-F11 two embeds contain no duplicate HTML id'], ['tests/review-renderer.test.js', '6129-F11 ${attribute} resolves']],
    ],
    '6129-F12 hideOnSuccess reset lifecycle' => [
        'fixes' => [['bbf.js', 'el._bbfHideOnSuccess'], ['bbf.js', 'if (el._bbfHideOnSuccess) return']],
        'positive' => [['tests/review-renderer.test.js', '6129-F12 delayed reset cannot reveal hideOnSuccess fields']],
        'failure' => [['tests/review-renderer.test.js', '6129-F12 reset callback preserves hidden success lifecycle']],
    ],
    '6129-F13 repeatable viewer previews and columns' => [
        'fixes' => [['viewer.php', 'function isRepeatableGroup'], ['bbf_versions.php', "is_bool(\$field['repeatable']"], ['bbf_auth.php', "is_bool(\$f['repeatable']"]],
        'positive' => [['tests/viewer-navigation.test.js', '6129-F13 repeatable answers remain visible and labelled across viewer previews including legacy snapshots'], ['tests/review-repeatable-test.php', '6129-F13 submission and scoped presentation preserve repeatable identity']],
        'failure' => [['tests/viewer-navigation.test.js', '6129-F13 static groups retain flattened child previews']],
    ],
    '6129-F14 finite numbers and bounded integer ratings' => [
        'fixes' => [['bbf_functions.php', '!is_finite($number)'], ['bbf_functions.php', '$field[\'max\'] ?? 5']],
        'positive' => [['tests/review-validation-test.php', '6129-F14 rating accepts integer in implicit one-to-five range'], ['tests/review-validation-test.php', '6129-F14 rating honors an explicit renderer maximum']],
        'failure' => [['tests/review-validation-test.php', '6129-F14 number rejects a non-finite numeric literal'], ['tests/review-validation-test.php', '6129-F14 rating rejects invalid value']],
    ],
    '6129-F15 typed JSON Schema field defaults' => [
        'fixes' => [['forms/form.schema.json', 'Checkbox fields use an array; other fields use a string.'], ['forms/form.schema.json', '"const": "checkbox"']],
        'positive' => [['tests/review-acceptance-test.php', 'checkbox array default accepted']],
        'failure' => [['tests/review-acceptance-test.php', 'invalid field default accepted']],
    ],
];
foreach ($review6129Inventory as $finding => $evidenceByRole) {
    acceptance_check(array_keys($evidenceByRole) === ['fixes', 'positive', 'failure'],
        "$finding maps fixes, positive behavior and failure behavior");
    foreach ($evidenceByRole as $role => $evidence) {
        acceptance_check($evidence !== [], "$finding has $role evidence");
        foreach ($evidence as [$relative, $anchor]) {
            $missionSources[$relative] ??= acceptance_source($root, $relative);
            acceptance_check(str_contains($missionSources[$relative], $anchor), "$finding $role anchor exists in $relative");
            if (str_starts_with($relative, 'tests/')) $missionTestFiles[$relative] = true;
        }
    }
}
acceptance_check(count($review6129Inventory) === 15, 'review 6129 inventory maps all findings F01 through F15');

$ciSource = acceptance_source($root, 'tests/review-ci-test.php');
foreach (array_keys($sources) as $relative) {
    if ($relative === 'tests/review-acceptance-test.php') {
        continue;
    }
    $basename = basename($relative);
    acceptance_check(str_contains($ciSource, $basename), "$basename is registered in the failure-propagating CI gate");
}
foreach (array_keys($missionTestFiles) as $relative) {
    if ($relative === 'tests/review-acceptance-test.php') continue;
    $basename = basename($relative);
    acceptance_check(str_contains($ciSource, $basename), "$basename is registered for the fresh mission inventory");
}
foreach (['tests/review-repeatable.test.js', 'tests/review-acceptance-test.php', 'tests/review-deploy-parity-test.php'] as $required) {
    acceptance_check(str_contains($ciSource, basename($required)), basename($required) . ' is an explicit final CI gate');
}

$workflow = acceptance_source($root, '.github/workflows/lint.yml');
acceptance_check(str_contains($workflow, 'php tests/review-ci-test.php'), 'workflow executes the local failure-propagating orchestrator');
acceptance_check(str_contains($workflow, 'php tests/review-access-mysql-test.php'), 'workflow executes the owned MariaDB matrix');
acceptance_check(str_contains($workflow, "php-version: '8.1'")
    && substr_count($workflow, 'php tests/review-ci-test.php') >= 2,
    'workflow runs the complete regression gate on declared PHP 8.1 minimum');
acceptance_check(substr_count($workflow, 'actions/setup-python@v5') >= 2
    && substr_count($workflow, 'python -m pip install jsonschema==4.23.0') >= 2,
    'PHP 8.2 and 8.1 gates install the pinned Draft 2020-12 schema test dependency');

$schema = json_decode(acceptance_source($root, 'forms/form.schema.json'), true, 512, JSON_THROW_ON_ERROR);
$top = $schema['properties'] ?? []; $field = $schema['$defs']['field']['properties'] ?? [];
$payment = $schema['$defs']['on_submit']['properties']['payment']['properties'] ?? [];
$optionVariants = $field['options']['items']['oneOf'] ?? [];
acceptance_check(isset($top['animate_conditions'], $top['validations'], $top['preview_fields']),
    'schema publishes runtime top-level condition, validation and viewer properties');
acceptance_check(isset($field['autocomplete']) && count($field['min']['oneOf'] ?? []) === 2
    && count($field['max']['oneOf'] ?? []) === 2 && ($optionVariants[0]['type'] ?? null) === 'string'
    && isset($optionVariants[1]['properties']['checked']),
    'schema publishes autocomplete, numeric/date bounds and historical string plus structured checked options');
acceptance_check(array_diff(['provider', 'mode', 'pricing_version', 'currency', 'amount_minor', 'amount_field',
    'min_amount_minor', 'max_amount_minor', 'catalog'], array_keys($payment)) === [],
    'schema publishes the server-owned fixed, catalog and donation payment contract');

$schemaProbe = <<<'PY'
import copy
import json
import pathlib
import sys
from jsonschema import Draft202012Validator

root = pathlib.Path(sys.argv[1])
schema = json.loads((root / 'form.schema.json').read_text(encoding='utf-8-sig'))
Draft202012Validator.check_schema(schema)
validator = Draft202012Validator(schema)
shipped = [
    'demo-advanced.json', 'demo-allergy.json', 'demo-csv.json', 'demo-file.json',
    'demo-modalities.json', 'demo-order.json', 'demo-psc.json', 'demo-quiz.json',
    'demo-webhook.json', 'kontakt.json', 'newsletter.json',
]
failures = []
for name in shipped:
    errors = list(validator.iter_errors(json.loads((root / name).read_text(encoding='utf-8-sig'))))
    if errors:
        failures.append(f'{name}: {errors[0].message}')
string_options_form = json.loads((root / 'demo-order.json').read_text(encoding='utf-8-sig'))
product_field = next(field for field in string_options_form['fields'] if field.get('name') == 'product')
product_field['options'] = ['business_cards', 'flyers']
if not validator.is_valid(string_options_form):
    failures.append('runtime-supported historical string options rejected')
checkbox_default_form = {
    'schema_version': 1,
    'id': 'checkbox-default',
    'fields': [{'name': 'choices', 'type': 'checkbox', 'options': ['a', 'b'], 'value': ['a', 'b']}],
}
if not validator.is_valid(checkbox_default_form):
    failures.append('checkbox array default accepted by runtime but rejected by schema')
invalid_field_defaults = {
    'checkbox scalar': {**checkbox_default_form, 'fields': [{**checkbox_default_form['fields'][0], 'value': 'a'}]},
    'checkbox duplicates': {**checkbox_default_form, 'fields': [{**checkbox_default_form['fields'][0], 'value': ['a', 'a']}]},
    'radio array': {**checkbox_default_form, 'fields': [{'name': 'choice', 'type': 'radio', 'options': ['a'], 'value': ['a']}]},
    'implicit text array': {**checkbox_default_form, 'fields': [{'name': 'note', 'value': ['a']}]},
}
for label, candidate in invalid_field_defaults.items():
    if validator.is_valid(candidate):
        failures.append(f'invalid field default accepted: {label}')
payment_schema = schema['$defs']['on_submit']['properties']['payment']
payment_validator = Draft202012Validator(payment_schema)
fixed = {'provider': 'stripe', 'mode': 'fixed', 'pricing_version': 'fixed-v1', 'currency': 'eur', 'amount_minor': 4990}
donation = {'provider': 'stripe', 'mode': 'donation', 'pricing_version': 'donation-v1', 'currency': 'eur',
            'minor_units': 2, 'amount_field': 'amount', 'min_amount_minor': 100, 'max_amount_minor': 50000}
catalog = json.loads((root / 'demo-order.json').read_text(encoding='utf-8-sig'))['on_submit']['payment']
for label, candidate in [('fixed', fixed), ('fixed default provider', {key: value for key, value in fixed.items() if key != 'provider'}), ('donation', donation), ('catalog', catalog)]:
    if not payment_validator.is_valid(candidate):
        failures.append(f'valid {label} rejected')
numeric_product_catalog = copy.deepcopy(catalog)
first_product = next(iter(numeric_product_catalog['catalog']['products'].values()))
numeric_product_catalog['catalog']['products'] = {'0': first_product}
invalid = {
    'fixed missing amount_minor': {key: value for key, value in fixed.items() if key != 'amount_minor'},
    'empty pricing_version': {**fixed, 'pricing_version': ''},
    'unsupported currency': {**fixed, 'currency': 'xxx'},
    'fractional UGX fixed amount': {**fixed, 'currency': 'ugx', 'amount_minor': 501},
    'catalog missing contract': {'provider': 'stripe', 'mode': 'catalog', 'pricing_version': 'catalog-v1', 'currency': 'eur', 'catalog': {}},
    'donation missing bounds': {key: value for key, value in donation.items() if key not in ('min_amount_minor', 'max_amount_minor')},
    'fixed with donation field': {**fixed, 'amount_field': 'amount'},
    'donation wrong exponent': {**donation, 'currency': 'jpy'},
    'numeric catalog product key': numeric_product_catalog,
}
for label, candidate in invalid.items():
    if payment_validator.is_valid(candidate):
        failures.append(f'invalid payment accepted: {label}')
if failures:
    print('\n'.join(failures), file=sys.stderr)
    sys.exit(1)
print(f'Draft 2020-12: {len(shipped)} shipped forms, historical string options, and {len(invalid)} negative payment contracts passed')
PY;
$schemaProcess = proc_open(
    ['python', '-c', $schemaProbe, $root . '/forms'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $schemaPipes,
    $root
);
$schemaCode = -1; $schemaOutput = ''; $schemaError = 'cannot start Python Draft 2020-12 validator';
if (is_resource($schemaProcess)) {
    fclose($schemaPipes[0]);
    $schemaOutput = stream_get_contents($schemaPipes[1]);
    $schemaError = stream_get_contents($schemaPipes[2]);
    fclose($schemaPipes[1]); fclose($schemaPipes[2]);
    $schemaCode = proc_close($schemaProcess);
}
acceptance_check($schemaCode === 0 && $schemaError === ''
    && str_contains($schemaOutput, '11 shipped forms, historical string options, and 9 negative payment contracts passed'),
    'official Draft 2020-12 validator accepts shipped and historical forms and rejects invalid payment modes');

$readme = acceptance_source($root, 'README.md'); $htaccess = acceptance_source($root, '.htaccess');
acceptance_check(str_contains($htaccess, 'BBF_BASE/') && str_contains($htaccess, 'lang/.*\\.php$')
    && str_contains($htaccess, '^/BBF_BASE/config') && str_contains($htaccess, '(?:bak|swp|save|orig|old)')
    && str_contains($htaccess, 'Keep lang/*.js public') && str_contains($readme, 'every request must return 403 or 404')
    && str_contains($readme, 'does not replace these sentinel-file probes'),
    'Nginx guidance protects config and backup variants while preserving language JavaScript and requiring sentinel probes');
acceptance_check(str_contains($readme, '"pricing_version": "order-v1"')
    && str_contains($readme, "Run the new release's `php smoketest.php`")
    && str_contains($readme, 'non-web-accessible staging directory')
    && str_contains($readme, 'Never restore the legacy client-authoritative'),
    'payment documentation uses server-owned pricing and the new runtime for a non-invasive pre-deploy compatibility check');

$runPsc = static function (string $query) use ($root): array {
    $command = [PHP_BINARY, '-d', 'disable_functions=mb_strtolower,mb_strlen', '-r',
        'parse_str($argv[1], $_GET); register_shutdown_function(function () { $status = http_response_code(); if ($status !== false) fwrite(STDERR, "HTTP_STATUS=$status\\n"); }); include $argv[2];',
        $query, $root . '/api-psc.php'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) return [-1, '', 'cannot start'];
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process), $stdout, $stderr];
};
[$pscCode, $pscBody, $pscError] = $runPsc('city=%C5%BDilina');
$pscJson = json_decode($pscBody, true);
acceptance_check($pscCode === 0 && $pscError === '' && is_array($pscJson) && $pscJson !== [],
    'PSČ city lookup works with Unicode fallback when mbstring is unavailable');
foreach (['psc%5B%5D=81101', 'city%5Bname%5D=Bratislava', 'prefix%5B%5D=1', 'limit%5Bvalue%5D=5'] as $invalidQuery) {
    [$pscCode, $pscBody, $pscError] = $runPsc($invalidQuery);
    acceptance_check($pscCode === 0 && $pscError === "HTTP_STATUS=400\n"
        && json_decode($pscBody, true) === ['error' => 'Invalid query parameter'],
        "PSČ API rejects non-scalar $invalidQuery with HTTP 400 and no runtime warning");
}

$packageSource = acceptance_source($root, 'tools/package-deploy.php');
acceptance_check(str_contains($packageSource, 'deliberately a closed list'), 'zero-build package uses a closed allowlist instead of copying tests');
acceptance_check(str_contains($ciSource, 'review-deploy-parity-test.php'), 'CI verifies exact zero-build deployment parity');

if ($failures !== []) {
    fwrite(STDERR, 'Acceptance inventory failed: ' . count($failures) . " of $checks checks failed.\n");
    exit(1);
}

print 'G10 finding-to-test inventory: 32/32 findings mapped; ' . $checks . " checks passed.\n";
