'use strict';

// Run with node --test tests/review-security.test.js (Chrome/Chromium required).
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawn, spawnSync } = require('node:child_process');

const source = fs.readFileSync(path.join(__dirname, '..', 'viewer.php'), 'utf8');
const editorSource = fs.readFileSync(path.join(__dirname, '..', 'editor.php'), 'utf8');

function browserExecutable() {
    const candidates = [process.env.CHROME_BIN, process.env.CHROMIUM_BIN,
        process.env.PROGRAMFILES && path.join(process.env.PROGRAMFILES, 'Google/Chrome/Application/chrome.exe'),
        process.env['PROGRAMFILES(X86)'] && path.join(process.env['PROGRAMFILES(X86)'], 'Microsoft/Edge/Application/msedge.exe'),
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'];
    const executable = candidates.find(candidate => candidate && fs.existsSync(candidate));
    assert.ok(executable, 'Install Chrome/Chromium or set CHROME_BIN; security tests must not silently skip the real browser');
    return executable;
}

async function browserChecks() {
    const results = [];
    const check = (name, fn) => {
        try { fn(); results.push({ name, ok: true }); }
        catch (error) { results.push({ name, ok: false, error: error.message }); }
    };
    const equal = (actual, expected) => {
        if (actual !== expected) throw new Error(JSON.stringify({ actual, expected }));
    };
    const inert = root => {
        equal(root.querySelectorAll('[onclick],[onmouseover],[onfocus],img,svg,script').length, 0);
    };
    const v = window.viewerSecurity;
    const panel = document.getElementById('panel-main');
    const payload = '" onclick="window.__xss++" onmouseover="window.__xss++" data-extra="';
    const markup = '<img src=x onerror="window.__xss++"> & \'quoted\' ž';
    const form = { name: markup, fields: [
        { name: 'answer', label: markup, type: 'text' },
        { name: 'choices', label: 'Choices', type: 'checkbox' },
        { name: 'email', type: 'email' },
        { name: 'url', type: 'url' },
    ] };
    const sub = { id: 'bbf_test', form: 'test', data: {
        answer: payload, choices: [markup, payload], email: payload, url: 'javascript:window.__xss++',
    }, meta: { ip: markup, user_agent: markup } };
    window.__xss = 0;
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: {
        writeText(text) { window.__copied = text; return Promise.resolve(); },
    } });
    check('control: real DOM leaves quotes unescaped and inline handlers execute', () => {
        const div = document.createElement('div');
        div.textContent = '"';
        equal(div.innerHTML, '"');
        div.innerHTML = '<button onclick="window.__control=1">control</button>';
        div.firstChild.click();
        equal(window.__control, 1);
    });
    check('detail copy and email attributes preserve literal values without handlers', () => {
        v.state.subs = [sub]; v.state.formDef = form;
        v.renderDetailView(sub, form);
        inert(panel);
        const copy = panel.querySelector('.btn-copy');
        equal(copy.dataset.val, payload);
        copy.click();
        equal(window.__copied, payload);
        equal(panel.querySelector('a').getAttribute('href'), 'mailto:' + payload);
        equal(window.__xss, 0);
    });
    check('table title retains full malicious value without attribute injection', () => {
        panel.innerHTML = v.renderTable();
        inert(panel);
        const cell = panel.querySelector('td[title]');
        equal(cell.title, payload);
        cell.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
        equal(window.__xss, 0);
    });
    check('search and identifier attributes cannot introduce elements or handlers', () => {
        v.state.search = payload;
        v.state.subs = [{ ...sub, id: payload }];
        v.state.viewMode = 'cards';
        v.renderMain();
        inert(panel);
        equal(panel.querySelector('#search-input').value, payload);
        equal(panel.querySelector('.grid-card').dataset.id, payload);
    });
    check('sidebar and dashboard form attributes remain inert', () => {
        v.state.dashDefs = {}; v.renderFormList([{ id: payload, name: markup, count: 1 }]);
        const sidebar = document.getElementById('form-list');
        inert(sidebar);
        equal(sidebar.querySelector('.form-item').dataset.id, payload);
        v.renderDashboard({ total: 1, today: 0, this_week: 0,
            per_form: [{ id: payload, name: markup, total: 1, today: 0 }], recent: [sub] });
        inert(panel);
        equal(panel.querySelector('.dash-form-card').dataset.form, payload);
    });
    check('URL values only link HTTP(S), never executable schemes', () => {
        for (const value of ['javascript:window.__xss++', 'JaVaScRiPt:alert(1)',
            'java\nscript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//example.invalid']) {
            panel.innerHTML = v.formatValue(value, 'url');
            equal(panel.querySelector('a'), null);
            equal(panel.textContent, value);
        }
        for (const value of ['https://example.invalid/?a=1&b=2', 'http://example.invalid/" onclick="window.__xss++']) {
            panel.innerHTML = v.formatValue(value, 'url');
            inert(panel);
            equal(panel.querySelector('a').getAttribute('href'), value);
        }
    });
    let prints = 0; const doc = document.implementation.createHTMLDocument('Print fixture');
    window.open = () => ({ document: doc, closed: false, focus() {}, close() { this.closed = true; }, print() { prints++; } });
    window.fetch = async () => ({ ok: true, async json() { return { submission: sub, form_def: form }; } }); // Explicit response stub, not HTTP authorization coverage.
    await v.printSubmission(sub.form, sub.id);
    check('print HTML keeps fresh response values and labels inert', () => {
        inert(doc); equal(prints, 1); equal(doc.querySelector('h1').textContent, markup);
        equal(doc.querySelector('td + td').textContent, payload); equal(doc.querySelector('td').textContent, markup);
    });
    const output = document.createElement('pre');
    output.id = 'security-result';
    output.textContent = JSON.stringify(results);
    document.body.appendChild(output);
}

