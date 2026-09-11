<?php
/**
 * BareBonesForms — Submissions Viewer
 *
 * Beautiful dashboard for browsing and managing form submissions.
 * Supports all storage backends: file, SQLite, MySQL, CSV.
 *
 * Access: shared token authentication on every host, including localhost.
 * DELETE THIS FILE if you don't need it in production.
 */

// ─── Bootstrap ──────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing config.php. Copy config.example.php to config.php and edit it.');
}
require_once __DIR__ . '/bbf_auth.php'; $config = bbf_auth_load_config(__DIR__ . '/config.php');
require_once __DIR__ . '/bbf_functions.php'; require_once __DIR__ . '/bbf_export.php'; require_once __DIR__ . '/bbf_read.php'; require_once __DIR__ . '/bbf_review.php'; require_once __DIR__ . '/bbf_versions.php';
set_exception_handler(static function (Throwable $error): void { error_log('BareBonesForms viewer: ' . $error->getMessage()); viewerRespond(500, ['error' => 'Cannot read submissions.']); });
// Shared access is required on every host, including loopback.
require_once __DIR__ . '/bbf_auth.php';
$viewerActions = ['', 'list_forms', 'dashboard', 'submissions', 'detail', 'stats', 'export', 'print', 'delete',
    'bulk_delete', 'forward', 'retry_delivery', 'review_filters', 'review_update', 'review_filter_save', 'review_filter_delete'];
$action = is_string($_GET['action'] ?? '') && in_array($_GET['action'] ?? '', $viewerActions, true) ? ($_GET['action'] ?? '') : 'invalid';
$principal = bbf_authenticate($config, true, $action === '');
$reviewActions = ['review_filters', 'review_update', 'review_filter_save', 'review_filter_delete'];
$mutation = in_array($action, ['delete', 'bulk_delete', 'forward', 'retry_delivery', 'review_update', 'review_filter_save', 'review_filter_delete'], true);
if ($mutation && (!$principal || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !bbf_auth_csrf_valid())) {
    // Denials are audited before any caller-controlled body is read.
    bbf_access_begin($config, $principal, 'viewer_' . $action, '', [], false, true, []);
}
[$accessBody, $bodyError] = $mutation ? viewerRequestBody() : [[], null];
if ($bodyError !== null) {
    bbf_access_begin($config, $principal, 'viewer_' . $action, '', [], false, true, []);
    viewerRespond($bodyError === 'too_large' ? 413 : 400, ['error' => 'Invalid request body.']);
}
$accessForm = bbf_auth_id($mutation ? ($accessBody['form'] ?? null) : ($_GET['form'] ?? null));
$permissions = in_array($action, ['submissions', 'detail', 'stats', 'export', 'print', 'delete', 'bulk_delete', 'forward', 'retry_delivery'], true) ? ['read'] : [];
if (in_array($action, $reviewActions, true) || ($action === 'submissions'
    && (array_key_exists('review', $_GET) || array_key_exists('status', $_GET) || array_key_exists('tags', $_GET)))) $permissions = ['read', 'review'];
if (in_array($action, ['export', 'print', 'forward'], true)) $permissions[] = 'export';
if (in_array($action, ['delete', 'bulk_delete'], true)) $permissions[] = 'delete';
$accessIds = $action === 'bulk_delete' ? ($accessBody['ids'] ?? []) : [$mutation ? ($accessBody['id'] ?? '') : ($_GET['id'] ?? '')];
bbf_access_begin($config, $principal, 'viewer_' . ($action ?: 'page'), $accessForm,
    $permissions, $action === 'retry_delivery', $mutation, is_array($accessIds) ? $accessIds : []);
if ($permissions && !$accessForm) viewerRespond(400, ['error' => 'Invalid form ID.']);
if (!in_array($action, $viewerActions, true)) viewerRespond(400, ['error' => 'Unknown action.']);
$viewerToken = bbf_auth_csrf();

$formsDir = $config['forms_dir'] ?? __DIR__ . '/forms';
$subsDir  = $config['submissions_dir'] ?? __DIR__ . '/submissions';
$storage  = $accessForm ? bbf_effective_storage_config($config, $accessForm)['storage'] : ($config['storage'] ?? 'file');
$canDelete = ($storage !== 'csv');

// ─── Branding ───────────────────────────────────────────────────
$siteName = $config['viewer']['site_name'] ?? 'BareBonesForms';
$logoUrl  = $config['viewer']['logo_url'] ?? '';
$viewerLang = $_GET['lang'] ?? ($config['viewer']['lang'] ?? $config['lang'] ?? 'en');

// ─── Helpers ─────────────────────────────────────────────────────
function viewerRequestBody(int $maxBytes = 65536): array {
    $input = fopen('php://input', 'rb');
    if (!$input) return [[], 'invalid'];
    try { $raw = stream_get_contents($input, $maxBytes + 1); } finally { fclose($input); }
    if (!is_string($raw)) return [[], 'invalid'];
    if (strlen($raw) > $maxBytes) return [[], 'too_large'];
    try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (Throwable $error) { return [[], 'invalid']; }
    return is_array($body) && array_is_list($body) === false ? [$body, null] : [[], 'invalid'];
}

function viewerRespond(int $code, $data): void {
    $auditCount = is_array($data) && array_key_exists('_audit_count', $data)
        ? max(0, (int)$data['_audit_count'])
        : ($code < 400 ? (int)($data['deleted'] ?? (isset($data['submissions']) ? count($data['submissions']) : (isset($data['submission']) || isset($data['ok']) ? 1 : ($data['total'] ?? count($data))))) : 0);
    if (is_array($data)) unset($data['_audit_count']);
    bbf_access_finish($auditCount, $code < 400); http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeId($raw): string {
    return bbf_auth_id($raw);
}

function checkViewerToken(): void {
    global $viewerToken;
    $provided = $_SERVER['HTTP_X_BBF_VIEWER_TOKEN'] ?? '';
    if (!bbf_auth_csrf_valid()) {
        viewerRespond(403, ['error' => 'Invalid viewer token.']);
    }
}

function viewerSubmissionCriteria(array $value): ?array {
    $reviewCriteria = array_diff_key($value, ['from' => true, 'to' => true]);
    $criteria = bbf_review_filter_criteria($reviewCriteria);
    if ($criteria === null) return null;
    foreach (['from', 'to'] as $key) {
        if (!array_key_exists($key, $value)) continue;
        if (!is_string($value[$key])) return null;
        $formats = ['!Y-m-d', '!Y-m-d\TH:i:s\Z', '!Y-m-d H:i:s']; $valid = false;
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value[$key]); $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
                && $date->format(substr($format, 1)) === $value[$key]) { $valid = true; break; }
        }
        if (!$valid) return null;
        $criteria[$key] = $value[$key];
    }
    return $criteria;
}

function viewerReviewRespond(array $result): void {
    if (($result['ok'] ?? false) === true) viewerRespond(200, $result);
    $reason = $result['reason'] ?? 'storage';
    if ($reason === 'conflict' && (isset($result['review']) || array_key_exists('filter', $result))) $result['_audit_count'] = 1;
    $code = $reason === 'invalid' ? 400 : ($reason === 'conflict' ? 409 : ($reason === 'not_found' ? 404 : 503));
    $messages = ['invalid' => 'Invalid review payload.', 'conflict' => 'Review revision conflict.',
        'not_found' => 'Review record not found.', 'storage' => 'Review metadata is unavailable.'];
    viewerRespond($code, $result + ['error' => $messages[$reason] ?? $messages['storage']]);
}

function viewerDeliveryStatus(array $config, string $formId, string $subId, bool $allowRetry): array {
    $effective = bbf_effective_storage_config($config, $formId);
    $path = bbf_outbox_existing_path($effective, $formId, $subId);
    if ($path !== '' && is_file($path)) {
        $expired = bbf_outbox_expire_leases($path, (int)($effective['delivery']['lease_seconds'] ?? 300));
        if (!($expired['ok'] ?? false)) {
            return ['ok' => false, 'state' => 'attention_required', 'settled' => false, 'jobs' => []];
        }
    }
    $status = bbf_outbox_status($path);
    if (!$allowRetry) {
        foreach ($status['jobs'] as &$job) $job['can_retry'] = false;
        unset($job);
    }
    return $status;
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
            return new (class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : 'PDO')("sqlite:$dbFile", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    } catch (PDOException $e) { error_log("BareBonesForms viewer DB: " . $e->getMessage()); throw $e; }
    return null;
}

function dbRowToSub(array $row): ?array {
    return bbf_read_db_row([
        'id'      => $row['id'],
        'form_id' => $row['form_id'],
        'data'    => $row['data'],
        'meta'    => $row['meta'],
    ]);
}

function matchesSearch(array $data, string $q): bool {
    return bbf_read_search($data, $q);
}

function viewerValueText(mixed $value): string {
    if (!is_array($value)) return (string)$value;
    if (array_is_list($value) && array_reduce($value,
        static fn(bool $flat, mixed $item): bool => $flat && (is_scalar($item) || $item === null), true)) {
        return implode(', ', array_map(static fn(mixed $item): string => (string)$item, $value));
    }
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

function viewerSubmissionDefinition(array $submission, ?array $current, ?array $principal): ?array {
    return bbf_auth_presentation(bbf_version_submission_definition($submission, $current), $principal);
}

// ─── Storage: Count ──────────────────────────────────────────────
function countSubs(string $formId, array $config, ?string $since = null): int { $config = bbf_effective_storage_config($config, $formId);
    $s = $config['storage'] ?? 'file';
    if ($s === 'file') return countSubsFile($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $since);
    if ($s === 'csv')  return countSubsCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $since);
    $pdo = getDbConnection($config);
    return $pdo ? countSubsDb($pdo, $formId, $since) : 0;
}

function countSubsFile(string $formId, string $dir, ?string $since): int {
    $total = null;
    bbf_read_page(bbf_read_files($formId, $dir, $since), 0, 0, $total);
    return $total;
}

function countSubsCsv(string $formId, string $dir, ?string $since): int {
    $total = null;
    bbf_read_page(bbf_read_csv($formId, $dir, $since), 0, 0, $total);
    return $total;
}

function countSubsDb(PDO $pdo, string $formId, ?string $since): int {
    // Use the same eligibility as pages, including the unbuffered MySQL fallback.
    $total = null;
    bbf_read_db_page($pdo, $formId, 0, 0, $since, null, $total);
    return $total;
}

