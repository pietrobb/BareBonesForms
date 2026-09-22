'use strict';

// Dependency-free browser isolation: no live network, filesystem writes or production access.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const contextSource = fs.readFileSync(path.join(__dirname, '..', 'bbf-context.js'), 'utf8');
const loaderSource = fs.readFileSync(path.join(__dirname, '..', 'gclid.js'), 'utf8');
const config = {
    visit_context: {
        trigger_params: ['gclid', 'gbraid', 'wbraid'],
        params: ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']
    }
};
const fieldNames = config.visit_context.trigger_params.concat(config.visit_context.params,
    ['landing_url', 'referrer', 'touch_at']);
const base = 'https://example.test/bbf/';
const tests = [];
function test(name, run) { tests.push({ name, run }); }
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
function storage(initial) {
    const values = new Map(Object.entries(initial || {}));
    return {
        values, reads: [], writes: [],
        getItem(key) { this.reads.push(key); return values.has(key) ? values.get(key) : null; },
        setItem(key, value) { this.writes.push([key, value]); values.set(key, value); }
    };
}
function form(names = fieldNames, initial = '') {
    const inputs = names.map(name => ({ name, type: 'hidden', value: initial }));
    return { inputs, querySelectorAll(selector) { assert.equal(selector, 'input'); return inputs; } };
}
function data(target) { return Object.fromEntries(target.inputs.map(input => [input.name, input.value])); }
function browser(options = {}) {
    const store = options.storage || storage();
    const listeners = new Map();
    const scripts = [];
    const forms = options.forms || [];
    const requests = [];
    const doc = {
        referrer: options.referrer || '',
        currentScript: null,
        head: { appendChild(script) { scripts.push(script); } },
        createElement(tag) { assert.equal(tag, 'script'); return {}; },
        querySelectorAll(selector) {
            assert.equal(selector, 'input');
            return forms.flatMap(target => target.inputs);
        },
        addEventListener(type, callback, capture) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push({ callback, capture });
        }
    };
    const win = { setTimeout, clearTimeout,
        location: { href: options.url || 'https://example.test/page' },
        fetch(url, init) {
            requests.push({ url, init });
            if (options.fetch) return options.fetch(url, init);
            return Promise.resolve({ ok: true, json: () => Promise.resolve(options.config || config) });
        }
    };
    Object.defineProperty(win, 'sessionStorage', {
        get() { if (options.denyStorage) throw new Error('SecurityError'); return store; }
    });
    const sandbox = vm.createContext({ window: win, document: doc, URL, Promise, console });
    function execute(kind, src) {
        doc.currentScript = { src: src || base + (kind === 'context' ? 'bbf-context.js' : 'gclid.js') };
        vm.runInContext(kind === 'context' ? contextSource : loaderSource, sandbox,
            { filename: kind === 'context' ? 'bbf-context.js' : 'gclid.js' });
        doc.currentScript = null;
    }
    return {
        win, doc, store, forms, scripts, requests, listeners, execute,
        event(type, target) {
            (listeners.get(type) || []).forEach(listener => listener.callback({ target: target || doc }));
        },
        async start() { execute('context', options.src); await win.BBFContext.ready; return win.BBFContext; },
        async loaded(index = 0) {
            execute('context', scripts[index].src);
            scripts[index].onload();
            await win._bbfContextLoading;
        },
        values(names) { const target = form(names); win.BBFContext.fill(target); return data(target); }
    };
}

// Renderer-side coordination contract, modeled here without touching bbf.js.
function rendererLoad(page) {
    if (page.win.BBFContext) return page.win.BBFContext.ready;
    if (page.win._bbfContextLoading) return page.win._bbfContextLoading;
    const pending = deferred();
    page.win._bbfContextLoading = pending.promise;
    const script = page.doc.createElement('script');
    script.src = base + 'bbf-context.js';
    script.onload = () => page.win.BBFContext.ready.then(pending.resolve, pending.reject);
    script.onerror = () => { page.win._bbfContextLoading = null; pending.resolve(null); };
    page.doc.head.appendChild(script);
    return pending.promise;
}

test('duplicate parameters select last nonempty; only click IDs are trimmed', async () => {
    const page = browser({ url: 'https://example.test/?gclid=&gclid=FIRST&gclid=%20LAST%20&gclid=%20%20'
        + '&utm_campaign=first&utm_campaign=%20last%20&utm_campaign=&utm_term=%20%20&utm_term=' });
    await page.start();
    const result = page.values();
    assert.equal(result.gclid, 'LAST');
    assert.equal(result.utm_campaign, ' last ');
    assert.equal(result.utm_term, '  ');
    assert.equal(result.gbraid, '');
    assert.equal(page.store.values.size, 1);
    assert.equal(page.store.writes.length, 1);
});

