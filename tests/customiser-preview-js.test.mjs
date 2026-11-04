// Runs oc-includes/assets/osclass/customiser-preview.js against a stub DOM. Use: node tests/customiser-preview-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code = readFileSync(new URL('../oc-includes/assets/osclass/customiser-preview.js', import.meta.url), 'utf8');

class El {
  constructor(attrs = {}) {
    this.attrs = { ...attrs };
    this.classes = new Set();
    this.scrolled = 0;
    this.classList = {
      add: (c) => this.classes.add(c),
      remove: (c) => this.classes.delete(c),
      contains: (c) => this.classes.has(c),
      toggle: (c, on) => (on ? this.classes.add(c) : this.classes.delete(c)),
    };
    this.rect = { top: 300, bottom: 500, left: 40, right: 760, height: 200 };
    this.css = { position: 'static', fontSize: '16px' };
    this.vars = {};
    this.style = { setProperty: (k, v) => { this.vars[k] = v; }, removeProperty: (k) => { delete this.vars[k]; } };
  }
  getBoundingClientRect() { return this.rect; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  closest(sel) { return sel === '[data-osc-block]' && 'data-osc-block' in this.attrs ? this : (this.parent ? this.parent.closest(sel) : null); }
  scrollIntoView(o) { this.scrolled += 1; this.scrollOpts = o; }
}

function boot({ adminMeta = '' } = {}) {
  const blocks = [new El({ 'data-osc-block': '11', 'data-osc-block-name': 'Intro' }), new El({ 'data-osc-block': 'n1' })];
  const inner = new El();
  inner.parent = blocks[0];
  const outside = new El();
  const header = new El();
  header.css.position = 'sticky';
  header.rect = { top: 0, bottom: 68, left: 0, right: 800, height: 68 };
  const listeners = {};
  const posted = [];
  const post = (m, o) => posted.push(JSON.parse(JSON.stringify([m, o])));
  const doc = {
    documentElement: new El(),
    querySelector: (s) => (s === 'meta[name="osc-customiser"]' && adminMeta ? new El({ content: adminMeta }) : null),
    querySelectorAll: (s) => (s === '[data-osc-block]' ? blocks : s === '.osc-block-selected' ? blocks.filter((b) => b.classes.has('osc-block-selected')) : []),
    addEventListener: (t, f) => { (listeners[t] = listeners[t] || []).push(f); },
    elementsFromPoint: () => [header],
  };
  doc.documentElement.clientWidth = 800;
  const win = {
    addEventListener: (t, f) => { (listeners['w:' + t] = listeners['w:' + t] || []).push(f); },
    parent: { postMessage: post },
    innerWidth: 800,
    innerHeight: 900,
    scrollY: 0,
  };
  const ctx = { document: doc, window: win, location: { origin: 'https://shop.test', href: 'https://shop.test/p' }, matchMedia: () => ({ matches: false }), getComputedStyle: (el) => el.css, requestAnimationFrame: (f) => f(), URL, Element: El, String, Array, Object };
  vm.runInNewContext(code, ctx);
  const send = (data, origin = 'https://shop.test') => (listeners['w:message'] || []).forEach((f) => f({ data, origin }));
  const click = (target) => {
    const ev = { target, prevented: false, preventDefault() { this.prevented = true; } };
    (listeners.click || []).forEach((f) => f(ev));
    return ev;
  };
  const submit = () => {
    const ev = { prevented: false, preventDefault() { this.prevented = true; } };
    (listeners.submit || []).forEach((f) => f(ev));
    return ev;
  };
  return { blocks, inner, outside, posted, send, click, submit, doc };
}

{
  const t = boot();
  assert.deepEqual(t.posted[0], [{ type: 'osc-customiser', ready: true }, 'https://shop.test']);
}

{
  const t = boot();
  t.send({ type: 'osc-customiser', select: '11' }, 'https://evil.test');
  assert.equal(t.blocks[0].classes.size, 0, 'an untrusted origin is ignored');
  t.send({ type: 'other', select: '11' });
  assert.equal(t.blocks[0].classes.size, 0, 'a foreign message type is ignored');
}

{
  const t = boot({ adminMeta: 'https://admin.test' });
  t.send({ type: 'osc-customiser', select: '11' });
  assert.equal(t.blocks[0].classes.size, 0, 'an admin origin other than this one is not trusted');
}

{
  const t = boot();
  t.send({ type: 'osc-customiser', select: '11', name: 'Hero' });
  assert.equal(t.blocks[0].classes.has('osc-block-selected'), true);
  assert.equal(t.blocks[0].getAttribute('data-osc-block-name'), 'Hero');
  assert.equal(t.blocks[0].scrolled, 1);
  t.send({ type: 'osc-customiser', select: 'n1' });
  assert.equal(t.blocks[0].classes.size, 0, 'selecting another block clears the first');
  assert.equal(t.blocks[1].classes.has('osc-block-selected'), true);
}

{
  const t = boot();
  t.send({ type: 'osc-customiser', text: { section: '11', field: 'title', value: 'x' } });
  assert.deepEqual(t.posted.at(-1)[0], { type: 'osc-customiser', missed: true });
}

{
  const t = boot();
  const ev = t.click(t.inner);
  assert.equal(ev.prevented, true);
  assert.deepEqual(t.posted.at(-1), [{ type: 'osc-customiser', picked: '11' }, 'https://shop.test']);
  const before = t.posted.length;
  assert.equal(t.click(t.outside).prevented, true, 'a click outside a block does not navigate either');
  assert.equal(t.posted.length, before, 'and picks nothing');
  assert.equal(t.submit().prevented, true);
}

{
  const t = boot();
  assert.equal(t.doc.documentElement.vars['--osc-preview-top'], '68px', 'the sticky header height is published for scroll-margin');
  t.send({ type: 'osc-customiser', select: '11' });
  assert.equal(t.blocks[0].classes.has('osc-block-tag-in'), false, 'a block well below the header keeps its tag above');
  t.blocks[1].rect = { top: 40, bottom: 400, left: 40, right: 760, height: 360 };
  t.send({ type: 'osc-customiser', select: 'n1' });
  assert.equal(t.blocks[1].classes.has('osc-block-tag-in'), true, 'a block under the header gets its tag inside');
  assert.equal(t.blocks[1].vars['--osc-block-tag-top'], '30px', 'just below the header');
  t.blocks[0].rect = { top: 300, bottom: 500, left: 0, right: 800, height: 200 };
  t.send({ type: 'osc-customiser', select: '11' });
  assert.equal(t.blocks[0].classes.has('osc-block-flush'), true, 'a full-width block draws its outline inside');
}

{
  const t = boot();
  t.send({ type: 'osc-customiser', rename: { ref: '11', name: 'Our story' } });
  assert.equal(t.blocks[0].getAttribute('data-osc-block-name'), 'Our story');
  t.send({ type: 'osc-customiser', rename: { ref: '11', name: '' } });
  assert.equal(t.blocks[0].getAttribute('data-osc-block-name'), 'Our story', 'an empty name is ignored');
}
