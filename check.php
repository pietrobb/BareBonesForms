<?php
/**
 * BareBonesForms — Installation Check
 *
 * Open this file in your browser to verify your installation.
 * DELETE THIS FILE after verification — it exposes server details.
 */

$config = null;
$results = [];
define('BBF_LOADED', true);

// ─── Access control ─────────────────────────────────────────────
// check.php exposes server details. Protect it:
// - Localhost: always allowed (development)
// - Remote: requires ?token=<api_token> from config.php
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost';

if (!$isLocal) {
    // Load config to get api_token for remote access
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $_tmpConfig = @require $configFile;
        $apiToken = is_array($_tmpConfig) ? ($_tmpConfig['api_token'] ?? '') : '';
        $providedToken = $_GET['token'] ?? '';
        if ($apiToken === '' || !hash_equals($apiToken, $providedToken)) {
            http_response_code(403);
            die('<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;text-align:center"><h1>Access denied</h1><p>check.php exposes server details. On remote servers, pass your api_token:<br><code>check.php?token=YOUR_API_TOKEN</code></p><p style="margin-top:16px;color:#888;">On localhost, access is unrestricted.</p></body></html>');
        }
    }
}

function check(string $group, string $name, bool $pass, string $detail = '', string $level = 'error'): bool {
    global $results;
    $results[] = [
        'group'  => $group,
        'name'   => $name,
        'pass'   => $pass,
        'detail' => $detail,
        'level'  => $pass ? 'pass' : $level,
    ];
    return $pass;
}

// ─── Load config ─────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
$hasConfig = file_exists($configFile);
if ($hasConfig) {
    $config = @require $configFile;
    if (!is_array($config)) $config = null;
}

// ═════════════════════════════════════════════════════════════
// PHP Environment
// ═════════════════════════════════════════════════════════════

check('PHP', 'PHP version >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='),
    'Current: PHP ' . PHP_VERSION);

check('PHP', 'JSON extension', extension_loaded('json'),
    'Required for form definitions and API.');

check('PHP', 'mbstring extension', extension_loaded('mbstring'),
    'Recommended for accurate multi-byte string length. Falls back to strlen().', 'warn');

check('PHP', 'Session support', extension_loaded('session'),
    'Required for CSRF protection.');

check('PHP', 'PDO extension', extension_loaded('pdo'),
    'Required for SQLite and MySQL storage.', 'warn');

check('PHP', 'PDO SQLite driver', extension_loaded('pdo_sqlite'),
    'Required only if using SQLite storage.', 'warn');

check('PHP', 'PDO MySQL driver', extension_loaded('pdo_mysql'),
    'Required only if using MySQL storage.', 'warn');

check('PHP', 'OpenSSL extension', extension_loaded('openssl'),
    'Required for SMTP TLS and HMAC webhooks.', 'warn');

// ═════════════════════════════════════════════════════════════
// Configuration
// ═════════════════════════════════════════════════════════════

check('Config', 'config.php exists', $hasConfig,
    $hasConfig ? 'Loaded OK.' : 'Copy config.example.php to config.php.');

if ($config) {
    $storage = $config['storage'] ?? 'file';
    check('Config', 'Storage backend set', in_array($storage, ['file', 'sqlite', 'mysql', 'csv']),
        'Current: "' . $storage . '"');

    check('Config', 'api_token configured', !empty($config['api_token']),
        'submissions.php is blocked until api_token is set.', 'warn');

    check('Config', 'webhook_secret configured', !empty($config['webhook_secret']),
        'Webhooks will not be signed without a secret.', 'warn');

    check('Config', 'CSRF protection enabled', ($config['csrf'] ?? true) === true,
        ($config['csrf'] ?? true) ? 'Enabled.' : 'Disabled — OK only for cross-origin-only setups.', 'warn');

    $mailMethod = $config['mail']['method'] ?? 'mail';
    check('Config', 'Email via SMTP', $mailMethod === 'smtp',
        'Current: "' . $mailMethod . '". SMTP recommended for production.', 'warn');

    if ($mailMethod === 'smtp') {
        check('Config', 'SMTP host set', !empty($config['mail']['smtp_host']),
            $config['mail']['smtp_host'] ?? 'empty');
        check('Config', 'SMTP credentials set', !empty($config['mail']['smtp_user']) && !empty($config['mail']['smtp_pass']),
            'Username and password required for SMTP auth.');
    }

    check('Config', 'From email set', !empty($config['mail']['from_email']) && $config['mail']['from_email'] !== 'noreply@example.com',
        'Current: ' . ($config['mail']['from_email'] ?? 'not set'), 'warn');
}

