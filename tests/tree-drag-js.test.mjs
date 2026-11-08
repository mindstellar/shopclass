// Runs osc.flatTree from the admin ui-osc.js on plain arrays. Use: node tests/tree-drag-js.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const src = readFileSync(new URL('../oc-admin/themes/modern/js/ui-osc.js', import.meta.url), 'utf8');
const block = src.match(/^osc\.flatTree = \(function \(\) \{[\s\S]*?^\}\)\(\);$/m);
assert.ok(block, 'ui-osc.js defines osc.flatTree');
const ctx = { osc: {} };
vm.runInNewContext(block[0], ctx);
const T = ctx.osc.flatTree;

let failed = 0;
function test(name, fn) {
  try {
    fn();
    console.log('  ok  ' + name);
  } catch (e) {
    failed++;
    console.log('  FAIL ' + name + '\n       ' + e.message);
  }
}

// Vehicles > Cars > Car Parts, Vehicles > Boats, Real estate > Houses, Jobs
const tree = () => [
  ['Vehicles', 0], ['Cars', 1], ['Car Parts', 2], ['Boats', 1],
  ['Real estate', 0], ['Houses', 1], ['Jobs', 0],
].map(([name, depth]) => ({ name, depth }));
const flat = (items) => items.map((i) => i.name + ':' + i.depth).join(' ');
const plain = (v) => JSON.parse(JSON.stringify(v));

test('blockEnd covers the subtree', () => {
  const t = tree();
  assert.equal(T.blockEnd(t, 0), 4);
  assert.equal(T.blockEnd(t, 1), 3);
  assert.equal(T.blockEnd(t, 2), 3);
  assert.equal(T.blockEnd(t, 6), 7);
});

test('height counts the levels below', () => {
  const t = tree();
  assert.equal(T.height(t, 0), 2);
  assert.equal(T.height(t, 1), 1);
  assert.equal(T.height(t, 3), 0);
});

test('range at the start is top level only', () => {
  assert.deepEqual(plain(T.range(tree(), 0)), { min: 0, max: 0 });
});

test('range at the end runs from top level to one below the last row', () => {
  assert.deepEqual(plain(T.range(tree(), 7)), { min: 0, max: 1 });
});

test('range sits between the row below and one deeper than the row above', () => {
  assert.deepEqual(plain(T.range(tree(), 3)), { min: 1, max: 3 });
});

test('range skips the moving block', () => {
  // Cars + Car Parts moving; a slot before Boats sees Vehicles above and Boats below.
  assert.deepEqual(plain(T.range(tree(), 3, 1, 3)), { min: 1, max: 1 });
  assert.deepEqual(plain(T.range(tree(), 1, 1, 3)), { min: 1, max: 1 });
});

test('parentAt finds the nearest row one level up', () => {
  const t = tree();
  assert.equal(T.parentAt(t, 4, 0), -1);
  assert.equal(T.parentAt(t, 4, 1), 0);
  assert.equal(T.parentAt(t, 4, 2), 3);
  assert.equal(T.parentAt(t, 3, 3), 2);
  // A depth with no row one level up above the slot has no parent.
  assert.equal(T.parentAt(t, 4, 3), -1);
  // Boats moving, slot where it was: depth 2 lands inside Cars.
  assert.equal(T.parentAt(t, 3, 2, 3, 4), 1);
  // Car Parts is skipped as part of the moving Cars block.
  assert.equal(T.parentAt(t, 3, 2, 1, 3), -1);
});

test('fits allows a block up to the limit and no deeper', () => {
  assert.equal(T.fits(0, 3, 4), true);
  assert.equal(T.fits(1, 3, 4), false);
  assert.equal(T.fits(2, 1, 4), true);
  assert.equal(T.fits(9, 9, 0), true);
});

test('moveBlock carries a block of 3 right under the row above', () => {
  const t = [{ name: 'A', depth: 0 }, { name: 'B', depth: 0 }, { name: 'B1', depth: 1 }, { name: 'B2', depth: 2 }, { name: 'C', depth: 0 }];
  const at = T.moveBlock(t, 1, 1, 1);
  assert.equal(at, 1);
  assert.equal(flat(t), 'A:0 B:1 B1:2 B2:3 C:0');
});

test('moveBlock down and out lands after the target', () => {
  const t = tree();
  const at = T.moveBlock(t, 0, 7, 0);
  assert.equal(at, 3);
  assert.equal(flat(t), 'Real estate:0 Houses:1 Jobs:0 Vehicles:0 Cars:1 Car Parts:2 Boats:1');
});

