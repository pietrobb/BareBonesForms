<?php
/**
 * BareBonesForms — Form Editor
 *
 * Visual JSON editor with live preview. A developer tool, not a drag-and-drop builder.
 * Edit form JSON directly with instant feedback — because guessing is for amateurs.
 *
 * Access: legacy administrative token required on every host.
 * DELETE THIS FILE if you don't need it in production.
 */

// ─── Bootstrap ──────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // Never leak errors to browser — log only
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing config.php. Copy config.example.php to config.php and edit it.');
}
require_once __DIR__ . '/bbf_auth.php';
require_once __DIR__ . '/bbf_versions.php';
require_once __DIR__ . '/bbf_functions.php';
$config = bbf_auth_load_config(__DIR__ . '/config.php');

// Only legacy administrative credentials can access definitions or editing.
$actions = ['', 'list', 'load', 'state', 'history', 'save', 'publish', 'rollback', 'create', 'delete', 'validate', 'preview_page'];
$action = is_string($_GET['action'] ?? '') && in_array($_GET['action'] ?? '', $actions, true) ? ($_GET['action'] ?? '') : 'invalid';
$principal = bbf_authenticate($config, true, in_array($action, ['', 'preview_page'], true));
$mutation = in_array($action, ['save', 'publish', 'rollback', 'create', 'delete'], true);
$accessBody = in_array($action, ['create', 'delete'], true)
    ? json_decode(file_get_contents('php://input'), true) : [];
$accessBody = is_array($accessBody) ? $accessBody : [];
$accessForm = bbf_auth_id($accessBody['id'] ?? ($_GET['form'] ?? null));
bbf_access_begin($config, $principal, 'editor_' . ($action ?: 'page'), $accessForm,
    [], true, $mutation);
if ($mutation && !$accessForm) editorRespond(400, ['error' => 'Invalid form ID.']); if ($accessForm && (!bbf_auth_definition_identity($config, $accessForm) || !bbf_auth_path_identity($config['forms_dir'] ?? __DIR__ . '/forms', "$accessForm.json"))) editorRespond(403, ['error' => 'Access denied.']);
if (!in_array($action, $actions, true)) {
    editorRespond(400, ['error' => 'Unknown action.']);
}
// This random CSRF value is not an authentication credential.
$editorToken = bbf_auth_csrf();
unset($accessBody);

$formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
$isReadOnly = !is_dir($formsDir) || !is_writable($formsDir);

// ─── Helper ─────────────────────────────────────────────────────
function editorRespond(int $code, array $data): void {
    bbf_access_finish($code < 400 ? (isset($data['ok']) ? 1 : count($data)) : 0, $code < 400); http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeId($raw): string {
    return bbf_auth_id($raw);
}

function checkToken(): void {
    if (!bbf_auth_csrf_valid()) {
        editorRespond(403, ['error' => 'Invalid editor token.']);
    }
}

function editorExpectedRevision(): int {
    $raw = $_SERVER['HTTP_X_BBF_REVISION'] ?? null;
    $revision = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]) : false;
    if ($revision === false || (string)$revision !== $raw) {
        editorRespond(400, ['error' => 'A valid X-BBF-Revision header is required.']);
    }
    return $revision;
}

function editorInvalidActiveRaw(array $config, string $formId): ?string {
    $paths = bbf_version_paths($config, $formId);
    if (!bbf_version_exact_child($paths['forms'], basename($paths['active']), false)) return null;
    $raw = file_get_contents($paths['active']);
    if ($raw === false) return null;
    try {
        $definition = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($definition)) return $raw;
        bbf_version_validate_definition($definition, $formId);
        return null;
    } catch (JsonException | InvalidArgumentException $error) {
        return $raw;
    }
}

function editorRepairInvalidActive(array $config, string $formId, array $definition, string $raw): ?array {
    if (array_key_exists('HTTP_X_BBF_REVISION', $_SERVER)) return null;
    $paths = bbf_version_paths($config, $formId);
    $lock = @fopen($paths['active'] . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open published definition lock.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock published definition.');
        $currentRaw = file_get_contents($paths['active']);
        if ($currentRaw === false) throw new RuntimeException('Cannot read published definition.');
        try {
            $current = json_decode($currentRaw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($current)) bbf_version_validate_definition($current, $formId);
            else throw new RuntimeException('Invalid published definition.');
            return null;
        } catch (JsonException | InvalidArgumentException | RuntimeException $error) {
            $errors = editorValidationErrors($definition);
            if ($errors) editorRespond(422, ['error' => 'Repair has validation errors.', 'errors' => $errors]);
            if (!bbf_storage_replace($paths['active'], static fn($fp) => bbf_storage_write_all($fp, $raw))) {
                throw new RuntimeException('Cannot repair published definition.');
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return ['ok' => true, 'repaired' => true] + bbf_version_state($config, $formId);
}

function editorValidationErrors($parsed): array {
    if (!is_array($parsed) || array_is_list($parsed)) {
        return [['path' => '', 'message' => 'Root must be a JSON object.']];
    }
    return array_map(static fn(string $message): array => ['path' => '', 'message' => $message],
        validateFormDefinition($parsed));
}

// ─── API Dispatcher ─────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $files = glob($formsDir . '/*.json') ?: [];
    $list = [];
    foreach ($files as $f) {
        $id = basename($f, '.json');
        if ($id === 'form.schema' || !bbf_auth_definition_identity($config, $id) || !is_file($f)) continue;
        $def = @json_decode(file_get_contents($f), true);
        $list[] = [
            'id'       => $id,
            'name'     => is_string($def['name'] ?? null) ? $def['name'] : $id,
            'fields'   => (is_array($def['fields'] ?? null) ? count($def['fields']) : 0),
            'modified' => date('c', filemtime($f)),
        ];
    }
    usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    editorRespond(200, $list);
}

if ($action === 'load') {
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    $file = $formsDir . '/' . $id . '.json';
    if (!file_exists($file)) editorRespond(404, ['error' => "Form '$id' not found."]);
    header('Content-Type: application/json; charset=utf-8');
    $definition = @file_get_contents($file); if ($definition === false) editorRespond(500, ['error' => 'Unable to load form.']); bbf_access_finish(1); echo $definition;
    exit;
}

if ($action === 'state' || $action === 'history') {
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    try {
        if ($action === 'state') editorRespond(200, bbf_version_state($config, $id));
        editorRespond(200, ['history' => bbf_version_history($config, $id)]);
    } catch (Throwable $error) {
        $invalidRaw = editorInvalidActiveRaw($config, $id);
        if ($invalidRaw !== null) {
            if ($action === 'state') editorRespond(200, ['form' => $id, 'revision' => null,
                'published_version' => '', 'draft_version' => '', 'repair_required' => true,
                'definition_raw' => $invalidRaw]);
            editorRespond(200, ['history' => []]);
        }
        editorRespond(500, ['error' => 'Unable to read form version state.']);
    }
}

if ($action === 'save') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 512000) editorRespond(400, ['error' => 'JSON too large (max 500KB).']);
    try {
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parsed) || array_is_list($parsed) || ($parsed['id'] ?? null) !== $id || !is_array($parsed['fields'] ?? null)) {
            editorRespond(400, ['error' => 'Draft identity or fields are invalid.']);
        }
        $repair = editorRepairInvalidActive($config, $id, $parsed, $raw);
        if ($repair !== null) editorRespond(200, $repair);
        $result = bbf_version_save_draft($config, $id, $parsed, editorExpectedRevision(), $principal['id']);
        editorRespond(($result['ok'] ?? false) ? 200 : 409, $result);
    } catch (JsonException | InvalidArgumentException $error) {
        editorRespond(400, ['error' => 'Invalid JSON draft.']);
    } catch (Throwable $error) {
        editorRespond(500, ['error' => 'Failed to save draft.']);
    }
}

