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
 * Characterization test for osc_search_url() -- the busiest URL builder in the app.
 *
 * Every filter link, facet, pagination link and canonical tag on the public site
 * comes out of this one function, and it answers five subdomain modes, four
 * canonical shapes and a query-string fallback. It pins the CURRENT output, quirks
 * and all, so the duplication inside it can be collapsed and proved unchanged.
 *
 * The lookups are inputs, so the five models are stubbed with fixed rows; the URL
 * assembly, the preference reads and osc_sanitizeString() run for real. A row that
 * is deliberately absent (id 99) exercises the does-not-exist fallbacks.
 *   php tests/search-url.php
 */

define('WEB_PATH', 'http://example.com/');
define('REL_WEB_URL', '/');
define('OSC_DEBUG', false);

$GLOBALS['__prefs']  = array();
$GLOBALS['__rw']     = false;
$GLOBALS['__subType'] = '';
$GLOBALS['__isSub']  = false;
$GLOBALS['__request'] = array();
$GLOBALS['__view']   = array();

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['__prefs'][$key] ?? '';
}
function osc_rewrite_enabled()
{
    return (bool)$GLOBALS['__rw'];
}
function osc_is_ssl()
{
    return false;
}
function osc_subdomain_type()
{
    return $GLOBALS['__subType'];
}
function osc_subdomain_host()
{
    return 'example.com';
}
function osc_is_subdomain()
{
    return (bool)$GLOBALS['__isSub'];
}
function osc_category_id()
{
    return View::newInstance()->_get('category_id') ?: 0;
}
function osc_category_slug()
{
    return View::newInstance()->_get('category_slug');
}
function osc_field($item, $field, $locale)
{
    return ($item !== null && isset($item[$field])) ? $item[$field] : '';
}
function osc_base_url($with_index = false)
{
    return WEB_PATH . ($with_index ? 'index.php' : '');
}

/** Stand-in for the request: what the current page was already filtered by. */
class Params
{
    public static function getParam($key, $html = false, $xss = true, $quotes = true)
    {
        return $GLOBALS['__request'][$key] ?? '';
    }
}

/** One fixed catalogue, shared by the five model stand-ins. */
abstract class FakeModel
{
    protected static $rows = array();

    public static function newInstance()
    {
        return new static();
    }

    public function findByPrimaryKey($id)
    {
        return static::$rows[(int)$id] ?? null;
    }

    protected function findByField($field, $value)
    {
        foreach (static::$rows as $row) {
            if (isset($row[$field]) && $row[$field] === $value) {
                return $row;
            }
        }

        return null;
    }
}

class Category extends FakeModel
{
    // 3 and 4 are roots. 20 > 21 > 22 is a three-level branch, so {CATEGORIES} has a
    // real path to walk and a reversed walk shows up.
    protected static $rows = array(
        3  => array('pk_i_id' => 3, 's_slug' => 'cars', 'fk_i_parent_id' => null),
        4  => array('pk_i_id' => 4, 's_slug' => 'bikes', 'fk_i_parent_id' => null),
        20 => array('pk_i_id' => 20, 's_slug' => 'vehicles', 'fk_i_parent_id' => null),
        21 => array('pk_i_id' => 21, 's_slug' => 'trucks', 'fk_i_parent_id' => 20),
        22 => array('pk_i_id' => 22, 's_slug' => 'pickups', 'fk_i_parent_id' => 21),
    );

    public function findBySlug($slug)
    {
        return $this->findByField('s_slug', $slug);
    }

    /** Leaf first, up to the root -- the order the real one hands back. */
    public function hierarchy($id)
    {
        $chain = array();
        $row   = $this->findByPrimaryKey($id);
        while ($row !== null) {
            $chain[] = $row;
            $row     = $row['fk_i_parent_id'] !== null ? $this->findByPrimaryKey($row['fk_i_parent_id']) : null;
        }

        return $chain;
    }
}

class Country extends FakeModel
{
    protected static $rows = array(
        1 => array('pk_i_id' => 1, 's_slug' => 'india', 's_code' => 'IN'),
    );

