<?php
/**
 * BareBonesForms — Submissions Viewer
 *
 * Beautiful dashboard for browsing and managing form submissions.
 * Supports all storage backends: file, SQLite, MySQL, CSV.
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
        $_SESSION['bbf_viewer_auth'] = true;
    }
    if (empty($_SESSION['bbf_viewer_auth'])) {
        http_response_code(403);
        die('<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;text-align:center"><h1>Access denied</h1><p>Viewer requires api_token on remote servers:<br><code>viewer.php?token=YOUR_API_TOKEN</code></p></body></html>');
    }
}
if (empty($_SESSION['bbf_viewer_token'])) {
    $_SESSION['bbf_viewer_token'] = bin2hex(random_bytes(32));
}
$viewerToken = $_SESSION['bbf_viewer_token'];

$formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
$subsDir  = $config['submissions_dir'] ?? __DIR__ . '/submissions';
$storage  = $config['storage'] ?? 'file';
$canDelete = ($storage !== 'csv');

// ─── Helpers ─────────────────────────────────────────────────────
function viewerRespond(int $code, $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeId(string $raw): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
}

function checkViewerToken(): void {
    global $viewerToken;
    $provided = $_SERVER['HTTP_X_BBF_VIEWER_TOKEN'] ?? '';
    if (!hash_equals($viewerToken, $provided)) {
        viewerRespond(403, ['error' => 'Invalid viewer token.']);
    }
}

function getDbConnection(array $config): ?PDO {
    try {
        $s = $config['storage'] ?? 'file';
        if ($s === 'mysql') {
            $db = $config['mysql'];
            return new PDO("mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}", $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        if ($s === 'sqlite') {
            $dbFile = $config['sqlite']['path'] ?? ($config['submissions_dir'] ?? __DIR__ . '/submissions') . '/bbf.sqlite';
            if (!file_exists($dbFile)) return null;
            return new PDO("sqlite:$dbFile", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    } catch (PDOException $e) { error_log("BareBonesForms viewer DB: " . $e->getMessage()); }
    return null;
}

function dbRowToSub(array $row): array {
    return [
        'id'   => $row['id'],
        'form' => $row['form_id'],
        'data' => json_decode($row['data'], true) ?: [],
        'meta' => json_decode($row['meta'], true) ?: [],
    ];
}

// ─── Storage: Count ──────────────────────────────────────────────
function countSubs(string $formId, array $config, ?string $since = null): int {
    $s = $config['storage'] ?? 'file';
    if ($s === 'file') return countSubsFile($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $since);
    if ($s === 'csv')  return countSubsCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $since);
    $pdo = getDbConnection($config);
    return $pdo ? countSubsDb($pdo, $formId, $since) : 0;
}

function countSubsFile(string $formId, string $dir, ?string $since): int {
    $d = "$dir/$formId";
    if (!is_dir($d)) return 0;
    $files = glob("$d/bbf_*.json") ?: [];
    if (!$since) return count($files);
    $ts = strtotime($since);
    $n = 0;
    foreach ($files as $f) { if (filemtime($f) >= $ts) $n++; }
    return $n;
}

function countSubsCsv(string $formId, string $dir, ?string $since): int {
    $file = "$dir/$formId.csv";
    if (!file_exists($file)) return 0;
    $fp = fopen($file, 'r');
    if (!$fp) return 0;
    $headers = fgetcsv($fp);
    if (!$headers) { fclose($fp); return 0; }
    if (!$since) {
        $n = 0;
        while (fgetcsv($fp) !== false) $n++;
        fclose($fp);
        return $n;
    }
    $si = array_search('_submitted', $headers);
    $n = 0;
    while (($row = fgetcsv($fp)) !== false) {
        if ($si !== false && ($row[$si] ?? '') >= $since) $n++;
    }
    fclose($fp);
    return $n;
}

function countSubsDb(PDO $pdo, string $formId, ?string $since): int {
    $sql = "SELECT COUNT(*) FROM bbf_submissions WHERE form_id = ?";
    $params = [$formId];
    if ($since) { $sql .= " AND created_at >= ?"; $params[] = $since; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ─── Storage: Load page ──────────────────────────────────────────
function loadSubsPage(string $formId, array $config, int $limit, int $offset, ?string $from, ?string $to, ?int &$total): array {
    $s = $config['storage'] ?? 'file';
    if ($s === 'file') return loadPageFile($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total);
    if ($s === 'csv')  return loadPageCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total);
    $pdo = getDbConnection($config);
    if (!$pdo) { $total = 0; return []; }
    return loadPageDb($pdo, $formId, $limit, $offset, $from, $to, $total);
}

function loadPageFile(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total): array {
    $d = "$dir/$formId";
    if (!is_dir($d)) { $total = 0; return []; }
    $files = glob("$d/bbf_*.json") ?: [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $results = [];
    foreach ($files as $f) {
        $sub = @json_decode(file_get_contents($f), true);
        if (!$sub) continue;
        $submitted = $sub['meta']['submitted'] ?? '';
        if ($from && $submitted < $from) continue;
        if ($to && $submitted > $to . 'T23:59:59') continue;
        $results[] = $sub;
    }
    $total = count($results);
    return array_slice($results, $offset, $limit);
}

function loadPageCsv(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total): array {
    $file = "$dir/$formId.csv";
    if (!file_exists($file)) { $total = 0; return []; }
    $fp = fopen($file, 'r');
    if (!$fp) { $total = 0; return []; }
    $headers = fgetcsv($fp);
    if (!$headers) { fclose($fp); $total = 0; return []; }
    $metaCols = ['_id', '_submitted', '_ip', '_user_agent'];
    $dataCols = array_values(array_diff($headers, $metaCols));
    $results = [];
    while (($row = fgetcsv($fp)) !== false) {
        $mapped = @array_combine($headers, array_pad($row, count($headers), ''));
        if ($mapped === false) continue;
        $submitted = $mapped['_submitted'] ?? '';
        if ($from && $submitted < $from) continue;
        if ($to && $submitted > $to . 'T23:59:59') continue;
        $data = [];
        foreach ($dataCols as $col) $data[$col] = $mapped[$col] ?? '';
        $results[] = [
            'id'   => $mapped['_id'] ?? '',
            'form' => $formId,
            'data' => $data,
            'meta' => ['submitted' => $submitted, 'ip' => $mapped['_ip'] ?? '', 'user_agent' => $mapped['_user_agent'] ?? ''],
        ];
    }
    fclose($fp);
    $results = array_reverse($results);
    $total = count($results);
    return array_slice($results, $offset, $limit);
}

function loadPageDb(PDO $pdo, string $formId, int $limit, int $offset, ?string $from, ?string $to, ?int &$total): array {
    $where = ['form_id = ?'];
    $params = [$formId];
    if ($from) { $where[] = 'created_at >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bbf_submissions WHERE $w");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE $w ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($params);
    return array_map('dbRowToSub', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ─── Storage: Load one ───────────────────────────────────────────
function loadOneSub(string $formId, string $subId, array $config): ?array {
    $s = $config['storage'] ?? 'file';
    $subId = sanitizeId($subId);
    if (!$subId) return null;
    if ($s === 'file') {
        $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . "/$formId/$subId.json";
        return file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    }
    if ($s === 'csv') {
        $total = null;
        $results = loadPageCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', 10000, 0, null, null, $total);
        foreach ($results as $r) { if ($r['id'] === $subId) return $r; }
        return null;
    }
    $pdo = getDbConnection($config);
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND form_id = ?");
    $stmt->execute([$subId, $formId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? dbRowToSub($row) : null;
}

// ─── Storage: Delete ─────────────────────────────────────────────
function deleteSub(string $formId, string $subId, array $config): bool {
    $s = $config['storage'] ?? 'file';
    $subId = sanitizeId($subId);
    if (!$subId || $s === 'csv') return false;
    if ($s === 'file') {
        $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . "/$formId/$subId.json";
        return file_exists($file) && unlink($file);
    }
    $pdo = getDbConnection($config);
    if (!$pdo) return false;
    $stmt = $pdo->prepare("DELETE FROM bbf_submissions WHERE id = ? AND form_id = ?");
    $stmt->execute([$subId, $formId]);
    return $stmt->rowCount() > 0;
}

// ─── API Dispatcher ──────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'list_forms') {
    $files = glob($formsDir . '/*.json') ?: [];
    $list = [];
    foreach ($files as $f) {
        $id = basename($f, '.json');
        if ($id === 'form.schema') continue;
        $def = @json_decode(file_get_contents($f), true);
        $list[] = [
            'id'     => $id,
            'name'   => $def['name'] ?? $id,
            'fields' => count($def['fields'] ?? []),
            'count'  => countSubs($id, $config),
        ];
    }
    usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    viewerRespond(200, $list);
}

if ($action === 'submissions') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId) viewerRespond(400, ['error' => 'Missing form ID.']);
    $limit  = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $total  = null;
    $subs   = loadSubsPage($formId, $config, $limit, $offset, $from, $to, $total);
    viewerRespond(200, ['form' => $formId, 'submissions' => $subs, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

if ($action === 'detail') {
    $formId = sanitizeId($_GET['form'] ?? '');
    $subId  = sanitizeId($_GET['id'] ?? '');
    if (!$formId || !$subId) viewerRespond(400, ['error' => 'Missing form or submission ID.']);
    $sub = loadOneSub($formId, $subId, $config);
    if (!$sub) viewerRespond(404, ['error' => 'Submission not found.']);
    $defFile = $formsDir . '/' . $formId . '.json';
    $formDef = file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null;
    viewerRespond(200, ['submission' => $sub, 'form_def' => $formDef]);
}

if ($action === 'stats') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId) viewerRespond(400, ['error' => 'Missing form ID.']);
    $now = new DateTime();
    $todayStart = $now->format('Y-m-d') . 'T00:00:00';
    $weekStart  = (clone $now)->modify('-7 days')->format('Y-m-d') . 'T00:00:00';
    $monthStart = (clone $now)->modify('-30 days')->format('Y-m-d') . 'T00:00:00';
    viewerRespond(200, [
        'total'      => countSubs($formId, $config),
        'today'      => countSubs($formId, $config, $todayStart),
        'this_week'  => countSubs($formId, $config, $weekStart),
        'this_month' => countSubs($formId, $config, $monthStart),
    ]);
}

if ($action === 'delete') {
    checkViewerToken();
    if (!$canDelete) viewerRespond(400, ['error' => 'Delete not supported for CSV storage.']);
    $body   = @json_decode(file_get_contents('php://input'), true);
    $formId = sanitizeId($body['form'] ?? '');
    $subId  = sanitizeId($body['id'] ?? '');
    if (!$formId || !$subId) viewerRespond(400, ['error' => 'Missing form or submission ID.']);
    $ok = deleteSub($formId, $subId, $config);
    if (!$ok) viewerRespond(404, ['error' => 'Submission not found or already deleted.']);
    viewerRespond(200, ['ok' => true]);
}

if ($action === 'export') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId) viewerRespond(400, ['error' => 'Missing form ID.']);
    $exportFrom = $_GET['from'] ?? null;
    $exportTo   = $_GET['to'] ?? null;
    $exportLimit = 100000;
    $last = $_GET['last'] ?? '';
    if ($last !== '' && $exportFrom === null) {
        if (preg_match('/^(\d+)d$/i', $last, $m))      $exportFrom = date('Y-m-d', strtotime("-{$m[1]} days"));
        elseif (preg_match('/^(\d+)h$/i', $last, $m))   $exportFrom = date('c', strtotime("-{$m[1]} hours"));
        elseif (preg_match('/^(\d+)w$/i', $last, $m))    $exportFrom = date('Y-m-d', strtotime("-{$m[1]} weeks"));
        elseif (preg_match('/^(\d+)m$/i', $last, $m))    $exportFrom = date('Y-m-d', strtotime("-{$m[1]} months"));
        elseif (preg_match('/^(\d+)$/', $last, $m))       $exportLimit = max(1, min(100000, intval($m[1])));
    }
    $total = null;
    $subs = loadSubsPage($formId, $config, $exportLimit, 0, $exportFrom, $exportTo, $total);
    $defFile = $formsDir . '/' . $formId . '.json';
    $def = file_exists($defFile) ? @json_decode(file_get_contents($defFile), true) : null;
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$formId}_submissions.csv");
    if (empty($subs)) { echo "No submissions\n"; exit; }
    $out = fopen('php://output', 'w');
    $fieldKeys = ($def && !empty($def['fields'])) ? array_column($def['fields'], 'name') : array_keys($subs[0]['data'] ?? []);
    fputcsv($out, array_merge(['id', 'submitted'], $fieldKeys));
    foreach ($subs as $sub) {
        $row = [$sub['id'], $sub['meta']['submitted'] ?? ''];
        foreach ($fieldKeys as $key) {
            $val = $sub['data'][$key] ?? '';
            $val = is_array($val) ? implode(', ', $val) : $val;
            if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) $val = "'" . $val;
            $row[] = $val;
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ─── Prepare UI data ────────────────────────────────────────────
$formFiles = glob($formsDir . '/*.json') ?: [];
$formsList = [];
foreach ($formFiles as $f) {
    $id = basename($f, '.json');
    if ($id === 'form.schema') continue;
    $def = @json_decode(file_get_contents($f), true);
    $formsList[] = ['id' => $id, 'name' => $def['name'] ?? $id, 'count' => countSubs($id, $config)];
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
<title>BareBonesForms — Viewer</title>
<style>
:root {
    --bg: #f8fafc; --bg-surface: #ffffff; --bg-alt: #f1f5f9;
    --text: #0f172a; --text-muted: #64748b; --text-light: #94a3b8;
    --border: #e2e8f0; --border-light: #f1f5f9;
    --accent: #2563eb; --accent-light: #dbeafe; --accent-text: #1d4ed8;
    --green: #059669; --green-bg: #ecfdf5; --green-text: #065f46;
    --amber: #d97706; --amber-bg: #fffbeb; --amber-text: #92400e;
    --violet: #7c3aed; --violet-bg: #f5f3ff; --violet-text: #5b21b6;
    --red: #dc2626; --red-bg: #fef2f2;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.06);
    --radius: 8px;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0f172a; --bg-surface: #1e293b; --bg-alt: #1e293b;
        --text: #e2e8f0; --text-muted: #94a3b8; --text-light: #64748b;
        --border: #334155; --border-light: #1e293b;
        --accent: #60a5fa; --accent-light: #1e3a5f; --accent-text: #93bbfd;
        --green: #34d399; --green-bg: #064e3b; --green-text: #6ee7b7;
        --amber: #fbbf24; --amber-bg: #78350f; --amber-text: #fcd34d;
        --violet: #a78bfa; --violet-bg: #4c1d95; --violet-text: #c4b5fd;
        --red: #f87171; --red-bg: #450a0a;
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.2);
        --shadow: 0 1px 3px rgba(0,0,0,0.3);
        --shadow-md: 0 4px 6px rgba(0,0,0,0.3);
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

/* ─── Header ─── */
.viewer-header { display: flex; align-items: center; gap: 16px; padding: 0 20px; height: 52px; background: var(--bg-surface); border-bottom: 1px solid var(--border); box-shadow: var(--shadow-sm); flex-shrink: 0; z-index: 10; }
.viewer-header h1 { font-size: 0.95rem; font-weight: 600; white-space: nowrap; }
.viewer-header h1 span { color: var(--accent); }
.header-spacer { flex: 1; }
.header-status { font-size: 0.78rem; color: var(--text-muted); }

