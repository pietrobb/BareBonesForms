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
check('6129-F14 number rejects a non-finite numeric literal', fn() => rejects(['type' => 'number'], '1e309', 'invalidNumber'));
foreach (['1.5', '0', '6', '1e309'] as $rating) {
    $message = $rating === '0' ? 'numberMin' : ($rating === '6' ? 'numberMax' : 'invalidNumber');
    check("6129-F14 rating rejects invalid value $rating", fn() => rejects(['type' => 'rating'], $rating, $message));
}
foreach (['1', '5'] as $rating) {
    check("6129-F14 rating accepts integer in implicit one-to-five range $rating", fn() => accepts(['type' => 'rating'], $rating));
}
check('6129-F14 rating honors an explicit renderer maximum', fn() => accepts(['type' => 'rating', 'max' => 10], '10'));

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
foreach (['not-a-date', '2026-02-30', '2026-2-03', '2026-02-03T00:00:00Z'] as $date) {
    check("date rejects invalid calendar value $date", fn() => rejects(['type' => 'date'], $date));
}
foreach (['2024-02-29', '2026-12-31'] as $date) {
    check("date accepts real calendar value $date", fn() => accepts(['type' => 'date'], $date));
}
check('form definition rejects malformed cross-field validation shapes', function () {
    $base = ['id' => 'cross-rules', 'fields' => [['name' => 'amount', 'type' => 'number']]];
    same(true, in_array('validations: Expected a list.', validateFormDefinition($base + ['validations' => 'bad-shape']), true));
    $errors = validateFormDefinition($base + ['validations' => [[
        'type' => 'unknown', 'fields' => ['missing'], 'min' => -1, 'message' => [],
    ]]]);
    foreach (['validations[0].type: Expected min_sum or min_filled.',
        'validations[0].fields[0]: Unknown form field.',
        'validations[0].min: Expected a non-negative number.',
        'validations[0].message: Expected string.'] as $error) {
        same(true, in_array($error, $errors, true));
    }
    same([], validateFormDefinition($base + ['validations' => [[
        'type' => 'min_sum', 'fields' => ['amount'], 'min' => 0.5, 'message' => 'Need amount',
    ]]]));
});

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
check('conditionally hidden option is rejected', function () {
    $field = ['name' => 'plan', 'type' => 'select', 'options' => [
        'personal', ['value' => 'business', 'show_if' => ['field' => 'customer_type', 'value' => 'business']],
    ]];
    same(['plan' => 'invalidOption:plan'], validate([$field], ['customer_type' => 'personal', 'plan' => 'business']));
    same([], validate([$field], ['customer_type' => 'business', 'plan' => 'business']));
});
check('template prefix rewrites option conditions without top-level collisions', function () {
    $resolved = resolveTemplates([
        ['name' => 'instance', 'type' => 'group', 'use' => 'choices', 'prefix' => 'p_'],
    ], ['choices' => [
        ['name' => 'gate'],
        ['name' => 'choice', 'type' => 'select', 'options' => [
            ['value' => 'conditional', 'show_if' => ['field' => 'gate', 'value' => 'yes']],
        ]],
    ]]);
    $fields = flattenFields($resolved);
    same([], validate($fields, ['gate' => 'no', 'p_gate' => 'yes', 'p_choice' => 'conditional']));
    same(['p_choice' => 'invalidOption:p_choice'], validate($fields, ['gate' => 'yes', 'p_gate' => 'no', 'p_choice' => 'conditional']));
});
check('repeatable option condition uses its row context', function () {
    $children = [
        ['name' => 'kind', 'type' => 'select', 'options' => ['normal', 'special']],
        ['name' => 'detail', 'type' => 'select', 'options' => [
            'plain', ['value' => 'secret', 'show_if' => ['field' => 'kind', 'value' => 'special']],
        ]],
    ];
    $group = ['name' => 'items', 'type' => 'group', 'repeatable' => true, 'min_items' => 1, 'max_items' => 2, 'fields' => $children];
    same(['items.0.detail' => 'invalidOption:detail'], validate([$group], ['kind' => 'special', 'items' => [['kind' => 'normal', 'detail' => 'secret']]]));
    same([], validate([$group], ['kind' => 'normal', 'items' => [['kind' => 'special', 'detail' => 'secret']]]));
});

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
check('missing email template renders structured values as escaped text', function () {
    $html = renderTemplate(__DIR__ . '/missing-template.html', [
        'items' => [['name' => '<script>alert(1)</script>']],
    ]);
    same(false, str_contains($html, '<script>'));
    same(true, str_contains($html, '&lt;script&gt;alert(1)&lt;\/script&gt;'));
});

