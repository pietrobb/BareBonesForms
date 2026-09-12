'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

class MiniElement {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parentElement = null;
        this.style = {};
        this.attributes = {};
        this.listeners = {};
        this.value = '';
        this.checked = false;
        this.selected = false;
        this.disabled = false;
        this.hidden = false;
        this.textContent = '';
        this._classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => this._classes.add(name)),
            remove: (...names) => names.forEach(name => this._classes.delete(name)),
            contains: name => this._classes.has(name),
            toggle: (name, force) => {
                const enabled = force === undefined ? !this._classes.has(name) : force;
                if (enabled) this._classes.add(name); else this._classes.delete(name);
            },
        };
    }
    set className(value) { this._classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
    get className() { return [...this._classes].join(' '); }
    set innerHTML(value) { this._innerHTML = String(value); if (value === '') this.children = []; }
    get innerHTML() { return this._innerHTML || ''; }
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; }
    removeChild(child) {
        const index = this.children.indexOf(child);
        if (index >= 0) this.children.splice(index, 1);
        child.parentElement = null;
        return child;
    }
    remove() { if (this.parentElement) this.parentElement.removeChild(this); }
    insertBefore(child, before) {
        child.parentElement = this;
        const index = this.children.indexOf(before);
        if (index < 0) this.children.push(child); else this.children.splice(index, 0, child);
        return child;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'name') this.name = String(value); }
    getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] || []).filter(fn => fn !== listener); }
    dispatchEvent(event) {
        if (!event.target) Object.defineProperty(event, 'target', { value: this, configurable: true });
        for (const listener of [...(this.listeners[event.type] || [])]) listener(event);
        return true;
    }
    focus() { this.focused = true; }
    scrollIntoView() { this.scrolled = true; }
    matches(selector) {
        selector = selector.trim();
        if (selector.includes(',')) return selector.split(',').some(part => this.matches(part));
        const checked = selector.endsWith(':checked');
        if (checked) selector = selector.slice(0, -8);
        if (checked && !this.checked) return false;
        const tag = selector.match(/^[a-z]+/i)?.[0];
        if (tag && this.tagName !== tag.toUpperCase()) return false;
        const cls = selector.match(/\.([\w-]+)/)?.[1];
        if (cls && !this.classList.contains(cls)) return false;
        const attr = selector.match(/\[([\w-]+)(?:="([^"]*)")?\]/);
        if (attr) {
            const actual = attr[1] === 'name' ? this.name : this.getAttribute(attr[1]);
            if (actual === undefined || actual === null) return false;
            if (attr[2] !== undefined && String(actual) !== attr[2]) return false;
        }
        return Boolean(tag || cls || attr);
    }
    querySelectorAll(selector) {
        const result = [];
        const visit = node => {
            for (const child of node.children) {
                if (child.matches(selector)) result.push(child);
                visit(child);
            }
        };
        visit(this);
        return result;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node; return null; }
}

function loadBBF(overrides = {}) {
    const document = {
        readyState: 'complete',
        head: new MiniElement('head'),
        getElementsByTagName(tag) { return tag === 'script' ? [{ src: 'https://example.test/bbf.js' }] : []; },
        querySelectorAll() { return []; },
        querySelector() { return null; },
        createElement(tag) { return new MiniElement(tag); },
        addEventListener() {},
    };
    class TestEvent {
        constructor(type, options = {}) { this.type = type; Object.assign(this, options); }
        preventDefault() { this.defaultPrevented = true; }
    }
    const context = vm.createContext({
        document,
        window: {},
        location: { href: 'https://example.test/demo.html', origin: 'https://example.test', search: '' },
        URL,
        URLSearchParams,
        Event: TestEvent,
        MouseEvent: TestEvent,
        FormData: overrides.FormData || class { forEach() {} },
        fetch: overrides.fetch || (async () => { throw new Error('Unexpected fetch'); }),
        requestAnimationFrame: overrides.requestAnimationFrame || (fn => { fn(); return 1; }),
        setTimeout: overrides.setTimeout || setTimeout,
        clearTimeout: overrides.clearTimeout || clearTimeout,
        console,
    });
    const source = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8');
    vm.runInContext(source, context, { filename: 'bbf.js' });
    return { BBF: context.window.BBF, document, context };
}

function deferred() {
    let resolve;
    const promise = new Promise(res => { resolve = res; });
    return { promise, resolve };
}

async function settle() {
    for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
}

test('latest render request owns its container when an older request fails late', async () => {
    const requests = new Map();
    const { BBF } = loadBBF({ fetch: url => {
        const gate = deferred(); requests.set(url, gate); return gate.promise;
    } });
    const container = new MiniElement('div');
    const oldRender = BBF.render('old', container, { baseUrl: 'https://api.test/' });
    const newRender = BBF.render('new', container, { baseUrl: 'https://api.test/' });
    requests.get('https://api.test/submit.php?form=new&action=definition').resolve({
        ok: true, json: async () => ({ id: 'new', name: 'Newest', fields: [] }),
    });
    await newRender;
    requests.get('https://api.test/submit.php?form=old&action=definition').resolve({
        ok: false, status: 500, json: async () => ({}),
    });
    await oldRender;
    assert.equal(container.querySelector('[data-form-id="new"]')?.getAttribute('data-form-id'), 'new');
    assert.equal(container.innerHTML.includes('bbf-error'), false);
});

