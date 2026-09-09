'use strict';

// Run with: node --test tests/editor-save.test.js
// Execute the actual editor script; PHP is never executed and fetch is fully mocked.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'editor.php'), 'utf8');
const script = [...php.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).find(text => text.includes('const INITIAL_FORM ='));
assert.ok(script, 'Extract the actual editor UI script');
const flush = () => new Promise(resolve => setImmediate(resolve));
const json = name => JSON.stringify({ name, fields: [] }, null, 2);

function harness({ readOnly = false } = {}) {
    const elements = new Map();
    const requests = [];
    const confirmations = [];
    const timers = new Map();
    let timerId = 0;
    let confirmAnswer = true;
    function element() {
        const listeners = new Map();
        let html = '';
        let text = '';
        return {
            value: '', disabled: false, style: {}, dataset: {},
            selectionStart: 0, selectionEnd: 0,
            classList: { add() {}, remove() {}, toggle() {} },
            get innerHTML() { return html; },
            set innerHTML(value) { html = String(value); },
            get options() {
                return [...html.matchAll(/<option value="([^"]*)"/g)].map(match => ({ value: match[1] }));
            },
            setAttribute() {},
            get textContent() { return text; },
            set textContent(value) {
                text = String(value);
                html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            },
            addEventListener(type, handler) {
                if (!listeners.has(type)) listeners.set(type, []);
                listeners.get(type).push(handler);
            },
            removeEventListener() {}, querySelectorAll() { return []; }, focus() {},
            emit(type, event = {}) {
                return Promise.all((listeners.get(type) || []).map(handler => handler(event)));
            },
            contentWindow: { postMessage() {} },
        };
    }
    function get(selector) {
        if (!elements.has(selector)) elements.set(selector, element());
        return elements.get(selector);
    }
    const document = {
        querySelector: get, getElementById: id => get('#' + id),
        createElement: element, addEventListener() {}, removeEventListener() {}, title: '',
    };
    const window = element();
    const context = vm.createContext({
        document, window, location: { origin: 'https://editor.invalid' },
        localStorage: { getItem() { return null; }, setItem() {} },
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        confirm(message) { confirmations.push(message); return confirmAnswer; },
        fetch(url, options = {}) {
            return new Promise((resolve, reject) => {
                requests.push({
                    url, options, action: new URL(url, 'https://editor.invalid').searchParams.get('action'),
                    settled: false,
                    respond(body, ok = true, status = ok ? 200 : 500) {
                        this.settled = true;
                        resolve({ ok, status, json: async () => body, text: async () => body });
                    },
                    fail(message) { this.settled = true; reject(new Error(message)); },
                });
            });
        },
    });
    const injected = script
        .replace(/<\?= json_encode\(\$formsList\) \?>/, '[]')
        .replace(/<\?= json_encode\(\$selectedFormId\) \?>/, '""')
        .replace(/<\?= json_encode\(\$editorToken\) \?>/, '"test-token"')
        .replace(/<\?= json_encode\(\$isReadOnly\) \?>/, String(readOnly))
        .replace(/\}\)\(\);\s*$/, 'globalThis.editorTest = { state, loadForm, saveForm, publishForm, rollbackForm, validate };\n})();');
    assert.ok(!injected.includes('<?'), 'All PHP injections are replaced with fixtures');
    vm.runInContext(injected, context, { filename: 'editor.php:editor-script' });
    const editor = context.editorTest;
    function next(action, newest = false) {
        const pending = requests.filter(r => !r.settled && r.action === action);
        const request = newest ? pending.at(-1) : pending[0];
        assert.ok(request, 'Expected pending ' + action + ' request');
        return request;
    }
    function respondLoad(id, text = json(id), revision = 0) {
        const version = 'v' + revision;
        next('state', true).respond({ form: id, revision, published_version: version,
            draft_version: version, definition: JSON.parse(text) });
        next('history', true).respond({ history: [{ revision, version, action: 'bootstrap', at: '2026-09-09T00:00:00Z' }] });
    }
    async function load(id, text = json(id), revision = 0) {
        const pending = editor.loadForm(id);
        respondLoad(id, text, revision);
        await pending;
        // loadForm also triggers ordinary background validation.
        next('validate').respond({ valid: true, errors: [] });
        await flush();
    }
    function edit(text) {
        get('#editor-textarea').value = text;
        return get('#editor-textarea').emit('input');
    }
    async function validateSave(result = { valid: true, errors: [] }) {
        next('validate').respond(result);
        await flush();
    }
    async function finishSave(revision = 1) {
        next('save').respond({ ok: true, revision, published_version: 'v0', draft_version: 'v' + revision });
        await flush();
        next('list').respond([]);
        next('history').respond({ history: [{ revision, version: 'v' + revision, action: 'draft', at: '2026-09-09T00:00:00Z' }] });
        await flush();
    }
    return {
        ...editor, get, document, window, requests, confirmations, next, load, respondLoad, edit,
        validateSave, finishSave,
        clickSave: () => get('#btn-save').emit('click'),
        clickPublish: () => get('#btn-publish').emit('click'),
        clickRollback: () => get('#btn-rollback').emit('click'),
        confirm: answer => { confirmAnswer = answer; },
        count: action => requests.filter(r => r.action === action).length,
    };
}

