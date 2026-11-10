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
 * The admin bell and notices panel: the bell's name and chip, the panel's groups, cap and
 * escaping, update chips for moderators, and the panel's keyboard and Hide behaviour in Chrome.
 *
 * Usage: php tests/admin-notice-bell.php
 */

declare(strict_types=1);

use mindstellar\admin\AdminNotice;
use mindstellar\admin\AdminNoticeView;

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function _e(string $s): void
{
    echo $s;
}
function osc_format_date(string $date): string
{
    return 'on ' . substr($date, 0, 10);
}
function osc_csrf_token_url(): string
{
    return 'CSRFName=n&CSRFToken=t';
}
function osc_asset_url_versioned(string $url): string
{
    return $url;
}
function osc_current_admin_theme_js_url(string $file): string
{
    return 'https://example.test/js/' . $file;
}
function osc_admin_notices(): AdminNoticeView
{
    return $GLOBALS['noticeView'];
}
function osc_is_moderator(): bool
{
    return $GLOBALS['moderator'] ?? false;
}
function osc_update_check_state(string $kind): array
{
    return array('checked' => time(), 'count' => 3, 'to_update' => array(), 'downloaded' => array(), 'available' => true, 'package' => array('s_new_version' => '99.0.0'));
}
require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/lib/admin-ui-stubs.php';
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '7.1.0');
}

/** Stands in for the top bar, counting what gets added. */
class AdminToolbar
{
    public static array $added = array();

    public static function getInstance(): self
    {
        return new self();
    }

    public function add_menu(array $menu): void
    {
        self::$added[] = $menu['id'];
    }

    public function remove_menu(string $id): void
    {
    }
}

// These helpers live in functions.php, which cannot load without the whole core.
$functionsSource = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
foreach (array('osc_admin_notice_name', 'osc_update_check_count', 'osc_admin_toolbar_update_counter', 'osc_admin_toolbar_update_core', 'osc_admin_toolbar_update_plugins', 'osc_admin_toolbar_update_themes', 'osc_admin_toolbar_update_languages') as $fn) {
    preg_match('~^function ' . $fn . '\(.*?^}~ms', $functionsSource, $m);
    eval($m[0]);
}

function notice_row(string $id, string $type, int $since = 100, array $over = array()): AdminNotice
{
    return AdminNotice::fromArray($over + array('id' => $id, 'type' => $type, 'text' => 'Text ' . $id . '.', 'link' => '?page=tools', 'linkLabel' => 'Open ' . $id, 'since' => $since));
}

function render_view(string $part, AdminNoticeView $view): string
{
    $GLOBALS['noticeView'] = $view;
    ob_start();
    require ABS_PATH . 'oc-admin/themes/modern/parts/' . $part;

    return (string) ob_get_clean();
}

/** Text inside the first tag matching $marker; '' when there is none. */
function bell_aria(string $html): string
{
    preg_match('~id="oscNoticeBell".*?aria-label="([^"]*)"~s', $html, $m);

    return $m[1] ?? '';
}

harness_section('the bell');
$bell = render_view('notices-bell.php', new AdminNoticeView());
pin('zero: the name says so', 'Notices, nothing needs attention', bell_aria($bell));
check('zero: no chip', !str_contains($bell, 'class="circle'));
check('zero: the panel is there, empty', str_contains($bell, 'osc-notice-empty') && str_contains($bell, 'Nothing needs attention.'));
foreach (array('aria-haspopup="dialog"', 'aria-expanded="false"', 'aria-controls="oscNoticePanel"', 'role="dialog"', 'aria-labelledby="oscNoticeTitle"', 'id="oscNoticeTitle"') as $attr) {
    check("bell markup has $attr", str_contains($bell, $attr));
}

$bell = render_view('notices-bell.php', new AdminNoticeView(array(notice_row('a', 'error'), notice_row('b', 'warning'))));
pin('one needs action', 'Notices, 2 notices, 1 needs action', bell_aria($bell));
check('red chip with the count', (bool) preg_match('~<i class="circle circle-red" aria-hidden="true">2</i>~', $bell));

$bell = render_view('notices-bell.php', new AdminNoticeView(array(notice_row('a', 'warning'))));
pin('no action in the name when none need it', 'Notices, 1 notice', bell_aria($bell));
check('grey chip when none need action', str_contains($bell, 'circle circle-gray'));

