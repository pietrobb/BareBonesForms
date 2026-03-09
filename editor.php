<?php
/**
 * BareBonesForms — Form Editor
 *
 * Visual JSON editor with live preview. A developer tool, not a drag-and-drop builder.
 * Edit form JSON directly with instant feedback — because guessing is for amateurs.
 *
 * Access: localhost = unrestricted. Remote = requires api_token.
 * DELETE THIS FILE if you don't need it in production.
 */

// ─── Bootstrap ──────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // Never leak errors to browser — log only
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing config.php. Copy config.example.php to config.php and edit it.');
}
$config = require __DIR__ . '/config.php';

// ─── Access control (session persists after first token auth) ────
session_start();
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isLocal) {
    $apiToken = $config['api_token'] ?? '';
    $provided = $_GET['token'] ?? ($_SERVER['HTTP_X_BBF_TOKEN'] ?? '');
    if ($apiToken !== '' && $provided !== '' && hash_equals($apiToken, $provided)) {
        $_SESSION['bbf_editor_auth'] = true;
    }
    if (empty($_SESSION['bbf_editor_auth'])) {
        http_response_code(403);
        die('<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;text-align:center"><h1>Access denied</h1><p>Editor requires api_token on remote servers:<br><code>editor.php?token=YOUR_API_TOKEN</code></p></body></html>');
    }
}
if (empty($_SESSION['bbf_editor_token'])) {
    $_SESSION['bbf_editor_token'] = bin2hex(random_bytes(32));
}
$editorToken = $_SESSION['bbf_editor_token'];

$formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
$isReadOnly = !is_dir($formsDir) || !is_writable($formsDir);

// ─── Helper ─────────────────────────────────────────────────────
function editorRespond(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeId(string $raw): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
}

function checkToken(): void {
    global $editorToken;
    $provided = $_SERVER['HTTP_X_BBF_EDITOR_TOKEN'] ?? '';
    if (!hash_equals($editorToken, $provided)) {
        editorRespond(403, ['error' => 'Invalid editor token.']);
    }
}

// ─── API Dispatcher ─────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $files = glob($formsDir . '/*.json') ?: [];
    $list = [];
    foreach ($files as $f) {
        $id = basename($f, '.json');
        if ($id === 'form.schema') continue;
        $def = @json_decode(file_get_contents($f), true);
        $list[] = [
            'id'       => $id,
            'name'     => $def['name'] ?? $id,
            'fields'   => count($def['fields'] ?? []),
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
    echo file_get_contents($file);
    exit;
}

if ($action === 'save') {
    checkToken();
    if ($isReadOnly) editorRespond(403, ['error' => 'Forms directory is not writable.']);
    $id = sanitizeId($_GET['form'] ?? '');
    if (!$id) editorRespond(400, ['error' => 'Missing form ID.']);
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 512000) editorRespond(400, ['error' => 'JSON too large (max 500KB).']);
    $parsed = @json_decode($raw, true);
    if ($parsed === null) editorRespond(400, ['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    $file = $formsDir . '/' . $id . '.json';
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $raw) === false) editorRespond(500, ['error' => 'Failed to write file.']);
    rename($tmp, $file);
    editorRespond(200, ['ok' => true]);
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
    unlink($file);
    editorRespond(200, ['ok' => true]);
}

if ($action === 'validate') {
    $raw = file_get_contents('php://input');
    $errors = [];
    $parsed = @json_decode($raw, true);
    if ($parsed === null) {
        editorRespond(200, ['valid' => false, 'errors' => [['path' => '', 'message' => 'Invalid JSON: ' . json_last_error_msg()]]]);
    }
    if (!is_array($parsed) || isset($parsed[0])) $errors[] = ['path' => '', 'message' => 'Root must be a JSON object.'];
    if (($parsed['schema_version'] ?? null) !== 1) $errors[] = ['path' => 'schema_version', 'message' => 'Must be 1.'];
    if (empty($parsed['id']) || !preg_match('/^[a-zA-Z0-9_-]+$/', $parsed['id'])) $errors[] = ['path' => 'id', 'message' => 'Required. Only a-z, 0-9, _ and -.'];
    if (empty($parsed['fields']) || !is_array($parsed['fields'])) $errors[] = ['path' => 'fields', 'message' => 'Required non-empty array.'];
    $validTypes = ['text','email','tel','url','number','date','textarea','select','radio','checkbox','hidden','password','section','page_break','rating','group'];
    $names = [];
    $checkFields = function(array $fields, string $path) use (&$checkFields, &$errors, &$names, $validTypes) {
        foreach ($fields as $i => $f) {
            $fp = "$path[$i]";
            if (empty($f['name'])) { $errors[] = ['path' => $fp, 'message' => 'Missing name.']; continue; }
            $type = $f['type'] ?? 'text';
            if (!in_array($type, $validTypes, true)) $errors[] = ['path' => "$fp.type", 'message' => "Unknown type: $type"];
            if (in_array($f['name'], $names, true)) $errors[] = ['path' => "$fp.name", 'message' => "Duplicate name: {$f['name']}"];
            $names[] = $f['name'];
            if (in_array($type, ['select','radio','checkbox']) && empty($f['options'])) $errors[] = ['path' => "$fp.options", 'message' => "$type requires options."];
            if ($type === 'group' && !empty($f['fields'])) $checkFields($f['fields'], "$fp.fields");
        }
    };
    if (!empty($parsed['fields']) && is_array($parsed['fields'])) $checkFields($parsed['fields'], 'fields');
    editorRespond(200, ['valid' => empty($errors), 'errors' => $errors]);
}

