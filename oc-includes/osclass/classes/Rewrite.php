<?php

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Class Rewrite
 */
class Rewrite
{
    private static $instance;
    private $rules;
    private $routes;
    private $request_uri;
    private $raw_request_uri;
    private $location;
    private $section;
    private $title;
    private $http_referer;

    public function __construct()
    {
        $this->request_uri     = '';
        $this->raw_request_uri = '';
        $this->location        = '';
        $this->section         = '';
        $this->title           = '';
        $this->http_referer    = '';
        $this->routes          = array();
        $this->rules           = $this->getRules();
    }

    /**
     * @return array
     */
    public function getRules()
    {
        return unserialize(Preference::newInstance()->get('rewrite_rules'), ['allowed_classes' => false]);
    }

    public function setRules()
    {
        Preference::newInstance()->replace('rewrite_rules', serialize($this->rules));
    }

    /**
     * List all rules
     *
     * @return array
     */
    public function listRules()
    {
        return $this->rules;
    }

    /**
     * add multiple rewrite rules
     *
     * @param $rules
     */
    public function addRules($rules)
    {
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (is_array($rule) && count($rule) > 1) {
                    $this->addRule($rule[0], $rule[1]);
                }
            }
        }
    }

    /**
     * Add rewrite rules
     *
     * @param $regexp
     * @param $uri
     */
    public function addRule($regexp, $uri)
    {
        $regexp = trim($regexp);
        $uri    = trim($uri);
        if ($regexp && $uri && !in_array($regexp, $this->rules, false)) {
            $this->rules[$regexp] = $uri;
        }
    }

    /**
     * @param        $id
     * @param        $regexp
     * @param        $url
     * @param        $file
     * @param bool   $user_menu
     * @param string $location
     * @param string $section
     * @param string $title
     */
    public function addRoute(
        $id,
        $regexp,
        $url,
        $file,
        $user_menu = false,
        $location = 'custom',
        $section = 'custom',
        $title = 'Custom'
    ) {
        $regexp = trim($regexp);
        $file   = trim($file);
        if ($regexp && $file) {
            $this->routes[$id] = array(
                'regexp'    => $regexp,
                'url'       => $url,
                'file'      => $file,
                'user_menu' => $user_menu,
                'location'  => $location,
                'section'   => $section,
                'title'     => $title
            );
        }
    }

    /**
     * Run hook on given root
     * $id will be used as hook name
     *
     * @param string   $id
     * @param string   $regexp
     * @param string   $url
     * @param callable $callback
     */
    public function addRouteHook(
        $id,
        $regexp,
        $url
    ) {
        $regexp = trim($regexp);
        if ($id && $regexp) {
            $this->routes[$id] = array(
                'regexp'          => $regexp,
                'url'             => $url,
                'routeController' => true,
            );
        }
    }

    /**
     * Get all registered routes
     *
     * @return array
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Init Rewrite Class
     *
     */
    public function init()
    {
        if (Params::existServerParam('REQUEST_URI')) {
            $request_uri            = Params::getRequestURI(false, false, false);
            $urldecoded_request_uri = urldecode($request_uri);
            if (preg_match(
                '|[?&]http_referer=(.*)$|',
                $urldecoded_request_uri,
                $ref_match
            )
            ) {
                $this->http_referer     = $ref_match[1];
                $_SERVER['REQUEST_URI'] = preg_replace(
                    '|[?&]http_referer=(.*)$|',
                    '',
                    $urldecoded_request_uri
                );
            }

            $this->raw_request_uri = $request_uri;
            $route_used            = false;
            foreach ($this->routes as $id => $route) {
                // UNCOMMENT TO DEBUG
                //echo 'Request URI: '.$request_uri." # Match : ".$route['regexp']."
                // # URI to go : ".$route['url']." <br />";
                if (preg_match('#^' . $route['regexp'] . '#', $request_uri, $m)) {
                    if (!preg_match_all('#{([^}]+)}#', $route['url'], $args)) {
                        $args[1] = array();
                    }
                    $l = count($m);
                    for ($p = 1; $p < $l; $p++) {
                        if (isset($args[1][$p - 1])) {
                            Params::setParam($args[1][$p - 1], $m[$p]);
                        } else {
                            Params::setParam('route_param_' . $p, $m[$p]);
                        }
                    }
                    Params::setParam('route', $id);
                    $route_used = true;
                    if (isset($route['routeController'])) {
                        Params::setParam('page', 'route');
                    } else {
                        Params::setParam('page', 'custom');
                        $this->location = $route['location'];
                        $this->section  = $route['section'];
                        $this->title    = $route['title'];
                    }
                    break;
                }
            }
            if (!$route_used) {
                if (Preference::newInstance()->get('rewriteEnabled')) {
                    $tmp_ar      = explode('?', $request_uri);
                    $request_uri = $tmp_ar[0];

                    // if try to access directly to a php file
                    if (preg_match('#^(.+?)\.php(.*)$#', $request_uri)) {
                        $file = explode('?', $request_uri);
                        if (!file_exists(ABS_PATH . $file[0])) {
                            self::newInstance()->set_location('error');
                            header('HTTP/1.1 404 Not Found');
                            osc_current_web_theme_path('404.php');
                            exit;
                        }
                    }

                    // Admin requests have their own front controller (oc-admin/index.php)
                    // and must not pass through the public friendly-URL rules. The greedy
                    // category catch-all (^(.+)/?$ -> page=search&sCategory=$1) would
                    // otherwise rewrite the bare admin URL and inject page=search into the
                    // admin request, polluting every getParam('page') consumer there.
                    // OC_ADMIN is ALWAYS defined (false on the public front controller,
                    // true in oc-admin) — so test its value, not defined(): the latter is
                    // always true and would disable friendly URLs everywhere.
                    if (!OC_ADMIN) {
                        foreach ($this->rules as $match => $uri) {
                            // UNCOMMENT TO DEBUG
                            // echo 'Request URI: '.$request_uri." # Match : ".$match." # URI to go : ".$uri." <br />";
                            if (preg_match('#^' . $match . '#', $request_uri, $m)) {
                                $request_uri = preg_replace('#' . $match . '#', $uri, $request_uri);
                                break;
                            }
                        }
                        $this->extractParams($request_uri);
                    }
                }
                $this->request_uri = $request_uri;

                if (Params::getParam('page')) {
                    $this->location = Params::getParam('page');
                }
                if (Params::getParam('action')) {
                    $this->section = Params::getParam('action');
                }
            }
        }
    }

    /**
     * @return \Rewrite
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @param string $uri
     */
    private function extractParams($uri = '')
    {
        $uri_array = explode('?', $uri);
        $length_i  = count($uri_array);
        for ($var_i = 1; $var_i < $length_i; $var_i++) {
            parse_str($uri_array[$var_i], $parsedVars);
            foreach ($parsedVars as $k => $v) {
                Params::setParam($k, urldecode($v));
            }
        }
    }

    /**
     * @param $regexp
     */
    public function removeRule($regexp)
    {
        unset($this->rules[$regexp]);
    }

    public function clearRules()
    {
        unset($this->rules);
        $this->rules = array();
    }

    /**
     * @return string
     */
    public function get_request_uri()
    {
        return $this->request_uri;
    }

    /**
     * @return string
     */
    public function get_raw_request_uri()
    {
        return $this->raw_request_uri;
    }

    /**
     * @return string
     */
    public function get_location()
    {
        return $this->location;
    }

    /**
     * @param $location
     */
    public function set_location($location)
    {
        $this->location = $location;
    }

    /**
     * @return string
     */
    public function get_section()
    {
        return $this->section;
    }

    /**
     * @return string
     */
    public function get_title()
    {
        return $this->title;
    }

    /**
     * @return string
     */
    public function get_http_referer()
    {
        return $this->http_referer;
    }

    /**
     * @param string $uri
     *
     * @return bool|string
     */
    private function extractURL($uri = '')
    {
        $uri_array = explode('?', str_replace('index.php', '', $uri));
        if ($uri_array[0][0] === '/') {
            return substr($uri_array[0], 1);
        }

        return $uri_array[0];
    }
}