// ─── Storage: Load page ──────────────────────────────────────────
function loadSubsPage(string $formId, array $config, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array { $config = bbf_effective_storage_config($config, $formId);
    $s = $config['storage'] ?? 'file';
    if ($s === 'file') return loadPageFile($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total, $q);
    if ($s === 'csv')  return loadPageCsv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', $limit, $offset, $from, $to, $total, $q);
    $pdo = getDbConnection($config);
    if (!$pdo) { $total = 0; return []; }
    return loadPageDb($pdo, $formId, $limit, $offset, $from, $to, $total, $q);
}

function viewerReviewBatch(array $rows, array $config, string $formId, ?string $status, array $tags, ?array $records = null): array {
    $records ??= bbf_review_records($config, $formId, array_column($rows, 'id'));
    $matched = [];
    foreach ($rows as $row) {
        $review = $records[$row['id']] ?? bbf_review_default();
        if ($status !== null && $review['status'] !== $status) continue;
        if ($tags !== [] && array_diff($tags, $review['tags']) !== []) continue;
        $row['review'] = $review;
        $matched[] = $row;
    }
    return $matched;
}

/** Review predicates are applied while streaming source rows, before count/offset/limit. */
function loadReviewSubsPage(string $formId, array $config, int $limit, int $offset, ?string $from, ?string $to,
    ?int &$total, ?string $q, ?string $status, array $tags): array {
    $effective = bbf_effective_storage_config($config, $formId);
    if ($status === null && $tags === []) {
        $page = loadSubsPage($formId, $effective, $limit, $offset, $from, $to, $total, $q);
        return viewerReviewBatch($page, $effective, $formId, null, []);
    }
    $allRecords = null;
    if (in_array($effective['storage'], ['file', 'csv'], true)) {
        // The legacy JSON sidecar is parsed once per filtered request, never once per submission batch.
        $allRecords = bbf_review_file_transaction($effective, $formId, false,
            static fn(array $document): array => $document['records']);
    } else {
        // Initialize and validate SQL review tables before opening an unbuffered submission cursor.
        bbf_review_records($effective, $formId, ['bbf_review_schema_probe']);
    }
    $rows = bbf_read_export($formId, $effective, PHP_INT_MAX, 0, $from, $to, $q);
    $total = 0; $page = []; $batch = [];
    $consume = static function (array $chunk) use (&$total, &$page, $effective, $formId, $status, $tags, $limit, $offset, $allRecords): void {
        foreach (viewerReviewBatch($chunk, $effective, $formId, $status, $tags, $allRecords) as $row) {
            if ($total >= $offset && count($page) < $limit) $page[] = $row;
            $total++;
        }
    };
    foreach ($rows as $row) {
        $batch[] = $row;
        if (count($batch) < 100) continue;
        $consume($batch); $batch = [];
    }
    if ($batch !== []) $consume($batch);
    return $page;
}

function loadPageFile(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    return bbf_read_page(bbf_read_files($formId, $dir, $from, $to, $q), $limit, $offset, $total);
}

function loadPageCsv(string $formId, string $dir, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    return bbf_read_page(bbf_read_csv($formId, $dir, $from, $to, $q), $limit, $offset, $total);
}

function loadPageDb(PDO $pdo, string $formId, int $limit, int $offset, ?string $from, ?string $to, ?int &$total, ?string $q = null): array {
    return bbf_read_db_page($pdo, $formId, $limit, $offset, $from, $to, $total, $q);
}

// ─── Storage: Load one ───────────────────────────────────────────
function loadOneSub(string $formId, string $subId, array $config): ?array { $config = bbf_effective_storage_config($config, $formId);
    $s = $config['storage'] ?? 'file';
    $subId = sanitizeId($subId);
    if (!$subId) return null;
    if ($s === 'file') {
        $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . "/$formId/$subId.json";
        return bbf_read_file($file, $formId);
    }
    if ($s === 'csv') {
        foreach (bbf_read_csv($formId, $config['submissions_dir'] ?? __DIR__ . '/submissions', null, null, null, $subId) as $sub) return $sub;
        return null;
    }
    $pdo = getDbConnection($config);
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND " . bbf_auth_form_sql($pdo));
    $stmt->execute([$subId, $formId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? dbRowToSub($row) : null;
}

// ─── Storage: Delete ─────────────────────────────────────────────
function deleteSubs(string $formId, array $subIds, array $config): array { $config = bbf_effective_storage_config($config, $formId);
    $s = $config['storage'] ?? 'file';
    if ($s === 'csv') return ['ok' => false, 'reason' => 'unsupported', 'deleted' => 0];
    $ids = [];
    foreach ($subIds as $subId) {
        $safeId = sanitizeId($subId);
        if ($safeId !== '') $ids[$safeId] = $safeId;
    }
    if ($ids === []) return ['ok' => true, 'deleted' => 0];

    $pdo = null;
    $stmt = null;
    if ($s !== 'file') {
        $pdo = getDbConnection($config);
        if (!$pdo) return ['ok' => false, 'reason' => 'primary', 'deleted' => 0];
        $stmt = $pdo->prepare("DELETE FROM bbf_submissions WHERE id = ? AND " . bbf_auth_form_sql($pdo));
    }
    $deletions = [];
    $deletedIds = [];
    $purgeIds = [];
    foreach ($ids as $subId) {
        $path = bbf_outbox_existing_path($config, $formId, $subId);
        if ($s === 'file') {
            $file = ($config['submissions_dir'] ?? __DIR__ . '/submissions') . "/$formId/$subId.json";
            $delete = static function () use ($file, $subId, &$deletedIds, &$purgeIds): array {
                $deleted = false; $absent = false;
                $locked = bbf_storage_locked($file, static function () use ($file, &$deleted, &$absent): bool {
                    clearstatcache(true, $file);
                    if (!is_file($file)) { $absent = true; return true; }
                    $deleted = @unlink($file);
                    return $deleted;
                });
                if ($locked && ($deleted || $absent)) $purgeIds[] = $subId;
                if ($locked && $deleted) $deletedIds[] = $subId;
                return ['ok' => $locked, 'deleted' => $locked && $deleted];
            };
        } else {
            $delete = static function () use ($stmt, $subId, $formId, &$deletedIds, &$purgeIds): array {
                try {
                    $stmt->execute([$subId, $formId]);
                    $deleted = $stmt->rowCount() > 0;
                    $purgeIds[] = $subId;
                    if ($deleted) $deletedIds[] = $subId;
                    return ['ok' => true, 'deleted' => $deleted];
                } catch (Throwable $error) {
                    error_log('BareBonesForms viewer delete: ' . $error->getMessage());
                    return ['ok' => false, 'deleted' => false];
                }
            };
        }
        $deletions[] = ['path' => $path, 'delete' => $delete];
    }
    $result = bbf_outbox_delete_submissions($deletions);
    if ($purgeIds !== []) {
        $purge = bbf_review_delete_records($config, $formId, array_values(array_unique($purgeIds)));
        if (!($purge['ok'] ?? false)) return ['ok' => false, 'reason' => 'review', 'deleted' => count($deletedIds)];
    }
    return $result;
}

function deleteSub(string $formId, string $subId, array $config): array {
    return deleteSubs($formId, [$subId], $config);
}

// ─── API Dispatcher ──────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'list_forms') {
    $files = glob($formsDir . '/*.json') ?: [];
    $list = [];
    foreach ($files as $f) {
        $id = basename($f, '.json');
        if ($id === 'form.schema' || !bbf_auth_can($principal, $id, 'read') || !bbf_auth_form_identity($config, $id)) continue;
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
        if ($id === 'form.schema' || !bbf_auth_can($principal, $id, 'read') || !bbf_auth_form_identity($config, $id)) continue;
        $def = @json_decode(file_get_contents($f), true);
        $formDefs[$id] = bbf_auth_presentation($def, $principal);
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
    $rawCriteria = [];
    foreach (['q', 'from', 'to', 'status', 'tags'] as $key) if (array_key_exists($key, $_GET)) $rawCriteria[$key] = $_GET[$key];
    $criteria = viewerSubmissionCriteria($rawCriteria);
    $includeReview = array_key_exists('status', $criteria ?? []) || array_key_exists('tags', $criteria ?? [])
        || ($_GET['review'] ?? null) === '1';
    if ($criteria === null || (array_key_exists('review', $_GET) && ($_GET['review'] ?? null) !== '1')) {
        viewerRespond(400, ['error' => 'Invalid submission filters.']);
    }
    $from = $criteria['from'] ?? null; $to = $criteria['to'] ?? null; $q = $criteria['q'] ?? null;
    $total = null;
    $canReview = bbf_auth_can($principal, $formId, 'review');
    $subs = $canReview && $includeReview
        ? loadReviewSubsPage($formId, $config, $limit, $offset, $from, $to, $total, $q,
            $criteria['status'] ?? null, $criteria['tags'] ?? [])
        : loadSubsPage($formId, $config, $limit, $offset, $from, $to, $total, $q);
    $defFile = $formsDir . '/' . $formId . '.json';
    $formDef = bbf_auth_presentation(file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null, $principal);
    viewerRespond(200, ['form' => $formId, 'submissions' => $subs, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'form_def' => $formDef]);
}

if ($action === 'detail' || $action === 'print') {
    $formId = sanitizeId($_GET['form'] ?? '');
    $subId  = sanitizeId($_GET['id'] ?? '');
    if (!$formId || !$subId) viewerRespond(400, ['error' => 'Missing form or submission ID.']);
    $sub = loadOneSub($formId, $subId, $config);
    if (!$sub || ($action === 'print' && (($sub['id'] ?? '') !== $subId || ($sub['form'] ?? '') !== $formId))) viewerRespond(404, ['error' => 'Submission not found.']);
    $defFile = $formsDir . '/' . $formId . '.json';
    $currentDef = file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null;
    $formDef = viewerSubmissionDefinition($sub, is_array($currentDef) ? $currentDef : null, $principal);
    $response = ['submission' => $sub, 'form_def' => $formDef];
    if ($action === 'detail') {
        $response['delivery'] = viewerDeliveryStatus($config, $formId, $subId, ($principal['admin'] ?? false) === true);
        if (bbf_auth_can($principal, $formId, 'review')) $response['review'] = bbf_review_get($config, $formId, $subId);
    }
    viewerRespond(200, $response);
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

if ($action === 'review_filters') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId || array_diff(array_keys($_GET), ['action', 'form', 'token'])) {
        viewerRespond(400, ['error' => 'Invalid saved-filter request.']);
    }
    $filters = bbf_review_filters($config, $formId, $principal['id']);
    viewerRespond(200, ['form' => $formId, 'filters' => $filters, '_audit_count' => count($filters)]);
}

if ($action === 'review_update') {
    if (array_diff(array_keys($accessBody), ['form', 'id', 'revision', 'patch'])
        || !is_int($accessBody['revision'] ?? null) || !is_array($accessBody['patch'] ?? null)) {
        viewerRespond(400, ['error' => 'Invalid review payload.']);
    }
    $formId = sanitizeId($accessBody['form'] ?? null); $subId = sanitizeId($accessBody['id'] ?? null);
    $sub = $formId && $subId ? loadOneSub($formId, $subId, $config) : null;
    if (!$sub || ($sub['id'] ?? '') !== $subId || ($sub['form'] ?? '') !== $formId) {
        viewerRespond(404, ['error' => 'Submission not found.']);
    }
    viewerReviewRespond(bbf_review_update($config, $formId, $subId, $principal['id'],
        $accessBody['patch'], $accessBody['revision']));
}

if ($action === 'review_filter_save') {
    if (array_diff(array_keys($accessBody), ['form', 'id', 'name', 'criteria', 'revision'])
        || !is_string($accessBody['name'] ?? null) || !is_array($accessBody['criteria'] ?? null)
        || !is_int($accessBody['revision'] ?? null)) viewerRespond(400, ['error' => 'Invalid review payload.']);
    viewerReviewRespond(bbf_review_filter_save($config, $accessForm, $principal['id'],
        (string)($accessBody['id'] ?? ''), $accessBody['name'], $accessBody['criteria'], $accessBody['revision']));
}

if ($action === 'review_filter_delete') {
    if (array_diff(array_keys($accessBody), ['form', 'id', 'revision'])
        || !is_int($accessBody['revision'] ?? null)) viewerRespond(400, ['error' => 'Invalid review payload.']);
    viewerReviewRespond(bbf_review_filter_delete($config, $accessForm, $principal['id'],
        (string)($accessBody['id'] ?? ''), $accessBody['revision']));
}

if ($action === 'retry_delivery') {
    checkViewerToken();
    $body = $accessBody;
    $formId = sanitizeId($body['form'] ?? null);
    $subId = sanitizeId($body['id'] ?? null);
    $jobKey = is_string($body['job'] ?? null) && preg_match('/\A[a-zA-Z0-9._:-]{1,160}\z/D', $body['job'])
        ? $body['job'] : '';
    if (!$formId || !$subId || !$jobKey
        || (array_key_exists('confirm_ambiguous', $body) && !is_bool($body['confirm_ambiguous']))) {
        viewerRespond(400, ['error' => 'Invalid form, submission, job, or confirmation value.']);
    }
    $sub = loadOneSub($formId, $subId, $config);
    if (!$sub || ($sub['id'] ?? '') !== $subId || ($sub['form'] ?? '') !== $formId) {
        viewerRespond(404, ['error' => 'Submission not found.']);
    }
    $deliveryConfig = bbf_effective_storage_config($config, $formId);
    $path = bbf_outbox_existing_path($deliveryConfig, $formId, $subId);
    if ($path === '' || !is_file($path)) viewerRespond(404, ['error' => 'Delivery state not found.']);
    $beforeRetry = viewerDeliveryStatus($config, $formId, $subId, true);
    if (!($beforeRetry['ok'] ?? false)) viewerRespond(503, ['error' => 'Delivery state could not be refreshed safely.']);
    $retry = bbf_outbox_retry($path, $jobKey, ($body['confirm_ambiguous'] ?? false) === true);
    if (!($retry['ok'] ?? false)) {
        $reason = (string)($retry['reason'] ?? 'unavailable');
        $code = $reason === 'missing' ? 404
            : (in_array($reason, ['lock', 'read', 'decode', 'encode', 'persist', 'operation', 'pending', 'running', 'backoff'], true) ? 503 : 409);
        $messages = [
            'missing' => 'Delivery job not found.',
            'confirmation_required' => 'Explicit confirmation is required because retry may duplicate delivery.',
            'succeeded' => 'Succeeded delivery jobs cannot be retried.',
            'exhausted' => 'Delivery retry limit has been reached.',
        ];
        viewerRespond($code, ['ok' => false, 'error' => $messages[$reason] ?? 'Delivery job cannot be retried.',
            'delivery' => viewerDeliveryStatus($config, $formId, $subId, true)]);
    }
    $actionResponse = [];
    $run = bbf_delivery_run_job($path, $jobKey, $deliveryConfig, $actionResponse, true);
    $delivery = viewerDeliveryStatus($config, $formId, $subId, true);
    $durableJob = null;
    if (($delivery['ok'] ?? false) === true) {
        foreach ($delivery['jobs'] ?? [] as $job) {
            if (is_array($job) && ($job['key'] ?? null) === $jobKey) { $durableJob = $job; break; }
        }
    }
    if (($run['ok'] ?? false) === true && ($durableJob['state'] ?? null) === 'succeeded') {
        viewerRespond(200, ['ok' => true, 'delivery' => $delivery]);
    }
    if (!is_array($durableJob)) {
        viewerRespond(503, ['ok' => false, 'error' => 'Cannot verify the durable delivery result.', 'delivery' => $delivery]);
    }
    $durableState = (string)($durableJob['state'] ?? 'failed');
    if (in_array($durableState, ['ambiguous', 'exhausted'], true)
        || ($durableState === 'failed' && ($durableJob['can_retry'] ?? false) !== true)) {
        viewerRespond(409, ['ok' => false, 'error' => 'Delivery retry reached a terminal or uncertain state.', 'delivery' => $delivery]);
    }
    viewerRespond(503, ['ok' => false, 'error' => 'Delivery retry did not succeed and may be retried.', 'delivery' => $delivery]);
}

if ($action === 'forward') {
    checkViewerToken();
    $body = $accessBody;
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
    $currentDef = file_exists($defFile) ? json_decode(file_get_contents($defFile), true) : null;
    $formDef = viewerSubmissionDefinition($sub, is_array($currentDef) ? $currentDef : null, $principal);
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
        $val = htmlspecialchars(viewerValueText($v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h .= '<tr><td style="padding:10px 12px;border:1px solid #e2e8f0;font-weight:600;background:#f8fafc;color:#475569;width:35%;font-size:13px">' . htmlspecialchars($label) . '</td>';
        $h .= '<td style="padding:10px 12px;border:1px solid #e2e8f0;font-size:14px;white-space:pre-wrap">' . ($val ?: '<span style="color:#94a3b8">-</span>') . '</td></tr>';
    }
    $h .= '</table></body></html>';
    $subject = htmlspecialchars_decode($formName) . ' — ' . $sub['id'];
    $errorsBefore = count($GLOBALS['_bbf_errors'] ?? []); try { sendEmail($to, $subject, $h, $config['mail']); if (count($GLOBALS['_bbf_errors'] ?? []) > $errorsBefore) viewerRespond(502, ['error' => 'Forward failed.']); } catch (Throwable $e) { viewerRespond(502, ['error' => 'Forward failed.']); }
    viewerRespond(200, ['ok' => true]);
}

if ($action === 'delete') {
    checkViewerToken();
    if (!$canDelete) viewerRespond(400, ['error' => 'Delete not supported for CSV storage.']);
    $body = $accessBody;
    $formId = sanitizeId($body['form'] ?? '');
    $subId  = sanitizeId($body['id'] ?? '');
    if (!$formId || !$subId) viewerRespond(400, ['error' => 'Missing form or submission ID.']);
    $result = deleteSub($formId, $subId, $config);
    if (!($result['ok'] ?? false)) {
        if (($result['reason'] ?? '') === 'running') viewerRespond(409, ['error' => 'Submission delivery is currently running.']);
        $deleted = max(0, (int)($result['deleted'] ?? 0));
        $response = ['error' => 'Submission could not be deleted safely.'];
        if ($deleted > 0) $response += ['deleted' => $deleted, '_audit_count' => $deleted];
        viewerRespond(503, $response);
    }
    if (($result['deleted'] ?? 0) !== 1) viewerRespond(404, ['error' => 'Submission not found or already deleted.']);
    viewerRespond(200, ['ok' => true]);
}

if ($action === 'bulk_delete') {
    checkViewerToken();
    if (!$canDelete) viewerRespond(400, ['error' => 'Delete not supported for CSV storage.']);
    $body = $accessBody;
    $formId = sanitizeId($body['form'] ?? '');
    $ids    = $body['ids'] ?? [];
    if (!$formId || !is_array($ids) || count($ids) === 0) viewerRespond(400, ['error' => 'Missing form or submission IDs.']);
    if (count($ids) > 100) viewerRespond(400, ['error' => 'Maximum 100 submissions per bulk delete.']);
    $result = deleteSubs($formId, $ids, $config);
    if (!($result['ok'] ?? false)) {
        if (($result['reason'] ?? '') === 'running') viewerRespond(409, ['error' => 'One or more submission deliveries are currently running.']);
        $deleted = max(0, (int)($result['deleted'] ?? 0));
        $response = ['error' => 'Submissions could not be deleted safely.'];
        if ($deleted > 0) $response += ['deleted' => $deleted, '_audit_count' => $deleted];
        viewerRespond(503, $response);
    }
    viewerRespond(200, ['ok' => true, 'deleted' => (int)($result['deleted'] ?? 0)]);
}

if ($action === 'export') {
    $formId = sanitizeId($_GET['form'] ?? '');
    if (!$formId) viewerRespond(400, ['error' => 'Missing form ID.']);
    $exportFrom = $_GET['from'] ?? null;
    $exportTo   = $_GET['to'] ?? null;
    $exportLimit = PHP_INT_MAX;
    $last = $_GET['last'] ?? '';
    if ($last !== '' && $exportFrom === null) {
        if (preg_match('/^(\d+)d$/i', $last, $m))      $exportFrom = date('Y-m-d', strtotime("-{$m[1]} days"));
        elseif (preg_match('/^(\d+)h$/i', $last, $m))   $exportFrom = date('c', strtotime("-{$m[1]} hours"));
        elseif (preg_match('/^(\d+)w$/i', $last, $m))    $exportFrom = date('Y-m-d', strtotime("-{$m[1]} weeks"));
        elseif (preg_match('/^(\d+)m$/i', $last, $m))    $exportFrom = date('Y-m-d', strtotime("-{$m[1]} months"));
        elseif (preg_match('/^(\d+)$/', $last, $m))       $exportLimit = max(1, intval($m[1]));
    }
    $total = null;
    $subs = bbf_read_export($formId, bbf_effective_storage_config($config, $formId), $exportLimit, 0, $exportFrom, $exportTo, trim($_GET['q'] ?? ''));
    $defFile = $formsDir . '/' . $formId . '.json';
    try {
        $def = bbf_export_definition($defFile);
        $prepared = bbf_export_prepare($def, $subs);
    } catch (Throwable $error) {
        error_log('BareBonesForms export: ' . $error->getMessage());
        viewerRespond(500, ['error' => 'Cannot prepare export.']);
    }
    unset($subs);
    $out = $prepared['stream'];
    try {
        bbf_access_finish($prepared['count']);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$formId}_submissions.csv");
        // Completion records successful preparation, not client receipt.
        while (!feof($out)) { $chunk = fread($out, 8192); if ($chunk === false) { error_log('BareBonesForms: Failed to transfer prepared CSV export.'); break; } echo $chunk; }
    } finally {
        fclose($out);
    }
    exit;
}

// ─── Prepare UI data ────────────────────────────────────────────
$formFiles = glob($formsDir . '/*.json') ?: [];
$formsList = [];
foreach ($formFiles as $f) {
    $id = basename($f, '.json');
    if ($id === 'form.schema' || !bbf_auth_can($principal, $id, 'read') || !bbf_auth_form_identity($config, $id)) continue;
    $def = @json_decode(file_get_contents($f), true);
    $formsList[] = ['id' => $id, 'name' => $def['name'] ?? $id, 'count' => countSubs($id, $config), 'can_delete' => bbf_effective_storage_config($config, $id, $def)['storage'] !== 'csv'];
}
usort($formsList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// ═════════════════════════════════════════════════════════════════
bbf_access_finish(count($formsList)); $canDelete = ['admin' => $principal['admin'], 'storage' => $canDelete, 'delete_forms' => array_column($formsList, 'can_delete', 'id'), 'forms' => in_array('read', $principal['permissions'], true) ? $principal['forms'] : [], 'permissions' => $principal['permissions']];
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
.review-toolbar { display: flex; align-items: end; gap: 8px; padding: 0 20px 12px; flex-wrap: wrap; }
.review-control { display: flex; flex-direction: column; gap: 3px; min-width: 130px; }
.review-control label { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); }
.review-control input, .review-control select, .review-card select, .review-card textarea { padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-surface); color: var(--text); font: inherit; }
.review-control input:focus, .review-control select:focus, .review-card select:focus, .review-card textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-light); outline: none; }
.review-saved-select { min-width: 180px; }
.review-badges { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 7px; }
.review-badge { display: inline-block; padding: 2px 7px; border-radius: 9px; background: var(--violet-bg); color: var(--violet-text); font-size: 0.7rem; }
.review-badge.status-done { background: var(--green-bg); color: var(--green-text); }
.review-badge.status-in-progress { background: var(--amber-bg); color: var(--amber-text); }
.review-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; margin-bottom: 16px; }
.review-card h2 { font-size: 0.85rem; margin-bottom: 12px; }
.review-fields { display: grid; grid-template-columns: minmax(130px, 180px) 1fr; gap: 12px; }
.review-card label { display: flex; flex-direction: column; gap: 4px; font-size: 0.75rem; font-weight: 600; color: var(--text-muted); }
.review-card textarea { min-height: 100px; resize: vertical; }
.review-notes { grid-column: 1 / -1; }
.review-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
.review-message { font-size: 0.78rem; color: var(--text-muted); }
@media (max-width: 640px) { .review-fields { grid-template-columns: 1fr; } .review-notes { grid-column: auto; } }

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

