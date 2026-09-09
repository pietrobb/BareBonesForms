<?php
/** BareBonesForms — authenticated submissions list, detail and CSV export. */
define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing config.php. Copy config.example.php to config.php and edit it.']);
    exit;
}
require_once __DIR__ . '/bbf_auth.php';
$config = bbf_auth_load_config(__DIR__ . '/config.php');
require_once __DIR__ . '/bbf_export.php';
require_once __DIR__ . '/bbf_read.php';
require_once __DIR__ . '/bbf_outbox.php';
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(static function (Throwable $error): void {
    error_log('BareBonesForms API: ' . $error->getMessage());
    bbf_access_finish(0, false);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Cannot read submissions.']);
});
// Stateless: cookies never authorize this API, and query auth never redirects.
$principal = bbf_authenticate($config, false);
$accessForm = bbf_auth_id($_GET['form'] ?? null);
$accessExport = ($_GET['format'] ?? '') === 'csv';
$accessAction = $accessExport ? 'api_export' : (isset($_GET['id']) ? 'api_detail' : 'api_list');
bbf_access_begin($config, $principal, $accessAction, $accessForm,
    $accessExport ? ['read', 'export'] : ['read'], false, false, [$_GET['id'] ?? '']);
if (!$accessForm || (isset($_GET['id']) && !bbf_auth_id($_GET['id']))) {
    bbf_access_finish(0, false);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid form or submission ID.']);
    exit;
}
$formId = $accessForm;
$format = $_GET['format'] ?? 'json';
$id = $_GET['id'] ?? null;
$limit = $format === 'csv' ? (isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : PHP_INT_MAX)
    : max(1, min(1000, intval($_GET['limit'] ?? 100)));
$offset = max(0, intval($_GET['offset'] ?? 0));
$dateFrom = $_GET['from'] ?? null;
$dateTo = $_GET['to'] ?? null;
$q = trim($_GET['q'] ?? '');
// Convenience: ?last=7d, ?last=24h, ?last=30 (items).
$last = $_GET['last'] ?? '';
if ($last !== '' && $dateFrom === null) {
    if (preg_match('/^(\d+)d$/i', $last, $m)) $dateFrom = date('Y-m-d', strtotime("-{$m[1]} days"));
    elseif (preg_match('/^(\d+)h$/i', $last, $m)) $dateFrom = date('c', strtotime("-{$m[1]} hours"));
    elseif (preg_match('/^(\d+)w$/i', $last, $m)) $dateFrom = date('Y-m-d', strtotime("-{$m[1]} weeks"));
    elseif (preg_match('/^(\d+)m$/i', $last, $m)) $dateFrom = date('Y-m-d', strtotime("-{$m[1]} months"));
    elseif (preg_match('/^(\d+)$/', $last, $m)) $limit = max(1, min($format === 'csv' ? PHP_INT_MAX : 10000, intval($m[1])));
}
$total = null;
$config = bbf_effective_storage_config($config, $formId);
// Export consumes an iterable exactly once; count comes only from checked preparation.
if ($format === 'csv' && !$id) {
    try {
        $formDef = bbf_export_definition($config['forms_dir'] . "/$formId.json");
        $prepared = bbf_export_prepare($formDef, bbf_read_export($formId, $config, $limit, $offset, $dateFrom, $dateTo, $q));
    } catch (Throwable $error) {
        error_log('BareBonesForms export: ' . $error->getMessage());
        bbf_access_finish(0, false);
        http_response_code(500);
        echo json_encode(['error' => 'Cannot prepare export.']);
        exit;
    }
    $out = $prepared['stream'];
    try {
        bbf_access_finish($prepared['count']);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$formId}_submissions.csv");
        // Completion means successful preparation, not client receipt.
        while (!feof($out)) { $chunk = fread($out, 8192); if ($chunk === false) { error_log('BareBonesForms: Failed to transfer prepared CSV export.'); break; } echo $chunk; }
    } finally { fclose($out); }
    exit;
}
switch ($config['storage']) {
    case 'mysql':
        $submissions = loadFromMysql($formId, $id, $config['mysql'], $limit, $offset, $dateFrom, $dateTo, $total, $q);
        break;
    case 'sqlite':
        $submissions = loadFromSqlite($formId, $id, $config, $limit, $offset, $dateFrom, $dateTo, $total, $q);
        break;
    case 'csv':
        $submissions = loadFromCsv($formId, $id, $config['submissions_dir'], $limit, $offset, $dateFrom, $dateTo, $total, $q);
        break;
    default:
        $submissions = loadFromFiles($formId, $id, $config['submissions_dir'], $limit, $offset, $dateFrom, $dateTo, $total, $q);
}
if ($id && $submissions) {
    $deliveryPath = bbf_outbox_existing_path($config, $formId, $id);
    $submissions[0]['delivery'] = bbf_outbox_status($deliveryPath);
}
bbf_access_finish(count($submissions), !$id || count($submissions) > 0);
if ($id) {
    if (!$submissions) {
        http_response_code(404);
        echo json_encode(['error' => 'Submission not found']);
    } else echo json_encode($submissions[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['form' => $formId, 'returned' => count($submissions), 'total' => $total ?? count($submissions),
    'limit' => $limit, 'offset' => $offset, 'submissions' => $submissions], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function loadFromFiles(string $formId, ?string $id, string $dir, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null, ?string $q = null): array {
    if ($id) {
        if (!bbf_auth_id($id)) return [];
        $sub = bbf_read_file("$dir/$formId/$id.json", $formId);
        return $sub === null ? [] : [$sub];
    }
    return bbf_read_page(bbf_read_files($formId, $dir, $dateFrom, $dateTo, $q), $limit, $offset, $total);
}

function loadFromMysql(string $formId, ?string $id, array $db, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null, ?string $q = null): array {
    $pdo = bbf_read_db_connect(['storage' => 'mysql', 'mysql' => $db]);
    if ($id) return iterator_to_array(bbf_read_db($pdo, $formId, null, null, null, $id, 1), false);
    return bbf_read_db_page($pdo, $formId, $limit, $offset, $dateFrom, $dateTo, $total, $q);
}

function loadFromSqlite(string $formId, ?string $id, array $config, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null, ?string $q = null): array {
    $pdo = bbf_read_db_connect(array_replace($config, ['storage' => 'sqlite']));
    if (!$pdo) { $total = 0; return []; }
    if ($id) return iterator_to_array(bbf_read_db($pdo, $formId, null, null, null, $id, 1), false);
    return bbf_read_db_page($pdo, $formId, $limit, $offset, $dateFrom, $dateTo, $total, $q);
}

function loadFromCsv(string $formId, ?string $id, string $dir, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null, ?string $q = null): array {
    if ($id) return iterator_to_array(bbf_read_csv($formId, $dir, null, null, null, $id), false);
    return bbf_read_page(bbf_read_csv($formId, $dir, $dateFrom, $dateTo, $q), $limit, $offset, $total);
}
