<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The admin sidebar in Chrome: sections open on their own and the clicked row stays put, one
 * pill marks the current page, hover and pressed use the sidebar tokens, the rail collapses
 * with the icons still, panel and chevron move together and turn around on a second click,
 * reduced motion is instant, and the phone menu never paints in the page.
 * Animations are read on a paused clock (t.seek), not on the wall clock.
 *
 * Usage: php tests/admin-sidebar-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/sidebar-browser.php';

harness_section('markup and styles');
$html = sidebar_menu_html('users', 'users_0');
check('sections are not an accordion', !str_contains($html, 'data-osc-parent'));
pin('a page load opens only the current section', array('users-submenu'), (function () use ($html): array {
    preg_match_all('~<ul class="sidebar-submenu collapse list-unstyled show" id="([^"]+)"~', $html, $m);

    return $m[1];
})());
check('its chevron says it is open', (bool) preg_match('~class="nav-chevron" data-osc-collapse="#users-submenu" aria-expanded="true"~', $html));
$scss = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/scss/sidebar.scss');
check('no scale on hover in sidebar.scss', !preg_match('~scale\(1\.~', $scss));
check('no raw hex or Sass colour in a sidebar transition', !preg_match('~transition:[^;]*(#[0-9a-f]{3}|\$[a-z])~i', $scss));

harness_section('sections in the expanded menu');
$r = sidebar_run(<<<'JS'
const users = t.row('Users');
const at = t.box(users);
await t.click(t.chevron('Users'));
await t.finish();
r.rowMoved = Math.abs(t.box(users).y - at.y);
r.underPointer = document.elementFromPoint(at.cx, at.cy).closest('.nav-item') === t.item('Users');
r.openAfterUsers = t.$$('.sidebar-submenu.show').map((p) => p.id);
await t.click(t.chevron('Messages'));
await t.finish();
await t.click(t.chevron('Users'));
await t.finish();
r.openAfterClose = t.$$('.sidebar-submenu.show').map((p) => p.id);
r.expanded = ['Listings', 'Users', 'Messages'].map((l) => t.chevron(l).getAttribute('aria-expanded'));

// Panel and chevron: same duration and easing, and the same progress at the same moment.
await t.click(t.chevron('Appearance'));
const panel = t.panel('Appearance'), chev = t.chevron('Appearance');
const pa = panel.getAnimations()[0];
const ca = chev.getAnimations().find((a) => a.transitionProperty === 'transform');
r.panelTiming = pa ? [pa.effect.getTiming().duration, pa.effect.getComputedTiming().easing] : null;
r.chevronTiming = ca ? [ca.effect.getTiming().duration, ca.effect.getComputedTiming().easing] : null;
t.seek(100);
const m = new DOMMatrix(t.css(chev, 'transform'));
const turn = (Math.atan2(m.b, m.a) * 180 / Math.PI + 90) / 90;
r.stepGap = Math.abs(turn - t.box(panel).h / panel.scrollHeight);
await t.finish();

// A second click halfway turns the panel around from where it is.
const pp = t.panel('Plugins');
await t.click(t.chevron('Plugins'));
t.seek(100);
const half = t.box(pp).h;
await t.click(t.chevron('Plugins'));
r.secondClickTaken = t.chevron('Plugins').getAttribute('aria-expanded');
t.seek(0);
r.reverseStart = [half, t.box(pp).h];
await t.finish();
r.reversedShut = [pp.classList.contains('show'), t.box(pp).h];

// Enter and Space on a chevron.
t.chevron('Tools').focus();
await pressKey('Enter'); await t.finish();
r.enter = t.panel('Tools').classList.contains('show');
await pressKey(' '); await t.finish();
r.space = t.panel('Tools').classList.contains('show');
JS);
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    check('the clicked row does not move when its section opens', ($r['rowMoved'] ?? 99) <= 1, 'moved ' . ($r['rowMoved'] ?? '?') . 'px');
    check('the pointer is still on the clicked row', $r['underPointer'] ?? false);
    pin('opening Users leaves Listings open', array('items-submenu', 'users-submenu'), $r['openAfterUsers'] ?? null);
    pin('closing Users leaves the others open', array('items-submenu', 'messages-submenu'), $r['openAfterClose'] ?? null);
    pin('each chevron tells its own state', array('true', 'false', 'true'), $r['expanded'] ?? null);
    pin('the panel takes 200ms on the shared easing', array(200, 'cubic-bezier(0.2, 0, 0, 1)'), $r['panelTiming'] ?? null);
    pin('the chevron takes the same', $r['panelTiming'] ?? 'none', $r['chevronTiming'] ?? null);
    check('chevron and panel are at the same point half way', ($r['stepGap'] ?? 1) < .02, 'gap ' . ($r['stepGap'] ?? '?'));
    pin('a click while it opens is taken', 'false', $r['secondClickTaken'] ?? null);
    check('and the close starts from the height it had reached', isset($r['reverseStart']) && $r['reverseStart'][0] > 0 && abs($r['reverseStart'][0] - $r['reverseStart'][1]) <= 1, json_encode($r['reverseStart'] ?? null));
    pin('it ends shut', array(false, 0), $r['reversedShut'] ?? null);
    check('Enter on a chevron opens its section', $r['enter'] ?? false);
    check('Space closes it again', ($r['space'] ?? true) === false);
}

