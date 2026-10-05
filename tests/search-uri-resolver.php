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
 * Pins mindstellar\search\SearchUriResolver: every URI shape the search page accepts,
 * and the friendly-param decoding ("/region,7/pattern,bike/meta4,red").
 *
 * DB-free: the lookups are fixed callables.
 *   php tests/search-uri-resolver.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\SearchUriResolver;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$categories = array(
    'cars'  => array('pk_i_id' => '3', 's_slug' => 'cars'),
    'bikes' => array('pk_i_id' => '4', 's_slug' => 'bikes'),
);
$GLOBALS['lookups'] = array();

$resolver = new SearchUriResolver(
    'search',
    'http://example.com/',
    static function ($id) {
        $GLOBALS['lookups'][] = "region:$id";

        return (string)$id === '12' ? array('pk_i_id' => '12') : false;
    },
    static function ($id) {
        $GLOBALS['lookups'][] = "city:$id";

        return (string)$id === '34' ? array('pk_i_id' => '34') : false;
    },
    static function ($value) use ($categories) {
        $GLOBALS['lookups'][] = "category:$value";

        return $categories[$value] ?? array();
    },
    static function ($slug) use ($categories) {
        $GLOBALS['lookups'][] = "slug:$slug";

        return $categories[$slug] ?? array();
    },
    static function ($slug) {
        $GLOBALS['lookups'][] = "history:$slug";

        return $slug === 'old-cars' ? 'http://example.com/cars' : null;
    }
);

/** Resolve and also return which lookups ran. */
$resolve = static function (string $uri, array $params = array(), bool $rewrite = true) use ($resolver): array {
    $GLOBALS['lookups'] = array();
    $out                = $resolver->resolve($uri, $params, $rewrite);
    $out['lookups']     = $GLOBALS['lookups'];

    return $out;
};

/** The expected answer, with defaults filled in. */
$expect = static function (string $uri, array $parts = array()): array {
    return array_merge(
        array('uri' => $uri, 'params' => array(), 'exports' => array(), 'redirect' => null, 'notFound' => false, 'lookups' => array()),
        $parts
    );
};

harness_section('left alone');

pin('index.php URL, untouched (no trim)', $expect('index.php?page=search&sCategory=3/'), $resolve('index.php?page=search&sCategory=3/'));
pin('/search', $expect('search'), $resolve('search/'));
pin('/search/2 and friendly params', $expect('search/pattern,bike/2'), $resolve('search/pattern,bike/2'));
pin('rewrite off', $expect('cars'), $resolve('cars/', array('sCategory' => 'cars'), false));
pin('an sFeed request', $expect('cars'), $resolve('cars', array('sCategory' => 'cars', 'sFeed' => '')));

harness_section('categories');

pin('/<cat>', $expect('cars', array(
    'params'  => array(array('set', 'sCategory', 'cars')),
    'exports' => array('search_uri' => 'cars'),
    'lookups' => array('category:cars'),
)), $resolve('cars', array('sCategory' => 'cars')));

pin('/<cat>/3 sets the page', $expect('cars/3', array(
    'params'  => array(array('set', 'iPage', '3'), array('set', 'sCategory', 'cars')),
    'exports' => array('search_uri' => 'cars'),
    'lookups' => array('category:cars'),
)), $resolve('cars/3', array('sCategory' => 'cars')));

pin('/<cat>/1 redirects to the URL without a page (302)', $expect('cars/1', array(
    'params'   => array(array('set', 'iPage', '1')),
    'exports'  => array('search_uri' => 'cars'),
    'redirect' => array('url' => 'http://example.com/cars', 'code' => null),
)), $resolve('cars/1', array('sCategory' => 'cars')));

pin('nested a/b/c keeps the last segment', $expect('vehicles/sport/cars', array(
    'params'  => array(array('set', 'sCategory', 'cars')),
    'exports' => array('search_uri' => 'vehicles/sport/cars'),
    'lookups' => array('category:cars'),
)), $resolve('vehicles/sport/cars', array('sCategory' => 'vehicles/sport/cars/')));

pin('a query string is dropped from the URI', $expect('cars', array(
    'params'  => array(array('set', 'sCategory', 'cars')),
    'exports' => array('search_uri' => 'cars'),
    'lookups' => array('category:cars'),
)), $resolve('cars?sOrder=i_price', array('sCategory' => 'cars')));

pin('a bare slug without sCategory', $expect('bikes', array(
    'params'  => array(array('set', 'sCategory', 'bikes')),
    'exports' => array('search_uri' => 'bikes'),
    'lookups' => array('slug:bikes'),
)), $resolve('bikes'));

pin('/search?x=1 is left for the canonical redirect', $expect('search', array(
    'exports' => array('search_uri' => 'search'),
)), $resolve('search?sPattern=x'));

harness_section('unknown and former slugs');

pin('unknown sCategory slug: 404', $expect('nosuch', array(
    'params'   => array(array('set', 'sCategory', 'nosuch')),
    'exports'  => array('search_uri' => 'nosuch'),
    'notFound' => true,
    'lookups'  => array('category:nosuch', 'history:nosuch'),
)), $resolve('nosuch', array('sCategory' => 'nosuch')));

pin('unknown bare slug: 404', $expect('nosuch', array(
    'exports'  => array('search_uri' => 'nosuch'),
    'notFound' => true,
    'lookups'  => array('slug:nosuch', 'history:nosuch'),
)), $resolve('nosuch'));

