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
 * Categories screen at phone width (390) in headless Chrome: the row menu sheet, the meta line,
 * the toolbar order, no sideways scroll, hold-drag, and the filter, no-result and help copy.
 *
 * Usage: php tests/categories-phone-ui.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('osc_esc_html')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
}
if (!function_exists('osc_locale_text_direction')) {
    function osc_locale_text_direction()
    {
        return $GLOBALS['phone_ui_dir'] ?? 'ltr';
    }
}
class_exists('CategoryForm');
require_once __DIR__ . '/lib/categories-browser.php';

/**
 * Runs one driver body (the inside of an async function taking t) at $width in real time through
 * puppeteer-core, as a touch device. Returns null (skipped) when node or puppeteer-core is missing.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts
 *
 * @return array<string,mixed>|null
 */
function phone_ui_run(string $body, array $tree, array $opts = array(), int $width = 390): ?array
{
    $chrome = categories_browser_chrome();
    $pc = (string) getenv('PUPPETEER_CORE');
    if ($pc === '') {
        $found = array_merge(array(ABS_PATH . 'node_modules/puppeteer-core'), glob((string) getenv('HOME') . '/.npm/_npx/*/node_modules/puppeteer-core') ?: array());
        $found = array_values(array_filter($found, static fn (string $d): bool => is_file($d . '/lib/puppeteer/puppeteer-core.js')));
        $pc = (string) ($found[0] ?? '');
    }
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($pc === '' || $node === '' || !is_file($pc . '/lib/puppeteer/puppeteer-core.js')) {
        if ((string) getenv('CI') !== '') {
            check('node and puppeteer-core are available for the phone run', false, 'run npm ci');
        }
        echo "  (skipped: no node or puppeteer-core for the phone run)\n";

        return null;
    }
    $dir = sys_get_temp_dir() . '/cat-phone-ui-' . getmypid() . '-' . substr(md5($body . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $js = "window.catDriver = async function (t) {\n"
        . "var r = {};\n"
        . "var name = function (tr) { return tr.querySelector('.cat-name').textContent; };\n"
        . "var vis = function (el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; };\n"
        . "var calls = function (a) { return window.__calls.filter(function (c) { return c.action === a; }); };\n"
        . "var more = function (n) { return t.row(n).querySelector('.cat-more'); };\n"
        . "var texts = function (list) { return list.map(function (b) { return b.textContent.trim(); }); };\n"
        . $body . "\nreturn r;\n};\n";
    file_put_contents($dir . '/driver.js', $js);
    file_put_contents($dir . '/page.html', categories_browser_page($tree, $opts + array('driver' => $dir . '/driver.js')));
    file_put_contents($dir . '/run.mjs', 'const BLINK = ' . json_encode(trim(CATEGORIES_BROWSER_MOUSE)) . ";\n" . <<<'MJS'
const [pc, chrome, page, width] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',844']});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 844, hasTouch: true, isMobile: true});
    await tab.exposeFunction('oscPress', (key) => tab.keyboard.press(key));
    await tab.goto('file://' + page);
    await tab.waitForSelector('html[data-done="1"]', {timeout: 90000});
    process.stdout.write(await tab.$eval('#osc-out', (el) => el.textContent));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/run.mjs') . ' '
        . escapeshellarg($pc) . ' ' . escapeshellarg($chrome) . ' ' . escapeshellarg($dir . '/page.html') . ' ' . $width . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));
    $r = json_decode((string) base64_decode(trim($out), true), true);
    check('the phone run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));
    if (is_array($r)) {
        pin('no script error', array(), $r['errors'] ?? null);
        pin('the screen is ready', true, $r['ready'] ?? null);
    }

    return is_array($r) ? $r : array();
}

$demo  = categories_fixture_demo();
$index = categories_browser_index($demo);
$ids   = array();
foreach ($index as $id => $row) {
    $ids[(string) $row['s_name']] = $id;
}

