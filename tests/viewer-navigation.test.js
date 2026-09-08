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
        state, api, showDashboard, selectForm, openDetail, loadPage, restoreRoute
    }; })();`);

function element() {
    return {
        innerHTML: '', style: {}, listeners: {},
        addEventListener(type, fn) { this.listeners[type] = fn; },
        querySelector() { return null; },
        classList: { contains() { return false; }, remove() {}, toggle() {} },
    };
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
        addEventListener(type, fn) { this.listeners[type] = fn; },
        createElement() {
            return {
                textContent: '',
                get innerHTML() {
                    return this.textContent.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                },
            };
        },
    };
    const location = { pathname: '/viewer.php', search: '?token=test-token', hash };
    const entries = savedHistory ? structuredClone(savedHistory.entries) : [{ hash, state: null }];
    let index = savedHistory?.index ?? 0;
    const window = { listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } };
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
    const sub = (id, form = 'quote-cz') => ({ id, form, data: { name: 'Test Person' }, meta: {} });
    const context = vm.createContext({
        document, window, location, history, URLSearchParams, console,
        setTimeout, clearTimeout, localStorage: { getItem() { return null; }, setItem() {} },
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
                case 'detail': data = { submission: sub(params.get('id'), form), form_def: formDef }; break;
                default: throw new Error('Unexpected API request: ' + url);
            }
            return { ok: true, json: async () => data };
        },
    });
    vm.runInContext(script, context, { filename: 'viewer.php inline script' });
    await settle();
    return {
        ...context.viewer, history, location, requests, elements,
        html: () => elements.get('panel-main').innerHTML,
        async click(selector, dataset = {}) {
            const target = { dataset, closest: candidate => candidate === selector ? target : null };
            elements.get('panel-main').listeners.click({ target });
            await settle();
        },
        async escape() {
            document.listeners.keydown({ key: 'Escape', target: { matches: () => false } });
            await settle();
        },
    };
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
