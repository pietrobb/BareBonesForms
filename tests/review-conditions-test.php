<?php
declare(strict_types=1);

// G5 server-condition regression suite: CLI-only and entirely in memory.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function msg(string $key, array $params = []): string {
    return $key . ':' . ($params['label'] ?? '');
}

define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_functions.php';

$passed = 0;
$failed = 0;

function conditions_same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function conditions_check(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        ++$passed;
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

$directDefinition = [
    ['name' => 'parent_gate', 'type' => 'text'],
    ['name' => 'child_gate', 'type' => 'text'],
    [
        'name' => 'parent_group',
        'type' => 'group',
        'show_if' => ['field' => 'parent_gate', 'value' => 'yes'],
        'fields' => [[
            'name' => 'required_child',
            'type' => 'text',
            'label' => 'Required child',
            'required' => true,
            'show_if' => ['field' => 'child_gate', 'value' => 'yes'],
        ]],
    ],
];
$directDefinitionSnapshot = $directDefinition;
$directFields = flattenFields($directDefinition);

conditions_check('parent false and child true skips required child', static function () use ($directFields): void {
    conditions_same([], validate($directFields, ['parent_gate' => 'no', 'child_gate' => 'yes']));
});

conditions_check('parent true and child false skips required child', static function () use ($directFields): void {
    conditions_same([], validate($directFields, ['parent_gate' => 'yes', 'child_gate' => 'no']));
});

conditions_check('parent and child true require child', static function () use ($directFields): void {
    conditions_same(
        ['required_child' => 'required:Required child'],
        validate($directFields, ['parent_gate' => 'yes', 'child_gate' => 'yes'])
    );
});

conditions_check('nested ancestors are all required for a required descendant', static function (): void {
    $definition = [
        ['name' => 'outer_gate', 'type' => 'text'],
        ['name' => 'middle_gate', 'type' => 'text'],
        ['name' => 'inner_gate', 'type' => 'text'],
        [
            'name' => 'outer_group',
            'type' => 'group',
            'show_if' => ['field' => 'outer_gate', 'value' => 'yes'],
            'fields' => [[
                'name' => 'middle_group',
                'type' => 'group',
                'show_if' => ['field' => 'middle_gate', 'value' => 'yes'],
                'fields' => [[
                    'name' => 'inner_group',
                    'type' => 'group',
                    'show_if' => ['field' => 'inner_gate', 'value' => 'yes'],
                    'fields' => [[
                        'name' => 'nested_required',
                        'label' => 'Nested required',
                        'required' => true,
                    ]],
                ]],
            ]],
        ],
    ];
    $fields = flattenFields($definition);

    conditions_same([], validate($fields, [
        'outer_gate' => 'no', 'middle_gate' => 'yes', 'inner_gate' => 'yes',
    ]));
    conditions_same([], validate($fields, [
        'outer_gate' => 'yes', 'middle_gate' => 'no', 'inner_gate' => 'yes',
    ]));
    conditions_same([], validate($fields, [
        'outer_gate' => 'yes', 'middle_gate' => 'yes', 'inner_gate' => 'no',
    ]));
    conditions_same(
        ['nested_required' => 'required:Nested required'],
        validate($fields, ['outer_gate' => 'yes', 'middle_gate' => 'yes', 'inner_gate' => 'yes'])
    );
});

$templateDefinitions = [
    'conditional_block' => [
        ['name' => 'parent_gate', 'type' => 'text'],
        ['name' => 'child_gate', 'type' => 'text'],
        [
            'name' => 'conditional_group',
            'type' => 'group',
            'show_if' => ['field' => 'parent_gate', 'value' => 'yes'],
            'fields' => [[
                'name' => 'required_child',
                'label' => 'Prefixed required child',
                'required' => true,
                'show_if' => ['field' => 'child_gate', 'value' => 'yes'],
            ]],
        ],
    ],
];
$templateReferences = [[
    'name' => 'instance',
    'type' => 'group',
    'use' => 'conditional_block',
    'prefix' => 'instance_',
]];
$templateDefinitionsSnapshot = $templateDefinitions;
$templateReferencesSnapshot = $templateReferences;
$resolvedTemplateFields = resolveTemplates($templateReferences, $templateDefinitions);
$flatTemplateFields = flattenFields($resolvedTemplateFields);

conditions_check('template prefix rewrites parent and child condition references', static function () use ($flatTemplateFields): void {
    $byName = array_column($flatTemplateFields, null, 'name');
    conditions_same(
        [
            'all' => [
                ['field' => 'instance_parent_gate', 'value' => 'yes'],
                ['field' => 'instance_child_gate', 'value' => 'yes'],
            ],
        ],
        $byName['instance_required_child']['show_if']
    );
});

conditions_check('prefixed template conditions enforce parent-child AND', static function () use ($flatTemplateFields): void {
    conditions_same([], validate($flatTemplateFields, [
        'instance_parent_gate' => 'no', 'instance_child_gate' => 'yes',
    ]));
    conditions_same([], validate($flatTemplateFields, [
        'instance_parent_gate' => 'yes', 'instance_child_gate' => 'no',
    ]));
    conditions_same(
        ['instance_required_child' => 'required:Prefixed required child'],
        validate($flatTemplateFields, [
            'instance_parent_gate' => 'yes', 'instance_child_gate' => 'yes',
        ])
    );
});

conditions_check('nested template-local condition references receive the instance prefix', static function (): void {
    $templates = ['nested' => [[
        'name' => 'container',
        'type' => 'group',
        'fields' => [
            ['name' => 'gate', 'type' => 'text'],
            ['name' => 'dependent', 'required' => true, 'show_if' => ['field' => 'gate', 'value' => 'yes']],
        ],
    ]]];
    $resolved = resolveTemplates([[
        'name' => 'instance', 'type' => 'group', 'use' => 'nested', 'prefix' => 'p_',
    ]], $templates);
    $fields = flattenFields($resolved);
    $byName = array_column($fields, null, 'name');

    conditions_same(['field' => 'p_gate', 'value' => 'yes'], $byName['p_dependent']['show_if']);
    conditions_same([], validate($fields, ['p_gate' => 'no']));
    conditions_same(['p_dependent' => 'required:p_dependent'], validate($fields, ['p_gate' => 'yes']));
});

conditions_check('6129-F03 normalized scalar condition enforces a required field', static function (): void {
    $fields = [
        ['name' => 'kind', 'type' => 'select', 'options' => ['personal', 'business']],
        ['name' => 'tax_id', 'required' => true, 'show_if' => ['field' => 'kind', 'value' => 'business']],
    ];

    conditions_same(['tax_id' => 'required:tax_id'], validate($fields, ['kind' => ' business ']));
    conditions_same([], validate($fields, ['kind' => ' personal ']));
});

conditions_check('6129-F03 empty operators share non-string scalar normalization', static function (): void {
    $empty = ['name' => 'empty_detail', 'required' => true, 'show_if' => ['field' => 'gate', 'op' => 'empty']];
    $notEmpty = ['name' => 'filled_detail', 'required' => true, 'show_if' => ['field' => 'gate', 'op' => 'not_empty']];

    conditions_same(['empty_detail' => 'required:empty_detail'], validate([$empty, $notEmpty], ['gate' => false]));
    conditions_same(['filled_detail' => 'required:filled_detail'], validate([$empty, $notEmpty], ['gate' => true]));
});

conditions_check('6129-F03 repeatable conditions use normalized row-local values', static function (): void {
    $group = [
        'name' => 'companies', 'type' => 'group', 'repeatable' => true,
        'fields' => [
            ['name' => 'kind', 'type' => 'select', 'options' => ['personal', 'business']],
            ['name' => 'tax_id', 'required' => true, 'show_if' => ['field' => 'kind', 'value' => 'business']],
        ],
    ];

    conditions_same(
        ['companies.0.tax_id' => 'required:tax_id'],
        validate([$group], ['kind' => 'personal', 'companies' => [['kind' => ' business ']]])
    );
    conditions_same([], validate([$group], ['kind' => 'business', 'companies' => [['kind' => ' personal ']]]));
});

conditions_check('6129-F03 repeatable empty condition normalizes a row boolean before outer fallback', static function (): void {
    $group = [
        'name' => 'rows', 'type' => 'group', 'repeatable' => true,
        'fields' => [
            ['name' => 'gate'],
            ['name' => 'detail', 'required' => true, 'show_if' => ['field' => 'gate', 'op' => 'empty']],
        ],
    ];

    conditions_same(
        ['rows.0.detail' => 'required:detail'],
        validate([$group], ['gate' => true, 'rows' => [['gate' => false]]])
    );
});

conditions_check('source field and template definitions are not mutated', static function () use (
    $directDefinition,
    $directDefinitionSnapshot,
    $templateDefinitions,
    $templateDefinitionsSnapshot,
    $templateReferences,
    $templateReferencesSnapshot
): void {
    conditions_same($directDefinitionSnapshot, $directDefinition);
    conditions_same($templateDefinitionsSnapshot, $templateDefinitions);
    conditions_same($templateReferencesSnapshot, $templateReferences);
});

restore_error_handler();
printf("G5 condition regression tests: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
