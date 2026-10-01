<?php
/**
 * BareBonesForms — Smoke Test Endpoint
 *
 * Loads all form definitions, generates valid test data, and validates them.
 *
 * Modes:
 *   Dry run (default): in-process validation only — no emails, no storage.
 *   Live (?live=1):    POSTs to submit.php for real — stores, sends emails.
 *                      Email fields use smoke_email; notify goes to smoke_notify.
 *
 * Usage:
 *   Dry:   GET smoketest.php with X-BBF-Smoke-Token: TOKEN
 *   Live:  POST smoketest.php?live=1 with X-BBF-Smoke-Token: TOKEN
 *   One:   append &form=kontakt to the live URL (never put tokens in URLs).
 *   CLI:   php smoketest.php [form_id] [--live] (trusted local invocation)
 *
 * Security: separate smoke_token, no cookies/admin grants; access is audited.
 */

define('BBF_LOADED', true);

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Missing config.php.']);
    exit;
}

require_once __DIR__ . '/bbf_functions.php';
require_once __DIR__ . '/bbf_auth.php'; $config = bbf_auth_load_config(__DIR__ . '/config.php');
require_once __DIR__ . '/bbf_diagnostics.php';
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

// ─── Stateless smoke auth; never consume management cookies ─────
$smokeToken = bbf_smoke_token($config) ?? ''; // shorter than 16 characters = not a credential (off)
function smokeTokenValid($token): bool { return is_string($token) && preg_match('/\A[\x21-\x7e]{1,512}\z/D', $token) === 1; }
$filterForm = $isCli ? (array_values(array_filter(array_slice($argv ?? [], 1), static fn($v) => $v !== '--live'))[0] ?? null) : ($_GET['form'] ?? null);
$isLive = $isCli ? in_array('--live', $argv ?? [], true) : !empty($_GET['live']);
$action = $isLive ? 'smoke_live' : 'smoke_dry';
// Audit labels are fixed, not credentials, addresses, URLs or caller-supplied IDs.
$auditConfig = $config; // bbf_audit_secrets() redacts smoke_token too
$principal = ['id' => $isCli ? 'smoke-cli' : 'smoke-http', 'admin' => false];
if (!$isCli) {
    bbf_auth_headers();
    header('Content-Type: application/json; charset=utf-8');
    $provided = $_SERVER['HTTP_X_BBF_SMOKE_TOKEN'] ?? null;
    $authorized = smokeTokenValid($smokeToken) && smokeTokenValid($provided) && hash_equals($smokeToken, $provided);
    // Reject URL credentials even alongside a valid header; no cookie or admin fallback.
    $authorized = $authorized && !array_key_exists('token', $_GET) && !array_key_exists('smoke_token', $_GET);
    if (!$authorized) {
        bbf_audit_write($auditConfig, null, $action, '', [], 'denied', 'attempted', 0);
        // Retain failed-login throttling, but only after durable audit preflight.
        $rlFile = ($config['logs_dir'] ?? __DIR__ . '/logs') . '/smoke_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
        $rlNow = time();
        $rlEntries = is_file($rlFile) ? json_decode(@file_get_contents($rlFile) ?: '[]', true) : [];
        $rlEntries = array_filter(is_array($rlEntries) ? $rlEntries : [], static fn($t) => is_int($t) && $t > $rlNow - 60);
        $limited = count($rlEntries) >= 5;
        if (!$limited) { $rlEntries[] = $rlNow; @file_put_contents($rlFile, json_encode(array_values($rlEntries)), LOCK_EX); }
        bbf_audit_write($auditConfig, null, $action, '', [], 'denied', 'failed', 0);
        bbf_auth_fail($limited ? 429 : 403);
    }
    if ($isLive && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        bbf_audit_write($auditConfig, $principal, $action, '', [], 'denied', 'attempted', 0);
        bbf_audit_write($auditConfig, $principal, $action, '', [], 'denied', 'failed', 0);
        header('Allow: POST'); bbf_auth_fail(405);
    }
}
// Header-only credentials need no browser CSRF exchange. Shutdown records unfinished failures.
bbf_access_begin($auditConfig, $principal, $action);
if ($filterForm !== null && !is_string($filterForm)) { http_response_code(400); exit('Invalid form selector.'); }
if ($isLive && !smokeTokenValid($smokeToken)) { http_response_code(400); if ($isCli) { fwrite(STDERR, "Live mode requires a generated smoke_token (at least 32 hexadecimal characters). Run php maintenance.php new-token.\n"); exit(1); } exit('Invalid smoke credential configuration.'); }