test('moveBlock up keeps the index', () => {
  const t = tree();
  assert.equal(T.moveBlock(t, 6, 4, 1), 4);
  assert.equal(flat(t), 'Vehicles:0 Cars:1 Car Parts:2 Boats:1 Jobs:1 Real estate:0 Houses:1');
});

test('keyTarget up and down swap sibling blocks', () => {
  const t = tree();
  assert.deepEqual(plain(T.keyTarget(t, 3, 'up', 0)), { at: 1, depth: 1 });
  assert.deepEqual(plain(T.keyTarget(t, 1, 'down', 0)), { at: 4, depth: 1 });
  assert.deepEqual(plain(T.keyTarget(t, 0, 'down', 0)), { at: 6, depth: 0 });
  const u = tree();
  T.moveBlock(u, 0, 6, 0);
  assert.equal(flat(u), 'Real estate:0 Houses:1 Vehicles:0 Cars:1 Car Parts:2 Boats:1 Jobs:0');
});

test('keyTarget in goes under the sibling above, out goes after the parent', () => {
  const t = tree();
  assert.deepEqual(plain(T.keyTarget(t, 3, 'in', 0)), { at: 3, depth: 2 });
  assert.deepEqual(plain(T.keyTarget(t, 2, 'out', 0)), { at: 3, depth: 1 });
  assert.deepEqual(plain(T.keyTarget(t, 1, 'out', 0)), { at: 4, depth: 0 });
  T.moveBlock(t, 1, 4, 0);
  assert.equal(flat(t), 'Vehicles:0 Boats:1 Cars:0 Car Parts:1 Real estate:0 Houses:1 Jobs:0');
});

test('keyTarget refusals', () => {
  const t = tree();
  assert.deepEqual(plain(T.keyTarget(t, 0, 'up', 0)), { refused: 'first' });
  assert.deepEqual(plain(T.keyTarget(t, 1, 'up', 0)), { refused: 'first' });
  assert.deepEqual(plain(T.keyTarget(t, 3, 'down', 0)), { refused: 'last' });
  assert.deepEqual(plain(T.keyTarget(t, 6, 'down', 0)), { refused: 'last' });
  assert.deepEqual(plain(T.keyTarget(t, 0, 'out', 0)), { refused: 'top' });
  assert.deepEqual(plain(T.keyTarget(t, 1, 'in', 0)), { refused: 'nothing-above' });
  assert.deepEqual(plain(T.keyTarget(t, 0, 'in', 0)), { refused: 'nothing-above' });
  // Real estate (1 level below) under Vehicles makes 3 levels: refused at 2, allowed at 3.
  assert.deepEqual(plain(T.keyTarget(t, 4, 'in', 2)), { refused: 'deep' });
  assert.deepEqual(plain(T.keyTarget(t, 4, 'in', 3)), { at: 4, depth: 1 });
});

test('siblingIndex gives the 1-based place among siblings', () => {
  const t = tree();
  assert.deepEqual(plain(T.siblingIndex(t, 3)), { index: 2, count: 2 });
  assert.deepEqual(plain(T.siblingIndex(t, 4)), { index: 2, count: 3 });
  assert.deepEqual(plain(T.siblingIndex(t, 2)), { index: 1, count: 1 });
});

test('position gives the 0-based place among the new siblings', () => {
  const t = tree();
  // Boats into Cars, after Car Parts.
  assert.equal(T.position(t, 4, 2, 3, 4), 1);
  // Jobs to the very start.
  assert.equal(T.position(t, 0, 0, 6, 7), 0);
  // Vehicles to the end, after Real estate and Jobs.
  assert.equal(T.position(t, 7, 0, 0, 4), 2);
});

test('clamp keeps each item at most one below the one before and within the limit', () => {
  const t = [{ depth: 1 }, { depth: 3 }, { depth: 2 }, { depth: 4 }];
  T.clamp(t, 0);
  assert.deepEqual(t.map((i) => i.depth), [0, 1, 2, 3]);
  const u = [{ depth: 0 }, { depth: 1 }, { depth: 2 }, { depth: 3 }];
  T.clamp(u, 2);
  assert.deepEqual(u.map((i) => i.depth), [0, 1, 1, 1]);
});

if (failed) {
  console.log(failed + ' failed');
  process.exit(1);
}
console.log('all passed');
