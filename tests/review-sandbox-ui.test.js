'use strict';

// Real Chromium DOM/event regression; file:// fixture and explicit fetch response stubs.
// This proves listener behavior, NOT server authorization (review-sandbox-test.php does that).
// No PHP server or network is used here; CSP blocks all connections/resources except inline scripts.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');

const sandbox = fs.readFileSync(path.join(__dirname, '..', 'sandbox.php'), 'utf8').replace(/\r\n/g, '\n');
const bbf = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8');
function chromium() {
    const candidates = [process.env.CHROME_BIN, process.env.CHROMIUM_BIN,
        process.env.PROGRAMFILES && path.join(process.env.PROGRAMFILES, 'Google/Chrome/Application/chrome.exe'),
        process.env['PROGRAMFILES(X86)'] && path.join(process.env['PROGRAMFILES(X86)'], 'Microsoft/Edge/Application/msedge.exe'),
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'];
    const executable = candidates.find(p => p && fs.existsSync(p));
    assert.ok(executable, 'Chromium required: set CHROME_BIN; never silently skip');
    return executable;
}

function responseStubs() {
    window.fixture = {
        definition: { id: 'alpha', name: 'Sandbox listener fixture', fields: [
            { name: 'answer', type: 'text', label: 'Answer', required: true },
        ] },
        calls: [], pending: [], violations: [], errors: [],
    };
    window.addEventListener('error', e => fixture.errors.push(e.message));
    window.addEventListener('unhandledrejection', e => fixture.errors.push(String(e.reason)));
    document.addEventListener('securitypolicyviolation', e => fixture.violations.push({ directive: e.violatedDirective, uri: e.blockedURI }));
    // Never delegate to native fetch, even for an unexpected URL.
    window.fetch = async (url, options = {}) => {
        const call = { url: String(url), options };
        fixture.calls.push(call);
        if (call.url === 'sandbox.php?action=definition&form=alpha') {
            return new Response(JSON.stringify(fixture.definition), { headers: { 'Content-Type': 'application/json' } });
        }
        if (call.url === 'submit.php?form=alpha&sandbox=1') {
            return new Promise((resolve, reject) => fixture.pending.push({ resolve, reject }));
        }
        if (call.url === 'submit.php?form=alpha') {
            return new Response(JSON.stringify({ status: 'error', message: 'NORMAL SUBMIT SENTINEL' }), {
                status: 403, headers: { 'Content-Type': 'application/json' },
            });
        }
        throw new Error('Unexpected stub request: ' + call.url);
    };
    // Defense in depth against alternative browser transports; never call a real implementation.
    window.XMLHttpRequest = class { constructor() { fixture.errors.push('Unexpected XHR'); throw new Error('XHR forbidden'); } };
    window.WebSocket = window.EventSource = class { constructor() { fixture.errors.push('Unexpected socket'); throw new Error('Sockets forbidden'); } };
    navigator.sendBeacon = () => { fixture.errors.push('Unexpected beacon'); return false; };
}

async function browserChecks() {
    const results = [];
    const check = (name, ok) => results.push({ name, ok: !!ok });
    const waitFor = async predicate => {
        for (let i = 0; i < 100; i++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 5));
        }
        throw new Error('Fixture did not settle');
    };
    const normalCalls = () => fixture.calls.filter(c => c.url === 'submit.php?form=alpha');
    const preview = { status: 'ok', sandbox: true, validation: { passed: true, field_count: 1, errors: {} },
        data: { answer: 'preview-value' }, on_submit_preview: {
            store: { enabled: true, backend: 'file' },
            notify: { to: 'never@example.invalid', subject: 'Preview only', template: 'fixture.html', body_preview: '<p>Rendered preview only</p>' },
            webhooks: ['https://never.example.invalid/hook'], redirect: 'https://never.example.invalid/next',
        } };
    const modes = [
        ['disabled HTML 403', () => new Response('<h1>Sandbox disabled</h1>', { status: 403 }), 'error'],
        ['authorization JSON 403', () => new Response(JSON.stringify({ error: 'Access denied' }), { status: 403 }), 'denied'],
        ['server JSON 500', () => new Response(JSON.stringify({ error: 'Internal error' }), { status: 500 }), 'denied'],
        ['malformed response', () => new Response('{broken', { status: 200 }), 'error'],
        ['fetch rejection', null, 'error'],
        ['positive preview', () => new Response(JSON.stringify(preview), { status: 200 }), 'preview'],
    ];
    try {
        await waitFor(() => document.querySelector('#form-container form'));
        check('file page has no sandbox query safety net', location.protocol === 'file:' && location.search === '');
        // Positive sensitivity control: an unmodified BBF form really has a live normal-submit handler.
        const control = BBF._buildForm(fixture.definition, 'alpha', '', {}, null);
        document.body.appendChild(control);
        control.elements.answer.value = 'normal-control';
        control.requestSubmit();
        await waitFor(() => control.querySelector('.bbf-message').textContent === 'NORMAL SUBMIT SENTINEL');
        check('actual BBF normal handler sends one non-sandbox POST in explicit stub control',
            normalCalls().length === 1 && normalCalls()[0].options.method === 'POST'
            && JSON.parse(normalCalls()[0].options.body).answer === 'normal-control');
        control.remove();
        const baselineNormal = normalCalls().length;
        const form = document.querySelector('#form-container form');
        const btn = form.querySelector('.bbf-submit');
        const originalText = btn.textContent;
        let bubbled = 0;
        form.addEventListener('submit', () => { bubbled++; });
        let event;
        document.addEventListener('submit', e => { event = e; }, true);
        // Retry the SAME form after every denial, then verify a positive preview. No handler rebuild.
        for (const [name, response, outcome] of modes) {
            for (const retry of [false, true]) {
                const label = name + (retry ? ' -> positive retry' : '');
                const expected = retry ? 'preview' : outcome;
                const before = fixture.calls.length;
                form.elements.answer.value = 'preview-value';
                form.requestSubmit();
                await waitFor(() => fixture.pending.length === 1);
                const call = fixture.calls[before];
                check(label + ': captured/cancelled once, BBF bubble handler suppressed while pending',
                    event.defaultPrevented && bubbled === 0 && btn.disabled
                    && normalCalls().length === baselineNormal && fixture.calls.length === before + 1);
                check(label + ': sandbox-only POST, session CSRF, same-origin credentials, actual FormData',
                    call.url === 'submit.php?form=alpha&sandbox=1' && call.options.method === 'POST'
                    && call.options.headers['X-BBF-CSRF'] === 'fixture-management-csrf'
                    && call.options.headers['Content-Type'] === 'application/json'
                    && call.options.credentials === 'same-origin'
                    && JSON.parse(call.options.body).answer === 'preview-value'
                    && !('_bbf_hp' in JSON.parse(call.options.body)) && !('_bbf_csrf' in JSON.parse(call.options.body)));
                const pending = fixture.pending.shift();
                if (retry) pending.resolve(new Response(JSON.stringify(preview)));
                else if (response) pending.resolve(response());
                else pending.reject(new TypeError('Fixture transport denial'));
                await waitFor(() => !btn.disabled);
                check(label + ': no normal fallback after response/error, input and button restored',
                    normalCalls().length === baselineNormal && fixture.calls.length === before + 1
                    && bubbled === 0 && form.elements.answer.value === 'preview-value' && btn.textContent === originalText);
                check(label + ': expected results visible', document.getElementById('results').classList.contains('visible')
                    && (expected === 'error' ? document.getElementById('validation-content').textContent.includes('Network error:')
                        : expected === 'denied' ? !!document.querySelector('#validation-content .badge-fail')
                            : !!document.querySelector('#validation-content .badge-pass')
                                && JSON.parse(document.getElementById('data-preview').textContent).answer === 'preview-value'
                                && document.getElementById('notify-content').textContent.includes('Rendered preview only')));
            }
        }
        check('no browser errors/connections; CSP blocks only actual BBF auto-CSS: ' + JSON.stringify({ errors: fixture.errors, violations: fixture.violations }), fixture.errors.length === 0 && fixture.violations.length === 1 && fixture.violations[0].directive === 'style-src-elem' && (fixture.violations[0].uri === 'file' || fixture.violations[0].uri.startsWith('file:')) && document.querySelectorAll('link[rel="stylesheet"]').length === 1 && document.querySelector('link[rel="stylesheet"]').getAttribute('href') === 'bbf.css');
    } catch (error) { results.push({ name: error.stack || error.message, ok: false }); }
    const output = document.createElement('pre');
    output.id = 'sandbox-result'; output.textContent = JSON.stringify(results); document.body.appendChild(output);
}