test('snapshot survives multiple pages; new braid clears old click ID and every UTM', async () => {
    const shared = storage();
    const url = 'https://example.test/prices?gclid=OLD&utm_source=paid&utm_campaign=TESTCAMP';
    const first = browser({ storage: shared, url, referrer: 'https://search.test/result' });
    await first.start();
    const original = first.values();
    for (const url of ['https://example.test/about', 'https://example.test/form',
        'https://example.test/?utm_campaign=newsletter&gclid=%20%20']) {
        const next = browser({ storage: shared, url, referrer: 'https://example.test/other' });
        await next.start();
        assert.deepEqual(next.values(), original);
    }
    assert.equal(shared.writes.length, 1);
    const finalUrl = 'https://example.test/new?gbraid=NEW';
    const next = browser({ storage: shared, url: finalUrl, referrer: 'https://new.test/' });
    await next.start();
    const result = next.values();
    assert.equal(result.gbraid, 'NEW');
    assert.equal(result.gclid, '');
    assert.equal(result.wbraid, '');
    config.visit_context.params.forEach(name => assert.equal(result[name], ''));
    assert.equal(result.landing_url, finalUrl);
    assert.equal(result.referrer, 'https://new.test/');
    assert.match(result.touch_at, /^\d{4}-\d\d-\d\dT.*Z$/);
    assert.equal(shared.values.size, 1);
    assert.equal(shared.writes.length, 2);
});

test('organic and UTM-only first touch includes automatic fields', async () => {
    for (const suffix of ['', '?utm_campaign=organic']) {
        const page = browser({ url: 'https://example.test/' + suffix });
        await page.start();
        const result = page.values();
        assert.equal(result.landing_url, page.win.location.href);
        assert.equal(result.referrer, '');
        assert.equal(result.gclid, '');
        assert.equal(result.utm_campaign, suffix ? 'organic' : '');
        assert.ok(Number.isFinite(Date.parse(result.touch_at)));
    }
});

test('long special values and full landing URL round-trip without truncation', async () => {
    const value = '  ' + 'Long & = % ? + ž 漢 😀 '.repeat(90) + '  ';
    const url = 'https://example.test/?wbraid=' + encodeURIComponent(value)
        + '&utm_term=' + encodeURIComponent(value) + '#full-fragment';
    const page = browser({ url, referrer: 'https://search.test/?q=' + encodeURIComponent(value) });
    await page.start();
    assert.equal(page.values().utm_term, value);
    assert.equal(page.values().wbraid, value.trim());
    assert.equal(page.values().landing_url, url);
    assert.equal(page.values().referrer, page.doc.referrer);
    const next = browser({ storage: page.store });
    await next.start();
    assert.deepEqual(next.values(), page.values());
});

test('URL decoding distinguishes encoded plus from query-space', async () => {
    const page = browser({ url: 'https://example.test/?utm_term=a+b%2Bc%2520%26%3D%3F' });
    await page.start();
    assert.equal(page.values().utm_term, 'a b+c%20&=?');
});

test('all nonempty click IDs in one URL belong to the same atomic touch', async () => {
    const page = browser({ url: 'https://example.test/?gclid=ONE&gbraid=TWO&wbraid=THREE' });
    await page.start();
    assert.equal(page.values().gclid, 'ONE');
    assert.equal(page.values().gbraid, 'TWO');
    assert.equal(page.values().wbraid, 'THREE');
});

test('no legacy per-key storage import', async () => {
    const page = browser({ storage: storage({ gclid: 'STALE', utm_campaign: 'WRONG' }) });
    await page.start();
    assert.equal(page.values().gclid, '');
    assert.equal(page.values().utm_campaign, '');
    assert.equal(page.store.reads.length, 1);
    assert.match(page.store.reads[0], /^bbf:visit-context:v1:/);
    assert.equal(page.store.values.get('gclid'), 'STALE');
});

test('missing, malformed and incomplete JSON never produces a partial snapshot', async () => {
    for (const raw of [null, '{invalid', 'null', '[]', '42', '{}',
        '{"version":1,"fields":{"gclid":"STALE"}}',
        JSON.stringify({ version: 1, fields: Object.fromEntries(fieldNames.map(name => [name, ''])) })]) {
        const store = storage();
        store.getItem = function (key) { this.reads.push(key); return raw; };
        const page = browser({ storage: store, url: 'https://example.test/?utm_campaign=fresh' });
        await page.start();
        assert.equal(page.values().gclid, '');
        assert.equal(page.values().utm_campaign, 'fresh');
        assert.equal(store.writes.length, 1);
    }
});

