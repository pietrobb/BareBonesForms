'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase(); this.children = []; this.parentElement = null;
        this.listeners = {}; this.attributes = {}; this.style = {}; this.value = '';
        this.checked = false; this.disabled = false; this.textContent = ''; this.type = '';
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
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; }
    setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'name') this.name = String(value); }
    getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    async emit(type) { for (const listener of this.listeners[type] || []) await listener({ type, target: this }); }
    dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) listener(event); return true; }
    matches(selector) {
        const cls = selector.match(/^\.([\w-]+)$/)?.[1];
        if (cls) return this.classList.contains(cls);
        const attr = selector.match(/^\[([\w-]+)="([^"]*)"\]$/);
        if (attr) return String(attr[1] === 'name' ? this.name : this.getAttribute(attr[1])) === attr[2];
        return false;
    }
    querySelectorAll(selector) {
        const found = [];
        const visit = node => node.children.forEach(child => { if (child.matches(selector)) found.push(child); visit(child); });
        visit(this); return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

function harness() {
    const requests = [];
    const store = new Map();
    const document = {
        head: new Element('head'),
        getElementsByTagName: tag => tag === 'script' ? [{ src: 'https://forms.test/bbf.js' }] : [],
        querySelectorAll: () => [],
        createElement: tag => new Element(tag),
        addEventListener() {}, readyState: 'loading',
    };
    class TestEvent { constructor(type, options = {}) { this.type = type; Object.assign(this, options); } }
    const context = vm.createContext({
        document, window: {}, location: { href: 'https://forms.test/page', origin: 'https://forms.test' }, URL, Event: TestEvent,
        localStorage: {
            getItem: key => store.get(key) || null,
            setItem: (key, value) => store.set(key, String(value)),
            removeItem: key => store.delete(key),
        },
        fetch(url, options) {
            const wait = deferred();
            requests.push({ url, options, wait });
            return wait.promise;
        },
        setTimeout, clearTimeout, console,
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8'), context, { filename: 'bbf.js' });
    const form = new Element('form'); form.className = 'bbf-form';
    const addInput = (name, type = 'text', value = '') => {
        const input = form.appendChild(new Element('input'));
        input.name = name; input.setAttribute('name', name); input.type = type; input.value = value;
        return input;
    };
    const fields = [
        { name: 'name', type: 'text' },
        { name: 'symptoms', type: 'textarea', sensitive: true },
        { name: 'password', type: 'password' },
        { name: 'choices', type: 'checkbox', other: true },
    ];
    const inputs = {
        name: addInput('name', 'text', 'Alice'),
        symptoms: addInput('symptoms', 'text', 'private health history'),
        password: addInput('password', 'password', 'secret'),
        a: addInput('choices', 'checkbox', 'a'),
        b: addInput('choices', 'checkbox', 'b'),
        other: addInput('choices', 'checkbox', '__other__'),
        choicesOther: addInput('choices_other', 'text', 'custom choice'),
    };
    inputs.a.checked = true;
    inputs.other.checked = true;
    const definition = { drafts: { enabled: true, fields: ['name', 'symptoms', 'password', 'choices'] } };
    const controls = context.window.BBF._buildDraftControls(form, definition, 'consultation', './', 'csrf-token', 'en', true, fields);
    form.appendChild(controls);
    const byClass = name => controls.querySelector('.' + name);
    const storageKey = context.window.BBF._draftStorageKey('./', 'consultation');
    const respond = (index, body, ok = true, status = ok ? 200 : 500) => requests[index].wait.resolve({
        ok, status, json: async () => body,
    });
    return { BBF: context.window.BBF, form, fields, inputs, controls, requests, store, storageKey, byClass, respond };
}

test('draft controls are absent unless explicitly enabled with an allowlist', () => {
    const h = harness();
    assert.equal(h.BBF._buildDraftControls(h.form, {}, 'off', './', 'csrf', 'en', true, h.fields), null);
    assert.equal(h.BBF._buildDraftControls(h.form, { drafts: { enabled: false, fields: ['name'] } }, 'off', './', 'csrf', 'en', true, h.fields), null);
    const schema = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'forms', 'form.schema.json'), 'utf8'));
    assert.equal(schema.properties.drafts.properties.fields.minItems, undefined);
    assert.equal(schema.properties.drafts.allOf[0].then.properties.fields.minItems, 1);
    assert.deepEqual(schema.properties.drafts.allOf[0].then.required, ['fields']);
});

