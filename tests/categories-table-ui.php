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
 * Categories screen in headless Chrome: search, status tabs, selection, disclosure, the edit
 * drawer (parent options, expiry chips, apply note, save and move) and the 1500-row budgets.
 *
 * Usage: php tests/categories-table-ui.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-browser.php';

/**
 * Runs one driver body (the inside of an async function taking t) and returns its reading.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts
 *
 * @return array<string,mixed>
 */
function table_ui_run(string $body, array $tree, array $opts = array()): array
{
    $file = sys_get_temp_dir() . '/cat-table-ui-' . getmypid() . '-' . substr(md5($body), 0, 8) . '.js';
    $js = "window.catDriver = async function (t) {\n"
        . "var name = function (tr) { return tr.querySelector('.cat-name').textContent; };\n"
        . "var r = {};\n" . $body . "\nreturn r;\n};\n";
    file_put_contents($file, $js);
    $r = categories_browser_run($file, $tree, $opts);
    @unlink($file);
    pin('no script error', array(), $r['errors'] ?? null);
    pin('the screen is ready', true, $r['ready'] ?? null);

    return $r;
}

/**
 * Same as table_ui_run() in real time, through puppeteer-core, at $width. Returns null (skipped) when
 * node or puppeteer-core is not on this machine.
 *
 * @param array<int,array<string,mixed>> $tree
 *
 * @return array<string,mixed>|null
 */
function table_ui_run_live(string $body, array $tree, int $width = 1440): ?array
{
    $chrome = categories_browser_chrome();
    $pc = test_puppeteer();
    if ($pc === '') {
        // npm ci installs puppeteer-core, so on CI a missing one is a failure, not a skip.
        if ((string) getenv('CI') !== '') {
            check('node and puppeteer-core are available for the real-time run', false, 'run npm ci');
        }
        echo "  (skipped: no node or puppeteer-core for the real-time run)\n";

        return null;
    }
    $dir = sys_get_temp_dir() . '/cat-table-live-' . getmypid();
    @mkdir($dir, 0700, true);
    // Top-level code runs while the page parses, after categories.js has queued its init.
    $js = "window.__t0 = performance.now();\n"
        . "document.addEventListener('DOMContentLoaded', function () { window.__initMs = performance.now() - window.__t0; });\n"
        . "document.addEventListener('DOMContentLoaded', function () { document.querySelector('.categories-app').addEventListener('cat:filtered', function (e) { var s = performance.now(); void document.body.offsetHeight; window.__firstPass = e.detail.ms + performance.now() - s; }, {once: true}); });\n"
        . "window.catDriver = async function (t) {\nvar r = {};\n" . $body . "\nreturn r;\n};\n";
    file_put_contents($dir . '/driver.js', $js);
    file_put_contents($dir . '/page.html', categories_browser_page($tree, array('driver' => $dir . '/driver.js')));
    file_put_contents($dir . '/run.mjs', 'const BLINK = ' . json_encode(trim(CATEGORIES_BROWSER_MOUSE)) . ";\n" . <<<'MJS'
const [pc, chrome, page, width] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',900', BLINK]});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 900});
    // A real key press for the driver: await window.oscPress('Tab').
    await tab.exposeFunction('oscPress', (key) => tab.keyboard.press(key));
    await tab.goto('file://' + page);
    await tab.waitForSelector('html[data-done="1"]', {timeout: 60000});
    process.stdout.write(await tab.$eval('#osc-out', (el) => el.textContent));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec('node ' . escapeshellarg($dir . '/run.mjs') . ' '
        . escapeshellarg($pc) . ' ' . escapeshellarg($chrome) . ' ' . escapeshellarg($dir . '/page.html') . ' ' . $width . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));
    $r = json_decode((string) base64_decode(trim($out), true), true);
    check('the real-time run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));

    return is_array($r) ? $r : array();
}

$demo  = categories_fixture_demo();
$index = categories_browser_index($demo);
$ids   = array();
foreach ($index as $id => $row) {
    $ids[(string) $row['s_name']] = $id;
}

