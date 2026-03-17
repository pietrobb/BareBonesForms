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
ini_set('display_errors', '0');
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing config.php. Copy config.example.php to config.php and edit it.');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/bbf_functions.php';

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

// ─── Branding ───────────────────────────────────────────────────
$siteName = $config['viewer']['site_name'] ?? 'BareBonesForms';
$logoUrl  = $config['viewer']['logo_url'] ?? '';
$viewerLang = $_GET['lang'] ?? ($config['viewer']['lang'] ?? $config['lang'] ?? 'en');

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

function matchesSearch(array $data, string $q): bool {
    $ql = mb_strtolower($q);
    foreach ($data as $v) {
        $str = is_array($v) ? implode(' ', $v) : (string)$v;
        if (mb_stripos($str, $ql) !== false) return true;
    }
    return false;
}

function buildPhpLabelMap(?array $formDef): array {
    $map = [];
    if (!$formDef || empty($formDef['fields'])) return $map;
    $walk = function(array $fields) use (&$map, &$walk) {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'group' && !empty($f['fields'])) { $walk($f['fields']); continue; }
            if (!empty($f['name']) && !empty($f['label'])) $map[$f['name']] = $f['label'];
        }
    };
    $walk($formDef['fields']);
    return $map;
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
function loadSubsPage(string $formId, array $config, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    $s = $config['storage'] ?? 'file';
    if ($s === 'file') return loadPageFile($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total, $q);
    if ($s === 'csv')  return loadPageCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total, $q);
    $pdo = getDbConnection($config);
    if (!$pdo) { $total = 0; return []; }
    return loadPageDb($pdo, $formId, $limit, $offset, $from, $to, $total, $q);
}

function loadPageFile(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
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
        if ($q && !matchesSearch($sub['data'] ?? [], $q)) continue;
        $results[] = $sub;
    }
    $total = count($results);
    return array_slice($results, $offset, $limit);
}

function loadPageCsv(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
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
        $mapped = @array_combine($headers, array_slice(array_pad($row, count($headers), ''), 0, count($headers)));
        if ($mapped === false) continue;
        $submitted = $mapped['_submitted'] ?? '';
        if ($from && $submitted < $from) continue;
        if ($to && $submitted > $to . 'T23:59:59') continue;
        $data = [];
        foreach ($dataCols as $col) $data[$col] = $mapped[$col] ?? '';
        $sub = [
            'id'   => $mapped['_id'] ?? '',
            'form' => $formId,
            'data' => $data,
            'meta' => ['submitted' => $submitted, 'ip' => $mapped['_ip'] ?? '', 'user_agent' => $mapped['_user_agent'] ?? ''],
        ];
        if ($q && !matchesSearch($data, $q)) continue;
        $results[] = $sub;
    }
    fclose($fp);
    $results = array_reverse($results);
    $total = count($results);
    return array_slice($results, $offset, $limit);
}

