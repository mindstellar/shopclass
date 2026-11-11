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
 * Categories screen for keyboard and screen reader users, in headless Chrome: control names from
 * the accessibility tree, one spoken sentence per result, where focus goes after each dialog and
 * delete, Tab order, right to left, reduced motion and forced colours.
 *
 * Usage: php tests/categories-a11y-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('osc_esc_html')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
}
if (!function_exists('osc_admin_locale_text_direction')) {
    function osc_admin_locale_text_direction(): string
    {
        return $GLOBALS['a11y_ui_dir'] ?? 'ltr';
    }
}
class_exists('CategoryForm');
require_once __DIR__ . '/lib/categories-browser.php';

/**
 * Runs one driver body (the inside of an async function taking t) through puppeteer-core, which
 * gives real key presses and the accessibility tree. In the driver, ax(sel) reads {role, name,
 * description} of an element, unnamed() lists controls with no name, press(key) presses a key and
 * media([...]) emulates media features; live holds every non-empty text a live region took.
 * Returns null (skipped) when node or puppeteer-core is missing.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts categories_browser_page() options
 *
 * @return array<string,mixed>|null
 */
function a11y_ui_run(string $body, array $tree, array $opts = array(), int $width = 1440): ?array
{
    $chrome = categories_browser_chrome();
    $pc = test_puppeteer();
    if ($pc === '') {
        if ((string) getenv('CI') !== '') {
            check('node and puppeteer-core are available for the run', false, 'run npm ci');
        }
        echo "  (skipped: no node or puppeteer-core)\n";

        return null;
    }
    $GLOBALS['a11y_ui_dir'] = !empty($opts['rtl']) ? 'rtl' : 'ltr';
    $dir = sys_get_temp_dir() . '/cat-a11y-ui-' . getmypid() . '-' . substr(md5($body . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $js = "window.catDriver = async function (t) {\n"
        . "var r = {};\n"
        . "var id = function (n) { return t.row(n).dataset.id; };\n"
        . "var press = window.oscPress, ax = window.oscAx, unnamed = window.oscUnnamed, media = window.oscMedia;\n"
        . "var live = [];\n"
        . "document.querySelectorAll('[aria-live]:not([aria-live=\"off\"]), [role=\"status\"], [role=\"alert\"], [role=\"log\"]').forEach(function (n) {\n"
        . "    new MutationObserver(function () { var x = n.textContent.trim(); if (x) { live.push(x); } }).observe(n, {childList: true, characterData: true, subtree: true});\n"
        . "});\n"
        . "var focusName = function () { var a = document.activeElement; return a && a.classList.contains('cat-name') ? a.textContent : (a ? (a.id || a.className || a.tagName) : null); };\n"
        . "var esc = async function () { await press('Escape'); await t.settle(450); };\n"
        . $body . "\nreturn r;\n};\n";
    file_put_contents($dir . '/driver.js', $js);
    file_put_contents($dir . '/page.html', categories_browser_page($tree, $opts + array('driver' => $dir . '/driver.js')));
    $phone = $width < 600 ? 'true' : 'false';
    file_put_contents($dir . '/run.mjs', 'const BLINK = ' . json_encode(trim(CATEGORIES_BROWSER_MOUSE)) . ";\nconst PHONE = $phone;\n" . <<<'MJS'
const [pc, chrome, page, width] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',900'].concat(PHONE ? [] : [BLINK])});
const CONTROLS = new Set(['button', 'checkbox', 'radio', 'combobox', 'textbox', 'searchbox', 'link', 'spinbutton', 'menuitem', 'tab', 'switch', 'slider', 'listbox']);
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: PHONE ? 844 : 900, hasTouch: PHONE, isMobile: PHONE});
    await tab.exposeFunction('oscPress', async (key) => {
        const keys = key.split('+');
        const main = keys.pop();
        for (const k of keys) {
            await tab.keyboard.down(k);
        }
        await tab.keyboard.press(main);
        for (const k of keys.reverse()) {
            await tab.keyboard.up(k);
        }
    });
    await tab.exposeFunction('oscMedia', (list) => tab.emulateMediaFeatures(list));
    await tab.exposeFunction('oscAx', async (sel) => {
        const h = await tab.$(sel);
        if (!h) {
            return null;
        }
        const s = await tab.accessibility.snapshot({root: h, interestingOnly: false});
        return s ? {role: s.role, name: s.name || '', description: s.description || ''} : null;
    });
    await tab.exposeFunction('oscUnnamed', async () => {
        const out = [];
        const walk = (n) => {
            if (CONTROLS.has(n.role) && !(n.name || '').trim()) {
                out.push(n.role + (n.value !== undefined ? ':' + n.value : ''));
            }
            (n.children || []).forEach(walk);
        };
        const root = await tab.accessibility.snapshot({interestingOnly: true});
        if (root) {
            walk(root);
        }
        return out;
    });
    await tab.goto('file://' + page);
    await tab.waitForSelector('html[data-done="1"]', {timeout: 120000});
    process.stdout.write(await tab.$eval('#osc-out', (el) => el.textContent));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec('node ' . escapeshellarg($dir . '/run.mjs') . ' '
        . escapeshellarg($pc) . ' ' . escapeshellarg($chrome) . ' ' . escapeshellarg($dir . '/page.html') . ' ' . $width . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));
    $r = json_decode((string) base64_decode(trim($out), true), true);
    check('the run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));
    if (is_array($r)) {
        pin('no script error', array(), $r['errors'] ?? null);
        pin('the screen is ready', true, $r['ready'] ?? null);
    }

    return is_array($r) ? $r : array();
}

