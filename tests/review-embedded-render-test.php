<?php
/** 2.2.0 embedded mode: bbf_render_html() as a host application uses it (bbf_render.php alone, no config, no I/O). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__) . '/bbf_render.php';
$passed = 0; $failed = 0;
function er_check(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $ok = $fn();
    } catch (Throwable $e) {
        $ok = false; $name .= ': ' . get_class($e) . ' ' . $e->getMessage();
    }
    if ($ok === true) { ++$passed; return; }
    ++$failed; print "FAIL $name\n";
}
function er_throws(callable $fn, string $needle = ''): bool {
    try { $fn(); } catch (BbfFormException $e) { return $needle === '' || str_contains($e->getMessage(), $needle); }
    return false;
}
function er_dom(string $html): DOMXPath {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR);
    libxml_clear_errors();
    return new DOMXPath($doc);
}
function er_attr(DOMXPath $x, string $query, string $attr): ?string {
    $node = $x->query($query)->item(0);
    return $node instanceof DOMElement && $node->hasAttribute($attr) ? $node->getAttribute($attr) : null;
}
function er_count(DOMXPath $x, string $query): int { return $x->query($query)->length; }

$product = ['id' => 'admin-product', 'name' => 'Produkt', 'submit_label' => 'Uložiť', 'fields' => [
    ['name' => 'title', 'type' => 'text', 'label' => 'Názov', 'required' => true, 'maxlength' => 20, 'size' => 'medium'],
    ['name' => 'price', 'type' => 'number', 'label' => 'Cena', 'required' => true, 'min' => 0, 'suffix' => '€'],
    ['name' => 'category', 'type' => 'select', 'label' => 'Kategória', 'options_from' => '/api/categories', 'placeholder' => 'Vyberte'],
    ['name' => 'on_sale', 'type' => 'checkbox', 'label' => 'Akcia', 'options' => ['yes']],
    ['name' => 'sale_price', 'type' => 'number', 'label' => 'Akciová cena', 'show_if' => ['field' => 'on_sale', 'value' => 'yes']],
    ['name' => 'note', 'type' => 'textarea', 'label' => 'Poznámka', 'rows' => 3, 'description' => 'Interná'],
    ['name' => 'image', 'type' => 'file', 'label' => 'Obrázok', 'required' => true, 'accept' => ['png', 'jpg']],
]];
$categories = static fn(string $source) => $source === '/api/categories' ? [['value' => '7', 'label' => 'Stany'], ['value' => '9', 'label' => 'Haly']] : null;
$base = ['action' => '/admin.php?page=product&id=5', 'options_resolver' => $categories, 'lang' => 'sk'];

er_check('action is required', static fn() => er_throws(static fn() => bbf_render_html($product, [])));
er_check('a definition error throws with the errors', static fn() => er_throws(
    static fn() => bbf_render_html(['id' => 'x', 'fields' => [['name' => 'a', 'type' => 'heading']]], $base), 'definition errors'));
er_check('a template that is not callable throws', static fn() => er_throws(
    static fn() => bbf_render_html($product, $base + ['templates' => ['control' => 'nope']])));

$html = bbf_render_html($product, $base);
$x = er_dom($html);
er_check('form posts to the action, escaped', static fn() => er_attr($x, '//form', 'action') === '/admin.php?page=product&id=5'
    && str_contains($html, 'action="/admin.php?page=product&amp;id=5"'));
er_check('a file field makes the form multipart', static fn() => er_attr($x, '//form', 'enctype') === 'multipart/form-data'
    && er_attr($x, '//form', 'method') === 'post');
er_check('no file field, no enctype', static fn() => er_attr(er_dom(bbf_render_html(['id' => 'p', 'fields' => [['name' => 'a', 'type' => 'text']]], ['action' => '/x'])), '//form', 'enctype') === null);
er_check('server render keeps browser validation (no novalidate)', static fn() => er_attr($x, '//form', 'novalidate') === null);
er_check('bbf.js classes: form, title, field wrappers, label, required mark', static fn() =>
    er_attr($x, '//form', 'class') === 'bbf-form' && er_count($x, '//h2[@class="bbf-title"]') === 1
    && er_attr($x, '//div[@data-field="title"]', 'class') === 'bbf-field bbf-field-text bbf-size-medium'
    && er_attr($x, '//label[@for="bbf-title"]', 'class') === 'bbf-label'
    && er_count($x, '//label[@for="bbf-title"]/span[@class="bbf-required"]') === 1);
er_check('input attributes from the definition', static fn() => er_attr($x, '//input[@id="bbf-title"]', 'maxlength') === '20'
    && er_attr($x, '//input[@id="bbf-title"]', 'required') !== null && er_attr($x, '//input[@id="bbf-price"]', 'min') === '0'
    && er_attr($x, '//input[@id="bbf-price"]', 'type') === 'number');
er_check('suffix wraps the input in bbf-input-group', static fn() => er_count($x, '//div[@class="bbf-input-group"]/input[@id="bbf-price"]') === 1
    && er_count($x, '//span[@class="bbf-input-suffix"]') === 1);
er_check('options_resolver fills the select, placeholder selected', static fn() => er_count($x, '//select[@id="bbf-category"]/option') === 3
    && er_attr($x, '//select[@id="bbf-category"]/option[1]', 'selected') !== null
    && er_attr($x, '//select[@id="bbf-category"]/option[1]', 'disabled') !== null);
er_check('checkbox inputs post as name[]', static fn() => er_attr($x, '//input[@id="bbf-on_sale-0"]', 'name') === 'on_sale[]'
    && er_attr($x, '//fieldset[@data-field="on_sale"]', 'class') === 'bbf-fieldset bbf-field bbf-field-checkbox'
    && er_count($x, '//fieldset[@data-field="on_sale"]/legend[@class="bbf-label"]') === 1);
er_check('show_if false hides the field like bbf.js', static fn() => er_attr($x, '//div[@data-field="sale_price"]', 'style') === 'display:none'
    && er_attr($x, '//div[@data-field="sale_price"]', 'data-conditional-hidden') === 'true');
er_check('description is linked by aria-describedby', static fn() => er_attr($x, '//textarea[@id="bbf-note"]', 'aria-describedby') === 'bbf-note-desc bbf-note-error'
    && er_attr($x, '//textarea[@id="bbf-note"]', 'rows') === '3' && er_count($x, '//small[@id="bbf-note-desc"]') === 1);
er_check('file input has a name, accept and required without an existing file', static fn() => er_attr($x, '//input[@id="bbf-image"]', 'name') === 'image'
    && er_attr($x, '//input[@id="bbf-image"]', 'accept') === '.png,.jpg' && er_attr($x, '//input[@id="bbf-image"]', 'required') !== null
    && er_count($x, '//input[@name="image__remove"]') === 0);
er_check('submit label from the definition, empty message area', static fn() => er_count($x, '//div[@class="bbf-field bbf-submit-wrap"]/button[@class="bbf-submit"][.="Uložiť"]') === 1
    && er_attr($x, '//div[contains(@class,"bbf-message")]', 'style') === 'display:none');
er_check('no BBF honeypot or BBF CSRF in the embedded form', static fn() => er_count($x, '//input[@name="_bbf_hp" or @name="_bbf_csrf"]') === 0);

// Editing: values, errors, hidden inputs, existing file.
$edit = bbf_render_html($product, $base + [
    'values' => ['title' => 'Stan "Alfa" <b>', 'price' => '120', 'category' => '9', 'on_sale' => ['yes'], 'sale_price' => '99',
        'note' => "Riadok </textarea><script>x</script>", 'image' => ['url' => '/uploads/p/5.jpg?v=2', 'name' => 'stan.jpg']],
    'errors' => ['title' => 'Názov je príliš dlhý <b>', '_validation_0' => 'Cena musí byť <kladná>'],
    'hidden' => ['csrf_token' => 'tok"en', 'id' => 5],
    'show_title' => false, 'submit_label' => 'Uložiť zmeny',
]);
$e = er_dom($edit);
er_check('values are filled and escaped', static fn() => er_attr($e, '//input[@id="bbf-title"]', 'value') === 'Stan "Alfa" <b>'
    && !str_contains($edit, '<b>"') && !str_contains($edit, '<script>') && str_contains($edit, '&lt;/textarea&gt;'));
er_check('select and checkbox values are selected', static fn() => er_attr($e, '//select[@id="bbf-category"]/option[@value="9"]', 'selected') !== null
    && er_attr($e, '//select[@id="bbf-category"]/option[@value="7"]', 'selected') === null
    && er_attr($e, '//input[@id="bbf-on_sale-0"]', 'checked') !== null);
er_check('show_if true with the current values shows the field', static fn() => er_attr($e, '//div[@data-field="sale_price"]', 'style') === null
    && er_attr($e, '//input[@id="bbf-sale_price"]', 'value') === '99');
er_check('field error: class, message, aria-invalid', static fn() => str_contains((string)er_attr($e, '//div[@data-field="title"]', 'class'), 'bbf-has-error')
    && $e->query('//div[@id="bbf-title-error"]')->item(0)->textContent === 'Názov je príliš dlhý <b>'
    && er_attr($e, '//input[@id="bbf-title"]', 'aria-invalid') === 'true');
er_check('errors without a field show in the message area', static fn() => $e->query('//div[@class="bbf-message bbf-error"]')->item(0)->textContent === 'Cena musí byť <kladná>');
er_check('host hidden inputs are written, escaped', static fn() => er_attr($e, '//input[@name="csrf_token"]', 'value') === 'tok"en'
    && er_attr($e, '//input[@name="id"]', 'value') === '5');
er_check('show_title=false and submit_label override', static fn() => er_count($e, '//h2') === 0 && er_count($e, '//button[.="Uložiť zmeny"]') === 1);
er_check('existing image: preview, link, remove checkbox, input not required', static fn() =>
    er_attr($e, '//div[@class="bbf-file-current"]/a/img', 'src') === '/uploads/p/5.jpg?v=2'
    && er_attr($e, '//div[@class="bbf-file-current"]/a/img', 'alt') === 'stan.jpg'
    && er_attr($e, '//input[@name="image__remove"]', 'type') === 'checkbox' && er_attr($e, '//input[@name="image__remove"]', 'checked') === null
    && er_attr($e, '//input[@id="bbf-image"]', 'required') === null
    && str_contains($e->query('//label[contains(@class,"bbf-file-remove-existing")]')->item(0)->textContent, 'stan.jpg'));
er_check('existing non-image file is a link', static fn() => er_count(er_dom(bbf_render_html($product, $base + ['values' => ['image' => ['url' => 'https://cdn.test/a/manual.pdf', 'name' => 'manual.pdf']]])),
    '//div[@class="bbf-file-current"]/a[@href="https://cdn.test/a/manual.pdf"][.="manual.pdf"]') === 1);
er_check('a javascript: or data: URL is never linked', static function () use ($product, $base): bool {
    foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x'] as $url) {
        $html = bbf_render_html($product, $base + ['values' => ['image' => ['url' => $url, 'name' => 'x.png']]]);
        if (str_contains(strtolower($html), 'script:') || str_contains($html, 'data:') || str_contains($html, 'bbf-file-current')) return false;
    }
    return true;
});
er_check('remove checkbox keeps its posted state after an error', static fn() => er_attr(er_dom(bbf_render_html($product, $base + [
    'values' => ['image' => ['url' => '/u/a.png', 'name' => 'a.png'], 'image__remove' => '1']])), '//input[@name="image__remove"]', 'checked') !== null);
er_check('max_files > 1: name[] and multiple', static function () use ($base): bool {
    $x = er_dom(bbf_render_html(['id' => 'g', 'fields' => [['name' => 'photos', 'type' => 'file', 'max_files' => 3, 'accept' => ['png']]]], $base));
    return er_attr($x, '//input[@id="bbf-photos"]', 'name') === 'photos[]' && er_attr($x, '//input[@id="bbf-photos"]', 'multiple') !== null;
});
er_check('id_prefix changes ids, labels and aria links', static fn() => er_attr(er_dom(bbf_render_html($product, $base + ['id_prefix' => 'shop'])), '//label[@for="shop-title"]', 'class') === 'bbf-label');
er_check('an unsafe id_prefix throws', static fn() => er_throws(static fn() => bbf_render_html($product, $base + ['id_prefix' => '"><x'])));

// Unavailable source: static options stay the fallback; none means an empty select (bbf_validate refuses any value).
er_check('a failed options source renders no options', static function () use ($product): bool {
    $x = er_dom(bbf_render_html($product, ['action' => '/x', 'options_resolver' => static fn() => null]));
    return er_count($x, '//select[@id="bbf-category"]/option') === 1;
});

// Select/radio/checkbox details matching bbf.js.
$choice = ['id' => 'c', 'fields' => [
    ['name' => 'color', 'type' => 'select', 'label' => 'Farba', 'options' => ['red', ['value' => 'blue', 'label' => 'Modrá', 'show_if' => ['field' => 'size', 'value' => 'L']]], 'other' => true],
    ['name' => 'size', 'type' => 'radio', 'label' => 'Veľkosť', 'options' => ['S', 'L'], 'columns' => 2, 'value' => 'L'],
    ['name' => 'extras', 'type' => 'checkbox', 'label' => 'Doplnky', 'options' => ['a', ['value' => 'b', 'label' => 'B', 'checked' => true]], 'other' => true],
    ['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'confirm' => true, 'required' => true],
    ['name' => 'ref', 'type' => 'hidden', 'value' => 'web'],
    ['name' => 'more', 'type' => 'section', 'title' => 'Ďalšie', 'description' => 'Popis'],
    ['name' => 'grp', 'type' => 'group', 'title' => 'Skupina', 'show_if' => ['field' => 'size', 'value' => 'S'], 'fields' => [
        ['name' => 'inner', 'type' => 'text', 'label' => 'Vnútri'],
    ]],
]];
$c = er_dom(bbf_render_html($choice, ['action' => '/x', 'lang' => 'sk']));
er_check('defaults: radio value and option checked', static fn() => er_attr($c, '//input[@id="bbf-size-1"]', 'checked') !== null
    && er_attr($c, '//input[@id="bbf-extras-1"]', 'checked') !== null && er_attr($c, '//input[@id="bbf-extras-0"]', 'checked') === null);
er_check('radio group layout and role', static fn() => er_attr($c, '//fieldset[@data-field="size"]/div', 'class') === 'bbf-options bbf-columns-2'
    && er_attr($c, '//fieldset[@data-field="size"]/div', 'role') === 'group');
er_check('option show_if goes to data-option-show-if', static fn() => json_decode((string)er_attr($c, '//option[@value="blue"]', 'data-option-show-if'), true) === ['field' => 'size', 'value' => 'L']);
er_check('"other" option and its hidden text input (localized)', static fn() => er_count($c, '//option[@value="__other__"]') === 1
    && er_attr($c, '//input[@name="color_other"]', 'style') === 'display:none;margin-top:6px'
    && er_attr($c, '//input[@id="bbf-extras-other"]', 'name') === 'extras[]');
er_check('email confirm input', static fn() => er_attr($c, '//input[@id="bbf-email-confirm"]', 'name') === 'email_confirm'
    && er_attr($c, '//input[@id="bbf-email-confirm"]', 'required') !== null);
er_check('hidden field wrapper and default value', static fn() => er_attr($c, '//div[@data-field="ref"]', 'style') === 'display:none'
    && er_attr($c, '//input[@name="ref"]', 'value') === 'web');
er_check('section and group markup; group hidden by its show_if', static fn() => er_count($c, '//div[@class="bbf-field bbf-section"][@data-field="more"]/h3[@class="bbf-section-title"]') === 1
    && er_count($c, '//p[@class="bbf-section-desc"]') === 1
    && er_attr($c, '//div[@data-field="grp"]', 'style') === 'display:none' && er_count($c, '//div[@data-field="grp"]/div[@data-field="inner"]') === 1);
er_check('a stored value outside the options selects "other" with the text', static function () use ($choice): bool {
    $x = er_dom(bbf_render_html($choice, ['action' => '/x', 'values' => ['color' => 'zelená', 'extras' => ['a', 'vlastné']]]));
    return er_attr($x, '//option[@value="__other__"]', 'selected') !== null && er_attr($x, '//input[@name="color_other"]', 'value') === 'zelená'
        && er_attr($x, '//input[@name="color_other"]', 'style') === 'margin-top:6px'
        && er_attr($x, '//input[@id="bbf-extras-other"]', 'checked') !== null && er_attr($x, '//input[@name="extras_other"]', 'value') === 'vlastné';
});
er_check('a value matching no option (no other) leaves the select empty like bbf.js', static fn() =>
    er_attr(er_dom(bbf_render_html(['id' => 's', 'fields' => [['name' => 's', 'type' => 'select', 'options' => ['a', 'b']]]], ['action' => '/x', 'values' => ['s' => 'zzz']])),
        '//select/option[1]', 'hidden') !== null);
er_check('language: sk "other" label, en fallback, messages override', static function () use ($choice): bool {
    $sk = bbf_render_html($choice, ['action' => '/x', 'lang' => 'sk']);
    $xx = bbf_render_html($choice, ['action' => '/x', 'lang' => 'xx']);
    $ov = bbf_render_html($choice, ['action' => '/x', 'lang' => 'sk', 'messages' => ['optionOther' => 'Iné (napíšte)', 'submitDefault' => 'Poslať']]);
    return str_contains($sk, '>' . bbf_messages('sk')['optionOther'] . '<') && str_contains($xx, '>' . bbf_messages('en')['submitDefault'] . '<')
        && str_contains($ov, 'Iné (napíšte)') && str_contains($ov, '>Poslať</button>');
});
er_check('messages do not leak out of bbf_render_html', static fn() => bbf_t('submitDefault') === bbf_messages('en')['submitDefault']);

// Templates (R4) and custom types (R5).
$seen = [];
$templated = bbf_render_html($product, $base + ['values' => ['title' => 'T'], 'errors' => ['price' => 'Zlá cena'], 'templates' => [
    'control' => static function (array $field, $value, array $ctx) use (&$seen): ?string {
        $seen[$field['name']] = $ctx;
        return $field['name'] === 'title' ? '<input class="my-input" name="' . bbf_e($ctx['name']) . '" value="' . bbf_e($value) . '">' : null;
    },
    'field' => static fn(array $field, string $control, array $ctx): ?string =>
        $field['name'] === 'price' ? '<div class="form-row' . ($ctx['error'] ? ' err' : '') . '">' . $control . '</div>' : null,
]]);
$t = er_dom($templated);
er_check('templates.control replaces one control, null keeps BBF', static fn() => er_attr($t, '//input[@class="my-input"]', 'value') === 'T'
    && er_count($t, '//div[@data-field="title"]/label[@for="bbf-title"]') === 1 && er_count($t, '//textarea[@id="bbf-note"]') === 1);
er_check('control ctx: id, name, value, error, attrs, hidden', static fn() => $seen['title']['id'] === 'bbf-title' && $seen['on_sale']['name'] === 'on_sale[]'
    && $seen['price']['error'] === 'Zlá cena' && $seen['title']['attrs']['maxlength'] === 20 && $seen['title']['attrs']['required'] === true
    && $seen['sale_price']['hidden'] === true && $seen['title']['hidden'] === false && $seen['title']['value'] === 'T');
er_check('templates.field replaces the wrapper and gets the control HTML', static fn() => er_count($t, '//div[@class="form-row err"]/div[@class="bbf-input-group"]/input[@id="bbf-price"]') === 1
    && er_count($t, '//div[@data-field="price"]') === 0);
er_check('a field matched by a template error is not repeated as a form error', static fn() => er_count($t, '//div[@class="bbf-message bbf-error"]') === 0);

$tree = ['id' => 'cat', 'fields' => [['name' => 'parent', 'type' => 'x-category-tree', 'label' => 'Nadradená', 'required' => true]]];
er_check('an x- type without a render handler throws', static function () use ($tree): bool {
    bbf_register_type('x-category-tree', ['validate' => static fn($v) => null]);
    return er_throws(static fn() => bbf_render_html($tree, ['action' => '/x']), 'render handler');
});
er_check('an x- type render handler draws the control inside the BBF wrapper', static function () use ($tree): bool {
    bbf_register_type('x-category-tree', ['render' => static fn(array $f, $v, array $ctx): string =>
        '<select class="tree" name="' . bbf_e($ctx['name']) . '" id="' . bbf_e($ctx['id']) . '"><option' . ($v === '3' ? ' selected' : '') . ' value="3">Stany</option></select>']);
    $x = er_dom(bbf_render_html($tree, ['action' => '/x', 'values' => ['parent' => '3']]));
    return er_attr($x, '//div[@data-field="parent"]', 'class') === 'bbf-field bbf-field-x-category-tree'
        && er_attr($x, '//select[@class="tree"]/option', 'selected') !== null && er_count($x, '//label[@for="bbf-parent"]') === 1;
});
er_check('templates.control wins over the type render handler', static fn() => str_contains(bbf_render_html($tree, ['action' => '/x',
    'templates' => ['control' => static fn() => '<i>host</i>']]), '<i>host</i>'));
er_check('unsupported types throw unless templates.control draws them', static function (): bool {
    foreach ([['name' => 'r', 'type' => 'rating'], ['name' => 'rows', 'type' => 'group', 'repeatable' => true, 'fields' => [['name' => 'a', 'type' => 'text']]]] as $field) {
        $form = ['id' => 'u', 'fields' => [$field]];
        if (!er_throws(static fn() => bbf_render_html($form, ['action' => '/x']), 'cannot draw') && !er_throws(static fn() => bbf_render_html($form, ['action' => '/x']), 'repeatable')) return false;
        if (!str_contains(bbf_render_html($form, ['action' => '/x', 'templates' => ['control' => static fn() => '<b>ok</b>']]), '<b>ok</b>')) return false;
    }
    return er_throws(static fn() => bbf_render_html(['id' => 'p', 'fields' => [['name' => 'a', 'type' => 'text'], ['name' => 'p2', 'type' => 'page_break'], ['name' => 'b', 'type' => 'text']]], ['action' => '/x']), 'page_break');
});
er_check('a control template returning a non-string throws', static fn() => er_throws(static fn() => bbf_render_html($tree, ['action' => '/x',
    'templates' => ['control' => static fn() => 42]])));

// Round trip: what the rendered form posts is what bbf_validate() accepts.
er_check('rendered names round-trip through bbf_validate()', static function () use ($product, $categories): bool {
    $x = er_dom(bbf_render_html($product, ['action' => '/x', 'options_resolver' => $categories, 'values' => ['image' => ['url' => '/u/a.png', 'name' => 'a.png']]]));
    $names = [];
    foreach ($x->query('//form//*[@name]') as $node) $names[$node->getAttribute('name')] = true;
    $expected = ['title', 'price', 'category', 'on_sale[]', 'sale_price', 'note', 'image', 'image__remove'];
    if (array_keys($names) !== $expected) return false;
    $result = bbf_validate($product, ['title' => 'Stan', 'price' => '10', 'category' => '7', 'on_sale' => ['yes'], 'sale_price' => '8', 'note' => ''],
        [], ['options_resolver' => $categories, 'values' => ['image' => ['url' => '/u/a.png']]]);
    return $result['errors'] === [] && $result['data']['image']['action'] === 'keep' && $result['data']['on_sale'] === ['yes'];
});

print "Embedded render: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
