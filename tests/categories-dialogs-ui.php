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
 * Categories screen dialogs in headless Chrome: the Disable and Delete confirms (facts, cost on the
 * button, Disable instead, focus), the bulk overlap note, the Set expiry… dialog (Now line, live
 * "end today" line, button count) and the result toasts, against a fake fetch.
 *
 * Usage: php tests/categories-dialogs-ui.php
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
function dialogs_ui_run(string $body, array $tree, array $opts = array()): array
{
    $file = sys_get_temp_dir() . '/cat-dialogs-ui-' . getmypid() . '-' . substr(md5($body), 0, 8) . '.js';
    $js = "window.catDriver = async function (t) {\n"
        . "var name = function (tr) { return tr.querySelector('.cat-name').textContent; };\n"
        . "var r = {};\n"
        . "var jm = document.createElement('div'); jm.id = 'jsMessage'; document.body.append(jm);\n"
        . "window.__flash = [];\nwindow.setJsMessage = function (type, text) { window.__flash.push([type, text]); };\n"
        . "window.__answers.category_bulk = function (call) { var ids = (call.body.ids || []).map(Number); return {ok: true, done: ids, failed: {}, queued: [], message: ids.length + ' done.'}; };\n"
        . "var calls = function (a) { return window.__calls.filter(function (c) { return c.action === a; }); };\n"
        . "var last = function (a) { return calls(a).pop() || {body: {}, query: {}}; };\n"
        . "var facts = function () { return t.\$\$('#catConfirmFacts li').map(function (l) { return l.textContent; }).join(' '); };\n"
        . "var toastText = function () { var b = t.\$('.osc-toast-text'); return b ? b.textContent : null; };\n"
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

harness_section('Delete confirm');
$r = dialogs_ui_run(<<<'JS'
window.__answers.category_delete_preview = {categories: 8, listings: 30, queued: false};
var del = t.act('Vehicles', 'delete');
await t.click(del);
r.open = t.$('#catConfirm').open;
r.title = t.$('#catConfirmTitle').textContent;
r.facts = t.$$('#catConfirmFacts li').map(function (l) { return [l.className, l.textContent]; });
r.icons = t.$$('#catConfirmFacts li i').map(function (i) { return i.className; });
r.ok = t.$('#catConfirmOk').textContent;
r.focus = document.activeElement.id;
r.aside = [!t.$('#catConfirmAside').hidden, t.$('#catConfirmAside button').textContent, t.$('#catConfirmAside p').textContent];
t.$('#catConfirmCancel').click();
await t.settle(400);
r.back = document.activeElement === del;
r.posted = calls('delete_category').length;
await t.click(del);
t.$('#catConfirmAside button').click();
await t.settle(400);
r.swapTitle = t.$('#catConfirm').open ? t.$('#catConfirmTitle').textContent : null;
r.swapOk = t.$('#catConfirmOk').textContent;
r.swapAside = t.$('#catConfirmAside').hidden;
t.$('#catConfirmCancel').click();
await t.settle(400);
r.swapBack = document.activeElement === del;
await t.click(del);
await ok();
r.toast = toastText();
r.said = t.$('#cat-announce').textContent;
r.live = !!t.$('.osc-toast[aria-live], .osc-toast [aria-live], .osc-toast[role="status"]');
window.__answers.category_delete_preview = {categories: 1, listings: 0, queued: false};
await t.click(t.act('Jobs', 'delete'));
r.leaf = [t.$('#catConfirmTitle').textContent, t.$$('#catConfirmFacts li').map(function (l) { return l.textContent; }), t.$('#catConfirmOk').textContent, t.$('#catConfirmAside').hidden];
JS, $demo);
pin('Delete opens the confirm', true, $r['open'] ?? null);
pin('the title names the category', 'Delete Vehicles?', $r['title'] ?? null);
pin('three facts: subcategories, listings, no undo', array(
    array('is-bad', 'Its 7 subcategories are deleted too.'),
    array('is-bad', '30 listings are deleted, along with any expired or unapproved ones.'),
    array('', 'This can\'t be undone.'),
), $r['facts'] ?? null);
pin('the red facts carry a warning icon', array('bi bi-exclamation-triangle', 'bi bi-exclamation-triangle'), $r['icons'] ?? null);
pin('the button counts the categories; the listings are in the facts', 'Delete 8 categories', $r['ok'] ?? null);
pin('Cancel has focus, not the danger button', 'catConfirmCancel', $r['focus'] ?? null);
pin('Disable instead is offered, with its sentence', array(true, 'Disable instead', 'Want to keep the listings? Disable instead. It hides everything and deletes nothing.'), $r['aside'] ?? null);
pin('Cancel puts focus back on the Delete button', true, $r['back'] ?? null);
pin('and deletes nothing', 0, $r['posted'] ?? null);
pin('Disable instead opens the Disable question', 'Disable Vehicles?', $r['swapTitle'] ?? null);
pin('whose button says Disable 11 categories', 'Disable 11 categories', $r['swapOk'] ?? null);
pin('with no aside of its own', true, $r['swapAside'] ?? null);
pin('Cancel there still returns focus to the opener', true, $r['swapBack'] ?? null);
check('deleting says what went', str_starts_with((string) ($r['toast'] ?? ''), 'Deleted Vehicles, 7 subcategories') && str_contains((string) ($r['toast'] ?? ''), '30 listings.'), (string) ($r['toast'] ?? ''));
pin('the same sentence is spoken once, by the page', $r['toast'] ?? null, $r['said'] ?? null);
pin('and the toast is not a live region', false, $r['live'] ?? null);
pin('a leaf with no listings has no aside and a plain button', array('Delete Jobs?', array('It has no listings. Any expired or unapproved ones are deleted.', 'This can\'t be undone.'), 'Delete 1 category', true), $r['leaf'] ?? null);

harness_section('Disable confirm and result toasts');
$r = dialogs_ui_run(<<<'JS'
var dis = t.act('Vehicles', 'toggle');
await t.click(dis);
r.title = t.$('#catConfirmTitle').textContent;
r.facts = t.$$('#catConfirmFacts li').map(function (l) { return [l.className, l.textContent]; });
r.ok = t.$('#catConfirmOk').textContent;
r.focus = document.activeElement.id;
r.aside = t.$('#catConfirmAside').hidden;
await ok();
r.back = document.activeElement === dis;
r.toast = toastText();
r.said = t.$('#cat-announce').textContent;
await t.click(t.act('Vehicles', 'toggle'));
r.enabled = toastText();
await t.click(t.act('Other Vehicles', 'toggle'));
r.leafAtOnce = !t.$('#catConfirm').open;
r.leafToast = toastText();
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.tick(t.row('Cars'));
await t.tick(t.row('Vehicles'));
await bulk('disable');
r.bulkTitle = t.$('#catConfirmTitle').textContent;
r.bulkFacts = t.$$('#catConfirmFacts li').map(function (l) { return l.textContent; });
r.bulkOk = t.$('#catConfirmOk').textContent;
JS, $demo);
pin('the title names the category', 'Disable Vehicles?', $r['title'] ?? null);
check('the facts say what happens', ($r['facts'][0][1] ?? '') === 'Its 10 subcategories are disabled too.' && str_contains((string) ($r['facts'][1][1] ?? ''), 'stop showing on your site') && ($r['facts'][2] ?? array()) === array('is-ok', 'Nothing is deleted. You can enable it again at any time.'), json_encode($r['facts'] ?? null));
pin('the button counts the categories', 'Disable 11 categories', $r['ok'] ?? null);
pin('Cancel has focus', 'catConfirmCancel', $r['focus'] ?? null);
pin('there is no aside', true, $r['aside'] ?? null);
pin('after Disable focus is on the row action', true, $r['back'] ?? null);
pin('the toast names what went off', 'Disabled Vehicles and 10 subcategories.', $r['toast'] ?? null);
pin('and is spoken once', $r['toast'] ?? null, $r['said'] ?? null);
pin('enabling says so', 'Enabled Vehicles and 10 subcategories.', $r['enabled'] ?? null);
pin('a leaf with no listings disables at once', true, $r['leafAtOnce'] ?? null);
pin('with its own short toast', 'Disabled Other Vehicles.', $r['leafToast'] ?? null);
pin('a bulk disable counts both ticked rows', 'Disable 2 selected categories?', $r['bulkTitle'] ?? null);
pin('notes that Cars is counted once, then the counts', 'Cars is inside Vehicles, so it is counted once.', $r['bulkFacts'][0] ?? null);
pin('and gives the total with subcategories', 'With their subcategories, that is 11 categories.', $r['bulkFacts'][1] ?? null);
pin('the bulk button counts the categories reached', 'Disable 11 categories', $r['bulkOk'] ?? null);

harness_section('Bulk delete overlap');
$r = dialogs_ui_run(<<<'JS'
window.__answers.category_delete_preview = {categories: 8, listings: 30, queued: false};
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.tick(t.row('Cars'));
await t.tick(t.row('Vehicles'));
await bulk('delete');
r.title = t.$('#catConfirmTitle').textContent;
r.facts = t.$$('#catConfirmFacts li').map(function (l) { return l.textContent; });
r.ok = t.$('#catConfirmOk').textContent;
r.preview = last('category_delete_preview').query.ids;
r.aside = !t.$('#catConfirmAside').hidden;
r.focus = document.activeElement.id;
t.$('#catConfirmCancel').click();
await t.settle(400);
r.back = document.activeElement === t.$('#cat-bulk');
JS, $demo);
pin('the title counts the picked rows', 'Delete 2 selected categories?', $r['title'] ?? null);
pin('Cars is noted as inside Vehicles, the total counts it once', array(
    'Cars is inside Vehicles, so it is counted once.',
    'With their subcategories, that is 8 categories.',
    '30 listings are deleted, along with any expired or unapproved ones.',
    'This can\'t be undone.',
), $r['facts'] ?? null);
pin('the button counts 8 once', 'Delete 8 categories', $r['ok'] ?? null);
pin('only the top-most id is sent to the preview', array((string) $ids['Vehicles']), $r['preview'] ?? null);
pin('Disable instead is offered', true, $r['aside'] ?? null);
pin('Cancel is focused', 'catConfirmCancel', $r['focus'] ?? null);
pin('Cancel returns focus to the bulk bar', true, $r['back'] ?? null);

harness_section('Set expiry dialog');
$veh = (string) $ids['Vehicles'];
$r = dialogs_ui_run(<<<'JS'
window.__answers.category_bulk = function (call) { var ids = (call.body.ids || []).map(Number); return {ok: true, done: ids, failed: {}, queued: [], message: ''}; };
window.__answers.category_expiry_preview = function (call) {
    return {listings: 30, endNow: (call.query.ids || []).indexOf(t.row('Vehicles').dataset.id) !== -1 ? 12 : 0};
};
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.tick(t.row('Cars'));
await t.tick(t.row('Vehicles'));
await bulk('expiry');
r.title = t.$('#catExpiryTitle').textContent;
r.now = t.$('#catExpiryNow').textContent;
r.ok0 = t.$('#catExpiryOk').textContent;
r.sub = t.$('#cat-bx-sub-label').textContent;
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
t.$('#cat-bx-posted input[value="new"]').click();
await t.settle(500);
var line = t.$('#cat-bx-posted .cat-exp-warn');
r.line = line && !line.hidden ? line.textContent : null;
r.previews = calls('category_expiry_preview').map(function (c) { return [c.query.ids, c.query.days, c.query.to_subcategories || null]; });
t.$('#cat-bx-posted input[value="keep"]').click();
await t.settle(500);
r.lineKeep = line ? line.hidden : null;
t.$('#cat-bx-posted input[value="new"]').click();
await t.settle(500);
t.$('#cat-bx-sub').click();
await t.settle(500);
r.ok1 = t.$('#catExpiryOk').textContent;
r.subNote = t.$('#cat-bx-sub-note').textContent;
r.order = Array.from(t.$('#catExpiry .osc-dialog-body').children).map(function (n) { return n.id || n.className; }).join(' ');
r.lastPreview = last('category_expiry_preview').query;
r.lineSub = t.$('#cat-bx-posted .cat-exp-warn').textContent;
t.$('#cat-bx-sub').click();
await t.settle(500);
t.$('#catExpiryForm').requestSubmit();
await t.settle(500);
r.posts = calls('category_bulk').map(function (c) { return [c.body.do, c.body.ids, c.body.days, c.body.expiry_listings, c.body.to_subcategories === undefined]; });
r.toast = toastText();
r.focus = document.activeElement.id;
await t.click(t.act('Other Vehicles', 'move'));
t.$('#catMove').close();
await t.tick(t.row('Other Vehicles'));
await bulk('expiry');
r.leaf = [t.$('#catExpiryTitle').textContent, t.$('#catExpiryNow').textContent, t.$('#cat-bx-posted').hidden, !t.$('#catExpiryFacts').hidden, t.$('#catExpiryFacts').textContent];
JS, $demo);
pin('the title counts the picked rows', 'Set expiry for 2 categories', $r['title'] ?? null);
pin('Now reads Mixed, with each value', 'Now: Mixed (45 days, 60 days)', $r['now'] ?? null);
pin('the button counts the picked rows', 'Set on 2 categories', $r['ok0'] ?? null);
pin('Give them new end dates shows how many end today', '12 listings end today.', $r['line'] ?? null);
pin('Cars and Vehicles are both asked, as separate groups', array(array(array((string) $ids['Vehicles']), '30', null), array(array((string) $ids['Cars']), '30', null)), array_slice($r['previews'] ?? array(), 0, 2));
pin('Keep their end dates hides the line', true, $r['lineKeep'] ?? null);
pin('ticking the subcategories counts every row reached', 'Set on 11 categories', $r['ok1'] ?? null);
pin('the note under it counts the subcategories that change', '10 of them change.', $r['subNote'] ?? null);
pin('the subcategory choice sits between the chips and the posted choice', 'catExpiryTitle catExpiryNow cat-exp form-check cat-bx-sub cat-bx-posted catExpiryFacts', $r['order'] ?? null);
pin('and asks once for Vehicles with its subcategories', array((string) $ids['Vehicles']), $r['lastPreview']['ids'] ?? null);
pin('with to_subcategories on', '1', $r['lastPreview']['to_subcategories'] ?? null);
pin('Vehicles then Cars are posted, one level each', array(
    array('expiry', array($veh), '30', 'new', true),
    array('expiry', array((string) $ids['Cars']), '30', 'new', true),
), $r['posts'] ?? null);
pin('the toast says what changed, and how many ended today', 'Listings in 2 categories now expire after 30 days. Listings already posted got new end dates; 12 listings ended today.', $r['toast'] ?? null);
pin('focus goes back to the bulk bar', 'cat-bulk', $r['focus'] ?? null);
pin('a leaf with no listings: title, Now, no posted choice, the fact', array('Set expiry for Other Vehicles', 'Now: after 60 days', true, true, 'It has no listings now. New listings get it from now on.'), $r['leaf'] ?? null);

harness_section('Edit drawer: live end-today line');
$r = dialogs_ui_run(<<<'JS'
window.__answers.category_expiry_preview = {listings: 30, endNow: 4};
await t.click(t.act('Vehicles', 'edit'));
await t.drawer();
t.$('input[name="cat-f-choice"][value="30"]').click();
await t.settle();
t.$('#cat-f-posted input[value="new"]').click();
await t.settle(500);
var line = t.$('#cat-f-posted .cat-exp-warn');
r.line = line && !line.hidden ? line.textContent : null;
var q = last('category_expiry_preview').query;
r.query = [q.ids, q.days, q.to_subcategories || null];
r.warnIcon = !!line.querySelector('i.bi-exclamation-triangle');
r.applyOff = t.$('#cat-apply-note').textContent;
t.$('#apply_changes_to_subcategories').click();
await t.settle(500);
r.sub = last('category_expiry_preview').query.to_subcategories;
r.applyOn = t.$('#cat-apply-note').textContent;
window.__answers.category_expiry_preview = {listings: 30, endNow: null};
t.$('#cat-f-posted input[value="keep"]').click();
t.$('#cat-f-posted input[value="new"]').click();
await t.settle(500);
r.dropped = line.hidden;
JS, $demo);
pin('the drawer shows how many listings end today', '4 listings end today.', $r['line'] ?? null);
pin('it asks about this category and the chosen days', array(array((string) $ids['Vehicles']), '30', null), $r['query'] ?? null);
pin('ticking the subcategories asks again with them', '1', $r['sub'] ?? null);
pin('the end-today line carries the warning mark', true, $r['warnIcon'] ?? null);
pin('left off, the note counts the subcategories that differ', 'Left off, each subcategory keeps its own setting. 10 subcategories differ from this one now.', $r['applyOff'] ?? null);
pin('ticked, it counts the ones that change', 'Every subcategory gets this setting too. 10 of them change.', $r['applyOn'] ?? null);
pin('an answer with no count drops the line', true, $r['dropped'] ?? null);

harness_section('Set expiry dialog: when the end-today line stays hidden');
$r = dialogs_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.tick(t.row('Cars'));
await t.tick(t.row('Vehicles'));
await bulk('expiry');
var line = t.$('#cat-bx-posted .cat-exp-warn');
var shown = function () { return line && !line.hidden ? line.textContent : null; };
var veh = t.row('Vehicles').dataset.id;
window.__answers.category_expiry_preview = {listings: 30, endNow: 0};
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle();
t.$('#cat-bx-posted input[value="new"]').click();
await t.settle(500);
r.zero = shown();
var hint = t.$('#cat-bx-posted [data-cat-redate-hint]');
r.hint30 = hint.textContent;
t.$('#catExpiry input[name="cat-bx-choice"][value="never"]').click();
await t.settle();
r.hintNever = [t.$('#cat-bx-posted [data-cat-redate]').textContent, hint.textContent];
window.__answers.category_expiry_preview = function (call) {
    return {listings: 30, endNow: (call.query.ids || []).indexOf(veh) !== -1 ? null : 5};
};
t.$('#catExpiry input[name="cat-bx-choice"][value="60"]').click();
await t.settle(500);
r.partNull = [shown(), t.$('#catExpiryOk').textContent];
window.__answers.category_expiry_preview = function (call) {
    return call.query.days === '30' ? {listings: 30, endNow: 7, __delay: 900} : {listings: 30, endNow: 3};
};
t.$('#catExpiry input[name="cat-bx-choice"][value="30"]').click();
await t.settle(350);
t.$('#catExpiry input[name="cat-bx-choice"][value="90"]').click();
await t.settle(1500);
r.stale = shown();
JS, $demo);
pin('a count of 0 leaves the line hidden', null, $r['zero'] ?? null);
pin('the help under new end dates states the days', 'Each one ends 30 days after it was published.', $r['hint30'] ?? null);
pin('with Never it says the end dates go', array('Remove their end dates', 'Their end dates are removed, so they stay up until taken down.'), $r['hintNever'] ?? null);
pin('a group with no count leaves the line hidden and the button as it was', array(null, 'Set on 2 categories'), $r['partNull'] ?? null);
pin('a slow answer to an older choice does not replace the newer count', '6 listings end today.', $r['stale'] ?? null);

exit(harness_result());
