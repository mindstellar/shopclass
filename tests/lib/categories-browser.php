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
 * Runs the Categories screen in headless Chrome: a static page from the real template output,
 * the built main.css and the real scripts, with a fake fetch (categories-fake-env.js). A driver
 * defines window.catDriver(t) and returns a reading; categories_browser_run() hands it back.
 * categories_browser_shot() takes a picture of a named state (categories-states.js).
 */

require_once __DIR__ . '/categories-fixture.php';

/**
 * Finds Chrome; stops the test (skip, or fail on CI) when there is none.
 */
function categories_browser_chrome(): string
{
    $chrome = test_browser();
    if ($chrome === '' && (string) getenv('CI') !== '') {
        check('a browser is available to drive the script', false, 'no Chrome or Chromium, so the browser assertions did not run');
    }
    if ($chrome === '') {
        echo "  (skipped: no Chrome or Chromium on this machine)\n";
        exit(harness_result());
    }

    return $chrome;
}

/**
 * The real drawer markup (categories/iframe.php) for the fake fetch. The drawer and page renderers
 * both define __get, so the drawer is rendered in a child process.
 *
 * @param array<string,mixed> $category
 * @param array<string,mixed> $vars
 */
function categories_browser_drawer_html(array $category, array $vars = array()): string
{
    $code = 'define("ABS_PATH", ' . var_export(ABS_PATH, true) . ');'
        . 'require ABS_PATH . "tests/lib/categories-drawer.php";'
        . '$a = json_decode((string) stream_get_contents(STDIN), true);'
        . 'echo categories_drawer_html($a[0], $a[1]);';
    $proc = proc_open(array(PHP_BINARY, '-r', $code), array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return '';
    }
    fwrite($pipes[0], (string) json_encode(array($category, $vars)));
    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $html;
}

/**
 * Flattens a toTreeAll tree to id => row (without the nested children).
 *
 * @param array<int,array<string,mixed>> $tree
 *
 * @return array<int,array<string,mixed>>
 */
function categories_browser_index(array $tree): array
{
    $out = array();
    $walk = function (array $rows) use (&$walk, &$out): void {
        foreach ($rows as $row) {
            $kids = $row['categories'] ?? array();
            unset($row['categories']);
            $out[(int) $row['pk_i_id']] = $row;
            $walk($kids);
        }
    };
    $walk($tree);

    return $out;
}

/**
 * Edit-drawer HTML for the ids the page may open, keyed as the fake fetch looks them up:
 * an id, 'new', or 'new:{parent id}'.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts
 *
 * @return array<string,string>
 */
function categories_browser_drawers(array $tree, array $opts): array
{
    $index = categories_browser_index($tree);
    $names = array();
    foreach ($index as $id => $row) {
        $names[(string) $row['s_name']] = $id;
    }
    $ids = $opts['drawer_ids'] ?? array_values(array_filter(array(
        $names['Vehicles'] ?? null,
        $names['Cars'] ?? null,
    )));
    $below = function (int $id) use ($index): int {
        $n = 0;
        $stack = array($id);
        while ($stack) {
            $cur = array_pop($stack);
            foreach ($index as $cid => $row) {
                if ((int) ($row['fk_i_parent_id'] ?? 0) === $cur) {
                    $n++;
                    $stack[] = $cid;
                }
            }
        }

        return $n;
    };
    $langs = array(
        array('pk_c_code' => 'en_US', 's_name' => 'English (US)', 's_short_name' => 'English'),
        array('pk_c_code' => 'en_IN', 's_name' => 'English (India)', 's_short_name' => 'English'),
        array('pk_c_code' => 'hi_IN', 's_name' => 'Hindi', 's_short_name' => 'Hindi'),
    );
    $make = function (?array $row, ?array $parent) use ($below, $langs): string {
        $category = $row ?? array(
            'pk_i_id' => 0, 'fk_i_parent_id' => null,
            'i_expiration_days' => (int) ($parent['i_expiration_days'] ?? 0),
            'b_enabled' => 1, 'b_price_enabled' => (int) ($parent['b_price_enabled'] ?? 1),
            's_name' => '', 's_description' => '', 's_slug' => '',
        );
        $locale = array();
        foreach ($langs as $l) {
            $locale[$l['pk_c_code']] = array('s_name' => $l['pk_c_code'] === 'en_US' ? (string) $category['s_name'] : '', 's_description' => '', 's_slug' => '');
        }
        $category['locale'] = $locale;
        $count = $row ? $below((int) $row['pk_i_id']) : 0;
        $vars = array(
            'category' => $category, 'languages' => $langs, 'has_subcategories' => $count > 0,
            'subcategory_count' => $count,
            'parent' => $parent ? array('id' => (int) $parent['pk_i_id'], 'name' => (string) $parent['s_name']) : null,
        );

        return categories_browser_drawer_html($category, $vars);
    };

    $out = array('new' => $make(null, null));
    foreach ($ids as $id) {
        $row = $index[(int) $id] ?? null;
        if ($row === null) {
            continue;
        }
        $parent = $row['fk_i_parent_id'] ? ($index[(int) $row['fk_i_parent_id']] ?? null) : null;
        $out[(string) $id]       = $make($row, $parent);
        $out['new:' . $id]       = $make(null, $row);
    }

    return $out;
}