test('viewer HTML sinks are inert in real Chromium and preserve copy/title/search/print data', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-viewer-security-'));
    try {
        const script = source.slice(source.indexOf('<script>') + 8, source.lastIndexOf('</script>'))
            .replace(/<\?= json_encode\(\$formsList\) \?>/g, '[]')
            .replace(/<\?= json_encode\(\$viewerToken\) \?>/g, '"isolated-test"')
            .replace(/<\?= json_encode\(\$canDelete\) \?>/g, 'true')
            .replace(/<\?= json_encode\(\$siteName\) \?>/g, '"Isolated viewer"')
            .replace(/<\?= json_encode\(\$viewerLang\) \?>/g, '"en"')
            .replace('renderFormList(FORMS);\nrestoreRoute();', `window.viewerSecurity = {
                state, renderDetailView, renderTable, renderMain, renderFormList,
                renderDashboard, formatValue, printSubmission
            };`);
        assert.ok(!script.includes('<?'), 'All PHP values must be replaced with fixtures');
        const page = path.join(temporary, 'security.html');
        const ids = ['form-list', 'panel-main', 'panel-forms', 'header-status', 'header-title',
            'drawer-backdrop', 'btn-hamburger'];
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'">'
            + ids.map(id => `<div id="${id}"></div>`).join('')
            + '<script>' + script.replace(/<\/script/gi, '<\\/script') + '</script>'
            + '<script>(' + browserChecks.toString().replace(/<\/script/gi, '<\\/script') + ')();</script>');
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-extensions',
            '--user-data-dir=' + path.join(temporary, 'profile'), '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error);
        assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="security-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Browser did not complete assertions: ' + result.stderr);
        const checks = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        assert.equal(checks.length, 7);
        for (const check of checks) assert.ok(check.ok, check.name + ': ' + check.error);
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});