/* ─── Layout ─── */
.viewer-layout { display: flex; flex: 1; overflow: hidden; }

/* ─── Left panel: forms ─── */
.panel-forms { width: 240px; flex-shrink: 0; border-right: 1px solid var(--border); display: flex; flex-direction: column; background: var(--bg-surface); }
.panel-forms-header { padding: 14px 16px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.form-list { flex: 1; overflow-y: auto; padding: 6px 0; }
.form-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; cursor: pointer; border-left: 3px solid transparent; transition: all 0.15s; }
.form-item:hover { background: var(--bg-alt); }
.form-item.active { background: var(--accent-light); border-left-color: var(--accent); }
.form-item-info { min-width: 0; }
.form-item-name { font-size: 0.84rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.form-item-id { font-size: 0.7rem; color: var(--text-muted); margin-top: 1px; }
.form-item-count { flex-shrink: 0; min-width: 28px; height: 22px; padding: 0 8px; border-radius: 11px; background: var(--bg-alt); color: var(--text-muted); font-size: 0.72rem; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.form-item.active .form-item-count { background: var(--accent); color: #fff; }

/* ─── Main panel ─── */
.panel-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

/* ─── Stats bar ─── */
.stats-bar { display: flex; gap: 12px; padding: 16px 20px; flex-shrink: 0; }
.stat-card { flex: 1; padding: 14px 16px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--bg-surface); position: relative; overflow: hidden; }
.stat-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
.stat-total::before { background: var(--accent); }
.stat-today::before { background: var(--green); }
.stat-week::before { background: var(--amber); }
.stat-month::before { background: var(--violet); }
.stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1; }
.stat-total .stat-value { color: var(--accent-text); }
.stat-today .stat-value { color: var(--green); }
.stat-week .stat-value { color: var(--amber); }
.stat-month .stat-value { color: var(--violet); }
.stat-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }

