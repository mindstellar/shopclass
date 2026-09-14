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
 * Pins what meta_description() emits for each page shape.
 *
 * The failure this guards is silent and only visible at scale: a listing index
 * whose description leads with the category blurb reads fine on one page and is
 * a duplicate across every city in that category, because the part that differs
 * sits past the truncation point. So the assertions here are mostly about
 * ORDER and about two pages differing, not about exact wording.
 *
 * The search/page/item context is what varies, so that layer is stubbed; the
 * assembly, budget arithmetic and osc_highlight() truncation run for real.
 *   php tests/meta-description.php
 */

define('OSC_META_DESCRIPTION_LENGTH', 155);

$GLOBALS['ctx'] = array();

/** Set the page context the builder reads. */
function ctx(array $over = array()): void
{
    $GLOBALS['ctx'] = array_merge(array(
        'home' => false, 'static' => false, 'ad' => false, 'search' => false,
        'pattern' => '', 'category_name' => '', 'category_description' => '',
        'city' => '', 'region' => '', 'country' => '', 'total' => 0,
        'page_description' => 'A classifieds site.',
        'static_text' => '', 'item_description' => '',
        'item_category' => '', 'item_city' => '',
    ), $over);
}

function osc_is_home_page()
{
    return $GLOBALS['ctx']['home'];
}
function osc_is_static_page()
{
    return $GLOBALS['ctx']['static'];
}
function osc_is_ad_page()
{
    return $GLOBALS['ctx']['ad'];
}
function osc_is_search_page()
{
    return $GLOBALS['ctx']['search'];
}
function osc_search_pattern()
{
    return $GLOBALS['ctx']['pattern'];
}
function osc_search_category_name($locale = '')
{
    return $GLOBALS['ctx']['category_name'];
}
function osc_search_category_description($locale = '')
{
    return $GLOBALS['ctx']['category_description'];
}
function osc_search_city()
{
    return $GLOBALS['ctx']['city'];
}
function osc_search_region()
{
    return $GLOBALS['ctx']['region'];
}
function osc_search_country()
{
    return $GLOBALS['ctx']['country'];
}
function osc_search_total_items()
{
    return $GLOBALS['ctx']['total'];
}
function osc_page_description()
{
    return $GLOBALS['ctx']['page_description'];
}
function osc_static_page_text()
{
    return $GLOBALS['ctx']['static_text'];
}
function osc_item_description()
{
    return $GLOBALS['ctx']['item_description'];
}
function osc_item_category()
{
    return $GLOBALS['ctx']['item_category'];
}
function osc_item_city()
{
    return $GLOBALS['ctx']['item_city'];
}
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}
function __($key, $domain = 'core')
{
    return $key;
}
function _n($single, $plural, $count, $domain = 'core')
{
    return $count == 1 ? $single : $plural;
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hUtils.php';   // osc_highlight
require_once __DIR__ . '/lib/harness.php';

// functions.php registers hooks at load, so it cannot be required standalone;
// the two functions under test are lifted out of it by name instead.
$src = file_get_contents(__DIR__ . '/../oc-includes/osclass/functions.php');
$from = strpos($src, 'function osc_search_meta_description()');
$to   = strpos($src, 'function meta_keywords()');
if ($from === false || $to === false || $to <= $from) {
    fwrite(STDERR, "meta_description()/osc_search_meta_description() not found in functions.php\n");
    exit(1);
}
eval(substr($src, $from, $to - $from));

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$BLURB = 'Business opportunities and franchise - franchise for sale, business partnership, '
    . 'distributorship, dealership and investment opportunities.';

harness_section('listing index: the location survives truncation');

ctx(array('search' => true, 'category_name' => 'Business Opportunities',
    'category_description' => $BLURB, 'city' => 'AmbalaCantt', 'total' => 7));
$ambala = meta_description();
ctx(array('search' => true, 'category_name' => 'Business Opportunities',
    'category_description' => $BLURB, 'city' => 'Ghaziabad', 'total' => 42));
$ghaziabad = meta_description();

check('city page opens with category and city', strpos($ambala, 'Business Opportunities in AmbalaCantt') === 0, $ambala);
check('two cities in one category differ', $ambala !== $ghaziabad, $ambala);
check('the difference is inside the visible window',
    substr($ambala, 0, 120) !== substr($ghaziabad, 0, 120), substr($ambala, 0, 120));
check('stays inside the budget', mb_strlen($ambala) <= OSC_META_DESCRIPTION_LENGTH, (string)mb_strlen($ambala));
check('a long blurb still gets in', strpos($ambala, 'Business opportunities and franchise') !== false, $ambala);

harness_section('count');

pin('singular', 'Cars in Pune - 1 listing.', (function () {
    ctx(array('search' => true, 'category_name' => 'Cars', 'city' => 'Pune', 'total' => 1));
    return meta_description();
})());
pin('plural', 'Cars in Pune - 24 listings.', (function () {
    ctx(array('search' => true, 'category_name' => 'Cars', 'city' => 'Pune', 'total' => 24));
    return meta_description();
})());
pin('an empty result set claims no count', 'Cars in Pune.', (function () {
    ctx(array('search' => true, 'category_name' => 'Cars', 'city' => 'Pune', 'total' => 0));
    return meta_description();
})());

harness_section('the other listing-index shapes');

pin('city only', 'Classified ads in Patna - 312 listings.', (function () {
    ctx(array('search' => true, 'city' => 'Patna', 'total' => 312));
    return meta_description();
})());
pin('region only', 'Classified ads in Assam - 90 listings.', (function () {
    ctx(array('search' => true, 'region' => 'Assam', 'total' => 90));
    return meta_description();
})());
pin('city wins over region and country', 'Classified ads in Patna - 5 listings.', (function () {
    ctx(array('search' => true, 'city' => 'Patna', 'region' => 'Bihar', 'country' => 'India', 'total' => 5));
    return meta_description();
})());
pin('a typed query describes the page better than its category', 'laptop in Pune - 18 listings.', (function () {
    ctx(array('search' => true, 'pattern' => 'laptop', 'category_name' => 'Electronics', 'city' => 'Pune', 'total' => 18));
    return meta_description();
})());
pin('an unfiltered index falls back to the site description', 'A classifieds site.', (function () {
    ctx(array('search' => true, 'total' => 900));
    return meta_description();
})());

harness_section('a fragment of a blurb is worse than none');

// A typed query is unbounded, so it is what actually drives the headline past the
// point where a blurb still fits.
$LONG = 'used maruti suzuki swift vdi diesel manual second owner under five lakh with '
    . 'service history and insurance valid';
ctx(array('search' => true, 'pattern' => $LONG, 'category_description' => $BLURB,
    'city' => 'Thiruvananthapuram', 'total' => 12));
$tight = meta_description();
check('no room left, so no blurb', strpos($tight, 'Business opportunities') === false, $tight);
check('the headline is intact', strpos($tight, 'Thiruvananthapuram') !== false, $tight);

harness_section('listing page');

pin('leads with the listing\'s own words', 'We provide reliable type approval services in Peru.', (function () {
    ctx(array('ad' => true, 'item_description' => '<p>We provide reliable type approval services in Peru.</p>',
        'item_category' => 'Other Services', 'item_city' => 'Lima'));
    return meta_description();
})());
pin('falls back to category and city when there are no words', 'Other Services Lima', (function () {
    ctx(array('ad' => true, 'item_description' => '', 'item_category' => 'Other Services', 'item_city' => 'Lima'));
    return meta_description();
})());

harness_section('static page');

pin(
    'a tag is a word boundary, not nothing',
    'What is Shopclass? Shopclass is a classifieds platform.',
    (function () {
        ctx(array('static' => true,
            'static_text' => '<h2>What is Shopclass?</h2><p>Shopclass is a classifieds platform.</p>'));
        return meta_description();
    })()
);

harness_section('home page');

pin('unchanged', 'A classifieds site.', (function () {
    ctx(array('home' => true));
    return meta_description();
})());

exit(harness_result());

/* file end: ./tests/meta-description.php */
