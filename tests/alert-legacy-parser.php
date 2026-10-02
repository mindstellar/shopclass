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
 * Pins mindstellar\search\LegacyAlertParser: every fragment shape core writes into an
 * old-format alert reads back to its search values, and anything else is held. The
 * fragments are built here the way Search and SearchBuilder build them, with the
 * driver's escaping done by hand, so no database is needed.
 *   php tests/alert-legacy-parser.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\AlertEnvelope;
use mindstellar\search\LegacyAlertParser as P;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

function osc_apply_filter($hook, $content = '', ...$args)
{
    return $content;
}

// A zone with daylight saving, so a day start is not a multiple of 86400.
date_default_timezone_set('Europe/Madrid');

// convert() reads the fragments against the install's own prefix.
define('DB_TABLE_PREFIX', 'oc_');
$prefix = DB_TABLE_PREFIX;
$meta   = $prefix . 't_item_meta';

/** mysqli::real_escape_string with backslash escapes on (the server default). */
$esc = static function (string $v): string {
    return strtr($v, array(
        "\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r",
        "'"  => "\\'", '"' => '\\"', "\x1a" => "\\Z",
    ));
};
/** The same under NO_BACKSLASH_ESCAPES: only the quote is doubled. */
$escDoubled = static function (string $v): string {
    return str_replace("'", "''", $v);
};

$types = array(
    3  => 'TEXT', 4 => 'DROPDOWN', 5 => 'RADIO', 6 => 'CHECKBOX', 7 => 'DATE',
    8  => 'DATEINTERVAL', 9 => 'NUMBER', 10 => 'TEXTAREA', 11 => 'URL',
);
$fieldType = static function (int $id) use ($types): ?string {
    return $types[$id] ?? null;
};

/** One custom-field condition, spelled as SearchBuilder::applyMeta() spells it. */
$cond = static function (string $shape, int $key, $aux, ?callable $e = null) use ($prefix, $meta, $esc): string {
    $e     = $e ?? $esc;
    $table = $meta;
    switch ($shape) {
        case 'LIKE':
            $sql = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql .= $table . '.s_value LIKE ' . "'" . $e('%' . $aux . '%') . "'";
            break;
        case 'EQ':
            $sql = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql .= $table . '.s_value = ' . "'" . $e((string)$aux) . "'";
            break;
        case 'BARE':
            $sql = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql .= $table . '.s_value = ' . $aux;
            break;
        case 'DATE':
            $y     = (int)date('Y', (int)$aux);
            $m     = (int)date('n', (int)$aux);
            $d     = (int)date('j', (int)$aux);
            $start = mktime(0, 0, 0, $m, $d, $y);
            $end   = mktime(23, 59, 59, $m, $d, $y);
            $sql   = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql   .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql   .= $table . '.s_value >= ' . $start . ' AND ';
            $sql   .= $table . '.s_value <= ' . $end;
            break;
        case 'RANGE':
            $sql = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql .= $table . '.s_value >= ' . $aux[0] . ' AND ';
            $sql .= $table . '.s_value <= ' . $aux[1];
            break;
        case 'INTERVAL':
            $sql  = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql  .= $table . '.fk_i_field_id = ' . $key . ' AND ';
            $sql  .= $aux[0] . ' >= ' . $table . ".s_value AND s_multi = 'from'";
            $sql1 = "SELECT fk_i_item_id FROM $table WHERE ";
            $sql1 .= $table . '.fk_i_field_id = ' . ($aux[2] ?? $key) . ' AND ';
            $sql1 .= $aux[1] . ' <= ' . $table . ".s_value AND s_multi = 'to'";
            $sql  = 'select a.fk_i_item_id from (' . $sql . ') a where a.fk_i_item_id IN (' . $sql1 . ')';
            break;
        default:
            throw new InvalidArgumentException($shape);
    }

    return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
};

/** Location fragments, as Search::addCity() and friends write them. */
$loc = static function (string $column, $value) use ($prefix): string {
    return is_int($value)
        ? sprintf('%st_item_location.%s = %d ', $prefix, $column, $value)
        : sprintf('%st_item_location.%s LIKE %s ', $prefix, $column, $value);
};
$user = static function (int $id) use ($prefix): string {
    return sprintf('%st_item.fk_i_user_id = %d ', $prefix, $id);
};

