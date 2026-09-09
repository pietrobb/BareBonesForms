'use strict';

// G7 real-browser viewer inbox UI regression. Run: node --test tests/review-inbox.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');

const source = fs.readFileSync(path.join(__dirname, '..', 'viewer.php'), 'utf8');

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
    const check = (name, fn) => {
        try { fn(); results.push({ name, ok: true }); }
        catch (error) { results.push({ name, ok: false, error: error.message }); }
    };
    const wait = async (turns = 20) => { for (let i = 0; i < turns; i++) await Promise.resolve(); };
    const v = window.viewerInboxUI;
    const panel = document.getElementById('panel-main');
    const hostile = '<img src=x onerror=window.__reviewXss=true>';

    await v.selectForm('alpha');
    await wait();
    check('review permission exposes native labelled inbox controls', () => {
        for (const id of ['review-status-filter', 'review-tags-filter', 'saved-filter-select', 'saved-filter-name']) {
            const control = document.getElementById(id);
            if (!control) throw new Error('missing control: ' + id);
            if (!document.querySelector(`label[for="${id}"]`)) throw new Error('missing label: ' + id);
        }
        if (panel.querySelectorAll('.review-badge').length < 2) throw new Error('review badges missing');
        if (panel.querySelector('img,script')) throw new Error('hostile list/filter value created active markup');
        if (!panel.textContent.includes(hostile)) throw new Error('escaped hostile value not visible as text');
        if (window.__reviewXss) throw new Error('hostile list value executed');
    });

    const status = document.getElementById('review-status-filter');
    const tags = document.getElementById('review-tags-filter');
    status.value = 'in-progress'; tags.value = 'vip, ' + hostile;
    document.getElementById('btn-filter').click();
    await wait();
    check('status and repeated tags survive URL state and reach pre-pagination API', () => {
        const hash = new URLSearchParams(location.hash.slice(1));
        if (hash.get('status') !== 'in-progress') throw new Error('status missing from URL');
        if (JSON.stringify(hash.getAll('tag')) !== JSON.stringify(['vip', hostile])) throw new Error('tags missing from URL');
        const request = window.__calls.filter(call => call.action === 'submissions').at(-1);
        if (request.review !== '1' || request.status !== 'in-progress') throw new Error('review/status API query missing');
        if (JSON.stringify(request.tags) !== JSON.stringify(['vip', hostile])) throw new Error('tag API query missing');
    });

    document.getElementById('saved-filter-select').value = 'existing';
    document.getElementById('saved-filter-select').dispatchEvent(new Event('change', { bubbles: true }));
    await wait();
    check('keyboard-native saved filter selection applies criteria', () => {
        if (v.state.selectedFilter !== 'existing' || v.state.search !== 'Alice') throw new Error('saved criteria not applied');
        if (v.state.reviewStatus !== 'done' || JSON.stringify(v.state.reviewTags) !== JSON.stringify(['priority'])) {
            throw new Error('saved review criteria not applied');
        }
        if (new URLSearchParams(location.hash.slice(1)).get('saved') !== 'existing') throw new Error('saved ID not retained');
    });

    document.getElementById('saved-filter-name').value = 'Updated filter';
    document.getElementById('save-review-filter').click();
    await wait();
    check('saved filter update sends CAS revision and refreshes revision', () => {
        const call = window.__posts.filter(post => post.action === 'review_filter_save').at(-1);
        if (call.body.id !== 'existing' || call.body.revision !== 2 || call.body.name !== 'Updated filter') {
            throw new Error('incorrect saved-filter update payload');
        }
        if (v.state.savedFilters.find(filter => filter.id === 'existing')?.revision !== 3) throw new Error('saved revision not refreshed');
    });

    document.getElementById('saved-filter-select').value = '';
    document.getElementById('saved-filter-select').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('saved-filter-name').value = 'My queue';
    document.getElementById('save-review-filter').click();
    await wait();
    const createdId = v.state.selectedFilter;
    check('saved filter create and delete use server-returned identity', () => {
        const create = window.__posts.filter(post => post.action === 'review_filter_save').at(-1);
        if (!createdId.startsWith('filter_') || create.body.id !== createdId || create.body.revision !== 0) {
            throw new Error('incorrect create identity/revision');
        }
        if (!document.getElementById('delete-review-filter')) throw new Error('delete control missing after create');
    });
    document.getElementById('delete-review-filter').click();
    await wait();
    check('saved filter delete removes only selected filter', () => {
        const remove = window.__posts.filter(post => post.action === 'review_filter_delete').at(-1);
        if (remove.body.id !== createdId || remove.body.revision !== 1) throw new Error('incorrect delete CAS payload');
        if (v.state.savedFilters.some(filter => filter.id === createdId) || v.state.selectedFilter) throw new Error('deleted filter retained');
        if (!v.state.savedFilters.some(filter => filter.id === 'existing')) throw new Error('sibling filter removed');
    });

    document.getElementById('saved-filter-select').value = 'existing';
    document.getElementById('saved-filter-select').dispatchEvent(new Event('change', { bubbles: true }));
    window.__filterConflict = true;
    document.getElementById('saved-filter-name').value = 'Overwrite winner';
    document.getElementById('save-review-filter').click(); await wait();
    check('saved-filter 409 displays authoritative winner criteria', () => {
        if (v.state.selectedFilter !== 'existing' || v.state.search !== 'Winner' || v.state.reviewStatus !== 'new'
            || JSON.stringify(v.state.reviewTags) !== JSON.stringify(['server'])) throw new Error('winner criteria not applied');
        if (document.getElementById('saved-filter-name').value !== 'Server winner') throw new Error('winner name not shown');
        const refresh = window.__calls.filter(call => call.action === 'submissions').at(-1);
        if (refresh.q !== 'Winner' || refresh.status !== 'new' || JSON.stringify(refresh.tags) !== JSON.stringify(['server'])) {
            throw new Error('winner criteria did not refresh submissions');
        }
    });
    window.__filterConflict = false; window.__filterMissing = true;
    document.getElementById('save-review-filter').click(); await wait();
    check('saved-filter conflict with null winner removes concurrently deleted record', () => {
        if (v.state.selectedFilter || v.state.savedFilters.some(filter => filter.id === 'existing')) {
            throw new Error('missing winner retained stale local filter');
        }
    });
    window.__filterMissing = false;

    const listRequestsBeforeRace = window.__calls.filter(call => call.action === 'submissions').length;
    document.getElementById('saved-filter-name').value = 'Slow create';
    window.__deferFilter = true;
    document.getElementById('save-review-filter').click(); await wait();
    v.renderMain();
    window.__filterFail = true;
    document.getElementById('saved-filter-name').value = 'Newer failed attempt';
    document.getElementById('save-review-filter').click(); await wait();
    window.__filterFail = false;
    window.__resolveFilter(); await wait();
    check('generation guard rejects late saved-filter response without navigation changes', () => {
        if (v.state.selectedFilter || v.state.savedFilters.some(filter => filter.name === 'Slow create')) {
            throw new Error('late filter response clobbered newer same-route state');
        }
        if (window.__calls.filter(call => call.action === 'submissions').length !== listRequestsBeforeRace) {
            throw new Error('race test accidentally relied on navigation/list generation');
        }
    });

    const listHash = location.hash;
    await v.openDetail('sub_1');
    await wait();
    check('detail review editor is escaped and accessible', () => {
        const card = panel.querySelector('.review-card');
        if (!card || card.getAttribute('aria-labelledby') !== 'review-title') throw new Error('review landmark missing');
        for (const id of ['review-status', 'review-tags', 'review-notes']) {
            if (!document.querySelector(`label[for="${id}"]`)) throw new Error('missing detail label: ' + id);
        }
        if (card.querySelector('img,script') || window.__reviewXss) throw new Error('hostile review value created active markup');
        if (document.getElementById('review-notes').value !== hostile) throw new Error('note was not preserved as text');
    });

    document.getElementById('review-status').value = 'done';
    document.getElementById('review-tags').value = 'closed, safe';
    document.getElementById('review-notes').value = 'Handled';
    document.getElementById('save-review').click();
    await wait();
    check('review save uses exact optimistic revision and refreshes detail', () => {
        const save = window.__posts.filter(post => post.action === 'review_update').at(-1);
        if (save.body.id !== 'sub_1' || save.body.revision !== 4) throw new Error('incorrect review CAS target');
        if (JSON.stringify(save.body.patch) !== JSON.stringify({ status: 'done', tags: ['closed', 'safe'], notes: 'Handled' })) {
            throw new Error('incorrect review patch');
        }
        if (panel._currentReview.revision !== 5 || document.getElementById('review-notes').value !== 'Handled') {
            throw new Error('saved review not rendered');
        }
    });

    window.__reviewConflict = true;
    document.getElementById('review-notes').value = 'Overwrite attempt';
    document.getElementById('save-review').click();
    await wait();
    check('409 loads winner and explicitly rejects local overwrite', () => {
        if (panel._currentReview.revision !== 8 || document.getElementById('review-notes').value !== 'Other user won') {
            throw new Error('conflict winner not loaded');
        }
        if (!document.getElementById('header-status').textContent.includes('not saved')) throw new Error('conflict not announced');
    });
    window.__reviewConflict = false;

    v.state.detailReturn = null; // Exercise bookmarked-detail fallback without an asynchronous browser history task.
    document.getElementById('btn-back').click();
    await wait();
    check('detail Back restores all inbox filters', () => {
        if (location.hash !== listHash || v.state.detail !== null) throw new Error('list route not restored');
        if (!document.getElementById('review-status-filter')) throw new Error('review toolbar not restored');
    });

    await v.openDetail('sub_1'); await wait();
    window.__deferReview = true;
    document.getElementById('review-notes').value = 'Late poison';
    document.getElementById('save-review').click();
    await v.showDashboard(); await wait();
    const dashboard = panel.innerHTML;
    window.__resolveReview(); await wait(40);
    check('late review response cannot overwrite newer navigation', () => {
        if (v.state.view !== 'dashboard' || panel.innerHTML !== dashboard || panel.textContent.includes('Late poison')) {
            throw new Error('late mutation overwrote dashboard');
        }
    });

    check('no browser errors or XSS execution', () => {
        if (window.__errors.length) throw new Error(window.__errors.join('; '));
        if (window.__reviewXss) throw new Error('hostile markup executed');
    });
    const output = document.createElement('pre'); output.id = 'inbox-ui-result';
    output.textContent = JSON.stringify(results); document.body.appendChild(output);
}