/**
 * The helpers a driver or state gets as t: row(name), tab(label), act(name, action), click,
 * type, tick, settle, frame, $ and $$.
 */
function categories_browser_helpers_js(): string
{
    return <<<'JS'
(function () {
    var t = {};
    t.$ = function (s, r) { return (r || document).querySelector(s); };
    t.$$ = function (s, r) { return Array.from((r || document).querySelectorAll(s)); };
    t.wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
    t.frame = function () { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); };
    t.settle = async function (ms) { await t.frame(); await t.wait(ms === undefined ? 250 : ms); await t.frame(); };
    t.row = function (name) {
        return t.$$('tr.cat-row').find(function (tr) {
            var n = tr.querySelector('.cat-name');
            return n && n.textContent.trim() === name;
        }) || null;
    };
    t.tab = function (label) {
        return t.$$('.cat-tabs a').find(function (a) { return a.textContent.indexOf(label) === 0; }) || null;
    };
    t.act = function (name, action) {
        var tr = t.row(name);
        return tr ? tr.querySelector('[data-cat-act="' + action + '"]') : null;
    };
    // Waits until the drawer is open and its form has loaded.
    t.drawer = async function () {
        for (var i = 0; i < 60; i++) {
            var app = t.$('.categories-app');
            if (app && app.classList.contains('drawer-open') && t.$('#catDrawerBody form')) { break; }
            await t.wait(50);
        }
        await t.settle(400);
    };
    t.shown = function () { return t.$$('tr.cat-row').filter(function (tr) { return !tr.hidden; }); };
    t.click = async function (el) {
        if (!el) { throw new Error('click: no such element'); }
        el.click();
        await t.settle();
    };
    t.type = async function (sel, text) {
        var el = t.$(sel);
        el.value = text;
        el.dispatchEvent(new Event('input', {bubbles: true}));
        await t.settle(400);
    };
    t.tick = async function (tr) {
        var box = tr.querySelector('.col-bulkactions input');
        box.checked = true;
        box.dispatchEvent(new Event('change', {bubbles: true}));
        await t.settle();
    };
    window.catHelpers = t;
}());
JS;
}

/**
 * Builds the page file for a run. Needs categories_page_html() from tests/lib/categories-page.php.
 *
 * @param array<int,array<string,mixed>> $tree
 * @param array<string,mixed>            $opts dark, width, status, depthLimit, state, drawer_ids, answers, driver
 */
function categories_browser_page(array $tree, array $opts): string
{
    if (!function_exists('categories_page_html')) {
        require_once __DIR__ . '/categories-page.php';
    }
    $html = categories_page_html($tree, array(
        'status'     => (string) ($opts['status'] ?? ''),
        'depthLimit' => $opts['depthLimit'] ?? null,
        'shell'      => true,
    ));

    $theme  = ABS_PATH . 'oc-admin/themes/modern/';
    $assets = ABS_PATH . 'oc-includes/assets/';
    $dark   = !empty($opts['dark']);
    $json   = static fn ($v): string => (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . $theme . 'css/main.css">'
        . '<link rel="stylesheet" href="file://' . $assets . 'bootstrap-icons/bootstrap-icons.min.css">'
        // A screenshot is of the settled screen, never of a drawer half way in.
        . (!empty($opts['still']) ? '<style>*,*::before,*::after{transition:none!important;animation:none!important}</style>' : '');

    $driver = isset($opts['driver']) && basename((string) $opts['driver']) !== 'categories-states.js'
        ? (string) file_get_contents((string) $opts['driver']) : '';
    $scripts = '<pre id="osc-out" hidden></pre>'
        . '<script>window.__drawers = ' . $json(categories_browser_drawers($tree, $opts)) . ';window.__state = ' . $json((string) ($opts['state'] ?? '')) . ';</script>'
        . '<script>' . file_get_contents(__DIR__ . '/categories-fake-env.js') . '</script>'
        . '<script>window.__answers = Object.assign(window.__answers, ' . $json((object) ($opts['answers'] ?? array())) . ');</script>'
        . '<script>window.osc = window.osc || {}; osc.locales = {_default: "en_US", current: "en_US", codes: ["en_US", "en_IN", "hi_IN"]}; osc.langs = {};</script>'
        . '<script src="file://' . $theme . 'js/osc.js"></script>'
        . '<script src="file://' . $theme . 'js/ui-osc.js"></script>'
        . '<script src="file://' . $assets . 'sortablejs/Sortable.min.js"></script>'
        . '<script src="file://' . $theme . 'js/categories.js"></script>'
        . '<script>' . categories_browser_helpers_js() . '</script>'
        . '<script>' . file_get_contents(__DIR__ . '/categories-states.js') . '</script>'
        . '<script>' . $driver . '</script>'
        . '<script>' . categories_browser_runner_js(isset($opts['driver']) || isset($opts['state'])) . '</script>';

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $scripts . '</body>', $html);
        $html = stripos($html, '</head>') !== false ? str_ireplace('</head>', $head . '</head>', $html) : $head . $html;
        $html = preg_replace('/<html\b/i', '<html data-bs-theme="' . ($dark ? 'dark' : 'light') . '"', $html, 1) ?? $html;
    } else {
        $html = '<!doctype html><html lang="en" data-bs-theme="' . ($dark ? 'dark' : 'light') . '"><head>' . $head . '</head><body>' . $html . $scripts . '</body></html>';
    }

    return $html;
}

