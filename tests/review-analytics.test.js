'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { loadBBF } = require('./review-renderer.test.js');
const adapter = fs.readFileSync(path.join(__dirname, '..', 'bbf-analytics.js'), 'utf8');

function tracker(track) {
    const listeners = [];
    const context = vm.createContext({ window: { umami: track ? { track } : undefined }, document: {
        addEventListener: (name, callback) => { assert.equal(name, 'bbf:submitted'); listeners.push(callback); }
    } });
    vm.runInContext(adapter, context);
    vm.runInContext(adapter, context);
    assert.equal(listeners.length, 1, 'adapter installs exactly once');
    return (detail, enabled = true) => listeners[0]({ detail, bbfAnalytics: { umami: enabled } });
}

test('adapter sends exact deterministic join keys through existing tracker only', () => {
    const sent = [];
    const emit = tracker((name, data) => sent.push({ name, ...data }));
    emit({ form: 'quote-en', submission_id: 'bbf_fixture' });
    assert.deepEqual(sent, [{ name: 'form_submitted', form: 'quote-en', submission_id: 'bbf_fixture' }]);
    emit({ form: 'quote-en' }); emit({ form: 'disabled', submission_id: 'bbf_disabled' }, false);
    assert.equal(sent.length, 1);
});

test('missing, throwing and rejected trackers cannot break submission events', async () => {
    const detail = { form: 'quote-en', submission_id: 'bbf_fixture' };
    assert.doesNotThrow(() => tracker()(detail));
    assert.doesNotThrow(() => tracker(() => { throw new Error('blocked'); })(detail));
    assert.doesNotThrow(() => tracker(() => Promise.reject(new Error('blocked')))(detail));
    await new Promise(resolve => setImmediate(resolve));
});

async function submit(result, httpOk = true, options = {}, setup = () => {}, timers = []) {
    const events = [];
    const runtime = loadBBF({ fetch: async () => ({ ok: httpOk, headers: { get: () => 'application/json' }, json: async () => result }),
        setTimeout: fn => { timers.push(fn); return fn; }, clearTimeout: fn => { const i = timers.indexOf(fn); if (i >= 0) timers.splice(i, 1); } });
    const { BBF, document, context } = runtime;
    context.window.location = context.location;
    context.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options.detail; } };
    document.dispatchEvent = event => { events.push(JSON.parse(JSON.stringify(event))); return true; };
    setup(runtime, events);
    const form = BBF._buildForm({ fields: [{ name: 'answer', type: 'text' }], _bbf_client: { analytics: options.analytics || {} } }, 'quote-en', 'https://example.test/', options, null, null, true);
    form.reset = () => {};
    await form.listeners.submit[0]({ preventDefault() {} });
    return { events, form, context };
}

test('successful HTTP submit emits once before custom success callback and redirect', async () => {
    let callbackSawEvent = false;
    let observed = [];
    const { events, context } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: '/thanks' }, true,
        { onSuccess: () => { callbackSawEvent = observed.length === 1; } }, (_, events) => { observed = events; });
    assert.deepEqual(events, [{ type: 'bbf:submitted', detail: { form: 'quote-en', submission_id: 'bbf_fixture' }, bbfAnalytics: {} }]);
    assert.equal(callbackSawEvent, true);
    assert.equal(context.window.location.href, '/thanks');
});

test('redirect only follows http(s) or relative targets, never script URLs', async () => {
    for (const bad of ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'java\tscript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x']) {
        const { context, form } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: bad });
        assert.notEqual(context.window.location.href, bad, 'blocked: ' + JSON.stringify(bad));
        assert.match(form.querySelector('.bbf-message')?.className || 'bbf-success', /bbf-success/);
    }
    for (const good of ['https://example.test/thanks?x=1', '/thanks', 'thanks.html']) {
        const { context } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: good });
        assert.equal(context.window.location.href, good);
    }
});

test('redirect resets stored answers before navigation, so Back cannot resubmit them', async () => {
    let resets = 0;
    const originalKey = 'key-of-the-stored-submission';
    const { context, form } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: '/thanks' }, true, {}, runtime => {
        const build = runtime.BBF._buildForm.bind(runtime.BBF);
        runtime.BBF._buildForm = (...args) => {
            const form = build(...args);
            form._bbfSubmitKey = originalKey;
            runtime.BBF._resetCustomFields = () => { resets++; };
            return form;
        };
    });
    assert.equal(context.window.location.href, '/thanks');
    assert.equal(form.querySelector('.bbf-submit').disabled, false);
    assert.equal(form._bbfSubmitting, false);
    assert.match(form.querySelector('.bbf-message').className, /bbf-success/);
    assert.equal(resets, 1);
    assert.notEqual(form._bbfSubmitKey, originalKey);
    assert.equal((context.window.listeners.pageshow || []).length, 1, 'only the standard idempotency-key listener remains');
});

