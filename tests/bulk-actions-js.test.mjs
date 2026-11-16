// Runs toggleBulkActionsModal() from oc-admin/themes/modern/js/osc.js on a stub DOM: a dialog with an
// empty-selection message warns instead of asking to confirm when no row is ticked.
// Use: node tests/bulk-actions-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const src = readFileSync(new URL('../oc-admin/themes/modern/js/osc.js', import.meta.url), 'utf8');
const block = src.match(/^function toggleBulkActionsModal\(\) \{[\s\S]*?^\}$/m);
assert.ok(block, 'osc.js defines toggleBulkActionsModal');

function run({ ticked, empty }) {
  const shown = [];
  const messages = [];
  const modal = {
    showModal: () => shown.push(true),
    getAttribute: (name) => (name === 'data-empty-message' ? empty : null),
    querySelector: () => ({ textContent: '' }),
  };
  const form = {
    querySelector: (sel) => (sel.includes(':checked') && ticked ? {} : null),
  };
  const select = { selectedIndex: 1, options: [{ value: '' }, { value: 'delete', text: 'Delete', getAttribute: () => 'Sure?' }] };
  const byId = { bulk_actions: select, bulkActionsModal: modal, datatablesForm: form, bulkActionsSubmit: { textContent: '' } };
  const ctx = {
    document: { getElementById: (id) => byId[id] || null },
    setJsMessage: (type, text) => messages.push([type, text]),
  };
  vm.runInNewContext(block[0] + '\ntoggleBulkActionsModal();', ctx);

  return { shown: shown.length, messages };
}

let r = run({ ticked: false, empty: 'No language selected.' });
assert.equal(r.shown, 0, 'no confirm with nothing ticked');
assert.deepEqual(r.messages, [['warning', 'No language selected.']]);

r = run({ ticked: true, empty: 'No language selected.' });
assert.equal(r.shown, 1, 'the confirm opens with a row ticked');
assert.deepEqual(r.messages, []);

r = run({ ticked: false, empty: null });
assert.equal(r.shown, 1, 'a dialog without the message still opens');
console.log('bulk-actions-js: ok');