// Execute the actual collection function and sandbox preview block, not copies of
// their logic. Read source only: no submit bootstrap, config, storage or HTTP.
$submitSource = file_get_contents(dirname(__DIR__) . '/submit.php');
same(true, strpos($submitSource, 'validateCrossFields(') < strpos($submitSource, '// ─── Sandbox mode'));
same(2, substr_count($submitSource, '$data = $normalizedData;'));
same(1, preg_match('/^function collectData\(.*?^\}/ms', $submitSource, $collectionMatch));
eval($collectionMatch[0]);
check('6129-F03 collection and conditions share normalized scalar input', function () {
    $fields = [
        ['name' => 'kind', 'type' => 'select', 'options' => ['personal', 'business']],
        ['name' => 'tax_id', 'required' => true, 'show_if' => ['field' => 'kind', 'value' => 'business']],
    ];
    same(['tax_id' => 'required:tax_id'], validate($fields, ['kind' => ' business ']));
    same(['kind' => 'business', 'tax_id' => 'SK123'], collectData($fields, ['kind' => ' business ', 'tax_id' => ' SK123 ']));
    same(['flag' => ''], collectData([['name' => 'flag']], ['flag' => false]));
});
same(1, preg_match('/^if \(\$isSandbox\) \{\R(    \$data = .*?^    \$sandboxResult\[\'on_submit_preview\'\] = \$preview;)/ms', $submitSource, $sandboxMatch));
$sandboxPreviewSource = $sandboxMatch[1];
function sandboxPreview(array $flatFields, array $input, array $validations = []): array {
    global $sandboxPreviewSource;
    // No email/templates, webhooks, actions or payment configured in this fixture.
    $formId = 'in-memory-validation';
    $form = ['fields' => $flatFields, 'validations' => $validations,
        'on_submit' => ['redirect' => '/preview/{{choice}}']];
    $shapeErrors = validateFieldShapes($flatFields, $input);
    $errors = validate($flatFields, $input);
    $normalizedData = $shapeErrors ? [] : collectData($flatFields, $input);
    if (!$shapeErrors) $errors = array_replace($errors, validateCrossFields($validations, $normalizedData));
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
check('cross-field rules use only normalized visible data', function () {
    $fields = [
        ['name' => 'customer_type', 'type' => 'select', 'options' => ['personal', 'business']],
        ['name' => 'hidden_amount', 'type' => 'number', 'show_if' => ['field' => 'customer_type', 'value' => 'business']],
        ['name' => 'visible_amount', 'type' => 'number'],
        ['name' => 'note'],
        ['name' => 'tags', 'type' => 'checkbox', 'options_from' => 'fixture'],
    ];
    $input = ['customer_type' => 'personal', 'hidden_amount' => '100', 'visible_amount' => '0', 'note' => '   ', 'tags' => ['   ']];
    $data = collectData($fields, $input);
    same(['customer_type' => 'personal', 'visible_amount' => '0', 'note' => '', 'tags' => ['   ']], $data);
    same([
        '_cross_hidden_amount_visible_amount' => 'Need fifty',
        '_cross_note_hidden_amount' => 'Fill one',
        '_cross_tags' => 'Select one',
    ], validateCrossFields([
        ['type' => 'min_sum', 'fields' => ['hidden_amount', 'visible_amount'], 'min' => 50, 'message' => 'Need fifty'],
        ['type' => 'min_filled', 'fields' => ['note', 'hidden_amount'], 'min' => 1, 'message' => 'Fill one'],
        ['type' => 'min_filled', 'fields' => ['tags'], 'min' => 1, 'message' => 'Select one'],
    ], $data));
});
check('cross-field rule without message uses the translated default, not English', function () {
    same(['_cross_amount' => 'crossFieldInvalid:'],
        validateCrossFields([['type' => 'min_sum', 'fields' => ['amount'], 'min' => 5]], ['amount' => '2']));
});
check('sandbox applies cross-field rules to the same normalized data', function () {
    $fields = [['name' => 'amount', 'type' => 'number'], ['name' => 'note']];
    $rules = [['type' => 'min_sum', 'fields' => ['amount'], 'min' => 5, 'message' => 'Need five']];
    $result = sandboxPreview($fields, ['amount' => ' 2 ', 'note' => ' x '], $rules);
    same('error', $result['status']);
    same(['_cross_amount' => 'Need five'], $result['validation']['errors']);
    same(['amount' => '2', 'note' => 'x'], $result['data']);
});
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
            // 2.1.5: a multi-value answer is joined with "," and percent-encoded, never left as a literal {{choice}}.
            same('/preview/' . ($multi ? 'a%2C' . $resolved : $resolved), $result['on_submit_preview']['redirect']);
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
        same('/preview/a%2CCustom', $result['on_submit_preview']['redirect']);
        same(['enabled' => true, 'backend' => 'file'], $result['on_submit_preview']['store']);
    });
}

