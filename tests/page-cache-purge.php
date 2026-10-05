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
 * The whole-cache purge: osc_purge_page_cache() collects reasons, one `page_cache_purge`
 * action fires at the end of the request, and the Docker fallback sends one PURGE.
 * Also pins which changes ask for it.
 *
 * DB-free.  Usage:  php tests/page-cache-purge.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

$GLOBALS['hooks']   = array();
$GLOBALS['fired']   = array();
$GLOBALS['enabled'] = true;

function osc_add_hook($hook, $callback = null, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $callback;
}
function osc_add_filter($hook, $callback = null, $priority = 5)
{
    osc_add_hook($hook, $callback, $priority);
}
function osc_run_hook($hook, ...$args)
{
    $GLOBALS['fired'][] = array('hook' => $hook, 'args' => $args);
    foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) {
        $callback(...$args);
    }
}
function osc_apply_filter($hook, $content = '', ...$args)
{
    return $hook === 'page_cache_purge_enabled' ? $GLOBALS['enabled'] : $content;
}
function osc_base_url($withIndex = false)
{
    return 'https://shop.example.test/';
}
function osc_settings_save($pageId)
{
    return $GLOBALS['save_result'];
}
function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once __DIR__ . '/lib/harness.php';

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$log = tempnam(sys_get_temp_dir(), 'purge-log');
ini_set('log_errors', '1');
ini_set('error_log', $log);

/** Forget the pending purge and everything recorded, as a new request would. */
function fresh(): void
{
    unset($GLOBALS['osc_page_cache_purge'], $GLOBALS['osc_page_cache_purge_running']);
    $GLOBALS['fired']   = array();
    $GLOBALS['enabled'] = true;
    unset($GLOBALS['hooks']['page_cache_purge']);
    putenv('OSC_PAGE_CACHE_PURGE_URL');
    putenv('OSC_MICROCACHE');
    file_put_contents($GLOBALS['log'], '');
}

/** @return array<int,array> the page_cache_purge firings */
function purges(): array
{
    return array_values(array_filter($GLOBALS['fired'], static function ($f) {
        return $f['hook'] === 'page_cache_purge';
    }));
}

/** A mock client that records every request and answers $status. */
function recorder(array &$requests, int $status): MockHttpClient
{
    return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $status) {
        $requests[] = array(
            'method'   => $method,
            'url'      => $url,
            'headers'  => $options['headers'] ?? array(),
            'no_proxy' => $options['no_proxy'] ?? null,
        );

        // 0 stands for no answer at all: the connection failed.
        return $status === 0
            ? new MockResponse('', array('error' => 'Connection refused'))
            : new MockResponse('', array('http_code' => $status));
    });
}

harness_section('one hook per request');

fresh();
osc_purge_page_cache('theme');
check('a call leaves a purge pending', osc_page_cache_purge_pending());
pin('nothing fires before the request ends', 0, count(purges()));
osc_page_cache_purge_flush();
$p = purges();
pin('one call fires one hook', 1, count($p));
pin('...carrying its reason', array('theme'), $p[0]['args'][0] ?? null);
check('...and nothing is pending after', !osc_page_cache_purge_pending());
osc_page_cache_purge_flush();
pin('a second flush fires nothing', 1, count(purges()));
osc_purge_page_cache('restore');
check('a call after the flush is not dropped', osc_page_cache_purge_pending());
osc_page_cache_purge_flush();
$p = purges();
pin('...it fires a flush of its own', 2, count($p));
pin('...with only its own reason', array('restore'), $p[1]['args'][0] ?? null);

fresh();
$GLOBALS['hooks']['page_cache_purge'][] = static function () {
    osc_purge_page_cache('again');
};
osc_purge_page_cache('theme');
osc_page_cache_purge_flush();
osc_page_cache_purge_flush();
pin('a listener asking for a purge does not start another', 1, count(purges()));
check('...and leaves nothing pending', !osc_page_cache_purge_pending());

fresh();
foreach (array('settings', 'plugin', 'settings', '', ' plugin ', 'theme') as $r) {
    osc_purge_page_cache($r);
}
osc_page_cache_purge_flush();
$p = purges();
pin('many calls fire one hook', 1, count($p));
pin('...with each reason once', array('settings', 'plugin', 'theme'), $p[0]['args'][0] ?? null);

