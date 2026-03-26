<?php
/**
 * One-time script: converts CSV to two JSON lookup files.
 * Run once: php data/build-psc-index.php
 */
$csv = __DIR__ . '/psc-obci-sr-a-cr.csv';
$fp = fopen($csv, 'r');

// Skip header
fgetcsv($fp, 0, ';', '"', '\\');

$pscToCity = [];  // PSČ → {city, country}
$cityToPsc = [];  // city name → {name, country, pscs[]}

while (($row = fgetcsv($fp, 0, ';', '"', '\\')) !== false) {
    if (count($row) < 3) continue;
    $code = trim($row[0]);
    $psc = trim($row[1]);
    $city = trim($row[2]);
    if ($psc === '' || $city === '') continue;

    // Normalize PSČ: ensure 5 digits with leading zero
    $psc = str_pad($psc, 5, '0', STR_PAD_LEFT);

    // Detect country: CZ codes start with 920, SK codes are 6-digit
    $country = str_starts_with($code, '920') ? 'CZ' : 'SK';

    // PSČ → city (one PSČ = one city)
    if (!isset($pscToCity[$psc])) {
        $pscToCity[$psc] = ['city' => $city, 'country' => $country];
    }

    // City → PSČ (one city can have multiple PSČs)
    $cityKey = mb_strtolower($city) . '_' . $country;
    if (!isset($cityToPsc[$cityKey])) {
        $cityToPsc[$cityKey] = ['name' => $city, 'country' => $country, 'pscs' => []];
    }
    if (!in_array($psc, $cityToPsc[$cityKey]['pscs'], true)) {
        $cityToPsc[$cityKey]['pscs'][] = $psc;
    }
}
fclose($fp);

// Sort PSČs within each city
foreach ($cityToPsc as &$entry) {
    sort($entry['pscs']);
}
unset($entry);

ksort($pscToCity);
ksort($cityToPsc);

file_put_contents(__DIR__ . '/psc-to-city.json', json_encode($pscToCity, JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/city-to-psc.json', json_encode($cityToPsc, JSON_UNESCAPED_UNICODE));

echo "Done: " . count($pscToCity) . " PSČs, " . count($cityToPsc) . " cities\n";
