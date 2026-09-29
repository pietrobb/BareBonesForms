'use strict';
// Every language pack carries every message the client and server can show, with the same placeholders.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const holders = text => (String(text).match(/\{\w+\}/g) || []).sort().join(',');

/** The English dictionary literal in bbf.js (`lang: { ... },` before the registered packs). */
function builtin() {
    const source = read('bbf.js');
    const start = source.indexOf('lang: {');
    const end = source.indexOf('// Registered language packs');
    assert.ok(start > 0 && end > start, 'bbf.js dictionary located');
    const literal = source.slice(start + 'lang: '.length, end).trim().replace(/,$/, '');
    return vm.runInNewContext(`(${literal})`);
}

function jsPack(file) {
    let messages = null;
    vm.runInNewContext(read(`lang/${file}`), { BBF: { registerLang: (code, m) => { messages = m; } } });
    return messages;
}

function phpPack(file) {
    const out = {};
    for (const m of read(`lang/${file}`).matchAll(/^\s*'(\w+)'\s*=>\s*'((?:[^'\\]|\\.)*)',/gm)) out[m[1]] = m[2].replace(/\\(['\\])/g, '$1');
    return out;
}

const langs = fs.readdirSync(path.join(root, 'lang')).filter(f => f.endsWith('.js')).map(f => f.slice(0, -3));

test('every client language pack has every built-in message with the same placeholders', () => {
    const en = builtin();
    assert.ok(Object.keys(en).length >= 60, 'built-in dictionary parsed');
    for (const lang of langs) {
        const pack = jsPack(`${lang}.js`);
        assert.ok(pack, `${lang}.js registers a pack`);
        for (const [key, text] of Object.entries(en)) {
            assert.equal(typeof pack[key], 'string', `${lang}.js has ${key}`);
            assert.equal(holders(pack[key]), holders(text), `${lang}.js ${key} placeholders`);
        }
    }
});

test('every server language pack has every lang/en.php message with the same placeholders', () => {
    const en = phpPack('en.php');
    assert.ok(Object.keys(en).length >= 40, 'en.php parsed');
    for (const lang of langs) {
        const pack = phpPack(`${lang}.php`);
        for (const [key, text] of Object.entries(en)) {
            assert.equal(typeof pack[key], 'string', `${lang}.php has ${key}`);
            assert.equal(holders(pack[key]), holders(text), `${lang}.php ${key} placeholders`);
        }
    }
});

// Older packs whose browser and server wording still differ (both correct); new divergences are refused.
const knownDivergent = new Set(['da emailMismatch', 'el dateMin', 'el dateMax', 'es dateMin', 'es dateMax', 'et dateMin', 'et dateMax',
    'fi dateMax', 'hu dateMin', 'hu dateMax', 'ja emailMismatch', 'ja dateMin', 'ja dateMax', 'nb emailMismatch', 'nl emailMismatch',
    'pt-br dateMin', 'pt-br dateMax', 'pt dateMin', 'pt dateMax', 'ru dateMin', 'ru dateMax', 'tlh tooLong', 'tr dateMin', 'tr dateMax',
    'zh-tw dateMin', 'zh-tw dateMax', 'zh dateMin', 'zh dateMax']);

test('a message the browser and the server both show reads the same in both packs', () => {
    const mismatches = [];
    for (const lang of langs) {
        const client = jsPack(`${lang}.js`);
        const server = phpPack(`${lang}.php`);
        for (const [key, text] of Object.entries(server)) {
            if (typeof client[key] === 'string' && client[key] !== text && !knownDivergent.has(`${lang} ${key}`)) {
                mismatches.push(`${lang} ${key}: "${client[key]}" vs "${text}"`);
            }
        }
    }
    assert.deepEqual(mismatches, []);
    assert.ok(![...knownDivergent].some(k => k.startsWith('lt ') || k.startsWith('sk ') || k.startsWith('cs ') || k.startsWith('en ')),
        'lt, sk, cs and en packs are fully consistent');
});

test('the Lithuanian pack carries the wording used in production on airdomes.pro', () => {
    const client = jsPack('lt.js');
    const server = phpPack('lt.php');
    for (const pack of [client, server]) {
        assert.equal(pack.invalidEmail, '{label} turi būti teisingas el. pašto adresas.');
        assert.equal(pack.numberMax, '{label} negali viršyti {max}.');
        assert.equal(pack.invalidOption, '{label} turi neteisingą pasirinkimą.');
    }
    assert.equal(client.crossFieldDefault, 'Patikrinkite įvestus duomenis.');
    assert.equal(client.formNotFound, 'Forma \u201E{id}\u201C nerasta ({status})', 'Lithuanian quotes „…“ stay paired');
    for (const text of [...Object.values(client), ...Object.values(server)]) assert.ok(!/neteisiną/.test(text), `no typo in "${text}"`);
});

test('every message key used in code exists in the English sources', () => {
    const en = builtin();
    const js = read('bbf.js');
    for (const m of js.matchAll(/\b(?:_t|t)\('(\w+)'/g)) assert.ok(m[1] in en, `bbf.js uses ${m[1]}`);
    const enPhp = phpPack('en.php');
    for (const file of ['bbf_uploads.php', 'bbf_functions.php', 'submit.php']) {
        for (const m of read(file).matchAll(/\b(?:msg|bbf_uploads_t)\('(\w+)'/g)) assert.ok(m[1] in enPhp, `${file} uses ${m[1]}`);
    }
});
