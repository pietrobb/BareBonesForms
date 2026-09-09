'use strict';

// G2 rendering boundary, NOT HTTP E2E. Run: node --test tests/review-access-ui.test.js
// Executes the actual viewer script/handlers in isolated file-based Chromium with
// substituted PHP bootstrap values. review-access-core-test.php covers server-side
// scope filtering, authorization, CSRF, expiry/revocation and audit. In particular,
// supplying stale/unauthorized rows here tests operation controls, NOT data secrecy.
// No DOM mocks, new dependencies, HTTP server, real mail, export or deletion.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');

const source = fs.readFileSync(path.join(__dirname, '..', 'viewer.php'), 'utf8');
const forms = [
    { id: 'alpha', name: 'Alpha applications', count: 2 },
    { id: 'beta', name: 'Beta applications', count: 2 },
];
const scoped = permissions => ({ admin: false, storage: true,
    forms: permissions.includes('read') ? ['alpha', 'orphan'] : [], permissions });
// Explicit expectations do not call/reimplement viewer.canOperate. Admin uses
// empty forms/permissions to prove its override; storage still disallows delete.
const profiles = [
    { name: 'read-only', access: scoped(['read']), csv: false, del: false, print: false },
    { name: 'read-export', access: scoped(['read', 'export']), csv: true, del: false, print: true },
    { name: 'read-delete', access: scoped(['read', 'delete']), csv: false, del: true, print: false },
    { name: 'admin', access: { admin: true, storage: true, forms: [], permissions: [] },
        form: 'beta', csv: true, del: true, print: true },
    { name: 'disallowed form', access: scoped(['read', 'export', 'delete']),
        form: 'beta', csv: false, del: false, print: false },
    { name: 'no privileges', access: scoped([]), csv: false, del: false, print: false },
    { name: 'export without read', access: scoped(['export']), csv: false, del: false, print: false },
    { name: 'delete without read', access: scoped(['delete']), csv: false, del: false, print: false },
    { name: 'admin CSV storage', access: { admin: true, storage: false, forms: [], permissions: [] },
        csv: true, del: false, print: true },
];