function assertDirty(h, expected) {
    assert.equal(h.state.isDirty, expected);
    assert.equal(h.document.title.startsWith('* '), expected);
    assert.equal(h.get('#header-form-name').innerHTML.includes('class="dirty"'), expected);
    const event = { prevented: false, preventDefault() { this.prevented = true; } };
    h.window.emit('beforeunload', event);
    assert.equal(event.prevented, expected, 'Navigation warning follows dirty state');
}

async function ready() {
    const h = harness();
    await h.load('alpha');
    await h.edit(json('submitted'));
    return h;
}

test('ordinary Tab prevents default and indents at the textarea selection', async () => {
    const h = harness();
    await h.load('alpha');
    const textarea = h.get('#editor-textarea');
    const before = textarea.value;
    const revision = h.state.revision;
    textarea.selectionStart = textarea.selectionEnd = 1;
    const event = {
        key: 'Tab', shiftKey: false, defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };

    await textarea.emit('keydown', event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(textarea.value, before.substring(0, 1) + '  ' + before.substring(1));
    assert.equal(textarea.selectionStart, 3);
    assert.equal(textarea.selectionEnd, 3);
    assert.equal(h.state.revision, revision + 1);
    assertDirty(h, true);
});

test('Shift+Tab is not intercepted and can escape without mutating editor state', async () => {
    const h = harness();
    await h.load('alpha');
    const textarea = h.get('#editor-textarea');
    textarea.selectionStart = 2;
    textarea.selectionEnd = 5;
    const before = {
        value: textarea.value,
        selectionStart: textarea.selectionStart,
        selectionEnd: textarea.selectionEnd,
        revision: h.state.revision,
        isDirty: h.state.isDirty,
    };
    const event = {
        key: 'Tab', shiftKey: true, defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };

    await textarea.emit('keydown', event);

    assert.equal(event.defaultPrevented, false,
        'Browser backward focus traversal remains available so focus can escape the editor');
    assert.deepEqual({
        value: textarea.value,
        selectionStart: textarea.selectionStart,
        selectionEnd: textarea.selectionEnd,
        revision: h.state.revision,
        isDirty: h.state.isDirty,
    }, before);
});

test('normal save uses the submitted form/text and clears dirty state after success', async () => {
    const h = await ready();
    const submitted = h.get('#editor-textarea').value;
    const pending = h.clickSave();
    assert.equal(h.get('#btn-save').disabled, true);
    assert.equal(h.next('validate').options.body, submitted);
    assertDirty(h, true);
    await h.validateSave();
    const request = h.next('save');
    assert.equal(request.url, 'editor.php?action=save&form=alpha');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body, submitted);
    assert.equal(request.options.headers['X-BBF-Editor-Token'], 'test-token');
    await h.finishSave();
    await pending;
    assert.equal(h.state.originalJson, submitted);
    assertDirty(h, false);
    assert.equal(h.get('#header-status').textContent, 'Draft saved');
    assert.equal(h.get('#btn-save').disabled, false);
});

