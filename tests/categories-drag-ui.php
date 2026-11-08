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
 * Categories drag and arrow-key moves in headless Chrome: the slot label, depth by pointer x, the
 * depth limit, Esc, hover-open, the saved move, rollback, RTL and the filter lock, against a fake
 * fetch.
 *
 * Usage: php tests/categories-drag-ui.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('osc_esc_html')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
}
// The real expiry control, so the Set expiry… dialog has its chips.
class_exists('CategoryForm');
require_once __DIR__ . '/lib/categories-browser.php';

/**
 * Runs one driver body (the inside of an async function taking t) and returns its reading.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts
 *
 * @return array<string,mixed>
 */
function drag_ui_run(string $body, array $tree, array $opts = array()): array
{
    $file = sys_get_temp_dir() . '/cat-drag-ui-' . getmypid() . '-' . substr(md5($body), 0, 8) . '.js';
    $js = "window.catDriver = async function (t) {\n"
        . "var name = function (tr) { return tr.querySelector('.cat-name').textContent; };\n"
        . "var r = {};\n"
        . "var jm = document.createElement('div'); jm.id = 'jsMessage'; document.body.append(jm);\n"
        . "window.__flash = [];\nwindow.setJsMessage = function (type, text) { window.__flash.push([type, text]); };\n"
        . "var calls = function (a) { return window.__calls.filter(function (c) { return c.action === a; }); };\n"
        . "var last = function (a) { return calls(a).pop() || {body: {}, query: {}}; };\n"
        . "var ok = async function () { t.\$('#catConfirmOk').click(); await t.settle(400); };\n"
        . "var bulk = async function (what) { t.\$('#cat-bulk').value = what; t.\$('#cat-bulk-form').requestSubmit(); await t.settle(400); };\n"
        . $body . "\nreturn r;\n};\n";
    file_put_contents($file, $js);
    $r = categories_browser_run($file, $tree, $opts);
    @unlink($file);
    pin('no script error', array(), $r['errors'] ?? null);
    pin('the screen is ready', true, $r['ready'] ?? null);

    return $r;
}

$demo  = categories_fixture_demo();
$index = categories_browser_index($demo);
$ids   = array();
foreach ($index as $id => $row) {
    $ids[(string) $row['s_name']] = $id;
}

$common = <<<'JS'
var handle = function (n) { return t.row(n).querySelector('.cat-handle'); };
var slot = function () { return t.$('.tree-slot'); };
var above = function () { var a = t.$('.is-tree-gap-after'); if (a) { return a; } var p = t.$('.is-tree-gap-before'); p = p && p.previousElementSibling; while (p && p.hidden) { p = p.previousElementSibling; } return p; };
var tag = function () { return slot() ? slot().querySelector('.tree-slot-tag').textContent : null; };
var tinted = function () { return t.$$('.is-drop-parent').map(name); };
var order = function (n) { return t.$$('tr.cat-row').filter(function (tr) { return !n || tr.dataset.parent === t.row(n).dataset.id; }).map(name); };
var moves = function () { return calls('category_move').map(function (c) { return [c.body.id, c.body.parent_id, c.body.position]; }); };
var rect = function (n) { return t.row(n).getBoundingClientRect(); };
var open = async function (n) { await t.click(t.row(n).querySelector('.cat-disclosure')); };
JS;

