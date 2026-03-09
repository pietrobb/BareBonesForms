<?php
/**
 * BareBonesForms — Sandbox
 *
 * Test forms without side effects.
 * Validates, previews emails/webhooks, but does NOT store or send anything.
 *
 * Enable in config.php: 'sandbox' => true
 * Disable before going to production.
 */

// Bootstrap
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing config.php. Copy config.example.php to config.php and edit it.');
}
$config = require __DIR__ . '/config.php';

if (empty($config['sandbox'])) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;text-align:center"><h1>Sandbox disabled</h1><p>Set <code>\'sandbox\' => true</code> in config.php to enable testing.</p></body></html>');
}

// Sandbox exposes server details — restrict to localhost or require api_token
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isLocal) {
    $apiToken = $config['api_token'] ?? '';
    $provided = $_GET['token'] ?? ($_SERVER['HTTP_X_BBF_TOKEN'] ?? '');
    if ($apiToken === '' || !hash_equals($apiToken, $provided)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;text-align:center"><h1>Access denied</h1><p>Sandbox exposes server details. On remote servers, pass your api_token:<br><code>sandbox.php?token=YOUR_API_TOKEN</code></p></body></html>');
    }
}

// List available forms
$formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
$formFiles = glob($formsDir . '/*.json');
$forms = [];
foreach ($formFiles as $f) {
    $basename = basename($f, '.json');
    if ($basename === 'form.schema') continue;
    $def = json_decode(file_get_contents($f), true);
    $forms[] = [
        'id'   => $basename,
        'name' => $def['name'] ?? $basename,
        'fields' => count($def['fields'] ?? []),
    ];
}

// Serve JSON form list for AJAX
if (($_GET['action'] ?? '') === 'forms') {
    header('Content-Type: application/json');
    echo json_encode($forms);
    exit;
}

// Serve form definition for AJAX
if (($_GET['action'] ?? '') === 'definition') {
    header('Content-Type: application/json');
    $fid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['form'] ?? '');
    $file = $formsDir . "/$fid.json";
    if ($fid && file_exists($file)) {
        echo file_get_contents($file);
    } else {
        echo json_encode(['error' => 'Form not found']);
    }
    exit;
}

