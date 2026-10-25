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
 * only when asked, and skips each part that is there already. The browser adds sample data
 * through install_sample_step(), one batch per request.
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
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return UPLOADS_PATH;
    }
}
foreach (array('hItems', 'hUtils', 'hKv', 'hFields') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
require_once ABS_PATH . 'oc-includes/osclass/install-functions.php';

$p = DB_TABLE_PREFIX;
$prefs = array('moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'admin_language' => 'en_US',
    'title_character_length' => '100', 'description_character_length' => '5000', 'currency' => 'USD',
    'maxSizeKb' => '2048', 'allowedExt' => 'png,gif,jpg,jpeg,webp', 'dimThumbnail' => '240x200', 'dimPreview' => '480x340', 'dimNormal' => '640x480');
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

$noPhotos = static fn () => new \mindstellar\install\SamplePhotos('/nonexistent.zip', '', '', sys_get_temp_dir());

harness_section('second run, now with sample data');
check('reports success', install_seed_site('shop', true, $noPhotos()) === true);
check('still 50 categories', $count("SELECT COUNT(*) FROM {$p}t_category") === 50);
check('the sample products are added', $count("SELECT COUNT(*) FROM {$p}t_item") === 16);
check('a third run adds nothing', install_seed_site('shop', true, $noPhotos()) === true && $count("SELECT COUNT(*) FROM {$p}t_item") === 16);

harness_section('classifieds with sample data');
$fresh();
check('reports success', install_seed_site('classifieds', true, $noPhotos()) === true);
check('103 categories', $count("SELECT COUNT(*) FROM {$p}t_category") === 103);
check('26 listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);

harness_section('failures raise the finish-page warning');
$fresh();
$_SESSION = array();
Session::getInstance()->_drop('install_sample_warning');
check('a category tree that cannot be written returns false', install_seed_site('blog', false) === false);
check('and sets the warning', (bool) Session::getInstance()->_get('install_sample_warning'));
check('and adds no sample data', install_seed_site('blog', true, $noPhotos()) === false && $count("SELECT COUNT(*) FROM {$p}t_item") === 0);
$fresh();
$_SESSION = array();
Session::getInstance()->_drop('install_sample_warning');
check('no warning on a clean run', install_seed_site('classifieds', false) === true && !Session::getInstance()->_get('install_sample_warning'));
Preference::getInstance()->set('title_character_length', '3');
scratchdb_forget_cache();
osc_reset_preferences();
check('no sample listing saved returns false', install_seed_site('classifieds', true, $noPhotos()) === false);
check('none were saved', $count("SELECT COUNT(*) FROM {$p}t_item") === 0);
check('and the warning is set', (bool) Session::getInstance()->_get('install_sample_warning'));

harness_section('the browser adds sample data one batch per request');
$fresh();
$_SESSION = array();
$session  = Session::getInstance();
$session->_set('install_site_type', 'classifieds');
$session->_set('install_sample_data', 1);
check('categories first', install_seed_site('classifieds', false) === true && $count("SELECT COUNT(*) FROM {$p}t_item") === 0);
$work = sys_get_temp_dir() . '/seed-site-' . getmypid() . '/';
mkdir($work);
ob_start();
imagewebp(imagecreatetruecolor(64, 48));
$webp = (string) ob_get_clean();
$zipPath = $work . 'pack.zip';
$z       = new ZipArchive();
$z->open($zipPath, ZipArchive::CREATE);
$z->addFromString('car-hatchback.webp', $webp);
$z->close();
$pack = new \mindstellar\install\SamplePhotos($zipPath, (string) hash_file('sha256', $zipPath), \mindstellar\install\SamplePhotos::URL, $work);
check('the photo step unpacks the pack', install_sample_step('sample_photos', 0, $pack)['ok'] === true);
$folder = (string) $session->_get('install_sample_photos');
check('the folder is kept for the batches', is_dir($folder));
check('a batch past the end is refused', install_sample_step('sample_batch', 6, $pack)['ok'] === false);
for ($b = 0; $b < 6; $b++) {
    install_sample_step('sample_batch', $b, $pack);
}
check('26 listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);
check('the Ford has its photo', $count("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item'") === 1);
check('the last batch removes the photo folder', !is_dir($folder) && $session->_get('install_sample_photos') === '');
check('a retried batch adds nothing', install_sample_step('sample_batch', 2, $pack)['ok'] === true && $count("SELECT COUNT(*) FROM {$p}t_item") === 26);
$session->_set('install_sample_data', 0);
check('without the sample choice a step is refused', install_sample_step('sample_batch', 0, $pack)['ok'] === false);
foreach (glob(UPLOADS_PATH . '0/*') ?: array() as $file) {
    unlink($file);
}
@rmdir(UPLOADS_PATH . '0');
(new \mindstellar\utility\FileSystem())->remove($work);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