for (const phase of ['validation', 'save']) {
    test('edits during ' + phase + ' remain dirty and a later save persists them', async () => {
        const h = await ready();
        const submitted = h.get('#editor-textarea').value;
        const pending = h.clickSave();
        if (phase === 'save') await h.validateSave();
        await h.edit(json('newer edits'));
        if (phase === 'validation') await h.validateSave();
        assert.equal(h.next('save').options.body, submitted, 'Save the validated snapshot');
        await h.finishSave();
        await pending;
        assert.equal(h.state.originalJson, submitted);
        assert.equal(h.get('#editor-textarea').value, json('newer edits'));
        assertDirty(h, true);
        assert.match(h.get('#header-status').textContent, /unsaved changes remain/);
        const retry = h.clickSave();
        await h.validateSave();
        assert.equal(h.next('save').options.body, json('newer edits'));
        await h.finishSave();
        await retry;
        assertDirty(h, false);
    });
}

test('revision changes are retained even when text is edited away and back', async () => {
    const h = await ready();
    const submitted = h.get('#editor-textarea').value;
    const pending = h.clickSave();
    await h.edit(json('intermediate'));
    await h.edit(submitted);
    await h.validateSave();
    await h.finishSave();
    await pending;
    assertDirty(h, true);
    assert.equal(h.state.originalJson, submitted);
});

for (const failure of ['validation network', 'save network', 'save HTTP']) {
    test(failure + ' failure preserves baseline and edits, releases lock, permits retry', async () => {
        const h = await ready();
        const baseline = h.state.originalJson;
        const pending = h.clickSave();
        if (failure !== 'validation network') await h.validateSave();
        await h.edit(json('newer edits'));
        if (failure === 'save HTTP') h.next('save').respond({ error: 'disk full' }, false);
        else h.next(failure === 'validation network' ? 'validate' : 'save').fail('network failed');
        await pending;
        assert.equal(h.state.originalJson, baseline);
        assert.equal(h.get('#editor-textarea').value, json('newer edits'));
        assertDirty(h, true);
        assert.equal(h.count('list'), 0);
        assert.equal(h.get('#btn-save').disabled, false);
        assert.match(h.get('#header-status').textContent, /disk full|network failed/);
        const retry = h.clickSave();
        await h.validateSave();
        await h.finishSave();
        await retry;
        assertDirty(h, false);
    });
}

test('repeated clicks and Ctrl/Meta+S cannot overlap validation, save, or refresh', async () => {
    const h = await ready();
    const pending = h.clickSave();
    async function repeat() {
        await h.clickSave();
        for (const modifier of ['ctrlKey', 'metaKey']) {
            await h.get('#editor-textarea').emit('keydown', {
                key: 's', [modifier]: true, preventDefault() {},
            });
        }
    }
    await repeat();
    assert.equal(h.count('validate'), 2, 'One load validation and one save validation');
    await h.validateSave();
    await repeat();
    assert.equal(h.count('save'), 1);
    h.next('save').respond({ ok: true, revision: 1, published_version: 'v0', draft_version: 'v1' });
    await flush();
    await repeat();
    assert.equal(h.count('validate'), 2);
    assert.equal(h.count('list'), 1);
    h.next('list').respond([]);
    h.next('history').respond({ history: [] });
    await pending;
    assert.equal(h.state.saving, false);
});

const warnings = { valid: false, errors: [{ path: 'fields', message: 'Required' }] };
for (const accept of [false, true]) {
    test('validation warning ' + (accept ? 'acceptance saves snapshot' : 'cancellation releases lock'), async () => {
        const h = await ready();
        const baseline = h.state.originalJson;
        h.confirm(accept);
        const pending = h.clickSave();
        await h.validateSave(warnings);
        assert.match(h.confirmations[0], /fields: Required/);
        if (accept) await h.finishSave();
        await pending;
        assert.equal(h.count('save'), accept ? 1 : 0);
        assertDirty(h, !accept);
        if (!accept) assert.equal(h.state.originalJson, baseline);
        else assert.equal(h.get('#header-status').textContent, 'Draft saved with warnings');
        assert.equal(h.get('#btn-save').disabled, false);
    });
}

