// Runs the plugin Details modal's applyDetail() from oc-admin/themes/modern/js/market.js: the Requires row lists the required plugins.
// Use: node tests/market-detail-requires-js.test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('..', import.meta.url));
const src = readFileSync(root + 'oc-admin/themes/modern/js/market.js', 'utf8');
const start = src.indexOf('function applyDetail(');
const end = src.indexOf('dReadme.innerHTML', start);
assert.ok(start > 0 && end > start, 'applyDetail not found');

const dRequires = { textContent: '' };
const dRequiresRow = { hidden: true };
const body = src.slice(start, end).replace(/^function applyDetail\([^)]*\) \{/, '');
const run = new Function('dRequiresRow', 'dRequires', 'i18n', 'detail', 'version', body);

run(dRequiresRow, dRequires, {}, {
  versions: [{ version: '1.0', requires: '7.1', requires_php: '8.2' }],
  requires_plugins: [{ name: 'Tax', constraint: '^0.2.0' }, { name: 'Cart', constraint: '' }],
}, '1.0');
assert.equal(dRequires.textContent, 'Shopclass 7.1 or newer · PHP 8.2 or newer · Tax ^0.2.0 · Cart');
assert.equal(dRequiresRow.hidden, false);

run(dRequiresRow, dRequires, {}, { versions: [], requires_plugins: [{ name: 'Tax', constraint: '' }] }, '');
assert.equal(dRequires.textContent, 'Tax');
assert.equal(dRequiresRow.hidden, false);
console.log('market-detail-requires-js: ok');