if ($action === 'publish') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    try {
        $expected = editorExpectedRevision();
        $current = bbf_version_state($config, $id);
        if ($current['revision'] !== $expected) {
            editorRespond(409, ['ok' => false, 'reason' => 'conflict'] + $current);
        }
        $errors = editorValidationErrors($current['definition']);
        if ($errors) editorRespond(422, ['error' => 'Draft has validation errors.', 'errors' => $errors] + $current);
        $result = bbf_version_publish($config, $id, $expected, $principal['id']);
        editorRespond(($result['ok'] ?? false) ? 200 : 409, $result);
    } catch (Throwable $error) {
        editorRespond(500, ['error' => 'Failed to publish draft.']);
    }
}

if ($action === 'rollback') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    $body = json_decode(file_get_contents('php://input'), true);
    $version = is_array($body) && is_string($body['version'] ?? null) ? $body['version'] : '';
    if (!preg_match('/\Av1-[a-f0-9]{64}\z/D', $version)) editorRespond(400, ['error' => 'Invalid rollback version.']);
    try {
        $expected = editorExpectedRevision();
        $paths = bbf_version_paths($config, $id);
        bbf_version_prepare_directory($paths);
        $errors = editorValidationErrors(bbf_version_read_blob($paths, $version));
        if ($errors) editorRespond(422, ['error' => 'Historical version has validation errors.', 'errors' => $errors]);
        $result = bbf_version_rollback($config, $id, $version, $expected, $principal['id']);
        editorRespond(($result['ok'] ?? false) ? 200 : 409, $result);
    } catch (RuntimeException $error) {
        editorRespond(404, ['error' => 'Rollback version is unavailable.']);
    } catch (Throwable $error) {
        editorRespond(500, ['error' => 'Failed to roll back definition.']);
    }
}

