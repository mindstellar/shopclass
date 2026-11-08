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
 * Undo on the Categories screen in headless Chrome: after a drag, a key move, Move to…, enable,
 * disable and Set expiry, against a fake fetch.
 *
 * Usage: php tests/categories-undo-ui.php
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
function undo_ui_run(string $body, array $tree, array $opts = array()): array
{
    $file = sys_get_temp_dir() . '/cat-undo-ui-' . getmypid() . '-' . substr(md5($body), 0, 8) . '.js';
    $js = "window.catDriver = async function (t) {\n"
        . "var name = function (tr) { return tr.querySelector('.cat-name').textContent; };\n"
        . "var r = {};\n"
        . "var jm = document.createElement('div'); jm.id = 'jsMessage'; document.body.append(jm);\n"
        . "window.__flash = [];\nwindow.setJsMessage = function (type, text) { window.__flash.push([type, text]); };\n"
        . "window.__answers.category_bulk = function (call) { var ids = (call.body.ids || []).map(Number); return {ok: true, done: ids, failed: {}, queued: [], message: ids.length + ' done.'}; };\n"
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
$i = static fn (string $name): string => (string) $ids[$name];

$common = <<<'JS'
var handle = function (n) { return t.row(n).querySelector('.cat-handle'); };
var order = function (n) { return t.$$('tr.cat-row').filter(function (tr) { return tr.dataset.parent === t.row(n).dataset.id; }).map(name); };
var open = async function (n) { await t.click(t.row(n).querySelector('.cat-disclosure')); };
var rect = function (n) { return t.row(n).getBoundingClientRect(); };
var off = function (n) { return t.row(n).classList.contains('is-disabled'); };
var toast = function () { var b = t.$('.osc-toast'); return b && !b.hidden ? [b.querySelector('.osc-toast-text').textContent, !b.querySelector('.osc-toast-undo').hidden] : null; };
var undo = async function () { t.$('.osc-toast-undo').click(); await t.settle(500); };
var seq = function (from) {
    return window.__calls.filter(function (c) { return c.action !== 'category_edit_iframe'; }).slice(from || 0).map(function (c) {
        var b = c.body;
        return [c.action].concat(['do', 'id', 'enabled', 'parent_id', 'position', 'days', 'expiry_listings'].filter(function (k) { return b[k] !== undefined; }).map(function (k) { return k + '=' + b[k]; }))
            .concat(b.ids ? ['ids=' + b.ids.join(',')] : []).join(' ');
    });
};
JS;

harness_section('Undo a drag, a key move and a failed Undo');
$r = undo_ui_run($common . <<<'JS'

await open('Vehicles');
var list0 = order('Vehicles');
var tot0 = t.row('Vehicles').dataset.listings;
var cp = rect('Car Parts');
var g = t.drag(handle('Cars'));
await g.to(g.pos().x, cp.bottom - 4, 8);
await g.by(40, 0, 4);
await g.drop();
await t.settle(400);
r.moved = [t.row('Cars').dataset.parent === t.row('Car Parts').dataset.id, toast()];
var n = seq().length;
await undo();
r.seq = seq(n);
r.order = order('Vehicles').join('|') === list0.join('|');
r.depth = t.row('Cars').dataset.depth;
r.totals = [t.row('Vehicles').dataset.listings === tot0, t.row('Car Parts').dataset.listings];
r.after = toast();

await t.key(handle('Car Parts'), 'down');
var keyed = order('Vehicles').join('|');
n = seq().length;
await undo();
r.key = [seq(n), order('Vehicles').join('|') === list0.join('|'), keyed !== list0.join('|')];

window.__flash.length = 0;
await t.key(handle('Car Parts'), 'down');
window.__answers.category_move = {error: 'Nope'};
await undo();
r.fail = [order('Vehicles').join('|') === keyed, window.__flash.slice()];
JS, $demo);
pin('the drag shows a toast with Undo', array(true, array('Moved Cars to Car Parts, 3 of 3.', true)), $r['moved'] ?? null);
pin('Undo posts the reverse move to the old parent and place', array('category_move id=' . $i('Cars') . ' parent_id=' . $i('Vehicles') . ' position=0'), $r['seq'] ?? null);
pin('the order is back', true, $r['order'] ?? null);
pin('Cars is back at its depth', '2', $r['depth'] ?? null);
pin('listing totals are back; Car Parts has its own again', array(true, '4'), $r['totals'] ?? null);
pin('the toast then says Undone. with no Undo', array('Undone.', false), $r['after'] ?? null);
pin('a key move undoes the same way', array(array('category_move id=' . $i('Car Parts') . ' parent_id=' . $i('Vehicles') . ' position=1'), true, true), $r['key'] ?? null);
pin('a refused Undo leaves the rows as they are and flashes the error', array(true, array(array('error', 'Nope'))), $r['fail'] ?? null);

harness_section('Undo disable and enable');
$r = undo_ui_run($common . <<<'JS'

await t.click(t.act('Boats - Ships', 'toggle'));
await open('Vehicles');
await t.click(t.act('Boats - Ships', 'toggle'));
if (t.$('#catConfirm').open) { await ok(); }
r.boatsOff = off('Boats - Ships');
await t.click(t.act('Vehicles', 'toggle'));
await ok();
r.all = [off('Vehicles'), off('Cars'), toast()];
var n = seq().length;
await undo();
r.seq = seq(n);
r.states = [off('Vehicles'), off('Cars'), off('Boats - Ships'), off('Winter tyres')];
r.tab = t.tab('Disabled').querySelector('.osc-count').textContent;

await t.click(t.act('Jobs', 'toggle'));
await ok();
await t.click(t.act('Jobs', 'toggle'));
r.enabled = [off('Jobs'), toast()];
n = seq().length;
await undo();
r.seq2 = seq(n);
r.jobs = [off('Jobs'), off('Healthcare')];

await t.click(t.act('Other Vehicles', 'toggle'));
r.leaf = [off('Other Vehicles'), toast()];
await t.click(t.act('Classes', 'toggle'));
await ok();
r.replaced = toast();
n = seq().length;
await undo();
r.seq3 = seq(n);
r.leafStill = off('Other Vehicles');
JS, $demo);
pin('Boats was off first', true, $r['boatsOff'] ?? null);
pin('Disable shows a toast with Undo', array(true, true, array('Disabled Vehicles and 10 subcategories.', true)), $r['all'] ?? null);
pin('Undo enables Vehicles, then disables only the rows that were off', array('enable_category id=' . $i('Vehicles') . ' enabled=1', 'category_bulk do=disable ids=' . $i('Boats - Ships')), $r['seq'] ?? null);
pin('Vehicles and its subcategories are on again; Boats stays off', array(false, false, true, false), $r['states'] ?? null);
pin('the Disabled tab counts the rows still off', '9', $r['tab'] ?? null);
pin('Enable shows Undo', array(false, array('Enabled Jobs and 21 subcategories.', true)), $r['enabled'] ?? null);
pin('Undo of enable disables it again, one request', array('enable_category id=' . $i('Jobs') . ' enabled=0'), $r['seq2'] ?? null);
pin('and the rows below are off with it', array(true, true), $r['jobs'] ?? null);
pin('a leaf with no listings disables at once and offers Undo', array(true, array('Disabled Other Vehicles.', true)), $r['leaf'] ?? null);
pin('a second action replaces the toast', array('Disabled Classes and 5 subcategories.', true), $r['replaced'] ?? null);
pin('and Undo then undoes only the second', array('enable_category id=' . $i('Classes') . ' enabled=1'), $r['seq3'] ?? null);
pin('the first change stays', true, $r['leafStill'] ?? null);

harness_section('Undo Move to… and Set expiry');
$r = undo_ui_run($common . <<<'JS'

await open('Vehicles');
var veh = t.row('Vehicles');
var re = t.row('Real estate');
var list0 = order('Vehicles').join('|');
var tot0 = [veh.dataset.listings, re.dataset.listings];
await t.click(t.act('Cars', 'move'));
var sel = t.$('#cat-move-parent');
sel.value = re.dataset.id;
sel.dispatchEvent(new Event('change', {bubbles: true}));
t.$('#catMoveForm').requestSubmit();
await t.settle(500);
r.toast = toast();
r.placed = [!!t.$('.tree-place-tag'), t.row('Cars').classList.contains('is-tree-flash')];
var n = seq().length;
await undo();
r.seq = seq(n);
r.back = [order('Vehicles').join('|') === list0, veh.dataset.listings === tot0[0], re.dataset.listings === tot0[1]];

await t.tick(t.row('Vehicles'));
await bulk('expiry');
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
t.$('#catExpiryForm').requestSubmit();
await t.settle(500);
r.exp = [t.row('Vehicles').querySelector('.col-expiry').textContent, toast()];
n = seq().length;
await undo();
r.seq2 = seq(n);
r.expBack = t.row('Vehicles').querySelector('.col-expiry').textContent;

await t.tick(t.row('Vehicles'));
await bulk('expiry');
t.$('#cat-bx-sub').click();
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
t.$('#catExpiryForm').requestSubmit();
await t.settle(500);
n = seq().length;
await undo();
r.seq3 = seq(n);

await t.tick(t.row('Vehicles'));
await bulk('expiry');
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
t.$('#cat-bx-posted input[value="new"]').click();
t.$('#catExpiryForm').requestSubmit();
await t.settle(500);
r.fresh = toast();
JS, $demo);
pin('Move to… shows a toast with Undo', array('Moved Cars to Real estate, 10 of 10.', true), $r['toast'] ?? null);
pin('the moved row gets the place tag and a flash', array(true, true), $r['placed'] ?? null);
pin('Undo moves it back to its old parent and place', array('category_move id=' . $i('Cars') . ' parent_id=' . $i('Vehicles') . ' position=0'), $r['seq'] ?? null);
pin('the order and both totals are back', array(true, true, true), $r['back'] ?? null);
pin('Set expiry with the end dates kept offers Undo', array('After 30 days', true), array(($r['exp'][0] ?? null), ($r['exp'][1][1] ?? null)));
pin('Undo posts the old value for each group, end dates kept', array('category_bulk do=expiry days=60 expiry_listings=keep ids=' . $i('Vehicles')), $r['seq2'] ?? null);
pin('the cell shows the old value again', 'After 60 days', $r['expBack'] ?? null);
pin('Undo with subcategories sends the parent apart from its children, one request per level', array('category_bulk do=expiry days=60 expiry_listings=keep ids=' . $i('Vehicles'), 6), array($r['seq3'][0] ?? null, count($r['seq3'] ?? array())));
pin('new end dates for posted listings offer no Undo', false, $r['fresh'][1] ?? null);

harness_section('Undo a bulk disable and a bulk Move to…');
$r = undo_ui_run($common . <<<'JS'

await open('Vehicles');
r.list0 = order('Vehicles');
await t.tick(t.row('Boats - Ships'));
await t.tick(t.row('Other Vehicles'));
await bulk('disable');
if (t.$('#catConfirm').open) { await ok(); }
r.off = [off('Boats - Ships'), off('Other Vehicles'), toast() && toast()[1]];
var n = seq().length;
await undo();
r.seq = seq(n);
r.on = [off('Boats - Ships'), off('Other Vehicles')];

await t.tick(t.row('Car Parts'));
await t.tick(t.row('Motorcycles'));
await bulk('move');
var sel = t.$('#cat-move-parent');
sel.value = t.row('Real estate').dataset.id;
sel.dispatchEvent(new Event('change', {bubbles: true}));
t.$('#catMoveForm').requestSubmit();
await t.settle(500);
r.moved = [t.row('Car Parts').dataset.parent === t.row('Real estate').dataset.id, t.row('Motorcycles').dataset.parent === t.row('Real estate').dataset.id];
n = seq().length;
await undo();
r.mseq = seq(n);
r.list1 = order('Vehicles');
JS, $demo);
pin('the bulk disable turns both off and offers Undo', array(true, true, true), $r['off'] ?? null);
pin('Undo enables each one again', array('enable_category id=' . $i('Boats - Ships') . ' enabled=1', 'enable_category id=' . $i('Other Vehicles') . ' enabled=1'), $r['seq'] ?? null);
pin('both rows are on again', array(false, false), $r['on'] ?? null);
pin('the bulk move took both to Real estate', array(true, true), $r['moved'] ?? null);
pin('Undo moves them back lowest place first', array(
    'category_move id=' . $i('Car Parts') . ' parent_id=' . $i('Vehicles') . ' position=1',
    'category_move id=' . $i('Motorcycles') . ' parent_id=' . $i('Vehicles') . ' position=2',
), $r['mseq'] ?? null);
pin('Vehicles has its old order back', $r['list0'] ?? 'no list', $r['list1'] ?? null);

exit(harness_result());
