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
 * Class CWebSearch
 */
class CWebSearch extends BaseModel
{
    public $mSearch;
    public $uri;

    /**
     * Boots the base controller, opens the Search model, and resolves the friendly search
     * URI into request params (category slug, location, feed), 404ing when it matches nothing.
     */
    public function __construct()
    {
        parent::__construct();

        $this->mSearch = Search::getInstance();

        $request = array();
        foreach (array('sCategory', 'sFeed') as $key) {
            if (Params::existParam($key)) {
                $request[$key] = Params::getParam($key);
            }
        }
        $resolved  = $this->uriResolver()->resolve(
            (string)Params::getRequestURI(false, false, false),
            $request,
            osc_rewrite_enabled()
        );
        $this->uri = $resolved['uri'];
        if (isset($resolved['exports']['search_uri'])) {
            $this->_exportVariableToView('search_uri', $resolved['exports']['search_uri']);
        }
        foreach ($resolved['params'] as $op) {
            if ($op[0] === 'set') {
                Params::setParam($op[1], $op[2]);
            } else {
                Params::unsetParam($op[1]);
            }
        }
        if ($resolved['redirect'] !== null) {
            $this->redirectTo($resolved['redirect']['url'], $resolved['redirect']['code']);
        }
        if ($resolved['notFound']) {
            $this->do404();
        }
    }

    //Business Layer...
    /**
     * Runs the listing search, exports the results and paging/canonical data to the view,
     * and renders the search template or the requested feed.
     *
     * @return void
     */
    public function doModel()
    {
        $this->saveAlertIfPosted();
        osc_run_hook('before_search');

        if (osc_rewrite_enabled()) {
            $this->applyFriendlyParams();
        }

        $uriParams = Params::getParamsAsArray();
        $this->redirectToCanonical($uriParams);

        $criteria = $this->buildCriteria($uriParams);
        $this->recordLatestSearch($criteria);

        $result = \mindstellar\search\SearchRunner::run($criteria, $this->mSearch, 'request');
        osc_run_hook('search', $this->mSearch);

        $this->exportResults($criteria, $result);
        $this->exportAlert($criteria);
        $this->markEmptyResult($criteria, $result->items());

        osc_run_hook('after_search');

        if (Params::existParam('sFeed')) {
            $this->renderFeed($criteria->feed(), $result->items());
        } else {
            $this->renderPage($criteria->categories());
        }
    }

    /**
     * The alert form posted without JavaScript.
     *
     * @return void
     */
    private function saveAlertIfPosted()
    {
        if ($this->action === 'alert_post') {
            $this->saveAlert();
        }
    }

    /**
     * 301 to the friendly URL of these params, then export this page's canonical.
     *
     * @param array<string,mixed> $uriParams
     *
     * @return void
     */
    private function redirectToCanonical(array $uriParams)
    {
        $searchUri = osc_search_url($uriParams);
        if ($this->uri !== 'feed') {
            $_base_url = WEB_PATH;
            if (str_replace('%20', '+', $searchUri) !== str_replace('%20', '+', $_base_url . $this->uri)
            ) {
                $this->redirectTo($searchUri, 301);
            }
        }

        // Self-referential canonical for every search/category page: this page of the result
        // set, unsorted, so sort and order permutations of the same page share one URL.
        if ($this->uri !== 'feed' && !Params::existParam('sFeed')) {
            $this->_exportVariableToView('canonical', osc_search_url(self::canonicalParams($uriParams)));
        }
    }

