<?php
/**
 * Test endpoint that simulates company lookup by IČO.
 * Used by integration tests for lookup feature.
 */
header('Content-Type: application/json; charset=utf-8');

$ico = $_GET['ico'] ?? '';

$companies = [
    '12345678' => [
        'name' => 'ACME s.r.o.',
        'street' => 'Hlavná 1',
        'city' => 'Bratislava',
        'zip' => '81101',
        'dic' => '2020123456',
        'ic_dph' => 'SK2020123456',
    ],
    '87654321' => [
        'name' => 'Test Corp a.s.',
        'street' => 'Dlhá 42',
        'city' => 'Košice',
        'zip' => '04001',
        'dic' => '2020654321',
        'ic_dph' => 'SK2020654321',
    ],
];

if (isset($companies[$ico])) {
    echo json_encode($companies[$ico], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