test('viewer inbox workflow in real Chromium', () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bbf-viewer-inbox-ui-'));
    try {
        const start = source.indexOf('<script>');
        const end = source.lastIndexOf('</script>');
        assert.ok(start >= 0 && end > start, 'actual viewer script exists');
        let script = source.slice(start + 8, end);
        const access = { admin: false, storage: true, delete_forms: { alpha: true },
            forms: ['alpha'], permissions: ['read', 'review'] };
        const values = { formsList: [{ id: 'alpha', name: 'Alpha', count: 1 }],
            viewerToken: 'isolated-csrf', canDelete: access, siteName: 'Inbox fixture', viewerLang: 'en' };
        for (const [name, value] of Object.entries(values)) {
            const marker = '<?= json_encode($' + name + ') ?>';
            assert.ok(script.includes(marker), 'bootstrap marker: ' + name);
            script = script.replaceAll(marker, JSON.stringify(value));
        }
        const init = 'renderFormList(FORMS);\nrestoreRoute();';
        assert.ok(script.includes(init), 'replace only automatic route loading');
        script = script.replace(init, `renderFormList(FORMS);
            window.viewerInboxUI = { state, api, selectForm, openDetail, loadPage, showDashboard, restoreRoute, renderMain };`);
        assert.ok(!script.includes('<?'), 'all PHP bootstrap values replaced');
        const css = source.match(/<style>([\s\S]*?)<\/style>/)?.[1];
        assert.ok(css, 'actual viewer styles included');
        const inline = text => text.replace(/<\/script/gi, '<\\/script');
        const hostile = '<img src=x onerror=window.__reviewXss=true>';
        const guard = `window.__errors=[]; window.__calls=[]; window.__posts=[]; window.__reviewXss=false;
            window.addEventListener('error', e => window.__errors.push(e.message));
            window.addEventListener('unhandledrejection', e => window.__errors.push(String(e.reason)));
            window.__review = {status:'in-progress',notes:${JSON.stringify(hostile)},tags:['vip',${JSON.stringify(hostile)}],revision:4,updated_at:'2026-09-09T12:00:00Z',updated_by:'reviewer'};
            window.__filters=[{id:'existing',name:${JSON.stringify(hostile)},criteria:{q:'Alice',status:'done',tags:['priority']},revision:2,updated_at:'2026-09-09T12:00:00Z'},
                {id:'other',name:'Other queue',criteria:{q:'Other'},revision:1,updated_at:'2026-09-09T12:00:00Z'}];
            window.fetch=async (url, options={}) => {
                const query=new URLSearchParams(url.slice(url.indexOf('?')+1)); const action=query.get('action');
                const call={action,tags:query.getAll('tags[]')}; query.forEach((value,key)=>{if(key!=='tags[]')call[key]=value;}); window.__calls.push(call);
                const response=(status,data)=>({ok:status<400,status,json:async()=>data});
                if(action==='stats') return response(200,{total:1,today:1,this_week:1,this_month:1});
                if(action==='submissions') return response(200,{total:1,form_def:{name:'Alpha',fields:[{name:'answer',label:'Answer',type:'text'}]},submissions:[{id:'sub_1',form:'alpha',data:{answer:'Response'},meta:{submitted:'2026-09-09T10:00:00Z'},review:window.__review}]});
                if(action==='review_filters') return response(200,{filters:window.__filters});
                if(action==='dashboard') return response(200,{total:1,today:1,this_week:1,per_form:[{id:'alpha',name:'Alpha',total:1,today:1}],recent:[],form_defs:{}});
                if(action==='detail') return response(200,{submission:{id:'sub_1',form:'alpha',data:{answer:'Response'},meta:{submitted:'2026-09-09T10:00:00Z'}},form_def:{name:'Alpha',fields:[{name:'answer',label:'Answer',type:'text'}]},delivery:{state:'settled',jobs:[]},review:window.__review});
                const body=JSON.parse(options.body||'{}'); window.__posts.push({action,body});
                if(action==='review_update') {
                    if(window.__reviewConflict) { window.__review={...window.__review,notes:'Other user won',revision:8}; return response(409,{error:'Review revision conflict.',reason:'conflict',review:window.__review}); }
                    const updated={...window.__review,...body.patch,revision:body.revision+1,updated_at:'2026-09-09T12:01:00Z',updated_by:'reviewer'};
                    if(window.__deferReview) return new Promise(resolve=>{window.__resolveReview=()=>{window.__review=updated;resolve(response(200,{ok:true,review:updated}));};});
                    window.__review=updated; return response(200,{ok:true,review:updated});
                }
                if(action==='review_filter_save') {
                    if(window.__filterConflict) { const winner={id:body.id,name:'Server winner',criteria:{q:'Winner',status:'new',tags:['server']},revision:9,updated_at:'2026-09-09T12:02:00Z'}; window.__filters=window.__filters.filter(f=>f.id!==winner.id).concat(winner); return response(409,{error:'Review revision conflict.',reason:'conflict',filter:winner}); }
                    if(window.__filterMissing) { window.__filters=window.__filters.filter(f=>f.id!==body.id); return response(409,{error:'Review revision conflict.',reason:'conflict',filter:null}); }
                    const saved={id:body.id,name:body.name,criteria:body.criteria,revision:body.revision+1,updated_at:'2026-09-09T12:01:00Z'};
                    if(window.__filterFail) return response(503,{error:'Fixture filter failure.'});
                    if(window.__deferFilter) { window.__deferFilter=false; return new Promise(resolve=>{window.__resolveFilter=()=>resolve(response(200,{ok:true,filter:saved}));}); }
                    window.__filters=window.__filters.filter(f=>f.id!==saved.id).concat(saved); return response(200,{ok:true,filter:saved});
                }
                if(action==='review_filter_delete') { window.__filters=window.__filters.filter(f=>f.id!==body.id); return response(200,{ok:true,deleted:1}); }
                throw new Error('Unexpected fetch: '+url);
            };`;
        const ids = ['form-list', 'panel-main', 'panel-forms', 'header-status', 'header-title',
            'drawer-backdrop', 'btn-hamburger'];
        const page = path.join(temporary, 'inbox.html');
        fs.writeFileSync(page, '<!doctype html><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; connect-src \'none\'; form-action \'none\'">'
            + '<style>' + css + '</style>' + ids.map(id => `<div id="${id}"></div>`).join('')
            + '<script>' + inline(guard) + '</script><script>' + inline(script) + '</script>'
            + '<script>(' + inline(browserChecks.toString()) + ')();</script>');
        const args = ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions',
            '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' + path.join(temporary, 'profile'),
            '--dump-dom', pathToFileURL(page).href];
        if (process.platform !== 'win32' && process.getuid?.() === 0) args.unshift('--no-sandbox');
        const result = spawnSync(browserExecutable(), args, { encoding: 'utf8', timeout: 45000,
            maxBuffer: 4 * 1024 * 1024, windowsHide: true });
        assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
        const encoded = result.stdout.match(/<pre id="inbox-ui-result">([\s\S]*?)<\/pre>/)?.[1];
        assert.ok(encoded, 'Browser did not complete assertions: ' + result.stderr);
        const checks = JSON.parse(encoded.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
        const failures = checks.filter(check => !check.ok);
        assert.equal(failures.length, 0, JSON.stringify({ passed: checks.length - failures.length, failures }, null, 2));
        assert.equal(checks.length, 15, 'all inbox browser checks executed');
    } finally {
        fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
