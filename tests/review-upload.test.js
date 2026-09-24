'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');

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

/** Runs inside Chromium. XMLHttpRequest and fetch are replaced by recorders the checks answer. */
async function browserChecks() {
    const results = [];
    const check = (name, predicate) => {
        try { if (!predicate()) throw new Error(name); results.push({ name, ok: true }); }
        catch (error) { results.push({ name, ok: false, error: error.message }); }
    };
    const tick = () => new Promise(resolve => setTimeout(resolve, 0));
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 200; attempt++) { if (predicate()) return; await new Promise(r => setTimeout(r, 5)); }
        throw new Error('Browser fixture did not settle');
    };
    window.__errors = [];
    window.addEventListener('error', event => window.__errors.push(event.message));
    window.addEventListener('unhandledrejection', event => window.__errors.push(String(event.reason)));

    const uploads = [];
    class FakeXHR {
        constructor() { this.headers = {}; this.listeners = {}; this.upload = { addEventListener: (t, f) => { this.uploadProgress = f; } }; this.status = 0; this.responseText = ''; }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader(name, value) { this.headers[name] = value; }
        addEventListener(type, listener) { this.listeners[type] = listener; }
        send(body) { this.body = body; uploads.push(this); }
        abort() { this.aborted = true; }
        answer(status, json) { this.status = status; this.responseText = JSON.stringify(json); this.listeners.load(); }
    }
    window.XMLHttpRequest = FakeXHR;
    const fetches = [];
    window.fetch = (url, options) => new Promise(resolve => fetches.push({ url: String(url), options, resolve }));
    const response = (status, body) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => body });

    const definition = {
        id: 'apply', name: 'Apply',
        drafts: { enabled: true, fields: ['name'] },
        fields: [
            { name: 'name', type: 'text', label: 'Name' },
            { name: 'cv', type: 'file', label: 'CV', required: true, max_files: 2, _bbf_accept: ['.pdf', '.png', 'application/pdf', 'image/png'], _bbf_max_size: 1000 },
            { name: 'extra', type: 'radio', label: 'Extra', options: ['yes', 'no'] },
            { name: 'letter', type: 'file', label: 'Letter', show_if: { field: 'extra', value: 'yes' }, _bbf_accept: ['.pdf'], _bbf_max_size: 1000 },
        ],
    };
    const form = BBF._buildForm(definition, 'apply', './', {}, 'csrf-token', 'en', true);
    document.body.appendChild(form);
    const wrap = form.querySelector('[data-field="cv"]');
    const input = wrap.querySelector('input[type="file"]');
    const submit = form.querySelector('.bbf-submit');
    const message = form.querySelector('.bbf-message');
    const select = files => {
        const transfer = new DataTransfer();
        files.forEach(file => transfer.items.add(file));
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const pdf = (name, size = 10) => new File(['%'.repeat(size)], name, { type: 'application/pdf' });
    const soon = new Date(Date.now() + 9 * 60 * 1000).toISOString();

    check('file input has no name, carries accept and multiple, and a live status region', () =>
        !input.name && input.accept === '.pdf,.png,application/pdf,image/png' && input.multiple
        && wrap.querySelector('.bbf-file-status').getAttribute('aria-live') === 'polite'
        && form.querySelector('.bbf-draft-files-note').textContent.includes('not saved in drafts'));

    select([pdf('a.pdf'), new File(['x'], 'evil.svg'), pdf('big.pdf', 5000)]);
    check('client pre-checks type and size without sending; one request per valid file', () =>
        uploads.length === 1 && wrap.querySelectorAll('.bbf-file-error').length === 2
        && wrap.textContent.includes('evil.svg: this file type is not accepted.') && wrap.textContent.includes('big.pdf is larger than'));
    const first = uploads[0];
    check('upload request: form, field and action in the URL, CSRF in X-BBF-CSRF, the file in the body', () =>
        first.method === 'POST' && first.url === './submit.php?form=apply&action=upload&field=cv'
        && first.headers['X-BBF-CSRF'] === 'csrf-token' && first.body.get('file').name === 'a.pdf');
    check('submit is disabled while an upload is in flight', () => submit.disabled === true);
    wrap.querySelectorAll('.bbf-file-error .bbf-file-remove').forEach(button => button.click());
    first.answer(200, { token: 'a'.repeat(32), expires_at: soon, file: { name: 'a.pdf', size: 10, type: 'application/pdf' } });
    // Checked synchronously: the 9-minute expiry deliberately fires its warning on the next task.
    check('after upload the row is done, submit is enabled, the status is announced', () =>
        !submit.disabled && wrap.querySelectorAll('.bbf-file-done').length === 1 && wrap.querySelectorAll('.bbf-file-error').length === 0
        && wrap.querySelector('.bbf-file-status').textContent === 'a.pdf uploaded.');
    await waitFor(() => wrap.querySelector('.bbf-file-status').textContent.includes('expire soon'));
    check('expiry warning 10 minutes before expires_at', () => wrap.querySelector('.bbf-file-status').textContent.includes('expire soon'));

    select([pdf('b.pdf')]);
    uploads[1].answer(503, { status: 'error', message: 'Temporary storage problem. Please try again.' });
    await tick();
    form.querySelector('[name="name"]').value = 'Alice';
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await tick();
    check('a failed upload blocks submit until retried or removed', () =>
        fetches.length === 0 && message.textContent.includes('could not be uploaded') && wrap.querySelector('.bbf-file-retry'));
    wrap.querySelector('.bbf-file-retry').click();
    uploads[2].answer(200, { token: 'b'.repeat(32), expires_at: soon, file: { name: 'b.pdf', size: 10, type: 'application/pdf' } });
    await tick();
    check('retry uploads again and succeeds', () => wrap.querySelectorAll('.bbf-file-done').length === 2 && uploads[2].url.includes('action=upload'));

    select([pdf('c.pdf')]);
    check('a third file exceeds max_files and is not sent', () => uploads.length === 3 && wrap.textContent.includes('allows at most 2 files'));
    wrap.querySelector('.bbf-file-error .bbf-file-remove').click();

    // A permanent server refusal (422) cannot be retried, so it must not hold a max_files slot.
    wrap.querySelectorAll('.bbf-file-done .bbf-file-remove')[1].click();
    await tick();
    fetches.shift().resolve(response(200, { status: 'ok' }));
    select([pdf('fake.pdf')]);
    uploads[3].answer(422, { status: 'error', message: 'The file content does not match its type.' });
    await tick();
    select([pdf('b2.pdf')]);
    check('a 422-refused file shows no retry and does not block the next file', () =>
        uploads.length === 5 && !wrap.querySelector('.bbf-file-retry') && !wrap.textContent.includes('allows at most 2 files'));
    uploads[4].answer(200, { token: 'b'.repeat(32), expires_at: soon, file: { name: 'b2.pdf', size: 10, type: 'application/pdf' } });
    await tick();
    wrap.querySelector('.bbf-file-error .bbf-file-remove').click();
    check('after removing the refused row two files are done again', () =>
        wrap.querySelectorAll('.bbf-file-done').length === 2 && wrap.querySelectorAll('.bbf-file-error').length === 0);

    // Remove the second file: upload_delete with the token in the JSON body, never the URL.
    wrap.querySelectorAll('.bbf-file-done .bbf-file-remove')[1].click();
    await tick();
    const deletion = fetches.shift();
    check('× sends upload_delete with the token only in the body', () =>
        deletion.url === './submit.php?form=apply&action=upload_delete' && JSON.parse(deletion.options.body).token === 'b'.repeat(32)
        && deletion.options.headers['X-BBF-CSRF'] === 'csrf-token' && wrap.querySelectorAll('.bbf-file').length === 1);
    deletion.resolve(response(200, { status: 'ok' }));

    // The hidden conditional file field is not submitted.
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await waitFor(() => fetches.length === 1);
    const body = JSON.parse(fetches[0].options.body);
    check('submit carries only tokens of visible file fields', () =>
        JSON.stringify(body.cv) === JSON.stringify(['a'.repeat(32)]) && !('letter' in body) && body.name === 'Alice'
        && typeof body._bbf_submit_key === 'string');
    check('no upload token ever appears in a URL', () =>
        uploads.concat(fetches).every(request => !/[a-f0-9]{32}/.test(request.url)));
    fetches[0].resolve(response(200, { status: 'ok', submission_id: 'bbf_0123456789abcdef', delivery: { settled: true } }));
    await waitFor(() => message.classList.contains('bbf-success'));
    check('reset after success clears the file list', () => wrap.querySelectorAll('.bbf-file').length === 0);

    const required = BBF._validate([definition.fields[1]], form, 'en');
    check('required file field without an uploaded file fails client validation', () => required.cv === 'CV is required.');
    check('no script errors', () => window.__errors.length === 0);
    const output = document.createElement('pre'); output.id = 'upload-ui-result';
    output.textContent = JSON.stringify(results); document.body.appendChild(output);
}

test('file upload client in real Chromium', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-upload-ui-'));
    try {
        const source = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8').replace(/<\/script/gi, '<\\/script');
        const checks = browserChecks.toString().replace(/<\/script/gi, '<\\/script');
        const page = path.join(temporary, 'upload.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'">'
            + '<body><script>' + source + '</script><script>(async()=>{try{await (' + checks + ')();}'
            + 'catch(error){const output=document.createElement("pre");output.id="upload-ui-result";'
            + 'output.textContent=JSON.stringify([{name:error.stack||error.message,ok:false}]);document.body.appendChild(output);}})();</script></body>');
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions',
            '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' + path.join(temporary, 'profile'),
            '--virtual-time-budget=10000', '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 60000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="upload-ui-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Chromium did not complete upload checks: ' + result.stderr + '\nDOM: ' + result.stdout.slice(-4000));
        const browserResults = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        const failures = browserResults.filter(check => !check.ok);
        assert.equal(failures.length, 0, JSON.stringify({ passed: browserResults.length - failures.length, failures }, null, 2));
        assert.equal(browserResults.length, 17, 'all upload browser checks executed');
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
