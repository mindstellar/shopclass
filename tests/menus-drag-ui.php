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
 * Appearance > Menus in headless Chrome: the flat list, dragging left and right to change
 * nesting, the "Too deep" refusal, Esc, the handle keys, the indent buttons, the unsaved marker
 * and the saved JSON.
 *
 * Usage: php tests/menus-drag-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/menus-browser.php';

/**
 * Runs a driver body (inside an async function taking t, with r the reading) on the Menus screen.
 *
 * @param array<string,mixed> $opts dark, rtl, width, editor
 *
 * @return array<string,mixed>
 */
function menus_ui_run(string $body, array $opts = array()): array
{
    $file = tempnam(sys_get_temp_dir(), 'menus-drv');
    file_put_contents($file, "window.menuDriver = async function (t) {\nvar r = {};\n"
        . "var status = function () { return t.\$('#menu-status').textContent; };\n"
        . "var tag = function () { var s = t.\$('.tree-slot-tag'); return s ? s.textContent : null; };\n"
        . "var slotDepth = function () { var s = t.\$('.tree-slot'); return s ? s.style.getPropertyValue('--tree-d') : null; };\n"
        . "var top = function (label) { var b = t.item(label).getBoundingClientRect(); return b; };\n"
        . $body . "\nreturn r;\n};\n");
    $r = menus_browser_run($file, null, $opts);
    @unlink($file);
    pin('no script error', array(), $r['errors'] ?? null);

    return $r;
}

/**
 * Wraps a driver body as a script file for menus_browser_run_live().
 */
function menus_ui_driver(string $body): string
{
    $file = tempnam(sys_get_temp_dir(), 'menus-drv');
    file_put_contents($file, "window.menuDriver = async function (t) {\nvar r = {};\n" . $body . "\nreturn r;\n};\n");

    return $file;
}

$start = array('Home:0', 'Vehicles:0', 'Cars:1', 'Car Parts:2', 'Pages:0', 'About:1', 'Sale:0');

echo "A flat list\n";
$r = menus_ui_run(<<<'JS'
r.lists = t.$$('#menu-items ul, #menu-items ol, .menu-children').length;
r.guideVar = t.item('Car Parts').style.getPropertyValue('--tree-d');
r.saved = t.save().map(function (i) { return i.children.length; });
JS);
pin('the items draw as one flat list in the saved order', $start, $r['shape'] ?? null);
pin('there are no nested lists', 0, $r['lists'] ?? null);
pin('a row carries its depth for the indent', '2', $r['guideVar'] ?? null);
pin('saving still nests the list', array(0, 1, 1, 0), $r['saved'] ?? null);

echo "Drag left to un-nest\n";
$r = menus_ui_run(<<<'JS'
var g = t.drag(t.grip('Car Parts'));
await g.by(-30, 0, 4);
r.one = [tag(), slotDepth()];
await g.by(-40, 0, 4);
r.two = [tag(), slotDepth()];
await g.drop();
r.status = status();
r.saved = t.save().map(function (i) { return i.label ? Object.keys(i.children).length : i.children.length; });
r.top = t.save().map(function (i) { return i.type + ':' + (i.ref || i.url); });
JS);
pin('one step left: inside Vehicles', array('Inside Vehicles', '1'), $r['one'] ?? null);
pin('two steps left: top level', array('Top level', '0'), $r['two'] ?? null);
pin('Car Parts is now a top-level item', array('Home:0', 'Vehicles:0', 'Cars:1', 'Car Parts:0', 'Pages:0', 'About:1', 'Sale:0'), $r['shape'] ?? null);
pin('the saved JSON has it at the top', array('builtin:home', 'category:2', 'category:4', 'link:/pages', 'link:https://example.test/sale'), $r['top'] ?? null);
pin('the page is marked unsaved', 'Unsaved changes', $r['status'] ?? null);

echo "Drag right, into another item\n";
$r = menus_ui_run(<<<'JS'
var home = top('Home');
var g = t.drag(t.grip('Cars'));
await g.to(home.left + 60, home.bottom + 2, 8);
r.mid = [tag(), t.$('.tree-ghost-more') ? t.$('.tree-ghost-more').textContent : null];
await g.drop();
r.status = status();
JS);
pin('the slot says Inside Home and the ghost counts the item under Cars', array('Inside Home', '+ 1 item under it'), $r['mid'] ?? null);
pin('Cars and Car Parts moved under Home', array('Home:0', 'Cars:1', 'Car Parts:2', 'Vehicles:0', 'Pages:0', 'About:1', 'Sale:0'), $r['shape'] ?? null);
pin('unsaved after a drag right', 'Unsaved changes', $r['status'] ?? null);

