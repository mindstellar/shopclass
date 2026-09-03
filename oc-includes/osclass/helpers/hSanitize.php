<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
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
    if (!function_exists('filter_var')) {
        return preg_replace('|([^a-zA-Z0-9\$\-\_\.\+!\*\'\(\),{}\|\^~\[\]`"#%;\/\?:@=<>\\\&]*)|', '', $value);
    }

    return filter_var($value, FILTER_SANITIZE_URL);
}

/**
 * Sanitize a string.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_string($value)
{
    return osc_sanitizeString($value);
}

/**
 * Sanitize capitalization for a string.
 * Capitalize first letter of each name.
 * If all-caps, remove all-caps.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_name($value)
{
    return ucwords(osc_sanitize_allcaps(trim($value)));
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
    if (preg_match('/^([A-Z][^A-Z]*)+$/', $value) && !preg_match('/[a-z]+/', $value)) {
        $value = ucfirst(strtolower($value));
    }

    return $value;
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
    return preg_replace('/(_+)/', '_', preg_replace('/([^0-9A-Za-z_]*)/', '', str_replace(' ', '_', trim($value))));
}

/**
 * Sanitize number (with no periods)
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_int($value)
{
    if (!preg_match('/^[0-9]*$/', $value)) {
        return (int)$value;
    }

    return $value;
}

/**
 * Format phone number. Supports 10-digit with extensions,
 * and defaults to international if cannot match US number.
 *
 * @param string $value value to sanitize
 *
 * @return string sanitized
 */
function osc_sanitize_phone($value)
{
    if (empty($value)) {
        return '';
    }

    // Remove strings that aren't letter and number.
    $value = preg_replace('/[^a-z0-9]/', '', strtolower($value));

    // Remove 1 from front of number.
    if (preg_match('/^([0-9]{11})/', $value) && $value[0] == 1) {
        $value = substr($value, 1);
    }

    // Check for phone ext.
    if (!preg_match('/^[0-9]$/', $value)) {
        $value =
            preg_replace('/^([0-9]{10})([a-z]+)([0-9]+)/', '$1ext$3', $value); // Replace 'x|ext|extension' with 'ext'.
        list($value, $ext) = explode('ext', $value); // Split number & ext.
    }

    // Add dashes: ___-___-____
    if (strlen($value) == 7) {
        $value = preg_replace('/([0-9]{3})([0-9]{4})/', '$1-$2', $value);
    } elseif (strlen($value) == 10) {
        $value = preg_replace('/([0-9]{3})([0-9]{3})([0-9]{4})/', '$1-$2-$3', $value);
    }

    return $ext ? $value . ' x' . $ext : $value;
}

/**
 * Escape html
 *
 * Formats text so that it can be safely placed in a form field in the event it has HTML tags.
 *
 * @access  public
 *
 * @param string
 *
 * @return  string
 * @version 2.4
 */
/**
 * Sanitise rich text to the markup a Shopclass editor can legitimately produce.
 *
 * The listing description is read with Params' XSS check switched off wherever a rich
 * editor is in use -- the check strips every tag, which would eat the formatting the
 * editor exists to produce -- and nothing replaced it, so a description was stored exactly
 * as submitted. A poster did not even need the editor's source view: the raw HTML went
 * through the ordinary form POST, and `<script>` in a description ran for every visitor
 * who opened the listing.
 *
 * So: an allow-list rather than all-or-nothing. Everything the toolbars can emit survives
 * -- inline formatting, lists, links, headings, quotes, tables, images, and the colour
 * spans the admin listing editor's forecolor button writes. Scripts, iframes, event
 * handlers, and any URL scheme that is not http/https/mailto do not.
 *
 * Arrays are walked, so a per-locale description map can be passed straight in.
 *
 * The definition cache is deliberately off: a cached one is written to disk, the existing
 * purifier avoids that for the same reason, and this runs when a listing is saved rather
 * than when one is read.
 *
 * @param array|string $value
 *
 * @return array|string same shape as $value
 */
function osc_sanitize_html($value)
{
    static $purifier = null;

    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = osc_sanitize_html($v);
        }

        return $value;
    }

    if (!is_string($value) || $value === '') {
        return $value;
    }

    if ($purifier === null) {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', osc_apply_filter('sanitize_html_allowed', implode(',', array(
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
            'a[href|title|rel]', 'h3', 'h4', 'blockquote', 'hr',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'span[style]', 'img[src|alt|width|height]',
        ))));
        // forecolor writes a colour span; nothing else needs inline CSS, and every
        // property left out is one that cannot be abused for overlay or exfiltration.
        $config->set('CSS.AllowedProperties', array(
            'color', 'background-color', 'text-align',
            'font-weight', 'font-style', 'text-decoration',
        ));
        $config->set('URI.AllowedSchemes', array('http' => true, 'https' => true, 'mailto' => true));
        $config->set('Cache.DefinitionImpl', null);
        $purifier = new HTMLPurifier($config);
    }

    return $purifier->purify($value);
}

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
 * @access  public
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
