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
    private $rulesRebuilt = false;

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
        $stored = Preference::newInstance()->get('rewrite_rules');
        if ($stored === '' || $stored === false) {
            return array();
        }
        $rules = unserialize($stored, ['allowed_classes' => false]);

        return is_array($rules) ? $rules : array();
    }

    public function setRules()
    {
        Preference::newInstance()->replace('rewrite_rules', serialize($this->rules));
    }

    /**
     * True when the persisted rule table is missing or was built by a different
     * Shopclass version, so it must be regenerated from buildRules(). Keying on
     * OSCLASS_VERSION (the code constant, not the DB version preference) rebuilds
     * on the first request after new files are deployed — before the DB upgrade
     * even runs — and needs no manual bump when buildRules() changes.
     *
     * @return bool
     */
    private function rulesAreStale()
    {
        // Already regenerated this request: the Preference cache still holds the
        // pre-write version (persistRules' REPLACE INTO does not refresh it), so a
        // second init() in the same request would otherwise rebuild again.
        if ($this->rulesRebuilt) {
            return false;
        }
        if (empty($this->rules)) {
            return true;
        }

        return (string)Preference::newInstance()->get('rewrite_rules_version') !== OSCLASS_VERSION;
    }

    /**
     * Regenerate the rule table from the current permalink preferences and cache
     * it. Single entry point shared by the admin permalinks screen and the
     * request-time self-heal in init().
     *
     * @return array the freshly built rules
     */
    public function rebuildAndPersistRules()
    {
        $this->buildRules();
        $this->persistRules();
        $this->rulesRebuilt = true;

        return $this->rules;
    }

    /**
     * Serialize the current rule table into the cache and stamp its version.
     * A read-only database (replica) makes the write fail; the rules stay valid
     * in memory for this request, so the failure is swallowed rather than fatal.
     */
    private function persistRules()
    {
        try {
            $pref = Preference::newInstance();
            $pref->replace('rewrite_rules', serialize($this->rules));
            $pref->replace('rewrite_rules_version', OSCLASS_VERSION);
        } catch (\Throwable $e) {
            // Non-fatal: the cache simply is not updated this request.
        }
    }

    /**
     * Populate the rule table from the permalink preferences. This is the single
     * source of truth for the site's friendly-URL structure. Fires the
     * before/after_rewrite_rules hooks so plugin-contributed rules are included.
     */
    public function buildRules()
    {
        $rewrite = $this;

        $item_url   = osc_get_preference('rewrite_item_url');
        $page_url   = osc_get_preference('rewrite_page_url');
        $cat_url    = osc_get_preference('rewrite_cat_url');
        $search_url = osc_get_preference('rewrite_search_url');

        osc_run_hook('before_rewrite_rules', array(&$rewrite));
        $rewrite->clearRules();

        // Contact rules
        $rewrite->addRule('^' . osc_get_preference('rewrite_contact') . '/?$', 'index.php?page=contact');

        // Feed rules
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_feed') . '/?$',
            'index.php?page=search&sFeed=rss'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_feed') . '/(.+)/?$',
            'index.php?page=search&sFeed=$1'
        );

        // Language rules
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_language') . '/(.*?)/?$',
            'index.php?page=language&locale=$1'
        );

        // Search rules
        $rewrite->addRule('^' . $search_url . '$', 'index.php?page=search');
        $rewrite->addRule('^' . $search_url . '/(.*)$', 'index.php?page=search&sParams=$1');

        // Item rules
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_mark') . '/(.*?)/([0-9]+)/?$',
            'index.php?page=item&action=mark&as=$1&id=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_send_friend') . '/([0-9]+)/?$',
            'index.php?page=item&action=send_friend&id=$1'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_contact') . '/([0-9]+)/?$',
            'index.php?page=item&action=contact&id=$1'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_new') . '/?$',
            'index.php?page=item&action=item_add'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_new') . '/([0-9]+)/?$',
            'index.php?page=item&action=item_add&catId=$1'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_activate') . '/([0-9]+)/(.*?)/?$',
            'index.php?page=item&action=activate&id=$1&secret=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_edit') . '/([0-9]+)/(.*?)/?$',
            'index.php?page=item&action=item_edit&id=$1&secret=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_delete') . '/([0-9]+)/(.*?)/?$',
            'index.php?page=item&action=item_delete&id=$1&secret=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_item_resource_delete')
            . '/([0-9]+)/([0-9]+)/([0-9A-Za-z]+)/?(.*?)/?$',
            'index.php?page=item&action=deleteResource&id=$1&item=$2&code=$3&secret=$4'
        );

        // Item rules
        $id_pos    = stripos($item_url, '{ITEM_ID}');
        $title_pos = stripos($item_url, '{ITEM_TITLE}');
        $cat_pos   = stripos($item_url, '{CATEGORIES');
        $param_pos = 1;
        if ($title_pos !== false && $id_pos > $title_pos) {
            $param_pos++;
        }
        if ($cat_pos !== false && $id_pos > $cat_pos) {
            $param_pos++;
        }
        $comments_pos = 1;
        if ($id_pos !== false) {
            $comments_pos++;
        }
        if ($title_pos !== false) {
            $comments_pos++;
        }
        if ($cat_pos !== false) {
            $comments_pos++;
        }
        $rewrite->addRule(
            '^([a-z]{2})_([A-Z]{2})/' . str_replace(
                '{ITEM_CITY}',
                '.*',
                str_replace('{CATEGORIES}', '.*', str_replace(
                    '{ITEM_TITLE}',
                    '.*',
                    str_replace('{ITEM_ID}', '([0-9]+)', $item_url . '\?comments-page=([0-9al]*)')
                ))
            ) . '$',
            'index.php?page=item&id=$3&lang=$1_$2&comments-page=$4'
        );
        $rewrite->addRule(
            '^' . str_replace('{ITEM_CITY}', '.*', str_replace(
                '{CATEGORIES}',
                '.*',
                str_replace(
                    '{ITEM_TITLE}',
                    '.*',
                    str_replace('{ITEM_ID}', '([0-9]+)', $item_url . '\?comments-page=([0-9al]*)')
                )
            )) . '$',
            'index.php?page=item&id=$1&comments-page=$2'
        );
        $rewrite->addRule('^([a-z]{2})_([A-Z]{2})/' . str_replace(
            '{ITEM_CITY}',
            '.*',
            str_replace(
                '{CATEGORIES}',
                '.*',
                str_replace('{ITEM_TITLE}', '.*', str_replace('{ITEM_ID}', '([0-9]+)', $item_url))
            )
        )
            . '$', 'index.php?page=item&id=$3&lang=$1_$2');
        $rewrite->addRule(
            '^' . str_replace('{ITEM_CITY}', '.*', str_replace(
                '{CATEGORIES}',
                '.*',
                str_replace('{ITEM_TITLE}', '.*', str_replace('{ITEM_ID}', '([0-9]+)', $item_url))
            )) . '$',
            'index.php?page=item&id=$1'
        );

        // User rules
        $rewrite->addRule('^' . osc_get_preference('rewrite_user_login') . '/?$', 'index.php?page=login');
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_dashboard') . '/?$',
            'index.php?page=user&action=dashboard'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_logout') . '/?$',
            'index.php?page=main&action=logout'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_register') . '/?$',
            'index.php?page=register&action=register'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_activate') . '/([0-9]+)/(.*?)/?$',
            'index.php?page=register&action=validate&id=$1&code=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_activate_alert')
            . '/([0-9]+)/([a-zA-Z0-9]+)/(.+)$',
            'index.php?page=user&action=activate_alert&id=$1&email=$3&secret=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_profile') . '/?$',
            'index.php?page=user&action=profile'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_profile') . '/([0-9]+)/([0-9]+)/?$',
            'index.php?page=user&action=pub_profile&id=$1&iPage=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_profile') . '/([0-9]+)/?$',
            'index.php?page=user&action=pub_profile&id=$1'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_profile') . '/([^/]+)/([0-9]+)/?$',
            'index.php?page=user&action=pub_profile&username=$1&iPage=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_profile') . '/([^/]+)/?$',
            'index.php?page=user&action=pub_profile&username=$1'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_items') . '/?$',
            'index.php?page=user&action=items'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_alerts') . '/?$',
            'index.php?page=user&action=alerts'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_recover') . '/?$',
            'index.php?page=login&action=recover'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_forgot') . '/([0-9]+)/(.*)/?$',
            'index.php?page=login&action=forgot&userId=$1&code=$2'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_change_password') . '/?$',
            'index.php?page=user&action=change_password'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_change_email') . '/?$',
            'index.php?page=user&action=change_email'
        );
        $rewrite->addRule(
            '^' . osc_get_preference('rewrite_user_change_username') . '/?$',
            'index.php?page=user&action=change_username'
        );
        $rewrite->addRule('^' . osc_get_preference('rewrite_user_change_email_confirm')
            . '/([0-9]+)/(.*?)/?$', 'index.php?page=user&action=change_email_confirm&userId=$1&code=$2');

        // Page rules
        $pos_pID   = stripos($page_url, '{PAGE_ID}');
        $pos_pSlug = stripos($page_url, '{PAGE_SLUG}');
        $pID_pos   = 1;
        $pSlug_pos = 1;
        if (is_numeric($pos_pID) && is_numeric($pos_pSlug)) {
            // set the order of the parameters
            if ($pos_pID > $pos_pSlug) {
                $pID_pos++;
            } else {
                $pSlug_pos++;
            }

            $rewrite->addRule(
                '^' . str_replace(
                    '{PAGE_SLUG}',
                    '([\p{L}\p{N}_\-,]+)',
                    str_replace('{PAGE_ID}', '([0-9]+)', $page_url)
                ) . '/?$',
                'index.php?page=page&id=$' . $pID_pos . '&slug=$' . $pSlug_pos
            );
            $rewrite->addRule(
                '^([a-z]{2})_([A-Z]{2})/' . str_replace(
                    '{PAGE_SLUG}',
                    '([\p{L}\p{N}_\-,]+)',
                    str_replace('{PAGE_ID}', '([0-9]+)', $page_url)
                ) . '/?$',
                'index.php?page=page&lang=$1_$2&id=$' . ($pID_pos + 2) . '&slug=$' . ($pSlug_pos + 2)
            );
        } elseif (is_numeric($pos_pID)) {
            $rewrite->addRule(
                '^' . str_replace('{PAGE_ID}', '([0-9]+)', $page_url) . '/?$',
                'index.php?page=page&id=$1'
            );
            $rewrite->addRule('^([a-z]{2})_([A-Z]{2})/' . str_replace('{PAGE_ID}', '([0-9]+)', $page_url)
                . '/?$', 'index.php?page=page&lang=$1_$2&id=$3');
        } else {
            $rewrite->addRule(
                '^' . str_replace('{PAGE_SLUG}', '([\p{L}\p{N}_\-,]+)', $page_url) . '/?$',
                'index.php?page=page&slug=$1'
            );
            $rewrite->addRule('^([a-z]{2})_([A-Z]{2})/' . str_replace(
                '{PAGE_SLUG}',
                '([\p{L}\p{N}_\-,]+)',
                $page_url
            ) . '/?$', 'index.php?page=page&lang=$1_$2&slug=$3');
        }

        // Clean archive files
        $rewrite->addRule('^(.+?)\.php(.*)$', '$1.php$2');

        // Category rules
        $id_pos    = stripos($item_url, '{CATEGORY_ID}');
        $title_pos = stripos($item_url, '{CATEGORY_NAME}');
        $cat_pos   = stripos($item_url, '{CATEGORIES');
        $param_pos = 1;
        if ($title_pos !== false && $id_pos > $title_pos) {
            $param_pos++;
        }
        if ($cat_pos !== false && $id_pos > $cat_pos) {
            $param_pos++;
        }
        $rewrite->addRule(
            '^' . str_replace(
                '{CATEGORIES}',
                '(.+)',
                str_replace(
                    '{CATEGORY_NAME}',
                    '([^/]+)',
                    str_replace('{CATEGORY_ID}', '([0-9]+)', $cat_url)
                )
            ) . '/([0-9]+)$',
            'index.php?page=search&sCategory=$' . $param_pos . '&iPage=$' . ($param_pos + 1)
        );
        $rewrite->addRule(
            '^' . str_replace(
                '{CATEGORIES}',
                '(.+)',
                str_replace(
                    '{CATEGORY_NAME}',
                    '([^/]+)',
                    str_replace('{CATEGORY_ID}', '([0-9]+)', $cat_url)
                )
            ) . '/?$',
            'index.php?page=search&sCategory=$' . $param_pos
        );

        $rewrite->addRule('^(.+)/([0-9]+)$', 'index.php?page=search&iPage=$2');
        $rewrite->addRule('^(.+)$', 'index.php?page=search');

        osc_run_hook('after_rewrite_rules', array(&$rewrite));
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
        // Self-heal: after a version change (new files deployed) or on a
        // fresh/corrupt cache, rebuild the rule table from code so a new route
        // type takes effect on the next request without re-saving permalinks.
        // Runs here (oc-load, after Plugins::init) so before/after_rewrite_rules
        // hooks fire with plugins loaded.
        if ($this->rulesAreStale()) {
            $this->rules = $this->rebuildAndPersistRules();
        }

        if (!Params::existServerParam('REQUEST_URI')) {
            return;
        }

        $request_uri = Params::getRequestURI(false, false, false);
        $this->captureHttpReferer($request_uri);
        $this->raw_request_uri = $request_uri;

        // Dispatch is decided by pure resolve*() methods that return a plain
        // description of the match (or null) and never touch Params; init() is the
        // one place those descriptions are applied to Params/instance state. That
        // split is what lets the resolvers be unit-tested without a request.

        // A registered route (plugin/theme addRoute, or a controller route hook)
        // wins outright and short-circuits the rest of dispatch.
        $route = $this->resolveRoute($request_uri);
        if ($route !== null) {
            $this->applyMatch($route);

            return;
        }

        // Core-native sitemap routes, served as a FALLBACK: a plugin that
        // registered its own route on these paths (e.g. a third-party XML sitemap)
        // already claimed them above and wins. Only when nothing else matched does
        // core serve the sitemap — regardless of whether rewrite is enabled and
        // without a theme having to register a route.
        $sitemap = $this->resolveSitemap($request_uri);
        if ($sitemap !== null) {
            $this->applyParams($sitemap);
            $this->location    = 'sitemap';
            $this->section     = 'sitemap';
            $this->request_uri = $request_uri;

            return;
        }

        if (Preference::newInstance()->get('rewriteEnabled')) {
            $rewrite = $this->resolveRewrite($request_uri);
            if ($rewrite['not_found']) {
                $this->set_location('error');
                header('HTTP/1.1 404 Not Found');
                osc_current_web_theme_path('404.php');
                exit;
            }
            $request_uri = $rewrite['uri'];
            $this->applyParams($rewrite['params']);
        }
        $this->request_uri = $request_uri;

        if (Params::getParam('page')) {
            $this->location = Params::getParam('page');
        }
        if (Params::getParam('action')) {
            $this->section = Params::getParam('action');
        }
    }

    /**
     * Write a resolved param map into Params. The single sink through which every
     * resolve*() result reaches request state.
     *
     * @param array $params key => value pairs to set
     */
    private function applyParams(array $params)
    {
        foreach ($params as $k => $v) {
            Params::setParam($k, $v);
        }
    }

    /**
     * Apply a resolveRoute() result: its params, plus location/section/title when
     * the route carried them (a controller route leaves those null).
     *
     * @param array $match
     */
    private function applyMatch(array $match)
    {
        $this->applyParams($match['params']);
        if ($match['location'] !== null) {
            $this->location = $match['location'];
        }
        if ($match['section'] !== null) {
            $this->section = $match['section'];
        }
        if ($match['title'] !== null) {
            $this->title = $match['title'];
        }
    }

    /**
     * Pull a `http_referer=` argument out of the request URI into $this->http_referer
     * and strip it from $_SERVER['REQUEST_URI'] so it never reaches dispatch.
     *
     * @param string $request_uri
     */
    private function captureHttpReferer($request_uri)
    {
        $urldecoded_request_uri = urldecode($request_uri);
        if (preg_match('|[?&]http_referer=(.*)$|', $urldecoded_request_uri, $ref_match)) {
            $this->http_referer     = $ref_match[1];
            $_SERVER['REQUEST_URI'] = preg_replace('|[?&]http_referer=(.*)$|', '', $urldecoded_request_uri);
        }
    }

    /**
     * Match the request against the registered routes ($this->routes) without side
     * effects. Returns a dispatch description — the params to set (captures named
     * via {…} placeholders in the route url, or route_param_N otherwise, plus
     * `route` and `page`) and, for a non-controller route, location/section/title —
     * or null when nothing matches. First match wins. See applyMatch() for the sink.
     *
     * @param string $request_uri
     *
     * @return array|null
     */
    private function resolveRoute($request_uri)
    {
        foreach ($this->routes as $id => $route) {
            if (!preg_match('#^' . $route['regexp'] . '#', $request_uri, $m)) {
                continue;
            }
            if (!preg_match_all('#{([^}]+)}#', $route['url'], $args)) {
                $args[1] = array();
            }
            $params = array();
            $l      = count($m);
            for ($p = 1; $p < $l; $p++) {
                $key          = $args[1][$p - 1] ?? ('route_param_' . $p);
                $params[$key] = $m[$p];
            }
            $params['route'] = $id;

            $result = array('params' => $params, 'location' => null, 'section' => null, 'title' => null);
            if (isset($route['routeController'])) {
                $result['params']['page'] = 'route';
            } else {
                $result['params']['page'] = 'custom';
                $result['location']       = $route['location'];
                $result['section']        = $route['section'];
                $result['title']          = $route['title'];
            }

            return $result;
        }

        return null;
    }

    /**
     * Resolve a request that no route claimed against the friendly-URL rewrite
     * rules, without side effects. Returns ['uri' => rewritten URI, 'params' =>
     * extracted query params, 'not_found' => bool]. `not_found` flags a direct
     * request for a missing .php file — init() turns that into the 404 (a pure
     * resolver never sends headers or exits).
     *
     * @param string $request_uri
     *
     * @return array{uri: string, params: array, not_found: bool}
     */
    private function resolveRewrite($request_uri)
    {
        $tmp_ar      = explode('?', $request_uri);
        $request_uri = $tmp_ar[0];

        // if try to access directly to a php file
        if (preg_match('#^(.+?)\.php(.*)$#', $request_uri)) {
            $file = explode('?', $request_uri);
            if (!file_exists(ABS_PATH . $file[0])) {
                return array('uri' => $request_uri, 'params' => array(), 'not_found' => true);
            }
        }

        // Admin requests have their own front controller (oc-admin/index.php) and
        // must not pass through the public friendly-URL rules. The greedy category
        // catch-all (^(.+)/?$ -> page=search&sCategory=$1) would otherwise rewrite
        // the bare admin URL and inject page=search into the admin request,
        // polluting every getParam('page') consumer there. OC_ADMIN is ALWAYS
        // defined (false on the public front controller, true in oc-admin) — so
        // test its value, not defined(): the latter is always true and would
        // disable friendly URLs everywhere.
        $params = array();
        if (!OC_ADMIN) {
            foreach ($this->rules as $match => $uri) {
                if (preg_match('#^' . $match . '#', $request_uri, $m)) {
                    $request_uri = preg_replace('#' . $match . '#', $uri, $request_uri);
                    break;
                }
            }
            $params = $this->parseParams($request_uri);
        }

        return array('uri' => $request_uri, 'params' => $params, 'not_found' => false);
    }

    /**
     * @return \Rewrite
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Recognise a core sitemap URL and set the dispatch params for it.
     *
     * Handles sitemap.xml / sitemapindex.xml / sitemap-index.xml (the index),
     * sitemap/{category,pages,cities,regions,countries,categories-regions,
     * categories-cities}.xml, and sitemap/item-sitemap_s{N}.xml (paginated item
     * sitemaps). The query string is ignored for matching.
     *
     * @param string $request_uri the install-relative request URI
     *
     * @return array|null the sitemap dispatch params, or null when no match
     */
    private function resolveSitemap($request_uri)
    {
        $path = explode('?', $request_uri, 2)[0];
        $path = ltrim($path, '/');

        $fixed = array(
            // robots.txt only reaches PHP when no static robots.txt shadows it
            // (the admin editor writes a static file); this is the fallback.
            'robots.txt'                     => 'robots',
            'sitemap.xml'                    => 'index',
            'sitemapindex.xml'               => 'index',
            'sitemap-index.xml'              => 'index',
            'sitemap/category.xml'           => 'category',
            'sitemap/pages.xml'              => 'pages',
            'sitemap/cities.xml'             => 'cities',
            'sitemap/regions.xml'            => 'regions',
            'sitemap/countries.xml'          => 'countries',
            'sitemap/categories-regions.xml' => 'cat_regions',
            'sitemap/categories-cities.xml'  => 'cat_cities',
        );

        if (isset($fixed[$path])) {
            return array('page' => 'sitemap', 'sitemap_doc' => $fixed[$path]);
        }

        if (preg_match('#^sitemap/item-sitemap_s([0-9]+)\.xml$#i', $path, $m)) {
            return array('page' => 'sitemap', 'sitemap_doc' => 'item', 'sitemap_page' => $m[1]);
        }

        return null;
    }

    /**
     * Parse a rewritten URI's query string into a param map, decoding each value
     * exactly once (parse_str already url-decodes; a second pass double-decoded, so
     * a%2Bb became "a b" instead of "a+b"). Pure — the caller writes the result.
     *
     * @param string $uri
     *
     * @return array
     */
    private function parseParams($uri = '')
    {
        $params    = array();
        $uri_array = explode('?', $uri);
        $length_i  = count($uri_array);
        for ($var_i = 1; $var_i < $length_i; $var_i++) {
            parse_str($uri_array[$var_i], $parsedVars);
            foreach ($parsedVars as $k => $v) {
                $params[$k] = $v;
            }
        }

        return $params;
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
}
