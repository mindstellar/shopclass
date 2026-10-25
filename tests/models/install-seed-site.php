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
 * install_seed_site() writes the chosen category tree and the example page, adds sample data
 * only when asked, and does nothing the second time it runs.
 *
 * Usage:  php tests/models/install-seed-site.php       (standalone, own scratch database)
 *         php tests/run-models.php install-seed-site   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_install_seed_site');
foreach (array('hItems', 'hUtils', 'hKv', 'hFields') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
require_once ABS_PATH . 'oc-includes/osclass/install-functions.php';

$p = DB_TABLE_PREFIX;
$prefs = array('moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'admin_language' => 'en_US',
    'title_character_length' => '100', 'description_character_length' => '5000', 'currency' => 'USD');
$fresh = static function () use ($admin, $prefs): void {
    scratchdb_truncate_all($admin);
    seed_locale($admin);
    seed_currency($admin);
    foreach ($prefs as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
    $_SERVER['REMOTE_ADDR'] = '192.0.2.71';
};
$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

harness_section('shop without sample data');
$fresh();
check('reports success', install_seed_site('shop', false) === true);
check('50 categories', $count("SELECT COUNT(*) FROM {$p}t_category") === 50);
check('the example page exists', $count("SELECT COUNT(*) FROM {$p}t_pages WHERE s_internal_name = 'example_page'") === 1);
check('no listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 0);
check('no sample record', osc_kv_get('sample', 'items') === null);

harness_section('second run');
check('reports success', install_seed_site('shop', true) === true);
check('still 50 categories', $count("SELECT COUNT(*) FROM {$p}t_category") === 50);
check('still no listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 0);

harness_section('classifieds with sample data');
$fresh();
check('reports success', install_seed_site('classifieds', true) === true);
check('103 categories', $count("SELECT COUNT(*) FROM {$p}t_category") === 103);
check('26 listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