harness_section('states');
$r = sidebar_run(<<<'JS'
const dash = t.row('Dashboard');
const a = t.box(dash.querySelector('a'));
await mouseMove(a.x + 40, a.cy);
r.hoverIn = t.css(dash, 'transition-duration');
await t.finish();
r.hoverBg = [t.css(dash, 'background-color'), t.token(dash, '--osc-sidebar-hover-bg')];
const icon = dash.querySelector('a > i');
r.hoverIcon = [t.css(icon, 'color'), t.token(dash, '--osc-sidebar-accent', 'color')];
r.hoverIconTransform = t.css(icon, 'transform');
await mouseDown();
await t.finish();
r.pressBg = [t.css(dash, 'background-color'), t.token(dash, '--osc-sidebar-press-bg')];
await mouseMove(900, 500);
await mouseUp();
await t.finish();

const add = t.$('#items_1');
const b = t.box(add);
await mouseMove(b.x + 50, b.cy);
await t.finish();
r.subHover = [t.css(add, 'background-color'), t.token(add, '--osc-sidebar-hover-bg')];
r.subDot = t.css(add, 'transform', '::before');
const cur = t.$('#items_0');
const c = t.box(cur);
await mouseMove(c.x + 50, c.cy);
await t.finish();
r.activeHover = [t.css(cur, 'background-color'), t.token(cur, '--osc-sidebar-active-bg')];
await mouseMove(900, 500);
await t.finish();

const listings = t.row('Listings');
r.sectionRow = [t.css(listings, 'background-color'), t.css(listings.querySelector('a'), 'font-weight'), t.css(listings.querySelector('a'), 'color') === t.token(listings, '--osc-sidebar-accent', 'color')];
r.pills = t.$$('#dashboard-menu div.nav-link, #dashboard-menu .sidebar-submenu a').filter((el) => t.css(el, 'background-color') !== 'rgba(0, 0, 0, 0)').map((el) => el.textContent.trim());

t.$('#osc_toolbar_home').focus();
await pressKey('Tab');
r.focusRow = [document.activeElement.closest('.nav-link') === dash, t.css(dash, 'outline-style'), t.css(dash, 'outline-width'), t.css(dash.querySelector('a'), 'outline-style')];

// A click marks the link current at once; the page it left lets go.
document.addEventListener('click', (e) => e.preventDefault());
t.chevron('Users').click();
await t.finish();
await t.click(t.$('#users_1'));
await mouseMove(900, 500);
await t.finish();
r.going = t.$$('#dashboard-menu div.nav-link, #dashboard-menu .sidebar-submenu a').filter((el) => t.css(el, 'background-color') !== 'rgba(0, 0, 0, 0)').map((el) => el.textContent.trim());
r.leftGoing = [t.css(listings.querySelector('a'), 'font-weight'), t.css(t.$('#items_0'), 'font-weight')];
JS);
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    pin('hover arrives in 80ms', '0.08s', $r['hoverIn'] ?? null);
    pin('hover paints the hover token', $r['hoverBg'][1] ?? 'x', $r['hoverBg'][0] ?? null);
    pin('the hover icon is bronze', $r['hoverIcon'][1] ?? 'x', $r['hoverIcon'][0] ?? null);
    pin('the hover icon does not scale', 'none', $r['hoverIconTransform'] ?? null);
    pin('pressed paints the press token', $r['pressBg'][1] ?? 'x', $r['pressBg'][0] ?? null);
    pin('a submenu link takes the hover token', $r['subHover'][1] ?? 'x', $r['subHover'][0] ?? null);
    check('its dot does not scale', preg_match('~^matrix\(1, 0, 0, 1,~', (string) ($r['subDot'] ?? '')) === 1, (string) ($r['subDot'] ?? ''));
    pin('hover leaves the current page as it is', $r['activeHover'][1] ?? 'x', $r['activeHover'][0] ?? null);
    pin('the open section keeps bronze ink and weight with no fill', array('rgba(0, 0, 0, 0)', '600', true), $r['sectionRow'] ?? null);
    pin('one pill, on the current page', array('Manage listings'), $r['pills'] ?? null);
    pin('the focus ring is on the row, 2px, not on the link', array(true, 'solid', '2px', 'none'), $r['focusRow'] ?? null);
    pin('a clicked link is the one pill at once', array('Add user'), $r['going'] ?? null);
    pin('and the page it left lets go', array('500', '500'), $r['leftGoing'] ?? null);
}

