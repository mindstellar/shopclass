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
 * Pins the stored alert format, mindstellar\search\AlertEnvelope: one canonical string per
 * search (t_alerts.s_search is the de-duplication key), values only, and validate()
 * refusing anything that is not exactly that. No categories are used, so no database.
 *   php tests/alert-envelope.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\AlertEnvelope;
use mindstellar\search\SearchCriteria;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// One stand-in plugin on alert_search_params, switched per section.
$GLOBALS['__plugin'] = null;

function osc_apply_filter($hook, $content = '', ...$args)
{
    if ($hook === 'alert_search_params' && is_callable($GLOBALS['__plugin'])) {
        return ($GLOBALS['__plugin'])($content, ...$args);
    }

    return $content;
}

$build = static function (array $request): string {
    return AlertEnvelope::build(SearchCriteria::fromRequest($request), $request);
};

harness_section('canonical form');

pin('no criteria -> empty params object', '{"v":2,"params":{}}', $build(array()));

pin(
    'empty values and false flags are dropped',
    '{"v":2,"params":{}}',
    $build(array(
        'sPattern'  => '  ',
        'bPic'      => '0',
        'bPremium'  => '',
        'sPriceMin' => '',
        'sPriceMax' => '0',
        'sCity'     => '',
        'sUser'     => '',
        'sLocale'   => 'not a locale',
        'meta'      => array(3 => '', 4 => array('from' => '', 'to' => '')),
    ))
);

pin(
    'order and paging keys are not part of an alert',
    '{"v":2,"params":{"sPattern":"bike"}}',
    $build(array('sPattern' => 'bike', 'sOrder' => 'i_price', 'iOrderType' => 'asc', 'iPage' => '3', 'iPagesize' => '50'))
);

$a = $build(array(
    'sPattern'  => 'road bike/fast é',
    'sCity'     => 'Pune,12',
    'sRegion'   => '7',
    'sCountry'  => 'IN',
    'sUser'     => '5,seller',
    'sLocale'   => 'en_US',
    'bPic'      => '1',
    'bPremium'  => '1',
    'sPriceMin' => '100.50',
    'sPriceMax' => '900',
    'meta'      => array('9' => 'red', '3' => array('to' => '20', 'from' => '10')),
));
$b = $build(array(
    'meta'      => array('3' => array('from' => '10', 'to' => '20'), '9' => 'red'),
    'sPriceMax' => '900',
    'sPriceMin' => '100',
    'bPremium'  => '1',
    'bPic'      => '1',
    'sLocale'   => array('en_US'),
    'sUser'     => array('seller', '5'),
    'sCountry'  => array('IN'),
    'sRegion'   => array('7'),
    'sCity'     => array('12', 'Pune'),
    'sPattern'  => ' road bike/fast é ',
));
pin('the same search gives the same bytes, whatever the order', $a, $b);
pin(
    'keys sorted, ids int, lists sorted, unicode and slashes unescaped',
    '{"v":2,"params":{"bPic":1,"bPremium":1,"meta":{"3":{"from":"10","to":"20"},"9":"red"},"sCity":[12,"Pune"],'
        . '"sCountry":["IN"],"sLocale":["en_US"],"sPattern":"road bike/fast é","sPriceMax":900,"sPriceMin":100,'
        . '"sRegion":[7],"sUser":[5,"seller"]}}',
    $a
);
pin('the pattern is stored before the search_pattern filter, tags stripped', '{"v":2,"params":{"sPattern":"red car"}}', $build(array('sPattern' => '<b>red</b> car')));
pin('a meta value over 255 bytes is dropped', '{"v":2,"params":{}}', $build(array('meta' => array(4 => str_repeat('x', 256)))));
pin(
    'a meta range keeps only from/to',
    '{"v":2,"params":{"meta":{"4":{"from":"1"}}}}',
    $build(array('meta' => array(4 => array('from' => '1', 'evil' => 'x'))))
);

harness_section('validate() accepts what build() makes');

pin('the built envelope validates to its params', json_decode($a, true)['params'], AlertEnvelope::validate($a));
pin('the empty envelope validates', array(), AlertEnvelope::validate('{"v":2,"params":{}}'));
pin('validateDecoded() agrees', json_decode($a, true)['params'], AlertEnvelope::validateDecoded(json_decode($a, true)));

harness_section('validate() refuses everything else');