harness_section('the bell sits after the chips and before the divider');
$header = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/parts/header.php');
$after  = strpos($header, "AdminToolbar::getInstance()->render()");
$here   = strpos($header, 'notices-bell.php');
$before = strpos($header, 'admin-topbar-divider');
check('order is toolbar, bell, divider', $after !== false && $here > $after && $before > $here);

harness_section('the panel');
$panel = render_view('notices-panel.php', new AdminNoticeView(array(notice_row('warn', 'warning', 900), notice_row('old', 'error', 100), notice_row('new', 'error', 500))));
check('Needs action comes before Heads-up', strpos($panel, 'Needs action') < strpos($panel, 'Heads-up'));
check('newest first in a group', strpos($panel, 'osc-notice="new"') < strpos($panel, 'osc-notice="old"'));
check('action rows before heads-up rows', strpos($panel, 'osc-notice="old"') < strpos($panel, 'osc-notice="warn"'));
check('row classes', str_contains($panel, 'osc-notice-row is-action') && str_contains($panel, 'osc-notice-row is-headsup'));
check('a Hide button on every row', substr_count($panel, 'data-osc-notice-hide=') === 3);
check('the aria-label names the notice', str_contains($panel, 'aria-label="Hide for 7 days: Text new."'));
check('the group shows its size', (bool) preg_match('~Needs action<span class="osc-notice-n">2</span>~', $panel));
check('See all goes to the screen', str_contains($panel, 'index.php?page=notices'));
check('the relative link gets the admin base', str_contains($panel, 'https://example.test/oc-admin/index.php?page=tools'));

$hiddenRow = notice_row('hid', 'error', 300, array('hiddenUntil' => 1893456000));
$panel     = render_view('notices-panel.php', new AdminNoticeView(array(), array($hiddenRow)));
check('nothing active: the quiet line', str_contains($panel, 'Nothing needs attention.'));
check('hidden rows have a Show button', str_contains($panel, 'data-osc-notice-show="hid"'));
check('and no Hide', !str_contains($panel, 'data-osc-notice-hide'));
check('hidden rows are in a closed list', (bool) preg_match('~osc-notice-group-hidden"[^>]* hidden~', $panel));
check('the foot counts them', str_contains($panel, '1 hidden'));
check('the comeback date is printed', str_contains($panel, 'Comes back on 2030-01-01'));

$rows  = array();
for ($i = 0; $i < 40; $i++) {
    $rows[] = notice_row('n' . $i, $i % 2 ? 'error' : 'warning', $i);
}
$panel = render_view('notices-panel.php', new AdminNoticeView($rows));
pin('the panel is capped at 30 rows', 30, substr_count($panel, 'data-osc-notice-hide='));
check('the group count is the real total', (bool) preg_match('~Heads-up<span class="osc-notice-n">20</span>~', $panel));
check('See all is there at the cap', str_contains($panel, 'osc-notice-all'));

harness_section('escaping');
$bad   = notice_row('x', 'error', 1, array('text' => '<b>bold</b> "q"', 'detail' => '<script>d</script>', 'linkLabel' => '<img src=x>', 'link' => 'javascript:alert(1)'));
$panel = render_view('notices-panel.php', new AdminNoticeView(array($bad, notice_row('y', 'warning', 1, array('linkLabel' => '<i>L</i>')))));
check('text is escaped', str_contains($panel, '&lt;b&gt;bold&lt;/b&gt; &quot;q&quot;') && !str_contains($panel, '<b>bold'));
check('link label is escaped', str_contains($panel, '&lt;i&gt;L&lt;/i&gt;') && !str_contains($panel, '<i>L</i>'));
check('a javascript: link never renders', !str_contains($panel, 'javascript:'));
check('the detail is left for the screen', !str_contains($panel, 'd</script>') && !str_contains($panel, '&lt;script&gt;'));
check('no html comments in the panel', !str_contains($panel, '<!--'));
check('no html comments in the bell', !str_contains(render_view('notices-bell.php', new AdminNoticeView(array(notice_row('a', 'error')))), '<!--'));