// ═════════════════════════════════════════════════════════════
// Directories
// ═════════════════════════════════════════════════════════════

$dirs = [
    'forms'       => $config['forms_dir']       ?? __DIR__ . '/forms',
    'submissions' => $config['submissions_dir']  ?? __DIR__ . '/submissions',
    'templates'   => $config['templates_dir']    ?? __DIR__ . '/templates',
    'logs'        => $config['logs_dir']         ?? __DIR__ . '/logs',
];

foreach ($dirs as $label => $dir) {
    if (!is_dir($dir)) {
        $created = @mkdir($dir, 0755, true);
        check('Dirs', "$label/ exists", $created,
            $created ? 'Auto-created.' : "Cannot create: $dir");
    } else {
        check('Dirs', "$label/ exists", true);
    }

    if (is_dir($dir) && in_array($label, ['submissions', 'logs'])) {
        check('Dirs', "$label/ writable", is_writable($dir),
            is_writable($dir) ? '' : "$dir is not writable by PHP.");
    }
}

// ═════════════════════════════════════════════════════════════
// Security
// ═════════════════════════════════════════════════════════════

check('Security', '.htaccess present', file_exists(__DIR__ . '/.htaccess'),
    'Protects config.php and data dirs on Apache. For Nginx, see .htaccess for equivalent rules.', 'warn');

// Check that config.php is not web-accessible (active probe)
$selfUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/config.php';
$ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$probe = @file_get_contents($selfUrl, false, $ctx);
$probeBlocked = ($probe === false || (isset($http_response_header) && preg_match('/\b(403|404)\b/', $http_response_header[0] ?? '')));
check('Security', 'config.php blocked via HTTP', $probeBlocked,
    $probeBlocked
        ? 'Direct HTTP access to config.php is denied.'
        : 'WARNING: config.php may be accessible via browser! Verify your .htaccess or server config blocks it.');

// Check that core files exist
check('Security', 'submit.php exists', file_exists(__DIR__ . '/submit.php'));
check('Security', 'submissions.php exists', file_exists(__DIR__ . '/submissions.php'));
check('Security', 'bbf.js exists', file_exists(__DIR__ . '/bbf.js'));

// Check config.php not accessible via forms dir
$formsDir = $dirs['forms'];
check('Security', 'No config.php in forms/', !file_exists($formsDir . '/config.php'),
    'config.php must not be placed in the forms directory.');

// BBF_LOADED guard in config.php
if ($hasConfig) {
    $cfgSrc = file_get_contents($configFile);
    check('Security', 'config.php has BBF_LOADED guard',
        str_contains($cfgSrc, 'BBF_LOADED'),
        str_contains($cfgSrc, 'BBF_LOADED')
            ? 'Guard prevents direct browser access.'
            : 'Add: defined(\'BBF_LOADED\') || exit; — protects against direct access if .htaccess is bypassed.');
}

// display_errors should be off
$dispErrors = ini_get('display_errors');
$dispOff = !$dispErrors || strtolower($dispErrors) === 'off' || $dispErrors === '0';
check('Security', 'display_errors is OFF', $dispOff,
    $dispOff ? 'Errors logged, not shown to browsers.'
             : 'display_errors is ON — PHP errors may leak paths and credentials. Set display_errors=Off in php.ini.', 'warn');

