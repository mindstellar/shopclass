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
 * Characterization ("golden") test for osc_item_url_from_item() — the listing-URL
 * builder that osc_item_url() delegates to. It pins the CURRENT observable output
 * for the permalink structures and rewrite/locale combinations a theme relies on,
 * so a future reverse-routing change (deriving these URLs from a shared route
 * definition instead of the hand-written str_replace here) is provably
 * behaviour-preserving. These are not aspirational — they lock in today's exact
 * strings, quirks included (the '?' in a structure is stripped, not treated as a
 * query separator).
 *
 * The permalink structure and rewrite flag are inputs, so the preference / filter
 * / locale layer is stubbed to controlled values; the URL templating, base-URL
 * prefixing and osc_sanitizeString() slugging run for real. Structures containing
 * {CATEGORIES} are intentionally NOT covered here — that branch does a Category
 * DB lookup and belongs in the DB-backed lane.  Usage:
 *   php tests/item-url-characterization.php
 */

define('WEB_PATH', 'http://example.com/');
define('OSC_DEBUG', false);

$GLOBALS['__prefs'] = array();

// Stubs for the controlled-input layer (defined before the real helpers load, and
// absent from the required files, so there is no redeclaration).
function osc_rewrite_enabled()
{
    return (bool)($GLOBALS['__prefs']['rewriteEnabled'] ?? false);
}
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['__prefs'][$key] ?? '';
}
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}
function osc_current_user_locale()
{
    return 'en_US';
}

require_once __DIR__ . '/../oc-includes/osclass/formatting.php';   // osc_sanitizeString, remove_accents
require_once __DIR__ . '/../oc-includes/osclass/helpers/hDefines.php'; // osc_item_url_from_item, osc_base_url
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$ITEM = array(
    'pk_i_id'          => 42,
    's_title'          => 'Blue Bicycle, almost new',
    's_city'           => 'San José',
    'fk_i_category_id' => 3,
);

/** Build a listing URL under a given structure/rewrite/locale. */
function item_url(array $prefs, array $item, string $locale = ''): string
{
    $GLOBALS['__prefs'] = $prefs;

    return osc_item_url_from_item($item, $locale);
}

harness_section('osc_item_url_from_item — friendly URLs on');
pin('{ITEM_TITLE}_{ITEM_ID}',
    'http://example.com/blue-bicycle-almost-new_42',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_TITLE}_{ITEM_ID}'), $ITEM));
pin('{ITEM_CITY}/{ITEM_TITLE}_{ITEM_ID}',
    'http://example.com/san-jose/blue-bicycle-almost-new_42',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_CITY}/{ITEM_TITLE}_{ITEM_ID}'), $ITEM));
pin('id-only structure',
    'http://example.com/ad/42',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => 'ad/{ITEM_ID}'), $ITEM));
pin('locale prefixed when passed',
    'http://example.com/en_US/blue-bicycle-almost-new_42',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_TITLE}_{ITEM_ID}'), $ITEM, 'en_US'));

harness_section('osc_item_url_from_item — quirks pinned');
pin("'?' in the structure is stripped, not a query separator",
    'http://example.com/blue-bike_42ref=x',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_TITLE}_{ITEM_ID}?ref=x'),
        array('pk_i_id' => 42, 's_title' => 'Blue Bike', 's_city' => 'Berlin', 'fk_i_category_id' => 3)));
pin('comma in title becomes a dash (via sanitize)',
    'http://example.com/a-b_9',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_TITLE}_{ITEM_ID}'),
        array('pk_i_id' => 9, 's_title' => 'a,b', 's_city' => '', 'fk_i_category_id' => 1)));

harness_section('osc_item_url_from_item — friendly URLs off (non-rewrite fallback)');
pin('plain query URL',
    'http://example.com/index.php?page=item&id=42',
    item_url(array('rewriteEnabled' => '0'), $ITEM));
pin('plain query URL with locale',
    'http://example.com/index.php?page=item&id=42&lang=en_US',
    item_url(array('rewriteEnabled' => '0'), $ITEM, 'en_US'));

harness_section('osc_item_url_from_item — invalid item');
pin('item without pk_i_id -> empty string', '',
    item_url(array('rewriteEnabled' => '1', 'rewrite_item_url' => '{ITEM_ID}'), array('s_title' => 'x')));

exit(harness_result());
