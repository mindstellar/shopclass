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
 * Pins for osc_item_adjacent_id()/osc_item_adjacent_url() and Item::findAdjacentLive().
 *
 * The URL builder lives in hDefines.php, which a model test cannot load, so it is
 * stood in for by one that prints every field the real builder reads. Equal stand-in
 * output therefore means equal real URLs.
 *
 * Usage:  php tests/models/itemadjacent.php
 *         php tests/run-models.php itemadjacent
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemadjacent');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
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
if (!function_exists('osc_item_url_from_item')) {
    function osc_item_url_from_item($item, $locale = '')
    {
        return 'url:' . $item['pk_i_id'] . '|' . $item['fk_i_category_id'] . '|'
            . ($item['s_city'] ?? '') . '|' . $item['s_title'] . '|' . $locale;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

Preference::getInstance(); // warm the preference map so no lookup is charged to a count pin

$cache     = \mindstellar\cache\CacheManager::getInstance();
$itemTable = DB_TABLE_PREFIX . 't_item';
$descTable = DB_TABLE_PREFIX . 't_item_description';
$locTable  = DB_TABLE_PREFIX . 't_item_location';

seed_locale($admin, 'en_US');
seed_locale($admin, 'fr_FR', 'French');
seed_country($admin, 'US');
$cat = seed_category($admin, 'Motors');

// Ids in order: live, spam, disabled, inactive, expired, expired-premium, live, live (French only).
$a        = seed_item($admin, $cat, null, 'First live');
$spam     = seed_item($admin, $cat, null, 'Spam');
$disabled = seed_item($admin, $cat, null, 'Disabled', 1.0, 1, 0);
$inactive = seed_item($admin, $cat, null, 'Inactive', 1.0, 0, 1);
$expired  = seed_item($admin, $cat, null, 'Expired');
$premium  = seed_item($admin, $cat, null, 'Expired premium');
$b        = seed_item($admin, $cat, null, 'Second live');
$c        = seed_item($admin, $cat, null, 'Titre seul', 1.0, 1, 1, 'fr_FR');

$admin->query("UPDATE $itemTable SET b_spam = 1 WHERE pk_i_id = $spam");
$admin->query("UPDATE $itemTable SET dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE pk_i_id IN ($expired, $premium)");
$admin->query("UPDATE $itemTable SET b_premium = 1 WHERE pk_i_id = $premium");
$admin->query("UPDATE $locTable SET s_city = 'San José' WHERE fk_i_item_id = $b");
seed_exec(
    $admin,
    "INSERT INTO $descTable (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, 'fr_FR', 'Deuxième', 'x')",
    'i',
    array($b)
);

$full = static function (int $id): string {
    return osc_item_url_from_item(Item::getInstance()->findByPrimaryKey($id));
};

harness_section('Neighbours skip hidden listings');
$cache->flush();
pin('next of the first live skips spam, disabled, inactive and expired', $premium, osc_item_adjacent_id('next', $a));
pin('a premium listing counts even when expired', $premium, osc_item_adjacent_id('prev', $b));
pin('next of the premium one is the second live', $b, osc_item_adjacent_id('next', $premium));
pin('prev of the premium one skips back to the first live', $a, osc_item_adjacent_id('prev', $premium));
pin('next from a hidden listing still finds the next live one', $premium, osc_item_adjacent_id('next', $spam));
pin('any direction other than prev means next', $c, osc_item_adjacent_id('sideways', $b));

harness_section('First and last');
pin('prev of the first listing is empty', '', osc_item_adjacent_url('prev', $a));
pin('prev id of the first listing is 0', 0, osc_item_adjacent_id('prev', $a));
pin('next of the last listing is empty', '', osc_item_adjacent_url('next', $c));
pin('no current item and no id gives empty', '', osc_item_adjacent_url('next'));

harness_section('URL equals the full-load URL');
$cache->flush();
pin('city and current-locale title', $full($b), osc_item_adjacent_url('next', $premium));
pin('fallback to the only title there is', $full($c), osc_item_adjacent_url('next', $b));
pin('plain listing', $full($a), osc_item_adjacent_url('prev', $premium));
pin('the stand-in carries the city', true, strpos(osc_item_adjacent_url('next', $premium), 'San José') !== false);

harness_section('Model row');
$row = Item::getInstance()->findAdjacentLive($premium, true, 'fr_FR');
pin('title follows the asked locale', 'Deuxième', $row['s_title'] ?? null);
pin('row carries only the URL fields', array('pk_i_id', 'fk_i_category_id', 's_city', 's_title'), array_keys($row));
pin('nothing before the first listing', array(), Item::getInstance()->findAdjacentLive($a, false, 'en_US'));

harness_section('Cost and cache');
$cache->flush();
pin('a cache miss is one statement', 1, harness_query_count(static function () use ($b) {
    osc_item_adjacent_url('next', $b);
}));
pin('the id from the same lookup is free', 0, harness_query_count(static function () use ($b) {
    osc_item_adjacent_id('next', $b);
}));
pin('an empty answer is one statement', 1, harness_query_count(static function () use ($c) {
    osc_item_adjacent_url('next', $c);
}));
pin('the empty answer is cached', 0, harness_query_count(static function () use ($c) {
    osc_item_adjacent_url('next', $c);
}));
$admin->query("UPDATE $itemTable SET b_enabled = 0 WHERE pk_i_id = $c");
pin('the cached answer is served until it expires', $full($c), osc_item_adjacent_url('next', $b));
$cache->flush();
pin('after expiry the hidden listing is gone', '', osc_item_adjacent_url('next', $b));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