test('switch initiated during validation cancels the old save before POST', async () => {
    const h = await ready();
    const pending = h.clickSave();
    const switching = h.loadForm('beta');
    await h.validateSave(warnings);
    await pending;
    assert.equal(h.count('save'), 0);
    assert.equal(h.confirmations.length, 1, 'Only discard prompt, no stale validation prompt');
    assert.equal(h.get('#btn-save').disabled, true, 'Still loading beta');
    await h.clickSave();
    assert.equal(h.count('validate'), 2, 'Cannot save old text while loading');
    h.respondLoad('beta');
    await switching;
    h.next('validate').respond({ valid: true, errors: [] });
    await flush();
    assert.equal(h.state.formId, 'beta');
    assert.equal(h.get('#btn-save').disabled, false);
});

for (const outcome of ['success', 'failure']) {
    test('old save ' + outcome + ' cannot alter the newly loaded form', async () => {
        const h = await ready();
        const pending = h.clickSave();
        await h.validateSave();
        const request = h.next('save');
        await h.load('beta');
        await h.edit(json('beta edited'));
        h.get('#header-status').textContent = 'Beta status';
        await h.clickSave();
        assert.equal(h.count('save'), 1, 'Global lock survives a switch');
        if (outcome === 'success') request.respond({ ok: true });
        else request.fail('old save failed');
        await pending;
        assert.equal(h.state.formId, 'beta');
        assert.equal(h.state.originalJson, json('beta'));
        assert.equal(h.get('#editor-textarea').value, json('beta edited'));
        assert.equal(h.get('#header-status').textContent, 'Beta status');
        assertDirty(h, true);
        assert.equal(h.count('list'), 0);
        assert.equal(h.get('#btn-save').disabled, false);
        const betaSave = h.clickSave();
        await h.validateSave();
        assert.match(h.next('save').url, /form=beta$/);
        await h.finishSave();
        await betaSave;
        assertDirty(h, false);
    });
}

test('switch away and back to the same form invalidates the old save callback', async () => {
    const h = await ready();
    const pending = h.clickSave();
    await h.validateSave();
    await h.load('beta');
    await h.load('alpha', json('reloaded alpha'));
    await h.edit(json('alpha new session'));
    h.next('save').respond({ ok: true });
    await pending;
    assert.equal(h.state.originalJson, json('reloaded alpha'));
    assert.equal(h.get('#editor-textarea').value, json('alpha new session'));
    assertDirty(h, true);
    assert.equal(h.count('list'), 0);
});

for (const outcome of ['success', 'failure']) {
    test('late list refresh ' + outcome + ' is ignored after a form switch', async () => {
        const h = await ready();
        const pending = h.clickSave();
        await h.validateSave();
        h.next('save').respond({ ok: true, revision: 1, published_version: 'v0', draft_version: 'v1' });
        await flush();
        const list = h.next('list');
        const history = h.next('history');
        await h.load('beta');
        h.get('#form-list').innerHTML = 'Current form list';
        h.get('#header-status').textContent = 'Current status';
        history.respond({ history: [{ revision: 99, version: 'stale', action: 'draft', at: 'stale' }] });
        if (outcome === 'success') list.respond([{ id: 'alpha', name: 'Stale name', fields: 0 }]);
        else list.fail('stale list failure');
        await pending;
        assert.equal(h.get('#form-list').innerHTML, 'Current form list');
        assert.equal(h.get('#header-status').textContent, 'Current status');
        assert.equal(h.state.originalJson, json('beta'));
        assertDirty(h, false);
    });
}

test('declining a form switch does not cancel the current save', async () => {
    const h = await ready();
    const pending = h.clickSave();
    h.confirm(false);
    await h.loadForm('beta');
    assert.equal(h.count('state'), 1);
    await h.validateSave();
    await h.finishSave();
    await pending;
    assert.equal(h.state.formId, 'alpha');
    assertDirty(h, false);
});

