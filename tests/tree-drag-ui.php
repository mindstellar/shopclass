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
 * The shared tree drag (osc.sortable tree mode) and osc.toast in headless Chrome, on a static
 * list of 8 rows in 3 levels (tests/lib/tree-drag-page.html), driven by synthetic pointer and key
 * events. Also checks that plain osc.sortable() still hands SortableJS today's options.
 *
 * Usage: php tests/tree-drag-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-browser.php';

/**
 * Runs one driver body (the inside of an async function taking t) on the static page.
 *
 * @param array<string,mixed> $opts limit (levels), rtl, reduced (prefers-reduced-motion), width
 *
 * @return array<string,mixed>
 */
function tree_ui_run(string $body, array $opts = array()): array
{
    $chrome = categories_browser_chrome();
    $theme  = ABS_PATH . 'oc-admin/themes/modern/';
    $assets = ABS_PATH . 'oc-includes/assets/';
    $json   = static fn ($v): string => (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    $driver = "window.catDriver = async function (t) {\n"
        . "treePointer.install(t);\n"
        . "var r = {};\n"
        . "var row = function (n) { return t.\$\$('tr.tree-row').find(function (tr) { return tr.querySelector('.tree-name').textContent === n; }); };\n"
        . "var handle = function (n) { return row(n).querySelector('.tree-handle'); };\n"
        . "var order = function () { return t.\$\$('tr.tree-row').map(function (tr) { return tr.querySelector('.tree-name').textContent + ':' + tr.dataset.depth + (tr.hidden ? 'h' : ''); }); };\n"
        . "var slot = function () { return t.\$('.tree-slot'); };\n"
        . "var above = function () { var a = t.\$('.is-tree-gap-after'); if (a) { return a; } var p = t.\$('.is-tree-gap-before'); p = p && p.previousElementSibling; while (p && p.hidden) { p = p.previousElementSibling; } return p; };\n"
        . "var tag = function () { return slot() ? slot().querySelector('.tree-slot-tag').textContent : null; };\n"
        . "var depth = function () { return slot() ? slot().style.getPropertyValue('--tree-d') : null; };\n"
        . "var tinted = function () { return t.\$\$('.is-drop-parent').map(function (el) { return el.querySelector('.tree-name').textContent; }); };\n"
        . $body . "\nreturn r;\n};\n";

    $page = '<!doctype html><html lang="en" data-bs-theme="light"' . (!empty($opts['rtl']) ? ' dir="rtl"' : '') . '><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . $theme . 'css/main.css">'
        . '<link rel="stylesheet" href="file://' . $assets . 'bootstrap-icons/bootstrap-icons.min.css">'
        . '</head><body><pre id="osc-out" hidden></pre>'
        . '<script>' . file_get_contents(__DIR__ . '/lib/categories-fake-env.js') . '</script>'
        . '<script>window.osc = window.osc || {}; osc.locales = {_default: "en_US", current: "en_US", codes: ["en_US"]}; osc.langs = {};'
        . 'window.__treeOpts = ' . $json(array('limit' => (int) ($opts['limit'] ?? 0))) . ';</script>'
        . '<script src="file://' . $theme . 'js/osc.js"></script>'
        . '<script src="file://' . $theme . 'js/ui-osc.js"></script>'
        . '<script src="file://' . $theme . 'js/tree-drag.js"></script>'
        . '<script src="file://' . __DIR__ . '/lib/tree-pointer.js"></script>'
        . '<script>' . categories_browser_helpers_js() . '</script>'
        . file_get_contents(__DIR__ . '/lib/tree-drag-page.html')
        . '<script>' . $driver . '</script>'
        . '<script>' . categories_browser_runner_js(true) . '</script>'
        . '</body></html>';

    $dir = sys_get_temp_dir() . '/osc-tree-drag-' . getmypid() . '-' . substr(md5($body . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/page.html', $page);
    $out = (string) shell_exec(escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
        . CATEGORIES_BROWSER_MOUSE . (!empty($opts['reduced']) ? CATEGORIES_BROWSER_STILL : '')
        . ' --window-size=' . (int) ($opts['width'] ?? 1440) . ',900 --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=30000 --dump-dom ' . escapeshellarg('file://' . $dir . '/page.html') . ' 2>/dev/null');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    $r = null;
    if (preg_match('#<pre id="osc-out"[^>]*>([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
        $r = json_decode((string) base64_decode($m[1], true), true);
    }
    check('the browser run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));
    $r = is_array($r) ? $r : array();
    pin('no script error', array(), $r['errors'] ?? null);

    return $r;
}

$start = array('Vehicles:0', 'Cars:1', 'Car Parts:2', 'Boats:1', 'Real estate:0', 'Houses:1h', 'Flats:1h', 'Jobs:0');

echo "Drag right, clamp, drop\n";
$r = tree_ui_run(<<<'JS'
var g = t.drag(handle('Boats'));
await g.by(28, 0, 4);
r.oneStep = [tag(), depth(), tinted(), t.$('.tree-ghost-name').textContent, !!t.$('.tree-ghost-more'), document.documentElement.classList.contains('is-tree-dragging')];
r.slotWide = Math.round(slot().getBoundingClientRect().width) === Math.round(t.$('#tree').getBoundingClientRect().width);
await g.by(72, 0, 4);
r.clampRight = [tag(), depth(), tinted()];
await g.by(-240, 0, 6);
r.clampLeft = [tag(), depth(), tinted()];
await g.by(168, 0, 6);
r.back = [tag(), depth()];
await g.drop();
r.moves = window.__moves;
r.after = order();
r.clean = [!!slot(), !!t.$('.tree-ghost'), document.documentElement.classList.contains('is-tree-dragging'), tinted().length];
JS);
pin('one step right: inside Cars, depth 2, Cars tinted, ghost names Boats', array('Inside Cars', '2', array('Cars'), 'Boats', false, true), $r['oneStep'] ?? null);
pin('the table slot spans the table', true, $r['slotWide'] ?? null);
pin('further right stops one below the row above', array('Inside Car Parts', '3', array('Car Parts')), $r['clampRight'] ?? null);
pin('far left stops at the depth of the row below', array('Top level', '0', array()), $r['clampLeft'] ?? null);
pin('back to one step right', array('Inside Cars', '2'), $r['back'] ?? null);
pin('commit gets the move', array(array('name' => 'Boats', 'from' => 3, 'at' => 4, 'depth' => 2, 'parent' => 'Cars', 'index' => 1, 'via' => 'drag')), $r['moves'] ?? null);
pin('the row lands under Cars', array('Vehicles:0', 'Cars:1', 'Car Parts:2', 'Boats:2', 'Real estate:0', 'Houses:1h', 'Flats:1h', 'Jobs:0'), $r['after'] ?? null);
pin('slot, ghost, dragging class and tint are gone', array(false, false, false, 0), $r['clean'] ?? null);

echo "A block moves together\n";
$r = tree_ui_run(<<<'JS'
var j = row('Jobs').getBoundingClientRect();
var g = t.drag(handle('Vehicles'));
await g.by(10, 120, 2);
var prev = above();
r.early = prev ? prev.querySelector('.tree-name').textContent : null;
await g.to(j.left + 60, j.bottom + 30, 8);
r.mid = [t.$('.tree-ghost-more') ? t.$('.tree-ghost-more').textContent : null, row('Cars').hidden, row('Boats').hidden, tag()];
await g.drop();
r.moves = window.__moves;
r.after = order();
JS);
pin('rows still sliding are read where they will be: the slot goes after Jobs', 'Jobs', $r['early'] ?? null);
pin('the ghost counts the rows under it and the block is hidden', array('+ 3 more', true, true, 'Top level'), $r['mid'] ?? null);
pin('commit gets the block move to the end', array(array('name' => 'Vehicles', 'from' => 0, 'at' => 8, 'depth' => 0, 'parent' => null, 'index' => 2, 'via' => 'drag')), $r['moves'] ?? null);
pin('the block lands after Jobs with its rows', array('Real estate:0', 'Houses:1h', 'Flats:1h', 'Jobs:0', 'Vehicles:0', 'Cars:1', 'Car Parts:2', 'Boats:1'), $r['after'] ?? null);

echo "Esc puts everything back\n";
$r = tree_ui_run(<<<'JS'
var g = t.drag(handle('Vehicles'));
await g.by(30, 90, 6);
r.during = [row('Cars').hidden, !!slot()];
await g.escape();
r.afterEsc = [!!slot(), document.documentElement.classList.contains('is-tree-dragging')];
await g.drop();
r.order = order();
r.moves = window.__moves.length;
r.cancelled = window.__cancelled;
await t.settle(500);
r.ghost = !!t.$('.tree-ghost');
JS);
pin('the block hides while dragging', array(true, true), $r['during'] ?? null);
pin('Esc removes the slot and ends the drag', array(false, false), $r['afterEsc'] ?? null);
pin('order and hidden rows are as before', $start, $r['order'] ?? null);
pin('nothing is committed', 0, $r['moves'] ?? null);
pin('the screen hears escape', array('escape'), $r['cancelled'] ?? null);
pin('the ghost goes', false, $r['ghost'] ?? null);

echo "A collapsed group opens after 600 ms\n";
$r = tree_ui_run(<<<'JS'
var re = row('Real estate').getBoundingClientRect();
var g = t.drag(handle('Jobs'));
await g.to(re.left + 80, re.top + re.height * 0.6, 6);
await g.hold(300);
r.early = [row('Real estate').classList.contains('is-will-open'), window.__opened.slice(), row('Houses').hidden];
await g.hold(500);
r.late = [row('Real estate').classList.contains('is-will-open'), window.__opened.slice(), row('Houses').hidden];
await g.escape();
await g.drop();
r.moves = window.__moves.length;
JS);
pin('before 600 ms the group is marked, not opened', array(true, array(), true), $r['early'] ?? null);
pin('after 600 ms open() ran and its rows show', array(false, array('Real estate'), false), $r['late'] ?? null);
pin('no move after Esc', 0, $r['moves'] ?? null);

echo "Too deep is refused\n";
$r = tree_ui_run(<<<'JS'
var g = t.drag(handle('Boats'));
await g.by(28, 0, 4);
r.ok = [tag(), slot().classList.contains('is-refused')];
await g.by(48, 0, 4);
r.deep = [tag(), slot().classList.contains('is-refused'), !!slot().querySelector('.bi-exclamation-triangle'), tinted()];
await g.drop();
r.moves = window.__moves.length;
r.cancelled = window.__cancelled;
r.order = order();
JS, array('limit' => 3));
pin('level 3 of 3 is allowed', array('Inside Cars', false), $r['ok'] ?? null);
pin('level 4 of 3 says Too deep, with no tint', array('Too deep', true, true, array()), $r['deep'] ?? null);
pin('a refused drop commits nothing', 0, $r['moves'] ?? null);
pin('the screen hears deep', array('deep'), $r['cancelled'] ?? null);
pin('order unchanged', $start, $r['order'] ?? null);

echo "Keyboard\n";
$r = tree_ui_run(<<<'JS'
r.up = await t.key(handle('Boats'), 'up');
r.focus = document.activeElement === handle('Boats');
var placed = t.$('.tree-place-tag');
r.placed = [placed ? placed.textContent : null, row('Boats').classList.contains('is-tree-placed'), tinted()];
var hint = t.$('.tree-key-hint');
r.hint = hint && !hint.hidden ? hint.querySelectorAll('div').length + ':' + hint.querySelectorAll('kbd')[2].textContent : null;
await t.key(handle('Cars'), 'right');
await t.key(handle('Cars'), 'left');
await t.key(handle('Cars'), 'down');
await t.key(handle('Vehicles'), 'up');
await t.key(handle('Jobs'), 'up');
window.__filtered = true;
await t.key(handle('Jobs'), 'down');
window.__filtered = false;
r.moves = window.__moves;
r.cancelled = window.__cancelled;
r.order = order();
await t.settle(1800);
r.placedGone = !t.$('.tree-place-tag') && !t.$('.is-tree-placed');
JS);
pin('a key move is taken', true, $r['up'] ?? null);
pin('focus stays on the moved handle', true, $r['focus'] ?? null);
pin('the moved row shows where it went', array('Inside Vehicles', true, array('Vehicles')), $r['placed'] ?? null);
pin('the key hint shows three lines, → for in', '3:→', $r['hint'] ?? null);
pin('↑ → ← ↑ give the moves', array(
    array('name' => 'Boats', 'from' => 3, 'at' => 1, 'depth' => 1, 'parent' => 'Vehicles', 'index' => 0, 'via' => 'key'),
    array('name' => 'Cars', 'from' => 2, 'at' => 2, 'depth' => 2, 'parent' => 'Boats', 'index' => 0, 'via' => 'key'),
    array('name' => 'Cars', 'from' => 2, 'at' => 4, 'depth' => 1, 'parent' => 'Vehicles', 'index' => 1, 'via' => 'key'),
    array('name' => 'Jobs', 'from' => 7, 'at' => 4, 'depth' => 0, 'parent' => null, 'index' => 1, 'via' => 'key'),
), $r['moves'] ?? null);
pin('refusals and the filter reach the screen', array('refused-key:last', 'refused-key:first', 'filtered'), $r['cancelled'] ?? null);
pin('final order', array('Vehicles:0', 'Boats:1', 'Cars:1', 'Car Parts:2', 'Jobs:0', 'Real estate:0', 'Houses:1h', 'Flats:1h'), $r['order'] ?? null);
pin('the place tag goes after 1.6 s', true, $r['placedGone'] ?? null);

echo "One key move at a time\n";
$r = tree_ui_run(<<<'JS'
window.__hold = true;
await t.key(handle('Real estate'), 'down');
await t.key(handle('Real estate'), 'down');
var g = t.drag(handle('Jobs'));
await g.by(0, -60, 4);
r.held = [window.__moves.length, !!slot()];
await g.drop();
window.__hold = false;
window.__release();
await t.settle(50);
await t.key(handle('Real estate'), 'up');
r.after = window.__moves.length;
JS);
pin('while a save is on its way, keys and drags wait', array(1, false), $r['held'] ?? null);
pin('once it settles the next key moves', 2, $r['after'] ?? null);

echo "Touch: a long press starts the drag\n";
$r = tree_ui_run(<<<'JS'
var g = t.drag(handle('Boats'), {type: 'touch'});
await g.hold(150);
r.early = !!slot();
await g.hold(150);
await g.by(28, 0, 4);
r.held = [!!slot(), tag()];
await g.drop();
r.moves = window.__moves.map(function (m) { return m.name + '>' + m.parent; });
var s = t.drag(handle('Jobs'), {type: 'touch'});
await s.by(0, -20, 2);
await s.hold(300);
r.scrolled = !!slot();
await s.drop();
r.after = window.__moves.length;
JS);
pin('no drag before 250 ms', false, $r['early'] ?? null);
pin('after the long press the drag runs', array(true, 'Inside Cars'), $r['held'] ?? null);
pin('and the drop moves the row', array('Boats>Cars'), $r['moves'] ?? null);
pin('a finger that moves first scrolls instead: no drag, no move', array(false, 1), array($r['scrolled'] ?? null, $r['after'] ?? null));

echo "Edge auto-scroll\n";
$r = tree_ui_run(<<<'JS'
document.body.style.paddingBlock = '1200px';
window.scrollTo({top: 1100, behavior: 'instant'});
await t.settle(50);
var g = t.drag(handle('Boats'));
await g.by(0, 8, 2);
var y0 = window.scrollY;
await g.to(g.pos().x, window.innerHeight - 10, 4);
await g.hold(400);
var y1 = window.scrollY;
await g.to(g.pos().x, 10, 6);
await g.hold(400);
var y2 = window.scrollY;
await g.escape();
r.down = y1 > y0 + 20;
r.up = y2 < y1 - 20;
JS);
pin('holding the pointer at the bottom edge scrolls down', true, $r['down'] ?? null);
pin('and at the top edge scrolls up', true, $r['up'] ?? null);

echo "Rows slide into place after a drop, unless motion is reduced\n";
$settle = <<<'JS'
var up = function (h) {
    var c = h.getBoundingClientRect();
    h.dispatchEvent(new PointerEvent('pointerup', {pointerId: 1, pointerType: 'mouse', isPrimary: true, bubbles: true, cancelable: true, clientX: c.left, clientY: c.top}));
};
var seen = new Set();
var mark = function () { t.$$('tr.tree-row').forEach(function (tr) { tr.getAnimations().forEach(function (a) { seen.add(a); }); }); };
var sliding = function () { return t.$$('tr.tree-row').filter(function (tr) { return tr.getAnimations().some(function (a) { return a.constructor === Animation && !seen.has(a); }); }).length; };
r.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
var g = t.drag(handle('Jobs'));
await g.to(g.pos().x, row('Vehicles').getBoundingClientRect().top + 4, 8);
await t.settle(300);
mark();
up(handle('Jobs'));
r.sliding = sliding();
await t.settle(400);
r.moved = window.__moves.length;
var e = t.drag(handle('Boats'));
await e.by(0, 40, 4);
await e.escape();
r.ghostLeft = !!t.$('.tree-ghost');
JS;
$r = tree_ui_run($settle);
check('after a drop the moved rows slide (' . var_export($r['sliding'] ?? null, true) . ')', ($r['sliding'] ?? 0) > 0);
pin('the drop is saved, and Esc lets the ghost fly home', array(false, 1, true), array($r['reduced'] ?? null, $r['moved'] ?? null, $r['ghostLeft'] ?? null));
$r = tree_ui_run($settle, array('reduced' => true));
pin('with reduced motion nothing slides and the ghost goes at once, but the move is saved', array(true, 0, 1, false), array($r['reduced'] ?? null, $r['sliding'] ?? null, $r['moved'] ?? null, $r['ghostLeft'] ?? null));

echo "Right to left\n";
$r = tree_ui_run(<<<'JS'
var g = t.drag(handle('Boats'));
await g.by(-28, 0, 4);
r.drag = [tag(), depth()];
await g.escape();
await g.drop();
await t.key(handle('Boats'), 'left');
await t.key(handle('Boats'), 'right');
r.moves = window.__moves;
var hint = t.$('.tree-key-hint');
r.hint = hint ? hint.querySelectorAll('kbd')[2].textContent : null;
JS, array('rtl' => true));
pin('dragging left nests in RTL', array('Inside Cars', '2'), $r['drag'] ?? null);
pin('← is in and → is out in RTL', array(
    array('name' => 'Boats', 'from' => 3, 'at' => 3, 'depth' => 2, 'parent' => 'Cars', 'index' => 1, 'via' => 'key'),
    array('name' => 'Boats', 'from' => 3, 'at' => 4, 'depth' => 1, 'parent' => 'Vehicles', 'index' => 1, 'via' => 'key'),
), $r['moves'] ?? null);
pin('the hint shows ← for in', '←', $r['hint'] ?? null);

echo "Selects keep their chevron clear of the text\n";
foreach (array('LTR' => array(), 'RTL' => array('rtl' => true)) as $dir => $o) {
    $r = tree_ui_run(<<<'JS'
var s = t.$('#bulk');
var cs = getComputedStyle(s);
var rtl = document.documentElement.dir === 'rtl';
var start = parseFloat(rtl ? cs.paddingRight : cs.paddingLeft);
var end = parseFloat(rtl ? cs.paddingLeft : cs.paddingRight);
r.room = end > start + 16;
JS, $o);
    pin('a bulk select has its room for the chevron at the inline end, ' . $dir, true, $r['room'] ?? null);
}

echo "The slot sits where a row's label would at each depth\n";
$align = <<<'JS'
var rtl = document.documentElement.dir === 'rtl';
var sign = rtl ? -1 : 1;
var inl = function (el) { var b = el.getBoundingClientRect(); return rtl ? b.right : b.left; };
var g = t.drag(handle('Boats'));
var x0 = g.pos().x;
var names = ['Vehicles', 'Cars', 'Car Parts'];
r.align = [];
for (var d = 0; d < 4; d++) {
    await g.to(x0 + sign * 24 * (d - 1), g.pos().y, 4);
    await t.settle(250);
    var box = slot().querySelector('.tree-slot-box');
    var cs = getComputedStyle(box);
    var text = inl(box) + sign * (parseFloat(cs.borderInlineStartWidth) + parseFloat(cs.paddingInlineStart));
    var ref = d < 3 ? inl(row(names[d]).querySelector('.tree-name')) : inl(row('Car Parts').querySelector('.tree-name')) + sign * 24;
    r.align.push([depth(), Math.abs(text - ref) <= 2]);
}
await g.escape();
JS;
foreach (array('LTR' => array(), 'RTL' => array('rtl' => true)) as $dir => $o) {
    $r = tree_ui_run($align, $o + array('reduced' => true));
    pin('the slot text starts at the label of a row at depth 0 to 3, ' . $dir, array(array('0', true), array('1', true), array('2', true), array('3', true)), $r['align'] ?? null);
}

echo "Toast\n";
$r = tree_ui_run(<<<'JS'
var n = 0;
osc.toast.labels({undo: 'Undo it', close: 'Close it'});
osc.toast('First', {undo: function () { n += 1; }});
var box = t.$('.osc-toast');
r.first = [!box.hidden, box.getAttribute('role'), box.getAttribute('aria-live'), t.$('.osc-toast-text').textContent, t.$('.osc-toast-undo').textContent, t.$('.osc-toast-close').getAttribute('aria-label'), !!t.$('.osc-toast-close .bi-x-lg')];
r.focus = document.activeElement === document.body;
osc.toast('Second', {undo: function () { n += 10; }});
r.one = t.$$('.osc-toast').length;
t.$('.osc-toast-undo').click();
t.$('.osc-toast-undo').click();
r.n = n;
await t.settle(400);
r.closed = box.hidden;
osc.toast('Third');
r.noUndo = [box.hidden, t.$('.osc-toast-undo').hidden, t.$('.osc-toast-text').textContent];
var h = osc.toast('Short', {ms: 300});
await t.wait(700);
r.timedOut = box.hidden;
osc.toast('Fourth');
h.close();
r.oldHandle = box.hidden;
JS);
$r2 = tree_ui_run(<<<'JS'
var opener = handle('Jobs');
opener.focus();
osc.toast('Moved', {undo: function () {}});
var box = t.$('.osc-toast');
r.focusStays = document.activeElement === opener;
t.$('.osc-toast-undo').focus();
r.insideNow = box.contains(document.activeElement);
box.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
await t.settle(400);
r.escClosed = box.hidden;
osc.toast('Again');
document.body.focus();
box.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
r.escOutside = box.hidden;
JS);
pin('Esc inside the toast closes it, focus stays put until then', array(true, true, true), array($r2['focusStays'] ?? null, $r2['insideNow'] ?? null, $r2['escClosed'] ?? null));
$r3 = tree_ui_run(<<<'JS'
osc.toast('A fairly long note that has to wrap on a phone screen without leaving it', {undo: function () {}});
await t.settle(400);
var b = t.$('.osc-toast').getBoundingClientRect();
var root = document.documentElement;
r.phone = [Math.round(b.left), Math.round(root.clientWidth - b.right), root.scrollWidth <= root.clientWidth];
JS, array('width' => 390));
pin('on a phone the toast is the screen minus a 16 px gutter each side, with no sideways scroll', array(16, 16, true), $r3['phone'] ?? null);
pin('a toast shows with its labels and is no live region', array(true, null, null, 'First', 'Undo it', 'Close it', true), $r['first'] ?? null);
pin('it does not take focus', true, $r['focus'] ?? null);
pin('one toast at a time', 1, $r['one'] ?? null);
pin('Undo runs the newest action once', 10, $r['n'] ?? null);
pin('Undo closes it', true, $r['closed'] ?? null);
pin('no undo, no Undo button', array(false, true, 'Third'), $r['noUndo'] ?? null);
pin('it closes after ms', true, $r['timedOut'] ?? null);
pin('an old handle cannot close a newer toast', false, $r['oldHandle'] ?? null);

echo "Plain osc.sortable is unchanged\n";
$r = tree_ui_run(<<<'JS'
var seen = [];
window.Sortable = function (el, o) { seen.push(JSON.parse(JSON.stringify(o))); };
var a = document.createElement('ul');
var b = document.createElement('ul');
document.body.append(a, b);
osc.sortable(a);
osc.sortable(a, {group: 'again'});
osc.sortable(b, {nested: true, group: 'menu-items'});
r.seen = seen;
var tb = t.$('#tree');
var ctl = osc.sortable(tb, {tree: {}});
r.tree = [ctl === window.__ctl, typeof ctl.flip, typeof ctl.cancel, typeof ctl.refresh, typeof ctl.destroy, ctl.dragging, seen.length];
JS);
$today = array(
    'delay' => 250, 'delayOnTouchOnly' => true, 'touchStartThreshold' => 5, 'animation' => 150,
    'ghostClass' => 'is-drag-ghost', 'chosenClass' => 'is-drag-chosen', 'dragClass' => 'is-drag-active',
    'scroll' => true, 'scrollSensitivity' => 60, 'filter' => 'input, select, textarea, [contenteditable]',
    'preventOnFilter' => false, 'forceFallback' => false,
);
$seen = $r['seen'] ?? array();
pin('one SortableJS per element', 2, count($seen));
pin('the flat options are today\'s', $today, $seen[0] ?? null);
pin('the nested options are today\'s', array('fallbackOnBody' => true, 'swapThreshold' => 0.12) + $today + array('group' => 'menu-items'), $seen[1] ?? null);
pin('tree mode returns one controller and never calls SortableJS', array(true, 'function', 'function', 'function', 'function', false, 2), $r['tree'] ?? null);

exit(harness_result());
