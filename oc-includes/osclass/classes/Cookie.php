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
 * Class Cookie
 */
class Cookie
{
    private static $instance;
    public $name;
    public $val;
    public $expires;

    /**
     * Read the request's identity cookie, if any, into the value bag.
     */
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
     * The shared Cookie instance, created on first call.
     *
     * @return \Cookie
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Set one value in the bag, and mirror it into $_COOKIE for this request.
     *
     * @param string $var
     * @param string $value
     *
     * @return void
     */
    public function push($var, $value)
    {
        $this->val[(string)$var] = $value;
        $_COOKIE[(string)$var]   = $value;
    }

    /**
     * Drop one value from the bag and from $_COOKIE.
     *
     * @param string $var
     *
     * @return void
     */
    public function pop($var)
    {
        unset($this->val[$var], $_COOKIE[$var]);
    }

    /**
     * Empty the value bag; nothing is written until set() is called.
     *
     * @return void
     */
    public function clear()
    {
        $this->val = array();
    }

    /**
     * Write the bag out as the identity cookie, plus the cache-bypass flag.
     *
     * @return void
     */
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

        // No values left (e.g. logout pops every key): expire the cookie instead of leaving
        // an empty one, so a reverse-proxy cache can serve the visitor the anonymous page.
        $expires = $cookie_val === '' ? time() - 3600 : (int) $this->expires;
        self::write($this->name, $cookie_val, $expires);

        // Companion cache-bypass flag with a fixed, domain-independent NAME. The identity
        // cookie above is named md5(WEB_PATH); a reverse proxy / CDN config cannot hardcode
        // that per-site hash, so a cache in front of the app cannot tell a logged-in visitor
        // from an anonymous one by cookie name and would serve them the cached anonymous copy.
        // This flag rides the identity cookie's exact lifecycle — "1" whenever any identity or
        // locale value is present, expired in lockstep on logout — so the proxy contract
        // (osc_cache_relevant_cookies) can match one stable name. It carries no secret; its
        // presence alone means "do not serve this request a cached public page".
        self::write('oc_cache_bypass', $cookie_val === '' ? '' : '1', $expires);
    }

    /**
     * The attributes every site cookie is written with: HttpOnly, SameSite=Lax, Secure on HTTPS,
     * on the site's path and cookie domain.
     *
     * @param int $expires unix time; 0 for a browser-session cookie, a past time to delete
     *
     * @return array<string,mixed> setcookie() options array
     */
    public static function options(int $expires): array
    {
        $options = array(
            'expires'  => $expires,
            'path'     => defined('REL_WEB_URL') ? REL_WEB_URL : '/',
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
     * Write one cookie with options(); a past $expires deletes it.
     *
     * @return bool false when headers were already sent
     */
    public static function write(string $name, string $value, int $expires): bool
    {
        return !headers_sent() && setcookie($name, $value, self::options($expires));
    }

    /**
     * How many values the bag currently holds.
     *
     * @return int
     */
    public function num_vals()
    {
        return count($this->val);
    }

    /**
     * One value from the bag, or '' when it is not set.
     *
     * @param string $str
     *
     * @return string
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
     * Set the cookie lifetime in seconds from now; 0 makes it a browser-session cookie.
     *
     * @param int $tm
     *
     * @return void
     */
    public function set_expires($tm)
    {
        // $tm === 0 marks a browser-session cookie (dropped when the browser closes);
        // any positive value is a lifetime in seconds from now. setcookie() treats an
        // expires of 0 as a session cookie, so the sentinel flows straight through set().
        $this->expires = ($tm === 0) ? 0 : time() + $tm;
    }
}
