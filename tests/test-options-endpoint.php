<?php
/**
 * Test endpoint that returns dynamic options as JSON.
 * Used by integration tests for options_from feature.
 */
if (PHP_SAPI !== 'cli' && !(PHP_SAPI === 'cli-server' && getenv('BBF_TEST_FIXTURE_ROOT') === realpath(__DIR__ . '/..'))) { http_response_code(403); exit('Test fixture only.'); } header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? 'countries';

if ($type === 'categories') {
    echo json_encode([
        ['value' => 'electronics', 'label' => 'Electronics'],
        ['value' => 'clothing', 'label' => 'Clothing'],
        ['value' => 'books', 'label' => 'Books'],
    ]);
} else {
    echo json_encode([
        ['value' => 'SK', 'label' => 'Slovakia'],
        ['value' => 'CZ', 'label' => 'Czech Republic'],
        ['value' => 'DE', 'label' => 'Germany'],
        ['value' => 'AT', 'label' => 'Austria'],
    ]);
}