// ─── Live mode: require smoke_email ─────────────────────────────
$smokeEmail  = $config['smoke_email'] ?? '';
$smokeNotify = $config['smoke_notify'] ?? $smokeEmail;
if ($isLive && empty($smokeEmail)) {
    $msg = 'Live mode requires smoke_email in config.php (your email for receiving test emails).';
    if ($isCli) { echo "\n  \033[31m$msg\033[0m\n\n"; exit(1); }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// ─── Live mode: need base URL for HTTP posts to submit.php ──────
if ($isLive) {
    $baseUrl = bbf_diagnostic_base_url($config);
    if ($baseUrl === null) {
        $message = 'Live diagnostics require a valid fixed diagnostic_base_url in config.php.';
        if ($isCli) { fwrite(STDERR, $message . "\n"); exit(1); }
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
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
    if (!smokeTokenValid($token)) return ['code' => 0, 'body' => '', 'json' => null, 'error' => 'Invalid smoke credential.']; $postData = http_build_query($data);

    // Prefer cURL — works on shared hosts with allow_url_fopen=0
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-BBF-Smoke-Token: ' . $token,
                'Connection: close',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [
            'code'  => $code,
            'body'  => is_string($body) ? $body : '',
            'json'  => @json_decode(is_string($body) ? $body : '', true),
            'error' => ($body === false || $code === 0) ? ('HTTP request failed: ' . $err) : '',
        ];
    }

    // Fallback to file_get_contents (requires allow_url_fopen=1)
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
            'ignore_errors' => true, 'follow_location' => 0,
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
        'error' => $body === false ? 'HTTP request failed (allow_url_fopen disabled and curl not available)' : '',
    ];
}

// ─── Test data generator ────────────────────────────────────────
const SMOKE_SAMPLE_MAX = 5000; // longest value built from a pattern: "(a{1000}){1000}" must not exhaust memory
const SMOKE_TEXT_MAX = 1000000; // longest plain text for minlength (1 MB); minlength 10^9 must not exhaust memory
/** First candidate that satisfies the field's pattern and length rules, checked like submit.php does. */
function smokeTextValue(array $field, array $preferred = []): string {
    $min = (int)($field['minlength'] ?? 0);
    $max = (int)($field['maxlength'] ?? 0);
    $pattern = is_string($field['pattern'] ?? null) ? $field['pattern'] : '';
    $candidates = $preferred;
    if (is_string($field['placeholder'] ?? null) && $field['placeholder'] !== '') $candidates[] = $field['placeholder'];
    // Plain text of exactly max(minlength, 11) characters (no trailing space, submit.php trims); a minlength above
    // SMOKE_TEXT_MAX cannot be met without exhausting memory, so that form fails with the length error.
    $candidates[] = 'Test Value';
    $plainLength = max($min, 11);
    if ($plainLength <= SMOKE_TEXT_MAX) {
        $plain = substr(str_repeat('Test data. ', intdiv($plainLength, 11) + 1), 0, $plainLength);
        $candidates[] = str_ends_with($plain, ' ') ? substr($plain, 0, -1) . '.' : $plain;
    }
    array_push($candidates, 'REF-A1B2C3', 'test', 'ABC123');
    foreach (range(1, 20) as $length) $candidates[] = substr(str_repeat('1234567890', 2), 0, $length);
    // A value built from the pattern itself, so a placeholder like "e.g. SK1234" is not needed to pass "^[A-Z]{2}\d{4}$".
    if ($pattern !== '') foreach (['min', 'more'] as $reps) {
        $sample = smokePatternSample($pattern, $reps);
        if ($sample !== null) $candidates[] = $sample;
    }
    $regex = $pattern !== '' ? bbfFieldPatternRegex($pattern) : null;
    foreach ($candidates as $value) {
        if ($value === '') continue; // "^a*$" allows "", but a required field would then fail as empty
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $min || ($max > 0 && $length > $max)) continue;
        if ($pattern !== '' && ($regex === null || @preg_match($regex, $value) !== 1)) continue;
        return $value;
    }
    return $preferred[0] ?? 'Test Value';
}

/**
 * One string matching a field pattern (JavaScript RegExp syntax), or null when the pattern uses something this
 * small generator does not model. $reps: 'min' takes the fewest repetitions, 'more' a few more (for minlength).
 * The caller always re-checks the result with the real regex.
 */
function smokePatternSample(string $pattern, string $reps = 'min'): ?string {
    $i = 0;
    $out = smokePatternAlternatives($pattern, $i, $reps);
    return $out !== null && $i >= strlen($pattern) ? $out : null;
}

function smokePatternAlternatives(string $p, int &$i, string $reps): ?string {
    // The first alternative the generator can produce is used; the rest are parsed only to reach the enclosing ")".
    $result = smokePatternSequence($p, $i, $reps);
    while ($i < strlen($p) && $p[$i] === '|') {
        $i++;
        $alternative = smokePatternSequence($p, $i, $reps);
        $result ??= $alternative;
    }
    return $result;
}

