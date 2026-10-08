<?php
/** 2.2.0 embedded mode: bbf_form.php alone, as a host application loads it (no BBF_LOADED, no config, no I/O). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!extension_loaded('fileinfo') && getenv('BBF_EMBEDDED_CHILD') !== '1') {
    // Upload checks need fileinfo, which Windows PHP builds ship but do not load by default.
    $proc = proc_open([PHP_BINARY, '-d', 'extension=fileinfo', __FILE__], [], $pipes, null, ['BBF_EMBEDDED_CHILD' => '1'] + getenv());
    exit(is_resource($proc) ? proc_close($proc) : 1);
}
require dirname(__DIR__) . '/bbf_form.php';
$passed = 0; $failed = 0;
function ef_check(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $ok = $fn();
    } catch (Throwable $e) {
        $ok = false; $name .= ': ' . get_class($e) . ' ' . $e->getMessage();
    }
    if ($ok === true) { ++$passed; return; }
    ++$failed; print "FAIL $name\n";
}
function ef_throws(callable $fn, string $class = BbfFormException::class): bool {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}
$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/bbf-embedded-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$product = ['id' => 'admin-product', 'fields' => [
    ['name' => 'title', 'type' => 'text', 'label' => 'Názov', 'required' => true, 'maxlength' => 20],
    ['name' => 'price', 'type' => 'number', 'label' => 'Cena', 'required' => true, 'min' => 0],
    ['name' => 'category', 'type' => 'select', 'label' => 'Kategória', 'options_from' => '/api/categories'],
    ['name' => 'on_sale', 'type' => 'checkbox', 'label' => 'Akcia', 'options' => ['yes']],
    ['name' => 'sale_price', 'type' => 'number', 'label' => 'Akciová cena', 'show_if' => ['field' => 'on_sale', 'value' => 'yes']],
    ['name' => 'image', 'type' => 'file', 'label' => 'Obrázok', 'required' => true, 'accept' => ['.png', '.jpg']],
]];
$categories = static fn(string $source) => $source === '/api/categories' ? [['value' => '7', 'label' => 'Stany'], ['value' => '9', 'label' => 'Haly']] : null;
$editing = ['values' => ['image' => ['url' => '/media/p1.png', 'name' => 'p1.png']], 'options_resolver' => $categories];

// ── Loading ─────────────────────────────────────────────────────
ef_check('library loads without BBF_LOADED, config or output', fn() => !defined('BBF_LOADED') && session_status() !== PHP_SESSION_ACTIVE && ob_get_level() <= 1);
file_put_contents("$tmp/admin-product.json", json_encode($product));
file_put_contents("$tmp/mismatch.json", json_encode(['id' => 'other'] + $product));
file_put_contents("$tmp/broken.json", json_encode(['id' => 'broken', 'fields' => [['name' => 'a', 'type' => 'nonsense']]]));
ef_check('bbf_load_form returns the checked definition', fn() => bbf_load_form('admin-product', $tmp)['fields'][0]['name'] === 'title');
ef_check('bbf_load_form refuses a path-like id', fn() => ef_throws(fn() => bbf_load_form('../admin-product', $tmp)));
ef_check('bbf_load_form refuses a missing form', fn() => ef_throws(fn() => bbf_load_form('nope', $tmp)));
ef_check('bbf_load_form refuses an id mismatch', fn() => ef_throws(fn() => bbf_load_form('mismatch', $tmp)));
ef_check('definition errors are listed on the exception', function () use ($tmp) {
    try { bbf_load_form('broken', $tmp); } catch (BbfFormException $e) { return $e->errors !== [] && str_contains(implode(' ', $e->errors), 'type'); }
    return false;
});

// ── Validation, language, messages ──────────────────────────────
ef_check('valid input is normalized; the hidden show_if field is dropped', function () use ($product, $editing) {
    $r = bbf_validate($product, ['title' => '  Stan 3x3 ', 'price' => '120', 'category' => '7', 'sale_price' => '99'], [], $editing);
    return $r['errors'] === [] && !array_key_exists('sale_price', $r['data']) && $r['data']['category'] === '7'
        && $r['data']['image'] === ['action' => 'keep', 'files' => []];
});
ef_check('errors come in the requested language', function () use ($product, $editing) {
    $r = bbf_validate($product, [], [], ['lang' => 'sk'] + $editing);
    return isset($r['errors']['title'], $r['errors']['price']) && str_contains($r['errors']['title'], 'Názov') && !str_contains($r['errors']['title'], 'required');
});
ef_check('an unknown language falls back to English', fn() => str_contains(bbf_validate($product, [], [], ['lang' => 'xx'] + $editing)['errors']['title'], 'required'));
ef_check('the host overrides single messages', fn() => bbf_validate($product, [], [], ['lang' => 'sk', 'messages' => ['required' => 'Chýba {label}']] + $editing)['errors']['title'] === 'Chýba Názov');
ef_check('the message handler is restored afterwards', fn() => !isset($GLOBALS['_bbf_t']) && bbf_t('required', ['label' => 'X']) === 'X is required.');
ef_check('maxlength is enforced', fn() => isset(bbf_validate($product, ['title' => str_repeat('a', 21), 'price' => '1'], [], $editing)['errors']['title']));
ef_check('a non-scalar value for a text field is refused, not cast', fn() => isset(bbf_validate($product, ['title' => ['x'], 'price' => '1'], [], $editing)['errors']['title']));

// ── options_from (R8) ───────────────────────────────────────────
ef_check('options_from: a value outside the resolved options is refused', fn() => isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '666'], [], $editing)['errors']['category']));
ef_check('options_from: without a resolver no value is accepted', fn() => isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '7'], [], ['values' => $editing['values']])['errors']['category']));
ef_check('options_from: a throwing resolver accepts no value', fn() => isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '7'], [], ['values' => $editing['values'], 'options_resolver' => fn() => throw new RuntimeException('db down')])['errors']['category']));
ef_check('options_from: an empty optional value stays valid', fn() => bbf_validate($product, ['title' => 'A', 'price' => '1'], [], ['values' => $editing['values']])['errors'] === []);
ef_check('options_resolver must be callable', fn() => ef_throws(fn() => bbf_validate($product, [], [], ['options_resolver' => 'not a function at all'])));

// ── Custom x- types (R5) ────────────────────────────────────────
$tree = ['id' => 'cat', 'fields' => [['name' => 'parent', 'type' => 'x-category-tree', 'label' => 'Nadradená', 'required' => true]]];
ef_check('an unregistered x- type is a definition error', fn() => ef_throws(fn() => bbf_validate($tree, ['parent' => '1'])));
ef_check('x- type names are checked', fn() => ef_throws(fn() => bbf_register_type('category-tree', []), InvalidArgumentException::class)
    && ef_throws(fn() => bbf_register_type('x-ok', ['render' => 'nope']), InvalidArgumentException::class));
bbf_register_type('x-category-tree', [
    'validate' => fn($v) => ctype_digit((string)$v) ? null : 'Neplatná kategória.',
    'normalize' => fn($v) => (int)$v,
]);
ef_check('a registered x- type validates and normalizes', fn() => bbf_validate($tree, ['parent' => '12'])['data'] === ['parent' => 12]
    && bbf_validate($tree, ['parent' => 'x'])['errors'] === ['parent' => 'Neplatná kategória.']
    && isset(bbf_validate($tree, [])['errors']['parent']));
$calls = [];
bbf_register_type('x-category-list', [
    'validate' => function ($v) use (&$calls) {
        $calls[] = ['validate', $v];
        return is_array($v) && array_filter($v, 'ctype_digit') === $v ? null : 'Neplatné kategórie.';
    },
    'normalize' => function ($v) use (&$calls) {
        $calls[] = ['normalize', $v];
        return array_map('intval', $v);
    },
]);
$list = ['id' => 'cl', 'fields' => [['name' => 'cats', 'type' => 'x-category-list', 'label' => 'Kategórie', 'required' => true]]];
ef_check('x- array value (name[]) reaches validate raw, then normalize, whose result is data', function () use ($list, &$calls) {
    $calls = [];
    $r = bbf_validate($list, ['cats' => ['3', '12']]);
    return $r['errors'] === [] && $r['data'] === ['cats' => [3, 12]] && $calls === [['validate', ['3', '12']], ['normalize', ['3', '12']]];
});
ef_check('x- value that fails validate is never normalized and is not in data', function () use ($list, &$calls) {
    $calls = [];
    $r = bbf_validate($list, ['cats' => ['3', 'x']]);
    return $r['errors'] === ['cats' => 'Neplatné kategórie.'] && !array_key_exists('cats', $r['data']) && count($calls) === 1;
});
ef_check('x- required means a non-empty array or a non-blank string', function () use ($list, &$calls) {
    $calls = [];
    return isset(bbf_validate($list, ['cats' => []])['errors']['cats']) && isset(bbf_validate($list, ['cats' => '  '])['errors']['cats'])
        && isset(bbf_validate($list, [])['errors']['cats']) && $calls === [];
});
ef_check('registering the same x- type twice throws, the first stays', fn() => ef_throws(fn() => bbf_register_type('x-category-list', []), InvalidArgumentException::class)
    && bbf_validate($list, ['cats' => ['5']])['data'] === ['cats' => [5]]);

// ── BareBonesEshop AdminForm points (unknown keys, stored option values) ──
ef_check('unknown input keys (csrf_token, attr[…], options[], category_ids[]) are ignored, data has only definition fields', function () use ($product, $editing) {
    $r = bbf_validate($product, ['title' => 'A', 'price' => '1', 'csrf_token' => 'abc', 'attr' => ['3' => 'x'], 'options' => ['a', 'b'], 'category_ids' => ['7', '9']], [], $editing);
    return $r['errors'] === [] && array_keys($r['data']) === ['title', 'price', 'category', 'on_sale', 'image'];
});
ef_check('a stored select value no longer among the options is accepted when it equals values[field]', fn() =>
    bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '42'], [], ['values' => $editing['values'] + ['category' => '42']] + $editing)['data']['category'] === '42');
ef_check('any other value outside the options stays refused, even with a stored value', fn() =>
    isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '43'], [], ['values' => $editing['values'] + ['category' => '42']] + $editing)['errors']['category']));
ef_check('the stored value is accepted even when the source failed (deleted category, DB down)', fn() =>
    bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '42'], [], ['values' => $editing['values'] + ['category' => '42']])['errors'] === []
    && isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'category' => '7'], [], ['values' => $editing['values'] + ['category' => '42']])['errors']['category']));
ef_check('stored checkbox values are kept per value', function () use ($product, $editing) {
    $opts = ['values' => $editing['values'] + ['on_sale' => ['yes', 'legacy']]] + $editing;
    return bbf_validate($product, ['title' => 'A', 'price' => '1', 'on_sale' => ['legacy']], [], $opts)['errors'] === []
        && isset(bbf_validate($product, ['title' => 'A', 'price' => '1', 'on_sale' => ['forged']], [], $opts)['errors']['on_sale']);
});

// ── Files (R6, $_FILES) ─────────────────────────────────────────
$png = "$tmp/upload.tmp";
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$php = "$tmp/evil.tmp";
file_put_contents($php, '<?php echo 1;');
$upload = static fn(string $path, string $name) => ['name' => $name, 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
$base = ['title' => 'A', 'price' => '1'];
$noCheck = ['check_uploaded' => false, 'options_resolver' => $categories];
ef_check('a required file without an existing one is an error', fn() => isset(bbf_validate($product, $base, [], $noCheck)['errors']['image']));
ef_check('an existing file satisfies the required file field (keep)', fn() => bbf_validate($product, $base, [], $editing)['data']['image']['action'] === 'keep');
ef_check('a valid upload replaces the file', function () use ($product, $base, $png, $upload, $noCheck) {
    $r = bbf_validate($product, $base, ['image' => $upload($png, 'Fotka produktu.PNG')], $noCheck);
    $f = $r['data']['image']['files'][0] ?? [];
    return $r['errors'] === [] && $r['data']['image']['action'] === 'replace' && $f['ext'] === 'png' && $f['tmp_name'] === $png
        && $f['sha256'] === hash_file('sha256', $png) && is_file($png);
});
ef_check('a file not uploaded by PHP is refused by default', fn() => isset(bbf_validate($product, $base, ['image' => $upload($png, 'a.png')], ['options_resolver' => $categories])['errors']['image']));
ef_check('a disallowed extension is refused', fn() => isset(bbf_validate($product, $base, ['image' => $upload($png, 'a.pdf')], $noCheck)['errors']['image']));
ef_check('a PHP file is refused even if renamed', fn() => isset(bbf_validate($product, $base, ['image' => $upload($php, 'a.png')], $noCheck)['errors']['image'])
    && isset(bbf_validate($product, $base, ['image' => $upload($png, 'a.php')], $noCheck)['errors']['image']));
ef_check('a PHP upload error is reported', fn() => isset(bbf_validate($product, $base, ['image' => ['name' => 'a.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]], $noCheck)['errors']['image']));
ef_check('too many files are refused', fn() => isset(bbf_validate($product, $base, ['image' => [
    'name' => ['a.png', 'b.png'], 'tmp_name' => [$png, $png], 'error' => [0, 0], 'size' => [filesize($png), filesize($png)]]], $noCheck)['errors']['image']));
ef_check('the __remove checkbox removes an optional existing file', function () use ($product, $base, $editing) {
    $optional = $product; $optional['fields'][5]['required'] = false;
    return bbf_validate($optional, $base + ['image__remove' => '1'], [], $editing)['data']['image'] === ['action' => 'remove', 'files' => []];
});
ef_check('removing a required file without a replacement is an error', fn() => isset(bbf_validate($product, $base + ['image__remove' => '1'], [], $editing)['errors']['image']));
ef_check('a value posted under a file field name is never data', fn() => bbf_validate($product, $base + ['image' => '/etc/passwd'], [], $editing)['data']['image']['action'] === 'keep');
ef_check('nothing was moved or written', fn() => is_file($png) && count(glob("$tmp/*")) === 5);

print "Embedded form library: $passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