harness_section('search');
$r = table_ui_run(<<<'JS'
await t.type('#cat-q', 'car');
r.shown = t.shown().map(name);
r.context = t.shown().filter(function (tr) { return tr.classList.contains('is-context'); }).map(name);
r.marks = t.$$('.cat-name mark').map(function (m) { return m.textContent; });
r.line = t.$('#cat-filter-line').hidden ? null : t.$('#cat-filter-line span').textContent;
r.handlesOff = t.shown().every(function (tr) { return tr.querySelector('.cat-handle').getAttribute('aria-disabled') === 'true'; });
r.expandOff = t.$('[data-cat-act="expand-all"]').disabled;
t.$('#cat-check-all').click();
await t.settle();
r.ticked = t.$$('tr.cat-row .col-bulkactions input').filter(function (b) { return b.checked; }).length;
r.tickedHidden = t.$$('tr.cat-row').filter(function (tr) { return tr.hidden && tr.querySelector('.col-bulkactions input').checked; }).length;
r.selected = t.$$('tr.cat-row.is-selected').length;
r.selcount = t.$('[data-cat-selcount]').textContent;
r.selnote = !t.$('.cat-selnote').hidden;
await t.type('#cat-q', 'CAR');
r.upper = t.shown().filter(function (tr) { return !tr.classList.contains('is-context'); }).length;
await t.type('#cat-q', 'bicycle shop');
var st = t.$('tr.cat-state');
r.noResult = !!st && !st.hidden;
r.noResultText = st ? st.textContent : '';
r.shownNoResult = t.shown().length;
r.lineNoResult = t.$('#cat-filter-line').hidden;
await t.click(t.$('[data-cat-act="add-from-search"]'));
await t.settle(400);
r.addTitle = t.$('#catDrawerTitle').textContent;
r.addName = (t.$('#catDrawerBody input[type="text"]') || {}).value;
t.$('#catDrawerClose').click();
await t.settle(500);
await t.click(t.$('[data-cat-act="clear-search"]'));
r.afterClear = t.shown().length;
r.marksAfter = t.$$('.cat-name mark').length;
r.handlesBack = t.shown().every(function (tr) { return !tr.querySelector('.cat-handle').hasAttribute('aria-disabled'); });
r.stateAfter = t.$('tr.cat-state').hidden;
JS, $demo);
pin('"car" shows the five matches and their three groups', array(
    'Vehicles', 'Cars', 'Car Parts', 'RVs - Campers - Caravans', 'Community', 'Carpool', 'Jobs', 'Healthcare',
), $r['shown'] ?? null);
pin('the groups are grey context rows', array('Vehicles', 'Community', 'Jobs'), $r['context'] ?? null);
pin('each match is highlighted', array('Car', 'Car', 'Car', 'Car', 'car'), $r['marks'] ?? null);
check('the filter line counts the matches', str_starts_with((string) ($r['line'] ?? ''), '5 categories match “car”.'), (string) ($r['line'] ?? 'hidden'));
check('the filter line names the search', str_contains((string) ($r['line'] ?? ''), '“car”'), (string) ($r['line'] ?? ''));
pin('reorder is off while searching', true, $r['handlesOff'] ?? null);
pin('Expand all is off while searching', true, $r['expandOff'] ?? null);
pin('select all ticks only the 8 shown rows', 8, $r['ticked'] ?? null);
pin('no hidden row is ticked', 0, $r['tickedHidden'] ?? null);
pin('ticked rows are marked selected', 8, $r['selected'] ?? null);
pin('the selection note counts them', '8', $r['selcount'] ?? null);
pin('the selection note shows', true, $r['selnote'] ?? null);
pin('search ignores case', 5, $r['upper'] ?? null);
pin('no match shows the no-results row', true, $r['noResult'] ?? null);
check('the no-results row names the search', str_contains((string) ($r['noResultText'] ?? ''), 'No category matches “bicycle shop”'), (string) ($r['noResultText'] ?? ''));
check('the no-results row offers to add it', str_contains((string) ($r['noResultText'] ?? ''), 'Add “bicycle shop” as a category'), (string) ($r['noResultText'] ?? ''));
pin('no category row shows', 0, $r['shownNoResult'] ?? null);
pin('the filter line hides with no match', true, $r['lineNoResult'] ?? null);
pin('Add "…" opens the Add category drawer', 'Add category', $r['addTitle'] ?? null);
pin('the drawer has the searched name filled in', 'bicycle shop', $r['addName'] ?? null);
pin('Clear search brings back the 8 groups', 8, $r['afterClear'] ?? null);
pin('no highlight is left', 0, $r['marksAfter'] ?? null);
pin('reorder is back on', true, $r['handlesBack'] ?? null);
pin('the no-results row hides', true, $r['stateAfter'] ?? null);