/** A blob shaped like Search::toJson() after the controller's order()/page(). */
$blob = static function (array $over = array()): array {
    return array_merge(array(
        'price_min'             => 0,
        'price_max'             => 0,
        'aCategories'           => array(),
        'city_areas'            => array(),
        'cities'                => array(),
        'regions'               => array(),
        'countries'             => array(),
        'withPattern'           => false,
        'sPattern'              => null,
        'tables'                => array(),
        'tables_join'           => array(),
        'no_catched_tables'     => array(),
        'no_catched_conditions' => array(),
        'user_ids'              => null,
        'order_column'          => 'dt_pub_date',
        'order_direction'       => 'desc',
        'limit_init'            => 0,
        'results_per_page'      => 12,
    ), $over);
};
$values = static function (array $over = array()): array {
    return array_merge(array(
        'sCategory' => array(),
        'sCityArea' => array(),
        'sCity'     => array(),
        'sRegion'   => array(),
        'sCountry'  => array(),
        'sUser'     => array(),
        'sPattern'  => '',
        'bPic'      => null,
        'bPremium'  => null,
        'sPriceMin' => 0,
        'sPriceMax' => 0,
        'meta'      => array(),
    ), $over);
};
$parse = static function (array $v1) use ($fieldType, $prefix): array {
    return P::parse($v1, $fieldType, $prefix);
};
$conds = static function (string ...$c) use ($blob): array {
    return $blob(array('no_catched_conditions' => $c));
};

$day = mktime(0, 0, 0, 3, 29, 2026); // the day clocks go forward in Madrid

/* ------------------------------------------------------------------------- */
harness_section('core shapes read back to their values');