$demo  = categories_fixture_demo();
$index = categories_browser_index($demo);
$ids   = array();
foreach ($index as $cid => $row) {
    $ids[(string) $row['s_name']] = $cid;
}
$preview = array('category_delete_preview' => array('categories' => 8, 'listings' => 30, 'queued' => false));

harness_section('Every control has a name');
$r = a11y_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
r.handle = await ax('#cat-' + id('Cars') + ' .cat-handle');
r.handleTop = await ax('#cat-' + id('Vehicles') + ' .cat-handle');
r.name = await ax('#cat-n-' + id('Cars'));
r.nameTop = await ax('#cat-n-' + id('Vehicles'));
r.box = await ax('#cat-' + id('Cars') + ' .col-bulkactions input');
r.disc = await ax('#cat-' + id('Vehicles') + ' .cat-disclosure');
t.row('Cars').querySelector('.cat-actions [data-cat-act="edit"]').focus();
await t.settle(100);
r.group = await ax('#cat-' + id('Cars') + ' .cat-actions');
r.unnamed = {page: await unnamed()};
await t.click(t.act('Cars', 'edit'));
await t.drawer();
r.unnamed.drawer = await unnamed();
await esc();
await t.click(t.act('Vehicles', 'delete'));
await t.settle(400);
r.unnamed.confirm = await unnamed();
await esc();
await t.click(t.act('Cars', 'move'));
r.unnamed.move = await unnamed();
await esc();
await t.tick(t.row('Cars'));
t.$('#cat-bulk').value = 'expiry';
t.$('#cat-bulk-form').requestSubmit();
await t.settle(400);
r.unnamed.expiry = await unnamed();
await esc();
JS, $demo, array('answers' => $preview));
if ($r !== null) {
    pin('the handle reads Reorder and the name', array('button', 'Reorder Cars'), array($r['handle']['role'] ?? null, $r['handle']['name'] ?? null));
    check('its description says which group it is in, then the keys', str_starts_with((string) ($r['handle']['description'] ?? ''), 'Inside Vehicles Use the arrow keys'), (string) ($r['handle']['description'] ?? ''));
    pin('a top-level handle has no group in its description', 'Reorder Vehicles', $r['handleTop']['name'] ?? null);
    check('and starts with the keys', str_starts_with((string) ($r['handleTop']['description'] ?? ''), 'Use the arrow keys'), (string) ($r['handleTop']['description'] ?? ''));
    pin('the name button is described as inside its parent', array('Cars', 'Inside Vehicles'), array($r['name']['name'] ?? null, $r['name']['description'] ?? null));
    pin('a top-level name has no description', '', $r['nameTop']['description'] ?? null);
    pin('the checkbox is named by the category', 'Cars', $r['box']['name'] ?? null);
    pin('the disclosure says what it does and for which row', array('Hide subcategories', 'Vehicles'), array($r['disc']['name'] ?? null, $r['disc']['description'] ?? null));
    pin('focus in the row actions names them as the row\'s group', array('group', 'Cars'), array($r['group']['role'] ?? null, $r['group']['name'] ?? null));
    pin('no unnamed control on the page, in the drawer, the confirm, Move to… or Set expiry', array('page' => array(), 'drawer' => array(), 'confirm' => array(), 'move' => array(), 'expiry' => array()), $r['unnamed'] ?? null);
}