test('sandbox submit serializes repeatable rows with the production collector', async () => {
    const { BBF } = loadBBF();
    const definition = { fields: [{
        name: 'items', type: 'group', repeatable: true, min_items: 1, max_items: 1,
        fields: [{ name: 'sku', type: 'text' }],
    }] };
    const form = BBF._buildForm(definition, 'orders', 'https://example.test/', {}, null, null, true);
    form.querySelector('[name="items__1__sku"]').value = 'A-1';
    let submitted = null;
    class SandboxFormData {
        forEach(callback) { callback('A-1', 'items__1__sku'); callback('trap', '_bbf_hp'); }
    }
    const sandboxSource = fs.readFileSync(path.join(__dirname, '..', 'sandbox.php'), 'utf8');
    const sandboxSubmit = sandboxSource.match(/    async function sandboxSubmit\(formEl, formId\) \{[\s\S]*?\r?\n    \}(?=\r?\n\r?\n    function showResults)/)?.[0];
    assert.ok(sandboxSubmit, 'extract actual sandbox submit function');
    const runtime = vm.createContext({
        BBF, FormData: SandboxFormData, currentFormDef: definition, sandboxCsrf: 'csrf',
        fetch: async (url, options) => {
            submitted = JSON.parse(options.body);
            return { json: async () => ({ status: 'ok' }) };
        },
        showResults() {}, showError() {},
    });
    vm.runInContext(sandboxSubmit + '\nthis.runSandboxSubmit = sandboxSubmit;', runtime);
    await runtime.runSandboxSubmit(form, 'orders');
    assert.deepEqual(submitted, { items: [{ sku: 'A-1' }] });
});

test('sandbox latest form load wins when the older definition resolves last', async () => {
    const requests = new Map();
    const requestFetch = url => { const gate = deferred(); requests.set(url, gate); return gate.promise; };
    const { BBF } = loadBBF({ fetch: requestFetch });
    const elements = new Map(['form-container', 'results', 'result-placeholder', 'form-json', 'form-meta']
        .map(id => [id, new MiniElement('div')]));
    const document = { getElementById: id => elements.get(id) };
    const sandboxSource = fs.readFileSync(path.join(__dirname, '..', 'sandbox.php'), 'utf8');
    const loadForm = sandboxSource.match(/    window\.loadForm = async function\(formId\) \{[\s\S]*?\n    \};/)?.[0];
    assert.ok(loadForm, 'extract actual sandbox load function');
    const runtime = vm.createContext({
        BBF, document, window: {}, sandboxSubmit() {}, encodeURIComponent,
        fetch: requestFetch,
    });
    vm.runInContext("let currentFormId = ''; let currentFormDef = null; let loadRequest = 0;\n"
        + loadForm + '\nthis.loadForm = window.loadForm; this.currentDefinition = () => currentFormDef;', runtime);
    const oldLoad = runtime.loadForm('old');
    const newLoad = runtime.loadForm('new');
    requests.get('sandbox.php?action=definition&form=new').resolve({
        json: async () => ({ id: 'new', name: 'Newest', fields: [
            { name: 'choice', type: 'select', options_from: '/new-options' },
        ] }),
    });
    await settle();
    requests.get('/new-options').resolve({ ok: true, json: async () => ['Fresh option'] });
    await newLoad;
    requests.get('sandbox.php?action=definition&form=old').resolve({
        json: async () => ({ id: 'old', name: 'Stale', fields: [] }),
    });
    await oldLoad;
    assert.equal(runtime.currentDefinition().id, 'new');
    assert.equal(elements.get('form-container').querySelector('[data-form-id="new"]')?.getAttribute('data-form-id'), 'new');
    assert.equal(elements.get('form-container').querySelector('option')?.textContent, 'Fresh option',
        '6129-F10 sandbox preview awaits options_from before rendering');
    assert.match(elements.get('form-json').textContent, /"id": "new"/);
});

test('sandbox payment preview consumes amount_minor and currency exponent contract', () => {
    const { BBF } = loadBBF();
    const base = { validation: { passed: true }, on_submit_preview: { payment: {
        provider: 'stripe', amount_minor: 1000, minor_units: 0, currency: 'JPY', product_name: 'Order',
    } } };
    assert.match(BBF._renderSandboxPreview(base), /1000 JPY/);
    base.on_submit_preview.payment = { provider: 'stripe', amount_minor: 1250, minor_units: 3, currency: 'KWD', product_name: 'Order' };
    assert.match(BBF._renderSandboxPreview(base), /1\.250 KWD/);
});

test('option-only show_if binds and cleared choices immediately hide dependents', () => {
    const { BBF } = loadBBF();
    const form = new MiniElement('form');
    form.className = 'bbf-form';
    const source = form.appendChild(new MiniElement('input'));
    source.name = 'gate'; source.setAttribute('name', 'gate'); source.value = 'yes';
    const option = form.appendChild(new MiniElement('label'));
    option.setAttribute('data-option-show-if', JSON.stringify({ field: 'gate', value: 'yes' }));
    const checkbox = option.appendChild(new MiniElement('input'));
    checkbox.type = 'checkbox'; checkbox.name = 'choice'; checkbox.setAttribute('name', 'choice'); checkbox.value = 'a'; checkbox.checked = true;
    const dependent = form.appendChild(new MiniElement('div'));
    dependent.setAttribute('data-field', 'result');
    const fields = [
        { name: 'choice', options: [{ value: 'a', label: 'A', show_if: { field: 'gate', value: 'yes' } }] },
        { name: 'result', show_if: { field: 'choice', value: 'a' } },
    ];

    BBF._bindConditions(form, fields, false);
    assert.equal(option.style.display, '');
    assert.equal(dependent.style.display, '');
    source.value = 'no';
    source.dispatchEvent(new Event('input'));
    assert.equal(option.style.display, 'none');
    assert.equal(checkbox.checked, false);
    assert.equal(dependent.style.display, 'none');
});

test('hidden empty select option is deselected without an unbounded fixed-point loop', () => {
    const { BBF } = loadBBF();
    const form = new MiniElement('form'); form.className = 'bbf-form';
    const gate = form.appendChild(new MiniElement('input'));
    gate.name = 'gate'; gate.setAttribute('name', 'gate'); gate.value = 'closed';
    const select = form.appendChild(new MiniElement('select'));
    const option = select.appendChild(new MiniElement('option'));
    option.value = ''; option.selected = true;
    option.setAttribute('data-option-show-if', JSON.stringify({ field: 'gate', value: 'open' }));
    let selectedIndex = 0;
    Object.defineProperty(select, 'selectedIndex', {
        get: () => selectedIndex,
        set: value => { selectedIndex = value; if (value === -1) option.selected = false; },
    });

    BBF._stabilizeOptionConditions(form);
    assert.equal(option.selected, false);
    assert.equal(select.selectedIndex, -1);
});

