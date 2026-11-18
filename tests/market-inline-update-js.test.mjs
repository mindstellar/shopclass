// Runs the real oc-admin/themes/modern/js/market.js against a small fake page: clicking an inline "Update to version X" button runs the shared update routine.
// Use: node tests/market-inline-update-js.test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('..', import.meta.url));
const src = readFileSync(root + 'oc-admin/themes/modern/js/market.js', 'utf8');

function element(attrs = {}) {
  const el = {
    attrs: { ...attrs }, textContent: '', innerHTML: '', disabled: false, parentNode: null,
    listeners: {}, classes: new Set(),
    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    hasAttribute(k) { return k in this.attrs; },
    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    closest(sel) { return sel === '.market-action-btn' && this.classes.has('market-action-btn') ? this : null; },
    classList: { add: (c) => el.classes.add(c), remove: (c) => el.classes.delete(c), toggle() {}, contains: (c) => el.classes.has(c) },
  };
  return el;
}

/** A page with one pending update: the app, the inline note + button, and the row showing 1.0. */
function page(reply) {
  const app = element({ 'data-type': 'plugin', 'data-update-url': 'https://x.test/?action=market_update&type=plugin&tok=1', 'data-install-url': 'https://x.test/?action=market_install', 'data-i18n': '{"ajaxError":"Something went wrong."}' });
  const button = element({ 'data-market-action': 'update', 'data-market-slug': 'ready', 'data-market-version': '1.2' });
  button.textContent = 'Update to version 1.2';
  button.classes.add('market-action-btn');
  const note = element({ 'data-market-slug': 'ready' });
  note.parentNode = { removeChild(n) { note.removed = true; } };
  const version = element();
  version.textContent = '1.0';
  const row = element();
  row.querySelector = (sel) => (sel === '.col-version' ? version : null);

  const flashes = [];
  const fetches = [];
  const document = {
    readyState: 'complete',
    addEventListener() {},
    createElement: () => element(),
    getElementById: (id) => (id === 'pkg-ready' ? row : null),
    querySelector: () => null,
    querySelectorAll: (sel) => (sel === '.market-app' ? [app] : sel.startsWith('[data-market-update-note]') ? [note] : sel.startsWith('.market-action-btn[') ? [button] : []),
  };
  const window = { localStorage: { getItem: () => null, setItem() {} }, location: { reload() {} }, CSS: { escape: (v) => v } };
  const fetch = (url, opts) => { fetches.push({ url, opts }); return Promise.resolve({ json: () => Promise.resolve(reply) }); };
  const setJsMessage = (type, text) => flashes.push([type, text]);

  new Function('document', 'window', 'CSS', 'fetch', 'setJsMessage', src)(document, window, window.CSS, fetch, setJsMessage);
  return { app, button, note, version, flashes, fetches, click: () => app.listeners.click.forEach((fn) => fn({ target: button, preventDefault() {} })) };
}

const tick = () => new Promise((r) => setTimeout(r, 0));

// Success: the shared routine posts the update, drops the inline note and shows the new version.
let p = page({ ok: true, message: 'Updated.' });
p.click();
await tick();
assert.equal(p.fetches.length, 1, 'one update request');
assert.equal(p.fetches[0].url, 'https://x.test/?action=market_update&type=plugin&tok=1&slug=ready&version=1.2');
assert.equal(p.fetches[0].opts.method, 'POST');
assert.equal(p.note.removed, true, 'inline note removed');
assert.equal(p.version.textContent, '1.2', 'row shows the new version');
assert.deepEqual(p.flashes, [['ok', 'Updated.']]);
assert.equal(p.button.textContent, 'Installed', 'button marked done');

// Failure: the error is shown, the note and the old version stay, the button is usable again.
p = page({ ok: false, message: 'Disk is full.' });
p.click();
await tick();
assert.equal(p.fetches.length, 1);
assert.equal(p.note.removed, undefined, 'note stays');
assert.equal(p.version.textContent, '1.0', 'version unchanged');
assert.deepEqual(p.flashes, [['error', 'Disk is full.']]);
assert.equal(p.button.disabled, false, 'button usable again');

// A button that is already disabled (blocked or busy) does nothing.
p = page({ ok: true, message: 'x' });
p.button.disabled = true;
p.click();
await tick();
assert.equal(p.fetches.length, 0);

console.log('market-inline-update-js: ok');
