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
 * What the separate-boxes and dropdown place fields post, saved through ItemActions with
 * "Only places from the list" on and off: ids alone get their names, a typed name that is a
 * place of its parent gets its id, and a typed or mismatched place is refused while it is on.
 *
 * Usage:  php tests/models/place-field-styles.php       (standalone, own scratch database)
 *         php tests/run-models.php place-field-styles   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$psRoot = sys_get_temp_dir() . '/osc-place-field-styles-' . getmypid() . '/';
@mkdir($psRoot . 'uploads/', 0777, true);
define('UPLOADS_PATH', $psRoot . 'uploads/');
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['psRoot'];
    }
}
register_shutdown_function(static function () use ($psRoot): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($psRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($psRoot);
});

use mindstellar\auth\Actor;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;

$admin = api_admin_boot('osc_models_place_field_styles');
scratchdb_pin_site_mode($admin, 'classifieds');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

$p  = DB_TABLE_PREFIX;
$en = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'IN', 'India');
$maha   = seed_region($admin, 'IN', 'Maharashtra');
$goa    = seed_region($admin, 'IN', 'Goa');
$mumbai = seed_city($admin, $maha, 'Mumbai', 'IN');
seed_city($admin, $goa, 'Panaji', 'IN');
seed_country($admin, 'GB', 'United Kingdom');
$kent = seed_region($admin, 'GB', 'Kent');
$cat = seed_category($admin, 'Cars', null, $en);
scratchdb_forget_cache();
(new ReflectionProperty(Category::class, 'instance'))->setValue(null, null);

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->replace($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setPref([
    'moderate_items' => '-1', 'logged_user_item_validation' => '0', 'items_wait_time' => '0', 'reg_user_post' => '0', 'selectable_parent_categories' => '1',
    'language' => 'en_US', 'title_character_length' => '100', 'description_character_length' => '5000',
    'enableField#f_price@items' => '1', 'enableField#images@items' => '0', 'item_required_location' => 'none',
]);
$_SERVER['REMOTE_ADDR'] = '192.0.2.82';

$form = static fn (array $place): array => $place + array(
    'catId'        => (string) $cat,
    'countryId'    => '', 'country' => '', 'regionId' => '', 'region' => '', 'cityId' => '', 'city' => '',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'contactName'  => 'Gary',
    'contactEmail' => 'gary@example.test',
);
// The stored place of a post saved through ItemActions, or the refusal's message.
$post = static function (array $place) use ($form, $admin, $p): array|string {
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($form($place), true);
    $result = $actions->add();
    if (is_string($result)) {
        return trim($result);
    }
    $id = $actions->lastItemId();

    return array_values((array) $admin->query("SELECT fk_c_country_code, s_country, fk_i_region_id, s_region, fk_i_city_id, s_city FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc());
};
// The place errors of the same post as pointer => code, or 'saved'.
$service = new ListingService();
$guest   = Actor::guest('192.0.2.82');
$errors  = static function (array $place) use ($form, $service, $guest): array|string {
    try {
        $service->create(ListingInput::fromArray($form($place), $guest, true), $guest);
    } catch (InvalidException $e) {
        $hits = array();
        foreach ($e->errors() as $err) {
            if (in_array($err['pointer'], array('/country', '/region', '/city'), true)) {
                $hits[$err['pointer']] = $err['code'];
            }
        }

        return $hits;
    }

    return 'saved';
};

$ids          = array('countryId' => 'IN', 'regionId' => (string) $maha, 'cityId' => (string) $mumbai);
$otherRegion  = array('countryId' => 'IN', 'regionId' => (string) $goa, 'cityId' => (string) $mumbai);
$typedRegion  = array('countryId' => 'IN', 'region' => 'Maharashtra');
$wrongCountry = array('countryId' => 'IN', 'regionId' => (string) $kent);
$cityOnly      = array('countryId' => 'GB', 'cityId' => (string) $mumbai);
$unknownRegion = array('countryId' => 'IN', 'region' => 'Atlantis');
$unknownCity  = array('countryId' => 'IN', 'regionId' => (string) $maha, 'city' => 'Smalltown');
$pick         = 'Pick your location from the list.';

harness_section('only places from the list on');
$setPref(array('item_place_from_list' => '1'));
pin('a dropdown post of three ids is saved with their names', array('IN', 'India', (string) $maha, 'Maharashtra', (string) $mumbai, 'Mumbai'), $post($ids));
pin('a dropdown city of another region is refused', $pick, $post($otherRegion));
pin('on /city as a mismatch', array('/city' => 'mismatch'), $errors($otherRegion));
pin('a region of another country is refused on /region', array('/region' => 'mismatch'), $errors($wrongCountry));
pin('a city picked alone, of another country, is refused', array('/region' => 'mismatch'), $errors($cityOnly));
pin('a typed region that is a region of the country is saved with its id', array('IN', 'India', (string) $maha, 'Maharashtra', null, ''), $post($typedRegion));
pin('a typed region that is not one is refused', $pick, $post($unknownRegion));
pin('with one error, on /region', array('/region' => 'not_listed'), $errors($unknownRegion));
$setPref(array('item_required_location' => 'region'));
pin('a typed city under a picked region is refused on /city', array('/city' => 'not_listed'), $errors($unknownCity));
$setPref(array('item_required_location' => 'none'));

harness_section('only places from the list off');
$setPref(array('item_place_from_list' => '0'));
pin('a typed region is saved as typed', array('IN', 'India', null, 'Atlantis', null, ''), $post($unknownRegion));
pin('a typed city under a picked region is saved as typed', array('IN', 'India', (string) $maha, 'Maharashtra', null, 'Smalltown'), $post($unknownCity));
pin('the three ids are still saved with their names', array('IN', 'India', (string) $maha, 'Maharashtra', (string) $mumbai, 'Mumbai'), $post($ids));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