test('option-only show_if inside nested templates follows the fully prefixed sibling', () => {
    const { BBF } = loadBBF();
    const resolved = BBF._resolveTemplates([
        { name: 'billing', type: 'group', use: 'address', prefix: 'billing_' },
    ], {
        address: [
            { name: 'region_group', type: 'group', use: 'region', prefix: 'detail_' },
        ],
        region: [
            { name: 'country', type: 'text' },
            { name: 'choice', type: 'checkbox', options: [{ value: 'eu', show_if: { field: 'country', value: 'EU' } }] },
        ],
    });
    const nestedFields = resolved[0].fields[0].fields;
    const optionCondition = nestedFields[1].options[0].show_if;
    assert.equal(nestedFields[0].name, 'billing_detail_country');
    assert.equal(optionCondition.field, 'billing_detail_country');

    const form = new MiniElement('form'); form.className = 'bbf-form';
    const source = form.appendChild(new MiniElement('input'));
    source.name = 'billing_detail_country'; source.setAttribute('name', 'billing_detail_country'); source.value = 'US';
    const option = form.appendChild(new MiniElement('label'));
    option.setAttribute('data-option-show-if', JSON.stringify(optionCondition));
    option.appendChild(new MiniElement('input'));
    BBF._bindConditions(form, BBF._flattenFields(resolved), false);
    assert.equal(option.style.display, 'none');
    source.value = 'EU'; source.dispatchEvent(new Event('change'));
    assert.equal(option.style.display, '');
});

test('rating updates dispatch input and change for dependent conditions', () => {
    const { BBF, context } = loadBBF();
    const wrap = BBF._buildRating({ name: 'score', label: 'Score', max: 5 });
    const hidden = wrap.querySelector('[name="score"]');
    let inputEvents = 0; let changeEvents = 0;
    hidden.addEventListener('input', () => inputEvents++);
    hidden.addEventListener('change', () => changeEvents++);
    const stars = wrap.querySelector('.bbf-rating-stars');
    const third = stars.querySelectorAll('.bbf-star')[2];
    stars.dispatchEvent(Object.assign(new context.Event('click'), { target: third }));
    assert.equal(hidden.value, '3');
    assert.equal(inputEvents, 1);
    assert.equal(changeEvents, 1);
});

test('draft restore applies all values before conditions and synchronizes rating accessibility', () => {
    const { BBF } = loadBBF();
    const fields = [
        { name: 'gate', type: 'text' },
        { name: 'choice', type: 'checkbox', options: [
            { value: 'kept', label: 'Kept', show_if: { field: 'gate', value: 'yes' } },
        ] },
        { name: 'optional', type: 'radio', options: [{ value: 'undefined', label: 'Undefined literal' }] },
        { name: 'score', type: 'rating', max: 3 },
    ];
    const form = BBF._buildForm({ fields }, 'draft-atomic', 'https://example.test/', {}, null, null, true);
    const optional = form.querySelector('[name="optional"]'); optional.checked = true;
    BBF._draftApply(form, fields, { choice: ['kept'], gate: 'yes', score: '5' },
        ['gate', 'choice', 'optional', 'score']);
    const choice = form.querySelector('[name="choice"]');
    const stars = form.querySelectorAll('.bbf-star');
    assert.equal(choice.checked, true);
    assert.equal(choice.parentElement.style.display, '');
    assert.equal(optional.checked, false);
    assert.equal(form.querySelector('[name="score"]').value, '3');
    assert.equal(stars[2].getAttribute('aria-checked'), 'true');
    assert.equal(stars[2].getAttribute('tabindex'), '0');
    assert.equal(stars.filter(star => star.classList.contains('bbf-star-active')).length, 3);
});

test('rating validation reveals its page and focuses an operable star', () => {
    const { BBF } = loadBBF();
    const form = new MiniElement('form');
    const page1 = form.appendChild(new MiniElement('div')); page1.className = 'bbf-page';
    const page2 = form.appendChild(new MiniElement('div')); page2.className = 'bbf-page'; page2.style.display = 'none';
    const rating = page2.appendChild(BBF._buildRating({ name: 'score', label: 'Score', required: true }));
    const nav = form.appendChild(new MiniElement('div')); nav.className = 'bbf-page-nav';
    for (const cls of ['bbf-prev', 'bbf-next', 'bbf-submit', 'bbf-page-indicator']) {
        const el = nav.appendChild(new MiniElement(cls === 'bbf-page-indicator' ? 'span' : 'button')); el.className = cls;
    }
    form._bbfPageState = { currentPage: { value: 0 }, totalPages: 2, langCode: null };

    BBF._showErrors(form, { score: 'Required' });
    const group = rating.querySelector('.bbf-rating-stars');
    const star = rating.querySelector('.bbf-star[tabindex="0"]');
    assert.equal(form._bbfPageState.currentPage.value, 1);
    assert.equal(group.getAttribute('aria-invalid'), 'true');
    assert.match(group.getAttribute('aria-describedby'), /bbf-score-error/);
    assert.equal(star.focused, true);
});

