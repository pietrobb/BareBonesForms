<?php
/**
 * Postal code / City lookup API for BareBonesForms demo.
 *
 * Endpoints:
 *   ?psc=81101         → {"city": "Bratislava 1", "country": "SK"}
 *   ?city=brat&limit=5 → [{value, label, psc, country}, ...] (autocomplete)
 */
header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . '/data';
$countryNames = ['SK' => 'Slovakia', 'CZ' => 'Czech Republic'];

// ─── PSČ → City lookup ─────────────────────────────────────────
if (!empty($_GET['psc'])) {
    $psc = preg_replace('/[^0-9]/', '', $_GET['psc']);
    $psc = str_pad($psc, 5, '0', STR_PAD_LEFT);

    $index = json_decode(file_get_contents($dataDir . '/psc-to-city.json'), true);
    if (isset($index[$psc])) {
        $entry = $index[$psc];
        echo json_encode([
            'city'    => $entry['city'],
            'country' => $countryNames[$entry['country']] ?? $entry['country'],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Postal code not found']);
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

    // Exact prefix matches first, then contains
    $prefixMatches = [];
    $containsMatches = [];

    foreach ($index as $key => $entry) {
        $nameLC = mb_strtolower($entry['name']);
        if (str_starts_with($nameLC, $q)) {
            $prefixMatches[] = $entry;
        } elseif (str_contains($nameLC, $q)) {
            $containsMatches[] = $entry;
        }
        if (count($prefixMatches) + count($containsMatches) >= $limit * 2) break;
    }

    $matches = array_merge($prefixMatches, $containsMatches);
    $matches = array_slice($matches, 0, $limit);

    $results = [];
    foreach ($matches as $entry) {
        $flag = $entry['country'] === 'SK' ? '🇸🇰' : '🇨🇿';
        $pscList = implode(', ', $entry['pscs']);
        $label = count($entry['pscs']) > 1
            ? $flag . ' ' . $entry['name'] . ' (' . $pscList . ')'
            : $flag . ' ' . $entry['name'] . ' — ' . $entry['pscs'][0];
        $results[] = [
            'value'   => $entry['name'],
            'label'   => $label,
            'psc'     => $entry['pscs'][0],
            'country' => $countryNames[$entry['country']] ?? $entry['country'],
        ];
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Use ?psc=XXXXX or ?city=query']);