harness_section('status tabs, disclosure');
$r = table_ui_run(<<<'JS'
r.foot0 = t.$('#cat-foot-sum').textContent;
await t.click(t.tab('Disabled'));
r.url = location.search;
var shown = t.shown();
var hits = shown.filter(function (tr) { return !tr.classList.contains('is-context'); });
r.hits = hits.length;
r.hitsOff = hits.every(function (tr) { return tr.classList.contains('is-disabled'); });
r.context = shown.filter(function (tr) { return tr.classList.contains('is-context'); }).map(name);
r.badge = t.tab('Disabled').querySelector('.osc-count').textContent;
r.active = t.tab('Disabled').classList.contains('is-active') && t.tab('Disabled').getAttribute('aria-current') === 'page';
r.line = t.$('#cat-filter-line span').textContent;
await t.click(t.tab('All'));
r.urlAll = location.search;
r.all = t.shown().length;
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
r.open = t.shown().length;
r.expanded = t.row('Vehicles').querySelector('.cat-disclosure').getAttribute('aria-expanded');
r.carsShown = !t.row('Cars').hidden;
r.engineHidden = t.row('Engine - Gearbox').hidden;
await t.click(t.$('[data-cat-act="expand-all"]'));
r.expandAll = t.shown().length;
await t.click(t.$('[data-cat-act="collapse-all"]'));
r.collapseAll = t.shown().length;
r.foot = t.$('#cat-foot-sum').textContent;
JS, $demo);
pin('the Disabled tab puts status=off in the address', '?status=off', $r['url'] ?? null);
pin('it shows the 8 disabled categories', 8, $r['hits'] ?? null);
pin('every match is disabled', true, $r['hitsOff'] ?? null);
pin('an enabled group holding one shows grey', array('Services'), $r['context'] ?? null);
pin('the tab count matches', '8', $r['badge'] ?? null);
pin('the tab is marked current', true, $r['active'] ?? null);
check('the filter line names the tab', str_contains((string) ($r['line'] ?? ''), 'Showing 8 disabled categories.'), (string) ($r['line'] ?? ''));
pin('All drops the status from the address', '', $r['urlAll'] ?? null);
pin('All shows the 8 groups again', 8, $r['all'] ?? null);
pin('opening Vehicles shows its 7 subcategories', 15, $r['open'] ?? null);
pin('its disclosure says expanded', 'true', $r['expanded'] ?? null);
pin('Cars shows', true, $r['carsShown'] ?? null);
pin('Car Parts stays closed', true, $r['engineHidden'] ?? null);
pin('Expand all shows every row', 97, $r['expandAll'] ?? null);
pin('Collapse all shows the 8 groups', 8, $r['collapseAll'] ?? null);
check('the footer sums the tree', preg_match('/^97 categories in 8 groups · \\d+ listings$/u', (string) ($r['foot'] ?? '')) === 1, (string) ($r['foot'] ?? ''));
pin('the script keeps the server footer', $r['foot0'] ?? 'x', $r['foot'] ?? null);

harness_section('starting on the Disabled tab');
$r = table_ui_run('r.shown = t.shown().filter(function (tr) { return !tr.classList.contains("is-context"); }).length;', $demo, array('status' => 'off'));
pin('?status=off filters on load', 8, $r['shown'] ?? null);