pin('former slug: 301 to the current URL', $expect('old-cars', array(
    'params'   => array(array('set', 'sCategory', 'old-cars')),
    'exports'  => array('search_uri' => 'old-cars'),
    'redirect' => array('url' => 'http://example.com/cars', 'code' => 301),
    'lookups'  => array('category:old-cars', 'history:old-cars'),
)), $resolve('old-cars', array('sCategory' => 'old-cars')));

pin('a numeric URI is a slug lookup, with no search_uri export', $expect('2', array(
    'notFound' => true,
    'lookups'  => array('slug:2', 'history:2'),
)), $resolve('2'));

harness_section('regions and cities');

pin('/x_<slug>-r12: region plus the category in front', $expect('cars_alpha-r12', array(
    'params'  => array(array('set', 'sRegion', '12'), array('unset', 'sCategory'), array('set', 'sCategory', 'cars')),
    'exports' => array('search_uri' => 'cars_alpha-r12'),
    'lookups' => array('region:12'),
)), $resolve('cars_alpha-r12', array('sCategory' => 'ignored')));

pin('/<slug>-r12 with page 2', $expect('alpha-r12/2', array(
    'params'  => array(array('set', 'iPage', '2'), array('set', 'sRegion', '12'), array('unset', 'sCategory')),
    'exports' => array('search_uri' => 'alpha-r12'),
    'lookups' => array('region:12'),
)), $resolve('alpha-r12/2'));

pin('/x-c34: city, sCategory dropped', $expect('aville-c34', array(
    'params'  => array(array('set', 'sCity', '34'), array('unset', 'sCategory')),
    'exports' => array('search_uri' => 'aville-c34'),
    'lookups' => array('city:34'),
)), $resolve('aville-c34', array('sCategory' => 'x')));

pin('/x_<slug>-c34', $expect('bikes_aville-c34', array(
    'params'  => array(array('set', 'sCity', '34'), array('unset', 'sCategory'), array('set', 'sCategory', 'bikes')),
    'exports' => array('search_uri' => 'bikes_aville-c34'),
    'lookups' => array('city:34'),
)), $resolve('bikes_aville-c34'));

pin('unknown region id: 404', $expect('alpha-r99', array(
    'exports'  => array('search_uri' => 'alpha-r99'),
    'notFound' => true,
    'lookups'  => array('region:99'),
)), $resolve('alpha-r99'));

pin('unknown city id: 404', $expect('aville-c99', array(
    'exports'  => array('search_uri' => 'aville-c99'),
    'notFound' => true,
    'lookups'  => array('city:99'),
)), $resolve('aville-c99'));

harness_section('decodeFriendlyParams');

$names = array(
    array('country', 'sCountry'),
    array('region', 'sRegion'),
    array('city', 'sCity'),
    array('cityarea', 'sCityArea'),
    array('category', 'sCategory'),
    array('user', 'sUser'),
    array('pattern', 'sPattern'),
);
$identity = static fn ($v) => $v;

pin('no params: nothing to set', array(), SearchUriResolver::decodeFriendlyParams('/', $names, '', $identity));
pin('named params map onto request names, in order', array(
    array('sRegion', '7'),
    array('sPattern', 'blue bike'),
    array('sCategory', '3'),
), SearchUriResolver::decodeFriendlyParams('/region,7/pattern,blue bike/category,3', $names, '', $identity));
pin('a renamed preference is honoured', array(array('sPattern', 'bike')), SearchUriResolver::decodeFriendlyParams(
    '/q,bike',
    array(array('q', 'sPattern')),
    '',
    $identity
));
pin('an unknown name passes through as given', array(array('sOrder', 'i_price')), SearchUriResolver::decodeFriendlyParams('/sOrder,i_price', $names, '', $identity));
pin('an empty value is kept', array(array('sCity', '')), SearchUriResolver::decodeFriendlyParams('/city,', $names, '', $identity));
pin('custom fields build the meta array, one level and two', array(
    array('meta', array(4 => 'red')),
    array('meta', array(4 => 'red', 5 => array('from' => '3'))),
), SearchUriResolver::decodeFriendlyParams('/meta4,red/meta5-from,3', $names, '', $identity));
pin('custom fields merge into the meta the request already had', array(
    array('meta', array(9 => 'x', 4 => 'red')),
), SearchUriResolver::decodeFriendlyParams('/meta4,red', $names, array(9 => 'x'), $identity));
pin('a scalar meta is not merged', array(
    array('meta', array(4 => 'red')),
), SearchUriResolver::decodeFriendlyParams('/meta4,red', $names, 'junk', $identity));

// Params::getParamArray() purifies the meta it reads, so each later segment sees the
// earlier values purified and the newest one raw. A marking purifier makes that visible.
$mark = static function ($v) {
    return array_map(static fn ($x) => is_array($x) ? $x : '[' . $x . ']', $v);
};
pin('meta read back between segments goes through the purifier', array(
    array('meta', array(9 => '[x]', 4 => 'red')),
    array('meta', array(9 => '[[x]]', 4 => '[red]', 5 => 'blue')),
), SearchUriResolver::decodeFriendlyParams('/meta4,red/meta5,blue', $names, array(9 => 'x'), $mark));
pin('a literal meta segment replaces meta for the next one', array(
    array('meta', 'x'),
    array('meta', array(4 => 'red')),
), SearchUriResolver::decodeFriendlyParams('/meta,x/meta4,red', $names, array(9 => 'y'), $identity));

exit(harness_result());

/* file end: ./tests/search-uri-resolver.php */
