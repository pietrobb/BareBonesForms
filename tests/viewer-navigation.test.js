'use strict';

// Run with: node --test tests/viewer-navigation.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'viewer.php'), 'utf8');
const script = source.slice(source.indexOf('<script>') + 8, source.lastIndexOf('</script>'))
    .replace(/<\?= json_encode\(\$formsList\) \?>/g, JSON.stringify([
        { id: 'quote-cz', name: 'Quote', count: 25 },
        { id: 'contact-sk', name: 'Contact', count: 1 },
    ]))
    .replace(/<\?= json_encode\(\$viewerToken\) \?>/g, '"test-token"')
    .replace(/<\?= json_encode\(\$canDelete\) \?>/g, 'true')
    .replace(/<\?= json_encode\(\$siteName\) \?>/g, '"Test Viewer"')
    .replace(/<\?= json_encode\(\$viewerLang\) \?>/g, '"en"')
    .replace(/\}\)\(\);\s*$/, `globalThis.viewer = {
        state, api, showDashboard, selectForm, openDetail, loadPage, restoreRoute,
        buildLabelMap, getPreviewKeys, getCardSections, getTableColumns,
        renderCards, renderCardsGrid, renderTable, valueText
    }; })();`);

function classList(...initial) {
    const names = new Set(initial);
    return {
        add(...values) { values.forEach(value => names.add(value)); },
        contains(value) { return names.has(value); },
        remove(...values) { values.forEach(value => names.delete(value)); },
        toggle(value, force) {
            const enabled = force === undefined ? !names.has(value) : force;
            if (enabled) names.add(value); else names.delete(value);
            return enabled;
        },
    };
}

