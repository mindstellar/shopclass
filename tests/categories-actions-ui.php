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
 * Categories screen row actions in headless Chrome: Disable, Delete, the bulk bar, Move to…,
 * and Set expiry…, against a fake fetch. Drag is in categories-drag-ui.php.
 *
 * Usage: php tests/categories-actions-ui.php
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
function actions_ui_run(string $body, array $tree, array $opts = array()): array
{
    $file = sys_get_temp_dir() . '/cat-actions-ui-' . getmypid() . '-' . substr(md5($body), 0, 8) . '.js';
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

harness_section('Disable');
$r = actions_ui_run(<<<'JS'
var veh = t.row('Vehicles');
r.offBefore = t.$$('tr.cat-row.is-disabled').length;
await t.click(t.act('Vehicles', 'toggle'));
r.open = t.$('#catConfirm').open;
r.title = t.$('#catConfirmTitle').textContent;
r.text = t.$('#catConfirmText').textContent;
r.vehListings = veh.dataset.listings;
r.callsBefore = calls('enable_category').length;
t.$('#catConfirm').close();
await t.settle();
r.cancelled = calls('enable_category').length;
await t.click(t.act('Vehicles', 'toggle'));
await ok();
var c = last('enable_category');
r.post = [c.method, c.body.id, c.body.enabled];
var below = t.$$('tr.cat-row').filter(function (tr) { return tr.dataset.depth !== '1' && ['Cars', 'Car Parts', 'Engine - Gearbox', 'Tyres - Wheels', 'Winter tyres', 'Motorcycles', 'Boats - Ships', 'RVs - Campers - Caravans', 'Trucks - Commercial Vehicles', 'Other Vehicles'].indexOf(name(tr)) !== -1; });
r.belowOff = below.filter(function (tr) { return tr.classList.contains('is-disabled'); }).length;
r.self = veh.classList.contains('is-disabled');
r.word = veh.querySelector('.col-status').textContent;
r.label = t.act('Vehicles', 'toggle').textContent;
r.badge = t.tab('Disabled').querySelector('.osc-count').textContent;
r.offAfter = t.$$('tr.cat-row.is-disabled').length;
await t.click(t.act('Vehicles', 'toggle'));
r.enableAtOnce = !t.$('#catConfirm').open && last('enable_category').body.enabled;
r.onAgain = !veh.classList.contains('is-disabled') && !t.row('Cars').classList.contains('is-disabled');
await t.click(t.act('Other Vehicles', 'toggle'));
r.leafAtOnce = !t.$('#catConfirm').open && last('enable_category').body.id === t.row('Other Vehicles').dataset.id && last('enable_category').body.enabled;
window.__answers.enable_category = {error: 'Parent category is disabled, you can not enable that category'};
await t.click(t.act('Jobs', 'toggle'));
await ok();
r.flash = window.__flash.slice();
r.jobsOff = t.row('Jobs').classList.contains('is-disabled');
JS, $demo);
pin('Disable on a category with subcategories asks first', true, $r['open'] ?? null);
pin('the question names the category', 'Disable Vehicles?', $r['title'] ?? null);
check('it names the subcategories and listings', str_contains((string) ($r['text'] ?? ''), '10 subcategories · ' . ($r['vehListings'] ?? '?') . ' listings.'), (string) ($r['text'] ?? ''));
pin('Cancel posts nothing', 0, $r['cancelled'] ?? null);
pin('Confirm posts enable_category with enabled=0', array('POST', (string) $ids['Vehicles'], '0'), $r['post'] ?? null);
pin('all 10 rows below grey out', 10, $r['belowOff'] ?? null);
pin('and the category itself', true, $r['self'] ?? null);
pin('its status reads Disabled', 'Disabled', $r['word'] ?? null);
pin('its action now reads Enable', 'Enable', $r['label'] ?? null);
pin('11 rows are off in all, more than before', ($r['offBefore'] ?? 0) + 11, $r['offAfter'] ?? null);
pin('Enable posts at once with enabled=1', '1', $r['enableAtOnce'] ?? null);
pin('and switches the subtree on again', true, $r['onAgain'] ?? null);
pin('a leaf with no listings disables without asking', '0', $r['leafAtOnce'] ?? null);
pin('a refused enable flashes the server message', array(array('error', 'Parent category is disabled, you can not enable that category')), $r['flash'] ?? null);
pin('and changes nothing', false, $r['jobsOff'] ?? null);

harness_section('Delete');
$r = actions_ui_run(<<<'JS'
window.__answers.category_delete_preview = {categories: 11, listings: 30, queued: false};
var foot0 = t.$('#cat-foot-sum').textContent;
var all0 = t.tab('All').querySelector('.osc-count').textContent;
var rows0 = t.$$('tr.cat-row').length;
await t.click(t.act('Vehicles', 'delete'));
r.open = t.$('#catConfirm').open;
r.title = t.$('#catConfirmTitle').textContent;
r.text = t.$('#catConfirmText').textContent;
r.okLabel = t.$('#catConfirmOk').textContent;
r.preview = last('category_delete_preview').query.ids;
r.deletedBefore = calls('delete_category').length;
await ok();
r.order = window.__calls.map(function (c) { return c.action; }).filter(function (a) { return a === 'category_delete_preview' || a === 'delete_category'; });
r.post = [last('delete_category').method, last('delete_category').body.id];
r.gone = [!t.row('Vehicles'), !t.row('Cars'), !t.row('Winter tyres')];
r.removed = rows0 - t.$$('tr.cat-row').length;
r.foot = [foot0, t.$('#cat-foot-sum').textContent];
r.all = [all0, t.tab('All').querySelector('.osc-count').textContent];
window.__answers.delete_category = {ok: true, queued: true};
window.__answers.category_delete_preview = {categories: 6, listings: 9000, queued: true};
await t.click(t.act('Classes', 'delete'));
r.queuedText = t.$('#catConfirmText').textContent;
await ok();
r.flash = window.__flash.slice();
window.__answers.delete_category = {error: 'An error occurred while deleting'};
await t.click(t.act('Jobs', 'delete'));
await ok();
r.stays = !!t.row('Jobs');
r.flash2 = window.__flash.length;
JS, $demo);
pin('Delete asks first', true, $r['open'] ?? null);
pin('the question names the category', 'Delete Vehicles?', $r['title'] ?? null);
check('it reports the real counts', str_contains((string) ($r['text'] ?? ''), '11 categories · 30 listings.'), (string) ($r['text'] ?? ''));
check('it says expired and unapproved listings go too', str_contains((string) ($r['text'] ?? ''), 'including expired and unapproved ones'), (string) ($r['text'] ?? ''));
pin('the button says Delete', 'Delete', $r['okLabel'] ?? null);
pin('the preview asks for this category only', array((string) $ids['Vehicles']), $r['preview'] ?? null);
pin('the preview comes first, the delete after', array('category_delete_preview', 'delete_category'), $r['order'] ?? null);
pin('the delete posts the id', array('POST', (string) $ids['Vehicles']), $r['post'] ?? null);
pin('Vehicles and everything below it are gone', array(true, true, true), $r['gone'] ?? null);
pin('11 rows left the table', 11, $r['removed'] ?? null);
check('the footer total dropped', ($r['foot'][0] ?? '') !== ($r['foot'][1] ?? ''), implode(' / ', $r['foot'] ?? array()));
pin('the All tab count dropped by 11', (string) ((int) ($r['all'][0] ?? 0) - 11), $r['all'][1] ?? null);
check('a big delete says it runs in the background', str_contains((string) ($r['queuedText'] ?? ''), 'Deleting in the background'), (string) ($r['queuedText'] ?? ''));
pin('and the flash says so after', array(array('ok', 'Deleting in the background. This can take a moment.')), $r['flash'] ?? null);
pin('a refused delete keeps the row', true, $r['stays'] ?? null);
pin('and flashes the error', 2, $r['flash2'] ?? null);

harness_section('Delete: cancel and a bad preview');
$r = actions_ui_run(<<<'JS'
window.__answers.category_delete_preview = {categories: 11, listings: 30, queued: false};
var rows0 = t.$$('tr.cat-row').length;
await t.click(t.act('Vehicles', 'delete'));
r.open = t.$('#catConfirm').open;
t.$('#catConfirm').close();
await t.settle(400);
await t.click(t.act('Jobs', 'delete'));
t.$('#catConfirm [data-osc-dialog-close]').click();
await t.settle(400);
r.closed = !t.$('#catConfirm').open;
await t.tick(t.row('Jobs'));
await t.tick(t.row('Vehicles'));
await bulk('delete');
r.bulkOpen = t.$('#catConfirm').open;
t.$('#catConfirm').close();
await t.settle(400);
r.posts = [calls('delete_category').length, calls('category_bulk').length];
r.rows = t.$$('tr.cat-row').length - rows0;
r.stay = [!!t.row('Vehicles'), !!t.row('Jobs')];
await t.click(t.$('[data-cat-act="clear-selection"]'));
window.__answers.category_delete_preview = {error: 'Nope'};
await t.click(t.act('Jobs', 'delete'));
r.errorOpen = t.$('#catConfirm').open;
r.errorFlash = window.__flash.slice(-1)[0];
window.__answers.category_delete_preview = {ok: true};
await t.click(t.act('Jobs', 'delete'));
r.emptyOpen = t.$('#catConfirm').open;
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
window.__answers.category_delete_preview = {categories: 1, listings: 19, queued: false};
await t.click(t.act('Cars', 'delete'));
r.leafText = t.$('#catConfirmText').textContent;
t.$('#catConfirm').close();
await t.settle(400);
r.posts2 = [calls('delete_category').length, calls('category_bulk').length];
JS, $demo);
pin('Delete asks first', true, $r['open'] ?? null);
pin('Cancel closes the question', true, $r['closed'] ?? null);
pin('bulk Delete asks first', true, $r['bulkOpen'] ?? null);
pin('closing or cancelling posts no delete, single or bulk', array(0, 0), $r['posts'] ?? null);
pin('every row stays', 0, $r['rows'] ?? null);
pin('Vehicles and Jobs are still there', array(true, true), $r['stay'] ?? null);
pin('a preview error opens no confirm', false, $r['errorOpen'] ?? null);
pin('and flashes the error', array('error', 'Nope'), $r['errorFlash'] ?? null);
pin('a preview with no counts opens no confirm', false, $r['emptyOpen'] ?? null);
check('a category with no subcategories does not mention them', !str_contains((string) ($r['leafText'] ?? ''), 'subcategories') && str_contains((string) ($r['leafText'] ?? ''), '1 category · 19 listings.'), (string) ($r['leafText'] ?? ''));
pin('still nothing was deleted', array(0, 0), $r['posts2'] ?? null);

harness_section('Bulk bar');
$r = actions_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.tick(t.row('Cars'));
await t.tick(t.row('Vehicles'));
r.sel = t.$('[data-cat-selcount]').textContent;
await bulk('disable');
r.open = t.$('#catConfirm').open;
r.title = t.$('#catConfirmTitle').textContent;
await ok();
var c = last('category_bulk');
r.post = [c.body.do, c.body.ids];
r.off = t.$$('tr.cat-row.is-disabled').length;
r.selAfter = t.$$('tr.cat-row.is-selected').length;
r.off0 = 8;
r.flash = [['ok', t.$('.osc-toast-text').textContent]];
window.__answers.category_bulk = function (call) {
    if (call.body.do === 'enable') {
        var m = {};
        m[call.body.ids[0]] = 'Parent category is disabled, you can not enable that category';
        return {ok: true, done: [], failed: m, queued: [], message: '0 categories enabled.'};
    }
    return {ok: true, done: call.body.ids.map(Number), failed: {}, queued: [], message: 'Done.'};
};
await t.tick(t.row('Cars'));
await bulk('enable');
r.enableFlash = window.__flash.slice(-1)[0];
r.enableOff = t.row('Cars').classList.contains('is-disabled');
await t.tick(t.row('Trucks - Commercial Vehicles'));
await t.tick(t.row('Other Vehicles'));
window.__answers.category_delete_preview = {categories: 2, listings: 1, queued: false};
await bulk('delete');
r.delTitle = t.$('#catConfirmTitle').textContent;
await ok();
r.delPost = [last('category_bulk').body.do, last('category_bulk').body.ids.length];
r.delGone = [!t.row('Trucks - Commercial Vehicles'), !t.row('Other Vehicles')];
JS, $demo);
pin('two ticked rows are selected', '2', $r['sel'] ?? null);
pin('bulk Disable asks first', true, $r['open'] ?? null);
pin('naming the one top-most category', 'Disable Vehicles?', $r['title'] ?? null);
pin('Cars is inside Vehicles, so only Vehicles is posted', array('disable', array((string) $ids['Vehicles'])), $r['post'] ?? null);
pin('Vehicles and its 10 below are off', 8 + 11, $r['off'] ?? null);
pin('the selection clears', 0, $r['selAfter'] ?? null);
pin('the toast is the server message', array(array('ok', '1 done.')), $r['flash'] ?? null);
pin('failed ids are reported in the flash', array('error', 'Parent category is disabled, you can not enable that category'), $r['enableFlash'] ?? null);
pin('and the row stays off', true, $r['enableOff'] ?? null);
pin('bulk delete names the count', 'Delete 2 categories?', $r['delTitle'] ?? null);
pin('and posts both ids', array('delete', 2), $r['delPost'] ?? null);
pin('and removes both rows', array(true, true), $r['delGone'] ?? null);