test('2.1.6 a redirect that never leaves the page (#done, 204) does not leave the button disabled', async () => {
    let pageUrl = '';
    const hash = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: '#done' }, true, {}, runtime => { pageUrl = String(runtime.context.location.href).split('#')[0]; });
    assert.equal(hash.context.window.location.href, pageUrl + '#done',
        'the page still jumps to the anchor; review 2.1.6: as an absolute URL, so a <base href> (SPA) cannot send it to another page');
    assert.equal(hash.form.querySelector('.bbf-submit').disabled, false, '#done on this page: the button is usable again');
    assert.match(hash.form.querySelector('.bbf-message').className, /bbf-success/);
    const sameUrl = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: 'https://example.test/demo.html#thanks' });
    assert.equal(sameUrl.form.querySelector('.bbf-submit').disabled, false, 'an absolute URL of this page with an anchor counts too');

    const timers = [];
    const { context, form } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: '/no-content' }, true, {}, () => {}, timers);
    const btn = form.querySelector('.bbf-submit');
    assert.equal(btn.disabled, false, 'the stored submission is complete even if navigation never leaves');
    assert.equal(timers.length, 0, 'no five-second guess about redirect completion');
    assert.match(form.querySelector('.bbf-message').className, /bbf-success/);

    const leave = [];
    const left = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect: '/thanks' }, true, {}, () => {}, leave);
    [...(left.context.window.listeners.pagehide || [])].forEach(fn => fn({}));
    assert.equal(leave.length, 0, 'once the page really unloads, the fallback is cancelled');
    assert.equal(left.form.querySelector('.bbf-submit').disabled, false);
});

test('hideOnSuccess applies before every redirect, including slow targets and downloads', async () => {
    for (const redirect of ['/thanks', '#done']) {
        const timers = [];
        const { form } = await submit({ status: 'ok', submission_id: 'bbf_fixture', redirect }, true, { hideOnSuccess: true }, () => {}, timers);
        assert.equal(form._bbfHideOnSuccess, true);
        assert.ok(form.querySelectorAll('.bbf-field, .bbf-submit-wrap').every(field => field.style.display === 'none'));
        assert.equal(form.querySelector('.bbf-message').style.display, 'block');
        assert.equal(timers.length, 0);
    }
});

test('form id is URL-encoded in every submit.php request', async () => {
    const urls = [];
    const runtime = loadBBF({ fetch: async url => { urls.push(String(url)); return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ status: 'ok', submission_id: 'bbf_x' }) }; } });
    runtime.context.window.location = runtime.context.location;
    const form = runtime.BBF._buildForm({ fields: [{ name: 'answer', type: 'text' }] }, 'a&b=c', 'https://example.test/', {}, null, null, true);
    form.reset = () => {};
    await form.listeners.submit[0]({ preventDefault() {} });
    assert.ok(urls.length > 0 && urls.every(u => !u.includes('form=a&b') && u.includes('form=a%26b%3Dc')), urls.join(' '));
    const source = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8');
    assert.doesNotMatch(source, /\?form=\$\{formId\}/, 'no unencoded form id left in bbf.js URLs');
});

test('onSuccess false does not suppress the stored-submission hook', async () => {
    const { events } = await submit({ status: 'ok', submission_id: 'bbf_fixture' }, true, { onSuccess: () => false });
    assert.equal(events.length, 1); const enabled = await submit({ status: 'ok', submission_id: 'bbf_enabled' }, true, { onSuccess: () => false, analytics: { umami: true } }); const disabled = await submit({ status: 'ok', submission_id: 'bbf_disabled' }, true, { onSuccess: () => false, analytics: { umami: false } }); const sent = []; const emit = tracker((name, data) => sent.push(data.form)); emit(enabled.events[0].detail, enabled.events[0].bbfAnalytics.umami); emit(disabled.events[0].detail, disabled.events[0].bbfAnalytics.umami); assert.deepEqual(sent, ['quote-en']);
});

test('sandbox, failed HTTP, rejected input and missing ID never emit success', async () => {
    for (const [result, ok] of [
        [{ status: 'ok', sandbox: true, submission_id: 'bbf_test' }, true],
        [{ status: 'ok', submission_id: 'bbf_test' }, false],
        [{ status: 'error', submission_id: 'bbf_test' }, true],
        [{ status: 'ok' }, true],
    ]) {
        const { events } = await submit(result, ok);
        assert.equal(events.length, 0);
    }
});

test('listener failure cannot change success UI or prevent callback', async () => {
    let called = false;
    await submit({ status: 'ok', submission_id: 'bbf_fixture' }, true, { onSuccess: () => { called = true; return false; } },
        ({ document }) => { document.dispatchEvent = () => { throw new Error('external listener'); }; });
    assert.equal(called, true);
});

test('context is filled before FormData serialization', async () => {
    let filled = false;
    let body;
    const { BBF, context } = loadBBF({
        FormData: class { constructor() { assert.equal(filled, true); } forEach(callback) { callback('BRAID', 'gbraid'); } },
        fetch: async (url, request) => { body = JSON.parse(request.body); return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ status: 'ok' }) }; }
    });
    context.window.location = context.location;
    context.window.BBFContext = { ready: Promise.resolve(), fill: () => { filled = true; } };
    const form = BBF._buildForm({ fields: [{ name: 'gbraid', type: 'hidden' }] }, 'quote-en', 'https://example.test/', { onSuccess: () => false }, null, null, true);
    await form.listeners.submit[0]({ preventDefault() {} });
    assert.deepEqual(body, { gbraid: 'BRAID' });
});
