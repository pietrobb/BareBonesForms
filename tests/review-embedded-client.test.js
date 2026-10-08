'use strict';
// 2.2.0 embedded mode in real Chromium: BBF.render host options, BBF.registerType, BBF.enhance on bbf_render_html output.

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

const SERVER_DEFINITION = {
    id: 'admin-product', name: 'Product', fields: [
        { name: 'title', type: 'text', label: 'Title', required: true },
        { name: 'category', type: 'select', label: 'Category', required: true, options_from: '/api/categories', options: [{ value: 'x', label: 'Fallback' }] },
        { name: 'tags', type: 'checkbox', label: 'Tags', options: ['new', 'sale'] },
        { name: 'sale_price', type: 'number', label: 'Sale price', show_if: { field: 'tags', op: 'contains', value: 'sale' } },
        { name: 'image', type: 'file', label: 'Image', required: true, accept: ['png', 'jpg'] },
    ],
};

/** Runs inside Chromium; fetch and XMLHttpRequest are recorders the checks answer. */
async function browserChecks(serverHtml, serverDefinition) {
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
    let xhrs = 0;
    window.XMLHttpRequest = class { constructor() { xhrs++; this.upload = { addEventListener() {} }; } open() {} send() {} setRequestHeader() {} addEventListener() {} abort() {} };
    const fetches = [];
    window.fetch = (url, options) => new Promise(resolve => fetches.push({ url: String(url), options, resolve }));
    const response = (status, body) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => body });
    const errorOf = (root, name) => (root.querySelector(`[data-field="${name}"] .bbf-field-error`) || {}).textContent || '';

    // 1. BBF.render in embedded mode: definition, values, optionsResolver, hiddenFields, submitUrl, a custom type.
    BBF.registerType('x-stars', {
        render(field, value, ctx) { const el = document.createElement('input'); el.type = 'range'; el.min = 0; el.max = 5; el.id = ctx.id; el.className = 'stars'; el.value = value ?? 0; return el; },
        value(wrap) { return Number(wrap.querySelector('.stars').value); },
        validate(value) { return value < 2 ? 'Too few stars' : null; },
    });
    let threw = false;
    try { BBF.registerType('stars', { render() {} }); } catch (error) { threw = true; }
    check('registerType refuses names without x-', () => threw);
    const definition = { id: 'product', name: 'Product', fields: [
        { name: 'title', type: 'text', label: 'Title', required: true },
        { name: 'category', type: 'select', label: 'Category', options_from: '/api/categories', required: true },
        { name: 'color', type: 'radio', label: 'Color', options: ['red', 'blue'], other: true },
        { name: 'tags', type: 'checkbox', label: 'Tags', options: ['new', 'sale'] },
        { name: 'sale_price', type: 'number', label: 'Sale price', show_if: { field: 'tags', op: 'contains', value: 'sale' } },
        { name: 'stars', type: 'x-stars', label: 'Stars', required: true },
    ] };
    const sources = [];
    const c1 = document.body.appendChild(document.createElement('div'));
    await BBF.render('product', c1, {
        definition, submitUrl: '/admin/products/5', hiddenFields: { csrf_token: 'host-token' }, lang: 'en',
        values: { title: 'Tent', category: '9', color: 'green', tags: ['sale'], sale_price: '99', stars: 3 },
        optionsResolver: async source => { sources.push(source); return [{ value: '7', label: 'Tents' }, { value: '9', label: 'Halls' }]; },
    });
    const f1 = c1.querySelector('form');
    check('definition option: nothing is fetched from submit.php', () => fetches.length === 0 && f1 !== null);
    check('optionsResolver replaces fetching options_from', () => sources.join() === '/api/categories' && f1.querySelector('select[name="category"]').options.length === 2);
    check('submitUrl: no BBF CSRF field, the host hidden field is there', () => !f1.querySelector('[name="_bbf_csrf"]') && f1.querySelector('[name="csrf_token"]').value === 'host-token');
    check('values fill text and select', () => f1.querySelector('[name="title"]').value === 'Tent' && f1.querySelector('[name="category"]').value === '9');
    check('a value outside the options is the shown "Other" text', () => f1.querySelector('input[name="color"][value="__other__"]').checked
        && f1.querySelector('[name="color_other"]').value === 'green' && f1.querySelector('[name="color_other"]').style.display === '');
    check('checkbox values and the condition they satisfy', () => f1.querySelector('input[name="tags"][value="sale"]').checked
        && f1.querySelector('[data-field="sale_price"]').getAttribute('data-conditional-hidden') !== 'true' && f1.querySelector('[name="sale_price"]').value === '99');
    check('custom type renders in a BBF field wrapper', () => f1.querySelector('[data-field="stars"]').className === 'bbf-field bbf-field-x-stars'
        && f1.querySelector('.stars').value === '3' && f1.querySelector('label[for="' + f1.querySelector('.stars').id + '"]'));
    f1.requestSubmit();
    await waitFor(() => fetches.length === 1);
    const sent = fetches[0];
    const body = JSON.parse(sent.options.body);
    check('posts JSON to submitUrl', () => sent.url.endsWith('/admin/products/5') && sent.options.headers['Content-Type'] === 'application/json');
    check('body carries values, custom type value and host token, no BBF CSRF', () => body.title === 'Tent' && body.category === '9'
        && body.color === '__other__' && body.color_other === 'green' && body.tags === 'sale' && body.stars === 3
        && body.csrf_token === 'host-token' && !('_bbf_csrf' in body));
    sent.resolve(response(422, { status: 'error', message: 'Fix the fields.', errors: { title: 'Title is taken' } }));
    await waitFor(() => errorOf(f1, 'title') !== '');
    check('host errors show under their fields', () => errorOf(f1, 'title') === 'Title is taken'
        && f1.querySelector('[data-field="title"]').classList.contains('bbf-has-error'));
    f1.querySelector('.stars').value = '1';
    f1.requestSubmit();
    await tick();
    check('custom type validate() stops the submit', () => fetches.length === 1 && errorOf(f1, 'stars') === 'Too few stars');

    const c2 = document.body.appendChild(document.createElement('div'));
    const consoleError = console.error; console.error = () => {};
    await BBF.render('bad', c2, { definition: { id: 'bad', fields: [{ name: 't', type: 'x-missing' }] }, submitUrl: '/x' });
    console.error = consoleError;
    check('an unregistered custom type fails to render, loudly', () => !c2.querySelector('form') && /x-missing/.test(c2.textContent));

    // 2. File fields in embedded mode: no upload to submit.php; multipart with the file; stored file and remove flag.
    const c3 = document.body.appendChild(document.createElement('div'));
    await BBF.render('brand', c3, {
        definition: { id: 'brand', fields: [{ name: 'name', type: 'text', label: 'Name' }, { name: 'logo', type: 'file', label: 'Logo', required: true, accept: ['png'] }] },
        submitUrl: '/admin/brands/2', hiddenFields: { csrf_token: 't' }, values: { name: 'Acme', logo: { url: '/u/acme.png', name: 'acme.png' } },
    });
    const f3 = c3.querySelector('form');
    check('stored file: preview and remove box like bbf_render_html', () => f3.querySelector('.bbf-file-current img.bbf-file-preview').getAttribute('src') === '/u/acme.png'
        && f3.querySelector('input[name="logo__remove"]').type === 'checkbox');
    f3.requestSubmit();
    await waitFor(() => fetches.length === 2);
    const keep = fetches[1].options.body;
    check('stored file satisfies required; multipart without a file means keep', () => keep instanceof FormData && keep.get('name') === 'Acme'
        && !keep.has('logo') && !keep.has('logo__remove') && keep.get('csrf_token') === 't' && !fetches[1].options.headers);
    fetches[1].resolve(response(200, { status: 'ok' }));
    await waitFor(() => !f3._bbfSubmitting);
    const input = f3.querySelector('.bbf-file-input');
    const transfer = new DataTransfer();
    transfer.items.add(new File(['png'], 'new.png', { type: 'image/png' }));
    input.files = transfer.files;
    input.dispatchEvent(new Event('change'));
    await tick();
    check('a chosen file is not uploaded to submit.php', () => xhrs === 0 && f3.querySelector('.bbf-file-done'));
    f3.requestSubmit();
    await waitFor(() => fetches.length === 3);
    const replaced = fetches[2].options.body;
    check('the chosen file goes with the submit under the field name', () => replaced.get('logo') instanceof File && replaced.get('logo').name === 'new.png');
    fetches[2].resolve(response(200, { status: 'ok' }));
    await waitFor(() => !f3._bbfSubmitting);
    f3.querySelector('input[name="logo__remove"]').checked = true;
    f3.requestSubmit();
    await tick();
    check('removing the stored file of a required field is refused in the browser', () => fetches.length === 3 && errorOf(f3, 'logo') !== '');

    // 3. BBF.enhance on the HTML bbf_render_html() produced on the server.
    const c4 = document.body.appendChild(document.createElement('div'));
    c4.innerHTML = serverHtml;
    const f4 = c4.querySelector('form');
    BBF.enhance(f4, serverDefinition, { lang: 'en' });
    let nativeSubmits = 0;
    f4.addEventListener('submit', event => { if (!event.defaultPrevented) nativeSubmits++; event.preventDefault(); });
    check('enhance turns browser validation off and keeps the server action', () => f4.noValidate && f4.getAttribute('action') === '/admin.php?page=product');
    f4.querySelector('[name="title"]').value = '';
    f4.requestSubmit();
    check('an invalid server-rendered form is stopped with BBF errors', () => nativeSubmits === 0 && errorOf(f4, 'title') !== '' && document.activeElement === f4.querySelector('[name="title"]'));
    const saleWrap = f4.querySelector('[data-field="sale_price"]');
    check('server-rendered hidden condition starts hidden', () => saleWrap.getAttribute('data-conditional-hidden') === 'true');
    const sale = f4.querySelector('input[name="tags[]"][value="sale"]');
    sale.checked = true; sale.dispatchEvent(new Event('change', { bubbles: true }));
    await tick();
    check('conditions read name[] checkboxes', () => saleWrap.getAttribute('data-conditional-hidden') !== 'true' && saleWrap.style.display !== 'none');
    f4.querySelector('[name="title"]').value = 'Tent';
    f4.querySelector('[name="category"]').value = '9';
    f4.requestSubmit();
    const shownErrors = Array.from(f4.querySelectorAll('.bbf-field-error')).map(e => e.textContent).filter(Boolean).join(' | ');
    check('server-resolved options and the stored file pass: the form posts natively' + (shownErrors ? ' — ' + shownErrors : ''), () => nativeSubmits === 1 && shownErrors === '');
    f4.querySelector('[name="image__remove"]').checked = true;
    f4.requestSubmit();
    check('removing the required stored file stops the native post', () => nativeSubmits === 1 && errorOf(f4, 'image') !== '');
    check('no script errors', () => window.__errors.length === 0);
    const output = document.createElement('pre'); output.id = 'embedded-result';
    output.textContent = JSON.stringify(results); document.body.appendChild(output);
}