fresh();
osc_page_cache_purge_flush();
pin('nothing pending fires nothing', 0, count(purges()));

fresh();
osc_purge_page_cache();
osc_page_cache_purge_flush();
pin('a call with no reason still fires, with an empty list', array(), purges()[0]['args'][0] ?? null);

fresh();
$GLOBALS['enabled'] = false;
osc_purge_page_cache('theme');
osc_page_cache_purge_flush();
pin('page_cache_purge_enabled = false fires nothing', 0, count(purges()));

harness_section('the flush runs last, with the session released');

// Shutdown order and the session lock only exist in a real request end, so a child
// process runs one.
$script = tempnam(sys_get_temp_dir(), 'purge-order');
file_put_contents($script, '<?php
function osc_add_hook($h, $c = null, $p = 5) {}
function osc_add_filter($h, $c = null, $p = 5) {}
function osc_apply_filter($h, $c = "", ...$a) { return $c; }
function osc_run_hook($hook, ...$a) {
    if ($hook === "page_cache_purge") {
        echo "purge:" . implode(",", $a[0]) . ":"
            . (session_status() === PHP_SESSION_ACTIVE ? "locked" : "released") . "\n";
    }
}
require ' . var_export(ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php', true) . ';
register_shutdown_function(function () { echo "page\n"; });
ini_set("session.save_path", sys_get_temp_dir());
ini_set("session.use_cookies", "0");
session_start();
osc_purge_page_cache("theme");
register_shutdown_function(function () { echo "late\n"; osc_purge_page_cache("cron"); });
');
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
@unlink($script);
pin(
    'after the page and every later shutdown function, with their reasons',
    "page\nlate\npurge:theme,cron:released\n",
    $out
);

harness_section('a failing listener');

fresh();
$GLOBALS['hooks']['page_cache_purge'][] = static function () {
    throw new RuntimeException('listener broke');
};
osc_purge_page_cache('theme');
$threw = false;
try {
    osc_page_cache_purge_flush();
} catch (Throwable $e) {
    $threw = true;
}
check('does not throw out of the flush', !$threw);
check('...and is logged', strpos((string)file_get_contents($log), 'listener broke') !== false);

harness_section('the Docker fallback');

fresh();
$requests = array();
osc_purge_page_cache('theme');
osc_page_cache_purge_flush(recorder($requests, 200));
pin('no OSC_PAGE_CACHE_PURGE_URL, no request', 0, count($requests));

fresh();
putenv('OSC_PAGE_CACHE_PURGE_URL=http://127.0.0.1/index.php');
$requests = array();
osc_purge_page_cache('theme');
osc_purge_page_cache('plugin');
osc_page_cache_purge_flush(recorder($requests, 200));
pin('with it set, exactly one request', 1, count($requests));
pin('...a PURGE', 'PURGE', $requests[0]['method'] ?? null);
pin('...to that URL', 'http://127.0.0.1/index.php', $requests[0]['url'] ?? null);
check(
    '...with the site host',
    in_array('Host: shop.example.test', $requests[0]['headers'] ?? array(), true)
);
pin('...never through a proxy', '*', $requests[0]['no_proxy'] ?? null);
pin('...and nothing logged on 200', '', (string)file_get_contents($log));

fresh();
putenv('OSC_PAGE_CACHE_PURGE_URL=http://127.0.0.1/index.php');
$GLOBALS['hooks']['page_cache_purge'][] = static function () {
    throw new RuntimeException('listener broke');
};
$requests = array();
osc_purge_page_cache('theme');
osc_page_cache_purge_flush(recorder($requests, 200));
pin('a failing listener does not stop the fallback', 1, count($requests));

$purge = new \mindstellar\cache\PagePurge(recorder($requests, 412));
check('412 (nothing cached) counts as done', $purge->purge('http://127.0.0.1/index.php', 'a.test'));
$purge = new \mindstellar\cache\PagePurge(recorder($requests, 404));
check('404 counts as done', $purge->purge('http://127.0.0.1/index.php', 'a.test'));