function smokePatternSequence(string $p, int &$i, string $reps): ?string {
    $out = '';
    $ok = true;
    while ($i < strlen($p) && $p[$i] !== '|' && $p[$i] !== ')') {
        $atom = smokePatternAtom($p, $i, $reps);
        if ($atom === null) { $ok = false; $atom = ''; }
        [$minRep, $moreRep] = smokePatternQuantifier($p, $i);
        $count = $reps === 'min' ? $minRep : $moreRep;
        if ($atom !== '' && $count > intdiv(SMOKE_SAMPLE_MAX - strlen($out), strlen($atom))) { $ok = false; continue; }
        if ($ok) $out .= str_repeat($atom, $count);
    }
    return $ok ? $out : null;
}

/** Repetition counts for the quantifier at $i (1/1 when there is none); a lazy "?" suffix is skipped. */
function smokePatternQuantifier(string $p, int &$i): array {
    $counts = [1, 1];
    $c = $p[$i] ?? '';
    if ($c === '?') $counts = [0, 1];
    elseif ($c === '*') $counts = [0, 3];
    elseif ($c === '+') $counts = [1, 3];
    elseif ($c === '{' && preg_match('/\G\{(\d+)(,(\d*))?\}/', $p, $m, 0, $i)) {
        $n = (int)$m[1];
        $counts = [$n, isset($m[2]) ? ($m[3] !== '' ? max($n, min((int)$m[3], $n + 5)) : $n + 3) : $n];
        $i += strlen($m[0]);
        if (($p[$i] ?? '') === '?') $i++;
        return $counts;
    } else return $counts;
    $i++;
    if (($p[$i] ?? '') === '?') $i++;
    return $counts;
}

/** A sample for one atom (group, class, escape, "." or literal), or null if it cannot be produced. */
function smokePatternAtom(string $p, int &$i, string $reps): ?string {
    $c = $p[$i];
    if ($c === '^' || $c === '$') { $i++; return ''; }
    if ($c === '.') { $i++; return 'a'; }
    if ($c === '(') {
        $i++;
        $lookaround = false;
        if (substr($p, $i, 2) === '?:') $i += 2;
        elseif (preg_match('/\G\?<(?![=!])[A-Za-z_]\w*>/', $p, $m, 0, $i)) $i += strlen($m[0]);
        elseif (preg_match('/\G\?<?[=!]/', $p, $m, 0, $i)) { $i += strlen($m[0]); $lookaround = true; }
        $inner = smokePatternAlternatives($p, $i, $reps);
        if (($p[$i] ?? '') !== ')') return null;
        $i++;
        return $lookaround ? '' : $inner;
    }
    if ($c === '[') {
        if (!preg_match('/\G\[\^?\]?(?:\\\\.|[^\]\\\\])*\]/s', $p, $m, 0, $i)) return null;
        $i += strlen($m[0]);
        return smokePatternPick($m[0]);
    }
    if ($c === '\\') {
        $n = $p[$i + 1] ?? '';
        if ($n === '') return null;
        if (preg_match('/\G\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|p\{[\w=]+\}|P\{[\w=]+\}|c[A-Za-z])/', $p, $m, 0, $i)) {
            $i += strlen($m[0]);
            return smokePatternPick($m[0]);
        }
        $i += 2;
        $known = ['d' => '1', 'w' => 'a', 's' => ' ', 'D' => 'a', 'W' => '-', 'S' => 'a', 'b' => '', 'B' => '', 'n' => "\n", 't' => "\t", 'r' => "\r"];
        if (isset($known[$n])) return $known[$n];
        if (ctype_digit($n) || ctype_alpha($n)) return null;   // back-references and escapes this generator does not know
        return $n;
    }
    // Literal character (one UTF-8 sequence).
    preg_match('/\G./su', $p, $m, 0, $i);
    $char = $m[0] ?? $c;
    $i += strlen($char);
    return $char;
}

/** First common character matched by a single-character regex fragment (class or escape). */
function smokePatternPick(string $fragment): ?string {
    $regex = bbfFieldPatternRegex('^' . $fragment . '$');
    if ($regex === null) return null;
    foreach (['a', 'A', '1', 'x', 'Z', '0', '9', '-', '_', '.', ' ', '@', '+', '/', ':', 'á', 'ž', 'é', 'ß', '*', '#', ','] as $char) {
        if (@preg_match($regex, $char) === 1) return $char;
    }
    return null;
}

