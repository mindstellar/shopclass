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
 * Pins the one path for place names in the visitor's language: the container holds one name
 * store that LocationNames and the location box suggestions share, the public AJAX lists go
 * through the shared list helper, and the sort by shown name stands on its own.
 *
 * DB-free.  Usage:  php tests/location-name-paths.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\container\Container;
use mindstellar\location\LocationAutocompleteQuery;
use mindstellar\location\LocationNames;
use mindstellar\location\LocationNameStore;

harness_reset_container();
$root  = Container::root();
$field = static function (object $o, string $name): mixed {
    $p = new ReflectionProperty($o, $name);

    return $p->getValue($o);
};

harness_section('one name store');
check('the container holds the name store', $root->has(LocationNameStore::class));
$store = $root->has(LocationNameStore::class) ? $root->get(LocationNameStore::class) : null;
check('LocationNames reads through it', $store !== null && $field($root->get(LocationNames::class), 'store') === $store);
check('the location box suggestions use it too', $store !== null && $field(LocationAutocompleteQuery::inLocale('en_US'), 'store') === $store);

harness_section('public AJAX lists');
$ajax = ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php';
foreach (array('regions' => 'region', 'cities' => 'city') as $method => $type) {
    $src = harness_method_source($ajax, $method);
    check($method . '() uses the shared list helper', str_contains($src, "_osc_location_list(") && str_contains($src, "'" . $type . "'"));
}
foreach (array('regions', 'cities', 'location', 'locationCountries', 'locationRegions', 'locationCities') as $method) {
    check($method . '() takes the screen language from osc_current_ui_locale()', !str_contains(harness_method_source($ajax, $method), 'osc_current_user_locale'));
}

harness_section('sort by shown name');
check('LocationNames::sortByName exists', method_exists(LocationNames::class, 'sortByName'));
if (method_exists(LocationNames::class, 'sortByName')) {
    $rows = array(
        array('id' => 1, 'name' => 'cherry'),
        array('id' => 2, 'name' => 'Apple'),
        array('id' => 3, 'name' => 'banana'),
    );
    pin('orders rows by the name key', array(2, 3, 1), array_column(LocationNames::sortByName($rows, 'name', 'de_DE'), 'id'));
}

exit(harness_result());

/* file end: ./tests/location-name-paths.php */