test('denied storage getter, getItem and setItem retain a working memory snapshot', async () => {
    for (const mode of ['getter', 'read', 'write']) {
        const store = storage();
        if (mode === 'read') store.getItem = () => { throw new Error('blocked'); };
        if (mode === 'write') store.setItem = () => { throw new Error('quota'); };
        const page = browser({ storage: store, denyStorage: mode === 'getter',
            url: 'https://example.test/?gbraid=MEMORY&utm_campaign=works' });
        await page.start();
        assert.equal(page.values().gbraid, 'MEMORY');
        assert.equal(page.values().utm_campaign, 'works');
    }
});

test('failed snapshot write does not resurrect stale storage in synchronous fill', async () => {
    const store = storage();
    const old = browser({ storage: store, url: 'https://example.test/?gclid=OLD&utm_campaign=OLD' });
    await old.start();
    store.setItem = () => { throw new Error('quota'); };
    const next = browser({ storage: store, url: 'https://example.test/?wbraid=NEW' });
    await next.start();
    const reads = store.reads.length;
    assert.equal(next.values().gclid, '');
    assert.equal(next.values().utm_campaign, '');
    assert.equal(next.values().wbraid, 'NEW');
    assert.equal(store.reads.length, reads);
});

test('generic configuration and endpoint namespace isolate snapshots', async () => {
    const store = storage();
    const first = browser({ storage: store, url: 'https://example.test/?gclid=OLD' });
    await first.start();
    const generic = { visit_context: { trigger_params: ['entry'], params: ['campaign', 'entry'] } };
    const second = browser({ storage: store, config: generic,
        url: 'https://example.test/?entry=%20custom%20&campaign=summer' });
    await second.start();
    assert.deepEqual(second.values(['entry', 'campaign']), { entry: 'custom', campaign: 'summer' });
    const third = browser({ storage: store, src: 'https://example.test/another/bbf-context.js',
        url: 'https://example.test/plain' });
    await third.start();
    assert.equal(third.values().gclid, '');
    assert.equal(store.values.size, 3);
    assert.equal(third.requests[0].url, 'https://example.test/another/submit.php?action=context');
});

test('config order is normalized; unknown stored fields are not filled', async () => {
    const store = storage();
    const first = browser({ storage: store, url: 'https://example.test/?gclid=SAVED' });
    await first.start();
    const [key, raw] = [...store.values][0];
    const record = JSON.parse(raw);
    record.fields.unconfigured = 'INJECTED';
    store.values.set(key, JSON.stringify(record));
    const reversed = { visit_context: {
        trigger_params: [...config.visit_context.trigger_params].reverse(),
        params: [...config.visit_context.params].reverse()
    } };
    const second = browser({ storage: store, config: reversed });
    await second.start();
    assert.equal(second.values().gclid, 'SAVED');
    assert.equal(second.values(['unconfigured']).unconfigured, '');
    assert.equal(store.values.size, 1);
});

test('ready is immediate; fill before ready is synchronous and non-destructive', async () => {
    const wait = deferred();
    const target = form(['gclid'], 'LOCAL');
    const page = browser({ forms: [target], fetch: () => wait.promise,
        url: 'https://example.test/?gclid=CAPTURED' });
    page.execute('context');
    assert.equal(typeof page.win.BBFContext.fill, 'function');
    assert.equal(typeof page.win.BBFContext.ready.then, 'function');
    assert.equal(page.win.BBFContext.fill(target), undefined);
    assert.equal(data(target).gclid, 'LOCAL');
    page.event('DOMContentLoaded');
    page.win.location.href = 'https://example.test/changed?gclid=WRONG';
    wait.resolve({ ok: true, json: async () => config });
    await page.win.BBFContext.ready;
    assert.equal(data(target).gclid, 'CAPTURED');
});

test('DOM-ready, capture-submit and renderer fill update only matching hidden inputs', async () => {
    const page = browser({ url: 'https://example.test/?gbraid=NEW' });
    await page.start();
    const target = form(['gclid', 'gbraid', 'unconfigured'], 'LOCAL');
    const visible = { name: 'gbraid', type: 'text', value: 'VISIBLE' };
    target.inputs.push(visible);
    page.forms.push(target);
    page.event('DOMContentLoaded');
    assert.equal(target.inputs[0].value, '');
    assert.equal(target.inputs[1].value, 'NEW');
    assert.equal(target.inputs[2].value, 'LOCAL');
    assert.equal(visible.value, 'VISIBLE');
    target.inputs[0].value = 'STALE';
    target.inputs[1].value = '';
    assert.equal(page.listeners.get('submit')[0].capture, true);
    page.event('submit', target);
    assert.equal(target.inputs[0].value, '');
    assert.equal(target.inputs[1].value, 'NEW');
    const rendered = form();
    assert.equal(page.win.BBFContext.fill(rendered), undefined);
    // Models the exact synchronous boundary before the renderer creates FormData.
    assert.equal(data(rendered).gbraid, 'NEW');
    assert.equal(page.store.reads.length, 1);
    assert.equal(page.requests.length, 1);
});