/** Value of the first option that is not empty ("Choose…" placeholders have value ""), so a required choice passes. */
function smokeFirstOption(array $options): string {
    foreach ($options as $opt) {
        $value = is_array($opt) ? ($opt['value'] ?? '') : $opt;
        if (is_scalar($value) && (string)$value !== '') return (string)$value;
    }
    return '';
}

/**
 * $problems collects fields that cannot get a safe value. In live mode an email field gets smoke_email only: when
 * smoke_email does not match the field's pattern, nothing is made up (a generated "a@firma.sk" is a real domain
 * and would receive the confirmation email), the field is reported instead.
 */
function generateSmokeData(array $form, string $emailOverride = '', array &$problems = []): array {
    $data   = [];
    $fields = smokeFlat($form['fields'] ?? []);
    $email  = $emailOverride ?: 'smoketest@example.com';

    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        if (!$name || in_array($type, ['section', 'page_break', 'group'], true)) continue;

        switch ($type) {
            case 'text':     $data[$name] = smokeTextValue($field); break;
            case 'email':
                if (empty($field['pattern'])) { $data[$name] = $email; break; }
                $value = smokeTextValue($field, [$email]);
                if ($emailOverride !== '' && $value !== $email) {
                    $problems[] = "smoke_email does not match the pattern of email field \"$name\"; not submitted, so no email goes to a made-up address. Set smoke_email to your address that matches it.";
                    $value = $email;
                }
                $data[$name] = $value;
                break;
            case 'tel':      $data[$name] = empty($field['pattern']) ? '+421900123456' : smokeTextValue($field, ['+421900123456']); break;
            case 'url':      $data[$name] = 'https://example.com'; break;
            case 'number':
                $min = $field['min'] ?? 1;
                $max = $field['max'] ?? 100;
                $data[$name] = (string)(int)ceil(($min + $max) / 2);
                break;
            case 'date':     $data[$name] = $field['min'] ?? date('Y-m-d'); break;
            case 'textarea':
                $data[$name] = smokeTextValue($field, ['Smoke test data.']); break;
            case 'select':
            case 'radio':
                if (!empty($field['options']) && is_array($field['options'])) $data[$name] = smokeFirstOption($field['options']);
                break;
            case 'checkbox':
                if (!empty($field['options']) && is_array($field['options'])) $data[$name] = [smokeFirstOption($field['options'])];
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
            if (in_array($tplType, ['radio', 'select']) && !empty($tplField['options']) && is_array($tplField['options'])) {
                $data[$prefName] = smokeFirstOption($tplField['options']);
            } elseif ($tplType === 'checkbox' && !empty($tplField['options']) && is_array($tplField['options'])) {
                $data[$prefName] = [smokeFirstOption($tplField['options'])];
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
    $dataProblems = [];
    $testData = generateSmokeData($form, $isLive ? $smokeEmail : '', $dataProblems);
    $result['fields_tested'] = count($testData);

    if ($isLive && $dataProblems !== []) {
        $result['status'] = 'fail';
        $result['errors'] = $dataProblems;
        $allPassed = false;
    } elseif ($isLive) {
        // ── Live mode: POST to submit.php (full pipeline) ───────
        $submitUrl = "$baseUrl/submit.php?form=" . rawurlencode($formId);
        $response  = smokePost($submitUrl, $testData, $smokeToken);

        if ($response['error']) {
            $result['status'] = 'fail';
            $result['errors'][] = 'HTTP request failed — is the server running?';
            $allPassed = false;
        } elseif ($response['code'] === 200 && !empty($response['json']['submission_id'])) {
            $result['submission_id'] = $response['json']['submission_id'];
            $result['confirm_to'] = $smokeEmail;
            $result['notify_to']  = $smokeNotify;
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
            if (is_array($onSubmit[$emailType] ?? null) && $onSubmit[$emailType] !== []) {
                $name = basename((string)($onSubmit[$emailType]['template'] ?? ($emailType === 'notify' ? 'notify.html' : 'confirm.html')));
                $source = is_file("$templatesDir/$name") ? @file_get_contents("$templatesDir/$name") : false;
                foreach (is_string($source) ? bbf_template_warnings($source) : [] as $warning)
                    $result['warnings'][] = "$emailType template $name: $warning";
            }
        }
    }

    $results[] = $result;
}

// ─── Output ─────────────────────────────────────────────────────
$passed = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$total  = count($results);
$mode   = $isLive ? 'LIVE' : 'dry run'; bbf_access_finish($total, $allPassed);

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
        if (!empty($r['confirm_to']))    $extra .= " [confirm→{$r['confirm_to']}]";
        if (!empty($r['notify_to']) && ($r['notify_to'] ?? '') !== ($r['confirm_to'] ?? ''))
            $extra .= " [notify→{$r['notify_to']}]";
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