.delivery-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 16px; overflow: hidden; }
.delivery-summary { padding: 12px 16px; font-size: 0.82rem; color: var(--text-muted); border-bottom: 1px solid var(--border-light); }
.delivery-summary strong { color: var(--text); }
.delivery-jobs { list-style: none; }
.delivery-job { padding: 12px 16px; border-bottom: 1px solid var(--border-light); }
.delivery-job:last-child { border-bottom: none; }
.delivery-job-heading { font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }
.delivery-job-row { font-size: 0.8rem; color: var(--text-muted); margin-top: 3px; }
.delivery-job-row strong { color: var(--text); font-weight: 500; }
.delivery-confirm { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; color: var(--amber-text); font-size: 0.8rem; line-height: 1.4; }
.delivery-confirm input { margin-top: 2px; accent-color: var(--amber); }
.btn-retry-delivery { margin-top: 10px; padding: 6px 14px; border: 1px solid var(--amber); border-radius: 6px; background: var(--amber-bg); color: var(--amber-text); font-size: 0.82rem; cursor: pointer; }
.btn-retry-delivery:hover:not(:disabled) { filter: brightness(0.97); }
.btn-retry-delivery:disabled { opacity: 0.55; cursor: wait; }

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

/* ─── Filled stat cards ─── */
.stat-card::before { display: none; }
.stat-total { background: var(--accent); border-color: var(--accent); }
.stat-total .stat-value, .stat-total .stat-label { color: #fff; }
.stat-today { background: var(--green); border-color: var(--green); }
.stat-today .stat-value, .stat-today .stat-label { color: #fff; }
.stat-week { background: var(--amber); border-color: var(--amber); }
.stat-week .stat-value, .stat-week .stat-label { color: #fff; }
.stat-month { background: var(--violet); border-color: var(--violet); }
.stat-month .stat-value, .stat-month .stat-label { color: #fff; }

/* ─── View toggle ─── */
.view-toggle { display: flex; gap: 2px; background: var(--bg-alt); border-radius: 6px; padding: 2px; }
.view-toggle-btn { padding: 5px 10px; border: none; border-radius: 4px; background: transparent; color: var(--text-muted); font-size: 0.78rem; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 4px; }
.view-toggle-btn:hover { color: var(--text); }
.view-toggle-btn.active { background: var(--bg-surface); color: var(--text); box-shadow: var(--shadow-sm); }

/* ─── Bulk action bar ─── */
.bulk-bar { display: flex; align-items: center; gap: 12px; padding: 8px 20px; background: var(--accent-light); border-bottom: 2px solid var(--accent); flex-shrink: 0; animation: fadeIn 0.15s; }
.bulk-bar-count { font-size: 0.82rem; font-weight: 600; color: var(--accent-text); }
.bulk-bar .toolbar-btn { padding: 5px 12px; font-size: 0.78rem; }
.bulk-bar .btn-danger { background: var(--red); color: #fff; border-color: var(--red); }
.bulk-bar .btn-danger:hover { opacity: 0.85; }

/* ─── Checkbox ─── */
.sub-checkbox { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; flex-shrink: 0; }

/* ─── Card grid view ─── */
.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.grid-card { position: relative; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; cursor: pointer; transition: all 0.15s; display: flex; flex-direction: column; }
.grid-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.grid-card.selected { outline: 2px solid var(--accent); }
.grid-card-bar { height: 4px; flex-shrink: 0; }
.grid-card-bar.age-today { background: var(--green); }
.grid-card-bar.age-recent { background: var(--amber); }
.grid-card-bar.age-week { background: var(--violet); }
.grid-card-bar.age-older { background: var(--text-light); }
.grid-card-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px 4px; }
.grid-card-time { font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.grid-card-time.age-today { background: var(--green-bg); color: var(--green-text); }
.grid-card-time.age-recent { background: var(--amber-bg); color: var(--amber-text); }
.grid-card-time.age-week { background: var(--violet-bg); color: var(--violet-text); }
.grid-card-time.age-older { background: var(--bg-alt); color: var(--text-muted); }
.grid-card-check { color: var(--green); font-size: 0.9rem; }
.grid-card-body { flex: 1; padding: 2px 0; }
.grid-card-section { padding: 4px 14px 6px; }
.grid-card-section-title { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid var(--border-light); }
.grid-card-field { font-size: 0.8rem; color: var(--text); padding: 2px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.grid-card-field strong { font-weight: 500; color: var(--text-muted); margin-right: 4px; }
.grid-card-footer { display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; border-top: 1px solid var(--border-light); margin-top: auto; }
.grid-card-details { padding: 0; border: 0; background: none; font: inherit; font-size: 0.78rem; color: var(--accent-text); font-weight: 500; cursor: pointer; }
.grid-card-id { font-size: 0.68rem; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--text-light); }
.grid-card .sub-checkbox { position: absolute; top: 14px; right: 12px; z-index: 2; }

/* ─── Table view ─── */
.sub-table-wrap { overflow-x: auto; }
.sub-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.sub-table th { padding: 10px 12px; text-align: left; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); background: var(--bg-alt); border-bottom: 2px solid var(--border); white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.sub-table td { padding: 10px 12px; border-bottom: 1px solid var(--border-light); vertical-align: middle; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sub-table tbody tr { cursor: pointer; transition: background 0.1s; border-left: 3px solid transparent; }
.sub-table tbody tr:hover { background: var(--bg-alt); }
.sub-table tbody tr.age-today { border-left-color: var(--green); }
.sub-table tbody tr.age-recent { border-left-color: var(--amber); }
.sub-table tbody tr.age-week { border-left-color: var(--violet); }
.sub-table tbody tr.selected { background: var(--accent-light); }
.sub-table .row-num { color: var(--text-light); font-size: 0.75rem; width: 40px; text-align: center; }
.sub-table .row-time { white-space: nowrap; }
.sub-table .time-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 600; }
.sub-table .time-badge.age-today { background: var(--green-bg); color: var(--green-text); }
.sub-table .time-badge.age-recent { background: var(--amber-bg); color: var(--amber-text); }
.sub-table .time-badge.age-week { background: var(--violet-bg); color: var(--violet-text); }
.sub-table .time-badge.age-older { background: var(--bg-alt); color: var(--text-muted); }
.sub-table .cb-cell { width: 36px; text-align: center; }
</style>
</head>
<body>

<header class="viewer-header">
    <button class="btn-hamburger" id="btn-hamburger">&#9776;</button>
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="" class="header-logo"><?php endif; ?>
    <h1 id="header-title"><?= htmlspecialchars($siteName) ?></h1>
    <span class="header-spacer"></span>
    <span class="header-status" id="header-status" role="status" aria-live="polite"></span>
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
const CAN_DELETE = <?= json_encode($canDelete) ?>; const canOperate = (form, p) => CAN_DELETE === true || (!!CAN_DELETE && (p !== 'delete' || (CAN_DELETE.delete_forms?.[form] ?? CAN_DELETE.storage)) && (CAN_DELETE.admin || (CAN_DELETE.forms.includes(form) && CAN_DELETE.permissions.includes('read') && CAN_DELETE.permissions.includes(p))));
const canReview = form => CAN_DELETE !== true && canOperate(form, 'review');
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
        cards:'Cards', table:'Table', card_view:'Card view', table_view:'Table view',
        view_details:'View Details', selected:'selected', bulk_delete:'Delete selected',
        deselect_all:'Deselect all', select_all:'Select all', select_submission:'Select submission',
        bulk_confirm:'Delete {n} submissions?', bulk_deleted:'{n} deleted',
        delivery:'Delivery', delivery_state:'State', attempts:'Attempts', last_result:'Last result',
        next_retry:'Next retry', no_delivery_jobs:'No delivery jobs recorded.', retry_delivery:'Retry delivery',
        retrying_delivery:'Retrying...', confirm_ambiguous:'I understand this retry may duplicate the side effect.',
        confirmation_required:'Confirm the duplicate-delivery risk before retrying.', delivery_refreshed:'Delivery status refreshed.',
        review_status:'Status', review_all:'All statuses', review_new:'New', review_progress:'In progress', review_done:'Done',
        review_tags:'Tags (comma-separated)', saved_filters:'Saved filters', filter_name:'Filter name', save_filter:'Save filter',
        delete_filter:'Delete filter', save_review:'Save review', internal_notes:'Internal notes', review_saved:'Review saved.',
        filter_saved:'Filter saved.', filter_deleted:'Filter deleted.', review_conflict:'Someone else changed this review. Their current version was loaded; your edit was not saved.',
        filter_conflict:'Someone else changed this saved filter. The current version was loaded; retry after reviewing it.',
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
        cards:'Karty', table:'Tabuľka', card_view:'Kartové zobrazenie', table_view:'Tabuľkové zobrazenie',
        view_details:'Zobraziť detail', selected:'vybraných', bulk_delete:'Zmazať vybrané',
        deselect_all:'Zrušiť výber', select_all:'Vybrať všetky', select_submission:'Vybrať odoslanie',
        bulk_confirm:'Zmazať {n} odoslaní?', bulk_deleted:'{n} zmazaných',
        delivery:'Doručenie', delivery_state:'Stav', attempts:'Pokusy', last_result:'Posledný výsledok',
        next_retry:'Ďalší pokus', no_delivery_jobs:'Nie sú zaznamenané žiadne úlohy doručenia.', retry_delivery:'Zopakovať doručenie',
        retrying_delivery:'Opakujem...', confirm_ambiguous:'Rozumiem, že tento pokus môže zopakovať vedľajší účinok.',
        confirmation_required:'Pred opakovaním potvrďte riziko duplicitného doručenia.', delivery_refreshed:'Stav doručenia bol obnovený.',
        review_status:'Stav', review_all:'Všetky stavy', review_new:'Nové', review_progress:'Rozpracované', review_done:'Hotové',
        review_tags:'Tagy (oddelené čiarkou)', saved_filters:'Uložené filtre', filter_name:'Názov filtra', save_filter:'Uložiť filter',
        delete_filter:'Vymazať filter', save_review:'Uložiť spracovanie', internal_notes:'Interné poznámky', review_saved:'Spracovanie uložené.',
        filter_saved:'Filter uložený.', filter_deleted:'Filter vymazaný.', review_conflict:'Spracovanie medzitým zmenil iný používateľ. Načítala sa jeho aktuálna verzia; vaša úprava sa neuložila.',
        filter_conflict:'Uložený filter medzitým niekto zmenil. Načítala sa aktuálna verzia; po kontrole skúste znova.',
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
        cards:'Karten', table:'Tabelle', card_view:'Kartenansicht', table_view:'Tabellenansicht',
        view_details:'Details anzeigen', selected:'ausgewählt', bulk_delete:'Ausgewählte löschen',
        deselect_all:'Auswahl aufheben', select_all:'Alle auswählen', select_submission:'Einreichung auswählen',
        bulk_confirm:'{n} Einreichungen löschen?', bulk_deleted:'{n} gelöscht',
        delivery:'Zustellung', delivery_state:'Status', attempts:'Versuche', last_result:'Letztes Ergebnis',
        next_retry:'Nächster Versuch', no_delivery_jobs:'Keine Zustellungsaufgaben erfasst.', retry_delivery:'Zustellung wiederholen',
        retrying_delivery:'Wird wiederholt...', confirm_ambiguous:'Ich verstehe, dass dieser Versuch die Nebenwirkung duplizieren kann.',
        confirmation_required:'Bestätigen Sie vor dem erneuten Versuch das Risiko einer doppelten Zustellung.', delivery_refreshed:'Zustellungsstatus aktualisiert.',
        review_status:'Status', review_all:'Alle Status', review_new:'Neu', review_progress:'In Bearbeitung', review_done:'Erledigt',
        review_tags:'Tags (kommagetrennt)', saved_filters:'Gespeicherte Filter', filter_name:'Filtername', save_filter:'Filter speichern',
        delete_filter:'Filter löschen', save_review:'Bearbeitung speichern', internal_notes:'Interne Notizen', review_saved:'Bearbeitung gespeichert.',
        filter_saved:'Filter gespeichert.', filter_deleted:'Filter gelöscht.', review_conflict:'Eine andere Person hat diese Bearbeitung geändert. Ihre aktuelle Version wurde geladen; Ihre Änderung wurde nicht gespeichert.',
        filter_conflict:'Eine andere Person hat diesen Filter geändert. Die aktuelle Version wurde geladen; prüfen Sie sie vor einem neuen Versuch.',
    },
};
function t(key) { return (I18N[LANG] || I18N.en)[key] || I18N.en[key] || key; }
function parseReviewTags(value) {
    const source = Array.isArray(value) ? value : String(value || '').split(',');
    return [...new Set(source.map(tag => String(tag).trim()).filter(Boolean))].slice(0, 20);
}
function reviewCriteria() {
    const criteria = {};
    if (state.search) criteria.q = state.search;
    if (state.dateFrom) criteria.from = state.dateFrom;
    if (state.dateTo) criteria.to = state.dateTo;
    if (state.reviewStatus) criteria.status = state.reviewStatus;
    if (state.reviewTags.length) criteria.tags = [...state.reviewTags];
    return criteria;
}
function applySavedFilter(filter) {
    state.selectedFilter = filter?.id || '';
    if (!filter) return;
    const criteria = filter.criteria || {};
    state.search = criteria.q || ''; state.dateFrom = criteria.from || ''; state.dateTo = criteria.to || '';
    state.reviewStatus = criteria.status || ''; state.reviewTags = parseReviewTags(criteria.tags || []);
    state.page = 1;
}

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
    reviewStatus: '',
    reviewTags: [],
    savedFilters: [],
    selectedFilter: '',
    stats: null,
    detail: null,
    detailReturn: null,
    todayMap: {},
    deleteTimer: null,
    viewMode: localStorage.getItem('bbf_viewMode') || 'cards',
    selected: new Set(),
};

// ─── API ─────────────────────────────────────────────────────────
const api = {
    async dashboard() {
        const r = await fetch('viewer.php?action=dashboard');
        return r.json();
    },
    async submissions(formId, limit, offset, from, to, q, status = '', tags = []) {
        let url = `viewer.php?action=submissions&form=${encodeURIComponent(formId)}&limit=${limit}&offset=${offset}`;
        if (from) url += `&from=${encodeURIComponent(from)}`;
        if (to) url += `&to=${encodeURIComponent(to)}`;
        if (q) url += `&q=${encodeURIComponent(q)}`;
        if (canReview(formId)) {
            url += '&review=1';
            if (status) url += `&status=${encodeURIComponent(status)}`;
            tags.forEach(tag => { url += `&tags[]=${encodeURIComponent(tag)}`; });
        }
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
    async retryDelivery(formId, subId, job, confirmAmbiguous) {
        const r = await fetch('viewer.php?action=retry_delivery', {
            method: 'POST',
            body: JSON.stringify({ form: formId, id: subId, job, confirm_ambiguous: confirmAmbiguous }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Viewer-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Delivery retry failed');
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
    async reviewFilters(formId) {
        const r = await fetch(`viewer.php?action=review_filters&form=${encodeURIComponent(formId)}`);
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Saved filters could not be loaded.');
        return d.filters;
    },
    async reviewUpdate(formId, subId, revision, patch) {
        return this.reviewMutation('review_update', { form: formId, id: subId, revision, patch });
    },
    async saveReviewFilter(formId, filter) {
        return this.reviewMutation('review_filter_save', { form: formId, id: filter.id, name: filter.name,
            criteria: filter.criteria, revision: filter.revision });
    },
    async deleteReviewFilter(formId, id, revision) {
        return this.reviewMutation('review_filter_delete', { form: formId, id, revision });
    },
    async reviewMutation(action, body) {
        const r = await fetch(`viewer.php?action=${action}`, {
            method: 'POST', body: JSON.stringify(body),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Viewer-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) { const error = new Error(d.error || 'Review update failed.'); error.data = d; error.status = r.status; throw error; }
        return d;
    },
    async listForms() {
        const r = await fetch('viewer.php?action=list_forms');
        return r.json();
    },
    async bulkDelete(formId, ids) {
        const r = await fetch('viewer.php?action=bulk_delete', {
            method: 'POST',
            body: JSON.stringify({ form: formId, ids }),
            headers: { 'Content-Type': 'application/json', 'X-BBF-Viewer-Token': TOKEN },
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Bulk delete failed');
        return d;
    },
};

// ─── URL Hash Routing ────────────────────────────────────────────
let navigationRequest = 0;
let filterMutationRequest = 0;

function updateHash(replace = false) {
    const params = new URLSearchParams();
    if (state.view === 'form' && state.formId) {
        params.set('form', state.formId);
        if (state.detail) params.set('id', state.detail);
        if (state.page > 1) params.set('page', state.page);
        if (state.search) params.set('q', state.search);
        if (state.dateFrom) params.set('from', state.dateFrom);
        if (state.dateTo) params.set('to', state.dateTo);
        if (state.reviewStatus) params.set('status', state.reviewStatus);
        state.reviewTags.forEach(tag => params.append('tag', tag));
        if (state.selectedFilter) params.set('saved', state.selectedFilter);
    }
    const hash = params.size ? '#' + params.toString() : '';
    const method = replace || hash === location.hash ? 'replaceState' : 'pushState';
    history[method]({ detailReturn: state.detailReturn }, '', location.pathname + location.search + hash);
}

function readHash() {
    const values = new URLSearchParams(location.hash.slice(1));
    return { ...Object.fromEntries(values), tags: values.getAll('tag') };
}

function restoreRoute() {
    const params = readHash();
    if (params.form) {
        return selectForm(params.form, {
            detail: params.id || null, page: Math.max(1, parseInt(params.page) || 1),
            search: params.q || '', dateFrom: params.from || '', dateTo: params.to || '',
            reviewStatus: params.status || '', reviewTags: params.tags || [], selectedFilter: params.saved || '',
            detailReturn: history.state?.detailReturn ?? null, replace: true,
        });
    }
    return showDashboard(true);
}

function backFromDetail() {
    if (state.detailReturn !== null) {
        history.back();
        return;
    }
    // A bookmarked detail has no originating list in this tab's history.
    return selectForm(state.formId, {
        page: state.page, search: state.search, dateFrom: state.dateFrom, dateTo: state.dateTo,
        reviewStatus: state.reviewStatus, reviewTags: state.reviewTags, selectedFilter: state.selectedFilter,
    });
}

window.addEventListener('hashchange', restoreRoute);

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
async function showDashboard(replace = false) {
    const request = ++navigationRequest;
    clearTimeout(searchTimer);
    state.view = 'dashboard';
    state.formId = null;
    state.detail = null;
    state.detailReturn = null;
    document.title = SITE_NAME;
    updateHash(replace);
    renderFormList(FORMS);
    panelMain.innerHTML = `<div class="loading">${esc(t('loading_dash'))}</div>`;
    try {
        const data = await api.dashboard();
        if (request !== navigationRequest) return;
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
        html += `<div class="dash-form-card" data-form="${esc(f.id)}" role="button" tabindex="0">`;
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
            const formDef = sub.meta?.form_definition || state.dashDefs[sub.form] || null;
            const labelMap = buildLabelMap(formDef);
            const previewKeys = getPreviewKeys(formDef, sub.data || {});
            const todayCls = isToday(sub.meta?.submitted) ? ' sub-card-today' : '';
            html += `<div class="sub-card${todayCls}" data-id="${esc(sub.id)}" data-form="${esc(sub.form)}" role="button" tabindex="0">`;
            html += `<div class="sub-card-header"><span class="sub-time">${esc(time)}</span>`;
            html += `<span><span class="sub-id">${esc(sub.id)}</span><span class="sub-card-form-tag">${esc(formName)}</span></span></div>`;
            html += `<div class="sub-preview">`;
            previewKeys.forEach(k => {
                const v = (sub.data || {})[k];
                if (v === undefined || v === null) return;
                const val = valueText(v);
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
    const request = ++navigationRequest;
    clearTimeout(searchTimer);
    state.view = 'form';
    state.formId = formId;
    state.page = opts?.page || 1;
    state.detail = opts?.detail || null;
    state.detailReturn = state.detail ? (opts?.detailReturn ?? null) : null;
    state.search = opts?.search || '';
    state.dateFrom = opts?.dateFrom || '';
    state.dateTo = opts?.dateTo || '';
    state.reviewStatus = canReview(formId) && ['new', 'in-progress', 'done'].includes(opts?.reviewStatus) ? opts.reviewStatus : '';
    state.reviewTags = canReview(formId) ? parseReviewTags(opts?.reviewTags || []) : [];
    state.selectedFilter = canReview(formId) ? (opts?.selectedFilter || '') : '';
    state.savedFilters = [];
    state.subs = [];
    state.stats = null;
    state.total = 0;
    state.selected.clear();
    renderFormList(FORMS);
    document.title = formId + ' — ' + SITE_NAME;
    updateHash(opts?.replace);

    if (state.detail) {
        panelMain.innerHTML = '<div class="loading">Loading...</div>';
        try {
            const data = await api.detail(formId, state.detail);
            if (request !== navigationRequest) return;
            if (state.detailReturn !== '') {
                const list = await api.submissions(formId, state.perPage, (state.page - 1) * state.perPage,
                    state.dateFrom, state.dateTo, state.search, state.reviewStatus, state.reviewTags);
                if (request !== navigationRequest) return;
                state.subs = list.submissions;
            }
            state.formDef = data.form_def;
            state.labelMap = buildLabelMap(state.formDef);
            renderDetailView(data.submission, data.form_def, data.delivery, data.review);
        } catch (err) { flash(err.message, true); }
        return;
    }

    renderMainLoading();
    try {
        const [statsData, subsData, filters] = await Promise.all([
            api.stats(formId),
            api.submissions(formId, state.perPage, (state.page - 1) * state.perPage, state.dateFrom, state.dateTo,
                state.search, state.reviewStatus, state.reviewTags),
            canReview(formId) ? api.reviewFilters(formId) : [],
        ]);
        if (request !== navigationRequest) return;
        state.stats = statsData;
        state.subs = subsData.submissions;
        state.savedFilters = filters;
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
    const selectedSaved = state.savedFilters.find(filter => filter.id === state.selectedFilter) || null;
    let html = '';
    html += '<div class="stats-bar">';
    html += statCard('stat-total', state.stats?.total ?? 0, t('total'));
    html += statCard('stat-today', state.stats?.today ?? 0, t('today'));
    html += statCard('stat-week', state.stats?.this_week ?? 0, t('this_week'));
    html += statCard('stat-month', state.stats?.this_month ?? 0, t('this_month'));
    html += '</div>';

    // Bulk action bar
    if (state.selected.size > 0 && canOperate(state.formId, 'delete')) {
        html += `<div class="bulk-bar" id="bulk-bar">`;
        html += `<span class="bulk-bar-count">${state.selected.size} ${esc(t('selected'))}</span>`;
        html += `<button class="toolbar-btn btn-danger" id="bulk-delete">${esc(t('bulk_delete'))}</button>`;
        html += `<button class="toolbar-btn" id="bulk-cancel">${esc(t('deselect_all'))}</button>`;
        html += `<span class="toolbar-spacer"></span>`;
        html += `</div>`;
    }

    html += '<div class="toolbar">';
    html += `<input type="text" id="search-input" placeholder="${esc(t('search'))}" value="${esc(state.search)}">`;
    html += `<input type="date" id="filter-from" value="${esc(state.dateFrom)}">`;
    html += `<span class="toolbar-sep">${esc(t('date_to'))}</span>`;
    html += `<input type="date" id="filter-to" value="${esc(state.dateTo)}">`;
    html += `<button class="toolbar-btn" id="btn-filter">${esc(t('filter'))}</button>`;
    html += `<span class="toolbar-spacer"></span>`;
    // View toggle
    html += `<div class="view-toggle">`;
    html += `<button class="view-toggle-btn${state.viewMode === 'cards' ? ' active' : ''}" data-mode="cards" title="${esc(t('card_view'))}">&#9638; ${esc(t('cards'))}</button>`;
    html += `<button class="view-toggle-btn${state.viewMode === 'table' ? ' active' : ''}" data-mode="table" title="${esc(t('table_view'))}">&#9776; ${esc(t('table'))}</button>`;
    html += `</div>`;
    html += `<span class="toolbar-count" id="toolbar-count">${state.total} ${esc(t('submissions'))}</span>`;
    if (canOperate(state.formId, 'export')) html += `<button class="toolbar-btn btn-accent" id="btn-export">${esc(t('export_csv'))}</button>`;
    html += '</div>';
    if (canReview(state.formId)) {
        html += '<div class="review-toolbar" aria-label="' + esc(t('saved_filters')) + '">';
        html += `<div class="review-control"><label for="review-status-filter">${esc(t('review_status'))}</label>`
            + `<select id="review-status-filter"><option value="">${esc(t('review_all'))}</option>`
            + `<option value="new"${state.reviewStatus === 'new' ? ' selected' : ''}>${esc(t('review_new'))}</option>`
            + `<option value="in-progress"${state.reviewStatus === 'in-progress' ? ' selected' : ''}>${esc(t('review_progress'))}</option>`
            + `<option value="done"${state.reviewStatus === 'done' ? ' selected' : ''}>${esc(t('review_done'))}</option></select></div>`;
        html += `<div class="review-control"><label for="review-tags-filter">${esc(t('review_tags'))}</label>`
            + `<input id="review-tags-filter" type="text" value="${esc(state.reviewTags.join(', '))}"></div>`;
        html += `<div class="review-control review-saved-select"><label for="saved-filter-select">${esc(t('saved_filters'))}</label>`
            + `<select id="saved-filter-select"><option value="">—</option>`
            + state.savedFilters.map(filter => `<option value="${esc(filter.id)}"${filter.id === state.selectedFilter ? ' selected' : ''}>${esc(filter.name)}</option>`).join('')
            + '</select></div>';
        html += `<div class="review-control"><label for="saved-filter-name">${esc(t('filter_name'))}</label>`
            + `<input id="saved-filter-name" type="text" maxlength="120" value="${esc(selectedSaved?.name || '')}"></div>`;
        html += `<button type="button" class="toolbar-btn" id="save-review-filter">${esc(t('save_filter'))}</button>`;
        if (selectedSaved) html += `<button type="button" class="toolbar-btn" id="delete-review-filter">${esc(t('delete_filter'))}</button>`;
        html += '</div>';
    }
    html += '<div class="content" id="content">';
    html += state.viewMode === 'table' ? renderTable() : renderCardsGrid();
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
    // Explicit preview_fields in form definition takes priority
    if (formDef?.preview_fields && Array.isArray(formDef.preview_fields)) {
        return formDef.preview_fields.filter(k => data[k] !== undefined && data[k] !== null);
    }
    if (!formDef?.fields) {
        // No form def: filter out group-like keys
        return dataKeys.filter(k => data[k] !== '' && data[k] !== null && !k.endsWith('_row')).slice(0, 5);
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
    // Prefer name-like, email, tel, company fields first
    const namePattern = /^(first_name|last_name|name|full_name|company|email|phone|tel)$/i;
    const preferred = allFields.filter(f => namePattern.test(f.name) || f.type === 'email' || f.type === 'tel');
    const rest = allFields.filter(f => !preferred.includes(f));
    const ordered = [...preferred, ...rest];
    return ordered.slice(0, 5).map(f => f.name);
}

function statCard(cls, value, label) {
    return `<div class="stat-card ${cls}"><div class="stat-value">${value}</div><div class="stat-label">${label}</div></div>`;
}

function renderReviewBadges(review) {
    if (!review || typeof review !== 'object') return '';
    const status = ['new', 'in-progress', 'done'].includes(review.status) ? review.status : 'new';
    let html = `<div class="review-badges" aria-label="${esc(t('review_status'))}">`;
    html += `<span class="review-badge status-${esc(status)}">${esc(status)}</span>`;
    (Array.isArray(review.tags) ? review.tags : []).forEach(tag => { html += `<span class="review-badge">${esc(tag)}</span>`; });
    return html + '</div>';
}

function renderCards(subs, opts) {
    const items = subs || state.subs;
    if (items.length === 0) {
        return `<div class="empty-state"><div class="empty-state-icon">&#128203;</div>`
            + `<h3>${esc(t('no_subs'))}</h3><p>${esc(t('no_match'))}</p></div>`;
    }
    return items.map(sub => {
        const time = relativeTime(sub.meta?.submitted);
        const formDef = sub.meta?.form_definition || state.formDef;
        const labelMap = buildLabelMap(formDef);
        const previewKeys = getPreviewKeys(formDef, sub.data || {});
        const preview = previewKeys.map(k => [k, (sub.data || {})[k]]).filter(([,v]) => v !== undefined);
        const formTag = opts?.showForm ? `<span class="sub-card-form-tag">${esc(sub.form_name || sub.form)}</span>` : '';
        const todayCls = isToday(sub.meta?.submitted) ? ' sub-card-today' : '';
        return `<div class="sub-card${todayCls}" data-id="${esc(sub.id)}"${opts?.showForm ? ` data-form="${esc(sub.form)}"` : ''} role="button" tabindex="0">` +
            `<div class="sub-card-header">` +
            `<span class="sub-time">${esc(time)}</span>` +
            `<span><span class="sub-id">${esc(sub.id)}</span>${formTag}</span></div>` +
            `<div class="sub-preview">` +
            preview.map(([k, v]) => {
                const val = valueText(v);
                const display = val.length > 60 ? val.substring(0, 60) + '...' : val;
                const label = labelMap[k] || k;
                return `<span class="sub-field"><strong>${esc(label)}:</strong> ${esc(display)}</span>`;
            }).join('') +
            `</div></div>`;
    }).join('');
}

// ─── Age classification ──────────────────────────────────────────
function getAgeCls(iso) {
    if (!iso) return 'age-older';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 86400) return 'age-today';
    if (diff < 259200) return 'age-recent';
    if (diff < 604800) return 'age-week';
    return 'age-older';
}

// ─── Card sections from form definition ──────────────────────────
function getCardSections(formDef, data) {
    if (!formDef?.fields) {
        const keys = Object.keys(data).filter(k => data[k] !== '' && data[k] !== null).slice(0, 6);
        return [{ title: '', fields: keys.map(k => ({ key: k, label: state.labelMap[k] || k, value: data[k] })) }];
    }
    const sections = [];
    let current = { title: '', fields: [] };
    let totalFields = 0;

    function walk(fieldList) {
        for (const f of fieldList) {
            if (totalFields >= 6 || sections.length >= 3) return;
            if (f.type === 'section') {
                if (current.fields.length > 0) sections.push(current);
                if (sections.length >= 3) return;
                current = { title: f.title || f.label || f.name, fields: [] };
                continue;
            }
            if (f.type === 'page_break' || f.type === 'hidden') continue;
            if (f.type === 'group' && f.fields) { walk(f.fields); continue; }
            const val = data[f.name];
            if (val === undefined || val === null || val === '') continue;
            current.fields.push({ key: f.name, label: f.label || f.name, value: val });
            totalFields++;
        }
    }
    walk(formDef.fields);
    if (current.fields.length > 0 && sections.length < 3) sections.push(current);
    return sections;
}

// ─── Table columns from form definition ──────────────────────────
function getTableColumns(formDef, subs) {
    const columns = new Map();
    function addDefinition(definition) {
        function walk(fields) {
            for (const f of fields || []) {
                if (f.type === 'group' && f.fields) { walk(f.fields); continue; }
                if (['section', 'page_break', 'hidden'].includes(f.type)) continue;
                if (f.name && !columns.has(f.name)) columns.set(f.name, f.label || f.name);
            }
        }
        if (definition?.fields) walk(definition.fields);
    }
    addDefinition(formDef);
    subs.forEach(sub => addDefinition(sub.meta?.form_definition));
    subs.forEach(sub => Object.keys(sub.data || {}).forEach(key => {
        if (!columns.has(key)) columns.set(key, key);
    }));
    return [...columns].map(([key, label]) => ({ key, label }));
}

// ─── Render: Card grid ───────────────────────────────────────────
function renderCardsGrid() {
    const items = state.subs;
    if (items.length === 0) {
        return `<div class="empty-state"><div class="empty-state-icon">&#128203;</div>`
            + `<h3>${esc(t('no_subs'))}</h3><p>${esc(t('no_match'))}</p></div>`;
    }
    let html = '<div class="cards-grid">';
    items.forEach(sub => {
        const time = relativeTime(sub.meta?.submitted);
        const ageCls = getAgeCls(sub.meta?.submitted);
        const formDef = sub.meta?.form_definition || state.formDef;
        const sections = getCardSections(formDef, sub.data || {});
        const isSelected = state.selected.has(sub.id);
        const todayCheck = isToday(sub.meta?.submitted) ? `<span class="grid-card-check">&#10003;</span>` : '';

        html += `<div class="grid-card${isSelected ? ' selected' : ''}" data-id="${esc(sub.id)}">`;
        html += `<div class="grid-card-bar ${ageCls}"></div>`;
        if (canOperate(state.formId, 'delete')) html += `<input type="checkbox" class="sub-checkbox" data-id="${esc(sub.id)}" aria-label="${esc(t('select_submission'))}: ${esc(sub.id)}"${isSelected ? ' checked' : ''}>`;
        html += `<div class="grid-card-header">`;
        html += `<span class="grid-card-time ${ageCls}">${esc(time)}</span>`;
        html += todayCheck;
        html += `</div>`;
        html += `<div class="grid-card-body">`;
        sections.forEach(section => {
            html += `<div class="grid-card-section">`;
            if (section.title) html += `<div class="grid-card-section-title">${esc(section.title)}</div>`;
            section.fields.forEach(f => {
                const val = valueText(f.value);
                const display = val.length > 35 ? val.substring(0, 35) + '...' : val;
                html += `<div class="grid-card-field"><strong>${esc(f.label)}:</strong> ${esc(display)}</div>`;
            });
            html += `</div>`;
        });
        html += renderReviewBadges(sub.review);
        html += `</div>`;
        html += `<div class="grid-card-footer">`;
        html += `<button type="button" class="grid-card-details grid-card-open" data-id="${esc(sub.id)}">${esc(t('view_details'))}</button>`;
        html += `<span class="grid-card-id">${esc(sub.id.length > 14 ? sub.id.substring(0, 14) + '...' : sub.id)}</span>`;
        html += `</div></div>`;
    });
    html += '</div>';
    return html;
}

// ─── Render: Table ───────────────────────────────────────────────
function renderTable() {
    const items = state.subs;
    if (items.length === 0) {
        return `<div class="empty-state"><div class="empty-state-icon">&#128203;</div>`
            + `<h3>${esc(t('no_subs'))}</h3><p>${esc(t('no_match'))}</p></div>`;
    }
    const cols = getTableColumns(state.formDef, items);
    const offset = (state.page - 1) * state.perPage;
    const allChecked = items.length > 0 && items.every(s => state.selected.has(s.id));

    let html = '<div class="sub-table-wrap"><table class="sub-table">';
    html += '<thead><tr>';
    if (canOperate(state.formId, 'delete')) html += `<th class="cb-cell"><input type="checkbox" class="sub-checkbox" id="select-all" aria-label="${esc(t('select_all'))}"${allChecked ? ' checked' : ''}></th>`;
    html += `<th class="row-num">#</th>`;
    html += `<th>${esc(t('submitted'))}</th>`;
    if (canReview(state.formId)) html += `<th>${esc(t('review_status'))}</th>`;
    cols.forEach(c => { html += `<th>${esc(c.label)}</th>`; });
    html += `<th>${esc(t('view_details'))}</th>`;
    html += '</tr></thead><tbody>';

    items.forEach((sub, idx) => {
        const ageCls = getAgeCls(sub.meta?.submitted);
        const time = relativeTime(sub.meta?.submitted);
        const isSelected = state.selected.has(sub.id);
        html += `<tr class="${ageCls}${isSelected ? ' selected' : ''}" data-id="${esc(sub.id)}">`;
        if (canOperate(state.formId, 'delete')) html += `<td class="cb-cell"><input type="checkbox" class="sub-checkbox" data-id="${esc(sub.id)}" aria-label="${esc(t('select_submission'))}: ${esc(sub.id)}"${isSelected ? ' checked' : ''}></td>`;
        html += `<td class="row-num">${offset + idx + 1}</td>`;
        html += `<td class="row-time"><span class="time-badge ${ageCls}">${esc(time)}</span></td>`;
        if (canReview(state.formId)) html += `<td>${renderReviewBadges(sub.review)}</td>`;
        cols.forEach(c => {
            const v = (sub.data || {})[c.key];
            const val = valueText(v);
            const display = val.length > 40 ? val.substring(0, 40) + '...' : val;
            html += `<td title="${esc(val)}">${esc(display)}</td>`;
        });
        html += `<td><button type="button" class="toolbar-btn table-row-open" data-id="${esc(sub.id)}">${esc(t('view_details'))}</button></td>`;
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
}

// ─── Bulk bar dynamic update ─────────────────────────────────────
function updateBulkBar() {
    const existing = document.getElementById('bulk-bar');
    if (state.selected.size > 0 && canOperate(state.formId, 'delete')) {
        if (existing) {
            existing.querySelector('.bulk-bar-count').textContent = state.selected.size + ' ' + t('selected');
        } else {
            const toolbar = panelMain.querySelector('.toolbar');
            if (toolbar) {
                const bar = document.createElement('div');
                bar.className = 'bulk-bar';
                bar.id = 'bulk-bar';
                bar.innerHTML = `<span class="bulk-bar-count">${state.selected.size} ${esc(t('selected'))}</span>`
                    + `<button class="toolbar-btn btn-danger" id="bulk-delete">${esc(t('bulk_delete'))}</button>`
                    + `<button class="toolbar-btn" id="bulk-cancel">${esc(t('deselect_all'))}</button>`
                    + `<span class="toolbar-spacer"></span>`;
                toolbar.before(bar);
            }
        }
    } else if (existing) {
        existing.remove();
    }
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
    const request = ++navigationRequest;
    updateHash();
    const offset = (state.page - 1) * state.perPage;
    try {
        const data = await api.submissions(state.formId, state.perPage, offset, state.dateFrom || null,
            state.dateTo || null, state.search || null, state.reviewStatus, state.reviewTags);
        if (request !== navigationRequest) return;
        state.subs = data.submissions;
        state.total = data.total;
        const content = document.getElementById('content');
        const pagination = document.getElementById('pagination');
        const count = document.getElementById('toolbar-count');
        if (content) content.innerHTML = state.viewMode === 'table' ? renderTable() : renderCardsGrid();
        if (pagination) pagination.innerHTML = renderPagination();
        if (count) count.textContent = state.total + ' submission' + (state.total !== 1 ? 's' : '');
        updateHash();
    } catch (err) { flash(err.message, true); }
}

// ─── Detail view ─────────────────────────────────────────────────
async function openDetail(subId, formId) {
    clearTimeout(searchTimer);
    const replace = !!state.detail;
    const detailReturn = replace ? state.detailReturn : location.hash;
    if (formId && formId !== state.formId) {
        return selectForm(formId, { detail: subId, detailReturn });
    }
    const request = ++navigationRequest;
    state.detailReturn = detailReturn;
    state.detail = subId;
    updateHash(replace);
    const content = document.getElementById('content');
    if (content) content.innerHTML = '<div class="loading">Loading...</div>';
    const pagination = document.getElementById('pagination');
    if (pagination) pagination.innerHTML = '';
    try {
        const data = await api.detail(state.formId, subId);
        if (request !== navigationRequest) return;
        state.formDef = data.form_def;
        state.labelMap = buildLabelMap(state.formDef);
        renderDetailView(data.submission, data.form_def, data.delivery, data.review);
    } catch (err) { flash(err.message, true); }
}

function renderDetailView(sub, formDef, delivery, review) {
    state.detail = sub.id;
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
    if (canOperate(state.formId, 'export')) html += `<button class="btn-action btn-forward" id="btn-forward">&#9993; ${esc(t('forward'))}</button>`;
    if (canOperate(state.formId, 'export')) html += `<button class="btn-action btn-print" id="btn-print">&#128438; ${esc(t('pdf'))}</button>`;
    if (canOperate(state.formId, 'delete')) html += `<button class="btn-delete" id="btn-del" data-id="${esc(sub.id)}">${esc(t('del'))}</button>`;
    html += '</div></div>';

    const fields = formDef?.fields || [];
    const dataKeys = Object.keys(sub.data || {});
    const sections = buildDetailSections(sub.data, fields, dataKeys);

    sections.forEach(section => {
        html += '<div class="detail-card">';
        if (section.title) html += `<div class="detail-section-title">${esc(section.title)}</div>`;
        section.fields.forEach(f => {
            const rawVal = valueText(f.value);
            html += `<div class="detail-field">`;
            html += `<div class="detail-label">${esc(f.label)}</div>`;
            html += `<div class="detail-value">${formatValue(f.value, f.type)}</div>`;
            html += `<button class="btn-copy" data-val="${esc(rawVal)}">${esc(t('copy'))}</button>`;
            html += '</div>';
        });
        html += '</div>';
    });

    html += renderReviewPanel(review);
    html += renderDeliveryPanel(delivery);
    html += `<div class="meta-card"><h4>${esc(t('meta'))}</h4>`;
    if (sub.meta?.submitted) html += `<div class="meta-row"><strong>${esc(t('submitted'))}</strong> ${esc(sub.meta.submitted)}</div>`;
    if (sub.meta?.ip) html += `<div class="meta-row"><strong>${esc(t('ip'))}</strong> ${esc(sub.meta.ip)}</div>`;
    if (sub.meta?.user_agent) html += `<div class="meta-row"><strong>${esc(t('ua'))}</strong> ${esc(sub.meta.user_agent)}</div>`;
    html += '</div></div>';

    // Replace full panelMain for detail view
    panelMain.innerHTML = '<div class="content" id="content">' + html + '</div><div class="pagination" id="pagination"></div>';

    // Store current safe detail data for operations and status refreshes.
    panelMain._currentSub = sub;
    panelMain._currentFormDef = formDef;
    panelMain._currentDelivery = delivery;
    panelMain._currentReview = review;
}

function renderReviewPanel(review) {
    if (!canReview(state.formId) || !review || typeof review !== 'object') return '';
    const status = ['new', 'in-progress', 'done'].includes(review.status) ? review.status : 'new';
    const tags = Array.isArray(review.tags) ? review.tags.join(', ') : '';
    return `<section class="review-card" aria-labelledby="review-title">`
        + `<h2 id="review-title">${esc(t('internal_notes'))}</h2><div class="review-fields">`
        + `<label for="review-status">${esc(t('review_status'))}<select id="review-status">`
        + `<option value="new"${status === 'new' ? ' selected' : ''}>${esc(t('review_new'))}</option>`
        + `<option value="in-progress"${status === 'in-progress' ? ' selected' : ''}>${esc(t('review_progress'))}</option>`
        + `<option value="done"${status === 'done' ? ' selected' : ''}>${esc(t('review_done'))}</option></select></label>`
        + `<label for="review-tags">${esc(t('review_tags'))}<input id="review-tags" type="text" value="${esc(tags)}"></label>`
        + `<label class="review-notes" for="review-notes">${esc(t('internal_notes'))}<textarea id="review-notes" maxlength="20000">${esc(review.notes || '')}</textarea></label>`
        + `</div><div class="review-actions"><button type="button" class="toolbar-btn btn-accent" id="save-review">${esc(t('save_review'))}</button>`
        + `<span class="review-message" id="review-message" role="status" aria-live="polite"></span></div></section>`;
}

function renderDeliveryPanel(delivery) {
    if (!delivery || typeof delivery !== 'object') return '';
    const jobs = Array.isArray(delivery.jobs) ? delivery.jobs : [];
    let html = `<section class="delivery-card" aria-labelledby="delivery-title"><h2 class="detail-section-title" id="delivery-title">${esc(t('delivery'))}</h2>`;
    html += `<div class="delivery-summary"><strong>${esc(t('delivery_state'))}:</strong> ${esc(delivery.state)}</div>`;
    if (jobs.length === 0) html += `<div class="delivery-summary">${esc(t('no_delivery_jobs'))}</div>`;
    else {
        html += '<ul class="delivery-jobs">';
        jobs.forEach((job, index) => {
            const confirmId = `delivery-confirm-${index}`;
            const result = job.last_result && typeof job.last_result === 'object' ? job.last_result : null;
            html += `<li class="delivery-job"><h3 class="delivery-job-heading">${esc(job.type)} — ${esc(job.key)}</h3>`;
            html += `<div class="delivery-job-row"><strong>${esc(t('delivery_state'))}:</strong> ${esc(job.state)}</div>`;
            html += `<div class="delivery-job-row"><strong>${esc(t('attempts'))}:</strong> ${esc(job.attempts)} / ${esc(job.max_attempts)}</div>`;
            if (result) html += `<div class="delivery-job-row"><strong>${esc(t('last_result'))}:</strong> ${esc(result.message)} (${esc(result.stage)}${result.code ? ' ' + esc(result.code) : ''})</div>`;
            if (job.next_retry !== null && job.next_retry !== undefined) html += `<div class="delivery-job-row"><strong>${esc(t('next_retry'))}:</strong> ${esc(new Date(Number(job.next_retry) * 1000).toLocaleString())}</div>`;
            if (job.can_retry === true) {
                if (job.requires_confirmation === true) html += `<label class="delivery-confirm" for="${esc(confirmId)}"><input type="checkbox" id="${esc(confirmId)}"> <span>${esc(t('confirm_ambiguous'))}</span></label>`;
                html += `<button type="button" class="btn-retry-delivery" data-job="${esc(job.key)}" data-confirm-id="${job.requires_confirmation === true ? esc(confirmId) : ''}" aria-label="${esc(t('retry_delivery') + ': ' + String(job.key))}">${esc(t('retry_delivery'))}</button>`;
            }
            html += '</li>';
        });
        html += '</ul>';
    }
    return html + '</section>';
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
// This application export is freshly authorized/audited by the server. Native
// browser/OS printing of previously disclosed data cannot be revoked or audited.
// A completed viewer_print audit means release of one fresh printable record,
// not proof that a user saved a PDF or completed the operating-system dialog.
async function printSubmission(formId, subId) {
    const win = window.open('', '_blank'); // Keep user activation; never populate from cache.
    if (!win) { flash(t('popup_blocked'), true); return; }
    const controller = new AbortController();
    const timeout = setTimeout(() => { controller.abort(); win.close(); }, 15000);
    try {
    win.opener = null;
    const response = await fetch(`viewer.php?action=print&form=${encodeURIComponent(formId)}&id=${encodeURIComponent(subId)}`, {
        cache: 'no-store', signal: controller.signal,
    });
    if (!response.ok) throw new Error('Print/PDF authorization or load failed.');
    const { submission: sub, form_def: formDef } = await response.json();
    if (!sub || sub.id !== subId || sub.form !== formId || !sub.data || typeof sub.data !== 'object') {
        throw new Error('Invalid Print/PDF response.');
    }
    if (win.closed || controller.signal.aborted) { win.close(); return; }
    const formName = formDef?.name || formId;
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
    .pre-wrap{white-space:pre-wrap}
    .footer{margin-top:30px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:0.75rem;color:#94a3b8}
    @media print{body{padding:20px}}</style></head><body>`;
    html += `<h1>${esc(formName)}</h1>`;
    html += `<div class="meta"><strong>ID:</strong> ${esc(sub.id)} &middot; <strong>Submitted:</strong> ${esc(submitted)}</div>`;
    html += '<table>';
    fields.forEach(([k, v]) => {
        const label = labelMap[k] || k;
        const val = valueText(v) || '-';
        html += `<tr><td>${esc(label)}</td><td><div class="pre-wrap" style="white-space:pre-wrap">${esc(val)}</div></td></tr>`;
    });
    html += '</table>';
    html += `<div class="footer">${esc(t('generated'))} ${esc(SITE_NAME)}</div>`;
    html += '</body></html>';

    win.document.write(html);
    win.document.close();
    win.focus();
    win.print();
    } catch (err) {
        flash(err.message, true);
    } finally {
        clearTimeout(timeout);
        win.close(); // Includes denial, malformed response, network failure and dialog dismissal.
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
function valueText(value) {
    if (value === null || value === undefined) return '';
    if (Array.isArray(value) && value.every(item => item === null || typeof item !== 'object')) {
        return value.map(item => String(item ?? '')).join(', ');
    }
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    return String(value);
}

function formatValue(value, type) {
    if (value === null || value === undefined || value === '') {
        return '<span class="empty-val">-</span>';
    }
    if (Array.isArray(value) && type === 'checkbox' && value.every(item => item === null || typeof item !== 'object')) {
        return value.map(v => `<span class="tag">${esc(String(v))}</span>`).join('');
    }
    if (typeof value === 'object') return `<div class="pre-wrap">${esc(valueText(value))}</div>`;
    const str = String(value);
    switch (type) {
        case 'rating': {
            const n = Math.min(5, Math.max(0, parseInt(str) || 0));
            return `<span class="stars">${'\u2605'.repeat(n)}${'\u2606'.repeat(5 - n)}</span>`;
        }
        case 'email':
            return `<a href="mailto:${esc(str)}">${esc(str)}</a>`;
        case 'url':
            return /^https?:\/\//i.test(str) ? `<a href="${esc(str)}" target="_blank" rel="noopener">${esc(str)}</a>` : esc(str);
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
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
function isNestedControl(target, container) {
    const control = target.closest('button,a,input,select,textarea,[contenteditable="true"]');
    return control && control !== container;
}

panelMain.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const target = e.target.closest('.dash-form-card,.sub-card');
    if (!target || isNestedControl(e.target, target)) return;
    e.preventDefault();
    e.stopPropagation();
    if (target.classList.contains('dash-form-card')) {
        selectForm(target.dataset.form);
    } else {
        const formId = target.dataset.form;
        openDetail(target.dataset.id, formId !== state.formId ? formId : undefined);
    }
});

panelMain.addEventListener('click', (e) => {
    // Dashboard form card
    const dashCard = e.target.closest('.dash-form-card');
    if (dashCard && !isNestedControl(e.target, dashCard)) { selectForm(dashCard.dataset.form); return; }

    // Submission card
    const subCard = e.target.closest('.sub-card');
    if (subCard && !isNestedControl(e.target, subCard)) {
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
        backFromDetail();
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

    // Controlled single-job delivery retry
    const retryDeliveryBtn = e.target.closest('.btn-retry-delivery');
    if (retryDeliveryBtn) {
        const confirmId = retryDeliveryBtn.dataset.confirmId;
        const confirmation = confirmId ? document.getElementById(confirmId) : null;
        if (confirmation && !confirmation.checked) {
            flash(t('confirmation_required'), true);
            confirmation.focus();
            return;
        }
        const sub = panelMain._currentSub;
        if (!sub) return;
        const request = navigationRequest;
        const formId = state.formId;
        const subId = sub.id;
        const job = retryDeliveryBtn.dataset.job;
        const confirmed = !!confirmation?.checked;
        retryDeliveryBtn.disabled = true;
        retryDeliveryBtn.textContent = t('retrying_delivery');
        if (confirmation) confirmation.disabled = true;
        (async () => {
            try {
                const result = await api.retryDelivery(formId, subId, job, confirmed);
                if (request !== navigationRequest || state.formId !== formId || state.detail !== subId) return;
                if (!result.delivery || typeof result.delivery !== 'object') throw new Error('Invalid delivery status response.');
                renderDetailView(sub, panelMain._currentFormDef, result.delivery, panelMain._currentReview);
                flash(t('delivery_refreshed'));
            } catch (err) {
                if (request !== navigationRequest || state.formId !== formId || state.detail !== subId) return;
                flash(err.message, true);
                retryDeliveryBtn.disabled = false;
                retryDeliveryBtn.textContent = t('retry_delivery');
                if (confirmation) confirmation.disabled = false;
            }
        })();
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
        const formId = state.formId;
        if (sub && canOperate(formId, 'export')) printSubmission(formId, sub.id);
        return;
    }

    // Delete button (inline confirmation)
    const delBtn = e.target.closest('#btn-del');
    if (delBtn) {
        if (delBtn.classList.contains('confirm')) {
            clearTimeout(state.deleteTimer);
            const subId = delBtn.dataset.id;
            const request = navigationRequest;
            const formId = state.formId;
            const listState = {
                page: state.page, search: state.search, dateFrom: state.dateFrom, dateTo: state.dateTo,
                reviewStatus: state.reviewStatus, reviewTags: [...state.reviewTags],
            };
            delBtn.textContent = t('deleting');
            delBtn.disabled = true;
            (async () => {
                try {
                    await api.del(formId, subId);
                    if (request !== navigationRequest) return;
                    flash(t('deleted'));
                    state.detail = null;
                    updateHash();
                    const [statsData, subsData] = await Promise.all([
                        api.stats(formId),
                        api.submissions(formId, state.perPage, (listState.page - 1) * state.perPage,
                            listState.dateFrom, listState.dateTo, listState.search,
                            listState.reviewStatus, listState.reviewTags),
                    ]);
                    if (request !== navigationRequest) return;
                    state.stats = statsData;
                    state.subs = subsData.submissions;
                    state.total = subsData.total;
                    renderMain();
                    const forms = await api.listForms();
                    if (request !== navigationRequest) return;
                    renderFormList(forms);
                } catch (err) {
                    if (request === navigationRequest) flash(err.message, true);
                }
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

    // Review mutation with optimistic conflict handling.
    const saveReviewBtn = e.target.closest('#save-review');
    if (saveReviewBtn) {
        const sub = panelMain._currentSub;
        const current = panelMain._currentReview;
        if (!sub || !current || !canReview(state.formId)) return;
        const request = navigationRequest; const formId = state.formId; const subId = sub.id;
        const patch = { status: document.getElementById('review-status')?.value || 'new',
            tags: parseReviewTags(document.getElementById('review-tags')?.value || ''),
            notes: document.getElementById('review-notes')?.value || '' };
        saveReviewBtn.disabled = true;
        (async () => {
            try {
                const result = await api.reviewUpdate(formId, subId, current.revision, patch);
                if (request !== navigationRequest || state.formId !== formId || state.detail !== subId) return;
                state.subs.forEach(item => { if (item.id === subId) item.review = result.review; });
                renderDetailView(sub, panelMain._currentFormDef, panelMain._currentDelivery, result.review);
                flash(t('review_saved'));
            } catch (err) {
                if (request !== navigationRequest || state.formId !== formId || state.detail !== subId) return;
                if (err.status === 409 && err.data?.review) {
                    state.subs.forEach(item => { if (item.id === subId) item.review = err.data.review; });
                    renderDetailView(sub, panelMain._currentFormDef, panelMain._currentDelivery, err.data.review);
                    flash(t('review_conflict'), true);
                } else { flash(err.message, true); saveReviewBtn.disabled = false; }
            }
        })();
        return;
    }

    // Saved-filter create/update.
    const saveFilterBtn = e.target.closest('#save-review-filter');
    if (saveFilterBtn && canReview(state.formId)) {
        clearTimeout(searchTimer);
        state.search = document.getElementById('search-input')?.value || '';
        state.dateFrom = document.getElementById('filter-from')?.value || '';
        state.dateTo = document.getElementById('filter-to')?.value || '';
        state.reviewStatus = document.getElementById('review-status-filter')?.value || '';
        state.reviewTags = parseReviewTags(document.getElementById('review-tags-filter')?.value || '');
        const name = document.getElementById('saved-filter-name')?.value.trim() || '';
        if (!name) { document.getElementById('saved-filter-name')?.focus(); return; }
        const current = state.savedFilters.find(filter => filter.id === state.selectedFilter) || null;
        const filter = { id: current?.id || `filter_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`,
            name, criteria: reviewCriteria(), revision: current?.revision || 0 };
        const request = navigationRequest; const mutation = ++filterMutationRequest; const formId = state.formId;
        saveFilterBtn.disabled = true;
        (async () => {
            try {
                const result = await api.saveReviewFilter(formId, filter);
                if (request !== navigationRequest || mutation !== filterMutationRequest || state.formId !== formId) return;
                const index = state.savedFilters.findIndex(item => item.id === result.filter.id);
                if (index >= 0) state.savedFilters[index] = result.filter; else state.savedFilters.push(result.filter);
                state.savedFilters.sort((a, b) => a.id.localeCompare(b.id));
                state.selectedFilter = result.filter.id;
                renderMain(); updateHash(); flash(t('filter_saved'));
                await loadPage();
            } catch (err) {
                if (request !== navigationRequest || mutation !== filterMutationRequest || state.formId !== formId) return;
                if (err.status === 409 && err.data && Object.hasOwn(err.data, 'filter')) {
                    const winner = err.data.filter;
                    const index = state.savedFilters.findIndex(item => item.id === filter.id);
                    if (winner) {
                        if (index >= 0) state.savedFilters[index] = winner; else state.savedFilters.push(winner);
                    } else if (index >= 0) state.savedFilters.splice(index, 1);
                    applySavedFilter(winner);
                    renderMain(); updateHash(); flash(t('filter_conflict'), true);
                    if (winner) await loadPage();
                } else { flash(err.message, true); saveFilterBtn.disabled = false; }
            }
        })();
        return;
    }

    const deleteFilterBtn = e.target.closest('#delete-review-filter');
    if (deleteFilterBtn && canReview(state.formId)) {
        const current = state.savedFilters.find(filter => filter.id === state.selectedFilter);
        if (!current) return;
        const request = navigationRequest; const mutation = ++filterMutationRequest; const formId = state.formId;
        deleteFilterBtn.disabled = true;
        (async () => {
            try {
                await api.deleteReviewFilter(formId, current.id, current.revision);
                if (request !== navigationRequest || mutation !== filterMutationRequest || state.formId !== formId) return;
                state.savedFilters = state.savedFilters.filter(filter => filter.id !== current.id);
                state.selectedFilter = ''; renderMain(); updateHash(); flash(t('filter_deleted'));
            } catch (err) {
                if (request !== navigationRequest || mutation !== filterMutationRequest || state.formId !== formId) return;
                if (err.status === 409 && err.data?.filter) {
                    const index = state.savedFilters.findIndex(filter => filter.id === current.id);
                    if (index >= 0) state.savedFilters[index] = err.data.filter;
                    applySavedFilter(err.data.filter);
                    renderMain(); updateHash(); flash(t('filter_conflict'), true);
                    await loadPage();
                } else { flash(err.message, true); deleteFilterBtn.disabled = false; }
            }
        })();
        return;
    }

    // Filter button
    if (e.target.closest('#btn-filter')) {
        const from = document.getElementById('filter-from');
        const to = document.getElementById('filter-to');
        state.dateFrom = from?.value || '';
        state.dateTo = to?.value || '';
        if (canReview(state.formId)) {
            filterMutationRequest++;
            state.reviewStatus = document.getElementById('review-status-filter')?.value || '';
            state.reviewTags = parseReviewTags(document.getElementById('review-tags-filter')?.value || '');
            state.selectedFilter = '';
        }
        state.page = 1;
        loadPage();
        return;
    }

    // Export button
    if (e.target.closest('#btn-export')) {
        let url = `viewer.php?action=export&form=${encodeURIComponent(state.formId)}`;
        if (state.dateFrom) url += `&from=${state.dateFrom}`;
        if (state.dateTo) url += `&to=${state.dateTo}`;
        if (state.search) url += `&q=${encodeURIComponent(state.search)}`; window.location.href = url;
        return;
    }

    // View toggle
    const toggleBtn = e.target.closest('.view-toggle-btn');
    if (toggleBtn && toggleBtn.dataset.mode) {
        state.viewMode = toggleBtn.dataset.mode;
        localStorage.setItem('bbf_viewMode', state.viewMode);
        state.selected.clear();
        renderMain();
        return;
    }

    // Checkbox click (individual and select-all)
    if (e.target.type === 'checkbox' && (e.target.classList.contains('sub-checkbox') || e.target.id === 'select-all')) {
        e.stopPropagation();
        if (e.target.id === 'select-all') {
            if (e.target.checked) state.subs.forEach(s => state.selected.add(s.id));
            else state.subs.forEach(s => state.selected.delete(s.id));
            const content = document.getElementById('content');
            if (content) content.innerHTML = state.viewMode === 'table' ? renderTable() : renderCardsGrid();
            updateBulkBar();
        } else {
            const id = e.target.dataset.id;
            if (id) {
                if (e.target.checked) state.selected.add(id);
                else state.selected.delete(id);
            }
            // Update visual state
            const card = e.target.closest('.grid-card');
            if (card) card.classList.toggle('selected', e.target.checked);
            const row = e.target.closest('tr');
            if (row) row.classList.toggle('selected', e.target.checked);
            updateBulkBar();
        }
        return;
    }

    // Bulk delete
    if (e.target.closest('#bulk-delete')) {
        const count = state.selected.size;
        if (!confirm(t('bulk_confirm').replace('{n}', count))) return;
        const btn = e.target.closest('#bulk-delete');
        const request = navigationRequest;
        const formId = state.formId;
        const selectedIds = [...state.selected];
        const listState = {
            page: state.page, search: state.search, dateFrom: state.dateFrom, dateTo: state.dateTo,
            reviewStatus: state.reviewStatus, reviewTags: [...state.reviewTags],
        };
        btn.textContent = t('deleting');
        btn.disabled = true;
        (async () => {
            try {
                const result = await api.bulkDelete(formId, selectedIds);
                if (request !== navigationRequest) return;
                flash(t('bulk_deleted').replace('{n}', result.deleted));
                state.selected.clear();
                const [statsData, subsData] = await Promise.all([
                    api.stats(formId),
                    api.submissions(formId, state.perPage, (listState.page - 1) * state.perPage,
                        listState.dateFrom, listState.dateTo, listState.search,
                        listState.reviewStatus, listState.reviewTags),
                ]);
                if (request !== navigationRequest) return;
                state.stats = statsData;
                state.subs = subsData.submissions;
                state.total = subsData.total;
                renderMain();
                const forms = await api.listForms();
                if (request !== navigationRequest) return;
                renderFormList(forms);
            } catch (err) {
                if (request === navigationRequest) flash(err.message, true);
            }
        })();
        return;
    }

    // Bulk cancel (deselect all)
    if (e.target.closest('#bulk-cancel')) {
        state.selected.clear();
        renderMain();
        return;
    }

    // Dedicated native detail controls for cards and table rows
    const detailButton = e.target.closest('.grid-card-open,.table-row-open');
    if (detailButton?.dataset.id) {
        openDetail(detailButton.dataset.id);
        return;
    }

    // Table row click (open detail)
    const tableRow = e.target.closest('.sub-table tbody tr');
    if (tableRow && !isNestedControl(e.target, tableRow) && tableRow.dataset.id) {
        openDetail(tableRow.dataset.id);
        return;
    }

    // Grid card click (open detail)
    const gridCard = e.target.closest('.grid-card');
    if (gridCard && !isNestedControl(e.target, gridCard) && gridCard.dataset.id) {
        openDetail(gridCard.dataset.id);
        return;
    }
});

// Native select change supports keyboard and screen-reader activation without click synthesis.
panelMain.addEventListener('change', (e) => {
    if (e.target.id !== 'saved-filter-select') return;
    const filter = state.savedFilters.find(item => item.id === e.target.value) || null;
    filterMutationRequest++;
    applySavedFilter(filter);
    renderMain(); updateHash();
    if (filter) loadPage();
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
            backFromDetail();
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
restoreRoute();

})();
</script>
</body>
</html>