harness_section('Row menu sheet');
$r = phone_ui_run(<<<'JS'
window.__answers.category_delete_preview = {categories: 8, listings: 30, queued: false};
var veh = t.row('Vehicles');
r.moreShown = vis(more('Vehicles'));
r.moreLabel = [more('Vehicles').getAttribute('aria-label'), more('Vehicles').getAttribute('aria-haspopup'), more('Vehicles').getAttribute('aria-describedby')];
r.textActionsHidden = !vis(veh.querySelector('.cat-actions'));
r.moreAtEnd = more('Vehicles').getBoundingClientRect().right > t.row('Vehicles').querySelector('.cat-name').getBoundingClientRect().right;
await t.click(more('Vehicles'));
var sheet = t.$('#catSheet');
r.open = sheet.open;
r.title = t.$('#catSheetTitle').textContent;
r.labelled = sheet.getAttribute('aria-labelledby');
r.items = Array.from(sheet.querySelectorAll('li')).map(function (li) {
    return li.classList.contains('cat-sheet-sep') ? '---' : li.textContent.trim();
});
r.sepRole = Array.from(sheet.querySelectorAll('.cat-sheet-sep')).map(function (li) { return li.getAttribute('role'); });
var box = sheet.getBoundingClientRect();
r.anchored = [Math.round(box.bottom) === window.innerHeight, Math.round(box.width) === window.innerWidth];
r.touch = Array.from(sheet.querySelectorAll('button')).every(function (b) { return b.getBoundingClientRect().height >= 40; });
r.delColor = getComputedStyle(sheet.querySelector('[data-cat-act="delete"]')).color !== getComputedStyle(sheet.querySelector('[data-cat-act="edit"]')).color;
sheet.querySelector('[data-cat-act="delete"]').click();
await t.settle(500);
r.afterDelete = [sheet.open, t.$('#catConfirm').open, t.$('#catConfirmTitle').textContent, t.$('#catConfirmOk').textContent];
t.$('#catConfirmCancel').click();
await t.settle(400);
r.focusAfterCancel = document.activeElement === more('Vehicles');

await t.click(more('Real estate'));
t.$('#catSheet [data-osc-dialog-close]').click();
await t.settle(300);
r.cancel = [t.$('#catSheet').open, document.activeElement === more('Real estate')];

await t.click(more('Real estate'));
await window.oscPress('Escape');
await t.settle(300);
r.esc = [t.$('#catSheet').open, document.activeElement === more('Real estate')];

var off = t.$$('tr.cat-row.is-disabled')[0];
off.querySelector('.cat-more').click();
await t.settle(300);
r.offLabel = t.$('#catSheet [data-cat-act="toggle"]').textContent;
t.$('#catSheet [data-osc-dialog-close]').click();
await t.settle(300);
await t.click(more('Real estate'));
r.onLabel = t.$('#catSheet [data-cat-act="toggle"]').textContent;
t.$('#catSheet [data-cat-act="edit"]').click();
await t.drawer();
r.edit = [t.$('#catSheet').open, t.$('#catDrawerTitle').textContent.length > 0];
JS, $demo);
if ($r !== null) {
    pin('every row has a ⋯ at the end of its name line', true, $r['moreShown'] ?? null);
    pin('named Actions, a dialog opener, described by the row name', array('Actions', 'dialog', 'cat-n-' . $ids['Vehicles']), $r['moreLabel'] ?? null);
    pin('the text actions are hidden on a phone', true, $r['textActionsHidden'] ?? null);
    pin('the ⋯ sits past the name', true, $r['moreAtEnd'] ?? null);
    pin('the ⋯ opens the sheet', true, $r['open'] ?? null);
    pin('headed by the category name', 'Vehicles', $r['title'] ?? null);
    pin('named by its heading', 'catSheetTitle', $r['labelled'] ?? null);
    pin('Edit, Add subcategory, Move to…, Disable, a rule, Delete, a rule, Cancel', array('Edit', 'Add subcategory', 'Move to…', 'Disable', '---', 'Delete', '---', 'Cancel'), $r['items'] ?? null);
    pin('the rules are presentation only', array('presentation', 'presentation'), $r['sepRole'] ?? null);
    pin('the sheet sits on the bottom edge, full width', array(true, true), $r['anchored'] ?? null);
    pin('every sheet button is at least 40 px tall', true, $r['touch'] ?? null);
    pin('Delete has its own colour', true, $r['delColor'] ?? null);
    pin('Delete closes the sheet and asks the Delete confirm', array(false, true, 'Delete Vehicles?', 'Delete 8 categories'), $r['afterDelete'] ?? null);
    pin('Cancel on the confirm returns focus to the ⋯', true, $r['focusAfterCancel'] ?? null);
    pin('Cancel on the sheet closes it and returns focus to the ⋯', array(false, true), $r['cancel'] ?? null);
    pin('Esc does the same', array(false, true), $r['esc'] ?? null);
    pin('a disabled row offers Enable', 'Enable', $r['offLabel'] ?? null);
    pin('an enabled row offers Disable', 'Disable', $r['onLabel'] ?? null);
    pin('Edit closes the sheet and opens the drawer', array(false, true), $r['edit'] ?? null);
}