/* ─── Toolbar ─── */
.toolbar { display: flex; align-items: center; gap: 8px; padding: 0 20px 12px; flex-shrink: 0; flex-wrap: wrap; }
.toolbar input[type="text"], .toolbar input[type="date"] { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.82rem; outline: none; }
.toolbar input[type="text"]:focus, .toolbar input[type="date"]:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-light); }
.toolbar input[type="text"] { width: 200px; }
.toolbar input[type="date"] { width: 140px; }
.toolbar-sep { font-size: 0.78rem; color: var(--text-muted); }
.toolbar-spacer { flex: 1; }
.toolbar-count { font-size: 0.78rem; color: var(--text-muted); }
.toolbar-btn { padding: 6px 14px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.8rem; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
.toolbar-btn:hover { background: var(--bg-alt); border-color: var(--accent); }
.toolbar-btn.btn-accent { background: var(--accent); color: #fff; border-color: var(--accent); }
.toolbar-btn.btn-accent:hover { opacity: 0.9; }

/* ─── Content ─── */
.content { flex: 1; overflow-y: auto; padding: 0 20px 20px; }

/* ─── Submission cards ─── */
.sub-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; margin-bottom: 8px; cursor: pointer; transition: all 0.15s; border-left: 3px solid var(--accent); }
.sub-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.sub-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.sub-time { font-size: 0.78rem; font-weight: 500; color: var(--text-muted); }
.sub-id { font-size: 0.7rem; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--text-light); }
.sub-preview { display: flex; flex-wrap: wrap; gap: 4px 16px; }
.sub-field { font-size: 0.82rem; color: var(--text); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sub-field strong { font-weight: 500; color: var(--text-muted); font-size: 0.75rem; }

/* ─── Detail view ─── */
.detail-view { animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.detail-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.btn-back { padding: 6px 14px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.btn-back:hover { background: var(--bg-alt); }
.detail-title { flex: 1; }
.detail-id { font-size: 0.82rem; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--text-muted); }
.detail-date { font-size: 0.78rem; color: var(--text-light); display: block; margin-top: 2px; }
.btn-delete { padding: 6px 14px; border: 1px solid var(--red); border-radius: 6px; background: transparent; color: var(--red); font-size: 0.82rem; cursor: pointer; }
.btn-delete:hover { background: var(--red-bg); }

.detail-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
.detail-section-title { padding: 10px 16px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); background: var(--bg-alt); border-bottom: 1px solid var(--border); }
.detail-field { padding: 12px 16px; border-bottom: 1px solid var(--border-light); }
.detail-field:last-child { border-bottom: none; }
.detail-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: var(--text-muted); margin-bottom: 4px; }
.detail-value { font-size: 0.88rem; line-height: 1.5; word-break: break-word; }
.detail-value a { color: var(--accent); text-decoration: none; }
.detail-value a:hover { text-decoration: underline; }
.detail-value .pre-wrap { white-space: pre-wrap; }
.detail-value .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; background: var(--accent-light); color: var(--accent-text); font-size: 0.78rem; margin: 2px 2px 2px 0; }
.detail-value .stars { color: var(--amber); font-size: 1.1rem; letter-spacing: 1px; }
.detail-value .empty-val { color: var(--text-light); font-style: italic; }

