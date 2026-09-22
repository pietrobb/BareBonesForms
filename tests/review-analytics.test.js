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

async function submit(result, httpOk = true, options = {}, setup = () => {}) {
    const events = [];
    const runtime = loadBBF({ fetch: async () => ({ ok: httpOk, headers: { get: () => 'application/json' }, json: async () => result }) });
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
