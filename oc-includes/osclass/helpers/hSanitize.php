<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Sanitize
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Sanitize a website URL.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_url($value)
{
    return (new \mindstellar\utility\Sanitize())->url($value);
}

/**
 * Turn a string into a URL-safe slug (not an HTML-escaped string).
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_string($value)
{
    return (new \mindstellar\utility\Sanitize())->slug($value);
}

/**
 * Sanitize capitalization for a name: trimmed, all-caps lowered, each word capitalised.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_name($value)
{
    return (new \mindstellar\utility\Sanitize())->name($value);
}

/**
 * Sanitize string that's all-caps
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_allcaps($value)
{
    return (new \mindstellar\utility\Sanitize())->allcaps($value);
}

/**
 * Sanitize a username
 *
 * @param string $value
 *
 * @return string sanitized
 */
function osc_sanitize_username($value)
{
    return (new \mindstellar\utility\Sanitize())->username($value);
}

/**
 * Sanitize a whole number
 *
 * @param string $value value to sanitize
 *
 * @return int sanitized
 */
function osc_sanitize_int($value)
{
    return (new \mindstellar\utility\Sanitize())->int($value);
}

/**
 * Sanitize a phone number: digits, a leading '+' and common separators are kept,
 * with no country-specific formatting.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_phone($value)
{
    return (new \mindstellar\utility\Sanitize())->phone($value);
}

/**
 * Reduce a value to plain text, taking every tag out along with what it contained.
 * Like Params::getParam() it escapes what it keeps, so the result is stored pre-escaped.
 *
 * @param array|string $value value to sanitize
 *
 * @return array|string same shape as $value
 */
function osc_sanitize_text($value)
{
    return (new \mindstellar\utility\Sanitize())->text($value);
}

/**
 * Sanitise rich text to the markup a Shopclass editor can produce. Scripts, iframes,
 * event handlers and any URL scheme but http, https and mailto are removed.
 * Arrays are walked, so a per-locale description map can be passed straight in.
 *
 * @param array|string $value
 *
 * @return array|string same shape as $value
 */
function osc_sanitize_html($value)
{
    return (new \mindstellar\utility\Sanitize())->richHtml($value);
}

/**
 * Escape html
 *
 * Formats text so that it can be safely placed in a form field in the event it has HTML tags.
 * Existing entities are left intact.
 *
 * @param string $str
 *
 * @return string
 * @version 2.4
 */
function osc_esc_html($str = '')
{
    if ($str === '') {
        return '';
    }

    $temp = '__TEMP_AMPERSANDS__';

    // Replace entities to temporary markers so that
    // htmlspecialchars won't mess them up
    $str = preg_replace("/&#(\d+);/", "$temp\\1;", $str);
    $str = preg_replace("/&(\w+);/", "$temp\\1;", $str);

    $str = htmlspecialchars($str);

    // In case htmlspecialchars misses these.
    $str = str_replace(array("'", '"'), array('&#39;', '&quot;'), $str);

    // Decode the temp markers back to entities
    $str = preg_replace("/$temp(\d+);/", "&#\\1;", $str);
    $str = preg_replace("/$temp(\w+);/", "&\\1;", $str);

    return $str;
}

/**
 * Escape single quotes, double quotes, <, >, & and line endings
 *
 * @param string $str
 *
 * @return string
 * @version 2.4
 */
function osc_esc_js($str)
{
    static $sNewLines = '<br><br/><br />';
    static $aNewLines = array('<br>', '<br/>', '<br />');
    $str = strip_tags($str, $sNewLines);
    $str = str_replace("\r", '', $str);
    $str = addslashes($str);
    $str = str_replace("\n", '\n', $str);
    $str = str_replace($aNewLines, '\n', $str);

    return $str;
}