test('forward email escapes multivalue answers using the actual PHP rendering block', () => {
    const start = source.indexOf("    foreach ($sub['data'] as $k => $v) {", source.indexOf("if ($action === 'forward')"));
    const end = source.indexOf("    $h .= '</table></body></html>';", start);
    const helper = source.match(/function viewerValueText\(mixed \$value\): string \{[\s\S]*?\r?\n\}/)?.[0];
    assert.ok(start > 0 && end > start && helper);
    const data = { answer: ['<img src=x onerror=alert(1)>', '"quoted" & text'], scalar: '<b>literal</b>',
        items: [{ sku: '<row>' }] };
    const code = helper + "\n$sub = ['data' => json_decode(stream_get_contents(STDIN), true)]; $labelMap = []; $h = '';\n"
        + source.slice(start, end) + '\necho $h;';
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', code], {
        input: JSON.stringify(data), encoding: 'utf8', timeout: 10000, windowsHide: true,
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr);
    assert.doesNotMatch(result.stdout, /<img|<b>/);
    assert.match(result.stdout, /&lt;img src=x onerror=alert\(1\)&gt;/);
    assert.match(result.stdout, /&quot;quoted&quot; &amp; text/);
    assert.match(result.stdout, /&lt;b&gt;literal&lt;\/b&gt;/);
    assert.match(result.stdout, /&quot;sku&quot;: &quot;&lt;row&gt;&quot;/);
    assert.doesNotMatch(result.stdout, />Array(?:<|\s)|\[object Object\]/);
});

test('editor preview renders hostile errors as text inside an opaque-origin sandbox', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-editor-preview-security-'));
    let server = null;
    try {
        const sandbox = editorSource.match(/<iframe[^>]+id="preview-frame"[^>]+sandbox="([^"]+)"/i)?.[1];
        assert.equal(sandbox, 'allow-scripts', 'Preview must not combine scripts with the administration origin');

        const previewScript = [...editorSource.matchAll(/<script>([\s\S]*?)<\/script>/g)]
            .map(match => match[1]).find(text => text.includes("e.data.type !== 'bbf-render'"));
        assert.ok(previewScript, 'Extract the actual editor preview message handler');
        const executablePreview = previewScript.replace(
            /<\?php if \(\$formId\): \?>[\s\S]*?<\?php endif; \?>/,
            ''
        );
        assert.ok(!executablePreview.includes('<?'), 'Remove only the standalone PHP render fixture');

        const attack = '<img src="missing" onerror="parent.postMessage({type:\'bbf-xss\'}, \'*\')">';
        const preview = path.join(temporary, 'preview.html');
        fs.writeFileSync(preview, '<!doctype html><meta charset="utf-8">'
            + '<div id="bbf-preview"></div>'
            + '<script>parent.postMessage({type:"bbf-boot"},"*");window.onerror=m=>parent.postMessage({type:"bbf-error",message:String(m)},"*");<\/script>'
            + '<script>window.BBF={baseUrl:"",async _prepareFormDefinition(){return true},_buildForm(){throw new Error(' + JSON.stringify(attack) + ')}};parent.postMessage({type:"bbf-stub"},"*");<\/script>'
            + '<script>' + executablePreview.replace(/<\/script/gi, '<\\/script') + '<\/script>'
            + '<script>parent.postMessage({type:"bbf-handler"},"*");window.addEventListener("message",()=>parent.postMessage({'
            + 'type:"bbf-preview-result",text:document.getElementById("bbf-preview").textContent,'
            + 'elements:document.getElementById("bbf-preview").querySelectorAll("img,script").length},"*"));<\/script>');

        const page = path.join(temporary, 'parent.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<iframe id="preview" sandbox="allow-scripts"></iframe><pre id="security-result"></pre>'
            + '<script>let xss=false;const events=[];const frame=document.getElementById("preview");'
            + 'window.addEventListener("message",event=>{if(event.source!==frame.contentWindow)return;events.push(event.data);'
            + 'if(event.data?.type==="bbf-handler"){events.push({type:"bbf-sent"});event.source.postMessage({type:"bbf-render",json:"{}"},"*");return}'
            + 'if(event.data?.type==="bbf-xss"){xss=true;return}if(event.data?.type==="bbf-preview-result")'
            + 'document.getElementById("security-result").textContent=JSON.stringify({...event.data,xss,events});});'
            + 'frame.src="preview.html";setTimeout(()=>{const out=document.getElementById("security-result");if(!out.textContent)'
            + 'out.textContent=JSON.stringify({timeout:true,xss,events});},500);<\/script>');

        const portFile = path.join(temporary, 'port.txt');
        const serverCode = `
            const http = require('node:http');
            const fs = require('node:fs');
            const path = require('node:path');
            const root = process.argv[1];
            const portFile = process.argv[2];
            const allowed = new Set(['parent.html', 'preview.html']);
            const server = http.createServer((request, response) => {
                const name = path.basename(new URL(request.url, 'http://localhost').pathname);
                if (!allowed.has(name)) { response.writeHead(404); response.end(); return; }
                response.setHeader('Content-Type', 'text/html; charset=utf-8');
                fs.createReadStream(path.join(root, name)).pipe(response);
            });
            server.listen(0, '127.0.0.1', () => fs.writeFileSync(portFile, String(server.address().port)));
        `;
        server = spawn(process.execPath, ['-e', serverCode, temporary, portFile], {
            stdio: 'ignore', windowsHide: true,
        });
        const deadline = Date.now() + 5000;
        while (!fs.existsSync(portFile) && Date.now() < deadline) {
            Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
        }
        assert.ok(fs.existsSync(portFile), 'Owned preview fixture server did not start');
        const pageUrl = 'http://127.0.0.1:' + fs.readFileSync(portFile, 'utf8').trim() + '/parent.html';

        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-extensions', '--virtual-time-budget=1000',
            '--user-data-dir=' + path.join(temporary, 'profile'), '--dump-dom', pageUrl];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error);
        assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="security-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Opaque preview did not return a result: ' + result.stderr);
        const check = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        assert.equal(check.text, attack, JSON.stringify(check));
        assert.equal(check.elements, 0, 'Hostile markup must remain inert text');
        assert.equal(check.xss, false, 'Preview payload must not execute');
    } finally {
        if (server && server.exitCode === null) server.kill();
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});