function runBrowser(mutateCapture = false) {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-sandbox-ui-'));
    try {
        // Actual sandbox markup/script. Only server-generated values/options and the local script URL are replaced.
        const start = sandbox.indexOf('<!DOCTYPE html>\n<html lang="en">');
        assert.ok(start > 0);
        let page = sandbox.slice(start, sandbox.indexOf('</html>', start) + 7)
            .replace(/<link rel="stylesheet" href="bbf.css">/, '')
            .replace(/<\?php foreach \(\$forms as \$f\): \?>[\s\S]*?<\?php endif; \?>/, '<option value="alpha">Fixture</option>')
            .replace(/<\?= json_encode\(\$selectedForm, JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT\) \?>/, '"alpha"')
            .replace(/<\?= json_encode\(bbf_auth_csrf\(\)\) \?>/, '"fixture-management-csrf"');
        assert.ok(!page.includes('<?'), 'All PHP bootstrap values must be explicit fixtures');
        if (mutateCapture) {
            assert.equal(page.split('}, { capture: true });').length, 2, 'Unique listener mutation point');
            page = page.replace('}, { capture: true });', '}, { capture: false });');
        }
        const inline = script => '<script>' + script.replace(/<\/script/gi, '<\\/script') + '</script>';
        page = page.replace('<head>', '<head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'; form-action \'none\'">')
            .replace('<script src="bbf.js"></script>', inline('(' + responseStubs.toString() + ')();') + inline(bbf))
            .replace('</body>', inline('(' + browserChecks.toString() + ')();') + '</body>');
        const file = path.join(temporary, 'sandbox.html');
        fs.writeFileSync(file, page);
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-extensions', '--disable-sync',
            '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' + path.join(temporary, 'profile'),
            '--virtual-time-budget=10000', '--dump-dom', pathToFileURL(file).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(chromium(), args, { encoding: 'utf8', timeout: 45000, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="sandbox-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Chromium did not complete checks: ' + result.stderr);
        return JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
    } finally { fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 }); }
}

test('actual sandbox capture listener suppresses actual BBF normal submit through denials and positive previews (response stubs)', () => {
    const checks = runBrowser();
    assert.equal(checks.length, 51, JSON.stringify(checks));
    for (const check of checks) assert.ok(check.ok, check.name);
});

test('sensitivity control: removing capture in the disposable page exposes BBF normal-submit fallback', () => {
    const checks = runBrowser(true);
    assert.ok(checks.some(c => c.ok && c.name.startsWith('actual BBF normal handler')));
    assert.ok(checks.some(c => !c.ok && c.name.includes('BBF bubble handler suppressed while pending')),
        'Regression must fail when BBF earlier listener runs before the sandbox listener');
    assert.ok(checks.some(c => !c.ok && c.name.includes('no normal fallback after response/error')));
});
