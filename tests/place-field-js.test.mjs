// Runs oc-includes/assets/osclass/place-field.js on oscAutocomplete() (ui-common.js) against a stub DOM,
// with the field's texts from PlaceField::texts(). Use: node tests/place-field-js.test.mjs
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('..', import.meta.url));
const code = readFileSync(root + 'oc-includes/assets/osclass/ui-common.js', 'utf8') + '\n'
  + readFileSync(root + 'oc-includes/assets/osclass/place-field.js', 'utf8');
const texts = JSON.parse(execFileSync('php', ['-r', 'define("ABS_PATH", getcwd() . "/"); require "oc-includes/vendor/autoload.php";'
  + ' function _m($s) { return $s; } echo json_encode(mindstellar\\location\\PlaceField::texts());'], { cwd: root, encoding: 'utf8' }));

class Node_ {
  constructor() { this.parentNode = null; this.children = []; }
}
class Text_ extends Node_ {
  constructor(t) { super(); this.data = t; }
  get textContent() { return this.data; }
}
const selParts = (sel) => {
  const out = [];
  const re = /(^[a-z]+)|\.([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\]/gi;
  let m;
  while ((m = re.exec(sel))) out.push({ tag: m[1], cls: m[2], attr: m[3], val: m[4] });
  return out;
};
class El extends Node_ {
  constructor(tag, doc) {
    super();
    this.tagName = tag.toUpperCase();
    this.doc = doc;
    this.attrs = {};
    this.listeners = {};
    this.value = '';
    this.hidden = false;
    this.className = '';
    this.id = '';
    this._text = '';
    this.classList = {
      toggle: (c, on) => {
        const set = new Set(this.className.split(/\s+/).filter(Boolean));
        if (on) set.add(c); else set.delete(c);
        this.className = [...set].join(' ');
      },
      contains: (c) => this.className.split(/\s+/).includes(c),
    };
  }
  get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._text; }
  set textContent(t) { this.children = []; this._text = String(t); }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'class') this.className = String(v); if (k === 'id') this.id = String(v); }
  getAttribute(k) {
    if (k === 'class') return this.className || null;
    if (k === 'id') return this.id || null;
    return k in this.attrs ? this.attrs[k] : null;
  }
  removeAttribute(k) { delete this.attrs[k]; }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  replaceWith(n) {
    const i = this.parentNode.children.indexOf(this);
    n.parentNode = this.parentNode;
    this.parentNode.children[i] = n;
    this.parentNode = null;
  }
  remove() {
    if (!this.parentNode) return;
    this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1);
    this.parentNode = null;
  }
  replaceChildren(...nodes) { this._text = ''; this.children = []; nodes.forEach((n) => this.appendChild(n)); }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  fire(type, extra = {}) {
    const e = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...extra };
    const bubbles = ['click', 'mousemove', 'mousedown', 'change'].includes(type);
    for (let x = this; x instanceof El; x = bubbles ? x.parentNode : null) {
      (x.listeners[type] || []).forEach((f) => f(e));
    }
    return e;
  }
  focus() { this.doc.activeElement = this; }
  contains(n) { for (let x = n; x; x = x.parentNode) if (x === this) return true; return false; }
  all() { return this.children.filter((c) => c instanceof El).flatMap((c) => [c, ...c.all()]); }
  matches(sel) {
    return selParts(sel).every((p) => {
      if (p.tag) return this.tagName === p.tag.toUpperCase();
      if (p.cls) return this.className.split(/\s+/).includes(p.cls);
      if (p.attr) {
        const v = p.attr === 'id' ? this.id : this.getAttribute(p.attr);
        return v !== null && (p.val === undefined || v === p.val);
      }
      return true;
    });
  }
  querySelectorAll(sel) { return this.all().filter((e) => e.matches(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  closest(sel) { for (let x = this; x instanceof El; x = x.parentNode) if (x.matches(sel)) return x; return null; }
}

function build({ strict = '1', countries = '1', prefill = null, saved = null, meta = true, errorShown = false } = {}) {
  const doc = {
    readyState: 'complete', activeElement: null, listeners: {},
    createElement: (t) => new El(t, doc),
    createElementNS: (ns, t) => new El(t, doc),
    createTextNode: (t) => new Text_(t),
    addEventListener() {},
  };
  const html = new El('html', doc);
  const head = html.appendChild(new El('head', doc));
  if (meta) {
    const m = head.appendChild(new El('meta', doc));
    m.setAttribute('name', 'shopclass-api');
    m.setAttribute('content', JSON.stringify({ url: 'https://x.test/api/v1', header: 'X-Shopclass-Token', token: 'tok123' }));
  }
  const root = html.appendChild(new El('div', doc));
  root.setAttribute('data-osc-place', '');
  root.setAttribute('data-strict', strict);
  root.setAttribute('data-countries', countries);
  for (const [k, v] of Object.entries(texts)) root.setAttribute('data-text-' + k, v);
  const add = (parent, tag, cls, attrs = {}) => {
    const e = parent.appendChild(new El(tag, doc));
    if (cls) e.className = cls;
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    return e;
  };
  const box = add(root, 'div', 'oe-place__box');
  const input = add(box, 'input', 'oe-place__input', { id: 'pl', 'aria-describedby': (errorShown ? 'pl-error ' : '') + 'pl-hint pl-note' });
  const end = add(box, 'div', 'oe-place__end');
  const spin = add(end, 'span', 'oe-place__spin'); spin.hidden = true;
  const clear = add(end, 'button', 'oe-place__clear'); clear.hidden = true;
  const list = add(box, 'ul', 'osc-ac-list', { id: 'pl-list' }); list.hidden = true;
  add(root, 'p', 'oe-place__hint', { id: 'pl-hint' });
  const note = add(root, 'p', 'oe-place__note', { id: 'pl-note' }); note.hidden = true;
  const err = add(root, 'p', 'oe-field-error', { id: 'pl-error' }); err.hidden = !errorShown;
  if (errorShown) err.textContent = 'Pick your location from the list.';
  const savedBtn = add(root, 'button', 'oe-place__saved'); savedBtn.hidden = true;
  if (saved) { savedBtn.setAttribute('data-place', JSON.stringify(saved)); savedBtn.hidden = false; }
  const hid = {};
  for (const n of ['cityId', 'regionId', 'countryId', 'region', 'country']) hid[n] = add(root, 'input', '', { name: n });
  const live = add(root, 'div', 'oe-place__live');
  if (prefill) {
    input.value = prefill.label; hid.cityId.value = prefill.id; hid.regionId.value = prefill.region_id; hid.countryId.value = prefill.country_code;
  }
  const form = new El('form', doc);
  form.appendChild(root);
  input.form = form;
  doc.querySelector = (s) => html.querySelector(s);
  doc.querySelectorAll = (s) => html.querySelectorAll(s);
  return { doc, root, input, list, spin, clear, note, err, savedBtn, hid, live, form };
}

const addTo = (doc, parent, tag, cls, attrs = {}) => {
  const e = parent.appendChild(new El(tag, doc));
  if (cls) e.className = cls;
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  return e;
};

function shell({ style, strict, ajax }) {
  const doc = {
    readyState: 'complete', activeElement: null,
    createElement: (t) => new El(t, doc),
    createTextNode: (t) => new Text_(t),
    addEventListener() {},
  };
  const html = new El('html', doc);
  const m = addTo(doc, html.appendChild(new El('head', doc)), 'meta', '', { name: 'shopclass-api' });
  m.setAttribute('content', JSON.stringify({ url: 'https://x.test/api/v1', header: 'X-Shopclass-Token', token: 'tok123' }));
  const root = html.appendChild(new El('div', doc));
  root.setAttribute('data-osc-place', style);
  root.setAttribute('data-strict', strict);
  if (ajax) root.setAttribute('data-ajax', ajax);
  for (const [k, v] of Object.entries(texts)) root.setAttribute('data-text-' + k, v);
  const form = new El('form', doc);
  form.appendChild(root);
  doc.querySelector = (q) => html.querySelector(q);
  doc.querySelectorAll = (q) => html.querySelectorAll(q);
  return { doc, root, form };
}

function level(doc, root, name, box) {
  const part = addTo(doc, root, 'div', 'oe-place__part', { 'data-level': name });
  let input = null;
  let list = null;
  let spin = null;
  let clear = null;
  if (box) {
    const b = addTo(doc, part, 'div', 'oe-place__box');
    input = addTo(doc, b, 'input', 'oe-place__input', { id: 'pl-' + name, name, 'aria-describedby': 'pl-hint' });
    const end = addTo(doc, b, 'div', 'oe-place__end');
    spin = addTo(doc, end, 'span', 'oe-place__spin'); spin.hidden = true;
    clear = addTo(doc, end, 'button', 'oe-place__clear'); clear.hidden = true;
    list = addTo(doc, b, 'ul', 'osc-ac-list', { id: 'pl-' + name + '-list' }); list.hidden = true;
  }
  const err = addTo(doc, part, 'p', 'oe-field-error', { id: 'pl-' + name + '-error' }); err.hidden = true;
  return { part, input, list, spin, clear, err };
}

function buildFields({ strict = '1', one = false, saved = null } = {}) {
  const { doc, root, form } = shell({ style: 'fields', strict });
  const country = addTo(doc, root, one ? 'input' : 'select', '', { name: 'countryId' });
  country.value = one ? 'IN' : '';
  const region = level(doc, root, 'region', true);
  const city = level(doc, root, 'city', true);
  const hid = {};
  for (const n of ['regionId', 'cityId', 'country']) hid[n] = addTo(doc, root, 'input', '', { name: n });
  const savedBtn = addTo(doc, root, 'button', 'oe-place__saved'); savedBtn.hidden = true;
  if (saved) { savedBtn.setAttribute('data-place', JSON.stringify(saved)); savedBtn.hidden = false; }
  const live = addTo(doc, root, 'div', 'oe-place__live');
  return { doc, root, form, country, region, city, hid, savedBtn, live };
}

function buildDropdown({ strict = '1', saved = null, ajax = 'https://x.test/index.php', textRegion = false } = {}) {
  const { doc, root, form } = shell({ style: 'dropdown', strict, ajax });
  form.setAttribute('action', 'https://x.test/form.php');
  const sel = (name, lvl, disabled) => {
    const s = addTo(doc, lvl.part, 'select', 'oe-place__select', { name, id: 'pl-' + lvl.name });
    const ph = addTo(doc, s, 'option'); ph.value = '';
    s.value = ''; s.disabled = disabled;
    return s;
  };
  const mk = (name) => ({ ...level(doc, root, name, false), name });
  const cl = mk('country'); const rl = mk('region'); const tl = mk('city');
  const country = sel('countryId', cl, false);
  for (const c of ['IN', 'DE']) { const o = addTo(doc, country, 'option'); o.value = c; }
  let region;
  if (textRegion) {
    region = addTo(doc, rl.part, 'input', 'oe-place__text', { type: 'text', name: 'region', id: 'pl-region' });
    addTo(doc, rl.part, 'input', '', { type: 'hidden', name: 'regionId', id: 'pl-regionId' });
  } else {
    region = sel('regionId', rl, true);
  }
  const city = sel('cityId', tl, true);
  const savedBtn = addTo(doc, root, 'button', 'oe-place__saved'); savedBtn.hidden = true;
  if (saved) { savedBtn.setAttribute('data-place', JSON.stringify(saved)); savedBtn.hidden = false; }
  const live = addTo(doc, root, 'div', 'oe-place__live');
  return { doc, root, form, country, region, city, regionPart: rl.part, cityPart: tl.part, regionErr: rl.err, cityErr: tl.err, savedBtn, live };
}

function run(opts = {}) {
  const f = opts.style === 'fields' ? buildFields(opts) : opts.style === 'dropdown' ? buildDropdown(opts) : build(opts);
  let now = 0;
  const timers = [];
  const calls = [];
  const pending = [];
  const ctx = {
    document: f.doc,
    fetch: (url, init) => new Promise((resolve, reject) => { calls.push({ url, init }); pending.push({ resolve, reject, url }); }),
    setTimeout: (fn, ms) => { timers.push({ fn, at: now + (ms || 0), id: timers.length + 1 }); return timers.length; },
    clearTimeout: (id) => { const t = timers.find((x) => x.id === id); if (t) t.dead = true; },
    JSON, Promise, encodeURIComponent, parseInt, Math, String, Object, Array, WeakSet, console,
    addEventListener() {},
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  const api = {
    ...f, calls, pending, ctx,
    tick(ms) {
      now += ms;
      for (const t of timers.filter((x) => !x.dead && x.at <= now)) { t.dead = true; t.fn(); }
    },
    async flush() { for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r)); },
    type(v, box) { const i = box || f.input; i.value = v; i.fire('input'); },
    answerBody(i, body, ok = true) { pending[i].resolve({ ok, status: ok ? 200 : 500, json: async () => body }); },
    answer(i, rows, more = 0, ok = true) {
      pending[i].resolve({ ok, status: ok ? 200 : 500, json: async () => ({ data: rows, meta: { more } }) });
    },
    options(list) { return (list || f.list).querySelectorAll('.osc-ac-option'); },
  };
  return api;
}

const MUM = { id: 1, name: 'Mumbai', region_id: 10, region: 'Maharashtra', country_code: 'IN', country: 'India', label: 'Mumbai, Maharashtra' };
const MUN = { id: 2, name: 'Munnar', region_id: 11, region: 'Kerala', country_code: 'IN', country: 'India', label: 'Munnar, Kerala' };

let n = 0;
async function test(name, fn) {
  try { await fn(); n++; console.log('ok   ' + name); } catch (e) { console.error('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
}

await test('typing fetches once after the debounce, with the page token header', async () => {
  const t = run();
  t.type('m'); t.tick(200);
  assert.equal(t.calls.length, 0, 'under two characters asks nothing');
  t.type('mu'); t.tick(100); t.type('mu'); t.tick(100);
  assert.equal(t.calls.length, 0, 'still inside the debounce');
  t.tick(100);
  assert.equal(t.calls.length, 1);
  assert.equal(t.calls[0].url, 'https://x.test/api/v1/cities?q=mu&limit=8');
  assert.equal(t.calls[0].init.headers['X-Shopclass-Token'], 'tok123');
  assert.equal(t.list.textContent, 'Searching…');
});

await test('a slow earlier answer does not overwrite a newer one', async () => {
  const t = run();
  t.type('mu'); t.tick(150);
  t.type('mum'); t.tick(150);
  assert.equal(t.calls.length, 2);
  t.answer(1, [MUM]);
  await t.flush();
  t.answer(0, [MUM, MUN]);
  await t.flush();
  assert.equal(t.options().length, 1);
  assert.equal(t.options()[0].textContent.startsWith('Mumbai'), true);
});

await test('rows show the bold prefix, region, and a country tag only with several countries', async () => {
  const t = run({ countries: 'n' });
  t.type('mu'); t.tick(150); t.answer(0, [MUM]); await t.flush();
  const opt = t.options()[0];
  assert.equal(opt.querySelector('b').textContent, 'Mu');
  assert.equal(opt.querySelector('.oe-place__region').textContent, ', Maharashtra');
  assert.equal(opt.querySelector('.oe-place__cc').textContent, 'IN');
  assert.equal(opt.getAttribute('role'), 'option');
  assert.equal(opt.id, 'pl-opt-0');
  const one = run();
  one.type('mu'); one.tick(150); one.answer(0, [MUM]); await one.flush();
  assert.equal(one.options()[0].querySelector('.oe-place__cc'), null);
});

await test('picking sets the three ids, the label and is-picked, and empties the typed names', async () => {
  const t = run();
  t.hid.region.value = 'Old'; t.hid.country.value = 'Oldland';
  t.type('mu'); t.tick(150); t.answer(0, [MUM, MUN]); await t.flush();
  t.options()[1].fire('mousedown');
  assert.equal(t.input.value, 'Munnar, Kerala');
  assert.deepEqual([t.hid.cityId.value, t.hid.regionId.value, t.hid.countryId.value], [2, 11, 'IN']);
  assert.equal(t.hid.region.value + t.hid.country.value, '');
  assert.equal(t.root.classList.contains('is-picked'), true);
  assert.equal(t.note.textContent, 'Picked from the list');
  assert.equal(t.live.textContent, 'Munnar, Kerala chosen.');
  assert.equal(t.list.hidden, true);
});

await test('typing after a pick clears the ids, drops is-picked and empties the note', async () => {
  const t = run();
  t.type('mu'); t.tick(150); t.answer(0, [MUM, MUN]); await t.flush();
  t.options()[1].fire('mousedown');
  t.hid.region.value = 'Typed'; t.hid.country.value = 'Typed';
  t.type('Munnar, Keral');
  assert.deepEqual([t.hid.cityId.value, t.hid.regionId.value, t.hid.countryId.value, t.hid.region.value, t.hid.country.value], ['', '', '', '', '']);
  assert.equal(t.root.classList.contains('is-picked'), false);
  assert.equal(t.note.textContent, '');
  assert.equal(t.note.hidden, true);
});

await test('arrows wrap, Enter picks, Escape closes; aria-activedescendant follows', async () => {
  const t = run();
  t.type('mu'); t.tick(150); t.answer(0, [MUM, MUN]); await t.flush();
  assert.equal(t.input.getAttribute('aria-expanded'), 'true');
  t.input.fire('keydown', { key: 'ArrowDown' });
  assert.equal(t.input.getAttribute('aria-activedescendant'), 'pl-opt-0');
  t.input.fire('keydown', { key: 'ArrowDown' });
  assert.equal(t.input.getAttribute('aria-activedescendant'), 'pl-opt-1');
  t.input.fire('keydown', { key: 'ArrowDown' });
  assert.equal(t.input.getAttribute('aria-activedescendant'), 'pl-opt-0');
  t.input.fire('keydown', { key: 'ArrowUp' });
  assert.equal(t.input.getAttribute('aria-activedescendant'), 'pl-opt-1');
  const enter = t.input.fire('keydown', { key: 'Enter' });
  assert.equal(enter.defaultPrevented, true);
  assert.equal(t.hid.cityId.value, 2);
  t.type('mu'); t.tick(150); await t.flush();
  assert.equal(t.list.hidden, false);
  t.input.fire('keydown', { key: 'Escape' });
  assert.equal(t.list.hidden, true);
  assert.equal(t.input.getAttribute('aria-expanded'), 'false');
  assert.equal(t.input.getAttribute('aria-activedescendant'), null);
});

await test('a failed fetch shows the error row, and Try again asks again', async () => {
  const t = run();
  t.type('mu'); t.tick(150);
  t.pending[0].reject(new Error('down')); await t.flush();
  assert.match(t.list.textContent, /Couldn’t load places\.Try again$/);
  const retry = t.list.querySelector('.oe-place__retry');
  retry.fire('click');
  assert.equal(t.calls.length, 2);
  t.answer(1, [MUM]); await t.flush();
  assert.equal(t.options().length, 1);
  const off = run({ strict: '0' });
  off.type('mu'); off.tick(150); off.pending[0].reject(new Error('x')); await off.flush();
  assert.match(off.list.textContent, /You can still type your place\./);
});

await test('an error status from the API is an error row too', async () => {
  const t = run();
  t.type('mu'); t.tick(150); t.answer(0, [], 0, false); await t.flush();
  assert.match(t.list.textContent, /Couldn’t load places\./);
});

await test('ON: submit with typed text is refused with the message, aria-invalid and focus', async () => {
  const t = run({ strict: '1' });
  t.type('Bombay');
  const e = t.form.fire('submit');
  assert.equal(e.defaultPrevented, true);
  assert.equal(t.err.hidden, false);
  assert.equal(t.err.textContent, 'Pick your location from the list.');
  assert.equal(t.input.getAttribute('aria-invalid'), 'true');
  assert.equal(t.input.getAttribute('aria-describedby'), 'pl-error pl-hint pl-note');
  assert.equal(t.doc.activeElement, t.input);
  t.type('mu'); t.tick(150); t.answer(0, [MUM]); await t.flush();
  t.options()[0].fire('mousedown');
  assert.equal(t.err.hidden, true);
  assert.equal(t.input.getAttribute('aria-invalid'), null);
  assert.equal(t.form.fire('submit').defaultPrevented, false);
});

await test('ON: an empty field and a picked place both submit', async () => {
  const t = run({ strict: '1' });
  assert.equal(t.form.fire('submit').defaultPrevented, false);
  const p = run({ strict: '1', prefill: { id: 5, label: 'Pune, Maharashtra', region_id: 10, country_code: 'IN' } });
  assert.equal(p.form.fire('submit').defaultPrevented, false);
});

await test('OFF: typed text submits and the note says it is not on the list', async () => {
  const t = run({ strict: '0' });
  t.type('Bombay');
  assert.equal(t.form.fire('submit').defaultPrevented, false);
  assert.equal(t.note.hidden, false);
  assert.equal(t.note.textContent, 'Not on the list. It will be shown as you typed it.');
});

await test('ON: submitting the one exact match that was listed picks it instead of refusing', async () => {
  const t = run({ strict: '1' });
  t.type('Mumbai'); t.tick(150); t.answer(0, [MUM]); await t.flush();
  const e = t.form.fire('submit');
  assert.equal(e.defaultPrevented, false);
  assert.equal(t.hid.cityId.value, 1);
});

await test('a region name with markup renders as text', async () => {
  const t = run();
  const evil = { ...MUM, region: '<img src=x onerror=alert(1)>', label: 'Mumbai, <img src=x onerror=alert(1)>' };
  t.type('mu'); t.tick(150); t.answer(0, [evil]); await t.flush();
  const reg = t.options()[0].querySelector('.oe-place__region');
  assert.equal(reg.textContent, ', <img src=x onerror=alert(1)>');
  assert.equal(t.options()[0].all().some((e) => e.tagName === 'IMG'), false);
});

await test('more matches show the count row; no match shows the ON and OFF texts', async () => {
  const t = run();
  t.type('mu'); t.tick(150); t.answer(0, [MUM], 12); await t.flush();
  const rows = t.list.querySelectorAll('.oe-place__status--more');
  assert.equal(rows[0].textContent, '12 more. Keep typing to narrow it down.');
  assert.equal(t.live.textContent, 'Places found: 1+. Use the up and down arrows to choose.');
  const cap = run();
  cap.type('mu'); cap.tick(150); cap.answer(0, [MUM], 100); await cap.flush();
  assert.equal(cap.list.querySelectorAll('.oe-place__status--more')[0].textContent, '100+ more. Keep typing to narrow it down.');
  const on = run({ strict: '1' });
  on.type('zz'); on.tick(150); on.answer(0, []); await on.flush();
  assert.equal(on.list.textContent, 'No places match “zz”. Check the spelling, or try a bigger town nearby.');
  const off = run({ strict: '0' });
  off.type('zz'); off.tick(150); off.answer(0, []); await off.flush();
  assert.equal(off.list.textContent, 'No places match “zz”. You can keep what you typed.');
});

await test('a complete shorter answer narrows locally without a second request', async () => {
  const t = run();
  t.type('mu'); t.tick(150); t.answer(0, [MUM, MUN], 0); await t.flush();
  t.type('mum'); t.tick(150); await t.flush();
  assert.equal(t.calls.length, 1);
  assert.equal(t.options().length, 1);
  t.type('mu'); t.tick(150); await t.flush();
  assert.equal(t.calls.length, 1, 'the same query is cached');
  const more = run();
  more.type('mu'); more.tick(150); more.answer(0, [MUM, MUN], 5); await more.flush();
  more.type('mum'); more.tick(150);
  assert.equal(more.calls.length, 2, 'an incomplete answer cannot narrow');
});

await test('a cached answer that matched by another language name is not narrowed', async () => {
  const t = run();
  const koeln = { id: 5, name: 'Cologne', region_id: 20, region: 'NRW', country_code: 'DE', country: 'Germany', label: 'Cologne, NRW' };
  const kolkata = { id: 6, name: 'Kolkata', region_id: 21, region: 'West Bengal', country_code: 'IN', country: 'India', label: 'Kolkata, West Bengal' };
  t.type('ko'); t.tick(150); t.answer(0, [koeln, kolkata], 0); await t.flush();
  t.type('kol'); t.tick(150);
  assert.equal(t.calls.length, 2, 'the server may match more than the shown names');
});

await test('a query with replacement patterns prints as typed in the no-match text', async () => {
  const t = run();
  t.type("a$&b"); t.tick(150); t.answer(0, [], 0); await t.flush();
  assert.equal(t.list.textContent, 'No places match “a$&b”. Check the spelling, or try a bigger town nearby.');
});

await test('Clear empties the field and ids and focuses the input; the saved button picks its place', async () => {
  const saved = { label: 'Mumbai, Maharashtra', city_id: 1, region_id: 10, country_code: 'IN', origin: 'address' };
  const t = run({ saved });
  assert.equal(t.savedBtn.hidden, false);
  t.savedBtn.fire('click');
  assert.deepEqual([t.input.value, t.hid.cityId.value, t.hid.regionId.value, t.hid.countryId.value], ['Mumbai, Maharashtra', 1, 10, 'IN']);
  assert.equal(t.note.textContent, 'From your saved address');
  assert.equal(t.savedBtn.hidden, true);
  t.clear.fire('click');
  assert.deepEqual([t.input.value, t.hid.cityId.value, t.hid.regionId.value, t.hid.countryId.value], ['', '', '', '']);
  assert.equal(t.doc.activeElement, t.input);
  assert.equal(t.savedBtn.hidden, false);
});

await test('blur auto-picks a single exact match and closes the list', async () => {
  const t = run();
  t.type('Mumbai'); t.tick(150); t.answer(0, [MUM]); await t.flush();
  t.input.fire('blur'); t.tick(200);
  assert.equal(t.hid.cityId.value, 1);
  assert.equal(t.list.hidden, true);
});

await test('init is idempotent and a server error message is wired to the input', async () => {
  const t = run({ errorShown: true });
  assert.equal(t.input.getAttribute('aria-invalid'), 'true');
  assert.equal(t.input.getAttribute('aria-describedby'), 'pl-error pl-hint pl-note');
  t.ctx.oscPlaceField(t.root);
  t.type('mu'); t.tick(150);
  assert.equal(t.calls.length, 1, 'a second init added no second listener');
  t.answer(0, [MUM]); await t.flush();
  t.options()[0].fire('mousedown');
  assert.equal(t.input.getAttribute('aria-describedby'), 'pl-hint pl-note', 'a picked place no longer points at the hidden error');
  t.type('Bombay');
  t.form.fire('submit');
  assert.equal(t.input.getAttribute('aria-describedby'), 'pl-error pl-hint pl-note');
});

await test('without the API meta tag the field shows the error row instead of throwing', async () => {
  const t = run({ meta: false });
  t.type('mu'); t.tick(150); await t.flush();
  assert.match(t.list.textContent, /Couldn’t load places\./);
});

const IN_REGION = { id: 10, country_code: 'IN', name: 'Maharashtra' };
const KER = { id: 11, country_code: 'IN', name: 'Kerala' };

await test('fields: the region box searches the chosen country with the page token', async () => {
  const t = run({ style: 'fields' });
  t.type('ma', t.region.input); t.tick(200);
  assert.equal(t.calls.length, 0, 'no country, no search');
  t.country.value = 'IN';
  t.type('ma', t.region.input); t.tick(200);
  assert.equal(t.calls.length, 1);
  assert.equal(t.calls[0].url, 'https://x.test/api/v1/countries/IN/regions?q=ma&limit=100');
  assert.equal(t.calls[0].init.headers['X-Shopclass-Token'], 'tok123');
  t.answer(0, [IN_REGION]); await t.flush();
  assert.equal(t.options(t.region.list)[0].querySelector('b').textContent, 'Ma');
});

await test('fields: picking a region sets its id and clears the city', async () => {
  const t = run({ style: 'fields' });
  t.country.value = 'IN';
  t.city.input.value = 'Pune'; t.hid.cityId.value = '7';
  t.type('ma', t.region.input); t.tick(200); t.answer(0, [IN_REGION, KER]); await t.flush();
  t.options(t.region.list)[0].fire('mousedown');
  assert.deepEqual([t.region.input.value, t.hid.regionId.value, t.hid.cityId.value, t.city.input.value], ['Maharashtra', 10, '', '']);
  assert.equal(t.region.list.hidden, true);
});

await test('fields: the city search follows the region, or all regions when none is set', async () => {
  const t = run({ style: 'fields' });
  t.country.value = 'IN';
  t.type('mu', t.city.input); t.tick(200);
  assert.equal(t.calls[0].url, 'https://x.test/api/v1/cities?q=mu&limit=8&country=IN');
  t.hid.regionId.value = '10';
  t.type('mun', t.city.input); t.tick(200);
  assert.equal(t.calls[1].url, 'https://x.test/api/v1/regions/10/cities?q=mun&limit=100');
  const none = run({ style: 'fields' });
  none.type('mu', none.city.input); none.tick(200);
  assert.equal(none.calls[0].url, 'https://x.test/api/v1/cities?q=mu&limit=8', 'no country filter without a country');
});

await test('fields: a city picked from the wide search fills region and country', async () => {
  const t = run({ style: 'fields' });
  t.type('mu', t.city.input); t.tick(200); t.answer(0, [MUM]); await t.flush();
  t.options(t.city.list)[0].fire('mousedown');
  assert.deepEqual([t.city.input.value, t.hid.cityId.value, t.region.input.value, t.hid.regionId.value, t.country.value], ['Mumbai', 1, 'Maharashtra', 10, 'IN']);
});

await test('fields: editing a level clears its id and the deeper levels, and a country change clears both', async () => {
  const t = run({ style: 'fields' });
  t.country.value = 'IN';
  const set = () => { t.region.input.value = 'Maharashtra'; t.hid.regionId.value = '10'; t.city.input.value = 'Pune'; t.hid.cityId.value = '7'; };
  set();
  t.type('Maharash', t.region.input);
  assert.deepEqual([t.hid.regionId.value, t.hid.cityId.value, t.city.input.value], ['', '', '']);
  set();
  t.type('Pun', t.city.input);
  assert.deepEqual([t.hid.regionId.value, t.hid.cityId.value, t.region.input.value], ['10', '', 'Maharashtra']);
  set();
  t.country.value = 'DE'; t.country.fire('change');
  assert.deepEqual([t.region.input.value, t.hid.regionId.value, t.city.input.value, t.hid.cityId.value], ['', '', '', '']);
});

await test('fields: ON refuses each part with text and no id, in its own line, focusing the first', async () => {
  const t = run({ style: 'fields' });
  t.country.value = 'IN';
  t.region.input.value = 'Maharashtra'; t.hid.regionId.value = '10';
  t.city.input.value = 'Bombay';
  const e = t.form.fire('submit');
  assert.equal(e.defaultPrevented, true);
  assert.equal(t.city.err.hidden, false);
  assert.equal(t.city.err.textContent, 'Pick your location from the list.');
  assert.equal(t.region.err.hidden, true);
  assert.equal(t.city.input.getAttribute('aria-invalid'), 'true');
  assert.equal(t.region.input.getAttribute('aria-invalid'), null);
  assert.equal(t.doc.activeElement, t.city.input);
  t.region.input.value = 'Mahar'; t.hid.regionId.value = '';
  t.form.fire('submit');
  assert.equal(t.region.err.hidden, false);
  assert.equal(t.doc.activeElement, t.region.input);
  const off = run({ style: 'fields', strict: '0' });
  off.city.input.value = 'Bombay';
  assert.equal(off.form.fire('submit').defaultPrevented, false);
});

await test('fields: a region name with markup is text, and blur picks the one exact match', async () => {
  const t = run({ style: 'fields' });
  t.country.value = 'IN';
  const evil = { id: 12, country_code: 'IN', name: '<img src=x onerror=alert(1)>' };
  t.type('<i', t.region.input); t.tick(200); t.answer(0, [evil]); await t.flush();
  assert.equal(t.options(t.region.list)[0].all().some((e) => e.tagName === 'IMG'), false);
  t.type('Maharashtra', t.region.input); t.tick(200); t.answer(1, [IN_REGION]); await t.flush();
  t.region.input.fire('blur'); t.tick(200);
  assert.equal(t.hid.regionId.value, 10);
});

await test('fields: Use my saved place fills country, region and city', async () => {
  const saved = { label: 'Mumbai, Maharashtra', city_id: 1, region_id: 10, country_code: 'IN', origin: 'address' };
  const t = run({ style: 'fields', saved });
  t.savedBtn.fire('click');
  assert.deepEqual([t.country.value, t.region.input.value, t.hid.regionId.value, t.city.input.value, t.hid.cityId.value], ['IN', 'Maharashtra', 10, 'Mumbai', 1]);
  assert.equal(t.savedBtn.hidden, true);
});

const states = (t) => [t.region.disabled, t.region.getAttribute('aria-busy'), t.city.disabled];
const optionTexts = (s) => s.querySelectorAll('option').map((o) => o.textContent);
const ajaxRegions = (code) => 'https://x.test/index.php?page=ajax&action=regions&countryId=' + code;

await test('dropdown: a country fills the regions as text, keeps the placeholder, resets the city', async () => {
  const t = run({ style: 'dropdown' });
  t.country.value = 'DE'; t.country.fire('change');
  assert.equal(t.calls[0].url, ajaxRegions('DE'));
  assert.equal(t.calls[0].init.method, 'POST');
  assert.deepEqual(states(t), [true, 'true', true]);
  t.answerBody(0, [{ pk_i_id: 5, s_name: 'Bavaria' }, { pk_i_id: 6, s_name: '<img src=x onerror=alert(1)>' }]); await t.flush();
  assert.deepEqual(optionTexts(t.region), ['', 'Bavaria', '<img src=x onerror=alert(1)>']);
  assert.equal(t.region.all().some((e) => e.tagName === 'IMG'), false);
  assert.deepEqual(states(t), [false, null, true]);
});

await test('dropdown: a late answer for an old country is ignored', async () => {
  const t = run({ style: 'dropdown' });
  t.country.value = 'IN'; t.country.fire('change');
  t.country.value = 'DE'; t.country.fire('change');
  t.answerBody(1, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  t.answerBody(0, [{ pk_i_id: 10, s_name: 'Maharashtra' }]); await t.flush();
  assert.deepEqual(optionTexts(t.region), ['', 'Bavaria']);
});

await test('dropdown: a late city answer for the old region is ignored after the country changes', async () => {
  const t = run({ style: 'dropdown' });
  t.country.value = 'DE'; t.country.fire('change');
  t.answerBody(0, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  t.region.value = '5'; t.region.fire('change');
  t.country.value = 'IN'; t.country.fire('change');
  t.answerBody(1, [{ pk_i_id: 50, s_name: 'Munich' }]); await t.flush();
  assert.deepEqual([optionTexts(t.city), t.city.disabled], [[''], true]);
});

await test('dropdown: a region loads its cities; clearing it disables the city again', async () => {
  const t = run({ style: 'dropdown' });
  t.country.value = 'DE'; t.country.fire('change');
  t.answerBody(0, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  t.region.value = '5'; t.region.fire('change');
  assert.equal(t.calls[1].url, 'https://x.test/index.php?page=ajax&action=cities&regionId=5');
  t.answerBody(1, [{ pk_i_id: 50, s_name: 'Munich' }]); await t.flush();
  assert.deepEqual([optionTexts(t.city), t.city.disabled], [['', 'Munich'], false]);
  t.region.value = ''; t.region.fire('change');
  assert.deepEqual([optionTexts(t.city), t.city.disabled], [[''], true]);
  assert.equal(t.calls.length, 2);
});

await test('dropdown: a failed load shows an error line and Try again asks again', async () => {
  const t = run({ style: 'dropdown' });
  t.country.value = 'DE'; t.country.fire('change');
  t.pending[0].reject(new Error('down')); await t.flush();
  assert.equal(t.regionErr.hidden, false);
  assert.match(t.regionErr.textContent, /Couldn’t load places\.Try again$/);
  assert.equal(t.region.getAttribute('aria-busy'), null);
  assert.equal(t.region.getAttribute('aria-invalid'), 'true');
  t.regionErr.querySelector('.oe-place__retry').fire('click');
  assert.equal(t.calls.length, 2);
  t.answerBody(1, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  assert.equal(t.regionErr.hidden, true);
  assert.deepEqual(optionTexts(t.region), ['', 'Bavaria']);
  const http = run({ style: 'dropdown' });
  http.country.value = 'DE'; http.country.fire('change');
  http.answerBody(0, [], false); await http.flush();
  assert.equal(http.regionErr.hidden, false);
});

await test('dropdown: a country with no regions stays an empty select when ON and becomes a text box when OFF', async () => {
  const on = run({ style: 'dropdown' });
  on.country.value = 'DE'; on.country.fire('change');
  on.answerBody(0, []); await on.flush();
  assert.deepEqual([optionTexts(on.region), on.region.disabled], [[''], true]);
  const off = run({ style: 'dropdown', strict: '0' });
  off.country.value = 'DE'; off.country.fire('change');
  off.answerBody(0, []); await off.flush();
  const box = off.regionPart.querySelector('input');
  assert.deepEqual([box.getAttribute('name'), box.id, off.regionPart.querySelector('select')], ['region', 'pl-region', null]);
  assert.equal(off.cityPart.querySelector('input').getAttribute('name'), 'city');
});

await test('dropdown: Use my saved place loads both lists, then selects the place', async () => {
  const saved = { label: 'Munich, Bavaria', city_id: 50, region_id: 5, country_code: 'DE', origin: 'address' };
  const t = run({ style: 'dropdown', saved });
  t.savedBtn.fire('click');
  assert.equal(t.country.value, 'DE');
  t.answerBody(0, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  assert.equal(t.calls[1].url, 'https://x.test/index.php?page=ajax&action=cities&regionId=5');
  t.answerBody(1, [{ pk_i_id: 50, s_name: 'Munich' }]); await t.flush();
  assert.deepEqual([t.region.value, t.city.value, t.savedBtn.hidden], ['5', '50', true]);
});

await test('dropdown: with no data-ajax the lists load from the form\'s address', async () => {
  const t = run({ style: 'dropdown', ajax: '' });
  t.country.value = 'DE'; t.country.fire('change');
  assert.equal(t.calls[0].url, 'https://x.test/form.php?page=ajax&action=regions&countryId=DE');
});

await test('dropdown OFF: a region drawn as text turns back into the one regionId control when the new country has regions', async () => {
  const t = run({ style: 'dropdown', strict: '0', textRegion: true });
  t.country.value = 'DE'; t.country.fire('change');
  t.answerBody(0, [{ pk_i_id: 5, s_name: 'Bavaria' }]); await t.flush();
  const named = t.root.querySelectorAll('[name="regionId"]');
  assert.deepEqual([named.length, named[0].tagName], [1, 'SELECT']);
});

console.log(n + ' passed');
