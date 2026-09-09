<?php
// Isolated, in-memory regression tests. Never load config.php or execute submit.php's bootstrap.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BBF_LOADED', true);
require dirname(__DIR__) . '/bbf_functions.php';

function msg(string $key, array $params = []): string {
    return $key . ':' . ($params['label'] ?? '');
}

$passed = 0;
$failed = 0;
function check(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        $passed++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n");
    }
}
function same($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function rejects(array $field, $value, string $message = 'invalidFormat'): void {
    same(['value' => $message . ':Value'], validate([$field + ['name' => 'value', 'label' => 'Value']], ['value' => $value]));
}
function accepts(array $field, $value): void {
    same([], validate([$field + ['name' => 'value']], ['value' => $value]));
}

$scalarFields = [
    'default text' => [],
    'email' => ['type' => 'email'],
    'number' => ['type' => 'number', 'min' => 1, 'max' => 10],
    'rating' => ['type' => 'rating', 'min' => 1, 'max' => 5],
    'url' => ['type' => 'url'],
    'tel' => ['type' => 'tel'],
    'date' => ['type' => 'date'],
    'hidden' => ['type' => 'hidden'],
    'password' => ['type' => 'password'],
    'pattern' => ['pattern' => '^[A-Z]+$'],
    'minlength' => ['minlength' => 3],
    'maxlength' => ['type' => 'textarea', 'maxlength' => 3],
    'radio' => ['type' => 'radio', 'options' => ['a', 'b']],
    'single select' => ['type' => 'select', 'options' => ['a', 'b']],
    'explicit single select' => ['type' => 'select', 'multiple' => false],
    'email multiple flag is not an array exemption' => ['type' => 'email', 'multiple' => true],
];
foreach ($scalarFields as $name => $field) {
    foreach ([[], ['a'], ['a', 'b'], ['key' => 'a'], [['a']]] as $i => $payload) {
        check("$name rejects array $i", fn() => rejects($field, $payload));
    }
}
foreach ([new stdClass(), (object)['value' => 'a']] as $i => $payload) {
    check("scalar rejects object $i", fn() => rejects([], $payload));
}
foreach ([
    [['type' => 'email'], 'bad', 'invalidEmail'],
    [['type' => 'number'], 'bad', 'invalidNumber'],
    [['type' => 'number', 'min' => 1], '0', 'numberMin'],
    [['type' => 'number', 'max' => 10], '11', 'numberMax'],
    [['pattern' => '^[A-Z]+$'], 'bad', 'invalidFormat'],
    [['minlength' => 3], 'ab', 'tooShort'],
    [['maxlength' => 3], 'abcd', 'tooLong'],
    [['type' => 'select', 'options' => ['a']], 'b', 'invalidOption'],
] as $i => [$field, $value, $message]) {
    check("existing scalar constraint $i", fn() => rejects($field, $value, $message));
}
foreach ([
    [['type' => 'email'], ' reader@example.test '],
    [['type' => 'number', 'min' => 0, 'max' => 10], 0],
    [['type' => 'number', 'min' => 0, 'max' => 10], 2.5],
    [['pattern' => '^[A-Z]+$', 'minlength' => 2, 'maxlength' => 3], ' AB '],
    [[], false], [[], null], [[], ''],
] as $i => [$field, $value]) {
    check("valid scalar $i", fn() => accepts($field, $value));
}
check('missing optional field', fn() => same([], validate([['name' => 'value']], [])));
check('missing required field', fn() => same(['value' => 'required:value'], validate([['name' => 'value', 'required' => true]], [])));

$multiFields = [
    'checkbox' => ['type' => 'checkbox', 'options' => ['a', ['value' => 'b', 'label' => 'Bee']]],
    'multiple select' => ['type' => 'select', 'multiple' => true, 'options' => ['a', 'b']],
    'dynamic checkbox' => ['type' => 'checkbox', 'options_from' => 'unused-local-fixture'],
];
foreach ($multiFields as $name => $field) {
    foreach (['a', ['a'], ['a', 'b'], [], '', null] as $i => $value) {
        check("$name accepts selection $i", fn() => accepts($field, $value));
    }
    foreach ([[['a']], ['a', ['b']], ['x' => ['b']], [null], [new stdClass()]] as $i => $value) {
        check("$name rejects nested/non-scalar item $i", fn() => rejects($field, $value));
    }
    check("$name required empty", fn() => rejects($field + ['required' => true], [], 'required'));
}
check('checkbox checks every option', fn() => rejects($multiFields['checkbox'], ['a', 'wrong'], 'invalidOption'));
check('multiple select checks every option', fn() => rejects($multiFields['multiple select'], ['a', 'wrong'], 'invalidOption'));
check('numeric option scalar items', fn() => accepts(['type' => 'checkbox', 'options' => ['0', '1']], [0, 1]));

foreach (['radio', 'select', 'checkbox'] as $type) {
    $field = ['name' => 'choice', 'type' => $type, 'options' => ['a'], 'other' => true];
    $value = $type === 'checkbox' ? ['a', '__other__'] : '__other__';
    foreach ([' Custom text ', '', null] as $i => $other) {
        check("$type Other scalar $i", fn() => same([], validate([$field], ['choice' => $value, 'choice_other' => $other])));
    }
    foreach ([[], ['text'], [['text']]] as $i => $other) {
        check("$type Other rejects array $i", fn() => same(['choice' => 'invalidFormat:choice'], validate([$field], ['choice' => $value, 'choice_other' => $other])));
    }
    check("$type Other requires opt-in", fn() => rejects(['type' => $type, 'options' => ['a']], $value, 'invalidOption'));
}
$email = ['name' => 'email', 'type' => 'email', 'confirm' => true];
check('email confirmation matches', fn() => same([], validate([$email], ['email' => 'a@example.test', 'email_confirm' => ' a@example.test '])));
check('email confirmation mismatch', fn() => same(['email' => 'emailMismatch:email'], validate([$email], ['email' => 'a@example.test', 'email_confirm' => 'b@example.test'])));
foreach ([[], ['a@example.test'], [['a@example.test']]] as $i => $confirm) {
    check("email confirmation rejects array $i", fn() => same(['email' => 'invalidFormat:email'], validate([$email], ['email' => 'a@example.test', 'email_confirm' => $confirm])));
}

check('shape preflight precedes conditions even when dependency is later', function () {
    $fields = [
        ['name' => 'dependent', 'required' => true, 'show_if' => ['field' => 'choices', 'op' => 'contains', 'value' => 'a']],
        ['name' => 'choices', 'type' => 'checkbox', 'options' => ['a']],
    ];
    same(['choices' => 'invalidFormat:choices'], validate($fields, ['choices' => [['a']]]));
    same([], validate($fields, ['choices' => []]));
});
check('hidden fields cannot conceal malformed shape', function () {
    $field = ['name' => 'hidden', 'show_if' => ['field' => 'toggle', 'value' => 'yes']];
    same(['hidden' => 'invalidFormat:hidden'], validate([$field], ['hidden' => [['bad']]]));
    same([], validate([$field + ['required' => true]], []));
});
check('resolved and flattened template child is typed', function () {
    $fields = resolveTemplates([['name' => 'group', 'type' => 'group', 'use' => 'person', 'prefix' => 'p_']], ['person' => [['name' => 'email', 'type' => 'email']]]);
    same(['p_email' => 'invalidFormat:p_email'], validate(flattenFields($fields), ['p_email' => ['bad']]));
});

// Reproduce submit.php's parsing, without running its bootstrap or any side effects.
check('JSON scalar array bypass rejected before collection', function () {
    $input = json_decode('{"email":["not-an-email"],"count":["bad"]}', true);
    same(['email' => 'invalidFormat:email', 'count' => 'invalidFormat:count'], validate([
        ['name' => 'email', 'type' => 'email'], ['name' => 'count', 'type' => 'number'],
    ], $input));
});
check('POST bracket arrays remain visible to validation', function () {
    parse_str('email[]=bad&choices[0][nested]=a', $input);
    same(['email' => 'invalidFormat:email', 'choices' => 'invalidFormat:choices'], validate([
        ['name' => 'email', 'type' => 'email'], ['name' => 'choices', 'type' => 'checkbox'],
    ], $input));
});
check('JSON object shape rejected for scalar', function () {
    same(['email' => 'invalidFormat:email'], validate([['name' => 'email', 'type' => 'email']], json_decode('{"email":{"value":"bad"}}', true)));
});
check('valid multivalue summary safely escapes every item', function () {
    $fields = [['name' => 'choice', 'type' => 'checkbox', 'other' => true]];
    $input = ['choice' => ['a', '<script>alert(1)</script>']];
    same([], validate($fields, $input));
    $html = buildSummary($fields, $input);
    same(false, str_contains($html, '<script>'));
    same(true, str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
});

// Execute the actual collection function and sandbox preview block, not copies of
// their logic. Read source only: no submit bootstrap, config, storage or HTTP.
$submitSource = file_get_contents(dirname(__DIR__) . '/submit.php');
same(1, preg_match('/^function collectData\(.*?^\}/ms', $submitSource, $collectionMatch));
eval($collectionMatch[0]);
same(1, preg_match('/^if \(\$isSandbox\) \{\R(    \$data = .*?^    \$sandboxResult\[\'on_submit_preview\'\] = \$preview;)/ms', $submitSource, $sandboxMatch));
$sandboxPreviewSource = $sandboxMatch[1];
function sandboxPreview(array $flatFields, array $input): array {
    global $sandboxPreviewSource;
    $errors = validate($flatFields, $input);
    // No email/templates, webhooks, actions or payment configured in this fixture.
    $formId = 'in-memory-validation';
    $form = ['fields' => $flatFields, 'on_submit' => ['redirect' => '/preview/{{choice}}']];
    $config = ['storage' => 'file'];
    eval($sandboxPreviewSource);
    return json_decode(json_encode($sandboxResult, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}
function sandboxRejects(array $fields, array $input, array $errors): void {
    $result = sandboxPreview($fields, $input);
    same('error', $result['status']);
    same('Validation failed', $result['message']);
    same(true, $result['sandbox']);
    same(false, $result['validation']['passed']);
    same($errors, $result['validation']['errors']);
    same([], $result['data']);
}
foreach ($scalarFields as $name => $field) {
    foreach ([[], ['bad'], ['key' => 'bad'], [['bad']]] as $i => $value) {
        check("sandbox $name rejects array $i safely", fn() => sandboxRejects(
            [$field + ['name' => 'value']], ['value' => $value], ['value' => 'invalidFormat:value']
        ));
    }
}
foreach ([
    'radio' => ['type' => 'radio'],
    'single select' => ['type' => 'select'],
    'checkbox' => ['type' => 'checkbox'],
    'multiple select' => ['type' => 'select', 'multiple' => true],
] as $name => $kind) {
    $field = $kind + ['name' => 'choice', 'options' => ['a'], 'other' => true];
    $multi = $kind['type'] === 'checkbox' || !empty($kind['multiple']);
    $value = $multi ? ['a', '__other__'] : '__other__';
    foreach ([[], ['bad'], [['bad']]] as $i => $other) {
        check("sandbox $name rejects Other array $i safely", fn() => sandboxRejects(
            [$field], ['choice' => $value, 'choice_other' => $other], ['choice' => 'invalidFormat:choice']
        ));
    }
    if ($multi) {
        check("sandbox $name rejects nested selection safely", fn() => sandboxRejects(
            [$field], ['choice' => ['a', ['__other__']], 'choice_other' => 'Custom'], ['choice' => 'invalidFormat:choice']
        ));
    }
    foreach ([' Custom ' => 'Custom', '' => 'Other'] as $other => $resolved) {
        check("sandbox $name retains resolved Other preview '$other'", function () use ($field, $value, $multi, $other, $resolved) {
            $result = sandboxPreview([$field], ['choice' => $value, 'choice_other' => $other]);
            same('ok', $result['status']);
            same(true, $result['validation']['passed']);
            same([], $result['validation']['errors']);
            same(['choice' => $multi ? ['a', $resolved] : $resolved], $result['data']);
            same('/preview/' . ($multi ? '{{choice}}' : $resolved), $result['on_submit_preview']['redirect']);
        });
    }
}
check('sandbox nested condition dependency never reaches collection casts', function () {
    sandboxRejects([
        ['name' => 'dependent', 'show_if' => ['field' => 'choice', 'op' => 'contains', 'value' => 'a']],
        ['name' => 'choice', 'type' => 'checkbox', 'options' => ['a']],
    ], ['choice' => [['a']]], ['choice' => 'invalidFormat:choice']);
});
foreach ([
    [['name' => 'email', 'type' => 'email'], ['email' => ' not-an-email '], 'invalidEmail:email', ['email' => 'not-an-email']],
    [['name' => 'required', 'required' => true], [], 'required:required', ['required' => '']],
    [['name' => 'code', 'pattern' => '^[A-Z]+$'], ['code' => ' bad '], 'invalidFormat:code', ['code' => 'bad']],
] as $i => [$field, $input, $error, $data]) {
    check("sandbox shape-valid validation error $i retains data and action preview", function () use ($field, $input, $error, $data) {
        $fields = [$field, ['name' => 'choice', 'type' => 'checkbox', 'other' => true, 'options' => ['a']]];
        $input += ['choice' => ['a', '__other__'], 'choice_other' => ' Custom '];
        same([], validateFieldShapes($fields, $input));
        $result = sandboxPreview($fields, $input);
        same('error', $result['status']);
        same(false, $result['validation']['passed']);
        same([$field['name'] => $error], $result['validation']['errors']);
        same($data + ['choice' => ['a', 'Custom']], $result['data']);
        same('/preview/{{choice}}', $result['on_submit_preview']['redirect']);
        same(['enabled' => true, 'backend' => 'file'], $result['on_submit_preview']['store']);
    });
}

restore_error_handler();
printf("Typed validation regression tests: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