harness_section('Layout at 390');
$r = phone_ui_run(<<<'JS'
var table = t.$('.cat-table');
r.width = window.innerWidth;
r.overflow = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
r.gutter = Math.round(table.getBoundingClientRect().left);
r.intro = t.$('.cat-intro') ? t.$('.cat-intro').textContent : null;
var top = function (sel) { return t.$(sel).getBoundingClientRect().top; };
r.order = [top('#cat-q'), top('#cat-bulk'), top('[data-cat-act="expand-all"]')];
r.expandOwnLine = top('[data-cat-act="expand-all"]') > t.$('#cat-bulk').getBoundingClientRect().bottom - 1;
r.vehicles = t.row('Vehicles').querySelector('.cat-meta').textContent;
r.cars = t.row('Cars') ? t.row('Cars').querySelector('.cat-meta').textContent : null;
await t.click(t.$('[data-cat-act="expand-all"]'));
r.cars = t.row('Cars').querySelector('.cat-meta').textContent;
r.barter = t.row('Barter').querySelector('.cat-meta').textContent;
r.noExpiryWord = t.$$('.cat-meta').some(function (m) { return /No expiry/.test(m.textContent); });
r.overflowOpen = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
r.moreSize = Math.round(more('Cars').getBoundingClientRect().width);
JS, $demo);
if ($r !== null) {
    pin('the page is phone width', 390, $r['width'] ?? null);
    pin('no sideways scroll', 0, $r['overflow'] ?? null);
    check('the page has a side gutter (' . var_export($r['gutter'] ?? null, true) . ' px)', ($r['gutter'] ?? 0) >= 8);
    pin('the intro line is under the heading', 'Sellers file each listing under one of these. Drag a row by its handle, or focus the handle and use the arrow keys, to change the order.', $r['intro'] ?? null);
    $o = $r['order'] ?? array(0, 0, 0);
    check('the toolbar reads search, bulk, then Expand all', $o[0] < $o[1] && $o[1] < $o[2]);
    pin('Expand all and Collapse all have a line of their own', true, $r['expandOwnLine'] ?? null);
    pin('the meta line of Vehicles', '7 subcategories · 33 listings · 60-day expiry', $r['vehicles'] ?? null);
    pin('the meta line of a leaf with an expiry', '19 listings · 45-day expiry', $r['cars'] ?? null);
    pin('and of a leaf with neither listings nor expiry', 'No listings', $r['barter'] ?? null);
    pin('no row says No expiry', false, $r['noExpiryWord'] ?? null);
    pin('no sideways scroll with every group open', 0, $r['overflowOpen'] ?? null);
    check('the ⋯ is at least a 40 px target (' . var_export($r['moreSize'] ?? null, true) . ')', ($r['moreSize'] ?? 0) >= 40);
}