if ($action === 'preview_page') {
    $formId = sanitizeId($_GET['form'] ?? '');
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
    window.addEventListener('message', function(e) {
        if (e.origin !== window.location.origin) return;
        if (!e.data || e.data.type !== 'bbf-render') return;
        try {
            const def = JSON.parse(e.data.json);
            container.innerHTML = '';
            container.className = 'bbf-form-container';
            const formEl = BBF._buildForm(def, def.id || 'preview', BBF.baseUrl, {showTitle: true}, null, null, false);
            container.appendChild(formEl);
        } catch(ex) {
            container.innerHTML = '<div class="preview-error">' + ex.message + '</div>';
        }
    });
    <?php if ($formId): ?>
    BBF.render('<?= $formId ?>', '#bbf-preview', {showTitle: true});
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
    if ($id === 'form.schema') continue;
    $def = @json_decode(file_get_contents($f), true);
    $formsList[] = ['id' => $id, 'name' => $def['name'] ?? $id, 'fields' => count($def['fields'] ?? [])];
}
usort($formsList, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$selectedFormId = sanitizeId($_GET['form'] ?? '');
if (!$selectedFormId && !empty($formsList)) $selectedFormId = $formsList[0]['id'];

// ═════════════════════════════════════════════════════════════════
// HTML / CSS / JS
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
.header-actions { display: flex; gap: 6px; }
.header-actions button, .panel-forms-header button { padding: 5px 12px; font-size: 0.78rem; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); cursor: pointer; }
.header-actions button:hover, .panel-forms-header button:hover { background: var(--hover); }
.btn-save { background: var(--accent) !important; color: #fff !important; border-color: var(--accent) !important; }
.btn-save:hover { opacity: 0.9; }
.btn-save:disabled { opacity: 0.4; cursor: not-allowed; }

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
    <span class="header-status" id="header-status"></span>
    <div class="header-actions">
        <button id="btn-format" title="Format JSON (Ctrl+Shift+F)">Format</button>
        <button id="btn-save" class="btn-save" title="Save (Ctrl+S)"<?= $isReadOnly ? ' disabled' : '' ?>>Save</button>
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
        <iframe class="preview-frame" id="preview-frame" src="about:blank" sandbox="allow-scripts allow-same-origin"></iframe>
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
    async load(id) {
        const r = await fetch('editor.php?action=load&form=' + encodeURIComponent(id));
        if (!r.ok) throw new Error((await r.json()).error || 'Load failed');
        return r.text();
    },
    async save(id, json) {
        const r = await fetch('editor.php?action=save&form=' + encodeURIComponent(id), {
            method: 'POST', body: json,
            headers: { 'Content-Type': 'application/json', 'X-BBF-Editor-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Save failed');
        return d;
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
    try {
        const json = await api.load(id);
        state.formId = id;
        state.originalJson = json;
        state.isDirty = false;
        textarea.value = json;
        updateGutter();
        updateHeader();
        updatePreview(true);
        validate();
        formList.querySelectorAll('.form-item').forEach(el =>
            el.classList.toggle('active', el.dataset.id === id));
    } catch(err) { flash(err.message, true); }
}

function updateHeader() {
    try {
        const def = JSON.parse(textarea.value);
        headerName.innerHTML = esc(def.name || state.formId) + (state.isDirty ? ' <span class="dirty">*</span>' : '');
    } catch(e) {
        headerName.innerHTML = esc(state.formId || '—') + (state.isDirty ? ' <span class="dirty">*</span>' : '');
    }
    editorFormId.textContent = state.formId ? state.formId + '.json' : '';
    document.title = (state.isDirty ? '* ' : '') + (state.formId || 'Editor') + ' — BBF Editor';
}

function markDirty() {
    state.isDirty = true;
    updateHeader();
    clearTimeout(state.previewTimer);
    state.previewTimer = setTimeout(() => updatePreview(false), 400);
    clearTimeout(state.validateTimer);
    state.validateTimer = setTimeout(validate, 800);
}

textarea.addEventListener('input', markDirty);
textarea.addEventListener('scroll', () => { gutter.scrollTop = textarea.scrollTop; });

textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Tab') {
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

async function saveForm() {
    if (READ_ONLY || !state.formId) return;
    try {
        await api.save(state.formId, textarea.value);
        state.originalJson = textarea.value;
        state.isDirty = false;
        updateHeader();
        flash('Saved');
        // Refresh list in case name changed
        renderFormList(await api.list());
    } catch(err) { flash(err.message, true); }
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
    if (!json.trim()) { errorBar.innerHTML = ''; validStatus.textContent = ''; state.errorLines = []; return; }
    try {
        const result = await api.validate(json);
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
        iframe.contentWindow.postMessage({ type: 'bbf-render', json: textarea.value }, location.origin);
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