if ($action === 'create') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $body = @json_decode(file_get_contents('php://input'), true);
    $id = sanitizeId($body['id'] ?? '');
    $name = trim($body['name'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    if (!$name) $name = $id;
    $file = $formsDir . '/' . $id . '.json';
    if (file_exists($file)) editorRespond(409, ['error' => "Form '$id' already exists."]);
    $template = json_encode([
        '$schema' => 'form.schema.json',
        'schema_version' => 1,
        'id' => $id,
        'name' => $name,
        'description' => '',
        'submit_label' => 'Submit',
        'success_message' => 'Thank you!',
        'fields' => [
            ['name' => 'name', 'type' => 'text', 'label' => 'Your Name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'rows' => 4],
        ],
        'on_submit' => ['store' => true],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($file, $template) === false) editorRespond(500, ['error' => 'Failed to create file.']);
    editorRespond(200, ['ok' => true, 'id' => $id]);
}

if ($action === 'delete') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $body = @json_decode(file_get_contents('php://input'), true);
    $id = sanitizeId($body['id'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    if ($id === 'form.schema') editorRespond(403, ['error' => 'Cannot delete form.schema.json.']);
    $file = $formsDir . '/' . $id . '.json';
    if (!file_exists($file)) editorRespond(404, ['error' => "Form '$id' not found."]);
    try {
        if (!bbf_version_delete_active($config, $id)) editorRespond(500, ['error' => 'Failed to delete file.']);
    } catch (Throwable $error) {
        editorRespond(500, ['error' => 'Failed to delete file.']);
    }
    editorRespond(200, ['ok' => true]);
}

if ($action === 'validate') {
    $raw = file_get_contents('php://input');
    try {
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $errors = editorValidationErrors($parsed);
    } catch (JsonException $error) {
        $errors = [['path' => '', 'message' => 'Invalid JSON: ' . $error->getMessage()]];
    }
    editorRespond(200, ['valid' => empty($errors), 'errors' => $errors]);
}

if ($action === 'preview_page') {
    $formId = sanitizeId($_GET['form'] ?? ''); bbf_access_finish(0);
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="bbf.css">
<style>
body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.preview-error { color: #ef4444; padding: 16px; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; }
</style>
</head><body>
<div id="bbf-preview"></div>
<script src="bbf.js"></script>
<script>
(function() {
    const container = document.getElementById('bbf-preview');
    let renderRequest = 0;
    window.addEventListener('message', async function(e) {
        if (window === window.parent || e.source !== window.parent) return;
        if (!e.data || e.data.type !== 'bbf-render') return;
        const request = ++renderRequest;
        try {
            const def = JSON.parse(e.data.json);
            if (!await BBF._prepareFormDefinition(def, () => request === renderRequest)) return;
            container.innerHTML = '';
            container.className = 'bbf-form-container';
            const formEl = BBF._buildForm(def, def.id || 'preview', BBF.baseUrl, {showTitle: true}, null, null, false);
            container.appendChild(formEl);
        } catch(ex) {
            if (request !== renderRequest) return;
            container.replaceChildren();
            const error = document.createElement('div');
            error.className = 'preview-error';
            error.textContent = ex instanceof Error ? ex.message : String(ex);
            container.appendChild(error);
        }
    });
    <?php if ($formId): ?>
    if (window === window.parent) BBF.render('<?= $formId ?>', '#bbf-preview', {showTitle: true});
    <?php endif; ?>
})();
</script>
</body></html><?php
    exit;
}

// ─── Prepare UI data ────────────────────────────────────────────
$formFiles = glob($formsDir . '/*.json') ?: [];
$formsList = [];
foreach ($formFiles as $f) {
    $id = basename($f, '.json');
    if ($id === 'form.schema' || !bbf_auth_definition_identity($config, $id) || !is_file($f)) continue;
    $def = @json_decode(file_get_contents($f), true);
    $formsList[] = ['id' => $id, 'name' => is_string($def['name'] ?? null) ? $def['name'] : $id, 'fields' => (is_array($def['fields'] ?? null) ? count($def['fields']) : 0)];
}
usort($formsList, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$selectedFormId = sanitizeId($_GET['form'] ?? '');
if (!$selectedFormId && !empty($formsList)) $selectedFormId = $formsList[0]['id'];

// ═════════════════════════════════════════════════════════════════
bbf_access_finish(count($formsList)); // HTML / CSS / JS
// ═════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BareBonesForms — Editor</title>
<style>
:root {
    --bg: #ffffff; --bg-alt: #f8f9fa; --bg-code: #1e1e2e;
    --text: #1a1a2e; --text-muted: #6b7280; --text-code: #cdd6f4;
    --border: #e5e7eb; --accent: #2563eb; --accent-light: #dbeafe;
    --green: #059669; --red: #dc2626; --orange: #d97706;
    --gutter-bg: #181825; --gutter-text: #585b70;
    --panel-bg: #ffffff; --panel-border: #e5e7eb;
    --hover: #f3f4f6; --active-bg: #dbeafe;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #1a1a2e; --bg-alt: #16162a; --bg-code: #1e1e2e;
        --text: #e2e8f0; --text-muted: #94a3b8; --text-code: #cdd6f4;
        --border: #334155; --accent: #60a5fa; --accent-light: #1e3a5f;
        --panel-bg: #1a1a2e; --panel-border: #334155;
        --hover: #1e293b; --active-bg: #1e3a5f;
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

/* ─── Header ─── */
.editor-header { display: flex; align-items: center; gap: 12px; padding: 0 16px; height: 48px; border-bottom: 1px solid var(--border); background: var(--bg-alt); flex-shrink: 0; }
.editor-header h1 { font-size: 0.95rem; font-weight: 600; white-space: nowrap; }
.editor-header h1 span { color: var(--accent); }
.header-form-name { font-size: 0.85rem; color: var(--text-muted); margin-left: 8px; }
.header-form-name .dirty { color: var(--orange); }
.header-spacer { flex: 1; }
.header-status { font-size: 0.75rem; color: var(--text-muted); }
.header-actions { display: flex; gap: 6px; align-items: center; }
.header-actions button, .header-actions select, .panel-forms-header button { padding: 5px 12px; font-size: 0.78rem; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); cursor: pointer; }
.header-actions button:hover, .panel-forms-header button:hover { background: var(--hover); }
.btn-save { background: var(--accent) !important; color: #fff !important; border-color: var(--accent) !important; }
.btn-save:hover { opacity: 0.9; }
.header-actions button:disabled, .header-actions select:disabled { opacity: 0.4; cursor: not-allowed; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

/* ─── Layout ─── */
.editor-layout { display: flex; flex: 1; overflow: hidden; }

/* ─── Left panel: form list ─── */
.panel-forms { width: 220px; flex-shrink: 0; border-right: 1px solid var(--border); display: flex; flex-direction: column; background: var(--bg-alt); }
.panel-forms-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
.form-list { flex: 1; overflow-y: auto; }
.form-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.form-item:hover { background: var(--hover); }
.form-item.active { background: var(--active-bg); }
.form-item-name { font-size: 0.82rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.form-item-id { font-size: 0.7rem; color: var(--text-muted); }
.form-item-del { opacity: 0; font-size: 0.75rem; color: var(--red); cursor: pointer; padding: 2px 6px; border-radius: 3px; flex-shrink: 0; }
.form-item:hover .form-item-del { opacity: 0.6; }
.form-item-del:hover { opacity: 1 !important; background: rgba(220,38,38,0.1); }

/* ─── Center panel: editor ─── */
.panel-editor { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.editor-toolbar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid var(--border); background: var(--bg-alt); font-size: 0.78rem; }
.editor-body { flex: 1; display: flex; overflow: hidden; background: var(--bg-code); }
.editor-gutter { width: 48px; flex-shrink: 0; background: var(--gutter-bg); color: var(--gutter-text); font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace; font-size: 0.8rem; line-height: 1.5rem; padding: 8px 0; text-align: right; overflow: hidden; user-select: none; }
.editor-gutter div { padding-right: 12px; }
.editor-gutter div.error-line { color: var(--red); font-weight: 700; }
.editor-textarea { flex: 1; border: none; outline: none; resize: none; background: var(--bg-code); color: var(--text-code); font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace; font-size: 0.8rem; line-height: 1.5rem; padding: 8px 12px; tab-size: 2; white-space: pre; overflow: auto; }
.editor-textarea::placeholder { color: var(--gutter-text); }
.error-bar { min-height: 28px; padding: 4px 12px; background: var(--bg-alt); border-top: 1px solid var(--border); font-size: 0.75rem; display: flex; align-items: center; gap: 12px; overflow-x: auto; white-space: nowrap; }
.error-bar .valid { color: var(--green); }
.error-bar .err { color: var(--red); cursor: pointer; }
.error-bar .err:hover { text-decoration: underline; }

/* ─── Snippet strip ─── */
.snippet-strip { padding: 6px 12px; background: var(--bg-alt); border-top: 1px solid var(--border); display: flex; gap: 4px; flex-wrap: wrap; }
.snippet-group { display: flex; gap: 3px; align-items: center; }
.snippet-group::before { content: attr(data-label); font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-right: 2px; }
.snippet-sep { width: 1px; height: 18px; background: var(--border); margin: 0 6px; }
.snippet-btn { padding: 2px 8px; font-size: 0.72rem; border: 1px solid var(--border); border-radius: 3px; background: var(--bg); color: var(--text); cursor: pointer; font-family: monospace; }
.snippet-btn:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ─── Right panel: preview ─── */
.panel-preview { width: 40%; flex-shrink: 0; display: flex; flex-direction: column; border-left: 1px solid var(--border); }
.preview-header { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid var(--border); background: var(--bg-alt); font-size: 0.78rem; font-weight: 600; color: var(--text-muted); }
.preview-frame { flex: 1; border: none; width: 100%; background: #fff; }

/* ─── Resize handle ─── */
.resize-handle { width: 5px; cursor: col-resize; background: var(--border); flex-shrink: 0; transition: background 0.15s; }
.resize-handle:hover, .resize-handle.active { background: var(--accent); }

/* ─── Modal ─── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
.modal-overlay.visible { display: flex; }
.modal { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 24px; width: 360px; max-width: 90vw; }
.modal h3 { font-size: 1rem; margin-bottom: 16px; }
.modal label { display: block; font-size: 0.82rem; margin-bottom: 4px; color: var(--text-muted); }
.modal input { width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); font-size: 0.85rem; margin-bottom: 12px; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
.modal-actions button { padding: 6px 16px; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 0.82rem; background: var(--bg); color: var(--text); }
.modal-actions .btn-primary { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ─── Responsive ─── */
@media (max-width: 1024px) { .panel-preview, .resize-handle { display: none; } }
@media (max-width: 768px) { .panel-forms { width: 160px; } }
</style>
</head>
<body>

<!-- ─── Header ─── -->
<header class="editor-header">
    <h1>Bare<span>Bones</span>Forms Editor</h1>
    <span class="header-form-name" id="header-form-name">—</span>
    <span class="header-spacer"></span>
    <span class="header-status" id="header-status" role="status" aria-live="polite"></span>
    <div class="header-actions">
        <button id="btn-format" title="Format JSON (Ctrl+Shift+F)">Format</button>
        <label class="sr-only" for="version-history">Version history</label>
        <select id="version-history" aria-label="Version history" disabled><option value="">History</option></select>
        <button id="btn-rollback" disabled>Roll back</button>
        <button id="btn-publish" disabled>Publish</button>
        <button id="btn-save" class="btn-save" title="Save draft (Ctrl+S)"<?= $isReadOnly ? ' disabled' : '' ?>>Save Draft</button>
    </div>
</header>

<!-- ─── Main layout ─── -->
<div class="editor-layout">

    <!-- Left: Form list -->
    <div class="panel-forms">
        <div class="panel-forms-header">
            <span>Forms</span>
            <button id="btn-new-form"<?= $isReadOnly ? ' disabled' : '' ?>>+ New</button>
        </div>
        <div class="form-list" id="form-list"></div>
    </div>

    <!-- Center: Editor -->
    <div class="panel-editor">
        <div class="editor-toolbar">
            <span id="editor-form-id" style="font-family:monospace; color:var(--text-muted);"></span>
            <span class="header-spacer"></span>
            <span id="validation-status"></span>
        </div>
        <div class="editor-body">
            <div class="editor-gutter" id="editor-gutter"></div>
            <textarea class="editor-textarea" id="editor-textarea" spellcheck="false"
                      placeholder="Select a form or create a new one..."></textarea>
        </div>
        <div class="error-bar" id="error-bar"></div>
        <div class="snippet-strip" id="snippet-strip">
            <div class="snippet-group" data-label="input">
                <button class="snippet-btn" data-type="text">text</button>
                <button class="snippet-btn" data-type="email">email</button>
                <button class="snippet-btn" data-type="tel">tel</button>
                <button class="snippet-btn" data-type="url">url</button>
                <button class="snippet-btn" data-type="number">number</button>
                <button class="snippet-btn" data-type="date">date</button>
                <button class="snippet-btn" data-type="password">password</button>
            </div>
            <div class="snippet-sep"></div>
            <div class="snippet-group" data-label="choice">
                <button class="snippet-btn" data-type="select">select</button>
                <button class="snippet-btn" data-type="radio">radio</button>
                <button class="snippet-btn" data-type="checkbox">checkbox</button>
            </div>
            <div class="snippet-sep"></div>
            <div class="snippet-group" data-label="content">
                <button class="snippet-btn" data-type="textarea">textarea</button>
                <button class="snippet-btn" data-type="rating">rating</button>
                <button class="snippet-btn" data-type="hidden">hidden</button>
            </div>
            <div class="snippet-sep"></div>
            <div class="snippet-group" data-label="structure">
                <button class="snippet-btn" data-type="section">section</button>
                <button class="snippet-btn" data-type="page_break">page_break</button>
                <button class="snippet-btn" data-type="group">group</button>
            </div>
        </div>
    </div>

    <!-- Resize handle -->
    <div class="resize-handle" id="resize-handle"></div>

    <!-- Right: Preview -->
    <div class="panel-preview" id="panel-preview">
        <div class="preview-header">
            <span>Live Preview</span>
            <button id="btn-preview-reload" style="padding:2px 8px;font-size:0.72rem;border:1px solid var(--border);border-radius:3px;background:var(--bg);color:var(--text);cursor:pointer;">Reload</button>
        </div>
        <iframe class="preview-frame" id="preview-frame" src="about:blank" sandbox="allow-scripts"></iframe>
    </div>
</div>

<!-- ─── New form modal ─── -->
<div class="modal-overlay" id="modal-new">
    <div class="modal">
        <h3>New Form</h3>
        <label for="new-form-name">Form Name</label>
        <input type="text" id="new-form-name" placeholder="Contact Form">
        <label for="new-form-id">Form ID (filename)</label>
        <input type="text" id="new-form-id" placeholder="contact-form">
        <div class="modal-actions">
            <button id="modal-cancel">Cancel</button>
            <button id="modal-create" class="btn-primary">Create</button>
        </div>
    </div>
</div>

<script>
(function() {
'use strict';

// ─── PHP-injected data ──────────────────────────────────────────
const FORMS = <?= json_encode($formsList) ?>;
const INITIAL_FORM = <?= json_encode($selectedFormId) ?>;
const TOKEN = <?= json_encode($editorToken) ?>;
const READ_ONLY = <?= json_encode($isReadOnly) ?>;

// ─── DOM refs ───────────────────────────────────────────────────
const $ = (s) => document.querySelector(s);
const textarea = $('#editor-textarea');
const gutter = $('#editor-gutter');
const formList = $('#form-list');
const iframe = $('#preview-frame');
const errorBar = $('#error-bar');
const headerName = $('#header-form-name');
const headerStatus = $('#header-status');
const editorFormId = $('#editor-form-id');
const validStatus = $('#validation-status');

// ─── State ──────────────────────────────────────────────────────
const state = {
    formId: null,
    originalJson: '',
    isDirty: false,
    revision: 0,
    formRevision: 0,
    validationRevision: 0,
    serverRevision: null,
    publishedVersion: '',
    draftVersion: '',
    history: [],
    conflicted: false,
    repairing: false,
    loading: false,
    saving: false,
    previewTimer: null,
    validateTimer: null,
    previewReady: false,
    errorLines: [],
};

// ─── Snippet library ────────────────────────────────────────────
const snippets = {
    text:       { name: "field_name", type: "text", label: "Label", placeholder: "", required: false },
    email:      { name: "email", type: "email", label: "Email", placeholder: "you@example.com", required: true },
    tel:        { name: "phone", type: "tel", label: "Phone", size: "medium" },
    url:        { name: "website", type: "url", label: "Website" },
    number:     { name: "quantity", type: "number", label: "Quantity", min: 0, max: 100, size: "small" },
    date:       { name: "date", type: "date", label: "Date", size: "medium" },
    password:   { name: "password", type: "password", label: "Password", minlength: 8 },
    select:     { name: "choice", type: "select", label: "Select One", options: [{value:"a", label:"Option A"},{value:"b", label:"Option B"}], required: false },
    radio:      { name: "choice", type: "radio", label: "Pick One", options: [{value:"a", label:"Option A"},{value:"b", label:"Option B"}], required: false },
    checkbox:   { name: "interests", type: "checkbox", label: "Select All That Apply", options: [{value:"a", label:"Option A"},{value:"b", label:"Option B"}] },
    textarea:   { name: "message", type: "textarea", label: "Message", rows: 4, maxlength: 1000 },
    rating:     { name: "rating", type: "rating", label: "Rating", required: true },
    hidden:     { name: "source", type: "hidden", value: "" },
    section:    { name: "section_1", type: "section", title: "Section Title", description: "" },
    page_break: { name: "page_2", type: "page_break" },
    group:      { name: "group_1", type: "group", title: "Group Title", fields: [{ name: "child_field", type: "text", label: "Child Field" }] },
};

// ─── API ────────────────────────────────────────────────────────
const api = {
    async list() {
        const r = await fetch('editor.php?action=list');
        return r.json();
    },
    async state(id) {
        const r = await fetch('editor.php?action=state&form=' + encodeURIComponent(id));
        const data = await r.json();
        if (!r.ok) throw new Error(data.error || 'Load failed');
        return data;
    },
    async history(id) {
        const r = await fetch('editor.php?action=history&form=' + encodeURIComponent(id));
        const data = await r.json();
        if (!r.ok) throw new Error(data.error || 'History failed');
        return data.history;
    },
    async save(id, json, revision) {
        return api.mutate('save', id, json, revision);
    },
    async publish(id, revision) {
        return api.mutate('publish', id, '', revision);
    },
    async rollback(id, version, revision) {
        return api.mutate('rollback', id, JSON.stringify({ version }), revision);
    },
    async mutate(action, id, body, revision) {
        const headers = { 'Content-Type': 'application/json', 'X-BBF-Editor-Token': TOKEN };
        if (revision !== null) headers['X-BBF-Revision'] = String(revision);
        const r = await fetch('editor.php?action=' + action + '&form=' + encodeURIComponent(id), {
            method: 'POST', body, headers,
        });
        const data = await r.json();
        if (r.status === 409) return data;
        if (!r.ok) { const error = new Error(data.error || action + ' failed'); error.data = data; throw error; }
        return data;
    },
    async create(name, id) {
        const r = await fetch('editor.php?action=create', {
            method: 'POST', body: JSON.stringify({ name, id }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Editor-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Create failed');
        return d;
    },
    async del(id) {
        const r = await fetch('editor.php?action=delete', {
            method: 'POST', body: JSON.stringify({ id }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Editor-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Delete failed');
        return d;
    },
    async validate(json) {
        const r = await fetch('editor.php?action=validate', { method: 'POST', body: json });
        return r.json();
    },
};

// ─── Form list ──────────────────────────────────────────────────
function renderFormList(forms) {
    formList.innerHTML = forms.map(f =>
        '<div class="form-item' + (f.id === state.formId ? ' active' : '') + '" data-id="' + f.id + '">'
        + '<div><div class="form-item-name">' + esc(f.name) + '</div>'
        + '<div class="form-item-id">' + esc(f.id) + ' &middot; ' + f.fields + ' fields</div></div>'
        + (READ_ONLY ? '' : '<span class="form-item-del" data-del="' + f.id + '" title="Delete">&times;</span>')
        + '</div>'
    ).join('');
}

formList.addEventListener('click', async (e) => {
    const del = e.target.closest('[data-del]');
    if (del) {
        e.stopPropagation();
        const id = del.dataset.del;
        if (!confirm('Delete form "' + id + '"? This cannot be undone.')) return;
        try {
            await api.del(id);
            const forms = await api.list();
            renderFormList(forms);
            if (state.formId === id) {
                state.formId = null;
                textarea.value = '';
                updateGutter();
                headerName.textContent = '—';
                editorFormId.textContent = '';
            }
            flash('Deleted');
        } catch(err) { flash(err.message, true); }
        return;
    }
    const item = e.target.closest('.form-item');
    if (item) loadForm(item.dataset.id);
});

// ─── Editor ─────────────────────────────────────────────────────
async function loadForm(id) {
    if (state.isDirty && !confirm('Unsaved changes. Discard?')) return;
    const formRevision = ++state.formRevision;
    const editRevision = state.revision;
    const editText = textarea.value;
    state.loading = true;
    updateSaveButton();
    try {
        const [current, history] = await Promise.all([api.state(id), api.history(id)]);
        if (state.formRevision !== formRevision) return;
        if (state.revision !== editRevision || textarea.value !== editText) {
            flash('Form load cancelled because editor text changed', true);
            return;
        }
        const json = current.repair_required ? current.definition_raw : JSON.stringify(current.definition, null, 2);
        state.formId = id;
        state.originalJson = json;
        state.isDirty = false;
        state.serverRevision = current.revision;
        state.publishedVersion = current.published_version;
        state.draftVersion = current.draft_version;
        state.history = history;
        state.conflicted = false;
        state.repairing = !!current.repair_required;
        textarea.value = json;
        updateGutter();
        updateHeader();
        updateVersionControls();
        updatePreview(true);
        validate();
        formList.querySelectorAll('.form-item').forEach(el =>
            el.classList.toggle('active', el.dataset.id === id));
    } catch(err) {
        if (state.formRevision === formRevision) flash(err.message, true);
    } finally {
        if (state.formRevision === formRevision) state.loading = false;
        updateSaveButton();
    }
}

function updateHeader() {
    try {
        const def = JSON.parse(textarea.value);
        headerName.innerHTML = esc(def.name || state.formId) + (state.isDirty ? ' <span class="dirty">*</span>' : '');
    } catch(e) {
        headerName.innerHTML = esc(state.formId || '—') + (state.isDirty ? ' <span class="dirty">*</span>' : '');
    }
    editorFormId.textContent = state.formId ? state.formId + '.json · ' +
        (state.repairing ? 'repair required' : 'revision ' + state.serverRevision) : '';
    document.title = (state.isDirty ? '* ' : '') + (state.formId || 'Editor') + ' — BBF Editor';
}

function updateVersionControls() {
    const history = $('#version-history');
    const selected = history.value;
    history.innerHTML = '<option value="">History</option>' + state.history.slice().reverse().map(event =>
        '<option value="' + esc(event.version) + '">r' + event.revision + ' · ' + esc(event.action) + ' · ' + esc(event.at) + '</option>'
    ).join('');
    if ([...history.options].some(option => option.value === selected)) history.value = selected;
    const busy = READ_ONLY || state.loading || state.saving || !state.formId;
    history.disabled = busy || state.history.length === 0;
    $('#btn-save').disabled = busy || state.conflicted;
    $('#btn-publish').disabled = busy || state.repairing || state.isDirty || state.conflicted || state.draftVersion === state.publishedVersion;
    $('#btn-rollback').disabled = busy || state.repairing || !history.value;
    textarea.readOnly = READ_ONLY || state.loading;
    textarea.setAttribute('aria-busy', state.loading || state.saving ? 'true' : 'false');
}

function markDirty() {
    state.revision++;
    state.isDirty = true;
    updateHeader();
    updateVersionControls();
    clearTimeout(state.previewTimer);
    state.previewTimer = setTimeout(() => updatePreview(false), 400);
    clearTimeout(state.validateTimer);
    state.validateTimer = setTimeout(validate, 800);
}

textarea.addEventListener('input', markDirty);
textarea.addEventListener('scroll', () => { gutter.scrollTop = textarea.scrollTop; });

textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Tab' && !e.shiftKey) {
        e.preventDefault();
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const val = textarea.value;
        textarea.value = val.substring(0, start) + '  ' + val.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + 2;
        markDirty();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveForm();
    }
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'f' || e.key === 'F')) {
        e.preventDefault();
        formatJson();
    }
});

function updateSaveButton() {
    updateVersionControls();
}

function applyServerResult(result) {
    state.serverRevision = result.revision;
    state.publishedVersion = result.published_version;
    state.draftVersion = result.draft_version;
    state.conflicted = false;
    state.repairing = false;
    updateHeader();
    updateVersionControls();
}

function handleConflict(result) {
    applyServerResult(result);
    const winner = JSON.stringify(result.definition, null, 2);
    if (confirm('Another editor saved first. Load the winning draft and discard your local text?\n\nCancel keeps your text; the next Save Draft will explicitly replace the winning draft.')) {
        textarea.value = winner;
        state.originalJson = winner;
        state.isDirty = false;
        updateGutter();
        updatePreview(false);
        validate();
        flash('Loaded winning draft', true);
    } else {
        state.originalJson = winner;
        state.isDirty = true;
        flash('Conflict acknowledged; local text is still unsaved', true);
    }
    updateHeader();
    updateVersionControls();
}

async function refreshHistory(formId, formRevision) {
    const history = await api.history(formId);
    if (state.formId === formId && state.formRevision === formRevision) {
        state.history = history;
        updateVersionControls();
    }
}

async function saveForm() {
    if (READ_ONLY || !state.formId || state.saving || state.loading || state.conflicted) return;
    const submitted = {
        text: textarea.value, formId: state.formId, serverRevision: state.serverRevision,
        revision: state.revision, formRevision: state.formRevision,
    };
    const isCurrentForm = () => state.formId === submitted.formId &&
        state.formRevision === submitted.formRevision;
    state.saving = true;
    updateSaveButton();
    try {
        const validation = await api.validate(submitted.text);
        if (!isCurrentForm()) return;
        if (!validation.valid) {
            const count = validation.errors.length;
            if (!confirm('Form has ' + count + ' validation error(s):\n\n' +
                validation.errors.map(e => (e.path ? e.path + ': ' : '') + e.message).join('\n') +
                '\n\nSave draft anyway?')) return;
        }
        const result = await api.save(submitted.formId, submitted.text, submitted.serverRevision);
        if (!isCurrentForm()) return;
        if (!result.ok) { handleConflict(result); return; }
        applyServerResult(result);
        state.originalJson = submitted.text;
        state.isDirty = state.revision !== submitted.revision || textarea.value !== submitted.text;
        updateHeader();
        flash(state.isDirty ? 'Draft snapshot saved; unsaved changes remain' :
            (result.repaired ? 'Published definition repaired' :
                (validation.valid ? 'Draft saved' : 'Draft saved with warnings')));
        const [forms] = await Promise.all([api.list(), refreshHistory(submitted.formId, submitted.formRevision)]);
        if (isCurrentForm()) renderFormList(forms);
    } catch(err) {
        if (isCurrentForm()) flash(err.message, true);
    } finally {
        state.saving = false;
        updateSaveButton();
    }
}

async function publishForm() {
    if (READ_ONLY || !state.formId || state.saving || state.loading || state.isDirty || state.conflicted) return;
    const submitted = { formId: state.formId, formRevision: state.formRevision, serverRevision: state.serverRevision };
    const isCurrentForm = () => state.formId === submitted.formId && state.formRevision === submitted.formRevision;
    state.saving = true;
    updateVersionControls();
    try {
        const result = await api.publish(submitted.formId, submitted.serverRevision);
        if (!isCurrentForm()) return;
        if (!result.ok) { handleConflict(result); return; }
        applyServerResult(result);
        await refreshHistory(submitted.formId, submitted.formRevision);
        if (isCurrentForm()) flash(result.recovery_pending ? 'Published; version state will finalize on reload' : 'Published');
    } catch (error) {
        if (isCurrentForm()) flash(error.message, true);
    } finally {
        state.saving = false;
        updateVersionControls();
    }
}

async function rollbackForm() {
    const version = $('#version-history').value;
    if (READ_ONLY || !state.formId || state.saving || state.loading || !version) return;
    if (!confirm('Publish the selected historical version as a new revision?')) return;
    const submitted = { formId: state.formId, formRevision: state.formRevision,
        serverRevision: state.serverRevision, version, revision: state.revision,
        text: textarea.value, dirty: state.isDirty };
    const isCurrentForm = () => state.formId === submitted.formId && state.formRevision === submitted.formRevision;
    state.saving = true;
    updateVersionControls();
    try {
        const result = await api.rollback(submitted.formId, submitted.version, submitted.serverRevision);
        if (!isCurrentForm()) return;
        if (!result.ok) { handleConflict(result); return; }
        const [current, history] = await Promise.all([api.state(submitted.formId), api.history(submitted.formId)]);
        if (!isCurrentForm()) return;
        applyServerResult(current);
        state.history = history;
        const json = JSON.stringify(current.definition, null, 2);
        const preserveLocal = submitted.dirty || state.revision !== submitted.revision || textarea.value !== submitted.text;
        state.originalJson = json;
        if (preserveLocal) {
            state.isDirty = textarea.value !== json;
        } else {
            textarea.value = json;
            state.isDirty = false;
            updateGutter();
        }
        updateHeader();
        updateVersionControls();
        updatePreview(false);
        validate();
        flash(result.recovery_pending ? 'Rolled back; version state will finalize on reload' : 'Rolled back');
    } catch (error) {
        if (isCurrentForm()) flash(error.message, true);
    } finally {
        state.saving = false;
        updateVersionControls();
    }
}

function formatJson() {
    try {
        const parsed = JSON.parse(textarea.value);
        textarea.value = JSON.stringify(parsed, null, 2);
        updateGutter();
        markDirty();
    } catch(e) { flash('Cannot format: ' + e.message, true); }
}

// ─── Gutter ─────────────────────────────────────────────────────
function updateGutter() {
    const lines = textarea.value.split('\n').length;
    let html = '';
    for (let i = 1; i <= lines; i++) {
        const isErr = state.errorLines.includes(i);
        html += '<div' + (isErr ? ' class="error-line"' : '') + '>' + i + '</div>';
    }
    gutter.innerHTML = html;
    gutter.scrollTop = textarea.scrollTop;
}

// ─── Validation ─────────────────────────────────────────────────
async function validate() {
    const json = textarea.value;
    const validationRevision = ++state.validationRevision;
    const formRevision = state.formRevision;
    if (!json.trim()) { errorBar.innerHTML = ''; validStatus.textContent = ''; state.errorLines = []; return; }
    try {
        const result = await api.validate(json);
        if (validationRevision !== state.validationRevision || formRevision !== state.formRevision || json !== textarea.value) return;
        state.errorLines = [];
        if (result.valid) {
            errorBar.innerHTML = '<span class="valid">&#10003; Valid JSON</span>';
            validStatus.innerHTML = '<span style="color:var(--green)">&#10003;</span>';
        } else {
            errorBar.innerHTML = result.errors.map(e =>
                '<span class="err">' + esc(e.path ? e.path + ': ' : '') + esc(e.message) + '</span>'
            ).join('');
            validStatus.innerHTML = '<span style="color:var(--red)">' + result.errors.length + ' error(s)</span>';
            // Rough line mapping: find paths in the JSON text
            result.errors.forEach(e => {
                if (e.path) {
                    const match = e.path.match(/\[(\d+)\]/);
                    if (match) {
                        // Find the Nth field in the text
                        const idx = parseInt(match[1]);
                        let count = -1, line = 0;
                        const lines = json.split('\n');
                        for (let i = 0; i < lines.length; i++) {
                            if (lines[i].includes('"name"')) count++;
                            if (count === idx) { line = i + 1; break; }
                        }
                        if (line) state.errorLines.push(line);
                    }
                }
            });
        }
        updateGutter();
    } catch(e) { /* ignore validation fetch errors */ }
}

// ─── Preview ────────────────────────────────────────────────────
function updatePreview(hardReload) {
    if (hardReload && state.formId) {
        iframe.src = 'editor.php?action=preview_page&form=' + encodeURIComponent(state.formId);
        state.previewReady = false;
        iframe.onload = () => { state.previewReady = true; sendToPreview(); };
        return;
    }
    if (state.previewReady) sendToPreview();
}

function sendToPreview() {
    try {
        iframe.contentWindow.postMessage({ type: 'bbf-render', json: textarea.value }, '*');
    } catch(e) { /* cross-origin or iframe not ready */ }
}

// ─── Snippets ───────────────────────────────────────────────────
document.getElementById('snippet-strip').addEventListener('click', (e) => {
    const btn = e.target.closest('.snippet-btn');
    if (!btn) return;
    const type = btn.dataset.type;
    const snippet = snippets[type];
    if (!snippet) return;
    const json = JSON.stringify(snippet, null, 2);
    insertAtCursor(json);
});

function insertAtCursor(text) {
    const start = textarea.selectionStart;
    const val = textarea.value;
    // Check if we need a comma before
    const before = val.substring(0, start).trimEnd();
    const needsComma = before.length > 0 && /[\]\}"0-9a-z]$/.test(before) && !before.endsWith(',') && !before.endsWith('[');
    const prefix = needsComma ? ',\n' : '';
    const insert = prefix + text;
    textarea.value = val.substring(0, start) + insert + val.substring(textarea.selectionEnd);
    textarea.selectionStart = textarea.selectionEnd = start + insert.length;
    textarea.focus();
    markDirty();
    updateGutter();
}

// ─── New form modal ─────────────────────────────────────────────
const modal = $('#modal-new');
const nameInput = $('#new-form-name');
const idInput = $('#new-form-id');

$('#btn-new-form').addEventListener('click', () => {
    if (READ_ONLY) return;
    nameInput.value = '';
    idInput.value = '';
    modal.classList.add('visible');
    nameInput.focus();
});

nameInput.addEventListener('input', () => {
    idInput.value = nameInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
});

$('#modal-cancel').addEventListener('click', () => modal.classList.remove('visible'));
modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('visible'); });

$('#modal-create').addEventListener('click', async () => {
    const name = nameInput.value.trim();
    const id = idInput.value.trim().replace(/[^a-zA-Z0-9_-]/g, '');
    if (!id) { nameInput.focus(); return; }
    try {
        await api.create(name || id, id);
        modal.classList.remove('visible');
        const forms = await api.list();
        renderFormList(forms);
        await loadForm(id);
        flash('Created');
    } catch(err) { flash(err.message, true); }
});

// ─── Resize handle ──────────────────────────────────────────────
const resizeHandle = $('#resize-handle');
const previewPanel = $('#panel-preview');
let resizing = false;

resizeHandle.addEventListener('mousedown', (e) => {
    e.preventDefault();
    resizing = true;
    resizeHandle.classList.add('active');
    document.addEventListener('mousemove', onResize);
    document.addEventListener('mouseup', stopResize);
});

function onResize(e) {
    if (!resizing) return;
    const layout = document.querySelector('.editor-layout');
    const rect = layout.getBoundingClientRect();
    const width = rect.right - e.clientX;
    const pct = Math.min(60, Math.max(20, (width / rect.width) * 100));
    previewPanel.style.width = pct + '%';
    localStorage.setItem('bbf_editor_preview_width', pct);
}

function stopResize() {
    resizing = false;
    resizeHandle.classList.remove('active');
    document.removeEventListener('mousemove', onResize);
    document.removeEventListener('mouseup', stopResize);
}

// Restore preview width
const savedWidth = localStorage.getItem('bbf_editor_preview_width');
if (savedWidth) previewPanel.style.width = savedWidth + '%';

// ─── Buttons ────────────────────────────────────────────────────
$('#btn-save').addEventListener('click', saveForm);
$('#btn-publish').addEventListener('click', publishForm);
$('#btn-rollback').addEventListener('click', rollbackForm);
$('#version-history').addEventListener('change', updateVersionControls);
$('#btn-format').addEventListener('click', formatJson);
$('#btn-preview-reload').addEventListener('click', () => updatePreview(true));

// ─── Helpers ────────────────────────────────────────────────────
function flash(msg, isError) {
    headerStatus.textContent = msg;
    headerStatus.style.color = isError ? 'var(--red)' : 'var(--green)';
    setTimeout(() => { headerStatus.textContent = ''; }, 3000);
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ─── Init ───────────────────────────────────────────────────────
renderFormList(FORMS);
if (INITIAL_FORM) loadForm(INITIAL_FORM);

// Warn on unsaved changes
window.addEventListener('beforeunload', (e) => {
    if (state.isDirty) { e.preventDefault(); e.returnValue = ''; }
});

})();
</script>
</body>
</html>