// ─── 2.1.1 review findings ─────────────────────────────────────
check('pattern containing / validates instead of breaking the definition', function (): void {
    $field = ['type' => 'text', 'pattern' => '^\d{2}/\d{2}/\d{4}$'];
    accepts($field, '01/02/2026');
    rejects($field, '01-02-2026');
    $errors = [];
    $names = [];
    validateFieldList([$field + ['name' => 'd']], 'fields', $errors, $names);
    same([], $errors);
});
check('pattern with an escaped slash and Unicode letters matches like the browser', function (): void {
    accepts(['type' => 'text', 'pattern' => '^a\/b$'], 'a/b');
    accepts(['type' => 'text', 'pattern' => '^.{3}$'], 'čšž');
});
check('quoted local parts that would split into several recipients are refused', function (): void {
    rejects(['type' => 'email'], '"a,b@evil.test,c"@example.com', 'invalidEmail');
    accepts(['type' => 'email'], 'jana.novak@example.com');
});
check('phone rule matches the browser', function (): void {
    accepts(['type' => 'tel'], '(555) 123-4567');
    accepts(['type' => 'tel'], "+421\u{00A0}900\u{00A0}123456");
    accepts(['type' => 'tel'], "+421\u{2009}900\u{2009}123456");
    rejects(['type' => 'tel'], 'call me', 'invalidTel');
});
check('show_if chain A -> B -> C: a hidden B hides C, and its forged value counts as empty', function (): void {
    $fields = [
        ['name' => 'a', 'type' => 'text'],
        ['name' => 'b', 'type' => 'text', 'show_if' => ['field' => 'a', 'value' => 'yes']],
        ['name' => 'c', 'type' => 'text', 'label' => 'C', 'required' => true, 'show_if' => ['field' => 'b', 'value' => 'go']],
    ];
    same([], validate($fields, ['a' => 'no', 'b' => 'go']));
    same(['a' => 'no'], bbfVisibleInput($fields, ['a' => 'no', 'b' => 'go', 'c' => 'x']));
    same(['c' => 'required:C'], validate($fields, ['a' => 'yes', 'b' => 'go']));
});
check('invalid UTF-8 is detected before storage', function (): void {
    same(true, bbfValidUtf8Deep(['name' => 'Žofia', 'list' => ['ok']]));
    same(false, bbfValidUtf8Deep(['name' => "bad\xC3"]));
    same(false, bbfValidUtf8Deep(["bad\xFF" => 'key']));
});
check('mail headers: quoted display names and RFC 5322 Date/Message-ID', function (): void {
    same('"Firma, s.r.o." <info@example.com>', bbf_mail_address_header('Firma, s.r.o.', 'info@example.com'));
    same('=?UTF-8?B?' . base64_encode('Žofia') . '?= <z@example.com>', bbf_mail_address_header('Žofia', 'z@example.com'));
    $headers = bbf_mail_standard_headers('noreply@example.com');
    same(true, (bool)preg_match('/\A<[0-9a-f]{32}@example\.com>\z/', $headers['Message-ID']) && strtotime($headers['Date']) > 0);
});

