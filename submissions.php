<?php
/**
 * BareBonesForms — Submissions API
 *
 * List, view, and export submissions.
 *
 * Usage:
 *   GET submissions.php?form=kontakt                  → list all
 *   GET submissions.php?form=kontakt&id=bbf_xxx       → single submission
 *   GET submissions.php?form=kontakt&format=csv        → export CSV
 *   GET submissions.php?form=kontakt&limit=20&offset=0 → paginated
 *   GET submissions.php?form=kontakt&from=2024-01-01&to=2024-12-31
 *
 * Authentication required. Set api_token in config.php.
 */

define('BBF_LOADED', true);
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing config.php. Copy config.example.php to config.php and edit it.']);
    exit;
}
$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

// ─── Auth check (required) ──────────────────────────────────────
$authToken = $config['api_token'] ?? '';
if ($authToken === '') {
    http_response_code(403);
    echo json_encode(['error' => 'API access disabled. Set api_token in config.php.']);
    exit;
}

$provided = $_GET['token'] ?? ($_SERVER['HTTP_X_BBF_TOKEN'] ?? '');
if (!hash_equals($authToken, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$formId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['form'] ?? '');
if (!$formId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ?form= parameter']);
    exit;
}

$format = $_GET['format'] ?? 'json';
$id = $_GET['id'] ?? null;
$limit = max(1, min(1000, intval($_GET['limit'] ?? 100)));
$offset = max(0, intval($_GET['offset'] ?? 0));
$dateFrom = $_GET['from'] ?? null;
$dateTo = $_GET['to'] ?? null;

// Convenience: ?last=7d, ?last=24h, ?last=30 (items)
$last = $_GET['last'] ?? '';
if ($last !== '' && $dateFrom === null) {
    if (preg_match('/^(\d+)d$/i', $last, $m)) {
        $dateFrom = date('Y-m-d', strtotime("-{$m[1]} days"));
    } elseif (preg_match('/^(\d+)h$/i', $last, $m)) {
        $dateFrom = date('c', strtotime("-{$m[1]} hours"));
    } elseif (preg_match('/^(\d+)w$/i', $last, $m)) {
        $dateFrom = date('Y-m-d', strtotime("-{$m[1]} weeks"));
    } elseif (preg_match('/^(\d+)m$/i', $last, $m)) {
        $dateFrom = date('Y-m-d', strtotime("-{$m[1]} months"));
    } elseif (preg_match('/^(\d+)$/', $last, $m)) {
        $limit = max(1, min(10000, intval($m[1])));
    }
}

$total = null;
switch ($config['storage']) {
    case 'mysql':
        $submissions = loadFromMysql($formId, $id, $config['mysql'], $limit, $offset, $dateFrom, $dateTo, $total);
        break;
    case 'sqlite':
        $submissions = loadFromSqlite($formId, $id, $config, $limit, $offset, $dateFrom, $dateTo, $total);
        break;
    case 'csv':
        $submissions = loadFromCsv($formId, $id, $config['submissions_dir'], $limit, $offset, $dateFrom, $dateTo, $total);
        break;
    default:
        $submissions = loadFromFiles($formId, $id, $config['submissions_dir'], $limit, $offset, $dateFrom, $dateTo, $total);
}

// ─── Single submission ──────────────────────────────────────────
if ($id) {
    if (empty($submissions)) {
        http_response_code(404);
        echo json_encode(['error' => 'Submission not found']);
    } else {
        echo json_encode($submissions[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ─── CSV export ─────────────────────────────────────────────────
if ($format === 'csv') {
    // Use form definition for stable column order
    $formFile = $config['forms_dir'] . "/$formId.json";
    $formDef = file_exists($formFile) ? json_decode(file_get_contents($formFile), true) : null;

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$formId}_submissions.csv");

    if (empty($submissions)) {
        echo "No submissions\n";
        exit;
    }

    $out = fopen('php://output', 'w');

    // Header row from form definition (stable) or first submission
    if ($formDef && !empty($formDef['fields'])) {
        $fieldKeys = array_column($formDef['fields'], 'name');
    } else {
        $fieldKeys = array_keys($submissions[0]['data'] ?? []);
    }
    $headers = array_merge(['id', 'submitted'], $fieldKeys);
    fputcsv($out, $headers);

    foreach ($submissions as $sub) {
        $row = [$sub['id'], $sub['meta']['submitted'] ?? ''];
        foreach ($fieldKeys as $key) {
            $val = $sub['data'][$key] ?? '';
            $val = is_array($val) ? implode(', ', $val) : $val;
            // Prevent CSV formula injection
            if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $val = "'" . $val;
            }
            $row[] = $val;
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ─── JSON list ──────────────────────────────────────────────────
$response = [
    'form'      => $formId,
    'returned'  => count($submissions),
    'total'     => $total ?? count($submissions),
    'limit'     => $limit,
    'offset'    => $offset,
    'submissions' => $submissions,
];
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


// ═════════════════════════════════════════════════════════════════

function loadFromFiles(string $formId, ?string $id, string $dir, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null): array {
    $formDir = $dir . '/' . $formId;
    if (!is_dir($formDir)) { $total = 0; return []; }

    if ($id) {
        $file = $formDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '.json';
        if (!file_exists($file)) return [];
        return [json_decode(file_get_contents($file), true)];
    }

    $files = glob($formDir . '/bbf_*.json');
    // Sort newest first
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

    $results = [];
    foreach ($files as $f) {
        $sub = json_decode(file_get_contents($f), true);
        if (!$sub) continue;

        // Date filter
        $submitted = $sub['meta']['submitted'] ?? '';
        if ($dateFrom && $submitted < $dateFrom) continue;
        if ($dateTo && $submitted > $dateTo . 'T23:59:59') continue;

        $results[] = $sub;
    }

    $total = count($results);
    return array_slice($results, $offset, $limit);
}

function loadFromMysql(string $formId, ?string $id, array $db, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null): array {
    try {
        $dsn = "mysql:host={$db['host']};dbname={$db['database']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND form_id = ?");
            $stmt->execute([$id, $formId]);
        } else {
            $where = ['form_id = ?'];
            $params = [$formId];

            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }

            // Total count
            $countSql = "SELECT COUNT(*) FROM bbf_submissions WHERE " . implode(' AND ', $where);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT * FROM bbf_submissions WHERE " . implode(' AND ', $where)
                 . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        return array_map(function($row) {
            return [
                'id'   => $row['id'],
                'form' => $row['form_id'],
                'data' => json_decode($row['data'], true),
                'meta' => json_decode($row['meta'], true),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log("BareBonesForms: " . $e->getMessage());
        return [];
    }
}

function loadFromSqlite(string $formId, ?string $id, array $config, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null): array {
    try {
        $dbFile = $config['sqlite']['path'] ?? $config['submissions_dir'] . '/bbf.sqlite';
        if (!file_exists($dbFile)) { $total = 0; return []; }

        $pdo = new PDO("sqlite:$dbFile", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM bbf_submissions WHERE id = ? AND form_id = ?");
            $stmt->execute([$id, $formId]);
        } else {
            $where = ['form_id = ?'];
            $params = [$formId];

            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }

            // Total count
            $countSql = "SELECT COUNT(*) FROM bbf_submissions WHERE " . implode(' AND ', $where);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT * FROM bbf_submissions WHERE " . implode(' AND ', $where)
                 . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        return array_map(function($row) {
            return [
                'id'   => $row['id'],
                'form' => $row['form_id'],
                'data' => json_decode($row['data'], true),
                'meta' => json_decode($row['meta'], true),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log("BareBonesForms SQLite: " . $e->getMessage());
        return [];
    }
}

function loadFromCsv(string $formId, ?string $id, string $dir, int $limit, int $offset, ?string $dateFrom, ?string $dateTo, ?int &$total = null): array {
    $file = $dir . '/' . $formId . '.csv';
    if (!file_exists($file)) return [];

    $fp = fopen($file, 'r');
    if (!$fp) return [];

    $headers = fgetcsv($fp);
    if (!$headers) { fclose($fp); return []; }

    // Meta columns (prefixed with _)
    $metaCols = ['_id', '_submitted', '_ip', '_user_agent'];
    $dataCols = array_values(array_diff($headers, $metaCols));

    $results = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($metaCols)) continue;

        $mapped = @array_combine($headers, array_slice(array_pad($row, count($headers), ''), 0, count($headers)));
        if ($mapped === false) continue;

        $subId = $mapped['_id'] ?? '';
        $submitted = $mapped['_submitted'] ?? '';

        if ($id && $subId !== $id) continue;
        if ($dateFrom && $submitted < $dateFrom) continue;
        if ($dateTo && $submitted > $dateTo . 'T23:59:59') continue;

        $data = [];
        foreach ($dataCols as $col) {
            $data[$col] = $mapped[$col] ?? '';
        }

        $results[] = [
            'id'   => $subId,
            'form' => $formId,
            'data' => $data,
            'meta' => [
                'ip'         => $mapped['_ip'] ?? '',
                'user_agent' => $mapped['_user_agent'] ?? '',
                'submitted'  => $submitted,
            ],
        ];

        if ($id) break;
    }
    fclose($fp);

    // CSV is chronological, reverse for newest-first
    $results = array_reverse($results);

    if ($id) return $results;
    $total = count($results);
    return array_slice($results, $offset, $limit);
}