harness_section('moderators get no update chips');
$GLOBALS['moderator'] = true;
AdminToolbar::$added  = array();
osc_admin_toolbar_update_core();
osc_admin_toolbar_update_plugins();
osc_admin_toolbar_update_themes();
osc_admin_toolbar_update_languages();
pin('a moderator sees none of the four', array(), AdminToolbar::$added);
$GLOBALS['moderator'] = false;
AdminToolbar::$added  = array();
osc_admin_toolbar_update_core();
osc_admin_toolbar_update_plugins();
osc_admin_toolbar_update_themes();
osc_admin_toolbar_update_languages();
pin('an admin sees all four', array('update_core', 'update_plugin', 'update_theme', 'update_language'), AdminToolbar::$added);

harness_section('the bell in a browser');
$reply = static fn (array $active, array $hidden, array $marks = array()): array => array(
    'ok'     => 1,
    'count'  => count($active),
    'action' => count(array_filter($active, static fn (AdminNotice $n): bool => $n->type === 'error')),
    'hidden' => count($hidden),
    'name'   => 'name-' . count($active),
    'panel'  => render_view('notices-panel.php', new AdminNoticeView($active, $hidden)),
    'marks'  => $marks,
);
$a      = notice_row('a', 'error', 300);
$b      = notice_row('b', 'warning', 200);
$c      = notice_row('c', 'warning', 100);
$aGone  = $a->withHiddenUntil(1893456000);
$bGone  = $b->withHiddenUntil(1893456000);
$cGone  = $c->withHiddenUntil(1893456000);
$fixture = array(
    'a'   => $reply(array($b, $c), array($aGone), array('tools' => array('group' => '', 'name' => ''))),
    'b'   => $reply(array($c), array($aGone, $bGone)),
    'c'   => $reply(array(), array($aGone, $bGone, $cGone)),
    'all' => $reply(array($a, $b, $c), array()),
);
$bellHtml = render_view('notices-bell.php', new AdminNoticeView(array($a, $b, $c), array()));
$bellHtml = (string) preg_replace('~<script.*?</script>~s', '', $bellHtml);

$page = '<main><header id="header" class="admin-topbar navbar"><ul class="navbar-nav admin-topbar-tools ms-auto">' . $bellHtml . '</ul></header>'
    . '<div id="osc-live"><div data-osc-live="polite"></div></div>'
    . '<div id="content-page" class="row"><span class="osc-dot is-action" data-osc-mark="tools" title="x"></span>'
    . '<span data-osc-mark-name="tools">, x</span><p id="outside">Page</p></div></main>';