function loadPageDb(PDO $pdo, string $formId, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    $where = ['form_id = ?'];
    $params = [$formId];
    if ($from) { $where[] = 'created_at >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    if ($q) { $where[] = 'data LIKE ?'; $params[] = '%' . $q . '%'; }
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

if ($action === 'dashboard') {
    $now = new DateTime();
    $todayStart = $now->format('Y-m-d') . 'T00:00:00';
    $weekStart  = (clone $now)->modify('-7 days')->format('Y-m-d') . 'T00:00:00';
    $formFiles = glob($formsDir . '/*.json') ?: [];
    $perForm = [];
    $allRecent = [];
    $formDefs = [];
    $totalAll = 0; $todayAll = 0; $weekAll = 0;

    foreach ($formFiles as $f) {
        $id = basename($f, '.json');
        if ($id === 'form.schema') continue;
        $def = @json_decode(file_get_contents($f), true);
        $formDefs[$id] = $def;
        $total = countSubs($id, $config);
        $today = countSubs($id, $config, $todayStart);
        $week  = countSubs($id, $config, $weekStart);
        $totalAll += $total; $todayAll += $today; $weekAll += $week;
        $perForm[] = ['id' => $id, 'name' => $def['name'] ?? $id, 'total' => $total, 'today' => $today];
        $t = null;
        $subs = loadSubsPage($id, $config, 5, 0, null, null, $t);
        foreach ($subs as &$s) { $s['form_name'] = $def['name'] ?? $id; if (!isset($s['form'])) $s['form'] = $id; }
        $allRecent = array_merge($allRecent, $subs);
    }
    usort($allRecent, fn($a, $b) => strcmp($b['meta']['submitted'] ?? '', $a['meta']['submitted'] ?? ''));
    $allRecent = array_slice($allRecent, 0, 20);
    viewerRespond(200, [
        'total' => $totalAll, 'today' => $todayAll, 'this_week' => $weekAll,
        'per_form' => $perForm, 'recent' => $allRecent, 'form_defs' => $formDefs,
    ]);
}

if ($action === 'submissions') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId) viewerRespond(400, ['error' => 'Missing form ID.']);
    $limit  = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $q      = trim($_GET['q'] ?? '');
    $total  = null;
    $subs   = loadSubsPage($formId, $config, $limit, $offset, $from, $to, $total, $q ?: null);
    $defFile = $formsDir . '/' . $formId . '.json';
    $formDef = file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null;
    viewerRespond(200, ['form' => $formId, 'submissions' => $subs, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'form_def' => $formDef]);
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

if ($action === 'forward') {
    checkViewerToken();
    $body   = @json_decode(file_get_contents('php://input'), true);
    $formId = sanitizeId($body['form'] ?? '');
    $subId  = sanitizeId($body['id'] ?? '');
    $to     = $body['to'] ?? '';
    $note   = $body['note'] ?? '';
    $addresses = array_map('trim', explode(',', $to));
    $addresses = array_filter($addresses, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
    if (empty($addresses) || count($addresses) > 5) viewerRespond(400, ['error' => 'Invalid or too many recipients (max 5).']);
    $to = implode(', ', $addresses);
    if (!$formId || !$subId) viewerRespond(400, ['error' => 'Missing form or submission ID.']);
    $sub = loadOneSub($formId, $subId, $config);
    if (!$sub) viewerRespond(404, ['error' => 'Submission not found.']);
    $defFile = $formsDir . '/' . $formId . '.json';
    $formDef = file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null;
    $formName = $formDef['name'] ?? $formId;
    $labelMap = buildPhpLabelMap($formDef);
    $submitted = $sub['meta']['submitted'] ?? '';
    $h = '<!DOCTYPE html><html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:600px;margin:0 auto;padding:20px">';
    $h .= '<h2 style="color:#1e293b;border-bottom:2px solid #2563eb;padding-bottom:8px">' . htmlspecialchars($formName) . '</h2>';
    $h .= '<p style="color:#64748b;font-size:14px"><strong>ID:</strong> ' . htmlspecialchars($sub['id']) . ' &middot; <strong>Submitted:</strong> ' . htmlspecialchars($submitted) . '</p>';
    if ($note) $h .= '<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin:16px 0;font-style:italic;color:#92400e">' . nl2br(htmlspecialchars($note)) . '</div>';
    $h .= '<table style="border-collapse:collapse;width:100%;margin-top:16px">';
    foreach ($sub['data'] as $k => $v) {
        $label = $labelMap[$k] ?? $k;
        $val = is_array($v) ? implode(', ', $v) : htmlspecialchars((string)$v);
        $h .= '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;font-weight:600;background:#f8fafc;color:#475569;width:35%;font-size:13px">' . htmlspecialchars($label) . '</td>';
        $h .= '<td style="padding:10px 12px;border:1px solid #e2e8f0;font-size:14px">' . ($val ?: '<span style="color:#94a3b8">-</span>') . '</td></tr>';
    }
    $h .= '</table></body></html>';
    $subject = htmlspecialchars_decode($formName) . ' — ' . $sub['id'];
    sendEmail($to, $subject, $h, $config['mail']);
    viewerRespond(200, ['ok' => true]);
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

// ═════════════════════════════════════════════════════════════════
// HTML / CSS / JS
// ═════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($siteName) ?></title>
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
.viewer-header { display: flex; align-items: center; gap: 12px; padding: 0 20px; height: 52px; background: var(--bg-surface); border-bottom: 1px solid var(--border); box-shadow: var(--shadow-sm); flex-shrink: 0; z-index: 20; }
.header-logo { height: 28px; width: auto; object-fit: contain; }
.viewer-header h1 { font-size: 0.95rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.viewer-header h1:hover { color: var(--accent); }
.header-spacer { flex: 1; }
.header-status { font-size: 0.78rem; color: var(--text-muted); transition: opacity 0.3s; }
.btn-hamburger { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: var(--text); padding: 4px 8px; border-radius: 4px; }
.btn-hamburger:hover { background: var(--bg-alt); }

/* ─── Layout ─── */
.viewer-layout { display: flex; flex: 1; overflow: hidden; }

/* ─── Left panel: forms ─── */
.panel-forms { width: 250px; flex-shrink: 0; border-right: 1px solid var(--border); display: flex; flex-direction: column; background: var(--bg-surface); z-index: 15; }
.panel-forms-header { padding: 14px 16px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.form-list { flex: 1; overflow-y: auto; padding: 6px 0; }
.sidebar-dashboard { display: flex; align-items: center; gap: 10px; padding: 12px 16px; cursor: pointer; border-left: 3px solid transparent; transition: all 0.15s; border-bottom: 1px solid var(--border-light); }
.sidebar-dashboard:hover { background: var(--bg-alt); }
.sidebar-dashboard.active { background: var(--accent-light); border-left-color: var(--accent); }
.sidebar-dashboard-icon { font-size: 1rem; }
.sidebar-dashboard-label { font-size: 0.84rem; font-weight: 600; }
.form-item { display: flex; align-items: center; gap: 8px; padding: 10px 16px; cursor: pointer; border-left: 3px solid transparent; transition: all 0.15s; }
.form-item:hover { background: var(--bg-alt); }
.form-item.active { background: var(--accent-light); border-left-color: var(--accent); }
.form-item-info { min-width: 0; flex: 1; }
.form-item-name { font-size: 0.84rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.form-item-id { font-size: 0.7rem; color: var(--text-muted); margin-top: 1px; }
.form-item-badges { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.form-item-count { min-width: 28px; height: 22px; padding: 0 8px; border-radius: 11px; background: var(--bg-alt); color: var(--text-muted); font-size: 0.72rem; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.form-item.active .form-item-count { background: var(--accent); color: #fff; }
.form-item-today { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--green); color: #fff; font-size: 0.68rem; font-weight: 700; display: flex; align-items: center; justify-content: center; }

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

/* ─── Dashboard ─── */
.dash-section { margin: 0 20px 20px; }
.dash-section-title { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border-light); }
.dash-forms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.dash-form-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; cursor: pointer; transition: all 0.15s; }
.dash-form-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.dash-form-name { font-size: 0.88rem; font-weight: 600; margin-bottom: 6px; }
.dash-form-stats { display: flex; gap: 12px; font-size: 0.75rem; color: var(--text-muted); }
.dash-form-stats span { display: flex; align-items: center; gap: 3px; }
.dash-form-stats .dot { width: 6px; height: 6px; border-radius: 50%; }
.sub-card-form-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; background: var(--violet-bg); color: var(--violet-text); font-size: 0.7rem; font-weight: 600; margin-left: 8px; }

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
.sub-card-today { border-left-color: var(--green); }
.sub-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.sub-time { font-size: 0.78rem; font-weight: 500; color: var(--text-muted); }
.sub-id { font-size: 0.7rem; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--text-light); }
.sub-preview { display: flex; flex-wrap: wrap; gap: 4px 16px; }
.sub-field { font-size: 0.82rem; color: var(--text); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sub-field strong { font-weight: 500; color: var(--text-muted); font-size: 0.75rem; }

/* ─── Detail view ─── */
.detail-view { animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.detail-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.btn-back { padding: 6px 14px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.btn-back:hover { background: var(--bg-alt); }
.detail-title { flex: 1; min-width: 150px; }
.detail-id { font-size: 0.82rem; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--text-muted); }
.detail-date { font-size: 0.78rem; color: var(--text-light); display: block; margin-top: 2px; }
.detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-action { padding: 6px 14px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.82rem; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 5px; }
.btn-action:hover { background: var(--bg-alt); border-color: var(--accent); }
.btn-action.btn-forward { border-color: var(--accent); color: var(--accent-text); }
.btn-action.btn-forward:hover { background: var(--accent-light); }
.btn-action.btn-print { border-color: var(--violet); color: var(--violet-text); }
.btn-action.btn-print:hover { background: var(--violet-bg); }
.btn-delete { padding: 6px 14px; border: 1px solid var(--red); border-radius: 6px; background: transparent; color: var(--red); font-size: 0.82rem; cursor: pointer; transition: all 0.15s; }
.btn-delete:hover { background: var(--red-bg); }
.detail-nav { display: flex; gap: 4px; }
.btn-nav { padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.82rem; cursor: pointer; transition: all 0.15s; }
.btn-nav:hover:not(:disabled) { background: var(--bg-alt); border-color: var(--accent); }
.btn-nav:disabled { opacity: 0.35; cursor: default; }
.badge-today { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 4px; background: var(--green-bg); color: var(--green-text); font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; vertical-align: middle; }
.btn-delete.confirm { background: var(--red); color: #fff; animation: pulse 0.3s; }
@keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.05); } }

.detail-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
.detail-section-title { padding: 10px 16px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); background: var(--bg-alt); border-bottom: 1px solid var(--border); }
.detail-field { padding: 12px 16px; border-bottom: 1px solid var(--border-light); position: relative; }
.detail-field:last-child { border-bottom: none; }
.detail-field:hover .btn-copy { opacity: 1; }
.detail-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: var(--text-muted); margin-bottom: 4px; }
.detail-value { font-size: 0.88rem; line-height: 1.5; word-break: break-word; padding-right: 30px; }
.detail-value a { color: var(--accent); text-decoration: none; }
.detail-value a:hover { text-decoration: underline; }
.detail-value .pre-wrap { white-space: pre-wrap; }
.detail-value .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; background: var(--accent-light); color: var(--accent-text); font-size: 0.78rem; margin: 2px 2px 2px 0; }
.detail-value .stars { color: var(--amber); font-size: 1.1rem; letter-spacing: 1px; }
.detail-value .empty-val { color: var(--text-light); font-style: italic; }
.btn-copy { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); opacity: 0; background: var(--bg-alt); border: 1px solid var(--border); border-radius: 4px; padding: 4px 8px; font-size: 0.7rem; cursor: pointer; color: var(--text-muted); transition: opacity 0.15s; }
.btn-copy:hover { background: var(--accent-light); color: var(--accent-text); }
.btn-copy.copied { opacity: 1; background: var(--green-bg); color: var(--green-text); border-color: var(--green); }

.meta-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
.meta-card h4 { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 8px; }
.meta-row { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: flex; gap: 8px; }
.meta-row strong { color: var(--text); font-weight: 500; min-width: 80px; }

/* ─── Modal ─── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 100; display: flex; align-items: center; justify-content: center; animation: fadeIn 0.15s; }
.modal-box { background: var(--bg-surface); border-radius: var(--radius); box-shadow: var(--shadow-md); width: 90%; max-width: 440px; overflow: hidden; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.modal-header h3 { font-size: 0.9rem; font-weight: 600; }
.modal-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted); padding: 4px; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 16px; }
.modal-body label { display: block; font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.03em; }
.modal-body input, .modal-body textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.85rem; outline: none; margin-bottom: 12px; font-family: inherit; }
.modal-body input:focus, .modal-body textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-light); }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--border); }

/* ─── Pagination ─── */
.pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 12px 20px; flex-shrink: 0; border-top: 1px solid var(--border); }
.page-btn { min-width: 32px; height: 32px; padding: 0 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.page-btn:hover { background: var(--bg-alt); border-color: var(--accent); }
.page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); pointer-events: none; }
.page-dots { color: var(--text-muted); font-size: 0.8rem; padding: 0 4px; }