$refuse = array(
    'empty string'                  => '',
    'not JSON'                      => '{"v":2,',
    'v is 1'                        => '{"v":1,"params":{}}',
    'v is "2"'                      => '{"v":"2","params":{}}',
    'no v'                          => '{"params":{}}',
    'params first'                  => '{"params":{},"v":2}',
    'extra top-level key'           => '{"v":2,"params":{},"held":"x"}',
    'held row'                      => '{"v":2,"held":"unknown SQL"}',
    'unknown key'                   => '{"v":2,"params":{"sFoo":"x"}}',
    'v1 SQL key'                    => '{"v":2,"params":{"no_catched_conditions":["1=1"]}}',
    'v1 tables_join key'            => '{"v":2,"params":{"tables_join":[]}}',
    'v1 user_ids key'               => '{"v":2,"params":{"user_ids":"1"}}',
    'non-int meta key'              => '{"v":2,"params":{"meta":{"x":"red"}}}',
    'meta key 0'                    => '{"v":2,"params":{"meta":{"0":"red"}}}',
    'nested meta value'             => '{"v":2,"params":{"meta":{"3":{"from":["1"]}}}}',
    'meta range with extra key'     => '{"v":2,"params":{"meta":{"3":{"from":"1","or":"1"}}}}',
    'meta value as number'          => '{"v":2,"params":{"meta":{"3":7}}}',
    'nested location'               => '{"v":2,"params":{"sCity":[["x"]]}}',
    'location as a scalar'          => '{"v":2,"params":{"sCity":"Pune"}}',
    'category as a string'          => '{"v":2,"params":{"sCategory":["5"]}}',
    'category list unsorted'        => '{"v":2,"params":{"sCategory":[7,5]}}',
    'pattern as an array'           => '{"v":2,"params":{"sPattern":["x"]}}',
    'flag false'                    => '{"v":2,"params":{"bPic":0}}',
    'flag true (bool)'              => '{"v":2,"params":{"bPic":true}}',
    'bad locale'                    => '{"v":2,"params":{"sLocale":["en_US\' OR 1"]}}',
    'empty value kept'              => '{"v":2,"params":{"sPattern":""}}',
    'keys out of order'             => '{"v":2,"params":{"sPattern":"a","bPic":1}}',
    'escaped slash (not canonical)' => '{"v":2,"params":{"sPattern":"a\/b"}}',
    'oversize'                      => '{"v":2,"params":{"sPattern":"' . str_repeat('a', AlertEnvelope::MAX_BYTES) . '"}}',
);
foreach ($refuse as $label => $json) {
    pin('refuses: ' . $label, null, AlertEnvelope::validate($json));
}
pin('validateDecoded() refuses a v1 blob', null, AlertEnvelope::validateDecoded(array('price_min' => 0, 'aCategories' => array())));
pin('isEnvelope() tells v2 from v1', array(true, false), array(
    AlertEnvelope::isEnvelope(array('v' => 2, 'held' => 'x')),
    AlertEnvelope::isEnvelope(array('price_min' => 0)),
));

harness_section('plugin keys through alert_search_params');

$GLOBALS['__plugin'] = static function (array $params, array $request): array {
    foreach (array('radius', 'tags', 'nested', 'sPattern', 'no_catched_conditions', 'bad key', 'big', 'long') as $key) {
        if (isset($request[$key])) {
            $params[$key] = $request[$key];
        }
    }

    return $params;
};

$withPlugin = $build(array('radius' => '25', 'tags' => array('a', 'b')));
pin(
    'a scalar and a flat list are kept, keys sorted with the core ones',
    '{"v":2,"params":{"radius":"25","tags":["a","b"]}}',
    $withPlugin
);
pin('and validate while the plugin is active', array('radius' => '25', 'tags' => array('a', 'b')), AlertEnvelope::validate($withPlugin));
pin(
    'a nested plugin value is dropped',
    '{"v":2,"params":{}}',
    $build(array('nested' => array('x' => array('y'))))
);
pin(
    'a list with a nested entry is dropped',
    '{"v":2,"params":{}}',
    $build(array('tags' => array('a', array('b'))))
);
$GLOBALS['__plugin'] = static function (array $params, array $request): array {
    $params['sPattern']              = 'planted';
    $params['sUser']                 = array(1);
    $params['no_catched_conditions'] = array('1=1');
    $params['bad key']               = 'x';
    $params['big']                   = str_repeat('x', 256);
    $params['long']                  = range(1, AlertEnvelope::MAX_LIST_ITEMS + 1);

    return $params;
};
pin(
    'core keys, v1 keys, bad names and oversize values from a plugin are all dropped',
    '{"v":2,"params":{"sPattern":"kept"}}',
    $build(array('sPattern' => 'kept'))
);

$GLOBALS['__plugin'] = null;
pin('without the plugin, its key is unknown and refused', null, AlertEnvelope::validate($withPlugin));

exit(harness_result());
