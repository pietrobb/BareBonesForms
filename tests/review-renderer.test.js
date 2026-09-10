'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

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

test('schema permits option-level show_if', () => {
    const schema = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'forms', 'form.schema.json'), 'utf8'));
    assert.deepEqual(schema.$defs.field.properties.options.items.properties.show_if, { $ref: '#/$defs/condition' });
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