/* ─── Empty & loading ─── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state-icon { font-size: 3rem; margin-bottom: 12px; opacity: 0.3; }
.empty-state h3 { font-size: 1rem; font-weight: 600; margin-bottom: 6px; color: var(--text); }
.empty-state p { font-size: 0.85rem; }
.loading { text-align: center; padding: 40px; color: var(--text-muted); font-size: 0.85rem; }

/* ─── Mobile drawer ─── */
.drawer-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 14; }
.drawer-open .drawer-backdrop { display: block; }

/* ─── Responsive ─── */
@media (max-width: 900px) {
    .stats-bar { flex-wrap: wrap; }
    .stat-card { min-width: calc(50% - 6px); }
}
@media (max-width: 768px) {
    .panel-forms { width: 220px; }
    .toolbar input[type="text"] { width: 140px; }
}
@media (max-width: 640px) {
    .btn-hamburger { display: block; }
    .panel-forms { position: fixed; left: 0; top: 52px; bottom: 0; width: 280px; transform: translateX(-100%); transition: transform 0.25s ease; box-shadow: none; z-index: 15; }
    .drawer-open .panel-forms { transform: translateX(0); box-shadow: var(--shadow-md); }
    .stats-bar { gap: 8px; padding: 12px; }
    .toolbar input[type="text"] { width: 120px; }
    .detail-actions { width: 100%; justify-content: flex-end; }
}
</style>
</head>
<body>

<header class="viewer-header">
    <button class="btn-hamburger" id="btn-hamburger">&#9776;</button>
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="" class="header-logo"><?php endif; ?>
    <h1 id="header-title"><?= htmlspecialchars($siteName) ?></h1>
    <span class="header-spacer"></span>
    <span class="header-status" id="header-status"></span>
</header>

<div class="viewer-layout">
    <div class="panel-forms" id="panel-forms">
        <div class="panel-forms-header"><?= htmlspecialchars($viewerLang === 'sk' ? 'Formuláre' : ($viewerLang === 'de' ? 'Formulare' : 'Forms')) ?></div>
        <div class="form-list" id="form-list"></div>
    </div>
    <div class="drawer-backdrop" id="drawer-backdrop"></div>
    <div class="panel-main" id="panel-main"></div>
</div>