foreach (array(403, 0) as $status) {
    fresh();
    putenv('OSC_PAGE_CACHE_PURGE_URL=http://127.0.0.1/index.php');
    $requests = array();
    osc_purge_page_cache('theme');
    $threw = false;
    try {
        osc_page_cache_purge_flush(recorder($requests, $status));
    } catch (Throwable $e) {
        $threw = true;
    }
    check("an answer of $status does not throw", !$threw);
    check("...and is logged", (string)file_get_contents($log) !== '');
}
$purge = new \mindstellar\cache\PagePurge(recorder($requests, 403));
check('403 reports failure', !$purge->purge('http://127.0.0.1/index.php', 'a.test'));

harness_section('purge address');

fresh();
pin('both unset: no address', '', osc_page_cache_purge_url());
putenv('OSC_PAGE_CACHE_PURGE_URL=http://10.0.0.1/p');
putenv('OSC_MICROCACHE=1');
pin('the env URL wins', 'http://10.0.0.1/p', osc_page_cache_purge_url());
putenv('OSC_PAGE_CACHE_PURGE_URL');
foreach (array('1', 'on', 'true', 'yes') as $on) {
    putenv("OSC_MICROCACHE=$on");
    pin("OSC_MICROCACHE=$on gives the internal address", 'http://127.0.0.1:8089/', osc_page_cache_purge_url());
}
foreach (array('0', 'off', '') as $off) {
    putenv("OSC_MICROCACHE=$off");
    pin("OSC_MICROCACHE='$off' gives none", '', osc_page_cache_purge_url());
}

fresh();
putenv('OSC_MICROCACHE=1');
$requests = array();
osc_purge_page_cache('theme');
osc_page_cache_purge_flush(recorder($requests, 200));
pin('flush uses the derived address', 'http://127.0.0.1:8089/', $requests[0]['url'] ?? null);
fresh();

harness_section('changes that ask for a purge');

$wired = array(
    'theme_activate'          => 'theme',
    'after_plugin_activate'   => 'plugin',
    'after_plugin_deactivate' => 'plugin',
    'after_plugin_uninstall'  => 'plugin',
    'after_delete_widget'     => 'widget',
    'add_category'            => 'category',
    'edited_category'         => 'category',
    'after_delete_category'   => 'category',
    'edited_category_order'   => 'category',
    'after_delete_page'       => 'page',
);
foreach ($wired as $hook => $reason) {
    fresh();
    osc_run_hook($hook, 1, 0);
    pin("$hook asks for one", array($reason), $GLOBALS['osc_page_cache_purge'] ?? null);
}
foreach (array('admin_form_after_save', 'after_rewrite_rules') as $hook) {
    check("$hook is not wired", empty($GLOBALS['hooks'][$hook]));
}

fresh();
$GLOBALS['save_result'] = array('errors' => array(), 'updated' => 2, 'values' => array(), 'id' => null);
\mindstellar\admin\form\CoreSettings::attempt('settings_main');
check('a settings save that changed something asks for one', osc_page_cache_purge_pending());

fresh();
$GLOBALS['save_result'] = array('errors' => array(), 'updated' => 0, 'values' => array(), 'id' => null);
\mindstellar\admin\form\CoreSettings::attempt('settings_main');
check('...one that changed nothing does not', !osc_page_cache_purge_pending());

fresh();
$GLOBALS['save_result'] = array('errors' => array('bad'), 'updated' => 1, 'values' => array(), 'id' => null);
\mindstellar\admin\form\CoreSettings::attempt('settings_main');
check('...nor one that was refused', !osc_page_cache_purge_pending());

// Controllers need the whole app to run, so their calls are pinned by source.
$direct = array(
    'controller/admin/CAdminTools.php'                        => array('maintenance', 3),
    'controller/admin/CAdminLanguages.php'                    => array('language', 4),
    'currency/CurrencyService.php'                             => array('currency', 3),
    'controller/admin/CAdminAppearance.php'                   => array('widget', 5),
    'category/CategoryService.php'                             => array('category', 1),
    'controller/admin/CAdminPages.php'                        => array('page', 1),
    'upgrade/Upgrade.php'                                     => array('upgrade', 1),
    'backup/BackupJobs.php'                                   => array('restore', 1),
    'backup/BackupService.php'                                => array('restore', 1),
);
foreach ($direct as $file => [$reason, $count]) {
    $src = (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/' . $file);
    pin("$file asks $count time(s)", $count, substr_count($src, "osc_purge_page_cache('$reason')"));
}

fresh();
@unlink($log);

exit(harness_result());
