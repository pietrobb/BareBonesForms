<?php
/**
 * PSČ / City lookup API for BareBonesForms demo.
 *
 * Endpoints:
 *   ?psc=81101         → {"city": "Bratislava 1"}
 *   ?city=brat&limit=5 → [{value, label}, ...] (autocomplete)
 */
header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . '/data';

// ─── PSČ → City lookup ─────────────────────────────────────────
if (!empty($_GET['psc'])) {
    $psc = preg_replace('/[^0-9]/', '', $_GET['psc']);
    $psc = str_pad($psc, 5, '0', STR_PAD_LEFT);

    $index = json_decode(file_get_contents($dataDir . '/psc-to-city.json'), true);
    if (isset($index[$psc])) {
        echo json_encode(['city' => $index[$psc]], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'PSČ not found']);
    }
    exit;
}

// ─── City autocomplete ─────────────────────────────────────────
if (isset($_GET['city'])) {
    $q = mb_strtolower(trim($_GET['city']));
    $limit = min((int)($_GET['limit'] ?? 8), 20);

    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    $index = json_decode(file_get_contents($dataDir . '/city-to-psc.json'), true);
    $results = [];

    // Exact prefix matches first, then contains
    $prefixMatches = [];
    $containsMatches = [];

    foreach ($index as $key => $entry) {
        if (str_starts_with($key, $q)) {
            $prefixMatches[] = $entry;
        } elseif (str_contains($key, $q)) {
            $containsMatches[] = $entry;
        }
        if (count($prefixMatches) + count($containsMatches) >= $limit * 2) break;
    }

    $matches = array_merge($prefixMatches, $containsMatches);
    $matches = array_slice($matches, 0, $limit);

    foreach ($matches as $entry) {
        $pscList = implode(', ', $entry['pscs']);
        $label = count($entry['pscs']) > 1
            ? $entry['name'] . ' (' . $pscList . ')'
            : $entry['name'] . ' — ' . $entry['pscs'][0];
        $results[] = [
            'value' => $entry['name'],
            'label' => $label,
            'psc'   => $entry['pscs'][0],  // first PSČ for auto-fill
        ];
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Use ?psc=XXXXX or ?city=query']);