test('save stays blocked through a pending load and recovers after load failure', async () => {
    const h = await ready();
    const switching = h.loadForm('beta');
    await h.clickSave();
    assert.equal(h.count('save'), 0);
    assert.equal(h.count('validate'), 1);
    h.next('state').fail('load failed');
    h.next('history').fail('history failed');
    await switching;
    assert.equal(h.get('#btn-save').disabled, false);
    assert.equal(h.state.formId, 'alpha');
    assertDirty(h, true);
    const pending = h.clickSave();
    await h.validateSave();
    await h.finishSave();
    await pending;
});

test('typing while state and history load are pending cancels the stale load without losing text', async () => {
    const h = harness();
    await h.load('alpha');
    const baseline = h.state.originalJson;
    const pending = h.loadForm('alpha');
    assert.equal(h.get('#editor-textarea').readOnly, true);
    const local = json('typed during load');
    await h.edit(local);
    h.respondLoad('alpha', json('server refresh'), 1);
    await pending;
    assert.equal(h.get('#editor-textarea').value, local);
    assert.equal(h.state.originalJson, baseline);
    assert.equal(h.state.serverRevision, 0);
    assert.equal(h.get('#editor-textarea').readOnly, false);
    assertDirty(h, true);
    assert.match(h.get('#header-status').textContent, /load cancelled/i);
});

test('CAS conflict returns the winning draft and requires an explicit replacement decision', async () => {
    const h = await ready();
    h.confirm(false);
    const local = h.get('#editor-textarea').value;
    const pending = h.clickSave();
    await h.validateSave();
    const save = h.next('save');
    assert.equal(save.options.headers['X-BBF-Revision'], '0');
    const winner = json('winning draft');
    save.respond({ ok: false, reason: 'conflict', revision: 1, published_version: 'v0',
        draft_version: 'v1', definition: JSON.parse(winner) }, false, 409);
    await pending;
    assert.equal(h.get('#editor-textarea').value, local, 'declining conflict reload preserves local text');
    assert.equal(h.state.originalJson, winner, 'winner becomes the explicit merge baseline');
    assert.equal(h.state.serverRevision, 1);
    assertDirty(h, true);
    assert.match(h.confirmations.at(-1), /next Save Draft will explicitly replace/);
    const retry = h.clickSave();
    await h.validateSave();
    assert.equal(h.next('save').options.headers['X-BBF-Revision'], '1');
    await h.finishSave(2);
    await retry;
    assertDirty(h, false);
});

test('accepting a CAS conflict loads the winning draft without a silent overwrite', async () => {
    const h = await ready();
    const pending = h.clickSave();
    await h.validateSave();
    const winner = json('winning draft');
    h.next('save').respond({ ok: false, reason: 'conflict', revision: 1, published_version: 'v0',
        draft_version: 'v1', definition: JSON.parse(winner) }, false, 409);
    await pending;
    assert.equal(h.get('#editor-textarea').value, winner);
    assert.equal(h.state.originalJson, winner);
    assertDirty(h, false);
    assert.match(h.get('#header-status').textContent, /winning draft/);
});

test('publish sends current CAS revision and refreshes controls and history', async () => {
    const h = await ready();
    const saved = h.clickSave();
    await h.validateSave();
    await h.finishSave(1);
    await saved;
    assert.equal(h.get('#btn-publish').disabled, false);
    const pending = h.clickPublish();
    const publish = h.next('publish');
    assert.equal(publish.options.headers['X-BBF-Revision'], '1');
    publish.respond({ ok: true, revision: 2, published_version: 'v1', draft_version: 'v1', recovery_pending: false });
    await flush();
    h.next('history').respond({ history: [{ revision: 2, version: 'v1', action: 'publish', at: '2026-09-09T00:00:00Z' }] });
    await pending;
    assert.equal(h.state.serverRevision, 2);
    assert.equal(h.get('#btn-publish').disabled, true);
    assert.equal(h.get('#header-status').textContent, 'Published');
});

