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

exit(harness_result());