function browserExecutable() {
    const candidates = [process.env.CHROME_BIN, process.env.CHROMIUM_BIN,
        process.env.PROGRAMFILES && path.join(process.env.PROGRAMFILES, 'Google/Chrome/Application/chrome.exe'),
        process.env['PROGRAMFILES(X86)'] && path.join(process.env['PROGRAMFILES(X86)'], 'Microsoft/Edge/Application/msedge.exe'),
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'];
    const executable = candidates.find(candidate => candidate && fs.existsSync(candidate));
    assert.ok(executable, 'Chrome/Chromium required: set CHROME_BIN; never silently skip browser coverage');
    return executable;
}

async function browserChecks(profile) {
    const results = [];
    const check = (name, fn) => {
        try { fn(); results.push({ name, ok: true }); }
        catch (error) { results.push({ name, ok: false, error: error.message }); }
    };
    const equal = (actual, expected, label) => {
        if (actual !== expected) throw new Error(`${label}: ${JSON.stringify({ actual, expected })}`);
    };
    const v = window.viewerAccessUI;
    const panel = document.getElementById('panel-main');
    const count = selector => panel.querySelectorAll(selector).length;
    const button = (selector, allowed) => {
        equal(count(selector), allowed ? 1 : 0, selector);
        if (allowed) {
            const el = panel.querySelector(selector);
            equal(el.tagName, 'BUTTON', selector + ' element');
            equal(el.disabled, false, selector + ' enabled');
            equal(el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden',
                true, selector + ' visible');
        }
    };
    const formId = profile.form || 'alpha';
    const formDef = { id: formId, name: formId === 'alpha' ? 'Alpha applications' : 'Beta applications',
        fields: [{ name: 'answer', label: 'Answer', type: 'text' },
            { name: 'email', label: 'Email', type: 'email' }] };
    const subs = [1, 2].map(n => ({ id: 'bbf_one_' + n, form: formId,
        data: { answer: `Application ${n}`, email: `person${n}@example.invalid` },
        meta: { submitted: '2026-09-08T10:00:00Z', ip: '192.0.2.1', user_agent: 'Fixture browser' } }));
    const reset = mode => Object.assign(v.state, { view: 'form', formId, formDef,
        labelMap: { answer: 'Answer', email: 'Email' }, subs, total: 2, page: 1,
        stats: { total: 2, today: 0, this_week: 2, this_month: 2 },
        selected: new Set(), detail: null, viewMode: mode });

    check('real DOM control (not sanitizing mocks)', () => {
        const el = document.createElement('div');
        el.textContent = '"';
        equal(el.innerHTML, '"', 'native text serialization');
        el.innerHTML = '<button onclick="window.__nativeClick=true">control</button>';
        el.firstChild.click();
        equal(window.__nativeClick, true, 'native inline event dispatch');
    });
    check('server-filtered FORMS bootstrap renders sidebar', () => {
        const actual = [...document.querySelectorAll('#form-list .form-item')].map(el => el.dataset.id);
        equal(JSON.stringify(actual), JSON.stringify(profile.forms.map(form => form.id)), 'sidebar forms');
        for (const form of profile.forms) {
            equal(document.getElementById('form-list').textContent.includes(form.name), true, 'form name');
        }
    });
    check('table and toolbar render records with permission-aware CSV and selection', () => {
        reset('table'); v.renderMain();
        equal(count('.sub-table tbody tr'), 2, 'real table rows');
        equal(panel.querySelector('td[title]').textContent, 'Application 1', 'real response cell');
        button('#btn-filter', true);
        button('#btn-export', profile.csv);
        equal(count('input[type="checkbox"]'), profile.del ? 3 : 0, 'table checkboxes');
        equal(count('#select-all'), profile.del ? 1 : 0, 'select-all');
        equal(count('.cb-cell'), profile.del ? 3 : 0, 'checkbox cells');
        button('#bulk-delete', false);
    });
    check('preselected table cannot manufacture unauthorized bulk actions', () => {
        reset('table'); v.state.selected = new Set(subs.map(sub => sub.id)); v.renderMain();
        button('#bulk-delete', profile.del);
        button('#bulk-cancel', profile.del);
        equal(count('#bulk-bar'), profile.del ? 1 : 0, 'bulk bar');
        equal(count('input[type="checkbox"]:checked'), profile.del ? 3 : 0, 'selected checkboxes');
        button('#btn-export', profile.csv);
    });
    check('actual selection events update and clear bulk controls', () => {
        reset('table'); v.renderMain();
        if (profile.del) {
            panel.querySelector('#select-all').click();
            equal(v.state.selected.size, 2, 'select-all handler');
            button('#bulk-delete', true);
            equal(panel.querySelector('.bulk-bar-count').textContent, '2 selected', 'selection count');
            panel.querySelector('#bulk-cancel').click();
            equal(v.state.selected.size, 0, 'deselect handler');
            button('#bulk-delete', false);
            equal(count('input:checked'), 0, 'deselected DOM');
        } else {
            // Exercise updateBulkBar independently of renderMain, including its
            // remove-existing branch, with stale selection left by a prior route.
            v.state.selected.add(subs[0].id);
            const staleBar = document.createElement('div');
            staleBar.id = 'bulk-bar'; panel.prepend(staleBar);
            v.updateBulkBar();
            equal(count('#bulk-bar, #bulk-delete, input[type="checkbox"]'), 0, 'no stale bulk controls');
        }
    });
    check('real view toggle renders cards with permission-aware selection', () => {
        reset('table'); v.renderMain();
        panel.querySelector('[data-mode="cards"]').click();
        equal(v.state.viewMode, 'cards', 'view toggle handler');
        equal(count('.grid-card'), 2, 'rendered cards');
        equal(count('input[type="checkbox"]'), profile.del ? 2 : 0, 'card checkboxes');
        button('#btn-export', profile.csv);
        if (profile.del) {
            panel.querySelector('.sub-checkbox').click();
            equal(v.state.selected.has(subs[0].id), true, 'individual selection handler');
            equal(count('.grid-card.selected'), 1, 'selected card');
            button('#bulk-delete', true);
        } else {
            v.state.selected.add(subs[0].id); v.renderMain();
            equal(count('#bulk-bar, #bulk-delete, #bulk-cancel, input[type="checkbox"]'), 0,
                'read-only/stale card has no deletion controls');
        }
    });
    check('detail content, forward and delete operations render correctly', () => {
        reset('table'); v.renderDetailView(subs[0], formDef);
        equal(panel.querySelector('.detail-value').textContent, 'Application 1', 'detail response');
        equal(count('.btn-copy'), 2, 'copy controls');
        button('#btn-back', true);
        button('#btn-forward', profile.csv);
        button('#btn-del', profile.del);
        equal(count('#bulk-delete, input[type="checkbox"]'), 0, 'no detail bulk controls');
        if (profile.csv) {
            panel.querySelector('#btn-forward').click();
            equal(document.querySelectorAll('.modal-overlay').length, 1, 'forward handler opens modal');
            equal(document.getElementById('fwd-to').type, 'email', 'forward recipient');
            equal(document.getElementById('fwd-send').disabled, false, 'send is available');
            document.getElementById('fwd-cancel').click();
            equal(document.querySelectorAll('.modal-overlay').length, 0, 'cancel closes modal');
        }
        if (profile.del) {
            const del = panel.querySelector('#btn-del');
            equal(del.dataset.id, subs[0].id, 'delete target');
            del.click(); // First click only: confirmation, never deletion.
            equal(del.classList.contains('confirm'), true, 'delete confirmation handler');
            clearTimeout(v.state.deleteTimer);
        }
    });
    const deliveryCheck = { name: 'safe delivery panel and controlled retry behavior', ok: true };
    const deliveryNetworkGuard = window.fetch;
    try {
        const hostile = '<img src=x onerror=alert(1)>';
        const delivery = { state: 'attention_required', settled: false, jobs: [{ key: 'action:0', type: hostile,
            state: 'ambiguous', attempts: 1, max_attempts: 3, next_retry: 1893456000,
            last_result: { message: hostile, stage: 'action', code: 503, retryable: false, at: 1 },
            can_retry: profile.access.admin, requires_confirmation: true }] };
        const detailCalls = [];
        window.fetch = async url => {
            detailCalls.push(url);
            return { ok: true, async json() { return { submission: subs[0], form_def: formDef, delivery }; } };
        };
        reset('cards'); v.renderMain();
        panel.querySelector('.grid-card').click();
        for (let i = 0; i < 12; i++) await Promise.resolve();
        equal(detailCalls.length, 1, 'click detail issues one request');
        equal(count('.delivery-card'), 1, 'click detail renders response delivery panel');
        reset('table'); v.renderDetailView(subs[0], formDef, delivery);
        equal(count('.delivery-card'), 1, 'delivery landmark');
        equal(panel.querySelector('.delivery-card').getAttribute('aria-labelledby'), 'delivery-title', 'accessible delivery heading');
        equal(count('.delivery-card img, .delivery-card script'), 0, 'delivery values escaped');
        equal(panel.querySelector('.delivery-card').textContent.includes(hostile), true, 'safe result shown as text');
        button('.btn-retry-delivery', profile.access.admin);
        if (profile.access.admin) {
            const calls = []; let settle;
            window.fetch = (url, options) => { calls.push({ url, options }); return new Promise(resolve => { settle = resolve; }); };
            const retry = panel.querySelector('.btn-retry-delivery');
            retry.click();
            equal(calls.length, 0, 'ambiguous retry blocked without explicit confirmation');
            equal(document.activeElement.id, 'delivery-confirm-0', 'confirmation receives focus');
            panel.querySelector('#delivery-confirm-0').click();
            retry.click();
            equal(calls.length, 1, 'one job retry request');
            equal(calls[0].url, 'viewer.php?action=retry_delivery', 'retry endpoint');
            equal(retry.disabled, true, 'retry disabled in flight');
            equal(JSON.stringify(JSON.parse(calls[0].options.body)), JSON.stringify({ form: formId, id: subs[0].id,
                job: 'action:0', confirm_ambiguous: true }), 'exact retry body');
            equal(calls[0].options.headers['X-BBF-Viewer-Token'], 'isolated-csrf-not-a-credential', 'session CSRF header');
            settle({ ok: true, async json() { return { ok: true, delivery: { state: 'succeeded', settled: true,
                jobs: [{ ...delivery.jobs[0], state: 'succeeded', attempts: 2, can_retry: false }] } }; } });
            for (let i = 0; i < 12; i++) await Promise.resolve();
            equal(panel.querySelector('.delivery-summary').textContent.includes('succeeded'), true, 'returned status refreshes panel');
            equal(count('.btn-retry-delivery'), 0, 'updated can_retry removes button');
        }
    } catch (error) { deliveryCheck.ok = false; deliveryCheck.error = error.message; }
    finally { window.fetch = deliveryNetworkGuard; }
    results.push(deliveryCheck);

    // Explicit fetch RESPONSE STUBS, not HTTP coverage or simulated server authorization.
    // Real endpoint decisions/audit are exercised by review-access-core-test.php.
    const printCheck = { name: 'built-in Print/PDF awaits fresh authorization and never falls back to cache', ok: true };
    const networkGuard = window.fetch;
    try {
        for (const outcome of ['current', 'revoke', 'expire', 'remove-export', 'audit-failure', 'missing', 'network', 'malformed', 'wrong-id', 'wrong-form', 'closed', 'blocked']) {
            reset('table'); v.renderDetailView(subs[0], formDef); // Already authorized/disclosed detail.
            const calls = []; let settle; let popup = null; let prints = 0; let opened = false;
            window.fetch = (url, options) => {
                calls.push({ url, options });
                return new Promise((resolve, reject) => { settle = { resolve, reject }; });
            };
            window.open = () => {
                opened = true;
                if (outcome === 'blocked') return null;
                const doc = document.implementation.createHTMLDocument('Blank print fixture');
                popup = { document: doc, closed: false, opener: window, focus() {},
                    close() { this.closed = true; }, print() { prints++; } };
                return popup;
            };
            let control = panel.querySelector('#btn-print');
            if (!profile.print) {
                equal(control, null, 'forbidden print has no button');
                control = document.createElement('button'); control.id = 'btn-print'; panel.append(control);
            }
            control.click(); // Actual built-in delegated handler, not a direct helper invocation.
            if (!profile.print || outcome === 'blocked') {
                equal(calls.length, 0, 'no export request for denied UI or blocked popup');
                equal(prints, 0, 'no print');
                equal(opened, profile.print, 'no popup for cached denial');
                continue;
            }
            equal(opened, true, 'popup opened synchronously during click');
            equal(calls.length, 1, 'one fresh request per click');
            equal(calls[0].url, `viewer.php?action=print&form=${encodeURIComponent(formId)}&id=${encodeURIComponent(subs[0].id)}`, 'dedicated print endpoint and exact target');
            equal(calls[0].options.cache, 'no-store', 'no cached HTTP response');
            equal(popup.document.body.textContent, '', 'no cached content while authorization pending');
            equal(prints, 0, 'never print before authorization');
            if (outcome === 'closed') popup.close();
            const fresh = { submission: { ...subs[0], data: { answer: 'Fresh server response' } },
                form_def: { ...formDef, name: 'Fresh server definition' } };
            if (outcome === 'wrong-id') fresh.submission.id = 'another-id';
            if (outcome === 'wrong-form') fresh.submission.form = 'another-form';
            if (outcome === 'network') settle.reject(new Error('Explicit offline response stub'));
            else {
                const status = ['revoke', 'expire', 'remove-export'].includes(outcome) ? 403
                    : outcome === 'audit-failure' ? 503 : outcome === 'missing' ? 404 : 200;
                settle.resolve({ ok: status === 200, status,
                    async json() { if (outcome === 'malformed') throw new SyntaxError('Invalid JSON stub'); return fresh; } });
            }
            // Drain handler await continuations without timers or a real network.
            for (let i = 0; i < 12; i++) await Promise.resolve();
            equal(prints, outcome === 'current' ? 1 : 0, outcome + ' actual dialog invocations');
            equal(popup.closed, true, outcome + ' no leaked popup');
            if (outcome === 'current') {
                equal(popup.document.querySelector('td + td').textContent, 'Fresh server response', 'fresh not cached record');
                equal(popup.document.querySelector('h1').textContent, 'Fresh server definition', 'fresh not cached definition');
                equal(popup.opener, null, 'popup opener detached');
            } else {
                equal(popup.document.body.textContent, '', outcome + ' no output, even if denied response contains data');
                equal(document.getElementById('header-status').textContent.length > 0 || outcome === 'closed', true, 'failure visible');
            }
        }
    } catch (error) { printCheck.ok = false; printCheck.error = error.message; }
    finally { window.fetch = networkGuard; }
    results.push(printCheck);
    check('no networking or uncaught browser errors', () => {
        equal(JSON.stringify(window.__accessUnexpected), '[]', 'unexpected activity');
    });
    const output = document.createElement('pre');
    output.id = 'access-ui-result';
    output.textContent = JSON.stringify(results);
    document.body.appendChild(output);
}

for (const fixture of profiles) {
    test('viewer access UI in Chromium: ' + fixture.name, () => {
        const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-viewer-access-ui-'));
        try {
            const profile = { ...fixture, forms: fixture.access.admin ? forms
                : forms.filter(form => fixture.access.forms.includes(form.id)) };
            const start = source.indexOf('<script>');
            const end = source.lastIndexOf('</script>');
            assert.ok(start >= 0 && end > start, 'actual viewer script exists');
            let script = source.slice(start + 8, end);
            const values = { formsList: profile.forms, viewerToken: 'isolated-csrf-not-a-credential',
                canDelete: profile.access, siteName: 'Isolated viewer', viewerLang: 'en' };
            for (const [name, value] of Object.entries(values)) {
                const marker = '<?= json_encode($' + name + ') ?>';
                assert.ok(script.includes(marker), 'bootstrap marker: ' + name);
                script = script.replaceAll(marker, JSON.stringify(value));
            }
            const init = 'renderFormList(FORMS);\nrestoreRoute();';
            assert.ok(script.includes(init), 'replace only automatic HTTP route loading');
            script = script.replace(init, `renderFormList(FORMS);
                window.viewerAccessUI = { state, renderMain, renderDetailView, updateBulkBar };`);
            assert.ok(!script.includes('<?'), 'all PHP bootstrap values replaced');
            const css = source.match(/<style>([\s\S]*?)<\/style>/)?.[1];
            assert.ok(css, 'actual viewer styles included for visible-control assertions');
            const inline = text => text.replace(/<\/script/gi, '<\\/script');
            const ids = ['form-list', 'panel-main', 'panel-forms', 'header-status', 'header-title',
                'drawer-backdrop', 'btn-hamburger'];
            const guard = `window.__accessUnexpected = [];
                window.addEventListener('error', e => window.__accessUnexpected.push(e.message));
                window.addEventListener('unhandledrejection', e => window.__accessUnexpected.push(String(e.reason)));
                window.fetch = (...args) => {
                    window.__accessUnexpected.push('fetch: ' + args[0]);
                    throw new Error('Network forbidden in rendering-boundary test');
                };`;
            const page = path.join(temporary, 'access.html');
            fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
                + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; '
                + 'script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'; form-action \'none\'">'
                + '<style>' + css + '</style>' + ids.map(id => `<div id="${id}"></div>`).join('')
                + '<script>' + inline(guard) + '</script><script>' + inline(script) + '</script>'
                + '<script>(' + inline(browserChecks.toString()) + ')(' + JSON.stringify(profile) + ');</script>');
            const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                '--disable-background-networking', '--disable-component-update', '--disable-sync',
                '--disable-extensions', '--host-resolver-rules=MAP * ~NOTFOUND',
                '--user-data-dir=' + path.join(temporary, 'profile'), '--dump-dom', pathToFileURL(page).href];
            if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
            const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
                maxBuffer: 4 * 1024 * 1024, windowsHide: true });
            assert.ifError(result.error);
            assert.equal(result.status, 0, result.stderr);
            const encoded = result.stdout.match(/<pre id="access-ui-result">([\s\S]*?)<\/pre>/)?.[1];
            assert.ok(encoded, 'Browser did not complete assertions: ' + result.stderr);
            const checks = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
            assert.equal(checks.length, 10, 'all rendering checks executed');
            const failures = checks.filter(check => !check.ok);
            assert.equal(failures.length, 0, JSON.stringify({ profile: fixture.name,
                passed: checks.length - failures.length, failures }, null, 2));
        } finally {
            fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
        }
    });
}