.meta-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
.meta-card h4 { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 8px; }
.meta-row { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: flex; gap: 8px; }
.meta-row strong { color: var(--text); font-weight: 500; min-width: 80px; }

/* ─── Pagination ─── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 12px 20px; flex-shrink: 0; border-top: 1px solid var(--border); }
.page-btn { min-width: 32px; height: 32px; padding: 0 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.page-btn:hover { background: var(--bg-alt); border-color: var(--accent); }
.page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); pointer-events: none; }
.page-dots { color: var(--text-muted); font-size: 0.8rem; padding: 0 4px; }

/* ─── Empty state ─── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state-icon { font-size: 3rem; margin-bottom: 12px; opacity: 0.3; }
.empty-state h3 { font-size: 1rem; font-weight: 600; margin-bottom: 6px; color: var(--text); }
.empty-state p { font-size: 0.85rem; }

/* ─── Loading ─── */
.loading { text-align: center; padding: 40px; color: var(--text-muted); font-size: 0.85rem; }

/* ─── Responsive ─── */
@media (max-width: 900px) {
    .stats-bar { flex-wrap: wrap; }
    .stat-card { min-width: calc(50% - 6px); }
}
@media (max-width: 768px) {
    .panel-forms { width: 180px; }
    .toolbar input[type="text"] { width: 140px; }
}
@media (max-width: 640px) {
    .panel-forms { display: none; }
    .stats-bar { gap: 8px; padding: 12px; }
}
</style>
</head>
<body>

