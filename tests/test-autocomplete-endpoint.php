<?php
/**
 * Test endpoint that returns city suggestions for autocomplete.
 * Used by integration tests for autocomplete_from feature.
 */
header('Content-Type: application/json; charset=utf-8');

$q = strtolower($_GET['q'] ?? '');

$cities = [
    ['value' => 'Bratislava', 'label' => 'Bratislava'],
    ['value' => 'Banská Bystrica', 'label' => 'Banská Bystrica'],
    ['value' => 'Bardejov', 'label' => 'Bardejov'],
    ['value' => 'Košice', 'label' => 'Košice'],
    ['value' => 'Komárno', 'label' => 'Komárno'],
    ['value' => 'Prešov', 'label' => 'Prešov'],
    ['value' => 'Žilina', 'label' => 'Žilina'],
    ['value' => 'Zvolen', 'label' => 'Zvolen'],
    ['value' => 'Trenčín', 'label' => 'Trenčín'],
    ['value' => 'Trnava', 'label' => 'Trnava'],
    ['value' => 'Nitra', 'label' => 'Nitra'],
];

if ($q === '') {
    echo json_encode([]);
    exit;
}

$results = array_values(array_filter($cities, function($c) use ($q) {
    return str_contains(strtolower($c['value']), $q);
}));

echo json_encode(array_slice($results, 0, 5), JSON_UNESCAPED_UNICODE);