// ─── 2.1.2: re-review of 2.1.1 ─────────────────────────────────
check('\d and \w stay ASCII like the browser: Arabic digits do not pass ^\d{5}$', function (): void {
    rejects(['type' => 'text', 'pattern' => '^\d{5}$'], '١٢٣٤٥', 'invalidFormat');
    accepts(['type' => 'text', 'pattern' => '^\d{5}$'], '12345');
    rejects(['type' => 'text', 'pattern' => '^\w+$'], 'ščť', 'invalidFormat');
    accepts(['type' => 'text', 'pattern' => '^[a-zščťžýáíé]{5}$'], 'ščťžý');
});

// ─── 2.1.3: review of 2.1.2 ────────────────────────────────────
check('\s matches Unicode spaces like the browser (NBSP, U+2009, U+3000); \S excludes them', function (): void {
    $nbsp = "\u{00A0}";
    accepts(['type' => 'text', 'pattern' => '^\S+\s\S+$'], "Jana{$nbsp}Nová");
    accepts(['type' => 'text', 'pattern' => '^\S+\s\S+$'], "Jana\u{2009}Nová");
    accepts(['type' => 'text', 'pattern' => '^[\s\w]+$'], "ab{$nbsp}cd\u{3000}");
    rejects(['type' => 'text', 'pattern' => '^\S+$'], "Jana{$nbsp}Nová", 'invalidFormat');
    rejects(['type' => 'text', 'pattern' => '^\w+$'], "a{$nbsp}b", 'invalidFormat');
    accepts(['type' => 'text', 'pattern' => '^a\\\\s$'], 'a\\s');
    same('/(*UTF)^\\\\s[\\/]$/', bbfFieldPatternRegex('^\\\\s[/]$'));
});

// ─── 2.1.5: review of 2.1.4 ─────────────────────────────────────
check('\S inside a character class behaves like the browser ([^\S\r\n] = whitespace without line breaks, incl. NBSP)', function (): void {
    $nbsp = "\u{00A0}";
    foreach (["a{$nbsp}b", 'a b', "a\tb", "a\u{3000}b"] as $ok) accepts(['type' => 'text', 'pattern' => '^a[^\S\r\n]b$'], $ok);
    foreach (["a\nb", "a\rb", 'axb'] as $bad) rejects(['type' => 'text', 'pattern' => '^a[^\S\r\n]b$'], $bad);
    accepts(['type' => 'text', 'pattern' => '^[\S]+$'], 'Nová');
    rejects(['type' => 'text', 'pattern' => '^[\S]+$'], "Jana{$nbsp}Nová");
    accepts(['type' => 'text', 'pattern' => '^[\S ]+$'], 'Jana Nová');
    rejects(['type' => 'text', 'pattern' => '^[\S ]+$'], "Jana{$nbsp}Nová");
    rejects(['type' => 'text', 'pattern' => '^[^\S]$'], 'x');
    accepts(['type' => 'text', 'pattern' => '^[^\S]$'], $nbsp);
    accepts(['type' => 'text', 'pattern' => '^[a-c\S]{2}\d$'], 'a%1');
});

