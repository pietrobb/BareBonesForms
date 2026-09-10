<?php
// G10 F7: isolated server contract for bounded repeatable groups.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BBF_LOADED', true);
require dirname(__DIR__) . '/bbf_functions.php';
require dirname(__DIR__) . '/bbf_export.php';
require dirname(__DIR__) . '/bbf_backup.php';
require __DIR__ . '/test-isolation-helper.php';

function msg(string $key, array $params = []): string {
    return $key . ':' . ($params['label'] ?? '');
}

$passed = 0;
$failed = 0;
function repeat_check(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        $passed++;
        print "PASS $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
    }
}
function repeat_same($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function repeat_contains(string $needle, array $messages): void {
    foreach ($messages as $message) if (str_contains($message, $needle)) return;
    throw new RuntimeException("Missing error containing: $needle\n" . implode("\n", $messages));
}

$group = [
    'name' => 'items',
    'type' => 'group',
    'title' => 'Line items',
    'repeatable' => true,
    'min_items' => 1,
    'max_items' => 2,
    'fields' => [
        ['name' => 'sku', 'type' => 'text', 'required' => true],
        ['name' => 'kind', 'type' => 'select', 'options' => ['normal', 'special']],
        ['name' => 'detail', 'type' => 'text', 'required' => true,
            'show_if' => ['field' => 'kind', 'value' => 'special']],
        ['name' => 'tags', 'type' => 'checkbox', 'options' => ['fragile', 'gift']],
    ],
];
$fields = [$group, ['name' => 'approved', 'type' => 'checkbox', 'options' => ['yes']]];

repeat_check('definition accepts bounded repeatable group', function () use ($group) {
    repeat_same([], validateFormDefinition(['id' => 'orders', 'fields' => [$group]]));
});
repeat_check('definition rejects invalid repeatable limits', function () use ($group) {
    $invalid = $group;
    $invalid['min_items'] = 3;
    $invalid['max_items'] = 2;
    $errors = validateFormDefinition(['id' => 'orders', 'fields' => [$invalid]]);
    repeat_contains('min_items cannot exceed max_items', $errors);
});
repeat_check('definition rejects repeatable properties on scalar', function () {
    $errors = validateFormDefinition(['id' => 'orders', 'fields' => [
        ['name' => 'sku', 'type' => 'text', 'repeatable' => true],
    ]]);
    repeat_contains("Repeatable properties require type 'group'", $errors);
});
repeat_check('definition validates repeatable control labels', function () use ($group) {
    $invalid = $group;
    $invalid['add_label'] = ['unsafe'];
    repeat_contains('add_label: Expected string',
        validateFormDefinition(['id' => 'orders', 'fields' => [$invalid]]));
});
repeat_check('definition rejects nested repeatable group', function () use ($group) {
    $outer = $group;
    $outer['fields'] = [[
        'name' => 'nested', 'type' => 'group', 'repeatable' => true,
        'fields' => [['name' => 'value', 'type' => 'text']],
    ]];
    repeat_contains('Nested repeatable groups are not supported',
        validateFormDefinition(['id' => 'orders', 'fields' => [$outer]]));
});
repeat_check('schema publishes repeatable controls and bounds', function () {
    $schema = json_decode(file_get_contents(dirname(__DIR__) . '/forms/form.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $properties = $schema['$defs']['field']['properties'];
    repeat_same('boolean', $properties['repeatable']['type']);
    repeat_same(0, $properties['min_items']['minimum']);
    repeat_same(100, $properties['max_items']['maximum']);
});
repeat_check('flattening preserves repeatable group as one data field', function () use ($fields) {
    repeat_same(['items', 'approved'], array_column(flattenFields($fields), 'name'));
});
repeat_check('static groups remain flattened for compatibility', function () {
    $flat = flattenFields([['name' => 'contact', 'type' => 'group', 'fields' => [
        ['name' => 'email', 'type' => 'email'],
    ]]]);
    repeat_same(['contact', 'email'], array_column($flat, 'name'));
    repeat_same([], validateFormDefinition(['id' => 'legacy', 'fields' => [
        ['name' => 'empty', 'type' => 'group'],
    ]]));
});

$valid = ['items' => [
    ['sku' => ' A-1 ', 'kind' => 'normal', 'detail' => '', 'tags' => ['gift']],
    ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
], 'approved' => ['yes']];
repeat_check('valid rows pass row-local field and condition validation', function () use ($fields, $valid) {
    repeat_same([], validate(flattenFields($fields), $valid));
});
repeat_check('minimum and maximum row limits are server authoritative', function () use ($group) {
    repeat_same(['items' => 'repeatableMin:Line items'], validate([$group], ['items' => []]));
    repeat_same(['items' => 'repeatableMax:Line items'], validate([$group], ['items' => [
        ['sku' => '1'], ['sku' => '2'], ['sku' => '3'],
    ]]));
});
repeat_check('row-local condition requires only the matching row child', function () use ($group) {
    repeat_same([], validate([$group], ['items' => [['sku' => '1', 'kind' => 'normal']]]));
    repeat_same(['items.0.detail' => 'required:detail'],
        validate([$group], ['items' => [['sku' => '1', 'kind' => 'special']]]));
});
repeat_check('row-local validation cannot inherit colliding top-level child values', function () use ($group) {
    repeat_same([
        'items.0.sku' => 'required:sku',
        'items.0.detail' => 'required:detail',
    ], validate([$group], [
        'sku' => 'TOP-LEVEL', 'detail' => 'TOP-LEVEL',
        'items' => [['kind' => 'special']],
    ]));
});
repeat_check('empty row object is valid when all children are optional', function () use ($group) {
    $optional = $group;
    $optional['fields'] = [['name' => 'note', 'type' => 'text']];
    repeat_same([], validate([$optional], ['items' => [[]]]));
});
repeat_check('hidden group skips minimum but not malformed shape preflight', function () use ($group) {
    $conditional = $group;
    $conditional['show_if'] = ['field' => 'enabled', 'value' => 'yes'];
    repeat_same([], validate([$conditional], ['enabled' => 'no']));
    repeat_same(['items' => 'invalidFormat:items'], validate([$conditional], [
        'enabled' => 'no', 'items' => [['sku' => ['nested']]],
    ]));
});

$malformed = [
    'scalar group' => 'bad',
    'object instead of row list' => ['sku' => 'bad'],
    'list instead of row object' => [['bad']],
    'unknown row key' => [['sku' => 'ok', 'admin' => 'true']],
    'nested scalar child' => [['sku' => ['bad']]],
    'nested checkbox item' => [['sku' => 'ok', 'tags' => [['gift']]]],
];
foreach ($malformed as $name => $rows) {
    repeat_check("rejects $name", function () use ($group, $rows) {
        repeat_same(['items' => 'invalidFormat:items'], validate([$group], ['items' => $rows]));
    });
}

$submitSource = file_get_contents(dirname(__DIR__) . '/submit.php');
repeat_same(1, preg_match('/^function collectData\(.*?^\}/ms', $submitSource, $collectionMatch));
eval($collectionMatch[0]);
repeat_check('collection stores stable rows and omits hidden row children', function () use ($fields, $valid) {
    repeat_same([
        'items' => [
            ['sku' => 'A-1', 'kind' => 'normal', 'tags' => ['gift']],
            ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
        ],
        'approved' => ['yes'],
    ], collectData(flattenFields($fields), $valid));
});
repeat_check('collection cannot leak colliding top-level values into rows', function () use ($group) {
    repeat_same(['items' => [[
        'sku' => 'ROW', 'kind' => 'normal', 'tags' => [],
    ]]], collectData([$group], [
        'sku' => 'TOP-LEVEL', 'detail' => 'TOP-LEVEL',
        'items' => [['sku' => ' ROW ', 'kind' => 'normal', 'tags' => []]],
    ]));
});
repeat_check('summary escapes structured repeatable content', function () use ($group) {
    $html = buildSummary([$group], ['items' => [['sku' => '<script>alert(1)</script>']]]);
    repeat_same(false, str_contains($html, '<script>'));
    repeat_same(true, str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
});
repeat_check('export schema uses one repeatable JSON column, not child columns', function () use ($group) {
    repeat_same(['items'], bbf_export_field_keys(['fields' => [$group]], []));
    repeat_same('[{"sku":"A-1"},{"sku":"B/2"}]', bbf_export_cell([
        ['sku' => 'A-1'], ['sku' => 'B/2'],
    ]));
    repeat_same('gift, fragile', bbf_export_cell(['gift', 'fragile']));
    repeat_same('[]', bbf_export_cell([], true));
    $prepared = bbf_export_prepare(['fields' => [$group]], [[
        'id' => 'one', 'meta' => ['submitted' => '2026-09-10T00:00:00Z'], 'data' => ['items' => []],
    ]]);
    fgetcsv($prepared['stream'], 0, ',', '"', '');
    $row = fgetcsv($prepared['stream'], 0, ',', '"', '');
    repeat_same('[]', $row[2] ?? null);
    fclose($prepared['stream']);
});

repeat_check('isolated submit HTTP stores rows and rejects malformed payloads before persistence', function () use ($group, $valid) {
    $root = bbf_test_installation(dirname(__DIR__));
    $server = null;
    try {
        $config = [
            'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
            'templates_dir' => "$root/templates", 'logs_dir' => "$root/logs", 'csrf' => false,
            'allowed_origins' => [], 'sandbox' => false, 'rate_limit' => 1000, 'honeypot_field' => '_hp',
            'lang' => 'en', 'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
            'mail' => ['method' => 'mail', 'from_email' => 'fixture@example.invalid', 'from_name' => 'Fixture'],
            'delivery' => ['max_attempts' => 3, 'retry_delay' => 60, 'lease_seconds' => 300],
            'stripe' => ['secret_key' => '', 'webhook_secret' => ''], 'access_tokens' => [],
        ];
        file_put_contents("$root/config.php", "<?php defined('BBF_LOADED') || exit; return " . var_export($config, true) . ";\n");
        file_put_contents("$root/forms/orders.json", json_encode([
            'id' => 'orders', 'name' => 'Orders', 'fields' => [$group],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
        $post = static function (array $body) use ($server): array {
            return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/submit.php?form=orders', null, [
                'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'],
                'raw' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        };
        $response = $post(['items' => $valid['items']]);
        repeat_same(200, $response['code']);
        repeat_same('ok', $response['json']['status'] ?? null);
        $files = glob("$root/submissions/orders/*.json") ?: [];
        repeat_same(1, count($files));
        $stored = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        repeat_same([
            ['sku' => 'A-1', 'kind' => 'normal', 'tags' => ['gift']],
            ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
        ], $stored['data']['items'] ?? null);
        foreach ([
            ['items' => [['sku' => ['nested']]]],
            ['items' => [['sku' => 'ok', 'admin' => 'true']]],
            ['sku' => 'TOP-LEVEL', 'detail' => 'TOP-LEVEL', 'items' => [['kind' => 'special']]],
            ['items' => [['sku' => '1'], ['sku' => '2'], ['sku' => '3']]],
        ] as $payload) {
            $rejected = $post($payload);
            repeat_same(422, $rejected['code']);
            repeat_same('error', $rejected['json']['status'] ?? null);
        }
        repeat_same(1, count(glob("$root/submissions/orders/*.json") ?: []));
    } finally {
        bbf_test_stop_server($server);
        bbf_test_cleanup($root);
    }
});

repeat_check('isolated file CSV and SQLite HTTP round-trip preserves repeatable and historical static rows', function () use ($group, $valid) {
    repeat_same(true, in_array('sqlite', PDO::getAvailableDrivers(), true));
    $expectedItems = [
        ['sku' => 'A-1', 'kind' => 'normal', 'tags' => ['gift']],
        ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
    ];
    $parseCsv = static function (string $body): array {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($header)) throw new RuntimeException('Misaligned repeatable export row.');
            $rows[] = array_combine($header, $row);
        }
        fclose($stream);
        return [$header, $rows];
    };
    foreach (['file', 'csv', 'sqlite'] as $storage) {
        $root = bbf_test_installation(dirname(__DIR__));
        $server = null;
        try {
            $config = [
                'api_token' => 'repeatable-fixture-admin-token', 'access_tokens' => [],
                'storage' => $storage, 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
                'templates_dir' => "$root/templates", 'logs_dir' => "$root/logs", 'csrf' => false,
                'allowed_origins' => [], 'sandbox' => false, 'rate_limit' => 1000, 'honeypot_field' => '_hp',
                'lang' => 'en', 'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
                'sqlite' => ['path' => "$root/submissions/bbf.sqlite"],
                'mail' => ['method' => 'mail', 'from_email' => 'fixture@example.invalid', 'from_name' => 'Fixture'],
                'delivery' => ['max_attempts' => 3, 'retry_delay' => 60, 'lease_seconds' => 300],
                'stripe' => ['secret_key' => '', 'webhook_secret' => ''],
            ];
            file_put_contents("$root/config.php", "<?php defined('BBF_LOADED') || exit; return " . var_export($config, true) . ";\n");
            $static = ['name' => 'contact', 'type' => 'group', 'fields' => [
                ['name' => 'legacy_note', 'type' => 'text'],
            ]];
            file_put_contents("$root/forms/orders.json", json_encode([
                'id' => 'orders', 'name' => 'Orders', 'fields' => [$static],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
            $base = 'http://127.0.0.1:' . $server['port'] . '/';
            $post = static function (array $body) use ($server, $base): array {
                return bbf_test_http($server, $base . 'submit.php?form=orders', null, [
                    'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'],
                    'raw' => json_encode($body, JSON_THROW_ON_ERROR),
                ]);
            };
            $legacyResponse = $post(['legacy_note' => "historical $storage"]);
            repeat_same(200, $legacyResponse['code']);
            $legacyId = $legacyResponse['json']['submission_id'] ?? '';
            file_put_contents("$root/forms/orders.json", json_encode([
                'id' => 'orders', 'name' => 'Orders', 'fields' => [$group, $static],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $currentResponse = $post(['items' => $valid['items'], 'legacy_note' => "current $storage"]);
            repeat_same(200, $currentResponse['code']);
            $currentId = $currentResponse['json']['submission_id'] ?? '';
            $auth = ['headers' => ['X-BBF-Token' => $config['api_token']]];
            $current = bbf_test_http($server, $base . 'submissions.php?form=orders&id=' . rawurlencode($currentId), null, $auth);
            repeat_same(200, $current['code']);
            repeat_same($expectedItems, $current['json']['data']['items'] ?? null);
            repeat_same("current $storage", $current['json']['data']['legacy_note'] ?? null);
            $legacy = bbf_test_http($server, $base . 'submissions.php?form=orders&id=' . rawurlencode($legacyId), null, $auth);
            repeat_same(200, $legacy['code']);
            repeat_same("historical $storage", $legacy['json']['data']['legacy_note'] ?? null);
            repeat_same(false, is_array($legacy['json']['data']['items'] ?? null));
            $export = bbf_test_http($server, $base . 'submissions.php?format=csv&form=orders', null, $auth);
            repeat_same(200, $export['code']);
            [$header, $rows] = $parseCsv($export['body']);
            repeat_same(['id', 'submitted', 'items', 'legacy_note'], $header);
            $byId = [];
            foreach ($rows as $row) $byId[$row['id']] = $row;
            repeat_same(json_encode($expectedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $byId[$currentId]['items'] ?? null);
            repeat_same("historical $storage", $byId[$legacyId]['legacy_note'] ?? null);
            repeat_same('', $byId[$legacyId]['items'] ?? null);
            if ($storage === 'file') {
                $stored = json_decode(file_get_contents("$root/submissions/orders/$currentId.json"), true, 512, JSON_THROW_ON_ERROR);
                repeat_same($expectedItems, $stored['data']['items'] ?? null);
            } elseif ($storage === 'csv') {
                $stream = fopen("$root/submissions/orders.csv", 'rb');
                $storedHeader = fgetcsv($stream, 0, ',', '"', '');
                $stored = [];
                while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) $stored[] = array_combine($storedHeader, $row);
                fclose($stream);
                $storedById = [];
                foreach ($stored as $row) $storedById[$row['_id']] = $row;
                repeat_same('["items"]', $storedById[$currentId]['__bbf:structured_fields'] ?? null);
                repeat_same('', $storedById[$legacyId]['__bbf:structured_fields'] ?? null);
            } else {
                $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare('SELECT data FROM bbf_submissions WHERE id = ?');
                $stmt->execute([$currentId]);
                repeat_same($expectedItems, json_decode($stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)['items'] ?? null);
                $stmt = null;
                $pdo = null;
            }
        } finally {
            bbf_test_stop_server($server);
            bbf_test_cleanup($root);
        }
    }
});

repeat_check('logical CSV restore preserves structured repeatable rows', function () {
    $root = bbf_test_installation(dirname(__DIR__));
    try {
        $record = [
            'id' => 'bbf_repeatable_restore', 'form' => 'orders',
            'data' => [
                'items' => [['sku' => 'A/1', 'tags' => ['gift']], ['sku' => 'B-2', 'tags' => []]],
                'choices' => ['red', 'blue'], 'note' => '=literal',
            ],
            'meta' => ['submitted' => '2026-09-10T03:00:00Z', 'ip' => '', 'user_agent' => 'fixture'],
        ];
        $created = [];
        bbf_backup_restore_csv(['submissions_dir' => "$root/submissions"], [
            'form' => 'orders', 'records' => [$record['id'] => $record],
        ], $created);
        $rows = iterator_to_array(bbf_read_csv('orders', "$root/submissions"), false);
        repeat_same(1, count($rows));
        repeat_same($record['id'], $rows[0]['id'] ?? null);
        repeat_same($record['form'], $rows[0]['form'] ?? null);
        $expectedData = $record['data'];
        $actualData = $rows[0]['data'] ?? [];
        ksort($expectedData, SORT_STRING);
        ksort($actualData, SORT_STRING);
        repeat_same($expectedData, $actualData);
        repeat_same($record['meta'], $rows[0]['meta'] ?? null);
    } finally {
        bbf_test_cleanup($root);
    }
});

restore_error_handler();
printf("Repeatable group PHP regression: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
