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
 * BoxFilter reads "west,south,east,north" and refuses anything else, and its condition is one
 * longitude range, or two when the box crosses the date line.
 *
 * Usage:  php tests/search-box-filter.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\query\BoxFilter;
use mindstellar\search\query\Statement;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

if (!defined('DB_TABLE_PREFIX')) {
    define('DB_TABLE_PREFIX', 'oc_');
}

harness_section('BoxFilter::parse');

pin('four numbers, west south east north', array(72.8, 18.9, 73.1, 19.3), BoxFilter::parse('72.8,18.9,73.1,19.3'));
pin('spaces around a number are fine', array(-10.0, -5.0, 10.0, 5.0), BoxFilter::parse(' -10, -5 ,10,5 '));
pin('edges round to four decimals', array(72.8123, 18.9, 73.1, 19.3), BoxFilter::parse('72.81234,18.9,73.1,19.3'));
pin('west past east is kept: it crosses the date line', array(170.0, -20.0, -170.0, 20.0), BoxFilter::parse('170,-20,-170,20'));
pin('the edges of the globe are kept', array(-180.0, -90.0, 180.0, 90.0), BoxFilter::parse('-180,-90,180,90'));
pin('a box of no height is kept', array(1.0, 2.0, 3.0, 2.0), BoxFilter::parse('1,2,3,2'));
foreach (array(
    'three numbers'           => '1,2,3',
    'five numbers'            => '1,2,3,4,5',
    'a word'                  => '1,2,east,4',
    'an empty edge'           => '1,,3,4',
    'south above north'       => '1,10,3,5',
    'latitude past 90'        => '1,-91,3,4',
    'longitude past 180'      => '-180.5,1,3,4',
    'range checked first'     => '180.00001,1,3,4',
    'infinity'                => '1e999,1,3,4',
    'not a number'            => 'NAN,1,3,4',
    'empty'                   => '',
    'too long'                => str_repeat('1', 101),
) as $label => $value) {
    pin($label . ' is ignored', null, BoxFilter::parse($value));
}
pin('an array is ignored', null, BoxFilter::parse(array('1', '2', '3', '4')));
pin('a number is ignored', null, BoxFilter::parse(4));

harness_section('BoxFilter::format');

pin('no trailing zeros', '72.8,18.9,73.1,19.3', BoxFilter::format(array(72.8, 18.9, 73.1, 19.3)));
pin('whole numbers and a negative zero', '-10,0,10,5', BoxFilter::format(array(-10.0, -0.0, 10.0, 5.0)));
pin('parse and format round-trip', '170,-20.1235,-170,20', BoxFilter::format((array) BoxFilter::parse('170.0,-20.12345,-170,20.00')));

harness_section('BoxFilter::apply');

$where = static function (?array $box): array {
    $filter = new BoxFilter();
    if ($box !== null) {
        $filter->within(...$box);
    }
    $statement = new Statement();
    $filter->apply($statement);
    [$sql, $params] = $statement->compile();
    $at = strpos($sql, 'WHERE ');

    return array($at === false ? '' : trim(substr($sql, $at + 6)), $params);
};

pin(
    'one longitude range',
    array('(oc_t_item_location.d_geo_lat BETWEEN ? AND ? AND (oc_t_item_location.d_geo_long BETWEEN ? AND ?))', array(18.9, 19.3, 72.8, 73.1)),
    $where(array(72.8, 18.9, 73.1, 19.3))
);
pin(
    'across the date line, two longitude ranges',
    array(
        '(oc_t_item_location.d_geo_lat BETWEEN ? AND ? AND (oc_t_item_location.d_geo_long BETWEEN ? AND ? OR oc_t_item_location.d_geo_long BETWEEN ? AND ?))',
        array(-20.0, 20.0, 170.0, 180.0, -180.0, -170.0),
    ),
    $where(array(170.0, -20.0, -170.0, 20.0))
);
pin('no box adds no condition', array('', array()), $where(null));

$filter = new BoxFilter();
check('unused until a box is set', !$filter->used() && $filter->recorded() === null);
$filter->within(1.0, 2.0, 3.0, 4.0);
pin('recorded by edge', array('west' => 1.0, 'south' => 2.0, 'east' => 3.0, 'north' => 4.0), $filter->recorded());

exit(harness_result());
