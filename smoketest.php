<?php
/**
 * BareBonesForms — Smoke Test Endpoint
 *
 * Loads all form definitions, generates valid test data, and runs
 * server-side validation in-process. No emails, no storage, no webhooks.
 *
 * Usage:
 *   Browser: smoketest.php?token=YOUR_SMOKE_TOKEN
 *   Curl:    curl "https://example.com/smoketest.php?token=YOUR_SMOKE_TOKEN"
 *   CLI:     php smoketest.php [form_id]
 *
 * Security: requires smoke_token set in config.php. CLI skips token check.
 */

define('BBF_LOADED', true);

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Missing config.php.']);
    exit;
}

require_once __DIR__ . '/bbf_functions.php';
$config = require __DIR__ . '/config.php';

// ─── Server-side i18n (needed by validate()) ────────────────────
$langCode = $config['lang'] ?? 'en';
$langFile = __DIR__ . '/lang/' . preg_replace('/[^a-z0-9-]/', '', $langCode) . '.php';
$messages = file_exists($langFile) ? require $langFile : [];
if ($langCode !== 'en') {
    $enFile = __DIR__ . '/lang/en.php';
    $enMessages = file_exists($enFile) ? require $enFile : [];
    $messages = array_merge($enMessages, $messages);
}
function msg(string $key, array $params = []): string {
    global $messages;
    $text = $messages[$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

$isCli = php_sapi_name() === 'cli';

// ─── Auth ───────────────────────────────────────────────────────
$smokeToken = $config['smoke_token'] ?? '';

if ($isCli) {
    $filterForm = $argv[1] ?? null;
} else {
    if (empty($smokeToken)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Smoke testing disabled. Set smoke_token in config.php.']);
        exit;
    }
    $providedToken = $_GET['token'] ?? '';
    if (!hash_equals($smokeToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Invalid smoke token.']);
        exit;
    }
    $filterForm = $_GET['form'] ?? null;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

// ─── Discover forms ─────────────────────────────────────────────
$formsDir  = $config['forms_dir'] ?? __DIR__ . '/forms';
$formFiles = glob($formsDir . '/*.json');
$formFiles = array_filter($formFiles, fn($f) => basename($f) !== 'form.schema.json');

if ($filterForm) {
    $filterForm = preg_replace('/[^a-zA-Z0-9_-]/', '', $filterForm);
    $formFiles  = array_filter($formFiles, fn($f) => basename($f, '.json') === $filterForm);
}
$formFiles = array_values($formFiles);

// ─── Test data generator ────────────────────────────────────────
function generateSmokeData(array $form): array {
    $data   = [];
    $fields = smokeFlat($form['fields'] ?? []);

    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        if (!$name || in_array($type, ['section', 'page_break', 'group'], true)) continue;

        switch ($type) {
            case 'text':
                $val = 'Test Value';
                if (!empty($field['minlength']) && $field['minlength'] > 10)
                    $val = str_repeat('Test data. ', (int)ceil($field['minlength'] / 11));
                if (!empty($field['pattern']) && str_contains($field['pattern'], 'REF-'))
                    $val = 'REF-A1B2C3';
                $data[$name] = $val;
                break;
            case 'email':    $data[$name] = 'smoketest@example.com'; break;
            case 'tel':      $data[$name] = '+421900123456'; break;
            case 'url':      $data[$name] = 'https://example.com'; break;
            case 'number':
                $min = $field['min'] ?? 1;
                $max = $field['max'] ?? 100;
                $data[$name] = (string)(int)ceil(($min + $max) / 2);
                break;
            case 'date':     $data[$name] = $field['min'] ?? date('Y-m-d'); break;
            case 'textarea':
                $minLen = $field['minlength'] ?? 5;
                $data[$name] = str_repeat('Smoke test data. ', (int)ceil(max($minLen, 10) / 18));
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
                    $data[$name] = [is_array($opt) ? ($opt['value'] ?? '') : $opt];
                }
                break;
            case 'rating':   $data[$name] = '3'; break;
            case 'hidden':   $data[$name] = $field['value'] ?? 'test'; break;
            case 'password': $data[$name] = 'TestP@ss123'; break;
        }
    }

    // Confirm fields (email confirmation)
    foreach ($fields as $field) {
        if (!empty($field['confirm']) && isset($data[$field['name']])) {
            $data[$field['name'] . '_confirm'] = $data[$field['name']];
        }
    }

    // Template groups (use + prefix)
    $templates = $form['templates'] ?? [];
    foreach ($fields as $field) {
        if (empty($field['use']) || empty($field['prefix']) || !isset($templates[$field['use']])) continue;
        $showIf       = $field['show_if'] ?? [];
        $triggerField = $showIf['field'] ?? '';
        $triggerValue = $showIf['value'] ?? '';
        if (!$triggerField || !isset($data[$triggerField])) continue;

        $selected = $data[$triggerField];
        $isActive = is_array($selected)
            ? in_array($triggerValue, $selected)
            : ($selected === $triggerValue);
        if (!$isActive) continue;

        foreach ($templates[$field['use']] as $tplField) {
            $prefName = $field['prefix'] . $tplField['name'];
            $tplType  = $tplField['type'] ?? 'text';
            if (in_array($tplType, ['radio', 'select']) && !empty($tplField['options'])) {
                $opt = $tplField['options'][0];
                $data[$prefName] = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            } elseif ($tplType === 'checkbox' && !empty($tplField['options'])) {
                $opt = $tplField['options'][0];
                $data[$prefName] = [is_array($opt) ? ($opt['value'] ?? '') : $opt];
            } elseif ($tplType === 'text')   { $data[$prefName] = 'Test'; }
            elseif   ($tplType === 'number') { $data[$prefName] = '1'; }
        }
    }

    return $data;
}

function smokeFlat(array $fields): array {
    $flat = [];
    foreach ($fields as $f) {
        $flat[] = $f;
        if (!empty($f['fields']) && is_array($f['fields']))
            $flat = array_merge($flat, smokeFlat($f['fields']));
    }
    return $flat;
}

// ─── Run tests ──────────────────────────────────────────────────
$results   = [];
$allPassed = true;

foreach ($formFiles as $file) {
    $formId  = basename($file, '.json');
    $content = file_get_contents($file);
    $form    = json_decode($content, true);
    $result  = ['form' => $formId, 'status' => 'ok', 'errors' => [], 'fields_tested' => 0];

    // 1. JSON parse check
    if ($form === null) {
        $result['status'] = 'fail';
        $result['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
        $results[] = $result;
        $allPassed = false;
        continue;
    }

    // 2. Schema validation
    $schemaErrors = validateFormDefinition($form);
    if (!empty($schemaErrors)) {
        $result['status'] = 'fail';
        $result['errors'] = $schemaErrors;
        $results[] = $result;
        $allPassed = false;
        continue;
    }

    // 3. Resolve templates + flatten (same pipeline as submit.php)
    if (!empty($form['templates'])) {
        $form['fields'] = resolveTemplates($form['fields'], $form['templates']);
    }
    $flatFields = flattenFields($form['fields']);

    // 4. Generate valid test data
    $testData = generateSmokeData($form);
    $result['fields_tested'] = count($testData);

    // 5. Run server-side validation (same function as submit.php)
    $errors = validate($flatFields, $testData);

    if (!empty($errors)) {
        // Separate conditional vs unconditional errors
        $hardErrors = [];
        $conditionalWarnings = [];
        foreach ($errors as $fieldName => $msg) {
            $isConditional = false;
            foreach ($flatFields as $f) {
                if (($f['name'] ?? '') === $fieldName && !empty($f['show_if'])) {
                    $isConditional = true;
                    break;
                }
            }
            if ($isConditional) {
                $conditionalWarnings[$fieldName] = $msg;
            } else {
                $hardErrors[$fieldName] = $msg;
            }
        }

        if (!empty($hardErrors)) {
            $result['status'] = 'fail';
            $result['errors'] = $hardErrors;
            $allPassed = false;
        }
        if (!empty($conditionalWarnings)) {
            $result['warnings'] = $conditionalWarnings;
        }
    }

    // 6. Check email templates exist
    $onSubmit     = $form['on_submit'] ?? [];
    $templatesDir = $config['templates_dir'] ?? __DIR__ . '/templates';
    foreach (['confirm_email', 'notify'] as $emailType) {
        if (!empty($onSubmit[$emailType]['template'])) {
            $tpl = $templatesDir . '/' . $onSubmit[$emailType]['template'];
            if (!file_exists($tpl)) {
                $result['status'] = 'fail';
                $result['errors'][] = "$emailType template missing: " . $onSubmit[$emailType]['template'];
                $allPassed = false;
            }
        }
    }

    $results[] = $result;
}

// ─── Output ─────────────────────────────────────────────────────
$passed = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$total  = count($results);

$output = [
    'status'  => $allPassed ? 'ok' : 'fail',
    'summary' => "$passed/$total forms passed",
    'forms'   => $results,
];

if ($isCli) {
    echo "\n\033[1;36m  BareBonesForms — Smoke Test\033[0m\n\n";
    foreach ($results as $r) {
        $icon = $r['status'] === 'ok' ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
        echo "  $icon {$r['form']} ({$r['fields_tested']} fields)\n";
        if (is_array($r['errors'])) {
            foreach ($r['errors'] as $k => $v) {
                $label = is_string($k) ? "$k: $v" : $v;
                echo "    \033[31m  $label\033[0m\n";
            }
        }
        foreach ($r['warnings'] ?? [] as $k => $v) {
            echo "    \033[33m  $k: $v (conditional)\033[0m\n";
        }
    }
    $c = $allPassed ? "\033[32m" : "\033[31m";
    echo "\n  {$c}{$output['summary']}\033[0m\n\n";
    exit($allPassed ? 0 : 1);
} else {
    http_response_code($allPassed ? 200 : 422);
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