<header class="viewer-header">
    <h1>Bare<span>Bones</span>Forms Viewer</h1>
    <span class="header-spacer"></span>
    <span class="header-status" id="header-status"></span>
</header>

<div class="viewer-layout">
    <div class="panel-forms">
        <div class="panel-forms-header">Forms</div>
        <div class="form-list" id="form-list"></div>
    </div>
    <div class="panel-main" id="panel-main">
        <div class="empty-state">
            <div class="empty-state-icon">&#9776;</div>
            <h3>Select a form</h3>
            <p>Choose a form from the sidebar to view its submissions.</p>
        </div>
    </div>
</div>

<script>
(function() {
'use strict';

const FORMS = <?= json_encode($formsList) ?>;
const INITIAL_FORM = <?= json_encode($selectedFormId) ?>;
const TOKEN = <?= json_encode($viewerToken) ?>;
const CAN_DELETE = <?= json_encode($canDelete) ?>;

const formListEl = document.getElementById('form-list');
const panelMain = document.getElementById('panel-main');
const headerStatus = document.getElementById('header-status');

const state = {
    formId: null,
    subs: [],
    total: 0,
    page: 1,
    perPage: 20,
    search: '',
    dateFrom: '',
    dateTo: '',
    stats: null,
    detail: null,
    formDef: null,
};

// ─── API ─────────────────────────────────────────────────────────
const api = {
    async submissions(formId, limit, offset, from, to) {
        let url = `viewer.php?action=submissions&form=${encodeURIComponent(formId)}&limit=${limit}&offset=${offset}`;
        if (from) url += `&from=${from}`;
        if (to) url += `&to=${to}`;
        const r = await fetch(url);
        if (!r.ok) throw new Error((await r.json()).error || 'Load failed');
        return r.json();
    },
    async detail(formId, subId) {
        const r = await fetch(`viewer.php?action=detail&form=${encodeURIComponent(formId)}&id=${encodeURIComponent(subId)}`);
        if (!r.ok) throw new Error((await r.json()).error || 'Load failed');
        return r.json();
    },
    async stats(formId) {
        const r = await fetch(`viewer.php?action=stats&form=${encodeURIComponent(formId)}`);
        return r.json();
    },
    async del(formId, subId) {
        const r = await fetch('viewer.php?action=delete', {
            method: 'POST',
            body: JSON.stringify({ form: formId, id: subId }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Viewer-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Delete failed');
        return d;
    },
    async listForms() {
        const r = await fetch('viewer.php?action=list_forms');
        return r.json();
    },
};

// ─── Form list ───────────────────────────────────────────────────
function renderFormList(forms) {
    formListEl.innerHTML = forms.map(f =>
        `<div class="form-item${f.id === state.formId ? ' active' : ''}" data-id="${f.id}">` +
        `<div class="form-item-info"><div class="form-item-name">${esc(f.name)}</div>` +
        `<div class="form-item-id">${esc(f.id)}</div></div>` +
        `<span class="form-item-count">${f.count}</span></div>`
    ).join('');
}

formListEl.addEventListener('click', (e) => {
    const item = e.target.closest('.form-item');
    if (item) selectForm(item.dataset.id);
});

// ─── Select form ─────────────────────────────────────────────────
async function selectForm(formId) {
    state.formId = formId;
    state.page = 1;
    state.detail = null;
    state.search = '';
    state.dateFrom = '';
    state.dateTo = '';
    formListEl.querySelectorAll('.form-item').forEach(el =>
        el.classList.toggle('active', el.dataset.id === formId));
    document.title = formId + ' — BBF Viewer';
    renderMainLoading();
    try {
        const [statsData, subsData] = await Promise.all([
            api.stats(formId),
            api.submissions(formId, state.perPage, 0),
        ]);
        state.stats = statsData;
        state.subs = subsData.submissions;
        state.total = subsData.total;
        renderMain();
    } catch (err) { flash(err.message, true); }
}

// ─── Render main ─────────────────────────────────────────────────
function renderMainLoading() {
    panelMain.innerHTML = '<div class="loading">Loading...</div>';
}

function renderMain() {
    let html = '';
    // Stats
    html += '<div class="stats-bar">';
    html += statCard('stat-total', state.stats?.total ?? 0, 'Total');
    html += statCard('stat-today', state.stats?.today ?? 0, 'Today');
    html += statCard('stat-week', state.stats?.this_week ?? 0, 'This week');
    html += statCard('stat-month', state.stats?.this_month ?? 0, 'This month');
    html += '</div>';
    // Toolbar
    html += '<div class="toolbar">';
    html += `<input type="text" id="search-input" placeholder="Search..." value="${esc(state.search)}">`;
    html += `<input type="date" id="filter-from" value="${esc(state.dateFrom)}">`;
    html += `<span class="toolbar-sep">to</span>`;
    html += `<input type="date" id="filter-to" value="${esc(state.dateTo)}">`;
    html += `<button class="toolbar-btn" id="btn-filter">Filter</button>`;
    html += `<span class="toolbar-spacer"></span>`;
    html += `<span class="toolbar-count" id="toolbar-count">${state.total} submission${state.total !== 1 ? 's' : ''}</span>`;
    html += `<button class="toolbar-btn btn-accent" id="btn-export">Export CSV</button>`;
    html += '</div>';
    // Cards
    html += '<div class="content" id="content">';
    html += renderCards();
    html += '</div>';
    // Pagination
    html += '<div class="pagination" id="pagination">';
    html += renderPagination();
    html += '</div>';
    panelMain.innerHTML = html;
    bindMainEvents();
}

function statCard(cls, value, label) {
    return `<div class="stat-card ${cls}"><div class="stat-value">${value}</div><div class="stat-label">${label}</div></div>`;
}

function renderCards() {
    const filtered = filterSubs(state.subs);
    if (filtered.length === 0) {
        return '<div class="empty-state"><div class="empty-state-icon">&#128203;</div>'
            + '<h3>No submissions</h3><p>No submissions match your criteria.</p></div>';
    }
    return filtered.map(sub => {
        const time = relativeTime(sub.meta?.submitted);
        const preview = Object.entries(sub.data || {}).slice(0, 3);
        return `<div class="sub-card" data-id="${esc(sub.id)}">` +
            `<div class="sub-card-header">` +
            `<span class="sub-time">${esc(time)}</span>` +
            `<span class="sub-id">${esc(sub.id)}</span></div>` +
            `<div class="sub-preview">` +
            preview.map(([k, v]) => {
                const val = Array.isArray(v) ? v.join(', ') : String(v);
                const display = val.length > 60 ? val.substring(0, 60) + '...' : val;
                return `<span class="sub-field"><strong>${esc(k)}:</strong> ${esc(display)}</span>`;
            }).join('') +
            `</div></div>`;
    }).join('');
}

function filterSubs(subs) {
    if (!state.search) return subs;
    const q = state.search.toLowerCase();
    return subs.filter(sub => {
        const text = JSON.stringify(sub.data).toLowerCase();
        return text.includes(q);
    });
}

function renderPagination() {
    const pages = Math.ceil(state.total / state.perPage);
    if (pages <= 1) return '';
    let html = '';
    if (state.page > 1) html += `<button class="page-btn" data-page="${state.page - 1}">&laquo;</button>`;
    for (let i = 1; i <= pages; i++) {
        if (pages <= 7 || i <= 2 || i > pages - 1 || Math.abs(i - state.page) <= 1) {
            html += `<button class="page-btn${i === state.page ? ' active' : ''}" data-page="${i}">${i}</button>`;
        } else if ((i === 3 && state.page > 4) || (i === pages - 1 && state.page < pages - 3)) {
            html += '<span class="page-dots">...</span>';
        }
    }
    if (state.page < pages) html += `<button class="page-btn" data-page="${state.page + 1}">&raquo;</button>`;
    return html;
}

function bindMainEvents() {
    const content = document.getElementById('content');
    const pagination = document.getElementById('pagination');
    const searchInput = document.getElementById('search-input');
    const filterFrom = document.getElementById('filter-from');
    const filterTo = document.getElementById('filter-to');

    // Card click → detail
    content?.addEventListener('click', (e) => {
        const card = e.target.closest('.sub-card');
        if (card) openDetail(card.dataset.id);
    });

    // Pagination
    pagination?.addEventListener('click', (e) => {
        const btn = e.target.closest('.page-btn');
        if (btn && btn.dataset.page) goToPage(parseInt(btn.dataset.page));
    });

    // Search (client-side filter)
    let searchTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = searchInput.value;
            content.innerHTML = renderCards();
            // Rebind card click events
            content.addEventListener('click', (e) => {
                const card = e.target.closest('.sub-card');
                if (card) openDetail(card.dataset.id);
            });
        }, 300);
    });

    // Date filter
    document.getElementById('btn-filter')?.addEventListener('click', () => {
        state.dateFrom = filterFrom?.value || '';
        state.dateTo = filterTo?.value || '';
        state.page = 1;
        loadPage();
    });

    // Export
    document.getElementById('btn-export')?.addEventListener('click', () => {
        let url = `viewer.php?action=export&form=${encodeURIComponent(state.formId)}`;
        if (state.dateFrom) url += `&from=${state.dateFrom}`;
        if (state.dateTo) url += `&to=${state.dateTo}`;
        window.location.href = url;
    });
}

// ─── Page navigation ─────────────────────────────────────────────
async function goToPage(page) {
    state.page = page;
    await loadPage();
}

async function loadPage() {
    const offset = (state.page - 1) * state.perPage;
    try {
        const data = await api.submissions(state.formId, state.perPage, offset, state.dateFrom || null, state.dateTo || null);
        state.subs = data.submissions;
        state.total = data.total;
        const content = document.getElementById('content');
        const pagination = document.getElementById('pagination');
        const count = document.getElementById('toolbar-count');
        if (content) {
            content.innerHTML = renderCards();
            content.addEventListener('click', (e) => {
                const card = e.target.closest('.sub-card');
                if (card) openDetail(card.dataset.id);
            });
        }
        if (pagination) pagination.innerHTML = renderPagination();
        if (count) count.textContent = state.total + ' submission' + (state.total !== 1 ? 's' : '');
        // Rebind pagination
        pagination?.addEventListener('click', (e) => {
            const btn = e.target.closest('.page-btn');
            if (btn && btn.dataset.page) goToPage(parseInt(btn.dataset.page));
        });
    } catch (err) { flash(err.message, true); }
}

// ─── Detail view ─────────────────────────────────────────────────
async function openDetail(subId) {
    state.detail = subId;
    const content = document.getElementById('content');
    if (content) content.innerHTML = '<div class="loading">Loading...</div>';
    try {
        const data = await api.detail(state.formId, subId);
        state.formDef = data.form_def;
        renderDetail(data.submission, data.form_def);
    } catch (err) { flash(err.message, true); }
}

function renderDetail(sub, formDef) {
    const submitted = sub.meta?.submitted || '';
    const dateStr = submitted ? new Date(submitted).toLocaleString() : '';

    let html = '<div class="detail-view">';
    // Header
    html += '<div class="detail-header">';
    html += `<button class="btn-back" id="btn-back">&larr; Back</button>`;
    html += `<div class="detail-title"><span class="detail-id">${esc(sub.id)}</span>`;
    html += `<span class="detail-date">${esc(relativeTime(submitted))} &middot; ${esc(dateStr)}</span></div>`;
    if (CAN_DELETE) html += `<button class="btn-delete" id="btn-del" data-id="${esc(sub.id)}">Delete</button>`;
    html += '</div>';

    // Fields
    const fields = formDef?.fields || [];
    const dataKeys = Object.keys(sub.data || {});
    const sections = buildDetailSections(sub.data, fields, dataKeys);

    sections.forEach(section => {
        html += '<div class="detail-card">';
        if (section.title) html += `<div class="detail-section-title">${esc(section.title)}</div>`;
        section.fields.forEach(f => {
            html += '<div class="detail-field">';
            html += `<div class="detail-label">${esc(f.label)}</div>`;
            html += `<div class="detail-value">${formatValue(f.value, f.type)}</div>`;
            html += '</div>';
        });
        html += '</div>';
    });

    // Meta
    html += '<div class="meta-card">';
    html += '<h4>Submission metadata</h4>';
    if (sub.meta?.submitted) html += `<div class="meta-row"><strong>Submitted</strong> ${esc(sub.meta.submitted)}</div>`;
    if (sub.meta?.ip) html += `<div class="meta-row"><strong>IP</strong> ${esc(sub.meta.ip)}</div>`;
    if (sub.meta?.user_agent) html += `<div class="meta-row"><strong>User agent</strong> ${esc(sub.meta.user_agent)}</div>`;
    html += '</div>';
    html += '</div>';

    const content = document.getElementById('content');
    const pagination = document.getElementById('pagination');
    if (content) content.innerHTML = html;
    if (pagination) pagination.innerHTML = '';

    // Back button
    document.getElementById('btn-back')?.addEventListener('click', () => {
        state.detail = null;
        const contentEl = document.getElementById('content');
        if (contentEl) {
            contentEl.innerHTML = renderCards();
            contentEl.addEventListener('click', (e) => {
                const card = e.target.closest('.sub-card');
                if (card) openDetail(card.dataset.id);
            });
        }
        const paginationEl = document.getElementById('pagination');
        if (paginationEl) {
            paginationEl.innerHTML = renderPagination();
            paginationEl.addEventListener('click', (e) => {
                const btn = e.target.closest('.page-btn');
                if (btn && btn.dataset.page) goToPage(parseInt(btn.dataset.page));
            });
        }
    });

    // Delete button
    document.getElementById('btn-del')?.addEventListener('click', async () => {
        if (!confirm('Delete this submission? This cannot be undone.')) return;
        try {
            await api.del(state.formId, sub.id);
            flash('Deleted');
            state.detail = null;
            // Reload
            const [statsData, subsData] = await Promise.all([
                api.stats(state.formId),
                api.submissions(state.formId, state.perPage, (state.page - 1) * state.perPage, state.dateFrom || null, state.dateTo || null),
            ]);
            state.stats = statsData;
            state.subs = subsData.submissions;
            state.total = subsData.total;
            renderMain();
            // Update sidebar count
            const forms = await api.listForms();
            renderFormList(forms);
        } catch (err) { flash(err.message, true); }
    });
}

function buildDetailSections(data, fields, dataKeys) {
    if (!fields || fields.length === 0) {
        // No form def: show all data as one section
        return [{ title: '', fields: dataKeys.map(k => ({ label: k, value: data[k], type: 'text' })) }];
    }

    const sections = [];
    let current = { title: '', fields: [] };
    const rendered = new Set();

    function processFields(fieldList) {
        for (const f of fieldList) {
            if (f.type === 'section') {
                if (current.fields.length > 0) sections.push(current);
                current = { title: f.title || f.label || f.name, fields: [] };
                continue;
            }
            if (f.type === 'page_break') continue;
            if (f.type === 'group' && f.fields) {
                processFields(f.fields);
                continue;
            }
            if (f.type === 'hidden') continue;
            const val = data[f.name];
            if (val === undefined || val === null) return;
            rendered.add(f.name);
            current.fields.push({
                label: f.label || f.name,
                value: val,
                type: f.type || 'text',
            });
        }
    }

    processFields(fields);
    if (current.fields.length > 0) sections.push(current);

    // Add any data keys not in form def
    const extra = dataKeys.filter(k => !rendered.has(k));
    if (extra.length > 0) {
        sections.push({ title: 'Other fields', fields: extra.map(k => ({ label: k, value: data[k], type: 'text' })) });
    }

    return sections;
}

// ─── Value formatting ────────────────────────────────────────────
function formatValue(value, type) {
    if (value === null || value === undefined || value === '') {
        return '<span class="empty-val">-</span>';
    }
    if (Array.isArray(value)) {
        if (type === 'checkbox') {
            return value.map(v => `<span class="tag">${esc(String(v))}</span>`).join('');
        }
        return esc(value.join(', '));
    }
    const str = String(value);
    switch (type) {
        case 'rating': {
            const n = Math.min(5, Math.max(0, parseInt(str) || 0));
            return `<span class="stars">${'\u2605'.repeat(n)}${'\u2606'.repeat(5 - n)}</span>`;
        }
        case 'email':
            return `<a href="mailto:${esc(str)}">${esc(str)}</a>`;
        case 'url':
            return `<a href="${esc(str)}" target="_blank" rel="noopener">${esc(str)}</a>`;
        case 'textarea':
            return `<div class="pre-wrap">${esc(str)}</div>`;
        case 'checkbox':
            return `<span class="tag">${esc(str)}</span>`;
        default:
            return esc(str);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────
function esc(s) {
    if (s === null || s === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

function relativeTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const now = new Date();
    const diff = (now - date) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 172800) return 'yesterday';
    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
    return date.toLocaleDateString();
}

function flash(msg, isError) {
    headerStatus.textContent = msg;
    headerStatus.style.color = isError ? 'var(--red)' : 'var(--green)';
    setTimeout(() => { headerStatus.textContent = ''; }, 3000);
}

// ─── Keyboard ────────────────────────────────────────────────────
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.detail) {
        document.getElementById('btn-back')?.click();
    }
});

// ─── Init ────────────────────────────────────────────────────────
renderFormList(FORMS);
if (INITIAL_FORM) selectForm(INITIAL_FORM);

})();
</script>
</body>
</html>
