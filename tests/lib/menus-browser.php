<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Runs the Appearance > Menus screen in headless Chrome: the real template (appearance/menus.php)
 * with the view stubs from tests/admin-menus.php, the built main.css and the real scripts. A driver
 * defines window.menuDriver(t) and returns a reading; menus_browser_run() hands it back.
 */

require_once __DIR__ . '/categories-browser.php';
require_once __DIR__ . '/categories-page.php';
require_once __DIR__ . '/menus-fixture.php';

foreach (array(
    'osc_register_script'            => '',
    'osc_asset_url_versioned'        => 'return (string) func_get_arg(0);',
    'osc_current_admin_theme_js_url' => 'return (string) func_get_arg(0);',
    'osc_language'                   => 'return "en_US";',
    'osc_current_admin_locale'       => 'return "en_US";',
    'osc_current_user_locale'        => 'return "en_US";',
) as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$args) { ' . $body . ' }');
    }
}
if (!class_exists('OSCLocale', false)) {
    class OSCLocale
    {
        public static function getInstance(): self
        {
            return new self();
        }

        /** @return array<int,array<string,string>> */
        public function listAllEnabled(): array
        {
            return array(array('pk_c_code' => 'en_US', 's_name' => 'English (US)'));
        }
    }
}

/**
 * The real Menus template as page HTML.
 *
 * @param array<string,mixed> $editor the $menuEditor view variable
 */
function menus_browser_html(array $editor): string
{
    $GLOBALS['categories_page_vars']  = array('menuEditor' => $editor);
    $GLOBALS['categories_page_shell'] = true;
    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/appearance/menus.php';

    return (string) ob_get_clean();
}

/**
 * The helpers a driver gets as t: item(label), grip(label), shape(), save(), plus drag(handle) and
 * key(el, key) from tree-pointer.js. save() runs the form's submit handler without leaving the
 * page and returns the posted items as nested JSON.
 */
function menus_browser_helpers_js(): string
{
    return <<<'JS'
(function () {
    var t = {};
    t.$ = function (s, r) { return (r || document).querySelector(s); };
    t.$$ = function (s, r) { return Array.from((r || document).querySelectorAll(s)); };
    t.wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
    t.frame = function () { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); };
    t.settle = async function (ms) { await t.frame(); await t.wait(ms === undefined ? 250 : ms); await t.frame(); };
    t.item = function (label) {
        return t.$$('li.menu-item').find(function (li) {
            var n = li.querySelector('.chip-name');
            return n && n.textContent.trim() === label;
        }) || null;
    };
    t.grip = function (label) {
        var li = t.item(label);
        return li ? li.querySelector('.chip-grip') : null;
    };
    // Every item in order as "label:depth"; depth is data-depth, or the list nesting on a nested list.
    t.shape = function () {
        return t.$$('li.menu-item').map(function (li) {
            var depth = 0;
            if (li.dataset.depth !== undefined) {
                depth = +li.dataset.depth;
            } else {
                for (var p = li.parentElement; p && p.id !== 'menu-items'; p = p.parentElement) {
                    if (p.tagName === 'UL' || p.tagName === 'OL') { depth++; }
                }
            }
            return li.querySelector('.chip-name').textContent.trim() + ':' + depth;
        });
    };
    t.save = function () {
        var form = t.$('#menu-form');
        form.addEventListener('submit', function (e) { e.preventDefault(); }, {once: true});
        form.requestSubmit();
        return JSON.parse(t.$('#menu-items-json').value || 'null');
    };
    if (window.treePointer) { window.treePointer.install(t); }
    window.menuHelpers = t;
}());
JS;
}

/**
 * Builds the page file for a run.
 *
 * @param array<int,array<string,mixed>>|null $items nested items; the fixture menu when null
 * @param array<string,mixed>                 $opts  dark, rtl, width, still, state, driver, editor (view overrides)
 */