    /**
     * The search values, 404ing when every category asked for is missing.
     *
     * @param array<string,mixed> $uriParams
     *
     * @return \mindstellar\search\SearchCriteria
     */
    private function buildCriteria(array $uriParams)
    {
        $criteria = \mindstellar\search\SearchCriteria::fromRequest($uriParams, array(
            'orderField'  => osc_default_order_field_at_search(),
            'orderType'   => osc_default_order_type_at_search(),
            'showAs'      => osc_default_show_as_at_search(),
            'pageSize'    => osc_default_results_per_page_at_search(),
            'maxPageSize' => osc_max_results_per_page_at_search(),
            'rssItems'    => osc_num_rss_items(),
        ));

        $categories = $criteria->categories();
        // A category that does not exist is a missing page, not every listing on the site.
        if ($categories !== array()
            && array_filter($categories, static fn ($c) => self::findCategory((string)$c) !== array()) === array()
        ) {
            $this->do404();
        }

        return $criteria;
    }

    /**
     * Add the pattern to the latest searches, on the first page only.
     *
     * @param \mindstellar\search\SearchCriteria $criteria
     *
     * @return void
     */
    private function recordLatestSearch($criteria)
    {
        if (osc_save_latest_searches()
            && (!Params::existParam('iPage')
                || Params::getParam('iPage') == 1)
        ) {
            $p_sPattern  = $criteria->pattern();
            $savePattern = osc_apply_filter('save_latest_searches_pattern', $p_sPattern);
            if ($savePattern != '') {
                LatestSearches::getInstance()->insert(array(
                    's_search' => $savePattern,
                    'd_date'   => date('Y-m-d H:i:s')
                ));
            }
        }
    }

    /**
     * Export the results, the paging and the search values to the view.
     *
     * @param \mindstellar\search\SearchCriteria $criteria
     * @param \mindstellar\search\SearchResult   $result
     *
     * @return void
     */
    private function exportResults($criteria, $result)
    {
        $page        = $criteria->page();
        $pageSize    = $criteria->pageSize();
        $iTotalItems = $result->total();

        $p_sCountry  = implode(', ', $criteria->countries());
        $countryName = $p_sCountry;
        if (strlen($p_sCountry) == 2) {
            $c = Country::getInstance()->findByCode($p_sCountry);
            if ($c) {
                $countryName = $c['s_name'];
            }
        }
        $p_sRegion  = implode(', ', $criteria->regions());
        $regionName = $p_sRegion;
        if (is_numeric($p_sRegion)) {
            $r = Region::getInstance()->findByPrimaryKey($p_sRegion);
            if ($r) {
                $regionName = $r['s_name'];
            }
        }
        $p_sCity  = implode(', ', $criteria->cities());
        $cityName = $p_sCity;
        if (is_numeric($p_sCity)) {
            $c = City::getInstance()->findByPrimaryKey($p_sCity);
            if ($c) {
                $cityName = $c['s_name'];
            }
        }

        $this->_exportVariableToView('search_start', $page * $pageSize);
        $this->_exportVariableToView('search_end', min(($page + 1) * $pageSize, $iTotalItems));
        $this->_exportVariableToView('search_category', $criteria->categories());
        $this->_exportVariableToView('search_order_type', $criteria->orderType());
        $this->_exportVariableToView('search_order', $criteria->order());

        $this->_exportVariableToView('search_pattern', $criteria->pattern());
        $this->_exportVariableToView('search_from_user', $criteria->users());
        $this->_exportVariableToView('search_total_pages', ceil($iTotalItems / $pageSize));
        $this->_exportVariableToView('search_page', $page);
        $this->_exportVariableToView('search_has_pic', $criteria->withPicture() ? 1 : 0);
        $this->_exportVariableToView('search_only_premium', $criteria->onlyPremium() ? 1 : 0);
        $this->_exportVariableToView('search_country', $countryName);
        $this->_exportVariableToView('search_region', $regionName);
        $this->_exportVariableToView('search_city', $cityName);
        $this->_exportVariableToView('search_price_min', $criteria->priceMin());
        $this->_exportVariableToView('search_price_max', $criteria->priceMax());
        $this->_exportVariableToView('search_total_items', $iTotalItems);
        $this->_exportVariableToView('items', $result->items());
        $this->_exportVariableToView('search_show_as', $criteria->showAs());

        // The model the page was built from, always: osc_get_premiums()/osc_search() read it,
        // and on a page a search_results backend answered it is that backend's model.
        $this->_exportVariableToView('search', $result->model());
    }