harness_section('edit drawer');
$r = table_ui_run(<<<'JS'
await t.click(t.act('Vehicles', 'edit'));
await t.settle(400);
r.title = t.$('#catDrawerTitle').textContent;
r.sub = t.$('#catDrawerSub').textContent;
r.vehListings = t.row('Vehicles').dataset.listings;
r.editing = t.row('Vehicles').classList.contains('is-editing');
var sel = t.$('#cat-parent');
r.parent = sel.value;
r.parentText = sel.selectedOptions[0].textContent;
r.options = sel.options.length;
r.selfOff = sel.querySelector('option[value="' + t.row('Vehicles').dataset.id + '"]').disabled;
r.childOff = sel.querySelector('option[value="' + t.row('Winter tyres').dataset.id + '"]').disabled;
r.otherOn = !sel.querySelector('option[value="' + t.row('Jobs').dataset.id + '"]').disabled;
r.apply = t.$('#cat-apply-label').textContent;
r.noteOff = t.$('#cat-apply-note').textContent;
t.$('#apply_changes_to_subcategories').click();
await t.settle();
r.noteOn = t.$('#cat-apply-note').textContent;
t.$('#apply_changes_to_subcategories').click();
await t.settle();
r.posted0 = t.$('#cat-f-posted').hidden;
t.$('input[name="cat-f-choice"][value="30"]').click();
await t.settle();
r.posted30 = !t.$('#cat-f-posted').hidden;
r.days30 = t.$('#cat-f-days').value;
t.$('#cat-f-posted input[value="new"]').click();
t.$('input[name="cat-f-choice"][value="never"]').click();
await t.settle();
r.never = t.$('[data-cat-redate]').textContent;
r.days0 = t.$('#cat-f-days').value;
t.$('input[name="cat-f-choice"][value="other"]').click();
await t.settle();
r.otherShown = !t.$('#cat-f-days').hidden;
t.$('input[name="cat-f-choice"][value="60"]').click();
await t.settle();
r.posted60 = t.$('#cat-f-posted').hidden;
r.keepBack = t.$('#cat-f-posted input[value="keep"]').checked;
r.otherHidden = t.$('#cat-f-days').hidden;
t.$('input[name="cat-f-choice"][value="30"]').click();
t.$('#apply_changes_to_subcategories').click();
window.__answers.edit_category_post = {error: 0, msg: 'Saved.', text: 'Vehicles & co', parent_id: 0, moved: false};
t.$('#catDrawerBody form').requestSubmit();
await t.settle(500);
var call = window.__calls.filter(function (c) { return c.body.action === 'edit_category_post'; })[0] || {body: {}};
r.posted = [call.method, call.body.parent_id, call.body.i_expiration_days, call.body.apply_changes_to_subcategories];
r.closed = !document.querySelector('.categories-app').classList.contains('drawer-open');
r.renamed = !!t.row('Vehicles & co');
r.expiry = t.row('Vehicles & co').querySelector('.col-expiry').textContent;
r.childExpiry = t.row('Winter tyres').querySelector('.col-expiry').textContent;
r.otherExpiry = t.row('Jobs').querySelector('.col-expiry').textContent;
r.option = Array.from(document.getElementById('catParentOptions').content.querySelectorAll('option')).some(function (o) { return o.textContent === 'Vehicles & co'; });
await t.click(t.act('Vehicles & co', 'addsub'));
await t.settle(400);
r.addTitle = t.$('#catDrawerTitle').textContent;
r.addSub = t.$('#catDrawerSub').textContent;
r.addParent = t.$('#cat-parent').value;
r.addPosted = t.$('#cat-f-posted').hidden;
t.$('input[name="cat-f-choice"][value="90"]').click();
await t.settle();
r.addPosted90 = t.$('#cat-f-posted').hidden;
var frame = window.__calls.filter(function (c) { return c.action === 'category_edit_iframe'; }).pop();
r.frame = [frame.query.id, frame.query.parent_id];
JS, $demo);
pin('one heading: Edit category', 'Edit category', $r['title'] ?? null);
pin('the line under it names the category and its counts', 'Vehicles · 10 subcategories · ' . $r['vehListings'] . ' listings', $r['sub'] ?? null);
pin('the row is marked as being edited', true, $r['editing'] ?? null);
pin('the parent is Top level', '0', $r['parent'] ?? null);
pin('Top level is the selected option', 'Top level', $r['parentText'] ?? null);
pin('every category is a parent option, plus Top level', 98, $r['options'] ?? null);
pin('it cannot be its own parent', true, $r['selfOff'] ?? null);
pin('nor go under its own subcategory', true, $r['childOff'] ?? null);
pin('another group can be picked', true, $r['otherOn'] ?? null);
pin('the apply box counts every level below', 'Also apply to its 10 subcategories', $r['apply'] ?? null);
pin('the apply note says what off does and how many differ', 'Left off, each subcategory keeps its own setting. 2 subcategories differ from this one now.', $r['noteOff'] ?? null);
pin('the apply note follows the tick', 'Every subcategory gets this setting too. 2 of them change.', $r['noteOn'] ?? null);
pin('Listings already posted starts hidden', true, $r['posted0'] ?? null);
pin('it shows once the expiry changes', true, $r['posted30'] ?? null);
pin('the 30 chip sets 30 days', '30', $r['days30'] ?? null);
pin('Never swaps the re-date label', 'Remove their end dates', $r['never'] ?? null);
pin('Never sets 0 days', '0', $r['days0'] ?? null);
pin('Other shows the day field', true, $r['otherShown'] ?? null);
pin('back to the saved 60 hides it', true, $r['posted60'] ?? null);
pin('and goes back to Keep', true, $r['keepBack'] ?? null);
pin('a preset chip hides the day field', true, $r['otherHidden'] ?? null);
pin('save posts the parent, expiry and apply box', array('POST', '0', '30', '1'), $r['posted'] ?? null);
pin('a save closes the drawer', true, $r['closed'] ?? null);
pin('the row takes the saved name', true, $r['renamed'] ?? null);
pin('the expiry cell updates', 'After 30 days', $r['expiry'] ?? null);
pin('ticked apply reaches every level below', 'After 30 days', $r['childExpiry'] ?? null);
pin('other groups keep theirs', 'After 30 days', $r['otherExpiry'] ?? null);
pin('the parent options take the new name', true, $r['option'] ?? null);
pin('Add subcategory has its own heading', 'Add subcategory', $r['addTitle'] ?? null);
pin('and says where it goes', 'Inside Vehicles & co', $r['addSub'] ?? null);
pin('its Parent is that category', (string) $ids['Vehicles'], $r['addParent'] ?? null);
pin('a new category never shows Listings already posted', true, $r['addPosted'] ?? null);
pin('not even after the expiry changes', true, $r['addPosted90'] ?? null);
pin('the drawer asks for a new category under it', array('0', (string) $ids['Vehicles']), $r['frame'] ?? null);