    public function findByCode($code)
    {
        return $this->findByField('s_code', $code);
    }
}

class Region extends FakeModel
{
    protected static $rows = array(
        7 => array('pk_i_id' => 7, 's_slug' => 'gujarat', 's_name' => 'Gujarat'),
    );

    public function findByName($name)
    {
        return $this->findByField('s_name', $name);
    }
}

class City extends FakeModel
{
    protected static $rows = array(
        11 => array('pk_i_id' => 11, 's_slug' => 'surat', 's_name' => 'Surat'),
    );

    public function findByName($name)
    {
        return $this->findByField('s_name', $name);
    }
}

class User extends FakeModel
{
    protected static $rows = array(
        5 => array('pk_i_id' => 5, 's_username' => 'jo'),
    );

    public function findByUsername($name)
    {
        return $this->findByField('s_username', $name);
    }
}

// The real View: it is standalone, and its cursor behaviour is exactly what the
// place-list helpers lean on.
require_once __DIR__ . '/../oc-includes/osclass/classes/View.php';

/** Counts of what each list loader was asked for, so a second load would show up. */
$GLOBALS['__loads'] = array();

abstract class FakeStats
{
    public static function newInstance()
    {
        return new static();
    }
}

class CountryStats extends FakeStats
{
    public function listCountries()
    {
        $GLOBALS['__loads'][] = 'countries';

        return array(
            array('country_name' => 'India', 'country_code' => 'IN', 'items' => 9),
            array('country_name' => 'Nepal', 'country_code' => 'NP', 'items' => 2),
        );
    }
}

class RegionStats extends FakeStats
{
    public function listRegions($country = '%%%%')
    {
        $GLOBALS['__loads'][] = 'regions:' . $country;

        return array(
            array('region_name' => 'Gujarat', 'region_slug' => 'gujarat', 'region_id' => 7, 'items' => 4),
        );
    }
}

class CityStats extends FakeStats
{
    public function listCities($region = '%%%%')
    {
        $GLOBALS['__loads'][] = 'cities:' . $region;

        return array(
            array('city_name' => 'Surat', 'city_slug' => 'surat', 'city_id' => 11, 'items' => 3),
            array('city_name' => 'Rajkot', 'city_slug' => 'rajkot', 'city_id' => 12, 'items' => 1),
        );
    }
}

require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/formatting.php';   // osc_sanitizeString
require_once __DIR__ . '/../oc-includes/osclass/classes/utility/Utils.php';
require_once __DIR__ . '/lib/harness.php';

if (!function_exists('osc_prune_array')) {
    function osc_prune_array(&$input)
    {
        \mindstellar\utility\Utils::pruneArray($input);
    }
}

// hSearch.php is loaded last: everything it leans on is already defined above.
require_once __DIR__ . '/../oc-includes/osclass/helpers/hSearch.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$GLOBALS['__prefs'] = array(
    'rewrite_search_url'          => 'search',
    'rewrite_search_country'      => 'country',
    'rewrite_search_region'       => 'region',
    'rewrite_search_city'         => 'city',
    'rewrite_search_city_area'    => 'cityarea',
    'rewrite_search_category'     => 'category',
    'rewrite_search_user'         => 'user',
    'rewrite_search_pattern'      => 'pattern',
    'rewrite_cat_url'             => '{CATEGORIES}',
    'seo_url_search_prefix'       => '',
);

/** Throw the shared View away, so each case starts with nothing exported. */
function viewReset()
{
    $ref  = new ReflectionClass('View');
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    $GLOBALS['__loads'] = array();
}

