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
    unset($GLOBALS['osc_page_cache_purge'], $GLOBALS['osc_page_cache_purged']);
    $GLOBALS['fired']   = array();
    $GLOBALS['enabled'] = true;
    unset($GLOBALS['hooks']['page_cache_purge']);
    putenv('OSC_PAGE_CACHE_PURGE_URL');
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
        $requests[] = array('method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? array());

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
osc_purge_page_cache('late');
osc_page_cache_purge_flush();
pin('a call after the flush fires nothing', 1, count(purges()));

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
@unlink($log);

exit(harness_result());