echo "Nothing above Home\n";
$r = menus_ui_run(<<<'JS'
var g = t.drag(t.grip('Home'));
await g.by(70, 0, 6);
r.tag = tag();
r.depth = slotDepth();
await g.drop();
r.status = status();
JS);
pin('Home cannot go right: top level', array('Top level', '0'), array($r['tag'] ?? null, $r['depth'] ?? null));
pin('nothing changed', $start, $r['shape'] ?? null);
pin('and the page stays saved', '', $r['status'] ?? null);

echo "Too deep\n";
$r = menus_ui_run(<<<'JS'
var home = top('Home');
var g = t.drag(t.grip('Vehicles'));
await g.to(home.left + 60, home.bottom + 2, 8);
r.tag = tag();
r.refused = !!t.$('.tree-slot.is-refused');
await g.drop();
r.say = t.$('#menu-announce').textContent;
JS);
pin('a block that would pass three levels is refused', array('A menu can nest items at most 3 levels deep.', true), array($r['tag'] ?? null, $r['refused'] ?? null));
pin('the drop changes nothing', $start, $r['shape'] ?? null);
pin('and it is announced', 'A menu can nest items at most 3 levels deep.', $r['say'] ?? null);

echo "Esc\n";
$r = menus_ui_run(<<<'JS'
var g = t.drag(t.grip('Cars'));
await g.by(0, -60, 6);
r.during = [!!t.$('.tree-slot'), t.item('Car Parts').hidden];
await g.escape();
await t.settle(300);
r.after = [!!t.$('.tree-slot'), t.item('Car Parts').hidden, t.item('Cars').hidden];
r.status = status();
JS);
pin('mid-drag: slot open, block hidden', array(true, true), $r['during'] ?? null);
pin('Esc closes the slot and shows the rows', array(false, false, false), $r['after'] ?? null);
pin('the order is unchanged', $start, $r['shape'] ?? null);
pin('and nothing is unsaved', '', $r['status'] ?? null);

echo "Handle keys\n";
$r = menus_ui_run(<<<'JS'
r.log = [];
async function press(label, key) {
    r.log.push([label, key, await t.key(t.grip(label), key), t.shape().join(' ')]);
}
await press('Car Parts', 'left');
await press('Pages', 'right');
await press('Sale', 'up');
await press('Home', 'down');
await press('Home', 'left');
r.say = t.$('#menu-announce').textContent;
r.focus = document.activeElement && document.activeElement.className;
r.status = status();
JS);
$log = $r['log'] ?? array();
pin('left moves Car Parts out of Cars', 'Home:0 Vehicles:0 Cars:1 Car Parts:1 Pages:0 About:1 Sale:0', $log[0][3] ?? null);
pin('right puts Pages (and About) under Vehicles', 'Home:0 Vehicles:0 Cars:1 Car Parts:1 Pages:1 About:2 Sale:0', $log[1][3] ?? null);
pin('up swaps Sale with the sibling block above', 'Home:0 Sale:0 Vehicles:0 Cars:1 Car Parts:1 Pages:1 About:2', $log[2][3] ?? null);
pin('down swaps Home with the sibling below', 'Sale:0 Home:0 Vehicles:0 Cars:1 Car Parts:1 Pages:1 About:2', $log[3][3] ?? null);
pin('left at the top level is refused and announced', array(false, 'Home is already at the top level.'), array(isset($log[4]) && $log[4][3] !== $log[3][3], $r['say'] ?? null));
pin('focus stays on a handle', 'chip-grip', $r['focus'] ?? null);
pin('keys mark the page unsaved', 'Unsaved changes', $r['status'] ?? null);

echo "Right to left\n";
$r = menus_ui_run(<<<'JS'
r.log = [];
await t.key(t.grip('Car Parts'), 'right');
r.log.push(t.shape().join(' '));
await t.key(t.grip('Car Parts'), 'left');
r.log.push(t.shape().join(' '));
JS, array('rtl' => true));
pin('in a right-to-left page the arrows swap', array('Home:0 Vehicles:0 Cars:1 Car Parts:1 Pages:0 About:1 Sale:0', 'Home:0 Vehicles:0 Cars:1 Car Parts:2 Pages:0 About:1 Sale:0'), $r['log'] ?? null);

