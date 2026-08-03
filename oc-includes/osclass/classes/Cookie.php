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
 * Class Cookie
 */
class Cookie
{
    private static $instance;
    public $name;
    public $val;
    public $expires;

    public function __construct()
    {
        $this->val     = array();
        $web_path      = WEB_PATH;
        $this->name    = md5($web_path);
        $this->expires = time() + 3600; // 1 hour by default
        if (isset($_COOKIE[$this->name])) {
            $tmp  = explode('&', $_COOKIE[$this->name]);
            $vars = $tmp[0];
            $vals = isset($tmp[1]) ? explode('._.', $tmp[1]) : array();
            $vars = explode('._.', $vars);

            foreach ($vars as $key => $var) {
                if ($var != '' && isset($vals[$key])) {
                    $this->val[(string)$var] = $vals[$key];
                    $_COOKIE[(string)$var]   = $vals[$key];
                } else {
                    $this->val[(string)$var] = '';
                    $_COOKIE[(string)$var]   = '';
                }
            }
        }
    }

    /**
     * @return \Cookie
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @param $var
     * @param $value
     */
    public function push($var, $value)
    {
        $this->val[(string)$var] = $value;
        $_COOKIE[(string)$var]   = $value;
    }

    /**
     * @param $var
     */
    public function pop($var)
    {
        unset($this->val[$var], $_COOKIE[$var]);
    }

    public function clear()
    {
        $this->val = array();
    }

    public function set()
    {
        $cookie_val = '';
        if (is_array($this->val) && count($this->val) > 0) {
            $vals = array();
            $vars = array();

            foreach ($this->val as $key => $curr) {
                if ($curr !== '') {
                    $vars[] = $key;
                    $vals[] = $curr;
                }
            }
            if (count($vars) > 0 && count($vals) > 0) {
                $cookie_val = implode('._.', $vars) . '&' . implode('._.', $vals);
            }
        }

        $options = $this->cookieOptions();
        if ($cookie_val === '') {
            // No values left (e.g. logout pops every key): actively expire the cookie instead
            // of leaving an empty long-lived one behind. A logged-out visitor then carries no
            // auth cookie at all, so a reverse-proxy cache can serve them the anonymous page.
            $options['expires'] = time() - 3600;
        }
        setcookie($this->name, $cookie_val, $options);
    }

    /**
     * Attributes shared by every write. This container carries a long-lived "remember me"
     * secret, so it is HttpOnly (out of reach of JS, hence XSS), Secure on HTTPS, and
     * SameSite=Lax — still sent on top-level navigation, so following a link into the site
     * keeps the visitor remembered while blocking the cookie on cross-site subrequests.
     *
     * @return array
     */
    private function cookieOptions()
    {
        $options = array(
            'expires'  => $this->expires,
            'path'     => REL_WEB_URL,
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (function_exists('osc_is_ssl') && osc_is_ssl()) {
            $options['secure'] = true;
        }
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
            $options['domain'] = COOKIE_DOMAIN;
        }

        return $options;
    }

    /**
     * @return int
     */
    public function num_vals()
    {
        return count($this->val);
    }

    /**
     * @param $str
     *
     * @return mixed|string
     */
    public function get_value($str)
    {
        if (isset($this->val[$str])) {
            return $this->val[$str];
        }

        return '';
    }

    //$tm: time in seconds

    /**
     * @param $tm
     */
    public function set_expires($tm)
    {
        // $tm === 0 marks a browser-session cookie (dropped when the browser closes);
        // any positive value is a lifetime in seconds from now. setcookie() treats an
        // expires of 0 as a session cookie, so the sentinel flows straight through set().
        $this->expires = ($tm === 0) ? 0 : time() + $tm;
    }
}
