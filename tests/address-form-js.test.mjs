// Runs oc-includes/assets/osclass/address-form.js against a stub DOM. Use: node tests/address-form-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code = readFileSync(new URL('../oc-includes/assets/osclass/address-form.js', import.meta.url), 'utf8');

class El {
  constructor(tag) {
    this.tagName = tag.toUpperCase();
    this.attrs = {};
    this.children = [];
    this.parentNode = null;
    this.value = '';
    this.required = false;
    this.disabled = false;
    this.hidden = false;
    this.listeners = {};
    this.textContent = '';
  }
  get firstChild() { return this.children[0] || null; }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'name') this.name = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; }
  appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; }
  removeChild(c) { this.children.splice(this.children.indexOf(c), 1); c.parentNode = null; return c; }
  replaceChild(n, o) { this.children[this.children.indexOf(o)] = n; n.parentNode = this; o.parentNode = null; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  dispatchEvent(e) { (this.listeners[e.type] || []).forEach((f) => f(e)); return true; }
  setCustomValidity() {}
  reportValidity() {}
  all() { return this.children.flatMap((c) => [c, ...c.all()]); }
  match(sel) {
    const m = sel.match(/^(\w+)?(?:\[([\w-]+)(?:="([^"]*)")?\])?$/);
    if (!m) return false;
    if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
    if (m[2] && !(m[2] in this.attrs)) return false;
    if (m[3] !== undefined && this.attrs[m[2]] !== m[3]) return false;
    return true;
  }
  querySelectorAll(sel) {
    const parts = sel.split(' ');
    let pool = [this];
    for (const part of parts) {
      const found = [];
      pool.forEach((p) => p.all().forEach((e) => { if (e.match(part) && !found.includes(e)) found.push(e); }));
      pool = found;
    }
    return pool;
  }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

function field(name, tag = 'input') {
  const box = new El('div');
  box.setAttribute('data-address-field', name);
  const label = new El('label');
  const el = new El(tag);
  el.setAttribute('name', name);
  el.setAttribute('id', 'a-' + name);
  box.appendChild(label);
  box.appendChild(el);
  return { box, el };
}

const FORMATS = {
  US: { fields: ['line1', 'city', 'state', 'postcode'], required: ['line1', 'city', 'state', 'postcode'], state: 'State', postcode: 'ZIP code', pattern: null, example: '' },
  GB: { fields: ['line1', 'city', 'postcode'], required: ['line1', 'city', 'postcode'], state: '', postcode: 'Postcode', pattern: null, example: '' },
  IE: { fields: ['line1', 'city', 'state'], required: ['line1', 'city'], state: 'County', postcode: '', pattern: null, example: '' },
};
const REGIONS = { US: [{ pk_i_id: 1, s_name: 'Texas' }, { pk_i_id: 2, s_name: 'Ohio' }], GB: [{ s_name: 'Kent' }] };

function boot({ saved = '', country = 'US', fetchRows = REGIONS } = {}) {
  const form = new El('form');
  const holder = new El('script');
  holder.textContent = JSON.stringify(FORMATS);
  holder.setAttribute('data-regions-url', '/ajax?countryId=');
  holder.setAttribute('data-choose', 'Choose…');
  const sel = new El('select');
  sel.setAttribute('name', 'country');
  sel.value = country;
  form.appendChild(sel);
  const inner = new El('div');
  inner.setAttribute('data-address-fields', '');
  form.appendChild(inner);
  const fields = {};
  ['line1', 'city', 'state', 'postcode'].forEach((n) => { fields[n] = field(n); inner.appendChild(fields[n].box); });
  fields.state.el.value = saved;
  const events = [];
  const calls = [];
  form.addEventListener('osc:address-country', (e) => events.push(e.detail));
  const doc = {
    getElementById: (id) => (id === 'osc-address-formats' ? holder : null),
    querySelectorAll: () => [form],
    createElement: (t) => new El(t),
  };
  const ctx = {
    document: doc,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
    fetch: (url) => { calls.push(url); const code = url.split('=').pop(); return Promise.resolve({ ok: true, json: () => Promise.resolve(fetchRows[code] || []) }); },
    Promise, Array, JSON, RegExp, String, encodeURIComponent,
  };
  vm.runInNewContext(code, ctx);
  const state = () => fields.state.box.querySelector('[name="state"]');
  const change = async (to) => { sel.value = to; sel.dispatchEvent({ type: 'change' }); await tick(); };
  return { form, events, calls, state, change, fields, sel };
}
const tick = () => new Promise((r) => setTimeout(r, 0));

// US with regions: the State box becomes a select with the regions.
{
  const t = boot();
  await tick();
  const s = t.state();
  assert.equal(s.tagName, 'SELECT');
  assert.deepEqual(s.children.map((o) => o.value), ['', 'Texas', 'Ohio']);
  assert.equal(s.children[0].textContent, 'Choose…');
  assert.equal(s.getAttribute('id'), 'a-state');
  assert.equal(s.required, true);
  assert.equal(t.events.length, 1);
  assert.equal(t.events[0].country, 'US');
  assert.equal(JSON.stringify(t.events[0].regions), '["Texas","Ohio"]');
}

// A saved region that is not in the list stays as its own option and stays chosen.
{
  const t = boot({ saved: 'Narnia' });
  await tick();
  const s = t.state();
  assert.deepEqual(s.children.map((o) => o.value), ['', 'Texas', 'Ohio', 'Narnia']);
  assert.equal(s.value, 'Narnia');
}

// GB shows no State; a country with no regions keeps a text box; switching drops a stale choice.
{
  const t = boot();
  await tick();
  await t.change('GB');
  assert.equal(t.fields.state.box.hidden, true);
  assert.equal(t.state().disabled, true);
  assert.equal(t.events.at(-1).regions.length, 0);
  await t.change('IE');
  assert.equal(t.state().tagName, 'INPUT');
  assert.equal(t.state().value, '');
  assert.equal(t.state().disabled, false);
  assert.equal(t.state().getAttribute('maxlength'), '100');
}

// Regions are fetched once per country.
{
  const t = boot();
  await tick();
  await t.change('IE');
  await t.change('US');
  assert.equal(t.calls.filter((u) => u.endsWith('US')).length, 1);
}

console.log('address-form.js: ok');