test('renderer and sandbox load failures keep hostile messages inert in real Chromium', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-load-error-security-'));
    try {
        const bbfSource = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8');
        const sandboxSource = fs.readFileSync(path.join(__dirname, '..', 'sandbox.php'), 'utf8');
        const loadForm = sandboxSource.match(/    window\.loadForm = async function\(formId\) \{[\s\S]*?\n    \};/)?.[0];
        assert.ok(loadForm, 'Extract the actual sandbox load function');
        const attack = '<img src=x onerror="window.__xss++">';
        const page = path.join(temporary, 'load-errors.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'">'
            + '<div id="renderer"></div><div id="form-container"></div><div id="results"></div>'
            + '<div id="result-placeholder"></div><div id="form-json"></div><div id="form-meta"></div>'
            + '<pre id="security-result"></pre><script>' + bbfSource.replace(/<\/script/gi, '<\\/script') + '<\/script>'
            + '<script>let currentFormId=null,currentFormDef=null,loadRequest=0;const sandboxCsrf="csrf";'
            + 'async function sandboxSubmit(){};' + loadForm.replace(/<\/script/gi, '<\\/script')
            + ';(async()=>{window.__xss=0;const attack=' + JSON.stringify(attack) + ';'
            + 'window.fetch=async()=>({ok:false,status:500});await BBF.render(attack,document.getElementById("renderer"),{baseUrl:"./"});'
            + 'const renderer=document.getElementById("renderer");window.fetch=async()=>{throw new Error(attack)};'
            + 'await window.loadForm("sandbox");const sandbox=document.getElementById("form-container");'
            + 'setTimeout(()=>{document.getElementById("security-result").textContent=JSON.stringify({'
            + 'rendererText:renderer.textContent,rendererElements:renderer.querySelectorAll("img,script").length,'
            + 'sandboxText:sandbox.textContent,sandboxElements:sandbox.querySelectorAll("img,script").length,xss:window.__xss});},50);})();<\/script>');
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-extensions', '--virtual-time-budget=500',
            '--user-data-dir=' + path.join(temporary, 'profile'), '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error);
        assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="security-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Browser did not complete load-error assertions: ' + result.stderr);
        const check = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        assert.match(check.rendererText, /<img src=x/);
        assert.match(check.sandboxText, /<img src=x/);
        assert.equal(check.rendererElements, 0);
        assert.equal(check.sandboxElements, 0);
        assert.equal(check.xss, 0);
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
