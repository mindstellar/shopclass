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
 * ListingInput::storeAddress(): a shop product with no location gets the store address.
 *
 * Usage: php tests/listing-store-address.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$GLOBALS['shopMode'] = true;
$GLOBALS['prefs']    = array('shop_country' => 'Spain', 'shop_region' => 'Madrid', 'shop_city' => ' Madrid ');

function osc_is_shop_mode()
{
    return $GLOBALS['shopMode'];
}

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$key] ?? '';
}

use mindstellar\listing\ListingInput;

$empty = array('countryId' => '', 'country' => '', 'region' => '', 'regionId' => '', 'city' => '', 'cityId' => '', 'title' => 'Mug');

harness_section('shop mode');
$out = ListingInput::storeAddress($empty);
pin('an empty location takes the store address', array('Spain', 'Madrid', 'Madrid'), array($out['country'], $out['region'], $out['city']));
pin('and the ids stay empty', array('', ''), array($out['countryId'], $out['cityId']));
pin('and other fields are untouched', 'Mug', $out['title']);
foreach (array('countryId' => 'ES', 'country' => 'France', 'region' => 'Lazio', 'city' => 'Rome') as $key => $value) {
    $typed = array($key => $value) + $empty;
    pin("a typed $key leaves the form as it is", $typed, ListingInput::storeAddress($typed));
}
pin('a region id alone does not count as a location', 'Spain', ListingInput::storeAddress(array('regionId' => '4') + $empty)['country']);

harness_section('other cases');
$GLOBALS['shopMode'] = false;
pin('classifieds is untouched', $empty, ListingInput::storeAddress($empty));
$GLOBALS['shopMode'] = true;
$GLOBALS['prefs']    = array();
pin('with no store address it is untouched', $empty, ListingInput::storeAddress($empty) + array());

exit(harness_result());