test('draft controls expose labelled code input, button types and live status', () => {
    const h = harness();
    const code = h.byClass('bbf-draft-code');
    const label = h.controls.children[0];
    const status = h.byClass('bbf-draft-status');
    assert.equal(label.htmlFor, code.id);
    assert.equal(status.getAttribute('role'), 'status');
    assert.equal(status.getAttribute('aria-live'), 'polite');
    assert.deepEqual(['bbf-draft-save', 'bbf-draft-resume', 'bbf-draft-delete'].map(name => h.byClass(name).type), ['button', 'button', 'button']);
    assert.equal(h.byClass('bbf-draft-resume').disabled, true);
    assert.equal(h.byClass('bbf-draft-delete').disabled, true);
});

test('save sends only client allowlisted nonsensitive values and stores returned bearer per form', async () => {
    const h = harness();
    const pending = h.byClass('bbf-draft-save').emit('click');
    assert.equal(h.requests.length, 1);
    const body = JSON.parse(h.requests[0].options.body);
    assert.deepEqual(body, { name: 'Alice', choices: ['a', '__other__'], choices_other: 'custom choice', _bbf_csrf: 'csrf-token' });
    assert.equal(h.requests[0].options.credentials, 'same-origin');
    h.respond(0, { status: 'ok', handle: 'A'.repeat(43), expires_at: '2026-09-10T00:00:00Z' });
    await pending;
    assert.equal(h.byClass('bbf-draft-code').value, 'A'.repeat(43));
    assert.equal([...h.store.values()][0], 'A'.repeat(43));
    assert.match(h.byClass('bbf-draft-status').textContent, /saved until/i);
});

test('6129-F07 draft restore follows the main Other selection and clears stale companion text', () => {
    const h = harness();
    h.BBF._draftApply(h.form, h.fields, { choices: ['__other__'], choices_other: 'restored choice' }, ['choices']);
    assert.equal(h.inputs.other.checked, true);
    assert.equal(h.inputs.choicesOther.value, 'restored choice');

    h.BBF._draftApply(h.form, h.fields, { choices: ['a'], choices_other: 'must not survive' }, ['choices']);
    assert.equal(h.inputs.a.checked, true);
    assert.equal(h.inputs.other.checked, false);
    assert.equal(h.inputs.choicesOther.value, '');
});

test('6129-F07 scalar radio and select Other companions collect, restore and clear', () => {
    const h = harness();
    const add = (name, type, value) => {
        const element = h.form.appendChild(new Element(type === 'select-one' ? 'select' : 'input'));
        element.name = name; element.setAttribute('name', name); element.type = type; element.value = value;
        return element;
    };
    const courier = add('delivery', 'radio', 'courier');
    const deliveryOther = add('delivery', 'radio', '__other__'); deliveryOther.checked = true;
    const deliveryText = add('delivery_other', 'text', 'pickup');
    const country = add('country', 'select-one', '__other__');
    const countryText = add('country_other', 'text', 'CZ');
    const fields = [{ name: 'delivery', type: 'radio', other: true }, { name: 'country', type: 'select', other: true }];

    assert.equal(JSON.stringify(h.BBF._draftCollect(h.form, fields, ['delivery', 'country'])),
        JSON.stringify({ delivery: '__other__', delivery_other: 'pickup', country: '__other__', country_other: 'CZ' }));
    const collisionFields = [...fields, { name: 'delivery_other', type: 'text', sensitive: true }];
    assert.equal(JSON.stringify(h.BBF._draftCollect(h.form, collisionFields, ['delivery'])), JSON.stringify({ delivery: '__other__' }),
        '6129-F07 client collection rejects a declared sensitive companion collision');
    deliveryText.value = 'private diagnosis';
    h.BBF._draftApply(h.form, collisionFields, { delivery: '__other__', delivery_other: 'attacker overwrite' }, ['delivery']);
    assert.equal(deliveryText.value, 'private diagnosis', '6129-F07 client restore cannot overwrite a colliding sensitive field');
    deliveryText.value = 'pickup';
    h.BBF._draftApply(h.form, fields,
        { delivery: '__other__', delivery_other: 'restored pickup', country: '__other__', country_other: 'AT' },
        ['delivery', 'country']);
    assert.equal(deliveryText.value, 'restored pickup');
    assert.equal(countryText.value, 'AT');

    h.BBF._draftApply(h.form, fields, { delivery: 'courier', delivery_other: 'stale', country: 'SK', country_other: 'stale' },
        ['delivery', 'country']);
    assert.equal(courier.checked, true);
    assert.equal(deliveryOther.checked, false);
    assert.equal(deliveryText.value, '');
    assert.equal(country.value, 'SK');
    assert.equal(countryText.value, '');
});

