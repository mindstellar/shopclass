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
 * The Required price, photos and location settings: each refuses a listing without it on
 * create and edit through ListingService, and the settings screen's dropdowns store the
 * preferences sites already have.
 *
 * Usage:  php tests/models/required-location.php       (standalone, own scratch database)
 *         php tests/run-models.php required-location   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$rlRoot = sys_get_temp_dir() . '/osc-required-fields-' . getmypid() . '/';
@mkdir($rlRoot . 'uploads/', 0777, true);
define('UPLOADS_PATH', $rlRoot . 'uploads/');
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['rlRoot'];
    }
}
register_shutdown_function(static function () use ($rlRoot): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rlRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($rlRoot);
});

use mindstellar\admin\ExposedSettings;
use mindstellar\admin\form\ItemSettingsScreen;
use mindstellar\auth\Actor;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;

$admin = api_admin_boot('osc_models_required_location');
scratchdb_pin_site_mode($admin, 'classifieds');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

$p   = DB_TABLE_PREFIX;
$en  = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$texas  = seed_region($admin, 'US', 'Texas');
$austin = seed_city($admin, $texas, 'Austin', 'US');
$cat    = seed_category($admin, 'Cars', null, $en);
scratchdb_forget_cache();
(new ReflectionProperty(Category::class, 'instance'))->setValue(null, null);

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->replace($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
// A site that never saved these keys: no row, and nothing left in the loaded copy.
$dropPref = static function (string ...$keys) use ($admin, $p): void {
    foreach ($keys as $key) {
        $admin->query("DELETE FROM {$p}t_preference WHERE s_name = '" . $admin->real_escape_string($key) . "'");
        Preference::getInstance()->set($key, null);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setPref([
    'moderate_items' => '-1', 'logged_user_item_validation' => '0', 'items_wait_time' => '0', 'reg_user_post' => '0', 'selectable_parent_categories' => '1',
    'language' => 'en_US', 'title_character_length' => '100', 'description_character_length' => '5000',
    'enableField#f_price@items' => '1', 'enableField#images@items' => '1', 'numImages@items' => '4', 'maxSizeKb' => '2048',
    'allowedExt' => 'png,gif,jpg,jpeg,webp', 'dimNormal' => '640x480', 'dimPreview' => '480x340', 'dimThumbnail' => '240x200',
]);
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';

$service = new ListingService();
$form    = static fn (array $place): array => $place + array(
    'catId'        => (string) $cat,
    'countryId'    => '', 'country' => '', 'regionId' => '', 'region' => '', 'cityId' => '', 'city' => '',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'contactName'  => 'Gary',
    'contactEmail' => 'gary@example.test',
);
// The errors of a save on the pointers checked here as pointer => message, or 'saved'.
$outcome = static function (callable $save): mixed {
    try {
        $save();
    } catch (InvalidException $e) {
        $hits = array();
        foreach ($e->errors() as $err) {
            if (in_array($err['pointer'], array('/country', '/region', '/city', '/price', '/photos'), true)) {
                $hits[$err['pointer']] = $err['message'];
            }
        }

        return $hits === array() ? 'other refusal: ' . $e->getMessage() : $hits;
    }

    return 'saved';
};
$guest  = Actor::guest('192.0.2.81');
$add    = static fn (array $place, ?Actor $actor = null): mixed => $outcome(static fn () => $service->create(ListingInput::fromArray($form($place), $actor ?? $guest, true), $actor ?? $guest));
$listed = $service->create(ListingInput::fromArray($form(array('countryId' => 'US', 'regionId' => (string) $texas, 'cityId' => (string) $austin)), Actor::admin(1, '192.0.2.81'), true), Actor::admin(1, '192.0.2.81'))->id();
$edit   = static fn (array $place): mixed => $outcome(static fn () => $service->update(ListingInput::fromArray(array('id' => (string) $listed) + $form($place), Actor::admin(1, '192.0.2.81'), false), Actor::admin(1, '192.0.2.81'), false, false));

$none    = array();
$country = array('countryId' => 'US');
$region  = array('countryId' => 'US', 'regionId' => (string) $texas);
$city    = array('countryId' => 'US', 'regionId' => (string) $texas, 'cityId' => (string) $austin);
$typed   = array('countryId' => 'US', 'regionId' => (string) $texas, 'city' => 'Smalltown');

harness_section('default: not required');
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'item_required_location'");
scratchdb_forget_cache();
osc_reset_preferences();
pin('a site with no row reads none', 'none', osc_item_required_location());
pin('a listing with no location is saved', 'saved', $add($none));
pin('so is an edit that clears it', 'saved', $edit($none));
$setPref(array('item_required_location' => 'planet'));
pin('an unknown value reads none', 'none', osc_item_required_location());

harness_section('country');
$setPref(array('item_required_location' => 'country'));
pin('create without a country is refused', array('/country' => 'Choose a country.'), $add($none));
pin('edit without a country is refused', array('/country' => 'Choose a country.'), $edit($none));
pin('a country is enough', 'saved', $add($country));
pin('a typed country name counts', 'saved', $add(array('country' => 'Freedonia')));

harness_section('country and region');
$setPref(array('item_required_location' => 'region'));
pin('create without a region is refused', array('/region' => 'Choose a region.'), $add($country));
pin('edit without a region is refused', array('/region' => 'Choose a region.'), $edit($country));
pin('nothing names both places', array('/country' => 'Choose a country.', '/region' => 'Choose a region.'), $add($none));
pin('a region is enough', 'saved', $add($region));

harness_section('country, region and city');
$setPref(array('item_required_location' => 'city'));
pin('create without a city is refused', array('/city' => 'Choose a city.'), $add($region));
pin('edit without a city is refused', array('/city' => 'Choose a city.'), $edit($region));
pin('a city from the list is saved', 'saved', $add($city));
pin('a typed city is saved', 'saved', $add($typed));
pin('and kept on edit', 'saved', $edit($typed));
pin('an admin is held to it too', array('/city' => 'Choose a city.'), $add($region, Actor::admin(1, '192.0.2.81')));

harness_section('form markers');
pin('city asks for all three', array(true, true, true), array(osc_item_location_required('country'), osc_item_location_required('region'), osc_item_location_required('city')));
$setPref(array('item_required_location' => 'country'));
pin('country asks for the country only', array(true, false, false), array(osc_item_location_required('country'), osc_item_location_required('region'), osc_item_location_required('city')));
ob_start();
osc_admin_location_picker(array('countries' => array(array('pk_c_code' => 'US', 's_name' => 'United States')), 'required' => array('country')));
$html = (string) ob_get_clean();
pin('the admin picker marks the country only', 1, substr_count($html, 'osc-field-required'));
ob_start();
osc_admin_photo_grid(array('min' => 2));
$grid = (string) ob_get_clean();
pin('the admin photo grid marks a minimum and names it', array(1, 1), array(substr_count($grid, 'osc-field-required'), substr_count($grid, 'Add at least 2 photos.')));
ob_start();
osc_admin_photo_grid(array());
pin('and marks nothing without one', 0, substr_count((string) ob_get_clean(), 'osc-field-required'));

harness_section('price');
$setPref(array('item_required_location' => 'none', 'enableField#f_price@items' => '1', 'item_price_required' => '1'));
pin('a required price reads required', true, osc_item_price_required());
pin('create without a price is refused', array('/price' => 'Add a price.'), $add(array('price' => '')));
pin('edit without a price is refused', array('/price' => 'Add a price.'), $edit(array('price' => '')));
pin('a price of 0 counts', 'saved', $add(array('price' => '0')));
$zero = (int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_item")->fetch_row()[0];
pin('and is stored as 0, which shows as Free', '0', $admin->query("SELECT i_price FROM {$p}t_item WHERE pk_i_id = $zero")->fetch_row()[0]);
pin('text that is no number is no price', array('/price' => 'Add a price.'), $add(array('price' => 'ask me')));
$free = seed_category($admin, 'Giveaways', null, $en);
$admin->query("UPDATE {$p}t_category SET b_price_enabled = 0 WHERE pk_i_id = $free");
scratchdb_forget_cache();
(new ReflectionProperty(Category::class, 'instance'))->setValue(null, null);
pin('a category that takes no price asks for none', 'saved', $add(array('price' => '', 'catId' => (string) $free)));
$setPref(array('item_price_required' => '0'));
pin('optional saves without a price', 'saved', $add(array('price' => '')));
$setPref(array('enableField#f_price@items' => '0', 'item_price_required' => '1'));
pin('prices off require none, whatever the flag', array(false, 'saved'), array(osc_item_price_required(), $add(array('price' => ''))));
$setPref(array('enableField#f_price@items' => '1'));
$dropPref('item_price_required');
pin('a site with no flag reads optional', false, osc_item_price_required());

harness_section('photos');
$jpeg = $rlRoot . 'car.jpg';
$img  = imagecreatetruecolor(64, 48);
imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 200, 30, 30));
imagejpeg($img, $jpeg, 80);
$photo  = static function () use ($jpeg, $rlRoot): string {
    $copy = $rlRoot . 'up-' . bin2hex(random_bytes(4)) . '.jpg';
    copy($jpeg, $copy);

    return $copy;
};
$adminActor = Actor::admin(1, '192.0.2.81');
$withPhotos = static fn (int $n): array => array('photos' => $n > 0 ? array_map(static fn (): string => $photo(), range(1, $n)) : array());
$post       = static fn (int $n): mixed => $outcome(static fn () => $service->create(ListingInput::fromArray($form($withPhotos($n)), $adminActor, true), $adminActor));
$photosOf   = static fn (int $id): int => mindstellar\listing\PhotoService::count($id);

$setPref(array('item_photos_min' => '0'));
pin('minimum 0 needs no photo', array(0, 'saved'), array(osc_item_photos_min(), $post(0)));
$setPref(array('item_photos_min' => '1'));
pin('minimum 1 refuses none', array('/photos' => 'Add at least 1 photo.'), $post(0));
pin('and takes one', 'saved', $post(1));
$setPref(array('item_photos_min' => '2'));
pin('minimum 2 refuses one', array('/photos' => 'Add at least 2 photos.'), $post(1));
$two = $service->create(ListingInput::fromArray($form($withPhotos(2)), $adminActor, true), $adminActor)->id();
pin('and takes two', 2, $photosOf($two));
$editPhotos = static fn (int $n): mixed => $outcome(static fn () => $service->update(ListingInput::fromArray(array('id' => (string) $two) + $form($withPhotos($n)), $adminActor, false), $adminActor, false, false));
pin('an edit keeping both needs no upload', 'saved', $editPhotos(0));
$gone = (int) mindstellar\listing\PhotoStore::ofItems(array($two))[0]['pk_i_id'];
pin('a photo is removed', true, (new mindstellar\listing\PhotoService())->delete($gone, $two, $adminActor));
pin('then an edit with one left is refused', array('/photos' => 'Add at least 2 photos.'), $editPhotos(0));
pin('and one new upload makes it two again', array('saved', 2), array($editPhotos(1), $photosOf($two)));
$setPref(array('enableField#images@items' => '0'));
pin('photos off ask for none, whatever the minimum', array(0, 'saved'), array(osc_item_photos_min(), $post(0)));
$setPref(array('enableField#images@items' => '1', 'item_photos_min' => '0'));

harness_section('settings screen');
$page     = ItemSettingsScreen::register();
$build    = new ReflectionMethod(ExposedSettings::class, 'post');
$registry = mindstellar\settings\SettingsPageRegistry::getInstance();
$entries  = new ReflectionProperty($registry, 'entries');
// The page reads the stored settings when it is declared, so each draw declares it again.
$redeclare = static function () use ($registry, $entries): string {
    $all = $entries->getValue($registry);
    unset($all[ItemSettingsScreen::PAGE_ID]);
    $entries->setValue($registry, $all);

    return ItemSettingsScreen::register();
};
$submit = static function (array $changes) use ($build, $redeclare): array {
    $page   = $redeclare();
    $fields = mindstellar\settings\SettingsPageRegistry::getInstance()->fields($page);
    $data   = $build->invoke(null, osc_settings_values($page), $changes, $fields);
    $result = Params::withRequest($data, static fn () => osc_settings_save($page));
    scratchdb_forget_cache();
    osc_reset_preferences();

    return $result['errors'];
};
$stored = static fn (string ...$keys): array => array_map(static fn (string $k): string => (string) Preference::getInstance()->get($k), $keys);
$shown  = static function (string ...$names) use ($redeclare): array {
    $values = osc_settings_values($redeclare());

    return array_map(static fn (string $n): string => (string) $values[$n], $names);
};

$setPref(array('enableField#f_price@items' => '1', 'enableField#images@items' => '0'));
$dropPref('item_price_required', 'item_photos_min');
pin('an existing site reads Optional price and Off photos', array('optional', 'off'), $shown('enableField#f_price@items', 'enableField#images@items'));
$submit(array('enableField#f_price@items' => 'required'));
pin('Required price stores on and the flag', array('1', '1'), $stored('enableField#f_price@items', 'item_price_required'));
pin('and reads back Required', array('required'), $shown('enableField#f_price@items'));
$submit(array('enableField#f_price@items' => 'optional'));
pin('Optional price stores on, no flag', array('1', '0'), $stored('enableField#f_price@items', 'item_price_required'));
$submit(array('enableField#f_price@items' => 'off'));
pin('Off price stores off', array('0', '0'), $stored('enableField#f_price@items', 'item_price_required'));
$submit(array('enableField#f_price@items' => '1'));
pin('the old ticked box still means Optional', array('1', '0'), $stored('enableField#f_price@items', 'item_price_required'));

$submit(array('enableField#images@items' => 'required', 'numImages@items' => '3', 'photos_min' => '2'));
pin('Required photos store on and the minimum', array('1', '2', '3'), $stored('enableField#images@items', 'item_photos_min', 'numImages@items'));
pin('and read back Required with it', array('required', '2'), $shown('enableField#images@items', 'photos_min'));
$refused = $submit(array('enableField#images@items' => 'required', 'numImages@items' => '3', 'photos_min' => '5'));
pin('a minimum over the limit is refused', array('At least 5 photos is more than the 3 images a listing can have.'), $refused);
pin('and nothing changes', array('2', '3'), $stored('item_photos_min', 'numImages@items'));
pin('with no limit any minimum is fine', array(), $submit(array('enableField#images@items' => 'required', 'numImages@items' => '0', 'photos_min' => '5')));
pin('a minimum of 0 is raised to 1', array('1'), ($submit(array('enableField#images@items' => 'required', 'photos_min' => '0')) ?: $stored('item_photos_min')));
$submit(array('enableField#images@items' => 'optional', 'photos_min' => '3'));
pin('Optional photos store on and no minimum', array('1', '0'), $stored('enableField#images@items', 'item_photos_min'));
$submit(array('enableField#images@items' => 'off'));
pin('Off photos store off', array('0', '0'), $stored('enableField#images@items', 'item_photos_min'));

$submit(array('item_required_location' => 'required', 'item_location_level' => 'region'));
pin('Required location stores the level', 'region', osc_item_required_location());
pin('and reads back', array('required', 'region'), $shown('item_required_location', 'item_location_level'));
$submit(array('item_required_location' => 'optional', 'item_location_level' => 'city'));
pin('Optional stores none, whatever the level', 'none', osc_item_required_location());
pin('a hand-made level is refused', 1, count($submit(array('item_required_location' => 'required', 'item_location_level' => 'planet'))));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