harness_section('the rail');
$r = sidebar_run(<<<'JS'
const icons = () => t.$$('#dashboard-menu > .nav-item > .nav-link a > i, .sidebar-brand > i, .sidebar-collapse-toggle > i').map((i) => t.box(i).cx);
const drift = (from) => Math.max(...icons().map((x, i) => Math.abs(x - from[i])));
const start = icons();
const users = t.row('Users');
const usersY = t.box(users).y;
const listings = t.panel('Listings');
const full = t.box(listings).h;
t.$('#oscSidebarToggle').click();
r.during = document.documentElement.getAttribute('data-osc-sidebar');
const seen = [];
for (const ms of [0, 16, 50, 100, 150, 199]) {
    t.seek(ms);
    seen.push(drift(start));
    if (ms === 100) { r.midPanel = [t.box(listings).h, full]; r.midRow = [t.box(users).y, usersY]; }
}
r.collapseDrift = Math.max(...seen);
await t.finish();
r.after = document.documentElement.getAttribute('data-osc-sidebar');
r.railWidth = t.box(t.$('.dashboard-sidebar')).w;
r.finalDrift = drift(start);
const icon = t.row('Dashboard').querySelector('a > i');
r.iconCentre = t.box(icon).cx;

const tools = t.row('Tools');
const dot = t.box(tools.querySelector('.osc-dot')), ti = t.box(tools.querySelector('a > i'));
r.railDot = [Math.round(dot.x - (ti.x + ti.w)), Math.round(dot.y - ti.y), Math.round(dot.w * 100) / 100, t.css(tools.querySelector('.osc-dot'), 'background-color') !== 'rgba(0, 0, 0, 0)'];
r.pills = t.$$('#dashboard-menu div.nav-link').filter((el) => t.css(el, 'background-color') !== 'rgba(0, 0, 0, 0)').map((el) => el.textContent.trim());

// Flyouts: moving to the next row hides the last one at once.
const u = t.box(t.row('Users')), m = t.box(t.row('Messages'));
await mouseMove(u.cx, u.cy); await t.finish();
r.flyOpen = t.css(t.panel('Users'), 'opacity');
await mouseMove(m.cx, m.cy);
r.flyPrev = [t.css(t.panel('Users'), 'opacity'), t.css(t.panel('Users'), 'visibility')];
await mouseMove(900, 500); await t.finish();

// Expand, turn around half way, expand again: the icons never move.
t.$('#oscSidebarToggle').click();
t.seek(100);
t.$('#oscSidebarToggle').click();
r.turned = document.documentElement.getAttribute('data-osc-sidebar');
t.seek(50);
r.turnDrift = drift(start);
await t.finish();
r.turnedEnd = document.documentElement.getAttribute('data-osc-sidebar');
t.$('#oscSidebarToggle').click();
for (const ms of [0, 50, 100, 150]) { t.seek(ms); seen.push(drift(start)); }
r.expandDrift = Math.max(...seen);
await t.finish();
r.back = [document.documentElement.getAttribute('data-osc-sidebar'), Math.round(t.box(listings).h) === Math.round(full)];
const dotE = t.box(t.row('Tools').querySelector('.osc-dot')), rowE = t.box(t.row('Tools')), chevE = t.box(t.chevron('Tools'));
r.rowDot = [Math.abs(dotE.cy - rowE.cy) <= 1, dotE.x + dotE.w <= chevE.x + .5, Math.round(dotE.w * 100) / 100];
JS);
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    pin('while the width moves the state is collapsing', 'collapsing', $r['during'] ?? null);
    check('no icon moves while the rail collapses', ($r['collapseDrift'] ?? 99) <= 1, 'moved ' . ($r['collapseDrift'] ?? '?') . 'px');
    pin('then it is collapsed', 'collapsed', $r['after'] ?? null);
    pin('the rail is 4rem', 56.0, (float) ($r['railWidth'] ?? 0));
    check('and the icons ended where they started', ($r['finalDrift'] ?? 99) <= 1);
    check('the icon column is on the rail centre', abs(($r['iconCentre'] ?? 0) - 28) <= 1, (string) ($r['iconCentre'] ?? ''));
    check('an open panel shrinks with the width, not at once', isset($r['midPanel']) && $r['midPanel'][0] > 0 && $r['midPanel'][0] < $r['midPanel'][1], json_encode($r['midPanel'] ?? null));
    check('rows below it move with it', isset($r['midRow']) && $r['midRow'][0] < $r['midRow'][1] && $r['midRow'][0] > $r['midRow'][1] - ($r['midPanel'][1] ?? 0), json_encode($r['midRow'] ?? null));
    pin('the notice dot keeps its place on the icon corner', array(-4, -10, 8.75, true), $r['railDot'] ?? null);
    pin('on the rail the section row carries the pill', array('Listings'), $r['pills'] ?? null);
    pin('a flyout opens on hover', '1', $r['flyOpen'] ?? null);
    pin('the last flyout is gone the moment the next row is entered', array('0', 'hidden'), $r['flyPrev'] ?? null);
    pin('a second click turns the rail around', 'collapsing', $r['turned'] ?? null);
    check('and the icons hold through the turn', ($r['turnDrift'] ?? 99) <= 1);
    pin('it ends collapsed', 'collapsed', $r['turnedEnd'] ?? null);
    check('no icon moves while the rail expands', ($r['expandDrift'] ?? 99) <= 1, 'moved ' . ($r['expandDrift'] ?? '?') . 'px');
    pin('expanded again, with the open panel back at full height', array('expanded', true), $r['back'] ?? null);
    pin('on a row the dot sits centred before the chevron', array(true, true, 8.75), $r['rowDot'] ?? null);
}