test('stale hide animation callback cannot hide a newer show', () => {
    const raf = []; const timers = [];
    const { BBF } = loadBBF({ requestAnimationFrame: fn => { raf.push(fn); }, setTimeout: fn => { timers.push(fn); } });
    const wrap = new MiniElement('div'); wrap.scrollHeight = 100; wrap.setAttribute('data-conditional-hidden', '');
    const form = { querySelector: () => wrap };
    let visible = false;
    BBF._evalCondition = () => visible;
    const fields = [{ name: 'dependent', show_if: { field: 'gate', value: 'yes' } }];
    BBF._applyConditions(form, fields, true);
    raf.shift()();
    visible = true;
    BBF._applyConditions(form, fields, true);
    raf.shift()();
    timers.forEach(fn => fn());
    assert.equal(wrap.style.display, '');
    assert.notEqual(wrap.getAttribute('data-conditional-hidden'), 'true');
});

test('latest lookup query wins even when the older response resolves last', async () => {
    const requests = new Map();
    const { BBF, context } = loadBBF({ fetch: url => {
        const gate = deferred(); requests.set(url, gate); return gate.promise;
    } });
    const form = new MiniElement('form'); form.className = 'bbf-form';
    const target = form.appendChild(new MiniElement('input')); target.name = 'company';
    const wrap = BBF._buildField({ name: 'code', type: 'text', lookup: {
        url: '/lookup?q={{value}}', trigger: 1, map: { company: 'name' },
    } });
    form.appendChild(wrap);
    const input = wrap.querySelector('[name="code"]');
    input.value = 'old'; input.dispatchEvent(new context.Event('input'));
    input.value = 'new'; input.dispatchEvent(new context.Event('input'));
    requests.get('/lookup?q=new').resolve({ ok: true, json: async () => ({ name: 'Newest' }) });
    await settle();
    requests.get('/lookup?q=old').resolve({ ok: true, json: async () => ({ name: 'Stale' }) });
    await settle();
    assert.equal(target.value, 'Newest');
});

test('6129-F08 repeatable lookup and autocomplete mappings stay inside their originating row', async () => {
    const requests = new Map();
    const timers = [];
    const { BBF, context } = loadBBF({
        fetch: url => { const gate = deferred(); requests.set(url, gate); return gate.promise; },
        setTimeout: fn => { timers.push(fn); return fn; },
        clearTimeout: fn => { const index = timers.indexOf(fn); if (index >= 0) timers.splice(index, 1); },
    });
    const form = new MiniElement('form'); form.className = 'bbf-form';
    const outerCity = form.appendChild(new MiniElement('input')); outerCity.name = 'city'; outerCity.setAttribute('name', 'city');
    const outerPrice = form.appendChild(new MiniElement('input')); outerPrice.name = 'price'; outerPrice.setAttribute('name', 'price');
    const group = form.appendChild(BBF._buildField({
        name: 'items', type: 'group', repeatable: true, min_items: 1, max_items: 2,
        fields: [
            { name: 'postal', type: 'text', lookup: { url: '/postal?q={{value}}', trigger: 1, map: { city: 'city', tier: 'tier', tags: 'tags' } } },
            { name: 'city', type: 'text' },
            { name: 'tier', type: 'radio', options: ['basic', 'pro'] },
            { name: 'tags', type: 'checkbox', options: ['red', 'blue'] },
            { name: 'product', type: 'text', autocomplete_from: { url: '/products?q={{value}}', min_length: 1, debounce: 0, map: { price: 'price', tier: 'tier', tags: 'tags' } } },
            { name: 'price', type: 'text' },
        ],
    }));
    group._bbfBindRows();
    const row = group.querySelector('.bbf-repeatable-row');
    const sibling = group._bbfAddRow(false);
    sibling.querySelector('[name="items__2__city"]').value = 'Sibling city';
    sibling.querySelector('[name="items__2__price"]').value = '17.00';
    sibling.querySelectorAll('[name="items__2__tier"]')[0].checked = true;
    sibling.querySelectorAll('[name="items__2__tags"]')[0].checked = true;
    const siblingState = () => JSON.stringify({
        city: sibling.querySelector('[name="items__2__city"]').value,
        price: sibling.querySelector('[name="items__2__price"]').value,
        tier: sibling.querySelectorAll('[name="items__2__tier"]').map(option => [option.value, option.checked]),
        tags: sibling.querySelectorAll('[name="items__2__tags"]').map(option => [option.value, option.checked]),
    });
    const untouchedSibling = siblingState();
    const postal = row.querySelector('[name="items__1__postal"]');
    postal.value = '100'; postal.dispatchEvent(new context.Event('input'));
    requests.get('/postal?q=100').resolve({ ok: true, json: async () => ({ city: 'Row city', tier: 'pro', tags: ['red', 'blue'] }) });
    await settle();
    assert.equal(row.querySelector('[name="items__1__city"]').value, 'Row city');
    assert.deepEqual(row.querySelectorAll('[name="items__1__tier"]').map(option => [option.value, option.checked]), [['basic', false], ['pro', true]],
        '6129-F08 lookup selects the matching radio without mutating option values');
    assert.deepEqual(row.querySelectorAll('[name="items__1__tags"]').map(option => [option.value, option.checked]), [['red', true], ['blue', true]],
        '6129-F08 lookup checks every mapped checkbox without mutating option values');
    assert.equal(outerCity.value, '');
    assert.equal(siblingState(), untouchedSibling, '6129-F08 lookup leaves the sibling repeatable row untouched');

    const product = row.querySelector('[name="items__1__product"]');
    product.value = 'w'; product.dispatchEvent(new context.Event('input', { isTrusted: true }));
    timers.shift()(); await settle();
    requests.get('/products?q=w').resolve({ ok: true, json: async () => [{ label: 'Widget', value: 'widget', price: '9.50', tier: 'basic', tags: ['blue'] }] });
    await settle();
    row.querySelector('.bbf-autocomplete-item').dispatchEvent(new context.Event('mousedown'));
    assert.equal(row.querySelector('[name="items__1__price"]').value, '9.50');
    assert.deepEqual(row.querySelectorAll('[name="items__1__tier"]').map(option => [option.value, option.checked]), [['basic', true], ['pro', false]],
        '6129-F08 autocomplete replaces the radio selection without mutating option values');
    assert.deepEqual(row.querySelectorAll('[name="items__1__tags"]').map(option => [option.value, option.checked]), [['red', false], ['blue', true]],
        '6129-F08 autocomplete clears stale checkbox selections inside its row');
    assert.equal(outerPrice.value, '');
    assert.equal(siblingState(), untouchedSibling, '6129-F08 autocomplete leaves the sibling repeatable row untouched');
});