harness_section('Move to…');
$r = actions_ui_run(<<<'JS'
var veh = t.row('Vehicles');
var re = t.row('Real estate');
r.before = [veh.dataset.listings, re.dataset.listings, re.dataset.subs];
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.click(t.act('Cars', 'move'));
r.open = t.$('#catMove').open;
r.moveTitle = t.$('#catMoveTitle').textContent;
r.moveHint = t.$('#cat-move-hint').textContent;
var sel = t.$('#cat-move-parent');
r.parent = sel.value === veh.dataset.id;
r.selfOff = sel.querySelector('option[value="' + t.row('Cars').dataset.id + '"]').disabled;
r.pos0 = t.$('#cat-move-pos').selectedOptions[0].textContent;
r.posOpts = Array.from(t.$('#cat-move-pos').options).map(function (o) { return o.textContent; });
sel.value = re.dataset.id;
sel.dispatchEvent(new Event('change', {bubbles: true}));
r.posAfter = Array.from(t.$('#cat-move-pos').options).map(function (o) { return o.textContent; });
r.posDefault = t.$('#cat-move-pos').selectedOptions[0].textContent;
window.__answers.category_move = {error: 'A category cannot go inside itself.', field: '/parent_id'};
t.$('#catMoveForm').requestSubmit();
await t.settle(400);
r.stillOpen = t.$('#catMove').open;
r.hint = t.$('#cat-move-hint').textContent;
r.unmoved = t.row('Cars').dataset.parent === veh.dataset.id;
delete window.__answers.category_move;
t.$('#catMoveForm').requestSubmit();
await t.settle(400);
var c = last('category_move');
r.post = [c.method, c.body.id, c.body.parent_id, c.body.position];
r.closed = !t.$('#catMove').open;
var cars = t.row('Cars');
r.moved = [cars.dataset.parent === re.dataset.id, cars.dataset.depth, name(cars.previousElementSibling), !cars.hidden];
r.after = [veh.dataset.listings, re.dataset.listings, re.dataset.subs];
r.cells = [veh.querySelector('.col-listings').textContent, re.querySelector('.col-listings').textContent];
r.flash = [['ok', t.$('.osc-toast-text').textContent]];
JS, $demo);
pin('Move to… opens the dialog', true, $r['open'] ?? null);
pin('its title names the category', 'Move Cars', $r['moveTitle'] ?? null);
pin('the hint says where it is now', 'Now in Vehicles, 1 of 7.', $r['moveHint'] ?? null);
pin('the parent starts as the current one', true, $r['parent'] ?? null);
pin('the category cannot go inside itself', true, $r['selfOff'] ?? null);
pin('its position starts as First', 'First', $r['pos0'] ?? null);
pin('positions are First, After each sibling, Last', array('First', 'After Car Parts', 'After Motorcycles', 'After Boats - Ships', 'After RVs - Campers - Caravans', 'After Trucks - Commercial Vehicles', 'Last'), $r['posOpts'] ?? null);
pin('another parent lists its own children', 'Last', $r['posAfter'][count($r['posAfter'] ?? array()) - 1] ?? null);
pin('and defaults to Last', 'Last', $r['posDefault'] ?? null);
pin('a refused move keeps the dialog open', true, $r['stillOpen'] ?? null);
pin('and shows the server message', 'A category cannot go inside itself.', $r['hint'] ?? null);
pin('and moves nothing', true, $r['unmoved'] ?? null);
$re = $r['before'] ?? array(0, 0, 0);
pin('Last posts id, parent_id and position', array('POST', (string) $ids['Cars'], (string) $ids['Real estate'], '9'), $r['post'] ?? null);
pin('the dialog closes', true, $r['closed'] ?? null);
pin('Cars is last under Real estate and shown', array(true, '2', 'Shops for Rent - Sale', true), $r['moved'] ?? null);
pin('Vehicles loses 19 listings and Real estate gains 19', array((string) ((int) $re[0] - 19), (string) ((int) $re[1] + 19), '10'), $r['after'] ?? null);
pin('the cells show it', array((string) ((int) $re[0] - 19), (string) ((int) $re[1] + 19)), $r['cells'] ?? null);
pin('the toast says where it went', array(array('ok', 'Moved Cars to Real estate, 10 of 10.')), $r['flash'] ?? null);