harness_section('Each result is spoken once');
$r = a11y_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
await t.click(t.row('For sale').querySelector('.cat-disclosure'));
live.length = 0;
t.row('Cars').querySelector('.cat-handle').focus();
await press('ArrowDown');
await t.settle(700);
r.keyMove = live.slice();
live.length = 0;
await t.click(t.act('Barter', 'toggle'));
await t.settle(500);
r.disable = live.slice();
t.tab('Enabled').click();
await t.settle(500);
live.length = 0;
var art = t.act('Art - Collectibles', 'toggle');
art.focus();
await t.click(art);
await t.settle(600);
r.tabDisable = live.slice();
r.tabFocus = focusName();
t.tab('All').click();
await t.settle(500);
window.__answers.category_delete_preview = {categories: 1, listings: 0, queued: false};
live.length = 0;
var del = t.act('Electronics', 'delete');
del.focus();
await t.click(del);
await t.settle(400);
t.$('#catConfirmOk').click();
await t.settle(600);
r.del = live.slice();
r.delFocus = focusName();
JS, $demo);
if ($r !== null) {
    pin('a key move is spoken once', array('Cars moved down. Now 2 of 7 in Vehicles.'), $r['keyMove'] ?? null);
    pin('a disable is spoken once', array('Disabled Barter.'), $r['disable'] ?? null);
    pin('on the Enabled tab the filter line does not talk over the result', array('Disabled Art - Collectibles.'), $r['tabDisable'] ?? null);
    pin('the row leaves the tab and focus goes to the next row\'s name', 'Books - Magazines', $r['tabFocus'] ?? null);
    pin('a delete is spoken once', array('Deleted Electronics.'), $r['del'] ?? null);
    pin('focus goes to the next row\'s name', 'For Babies - Infants', $r['delFocus'] ?? null);
}

harness_section('The toast is not a live region, and Esc closes it');
$r = a11y_ui_run(<<<'JS'
await t.click(t.act('Barter', 'toggle'));
await t.settle(500);
var box = t.$('.osc-toast');
r.roles = box ? [box.hidden, box.getAttribute('role'), box.getAttribute('aria-live'), box.querySelectorAll('[role], [aria-live]').length] : null;
var opener = t.row('Vehicles').querySelector('.cat-name');
opener.focus();
t.$('.osc-toast-close').focus();
await esc();
r.esc = [t.$('.osc-toast').hidden, document.activeElement === opener];
JS, $demo);
if ($r !== null) {
    pin('the toast shows but carries no role or aria-live, so the page\'s own line speaks alone', array(false, null, null, 0), $r['roles'] ?? null);
    pin('Esc on the toast closes it and focus goes back to where it was', array(true, true), $r['esc'] ?? null);
}

harness_section('Focus comes back');
$r = a11y_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
var edit = t.act('Cars', 'edit');
edit.focus();
await t.click(edit);
await t.drawer();
r.drawerOpen = t.$('#catDrawer').contains(document.activeElement);
r.trap = true;
for (var i = 0; i < 24; i++) {
    await press(i % 5 === 4 ? 'Shift+Tab' : 'Tab');
    r.trap = r.trap && t.$('#catDrawer').contains(document.activeElement);
}
r.modal = t.$('#catDrawer').getAttribute('aria-modal');
await esc();
r.drawer = document.activeElement === t.act('Cars', 'edit');

var mv = t.act('Cars', 'move');
mv.focus();
await t.click(mv);
r.moveFocus = document.activeElement.id;
t.$('#cat-move-parent').value = '0';
t.$('#cat-move-parent').dispatchEvent(new Event('change', {bubbles: true}));
t.$('#catMoveForm').requestSubmit();
await t.settle(700);
r.move = [t.row('Cars').dataset.depth, document.activeElement === t.act('Cars', 'move') || document.activeElement === t.row('Cars').querySelector('.cat-name')];

var off = t.act('Vehicles', 'toggle');
off.focus();
await t.click(off);
await t.settle(300);
r.confirmFocus = document.activeElement.id;
t.$('#catConfirmCancel').click();
await t.settle(300);
r.confirm = document.activeElement === t.act('Vehicles', 'toggle');