test('newer resume response wins and stale callback cannot overwrite fields', async () => {
    const h = harness();
    const code = h.byClass('bbf-draft-code');
    code.value = 'A'.repeat(43); await code.emit('input');
    const first = h.byClass('bbf-draft-resume').emit('click');
    code.value = 'B'.repeat(43); await code.emit('input');
    const second = h.byClass('bbf-draft-resume').emit('click');
    assert.equal(h.requests.length, 2);
    h.respond(1, { status: 'ok', data: { name: 'Newest', choices: ['b'] } });
    await second;
    h.respond(0, { status: 'ok', data: { name: 'Stale', choices: ['a'] } });
    await first;
    assert.equal(h.inputs.name.value, 'Newest');
    assert.equal(h.inputs.a.checked, false);
    assert.equal(h.inputs.b.checked, true);
    assert.equal(code.value, 'B'.repeat(43));
    assert.equal(h.store.get(h.storageKey), 'B'.repeat(43));
    assert.equal(h.byClass('bbf-draft-status').textContent, 'Saved progress restored.');
    assert.equal(h.byClass('bbf-draft-status').classList.contains('bbf-error'), false);
});

test('stale delete success or failure cannot clear a newer resume code', async () => {
    for (const failure of [false, true]) {
        const h = harness();
        const code = h.byClass('bbf-draft-code');
        h.store.set(h.storageKey, 'A'.repeat(43));
        code.value = 'A'.repeat(43); await code.emit('input');
        const deleting = h.byClass('bbf-draft-delete').emit('click');
        code.value = 'B'.repeat(43); await code.emit('input');
        if (failure) h.respond(0, { status: 'error', message: 'Delete failed' }, false, 503);
        else h.respond(0, { status: 'ok', ok: true });
        await deleting;
        assert.equal(code.value, 'B'.repeat(43));
        assert.equal(h.store.get(h.storageKey), 'A'.repeat(43));
        assert.equal(h.byClass('bbf-draft-status').textContent, '');
    }
});

test('pending save success or failure cannot replace a newly typed code', async () => {
    for (const failure of [false, true]) {
        const h = harness();
        const code = h.byClass('bbf-draft-code');
        h.store.set(h.storageKey, 'A'.repeat(43));
        code.value = 'A'.repeat(43); await code.emit('input');
        const saving = h.byClass('bbf-draft-save').emit('click');
        code.value = 'B'.repeat(43); await code.emit('input');
        if (failure) h.respond(0, { status: 'error', message: 'Expired' }, false, 410);
        else h.respond(0, { status: 'ok', handle: 'A'.repeat(43), expires_at: '2026-09-10T00:00:00Z' });
        await saving;
        assert.equal(code.value, 'B'.repeat(43));
        assert.equal(h.store.get(h.storageKey), 'A'.repeat(43));
        assert.equal(h.byClass('bbf-draft-status').textContent, '');
    }
});