test('embedded mode client (render options, custom types, enhance) in real Chromium', () => {
    const renderScript = `require ${JSON.stringify(path.join(__dirname, '..', 'bbf_render.php'))};
        echo bbf_render_html(json_decode(stream_get_contents(STDIN), true), ['action' => '/admin.php?page=product', 'lang' => 'en',
            'values' => ['title' => 'Tent', 'category' => '7', 'image' => ['url' => '/u/tent.png', 'name' => 'tent.png']],
            'options_resolver' => fn($s) => [['value' => '7', 'label' => 'Tents'], ['value' => '9', 'label' => 'Halls']]]);`;
    const php = spawnSync(process.env.PHP_BINARY || 'php', ['-r', renderScript], { input: JSON.stringify(SERVER_DEFINITION), encoding: 'utf8' });
    assert.equal(php.status, 0, php.stderr || php.stdout);
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-embedded-ui-'));
    try {
        const source = fs.readFileSync(path.join(__dirname, '..', 'bbf.js'), 'utf8').replace(/<\/script/gi, '<\\/script');
        const checks = browserChecks.toString().replace(/<\/script/gi, '<\\/script');
        const args = JSON.stringify([php.stdout, SERVER_DEFINITION]).replace(/</g, '\\u003c');
        const page = path.join(temporary, 'embedded.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; img-src \'none\'; connect-src \'none\'">'
            + '<body><script>' + source + '</script><script>(async()=>{try{await (' + checks + ')(...' + args + ');}'
            + 'catch(error){const output=document.createElement("pre");output.id="embedded-result";'
            + 'output.textContent=JSON.stringify([{name:error.stack||error.message,ok:false}]);document.body.appendChild(output);}})();</script></body>');
        const browserArgs = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions',
            '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' + path.join(temporary, 'profile'),
            '--virtual-time-budget=10000', '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) browserArgs.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), browserArgs, { encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="embedded-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Chromium did not complete the embedded checks: ' + result.stderr + '\nDOM: ' + result.stdout.slice(-4000));
        const browserResults = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        const failures = browserResults.filter(check => !check.ok);
        assert.equal(failures.length, 0, JSON.stringify({ passed: browserResults.length - failures.length, failures }, null, 2));
        assert.equal(browserResults.length, 25, 'all embedded browser checks executed');
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