function bell_run(string $page, string $driver, int $width): ?array
{
    $chrome = test_browser();
    $pc     = test_puppeteer();
    if ($chrome === '' || $pc === '') {
        if ((string) getenv('CI') !== '') {
            check('a browser and puppeteer-core are available', false, 'run npm ci');
        }
        echo "  (skipped: no browser or puppeteer-core)\n";

        return null;
    }
    $tmp = sys_get_temp_dir() . '/osc-bell-' . getmypid() . '-' . $width;
    mkdir($tmp, 0700, true);
    $scripts = '';
    foreach (array('oc-admin/themes/modern/js/osc.js', 'oc-admin/themes/modern/js/ui-osc.js', 'oc-admin/themes/modern/js/notices.js') as $s) {
        $scripts .= '<script src="file://' . ABS_PATH . $s . '"></script>';
    }
    file_put_contents($tmp . '/page.html', '<!doctype html><html lang="en" data-bs-theme="light"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . ABS_PATH . 'oc-admin/themes/modern/css/main.css"></head><body>'
        . '<script>window.osc = window.osc || {};</script>' . $page . $scripts . '</body></html>');
    file_put_contents($tmp . '/driver.js', $driver);
    file_put_contents($tmp . '/run.mjs', <<<'MJS'
import {readFileSync} from 'node:fs';
const [pc, chrome, dir, width] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files']});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 800});
    const errors = [];
    tab.on('pageerror', (e) => errors.push(String(e.message)));
    await tab.goto('file://' + dir + '/page.html', {waitUntil: 'load'});
    const press = async (key, shift) => {
        if (shift) { await tab.keyboard.down('Shift'); }
        await tab.keyboard.press(key);
        if (shift) { await tab.keyboard.up('Shift'); }
    };
    await tab.exposeFunction('pressKey', press);
    const out = await tab.evaluate(async (src) => {
        const t = {wait: (ms) => new Promise((d) => setTimeout(d, ms))};
        const r = {};
        try { await new Function('t', 'r', 'return (async () => {' + src + '})()')(t, r); } catch (e) { r.thrown = String(e.message); }
        return r;
    }, readFileSync(dir + '/driver.js', 'utf8'));
    out.errors = errors;
    console.log(JSON.stringify(out));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec('node ' . escapeshellarg($tmp . '/run.mjs') . ' ' . escapeshellarg($pc) . ' ' . escapeshellarg($chrome) . ' ' . escapeshellarg($tmp) . ' ' . $width . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($tmp));
    $r = json_decode(trim($out), true);
    check('the browser run produced a reading', is_array($r), substr($out, 0, 400));

    return is_array($r) ? $r : array();
}

$shared = 'const bell = document.getElementById("oscNoticeBell");'
    . 'const panel = document.getElementById("oscNoticePanel");'
    . 'const active = () => document.activeElement;'
    . 'const label = () => (active().getAttribute("aria-label") || active().textContent).trim();'
    . 'const FIX = ' . json_encode($fixture) . ';';

$r = bell_run($page, $shared . <<<'JS'
let posted = [];
let reply = (id) => FIX[id];
window.fetch = async (url, init) => {
    const id = new URLSearchParams(init.body).get('id');
    posted.push([url, id, init.method, new URLSearchParams(init.body).get('CSRFToken')]);
    const out = reply(id);
    if (out instanceof Error) { throw out; }
    return {json: async () => out};
};
const notes = [];
osc.notify = (type, text) => notes.push([type, text]);

bell.click();
await t.wait(60);
r.open = [panel.classList.contains('is-open'), bell.getAttribute('aria-expanded'), getComputedStyle(panel).visibility];
r.modal = panel.getAttribute('aria-modal');
r.first = active().textContent.trim();
const rows = [...panel.querySelectorAll('.osc-notice-row a, .osc-notice-row button, .osc-notice-all')].filter((el) => el.offsetParent !== null);
rows[rows.length - 1].focus();
await pressKey('Tab');
r.deskWrap = active() === panel.querySelector('.osc-notice-close') || panel.contains(active());
rows[0].focus();
await pressKey('ArrowDown'); r.down = active().closest('.osc-notice-row').dataset.oscNotice;
await pressKey('End'); r.end = active().closest('.osc-notice-row').dataset.oscNotice;
await pressKey('Home'); r.home = active().closest('.osc-notice-row').dataset.oscNotice;
await pressKey('Escape');
await t.wait(30);
r.esc = [panel.classList.contains('is-open'), bell.getAttribute('aria-expanded'), active() === bell];

bell.click(); await t.wait(40);
document.getElementById('outside').click(); await t.wait(30);
r.outside = panel.classList.contains('is-open');

bell.click(); await t.wait(40);
panel.querySelector('[data-osc-notice-hide="a"]').click();
await t.wait(400);
r.posted = posted[0];
r.afterHide = active().getAttribute('data-osc-notice-hide');
r.rowGone = !panel.querySelector('[data-osc-notice-hide="a"]');
r.name = bell.getAttribute('aria-label');
r.chip = [bell.querySelector('.circle').textContent, bell.querySelector('.circle').className];
r.live = document.querySelector('[data-osc-live="polite"]').textContent;
r.mark = [document.querySelector('[data-osc-mark="tools"]').hidden, document.querySelector('[data-osc-mark-name="tools"]').textContent];

r.toggleLabel0 = panel.querySelector('[data-osc-notice-toggle]').textContent;
panel.querySelector('[data-osc-notice-toggle]').click();
r.listOpen = [panel.querySelector('.osc-notice-group-hidden').hidden, panel.querySelector('[data-osc-notice-toggle]').getAttribute('aria-expanded'), panel.querySelector('[data-osc-notice-toggle]').textContent];

panel.querySelector('[data-osc-notice-hide="b"]').click();
await t.wait(400);
r.afterHideB = active().getAttribute('data-osc-notice-hide');
r.keepOpen = panel.querySelector('.osc-notice-group-hidden').hidden;

panel.querySelector('[data-osc-notice-hide="c"]').click();
await t.wait(400);
r.afterLast = active().hasAttribute('data-osc-notice-toggle');
r.noChip = bell.querySelector('.circle') === null;

reply = () => new Error('down');
panel.querySelector('[data-osc-notice-show="a"]').click();
await t.wait(200);
r.failure = notes[0][0];
r.stillThere = !!panel.querySelector('[data-osc-notice-show="a"]');
JS, 1440);
if ($r !== null) {
    pin('no script errors', array(), $r['errors'] ?? null);
    pin('nothing thrown', null, $r['thrown'] ?? null);
    pin('it opens', array(true, 'true', 'visible'), $r['open'] ?? null);
    pin('not modal on desktop', null, $r['modal'] ?? null);
    pin('focus goes to the first row', 'Open a', $r['first'] ?? null);
    check('Tab stays in the panel on a wide screen too', $r['deskWrap'] ?? false);
    pin('Down moves to the next row', 'b', $r['down'] ?? null);
    pin('End goes to the last', 'c', $r['end'] ?? null);
    pin('Home goes to the first', 'a', $r['home'] ?? null);
    pin('Esc closes and returns to the bell', array(false, 'false', true), $r['esc'] ?? null);
    pin('a click outside closes', false, $r['outside'] ?? null);
    pin('Hide posts the id with the token', array('https://example.test/oc-admin/index.php?page=ajax&action=notice_hide', 'a', 'POST', 't'), $r['posted'] ?? null);
    pin('after Hide, focus on the next row\'s Hide', 'b', $r['afterHide'] ?? null);
    check('the panel was replaced', $r['rowGone'] ?? false);
    pin('the bell name updates', 'name-2', $r['name'] ?? null);
    pin('the chip updates and turns grey', array('2', 'circle circle-gray'), $r['chip'] ?? null);
    pin('Hide is spoken once, politely', 'Hidden for 7 days.', $r['live'] ?? null);
    pin('the dot goes out and its name empties', array(true, ''), $r['mark'] ?? null);
    pin('the hidden list opens', array(false, 'true', 'Close list'), $r['listOpen'] ?? null);
    pin('with no next row, focus on the toggle', true, $r['afterLast'] ?? null);
    pin('the list stays open across a Hide', false, $r['keepOpen'] ?? null);
    check('the chip goes at zero', $r['noChip'] ?? false);
    pin('a failed Hide says so', 'error', $r['failure'] ?? null);
    check('and nothing changes', $r['stillThere'] ?? false);
}

$r = bell_run($page, $shared . <<<'JS'
bell.click();
await t.wait(320);
const style = getComputedStyle(panel);
const box = panel.getBoundingClientRect();
r.fixed = style.position;
r.atBottom = Math.round(box.bottom);
r.modal = panel.getAttribute('aria-modal');
r.backdrop = getComputedStyle(document.querySelector('.osc-notice-backdrop')).visibility;
r.scrollLock = getComputedStyle(document.body).overflow;
r.target = Math.round(panel.querySelector('.osc-notice-links a').getBoundingClientRect().height);
const items = [...panel.querySelectorAll('a[href], button')].filter((el) => el.offsetParent !== null);
items[items.length - 1].focus();
await pressKey('Tab');
r.wrap = active() === items[0];
await pressKey('Tab', true);
r.wrapBack = active() === items[items.length - 1];
document.querySelector('.osc-notice-backdrop').click();
await t.wait(40);
r.closed = [panel.classList.contains('is-open'), getComputedStyle(document.body).overflow, active() === bell];
JS, 390);
if ($r !== null) {
    pin('no script errors on a phone', array(), $r['errors'] ?? null);
    pin('nothing thrown on a phone', null, $r['thrown'] ?? null);
    pin('a bottom sheet', 'fixed', $r['fixed'] ?? null);
    pin('...at the bottom of the screen', 800, $r['atBottom'] ?? null);
    pin('...modal', 'true', $r['modal'] ?? null);
    pin('...with a backdrop', 'visible', $r['backdrop'] ?? null);
    pin('...and the page locked', 'hidden', $r['scrollLock'] ?? null);
    check('44 px targets', ($r['target'] ?? 0) >= 44, (string) ($r['target'] ?? ''));
    pin('Tab wraps to the first', true, $r['wrap'] ?? null);
    pin('Shift+Tab wraps to the last', true, $r['wrapBack'] ?? null);
    pin('the backdrop closes it and unlocks', array(false, 'visible', true), $r['closed'] ?? null);
}

exit(harness_result());

/* file end: ./tests/admin-notice-bell.php */
