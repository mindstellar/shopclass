// Runs the installer's step 5 runner in install.js against a stub DOM and fetch. Use: node tests/install-runner-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code = readFileSync(new URL('../oc-includes/osclass/installer/install.js', import.meta.url), 'utf8');

class ClassList {
  constructor() { this.set = new Set(); }
  add(...c) { c.forEach((x) => this.set.add(x)); }
  remove(...c) { c.forEach((x) => this.set.delete(x)); }
  toggle(c, on) { (on ?? !this.set.has(c)) ? this.set.add(c) : this.set.delete(c); }
  contains(c) { return this.set.has(c); }
}

class El {
  constructor(tag, id = '') {
    this.tagName = tag.toUpperCase();
    this.id = id;
    this.attrs = {};
    this.children = [];
    this.classList = new ClassList();
    this.style = {};
    this.hidden = false;
    this.value = '';
    this.listeners = {};
    this.focused = 0;
    this._text = '';
  }
  set className(v) { this.classList = new ClassList(); String(v).split(/\s+/).filter(Boolean).forEach((c) => this.classList.add(c)); }
  get className() { return [...this.classList.set].join(' '); }
  set textContent(v) { this.children = []; this._text = String(v); }
  get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  appendChild(c) { this.children.push(c); return c; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  focus() { this.focused++; }
  submit() { this.submitted = true; }
  all() { return this.children.flatMap((c) => (c instanceof El ? [c, ...c.all()] : [])); }
  querySelectorAll(sel) { const cls = sel.replace(/^.*\./, ''); return this.all().filter((e) => e.classList.contains(cls)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

class TextNode {
  constructor(t) { this.textContent = t; }
}

function plan() {
  const unit = (action, lost, label, fields = {}) => ({ action, fields, lost, label });
  return [
    { id: 'account', label: 'Create your admin account', stop: true, units: [unit('account', '', 'Create your admin account')] },
    { id: 'starter', label: 'Add starter categories', units: [unit('starter', 'starter', 'Add starter categories')] },
    { id: 'locations', label: 'Add places in %s', when: 'country', units: [unit('locations', 'locations', 'Add places in %s')] },
    { id: 'examples', label: 'Add example listings', units: [0, 1, 2].map((b) => unit('sample_batch', 'sample_batch', `batch ${b + 1} of 3`, { batch: String(b) })) },
    { id: 'plugins', label: 'Add shop plugins', units: [unit('install_package', 'plugin:a', 'Plugin A', { slug: 'a' }), unit('install_package', 'plugin:b', 'Plugin B', { slug: 'b' })] },
  ];
}

// answers: a function of (action, params, call number) returning an object, 'abort', '502' or a JSON value.
async function boot(answers, opts = {}) {
  const byId = {};
  const add = (tag, id) => (byId[id] = new El(tag, id));
  const form = add('form', 'ins-target-form');
  form.setAttribute('data-install-plan', JSON.stringify(plan()));
  form.elements = { install_nonce: { value: 'N1' } };
  form.fields = [];
  const field = (id, value) => { const el = add('input', id); el.value = value; el.name = id; form.fields.push(el); return el; };
  field('webtitle', 'My site');
  field('admin_user', 'admin');
  field('email', 'a@b.test');
  const country = field('location-json', 'IN');
  country.selectedOptions = [{ textContent: 'India' }];
  ['ins-target-wrap', 'ins-progress-announcer', 'ins-progress-title', 'ins-meter-now', 'ins-meter-count', 'ins-track', 'ins-fill', 'ins-slow', 'ins-tasks', 'ins-eta', 'admin_user-error'].forEach((id) => add(id === 'ins-tasks' ? 'ul' : 'div', id));
  const DEFAULT_TEXT = "The server didn't answer. Your details are still here.";
  const panelWithText = (id, text) => {
    const panel = add('div', id);
    panel.hidden = true;
    const slot = new El('p');
    slot.className = 'ins-panel-text';
    slot.textContent = text;
    panel.appendChild(slot);
    return panel;
  };
  panelWithText('ins-target-error', DEFAULT_TEXT);
  if (opts.doneTitle) add('h1', 'ins-done-title');
  panelWithText('ins-progress-error', '');
  const reloadLink = add('a', 'ins-progress-reload');
  reloadLink.hidden = true;
  const progress = add('section', 'ins-progress');
  progress.hidden = true;
  const body = new El('div');
  body.className = 'ins-stage-body';
  const actions = new El('div');
  actions.className = 'ins-actions';
  progress.appendChild(body);
  progress.appendChild(actions);
  actions.appendChild(byId['ins-eta']);

  const announced = [];
  const announcer = byId['ins-progress-announcer'];
  Object.defineProperty(announcer, 'textContent', { set(v) { if (v !== '') announced.push(String(v)); }, get() { return announced[announced.length - 1] || ''; } });
  const doc = {
    body: new El('body'),
    getElementById: (id) => byId[id] || null,
    querySelectorAll: () => [],
    querySelector: () => null,
    createElement: (tag) => new El(tag),
    createTextNode: (t) => new TextNode(t),
    addEventListener: (t, f) => { if (t === 'DOMContentLoaded') doc.ready = f; },
  };
  const calls = [];
  const fetchStub = (url, opts) => {
    const params = new URLSearchParams(opts.body);
    const action = params.get('action');
    calls.push(params);
    const answer = answers(action, params, calls.length);
    if (answer === 'abort') return Promise.reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
    if (answer === '502') return Promise.resolve({ status: 502, json: () => Promise.reject(new SyntaxError('Unexpected token <')) });
    return Promise.resolve({ status: 200, json: () => Promise.resolve(answer) });
  };
  class FormData {
    constructor(f) { this.f = f; }
    forEach(cb) { this.f.fields.forEach((el) => cb(el.value, el.name)); }
  }
  const win = {
    setTimeout: (fn, ms) => (ms <= 1000 ? (queueMicrotask(fn), 1) : 2),
    clearTimeout: () => {},
    location: { assign: (u) => { win.assigned = u; } },
    leave: new Set(),
    leaveSeen: 0,
    addEventListener: (t, f) => { if (t === 'beforeunload') { win.leave.add(f); win.leaveSeen++; } },
    removeEventListener: (t, f) => { if (t === 'beforeunload') win.leave.delete(f); },
  };
  const ctx = { window: win, document: doc, fetch: fetchStub, FormData, URLSearchParams, AbortController, JSON, String, Math, Object, Array, Promise, parseInt, queueMicrotask };
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  doc.ready();
  form.listeners.submit[0]({ preventDefault() {} });
  for (let i = 0; i < 300; i++) await new Promise((r) => setImmediate(r));

  const sent = doc.body.children[0] || null;
  const lost = sent ? sent.children.filter((c) => c.name === 'lost[]').map((c) => c.value) : null;
  const rows = byId['ins-tasks'].children.map((li) => ({
    s: li.getAttribute('data-s'),
    name: li.children[1].children[0].textContent,
    sub: li.children[1].children[1].textContent,
    state: li.children[2].textContent,
  }));
  return { announced, byId, form, progress, body, actions, calls, sent, lost, rows, win };
}

const ok = { status: true };
const actions = (r) => r.calls.map((p) => p.get('action') + (p.get('batch') ? ':' + p.get('batch') : '') + (p.get('slug') ? ':' + p.get('slug') : ''));
const all = ['account', 'starter', 'locations', 'sample_batch:0', 'sample_batch:1', 'sample_batch:2', 'install_package:a', 'install_package:b'];

// Every unit succeeds.
{
  const r = await boot((a) => (a === 'account' ? { status: true, password: 'pw1', email_status: 'sent' } : ok));
  assert.deepEqual(actions(r), all, 'every unit runs once, in order');
  assert.deepEqual(r.rows.map((x) => x.s), ['ok', 'ok', 'ok', 'ok', 'ok']);
  assert.equal(r.rows[2].name, 'Add places in India');
  assert.ok(r.sent && r.sent.submitted, 'the finish form is posted');
  assert.deepEqual(r.lost, []);
  const val = (n) => r.sent.children.find((c) => c.name === n).value;
  assert.equal(val('password'), 'pw1');
  assert.equal(val('result'), 'sent');
  assert.equal(val('install_nonce'), 'N1');
  assert.equal(r.byId['ins-meter-count'].textContent, '8 of 8 done');
  assert.equal(r.byId['ins-fill'].style.transform, 'scaleX(1)');
  assert.equal(r.byId['ins-track'].getAttribute('aria-valuetext'), '8 of 8 done');
  assert.ok(r.byId['ins-track'].classList.contains('is-finished'));
  assert.equal(r.byId['ins-target-wrap'].getAttribute('aria-busy'), null, 'the wrap is never marked busy');
  assert.deepEqual(r.announced, ['Create your admin account done', 'Add starter categories done', 'Add places in India done', 'Add example listings done', 'Add shop plugins done', 'All done'], 'one message per task, not per unit');
  assert.equal(r.calls[2].get('location-json'), 'IN');
  assert.ok(r.win.leaveSeen > 0, 'leaving the tab asks first while the install runs');
  assert.equal(r.win.leave.size, 0, 'the leave guard is gone before the finish post');
}

// Timeouts, a 502 and refusals are skipped with reasons, and the run goes on.
{
  const r = await boot((a, p) => {
    if (a === 'starter') return 'abort';
    if (a === 'locations') return { status: false, message: 'No places for this country.' };
    if (a === 'sample_batch') return p.get('batch') === '0' ? ok : '502';
    if (a === 'install_package') return p.get('slug') === 'a' ? { status: false, error: 'Plugin A failed' } : ok;
    return { status: true, password: 'pw' };
  });
  assert.deepEqual(actions(r), all, 'later units still run after skips');
  assert.deepEqual(r.rows.map((x) => x.s), ['ok', 'skip', 'skip', 'skip', 'skip']);
  assert.equal(r.rows[1].sub, 'The server did not answer in time, or the connection dropped.');
  assert.equal(r.rows[2].sub, 'No places for this country.');
  assert.equal(r.rows[3].state, '1 of 3 added');
  assert.equal(r.rows[3].sub, "batch 2 of 3, batch 3 of 3 couldn't be added. You can add it later.");
  assert.equal(r.rows[4].state, '1 of 2 added');
  assert.equal(r.rows[4].sub, "Plugin A couldn't be added. You can add it later.");
  assert.deepEqual(r.lost, ['starter', 'sample_batch'], 'only units with no answer are posted as lost, once each');
  assert.equal(r.byId['ins-meter-count'].textContent, '8 of 8 done');
  assert.ok(r.sent && r.sent.submitted);
  assert.equal(r.announced.length, 6, 'skips add no extra messages');
  assert.equal(r.announced[1], 'Add starter categories. The server did not answer in time, or the connection dropped.');
}

// A refused account field stops the run and shows the form.
{
  const r = await boot((a) => (a === 'account' ? { status: false, field: 'admin_user', error: 'That username is taken.' } : ok));
  assert.deepEqual(actions(r), ['account'], 'nothing runs after the account fails');
  assert.equal(r.form.hidden, false);
  assert.equal(r.progress.hidden, true);
  assert.equal(r.byId.admin_user.getAttribute('aria-invalid'), 'true');
  assert.ok(r.byId.admin_user.focused > 0);
  assert.equal(r.sent, null);
  assert.equal(r.win.leave.size, 0, 'the leave guard is gone when the form comes back');
}

// An account timeout stops the run and shows the error panel.
{
  const r = await boot((a) => (a === 'account' ? 'abort' : ok));
  assert.deepEqual(actions(r), ['account']);
  assert.equal(r.form.hidden, false);
  const panel = r.byId['ins-target-error'];
  assert.equal(panel.hidden, false);
  assert.equal(panel.querySelector('.ins-panel-text').textContent, "The server didn't answer. Your details are still here.", 'no server error keeps the rendered default');
  assert.ok(panel.focused > 0);
  assert.equal(r.sent, null);
}

// A server error on the account replaces the default text, and a later timeout brings it back.
{
  let n = 0;
  const r = await boot((a) => (a === 'account' ? (++n === 1 ? { status: false, error: 'Could not write to the database.' } : 'abort') : ok));
  const slot = r.byId['ins-target-error'].querySelector('.ins-panel-text');
  assert.equal(slot.textContent, 'Could not write to the database.');
  r.form.listeners.submit[0]({ preventDefault() {} });
  for (let i = 0; i < 50; i++) await new Promise((res) => setImmediate(res));
  assert.equal(slot.textContent, "The server didn't answer. Your details are still here.", 'the rendered default comes back');
}
{
  const r = await boot((a) => (a === 'account' ? { status: false, error: 'Could not write to the database.' } : ok));
  assert.equal(r.byId['ins-target-error'].querySelector('.ins-panel-text').textContent, 'Could not write to the database.');
  assert.equal(r.byId['ins-target-error'].hidden, false);
}

// A lost session on the account opens step 4, which renders the session-ended stage.
{
  const r = await boot((a) => (a === 'account' ? { status: false, fatal: true, error: 'Your session expired.' } : ok));
  assert.deepEqual(actions(r), ['account']);
  assert.equal(r.win.assigned, 'install.php?step=4');
  assert.equal(r.byId['ins-target-error'].hidden, true, 'the account panel is not shown');
  assert.equal(r.sent, null);
}

// A fatal answer stops the run with Reload.
{
  const r = await boot((a) => (a === 'starter' ? { status: false, fatal: true, error: 'The database went away.' } : { status: true }));
  assert.deepEqual(actions(r), ['account', 'starter']);
  assert.equal(r.rows[1].s, 'skip');
  assert.equal(r.rows[1].sub, 'The database went away.');
  assert.ok(r.byId['ins-track'].classList.contains('is-finished'));
  assert.equal(r.byId['ins-eta'].hidden, true);
  assert.equal(r.byId['ins-progress-reload'].hidden, false);
  const panel = r.byId['ins-progress-error'];
  assert.equal(panel.hidden, false);
  assert.equal(panel.querySelector('.ins-panel-text').textContent, 'The database went away.');
  assert.ok(panel.focused > 0);
  assert.equal(r.body.children.length, 0, 'no panel is built by the script');
  assert.equal(r.actions.children.length, 1, 'no link is built by the script');
  assert.equal(r.sent, null);
}

// The done page moves focus to its headline on load.
{
  const r = await boot((a) => (a === 'account' ? { status: true } : ok), { doneTitle: true });
  assert.ok(r.byId['ins-done-title'].focused > 0, 'the done headline gets focus');
}

// A new run after a fatal stop clears the danger panel, Reload and the spoken count.
{
  let n = 0;
  const r = await boot((a) => {
    if (a === 'account') return ++n === 1 ? { status: true } : 'abort';
    return a === 'starter' ? { status: false, fatal: true, error: 'The database went away.' } : ok;
  });
  assert.equal(r.byId['ins-progress-error'].hidden, false);
  assert.notEqual(r.byId['ins-track'].getAttribute('aria-valuetext'), null);
  r.form.listeners.submit[0]({ preventDefault() {} });
  for (let i = 0; i < 50; i++) await new Promise((res) => setImmediate(res));
  assert.equal(r.byId['ins-progress-error'].hidden, true, 'the old danger panel is hidden');
  assert.equal(r.byId['ins-progress-reload'].hidden, true, 'Reload is hidden');
  assert.equal(r.byId['ins-track'].getAttribute('aria-valuetext'), null, 'the old spoken count is cleared');
}

console.log('install runner: all checks passed');
