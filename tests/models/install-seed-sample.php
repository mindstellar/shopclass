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
 * The installer's sample data: custom field groups reach child categories, every sample
 * listing is saved active with its field values, products carry stock and variants,
 * the ids are recorded, and a second run is refused.
 *
 * Usage:  php tests/models/install-seed-sample.php       (standalone, own scratch database)
 *         php tests/run-models.php install-seed-sample   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_install_seed_sample');
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return UPLOADS_PATH;
    }
}
foreach (array('hItems', 'hUtils', 'hKv') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
require_once ABS_PATH . 'oc-includes/osclass/install-functions.php';

$p = DB_TABLE_PREFIX;
seed_locale($admin);
seed_currency($admin);
foreach (array('moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'admin_language' => 'en_US',
    'title_character_length' => '100', 'description_character_length' => '5000', 'currency' => 'USD',
    'maxSizeKb' => '2048', 'allowedExt' => 'png,gif,jpg,jpeg,webp', 'dimThumbnail' => '240x200', 'dimPreview' => '480x340', 'dimNormal' => '640x480') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.71';

$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

$pack = sys_get_temp_dir() . '/sample-pack-' . getmypid() . '/';
mkdir($pack);
imagewebp(imagecreatetruecolor(640, 480), $pack . 'car-hatchback.webp');

harness_section('classifieds sample');
install_seed_categories('classifieds');
$result = install_seed_sample('classifieds', $pack);
check('no errors: ' . implode('; ', $result['errors']), $result['errors'] === array());
check('26 listings made', $result['listings'] === 26);
check('26 listings active', $count("SELECT COUNT(*) FROM {$p}t_item WHERE b_active = 1 AND b_enabled = 1") === 26);
check('the kv group holds the item ids', count(osc_kv_get('sample', 'items', array())) === 26);
check('the kv group holds the field ids', count(osc_kv_get('sample', 'fields', array())) === $result['fields']);
check('the kv group holds 7 group ids', count(osc_kv_get('sample', 'groups', array())) === 7);

$cars = $count("SELECT fk_i_category_id FROM {$p}t_category_description WHERE s_name = 'Cars'");
$names = array_column(\Field::getInstance()->findByCategory($cars), 's_name');
check('Vehicle fields reach the Cars category', in_array('Make', $names, true) && in_array('Mileage', $names, true));
$names = array_column(\Field::getInstance()->findByCategory(
    $count("SELECT fk_i_category_id FROM {$p}t_category_description WHERE s_name = 'Mobile Phones'")
), 's_name');
check('Item fields reach Mobile Phones', $names === array('Brand', 'Condition', 'Colour'));
check('field values were stored', $count("SELECT COUNT(*) FROM {$p}t_item_meta m JOIN {$p}t_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE f.s_name = 'Make' AND m.s_value = 'Ford'") === 1);
check('a date value is a timestamp', $count("SELECT COUNT(*) FROM {$p}t_item_meta m JOIN {$p}t_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE f.s_name = 'Available from' AND m.s_value > UNIX_TIMESTAMP()") === 1);

check('the Ford has its photo', $count("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item'") === 1);
check('the pack file is still there', is_file($pack . 'car-hatchback.webp'));

harness_section('second run');
$threw = false;
try {
    install_seed_sample('classifieds');
} catch (\RuntimeException $e) {
    $threw = true;
}
check('refused while sample data exists', $threw);
check('nothing was added', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);

harness_section('shop sample');
scratchdb_truncate_all($admin);
seed_locale($admin);
seed_currency($admin);
scratchdb_forget_cache();
osc_reset_preferences();
install_seed_categories('shop');
$result = install_seed_sample('shop');
check('no errors: ' . implode('; ', $result['errors']), $result['errors'] === array());
check('16 products made', $result['listings'] === 16);
check('every product is for sale', $count("SELECT COUNT(DISTINCT fk_i_item_id) FROM {$p}t_item_variant") === 16);
check('the t-shirt has 6 variants', $count("SELECT COUNT(*) FROM {$p}t_item_variant v JOIN {$p}t_item_description d ON d.fk_i_item_id = v.fk_i_item_id WHERE d.s_title = 'Cotton t-shirt' AND v.s_options IS NOT NULL") === 6);
check('one product is low on stock', $count("SELECT COUNT(*) FROM {$p}t_item_variant WHERE i_stock <= 3 AND s_options IS NULL") >= 1);

foreach (glob(UPLOADS_PATH . '0/*') ?: array() as $file) {
    unlink($file);
}
@rmdir(UPLOADS_PATH . '0');
unlink($pack . 'car-hatchback.webp');
rmdir($pack);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