echo "Indent and outdent buttons\n";
$r = menus_ui_run(<<<'JS'
t.$('[data-act="indent"]', t.item('Pages')).click();
await t.settle(100);
r.a = t.shape().join(' ');
r.indentOff = t.$('[data-act="indent"]', t.item('Home')).disabled;
r.outdentOff = t.$('[data-act="outdent"]', t.item('Home')).disabled;
t.$('[data-act="outdent"]', t.item('Cars')).click();
await t.settle(100);
r.b = t.shape().join(' ');
r.status = status();
JS);
pin('indent puts Pages and its child under Vehicles', 'Home:0 Vehicles:0 Cars:1 Car Parts:2 Pages:1 About:2 Sale:0', $r['a'] ?? null);
pin('the first item cannot indent or outdent', array(true, true), array($r['indentOff'] ?? null, $r['outdentOff'] ?? null));
pin('outdent moves Cars and Car Parts out one level', 'Home:0 Vehicles:0 Pages:1 About:2 Cars:0 Car Parts:1 Sale:0', $r['b'] ?? null);
pin('the buttons mark the page unsaved', 'Unsaved changes', $r['status'] ?? null);

echo "Items beyond the place's depth\n";
$place = array('label' => 'Header', 'depth' => 2, 'hint' => '');
$r = menus_ui_run(<<<'JS'
r.before = [t.$('#menu-place-warn').hidden, t.$('#menu-place-warn').textContent];
var g = t.drag(t.grip('Car Parts'));
await g.by(-70, 0, 6);
await g.drop();
r.after = [t.$('#menu-place-warn').hidden, t.$('#menu-place-warn').textContent];
JS, array('editor' => array('place' => $place, 'places' => array('header' => $place))));
pin('one item is deeper than the place shows', array(false, '1 item is deeper than your theme shows here and will not show.'), $r['before'] ?? null);
pin('after un-nesting it the warning goes', array(true, ''), $r['after'] ?? null);

echo "Screen reader: one announcement per move\n";
$r = menus_ui_run(<<<'JS'
r.log = [];
t.$$('[role=status],[aria-live]').forEach(function (el) {
    new MutationObserver(function () { if (el.textContent !== '') { r.log.push((el.id || el.className) + '=' + el.textContent); } })
        .observe(el, {childList: true, characterData: true, subtree: true});
});
async function mark(fn) { var from = r.log.length; await fn(); await t.settle(300); return r.log.slice(from); }
r.drag = await mark(async function () { var g = t.drag(t.grip('Car Parts')); await g.by(-30, 0, 4); await g.drop(); });
r.focusDrag = document.activeElement.getAttribute('aria-label');
r.key = await mark(function () { return t.key(t.grip('Pages'), 'right'); });
r.btn = await mark(function () { t.$('[data-act="outdent"]', t.item('Cars')).click(); });
r.names = [t.grip('Cars').getAttribute('aria-label'), t.$('[data-act="outdent"]', t.item('Cars')).getAttribute('aria-label'), t.$('[data-act="indent"]', t.item('Cars')).getAttribute('aria-label')];
r.statusLive = [t.$('#menu-status').getAttribute('role'), t.$('#menu-status').getAttribute('aria-live')];
JS);
pin('a key move says its position once', array('menu-announce=Position 3 of 3, level 2'), $r['key'] ?? null);
pin('an outdent button says its position once', array('menu-announce=Position 3 of 4, level 1'), $r['btn'] ?? null);
pin('a pointer drag says its position once', array('menu-announce=Position 2 of 2, level 2'), $r['drag'] ?? null);
check('focus stays on the moved item\'s handle after a drag', str_contains((string) ($r['focusDrag'] ?? ''), 'Car Parts'), (string) ($r['focusDrag'] ?? ''));
pin('the handle and buttons name the item', array('Move Cars with the arrow keys', 'Move Cars out one level', 'Move Cars under the item above'), $r['names'] ?? null);
pin('the Unsaved changes marker is not a live region', array(null, null), $r['statusLive'] ?? null);

echo "Removing the last item\n";
$r = menus_ui_run(<<<'JS'
t.$('[data-act="remove"]', t.item('Sale')).click();
await t.settle(200);
r.afterLast = document.activeElement.getAttribute('aria-label');
for (var n = 0; n < 6; n++) { t.$('[data-act="remove"]', t.$('li.menu-item')).click(); await t.settle(100); }
r.afterAll = document.activeElement.getAttribute('data-menu-add');
r.emptyShown = !t.$('#menu-empty').hidden;
JS);
check('removing the last row focuses the one before', str_contains((string) ($r['afterLast'] ?? ''), 'About'), (string) ($r['afterLast'] ?? ''));
pin('removing every item leaves focus on an Add button', 'category', $r['afterAll'] ?? null);
pin('and shows the empty line', true, $r['emptyShown'] ?? null);

