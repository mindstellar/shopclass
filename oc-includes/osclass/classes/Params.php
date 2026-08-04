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
 * Class Params
 */
class Params
{
    private static $HTMLPurifier;
    private static $request;
    private static $server;

    public static function init()
    {
        self::$request = array_merge($_GET, $_POST);
        self::$server  = $_SERVER;
    }

    /**
     * Return HTMLPurified param
     *
     * @param      $param
     * @param bool $html_encode
     * @param bool $xss_check
     * @param bool $quotes_encode
     *
     * @return mixed
     */
    public static function getParam($param, $html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if ($param === '') {
            return '';
        }
        if (!isset(self::$request[$param])) {
            return '';
        }

        return self::purify(self::$request[$param], $html_encode, $xss_check, $quotes_encode);
    }

    /**
     * Type-safe integer accessor. Request values are attacker-controlled in shape as well as
     * content: `?id[]=1` makes the raw value an array, and `(int)getParam('id')` then juggles
     * that array to 1 (with a warning) instead of failing. This never returns an array — an
     * array (or a missing param) yields $default, a scalar is cast to int. A drop-in, array-safe
     * replacement for `(int) Params::getParam($p)` at ID/page/count sites.
     *
     * @param string $param
     * @param int    $default value for a missing or array-valued param
     *
     * @return int
     */
    public static function getParamInt($param, $default = 0)
    {
        if ($param === '' || !isset(self::$request[$param])) {
            return $default;
        }
        $value = self::$request[$param];
        if (is_array($value)) {
            return $default;
        }

        return (int)$value;
    }

    /**
     * Type-safe string accessor. Same purification as getParam(), but an array-valued param
     * (e.g. `?s_name[]=x`) yields '' instead of an array leaking into string/SQL context. Use at
     * sites that require a scalar string.
     *
     * @param string $param
     * @param bool   $html_encode
     * @param bool   $xss_check
     * @param bool   $quotes_encode
     *
     * @return string
     */
    public static function getParamString($param, $html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if ($param === '' || !isset(self::$request[$param])) {
            return '';
        }
        $value = self::$request[$param];
        if (is_array($value)) {
            return '';
        }

        return self::purify($value, $html_encode, $xss_check, $quotes_encode);
    }

    /**
     * Type-safe array accessor for genuinely multi-valued params (meta[], s_info[], sCategory[]…).
     * A scalar-valued param (or a missing one) yields an empty array, so a caller that iterates
     * the result can never trip over a scalar an attacker sent where an array was expected.
     * Purification matches getParam()'s defaults, applied recursively.
     *
     * @param string $param
     * @param bool   $html_encode
     * @param bool   $xss_check
     * @param bool   $quotes_encode
     *
     * @return array
     */
    public static function getParamArray($param, $html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if ($param === '' || !isset(self::$request[$param]) || !is_array(self::$request[$param])) {
            return array();
        }

        return self::purify(self::$request[$param], $html_encode, $xss_check, $quotes_encode);
    }

    /**
     * Function to purify given string or array
     * Should be moved to separate class
     *
     * @param      $value
     *
     * @param bool $html_encode
     * @param bool $xss_check
     * @param bool $quotes_encode
     *
     * @return string
     */
    private static function purify($value, $html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if ($html_encode === false && $xss_check === false && $quotes_encode === false) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                $v = self::purify($v, $html_encode, $xss_check, $quotes_encode); // recursive
            }
        } else {
            if ($xss_check === true) {
                if (self::$HTMLPurifier === null) {
                    $purifier_config = HTMLPurifier_Config::createDefault();
                    $purifier_config->set('HTML.Allowed', '');
                    // Stripping all tags leaves no definition to persist, so use the in-memory
                    // NullCache instead of writing serializer blobs into the public uploads dir.
                    $purifier_config->set('Cache.DefinitionImpl', null);
                    self::$HTMLPurifier = new HTMLPurifier($purifier_config);
                }

                $value = self::$HTMLPurifier->purify($value);
            }

            if ($html_encode === true) {
                if ($quotes_encode === true) {
                    return htmlspecialchars($value, ENT_QUOTES);
                }

                return htmlspecialchars($value, ENT_NOQUOTES);
            }
        }

        return $value;
    }

    /**
     * @param $param
     *
     * @return bool
     */
    public static function existParam($param)
    {
        if ($param === '') {
            return false;
        }
        if (!isset(self::$request[$param])) {
            return false;
        }

        return true;
    }

    /**
     * Return REQUEST_URI from $_SERVER params
     *
     * @param bool $html_encode
     * @param bool $xss_check
     * @param bool $quotes_encode
     *
     * @return string|string[]|null
     */
    public static function getRequestURI($html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if (self::existServerParam('REQUEST_URI')) {
            $raw_request_uri = self::getServerParam('REQUEST_URI', $html_encode, $xss_check, $quotes_encode);

            //make this to osclass installation specific
            return preg_replace('|^' . REL_WEB_URL . '|', '', $raw_request_uri);
        }

        return '';
    }

    /**
     * @param $param
     *
     * @return bool
     */
    public static function existServerParam($param)
    {
        if ($param === '') {
            return false;
        }
        if (!isset(self::$server[$param])) {
            return false;
        }

        return true;
    }

    /**
     * @param      $param
     * @param bool $html_encode
     * @param bool $xss_check
     * @param bool $quotes_encode
     *
     * @return string
     */
    public static function getServerParam($param, $html_encode = false, $xss_check = true, $quotes_encode = true)
    {
        if ($param === '') {
            return '';
        }
        if (!isset(self::$server[$param])) {
            return '';
        }

        $value = self::$server[$param];

        $value = self::purify($value, $html_encode, $xss_check, $quotes_encode);

        return $value;
    }

    /**
     * @param bool $xss_check
     *
     * @return string
     */
    public static function getServerParamsAsArray($xss_check = true)
    {
        $value = self::$server;

        $value = self::purify($value, false, $xss_check, false);

        return $value;
    }

    /**
     * @param $param
     *
     * @return array
     */
    public static function getFiles($param)
    {
        if (isset($_FILES[$param])) {
            return $_FILES[$param];
        }

        return array();
    }

    /**
     * @param $key
     * @param $value
     */
    public static function setParam($key, $value)
    {
        self::$request[$key] = $value;
    }

    /**
     * @param $key
     */
    public static function unsetParam($key)
    {
        unset(self::$request[$key]);
    }

    /**
     * Will be removed do not use this
     *
     * @deprecated 4.0
     * return void
     */
    public static function _view()
    {
        print_r(self::getParamsAsArray());
    }

    /**
     * @param string $what
     * @param bool   $xss_check
     *
     * @return array|string
     */
    public static function getParamsAsArray($what = '', $xss_check = true)
    {
        switch ($what) {
            case ('get'):
                $value = $_GET;
                break;
            case ('post'):
                $value = $_POST;
                break;
            case ('cookie'):
                return $_COOKIE;
                break;
            case ('files'):
                return $_FILES;
                break;
            case ('request'): // This should not be called, as it depends on server's configuration
                return $_REQUEST;
                break;
            default:
                $value = self::$request;
                break;
        }

        $value = self::purify($value, false, $xss_check, false); // $xss_check, $quotes_encode );

        return $value;
    }
}