await t.tick(t.row('Motorcycles'));
t.$('#cat-bulk').value = 'expiry';
t.$('#cat-bulk').focus();
t.$('#cat-bulk-form').requestSubmit();
await t.settle(400);
await esc();
r.expiry = document.activeElement.id;
t.$('[data-cat-act="clear-selection"]').click();

await t.click(t.$('[data-cat-act="collapse-all"]'));
window.__answers.category_delete_preview = {categories: 22, listings: 4, queued: false};
var last = t.shown().pop();
r.lastName = last.querySelector('.cat-name').textContent;
var dl = last.querySelector('[data-cat-act="delete"]');
dl.focus();
await t.click(dl);
await t.settle(400);
t.$('#catConfirmOk').click();
await t.settle(600);
r.lastDelete = document.activeElement.id;

await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
t.row('Car Parts').querySelector('.col-bulkactions input').focus();
r.order = [];
for (var k = 0; k < 9; k++) {
    await press('Tab');
    var a = document.activeElement;
    var tr = a.closest('tr.cat-row');
    r.order.push((tr ? tr.querySelector('.cat-name').textContent + ':' : '') + (a.dataset.catAct || a.className || a.type));
    if (a.dataset.catAct === 'addsub') {
        await t.settle(300);
        r.actionsShown = getComputedStyle(tr.querySelector('.cat-actions')).opacity;
    }
}
JS, $demo, array('answers' => $preview));
if ($r !== null) {
    pin('the drawer takes focus', true, $r['drawerOpen'] ?? null);
    pin('Tab and Shift+Tab stay inside the drawer', true, $r['trap'] ?? null);
    pin('the drawer is modal', 'true', $r['modal'] ?? null);
    pin('Esc closes it and focus is back on Edit', true, $r['drawer'] ?? null);
    pin('Move to… opens on the parent list', 'cat-move-parent', $r['moveFocus'] ?? null);
    pin('after the move focus is back on the moved row', array('1', true), $r['move'] ?? null);
    pin('a confirm opens on Cancel', 'catConfirmCancel', $r['confirmFocus'] ?? null);
    pin('Cancel puts focus back on Disable', true, $r['confirm'] ?? null);
    pin('closing Set expiry puts focus on the bulk select', 'cat-bulk', $r['expiry'] ?? null);
    pin('the last row is Cars, moved to the end of the top level', 'Cars', $r['lastName'] ?? null);
    pin('deleting the last row puts focus in the search box', 'cat-q', $r['lastDelete'] ?? null);
    pin('Tab runs through a row in order, actions included, then the next row', array(
        'Car Parts:cat-handle', 'Car Parts:cat-disclosure', 'Car Parts:edit', 'Car Parts:edit', 'Car Parts:addsub',
        'Car Parts:move', 'Car Parts:toggle', 'Car Parts:delete', 'Motorcycles:checkbox',
    ), $r['order'] ?? null);
    pin('the hover-only actions show while one has focus', '1', $r['actionsShown'] ?? null);
}

harness_section('Reorder is off while filtered, and says why');
$r = a11y_ui_run(<<<'JS'
await t.type('#cat-q', 'car');
var h = t.shown().find(function (tr) { return !tr.classList.contains('is-context'); }).querySelector('.cat-handle');
r.off = h.getAttribute('aria-disabled');
r.why = (await ax('#cat-' + h.closest('tr').dataset.id + ' .cat-handle')).description;
await t.type('#cat-q', '');
r.back = (await ax('#cat-' + id('Vehicles') + ' .cat-handle')).description;
JS, $demo);
if ($r !== null) {
    pin('a handle is marked off', 'true', $r['off'] ?? null);
    check('and its description gives the reason', str_contains((string) ($r['why'] ?? ''), 'Clear the search and status filter to reorder.'), (string) ($r['why'] ?? ''));
    check('clearing the search brings the keys back', str_starts_with((string) ($r['back'] ?? ''), 'Use the arrow keys'), (string) ($r['back'] ?? ''));
}

