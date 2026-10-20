// Run: node --test js/tests/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import escapeHtml from '../src/forum/utils/escapeHtml.js';

test('quotes are escaped, so a value cannot leave an attribute', () => {
    // A magnet name from the post's dn= parameter, as it reaches title="…".
    const name = 'x" onmouseover="alert(1)';
    assert.equal(escapeHtml(name), 'x&quot; onmouseover=&quot;alert(1)');
    assert.equal(`<a title="${escapeHtml(name)}">`, '<a title="x&quot; onmouseover=&quot;alert(1)">');
    assert.equal(escapeHtml("it's"), 'it&#39;s');
});

test('markup is escaped', () => {
    assert.equal(escapeHtml('<img src=x onerror=alert(1)> & co'), '&lt;img src=x onerror=alert(1)&gt; &amp; co');
});

test('non-strings are stringified, nothing becomes empty', () => {
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
    assert.equal(escapeHtml(42), '42');
});
