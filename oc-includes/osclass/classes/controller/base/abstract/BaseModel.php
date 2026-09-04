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

use mindstellar\utility\Utils;

/**
 * Class BaseModel
 */
abstract class BaseModel
{
    protected $page;
    protected $action;
    protected $ajax;
    protected $time;

    public function __construct()
    {
        // this is necessary because if HTTP_HOST doesn't have the PORT the parse_url is null
        $current_host = parse_url(Params::getServerParam('HTTP_HOST'), PHP_URL_HOST);
        if ($current_host === null) {
            $current_host = Params::getServerParam('HTTP_HOST');
        }

        if (parse_url(osc_base_url(), PHP_URL_HOST) !== $current_host) {
            // first check if it's http or https
            $url = 'http://';
            if (Utils::isSsl()) {
                $url = 'https://';
            }
            // append the domain
            $url .= parse_url(osc_base_url(), PHP_URL_HOST);
            // Append the port only when the request used a non-default one. A null port is the
            // usual case (80/443 are implicit and absent from HTTP_HOST) and must NOT append a
            // bare ":" — that produced canonical redirects to "https://host:/…".
            $http_port    = parse_url(Params::getServerParam('HTTP_HOST'), PHP_URL_PORT);
            $default_port = Utils::isSsl() ? 443 : 80;
            if ($http_port !== null && (int)$http_port !== $default_port) {
                $url .= ':' . (int)$http_port;
            }
            // append the request
            $url .= Params::getServerParam('REQUEST_URI', false, false);
            $this->redirectTo($url);
        }

        $this->subdomain_params($current_host);
        $this->setParams();
        $this->ajax = false;
        $this->time = microtime(true);
        WebThemes::newInstance();
        osc_run_hook('init');
    }

    /**
     * @param      $url
     * @param null $code
     */
    public function redirectTo($url, $code = null)
    {
        Utils::redirectTo($url, $code);
    }

    /**
     * @param $host
     */
    private function subdomain_params($host)
    {
        $subdomain_type = osc_subdomain_type();
        $subhost        = osc_subdomain_host();
        // strpos is used to check if the domain is different, useful when accessing the website by diferent domains
        if ($subdomain_type && $subhost && strpos($host, $subhost) !== false
            && preg_match('|^(www\.)?(.+)\.' . $subhost . '$|i', $host, $match)
        ) {
            $subdomain = $match[2];
            if ($subdomain && $subdomain !== 'www') {
                if ($subdomain_type === 'category') {
                    $category = Category::newInstance()->findBySlug($subdomain);
                    if (isset($category['pk_i_id'])) {
                        $this->_exportVariableToView('subdomain_name', $category['s_name']);
                        $this->_exportVariableToView('subdomain_slug', $category['s_slug']);
                        Params::setParam('sCategory', $category['pk_i_id']);
                        if (!Params::getParam('page')) {
                            Params::setParam('page', 'search');
                        }
                    } else {
                        $this->do404();
                    }
                } elseif ($subdomain_type === 'country') {
                    $country = Country::newInstance()->findBySlug($subdomain);
                    if (isset($country['pk_c_code'])) {
                        $this->_exportVariableToView('subdomain_name', $country['s_name']);
                        $this->_exportVariableToView('subdomain_slug', $country['s_slug']);
                        Params::setParam('sCountry', $country['pk_c_code']);
                    } else {
                        $this->do404();
                    }
                } elseif ($subdomain_type === 'region') {
                    $region = Region::newInstance()->findBySlug($subdomain);
                    if (isset($region['pk_i_id'])) {
                        $this->_exportVariableToView('subdomain_name', $region['s_name']);
                        $this->_exportVariableToView('subdomain_slug', $region['s_slug']);
                        Params::setParam('sRegion', $region['pk_i_id']);
                    } else {
                        $this->locationSubdomainSlugRedirect('REGION', $subdomain, $match[1], $subhost);
                        $this->do404();
                    }
                } elseif ($subdomain_type === 'city') {
                    $city = City::newInstance()->findBySlug($subdomain);
                    if (isset($city['pk_i_id'])) {
                        $this->_exportVariableToView('subdomain_name', $city['s_name']);
                        $this->_exportVariableToView('subdomain_slug', $city['s_slug']);
                        Params::setParam('sCity', $city['pk_i_id']);
                    } else {
                        $this->locationSubdomainSlugRedirect('CITY', $subdomain, $match[1], $subhost);
                        $this->do404();
                    }
                } elseif ($subdomain_type === 'user') {
                    $user = User::newInstance()->findByUsername($subdomain);
                    if (isset($user['pk_i_id'])) {
                        $this->_exportVariableToView('subdomain_name', $user['s_name']);
                        $this->_exportVariableToView('subdomain_slug', $user['s_username']);
                        Params::setParam('sUser', $user['pk_i_id']);
                    } else {
                        $this->do404();
                    }
                } else {
                    $this->do404();
                }
            }
        }
    }