test('autocomplete keeps newest results and shortening invalidates in-flight work', async () => {
    const requests = new Map();
    const timers = [];
    const { BBF, context } = loadBBF({
        fetch: url => { const gate = deferred(); requests.set(url, gate); return gate.promise; },
        setTimeout: fn => { timers.push(fn); return fn; },
        clearTimeout: fn => { const index = timers.indexOf(fn); if (index >= 0) timers.splice(index, 1); },
    });
    const wrap = BBF._buildField({ name: 'city', type: 'text', autocomplete_from: {
        url: '/cities?q={{value}}', min_length: 1, debounce: 0,
    } });
    const form = new MiniElement('form'); form.className = 'bbf-form'; form.appendChild(wrap);
    const input = wrap.querySelector('[name="city"]');
    const list = wrap.querySelector('.bbf-autocomplete-list');
    input.value = 'o'; input.dispatchEvent(new context.Event('input', { isTrusted: true })); timers.shift()(); await settle();
    input.value = 'os'; input.dispatchEvent(new context.Event('input', { isTrusted: true })); timers.shift()(); await settle();
    requests.get('/cities?q=os').resolve({ ok: true, json: async () => ['Oslo'] }); await settle();
    requests.get('/cities?q=o').resolve({ ok: true, json: async () => ['Old'] }); await settle();
    assert.equal(list.querySelector('.bbf-autocomplete-item').textContent, 'Oslo');

    input.value = 'osl'; input.dispatchEvent(new context.Event('input', { isTrusted: true })); timers.shift()(); await settle();
    input.dispatchEvent(new context.Event('blur'));
    assert.equal(timers.length, 1);
    input.dispatchEvent(new context.Event('focus'));
    assert.equal(timers.length, 0);
    requests.get('/cities?q=osl').resolve({ ok: true, json: async () => ['Oslo refined'] }); await settle();
    assert.equal(list.querySelector('.bbf-autocomplete-item').textContent, 'Oslo refined');

    input.value = 'oslo'; input.dispatchEvent(new context.Event('input', { isTrusted: true })); timers.shift()(); await settle();
    input.value = ''; input.dispatchEvent(new context.Event('input', { isTrusted: true }));
    requests.get('/cities?q=oslo').resolve({ ok: true, json: async () => ['Stale after clear'] }); await settle();
    assert.equal(list.style.display, 'none');
});

test('first invalid input reveals and synchronizes its actual page before focus', () => {
    const { BBF } = loadBBF();
    const form = new MiniElement('form');
    const page1 = form.appendChild(new MiniElement('div')); page1.className = 'bbf-page';
    const page2 = form.appendChild(new MiniElement('div')); page2.className = 'bbf-page'; page2.style.display = 'none';
    const wrap = page2.appendChild(new MiniElement('div')); wrap.setAttribute('data-field', 'required');
    const input = wrap.appendChild(new MiniElement('input'));
    const error = wrap.appendChild(new MiniElement('div')); error.className = 'bbf-field-error';
    const nav = form.appendChild(new MiniElement('div')); nav.className = 'bbf-page-nav';
    for (const cls of ['bbf-prev', 'bbf-next', 'bbf-submit', 'bbf-page-indicator']) { const el = nav.appendChild(new MiniElement(cls === 'bbf-page-indicator' ? 'span' : 'button')); el.className = cls; }
    form._bbfPageState = { currentPage: { value: 0 }, totalPages: 2, langCode: null };

    BBF._showErrors(form, { required: 'Required' });
    assert.equal(form._bbfPageState.currentPage.value, 1);
    assert.equal(page1.style.display, 'none');
    assert.equal(page2.style.display, '');
    assert.equal(input.focused, true);
});

test('successful submit passes the filtered payload and recomputes conditions after reset', async () => {
    let callbackBody = null;
    class TestFormData {
        forEach(callback) {
            callback('yes', 'gate');
            callback('shown', 'dependent');
            callback('stale', 'excluded');
            callback('3', 'score');
        }
    }
    const { BBF, context } = loadBBF({
        FormData: TestFormData,
        fetch: async () => ({
            ok: true,
            headers: { get: () => 'application/json' },
            json: async () => ({ status: 'ok' }),
        }),
    });
    context.window.location = context.location;
    const el = BBF._buildForm({
        fields: [
            { name: 'gate', type: 'text', value: 'yes' },
            { name: 'dependent', type: 'text', show_if: { field: 'gate', value: 'yes' } },
            { name: 'excluded', type: 'text', show_if: { field: 'gate', value: 'no' } },
            { name: 'score', type: 'rating', max: 5 },
        ],
    }, 'test', 'https://example.test/', {
        onSuccess: (result, body) => { callbackBody = body; },
    }, null, null, true);
    const gate = el.querySelector('[name="gate"]');
    const dependent = el.querySelector('[data-field="dependent"]');
    const excluded = el.querySelector('[data-field="excluded"]');
    const rating = el.querySelector('[data-field="score"]');
    const ratingInput = rating.querySelector('[name="score"]');
    const ratingStars = rating.querySelectorAll('.bbf-star');
    assert.equal(dependent.style.display, '');
    assert.equal(excluded.style.display, 'none');
    rating.querySelector('.bbf-rating-stars').dispatchEvent(Object.assign(new context.Event('click'), { target: ratingStars[2] }));
    assert.equal(ratingStars[2].getAttribute('aria-checked'), 'true');
    el.reset = () => {
        gate.value = '';
        ratingInput.value = '';
        el.dispatchEvent(new context.Event('reset'));
        el.resetCalled = true;
    };

    el.dispatchEvent(new context.Event('submit'));
    await settle();
    assert.equal(callbackBody.gate, 'yes');
    assert.equal(callbackBody.dependent, 'shown');
    assert.equal(Object.hasOwn(callbackBody, 'excluded'), false);
    assert.equal(el.resetCalled, true);
    assert.equal(dependent.style.display, 'none');
    assert.equal(ratingInput.value, '');
    assert.equal(ratingStars[0].getAttribute('tabindex'), '0');
    assert.equal(ratingStars[2].getAttribute('aria-checked'), 'false');
    assert.equal(ratingStars[2].classList.contains('bbf-star-active'), false);
});