// Probe directories that should be blocked
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/');
$probeCtx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
foreach (['submissions', 'logs', 'templates', 'actions', 'forms'] as $probeDir) {
    $http_response_header = null;
    $probeResult = @file_get_contents("$baseUrl/$probeDir/", false, $probeCtx);
    $dirBlocked = ($probeResult === false || (isset($http_response_header[0]) && preg_match('/\b(403|404)\b/', $http_response_header[0])));
    check('Security', "$probeDir/ blocked via HTTP", $dirBlocked,
        $dirBlocked ? "Directory listing denied."
                    : "WARNING: $probeDir/ may be browsable! Add Options -Indexes and RewriteRule to .htaccess.");
}

// Check for common leftover files that shouldn't be in production
$dangerousFiles = ['phpinfo.php', 'info.php', 'test.php', 'pi.php'];
foreach ($dangerousFiles as $df) {
    if (file_exists(__DIR__ . '/' . $df)) {
        check('Security', "No $df in root", false,
            "$df exists — it may expose server information. Delete it.");
    }
}

// Sandbox should be off in production
if ($config) {
    check('Security', 'Sandbox disabled', empty($config['sandbox']),
        empty($config['sandbox']) ? 'Sandbox is OFF.'
            : 'Sandbox is ON — exposes form previews, webhook URLs, and template rendering. Disable for production.', 'warn');
}

// ═════════════════════════════════════════════════════════════
// Form Definitions
// ═════════════════════════════════════════════════════════════

$formFiles = glob($formsDir . '/*.json');
$formFiles = array_filter($formFiles, fn($f) => basename($f) !== 'form.schema.json');
$formFiles = array_values($formFiles);

check('Forms', 'Form definitions found', count($formFiles) > 0,
    count($formFiles) . ' form(s) in ' . basename($formsDir) . '/');

check('Forms', 'form.schema.json present', file_exists($formsDir . '/form.schema.json'),
    'JSON Schema for IDE autocomplete and validation.', 'warn');

foreach ($formFiles as $formFile) {
    $fname = basename($formFile, '.json');
    $content = @file_get_contents($formFile);
    $form = json_decode($content, true);
    $jsonOk = ($form !== null);

    check('Forms', "$fname.json: valid JSON", $jsonOk,
        $jsonOk ? '' : 'Parse error: ' . json_last_error_msg());

    if (!$jsonOk) continue;

    check('Forms', "$fname.json: has id", !empty($form['id']),
        'id: "' . ($form['id'] ?? '') . '"');

    check('Forms', "$fname.json: has fields", !empty($form['fields']) && is_array($form['fields']),
        is_array($form['fields'] ?? null) ? count($form['fields']) . ' field(s)' : 'Missing.');

    check('Forms', "$fname.json: schema_version = 1", ($form['schema_version'] ?? null) === 1,
        'Add "schema_version": 1 to your form JSON.', 'warn');

    // Validate field names unique and present (recursive into groups)
    $fieldNames = [];
    $fieldsOk = true;
    $fieldIssue = '';
    $checkFieldList = function(array $fields, string $path) use (&$checkFieldList, &$fieldNames, &$fieldsOk, &$fieldIssue) {
        $validTypes = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'hidden', 'password', 'section', 'page_break', 'rating', 'group'];
        foreach ($fields as $i => $field) {
            if (!$fieldsOk) break;
            if (empty($field['name'])) {
                $fieldsOk = false;
                $fieldIssue = "{$path}[{$i}]: missing name.";
                break;
            }
            if (in_array($field['name'], $fieldNames, true)) {
                $fieldsOk = false;
                $fieldIssue = "Duplicate field name: {$field['name']}";
                break;
            }
            $fieldNames[] = $field['name'];

            $type = $field['type'] ?? 'text';
            if (!in_array($type, $validTypes, true)) {
                $fieldsOk = false;
                $fieldIssue = "{$field['name']}: unknown type '$type'.";
                break;
            }

            // Group — recurse into children
            if ($type === 'group' && !empty($field['fields']) && is_array($field['fields'])) {
                $checkFieldList($field['fields'], "{$path}[{$i}].fields");
                continue;
            }

            if (in_array($type, ['select', 'radio', 'checkbox']) && empty($field['options'])) {
                $fieldsOk = false;
                $fieldIssue = "{$field['name']}: type '$type' requires options.";
                break;
            }
        }
    };
    $checkFieldList($form['fields'] ?? [], 'fields');
    check('Forms', "$fname.json: field definitions", $fieldsOk, $fieldIssue);

    // Check referenced templates
    $onSubmit = $form['on_submit'] ?? [];
    if (!empty($onSubmit['confirm_email']['template'])) {
        $tpl = $dirs['templates'] . '/' . $onSubmit['confirm_email']['template'];
        check('Forms', "$fname.json: confirm email template", file_exists($tpl),
            basename($tpl), 'warn');
    }
    if (!empty($onSubmit['notify']['template'])) {
        $tpl = $dirs['templates'] . '/' . $onSubmit['notify']['template'];
        check('Forms', "$fname.json: notify email template", file_exists($tpl),
            basename($tpl), 'warn');
    }

    // Check custom actions exist
    if (!empty($onSubmit['actions'])) {
        foreach ($onSubmit['actions'] as $action) {
            $atype = $action['type'] ?? '';
            if ($atype) {
                $afile = __DIR__ . '/actions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $atype) . '.php';
                check('Forms', "$fname.json: action '$atype'", file_exists($afile),
                    file_exists($afile) ? '' : "actions/$atype.php not found.", 'warn');
            }
        }
    }
}