harness_section('reduced motion');
$r = sidebar_run(<<<'JS'
const p = t.panel('Users');
t.chevron('Users').click();
r.panel = [p.getAnimations().length, Math.round(t.box(p).h) === p.scrollHeight && p.scrollHeight > 0];
t.$('#oscSidebarToggle').click();
r.rail = [document.documentElement.getAttribute('data-osc-sidebar'), t.$('.dashboard-sidebar').getAnimations().length, Math.round(t.box(t.$('.dashboard-sidebar')).w)];
JS, array('reduce' => true));
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    pin('a section opens at once, no animation', array(0, true), $r['panel'] ?? null);
    pin('the rail collapses at once', array('collapsed', 0, 56), $r['rail'] ?? null);
}

harness_section('phone');
$r = sidebar_run(<<<'JS'
const nav = t.$('#sidebar-wrapper');
r.first = [t.css(nav, 'visibility'), t.css(nav, 'position'), Math.round(t.box(t.$('#content-render')).w)];
JS, array('width' => 390, 'height' => 844, 'nojs' => true));
if ($r !== null) {
    pin('before the script runs the menu is not in the page and the content has the full width', array('hidden', 'fixed', 390), $r['first'] ?? null);
}
$r = sidebar_run(<<<'JS'
const nav = t.$('#sidebar-wrapper');
r.drawer = [nav.classList.contains('offcanvas'), t.css(nav, 'transition-duration'), t.css(nav, 'transition-timing-function')];
t.$('#header .navbar-toggler').click();
await t.wait(30);
await t.finish();
for (let i = 0; i < 20 && !nav.classList.contains('show'); i++) { await t.wait(25); }
r.open = [nav.classList.contains('show'), Math.round(t.box(nav).x), t.css(nav, 'visibility')];
const row = t.row('Users'), chev = t.chevron('Users');
r.targets = [Math.round(t.box(row).h), Math.round(t.box(chev).w), Math.round(t.box(chev).h), Math.round(t.box(t.$('#items_1')).h)];
JS, array('width' => 390, 'height' => 844));
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    pin('the drawer slides on the shared duration and easing', array(true, '0.2s', 'cubic-bezier(0.2, 0, 0, 1)'), $r['drawer'] ?? null);
    pin('it opens in view', array(true, 0, 'visible'), $r['open'] ?? null);
    pin('rows, chevrons and submenu links are 44px', array(44, 44, 44, 44), $r['targets'] ?? null);
}

exit(harness_result());