test('repeatable controls enforce bounds while row and input identities stay stable', () => {
    const { BBF, context } = loadBBF();
    const group = BBF._buildField({
        name: 'items', type: 'group', title: 'Items', repeatable: true, min_items: 1, max_items: 2,
        fields: [{ name: 'sku', type: 'text', label: 'SKU' }],
    });
    const add = group.querySelector('.bbf-repeatable-add');
    let rows = group.querySelectorAll('.bbf-repeatable-row');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].getAttribute('data-bbf-row-id'), '1');
    assert.equal(rows[0].querySelector('.bbf-repeatable-remove').disabled, true);
    const firstInputId = rows[0].querySelector('[name="items__1__sku"]').id;

    add.dispatchEvent(new context.Event('click'));
    rows = group.querySelectorAll('.bbf-repeatable-row');
    assert.equal(rows.length, 2);
    assert.equal(add.disabled, true);
    assert.equal(rows[0].querySelector('[name="items__1__sku"]').id, firstInputId);
    assert.equal(rows[1].querySelector('.bbf-repeatable-row-title').textContent, 'Items item 2');
    assert.match(rows[1].querySelector('.bbf-repeatable-remove').getAttribute('aria-label'), /Items item 2/);

    rows[0].querySelector('.bbf-repeatable-remove').dispatchEvent(new context.Event('click'));
    rows = group.querySelectorAll('.bbf-repeatable-row');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].getAttribute('data-bbf-row-id'), '2');
    assert.equal(rows[0].querySelector('[name="items__2__sku"]').id, 'bbf-items__2__sku');
    add.dispatchEvent(new context.Event('click'));
    rows = group.querySelectorAll('.bbf-repeatable-row');
    assert.equal(rows[1].getAttribute('data-bbf-row-id'), '3');
});

test('repeatable conditions, validation, and serialization stay row-local', () => {
    const { BBF, context } = loadBBF();
    const field = {
        name: 'items', type: 'group', title: 'Items', repeatable: true, min_items: 2, max_items: 2,
        fields: [
            { name: 'sku', type: 'text', label: 'SKU', required: true },
            { name: 'kind', type: 'select', options: [{ value: 'normal', label: 'Normal' }, { value: 'special', label: 'Special' }] },
            { name: 'special_fields', type: 'group', show_if: { all: [
                { field: 'kind', value: 'special' }, { field: 'enabled', value: 'yes' },
            ] }, fields: [
                { name: 'detail', type: 'text', label: 'Detail', required: true },
            ] },
            { name: 'tags', type: 'checkbox', options: [{ value: 'gift', label: 'Gift' }] },
        ],
    };
    const form = new MiniElement('form'); form.className = 'bbf-form';
    const enabled = form.appendChild(new MiniElement('input'));
    enabled.name = 'enabled'; enabled.setAttribute('name', 'enabled'); enabled.value = 'yes';
    const group = form.appendChild(BBF._buildField(field));
    group._bbfBindRows();
    const rows = group.querySelectorAll('.bbf-repeatable-row');
    const firstKind = rows[0].querySelector('[name="items__1__kind"]');
    const secondKind = rows[1].querySelector('[name="items__2__kind"]');
    firstKind.value = 'normal'; firstKind.dispatchEvent(new context.Event('change'));
    secondKind.value = 'special'; secondKind.dispatchEvent(new context.Event('change'));
    rows[0].querySelector('[name="items__1__sku"]').value = 'A-1';
    rows[1].querySelector('[name="items__2__sku"]').value = 'B-2';
    rows[1].querySelector('[name="items__2__tags"]').checked = true;

    assert.equal(rows[0].querySelector('[data-field="items__1__special_fields"]').style.display, 'none');
    assert.equal(rows[1].querySelector('[data-field="items__2__special_fields"]').style.display, '');
    enabled.value = 'no'; enabled.dispatchEvent(new context.Event('change'));
    assert.equal(rows[1].querySelector('[data-field="items__2__special_fields"]').style.display, 'none');
    enabled.value = 'yes'; enabled.dispatchEvent(new context.Event('change'));
    assert.equal(rows[1].querySelector('[data-field="items__2__special_fields"]').style.display, '');
    const errors = BBF._validateRepeatableGroups([field], form);
    assert.deepEqual(Object.keys(errors), ['items__2__detail']);
    rows[1].querySelector('[name="items__2__detail"]').value = 'Cold';
    assert.deepEqual(Object.keys(BBF._validateRepeatableGroups([field], form)), []);
    assert.deepEqual(JSON.parse(JSON.stringify(BBF._collectRepeatableRows(field, group))), [
        { sku: 'A-1', kind: 'normal', tags: [] },
        { sku: 'B-2', kind: 'special', detail: 'Cold', tags: ['gift'] },
    ]);
    assert.equal(enabled.listeners.change.length, 2);
    group._bbfResetRows();
    assert.equal(enabled.listeners.change.length, 2);
});

