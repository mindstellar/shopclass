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
 * Pins CWebSearch::findCategory() — the sCategory lookup that decides whether a
 * search route 404s.
 *
 * It resolved by slug only, so /search?sCategory=54 — an id, which
 * osc_search_url() emits and a category <select> submits — 404'd a category that
 * plainly existed, while /search?sCategory=cars worked. The asymmetry is the bug;
 * these assertions cover both spellings, and equally that an unknown value still
 * resolves to nothing so the caller keeps 404ing it.
 *
 * Category is stubbed: the lookups are the input here, not the thing under test.
 *   php tests/search-category-resolve.php
 */

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$GLOBALS['bySlug'] = array();
$GLOBALS['byId']   = array();
$GLOBALS['calls']  = array();

/** Stand-in for the Category model, recording which lookups were asked for. */
class Category
{
    public static function newInstance()
    {
        return new self();
    }

    public function findBySlug($slug)
    {
        $GLOBALS['calls'][] = "slug:$slug";

        return $GLOBALS['bySlug'][$slug] ?? array();
    }

    public function findByPrimaryKey($id)
    {
        $GLOBALS['calls'][] = "id:$id";

        // The real model returns false — not an empty array — for a miss.
        return $GLOBALS['byId'][$id] ?? false;
    }
}

// Lifted by name: CWebSearch extends BaseModel and pulls in the whole web
// controller stack, but this method touches nothing except the model above.
$src  = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebSearch.php');
$from = strpos($src, 'public static function findCategory($value)');
$to   = strpos($src, '/**', $from);
if ($from === false || $to === false || $to <= $from) {
    fwrite(STDERR, "CWebSearch::findCategory() not found\n");
    exit(1);
}
eval('function find_category($value) ' . substr($src, strpos($src, '{', $from), $to - strpos($src, '{', $from)));

$CARS = array('pk_i_id' => '54', 's_slug' => 'cars', 's_name' => 'Cars');
$GLOBALS['bySlug']['cars'] = $CARS;
$GLOBALS['byId']['54']     = $CARS;

harness_section('both spellings of the same category resolve');

pin('a slug resolves', $CARS, find_category('cars'));
pin('an id resolves too — this is the case that used to 404', $CARS, find_category('54'));

harness_section('an unknown category still resolves to nothing, so the caller 404s');

pin('an unknown slug', array(), find_category('no-such-category'));
pin('an unknown id', array(), find_category('99999'));
pin('an empty value', array(), find_category(''));

harness_section('a miss returns an empty array, never the model\'s false');

$out = find_category('99999');
check('empty(), not false — count() on false is fatal on PHP 8', $out === array(), describe($out));

harness_section('the id lookup is a fallback, not a first move');

// A numeric slug is legal, and must not be shadowed by a category whose id
// happens to be the same number.
$GLOBALS['bySlug']['2024'] = array('pk_i_id' => '7', 's_slug' => '2024');
$GLOBALS['byId']['2024']   = array('pk_i_id' => '2024', 's_slug' => 'something-else');
pin('a numeric slug wins over the same number as an id', '7', find_category('2024')['pk_i_id']);

$GLOBALS['calls'] = array();
find_category('cars');
pin('a slug hit costs one lookup', array('slug:cars'), $GLOBALS['calls']);

$GLOBALS['calls'] = array();
find_category('54');
pin('an id hit falls through in order', array('slug:54', 'id:54'), $GLOBALS['calls']);

$GLOBALS['calls'] = array();
find_category('no-such-category');
pin('a non-numeric miss never asks for an id', array('slug:no-such-category'), $GLOBALS['calls']);

exit(harness_result());

/* file end: ./tests/search-category-resolve.php */