harness_section('saving a new parent moves the row');
$r = table_ui_run(<<<'JS'
var re = t.row('Real estate');
var veh = t.row('Vehicles');
r.foot0 = t.$('#cat-foot-sum').textContent;
r.before = [veh.dataset.listings, re.dataset.listings, veh.dataset.subs, veh.dataset.below];
await t.click(t.act('Cars', 'edit'));
await t.settle(400);
r.parent = t.$('#cat-parent').value;
r.sub = t.$('#catDrawerSub').textContent;
window.__answers.edit_category_post = {error: 0, msg: 'Saved.', text: 'Cars', parent_id: +re.dataset.id, moved: true};
t.$('#cat-parent').value = re.dataset.id;
t.$('#catDrawerBody form').requestSubmit();
await t.settle(500);
var cars = t.row('Cars');
r.carsParent = cars.dataset.parent === re.dataset.id;
r.carsDepth = cars.dataset.depth;
r.prev = name(cars.previousElementSibling);
r.next = name(cars.nextElementSibling);
r.carsShown = !cars.hidden;
r.after = [veh.dataset.listings, re.dataset.listings, veh.dataset.subs, veh.dataset.below, re.dataset.subs];
r.cells = [veh.querySelector('.col-listings').textContent, re.querySelector('.col-listings').textContent, veh.querySelector('.col-subs').textContent];
r.foot = t.$('#cat-foot-sum').textContent;
var opts = Array.from(document.getElementById('catParentOptions').content.querySelectorAll('option'));
var at = opts.findIndex(function (o) { return o.value === cars.dataset.id; });
r.optPrev = opts[at - 1].textContent.trim();
r.optDepth = opts[at].dataset.depth;
await t.click(t.act('Cars', 'edit'));
await t.settle(400);
window.__answers.edit_category_post = {error: 3, msg: 'That category is too deep.', field: '/parent_id'};
t.$('#catDrawerBody form').requestSubmit();
await t.settle(500);
r.stillOpen = document.querySelector('.categories-app').classList.contains('drawer-open');
r.invalid = t.$('#cat-parent').getAttribute('aria-invalid');
r.unmoved = t.row('Cars').dataset.parent === re.dataset.id;
JS, $demo);
pin('Cars starts under Vehicles', (string) $ids['Vehicles'], $r['parent'] ?? null);
pin('the line under the heading says where it is', 'Cars · Inside Vehicles · 19 listings', $r['sub'] ?? null);
pin('Cars is now under Real estate', true, $r['carsParent'] ?? null);
pin('at the same depth', '2', $r['carsDepth'] ?? null);
pin('at the end of its new group', 'Shops for Rent - Sale', $r['prev'] ?? null);
pin('before the next group', 'Services', $r['next'] ?? null);
pin('its new group opens to show it', true, $r['carsShown'] ?? null);
$before = $r['before'] ?? array(0, 0, 0, 0);
pin('listings move from Vehicles to Real estate; Vehicles loses one subcategory', array(
    (string) ((int) $before[0] - 19), (string) ((int) $before[1] + 19), (string) ((int) $before[2] - 1), (string) ((int) $before[3] - 1), '10',
), $r['after'] ?? null);
pin('the cells show it', array((string) ((int) $before[0] - 19), (string) ((int) $before[1] + 19), (string) ((int) $before[2] - 1)), $r['cells'] ?? null);
pin('the footer total is unchanged', $r['foot0'] ?? 'x', $r['foot'] ?? null);
pin('the parent options follow the move', 'Shops for Rent - Sale', $r['optPrev'] ?? null);
pin('with its depth', '2', $r['optDepth'] ?? null);
pin('a refused parent keeps the drawer open', true, $r['stillOpen'] ?? null);
pin('and marks the Parent field', 'true', $r['invalid'] ?? null);
pin('and moves nothing', true, $r['unmoved'] ?? null);

