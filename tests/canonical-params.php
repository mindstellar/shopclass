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
 * Pins CWebSearch::canonicalParams() — which request params identify the page a
 * listing index canonicalises to, and which only slice or narrow the same set.
 *
 * Both directions are silent and both are damaging, so both are asserted. Too
 * little dropped and every price or custom-field facet self-canonicalises as a
 * page of its own, which is unbounded. Too much dropped and two genuinely
 * different pages claim the same canonical, which de-indexes one of them — so
 * the identity params have their own assertions rather than being assumed.
 *   php tests/canonical-params.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// The method is static and touches nothing else on the controller, so it is
// lifted out rather than loading BaseModel and the whole web-controller stack.
$src  = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebSearch.php');
$from = strpos($src, 'public static function canonicalParams(array $params)');
$to   = strpos($src, 'private function categorySlugRedirect(');
if ($from === false || $to === false || $to <= $from) {
    fwrite(STDERR, "CWebSearch::canonicalParams() not found\n");
    exit(1);
}
eval('function canonical_params(array $params) ' . substr($src, strpos($src, '{', $from), $to - strpos($src, '{', $from)));

harness_section('what identifies the page must survive');

// Dropping any of these would point two different result sets at one canonical.
$identity = array(
    'sCategory' => '54',
    'sCity'     => '278393',
    'sRegion'   => '781490',
    'sCountry'  => 'IN',
    'sCityArea' => '99',
    'sPattern'  => 'laptop',
    'sUser'     => '1200',
    'sLocale'   => 'fr_FR',
);
foreach ($identity as $key => $value) {
    $out = canonical_params(array($key => $value, 'iPage' => '3'));
    pin("$key survives", array($key => $value), $out);
}

harness_section('what only slices or narrows the same set is dropped');

$noise = array(
    'iPage'      => '4',
    'iPagesize'  => '50',
    'sOrder'     => 'i_price',
    'iOrderType' => 'asc',
    'sShowAs'    => 'gallery',
    'sPriceMin'  => '100',
    'sPriceMax'  => '5000',
    'bPic'       => '1',
    'bPremium'   => '1',
    'page'       => 'search',
    'action'     => '',
    'sParams'    => 'x',
    'sFeed'      => 'rss',
);
foreach ($noise as $key => $value) {
    $out = canonical_params(array('sCategory' => '54', $key => $value));
    pin("$key dropped", array('sCategory' => '54'), $out);
}

// meta[] is the custom-field facet: an array, and the widest multiplier of them all.
pin(
    'custom-field facets dropped',
    array('sCategory' => '54'),
    canonical_params(array('sCategory' => '54', 'meta' => array('7' => 'diesel', '9' => 'automatic')))
);

harness_section('the shapes this was built for');

pin(
    'a price-filtered category page canonicalises to the category',
    array('sCategory' => 'cars'),
    canonical_params(array('sCategory' => 'cars', 'sPriceMin' => '100', 'page' => 'search'))
);
pin(
    'a category-city page keeps both, so it stays its own page',
    array('sCategory' => '54', 'sCity' => '278393'),
    canonical_params(array('sCategory' => '54', 'sCity' => '278393', 'iPage' => '2', 'sOrder' => 'dt_pub_date'))
);
pin(
    'a fully-faceted request falls back to the bare index',
    array(),
    canonical_params(array('page' => 'search', 'sPriceMin' => '10', 'bPic' => '1', 'sOrder' => 'i_price'))
);

harness_section('no surprises');

pin('an empty request stays empty', array(), canonical_params(array()));
pin(
    'an unknown param is left alone rather than guessed at',
    array('sCategory' => '54', 'sSomethingAPluginAdded' => 'x'),
    canonical_params(array('sCategory' => '54', 'sSomethingAPluginAdded' => 'x', 'iPage' => '2'))
);

exit(harness_result());

/* file end: ./tests/canonical-params.php */
