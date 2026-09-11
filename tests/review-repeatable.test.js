'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

test('F7 repeatable client behavioral gate', () => {
    const rendererSuite = path.join(__dirname, 'review-renderer.test.js');
    const env = { ...process.env };
    delete env.NODE_TEST_CONTEXT;
    const result = spawnSync(process.execPath, [
        '--test',
        '--test-name-pattern=repeatable',
        rendererSuite,
    ], {
        cwd: path.join(__dirname, '..'),
        env,
        encoding: 'utf8',
        maxBuffer: 4 * 1024 * 1024,
    });

    assert.equal(result.error, undefined, result.error?.message);
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    for (const name of [
        'repeatable controls enforce bounds while row and input identities stay stable',
        'repeatable conditions, validation, and serialization stay row-local',
        'repeatable reset restores minimum rows and page navigation validates current rows',
        'submit emits structured repeatable rows and maps dotted server errors to current row controls',
        'repeatable lookup and autocomplete mappings stay inside their originating row',
        'sandbox submit serializes repeatable rows with the production collector',
    ]) {
        assert.match(result.stdout, new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    }
    assert.match(result.stdout, /(?:#|ℹ)\s+pass 6\b/);
    assert.match(result.stdout, /(?:#|ℹ)\s+fail 0\b/);
});