harness_section('a theme depth limit');
$r = table_ui_run(<<<'JS'
await t.click(t.act('Cars', 'edit'));
await t.settle(400);
var sel = t.$('#cat-parent');
var opt = function (n) { return sel.querySelector('option[value="' + t.row(n).dataset.id + '"]'); };
r.cars = [opt('Car Parts').disabled, opt('Tyres - Wheels').disabled, opt('Tyres - Wheels').textContent.trim()];
r.hint = t.$('#cat-parent-hint').hidden ? '' : t.$('#cat-parent-hint').textContent;
t.$('#catDrawerClose').click();
await t.settle(500);
await t.click(t.act('Car Parts', 'edit'));
await t.settle(400);
sel = t.$('#cat-parent');
r.carParts = [opt('Real estate').disabled, sel.querySelector('option[value="0"]').disabled, opt('Cars').disabled];
JS, $demo, array('depthLimit' => 3, 'drawer_ids' => array($ids['Cars'], $ids['Car Parts'])));
pin('with 3 levels, Cars may go under a level-2 row, not a level-3 one', array(false, true, 'Tyres - Wheels (too deep)'), $r['cars'] ?? null);
pin('the hint says why', 'This theme shows 3 levels of categories.', $r['hint'] ?? null);
pin('a category already 4 deep may move where it gets no deeper; not under a level-2 row', array(false, false, true), $r['carParts'] ?? null);

// Headless Chrome will not open a window narrower than 500 px, so the phone run uses puppeteer.
harness_section('phone width (390)');
$r = table_ui_run_live(<<<'JS'
var vis = function (el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; };
r.width = window.innerWidth;
r.overflow = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
var veh = t.row('Vehicles');
r.cols = ['.col-subs', '.col-listings', '.col-expiry'].map(function (c) { return vis(veh.querySelector(c)); });
r.head = vis(t.$('.cat-table thead'));
r.meta = vis(veh.querySelector('.cat-meta'));
r.actions = vis(veh.querySelector('.cat-more')) && !vis(veh.querySelector('.cat-actions'));
await t.click(veh.querySelector('.cat-disclosure'));
await t.click(t.$('[data-cat-act="expand-all"]'));
r.overflowOpen = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
await t.tick(t.row('Cars'));
r.bulk = vis(t.$('#cat-bulk')) && vis(t.$('.cat-selnote'));
r.search = vis(t.$('#cat-q'));
JS, $demo, 390);
if ($r !== null) {
    pin('no script error', array(), $r['errors'] ?? null);
    pin('the page is phone width', 390, $r['width'] ?? null);
    pin('no sideways scroll', 0, $r['overflow'] ?? null);
    pin('the Subcategories, Listings and Listings expire cells are hidden', array(false, false, false), $r['cols'] ?? null);
    pin('and so is the table head', false, $r['head'] ?? null);
    pin('the counts show in the meta line', true, $r['meta'] ?? null);
    pin('the row actions give way to the ⋯', true, $r['actions'] ?? null);
    pin('no sideways scroll with every group open', 0, $r['overflowOpen'] ?? null);
    pin('the bulk bar and selection note show', true, $r['bulk'] ?? null);
    pin('the search shows', true, $r['search'] ?? null);
}