harness_section('Big tree at 390');
$r = phone_ui_run(<<<'JS'
r.rows = t.$$('tr.cat-row').length;
r.overflow = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
await t.click(t.$('[data-cat-act="expand-all"]'));
r.overflowOpen = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
JS, categories_fixture_big());
if ($r !== null) {
    pin('1500 rows', 1500, $r['rows'] ?? null);
    pin('no sideways scroll', 0, $r['overflow'] ?? null);
    pin('no sideways scroll with every group open', 0, $r['overflowOpen'] ?? null);
}

harness_section('Hold-drag and the ⋯');
$r = phone_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
var g0 = t.drag(more('Cars'), {type: 'touch'});
await g0.hold(320);
await g0.by(0, 60, 6);
r.moreDrag = [!!t.$('.tree-slot'), calls('category_move').length];
await g0.drop();
await t.settle(300);
var cp = t.row('Car Parts').getBoundingClientRect();
var g = t.drag(t.row('Cars').querySelector('.cat-handle'), {type: 'touch'});
await g.hold(320);
await g.to(g.pos().x, cp.bottom - 4, 8);
await g.by(24, 0, 4);
r.tag = t.$('.tree-slot') ? t.$('.tree-slot-tag').textContent : null;
await t.wait(250);
var nm = document.createRange(); nm.selectNodeContents(t.row('Car Parts').querySelector('.cat-name'));
r.slotStep = Math.round(t.$('.tree-slot-box').getBoundingClientRect().left - nm.getClientRects()[0].left);
await g.drop();
await t.settle(500);
r.moves = calls('category_move').map(function (c) { return [c.body.id, c.body.parent_id, c.body.position]; });
r.depth = t.row('Cars').dataset.depth;
r.meta = [t.row('Vehicles').querySelector('.cat-meta').textContent, t.row('Car Parts').querySelector('.cat-meta').textContent];
JS, $demo);
if ($r !== null) {
    pin('pressing and holding the ⋯ starts no drag and moves nothing', array(false, 0), $r['moreDrag'] ?? null);
    pin('holding the handle and moving right opens Inside Car Parts', 'Inside Car Parts', $r['tag'] ?? null);
    check('the slot box starts one phone indent step past the Car Parts name (14-16 px, ' . var_export($r['slotStep'] ?? null, true) . ')', in_array($r['slotStep'] ?? null, array(14, 15, 16), true));
    pin('the drop posts category_move for Cars under Car Parts', array(array((string) $ids['Cars'], (string) $ids['Car Parts'], '2')), $r['moves'] ?? null);
    pin('Cars is one level deeper', '3', $r['depth'] ?? null);
    pin('the meta lines follow the move', array('6 subcategories · 33 listings · 60-day expiry', '3 subcategories · 23 listings · 60-day expiry'), $r['meta'] ?? null);
}