    /**
     * If $slug is a former region/city slug recorded in the location rename history,
     * 301 to the same URL with the current slug swapped into the subdomain. Falls
     * through (returns without redirecting) on a history miss, a target row that no
     * longer exists, or a current slug that would not change the URL -- the caller
     * then do404()s.
     *
     * The default search-URL scheme embeds the row id ({slug}-r{id}) and self-heals
     * on rename, but subdomain routing resolves purely by slug with nothing to fall
     * back on -- and a dataset update can rename a large share of regions/cities at
     * once (see LocationImporter). This is that fallback.
     *
     * @param string $type      'REGION' or 'CITY'
     * @param string $slug      the requested (missed) subdomain slug
     * @param string $wwwPrefix 'www.' or '', whatever prefixed the subdomain in the request host
     * @param string $subhost   the configured subdomain host (osc_subdomain_host())
     *
     * @return void
     */
    private function locationSubdomainSlugRedirect($type, $slug, $wwwPrefix, $subhost)
    {
        $slug = trim((string)$slug);
        if ($slug === '') {
            return;
        }

        try {
            $history = osc_db_select_one(
                'SELECT fk_i_id FROM ' . DB_TABLE_PREFIX . 't_location_slug_history'
                . ' WHERE e_type = ? AND s_slug = ?',
                array($type, $slug)
            );
        } catch (\mindstellar\database\DbException $e) {
            return;
        }

        if ($history === null) {
            return;
        }

        $model   = $type === 'REGION' ? Region::newInstance() : City::newInstance();
        $current = $model->findByPrimaryKey((int)$history['fk_i_id']);
        if (!$current || !isset($current['pk_i_id'])) {
            return; // target row is gone -> let the caller do404()
        }

        $currentSlug = $current['s_slug'];
        if ($currentSlug === '' || $currentSlug === $slug) {
            return; // loop guard
        }

        $url = Utils::isSsl() ? 'https://' : 'http://';
        $url .= $wwwPrefix . $currentSlug . '.' . $subhost;
        // Same non-default-port handling as the host canonicalization above: a null
        // port is the usual case and must not append a bare ":".
        $http_port    = parse_url(Params::getServerParam('HTTP_HOST'), PHP_URL_PORT);
        $default_port = Utils::isSsl() ? 443 : 80;
        if ($http_port !== null && (int)$http_port !== $default_port) {
            $url .= ':' . (int)$http_port;
        }
        $url .= Params::getServerParam('REQUEST_URI', false, false);

        $this->redirectTo($url, 301);
    }

    //to export variables at the business layer

    /**
     * Retained for themes and plugins that call it. Core no longer uses it: a URL that
     * resolves to nothing is Not Found, not Bad Request.
     *
     * @return void
     */
    public function do400()
    {
        Rewrite::newInstance()->set_location('error');
        header('HTTP/1.1 400 Bad Request');
        $this->sendErrorCacheHeaders();
        osc_current_web_theme_path(osc_locate_template(array('404.php'), '404'));
        exit;
    }

    /**
     *
     * @since 3.9.0
     */
    protected function setParams()
    {
        $this->page   = Params::getParam('page');
        $this->action = Params::getParam('action');
    }

    public function __destruct()
    {
        if (!$this->ajax && OSC_DEBUG) {
            echo '<!-- ' . $this->getTime() . ' seg. -->';
        }
    }

    /**
     * @return float
     */
    public function getTime()
    {
        $timeEnd = microtime(true);

        return $timeEnd - $this->time;
    }

    /**
     * @param $key
     * @param $value
     */
    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }

    /**
     * @param null $key
     */
    public function _view($key = null)
    {
        View::newInstance()->_view($key);
    }

    public function do404()
    {
        Rewrite::newInstance()->set_location('error');
        header('HTTP/1.1 404 Not Found');
        $this->sendErrorCacheHeaders();
        osc_current_web_theme_path(osc_locate_template(array('404.php'), '404'));
        exit;
    }

    /**
     * Retained for themes and plugins that call it. Core no longer uses it: 410 claims a URL
     * is permanently gone, which a restore or a re-import makes untrue, and search engines
     * treat it almost identically to 404.
     *
     * @return void
     */
    public function do410()
    {
        Rewrite::newInstance()->set_location('error');
        header('HTTP/1.1 410 Gone');
        $this->sendErrorCacheHeaders();
        osc_current_web_theme_path(osc_locate_template(array('404.php'), '404'));
        exit;
    }

    /**
     * Error pages exit before index.php can stamp Cache-Control, so stamp it here — a crawler
     * walking dead listing URLs is otherwise a full theme render per hit. An identified visitor
     * is downgraded to `private, no-store` by osc_response_is_cacheable().
     *
     * @return void
     */
    private function sendErrorCacheHeaders()
    {
        osc_mark_response_cacheable();
        osc_send_response_cache_headers();
    }
    /**
     *  Functions that will have to be rewritten in the class that extends from this
     */
    abstract protected function doModel();

    /**
     * @param $file
     *
     * @return mixed
     */
    abstract protected function doView($file);
}

/* file end: ./oc-includes/osclass/core/BaseModel.php */