harness_section('Drag in and out');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
var cp = rect('Car Parts');
var g = t.drag(handle('Cars'));
await g.to(g.pos().x, cp.bottom - 4, 8);
r.sibling = [tag(), tinted()];
await g.by(40, 0, 4);
r.inside = [tag(), tinted(), t.$('.tree-ghost-name').textContent];
await t.settle(300);
var nm = document.createRange(); nm.selectNodeContents(t.row('Car Parts').querySelector('.cat-name'));
r.box = Math.round(slot().querySelector('.tree-slot-box').getBoundingClientRect().left - nm.getClientRects()[0].left);
await g.drop();
await t.settle(400);
r.cars = order('Car Parts');
r.carsRow = [t.row('Cars').dataset.depth, t.row('Cars').dataset.parent === t.row('Car Parts').dataset.id];
r.post = moves();
r.listings = [t.row('Vehicles').dataset.listings, t.row('Car Parts').dataset.listings];
r.toast = t.$('.osc-toast') ? t.$('.osc-toast-text').textContent : null;
r.undo = t.$('.osc-toast') ? !t.$('.osc-toast-undo').hidden : null;
r.announce = t.$('#cat-announce').textContent;
JS, $demo, array('still' => true));
pin('over Car Parts without moving sideways it stays a sibling', array('Inside Vehicles', array('Vehicles')), $r['sibling'] ?? null);
pin('one step right: Inside Car Parts, Car Parts tinted, ghost names Cars', array('Inside Car Parts', array('Car Parts'), 'Cars'), $r['inside'] ?? null);
check('the slot box starts one indent step in from the Car Parts name, where a child name would (24-25 px, ' . var_export($r['box'] ?? null, true) . ')', in_array($r['box'] ?? null, array(24, 25), true));
pin('Car Parts now holds Cars last', array('Engine - Gearbox', 'Tyres - Wheels', 'Cars'), $r['cars'] ?? null);
pin('Cars is one level deeper, under Car Parts', array('3', true), $r['carsRow'] ?? null);
pin('the save posts id, parent_id and position', array(array((string) $ids['Cars'], (string) $ids['Car Parts'], '2')), $r['post'] ?? null);
pin('Vehicles keeps its total; Car Parts gains the listings', array('33', '23'), $r['listings'] ?? null);
pin('the toast says where it went', 'Moved Cars to Car Parts, 3 of 3.', $r['toast'] ?? null);
pin('and is announced', 'Moved Cars to Car Parts, 3 of 3.', $r['announce'] ?? null);
pin('the toast offers Undo', true, $r['undo'] ?? null);

harness_section('A block moves together');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
await open('Car Parts');
await open('Tyres - Wheels');
var cars = rect('Cars');
var g = t.drag(handle('Car Parts'));
await g.to(g.pos().x, cars.bottom - 4, 8);
r.ghost = t.$('.tree-ghost-more') ? t.$('.tree-ghost-more').textContent : null;
r.hidden = ['Engine - Gearbox', 'Tyres - Wheels', 'Winter tyres'].map(function (n) { return t.row(n).hidden; });
r.over = [tag(), above() && name(above())];
await g.by(40, 0, 4);
r.into = tag();
await g.drop();
await t.settle(400);
r.order = ['Cars', 'Car Parts', 'Engine - Gearbox', 'Tyres - Wheels', 'Winter tyres'].map(function (n) { return n + ':' + t.row(n).dataset.depth; });
r.post = moves();
JS, $demo);
pin('the ghost counts the rows under it', '+ 3 subcategories', $r['ghost'] ?? null);
pin('the whole block is hidden while it moves', array(true, true, true), $r['hidden'] ?? null);
pin('Car Parts moves under Cars with its three subcategories, one level deeper', array('Cars:2', 'Car Parts:3', 'Engine - Gearbox:4', 'Tyres - Wheels:4', 'Winter tyres:5'), $r['order'] ?? null);
pin('one move is saved, as the first child of Cars', array(array((string) $ids['Car Parts'], (string) $ids['Cars'], '0')), $r['post'] ?? null);

harness_section('Out to the top level');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
var cl = rect('Classes');
var g = t.drag(handle('Cars'));
await g.to(g.pos().x, cl.top + 4, 8);
r.mid = tag();
await g.by(-80, 0, 4);
r.out = [tag(), tinted()];
await g.drop();
await t.settle(400);
r.row = [t.row('Cars').dataset.depth, t.row('Cars').dataset.parent];
r.next = name(t.row('Cars').nextElementSibling);
r.post = moves();
JS, $demo);
pin('over the row after Vehicles, a sibling of Other Vehicles', 'Inside Vehicles', $r['mid'] ?? null);
pin('dragged left: Top level, nothing tinted', array('Top level', array()), $r['out'] ?? null);
pin('Cars is a top-level row before Classes', array(array('1', '0'), 'Classes'), array($r['row'] ?? null, $r['next'] ?? null));
pin('the save posts parent_id 0 and the top-level place', array(array((string) $ids['Cars'], '0', '2')), $r['post'] ?? null);