<script>
(function() {
'use strict';

const FORMS = <?= json_encode($formsList) ?>;
const TOKEN = <?= json_encode($viewerToken) ?>;
const CAN_DELETE = <?= json_encode($canDelete) ?>;
const SITE_NAME = <?= json_encode($siteName) ?>;
const LANG = <?= json_encode($viewerLang) ?>;

const I18N = {
    en: {
        dashboard:'Dashboard', forms:'Forms', forms_overview:'Forms overview', recent:'Recent submissions',
        total:'Total', today:'Today', this_week:'This week', this_month:'This month',
        search:'Search...', date_to:'to', filter:'Filter', submissions:'submissions', export_csv:'Export CSV',
        loading:'Loading...', loading_dash:'Loading dashboard...', no_subs:'No submissions',
        no_match:'No submissions match your criteria.',
        back:'Back', forward:'Forward', pdf:'PDF', del:'Delete', confirm:'Confirm?',
        deleting:'Deleting...', deleted:'Deleted',
        fwd_title:'Forward submission', fwd_to:'To (email)', fwd_note:'Note (optional)',
        cancel:'Cancel', send:'Send', sending:'Sending...', fwd_ok:'Forwarded to',
        copy:'Copy', copied:'Copied!', copy_fail:'Copy failed',
        popup_blocked:'Popup blocked — allow popups.',
        meta:'Submission metadata', submitted:'Submitted', ip:'IP', ua:'User agent',
        other:'Other fields', prev:'Prev', next:'Next',
        just_now:'just now', min_ago:'min ago', h_ago:'h ago', yesterday:'yesterday', days_ago:'days ago',
        generated:'Generated from', today_label:'today',
    },
    sk: {
        dashboard:'Prehľad', forms:'Formuláre', forms_overview:'Prehľad formulárov', recent:'Posledné odoslania',
        total:'Celkom', today:'Dnes', this_week:'Tento týždeň', this_month:'Tento mesiac',
        search:'Hľadať...', date_to:'do', filter:'Filtrovať', submissions:'odoslaní', export_csv:'Export CSV',
        loading:'Načítavam...', loading_dash:'Načítavam prehľad...', no_subs:'Žiadne odoslania',
        no_match:'Žiadne odoslania nezodpovedajú vašim kritériám.',
        back:'Späť', forward:'Preposlať', pdf:'PDF', del:'Vymazať', confirm:'Potvrdiť?',
        deleting:'Mazanie...', deleted:'Vymazané',
        fwd_title:'Preposlať odoslanie', fwd_to:'Komu (email)', fwd_note:'Poznámka (voliteľné)',
        cancel:'Zrušiť', send:'Odoslať', sending:'Odosielam...', fwd_ok:'Preposlané na',
        copy:'Kopírovať', copied:'Skopírované!', copy_fail:'Kopírovanie zlyhalo',
        popup_blocked:'Popup zablokovaný — povoľte vyskakovacie okná.',
        meta:'Metadáta odoslania', submitted:'Odoslané', ip:'IP', ua:'Prehliadač',
        other:'Ostatné polia', prev:'Predch.', next:'Ďalšie',
        just_now:'práve teraz', min_ago:'min', h_ago:'hod', yesterday:'včera', days_ago:'dní',
        generated:'Vygenerované z', today_label:'dnes',
    },
    de: {
        dashboard:'Übersicht', forms:'Formulare', forms_overview:'Formulare', recent:'Letzte Einreichungen',
        total:'Gesamt', today:'Heute', this_week:'Diese Woche', this_month:'Dieser Monat',
        search:'Suchen...', date_to:'bis', filter:'Filtern', submissions:'Einreichungen', export_csv:'CSV Export',
        loading:'Laden...', loading_dash:'Übersicht laden...', no_subs:'Keine Einreichungen',
        no_match:'Keine Einreichungen entsprechen Ihren Kriterien.',
        back:'Zurück', forward:'Weiterleiten', pdf:'PDF', del:'Löschen', confirm:'Bestätigen?',
        deleting:'Lösche...', deleted:'Gelöscht',
        fwd_title:'Einreichung weiterleiten', fwd_to:'An (E-Mail)', fwd_note:'Notiz (optional)',
        cancel:'Abbrechen', send:'Senden', sending:'Sende...', fwd_ok:'Weitergeleitet an',
        copy:'Kopieren', copied:'Kopiert!', copy_fail:'Kopieren fehlgeschlagen',
        popup_blocked:'Popup blockiert — bitte Pop-ups erlauben.',
        meta:'Einreichungs-Metadaten', submitted:'Eingereicht', ip:'IP', ua:'Browser',
        other:'Weitere Felder', prev:'Zurück', next:'Weiter',
        just_now:'gerade eben', min_ago:'Min.', h_ago:'Std.', yesterday:'gestern', days_ago:'Tage',
        generated:'Erstellt von', today_label:'heute',
    },
};
function t(key) { return (I18N[LANG] || I18N.en)[key] || I18N.en[key] || key; }

const formListEl = document.getElementById('form-list');
const panelMain = document.getElementById('panel-main');
const panelForms = document.getElementById('panel-forms');
const headerStatus = document.getElementById('header-status');
const headerTitle = document.getElementById('header-title');
const drawerBackdrop = document.getElementById('drawer-backdrop');

const state = {
    view: 'dashboard',
    formId: null,
    formDef: null,
    labelMap: {},
    subs: [],
    total: 0,
    page: 1,
    perPage: 20,
    search: '',
    dateFrom: '',
    dateTo: '',
    stats: null,
    detail: null,
    todayMap: {},
    deleteTimer: null,
};

// ─── API ─────────────────────────────────────────────────────────
const api = {
    async dashboard() {
        const r = await fetch('viewer.php?action=dashboard');
        return r.json();
    },
    async submissions(formId, limit, offset, from, to, q) {
        let url = `viewer.php?action=submissions&form=${encodeURIComponent(formId)}&limit=${limit}&offset=${offset}`;
        if (from) url += `&from=${from}`;
        if (to) url += `&to=${to}`;
        if (q) url += `&q=${encodeURIComponent(q)}`;
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
    async forward(formId, subId, to, note) {
        const r = await fetch('viewer.php?action=forward', {
            method: 'POST',
            body: JSON.stringify({ form: formId, id: subId, to, note }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Viewer-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Forward failed');
        return d;
    },
    async listForms() {
        const r = await fetch('viewer.php?action=list_forms');
        return r.json();
    },
};

// ─── URL Hash Routing ────────────────────────────────────────────
function updateHash() {
    let hash = '';
    if (state.view === 'form' && state.formId) {
        hash = `form=${state.formId}`;
        if (state.detail) hash += `&id=${state.detail}`;
        else if (state.page > 1) hash += `&page=${state.page}`;
    }
    history.replaceState(null, '', hash ? '#' + hash : location.pathname + location.search);
}

function readHash() {
    const h = location.hash.slice(1);
    if (!h) return null;
    const params = {};
    h.split('&').forEach(p => { const [k, v] = p.split('='); if (k && v) params[k] = decodeURIComponent(v); });
    return params;
}

// ─── Mobile drawer ───────────────────────────────────────────────
document.getElementById('btn-hamburger')?.addEventListener('click', () => {
    document.body.classList.toggle('drawer-open');
});
drawerBackdrop?.addEventListener('click', () => {
    document.body.classList.remove('drawer-open');
});
function closeDrawer() { document.body.classList.remove('drawer-open'); }

// ─── Sidebar ─────────────────────────────────────────────────────
function renderFormList(forms, todayMap) {
    let html = `<div class="sidebar-dashboard${state.view === 'dashboard' ? ' active' : ''}" id="sidebar-dash">`;
    html += `<span class="sidebar-dashboard-icon">&#9776;</span>`;
    html += `<span class="sidebar-dashboard-label">${esc(t('dashboard'))}</span></div>`;
    html += forms.map(f => {
        const today = (todayMap || state.todayMap)[f.id] || 0;
        return `<div class="form-item${f.id === state.formId && state.view === 'form' ? ' active' : ''}" data-id="${esc(f.id)}">` +
            `<div class="form-item-info"><div class="form-item-name">${esc(f.name)}</div>` +
            `<div class="form-item-id">${esc(f.id)}</div></div>` +
            `<div class="form-item-badges">` +
            (today > 0 ? `<span class="form-item-today">${today}</span>` : '') +
            `<span class="form-item-count">${f.count}</span></div></div>`;
    }).join('');
    formListEl.innerHTML = html;
}

formListEl.addEventListener('click', (e) => {
    const dash = e.target.closest('#sidebar-dash');
    if (dash) { showDashboard(); closeDrawer(); return; }
    const item = e.target.closest('.form-item');
    if (item) { selectForm(item.dataset.id); closeDrawer(); }
});

headerTitle.addEventListener('click', () => showDashboard());

// ─── Dashboard ───────────────────────────────────────────────────
async function showDashboard() {
    state.view = 'dashboard';
    state.formId = null;
    state.detail = null;
    document.title = SITE_NAME;
    updateHash();
    renderFormList(FORMS);
    panelMain.innerHTML = `<div class="loading">${esc(t('loading_dash'))}</div>`;
    try {
        const data = await api.dashboard();
        const todayMap = {};
        (data.per_form || []).forEach(f => { todayMap[f.id] = f.today; });
        state.todayMap = todayMap;
        state.dashDefs = data.form_defs || {};
        renderFormList(FORMS, todayMap);
        renderDashboard(data);
    } catch (err) { flash(err.message, true); }
}

function renderDashboard(data) {
    let html = '';
    html += '<div class="stats-bar">';
    html += statCard('stat-total', data.total, t('total'));
    html += statCard('stat-today', data.today, t('today'));
    html += statCard('stat-week', data.this_week, t('this_week'));
    html += '</div>';

    // Forms overview
    html += `<div class="dash-section"><div class="dash-section-title">${esc(t('forms_overview'))}</div>`;
    html += '<div class="dash-forms-grid">';
    (data.per_form || []).forEach(f => {
        html += `<div class="dash-form-card" data-form="${esc(f.id)}">`;
        html += `<div class="dash-form-name">${esc(f.name)}</div>`;
        html += `<div class="dash-form-stats">`;
        html += `<span><span class="dot" style="background:var(--accent)"></span> ${f.total} ${esc(t('total').toLowerCase())}</span>`;
        if (f.today > 0) html += `<span><span class="dot" style="background:var(--green)"></span> ${f.today} ${esc(t('today').toLowerCase())}</span>`;
        html += `</div></div>`;
    });
    html += '</div></div>';

    // Recent submissions
    if (data.recent && data.recent.length > 0) {
        html += `<div class="dash-section"><div class="dash-section-title">${esc(t('recent'))}</div>`;
        html += '<div class="content" id="content">';
        data.recent.forEach(sub => {
            const time = relativeTime(sub.meta?.submitted);
            const formName = sub.form_name || sub.form;
            const formDef = state.dashDefs[sub.form] || null;
            const labelMap = buildLabelMap(formDef);
            const previewKeys = getPreviewKeys(formDef, sub.data || {});
            const todayCls = isToday(sub.meta?.submitted) ? ' sub-card-today' : '';
            html += `<div class="sub-card${todayCls}" data-id="${esc(sub.id)}" data-form="${esc(sub.form)}">`;
            html += `<div class="sub-card-header"><span class="sub-time">${esc(time)}</span>`;
            html += `<span><span class="sub-id">${esc(sub.id)}</span><span class="sub-card-form-tag">${esc(formName)}</span></span></div>`;
            html += `<div class="sub-preview">`;
            previewKeys.forEach(k => {
                const v = (sub.data || {})[k];
                if (v === undefined || v === null) return;
                const val = Array.isArray(v) ? v.join(', ') : String(v);
                const display = val.length > 50 ? val.substring(0, 50) + '...' : val;
                const label = labelMap[k] || k;
                html += `<span class="sub-field"><strong>${esc(label)}:</strong> ${esc(display)}</span>`;
            });
            html += '</div></div>';
        });
        html += '</div></div>';
    }

    panelMain.innerHTML = html;
}

// ─── Select form ─────────────────────────────────────────────────
async function selectForm(formId, opts) {
    state.view = 'form';
    state.formId = formId;
    state.page = opts?.page || 1;
    state.detail = opts?.detail || null;
    state.search = '';
    state.dateFrom = '';
    state.dateTo = '';
    renderFormList(FORMS);
    document.title = formId + ' — ' + SITE_NAME;
    updateHash();

    if (state.detail) {
        panelMain.innerHTML = '<div class="loading">Loading...</div>';
        try {
            const data = await api.detail(formId, state.detail);
            state.formDef = data.form_def;
            state.labelMap = buildLabelMap(state.formDef);
            renderDetailView(data.submission, data.form_def);
        } catch (err) { flash(err.message, true); }
        return;
    }

    renderMainLoading();
    try {
        const [statsData, subsData] = await Promise.all([
            api.stats(formId),
            api.submissions(formId, state.perPage, (state.page - 1) * state.perPage),
        ]);
        state.stats = statsData;
        state.subs = subsData.submissions;
        state.total = subsData.total;
        state.formDef = subsData.form_def || null;
        state.labelMap = buildLabelMap(state.formDef);
        renderMain();
    } catch (err) { flash(err.message, true); }
}

// ─── Render main (form view) ─────────────────────────────────────
function renderMainLoading() {
    panelMain.innerHTML = `<div class="loading">${esc(t('loading'))}</div>`;
}

function renderMain() {
    let html = '';
    html += '<div class="stats-bar">';
    html += statCard('stat-total', state.stats?.total ?? 0, t('total'));
    html += statCard('stat-today', state.stats?.today ?? 0, t('today'));
    html += statCard('stat-week', state.stats?.this_week ?? 0, t('this_week'));
    html += statCard('stat-month', state.stats?.this_month ?? 0, t('this_month'));
    html += '</div>';
    html += '<div class="toolbar">';
    html += `<input type="text" id="search-input" placeholder="${esc(t('search'))}" value="${esc(state.search)}">`;
    html += `<input type="date" id="filter-from" value="${esc(state.dateFrom)}">`;
    html += `<span class="toolbar-sep">${esc(t('date_to'))}</span>`;
    html += `<input type="date" id="filter-to" value="${esc(state.dateTo)}">`;
    html += `<button class="toolbar-btn" id="btn-filter">${esc(t('filter'))}</button>`;
    html += `<span class="toolbar-spacer"></span>`;
    html += `<span class="toolbar-count" id="toolbar-count">${state.total} ${esc(t('submissions'))}</span>`;
    html += `<button class="toolbar-btn btn-accent" id="btn-export">${esc(t('export_csv'))}</button>`;
    html += '</div>';
    html += '<div class="content" id="content">';
    html += renderCards();
    html += '</div>';
    html += '<div class="pagination" id="pagination">';
    html += renderPagination();
    html += '</div>';
    panelMain.innerHTML = html;
}

function buildLabelMap(formDef) {
    const map = {};
    if (!formDef?.fields) return map;
    function walk(fields) {
        for (const f of fields) {
            if (f.type === 'group' && f.fields) { walk(f.fields); continue; }
            if (f.name && f.label) map[f.name] = f.label;
        }
    }
    walk(formDef.fields);
    return map;
}

function getPreviewKeys(formDef, data) {
    const dataKeys = Object.keys(data);
    if (!formDef?.fields) {
        // No form def: filter out group-like keys, take first 3 with values
        return dataKeys.filter(k => data[k] !== '' && data[k] !== null && !k.endsWith('_row')).slice(0, 3);
    }
    // Collect all visible fields from form def
    const allFields = [];
    function walk(fields) {
        for (const f of fields) {
            if (f.type === 'group' && f.fields) { walk(f.fields); continue; }
            if (['section', 'page_break', 'hidden'].includes(f.type)) continue;
            if (f.name && data[f.name] !== undefined && data[f.name] !== null && data[f.name] !== '') {
                allFields.push(f);
            }
        }
    }
    walk(formDef.fields);
    // Prefer name-like, email, tel fields first
    const namePattern = /^(first_name|last_name|name|full_name|company|email|phone|tel)$/i;
    const preferred = allFields.filter(f => namePattern.test(f.name) || f.type === 'email' || f.type === 'tel');
    const rest = allFields.filter(f => !preferred.includes(f));
    const ordered = [...preferred, ...rest];
    return ordered.slice(0, 3).map(f => f.name);
}

function statCard(cls, value, label) {
    return `<div class="stat-card ${cls}"><div class="stat-value">${value}</div><div class="stat-label">${label}</div></div>`;
}

function renderCards(subs, opts) {
    const items = subs || state.subs;
    if (items.length === 0) {
        return `<div class="empty-state"><div class="empty-state-icon">&#128203;</div>`
            + `<h3>${esc(t('no_subs'))}</h3><p>${esc(t('no_match'))}</p></div>`;
    }
    return items.map(sub => {
        const time = relativeTime(sub.meta?.submitted);
        const previewKeys = getPreviewKeys(state.formDef, sub.data || {});
        const preview = previewKeys.map(k => [k, (sub.data || {})[k]]).filter(([,v]) => v !== undefined);
        const formTag = opts?.showForm ? `<span class="sub-card-form-tag">${esc(sub.form_name || sub.form)}</span>` : '';
        const todayCls = isToday(sub.meta?.submitted) ? ' sub-card-today' : '';
        return `<div class="sub-card${todayCls}" data-id="${esc(sub.id)}"${opts?.showForm ? ` data-form="${esc(sub.form)}"` : ''}>` +
            `<div class="sub-card-header">` +
            `<span class="sub-time">${esc(time)}</span>` +
            `<span><span class="sub-id">${esc(sub.id)}</span>${formTag}</span></div>` +
            `<div class="sub-preview">` +
            preview.map(([k, v]) => {
                const val = Array.isArray(v) ? v.join(', ') : String(v);
                const display = val.length > 60 ? val.substring(0, 60) + '...' : val;
                const label = state.labelMap[k] || k;
                return `<span class="sub-field"><strong>${esc(label)}:</strong> ${esc(display)}</span>`;
            }).join('') +
            `</div></div>`;
    }).join('');
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

// ─── Page navigation ─────────────────────────────────────────────
async function loadPage() {
    const offset = (state.page - 1) * state.perPage;
    try {
        const data = await api.submissions(state.formId, state.perPage, offset, state.dateFrom || null, state.dateTo || null, state.search || null);
        state.subs = data.submissions;
        state.total = data.total;
        const content = document.getElementById('content');
        const pagination = document.getElementById('pagination');
        const count = document.getElementById('toolbar-count');
        if (content) content.innerHTML = renderCards();
        if (pagination) pagination.innerHTML = renderPagination();
        if (count) count.textContent = state.total + ' submission' + (state.total !== 1 ? 's' : '');
        updateHash();
    } catch (err) { flash(err.message, true); }
}

// ─── Detail view ─────────────────────────────────────────────────
async function openDetail(subId, formId) {
    if (formId && formId !== state.formId) {
        selectForm(formId, { detail: subId });
        return;
    }
    state.detail = subId;
    updateHash();
    const content = document.getElementById('content');
    if (content) content.innerHTML = '<div class="loading">Loading...</div>';
    const pagination = document.getElementById('pagination');
    if (pagination) pagination.innerHTML = '';
    try {
        const data = await api.detail(state.formId, subId);
        state.formDef = data.form_def;
        state.labelMap = buildLabelMap(state.formDef);
        renderDetailView(data.submission, data.form_def);
    } catch (err) { flash(err.message, true); }
}

function renderDetailView(sub, formDef) {
    const submitted = sub.meta?.submitted || '';
    const dateStr = submitted ? new Date(submitted).toLocaleString() : '';

    let html = '<div class="detail-view">';
    // Find prev/next submission IDs
    const subIds = state.subs.map(s => s.id);
    const curIdx = subIds.indexOf(sub.id);
    const prevId = curIdx > 0 ? subIds[curIdx - 1] : null;
    const nextId = curIdx >= 0 && curIdx < subIds.length - 1 ? subIds[curIdx + 1] : null;
    const todayBadge = isToday(submitted) ? `<span class="badge-today">${esc(t('today_label'))}</span>` : '';

    html += '<div class="detail-header">';
    html += `<button class="btn-back" id="btn-back">&larr; ${esc(t('back'))}</button>`;
    html += `<div class="detail-nav">`;
    html += `<button class="btn-nav" id="btn-prev"${prevId ? '' : ' disabled'}>&lsaquo; ${esc(t('prev'))}</button>`;
    html += `<button class="btn-nav" id="btn-next"${nextId ? '' : ' disabled'}>${esc(t('next'))} &rsaquo;</button>`;
    html += `</div>`;
    html += `<div class="detail-title"><span class="detail-id">${esc(sub.id)}${todayBadge}</span>`;
    html += `<span class="detail-date">${esc(relativeTime(submitted))} &middot; ${esc(dateStr)}</span></div>`;
    html += '<div class="detail-actions">';
    html += `<button class="btn-action btn-forward" id="btn-forward">&#9993; ${esc(t('forward'))}</button>`;
    html += `<button class="btn-action btn-print" id="btn-print">&#128438; ${esc(t('pdf'))}</button>`;
    if (CAN_DELETE) html += `<button class="btn-delete" id="btn-del" data-id="${esc(sub.id)}">${esc(t('del'))}</button>`;
    html += '</div></div>';

    const fields = formDef?.fields || [];
    const dataKeys = Object.keys(sub.data || {});
    const sections = buildDetailSections(sub.data, fields, dataKeys);

    sections.forEach(section => {
        html += '<div class="detail-card">';
        if (section.title) html += `<div class="detail-section-title">${esc(section.title)}</div>`;
        section.fields.forEach(f => {
            const rawVal = Array.isArray(f.value) ? f.value.join(', ') : String(f.value ?? '');
            html += `<div class="detail-field">`;
            html += `<div class="detail-label">${esc(f.label)}</div>`;
            html += `<div class="detail-value">${formatValue(f.value, f.type)}</div>`;
            html += `<button class="btn-copy" data-val="${esc(rawVal)}">${esc(t('copy'))}</button>`;
            html += '</div>';
        });
        html += '</div>';
    });

    html += `<div class="meta-card"><h4>${esc(t('meta'))}</h4>`;
    if (sub.meta?.submitted) html += `<div class="meta-row"><strong>${esc(t('submitted'))}</strong> ${esc(sub.meta.submitted)}</div>`;
    if (sub.meta?.ip) html += `<div class="meta-row"><strong>${esc(t('ip'))}</strong> ${esc(sub.meta.ip)}</div>`;
    if (sub.meta?.user_agent) html += `<div class="meta-row"><strong>${esc(t('ua'))}</strong> ${esc(sub.meta.user_agent)}</div>`;
    html += '</div></div>';

    // Replace full panelMain for detail view
    panelMain.innerHTML = '<div class="content" id="content">' + html + '</div><div class="pagination" id="pagination"></div>';

    // Store sub data for forward/print
    panelMain._currentSub = sub;
    panelMain._currentFormDef = formDef;
}

function buildDetailSections(data, fields, dataKeys) {
    if (!fields || fields.length === 0) {
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
            if (f.type === 'group' && f.fields) { processFields(f.fields); continue; }
            if (f.type === 'hidden') continue;
            const val = data[f.name];
            if (val === undefined || val === null) continue;
            rendered.add(f.name);
            current.fields.push({ label: f.label || f.name, value: val, type: f.type || 'text' });
        }
    }

    processFields(fields);
    if (current.fields.length > 0) sections.push(current);
    const extra = dataKeys.filter(k => !rendered.has(k));
    if (extra.length > 0) {
        sections.push({ title: t('other'), fields: extra.map(k => ({ label: k, value: data[k], type: 'text' })) });
    }
    return sections;
}

// ─── Forward modal ───────────────────────────────────────────────
function showForwardModal(sub) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `<div class="modal-box">
        <div class="modal-header"><h3>${esc(t('fwd_title'))}</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <label>${esc(t('fwd_to'))}</label>
            <input type="email" id="fwd-to" placeholder="recipient@example.com">
            <label>${esc(t('fwd_note'))}</label>
            <textarea id="fwd-note" rows="3"></textarea>
        </div>
        <div class="modal-footer">
            <button class="toolbar-btn" id="fwd-cancel">${esc(t('cancel'))}</button>
            <button class="toolbar-btn btn-accent" id="fwd-send">${esc(t('send'))}</button>
        </div>
    </div>`;
    document.body.appendChild(overlay);
    const toInput = document.getElementById('fwd-to');
    toInput.focus();
    const close = () => overlay.remove();
    overlay.querySelector('.modal-close').addEventListener('click', close);
    document.getElementById('fwd-cancel').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    document.getElementById('fwd-send').addEventListener('click', async () => {
        const to = toInput.value.trim();
        if (!to) { toInput.focus(); return; }
        const note = document.getElementById('fwd-note').value.trim();
        const btn = document.getElementById('fwd-send');
        btn.textContent = t('sending');
        btn.disabled = true;
        try {
            await api.forward(state.formId, sub.id, to, note);
            flash(t('fwd_ok') + ' ' + to);
            close();
        } catch (err) { flash(err.message, true); btn.textContent = t('send'); btn.disabled = false; }
    });
    const onKey = (e) => { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); } };
    document.addEventListener('keydown', onKey);
}

// ─── Print / PDF ─────────────────────────────────────────────────
function printSubmission(sub, formDef) {
    const formName = formDef?.name || state.formId;
    const submitted = sub.meta?.submitted ? new Date(sub.meta.submitted).toLocaleString() : '';
    const labelMap = buildLabelMap(formDef);
    const fields = Object.entries(sub.data || {});

    let html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${esc(formName)} — ${esc(sub.id)}</title>
    <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:700px;margin:0 auto;padding:40px 30px;color:#1e293b}
    h1{font-size:1.3rem;border-bottom:2px solid #2563eb;padding-bottom:8px;margin-bottom:4px}
    .meta{color:#64748b;font-size:0.85rem;margin-bottom:24px}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    td{padding:10px 12px;border:1px solid #e2e8f0;font-size:0.9rem;vertical-align:top}
    td:first-child{font-weight:600;background:#f8fafc;color:#475569;width:35%}
    .footer{margin-top:30px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:0.75rem;color:#94a3b8}
    @media print{body{padding:20px}}</style></head><body>`;
    html += `<h1>${esc(formName)}</h1>`;
    html += `<div class="meta"><strong>ID:</strong> ${esc(sub.id)} &middot; <strong>Submitted:</strong> ${esc(submitted)}</div>`;
    html += '<table>';
    fields.forEach(([k, v]) => {
        const label = labelMap[k] || k;
        const val = Array.isArray(v) ? v.join(', ') : String(v || '-');
        html += `<tr><td>${esc(label)}</td><td>${esc(val)}</td></tr>`;
    });
    html += '</table>';
    html += `<div class="footer">${esc(t('generated'))} ${esc(SITE_NAME)}</div>`;
    html += '</body></html>';

    const win = window.open('', '_blank');
    if (win) {
        win.document.write(html);
        win.document.close();
        setTimeout(() => win.print(), 300);
    } else {
        flash(t('popup_blocked'), true);
    }
}

// ─── Copy to clipboard ──────────────────────────────────────────
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = t('copied');
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = t('copy'); btn.classList.remove('copied'); }, 1500);
    }).catch(() => flash(t('copy_fail'), true));
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
    if (diff < 0) return t('just_now');
    if (diff < 60) return t('just_now');
    if (diff < 3600) return Math.floor(diff / 60) + ' ' + t('min_ago');
    if (diff < 86400) return Math.floor(diff / 3600) + ' ' + t('h_ago');
    if (diff < 172800) return t('yesterday');
    if (diff < 604800) return Math.floor(diff / 86400) + ' ' + t('days_ago');
    return date.toLocaleDateString();
}

function isToday(iso) {
    if (!iso) return false;
    return new Date(iso).toDateString() === new Date().toDateString();
}

function flash(msg, isError) {
    headerStatus.textContent = msg;
    headerStatus.style.color = isError ? 'var(--red)' : 'var(--green)';
    setTimeout(() => { headerStatus.textContent = ''; }, 3000);
}

// ─── Global event delegation ─────────────────────────────────────
panelMain.addEventListener('click', (e) => {
    // Dashboard form card
    const dashCard = e.target.closest('.dash-form-card');
    if (dashCard) { selectForm(dashCard.dataset.form); return; }

    // Submission card
    const subCard = e.target.closest('.sub-card');
    if (subCard && !e.target.closest('.btn-copy')) {
        const formId = subCard.dataset.form;
        openDetail(subCard.dataset.id, formId !== state.formId ? formId : undefined);
        return;
    }

    // Pagination
    const pageBtn = e.target.closest('.page-btn');
    if (pageBtn && pageBtn.dataset.page) {
        state.page = parseInt(pageBtn.dataset.page);
        loadPage();
        return;
    }

    // Back button
    if (e.target.closest('#btn-back')) {
        state.detail = null;
        updateHash();
        renderMain();
        return;
    }

    // Prev/Next navigation
    if (e.target.closest('#btn-prev')) {
        const ids = state.subs.map(s => s.id);
        const idx = ids.indexOf(state.detail);
        if (idx > 0) openDetail(ids[idx - 1]);
        return;
    }
    if (e.target.closest('#btn-next')) {
        const ids = state.subs.map(s => s.id);
        const idx = ids.indexOf(state.detail);
        if (idx >= 0 && idx < ids.length - 1) openDetail(ids[idx + 1]);
        return;
    }

    // Forward button
    if (e.target.closest('#btn-forward')) {
        const sub = panelMain._currentSub;
        if (sub) showForwardModal(sub);
        return;
    }

    // Print button
    if (e.target.closest('#btn-print')) {
        const sub = panelMain._currentSub;
        const def = panelMain._currentFormDef;
        if (sub) printSubmission(sub, def);
        return;
    }

    // Delete button (inline confirmation)
    const delBtn = e.target.closest('#btn-del');
    if (delBtn) {
        if (delBtn.classList.contains('confirm')) {
            clearTimeout(state.deleteTimer);
            const subId = delBtn.dataset.id;
            delBtn.textContent = t('deleting');
            delBtn.disabled = true;
            (async () => {
                try {
                    await api.del(state.formId, subId);
                    flash(t('deleted'));
                    state.detail = null;
                    updateHash();
                    const [statsData, subsData] = await Promise.all([
                        api.stats(state.formId),
                        api.submissions(state.formId, state.perPage, (state.page - 1) * state.perPage),
                    ]);
                    state.stats = statsData;
                    state.subs = subsData.submissions;
                    state.total = subsData.total;
                    renderMain();
                    const forms = await api.listForms();
                    renderFormList(forms);
                } catch (err) { flash(err.message, true); }
            })();
        } else {
            delBtn.classList.add('confirm');
            delBtn.textContent = t('confirm');
            state.deleteTimer = setTimeout(() => {
                delBtn.classList.remove('confirm');
                delBtn.textContent = t('del');
            }, 3000);
        }
        return;
    }

    // Copy button
    const copyBtn = e.target.closest('.btn-copy');
    if (copyBtn) {
        copyToClipboard(copyBtn.dataset.val, copyBtn);
        return;
    }

    // Filter button
    if (e.target.closest('#btn-filter')) {
        const from = document.getElementById('filter-from');
        const to = document.getElementById('filter-to');
        state.dateFrom = from?.value || '';
        state.dateTo = to?.value || '';
        state.page = 1;
        loadPage();
        return;
    }

    // Export button
    if (e.target.closest('#btn-export')) {
        let url = `viewer.php?action=export&form=${encodeURIComponent(state.formId)}`;
        if (state.dateFrom) url += `&from=${state.dateFrom}`;
        if (state.dateTo) url += `&to=${state.dateTo}`;
        window.location.href = url;
        return;
    }
});

// Search (server-side with debounce)
let searchTimer;
panelMain.addEventListener('input', (e) => {
    if (e.target.id === 'search-input') {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = e.target.value;
            state.page = 1;
            loadPage();
        }, 400);
    }
});

// ─── Keyboard ────────────────────────────────────────────────────
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        // Close modal first
        const modal = document.querySelector('.modal-overlay');
        if (modal) { modal.remove(); return; }
        // Close drawer
        if (document.body.classList.contains('drawer-open')) { closeDrawer(); return; }
        // Back from detail
        if (state.detail) {
            state.detail = null;
            updateHash();
            renderMain();
        }
    }
    // Prev/Next with arrow keys in detail
    if (state.detail && (e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.target.matches('input,textarea')) {
        const btn = document.getElementById(e.key === 'ArrowLeft' ? 'btn-prev' : 'btn-next');
        if (btn && !btn.disabled) btn.click();
        return;
    }
    // Focus search with /
    if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
        const search = document.getElementById('search-input');
        if (search && document.activeElement !== search) {
            e.preventDefault();
            search.focus();
        }
    }
});

// ─── Init ────────────────────────────────────────────────────────
renderFormList(FORMS);
const hashParams = readHash();
if (hashParams?.form) {
    selectForm(hashParams.form, { detail: hashParams.id || null, page: parseInt(hashParams.page) || 1 });
} else {
    showDashboard();
}

})();
</script>
</body>
</html>