test('rollback uses selected immutable version and reloads the resulting winner', async () => {
    const h = harness();
    await h.load('alpha');
    h.get('#version-history').value = 'v0';
    await h.get('#version-history').emit('change');
    const pending = h.clickRollback();
    const rollback = h.next('rollback');
    assert.equal(rollback.options.headers['X-BBF-Revision'], '0');
    assert.deepEqual(JSON.parse(rollback.options.body), { version: 'v0' });
    rollback.respond({ ok: true, revision: 1, published_version: 'v0', draft_version: 'v0', recovery_pending: false });
    await flush();
    h.next('state').respond({ form: 'alpha', revision: 1, published_version: 'v0', draft_version: 'v0',
        definition: JSON.parse(json('rolled back')) });
    h.next('history').respond({ history: [{ revision: 1, version: 'v0', action: 'rollback', at: '2026-09-09T00:00:00Z' }] });
    await pending;
    assert.equal(h.get('#editor-textarea').value, json('rolled back'));
    assert.equal(h.state.serverRevision, 1);
    assertDirty(h, false);
});

test('rollback publishes the selected winner without discarding pre-existing unsaved text', async () => {
    const h = await ready();
    const local = h.get('#editor-textarea').value;
    h.get('#version-history').value = 'v0';
    await h.get('#version-history').emit('change');
    const pending = h.clickRollback();
    h.next('rollback').respond({ ok: true, revision: 1, published_version: 'v0', draft_version: 'v0', recovery_pending: false });
    await flush();
    const winner = json('rolled back');
    h.next('state').respond({ form: 'alpha', revision: 1, published_version: 'v0', draft_version: 'v0',
        definition: JSON.parse(winner) });
    h.next('history').respond({ history: [{ revision: 1, version: 'v0', action: 'rollback', at: '2026-09-09T00:00:00Z' }] });
    await pending;
    assert.equal(h.get('#editor-textarea').value, local);
    assert.equal(h.state.originalJson, winner);
    assert.equal(h.state.serverRevision, 1);
    assertDirty(h, true);
});

test('edit made while rollback is in flight survives the winning server refresh', async () => {
    const h = harness();
    await h.load('alpha');
    h.get('#version-history').value = 'v0';
    await h.get('#version-history').emit('change');
    const pending = h.clickRollback();
    const rollback = h.next('rollback');
    const local = json('typed during rollback');
    await h.edit(local);
    rollback.respond({ ok: true, revision: 1, published_version: 'v0', draft_version: 'v0', recovery_pending: false });
    await flush();
    const winner = json('rolled back');
    h.next('state').respond({ form: 'alpha', revision: 1, published_version: 'v0', draft_version: 'v0',
        definition: JSON.parse(winner) });
    h.next('history').respond({ history: [{ revision: 1, version: 'v0', action: 'rollback', at: '2026-09-09T00:00:00Z' }] });
    await pending;
    assert.equal(h.get('#editor-textarea').value, local);
    assert.equal(h.state.originalJson, winner);
    assert.equal(h.state.serverRevision, 1);
    assertDirty(h, true);
});

test('late publish and validation responses cannot alter a newer form session', async () => {
    const h = harness();
    await h.load('alpha');
    h.state.draftVersion = 'v1';
    h.state.publishedVersion = 'v0';
    const publishing = h.publishForm();
    const publish = h.next('publish');
    await h.load('beta');
    h.get('#header-status').textContent = 'Beta status';
    publish.respond({ ok: true, revision: 9, published_version: 'stale', draft_version: 'stale' });
    await publishing;
    assert.equal(h.state.formId, 'beta');
    assert.equal(h.state.serverRevision, 0);
    assert.equal(h.get('#header-status').textContent, 'Beta status');

    const first = h.validate();
    const oldValidation = h.next('validate');
    await h.edit(json('beta newer'));
    const second = h.validate();
    const newValidation = h.next('validate', true);
    newValidation.respond({ valid: false, errors: [{ path: 'fields', message: 'Newest error' }] });
    await second;
    oldValidation.respond({ valid: true, errors: [] });
    await first;
    assert.match(h.get('#error-bar').innerHTML, /Newest error/);
});

test('no selected form and read-only mode never initiate save requests', async () => {
    const empty = harness();
    await empty.clickSave();
    assert.equal(empty.requests.length, 0);
    const h = harness({ readOnly: true });
    await h.load('alpha');
    await h.edit(json('edited'));
    await h.clickSave();
    assert.equal(h.count('validate'), 1);
    assert.equal(h.count('save'), 0);
    assert.equal(h.get('#btn-save').disabled, true);
});