function menus_browser_page(?array $items, array $opts): string
{
    $html = menus_browser_html(menus_fixture_editor($items, (array) ($opts['editor'] ?? array())));

    $theme  = ABS_PATH . 'oc-admin/themes/modern/';
    $assets = ABS_PATH . 'oc-includes/assets/';
    $dark   = !empty($opts['dark']);
    $rtl    = !empty($opts['rtl']) ? ' dir="rtl"' : '';
    $json   = static fn ($v): string => (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . $theme . 'css/main.css">'
        . '<link rel="stylesheet" href="file://' . $assets . 'bootstrap-icons/bootstrap-icons.min.css">'
        . (!empty($opts['still']) ? CATEGORIES_BROWSER_STILL_HEAD : '');

    $driver = isset($opts['driver']) && basename((string) $opts['driver']) !== 'categories-states.js'
        ? (string) file_get_contents((string) $opts['driver']) : '';
    $scripts = '<pre id="osc-out" hidden></pre>'
        . '<script>window.__state = ' . $json((string) ($opts['state'] ?? '')) . ';</script>'
        . '<script>' . file_get_contents(__DIR__ . '/categories-fake-env.js') . '</script>'
        . '<script>window.osc = window.osc || {}; osc.locales = {_default: "en_US", current: "en_US", codes: ["en_US"]}; osc.langs = {};</script>'
        . '<script src="file://' . $theme . 'js/osc.js"></script>'
        . '<script src="file://' . $theme . 'js/ui-osc.js"></script>'
        . '<script src="file://' . $theme . 'js/tree-drag.js"></script>'
        . '<script src="file://' . $assets . 'sortablejs/Sortable.min.js"></script>'
        . '<script src="file://' . $theme . 'js/menus.js"></script>'
        . '<script>' . file_get_contents(__DIR__ . '/tree-pointer.js') . '</script>'
        . '<script>' . menus_browser_helpers_js() . '</script>'
        . '<script>' . file_get_contents(__DIR__ . '/categories-states.js') . '</script>'
        . '<script>' . $driver . '</script>'
        . '<script>' . menus_browser_runner_js(isset($opts['driver']) || isset($opts['state'])) . '</script>';

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $scripts . '</body>', $html);
        $html = stripos($html, '</head>') !== false ? str_ireplace('</head>', $head . '</head>', $html) : $head . $html;
        $html = preg_replace('/<html\b/i', '<html' . $rtl . ' data-bs-theme="' . ($dark ? 'dark' : 'light') . '"', $html, 1) ?? $html;
    } else {
        $html = '<!doctype html><html lang="en"' . $rtl . ' data-bs-theme="' . ($dark ? 'dark' : 'light') . '"><head>' . $head . '</head><body>' . $html . $scripts . '</body></html>';
    }

    return $html;
}

/**
 * Waits for the list to be drawn, runs the driver (window.menuDriver, or the named state from
 * categories-states.js), and writes the reading as base64 JSON into pre#osc-out.
 */
function menus_browser_runner_js(bool $drive): string
{
    $drive = $drive ? 'true' : 'false';

    return <<<JS
(function () {
    function done(r) {
        var out = document.getElementById('osc-out');
        out.textContent = btoa(unescape(encodeURIComponent(JSON.stringify(r))));
        document.documentElement.setAttribute('data-done', '1');
    }
    window.addEventListener('load', async function () {
        var t = window.menuHelpers;
        var r = {};
        for (var i = 0; i < 40 && !document.querySelector('#menu-items > li'); i++) {
            await t.wait(50);
        }
        try {
            if ($drive) {
                var state = window.catStates && window.catStates[window.__state];
                if (typeof window.menuDriver === 'function') {
                    r = (await window.menuDriver(t)) || {};
                } else if (state) {
                    await state(t);
                    await t.settle(400);
                }
            }
        } catch (e) {
            r = {error: String(e && e.message ? e.message : e)};
        }
        r.ready = !!document.querySelector('#menu-items > li');
        r.shape = t.shape();
        r.errors = window.__errors;
        done(r);
    });
}());
JS;
}

/**
 * Loads the page in headless Chrome and returns the driver's reading, plus ready (the list is
 * drawn), shape (items as "label:depth") and errors (script errors with their source file).
 *
 * @param string                              $driverJs path to a script that sets window.menuDriver
 * @param array<int,array<string,mixed>>|null $items    nested items; the fixture menu when null
 * @param array<string,mixed>                 $opts     dark, rtl, width, editor
 *
 * @return array<string,mixed>
 */
