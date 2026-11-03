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

namespace mindstellar\utility;

/**
 * Class Escape
 *
 * @package mindstellar\utility
 */
class Escape
{
    /**
     * Escape single quotes, double quotes, <, >, & and line endings
     *
     * @param string $str
     *
     * @return string
     * @version 2.4
     */
    public static function js($str)
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

    /**
     * Escape given characters
     *
     * @param string $str
     * @param string $chars
     *
     * @return string
     * @since 5.1
     */
    public static function escapeChars($str, $chars = '"')
    {
        return preg_replace('/[' . $chars . ']/', '\\\$0', $str);
    }

    /**
     * Escape unicode characters
     *
     * @param string $str
     *
     * @return string
     */
    public static function unicode($str)
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * Escapes text for HTML or XML. The same as PHP's htmlspecialchars(), with the same arguments
     * and defaults, so core escapes in one place.
     *
     * @param string      $str
     * @param int         $flags
     * @param string|null $encoding
     * @param bool        $doubleEncode
     *
     * @return string
     */
    public static function text($str, $flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding = null, $doubleEncode = true)
    {
        return htmlspecialchars($str, $flags, $encoding, $doubleEncode);
    }

    /**
     * Converts every character that has an HTML entity. The same as PHP's htmlentities(), with
     * the same arguments and defaults.
     *
     * @param string      $str
     * @param int         $flags
     * @param string|null $encoding
     * @param bool        $doubleEncode
     *
     * @return string
     */
    public static function htmlEntities($str, $flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding = null, $doubleEncode = true)
    {
        return htmlentities($str, $flags, $encoding, $doubleEncode);
    }

    /**
     * Escapes text for HTML and keeps entities that are already there, such as &amp; or &#39;.
     * Quotes always become &#39; and &quot;. osc_esc_html() uses it.
     *
     * @param string $str
     *
     * @return string
     */
    public static function htmlKeepEntities($str = '')
    {
        if ($str === '') {
            return '';
        }
        // With no "&" there is no entity to keep, so the four passes below would change nothing.
        if (is_string($str) && strpos($str, '&') === false) {
            return str_replace(array("'", '"'), array('&#39;', '&quot;'), self::text($str));
        }

        $temp = '__TEMP_AMPERSANDS__';

        // Replace entities to temporary markers so that
        // htmlspecialchars won't mess them up
        $str = preg_replace("/&#(\d+);/", "$temp\\1;", $str);
        $str = preg_replace("/&(\w+);/", "$temp\\1;", $str);

        $str = self::text($str);

        // In case htmlspecialchars misses these.
        $str = str_replace(array("'", '"'), array('&#39;', '&quot;'), $str);

        // Decode the temp markers back to entities
        $str = preg_replace("/$temp(\d+);/", "&#\\1;", $str);
        $str = preg_replace("/$temp(\w+);/", "&\\1;", $str);

        return $str;
    }

    /**
     * Escape html keep encoded entities
     *
     * @param string $str
     *
     * @return string
     * @since 5.1
     */
    public static function html($str)
    {
        $str = self::entities($str);

        return htmlspecialchars($str, ENT_QUOTES);
    }

    /**
     * Escape html entities
     *
     * @param string $str
     *
     * @return string
     */
    public static function entities($str)
    {
        return preg_replace_callback('/&#([0-9a-zA-Z]*);/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * Escape html string for use in javascript
     *
     * @param string $str
     *
     * @return string
     * @since 5.1
     * @deprecated since 7.1.0, removed in 8.0; use osc_esc_js() instead
     */
    public static function jsHtml($str)
    {
        $str = self::entities($str);

        return preg_replace('/</', '\\x3C', $str);
    }

}
