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
 * The installer writes the chosen starter category tree: counts, fixed ids, sibling order,
 * and a refusal for an unknown site type.
 *
 * Usage:  php tests/models/install-seed-categories.php       (standalone, own scratch database)
 *         php tests/run-models.php install-seed-categories   (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_install_seed_categories');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('LIB_PATH')) {
    define('LIB_PATH', ABS_PATH . 'oc-includes/');
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
        return ABS_PATH . 'oc-content/plugins/';
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/install-functions.php';

Preference::getInstance()->set('admin_language', 'en_US');
scratchdb_forget_cache();
osc_reset_preferences();
seed_locale($admin, 'en_US', 'English');
$p = DB_TABLE_PREFIX;

$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

harness_section('shop tree');
check('returns the row count', install_seed_categories('shop') === 50);
check('50 categories written', $count("SELECT COUNT(*) FROM {$p}t_category") === 50);
check('50 names written', $count("SELECT COUNT(*) FROM {$p}t_category_description") === 50);
check('10 top categories', $count("SELECT COUNT(*) FROM {$p}t_category WHERE fk_i_parent_id IS NULL") === 10);
check('Clothing keeps id 1', $count("SELECT COUNT(*) FROM {$p}t_category_description WHERE fk_i_category_id = 1 AND s_name = 'Clothing'") === 1);
$kids = $admin->query("SELECT d.s_name FROM {$p}t_category c JOIN {$p}t_category_description d ON d.fk_i_category_id = c.pk_i_id WHERE c.fk_i_parent_id = 1 ORDER BY c.i_position")->fetch_all();
check('children follow list order', array_column($kids, 0) === array('Women', 'Men', 'Kids', 'Shoes', 'Accessories'));
$positions = $admin->query("SELECT i_position FROM {$p}t_category WHERE fk_i_parent_id = 1 ORDER BY i_position")->fetch_all();
check('positions count from 1 under each parent', array_map('intval', array_column($positions, 0)) === array(1, 2, 3, 4, 5));
$positions = $admin->query("SELECT i_position FROM {$p}t_category WHERE fk_i_parent_id IS NULL ORDER BY i_position")->fetch_all();
check('top categories count from 1 too', array_map('intval', array_column($positions, 0)) === range(1, 10));

scratchdb_truncate_all($admin);
seed_locale($admin, 'en_US', 'English');

harness_section('classifieds tree');
check('returns the row count', install_seed_categories('classifieds') === 103);
check('13 top categories', $count("SELECT COUNT(*) FROM {$p}t_category WHERE fk_i_parent_id IS NULL") === 13);
check('Vehicles keeps id 1', $count("SELECT COUNT(*) FROM {$p}t_category_description WHERE fk_i_category_id = 1 AND s_name = 'Vehicles'") === 1);
check('all enabled', $count("SELECT COUNT(*) FROM {$p}t_category WHERE b_enabled = 0") === 0);

harness_section('unknown type');
$threw = false;
try {
    install_seed_categories('blog');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('refused', $threw);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
