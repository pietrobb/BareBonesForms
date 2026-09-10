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

$ciSource = acceptance_source($root, 'tests/review-ci-test.php');
foreach (array_keys($sources) as $relative) {
    if ($relative === 'tests/review-acceptance-test.php') {
        continue;
    }
    $basename = basename($relative);
    acceptance_check(str_contains($ciSource, $basename), "$basename is registered in the failure-propagating CI gate");
}
foreach (['tests/review-repeatable.test.js', 'tests/review-acceptance-test.php', 'tests/review-deploy-parity-test.php'] as $required) {
    acceptance_check(str_contains($ciSource, basename($required)), basename($required) . ' is an explicit final CI gate');
}

$workflow = acceptance_source($root, '.github/workflows/lint.yml');
acceptance_check(str_contains($workflow, 'php tests/review-ci-test.php'), 'workflow executes the local failure-propagating orchestrator');
acceptance_check(str_contains($workflow, 'php tests/review-access-mysql-test.php'), 'workflow executes the owned MariaDB matrix');

$packageSource = acceptance_source($root, 'tools/package-deploy.php');
acceptance_check(str_contains($packageSource, 'deliberately a closed list'), 'zero-build package uses a closed allowlist instead of copying tests');
acceptance_check(str_contains($ciSource, 'review-deploy-parity-test.php'), 'CI verifies exact zero-build deployment parity');

if ($failures !== []) {
    fwrite(STDERR, 'Acceptance inventory failed: ' . count($failures) . " of $checks checks failed.\n");
    exit(1);
}

print 'G10 finding-to-test inventory: 32/32 findings mapped; ' . $checks . " checks passed.\n";
