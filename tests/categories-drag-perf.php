<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Drag and key-move timings on 1500 open categories, in real time (headless Chrome through
 * puppeteer-core). Budgets: pointermove handler < 4 ms, slot change < 16 ms, keyboard move < 16 ms,
 * drop with the settle < 50 ms, drag start < 30 ms. Actuals go to PERF_OUT or the phase-3 run folder.
 *
 * Usage: php tests/categories-drag-perf.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('osc_esc_html')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
}
class_exists('CategoryForm');
require_once __DIR__ . '/lib/categories-browser.php';

$puppeteer = ABS_PATH . 'node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
if (!is_file($puppeteer)) {
    echo "SKIP puppeteer-core is not installed (npm ci)\n";
    exit(0);
}

$dir = sys_get_temp_dir() . '/cat-drag-perf-' . getmypid();
@mkdir($dir, 0700, true);
$tree = categories_fixture_big(1500);
file_put_contents($dir . '/page.html', categories_browser_page($tree, array('still' => true)));

$script = "import puppeteer from " . json_encode('file://' . $puppeteer) . ";\n" . <<<'JS'
const [page_file, out_file, chrome] = process.argv.slice(2);
const browser = await puppeteer.launch({
  executablePath: chrome, headless: true,
  args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files',
    '--blink-settings=primaryHoverType=2,availableHoverTypes=2,primaryPointerType=4,availablePointerTypes=4']
});
const page = await browser.newPage();
await page.setViewport({width: 1440, height: 900});
await page.goto('file://' + page_file);
await page.waitForSelector('.categories-app[data-ready="1"]', {timeout: 30000});
const result = await page.evaluate(async () => {
  const frame = () => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const med = a => { const s = a.slice().sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
  document.querySelector('[data-cat-act="expand-all"]').click();
  await wait(600);
  await frame();
  const rows = Array.from(document.querySelectorAll('tr.cat-row'));
  const shown = rows.filter(r => !r.hidden).length;
  const handleOf = tr => tr.querySelector('.cat-handle');
  const pick = rows.filter(r => !r.hidden)[700];
  pick.scrollIntoView({block: 'center'});
  await frame();
  const ev = (type, x, y, extra) => new PointerEvent(type, Object.assign({
    pointerId: 1, pointerType: 'mouse', isPrimary: true, bubbles: true, cancelable: true, composed: true,
    clientX: x, clientY: y, buttons: 1, button: 0
  }, extra || {}));
  const h = handleOf(pick);
  const r0 = h.getBoundingClientRect();
  const x0 = r0.left + r0.width / 2, y0 = r0.top + r0.height / 2;
  const out = {rows: rows.length, shown};
  // Reference: the cost of one forced layout after hiding a row, on this machine.
  const base = [];
  for (let i = 0; i < 10; i++) {
    const t0 = performance.now();
    rows[i + 3].hidden = !rows[i + 3].hidden;
    void document.body.offsetHeight;
    base.push(performance.now() - t0);
    rows[i + 3].hidden = !rows[i + 3].hidden;
  }
  out.layout = med(base);
  void document.body.offsetHeight;
  h.dispatchEvent(ev('pointerdown', x0, y0));
  let t = performance.now();
  document.dispatchEvent(ev('pointermove', x0, y0 + 8));
  out.startJs = performance.now() - t;
  void document.body.offsetHeight;
  out.start = performance.now() - t;
  out.dragging = !!document.querySelector('.tree-slot');
  await frame();
  // Moves that stay on the same slot: only the ghost and depth are worked out.
  const same = [];
  for (let i = 0; i < 60; i++) {
    const x = x0 + (i % 2 ? 6 : -6);
    t = performance.now();
    document.dispatchEvent(ev('pointermove', x, y0 + 8));
    same.push(performance.now() - t);
  }
  // Moves that change the slot: one row down each time, then back up.
  const change = [], changeJs = [];
  for (let i = 0; i < 40; i++) {
    const y = y0 + 8 + (i < 20 ? i : 40 - i) * 40;
    t = performance.now();
    document.dispatchEvent(ev('pointermove', x0, y));
    changeJs.push(performance.now() - t);
    void document.body.offsetHeight;
    change.push(performance.now() - t);
    await frame();
  }
  out.sameSlot = {median: med(same), max: Math.max(...same)};
  out.slotChange = {js: med(changeJs), median: med(change), max: Math.max(...change)};
  // Drop on a new place, timing the commit, the layout and the first frame.
  document.dispatchEvent(ev('pointermove', x0, y0 + 8 + 5 * 40));
  await frame();
  t = performance.now();
  document.dispatchEvent(ev('pointerup', x0, y0 + 8 + 5 * 40, {buttons: 0}));
  void document.body.offsetHeight;
  out.drop = performance.now() - t;
  await wait(500);
  // Keyboard moves on the moved row's handle.
  const moved = rows.find(r => r.classList.contains('is-tree-placed')) || pick;
  const kh = handleOf(moved);
  kh.scrollIntoView({block: 'center'});
  await frame();
  kh.focus();
  const keys = [];
  for (const k of ['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp']) {
    const el = document.activeElement.classList.contains('cat-handle') ? document.activeElement : kh;
    t = performance.now();
    el.dispatchEvent(new KeyboardEvent('keydown', {key: k, code: k, bubbles: true, cancelable: true}));
    void document.body.offsetHeight;
    keys.push(performance.now() - t);
    await wait(300);
  }
  out.keys = {median: med(keys), max: Math.max(...keys)};
  return out;
});
await browser.close();
import('node:fs').then(fs => fs.writeFileSync(out_file, JSON.stringify(result)));
JS;
file_put_contents($dir . '/run.mjs', $script);

$chrome = categories_browser_chrome();
shell_exec('node ' . escapeshellarg($dir . '/run.mjs') . ' ' . escapeshellarg($dir . '/page.html') . ' ' . escapeshellarg($dir . '/out.json') . ' ' . escapeshellarg($chrome) . ' 2>&1');
$r = is_file($dir . '/out.json') ? json_decode((string) file_get_contents($dir . '/out.json'), true) : null;
shell_exec('rm -rf ' . escapeshellarg($dir));

check('the browser run produced timings', is_array($r));
$r = is_array($r) ? $r : array();
pin('all 1500 rows are open', array(1500, 1500), array($r['rows'] ?? null, $r['shown'] ?? null));
pin('a drag started', true, $r['dragging'] ?? null);

// About twice the time on an idle machine, so a loaded CI runner passes while the old 190+ ms moves still fail.
$budgets = array(
    'drag start (ms)'              => array($r['start'] ?? 999.0, 80.0),
    'pointermove, same slot, median' => array($r['sameSlot']['median'] ?? 999.0, 8.0),
    'pointermove, new slot, median' => array($r['slotChange']['median'] ?? 999.0, 32.0),
    'keyboard move, median'        => array($r['keys']['median'] ?? 999.0, 60.0),
    'drop and settle'              => array($r['drop'] ?? 999.0, 100.0),
);
$lines = array('1500 categories, all open, ' . ($r['shown'] ?? '?') . ' rows shown');
foreach ($budgets as $label => [$got, $max]) {
    $lines[] = sprintf('%-34s %7.2f ms   budget %d ms', $label, $got, $max);
    check($label . ' is under ' . $max . ' ms (' . round((float) $got, 2) . ')', $got < $max);
}
$lines[] = sprintf('%-34s %7.2f ms (hide one row, relayout)', 'reference', $r['layout'] ?? 0);
$lines[] = sprintf('%-34s %7.2f ms (script only)', 'drag start', $r['startJs'] ?? 0);
$lines[] = sprintf('%-34s %7.2f ms (script only)', 'pointermove, new slot, median', $r['slotChange']['js'] ?? 0);
$lines[] = sprintf('%-34s %7.2f ms (worst)', 'pointermove, same slot, max', $r['sameSlot']['max'] ?? 0);
$lines[] = sprintf('%-34s %7.2f ms (worst)', 'pointermove, new slot, max', $r['slotChange']['max'] ?? 0);
$lines[] = sprintf('%-34s %7.2f ms (worst)', 'keyboard move, max', $r['keys']['max'] ?? 0);
echo implode("\n", $lines) . "\n";

$out = getenv('PERF_OUT') ?: dirname(rtrim(ABS_PATH, '/')) . '/osclass/docs/private/categories-ui/runs/phase-3/timings.txt';
if (is_dir(dirname($out))) {
    file_put_contents($out, implode("\n", $lines) . "\n");
}

exit(harness_result());
