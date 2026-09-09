<?php
/**
 * BareBonesForms — Smoke Test Pipeline
 *
 * Tests all form definitions via sandbox mode:
 *   1. JSON structure validation (no server needed)
 *   2. Empty submit → expects validation errors for required fields
 *   3. Valid data submit → expects OK
 *   4. Template existence checks
 *
 * Usage:
 *   php tests/smoke-test.php          Run all tests
 *   php tests/smoke-test.php kontakt  Run tests for one form only
 *
 * No emails sent, no data stored — everything goes through sandbox.
 */

require_once __DIR__ . '/test-isolation-helper.php'; // CLI-only, before any side effects.
$projectDir  = bbf_test_installation(dirname(__DIR__), true); bbf_test_copy(dirname(__DIR__) . '/sandbox.php', $projectDir . '/sandbox.php');
$formsDir    = $projectDir . '/forms';
$templatesDir = $projectDir . '/templates';
$configFile  = $projectDir . '/config.php';
$host        = '127.0.0.1';
$port        = bbf_test_port(); // OS-selected loopback port
$baseUrl     = "http://$host:$port";
$filterForm  = $argv[1] ?? null;

// ─── Output helpers ─────────────────────────────────────────────
$passed = 0; $failed = 0; $warnings = 0;

function out(string $msg): void { echo $msg . "\n"; }
function pass(string $msg): void { global $passed; $passed++; echo "  \033[32m✓\033[0m $msg\n"; }
function fail(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  \033[31m✗\033[0m $msg\n"; if ($detail) echo "    \033[90m$detail\033[0m\n"; }
function warn(string $msg): void { global $warnings; $warnings++; echo "  \033[33m!\033[0m $msg\n"; }
function section(string $msg): void { echo "\n\033[1m── $msg\033[0m\n"; }

// ─── Setup config for testing ───────────────────────────────────
$configBackup = null;
$createdConfig = false;

function cleanup(): void {
    global $configFile, $configBackup, $createdConfig, $serverProc;
    if ($createdConfig && !$configBackup) {
        @unlink($configFile);
    } elseif ($configBackup !== null) {
        file_put_contents($configFile, $configBackup);
    }
    bbf_test_stop_server($serverProc ?? null);
    $serverProc = null;
}
register_shutdown_function('cleanup');

if (file_exists($configFile)) {
    $configBackup = file_get_contents($configFile);
}

// Write private test config (sandbox and CSRF enabled; fixture-only admin credential)
$testConfig = <<<'PHP'
<?php
defined('BBF_LOADED') || exit;
return [
    'storage'        => 'file',
    'sandbox'        => true,
    'csrf'           => true,
    'rate_limit'     => 9999,
    'store_ip'       => false,
    'store_user_agent' => false,
    'mail'           => [
        'method'     => 'mail',
        'from_email' => 'test@example.com',
        'from_name'  => 'BBF Test',
    ],
    'webhook_secret' => 'test-secret',
    'api_token'      => 'test-token',
    'forms_dir'      => __DIR__ . '/forms',
    'submissions_dir' => __DIR__ . '/submissions',
    'templates_dir'  => __DIR__ . '/templates',
    'logs_dir'       => __DIR__ . '/logs',
    'lang'           => 'en',
];
PHP;

file_put_contents($configFile, $testConfig);
$createdConfig = !$configBackup;

// Clean rate limit logs from previous runs
$logsDir = $projectDir . '/logs';
if (is_dir($logsDir)) {
    foreach (glob($logsDir . '/ratelimit_*.json') as $rlFile) @unlink($rlFile);
}

// ─── Discover forms ─────────────────────────────────────────────
$formFiles = glob($formsDir . '/*.json');
$formFiles = array_filter($formFiles, fn($f) => basename($f) !== 'form.schema.json');
$formFiles = array_values($formFiles);

if ($filterForm) {
    $formFiles = array_filter($formFiles, fn($f) => basename($f, '.json') === $filterForm);
    if (empty($formFiles)) {
        out("Form '$filterForm' not found in $formsDir.");
        exit(1);
    }
}

out("\n\033[1;36m╔═══════════════════════════════════════════════╗\033[0m");
out("\033[1;36m║   BareBonesForms — Smoke Test Pipeline        ║\033[0m");
out("\033[1;36m╚═══════════════════════════════════════════════╝\033[0m");
out("Found " . count($formFiles) . " form(s) to test.");

// ═════════════════════════════════════════════════════════════════
// Phase 1: Structural tests (no server needed)
// ═════════════════════════════════════════════════════════════════

section("Phase 1: JSON Structure");

$forms = [];
foreach ($formFiles as $file) {
    $name = basename($file, '.json');
    $content = file_get_contents($file);
    $form = json_decode($content, true);

    if ($form === null) {
        fail("$name.json: invalid JSON — " . json_last_error_msg());
        continue;
    }
    pass("$name.json: valid JSON");

    // Required properties
    if (empty($form['id'])) { fail("$name.json: missing 'id'"); }
    else { pass("$name.json: has id '{$form['id']}'"); }

    if (empty($form['fields']) || !is_array($form['fields'])) {
        fail("$name.json: missing 'fields' array");
        continue;
    }
    pass("$name.json: has " . count($form['fields']) . " top-level field(s)");

    // Check field names unique
    $fieldNames = [];
    $dupes = false;
    $checkFields = function(array $fields) use (&$checkFields, &$fieldNames, &$dupes) {
        foreach ($fields as $f) {
            if (!empty($f['name'])) {
                if (in_array($f['name'], $fieldNames, true)) { $dupes = $f['name']; return; }
                $fieldNames[] = $f['name'];
            }
            if (!empty($f['fields']) && is_array($f['fields'])) {
                $checkFields($f['fields']);
            }
        }
    };
    $checkFields($form['fields']);
    if ($dupes) { fail("$name.json: duplicate field name '$dupes'"); }
    else { pass("$name.json: all field names unique (" . count($fieldNames) . ")"); }

    // Check templates exist
    $onSubmit = $form['on_submit'] ?? [];
    foreach (['confirm_email', 'notify'] as $emailType) {
        if (!empty($onSubmit[$emailType]['template'])) {
            $tpl = $templatesDir . '/' . $onSubmit[$emailType]['template'];
            if (file_exists($tpl)) {
                pass("$name.json: $emailType template exists (" . $onSubmit[$emailType]['template'] . ")");
            } else {
                fail("$name.json: $emailType template missing", $onSubmit[$emailType]['template']);
            }
        }
    }

    // Check payment configuration
    if (!empty($onSubmit['payment'])) {
        $pay = $onSubmit['payment'];
        if (empty($pay['provider'])) {
            fail("$name.json: payment missing 'provider'");
        } elseif ($pay['provider'] !== 'stripe') {
            fail("$name.json: unsupported payment provider '{$pay['provider']}'");
        } else {
            pass("$name.json: payment provider = stripe");
        }

        $mode = $pay['mode'] ?? '';
        if ($mode === 'catalog' && !empty($pay['catalog']) && is_array($pay['catalog'])) {
            pass("$name.json: payment amount from trusted catalog");
        } elseif ($mode === 'donation' && !empty($pay['amount_field'])) {
            pass("$name.json: donation amount from field '{$pay['amount_field']}'");
        } elseif (isset($pay['amount']) && is_numeric($pay['amount'])) {
            pass("$name.json: fixed payment amount {$pay['amount']}");
        } else {
            fail("$name.json: payment needs trusted catalog, explicit donation amount_field, or fixed amount");
        }

        if (!empty($pay['currency'])) {
            if (strlen($pay['currency']) !== 3) {
                fail("$name.json: payment currency should be 3-letter ISO code, got '{$pay['currency']}'");
            } else {
                pass("$name.json: payment currency = {$pay['currency']}");
            }
        }

        // Check storage compatibility
        $formStorage = $form['storage'] ?? null;
        if ($formStorage === 'csv') {
            fail("$name.json: payment requires file/sqlite/mysql storage, not csv");
        }
    }

    $forms[$name] = $form;
}

if (empty($forms)) {
    out("\nNo valid forms to test. Exiting.");
    exit(1);
}

// ═════════════════════════════════════════════════════════════════
// Phase 2: Functional tests via sandbox
// ═════════════════════════════════════════════════════════════════

section("Phase 2: Starting PHP dev server");

$serverProc = bbf_test_start_server($projectDir, $host, $port);
bbf_test_verify_server($serverProc);
pass("PHP dev server running on $host:$port");
// Login once over owned HTTP; the fixture session survives server restarts.
$login = bbf_test_http($serverProc, "$baseUrl/sandbox.php", null, ['headers' => ['X-BBF-Token' => 'test-token']]);
preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/mi', $login['headers'], $sessionCookie);
preg_match('/const sandboxCsrf = ("[a-f0-9]{64}")/', $login['body'], $sessionCsrf);
if ($login['code'] !== 200 || empty($sessionCookie[1]) || empty($sessionCsrf[1])) { fail('fixture admin login must return HTTP 200, session cookie and management CSRF'); exit(1); }
function httpPost(string $url, array $data): array {
    global $serverProc, $sessionCookie, $sessionCsrf;
    return bbf_test_http($serverProc, $url, $data, ['cookie' => $sessionCookie[1], 'headers' => ['X-BBF-CSRF' => json_decode($sessionCsrf[1])]]); }
function generateTestData(array $form, bool $fillRequired = true): array {
    $data = [];
    $flatFields = flattenFields($form['fields'] ?? []);

    foreach ($flatFields as $field) {
        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        if (!$name || in_array($type, ['section', 'page_break', 'group'], true)) continue;

        $required = !empty($field['required']);
        if (!$fillRequired && $required) continue; // skip required fields for empty test
        if (!$fillRequired) continue; // skip all for empty test

        // Handle confirm fields (e.g. email_confirm = same as email)
        if (!empty($field['confirm'])) {
            // Will be filled after the main field is set
        }

        // Handle pattern fields
        if (!empty($field['pattern'])) {
            // Try to match known patterns
            $p = $field['pattern'];
            if (str_contains($p, 'REF-')) {
                $data[$name] = 'REF-A1B2C3';
                continue;
            }
            // Digits-only pattern (e.g. postal codes: ^[0-9]{5}$)
            if (preg_match('/^\^\[0-9\]\{(\d+)\}/', $p, $pm)) {
                $data[$name] = str_repeat('1', (int)$pm[1]);
                continue;
            }
        }

        switch ($type) {
            case 'text':
                $data[$name] = 'Test Value';
                if (!empty($field['minlength']) && $field['minlength'] > 10) {
                    $data[$name] = str_repeat('Test data. ', (int)ceil($field['minlength'] / 11));
                }
                // Respect maxlength — truncate if needed
                if (!empty($field['maxlength']) && strlen($data[$name]) > $field['maxlength']) {
                    $data[$name] = substr($data[$name], 0, (int)$field['maxlength']);
                }
                break;
            case 'email':
                $data[$name] = 'test@example.com';
                break;
            case 'tel':
                $data[$name] = '+1234567890';
                break;
            case 'url':
                $data[$name] = 'https://example.com';
                break;
            case 'number':
                $min = $field['min'] ?? 1;
                $max = $field['max'] ?? 100;
                $data[$name] = (string)max($min, min($max, (int)ceil(($min + $max) / 2)));
                break;
            case 'date':
                $data[$name] = $field['min'] ?? date('Y-m-d');
                break;
            case 'textarea':
                $minLen = $field['minlength'] ?? 5;
                $data[$name] = str_repeat('Test text data. ', (int)ceil($minLen / 16));
                break;
            case 'select':
            case 'radio':
                if (!empty($field['options'])) {
                    $opt = $field['options'][0];
                    $data[$name] = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                }
                break;
            case 'checkbox':
                if (!empty($field['options'])) {
                    $opt = $field['options'][0];
                    $val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                    $data[$name] = [$val];
                }
                break;
            case 'rating':
                $data[$name] = '3';
                break;
            case 'hidden':
                $data[$name] = $field['value'] ?? 'test';
                break;
            case 'password':
                $data[$name] = 'TestP@ss123';
                break;
        }
    }

    // Handle confirm fields — copy the value from the confirmed field
    foreach ($flatFields as $field) {
        if (!empty($field['confirm']) && isset($data[$field['name']])) {
            $data[$field['name'] . '_confirm'] = $data[$field['name']];
        }
    }

    // Handle template-generated fields (use + prefix pattern)
    // When a checkbox selects a value that activates a group with use/prefix,
    // we need to also generate data for the template's prefixed fields
    $templates = $form['templates'] ?? [];
    foreach ($flatFields as $field) {
        if (!empty($field['use']) && !empty($field['prefix']) && isset($templates[$field['use']])) {
            // Check if the show_if condition is met by our test data
            $showIf = $field['show_if'] ?? [];
            $triggerField = $showIf['field'] ?? '';
            $triggerValue = $showIf['value'] ?? '';
            if ($triggerField && isset($data[$triggerField])) {
                $selected = $data[$triggerField];
                $isActive = is_array($selected) ? in_array($triggerValue, $selected) : ($selected === $triggerValue);
                if ($isActive) {
                    $prefix = $field['prefix'];
                    foreach ($templates[$field['use']] as $tplField) {
                        $prefName = $prefix . $tplField['name'];
                        $tplType = $tplField['type'] ?? 'text';
                        if (in_array($tplType, ['radio', 'select']) && !empty($tplField['options'])) {
                            $opt = $tplField['options'][0];
                            $data[$prefName] = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                        } elseif ($tplType === 'checkbox' && !empty($tplField['options'])) {
                            $opt = $tplField['options'][0];
                            $data[$prefName] = [is_array($opt) ? ($opt['value'] ?? '') : $opt];
                        }
                    }
                }
            }
        }
    }

    return $data;
}

function flattenFields(array $fields): array {
    $flat = [];
    foreach ($fields as $field) {
        $flat[] = $field;
        if (!empty($field['fields']) && is_array($field['fields'])) {
            $flat = array_merge($flat, flattenFields($field['fields']));
        }
    }
    return $flat;
}

function countRequired(array $fields): int {
    $count = 0;
    foreach (flattenFields($fields) as $f) {
        $type = $f['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;
        if (!empty($f['required']) && empty($f['show_if'])) $count++;
    }
    return $count;
}

// ─── Server restart helper ──────────────────────────────────────
function restartServer(): void {
    global $host, $port, $serverProc, $projectDir;
    bbf_test_stop_server($serverProc);
    $serverProc = null;
    usleep(300000); // wait for port to free
    $serverProc = bbf_test_start_server($projectDir, $host, $port);
}

// ─── Run functional tests ───────────────────────────────────────
$formIndex = 0;
foreach ($forms as $name => $form) {
    section("Form: $name");

    // Restart every form to prevent Windows hangs; keep the same fixture session.
    if ($formIndex > 0) {
        restartServer();
    }
    $formIndex++;
    usleep(300000);

    $formId = $form['id'];
    $submitUrl = "$baseUrl/submit.php?form=$formId&sandbox=1";

    // Test 1: Definition endpoint
    $defUrl = "$baseUrl/submit.php?form=$formId&action=definition";
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $defBody = bbf_test_http($serverProc, $defUrl)['body'];
    $def = $defBody ? json_decode($defBody, true) : null;

    if ($def && !empty($def['id'])) {
        pass("definition endpoint returns valid JSON (id: {$def['id']})");
        // Verify sensitive data stripped
        if (isset($def['on_submit'])) {
            fail("definition endpoint leaks on_submit config");
        } else {
            pass("definition endpoint strips server-side config");
        }
    } else {
        fail("definition endpoint failed", substr($defBody ?: 'no response', 0, 200));
    }

    // Test 2: Empty submit → validation errors
    usleep(200000);
    $requiredCount = countRequired($form['fields']);
    $emptyResult = httpPost($submitUrl, []);

    if ($emptyResult['error']) {
        fail("empty submit: HTTP error", $emptyResult['error']);
    } elseif ($emptyResult['code'] === 422 && !empty($emptyResult['json']['sandbox'])) {
        $errCount = count($emptyResult['json']['validation']['errors'] ?? []);
        if ($requiredCount > 0 && $errCount > 0) {
            pass("empty submit: $errCount validation error(s) for $requiredCount unconditional required field(s)");
        } elseif ($requiredCount === 0) {
            warn("empty submit: no unconditional required fields — 422 may be from conditional required");
        } else {
            fail("empty submit: expected errors but got $errCount", json_encode($emptyResult['json']['validation']['errors'] ?? []));
        }
    } elseif ($emptyResult['code'] === 200 && $requiredCount === 0) {
        pass("empty submit: OK (no unconditional required fields)");
    } else {
        fail("empty submit: unexpected response (HTTP {$emptyResult['code']})", substr($emptyResult['body'] ?? '', 0, 300));
    }

    // Test 3: Valid data submit → OK
    usleep(200000);
    $testData = generateTestData($form, true);
    $validResult = httpPost($submitUrl, $testData);

    if ($validResult['error']) {
        fail("valid submit: HTTP error", $validResult['error']);
    } elseif ($validResult['code'] === 200 && !empty($validResult['json']['sandbox'])) {
        $json = $validResult['json'];
        pass("valid submit: sandbox OK (id: {$json['submission_id']})");

        // Check data was collected
        $dataCount = count($json['data'] ?? []);
        if ($dataCount > 0) {
            pass("valid submit: $dataCount field(s) collected");
        } else {
            warn("valid submit: no fields collected");
        }

        // Check on_submit preview
        $preview = $json['on_submit_preview'] ?? [];
        if (!empty($preview['confirm_email'])) {
            if (!empty($preview['confirm_email']['body_preview'])) {
                pass("valid submit: confirm_email template rendered");
            } else {
                warn("valid submit: confirm_email template empty");
            }
        }
        if (!empty($preview['notify'])) {
            if (!empty($preview['notify']['body_preview'])) {
                pass("valid submit: notify template rendered");
            } else {
                warn("valid submit: notify template empty");
            }
        }

        // Payment-specific checks
        $onSubmit = $form['on_submit'] ?? [];
        if (!empty($onSubmit['payment'])) {
            $meta = $json['meta'] ?? [];
            if (($meta['payment_status'] ?? '') === 'pending') {
                pass("valid submit: payment_status = pending");
            } else {
                fail("valid submit: payment form missing payment_status in meta");
            }

            $payPreview = $preview['payment'] ?? [];
            if (!empty($payPreview['provider'])) {
                pass("valid submit: payment preview shows provider = {$payPreview['provider']}");
                if (($payPreview['amount'] ?? 0) > 0) {
                    pass("valid submit: payment amount = {$payPreview['amount']} {$payPreview['currency']}");
                } else {
                    warn("valid submit: payment amount is 0 (may need hidden field value)");
                }
            } else {
                fail("valid submit: payment preview missing");
            }
        }
    } elseif ($validResult['code'] === 422) {
        $errors = $validResult['json']['validation']['errors'] ?? [];
        // Some errors may be from conditional required fields we didn't fill
        $conditionalErrors = array_filter($errors, function($msg, $field) use ($form) {
            // Check if this field has show_if
            foreach (flattenFields($form['fields']) as $f) {
                if (($f['name'] ?? '') === $field && !empty($f['show_if'])) return true;
            }
            return false;
        }, ARRAY_FILTER_USE_BOTH);

        if (count($conditionalErrors) === count($errors)) {
            warn("valid submit: only conditional field errors (expected — test data doesn't fill conditional fields)");
        } else {
            $unconditional = array_diff_key($errors, $conditionalErrors);
            fail("valid submit: validation failed with unconditional errors", json_encode($unconditional));
        }
    } else {
        fail("valid submit: unexpected response (HTTP {$validResult['code']})", substr($validResult['body'] ?? '', 0, 300));
    }
}

// ═════════════════════════════════════════════════════════════════
// Results
// ═════════════════════════════════════════════════════════════════

out("\n\033[1m═══════════════════════════════════════════════\033[0m");
$total = $passed + $failed + $warnings;
$color = $failed > 0 ? "\033[31m" : "\033[32m";
out("{$color}Results: $passed passed, $failed failed, $warnings warnings (of $total)\033[0m");
out("\033[1m═══════════════════════════════════════════════\033[0m\n");

exit($failed > 0 ? 1 : 0);
