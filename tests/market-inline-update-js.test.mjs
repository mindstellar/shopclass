// Runs clearInlineUpdate() from oc-admin/themes/modern/js/market.js and checks the inline update button uses the shared routine.
// Use: node tests/market-inline-update-js.test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('..', import.meta.url));
const src = readFileSync(root + 'oc-admin/themes/modern/js/market.js', 'utf8');
const start = src.indexOf('function clearInlineUpdate(');
const end = src.indexOf('function cssEscape(', start);
assert.ok(start > 0 && end > start, 'clearInlineUpdate not found');

const removed = [];
const note = { parentNode: { removeChild: (n) => removed.push(n) } };
const version = { textContent: '1.0' };
const row = { querySelector: (sel) => (sel === '[data-installed-version]' ? null : sel === '.col-version' ? version : null) };
const seen = [];
const document = {
  querySelectorAll: (sel) => { seen.push(sel); return [note]; },
  getElementById: (id) => { seen.push(id); return row; },
};
const run = new Function('document', 'cssEscape', src.slice(start, end) + 'return clearInlineUpdate;');
run(document, (v) => v)('tax-rates', '1.2');
assert.deepEqual(removed, [note]);
assert.equal(version.textContent, '1.2');
assert.ok(seen.includes('pkg-tax-rates'));

// Success of the shared routine clears the inline line, and there is one update fetch only.
assert.match(src, /clearInlineUpdate\(slug, version\);\s*removeUpdateItem\(slug\);/);
assert.equal(src.split('updateUrl : installUrl').length, 2);
console.log('market-inline-update-js: ok');