check('malformed show_if never breaks a submission and is reported by the definition check', function (): void {
    $input = ['kind' => 'b', 'detail' => ''];
    foreach ([['all' => ['field' => 'kind', 'value' => 'a']], ['any' => 'kind'], 'kind', 42, ['field' => ['kind']], ['field' => 'kind', 'op' => ['not']],
              ['all' => ['x', null]], ['field' => 'kind', 'value' => [['a']]]] as $cond) {
        same(true, is_bool(evalCondition($cond, $input)));
        $fields = [['name' => 'kind', 'type' => 'text'], ['name' => 'detail', 'type' => 'text', 'required' => true, 'show_if' => $cond]];
        same(true, is_array(validate(flattenFields($fields), $input))); // no TypeError (a 500 in 2.1.4)
        same(true, is_array(resolveTemplates([['name' => 't', 'type' => 'text', 'use' => 'tpl', 'prefix' => 'p_']], ['tpl' => [['name' => 'x', 'show_if' => $cond]]])));
    }
    // Same result as bbf.js, which ignores all/any that are not a list: {"all": {...}} is a no-op there too.
    same(true, evalCondition(['all' => ['field' => 'kind', 'value' => 'a']], $input));
    same(false, evalCondition(['field' => 'kind', 'value' => 'a', 'op' => 'eq'], $input), 'an unknown op still means equals');
    $errors = static fn($cond) => validateFormDefinition(['id' => 'f', 'fields' => [['name' => 'kind', 'type' => 'text'],
        ['name' => 'detail', 'type' => 'select', 'show_if' => $cond, 'options' => ['a', ['value' => 'b', 'show_if' => $cond]]]]]);
    same(['fields[1].show_if.all: Expected a list of conditions, e.g. "all": [{"field": "…", "value": "…"}].',
        'fields[1].options[1].show_if.all: Expected a list of conditions, e.g. "all": [{"field": "…", "value": "…"}].'],
        $errors(['all' => ['field' => 'kind', 'value' => 'a']]));
    same(true, str_contains(implode(' ', $errors('kind')), 'fields[1].show_if: Expected an object'));
    same(true, str_contains(implode(' ', $errors(['any' => [['field' => 'kind'], null]])), 'show_if.any[1]: Expected an object'));
    same(true, str_contains(implode(' ', $errors(['field' => ['kind']])), 'show_if.field: Expected the name'));
    same(true, str_contains(implode(' ', $errors(['field' => 'kind', 'op' => ['not']])), 'show_if.op: Expected a string'));
    foreach ([null, false, [], ['field' => 'kind', 'value' => 'a', 'op' => 'eq'], ['all' => [], 'any' => [['field' => 'kind', 'op' => 'empty']]]] as $fine) {
        same([], $errors($fine));
    }
    // Review 2.1.5: generators and editors write null for "not set"; 2.1.4 accepted it, so it must stay valid.
    foreach ([['field' => 'kind', 'op' => null, 'value' => 'b'], ['field' => null], ['field' => 'kind', 'value' => 'b', 'all' => null, 'any' => null],
              ['any' => [['field' => 'kind', 'op' => null, 'value' => 'b']]]] as $nulls) {
        same([], $errors($nulls));
    }
    same(true, evalCondition(['field' => 'kind', 'op' => null, 'value' => 'b'], $input), 'op null means equals');
    same(true, evalCondition(['field' => null, 'value' => 'x'], $input), 'field null means no condition');
    same(true, evalCondition(['field' => 'n', 'value' => 5], ['n' => '5']), 'a numeric value matches the submitted text');
    // Review 2.1.6: an object or nested list as value is evaluated differently by bbf.js and the server.
    foreach ([['field' => 'kind', 'value' => ['a' => 'b']], ['field' => 'kind', 'value' => [['b']]], ['any' => [['field' => 'kind', 'value' => ['x' => 1]]]]] as $object) {
        same(true, str_contains(implode(' ', $errors($object)), '.value: Expected text, a number'));
    }
    foreach ([['field' => 'kind', 'value' => 5], ['field' => 'kind', 'value' => true], ['field' => 'kind', 'value' => ['a', 5, false]], ['field' => 'kind', 'value' => []]] as $scalar) {
        same([], $errors($scalar));
    }
    $schema = json_decode((string)file_get_contents(dirname(__DIR__) . '/forms/form.schema.json'), true);
    $leaf = $schema['$defs']['condition']['oneOf'][0]['properties'];
    same(true, in_array('number', $leaf['value']['oneOf'][0]['type'], true) && in_array(null, $leaf['op']['enum'], true) && in_array('null', $leaf['op']['type'], true),
        'form.schema.json allows "value": 5 and "op": null like the server does');
    foreach (glob(dirname(__DIR__) . '/forms/*.json') as $file) {
        $form = json_decode((string)file_get_contents($file), true);
        if (is_array($form) && isset($form['fields'])) same([], array_values(array_filter(validateFormDefinition($form), static fn($e) => str_contains($e, 'show_if'))));
    }
});

restore_error_handler();
printf("Typed validation regression tests: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
