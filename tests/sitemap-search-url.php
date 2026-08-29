<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the URL-synthesis contract the category/location sitemaps depend on:
 * osc_search_url() builds from the params it is handed, while
 * osc_update_search_url() merges whatever is in the CURRENT request. Building
 * those documents with the "update" helper leaked the sitemap route's own
 * sitemap_doc param into every category-location URL, which pushed the param
 * count past osc_search_url()'s two-param pretty-URL branch and emitted a
 * second, self-canonicalising copy of each page.
 *
 * Rewrite is off so the query-string branch runs and no DAO is touched; the
 * leak and the param count are visible either way.  Usage:
 *   php tests/sitemap-search-url.php
 */

define('WEB_PATH', 'http://example.com/');
define('OSC_DEBUG', false);

// Controlled-input layer. None of these live in hSearch.php, so there is no
// redeclaration when the real helpers load below.
function osc_rewrite_enabled()
{
    return false;
}
function osc_get_preference($key, $section = 'osclass')
{
    return '';
}
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}
function osc_subdomain_type()
{
    return '';
}
function osc_is_subdomain()
{
    return false;
}
function osc_is_ssl()
{
    return false;
}
function osc_base_url($with_index = false)
{
    return WEB_PATH;
}
function osc_prune_array(&$input)
{
    \mindstellar\utility\Utils::pruneArray($input);
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/View.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/utility/Utils.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hSearch.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Put the request into the state Sitemap::serve() leaves it in. */
function on_sitemap_route(string $doc): void
{
    Params::setParam('page', 'sitemap');
    Params::setParam('sitemap_doc', $doc);
}

harness_section('osc_search_url() ignores the ambient request');

on_sitemap_route('cat_cities');
pin(
    'cat/city URL carries only the params it was handed',
    'http://example.com/index.php?page=search&sCategory=54&sCity=278393',
    osc_search_url(array('sCategory' => 54, 'sCity' => 278393))
);

on_sitemap_route('cat_regions');
pin(
    'cat/region URL carries only the params it was handed',
    'http://example.com/index.php?page=search&sCategory=54&sRegion=781490',
    osc_search_url(array('sCategory' => 54, 'sRegion' => 781490))
);

harness_section('the leak osc_update_search_url() would reintroduce');

on_sitemap_route('cat_cities');
$leaked = osc_update_search_url(array(
    'action_specific' => '',
    'CSRFName'        => '',
    'CSRFToken'       => '',
    'route'           => '',
    'sCategory'       => 54,
    'sCity'           => 278393,
));
check(
    'osc_update_search_url() drags sitemap_doc in — why the sitemaps must not use it',
    strpos($leaked, 'sitemap_doc') !== false,
    $leaked
);

harness_section('param count stays inside the pretty-URL branch');

// osc_search_url() only emits the /<cat>_<city>-c<id> permalink while the count
// is exactly sCategory + location (hSearch.php); one extra param drops it to the
// generic /search/k,v builder, which is the duplicate that reached the sitemap.
foreach (array('sCity' => 278393, 'sRegion' => 781490) as $key => $id) {
    $params = array('sCategory' => 54, $key => $id);
    osc_prune_array($params);
    pin("sCategory + $key prunes to 2 params", 2, count($params));
}

harness_section('the sitemap builders call the right helper');

// The assertions above pin the helpers; this one pins the call site, which is
// the thing that actually regressed and cannot be exercised without a database.
$src = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/Sitemap.php');
foreach (array('generateCatRegionSitemap', 'generateCatCitySitemap') as $method) {
    $from = strpos($src, 'function ' . $method . '(');
    $body = $from === false ? '' : substr($src, $from, 900);
    // Match the assignment, not the bare name: the call site carries a comment
    // naming the helper it must not use.
    check("$method() builds URLs with osc_search_url()", strpos($body, '$url = osc_search_url(') !== false);
    check("$method() does not use osc_update_search_url()", strpos($body, '$url = osc_update_search_url(') === false);
}

exit(harness_result());

/* file end: ./tests/sitemap-search-url.php */