    /**
     * Export the encrypted alert token and whether the visitor already has this alert.
     *
     * @param \mindstellar\search\SearchCriteria $criteria
     *
     * @return void
     */
    private function exportAlert($criteria)
    {
        // The alert stores the search values in canonical form, not the SQL of toJson().
        $json = \mindstellar\search\AlertEnvelope::build($criteria, Params::getParamsAsArray());
        // Encrypted with a persistent per-install key, so the later subscribe request can
        // verify it without the session (which would make every search page uncacheable).
        $encoded_alert = base64_encode(osc_encrypt_alert($json));

        $this->_exportVariableToView('search_alert', $encoded_alert);
        $alerts_sub = 0;
        if (osc_is_web_user_logged_in()) {
            $alerts = Alerts::getInstance()->findBySearchAndUser($json, osc_logged_user_id());
            if (count($alerts) > 0) {
                $alerts_sub = 1;
            }
        }
        $this->_exportVariableToView('search_alert_subscribed', $alerts_sub);
    }

    /**
     * No results: a refined search is a 404, a browse page stays 200 but is noindexed.
     *
     * @param \mindstellar\search\SearchCriteria $criteria
     * @param array<int,array<string,mixed>>     $items
     *
     * @return void
     */
    private function markEmptyResult($criteria, array $items)
    {
        if (count($items) !== 0) {
            return;
        }
        // A pattern, price range, custom-field facet or photo/premium filter with no match
        // is thin and unbounded, so it is not indexed. A valid category or location with no
        // listings yet is a stable URL: keep it 200, noindexed while empty.
        $p_sPriceMin     = $criteria->priceMin();
        $p_sPriceMax     = $criteria->priceMax();
        $metaFacets      = Params::getParam('meta');
        $isRefinedSearch = ($criteria->pattern() !== '')
            || ($p_sPriceMin !== '' && $p_sPriceMin !== null)
            || ($p_sPriceMax !== '' && $p_sPriceMax !== null)
            || (is_array($metaFacets) && count($metaFacets) > 0)
            || $criteria->withPicture()
            || $criteria->onlyPremium();

        if ($isRefinedSearch) {
            header('HTTP/1.1 404 Not Found');
        } else {
            $this->_exportVariableToView('meta_noindex', true);
            // noindex and a canonical on the same URL contradict each other.
            $this->_exportVariableToView('canonical', '');
        }
    }

    /**
     * The rss feed, or a plugin's feed through its feed_<name> hook.
     *
     * @param mixed                          $p_sFeed the sFeed value
     * @param array<int,array<string,mixed>> $aItems
     *
     * @return void
     */
    private function renderFeed($p_sFeed, array $aItems)
    {
        if ($p_sFeed == '' || $p_sFeed === 'rss') {
            header('Content-type: text/xml; charset=utf-8');

            $feed = new RSSFeed();
            $feed->setTitle(__('Latest listings added') . ' - ' . osc_page_title());
            $feed->setLink(osc_base_url());
            $feed->setDescription(__('Latest listings added in') . ' ' . osc_page_title());

            if (osc_count_items() > 0) {
                while (osc_has_items()) {
                    // Raw values: RSSFeed handles all XML/HTML escaping.
                    $itemArray = array(
                        'title'       => osc_item_title(),
                        'link'        => osc_item_url(),
                        'description' => osc_item_description(),
                        'country'     => osc_item_country(),
                        'region'      => osc_item_region(),
                        'city'        => osc_item_city(),
                        'city_area'   => osc_item_city_area(),
                        'category'    => osc_item_category(),
                        'dt_pub_date' => osc_item_pub_date()
                    );

                    if (osc_count_item_resources() > 0) {
                        osc_has_item_resources();

                        // Thumbnail rendered into the description (legacy behaviour).
                        $itemArray['image'] = array(
                            'url'   => osc_resource_thumbnail_url(),
                            'title' => osc_item_title(),
                            'link'  => osc_item_url()
                        );

                        // RSS enclosure for the first resource. No size is stored, so length is 0.
                        $itemArray['enclosure'] = array(
                            'url'    => osc_resource_url(),
                            'type'   => osc_resource_type(),
                            'length' => 0
                        );
                    }

                    // A plugin can adjust or drop feed entries.
                    $itemArray = osc_apply_filter('rss_feed_item', $itemArray, osc_item());

                    $feed->addItem($itemArray);
                }
            }

            osc_run_hook('feed', $feed);
            $feed->dumpXML();
        } else {
            osc_run_hook('feed_' . $p_sFeed, $aItems);
        }
    }