$accept = array(
    array('an empty search', $blob(), $values()),
    array('categories, ints and digit strings', $blob(array('aCategories' => array(1, '2'))), $values(array('sCategory' => array(1, 2)))),
    array('city area by id', $blob(array('city_areas' => array($loc('fk_i_city_area_id', 7)))), $values(array('sCityArea' => array(7)))),
    array(
        'city area by name',
        $blob(array('city_areas' => array($loc('s_city_area', "'" . $esc('Downtown') . "'")))),
        $values(array('sCityArea' => array('Downtown')))
    ),
    array('city by id', $blob(array('cities' => array($loc('fk_i_city_id', 12)))), $values(array('sCity' => array(12)))),
    array(
        'city by name, backslash-escaped quote',
        $blob(array('cities' => array($loc('s_city', "'" . $esc("L'Hospitalet") . "'")))),
        $values(array('sCity' => array("L'Hospitalet")))
    ),
    array(
        'city by name, doubled quote',
        $blob(array('cities' => array($loc('s_city', "'" . $escDoubled("L'Hospitalet") . "'")))),
        $values(array('sCity' => array("L'Hospitalet")))
    ),
    array('region by id', $blob(array('regions' => array($loc('fk_i_region_id', 3)))), $values(array('sRegion' => array(3)))),
    array(
        'region by name, and a second one',
        $blob(array('regions' => array($loc('s_region', "'Alpha'"), $loc('s_region', "'Beta'")))),
        $values(array('sRegion' => array('Alpha', 'Beta')))
    ),
    array(
        'country by code',
        $blob(array('countries' => array($prefix . "t_item_location.fk_c_country_code = 'us' "))),
        $values(array('sCountry' => array('us')))
    ),
    array(
        'country by name',
        $blob(array('countries' => array($prefix . "t_item_location.s_country LIKE 'United States' "))),
        $values(array('sCountry' => array('United States')))
    ),
    array(
        'two countries, a code and a name',
        $blob(array('countries' => array(
            $prefix . "t_item_location.fk_c_country_code = 'us' ",
            $prefix . "t_item_location.s_country LIKE 'Spain' ",
        ))),
        $values(array('sCountry' => array('us', 'Spain')))
    ),
    array(
        'country by a number, written bare',
        $blob(array('countries' => array($prefix . 't_item_location.s_country LIKE 123 '))),
        $values(array('sCountry' => array('123')))
    ),
    array('user_ids: one id', $blob(array('user_ids' => 5)), $values(array('sUser' => array(5)))),
    array('user_ids: one id as a string', $blob(array('user_ids' => '5')), $values(array('sUser' => array(5)))),
    array('user_ids: fragments', $blob(array('user_ids' => array($user(5), $user(9)))), $values(array('sUser' => array(5, 9)))),
    array('user_ids: an empty list', $blob(array('user_ids' => array())), $values()),
    array('prices', $blob(array('price_min' => 1000, 'price_max' => 10000)), $values(array('sPriceMin' => 1000, 'sPriceMax' => 10000))),
    array('a price stored as a float', $blob(array('price_max' => 250.0)), $values(array('sPriceMax' => 250))),
    array('pattern, raw', $blob(array('sPattern' => 'vintage', 'withPattern' => true)), $values(array('sPattern' => 'vintage'))),
    array('pattern, legacy quoted and escaped', $blob(array('sPattern' => "'o\\'neil'")), $values(array('sPattern' => "o'neil"))),
    array(
        'picture and premium',
        $blob(array('withPicture' => true, 'onlyPremium' => true)),
        $values(array('bPic' => 1, 'bPremium' => 1))
    ),
    array('TEXT', $conds($cond('LIKE', 3, 'low mileage')), $values(array('meta' => array(3 => 'low mileage')))),
    array('TEXTAREA', $conds($cond('LIKE', 10, 'a')), $values(array('meta' => array(10 => 'a')))),
    array('URL', $conds($cond('LIKE', 11, 'example.com/x')), $values(array('meta' => array(11 => 'example.com/x')))),
    array('TEXT with a percent sign inside', $conds($cond('LIKE', 3, '50%')), $values(array('meta' => array(3 => '50%')))),
    array('DROPDOWN', $conds($cond('EQ', 4, 'red')), $values(array('meta' => array(4 => 'red')))),
    array('RADIO', $conds($cond('EQ', 5, 'used')), $values(array('meta' => array(5 => 'used')))),
    array("DROPDOWN, backslash-escaped quote", $conds($cond('EQ', 4, "red's pick")), $values(array('meta' => array(4 => "red's pick")))),
    array(
        'DROPDOWN, doubled quote',
        $conds($cond('EQ', 4, "red's pick", $escDoubled)),
        $values(array('meta' => array(4 => "red's pick")))
    ),
    array(
        'DROPDOWN, backslash, double quote and newline escapes',
        $conds($cond('EQ', 4, "a\\b \"c\"\nd")),
        $values(array('meta' => array(4 => "a\\b \"c\"\nd")))
    ),
    array('DROPDOWN, unquoted (pre-2026-09)', $conds($cond('BARE', 4, 'red')), $values(array('meta' => array(4 => 'red')))),
    array('DROPDOWN, a bare number', $conds($cond('BARE', 4, '5')), $values(array('meta' => array(4 => '5')))),
    array('CHECKBOX', $conds($cond('BARE', 6, '1')), $values(array('meta' => array(6 => '1')))),
    array('DATE', $conds($cond('DATE', 7, $day + 3600 * 15)), $values(array('meta' => array(7 => (string)$day)))),
    array(
        'DATEINTERVAL',
        $conds($cond('INTERVAL', 8, array(1767225600, 1798761599))),
        $values(array('meta' => array(8 => array('from' => '1767225600', 'to' => '1798761599'))))
    ),
    array(
        'NUMBER, whole bounds',
        $conds($cond('RANGE', 9, array((float)3000, (float)20000))),
        $values(array('meta' => array(9 => array('from' => '3000', 'to' => '20000'))))
    ),
    array(
        'NUMBER, fractional bounds',
        $conds($cond('RANGE', 9, array(1.5, 2.25))),
        $values(array('meta' => array(9 => array('from' => '1.5', 'to' => '2.25'))))
    ),
    array('a deleted custom field is dropped, the rest kept', $conds($cond('EQ', 99, 'x'), $cond('EQ', 4, 'red')), $values(array('meta' => array(4 => 'red')))),
    array(
        'everything at once',
        $blob(array(
            'aCategories'           => array(4, '8'),
            'cities'                => array($loc('fk_i_city_id', 12)),
            'countries'             => array($prefix . "t_item_location.fk_c_country_code = 'es' "),
            'sPattern'              => 'bike',
            'withPicture'           => true,
            'price_min'             => 10,
            'no_catched_conditions' => array($cond('LIKE', 3, 'x'), $cond('EQ', 5, 'new')),
        )),
        $values(array(
            'sCategory' => array(4, 8),
            'sCity'     => array(12),
            'sCountry'  => array('es'),
            'sPattern'  => 'bike',
            'bPic'      => 1,
            'sPriceMin' => 10,
            'meta'      => array(3 => 'x', 5 => 'new'),
        ))
    ),
);
foreach ($accept as list($label, $v1, $want)) {
    $got = $parse($v1);
    pin($label, array('values' => $want, 'held' => null), $got);
}