function element() {
    return {
        innerHTML: '', style: {}, listeners: {}, classList: classList(),
        addEventListener(type, fn) {
            const previous = this.listeners[type];
            this.listeners[type] = previous ? event => { previous(event); fn(event); } : fn;
        },
        querySelector() { return null; },
    };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

async function settle() {
    for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
}

async function createViewer(hash = '', savedHistory = null) {
    const elements = new Map(['form-list', 'panel-main', 'panel-forms', 'header-status',
        'header-title', 'drawer-backdrop', 'btn-hamburger', 'content', 'pagination', 'toolbar-count']
        .map(id => [id, element()]));
    const document = {
        listeners: {}, body: element(),
        getElementById(id) { return elements.get(id) || null; },
        querySelector() { return null; },
        addEventListener(type, fn) {
            const previous = this.listeners[type];
            this.listeners[type] = previous ? event => { previous(event); fn(event); } : fn;
        },
        createElement() {
            return {
                textContent: '',
                get innerHTML() {
                    return this.textContent.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                },
            };
        },
    };
    const location = { pathname: '/viewer.php', search: '?token=test-token', hash };
    const entries = savedHistory ? structuredClone(savedHistory.entries) : [{ hash, state: null }];
    let index = savedHistory?.index ?? 0;
    const window = {
        listeners: {},
        addEventListener(type, fn) {
            const previous = this.listeners[type];
            this.listeners[type] = previous ? event => { previous(event); fn(event); } : fn;
        },
    };
    const history = {
        get state() { return entries[index].state; },
        pushState(state, unused, url) {
            const hash = url.includes('#') ? url.slice(url.indexOf('#')) : '';
            entries.splice(index + 1);
            entries.push({ hash, state: structuredClone(state) });
            index++;
            location.hash = hash;
        },
        replaceState(state, unused, url) {
            location.hash = url.includes('#') ? url.slice(url.indexOf('#')) : '';
            entries[index] = { hash: location.hash, state: structuredClone(state) };
        },
        go(delta) {
            const next = index + delta;
            if (next < 0 || next >= entries.length) return;
            const oldHash = location.hash;
            index = next;
            location.hash = entries[index].hash;
            if (location.hash !== oldHash) window.listeners.hashchange();
        },
        back() { this.go(-1); },
        forward() { this.go(1); },
        snapshot() { return { entries, index }; },
    };
    const requests = [];
    const formDef = { name: 'Quote', fields: [{ name: 'name', label: 'Name', type: 'text' }] };
    const delivery = { state: 'retry_scheduled', settled: false, jobs: [{ key: 'action:0', type: 'action',
        state: 'failed', attempts: 1, max_attempts: 3, next_retry: 1893456000, can_retry: false }] };
    const sub = (id, form = 'quote-cz') => ({ id, form, data: { name: 'Test Person' }, meta: {} });
    const context = vm.createContext({
        document, window, location, history, URLSearchParams, console,
        setTimeout, clearTimeout, confirm: () => true,
        localStorage: { getItem() { return null; }, setItem() {} },
        fetch: async url => {
            const params = new URLSearchParams(url.slice(url.indexOf('?') + 1));
            requests.push(Object.fromEntries(params));
            const form = params.get('form');
            let data;
            switch (params.get('action')) {
                case 'dashboard':
                    data = { total: 26, today: 0, this_week: 0,
                        per_form: [{ id: 'quote-cz', name: 'Quote', total: 25, today: 0 }],
                        recent: [sub('bbf_1')], form_defs: { 'quote-cz': formDef } };
                    break;
                case 'stats': data = { total: form === 'quote-cz' ? 25 : 1 }; break;
                case 'submissions':
                    data = { total: form === 'quote-cz' ? 25 : 1, form_def: formDef,
                        submissions: [sub('bbf_1', form), sub('bbf_2', form)] };
                    break;
                case 'detail': data = { submission: sub(params.get('id'), form), form_def: formDef, delivery }; break;
                case 'delete': data = { ok: true }; break;
                case 'bulk_delete': data = { ok: true, deleted: 2 }; break;
                case 'list_forms': data = [
                    { id: 'quote-cz', name: 'Quote', count: 23 },
                    { id: 'contact-sk', name: 'Contact', count: 1 },
                ]; break;
                default: throw new Error('Unexpected API request: ' + url);
            }
            return { ok: true, json: async () => data };
        },
    });
    vm.runInContext(script, context, { filename: 'viewer.php inline script' });
    await settle();

    function eventNode({ aliases = [], classes = [], dataset = {}, id = '', tag = 'div', type = '', parent = null } = {}) {
        const node = {
            dataset, id, tagName: tag.toUpperCase(), type, parentElement: parent,
            classList: classList(...classes), disabled: false, checked: false,
            matches(selector) {
                return selector.split(',').some(candidate => {
                    const part = candidate.trim();
                    if (aliases.includes(part)) return true;
                    if (part === this.tagName.toLowerCase()) return true;
                    if (part.startsWith('#')) return this.id === part.slice(1);
                    if (/^\.[\w-]+$/.test(part)) return this.classList.contains(part.slice(1));
                    if (part === 'input[type="checkbox"]') return this.tagName === 'INPUT' && this.type === 'checkbox';
                    return false;
                });
            },
            closest(selector) {
                for (let current = this; current; current = current.parentElement) {
                    if (current.matches(selector)) return current;
                }
                return null;
            },
        };
        node.click = () => dispatchClick(node);
        return node;
    }

    function actionTarget(selector, dataset, options = {}) {
        const classes = [...selector.matchAll(/\.([\w-]+)/g)].map(match => match[1]);
        const id = selector.startsWith('#') ? selector.slice(1) : '';
        const tag = options.tag || (selector.includes(' tr') ? 'tr' : (selector.startsWith('#btn-') || selector === '#bulk-delete' ? 'button' : 'div'));
        const action = eventNode({ aliases: [selector], classes: [...classes, ...(options.classes || [])], dataset, id, tag });
        if (!options.nested) return { action, target: action };
        const nested = {
            checkbox: { aliases: ['.sub-checkbox'], classes: ['sub-checkbox'], tag: 'input', type: 'checkbox' },
            button: { classes: ['nested-button'], tag: 'button' },
            input: { classes: ['nested-input'], tag: 'input', type: 'text' },
            select: { classes: ['nested-select'], tag: 'select' },
            link: { classes: ['nested-link'], tag: 'a' },
        }[options.nested];
        return { action, target: eventNode({ ...nested, dataset, parent: action }) };
    }

    function dispatchClick(target) {
        let stopped = false;
        const event = {
            target,
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { stopped = true; },
            defaultPrevented: false,
        };
        elements.get('panel-main').listeners.click?.(event);
        return { event, stopped };
    }

    async function press(selector, dataset, key, options = {}) {
        const { target } = actionTarget(selector, dataset, options);
        let stopped = false;
        const event = {
            key, target, ctrlKey: false, metaKey: false, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { stopped = true; },
        };
        elements.get('panel-main').listeners.keydown?.(event);
        if (!stopped) document.listeners.keydown?.(event);
        const nativeButton = ['BUTTON', 'A'].includes(target.tagName) && (key === 'Enter' || key === ' ');
        const nativeCheckbox = target.tagName === 'INPUT' && target.type === 'checkbox' && key === ' ';
        if (!event.defaultPrevented && (nativeButton || nativeCheckbox)) target.click();
        await settle();
    }

    return {
        ...context.viewer, history, location, requests, elements,
        html: () => elements.get('panel-main').innerHTML,
        async click(selector, dataset = {}, options = {}) {
            dispatchClick(actionTarget(selector, dataset, options).target);
            await settle();
        },
        press,
        async escape() {
            document.listeners.keydown({ key: 'Escape', target: { matches: () => false } });
            await settle();
        },
    };
}

async function showFilteredQuote(v) {
    await v.selectForm('quote-cz');
    v.state.page = 2;
    v.state.search = 'Person & name=ž';
    v.state.dateFrom = '2026-09-01';
    v.state.dateTo = '2026-09-08';
    await v.loadPage();
}

function routeSnapshot(v) {
    return {
        hash: v.location.hash,
        view: v.state.view,
        formId: v.state.formId,
        detail: v.state.detail,
        page: v.state.page,
        search: v.state.search,
        dateFrom: v.state.dateFrom,
        dateTo: v.state.dateTo,
    };
}

function assertKeyboardTarget(html, marker, native = false) {
    const markerAt = html.indexOf(marker);
    assert.notEqual(markerAt, -1, `rendered target ${marker} must exist`);
    const start = html.lastIndexOf('<', markerAt);
    const end = html.indexOf('>', markerAt);
    const openingTag = html.slice(start, end + 1);
    if (native) {
        assert.match(openingTag, /^<button\b/, `${marker} must be a native button`);
        return;
    }
    assert.match(openingTag, /\btabindex="0"/, `${marker} must be in the tab order`);
    assert.match(openingTag, /\brole="button"/, `${marker} must expose button semantics`);
}

for (const action of ['button', 'escape', 'browser']) {
    test(`dashboard -> detail -> ${action} returns to populated dashboard`, async () => {
        const v = await createViewer();
        await v.click('.sub-card', { id: 'bbf_1', form: 'quote-cz' });
        assert.match(v.html(), /detail-view/);
        assert.equal(v.state.detailReturn, '');
        if (action === 'button') await v.click('#btn-back');
        if (action === 'escape') await v.escape();
        if (action === 'browser') { v.history.back(); await settle(); }
        assert.equal(v.state.view, 'dashboard');
        assert.equal(v.location.hash, '');
        assert.match(v.html(), /Forms overview/);
        assert.match(v.html(), /stat-value">26</);
        assert.doesNotMatch(v.html(), /empty-state/);
    });
}

for (const mode of ['cards', 'table']) {
    test(`${mode} list retains page, filters and view mode after detail`, async () => {
        const v = await createViewer();
        await v.selectForm('quote-cz');
        v.state.viewMode = mode;
        v.state.page = 2;
        v.state.search = 'Person & name=ž';
        v.state.dateFrom = '2026-09-01';
        v.state.dateTo = '2026-09-08';
        await v.loadPage();
        const listHash = v.location.hash;
        await v.click(mode === 'cards' ? '.grid-card' : '.sub-table tbody tr', { id: 'bbf_1' });
        assert.match(v.html(), /delivery-card/);
        assert.match(v.html(), /retry_scheduled/);
        await v.click('#btn-next');
        assert.equal(v.state.detail, 'bbf_2');
        await v.click('#btn-back');
        assert.equal(v.state.detail, null);
        assert.equal(v.location.hash, listHash);
        assert.equal(v.state.page, 2);
        assert.equal(v.state.search, 'Person & name=ž');
        assert.equal(v.state.dateFrom, '2026-09-01');
        assert.equal(v.state.dateTo, '2026-09-08');
        assert.equal(v.state.viewMode, mode);
        assert.equal(v.state.total, 25);
        assert.match(v.html(), mode === 'cards' ? /cards-grid/ : /sub-table/);
        const request = v.requests.filter(r => r.action === 'submissions').at(-1);
        assert.equal(request.offset, '20');
        assert.equal(request.q, 'Person & name=ž');
        assert.equal(request.from, '2026-09-01');
        assert.equal(request.to, '2026-09-08');
    });
}

test('browser Back and Forward restore list, dashboard and detail without extra history entries', async () => {
    const v = await createViewer();
    await v.selectForm('quote-cz');
    await v.openDetail('bbf_1');
    assert.equal(v.history.snapshot().entries.length, 3);
    v.history.back(); await settle();
    assert.equal(v.state.total, 25);
    assert.equal(v.state.detail, null);
    v.history.back(); await settle();
    assert.equal(v.state.view, 'dashboard');
    v.history.forward(); await settle();
    assert.equal(v.state.formId, 'quote-cz');
    v.history.forward(); await settle();
    assert.equal(v.state.detail, 'bbf_1');
    assert.equal(v.state.subs.length, 2);
    assert.match(v.html(), /detail-view/);
    assert.equal(v.history.snapshot().entries.length, 3);
    assert.equal(v.location.search, '?token=test-token');
});

test('direct detail link loads a real list on Back', async () => {
    const v = await createViewer('#form=quote-cz&id=bbf_1&page=2&q=Person');
    assert.match(v.html(), /detail-view/);
    assert.match(v.html(), /delivery-card/);
    assert.match(v.html(), /retry_scheduled/);
    await v.click('#btn-back');
    assert.equal(v.state.detail, null);
    assert.equal(v.state.formId, 'quote-cz');
    assert.equal(v.state.total, 25);
    assert.equal(v.state.page, 2);
    assert.equal(v.state.search, 'Person');
    assert.match(v.html(), /cards-grid/);
});

test('reload on a dashboard detail preserves its return destination', async () => {
    const first = await createViewer();
    await first.openDetail('bbf_1', 'quote-cz');
    const reloaded = await createViewer(first.location.hash, first.history.snapshot());
    await reloaded.click('#btn-back');
    assert.equal(reloaded.state.view, 'dashboard');
    assert.match(reloaded.html(), /Forms overview/);
});

test('dashboard detail cannot reuse submissions from a previously visited form', async () => {
    const v = await createViewer();
    await v.selectForm('contact-sk');
    await v.showDashboard();
    await v.openDetail('bbf_1', 'quote-cz');
    assert.equal(v.state.subs.length, 0);
    await v.click('#btn-back');
    assert.equal(v.state.view, 'dashboard');
});

test('late detail response cannot overwrite dashboard after browser Back', async () => {
    const v = await createViewer();
    let resolve;
    v.api.detail = () => new Promise(r => { resolve = r; });
    const pending = v.openDetail('bbf_1', 'quote-cz');
    v.history.back(); await settle();
    resolve({ submission: { id: 'bbf_1', data: {}, meta: {} }, form_def: null });
    await pending;
    assert.equal(v.state.view, 'dashboard');
    assert.match(v.html(), /Forms overview/);
});

test('late list response cannot overwrite a newer form selection', async () => {
    const v = await createViewer();
    await v.selectForm('quote-cz');
    const original = v.api.submissions;
    let resolve;
    v.api.submissions = () => new Promise(r => { resolve = r; });
    const pending = v.loadPage();
    v.api.submissions = original;
    await v.selectForm('contact-sk');
    resolve({ submissions: [], total: 0 });
    await pending;
    assert.equal(v.state.formId, 'contact-sk');
    assert.equal(v.state.total, 1);
    assert.equal(v.state.subs[0].form, 'contact-sk');
});

test('late delivery retry response cannot overwrite newer navigation', async () => {
    const v = await createViewer();
    await v.openDetail('bbf_1', 'quote-cz');
    const gate = deferred();
    v.api.retryDelivery = () => gate.promise;
    await v.click('.btn-retry-delivery', { job: 'action:0' });
    await v.showDashboard();
    const expectedRoute = routeSnapshot(v);
    const expectedHtml = v.html();

    gate.resolve({ delivery: { state: 'settled', settled: true, jobs: [] } });
    await settle();

    assert.deepEqual(routeSnapshot(v), expectedRoute);
    assert.equal(v.html(), expectedHtml);
    assert.match(v.html(), /Forms overview/);
});

for (const operation of ['single', 'bulk']) {
    for (const navigation of ['Dashboard', 'Back', 'other form']) {
        test(`late ${operation} delete response cannot overwrite ${navigation} navigation`, async () => {
            const v = await createViewer();
            await showFilteredQuote(v);
            if (operation === 'single') await v.openDetail('bbf_1');
            else v.state.selected.add('bbf_1');

            const gate = deferred();
            if (operation === 'single') {
                v.api.del = () => gate.promise;
                await v.click('#btn-del', { id: 'bbf_1' }, { classes: ['confirm'] });
            } else {
                v.api.bulkDelete = () => gate.promise;
                await v.click('#bulk-delete');
            }

            if (navigation === 'Dashboard') await v.showDashboard();
            else if (navigation === 'Back') { v.history.back(); await settle(); }
            else await v.selectForm('contact-sk');

            const expectedRoute = routeSnapshot(v);
            const expectedHtml = v.html();
            v.api.stats = async () => ({ total: 999, today: 999, this_week: 999, this_month: 999 });
            v.api.submissions = async () => ({
                total: 999,
                form_def: null,
                submissions: [{ id: 'late-delete-poison', form: 'poison', data: { name: 'Late delete' }, meta: {} }],
            });
            v.api.listForms = async () => [];

            gate.resolve(operation === 'single' ? { ok: true } : { ok: true, deleted: 1 });
            await settle();

            assert.deepEqual(routeSnapshot(v), expectedRoute);
            assert.equal(v.html(), expectedHtml);
            assert.doesNotMatch(v.html(), /late-delete-poison|Late delete/);
        });
    }
}

for (const operation of ['single', 'bulk']) {
    test(`successful ${operation} delete refresh preserves page and all filters`, async () => {
        const v = await createViewer();
        await showFilteredQuote(v);
        if (operation === 'single') {
            await v.openDetail('bbf_1');
            await v.click('#btn-del', { id: 'bbf_1' }, { classes: ['confirm'] });
        } else {
            v.state.selected.add('bbf_1');
            v.state.selected.add('bbf_2');
            await v.click('#bulk-delete');
        }

        assert.equal(v.state.page, 2);
        assert.equal(v.state.search, 'Person & name=ž');
        assert.equal(v.state.dateFrom, '2026-09-01');
        assert.equal(v.state.dateTo, '2026-09-08');
        const hash = new URLSearchParams(v.location.hash.slice(1));
        assert.equal(hash.get('page'), '2');
        assert.equal(hash.get('q'), 'Person & name=ž');
        assert.equal(hash.get('from'), '2026-09-01');
        assert.equal(hash.get('to'), '2026-09-08');
        const refresh = v.requests.filter(request => request.action === 'submissions').at(-1);
        assert.equal(refresh.offset, '20');
        assert.equal(refresh.q, 'Person & name=ž');
        assert.equal(refresh.from, '2026-09-01');
        assert.equal(refresh.to, '2026-09-08');
    });
}

const keyboardSurfaces = {
    'dashboard form card': {
        marker: 'dash-form-card', selector: '.dash-form-card', dataset: { form: 'quote-cz' }, request: 'submissions',
        setup: async () => {},
    },
    'dashboard recent card': {
        marker: 'sub-card', selector: '.sub-card', dataset: { id: 'bbf_1', form: 'quote-cz' }, request: 'detail',
        setup: async () => {},
    },
    'submission grid card': {
        marker: 'grid-card-open', selector: '.grid-card-open', containerSelector: '.grid-card',
        dataset: { id: 'bbf_1' }, request: 'detail', native: true, tag: 'button',
        setup: async v => { await v.selectForm('quote-cz'); },
    },
    'submission table row': {
        marker: 'table-row-open', selector: '.table-row-open', containerSelector: '.sub-table tbody tr',
        dataset: { id: 'bbf_1' }, request: 'detail', native: true, tag: 'button',
        setup: async v => { v.state.viewMode = 'table'; await v.selectForm('quote-cz'); },
    },
};

for (const [surface, config] of Object.entries(keyboardSurfaces)) {
    for (const key of ['Enter', ' ']) {
        test(`${surface} activates with ${key === ' ' ? 'Space' : key} exactly once`, async () => {
            const v = await createViewer();
            await config.setup(v);
            const renderedList = v.html();
            const before = v.requests.filter(request => request.action === config.request).length;
            await v.press(config.selector, config.dataset, key, { tag: config.tag });
            const after = v.requests.filter(request => request.action === config.request).length;
            assert.equal(after - before, 1);
            assertKeyboardTarget(renderedList, config.marker, config.native);
        });
    }
}

test('selection checkboxes have localized programmatic names', async () => {
    const v = await createViewer();
    await v.selectForm('quote-cz');
    assert.match(v.html(), /class="sub-checkbox" data-id="bbf_1" aria-label="Select submission: bbf_1"/);

    v.state.viewMode = 'table';
    await v.selectForm('quote-cz');
    assert.match(v.html(), /id="select-all" aria-label="Select all"/);
    assert.match(v.html(), /class="sub-checkbox" data-id="bbf_2" aria-label="Select submission: bbf_2"/);
});

for (const surface of ['submission grid card', 'submission table row']) {
    const config = keyboardSurfaces[surface];
    for (const [control, key] of [['checkbox', ' '], ['button', 'Enter'], ['button', ' '], ['input', 'Enter'], ['select', ' ']]) {
        test(`${surface} ignores ${key === ' ' ? 'Space' : key} from nested ${control}`, async () => {
            const v = await createViewer();
            await config.setup(v);
            const before = v.requests.filter(request => request.action === 'detail').length;
            await v.press(config.containerSelector, config.dataset, key, { nested: control });
            const after = v.requests.filter(request => request.action === 'detail').length;
            assert.equal(after, before);
            assert.equal(v.state.detail, null);
        });
    }
}

test('6129-F13 repeatable answers remain visible and labelled across viewer previews including legacy snapshots', async () => {
    const v = await createViewer();
    const definition = { fields: [{
        name: 'items', type: 'group', title: 'Line items', repeatable: true,
        fields: [{ name: 'sku', type: 'text', label: 'SKU' }],
    }] };
    const rows = [{ sku: 'A-1' }, { sku: 'B-2' }];
    const data = { items: rows };
    const legacyDefinition = structuredClone(definition);
    delete legacyDefinition.fields[0].repeatable;
    const submission = { id: 'bbf_repeatable', data, meta: { form_definition: legacyDefinition } };

    assert.deepEqual(Array.from(v.getPreviewKeys(definition, data)), ['items']);
    assert.deepEqual(Array.from(v.getPreviewKeys(legacyDefinition, data)), ['items']);
    assert.equal(v.buildLabelMap(definition).items, 'Line items');
    const sections = v.getCardSections(definition, data);
    assert.equal(sections.length, 1);
    assert.equal(sections[0].fields[0].key, 'items');
    assert.equal(sections[0].fields[0].value, rows);
    assert.match(v.valueText(rows), /A-1[\s\S]*B-2/);

    assert.match(v.renderCards([submission]), /Line items:[\s\S]*A-1/);
    v.state.formDef = null;
    v.state.subs = [submission];
    assert.match(v.renderCardsGrid(), /Line items:[\s\S]*A-1/);
    const table = v.renderTable();
    assert.match(table, /<th>Line items<\/th>/);
    assert.match(table, /A-1/);
    assert.equal((table.match(/<th>SKU<\/th>/g) || []).length, 0);
});

test('6129-F13 static groups retain flattened child previews', async () => {
    const v = await createViewer();
    const definition = { fields: [{
        name: 'contact', type: 'group', title: 'Contact',
        fields: [{ name: 'email', type: 'email', label: 'Email' }],
    }] };
    const data = { email: 'person@example.test' };
    assert.deepEqual(Array.from(v.getPreviewKeys(definition, data)), ['email']);
    assert.deepEqual(Array.from(v.getTableColumns(definition, []), column => column.key), ['email']);
    assert.equal(v.getCardSections(definition, data)[0].fields[0].key, 'email');
});

test('table columns retain renamed and deleted historical fields from version snapshots', async () => {
    const v = await createViewer();
    const current = { fields: [{ name: 'current', label: 'Current label', type: 'text' }] };
    const submissions = [{
        data: { historical: 'retained', orphan: 'also retained' },
        meta: { form_definition: { fields: [{ name: 'historical', label: 'Historical label', type: 'text' }] } },
    }];
    const columns = v.getTableColumns(current, submissions);
    assert.deepEqual(Array.from(columns, column => column.key), ['current', 'historical', 'orphan']);
    assert.equal(columns.find(column => column.key === 'historical').label, 'Historical label');
});
