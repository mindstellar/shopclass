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
  replaceChildren(...nodes) { this._text = ''; this.children = []; nodes.forEach((n) => this.appendChild(n)); }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  fire(type, extra = {}) {
    const e = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...extra };
    const bubbles = ['click', 'mousemove', 'mousedown'].includes(type);
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

function run(opts = {}) {
  const f = build(opts);
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
    type(v) { f.input.value = v; f.input.fire('input'); },
    answer(i, rows, more = 0, ok = true) {
      pending[i].resolve({ ok, status: ok ? 200 : 500, json: async () => ({ data: rows, meta: { more } }) });
    },
    options() { return f.list.querySelectorAll('.osc-ac-option'); },
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

console.log(n + ' passed');
