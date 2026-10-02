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
 * Pins mindstellar\search\SearchCriteria::fromRequest() — the request normalisation
 * CWebSearch::doModel() used to do inline (sCategory/sCityArea/sCity/sRegion/sCountry
 * comma-split, sUser/sLocale split-or-stay-empty, sPattern through strip_tags()+trim()+
 * the `search_pattern` filter, bPic/bPremium loose-== 1).
 *
 * Runs the real class (via the composer autoloader), not a copy. Only osc_apply_filter()
 * is stubbed; it is the one helper the class calls, and the stub here can switch into a
 * non-identity mode to prove the `search_pattern` filter actually runs rather than
 * being silently skipped.
 *   php tests/search-criteria.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\SearchCriteria;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// Identity by default -- same as an install with nothing hooked on 'search_pattern'.
// A test flips this to 'wrap' to prove fromRequest() actually calls the filter and
// stores its return value, not the pre-filter trimmed string.
$GLOBALS['__filterMode'] = 'identity';

function osc_apply_filter($hook, $content = '', ...$args)
{
    if ($hook === 'search_pattern' && $GLOBALS['__filterMode'] === 'wrap') {
        return '<<' . $content . '>>';
    }

    return $content;
}

harness_section('sCategory -- array kept, scalar comma-split, empty is []');

pin('absent -> []', array(), SearchCriteria::fromRequest(array())->categories());
pin("'' -> []", array(), SearchCriteria::fromRequest(array('sCategory' => ''))->categories());
pin("'54' -> ['54']", array('54'), SearchCriteria::fromRequest(array('sCategory' => '54'))->categories());
pin(
    "'54,12,7' -> ['54','12','7']",
    array('54', '12', '7'),
    SearchCriteria::fromRequest(array('sCategory' => '54,12,7'))->categories()
);
pin(
    'array kept as-is, not re-split',
    array('54', '12'),
    SearchCriteria::fromRequest(array('sCategory' => array('54', '12')))->categories()
);
pin(
    'an array element carrying a comma is not re-split',
    array('a,b', 'c'),
    SearchCriteria::fromRequest(array('sCategory' => array('a,b', 'c')))->categories()
);

harness_section('sCityArea / sCity / sRegion / sCountry -- same normalisation');

pin('sCityArea splits', array('99', '1'), SearchCriteria::fromRequest(array('sCityArea' => '99,1'))->cityAreas());
pin('sCityArea empty -> []', array(), SearchCriteria::fromRequest(array('sCityArea' => ''))->cityAreas());
pin('sCity splits', array('278393'), SearchCriteria::fromRequest(array('sCity' => '278393'))->cities());
pin('sCity array kept', array('1', '2'), SearchCriteria::fromRequest(array('sCity' => array('1', '2')))->cities());
pin('sRegion splits', array('781490', '2'), SearchCriteria::fromRequest(array('sRegion' => '781490,2'))->regions());
pin('sRegion empty -> []', array(), SearchCriteria::fromRequest(array())->regions());
pin('sCountry splits', array('IN', 'US'), SearchCriteria::fromRequest(array('sCountry' => 'IN,US'))->countries());
pin('sCountry empty -> []', array(), SearchCriteria::fromRequest(array('sCountry' => ''))->countries());

harness_section('sUser -- empty stays the empty string, not []');

pin("absent -> ''", '', SearchCriteria::fromRequest(array())->users());
pin("'' -> ''", '', SearchCriteria::fromRequest(array('sUser' => ''))->users());
pin("'1200' -> ['1200']", array('1200'), SearchCriteria::fromRequest(array('sUser' => '1200'))->users());
pin(
    "'1200,55' -> ['1200','55']",
    array('1200', '55'),
    SearchCriteria::fromRequest(array('sUser' => '1200,55'))->users()
);
pin(
    'array kept as-is',
    array('1200', '55'),
    SearchCriteria::fromRequest(array('sUser' => array('1200', '55')))->users()
);

harness_section('sLocale -- same shape as sUser');

pin("absent -> ''", '', SearchCriteria::fromRequest(array())->locale());
pin("'en_US' -> ['en_US']", array('en_US'), SearchCriteria::fromRequest(array('sLocale' => 'en_US'))->locale());
pin(
    "'en_US,fr_FR' -> ['en_US','fr_FR']",
    array('en_US', 'fr_FR'),
    SearchCriteria::fromRequest(array('sLocale' => 'en_US,fr_FR'))->locale()
);
pin('array kept as-is', array('en_US'), SearchCriteria::fromRequest(array('sLocale' => array('en_US')))->locale());