harness_section('Right to left');
$r = a11y_ui_run(<<<'JS'
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
var veh = t.row('Vehicles').querySelector('.cat-name').getBoundingClientRect();
var cp = t.row('Car Parts').querySelector('.cat-name').getBoundingClientRect();
r.indent = Math.round(veh.right - cp.right);
r.chevron = getComputedStyle(t.row('Car Parts').querySelector('.cat-disclosure i')).transform;
t.row('Cars').querySelector('.cat-handle').focus();
live.length = 0;
await press('ArrowRight');
await t.settle(700);
r.out = window.__calls.filter(function (c) { return c.action === 'category_move'; }).map(function (c) { return [c.body.id, c.body.parent_id]; });
r.outSaid = live.slice();
r.focus = document.activeElement === t.row('Cars').querySelector('.cat-handle');
t.row('Motorcycles').querySelector('.cat-handle').focus();
await press('ArrowLeft');
await t.settle(700);
r.inParent = t.row('Motorcycles').dataset.parent;
await t.click(t.act('Vehicles', 'delete'));
await t.settle(400);
var fact = t.$('#catConfirmFacts li');
r.factIconRight = fact.querySelector('i').getBoundingClientRect().left > fact.querySelector('span').getBoundingClientRect().left;
JS, $demo, array('rtl' => true, 'answers' => $preview));
if ($r !== null) {
    check('a subcategory steps in from the right (' . var_export($r['indent'] ?? null, true) . ' px)', ($r['indent'] ?? 0) >= 20);
    check('a closed chevron points left', str_starts_with((string) ($r['chevron'] ?? ''), 'matrix(-1'), (string) ($r['chevron'] ?? ''));
    pin('→ moves Cars out of Vehicles', array(array((string) $ids['Cars'], '0')), $r['out'] ?? null);
    check('and says so once', count($r['outSaid'] ?? array()) === 1 && str_starts_with((string) ($r['outSaid'][0] ?? ''), 'Cars moved out of Vehicles.'), json_encode($r['outSaid'] ?? null));
    pin('focus stays on its handle', true, $r['focus'] ?? null);
    pin('← puts Motorcycles under the row above', (string) $ids['Car Parts'], $r['inParent'] ?? null);
    pin('dialog fact icons sit on the right', true, $r['factIconRight'] ?? null);
}

harness_section('Right to left on a phone');
$r = a11y_ui_run(<<<'JS'
var line = function (el) { var g = document.createRange(); g.selectNodeContents(el); return g.getClientRects()[0]; };
var nm = t.row('For sale').querySelector('.cat-name');
r.nameAlign = Math.round(nm.getBoundingClientRect().right - line(nm).right);
await t.click(t.row('Vehicles').querySelector('.cat-more'));
var title = line(t.$('#catSheetTitle'));
var edit = line(t.$('#catSheet [data-cat-act="edit"]'));
r.sheetAlign = Math.round(Math.abs(title.right - edit.right));
r.overflow = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
JS, $demo, array('rtl' => true), 390);
if ($r !== null) {
    check('a Latin name lines up on the right of its row (' . var_export($r['nameAlign'] ?? null, true) . ')', ($r['nameAlign'] ?? 99) <= 8);
    check('the sheet heading lines up with its buttons (' . var_export($r['sheetAlign'] ?? null, true) . ')', ($r['sheetAlign'] ?? 99) <= 3);
    pin('no sideways scroll', 0, $r['overflow'] ?? null);
}

harness_section('Reduced motion and forced colours');
$r = a11y_ui_run(<<<'JS'
await media([{name: 'prefers-reduced-motion', value: 'reduce'}]);
var tr = t.row('Vehicles');
tr.classList.add('is-flash');
r.flash = getComputedStyle(tr.querySelector('.col-name')).animationName;
tr.classList.remove('is-flash');
await media([{name: 'forced-colors', value: 'active'}]);
r.forced = window.matchMedia('(forced-colors: active)').matches;
var off = t.$('tr.cat-row.is-disabled .cat-name');
var on = t.$('tr.cat-row:not(.is-disabled) .cat-name');
r.offDiffers = getComputedStyle(off).color !== getComputedStyle(on).color;
await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
var gap = t.row('Cars').querySelector('.cat-actions-gap');
r.gap = getComputedStyle(gap).backgroundColor !== getComputedStyle(t.row('Cars').querySelector('.col-name')).backgroundColor;
JS, $demo);
if ($r !== null) {
    pin('a new row keeps a still tint instead of the wash', 'none', $r['flash'] ?? null);
    pin('forced colours apply', true, $r['forced'] ?? null);
    pin('a disabled name still reads differently', true, $r['offDiffers'] ?? null);
    pin('the line before Delete still shows', true, $r['gap'] ?? null);
}

exit(harness_result());