/* ------------------------------------------------------------------------- */
harness_section('anything else is held, with a reason');

$long = str_repeat('x', 256);
$held = array(
    array('an unknown key', $blob(array('sOrder' => 'x')), P::HELD_KEY),
    array('plugin tables', $blob(array('no_catched_tables' => array($prefix . 't_item_car'))), P::HELD_TABLES),
    array('plugin joins', $blob(array('tables_join' => array(array('oc_x', 'oc_x.id = 1', 'LEFT')))), P::HELD_TABLES),
    array('a plugin condition', $conds($prefix . 't_item.i_price > 0'), P::HELD_CONDITION),
    array('OR 1=1 after a core condition', $conds($cond('EQ', 4, 'red') . ' OR 1=1'), P::HELD_CONDITION),
    array('an extra clause inside the subquery', $conds(str_replace("= 'red')", "= 'red' OR 1=1)", $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('a stray quote in the value', $conds(str_replace("'red'", "'red' OR 'a'='a'", $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('a comment', $conds($cond('EQ', 4, 'red') . ' -- '), P::HELD_CONDITION),
    array('a block comment in the value spot', $conds(str_replace("'red'", "/**/'red'", $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('UNION', $conds(str_replace("= 'red')", "= 'red' UNION SELECT pk_i_id FROM oc_t_user)", $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('another table prefix', $conds(str_replace('oc_', 'xx_', $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('a bare value with a space', $conds($cond('BARE', 4, 'red OR 1')), P::HELD_CONDITION),
    array('the same field twice', $conds($cond('EQ', 4, 'red'), $cond('EQ', 4, 'blue')), P::HELD_CONDITION),
    array(
        'a DATEINTERVAL over two fields',
        $conds($cond('INTERVAL', 8, array(1767225600, 1798761599, 9))),
        P::HELD_CONDITION
    ),
    array('conditions not a list', $blob(array('no_catched_conditions' => 'x')), P::HELD_VALUE),
    array('a TEXT filter on what is now a dropdown', $conds($cond('LIKE', 4, 'red')), P::HELD_FIELD_TYPE),
    array('a DATEINTERVAL filter on what is now a number', $conds($cond('INTERVAL', 9, array(1, 2))), P::HELD_FIELD_TYPE),
    array('CHECKBOX other than 1', $conds($cond('BARE', 6, '2')), P::HELD_VALUE),
    array('DATE start not at the start of its day', $conds($cond('RANGE', 7, array($day + 1, $day + 86400 - 1))), P::HELD_VALUE),
    array('DATE end not the end of that day', $conds($cond('RANGE', 7, array($day, $day + 86400 * 2))), P::HELD_VALUE),
    array('DATE with a fractional bound', $conds($cond('RANGE', 7, array('1.5', '2'))), P::HELD_VALUE),
    array('NUMBER bound 0 (the builder would drop the filter)', $conds($cond('RANGE', 9, array(0, 5))), P::HELD_VALUE),
    array('a NUL after unescaping', $conds($cond('EQ', 4, "red\0")), P::HELD_VALUE),
    array('a value over 255 bytes', $conds($cond('EQ', 4, $long)), P::HELD_VALUE),
    array('an empty TEXT value', $conds($cond('LIKE', 3, '')), P::HELD_VALUE),
    array('an escape real_escape_string never writes, with a lone quote', $conds(str_replace("'red'", "'r\\x'd'", $cond('EQ', 4, 'red'))), P::HELD_CONDITION),
    array('a location with OR', $blob(array('cities' => array($prefix . 't_item_location.fk_i_city_id = 1 OR 1=1 '))), P::HELD_FILTER),
    array('a location without its trailing space', $blob(array('cities' => array($prefix . 't_item_location.fk_i_city_id = 1'))), P::HELD_FILTER),
    array('a location name with a stray quote', $blob(array('cities' => array($loc('s_city', "'a' OR 'b'")))), P::HELD_FILTER),
    array('a numeric location name', $blob(array('cities' => array($loc('s_city', "'123'")))), P::HELD_FILTER),
    array('a location on another column', $blob(array('cities' => array($loc('s_region', "'Alpha'")))), P::HELD_FILTER),
    array('a location from another table prefix', $blob(array('cities' => array('xx_t_item_location.fk_i_city_id = 1 '))), P::HELD_FILTER),
    array('an upper-case country code', $blob(array('countries' => array($prefix . "t_item_location.fk_c_country_code = 'US' "))), P::HELD_FILTER),
    array('a two-letter country name', $blob(array('countries' => array($prefix . "t_item_location.s_country LIKE 'us' "))), P::HELD_FILTER),
    array('a user fragment with OR', $blob(array('user_ids' => array($user(5) . 'OR 1=1 '))), P::HELD_FILTER),
    array('a user id with SQL', $blob(array('user_ids' => '5 OR 1=1')), P::HELD_FILTER),
    array('a category with SQL', $blob(array('aCategories' => array('1) OR SLEEP(1) -- '))), P::HELD_VALUE),
    array('a category list that is not a list', $blob(array('aCategories' => '1')), P::HELD_VALUE),
    array('withPicture false', $blob(array('withPicture' => false)), P::HELD_VALUE),
    array('a pattern that is a list', $blob(array('sPattern' => array('x'))), P::HELD_VALUE),
    array('a price that is not a number', $blob(array('price_min' => 'abc')), P::HELD_VALUE),
    array('a location list that is not a list', $blob(array('regions' => 'Alpha')), P::HELD_VALUE),
);
foreach ($held as list($label, $v1, $reason)) {
    pin($label, array('values' => null, 'held' => $reason), $parse($v1));
}

/* ------------------------------------------------------------------------- */
harness_section('convert(): the stored string');

pin(
    'a core row becomes the canonical envelope',
    '{"v":2,"params":{"bPic":1,"sCity":["Aville"],"sPattern":"o\'neil","sPriceMax":500}}',
    P::convert(json_encode($blob(array(
        'cities'      => array($loc('s_city', "'Aville'")),
        'withPicture' => true,
        'price_max'   => 500,
        'sPattern'    => "'o\\'neil'",
    ))), $fieldType)['json']
);
pin(
    'a custom-field filter with no category (core never wrote one) is held',
    'unknown_condition',
    P::convert(json_encode($conds($cond('EQ', 4, 'red'))), $fieldType)['held']
);
pin('a held row becomes the held marker', array('json' => '{"v":2,"held":"extra_tables"}', 'held' => 'extra_tables'), P::convert(json_encode($blob(array('tables_join' => array(array(1)))))));
pin('a row that is not JSON is held', array('json' => '{"v":2,"held":"not_json"}', 'held' => 'not_json'), P::convert('nope'));
pin('a NULL column is held', 'not_json', P::convert(null)['held']);
pin(
    'a v2 envelope that validates is kept as it is',
    array('json' => '{"v":2,"params":{"sPattern":"x"}}', 'held' => null),
    P::convert('{"v":2,"params":{"sPattern":"x"}}')
);
pin('a v2 envelope that does not validate is held', 'invalid_envelope', P::convert('{"v":2,"params":{"no_catched_conditions":["1=1"]}}')['held']);
pin('heldReason() reads a held marker', 'extra_tables', AlertEnvelope::heldReason('{"v":2,"held":"extra_tables"}'));
pin('heldReason() is null for a search', null, AlertEnvelope::heldReason('{"v":2,"params":{}}'));

exit(harness_result());