function menus_browser_run(string $driverJs, ?array $items = null, array $opts = array()): array
{
    $chrome = categories_browser_chrome();
    $dir    = sys_get_temp_dir() . '/osc-menus-' . getmypid() . '-' . substr(md5($driverJs . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $page = $dir . '/page.html';
    file_put_contents($page, menus_browser_page($items, $opts + array('driver' => $driverJs)));

    $out = (string) shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
        . CATEGORIES_BROWSER_MOUSE
        . ' --window-size=' . (int) ($opts['width'] ?? 1440) . ',900'
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=20000 --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    $r = null;
    if (preg_match('#<pre id="osc-out"[^>]*>([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
        $r = json_decode((string) base64_decode($m[1], true), true);
    }
    check('the browser run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));

    return is_array($r) ? $r : array();
}

/**
 * Same as menus_browser_run() in real time through puppeteer-core, at a true phone width. The
 * driver also gets window.oscPress(key) and window.oscTouch(steps), where steps is a list of
 * [type, x, y] with type start, move or end, sent as real touch events. Returns null (skipped)
 * when node or puppeteer-core is not on this machine.
 *
 * @param array<int,array<string,mixed>>|null $items nested items; the fixture menu when null
 * @param array<string,mixed>                 $opts  dark, rtl, width, touch, editor
 *
 * @return array<string,mixed>|null
 */
function menus_browser_run_live(string $driverJs, ?array $items = null, array $opts = array()): ?array
{
    $chrome = categories_browser_chrome();
    $pc = test_puppeteer();
    if ($pc === '') {
        if ((string) getenv('CI') !== '') {
            check('node and puppeteer-core are available for the real-time run', false, 'run npm ci');
        }
        echo "  (skipped: no node or puppeteer-core for the real-time run)\n";

        return null;
    }
    $dir = sys_get_temp_dir() . '/osc-menus-live-' . getmypid() . '-' . substr(md5($driverJs . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/page.html', menus_browser_page($items, $opts + array('driver' => $driverJs)));
    file_put_contents($dir . '/run.mjs', 'const BLINK = ' . json_encode(trim(CATEGORIES_BROWSER_MOUSE)) . ";\n" . <<<'MJS'
const [pc, chrome, page, width, touch] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',900', touch === '1' ? '' : BLINK].filter(Boolean)});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 900, hasTouch: touch === '1', isMobile: touch === '1'});
    const cdp = await tab.createCDPSession();
    await tab.exposeFunction('oscPress', (key) => tab.keyboard.press(key));
    await tab.exposeFunction('oscTouch', async (steps) => {
        for (const [type, x, y] of steps) {
            if (type === 'wait') {
                await new Promise((done) => setTimeout(done, x));
                continue;
            }
            await cdp.send('Input.dispatchTouchEvent', {
                type: {start: 'touchStart', move: 'touchMove', end: 'touchEnd'}[type],
                touchPoints: type === 'end' ? [] : [{x, y}],
            });
        }
    });
    await tab.goto('file://' + page);
    await tab.waitForSelector('html[data-done="1"]', {timeout: 60000});
    process.stdout.write(await tab.$eval('#osc-out', (el) => el.textContent));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec('node ' . escapeshellarg($dir . '/run.mjs') . ' '
        . escapeshellarg($pc) . ' ' . escapeshellarg($chrome) . ' ' . escapeshellarg($dir . '/page.html')
        . ' ' . (int) ($opts['width'] ?? 1440) . ' ' . (empty($opts['touch']) ? '0' : '1') . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));
    $r = json_decode((string) base64_decode(trim($out), true), true);
    check('the real-time run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));

    return is_array($r) ? $r : array();
}

/**
 * Takes a PNG of the Menus screen. $state names a state in categories-states.js to drive into
 * first ('' = as loaded). Returns false when Chrome made no file.
 *
 * @param array<int,array<string,mixed>>|null $items nested items; the fixture menu when null
 */
function menus_browser_shot(string $state, int $width, bool $dark, string $png, ?array $items = null, bool $rtl = false): bool
{
    $chrome = categories_browser_chrome();
    $dir    = sys_get_temp_dir() . '/osc-menus-shot-' . getmypid() . '-' . substr(md5($state . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $page = $dir . '/page.html';
    file_put_contents($page, menus_browser_page($items, array(
        'state' => $state, 'dark' => $dark, 'rtl' => $rtl, 'still' => true,
    )));
    @mkdir(dirname($png), 0775, true);
    @unlink($png);
    shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
        . CATEGORIES_BROWSER_MOUSE . CATEGORIES_BROWSER_STILL
        . ' --hide-scrollbars --force-device-scale-factor=1 --window-size=' . $width . ',' . ($width < 600 ? 844 : 900)
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=20000 --screenshot=' . escapeshellarg($png) . ' ' . escapeshellarg('file://' . $page) . ' >/dev/null 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    return is_file($png);
}

/* file end: ./tests/lib/menus-browser.php */
