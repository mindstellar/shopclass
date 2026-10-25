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
 * Removing the installer's sample data: listings with their photos on disk, field values,
 * fields, groups and the record go; categories and listings the installer did not add stay.
 *
 * Usage:  php tests/models/sample-data-remove.php       (standalone, own scratch database)
 *         php tests/run-models.php sample-data-remove   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_sample_data_remove');
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
$_SERVER['REMOTE_ADDR'] = '192.0.2.72';

$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

$pack = sys_get_temp_dir() . '/sample-pack-' . getmypid() . '/';
mkdir($pack);
imagewebp(imagecreatetruecolor(640, 480), $pack . 'car-hatchback.webp');

harness_section('remove');
install_seed_categories('classifieds');
$result = install_seed_sample('classifieds', $pack);
check('seeded: ' . implode('; ', $result['errors']), $result['errors'] === array() && $result['listings'] === 26);

$service = new \mindstellar\listing\ListingService();
$actor   = \mindstellar\auth\Actor::admin(1, '127.0.0.1');
$own     = $service->create(
    \mindstellar\listing\ListingInput::fromArray(array(
        'catId' => $count("SELECT fk_i_category_id FROM {$p}t_category_description WHERE s_name = 'Cars'"), 'title' => array('en_US' => 'Mine'), 'description' => array('en_US' => 'A listing the installer did not add.'),
        'price' => 5, 'contactName' => 'Me', 'contactEmail' => 'me@example.com',
    ), $actor, true),
    $actor,
    true
);

// A recorded id that now belongs to a real listing, as after an id is reused.
osc_kv_set('sample', 'items', array_merge(osc_kv_get('sample', 'items'), array((int) $own->id())));

$sample = new \mindstellar\install\SampleData();
check('sample data is present', $sample->present());
check('26 sample listings are installed, not the real one', count($sample->installed()) === 26);
$files = glob(UPLOADS_PATH . '0/*') ?: array();
check('the photo is on disk', count($files) > 0);
$categories = $count("SELECT COUNT(*) FROM {$p}t_category");

$removed = $sample->remove($actor);
check('26 listings reported removed', $removed['listings'] === 26);
check('only the other listing is left', $count("SELECT COUNT(*) FROM {$p}t_item") === 1);
check('the other listing is the one that stays', (int) $own->id() === $count("SELECT pk_i_id FROM {$p}t_item"));
check('photo rows are gone', $count("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item'") === 0);
check('photo files are gone', (glob(UPLOADS_PATH . '0/*') ?: array()) === array());
check('field values are gone', $count("SELECT COUNT(*) FROM {$p}t_item_meta") === 0);
check('fields are gone', $count("SELECT COUNT(*) FROM {$p}t_meta_fields") === 0);
check('groups are gone', $count("SELECT COUNT(*) FROM {$p}t_meta_group") === 0);
check('the kv group is gone', osc_kv_get('sample', 'items') === null && osc_kv_get('sample', 'groups') === null);
check('categories stay', $count("SELECT COUNT(*) FROM {$p}t_category") === $categories && $categories > 0);
check('nothing is installed now', $sample->installed() === array());
check('nothing is present now', !$sample->present());
check('removing again does nothing', $sample->remove($actor) === array('listings' => 0, 'fields' => 0, 'groups' => 0));

unlink($pack . 'car-hatchback.webp');
rmdir($pack);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
