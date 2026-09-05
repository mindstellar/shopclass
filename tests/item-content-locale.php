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
 * Pins which locale osc_item_content_locale() reports for an item.
 *
 * The edit form posts title[<locale>], and it must be the locale the text on
 * screen came from. osc_item_title() falls back -- the visitor's locale, then the
 * site default, then any locale with text -- so on a multilingual site the form
 * showed one language's text in a field named for another. Saving wrote that text
 * back under the visitor's locale and left the original alone, so every edit from
 * a different language silently added another untranslated copy of the listing.
 *
 * Nothing here reaches a database: the resolution is a walk over the item's own
 * locale map, which is exactly the part that was wrong.
 *
 * Usage:  php tests/item-content-locale.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['item']    = array();
$GLOBALS['browsing'] = 'en_US';
$GLOBALS['default']  = 'en_US';

function osc_item()
{
    return $GLOBALS['item'];
}

function osc_current_user_locale()
{
    return $GLOBALS['browsing'];
}

function osc_language()
{
    return $GLOBALS['default'];
}

/** Build an item whose locale map holds a title for each given code. */
function item_with(array $codes): array
{
    $locale = array();
    foreach ($codes as $code => $title) {
        $locale[$code] = array('s_title' => $title, 's_description' => '');
    }

    return array('pk_i_id' => 1, 'locale' => $locale);
}

/** Extract just the helper, so the whole item helper file need not boot. */
$src = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hItems.php');
preg_match('/function osc_item_content_locale\(\).*?\n}/s', $src, $m);
harness_section('the helper');
check('osc_item_content_locale() is defined in hItems.php', !empty($m));
eval($m[0]);

harness_section('the visitor\'s own locale wins when the item has it');
$GLOBALS['browsing'] = 'en_US';
$GLOBALS['item']     = item_with(array('en_US' => 'Car', 'fr_FR' => 'Voiture'));
pin('translated both ways, browsing en_US', 'en_US', osc_item_content_locale());
$GLOBALS['browsing'] = 'fr_FR';
pin('same item, browsing fr_FR', 'fr_FR', osc_item_content_locale());

harness_section('otherwise the site default, then whatever exists');
// The case that was writing duplicates: browsing a language the listing has no
// text in. The form must post under the language whose text it is showing.
$GLOBALS['browsing'] = 'de_DE';
$GLOBALS['default']  = 'en_US';
$GLOBALS['item']     = item_with(array('en_US' => 'Car', 'fr_FR' => 'Voiture'));
pin('untranslated for the visitor, falls to the site default', 'en_US', osc_item_content_locale());

$GLOBALS['item'] = item_with(array('fr_FR' => 'Voiture'));
pin('neither the visitor\'s nor the default: the one that exists', 'fr_FR', osc_item_content_locale());

harness_section('an empty title does not count as a translation');
// A row can exist with no title. Reporting it would post under a locale the
// visitor never saw text for.
$GLOBALS['browsing'] = 'de_DE';
$GLOBALS['item']     = item_with(array('de_DE' => '', 'fr_FR' => 'Voiture'));
pin('an empty row is skipped', 'fr_FR', osc_item_content_locale());

harness_section('new content posts in the visitor\'s locale');
$GLOBALS['browsing'] = 'fr_FR';
$GLOBALS['item']     = array('pk_i_id' => 0);
pin('no locale map at all', 'fr_FR', osc_item_content_locale());
$GLOBALS['item'] = item_with(array());
pin('an empty locale map', 'fr_FR', osc_item_content_locale());

exit(harness_result());
