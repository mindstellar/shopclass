// Runs oc-admin/themes/modern/js/widget-fields.js against a stub DOM. Use: node tests/widget-fields-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code = readFileSync(new URL('../oc-admin/themes/modern/js/widget-fields.js', import.meta.url), 'utf8');

class El {
  constructor(tag, attrs = {}) {
    this.tagName = tag.toUpperCase();
    this.attrs = { ...attrs };
    this.children = [];
    this.listeners = {};
    this.value = attrs.value ?? '';
    this.type = attrs.type || (tag === 'textarea' ? 'textarea' : tag === 'select' ? 'select-one' : 'text');
    this.checked = false;
    this.hidden = false;
    this.id = attrs.id || '';
    this.name = attrs.name || '';
    const cls = (attrs.class || '').split(' ').filter(Boolean);
    this.classList = { contains: (c) => cls.includes(c) };
  }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  add(c) { this.children.push(c); return c; }
  all() { return this.children.flatMap((c) => [c, ...c.all()]); }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  fire(type, target) { (this.listeners[type] || []).forEach((f) => f({ type, target })); }
  querySelectorAll(sel) {
    const test = {
      '[name^="config["]': (e) => e.name.startsWith('config['),
      '[data-show-name]': (e) => e.getAttribute('data-show-name') !== null,
      '.widget-image-input': (e) => e.classList.contains('widget-image-input'),
    }[sel];
    return this.all().filter(test);
  }
}

function build() {
  const scope = new El('div');
  const radios = ['left', 'center', 'right'].map((v) => scope.add(Object.assign(new El('input', { type: 'radio', name: 'config[align]', value: v }), { type: 'radio' })));
  const title = scope.add(new El('input', { name: 'config[title]' }));
  const flag = scope.add(Object.assign(new El('input', { name: 'config[flag]', type: 'checkbox' }), { type: 'checkbox' }));
  const body = scope.add(new El('textarea', { name: 'config[body]', id: 'body', 'data-osc-richtext': '{}' }));
  const image = scope.add(new El('input', { name: 'config[image]', type: 'hidden', class: 'widget-image-input' }));
  const extra = scope.add(new El('div', { 'data-show-name': 'config[align]', 'data-show-value': 'right' }));
  const note = scope.add(new El('p'));
  return { scope, radios, title, flag, body, image, extra, note };
}

function boot(editor = null) {
  const synced = [];
  const mounted = [];
  const ctx = {
    window: {
      oscSyncWidgetImage: (el) => synced.push(el),
      oscRichtext: { mount: (scope, cb) => mounted.push([scope, cb]), remove: (scope) => mounted.push(['removed', scope]) },
    },
    tinymce: { get: (id) => (editor && id === 'body' ? editor : null) },
    WeakMap, Object, Array, String, RegExp,
  };
  vm.runInNewContext(code, ctx);
  return { api: ctx.window.oscWidgetFields, synced, mounted };
}

const editorStub = (html = '') => ({
  initialized: true,
  html,
  getContent() { return this.html; },
  setContent(h) { this.html = h; },
  save() {},
  on(ev, fn) { (this.ons = this.ons || {})[ev] = fn; },
});

// write() ticks the right radio and never rewrites a radio value.
{
  const { api } = boot();
  const f = build();
  api.write(f.scope, { align: 'center' });
  assert.deepEqual(f.radios.map((r) => r.checked), [false, true, false]);
  assert.deepEqual(f.radios.map((r) => r.value), ['left', 'center', 'right']);
}

// read() gives the checked radio, 1/0 for a checkbox, and nothing for a note.
{
  const { api } = boot();
  const f = build();
  f.radios[2].checked = true;
  f.flag.checked = true;
  f.title.value = 'Hi';
  const out = api.read(f.scope);
  assert.equal(out.align, 'right');
  assert.equal(out.flag, 1);
  assert.equal(out.title, 'Hi');
  f.flag.checked = false;
  assert.equal(api.read(f.scope).flag, 0);
  assert.equal(Object.keys(out).some((k) => k === 'note'), false);
  assert.equal(Object.keys(out).length, 5);
}

// A radio group with nothing checked is left out of the result.
{
  const { api } = boot();
  const f = build();
  assert.equal('align' in api.read(f.scope), false);
}

// show_if hides and shows with the controlling field.
{
  const { api } = boot();
  const f = build();
  api.write(f.scope, { align: 'left' });
  assert.equal(f.extra.hidden, true);
  api.write(f.scope, { align: 'right' });
  assert.equal(f.extra.hidden, false);
  api.mount(f.scope);
  f.radios[0].checked = true;
  f.radios[2].checked = false;
  f.scope.fire('change', f.radios[0]);
  assert.equal(f.extra.hidden, true);
}

// Richtext write() goes through the editor and turns plain text into paragraphs.
{
  const ed = editorStub();
  const { api } = boot(ed);
  const f = build();
  api.write(f.scope, { body: 'One\n\nTwo & <3' });
  assert.equal(ed.html, '<p>One</p>\n<p>Two &amp; &lt;3</p>');
  api.write(f.scope, { body: '<p>Keep</p>' });
  assert.equal(ed.html, '<p>Keep</p>');
  assert.equal(api.read(f.scope).body, '<p>Keep</p>');
}

// Without an editor yet, richtext still lands in the textarea.
{
  const { api } = boot();
  const f = build();
  api.write(f.scope, { body: 'Plain' });
  assert.equal(f.body.value, '<p>Plain</p>');
}

// Image write() calls the sync.
{
  const { api, synced } = boot();
  const f = build();
  api.write(f.scope, { image: '/a.png' });
  assert.equal(f.image.value, '/a.png');
  assert.deepEqual(synced, [f.image]);
}

// onChange: typing only for text fields, change for the rest; richtext goes through the editor.
{
  const ed = editorStub('<p>x</p>');
  const { api, mounted } = boot(ed);
  const f = build();
  const seen = [];
  api.onChange(f.scope, (n, v, t) => seen.push([n, v, t]));
  api.mount(f.scope);
  f.title.value = 'A';
  f.scope.fire('input', f.title);
  f.scope.fire('input', f.flag);
  f.flag.checked = true;
  f.scope.fire('change', f.flag);
  assert.deepEqual(seen, [['title', 'A', true], ['flag', 1, false]]);
  const [, cb] = mounted[0];
  cb(ed, f.body);
  ed.ons.input();
  assert.deepEqual(seen[2], ['body', '<p>x</p>', true]);
}

// unmount hands the scope back to the editor module.
{
  const { api, mounted } = boot();
  const f = build();
  api.unmount(f.scope);
  assert.deepEqual(mounted[0], ['removed', f.scope]);
}

console.log('widget-fields-js ok');