test('repeatable reset restores minimum rows and page navigation validates current rows', () => {
    const timers = [];
    const { BBF, context } = loadBBF({ setTimeout: fn => { timers.push(fn); return fn; } });
    const field = {
        name: 'items', type: 'group', title: 'Items', repeatable: true, min_items: 1, max_items: 2,
        fields: [{ name: 'sku', type: 'text', label: 'SKU', required: true }],
    };
    const form = BBF._buildForm({ fields: [field, { type: 'page_break' }, { name: 'done', type: 'text' }] },
        'test', 'https://example.test/', {}, null, null, true);
    const group = form.querySelector('[data-field="items"]');
    group.querySelector('.bbf-repeatable-add').dispatchEvent(new context.Event('click'));
    assert.equal(group.querySelectorAll('.bbf-repeatable-row').length, 2);
    form.dispatchEvent(new context.Event('reset'));
    timers.splice(0).forEach(fn => fn());
    assert.equal(group.querySelectorAll('.bbf-repeatable-row').length, 1);

    form.querySelector('.bbf-next').dispatchEvent(new context.Event('click'));
    assert.equal(form._bbfPageState.currentPage.value, 0);
    const input = group.querySelector('.bbf-repeatable-row').querySelector('input');
    assert.equal(input.focused, true);
    input.value = 'A-1';
    form.querySelector('.bbf-next').dispatchEvent(new context.Event('click'));
    assert.equal(form._bbfPageState.currentPage.value, 1);
});

test('submit emits structured repeatable rows and maps dotted server errors to current row controls', async () => {
    let callbackBody = null;
    class EmptyFormData { forEach() {} }
    const { BBF, context } = loadBBF({
        FormData: EmptyFormData,
        fetch: async () => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ status: 'ok' }) }),
    });
    context.window.location = context.location;
    const field = {
        name: 'items', type: 'group', title: 'Items', repeatable: true, min_items: 1, max_items: 1,
        fields: [
            { name: 'sku', type: 'text', required: true },
            { name: 'kind', type: 'select', options: [{ value: 'normal', label: 'Normal' }] },
            { name: 'detail', type: 'text', show_if: { field: 'kind', value: 'special' } },
        ],
    };
    const form = BBF._buildForm({ fields: [field] }, 'test', 'https://example.test/', {
        onSuccess: (result, body) => { callbackBody = body; return false; },
    }, null, null, true);
    const row = form.querySelector('.bbf-repeatable-row');
    row.querySelector('[name="items__1__sku"]').value = 'A-1';
    row.querySelector('[name="items__1__kind"]').value = 'normal';
    form.dispatchEvent(new context.Event('submit'));
    await settle();
    assert.deepEqual(JSON.parse(JSON.stringify(callbackBody.items)), [{ sku: 'A-1', kind: 'normal' }]);

    BBF._showErrors(form, { 'items.0.sku': 'Server rejected SKU' });
    const skuWrap = row.querySelector('[data-field="items__1__sku"]');
    assert.equal(skuWrap.querySelector('.bbf-field-error').textContent, 'Server rejected SKU');
    assert.equal(skuWrap.querySelector('input').focused, true);
});

test('6129-F09 cross-field zero, whitespace, arrays, and hidden values match PHP', () => {
    const { BBF } = loadBBF();
    const form = new MiniElement('form'); form.className = 'bbf-form';
    const text = form.appendChild(new MiniElement('input'));
    text.name = 'text'; text.setAttribute('name', 'text');
    const choices = ['2', '3'].map(value => {
        const input = form.appendChild(new MiniElement('input'));
        input.type = 'checkbox'; input.name = 'choices'; input.setAttribute('name', 'choices');
        input.value = value; input.checked = true; return input;
    });
    const hiddenWrap = form.appendChild(new MiniElement('div'));
    hiddenWrap.setAttribute('data-conditional-hidden', 'true');
    const hidden = hiddenWrap.appendChild(new MiniElement('input'));
    hidden.name = 'hidden'; hidden.setAttribute('name', 'hidden'); hidden.value = '100';
    const phpCode = `define('BBF_LOADED', true); require $argv[1]; $payload=json_decode(stream_get_contents(STDIN),true); echo json_encode(validateCrossFields($payload['rules'],$payload['data']));`;
    const phpErrors = (rules, data) => {
        const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', phpCode,
            path.join(__dirname, '..', 'bbf_functions.php')], {
            input: JSON.stringify({ rules, data }), encoding: 'utf8', timeout: 10000, windowsHide: true,
        });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        return JSON.parse(result.stdout);
    };
    const check = (label, rule, data, expectedErrors) => {
        const clientErrors = BBF._validateCrossField([rule], form);
        const serverErrors = phpErrors([rule], data);
        assert.equal(Object.keys(clientErrors).length, expectedErrors, '6129-F09 client ' + label);
        assert.equal(Object.keys(serverErrors).length, expectedErrors, '6129-F09 PHP ' + label);
    };

    text.value = '';
    check('explicit zero minimum accepts empty visible values',
        { type: 'min_sum', fields: ['text', 'hidden'], min: 0 }, { text: '' }, 0);
    check('positive minimum rejects empty visible values despite hidden stale data',
        { type: 'min_sum', fields: ['text', 'hidden'], min: 1 }, { text: '' }, 1);
    text.value = '   ';
    check('whitespace is not filled', { type: 'min_filled', fields: ['text'], min: 1 }, { text: '   ' }, 1);
    text.value = '';
    check('checkbox arrays are not numeric scalars',
        { type: 'min_sum', fields: ['choices'], min: 1 }, { choices: choices.map(input => input.value) }, 1);
    check('nonempty checkbox arrays count as one filled field',
        { type: 'min_filled', fields: ['choices'], min: 1 }, { choices: choices.map(input => input.value) }, 0);
});