harness_section('Set expiry…');
$r = actions_ui_run(<<<'JS'
await t.tick(t.row('Vehicles'));
await t.tick(t.row('Jobs'));
await bulk('expiry');
r.open = t.$('#catExpiry').open;
r.chips = t.$$('#catExpiry .cat-exp-chip').length;
r.postedHidden0 = t.$('#cat-bx-posted').hidden;
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
r.days = t.$('#cat-bx-days').value;
r.postedShown = !t.$('#cat-bx-posted').hidden;
t.$('#cat-bx-posted input[value="new"]').click();
r.subLabel = t.$('#cat-bx-sub-label').textContent;
t.$('#cat-bx-sub').click();
t.$('#catExpiryForm').requestSubmit();
await t.settle(400);
var c = last('category_bulk');
r.post = [c.body.do, c.body.ids, c.body.days, c.body.expiry_listings, c.body.to_subcategories];
r.closed = !t.$('#catExpiry').open;
r.cells = [t.row('Vehicles').querySelector('.col-expiry').textContent, t.row('Cars').querySelector('.col-expiry').textContent, t.row('Jobs').querySelector('.col-expiry').textContent, t.row('Healthcare').querySelector('.col-expiry').textContent];
r.sel = t.$$('tr.cat-row.is-selected').length;
await t.tick(t.row('Vehicles'));
await bulk('expiry');
r.postedBack = !t.$('#cat-bx-posted').hidden;
await t.click(t.$('[data-cat-act="clear-selection"]'));
t.$('#catExpiry').close();
await t.click(t.row('Services').querySelector('.cat-disclosure'));
await t.tick(t.row('Horoscopes - Tarot'));
await bulk('expiry');
r.noListingsPosted = t.$('#cat-bx-posted').hidden;
t.$('#catExpiry input[name="cat-bx-choice"][value="90"]').click();
await t.settle();
r.noListingsStill = t.$('#cat-bx-posted').hidden;
t.$('#catExpiry input[name="cat-bx-choice"][value="never"]').click();
t.$('#catExpiryForm').requestSubmit();
await t.settle(400);
c = last('category_bulk');
r.keep = [c.body.days, c.body.expiry_listings, c.body.to_subcategories === undefined];
JS, $demo);
pin('Set expiry… opens the dialog', true, $r['open'] ?? null);
pin('with the five chips', 5, $r['chips'] ?? null);
pin('Listings already posted shows, as the selection holds listings', false, $r['postedHidden0'] ?? null);
pin('the 30 chip sets 30 days', '30', $r['days'] ?? null);
pin('and stays shown after a chip', true, $r['postedShown'] ?? null);
pin('the subcategory box counts every level below', 'Also apply to its 31 subcategories', $r['subLabel'] ?? null);
pin('it posts do, ids, days, expiry_listings and to_subcategories', array('expiry', array((string) $ids['Vehicles'], (string) $ids['Jobs']), '30', 'new', '1'), $r['post'] ?? null);
pin('the dialog closes', true, $r['closed'] ?? null);
pin('the cells update, below included', array('After 30 days', 'After 30 days', 'After 30 days', 'After 30 days'), $r['cells'] ?? null);
pin('the selection clears', 0, $r['sel'] ?? null);
pin('the dialog resets to Keep when opened again', true, $r['postedBack'] ?? null);
pin('a selection with no listings hides the posted choice', true, $r['noListingsPosted'] ?? null);
pin('and keeps it hidden after a change', true, $r['noListingsStill'] ?? null);
pin('and sends keep, with no subcategory flag', array('0', 'keep', true), $r['keep'] ?? null);

exit(harness_result());
