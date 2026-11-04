// Runs oc-admin/themes/modern/js/media-picker.js against a stub DOM. Use: node tests/media-picker-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code = readFileSync(new URL('../oc-admin/themes/modern/js/media-picker.js', import.meta.url), 'utf8');

class El {
  constructor(cls = '', attrs = {}) {
    this.cls = cls;
    this.attrs = { ...attrs };
    this.listeners = {};
    this.hidden = false;
    this.value = '';
    this.textContent = '';
    this.innerHTML = '';
    this.children = [];
    this.open = false;
    this.classList = { add() {}, remove() {}, toggle() {}, contains() { return false; } };
  }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  setAttribute(k, v) { this.attrs[k] = v; }
  removeAttribute(k) { delete this.attrs[k]; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  fire(type, extra = {}) { (this.listeners[type] || []).forEach((f) => f({ type, preventDefault() {}, ...extra })); }
  appendChild(c) { this.children.push(c); return c; }
  focus() { this.focused = true; }
  closest(sel) { return sel === '.media-picker-item' && this.cls === 'media-picker-item' ? this : null; }
}

function boot() {
  const parts = {};
  ['media-picker-grid', 'media-picker-empty', 'media-picker-loading', 'media-picker-error', 'media-picker-file',
    'media-picker-link-input', 'media-picker-link-error', 'media-picker-upload', 'media-picker-link-add', 'media-picker-drop']
    .forEach((c) => { parts[c] = new El(c); });
  const dialog = new El('media-picker', { 'data-list-url': '/list', 'data-upload-url': '/upload', 'data-msg-upload': 'failed' });
  dialog.querySelector = (sel) => parts[sel.replace(/^\./, '')];
  dialog.showModal = () => { dialog.open = true; };
  dialog.close = () => { dialog.open = false; };
  dialog.contains = () => false;

  const document = {
    getElementById: (id) => (id === 'oscMediaPicker' ? dialog : null),
    querySelectorAll: () => [],
    addEventListener() {},
    createElement: () => new El(),
  };
  const ctx = {
    window: {}, document, URL, FormData: class {}, Event: class {},
    fetch: () => Promise.resolve({ json: () => ({ items: [] }) }),
  };
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  return { picker: ctx.window.oscMediaPicker, dialog, parts };
}

const { picker, dialog, parts } = boot();

// Link rules match WidgetConfig::imageValue(): http(s) addresses and site paths only.
for (const ok of ['https://cdn.example.com/a.jpg', 'http://example.com/x.png?w=2', '/oc-content/uploads/a.jpg', '  https://example.com/a.jpg  ']) {
  assert.equal(picker.linkValue(ok), ok.trim(), `accepts ${ok}`);
}
for (const bad of ['', 'javascript:alert(1)', 'data:image/png;base64,AAA', '//evil.example/a.jpg', 'ftp://example.com/a.jpg',
  'example.com/a.jpg', 'https://', 'https://exa mple.com/a.jpg', '/\\evil', '/a"onerror=x', 'https://example.com/<x>']) {
  assert.equal(picker.linkValue(bad), '', `rejects ${JSON.stringify(bad)}`);
}

// A bad link shows the inline error and keeps the dialog open.
let got = null;
picker.open((url, info) => { got = [url, info]; });
assert.equal(dialog.open, true);
parts['media-picker-link-input'].value = 'javascript:alert(1)';
parts['media-picker-link-add'].fire('click');
assert.equal(got, null);
assert.equal(parts['media-picker-link-error'].hidden, false);
assert.equal(parts['media-picker-link-input'].getAttribute('aria-invalid'), 'true');
assert.equal(dialog.open, true);

// A good link calls back with the address and closes.
parts['media-picker-link-input'].value = 'https://cdn.example.com/a.jpg';
parts['media-picker-link-input'].fire('input');
assert.equal(parts['media-picker-link-error'].hidden, true);
parts['media-picker-link-input'].fire('keydown', { key: 'Enter' });
assert.equal(got[0], 'https://cdn.example.com/a.jpg');
assert.equal(got[1].alt, '');
assert.equal(dialog.open, false);

// Opening again clears the old link; picking from the library calls the new callback.
got = null;
picker.open((url) => { got = url; });
assert.equal(parts['media-picker-link-input'].value, '');
const item = new El('media-picker-item', { 'data-url': '/oc-content/uploads/b.jpg' });
parts['media-picker-grid'].fire('click', { target: item });
assert.equal(got, '/oc-content/uploads/b.jpg');
assert.equal(dialog.open, false);

// A callback cannot fire twice: once closed, a stray pick does nothing.
got = null;
parts['media-picker-grid'].fire('click', { target: item });
assert.equal(got, null);

console.log('media-picker-js: ok');