harness_section('desktop rows (1440)');
$r = table_ui_run_live(<<<'JS'
var veh = t.row('Vehicles');
var acts = veh.querySelector('.cat-actions');
var wait = function () { return new Promise(function (res) { setTimeout(res, 300); }); };
r.height = veh.getBoundingClientRect().height;
r.rest = getComputedStyle(acts).opacity;
veh.querySelector('.cat-name').focus();
await window.oscPress('Tab');
await wait();
var on = document.activeElement;
r.focused = veh.contains(on) && on.closest('.cat-actions') ? on.dataset.catAct : null;
r.shown = getComputedStyle(acts).opacity;
var row = veh.getBoundingClientRect(), box = acts.getBoundingClientRect();
r.inline = box.top >= row.top && box.bottom <= row.bottom;
r.heightFocused = row.height;
r.ring = getComputedStyle(veh).outlineStyle;
JS, $demo);
if ($r !== null) {
    pin('no script error', array(), $r['errors'] ?? null);
    check('a row is at most 44 px high', ($r['height'] ?? 99) <= 44, (string) ($r['height'] ?? ''));
    pin('the row actions are hidden at rest', '0', $r['rest'] ?? null);
    pin('Tab from the name reaches Edit', 'edit', $r['focused'] ?? null);
    pin('focus shows the row actions', '1', $r['shown'] ?? null);
    pin('they sit on the row line', true, $r['inline'] ?? null);
    pin('the row keeps its height', $r['height'] ?? null, $r['heightFocused'] ?? null);
    pin('the focused row is outlined', 'solid', $r['ring'] ?? null);
}

harness_section('1500 categories');
// Chrome's virtual time stops the clock, so the budgets run in real time through puppeteer-core.
$r = table_ui_run_live(<<<'JS'
await t.settle();
r.init = window.__initMs;
r.firstPass = window.__firstPass;
// The pass time plus the layout it causes.
var last = function () { return new Promise(function (res) { document.querySelector('.categories-app').addEventListener('cat:filtered', function (e) { var s = performance.now(); void document.body.offsetHeight; res({ms: e.detail.ms + performance.now() - s, pass: e.detail.ms}); }, {once: true}); }); };
var p = last();
await t.type('#cat-q', 'category 3.2');
r.search = (await p).ms;
r.matches = t.shown().filter(function (tr) { return !tr.classList.contains('is-context'); }).length;
await t.type('#cat-q', '');
p = last();
t.$('[data-cat-act="expand-all"]').click();
var ex = await p; r.expand = ex.ms; r.expandPass = ex.pass;
await t.settle();
r.shown = t.shown().length;
JS, categories_fixture_big());
if ($r !== null) {
    pin('no script error', array(), $r['errors'] ?? null);
    pin('Expand all shows all 1500', 1500, $r['shown'] ?? null);
    check('the search finds rows', ($r['matches'] ?? 0) > 0, (string) ($r['matches'] ?? ''));
    check('init and the first pass are under 200 ms', ($r['init'] ?? 999) + ($r['firstPass'] ?? 999) < 200, sprintf('%.1f + %.1f ms', $r['init'] ?? -1, $r['firstPass'] ?? -1));
    check('a search pass is under 50 ms', ($r['search'] ?? 999) < 50, sprintf('%.1f ms', $r['search'] ?? -1));
    // Showing 1500 rows costs about 200 ms of layout on its own, with or without the stylesheet,
    // so the budget holds the script's pass; the total is printed below.
    check('the Expand all pass is under 150 ms', ($r['expandPass'] ?? 999) < 150, sprintf('%.1f ms', $r['expandPass'] ?? -1));
    check('Expand all with its layout stays under 1500 ms', ($r['expand'] ?? 9999) < 1500, sprintf('%.1f ms', $r['expand'] ?? -1));
    printf("  timings: init %.1f ms + first pass %.1f ms, search %.1f ms, expand all %.1f ms (pass %.1f ms)\n", $r["init"] ?? -1, $r["firstPass"] ?? -1, $r["search"] ?? -1, $r["expand"] ?? -1, $r["expandPass"] ?? -1);
}

exit(harness_result());