test('pending resume error cannot clear a newly typed code', async () => {
    const h = harness();
    const code = h.byClass('bbf-draft-code');
    h.store.set(h.storageKey, 'A'.repeat(43));
    code.value = 'A'.repeat(43); await code.emit('input');
    const loading = h.byClass('bbf-draft-resume').emit('click');
    code.value = 'B'.repeat(43); await code.emit('input');
    h.respond(0, { status: 'error', message: 'Expired' }, false, 410);
    await loading;
    assert.equal(code.value, 'B'.repeat(43));
    assert.equal(h.store.get(h.storageKey), 'A'.repeat(43));
    assert.equal(h.byClass('bbf-draft-status').textContent, '');
});

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

async function browserChecks() {
    const results = [];
    const check = (name, predicate) => {
        try { if (!predicate()) throw new Error(name); results.push({ name, ok: true }); }
        catch (error) { results.push({ name, ok: false, error: error.message }); }
    };
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 100; attempt++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 5));
        }
        throw new Error('Browser fixture did not settle');
    };
    window.__errors = [];
    window.addEventListener('error', event => window.__errors.push(event.message));
    window.addEventListener('unhandledrejection', event => window.__errors.push(String(event.reason)));
    const handles = { old: 'A'.repeat(43), current: 'B'.repeat(43), saved: 'C'.repeat(43), other: 'D'.repeat(43) };
    const fields = [
        { name: 'name', type: 'text' },
        { name: 'symptoms', type: 'textarea', sensitive: true },
        { name: 'password', type: 'password' },
        { name: 'choices', type: 'checkbox', other: true },
    ];
    const form = document.createElement('form');
    const input = (name, type, value) => {
        const element = document.createElement('input');
        element.name = name; element.type = type; element.value = value; form.appendChild(element); return element;
    };
    const name = input('name', 'text', 'Alice');
    const symptoms = input('symptoms', 'text', 'private health history');
    const password = input('password', 'password', 'secret');
    const choiceA = input('choices', 'checkbox', 'a'); choiceA.checked = true;
    const choiceB = input('choices', 'checkbox', 'b');
    const definition = { drafts: { enabled: true, fields: ['name', 'symptoms', 'password', 'choices'] } };
    const storageKey = BBF._draftStorageKey('./', 'consultation');
    const otherKey = BBF._draftStorageKey('./', 'other-form');
    localStorage.setItem(storageKey, handles.old);
    localStorage.setItem(otherKey, handles.other);
    const pending = [];
    window.fetch = (url, options) => new Promise(resolve => pending.push({ url: String(url), options, resolve }));
    const response = (status, body) => ({ ok: status < 400, status, json: async () => body });
    const controls = BBF._buildDraftControls(form, definition, 'consultation', './', 'csrf-token', 'en', true, fields);
    form.appendChild(controls); document.body.appendChild(form);
    const code = controls.querySelector('.bbf-draft-code');
    const status = controls.querySelector('.bbf-draft-status');
    const save = controls.querySelector('.bbf-draft-save');
    const resume = controls.querySelector('.bbf-draft-resume');
    const remove = controls.querySelector('.bbf-draft-delete');
    check('real DOM exposes accessible controls and restores only exact-form storage', () =>
        controls.querySelector(`label[for="${code.id}"]`) && status.getAttribute('role') === 'status'
        && status.getAttribute('aria-live') === 'polite' && code.value === handles.old
        && localStorage.getItem(otherKey) === handles.other && !resume.disabled && !remove.disabled);

    code.value = handles.old; code.dispatchEvent(new Event('input', { bubbles: true })); resume.click();
    await waitFor(() => pending.length === 1);
    code.value = handles.current; code.dispatchEvent(new Event('input', { bubbles: true })); resume.click();
    await waitFor(() => pending.length === 2);
    const hostile = '<img src=x onerror=window.__draftXss=true>';
    pending[1].resolve(response(200, { status: 'ok', data: { name: hostile, choices: ['b'] } }));
    await waitFor(() => status.textContent === 'Saved progress restored.');
    pending[0].resolve(response(410, { status: 'error', message: 'Expired stale request' }));
    await new Promise(resolve => setTimeout(resolve, 0));
    check('newest resume wins fields, live status and localStorage over stale failure', () =>
        name.value === hostile && choiceA.checked === false && choiceB.checked === true
        && code.value === handles.current && status.textContent === 'Saved progress restored.'
        && !status.classList.contains('bbf-error') && localStorage.getItem(storageKey) === handles.current
        && !document.querySelector('img') && !window.__draftXss);

    let staleBranchesSafe = true;
    for (const [button, failure] of [[save, false], [save, true], [remove, false], [remove, true]]) {
        code.value = handles.current; code.dispatchEvent(new Event('input', { bubbles: true }));
        const requestIndex = pending.length;
        button.click(); await waitFor(() => pending.length === requestIndex + 1);
        code.value = handles.other; code.dispatchEvent(new Event('input', { bubbles: true }));
        pending[requestIndex].resolve(failure
            ? response(503, { status: 'error', message: 'Stale failure' })
            : response(200, { status: 'ok', ok: true, handle: handles.saved, expires_at: '2026-09-10T00:00:00Z' }));
        await new Promise(resolve => setTimeout(resolve, 0));
        staleBranchesSafe = staleBranchesSafe && code.value === handles.other && status.textContent === ''
            && localStorage.getItem(storageKey) === handles.current && !status.classList.contains('bbf-error');
    }
    check('real stale save/delete success and failure preserve newer code, status and storage', () => staleBranchesSafe);

    code.value = handles.current; code.dispatchEvent(new Event('input', { bubbles: true }));
    name.value = 'Saved in Chromium';
    const saveIndex = pending.length;
    save.click(); await waitFor(() => pending.length === saveIndex + 1);
    const saveBody = JSON.parse(pending[saveIndex].options.body);
    pending[saveIndex].resolve(response(201, { status: 'ok', handle: handles.saved, expires_at: '2026-09-10T00:00:00Z' }));
    await waitFor(() => code.value === handles.saved);
    check('real save excludes sensitive fields and stores server-returned bearer', () =>
        JSON.stringify(saveBody) === JSON.stringify({ name: 'Saved in Chromium', choices: ['b'], _bbf_csrf: 'csrf-token', _bbf_draft_handle: handles.current })
        && localStorage.getItem(storageKey) === handles.saved && status.textContent.includes('Draft saved until'));

    const deleteIndex = pending.length;
    remove.click(); await waitFor(() => pending.length === deleteIndex + 1);
    pending[deleteIndex].resolve(response(200, { status: 'ok', ok: true }));
    await waitFor(() => status.textContent === 'Saved progress deleted.');
    check('real delete clears only this form bearer and restores button state', () =>
        code.value === '' && localStorage.getItem(storageKey) === null && localStorage.getItem(otherKey) === handles.other
        && resume.disabled && remove.disabled && !save.disabled);
    check('real browser completed without script errors or hostile execution', () =>
        window.__errors.length === 0 && !window.__draftXss && symptoms.value === 'private health history' && password.value === 'secret');
    const output = document.createElement('pre'); output.id = 'draft-ui-result';
    output.textContent = JSON.stringify(results); document.body.appendChild(output);
}

test('respondent draft workflow in real Chromium', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-draft-ui-'));
    try {
        const source = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8').replace(/<\/script/gi, '<\\/script');
        const checks = browserChecks.toString().replace(/<\/script/gi, '<\\/script');
        const page = path.join(temporary, 'drafts.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'">'
            + '<body><script>' + source + '</script><script>(async()=>{try{await (' + checks + ')();}'
            + 'catch(error){const output=document.createElement("pre");output.id="draft-ui-result";'
            + 'output.textContent=JSON.stringify([{name:error.stack||error.message,ok:false}]);document.body.appendChild(output);}})();</script></body>');
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions',
            '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' + path.join(temporary, 'profile'),
            '--virtual-time-budget=10000', '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="draft-ui-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Chromium did not complete draft checks: ' + result.stderr + '\nDOM: ' + result.stdout.slice(-4000));
        const browserResults = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        const failures = browserResults.filter(check => !check.ok);
        assert.equal(failures.length, 0, JSON.stringify({ passed: browserResults.length - failures.length, failures }, null, 2));
        assert.equal(browserResults.length, 6, 'all draft browser checks executed');
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