harness_section('sPattern -- strip_tags, trim, then the search_pattern filter');

pin("absent -> ''", '', SearchCriteria::fromRequest(array())->pattern());
check('absent -> hasPattern() false', SearchCriteria::fromRequest(array())->hasPattern() === false);
pin('whitespace trimmed', 'hi', SearchCriteria::fromRequest(array('sPattern' => '  hi  '))->pattern());
pin(
    'tags stripped, inner text kept',
    'hello',
    SearchCriteria::fromRequest(array('sPattern' => '<b>hello</b>'))->pattern()
);
check(
    "'0' is still a pattern (hasPattern() does not coerce to bool)",
    SearchCriteria::fromRequest(array('sPattern' => '0'))->hasPattern() === true
);
pin('literal \'0\' preserved', '0', SearchCriteria::fromRequest(array('sPattern' => '0'))->pattern());

$GLOBALS['__filterMode'] = 'wrap';
pin(
    'the search_pattern filter runs and its return value is what is stored',
    '<<hi>>',
    SearchCriteria::fromRequest(array('sPattern' => '  hi  '))->pattern()
);
check(
    'a filter that turns empty into non-empty flips hasPattern() too',
    SearchCriteria::fromRequest(array())->hasPattern() === true
);
pin(
    'rawPattern() is the value before the filter, so a saved alert does not filter twice',
    'hi',
    SearchCriteria::fromRequest(array('sPattern' => '  <i>hi</i>  '))->rawPattern()
);
$GLOBALS['__filterMode'] = 'identity';

pin('an array-valued sPattern is dropped instead of crashing strip_tags()', '',
    SearchCriteria::fromRequest(array('sPattern' => array('x')))->pattern());
pin('a nested list entry is dropped, the scalars kept with their keys', array(0 => '5', 2 => '7'),
    SearchCriteria::fromRequest(array('sCategory' => array('5', array('x'), '7')))->categories());
pin('an sUser list with nothing usable counts as no user', '',
    SearchCriteria::fromRequest(array('sUser' => array('')))->users());
pin('array-valued prices are dropped', array('', ''), array(
    SearchCriteria::fromRequest(array('sPriceMin' => array('1')))->priceMin(),
    SearchCriteria::fromRequest(array('sPriceMax' => array('1')))->priceMax(),
));

harness_section('bPic / bPremium -- loose == 1, everything else is false');

check('absent -> withPicture() false', SearchCriteria::fromRequest(array())->withPicture() === false);
check("'1' -> withPicture() true", SearchCriteria::fromRequest(array('bPic' => '1'))->withPicture() === true);
check('1 (int) -> withPicture() true', SearchCriteria::fromRequest(array('bPic' => 1))->withPicture() === true);
check("'0' -> withPicture() false", SearchCriteria::fromRequest(array('bPic' => '0'))->withPicture() === false);
check("'2' -> withPicture() false", SearchCriteria::fromRequest(array('bPic' => '2'))->withPicture() === false);
check(
    'an array never equals 1 -> withPicture() false',
    SearchCriteria::fromRequest(array('bPic' => array('1')))->withPicture() === false
);

check('absent -> onlyPremium() false', SearchCriteria::fromRequest(array())->onlyPremium() === false);
check("'1' -> onlyPremium() true", SearchCriteria::fromRequest(array('bPremium' => '1'))->onlyPremium() === true);
check("'0' -> onlyPremium() false", SearchCriteria::fromRequest(array('bPremium' => '0'))->onlyPremium() === false);

harness_section('sPriceMin / sPriceMax -- passed through untouched');

pin("absent -> ''", '', SearchCriteria::fromRequest(array())->priceMin());
pin("absent -> ''", '', SearchCriteria::fromRequest(array())->priceMax());
pin('scalar kept exactly', '100', SearchCriteria::fromRequest(array('sPriceMin' => '100'))->priceMin());
pin('scalar kept exactly', '500.50', SearchCriteria::fromRequest(array('sPriceMax' => '500.50'))->priceMax());

harness_section('meta -- passed through untouched, no shape forced');

pin("absent -> ''", '', SearchCriteria::fromRequest(array())->meta());
pin(
    'array kept exactly, keys and all',
    array(7 => 'diesel', 9 => 'automatic'),
    SearchCriteria::fromRequest(array('meta' => array(7 => 'diesel', 9 => 'automatic')))->meta()
);
pin('a meta value that is not a list is dropped', '', SearchCriteria::fromRequest(array('meta' => 'oops'))->meta());

exit(harness_result());

/* file end: ./tests/search-criteria.php */