test('empty configuration still collects automatic fields without filling URL params', async () => {
    const page = browser({ config: { visit_context: { trigger_params: [], params: [] } },
        url: 'https://example.test/?gclid=NOT-CONFIGURED' });
    await page.start();
    assert.equal(page.values().gclid, '');
    assert.equal(page.values().landing_url, page.win.location.href);
});

test('fetch failures and malformed config resolve ready without breaking form values', async () => {
    const cases = [
        () => Promise.reject(new Error('offline')),
        () => { throw new Error('fetch unavailable'); },
        async () => ({ ok: false }),
        async () => ({ ok: true, json: async () => { throw new Error('invalid JSON'); } }),
        ...[{}, { visit_context: {} }, { visit_context: { trigger_params: 'bad', params: [] } },
            { visit_context: { trigger_params: [], params: [null] } }]
            .map(value => async () => ({ ok: true, json: async () => value }))
    ];
    for (const fetch of cases) {
        const target = form(['gclid'], 'LOCAL');
        const page = browser({ fetch, forms: [target] });
        await page.start();
        page.event('submit', target);
        assert.equal(data(target).gclid, 'LOCAL');
        assert.equal(page.store.writes.length, 0);
    }
});

test('gclid loader captures pages without forms, uses its own path and deduplicates', async () => {
    const page = browser({ url: 'https://example.test/landing?gbraid=ORIGINAL', referrer: 'https://search.test/' });
    page.execute('loader', base + 'gclid.js?v=123#fragment');
    const pending = page.win._bbfContextLoading;
    page.execute('loader');
    assert.equal(page.scripts.length, 1);
    assert.equal(page.scripts[0].src, base + 'bbf-context.js');
    assert.equal(page.win._bbfContextLoading, pending);
    assert.equal(rendererLoad(page), pending);
    page.win.location.href = 'https://example.test/changed?gbraid=WRONG';
    page.doc.referrer = 'https://wrong.test/';
    await page.loaded();
    assert.equal(page.values().gbraid, 'ORIGINAL');
    assert.equal(page.values().landing_url, 'https://example.test/landing?gbraid=ORIGINAL');
    assert.equal(page.values().referrer, 'https://search.test/');
    assert.equal(await pending, page.win.BBFContext);
    assert.equal(page.requests[0].url, base + 'submit.php?action=context');
    assert.equal(page.requests[0].init.credentials, 'same-origin');
    assert.equal(page.store.writes.length, 1);
});

test('renderer-first loader race shares promise and preserves original page', async () => {
    const page = browser({ url: 'https://example.test/?gclid=FIRST' });
    const pending = rendererLoad(page);
    page.execute('loader');
    assert.equal(page.win._bbfContextLoading, pending);
    assert.equal(page.scripts.length, 1);
    page.win.location.href = 'https://example.test/changed';
    await page.loaded();
    await pending;
    assert.equal(page.values().gclid, 'FIRST');
    assert.equal(page.requests.length, 1);
});

test('duplicate direct module execution and loader during config fetch remain single-init', async () => {
    const wait = deferred();
    const page = browser({ fetch: () => wait.promise, url: 'https://example.test/?gclid=ONCE' });
    page.execute('context');
    const api = page.win.BBFContext;
    const pending = page.win._bbfContextLoading;
    page.execute('loader');
    page.execute('context');
    assert.equal(page.win.BBFContext, api);
    assert.equal(page.win._bbfContextLoading, pending);
    assert.equal(page.scripts.length, 0);
    wait.resolve({ ok: true, json: async () => config });
    await pending;
    assert.equal(page.requests.length, 1);
    assert.equal(page.store.writes.length, 1);
    assert.equal(page.listeners.get('submit').length, 1);
    page.execute('loader');
    assert.equal(page.scripts.length, 0);
});

test('loader script failure resolves safely and permits retry without losing landing URL', async () => {
    const page = browser({ url: 'https://example.test/?gclid=RETRY' });
    page.execute('loader');
    const failed = page.win._bbfContextLoading;
    page.scripts[0].onerror();
    assert.equal(await failed, null);
    assert.equal(page.win._bbfContextLoading, null);
    page.win.location.href = 'https://example.test/changed';
    page.execute('loader');
    assert.equal(page.scripts.length, 2);
    await page.loaded(1);
    assert.equal(page.values().gclid, 'RETRY');
});

(async function () {
    let passed = 0;
    for (const entry of tests) {
        try {
            await entry.run();
            console.log('PASS ' + entry.name);
            passed++;
        } catch (error) {
            console.error('FAIL ' + entry.name);
            throw error;
        }
    }
    console.log(passed + '/' + tests.length + ' visit-context tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