    /**
     * Render the results page; a theme may ship search-<category-slug>.php for one category.
     *
     * @param array<int,mixed> $categories
     *
     * @return void
     */
    private function renderPage(array $categories)
    {
        // Public search / category results: cacheable for anonymous visitors.
        osc_mark_response_cacheable();

        // The token comes from the request as it stands: resolving it to a row would cost a
        // query on every search, and a file that does not exist is not worth one.
        $viewCandidates = array();
        if (count($categories) === 1) {
            $viewCategory = reset($categories);
            if (is_string($viewCategory)) {
                $segments     = explode('/', trim($viewCategory, '/'));
                $viewCategory = end($segments);
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $viewCategory)) {
                    $viewCandidates[] = 'search-' . $viewCategory . '.php';
                }
            }
        }
        $viewCandidates[] = 'search.php';

        $this->doView(osc_locate_template($viewCandidates, 'search'));
    }

    /**
     * The URI resolver, wired to the location and category models.
     *
     * @return \mindstellar\search\SearchUriResolver
     */
    private function uriResolver()
    {
        return new \mindstellar\search\SearchUriResolver(
            (string)osc_get_preference('rewrite_search_url'),
            osc_base_url(),
            static fn ($id) => Region::getInstance()->findByPrimaryKey($id),
            static fn ($id) => City::getInstance()->findByPrimaryKey($id),
            static fn ($value) => self::findCategory($value),
            static fn ($slug) => Category::getInstance()->findBySlug($slug),
            fn ($slug) => $this->categorySlugRedirectUrl($slug)
        );
    }

    /**
     * Decode the friendly search params ("/region,7/pattern,bike") into request params.
     *
     * @return void
     */
    private function applyFriendlyParams()
    {
        $names = array();
        foreach (array(
            'rewrite_search_country'   => 'sCountry',
            'rewrite_search_region'    => 'sRegion',
            'rewrite_search_city'      => 'sCity',
            'rewrite_search_city_area' => 'sCityArea',
            'rewrite_search_category'  => 'sCategory',
            'rewrite_search_user'      => 'sUser',
            'rewrite_search_pattern'   => 'sPattern',
        ) as $preference => $param) {
            $names[] = array(osc_get_preference($preference), $param);
        }
        $ops = \mindstellar\search\SearchUriResolver::decodeFriendlyParams(
            '/' . Params::getParam('sParams', false, false),
            $names,
            Params::getParam('meta', false, false, false),
            static fn ($value) => Params::purifyText($value)
        );
        foreach ($ops as $op) {
            Params::setParam($op[0], $op[1]);
        }
        if ($ops !== array()) {
            Params::unsetParam('sParams');
        }
    }

    //hopefully generic...

    /**
     * Renders the given theme template between the `before_html` and `after_html` hooks.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_html');
    }

    /**
     * The alert form without JavaScript: save the search, say how it went, and go back.
     *
     * @return void
     */
    private function saveAlert(): void
    {
        $code = osc_subscribe_alert(Params::getParamString('alert'), Params::getParamString('alert_email'));
        if ($code === 1) {
            osc_add_flash_ok_message(osc_is_web_user_logged_in()
                ? _m('You are subscribed to this search.')
                : _m('Check your email to confirm the alert.'));
        } else {
            $messages = array(
                -1 => _m('Enter a valid email address.'),
                -2 => _m('This search could not be saved. Search again and try once more.'),
                -4 => _m('Sign in to save a search.'),
                -5 => _m('Too many alerts were saved from here. Please try again later.'),
            );
            osc_add_flash_error_message($messages[$code] ?? _m('This search could not be saved.'));
        }
        // Back to the search it came from; anything off-site goes to the search page.
        $this->redirectTo(osc_local_referer(osc_search_url()));
    }

    /**
     * Resolve an sCategory value, which may be either a slug or an id.
     *
     * Slug first, because that is what a friendly URL carries. An id is just as
     * legitimate — osc_search_url() emits ids and a category <select> submits
     * them — and resolving only by slug 404s a category that plainly exists.
     *
     * @param string $value
     *
     * @return array<string,mixed> The category row, or an empty array when there is no such category
     */
    public static function findCategory($value)
    {
        $category = Category::getInstance()->findBySlug($value);
        if (empty($category) && is_numeric($value)) {
            $byId = Category::getInstance()->findByPrimaryKey($value);
            if (!empty($byId)) {
                return $byId;
            }
        }

        return is_array($category) ? $category : array();
    }

    /**
     * The params that identify the result set a listing-index page is about,
     * from the ones the request happened to carry.
     *
     * What survives is what makes two URLs different pages: the category, the
     * place, a typed query, a seller. What is dropped either re-orders or
     * narrows the same set — paging, sort, and the price / photo / premium /
     * custom-field facets, each of which multiplies into its own crawlable URL
     * that would otherwise self-canonicalise as if it were a page of its own.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public static function canonicalParams(array $params)
    {
        // Each page of results is its own page to index, so a page after the first keeps its
        // number; pointing every page at page 1 hides the listings only deeper pages show.
        $page = isset($params['iPage']) && is_numeric($params['iPage']) ? (int) $params['iPage'] : 0;
        $drop = array(
            // routing
            'page', 'action', 'sParams', 'sFeed',
            // same set, different order or size
            'iPage', 'iPagesize', 'sOrder', 'iOrderType', 'sShowAs',
            // same set, narrowed
            'sPriceMin', 'sPriceMax', 'meta', 'bPic', 'bPremium',
        );
        foreach ($drop as $key) {
            unset($params[$key]);
        }
        if ($page > 1) {
            $params['iPage'] = $page;
        }

        return $params;
    }

    /**
     * The current URL of a category whose former slug is $slug, or null when there is no
     * history row or the category is gone/disabled (the caller then 404s).
     *
     * @param string $slug
     *
     * @return string|null
     */
    private function categorySlugRedirectUrl($slug)
    {
        $slug = trim((string)$slug);
        if ($slug === '') {
            return null;
        }
        try {
            $rows = osc_db_select(
                'SELECT fk_i_category_id FROM ' . DB_TABLE_PREFIX . 't_category_slug_history'
                . ' WHERE s_slug = ? ORDER BY dt_date DESC LIMIT 1',
                array($slug)
            );
        } catch (\mindstellar\database\DbException $e) {
            return null;
        }
        if (count($rows) === 0) {
            return null;
        }
        $category = Category::getInstance()->findByPrimaryKey((int)$rows[0]['fk_i_category_id']);
        if (!$category || (int)$category['b_enabled'] === 0) {
            return null; // deleted/disabled -> let the caller 404
        }
        $currentSlug = $category['s_slug'];
        if ($currentSlug === '' || $currentSlug === $slug) {
            return null; // loop guard
        }
        return osc_search_url(array('sCategory' => $currentSlug));
    }
}
