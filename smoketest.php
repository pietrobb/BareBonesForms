<?php
/**
 * BareBonesForms — Smoke Test Endpoint
 *
 * Loads all form definitions, generates valid test data, and validates them.
 *
 * Modes:
 *   Dry run (default): in-process validation only — no emails, no storage.
 *   Live (?live=1):    POSTs to submit.php for real — stores, sends emails.
 *                      All email fields are overridden with smoke_email from config.
 *
 * Usage:
 *   Dry:   smoketest.php?token=TOKEN
 *   Live:  smoketest.php?token=TOKEN&live=1
 *   One:   smoketest.php?token=TOKEN&form=kontakt&live=1
 *   CLI:   php smoketest.php [form_id]
 *
 * Security: requires smoke_token in config.php. CLI skips token check.
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
    $isLive     = in_array('--live', $argv ?? [], true);
} else {
    // Rate-limit auth attempts: max 5 per minute per IP
    $logsDir = $config['logs_dir'] ?? __DIR__ . '/logs';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlFile  = $logsDir . '/smoke_rl_' . md5($ip) . '.json';
    $rlNow   = time();
    if (file_exists($rlFile)) {
        $rlEntries = json_decode(file_get_contents($rlFile), true) ?? [];
        $rlEntries = array_filter($rlEntries, fn($t) => $t > $rlNow - 60);
        if (count($rlEntries) >= 5) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many requests.']);
            exit;
        }
    } else {
        $rlEntries = [];
    }

    // Uniform 404 for any auth failure — don't reveal endpoint exists
    if (empty($smokeToken) || empty($_GET['token'] ?? '') || !hash_equals($smokeToken, $_GET['token'] ?? '')) {
        // Log failed attempt for rate limiting
        $rlEntries[] = $rlNow;
        if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
        @file_put_contents($rlFile, json_encode($rlEntries));
        http_response_code(404);
        echo '<!DOCTYPE html><title>404</title><h1>Not Found</h1>';
        exit;
    }

    $filterForm = $_GET['form'] ?? null;
    $isLive     = !empty($_GET['live']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

// ─── Live mode: require smoke_email ─────────────────────────────
$smokeEmail = $config['smoke_email'] ?? '';
if ($isLive && empty($smokeEmail)) {
    $msg = 'Live mode requires smoke_email in config.php (your email for receiving test emails).';
    if ($isCli) { echo "\n  \033[31m$msg\033[0m\n\n"; exit(1); }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// ─── Live mode: need base URL for HTTP posts to submit.php ──────
if ($isLive) {
    if (!$isCli) {
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path    = dirname($_SERVER['SCRIPT_NAME']);
        $baseUrl = rtrim("$scheme://$host$path", '/');
    } else {
        $baseUrl = 'http://127.0.0.1:' . ($argv[2] ?? '8000');
    }
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

// ─── HTTP POST helper (for live mode) ───────────────────────────
function smokePost(string $url, array $data, string $token): array {
    $postData = http_build_query($data);
    $headers  = "Content-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($postData) . "\r\n"
              . "X-BBF-Smoke-Token: $token\r\n"
              . "Connection: close\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => $headers,
            'content'       => $postData,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
    ]);
    $http_response_header = null;
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return [
        'code'  => $code,
        'body'  => $body ?: '',
        'json'  => @json_decode($body ?: '', true),
        'error' => $body === false ? 'HTTP request failed' : '',
    ];
}

// ─── Test data generator ────────────────────────────────────────
function generateSmokeData(array $form, string $emailOverride = ''): array {
    $data   = [];
    $fields = smokeFlat($form['fields'] ?? []);
    $email  = $emailOverride ?: 'smoketest@example.com';

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
            case 'email':    $data[$name] = $email; break;
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
    $result  = [
        'form'          => $formId,
        'status'        => 'ok',
        'mode'          => $isLive ? 'live' : 'dry',
        'errors'        => [],
        'fields_tested' => 0,
    ];

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

    // 4. Generate valid test data (in live mode, email fields → smoke_email)
    $testData = generateSmokeData($form, $isLive ? $smokeEmail : '');
    $result['fields_tested'] = count($testData);

    if ($isLive) {
        // ── Live mode: POST to submit.php (full pipeline) ───────
        $submitUrl = "$baseUrl/submit.php?form=$formId&smoke_token=" . urlencode($smokeToken);
        $response  = smokePost($submitUrl, $testData, $smokeToken);

        if ($response['error']) {
            $result['status'] = 'fail';
            $result['errors'][] = 'HTTP request failed — is the server running?';
            $allPassed = false;
        } elseif ($response['code'] === 200 && !empty($response['json']['submission_id'])) {
            $result['submission_id'] = $response['json']['submission_id'];
            $result['emails_to']     = $smokeEmail;
        } elseif ($response['code'] === 422) {
            $result['status'] = 'fail';
            $result['errors'] = $response['json']['validation']['errors'] ?? $response['json']['errors'] ?? ['Validation failed'];
            $allPassed = false;
        } else {
            $result['status'] = 'fail';
            $result['errors'][] = "HTTP {$response['code']}: " . substr($response['body'], 0, 200);
            $allPassed = false;
        }
    } else {
        // ── Dry mode: in-process validation only ────────────────
        $errors = validate($flatFields, $testData);

        if (!empty($errors)) {
            $hardErrors = [];
            $conditionalWarnings = [];
            foreach ($errors as $fieldName => $errMsg) {
                $isConditional = false;
                foreach ($flatFields as $f) {
                    if (($f['name'] ?? '') === $fieldName && !empty($f['show_if'])) {
                        $isConditional = true;
                        break;
                    }
                }
                if ($isConditional) {
                    $conditionalWarnings[$fieldName] = $errMsg;
                } else {
                    $hardErrors[$fieldName] = $errMsg;
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

        // Check email templates exist
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
    }

    $results[] = $result;
}

// ─── Output ─────────────────────────────────────────────────────
$passed = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$total  = count($results);
$mode   = $isLive ? 'LIVE' : 'dry run';

$output = [
    'status'  => $allPassed ? 'ok' : 'fail',
    'mode'    => $isLive ? 'live' : 'dry',
    'summary' => "$passed/$total forms passed ($mode)",
    'forms'   => $results,
];

if ($isCli) {
    $modeLabel = $isLive ? "\033[1;33mLIVE\033[0m" : "dry run";
    echo "\n\033[1;36m  BareBonesForms — Smoke Test\033[0m ($modeLabel)\n\n";
    foreach ($results as $r) {
        $icon = $r['status'] === 'ok' ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
        $extra = '';
        if (!empty($r['submission_id'])) $extra = " → {$r['submission_id']}";
        if (!empty($r['emails_to']))     $extra .= " → {$r['emails_to']}";
        echo "  $icon {$r['form']} ({$r['fields_tested']} fields)$extra\n";
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
