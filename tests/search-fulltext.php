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
 * Pins PatternFilter::fullTextUsable() against injected server settings: the minimum
 * token size and stopwords decide the FULLTEXT path, and OSC_FT_MIN_WORD_LEN overrides
 * the size. The real server read is pinned in tests/models/search.php.
 *   php tests/search-fulltext.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\search\query\PatternFilter;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$server = new ReflectionProperty(PatternFilter::class, 'server');
if (PHP_VERSION_ID < 80100) {
    $server->setAccessible(true);
}
$server->setValue(null, array('min' => 4, 'stop' => array('the' => true, 'with' => true)));

$usable = static function (string $pattern): bool {
    $f = new PatternFilter();
    $f->set($pattern);

    return $f->fullTextUsable();
};

harness_section('PatternFilter: server minimum token size');
pin('a word below the server minimum falls back', false, $usable('sed'));
pin('a word at the server minimum uses FULLTEXT', true, $usable('sedan'));

harness_section('PatternFilter: stopwords');
pin('a stopword-only search falls back', false, $usable('the'));
pin('stopwords match in any case', false, $usable('The WITH'));
pin('a stopword next to a real word uses FULLTEXT', true, $usable('the sedan'));
pin('an excluded real word does not count', false, $usable('the -sedan'));
pin('a quoted phrase still uses FULLTEXT', true, $usable('"the"'));
pin('an empty pattern still uses FULLTEXT', true, $usable(''));

harness_section('PatternFilter: OSC_FT_MIN_WORD_LEN overrides the server');
define('OSC_FT_MIN_WORD_LEN', 2);
pin('the constant lowers the minimum', true, $usable('se'));
pin('a stopword still falls back', false, $usable('the'));

exit(harness_result());

/* file end: ./tests/search-fulltext.php */