$selectedForm = $_GET['form'] ?? ($forms[0]['id'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BareBonesForms — Sandbox</title>
<link rel="stylesheet" href="bbf.css">
<style>
:root {
    --bg: #ffffff; --bg-alt: #f8f9fa; --bg-code: #1e1e2e;
    --text: #1a1a2e; --text-muted: #6b7280; --text-code: #cdd6f4;
    --border: #e5e7eb; --accent: #2563eb; --accent-light: #dbeafe;
    --green: #059669; --red: #dc2626; --orange: #d97706;
    --sandbox-bg: #fef3c7; --sandbox-border: #f59e0b;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #1a1a2e; --bg-alt: #16162a; --bg-code: #0f0f1a;
        --text: #e2e8f0; --text-muted: #94a3b8; --text-code: #cdd6f4;
        --border: #334155; --accent: #60a5fa; --accent-light: #1e3a5f;
        --green: #34d399; --red: #f87171; --orange: #fbbf24;
        --sandbox-bg: #422006; --sandbox-border: #d97706;
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }

/* Sandbox banner */
.sandbox-banner {
    background: var(--sandbox-bg); border-bottom: 2px solid var(--sandbox-border);
    padding: 12px 24px; text-align: center; font-weight: 600; font-size: 0.9rem;
}
.sandbox-banner code { background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }

/* Header */
.header {
    background: var(--bg-alt); border-bottom: 1px solid var(--border);
    padding: 16px 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.header h1 { font-size: 1.2rem; font-weight: 700; white-space: nowrap; }
.header h1 span { color: var(--accent); }
.form-select {
    padding: 7px 12px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--bg); color: var(--text); font-size: 0.9rem; font-family: inherit; min-width: 200px;
}
.form-meta { font-size: 0.82rem; color: var(--text-muted); }

/* Layout */
.layout { display: grid; grid-template-columns: 1fr 1fr; gap: 0; min-height: calc(100vh - 120px); }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }

.panel { padding: 24px; overflow-y: auto; }
.panel-left { border-right: 1px solid var(--border); }
.panel h2 { font-size: 1.1rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.panel h3 { font-size: 0.95rem; margin: 20px 0 8px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }

/* Form area */
.form-area { max-width: 540px; }
.form-area .bbf-form-container { max-width: 100%; }

/* Debug panel */
.debug-section {
    background: var(--bg-alt); border: 1px solid var(--border); border-radius: 8px;
    padding: 16px; margin-bottom: 16px;
}
.debug-section summary {
    cursor: pointer; font-weight: 600; font-size: 0.9rem; color: var(--accent);
    list-style: none; display: flex; align-items: center; gap: 6px;
}
.debug-section summary::before { content: '\25B6'; font-size: 0.7em; transition: transform 0.15s; }
.debug-section[open] summary::before { transform: rotate(90deg); }

/* Status badges */
.badge {
    display: inline-block; padding: 2px 10px; border-radius: 99px;
    font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
}
.badge-pass { background: #d1fae5; color: #065f46; }
.badge-fail { background: #fee2e2; color: #991b1b; }
.badge-skip { background: #e5e7eb; color: #6b7280; }
@media (prefers-color-scheme: dark) {
    .badge-pass { background: #064e3b; color: #6ee7b7; }
    .badge-fail { background: #450a0a; color: #fca5a5; }
    .badge-skip { background: #374151; color: #9ca3af; }
}

/* Code preview */
.code-preview {
    background: var(--bg-code); color: var(--text-code); border-radius: 6px;
    padding: 12px 16px; margin: 8px 0; overflow-x: auto;
    font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
    font-size: 0.8rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
}

/* Email preview */
.email-preview {
    background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
    padding: 16px; margin: 8px 0;
}
.email-header { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px; }
.email-header strong { color: var(--text); }
.email-body { border-top: 1px solid var(--border); padding-top: 12px; font-size: 0.9rem; }

/* Result area */
.result-area { display: none; }
.result-area.visible { display: block; }

/* Validation list */
.validation-list { list-style: none; padding: 0; }
.validation-list li {
    padding: 6px 10px; border-radius: 4px; margin: 4px 0;
    font-size: 0.88rem; display: flex; align-items: center; gap: 8px;
}
.validation-list .v-pass { color: var(--green); }
.validation-list .v-fail { color: var(--red); }
.validation-list .v-icon { font-weight: 700; width: 20px; text-align: center; }

/* JSON toggle */
.json-toggle { display: inline-block; margin: 0 0 12px; padding: 6px 14px; background: var(--bg-alt); border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-size: 0.82rem; color: var(--text-muted); font-family: inherit; }
.json-toggle:hover { border-color: var(--accent); color: var(--accent); }

/* Spinner */
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="sandbox-banner">
    SANDBOX MODE — Nothing is stored or sent. Set <code>'sandbox' => false</code> in config.php for production.
</div>

<div class="header">
    <h1>BareBonesForms <span>Sandbox</span></h1>
    <select class="form-select" id="form-select" onchange="loadForm(this.value)">
        <?php foreach ($forms as $f): ?>
        <option value="<?= htmlspecialchars($f['id']) ?>" <?= $f['id'] === $selectedForm ? 'selected' : '' ?>>
            <?= htmlspecialchars($f['name']) ?> (<?= $f['fields'] ?> fields)
        </option>
        <?php endforeach; ?>
        <?php if (empty($forms)): ?>
        <option disabled>No forms found in forms/ directory</option>
        <?php endif; ?>
    </select>
    <span class="form-meta" id="form-meta"></span>
</div>

<div class="layout">
    <!-- Left: Form -->
    <div class="panel panel-left">
        <h2>Form Preview</h2>
        <div class="form-area" id="form-container">
            <div style="color:var(--text-muted);padding:24px;text-align:center">Loading form...</div>
        </div>

        <button class="json-toggle" id="json-toggle" onclick="toggleJson()">Show Form JSON</button>
        <div id="json-preview" style="display:none">
            <div class="code-preview" id="form-json"></div>
        </div>
    </div>

    <!-- Right: Results -->
    <div class="panel panel-right">
        <h2>Test Results</h2>

        <div id="result-placeholder" style="color:var(--text-muted);text-align:center;padding:40px 0;">
            Submit the form to see validation results,<br>email previews, and webhook details.
        </div>

        <div class="result-area" id="results">
            <!-- Validation -->
            <details class="debug-section" open>
                <summary>Validation</summary>
                <div id="validation-content"></div>
            </details>

            <!-- Collected Data -->
            <details class="debug-section" open>
                <summary>Collected Data</summary>
                <div class="code-preview" id="data-preview"></div>
            </details>

            <!-- Store Preview -->
            <details class="debug-section" id="store-section">
                <summary>Storage</summary>
                <div id="store-content"></div>
            </details>

            <!-- Email Preview: Confirmation -->
            <details class="debug-section" id="confirm-section" style="display:none">
                <summary>Confirmation Email</summary>
                <div id="confirm-content"></div>
            </details>

            <!-- Email Preview: Notification -->
            <details class="debug-section" id="notify-section" style="display:none">
                <summary>Admin Notification</summary>
                <div id="notify-content"></div>
            </details>

            <!-- Webhooks -->
            <details class="debug-section" id="webhook-section" style="display:none">
                <summary>Webhooks</summary>
                <div id="webhook-content"></div>
            </details>

            <!-- Actions -->
            <details class="debug-section" id="actions-section" style="display:none">
                <summary>Custom Actions</summary>
                <div id="actions-content"></div>
            </details>

            <!-- Redirect -->
            <details class="debug-section" id="redirect-section" style="display:none">
                <summary>Redirect</summary>
                <div id="redirect-content"></div>
            </details>

            <!-- Full Response -->
            <details class="debug-section">
                <summary>Raw API Response</summary>
                <div class="code-preview" id="raw-response"></div>
            </details>
        </div>
    </div>
</div>

<script src="bbf.js"></script>
<script>
(function() {
    let currentFormId = '<?= htmlspecialchars($selectedForm) ?>';
    let currentFormDef = null;

    window.loadForm = async function(formId) {
        currentFormId = formId;
        const container = document.getElementById('form-container');
        container.innerHTML = '<div style="color:var(--text-muted);padding:24px;text-align:center"><span class="spinner"></span> Loading...</div>';

        // Reset results
        document.getElementById('results').classList.remove('visible');
        document.getElementById('result-placeholder').style.display = '';

        try {
            // Fetch form definition
            const resp = await fetch(`sandbox.php?action=definition&form=${formId}`);
            currentFormDef = await resp.json();

            // Show form JSON
            document.getElementById('form-json').textContent = JSON.stringify(currentFormDef, null, 2);

            // Update meta
            const fields = currentFormDef.fields || [];
            const types = {};
            fields.forEach(f => { const t = f.type || 'text'; types[t] = (types[t] || 0) + 1; });
            const typeStr = Object.entries(types).map(([t, c]) => `${c}x ${t}`).join(', ');
            document.getElementById('form-meta').textContent = `${fields.length} fields (${typeStr})`;

            // Render form (without CSRF — sandbox mode)
            container.innerHTML = '';
            container.classList.add('bbf-form-container');
            const formEl = BBF._buildForm(currentFormDef, formId, '', {}, null);
            container.appendChild(formEl);

            // Intercept submit
            formEl.addEventListener('submit', async (e) => {
                e.preventDefault();
                await sandboxSubmit(formEl, formId);
            });
        } catch (err) {
            container.innerHTML = `<div style="color:var(--red);padding:24px">Failed to load form: ${err.message}</div>`;
        }
    };

    async function sandboxSubmit(formEl, formId) {
        const btn = formEl.querySelector('.bbf-submit');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Testing...';

        // Collect form data
        const fd = new FormData(formEl);
        const body = {};
        fd.forEach((v, k) => {
            if (k === '_bbf_hp' || k === '_bbf_csrf') return;
            if (body[k]) {
                if (!Array.isArray(body[k])) body[k] = [body[k]];
                body[k].push(v);
            } else {
                body[k] = v;
            }
        });

        try {
            const resp = await fetch(`submit.php?form=${formId}&sandbox=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin',
            });
            const result = await resp.json();
            showResults(result);
        } catch (err) {
            showError('Network error: ' + err.message);
        }

        btn.disabled = false;
        btn.textContent = origText;
    }

    function showResults(result) {
        document.getElementById('result-placeholder').style.display = 'none';
        document.getElementById('results').classList.add('visible');

        // Validation
        const vContent = document.getElementById('validation-content');
        const passed = result.validation?.passed;
        const errors = result.validation?.errors || {};
        const fields = currentFormDef?.fields || [];

        let vHtml = `<div style="margin-bottom:8px"><span class="badge ${passed ? 'badge-pass' : 'badge-fail'}">${passed ? 'PASSED' : 'FAILED'}</span> ${result.validation?.field_count || 0} fields validated</div>`;
        vHtml += '<ul class="validation-list">';
        fields.forEach(f => {
            const name = f.name;
            const err = errors[name];
            if (err) {
                vHtml += `<li class="v-fail"><span class="v-icon">✗</span> <strong>${f.label || name}:</strong> ${escHtml(err)}</li>`;
            } else {
                vHtml += `<li class="v-pass"><span class="v-icon">✓</span> ${f.label || name}</li>`;
            }
        });
        vHtml += '</ul>';
        vContent.innerHTML = vHtml;

        // Collected Data
        document.getElementById('data-preview').textContent = JSON.stringify(result.data || {}, null, 2);

        // Store preview
        const preview = result.on_submit_preview || {};
        const storeEl = document.getElementById('store-content');
        if (preview.store) {
            storeEl.innerHTML = `<p>Storage: <strong>${preview.store.enabled ? 'enabled' : 'disabled'}</strong> (backend: <code>${escHtml(preview.store.backend)}</code>)</p>`;
        }

        // Confirm email
        const confirmSec = document.getElementById('confirm-section');
        if (preview.confirm_email) {
            confirmSec.style.display = '';
            document.getElementById('confirm-content').innerHTML = buildEmailPreview(preview.confirm_email);
        } else {
            confirmSec.style.display = 'none';
        }

        // Notify email
        const notifySec = document.getElementById('notify-section');
        if (preview.notify) {
            notifySec.style.display = '';
            document.getElementById('notify-content').innerHTML = buildEmailPreview(preview.notify);
        } else {
            notifySec.style.display = 'none';
        }

        // Webhooks
        const whSec = document.getElementById('webhook-section');
        if (preview.webhooks?.length) {
            whSec.style.display = '';
            let whHtml = '<ul style="list-style:none;padding:0">';
            preview.webhooks.forEach(url => {
                whHtml += `<li style="padding:4px 0"><code>${escHtml(url)}</code></li>`;
            });
            whHtml += '</ul><p style="font-size:0.82rem;color:var(--text-muted);margin-top:8px">Webhooks are NOT fired in sandbox mode.</p>';
            document.getElementById('webhook-content').innerHTML = whHtml;
        } else {
            whSec.style.display = 'none';
        }

        // Actions
        const actSec = document.getElementById('actions-section');
        if (preview.actions?.length) {
            actSec.style.display = '';
            let actHtml = '<ul style="list-style:none;padding:0">';
            preview.actions.forEach(a => {
                const icon = a.file_exists ? '✓' : '✗';
                const cls = a.file_exists ? 'v-pass' : 'v-fail';
                actHtml += `<li class="${cls}" style="padding:4px 0"><span class="v-icon">${icon}</span> <code>actions/${escHtml(a.type)}.php</code> ${a.file_exists ? '' : '(file not found)'}</li>`;
            });
            actHtml += '</ul><p style="font-size:0.82rem;color:var(--text-muted);margin-top:8px">Actions are NOT executed in sandbox mode.</p>';
            document.getElementById('actions-content').innerHTML = actHtml;
        } else {
            actSec.style.display = 'none';
        }

        // Redirect
        const redirSec = document.getElementById('redirect-section');
        if (preview.redirect) {
            redirSec.style.display = '';
            document.getElementById('redirect-content').innerHTML = `<p>Would redirect to: <code>${escHtml(preview.redirect)}</code></p><p style="font-size:0.82rem;color:var(--text-muted)">Redirect is NOT followed in sandbox mode.</p>`;
        } else {
            redirSec.style.display = 'none';
        }

        // Raw response
        document.getElementById('raw-response').textContent = JSON.stringify(result, null, 2);
    }

    function buildEmailPreview(email) {
        return `<div class="email-preview">
            <div class="email-header">
                <div><strong>To:</strong> ${escHtml(email.to)}</div>
                <div><strong>Subject:</strong> ${escHtml(email.subject)}</div>
                <div><strong>Template:</strong> <code>${escHtml(email.template)}</code></div>
            </div>
            <div class="email-body">${email.body_preview || '<em>No template content</em>'}</div>
        </div>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-top:4px">Email is NOT sent in sandbox mode.</p>`;
    }

    function showError(msg) {
        document.getElementById('result-placeholder').style.display = 'none';
        document.getElementById('results').classList.add('visible');
        document.getElementById('validation-content').innerHTML = `<div style="color:var(--red)">${escHtml(msg)}</div>`;
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    window.toggleJson = function() {
        const el = document.getElementById('json-preview');
        const btn = document.getElementById('json-toggle');
        if (el.style.display === 'none') {
            el.style.display = '';
            btn.textContent = 'Hide Form JSON';
        } else {
            el.style.display = 'none';
            btn.textContent = 'Show Form JSON';
        }
    };

    // Initial load
    if (currentFormId) loadForm(currentFormId);
})();
</script>
</body>
</html>
