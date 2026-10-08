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
 * WidgetStore writes for the Appearance > Widgets screen, and PageService::delete, which
 * removes a page together with its locale rows and its page-builder widgets.
 *
 * Usage:  php tests/models/widgetstore.php          (standalone, own scratch database)
 *         php tests/run-models.php widgetstore      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\model\Resource;
use mindstellar\pages\PageService;
use mindstellar\widgets\WidgetStore;

$admin = scratchdb_session('osc_models_widgetstore');
// Stand-ins for the helpers ResourceUploader and osc_add_hook reach, as in itemresource.php.
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return ABS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

$table = DB_TABLE_PREFIX . 't_widget';
$locale = seed_locale($admin);

$count = static function (string $where) use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table WHERE $where")->fetch_assoc()['c'];
};
$row = static fn (array $extra = array()): array => $extra + array(
    's_location'    => 'sidebar',
    'e_kind'        => 'html',
    's_description' => 'W',
    's_content'     => '',
);

harness_section('WidgetStore: add, find, nextOrder');

$admin->query("DELETE FROM $table");
pin('an empty location orders from 0', 0, WidgetStore::nextOrder('sidebar'));
$w1 = WidgetStore::add($row(array('i_order' => 0, 's_description' => 'one')));
$w2 = WidgetStore::add($row(array('i_order' => 1)));
$w3 = WidgetStore::add($row(array('s_location' => 'footer')));
check('add returns the new id', $w1 > 0 && $w2 > $w1);
pin('nextOrder is max + 1', 2, WidgetStore::nextOrder('sidebar'));
pin('find returns the row as strings', 'one', WidgetStore::find($w1)['s_description'] ?? null);
pin('find returns null for an unknown id', null, WidgetStore::find(999999));
pin('idsAt lists the location', array($w1, $w2), WidgetStore::idsAt('sidebar'));

harness_section('WidgetStore: update, moveTo, reorder');

pin('update reports one changed row', 1, WidgetStore::update($w1, array('s_description' => 'changed')));
pin('an unchanged update reports 0, not an error', 0, WidgetStore::update($w1, array('s_description' => 'changed')));
pin('the update landed', 'changed', WidgetStore::find($w1)['s_description']);

WidgetStore::moveTo($w3, 'sidebar');
pin('moveTo changes the location', 'sidebar', WidgetStore::find($w3)['s_location']);
pin('reorder commits', true, WidgetStore::reorder(array($w3, $w1, $w2)));
pin(
    'reorder writes each id its position',
    array($w3 => '0', $w1 => '1', $w2 => '2'),
    array_column(iterator_to_array($admin->query("SELECT pk_i_id, i_order FROM $table ORDER BY i_order")), 'i_order', 'pk_i_id')
);

/* A write must drop the cached findByLocation read. */
Widget::getInstance()->findByLocation('sidebar');
WidgetStore::update($w2, array('s_description' => 'fresh'));
$fresh = array_column(Widget::getInstance()->findByLocation('sidebar'), 's_description', 'pk_i_id');
pin('a write invalidates the widget cache group', 'fresh', $fresh[$w2] ?? null);

/* Each write pinned the same way: warm the cache, write, re-read. */
$cachedIds = static fn (string $location): array => array_map(
    'intval',
    array_column(Widget::getInstance()->findByLocation($location), 'pk_i_id')
);
$cachedIds('cache');
$c1 = WidgetStore::add($row(array('s_location' => 'cache', 'i_order' => 0)));
pin('add invalidates the widget cache', array($c1), $cachedIds('cache'));
$c2 = WidgetStore::add($row(array('s_location' => 'cache', 'i_order' => 1)));
$cachedIds('cache');
WidgetStore::reorder(array($c2, $c1));
pin('reorder invalidates the widget cache', array($c2, $c1), $cachedIds('cache'));
WidgetStore::reorderWithin('cache', array($c1, $c2));
pin('reorderWithin invalidates the widget cache', array($c1, $c2), $cachedIds('cache'));
$cachedIds('cache.b');
WidgetStore::moveTo($c2, 'cache.b');
pin('moveTo invalidates the widget cache', array($c1), $cachedIds('cache'));
pin('moveTo shows the widget at its new location', array($c2), $cachedIds('cache.b'));
WidgetStore::delete($c1);
pin('delete invalidates the widget cache', array(), $cachedIds('cache'));
$cachedIds('cache.b');
WidgetStore::deleteByLocation('cache.b');
pin('deleteByLocation invalidates the widget cache', array(), $cachedIds('cache.b'));

harness_section('WidgetStore: delete');

pin('delete removes one row', 1, WidgetStore::delete($w2));
pin('the row is gone', 0, $count("pk_i_id = $w2"));
WidgetStore::add($row(array('s_location' => 'page.7')));
WidgetStore::add($row(array('s_location' => 'page.7')));
pin('deleteByLocation removes every widget there', 2, WidgetStore::deleteByLocation('page.7'));
pin('other locations keep theirs', 2, $count("s_location = 'sidebar'"));

harness_section('PageService::delete');

$pages = Page::getInstance();
$pages->insert(
    array('s_internal_name' => 'gone', 'b_indelible' => 0, 'b_link' => 1, 's_meta' => ''),
    array($locale => array('s_title' => 'Gone', 's_text' => 'x'))
);
$pageId = (int) $pages->findByInternalName('gone')['pk_i_id'];
WidgetStore::add($row(array('s_location' => 'page.' . $pageId)));
$resources = new Resource();
$resources->insertResource(Resource::OWNER_PAGE, $pageId, array('s_name' => 'img'));
$keptResource = $resources->insertResource(Resource::OWNER_PAGE, $pageId + 1000, array('s_name' => 'other'));
$resourceCount = static fn (string $where): int => (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_resource WHERE $where")->fetch_assoc()['c'];

$fired = array();
osc_add_hook('before_delete_page', static function ($id) use (&$fired) {
    $fired[] = 'before:' . $id;
});
osc_add_hook('after_delete_page', static function ($id) use (&$fired) {
    $fired[] = 'after:' . $id;
});

pin('deleting a page reports one removed row', 1, PageService::make()->delete($pageId));
pin('the hooks fire around it', array('before:' . $pageId, 'after:' . $pageId), $fired);
pin('the page row is gone', 0, (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_pages WHERE pk_i_id = $pageId")->fetch_assoc()['c']);
pin('its locale rows are gone', 0, (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_pages_description WHERE fk_i_pages_id = $pageId")->fetch_assoc()['c']);
pin('its widgets are gone', 0, $count("s_location = 'page.$pageId'"));
pin('other widgets stay', 2, $count("s_location = 'sidebar'"));
pin('its resources are gone', 0, $resourceCount("s_owner_type = 'page' AND i_owner_id = $pageId"));
pin('another page keeps its resources', 1, $resourceCount("pk_i_id = $keptResource"));

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('an unknown id removes nothing', 0, PageService::make()->delete(999999));
error_reporting($prevLevel);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/widgetstore.php */
