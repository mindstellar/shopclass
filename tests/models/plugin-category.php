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
 * The categories a plugin is limited to live as one list per plugin in t_key_value.
 * Migration 0067 moves the old t_plugin_category rows; uninstalling a plugin and deleting a
 * category clean the lists.
 *
 * Usage:  php tests/models/plugin-category.php        (standalone, own scratch database)
 *         php tests/run-models.php plugin-category    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_models_plugin_category');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

Preference::newInstance();

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
$cars   = seed_category($admin, 'Cars', null, $locale);
$vans   = seed_category($admin, 'Vans', $cars, $locale);
$bikes  = seed_category($admin, 'Bikes', null, $locale);
$num    = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
$fresh  = static function (): PluginCategory {
    // A new request: the per-request copy starts empty.
    $prop = new ReflectionProperty(PluginCategory::class, 'lists');
    $prop->setAccessible(true);
    $prop->setValue(null, array());

    return new PluginCategory();
};

harness_section('migration 0067');
$admin->query("CREATE TABLE {$p}t_plugin_category (s_plugin_name VARCHAR(40) NOT NULL, fk_i_category_id INT UNSIGNED NOT NULL, PRIMARY KEY (s_plugin_name, fk_i_category_id))");
$admin->query("INSERT INTO {$p}t_plugin_category VALUES ('digital-goods', $bikes), ('digital-goods', $cars), ('cars-attr', $cars)");
$migrate = static fn () => (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0067_plugin_categories_to_key_value.php')->up(Connection::instance());
$migrate();
pin('the table is dropped', 0, $num("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$p}t_plugin_category'"));
pin('each plugin keeps its list, sorted', array((string) min($cars, $bikes), (string) max($cars, $bikes)), $fresh()->listSelected('digital-goods'));
pin('a second plugin keeps its own', array((string) $cars), $fresh()->listSelected('cars-attr'));
$migrate();
pin('a second run is a no-op', 2, $num("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'plugin_categories'"));

harness_section('reads');
check('osc_is_this_category answers yes', osc_is_this_category('digital-goods', $bikes));
check('and no for a category not chosen', !osc_is_this_category('digital-goods', $vans));
check('and no for a plugin with no list', !osc_is_this_category('nothing', $cars));
pin('findByCategoryId lists the plugins on a category', array('cars-attr', 'digital-goods'), array_column($fresh()->findByCategoryId($cars), 's_plugin_name'));

harness_section('the admin save');
Plugins::cleanCategoryFromPlugin('digital-goods');
Plugins::addToCategoryPlugin(array($cars), 'digital-goods');
pin('a parent brings its subcategories', array((string) $cars, (string) $vans), $fresh()->listSelected('digital-goods'));

harness_section('cleanup');
(new Category())->deleteByPrimaryKey($vans);
pin('a deleted category leaves every list', array((string) $cars), $fresh()->listSelected('digital-goods'));
Plugins::cleanCategoryFromPlugin('cars-attr');
pin('an uninstalled plugin has no row left', 0, $num("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'plugin_categories' AND s_key = 'cars-attr'"));

harness_section('old table calls');
$legacy = $fresh();
check('insert() adds a pair', $legacy->insert(array('s_plugin_name' => 'old', 'fk_i_category_id' => $bikes)) && $fresh()->isThisCategory('old', $bikes));
check('delete() by plugin clears it', $legacy->delete(array('s_plugin_name' => 'old')) && !$fresh()->isThisCategory('old', $bikes));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