test('6129-F10 shared preview preparation is latest-request-wins for options_from', async () => {
    const requests = new Map();
    const { BBF } = loadBBF({ fetch: url => {
        const gate = deferred(); requests.set(url, gate); return gate.promise;
    } });
    let generation = 1;
    const stale = { fields: [{ name: 'choice', type: 'select', options_from: '/stale-options' }] };
    const fresh = { fields: [{ name: 'choice', type: 'select', options_from: '/fresh-options' }] };
    const stalePreparation = BBF._prepareFormDefinition(stale, () => generation === 1);
    generation = 2;
    const freshPreparation = BBF._prepareFormDefinition(fresh, () => generation === 2);
    requests.get('/fresh-options').resolve({ ok: true, json: async () => ['Fresh'] });
    await freshPreparation;
    requests.get('/stale-options').resolve({ ok: true, json: async () => ['Stale'] });
    await stalePreparation;
    assert.deepEqual(fresh.fields[0].options, ['Fresh'], '6129-F10 current preview receives dynamic options');
    assert.equal(stale.fields[0].options, undefined, '6129-F10 stale preview cannot apply late dynamic options');
    const editor = fs.readFileSync(path.join(__dirname, '..', 'editor.php'), 'utf8');
    const sandbox = fs.readFileSync(path.join(__dirname, '..', 'sandbox.php'), 'utf8');
    assert.match(editor, /await BBF\._prepareFormDefinition\(def,/);
    assert.match(sandbox, /await BBF\._prepareFormDefinition\(formDef,/);
});

test('6129-F11 simultaneous form instances namespace every label and ARIA id', () => {
    const { BBF } = loadBBF();
    const definition = { fields: [
        { name: 'email', type: 'text', label: 'Email', description: 'Contact', autocomplete_from: '/people?q={{value}}' },
        { name: 'choice', type: 'checkbox', label: 'Choice', description: 'Pick', options: ['a'] },
        { name: 'score', type: 'rating', label: 'Score', description: 'Rate' },
        { name: 'items', type: 'group', repeatable: true, title: 'Items', fields: [{ name: 'sku', type: 'text', label: 'SKU' }] },
    ] };
    const forms = [
        BBF._buildForm(structuredClone(definition), 'same', 'https://example.test/', {}, null, null, true),
        BBF._buildForm(structuredClone(definition), 'same', 'https://example.test/', {}, null, null, true),
    ];
    const nodes = root => {
        const result = [];
        const visit = node => { result.push(node); node.children.forEach(visit); };
        visit(root); return result;
    };
    const allNodes = forms.flatMap(nodes);
    const ids = allNodes.map(node => node.id).filter(Boolean);
    assert.equal(new Set(ids).size, ids.length, '6129-F11 two embeds contain no duplicate HTML id');
    assert.notEqual(forms[0].getAttribute('data-bbf-instance'), forms[1].getAttribute('data-bbf-instance'));
    forms.forEach(form => {
        const formIds = new Set(nodes(form).map(node => node.id).filter(Boolean));
        const email = form.querySelector('[name="email"]');
        assert.equal(form.querySelector('label').getAttribute('for'), email.id);
        for (const attribute of ['aria-describedby', 'aria-controls']) {
            nodes(form).forEach(node => {
                const references = (node.getAttribute(attribute) || '').split(/\s+/).filter(Boolean);
                references.forEach(id => assert.ok(formIds.has(id), `6129-F11 ${attribute} resolves ${id}`));
            });
        }
    });
});

test('6129-F12 delayed reset cannot reveal hideOnSuccess fields', async () => {
    const timers = [];
    class TestFormData { forEach(callback) { callback('yes', 'gate'); callback('kept', 'dependent'); } }
    const { BBF, context } = loadBBF({
        FormData: TestFormData,
        setTimeout: fn => { timers.push(fn); return fn; },
        fetch: async () => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ status: 'ok' }) }),
    });
    context.window.location = context.location;
    const form = BBF._buildForm({ fields: [
        { name: 'gate', type: 'text', value: 'yes' },
        { name: 'dependent', type: 'text', show_if: { field: 'gate', value: 'yes' } },
    ] }, 'hidden-success', 'https://example.test/', { hideOnSuccess: true }, null, null, true);
    const gate = form.querySelector('[name="gate"]');
    const dependent = form.querySelector('[data-field="dependent"]');
    form.reset = () => { gate.value = ''; form.dispatchEvent(new context.Event('reset')); };
    form.dispatchEvent(new context.Event('submit'));
    await settle();
    assert.equal(dependent.style.display, 'none');
    timers.splice(0).forEach(fn => fn());
    assert.equal(dependent.style.display, 'none', '6129-F12 reset callback preserves hidden success lifecycle');
    assert.equal(form.querySelector('.bbf-submit-wrap').style.display, 'none');
});

test('schema permits option-level show_if', () => {
    const schema = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'forms', 'form.schema.json'), 'utf8'));
    assert.deepEqual(schema.$defs.field.properties.options.items.oneOf[1].properties.show_if, { $ref: '#/$defs/condition' });
});

test('demo5 renders once, scores the submitted payload, and handles no-match success', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', 'demo5.html'), 'utf8');
    const start = source.lastIndexOf('<script>') + '<script>'.length;
    const script = source.slice(start, source.lastIndexOf('</script>'));
    const message = { innerHTML: '' };
    const container = { querySelector: selector => selector === '.bbf-message' ? message : null };
    let renderCount = 0;
    let renderOptions = null;
    vm.runInNewContext(script, {
        document: { getElementById: id => id === 'allergy-form' ? container : null },
        BBF: { render: (id, target, options) => { renderCount++; renderOptions = options; } },
        setTimeout: callback => callback(),
        location: { reload() {} },
    });

    assert.equal(renderCount, 1);
    assert.equal(message.innerHTML, '');
    renderOptions.onSuccess({}, { sneeze_intensity: 'frequent_violent' });
    assert.match(message.innerHTML, /Sabadilla/);
    assert.doesNotMatch(message.innerHTML, /No matching profile/);
    renderOptions.onSuccess({}, {});
    assert.match(message.innerHTML, /No matching profile was found/);
    assert.match(message.innerHTML, /Try Again/);
});