/**
 * Waits for the screen to be ready, runs the driver, and writes the reading as base64 JSON into
 * pre#osc-out. $drive is false for a plain load, which still reports errors and the ready flag.
 */
function categories_browser_runner_js(bool $drive): string
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
        var t = window.catHelpers;
        var app = document.querySelector('.categories-app');
        var r = {};
        for (var i = 0; i < 40 && app && app.dataset.ready !== '1'; i++) {
            await t.wait(50);
        }
        try {
            if ($drive && typeof window.catDriver === 'function') {
                r = (await window.catDriver(t)) || {};
            }
        } catch (e) {
            r = {error: String(e && e.message ? e.message : e)};
        }
        r.ready = !!app && app.dataset.ready === '1';
        r.errors = window.__errors;
        r.calls = window.__calls;
        done(r);
    });
}());
JS;
}

/**
 * Loads the page in headless Chrome and returns the driver's reading, plus ready (the screen set
 * data-ready), errors (script errors with their source file) and calls (every fetch).
 *
 * @param string                         $driverJs path to a script that sets window.catDriver
 * @param array<int,array<string,mixed>> $tree     a toTreeAll tree
 * @param array<string,mixed>            $opts     dark, width, status, depthLimit, drawer_ids, answers
 *
 * @return array<string,mixed>
 */
function categories_browser_run(string $driverJs, array $tree, array $opts = array()): array
{
    $chrome = categories_browser_chrome();
    $dir    = sys_get_temp_dir() . '/osc-categories-' . getmypid() . '-' . substr(md5($driverJs . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $page = $dir . '/page.html';
    file_put_contents($page, categories_browser_page($tree, $opts + array('driver' => $driverJs)));

    $out = (string) shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
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
 * Takes a PNG of one named state. States: default, search, disabled-tab, bulk-selected,
 * drawer-edit, drawer-addsub, move, empty, big. Returns false when Chrome made no file.
 */
function categories_browser_shot(string $state, int $width, bool $dark, string $png): bool
{
    $chrome = categories_browser_chrome();
    $tree = match ($state) {
        'empty' => array(),
        'big', 'big-scroll' => categories_fixture_big(),
        default => categories_fixture_demo(),
    };
    $dir = sys_get_temp_dir() . '/osc-categories-shot-' . getmypid() . '-' . substr(md5($state . microtime()), 0, 6);
    @mkdir($dir, 0700, true);
    $page = $dir . '/page.html';
    file_put_contents($page, categories_browser_page($tree, array(
        'state' => $state, 'dark' => $dark, 'width' => $width, 'still' => true,
        'status' => $state === 'disabled-tab' ? 'off' : '',
    )));
    @mkdir(dirname($png), 0775, true);
    @unlink($png);
    shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
        . ' --hide-scrollbars --force-device-scale-factor=1 --window-size=' . $width . ',' . ($width < 600 ? 844 : 900)
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=20000 --screenshot=' . escapeshellarg($png) . ' ' . escapeshellarg('file://' . $page) . ' >/dev/null 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    return is_file($png);
}

/* file end: ./tests/lib/categories-browser.php */