harness_section('Filter line, handle press and no results');
$r = phone_ui_run(<<<'JS'
await t.type('#cat-q', 'car');
var line = t.$('#cat-filter-line');
r.line = line.hidden ? null : line.querySelector('span').textContent;
r.showAll = line.hidden ? null : line.querySelector('button').textContent;
r.handleOff = t.$$('tr.cat-row').filter(function (tr) { return !tr.hidden; }).every(function (tr) { return tr.querySelector('.cat-handle').getAttribute('aria-disabled') === 'true'; });
r.moreHiddenOnContext = t.$$('tr.cat-row.is-context').every(function (tr) { return !vis(tr.querySelector('.cat-more')); });
await t.click(t.$$('tr.cat-row').filter(function (tr) { return !tr.hidden; })[0].querySelector('.cat-handle'));
r.toast = t.$('.osc-toast-text') ? t.$('.osc-toast-text').textContent : null;
await t.type('#cat-q', 'zzz');
var st = t.$('.cat-state');
r.none = [st.querySelector('strong').textContent, st.querySelector('.cat-state-hint').textContent, texts(t.$$('.cat-state button'))];
await t.type('#cat-q', '');
t.tab('Disabled').click();
await t.settle(300);
await t.type('#cat-q', 'zzz');
r.noneTab = t.$('.cat-state strong').textContent;
await t.type('#cat-q', '');
r.tabLine = t.$('#cat-filter-line').hidden ? null : t.$('#cat-filter-line span').textContent;
JS, $demo);
if ($r !== null) {
    check('the filter line counts the matches and names the search', preg_match('/^\d+ categories match “car”\. Their groups are shown in grey\. Clear the search and filter to reorder\.$/u', (string) ($r['line'] ?? '')) === 1, (string) ($r['line'] ?? 'hidden'));
    pin('and offers Show all', 'Show all', $r['showAll'] ?? null);
    pin('handles are marked off while filtered', true, $r['handleOff'] ?? null);
    pin('the ⋯ is hidden on grey context rows', true, $r['moreHiddenOnContext'] ?? null);
    pin('pressing a handle while filtered says what to do', 'Clear the search and status filter to reorder.', $r['toast'] ?? null);
    pin('no results: the title, a hint, Clear search and Add', array('No category matches “zzz”', 'Check the spelling, or search for part of the name.', array('Clear search', 'Add “zzz” as a category')), $r['none'] ?? null);
    pin('no results on a tab names the tab', 'No category matches “zzz” in Disabled', $r['noneTab'] ?? null);
    check('the Disabled tab line counts the disabled categories', preg_match('/^Showing \d+ disabled categories\. Their groups are shown in grey/u', (string) ($r['tabLine'] ?? '')) === 1, (string) ($r['tabLine'] ?? 'hidden'));
}

harness_section('Empty tab');
$allOn = $demo;
$walk = function (array &$rows) use (&$walk): void {
    foreach ($rows as &$row) {
        $row['b_enabled'] = 1;
        $walk($row['categories']);
    }
};
$walk($allOn);
$r = phone_ui_run(<<<'JS'
t.tab('Disabled').click();
await t.settle(300);
r.off = [t.$('.cat-state strong').textContent, t.$('.cat-state .cat-state-hint').textContent];
JS, $allOn);
if ($r !== null) {
    pin('the Disabled tab of an all-enabled tree says so', array('No disabled categories.', 'Every category is showing on your site.'), $r['off'] ?? null);
}

harness_section('Help panel keys');
$help = static function (string $dir): string {
    $GLOBALS['phone_ui_dir'] = $dir;
    require_once __DIR__ . '/lib/categories-page.php';
    categories_page_html(categories_fixture_demo());
    $fn = $GLOBALS['categories_page_head']['help'] ?? null;
    ob_start();
    if (is_callable($fn)) {
        $fn();
    }

    return (string) ob_get_clean();
};
$ltr = $help('ltr');
check('the help bullet shows the handle icon', str_contains($ltr, 'bi-grip-vertical'));
check('and the arrow keys as kbd', str_contains($ltr, '<kbd class="cat-k">↑</kbd> <kbd class="cat-k">↓</kbd>'));
check('right nests, left moves out', str_contains($ltr, '<kbd class="cat-k cat-k-in">→</kbd>') && str_contains($ltr, '<kbd class="cat-k cat-k-out">←</kbd>'));
$rtl = $help('rtl');
check('in RTL the nesting keys swap', str_contains($rtl, '<kbd class="cat-k cat-k-in">←</kbd>') && str_contains($rtl, '<kbd class="cat-k cat-k-out">→</kbd>'));

exit(harness_result());
