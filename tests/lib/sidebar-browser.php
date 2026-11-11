<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Runs the admin sidebar in Chrome: the shell from parts/header.php, a menu drawn by the real
 * AdminMenu::renderMenu(), the built main.css and the real osc.js + ui-osc.js. A driver gets t
 * (helpers, including a seekable clock over every running animation) and fills r.
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
$GLOBALS['moderator'] = false;
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return $GLOBALS['moderator'];
    }
}
if (!function_exists('osc_admin_notice_name')) {
    function osc_admin_notice_name(int $count, int $action, bool $bell = false): string
    {
        return $count < 1 ? '' : $count . ' notices';
    }
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/admin-ui-stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

/**
 * A menu shaped like the real one: leaves, sections with submenus, a count, a plugin section
 * with its own icon class and one with none (the bi-app fallback), and notice dots on Tools.
 *
 * @return array<string,array<mixed>>
 */
function sidebar_fixture_menu(): array
{
    $u = static fn (string $q): string => 'https://example.test/oc-admin/index.php?page=' . $q;
    $sub = static function (string $section, array $titles) use ($u): array {
        $out = array();
        foreach ($titles as $i => $title) {
            $id = $section . '_' . $i;
            $out[$id] = array($title, $u($section . '&action=' . $i), $id, 'moderator');
        }

        return $out;
    };
    $items = $sub('items', array('Manage listings', 'Add listing', 'Reported', 'Comments', 'Media', 'Listing settings', 'Custom fields'));
    $items['items_3']['count'] = 3;

    return array(
        'dash'       => array('Dashboard', $u('dash'), 'dash', 'moderator', 'bi bi-speedometer2'),
        'items'      => array('Listings', $u('items'), 'items', 'moderator', 'bi bi-card-list', 'sub' => $items),
        'users'      => array('Users', $u('users'), 'users', 'moderator', 'bi bi-people', 'sub' => $sub('users', array('Manage users', 'Add user', 'Banned users', 'Admins'))),
        'messages'   => array('Messages', $u('messages'), 'messages', 'moderator', 'bi bi-chat-left-text', 'sub' => $sub('messages', array('Inbox', 'Settings'))),
        'appearance' => array('Appearance', $u('appearance'), 'appearance', 'moderator', 'bi bi-palette', 'sub' => $sub('appearance', array('Themes', 'Customise', 'Widgets', 'Menus'))),
        'plugins'    => array('Plugins', $u('plugins'), 'plugins', 'moderator', 'bi bi-plug', 'sub' => $sub('plugins', array('Manage plugins', 'Add plugin', 'Shop extras'))),
        'shopext'    => array('Shop extras', $u('plugins&action=renderplugin&file=x'), 'shopext', 'moderator', 'bi bi-puzzle'),
        'noicon'     => array('Old plugin', $u('plugins&action=renderplugin&file=y'), 'noicon', 'moderator', ''),
        'tools'      => array('Tools', $u('tools'), 'tools', 'moderator', 'bi bi-wrench', 'sub' => $sub('tools', array('Backup', 'Maintenance', 'System info'))),
        'help'       => array('Help', $u('help'), 'help', 'moderator', 'bi bi-question-circle'),
    );
}

/** The menu markup for one current page, as renderAdminMenu() draws it. */
function sidebar_menu_html(string $menu, string $sub): string
{
    $admin = new AdminMenu();
    $marks = new ReflectionProperty($admin, 'noticeMarks');
    $marks->setValue($admin, array(
        'tools'        => array('group' => 'action', 'count' => 2, 'action' => 1),
        'tools/tools_0' => array('group' => 'headsup', 'count' => 1, 'action' => 0),
    ));
    $render = new ReflectionMethod($admin, 'renderMenu');
    $html   = '<div class="px-1 pt-2"><ul id="dashboard-menu" class="oscmenu col-md-12 nav nav-pills flex-column">';
    foreach (sidebar_fixture_menu() as $id => $value) {
        $html .= $render->invoke($admin, $id, $value, $menu, $sub);
    }

    return $html . '</ul></div>';
}

/**
 * The shell from parts/header.php (sidebar, topbar and its menu button) with the menu in it.
 * PHP in the template becomes the text it would print, or nothing.
 */
function sidebar_shell_html(string $menuHtml): string
{
    $src   = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/parts/header.php');
    $start = (int) strpos($src, '<div id="content"');
    $menuButton = (int) strpos($src, 'admin-topbar-menu-close');
    $end   = (int) strpos($src, '</button>', $menuButton) + strlen('</button>');
    $part  = substr($src, $start, $end - $start);
    $part  = (string) preg_replace_callback('~<\?php(.*?)\?>~s', static function (array $m) use ($menuHtml): string {
        if (str_contains($m[1], 'osc_draw_admin_menu')) {
            return $menuHtml;
        }
        if (preg_match("~(?:_e|__)\\('([^']*)'\\)~", $m[1], $t)) {
            return $t[1];
        }

        return '';
    }, $part);

    return $part . '</header><div id="content-page" class="row"><div class="col"><h1>Manage listings</h1>'
        . str_repeat('<p>Listing row text that fills the content column.</p>', 30) . '</div></div></main></div></div>';
}

/**
 * Loads the sidebar page in Chrome, runs $driver (an async body with t and r), returns r plus
 * errors. Options: width, height, dark, reduce, rail, nojs, menu, sub, rtl. Returns null with
 * no browser (a failure on CI).
 *
 * @param array<string,mixed> $opts
 *
 * @return array<string,mixed>|null
 */
function sidebar_run(string $driver, array $opts = array()): ?array
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
    $tmp = sys_get_temp_dir() . '/osc-sidebar-' . getmypid() . '-' . substr(md5($driver . microtime()), 0, 8);
    mkdir($tmp, 0700, true);
    $scripts = '';
    if (empty($opts['nojs'])) {
        foreach (array('osc.js', 'ui-osc.js') as $s) {
            $scripts .= '<script src="file://' . ABS_PATH . 'oc-admin/themes/modern/js/' . $s . '"></script>';
        }
    }
    $rail = !empty($opts['rail']) ? 'collapsed' : 'expanded';
    file_put_contents($tmp . '/page.html', '<!doctype html><html lang="en"' . (!empty($opts['rtl']) ? ' dir="rtl"' : '')
        . ' data-bs-theme="' . (!empty($opts['dark']) ? 'dark' : 'light') . '" data-osc-sidebar="' . $rail . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . ABS_PATH . 'oc-admin/themes/modern/css/main.css">'
        . '<link rel="stylesheet" href="file://' . ABS_PATH . 'oc-includes/assets/bootstrap-icons/bootstrap-icons.min.css"></head><body>'
        . '<script>window.osc = window.osc || {};</script>'
        . sidebar_shell_html(sidebar_menu_html((string) ($opts['menu'] ?? 'items'), (string) ($opts['sub'] ?? 'items_0')))
        . $scripts . '</body></html>');
    file_put_contents($tmp . '/driver.js', $driver);
    file_put_contents($tmp . '/run.mjs', <<<'MJS'
import {readFileSync} from 'node:fs';
const [pc, chrome, dir, cfg] = process.argv.slice(2);
const o = JSON.parse(cfg);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--hide-scrollbars',
    '--blink-settings=primaryHoverType=2,availableHoverTypes=2,primaryPointerType=4,availablePointerTypes=4']});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: o.width, height: o.height, deviceScaleFactor: o.scale || 1});
    await tab.emulateMediaFeatures([{name: 'prefers-reduced-motion', value: o.reduce ? 'reduce' : 'no-preference'}]);
    const errors = [];
    tab.on('pageerror', (e) => errors.push(String(e.message)));
    await tab.goto('file://' + dir + '/page.html', {waitUntil: 'load'});
    await tab.exposeFunction('pressKey', async (key) => { await tab.keyboard.press(key); });
    await tab.exposeFunction('mouseMove', async (x, y) => { await tab.mouse.move(x, y); });
    await tab.exposeFunction('mouseDown', async () => { await tab.mouse.down(); });
    await tab.exposeFunction('mouseUp', async () => { await tab.mouse.up(); });
    await tab.exposeFunction('snap', async (path) => { await tab.screenshot({path}); });
    const out = await tab.evaluate(async (src) => {
        const t = {};
        t.$ = (s, root) => (root || document).querySelector(s);
        t.$$ = (s, root) => [...(root || document).querySelectorAll(s)];
        t.frame = () => new Promise((d) => requestAnimationFrame(() => requestAnimationFrame(d)));
        t.wait = (ms) => new Promise((d) => setTimeout(d, ms));
        t.item = (label) => t.$$('#dashboard-menu > .nav-item').find((li) => li.querySelector('.nav-link a').textContent.trim().startsWith(label));
        t.row = (label) => t.item(label).querySelector(':scope > .nav-link');
        t.chevron = (label) => t.item(label).querySelector('.nav-chevron');
        t.panel = (label) => t.item(label).querySelector('.sidebar-submenu');
        t.box = (el) => { const b = el.getBoundingClientRect(); return {x: b.left, y: b.top, w: b.width, h: b.height, cx: b.left + b.width / 2, cy: b.top + b.height / 2}; };
        t.css = (el, prop, pseudo) => getComputedStyle(el, pseudo || null).getPropertyValue(prop);
        // A token as the browser resolves it on el, for comparing with a computed colour.
        t.token = (el, name, prop) => {
            const probe = document.createElement('span');
            probe.style.setProperty(prop || 'background-color', 'var(' + name + ')');
            el.appendChild(probe);
            const v = getComputedStyle(probe).getPropertyValue(prop || 'background-color');
            probe.remove();
            return v;
        };
        // The clock: every running animation (CSS transitions included) paused at ms from its start.
        t.running = () => document.getAnimations().filter((a) => a.playState === 'running' || a.playState === 'paused');
        t.seek = (ms) => { t.running().forEach((a) => { a.pause(); a.currentTime = ms; }); };
        t.finish = async () => {
            for (let i = 0; i < 5; i++) {
                const list = t.running();
                if (!list.length) { break; }
                list.forEach((a) => { try { a.finish(); } catch (e) { a.cancel(); } });
                await t.frame();
            }
            await t.frame();
        };
        t.click = async (el) => { const b = t.box(el); await mouseMove(b.cx, b.cy); await mouseDown(); await mouseUp(); };
        const r = {};
        try { await new Function('t', 'r', 'return (async () => {' + src + '})()')(t, r); } catch (e) { r.thrown = String(e && e.stack || e); }
        return r;
    }, readFileSync(dir + '/driver.js', 'utf8'));
    out.errors = errors;
    console.log(JSON.stringify(out));
} finally {
    await browser.close();
}
MJS);
    $cfg = (string) json_encode(array(
        'width' => (int) ($opts['width'] ?? 1440), 'height' => (int) ($opts['height'] ?? 900),
        'reduce' => !empty($opts['reduce']), 'scale' => (int) ($opts['scale'] ?? 1),
    ));
    $out = (string) shell_exec('node ' . escapeshellarg($tmp . '/run.mjs') . ' ' . escapeshellarg(test_puppeteer()) . ' ' . escapeshellarg($chrome)
        . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($tmp));
    $r = json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true);
    check('the browser run produced a reading', is_array($r), substr($out, 0, 600));

    return is_array($r) ? $r : array();
}

/* file end: ./tests/lib/sidebar-browser.php */