harness_section('Depth limit');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
await open('Car Parts');
await open('Tyres - Wheels');
var wt = rect('Winter tyres');
var g = t.drag(handle('Cars'));
await g.to(g.pos().x, wt.bottom - 4, 8);
await g.by(140, 0, 6);
r.refused = [tag(), slot().classList.contains('is-refused'), tinted()];
await g.drop();
await t.settle(400);
r.stay = order('Vehicles').slice(0, 2);
r.post = moves();
r.slot = !!slot();
r.said = t.$('#cat-announce').textContent;
JS, $demo, array('limit' => 4));
pin('a fourth level is refused with the theme limit', array('Too deep. This theme shows 4 levels of categories.', true, array()), $r['refused'] ?? null);
pin('nothing moves', array('Cars', 'Car Parts'), $r['stay'] ?? null);
pin('nothing is posted', array(), $r['post'] ?? null);
pin('the slot is gone', false, $r['slot'] ?? null);
pin('it says why', 'Not moved. Too deep. This theme shows 4 levels of categories.', $r['said'] ?? null);

harness_section('Esc and hover-open');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
var before = t.$$('tr.cat-row').map(function (tr) { return name(tr) + (tr.hidden ? 'h' : ''); });
var cl = rect('Classes');
var g = t.drag(handle('Car Parts'));
await g.to(g.pos().x, cl.top + 4, 8);
r.during = [!!slot(), t.row('Car Parts').hidden];
await g.escape();
await t.settle(400);
r.after = t.$$('tr.cat-row').map(function (tr) { return name(tr) + (tr.hidden ? 'h' : ''); });
r.same = JSON.stringify(before) === JSON.stringify(r.after);
r.clean = [!!slot(), !!t.$('.tree-ghost'), document.documentElement.classList.contains('is-tree-dragging')];
r.nCalls = moves().length;
r.listings = t.row('Vehicles').dataset.listings;
r.said = t.$('#cat-announce').textContent;

var re = rect('Real estate');
var g2 = t.drag(handle('Cars'));
await g2.to(g2.pos().x, re.top + re.height / 2, 8);
r.closed = t.row('Houses - Apartments for Sale').hidden;
r.will = t.row('Real estate').classList.contains('is-will-open');
await g2.hold(700);
r.opened = !t.row('Houses - Apartments for Sale').hidden;
r.expanded = t.row('Real estate').querySelector('.cat-disclosure').getAttribute('aria-expanded');
await g2.escape();
JS, $demo);
pin('mid-drag the slot shows and the row is hidden', array(true, true), $r['during'] ?? null);
pin('Esc restores every row, hidden or not', true, $r['same'] ?? null);
pin('and clears slot, ghost and drag class', array(false, false, false), $r['clean'] ?? null);
pin('no request, totals unchanged', array(0, '33'), array($r['nCalls'] ?? null, $r['listings'] ?? null));
pin('Esc is announced', 'Move cancelled.', $r['said'] ?? null);
pin('over a collapsed group it waits at first', array(true, true), array($r['closed'] ?? null, $r['will'] ?? null));
pin('after 600 ms the group opens', array(true, 'true'), array($r['opened'] ?? null, $r['expanded'] ?? null));