/** Build a search URL under a controlled request/view/subdomain state. */
function searchUrl($params, array $state = array())
{
    $GLOBALS['__rw']      = $state['rewrite'] ?? false;
    $GLOBALS['__subType'] = $state['subdomain'] ?? '';
    $GLOBALS['__isSub']   = $state['onSubdomain'] ?? false;
    $GLOBALS['__request'] = $state['request'] ?? array();
    viewReset();
    foreach (($state['view'] ?? array()) as $key => $value) {
        View::newInstance()->_exportVariableToView($key, $value);
    }
    if (isset($state['prefs'])) {
        foreach ($state['prefs'] as $k => $v) {
            $GLOBALS['__prefs'][$k] = $v;
        }
    }
    $out = osc_search_url($params);
    if (isset($state['prefs'])) {
        foreach (array_keys($state['prefs']) as $k) {
            $GLOBALS['__prefs'][$k] = $k === 'rewrite_cat_url' ? '{CATEGORIES}' : '';
        }
        $GLOBALS['__prefs']['rewrite_search_url'] = 'search';
    }

    return $out;
}

require __DIR__ . '/fixtures/search-url-cases.php';

foreach (array('off' => $EXPECT_OFF, 'on' => $EXPECT_ON) as $mode => $expected) {
    $rw = ($mode === 'on');

    harness_section('search URLs, friendly URLs ' . $mode);
    foreach ($CASES as $label => $case) {
        $state            = $case[1];
        $state['rewrite'] = $rw;
        pin($label, $expected[$label], searchUrl($case[0], $state));
    }

    harness_section('subdomains, friendly URLs ' . $mode);
    foreach ($SUBDOMAIN as $label => $case) {
        $state            = $case[1];
        $state['rewrite'] = $rw;
        pin($label, $expected[$label], searchUrl($case[0], $state));
    }
}

/**
 * Walk a place list the way a theme does, but give up after $limit steps. A list
 * that is reloaded on every call never runs out, and a bare while() would hang
 * the run instead of failing it.
 */
function walk(callable $has, callable $read, $limit = 20)
{
    $out = array();
    while (count($out) < $limit && $has()) {
        $out[] = $read();
    }

    return $out;
}

harness_section('walking a place list');

// Each list is fetched once, on first use, and the walk resets itself at the end so
// a second loop over the same list starts again from the top.
viewReset();
$names = walk('osc_has_list_countries', static function () {
    return osc_list_country_name() . '/' . osc_list_country_code() . '/' . osc_list_country_items();
});
pin('every country, once', array('India/IN/9', 'Nepal/NP/2'), $names);
pin('the list was fetched once', array('countries'), $GLOBALS['__loads']);
$again = walk('osc_has_list_countries', 'osc_list_country_name');
pin('the walk starts again from the top', array('India', 'Nepal'), $again);
pin('and still only one fetch', array('countries'), $GLOBALS['__loads']);
pin('counting needs no second fetch', 2, osc_count_list_countries());

viewReset();
pin('counting on its own fetches the list', 2, osc_count_list_countries());
pin('that fetch happened', array('countries'), $GLOBALS['__loads']);

viewReset();
$regions = walk(static function () {
    return osc_has_list_regions('IN');
}, static function () {
    return osc_list_region_name() . '/' . osc_list_region_slug() . '/' . osc_list_region_id() . '/'
        . osc_list_region_items();
});
pin('every region of a country', array('Gujarat/gujarat/7/4'), $regions);
pin('the country was passed to the lookup', array('regions:IN'), $GLOBALS['__loads']);

viewReset();
pin('regions with no country asked use the wildcard', 1, osc_count_list_regions());
pin('and say so', array('regions:%%%%'), $GLOBALS['__loads']);

viewReset();
$cities = walk(static function () {
    return osc_has_list_cities(7);
}, static function () {
    return osc_list_city_name() . '/' . osc_list_city_slug() . '/' . osc_list_city_id() . '/'
        . osc_list_city_items();
});
pin('every city of a region', array('Surat/surat/11/3', 'Rajkot/rajkot/12/1'), $cities);
pin('the region was passed to the lookup', array('cities:7'), $GLOBALS['__loads']);
pin('counting cities needs no second fetch', 2, osc_count_list_cities(7));

viewReset();
pin('cities with no region asked use the wildcard', 2, osc_count_list_cities());
pin('and say so', array('cities:%%%%'), $GLOBALS['__loads']);

exit(harness_result());
