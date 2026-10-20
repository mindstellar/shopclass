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
 * Pins that an sCategory sent as an array does not crash the search page.
 *
 * sCategory[]=3&sCategory[]=4 is a real multi-category search: SearchCriteria keeps the
 * array and osc_search_url() joins it. With friendly URLs on, SearchUriResolver ran
 * strpos() over it first, so /search?sCategory[]=3 was a TypeError and an HTTP 500. An
 * array is a list of categories, not a slug, and the resolver now leaves it alone.
 *
 * DB-free: the lookups are fixed callables.  Usage: php tests/search-category-array.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\SearchUriResolver;

$GLOBALS['lookups'] = array();
$lookup             = static function (string $kind, $found) {
    return static function ($value) use ($kind, $found) {
        $GLOBALS['lookups'][] = $kind . ':' . (is_array($value) ? 'array' : $value);

        return (string)$value === 'cars' ? $found : array();
    };
};
$resolver = new SearchUriResolver(
    'search',
    'http://example.com/',
    $lookup('region', array()),
    $lookup('city', array()),
    $lookup('category', array('pk_i_id' => '3', 's_slug' => 'cars')),
    $lookup('slug', array('pk_i_id' => '3', 's_slug' => 'cars')),
    static function ($slug) {
        $GLOBALS['lookups'][] = 'history:' . (is_array($slug) ? 'array' : $slug);

        return null;
    }
);

/** Resolve, turning a thrown error into a result the checks can compare. */
$resolve = static function (string $uri, array $params) use ($resolver): array {
    $GLOBALS['lookups'] = array();
    try {
        $out = $resolver->resolve($uri, $params, true);
    } catch (\Throwable $e) {
        return array('error' => get_class($e) . ': ' . $e->getMessage());
    }
    $out['lookups'] = $GLOBALS['lookups'];

    return $out;
};

$untouched = static function (string $uri, string $searchUri): array {
    return array(
        'uri'      => $uri,
        'params'   => array(),
        'exports'  => array('search_uri' => $searchUri),
        'redirect' => null,
        'notFound' => false,
        'lookups'  => array(),
    );
};

harness_section('an array sCategory is left for the search to read');

pin(
    '/search?sCategory[]=3&sCategory[]=4',
    $untouched('search', 'search'),
    $resolve('search?sCategory[]=3&sCategory[]=4', array('sCategory' => array('3', '4')))
);
pin(
    '/?page=search&sCategory[]=3',
    $untouched('', ''),
    $resolve('?page=search&sCategory[]=3', array('sCategory' => array('3')))
);
pin(
    'a nested sCategory[0][0]=x',
    $untouched('search', 'search'),
    $resolve('search?sCategory[0][0]=x', array('sCategory' => array(array('x'))))
);
pin(
    'an empty sCategory[]=',
    $untouched('search', 'search'),
    $resolve('search?sCategory[]=', array('sCategory' => array('')))
);

harness_section('a single sCategory still resolves');

pin('/cars', array(
    'uri'      => 'cars',
    'params'   => array(array('set', 'sCategory', 'cars')),
    'exports'  => array('search_uri' => 'cars'),
    'redirect' => null,
    'notFound' => false,
    'lookups'  => array('category:cars'),
), $resolve('cars', array('sCategory' => 'cars')));
pin('an unknown slug is still a 404', true, $resolve('nosuch', array('sCategory' => 'nosuch'))['notFound'] ?? null);

exit(harness_result());

/* file end: ./tests/search-category-array.php */