// ═════════════════════════════════════════════════════════════
// Storage Backend
// ═════════════════════════════════════════════════════════════

if ($config) {
    $storage = $config['storage'] ?? 'file';

    if ($storage === 'mysql') {
        try {
            $db = $config['mysql'] ?? [];
            $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}";
            $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            check('Storage', 'MySQL connection', true,
                $db['host'] . ' / ' . $db['database']);

            // Check if table exists
            $tables = $pdo->query("SHOW TABLES LIKE 'bbf_submissions'")->fetchAll();
            check('Storage', 'bbf_submissions table', count($tables) > 0,
                count($tables) > 0 ? 'Table exists.' : 'Will be auto-created on first submission.', 'warn');
        } catch (PDOException $e) {
            check('Storage', 'MySQL connection', false, $e->getMessage());
        }
    }

    if ($storage === 'sqlite') {
        $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
        $dbDir = dirname($dbFile);

        check('Storage', 'SQLite directory writable', is_dir($dbDir) && is_writable($dbDir), $dbDir);

        if (extension_loaded('pdo_sqlite')) {
            try {
                $pdo = new PDO("sqlite:$dbFile", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                check('Storage', 'SQLite connection', true, basename($dbFile));

                if (file_exists($dbFile)) {
                    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bbf_submissions'")->fetchAll();
                    check('Storage', 'bbf_submissions table', count($tables) > 0,
                        count($tables) > 0 ? 'Table exists.' : 'Will be auto-created.', 'warn');
                }
            } catch (PDOException $e) {
                check('Storage', 'SQLite connection', false, $e->getMessage());
            }
        }
    }

    if ($storage === 'file') {
        $subDir = $config['submissions_dir'] ?? __DIR__ . '/submissions';
        check('Storage', 'File storage directory', is_dir($subDir) && is_writable($subDir),
            $subDir);
    }

    if ($storage === 'csv') {
        $subDir = $config['submissions_dir'] ?? __DIR__ . '/submissions';
        check('Storage', 'CSV storage directory', is_dir($subDir) && is_writable($subDir),
            $subDir);
    }
}

// ═════════════════════════════════════════════════════════════
// Results
// ═════════════════════════════════════════════════════════════

$passCount  = count(array_filter($results, fn($r) => $r['level'] === 'pass'));
$warnCount  = count(array_filter($results, fn($r) => $r['level'] === 'warn'));
$errorCount = count(array_filter($results, fn($r) => $r['level'] === 'error'));
$totalCount = count($results);
$allGood    = $errorCount === 0;