harness_section('Arrow keys');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
await t.key(handle('Car Parts'), 'down');
r.down = [order('Vehicles').slice(0, 3), moves().pop()];
r.downSaid = t.$('#cat-announce').textContent;
r.focus = document.activeElement === handle('Car Parts');
r.tagShown = !!t.$('.tree-place-tag');
await t.key(handle('Car Parts'), 'up');
r.up = [order('Vehicles').slice(0, 3), moves().pop()];
await t.key(handle('Car Parts'), 'right');
r.right = [t.row('Car Parts').dataset.parent === t.row('Cars').dataset.id, t.row('Car Parts').dataset.depth, moves().pop()];
r.rightSaid = t.$('#cat-announce').textContent;
await open('Cars');
await t.key(handle('Car Parts'), 'left');
r.left = [t.row('Car Parts').dataset.parent === t.row('Vehicles').dataset.id, t.row('Car Parts').dataset.depth, moves().pop()];
r.leftSaid = t.$('#cat-announce').textContent;
var n = calls('category_move').length;
await t.key(handle('Cars'), 'up');
r.first = [calls('category_move').length === n, t.$('#cat-announce').textContent];
await t.key(handle('Vehicles'), 'left');
r.top = [calls('category_move').length === n, t.$('#cat-announce').textContent];
await t.key(handle('For sale'), 'right');
r.nothing = [calls('category_move').length === n, t.$('#cat-announce').textContent];
r.hint = !!t.$('.tree-key-hint');
JS, $demo);
pin('down swaps Car Parts with Motorcycles', array(array('Cars', 'Motorcycles', 'Car Parts'), array((string) $ids['Car Parts'], (string) $ids['Vehicles'], '2')), $r['down'] ?? null);
pin('and says where it is now', 'Car Parts moved down. Now 3 of 7 in Vehicles.', $r['downSaid'] ?? null);
pin('focus stays on the moved handle', true, $r['focus'] ?? null);
pin('a place tag shows on the moved row', true, $r['tagShown'] ?? null);
pin('up gives the same post as a drag', array(array('Cars', 'Car Parts', 'Motorcycles'), array((string) $ids['Car Parts'], (string) $ids['Vehicles'], '1')), $r['up'] ?? null);
pin('right puts it last under Cars', array(true, '3', array((string) $ids['Car Parts'], (string) $ids['Cars'], '0')), $r['right'] ?? null);
pin('and says so', 'Car Parts is now under Cars, last of 1.', $r['rightSaid'] ?? null);
pin('left moves it out, after Cars', array(true, '2', array((string) $ids['Car Parts'], (string) $ids['Vehicles'], '1')), $r['left'] ?? null);
pin('and says so', 'Car Parts moved out of Cars. Now in Vehicles, after Cars.', $r['leftSaid'] ?? null);
pin('first row up: nothing posted, it says why', array(true, 'Cars is already first in Vehicles.'), $r['first'] ?? null);
pin('top row left: nothing posted, it says why', array(true, 'Vehicles is already at the top level.'), $r['top'] ?? null);
pin('first row right: nothing above it', array(true, 'Nothing above For sale to put it under.'), $r['nothing'] ?? null);

harness_section('Right to left');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
await t.key(handle('Car Parts'), 'left');
r.left = [t.row('Car Parts').dataset.parent === t.row('Cars').dataset.id, moves().length];
await t.key(handle('Car Parts'), 'right');
r.right = [t.row('Car Parts').dataset.parent === t.row('Vehicles').dataset.id, moves().length];
var cp = rect('Car Parts');
var g = t.drag(handle('Motorcycles'));
await g.to(g.pos().x, cp.bottom - 4, 8);
await g.by(-40, 0, 4);
r.drag = tag();
await g.escape();
JS, $demo, array('rtl' => true));
pin('in RTL the left key puts it under the row above', array(true, 1), $r['left'] ?? null);
pin('and the right key moves it out', array(true, 2), $r['right'] ?? null);
pin('dragging toward the start side nests', 'Inside Car Parts', $r['drag'] ?? null);

harness_section('Filter locks reordering');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
await t.type('#cat-q', 'car');
var g = t.drag(handle('Cars'));
await g.by(0, 60, 6);
r.slot = !!slot();
await g.drop();
await t.key(handle('Cars'), 'down');
r.said = t.$('#cat-announce').textContent;
r.posts = moves().length;
r.line = t.$('#cat-filter-line').textContent;
JS, $demo);
pin('a handle press does nothing while a search is on', false, $r['slot'] ?? null);
pin('keys say why', 'Clear the search and status filter to reorder.', $r['said'] ?? null);
pin('and post nothing', 0, $r['posts'] ?? null);

harness_section('A failed save rolls back');
$r = drag_ui_run($common . <<<'JS'

await open('Vehicles');
window.__answers.category_move = {error: 'Nope'};
var before = order('Vehicles').slice(0, 3);
var cl = rect('Classes');
var g = t.drag(handle('Cars'));
await g.to(g.pos().x, cl.top + 4, 8);
await g.by(-80, 0, 4);
await g.drop();
await t.settle(600);
r.after = order('Vehicles').slice(0, 3);
r.before = before;
r.depth = t.row('Cars').dataset.depth;
r.listings = t.row('Vehicles').dataset.listings;
r.flash = window.__flash.slice();
r.toast = !!t.$('.osc-toast') && !t.$('.osc-toast').hidden;
JS, $demo);
pin('the rows are back where they were', true, ($r['after'] ?? 1) === ($r['before'] ?? 2));
pin('at their old depth', '2', $r['depth'] ?? null);
pin('with the old listing totals', '33', $r['listings'] ?? null);
pin('the error is flashed', array(array('error', 'Nope')), $r['flash'] ?? null);
pin('and no success toast shows', false, $r['toast'] ?? null);

exit(harness_result());