echo "Right to left: icons and guide lines\n";
$r = menus_ui_run(<<<'JS'
var bi = t.$('[data-act="outdent"] .bi', t.item('Cars'));
r.flip = getComputedStyle(bi).transform;
var before = getComputedStyle(t.item('Car Parts'), '::before');
r.guide = [before.insetInlineStart, before.backgroundImage.indexOf('to left') > -1];
var li = t.item('Car Parts').getBoundingClientRect(), row = t.item('Car Parts').querySelector('.field-chip-row').getBoundingClientRect();
r.rowEdge = Math.round(li.right - row.right);
r.opened = Math.round(t.item('Home').querySelector('.field-chip-row').getBoundingClientRect().right - row.right);
JS, array('rtl' => true));
pin('the outdent arrow is mirrored', 'matrix(-1, 0, 0, 1, 0, 0)', $r['flip'] ?? null);
pin('guide lines start on the right', array('0px', true), $r['guide'] ?? null);
check('a deeper row steps in from the right', ($r['opened'] ?? 0) > 20, (string) ($r['opened'] ?? ''));

echo "Phone (390 wide, real time)\n";
$long = array(
    array('type' => 'link', 'ref' => 0, 'url' => '/a', 'label' => array('en_US' => 'A rather long first level menu label'), 'children' => array(
        array('type' => 'link', 'ref' => 0, 'url' => '/b', 'label' => array('en_US' => 'A rather long second level menu label'), 'children' => array(
            array('type' => 'link', 'ref' => 0, 'url' => '/c', 'label' => array('en_US' => 'A rather long third level menu label')),
        )),
    )),
);
$r = menus_browser_run_live(menus_ui_driver(<<<'JS'
var de = document.documentElement;
r.scroll = [de.scrollWidth, de.clientWidth];
var deep = t.item('A rather long third level menu label');
r.nameWidth = Math.round(deep.querySelector('.chip-name').getBoundingClientRect().width);
r.touchSizes = t.$$('.chip-grip, .chip-btn', deep).map(function (b) { var q = b.getBoundingClientRect(); return Math.min(q.width, q.height) >= 40; });
JS), $long, array('width' => 390, 'touch' => true));
$r = $r ?? array();
check('no sideways scroll at 390', ($r['scroll'][0] ?? 999) <= ($r['scroll'][1] ?? 0), json_encode($r['scroll'] ?? null));
check('the deepest name keeps room (12 characters or more)', ($r['nameWidth'] ?? 0) >= 100, (string) ($r['nameWidth'] ?? ''));
pin('handle and buttons are 40 px or more', array(true, true, true, true, true), $r['touchSizes'] ?? null);

$r = menus_browser_run_live(menus_ui_driver(<<<'JS'
var g = t.grip('Car Parts');
g.scrollIntoView({block: 'center'});
await t.settle(100);
var q = g.getBoundingClientRect(), x = q.left + q.width / 2, y = q.top + q.height / 2;
await window.oscTouch([['start', x, y], ['wait', 120, 0], ['move', x - 5, y, 0]]);
r.early = t.shape().join(' ') + '|' + !!t.$('.tree-slot');
await window.oscTouch([['end', 0, 0]]);
await window.oscTouch([['start', x, y], ['wait', 320, 0], ['move', x - 10, y], ['move', x - 25, y], ['wait', 80, 0]]);
r.mid = !!t.$('.tree-slot');
await window.oscTouch([['end', 0, 0]]);
await t.settle(200);
r.after = t.shape().join(' ');
JS), null, array('width' => 390, 'touch' => true));
pin('a quick touch on the handle does not start a drag', 'Home:0 Vehicles:0 Cars:1 Car Parts:2 Pages:0 About:1 Sale:0|false', $r['early'] ?? null);
pin('holding then dragging left shows the slot', true, $r['mid'] ?? null);
pin('and dropping un-nests the item', 'Home:0 Vehicles:0 Cars:1 Car Parts:1 Pages:0 About:1 Sale:0', $r['after'] ?? null);

echo "Dialog returns focus\n";
$r = menus_ui_run(<<<'JS'
var opener = t.$('.menu-delete');
opener.focus();
opener.click();
await t.settle(200);
r.open = !!t.$('#menuDeleteDialog[open]');
t.$('#menuDeleteDialog [data-osc-dialog-close]').click();
await t.settle(200);
r.back = document.activeElement === opener;
JS);
pin('the confirm opens', true, $r['open'] ?? null);
pin('closing it puts focus back on its button', true, $r['back'] ?? null);

exit(harness_result());
