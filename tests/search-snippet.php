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
 * Pins SearchSnippet (core snippets and cleaning an engine's) and SearchExtras::fromAnswer().
 *   php tests/search-snippet.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/oc-includes/osclass/classes/search/SearchSnippet.php';
require_once dirname(__DIR__) . '/oc-includes/osclass/classes/search/SearchExtras.php';

use mindstellar\search\SearchExtras;
use mindstellar\search\SearchSnippet;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('SearchSnippet::make');

pin('the searched word is marked', 'A red <mark>bike</mark> for sale', SearchSnippet::make('<p>A red bike for sale</p>', 'bike'));
pin('case does not matter, the text keeps its case', 'Old <mark>Bike</mark>', SearchSnippet::make('Old Bike', 'bike'));
pin('every word of the pattern is marked', '<mark>red</mark> <mark>bike</mark>', SearchSnippet::make('red bike', 'bike red'));
pin('only whole words are marked', 'bikes and motorbike', SearchSnippet::make('bikes and motorbike', 'bike'));
pin('the text is escaped before marking', '&lt;script&gt; <mark>bike</mark>', SearchSnippet::make('&lt;script&gt; bike', 'bike'));
pin('an entity is never split by a mark', 'Tom &amp; <mark>Jerry</mark>', SearchSnippet::make('Tom &amp; Jerry', 'amp Jerry'));
pin('a pattern with markup cannot inject it', 'x', SearchSnippet::make('x', '<b>x'));
pin('tags become spaces, so words stay apart', 'one two', SearchSnippet::make('one<br>two', ''));
pin('long text is cut with an ellipsis', 'abcde…', SearchSnippet::make('abcdefghij', '', 5));
pin('one-letter words are not marked', 'a b', SearchSnippet::make('a b', 'a'));
pin('no pattern gives plain escaped text', 'Fish &amp; chips', SearchSnippet::make('Fish & chips', ''));

harness_section('SearchSnippet::clean');

pin('<mark> survives', 'a <mark>b</mark> c', SearchSnippet::clean('a <mark>b</mark> c'));
pin('other tags are dropped', 'x alert(1) <mark>y</mark>', SearchSnippet::clean('x <script>alert(1)</script> <mark>y</mark>'));
pin('a mark with attributes is not kept', ' z', SearchSnippet::clean('<mark onclick="x()">z</mark>'));
pin('text already escaped is not escaped twice', 'Tom &amp; <mark>Jerry</mark>', SearchSnippet::clean('Tom &amp; <MARK>Jerry</MARK>'));
pin('raw ampersands and quotes are escaped', 'a &amp; &quot;b&quot;', SearchSnippet::clean('a & "b"'));
pin('an unclosed mark is closed', '<mark>open</mark>', SearchSnippet::clean('<mark>open'));
pin('a stray closing mark is dropped', 'stray', SearchSnippet::clean('stray</mark>'));
pin('an entity hiding a tag stays text', '&lt;img src=x onerror=alert(1)&gt;', SearchSnippet::clean('&lt;img src=x onerror=alert(1)&gt;'));

harness_section('SearchExtras::fromAnswer');

$e = SearchExtras::fromAnswer(array(
    'facets'     => array('category' => array(4 => 10, '7' => '3', 0 => 5, 9 => -1, 'x' => 2, 8 => 'many')),
    'suggestion' => ' <b>bike</b> ',
    'relaxed'    => true,
));
pin('only positive counts for real ids are kept', array(4 => 10, 7 => 3), $e->categoryCounts());
pin('the suggestion is trimmed plain text', 'bike', $e->suggestion());
pin('relaxed is read', true, $e->relaxed());

$n = SearchExtras::fromAnswer(array('facets' => 'nope', 'suggestion' => array('x'), 'relaxed' => 'yes'));
pin('bad shapes give the empty values', array(array(), '', false), array($n->categoryCounts(), $n->suggestion(), $n->relaxed()));
pin('none() is empty', array(array(), '', false), array(SearchExtras::none()->categoryCounts(), SearchExtras::none()->suggestion(), SearchExtras::none()->relaxed()));

exit(harness_result());
