'use strict';
// 2.2.0: bbf_render_html() (PHP) builds the same field DOM as bbf.js _buildField, so bbf.css and BBF.enhance fit both.

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { loadBBF } = require('./review-renderer.test.js');

const FIELDS = [
    { name: 'title', type: 'text', label: 'Title', required: true, maxlength: 20, size: 'medium', description: 'Shown in lists', css_class: 'wide' },
    { name: 'price', type: 'number', label: 'Price', min: 0, prefix: '€', suffix: 'EUR', label_position: 'left' },
    { name: 'mail', type: 'email', label: 'E-mail', confirm: true, required: true },
    { name: 'phone', type: 'tel', label: 'Phone', placeholder: '+421' },
    { name: 'web', type: 'url', label: 'Web' },
    { name: 'born', type: 'date', label: 'Born' },
    { name: 'secret', type: 'password', label: 'Password', size: 'small' },
    { name: 'note', type: 'textarea', label: 'Note', rows: 3 },
    { name: 'status', type: 'select', label: 'Status', placeholder: 'Choose', options: ['draft', { value: 'live', label: 'Live', show_if: { field: 'web', op: 'not_empty' } }], other: true },
    { name: 'plain', type: 'select', label: 'Plain', options: ['a', 'b'] },
    { name: 'size', type: 'radio', label: 'Size', required: true, options: ['S', { value: 'L', label: 'Large' }], columns: 2, description: 'Pick one' },
    { name: 'extras', type: 'checkbox', label: 'Extras', options: ['a', 'b'], other: true, other_label: 'Something else', columns: 'inline' },
    { name: 'ref', type: 'hidden', value: 'web' },
    { name: 'doc', type: 'file', label: 'Document', accept: ['pdf', 'png'], description: 'PDF or PNG', css_class: 'upload' },
    { name: 'photos', type: 'file', label: 'Photos', max_files: 3, accept: ['jpg'] },
    { name: 'intro', type: 'section', title: 'More', description: 'Details below' },
    { name: 'box', type: 'group', title: 'Box', description: 'Grouped', fields: [{ name: 'inner', type: 'text', label: 'Inner' }] },
];

const KEPT_ATTRIBUTES = ['for', 'role', 'data-field', 'aria-describedby', 'aria-live', 'data-option-show-if'];

/** Tag, classes, id, name, type, kept attributes, own text and children: what bbf.css and bbf.js selectors rely on. */
function skeleton(node) {
    const tag = node.tag.toLowerCase();
    const out = { tag, cls: [...node.cls].filter(Boolean).sort().join(' ') };
    if (node.id) out.id = node.id;
    // The server form posts files and checkbox lists natively (name / name[]); bbf.js uploads files by token.
    if (node.name && !(tag === 'input' && node.type === 'file')) out.name = node.name.replace(/\[\]$/, '');
    if (tag === 'input' && node.type) out.type = node.type;
    for (const attr of KEPT_ATTRIBUTES) if (node.attrs[attr] !== undefined) out[attr] = attr === 'data-option-show-if' ? JSON.parse(node.attrs[attr]) : node.attrs[attr];
    if (node.text) out.text = node.text;
    if (node.children.length) out.children = node.children.map(skeleton);
    return out;
}

function fromMini(el) {
    return {
        tag: el.tagName, cls: el.className.split(/\s+/), id: el.id, name: el.name, type: el.type,
        attrs: el.attributes, text: el.textContent, children: el.children.map(fromMini),
    };
}

const PHP_SKELETON = `
require ${JSON.stringify(path.join(__dirname, '..', 'bbf_render.php'))};
$fields = json_decode(stream_get_contents(STDIN), true);
$html = bbf_render_html(['id' => 'parity', 'fields' => $fields], ['action' => '/save', 'lang' => 'en']);
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
$walk = function (DOMElement $el) use (&$walk): array {
    $text = ''; $children = []; $attrs = [];
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement) $children[] = $walk($child);
        elseif ($child instanceof DOMText) $text .= $child->data;
    }
    foreach ($el->attributes as $a) $attrs[$a->name] = $a->value;
    return ['tag' => $el->tagName, 'cls' => preg_split('/\\s+/', $attrs['class'] ?? ''), 'id' => $attrs['id'] ?? '',
        'name' => $attrs['name'] ?? '', 'type' => $attrs['type'] ?? '', 'attrs' => (object)$attrs, 'text' => $text, 'children' => $children];
};
$out = [];
foreach ((new DOMXPath($doc))->query('//form/*[@data-field]') as $el) $out[$el->getAttribute('data-field')] = $walk($el);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
`;

test('bbf_render_html field DOM matches bbf.js _buildField', () => {
    const php = spawnSync(process.env.PHP_BINARY || 'php', ['-r', PHP_SKELETON], { input: JSON.stringify(FIELDS), encoding: 'utf8' });
    assert.equal(php.status, 0, php.stderr || php.stdout);
    const server = JSON.parse(php.stdout);
    const { BBF } = loadBBF();
    for (const field of FIELDS) {
        const client = skeleton(fromMini(BBF._buildField(JSON.parse(JSON.stringify(field)), 'en', 'bbf')));
        assert.ok(server[field.name], `server rendered ${field.name}`);
        const rendered = skeleton(server[field.name]);
        if (field.name === 'plain') {
            // Deliberate difference: the server render gives an optional select an empty "—" choice (host admin forms);
            // bbf.js keeps its standalone behaviour so the live sites' forms do not change.
            const select = rendered.children.find(c => c.tag === 'select');
            assert.deepEqual(select.children.shift(), { tag: 'option', cls: '', text: '—' });
        }
        assert.deepEqual(rendered, client, `DOM of ${field.type} field "${field.name}"`);
    }
});