// Group results
$grouped = [];
foreach ($results as $r) {
    $grouped[$r['group']][] = $r;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BareBonesForms — Installation Check</title>
<style>
:root {
    --bg: #ffffff; --bg2: #f8f9fa; --text: #1a1a2e; --muted: #6b7280;
    --border: #e5e7eb; --pass: #059669; --pass-bg: #ecfdf5;
    --warn: #d97706; --warn-bg: #fffbeb; --fail: #dc2626; --fail-bg: #fef2f2;
    --accent: #2563eb;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #1a1a2e; --bg2: #16162a; --text: #e2e8f0; --muted: #94a3b8;
        --border: #334155; --pass-bg: #064e3b; --warn-bg: #451a03; --fail-bg: #450a0a;
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; padding: 32px 20px; }
.wrap { max-width: 720px; margin: 0 auto; }

.header { text-align: center; margin-bottom: 32px; }
.header h1 { font-size: 1.5rem; margin-bottom: 4px; }
.header p { color: var(--muted); font-size: 0.9rem; }
.skull { font-size: 2.5rem; margin-bottom: 8px; }

.summary { display: flex; gap: 16px; justify-content: center; margin: 24px 0 32px; flex-wrap: wrap; }
.stat { padding: 12px 24px; border-radius: 8px; text-align: center; min-width: 100px; }
.stat .num { font-size: 1.8rem; font-weight: 700; }
.stat .lbl { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
.stat.pass { background: var(--pass-bg); color: var(--pass); }
.stat.warn { background: var(--warn-bg); color: var(--warn); }
.stat.fail { background: var(--fail-bg); color: var(--fail); }

.verdict { text-align: center; padding: 16px; border-radius: 8px; margin-bottom: 32px; font-weight: 600; font-size: 1.1rem; }
.verdict.ok { background: var(--pass-bg); color: var(--pass); }
.verdict.problems { background: var(--fail-bg); color: var(--fail); }

.group { margin-bottom: 24px; }
.group-title { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; color: var(--muted); margin-bottom: 8px; padding-left: 4px; }

.check { display: flex; align-items: flex-start; gap: 10px; padding: 8px 12px; border-radius: 6px; margin-bottom: 2px; }
.check:hover { background: var(--bg2); }
.icon { width: 20px; height: 20px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; color: #fff; margin-top: 2px; }
.icon.pass { background: var(--pass); }
.icon.warn { background: var(--warn); }
.icon.error { background: var(--fail); }
.check-name { font-weight: 500; font-size: 0.9rem; }
.check-detail { color: var(--muted); font-size: 0.8rem; }

.footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--muted); font-size: 0.8rem; }
.footer strong { color: var(--fail); }
</style>
</head>
<body>
<div class="wrap">

<div class="header">
    <div class="skull">&#x1F480;</div>
    <h1>BareBonesForms — Installation Check</h1>
    <p>Checking your server, config, forms, and security.</p>
</div>

<div class="summary">
    <div class="stat pass"><div class="num"><?= $passCount ?></div><div class="lbl">Passed</div></div>
    <div class="stat warn"><div class="num"><?= $warnCount ?></div><div class="lbl">Warnings</div></div>
    <div class="stat fail"><div class="num"><?= $errorCount ?></div><div class="lbl">Failed</div></div>
</div>

<div class="verdict <?= $allGood ? 'ok' : 'problems' ?>">
    <?= $allGood
        ? ($warnCount > 0 ? "All checks passed with $warnCount warning(s). Review warnings for production." : 'All checks passed. Ready to go.')
        : "$errorCount check(s) failed. Fix errors before going live." ?>
</div>

<?php foreach ($grouped as $group => $checks): ?>
<div class="group">
    <div class="group-title"><?= htmlspecialchars($group) ?></div>
    <?php foreach ($checks as $c): ?>
    <div class="check">
        <div class="icon <?= $c['level'] ?>"><?= $c['level'] === 'pass' ? '&#10003;' : ($c['level'] === 'warn' ? '!' : '&#10007;') ?></div>
        <div>
            <div class="check-name"><?= htmlspecialchars($c['name']) ?></div>
            <?php if ($c['detail']): ?>
            <div class="check-detail"><?= htmlspecialchars($c['detail']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="footer">
    Checked <?= $totalCount ?> items at <?= date('Y-m-d H:i:s') ?> &middot; PHP <?= PHP_VERSION ?><br>
    <strong>Delete this file (check.php) after verification.</strong>
</div>

</div>
</body>
</html>
