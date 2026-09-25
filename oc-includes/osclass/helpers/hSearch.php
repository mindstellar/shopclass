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
 * Helper Search
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets search object
 *
 * @return Search
 */
function osc_search()
{
    if (View::newInstance()->_exists('search')) {
        return View::newInstance()->_get('search');
    }

    $search = new Search();
    View::newInstance()->_exportVariableToView('search', $search);

    return $search;
}

/**
 * Gets available search orders
 *
 * @return array<string,array{sOrder:string,iOrderType:string}>
 */
function osc_list_orders()
{
    if (osc_search_pattern() !== '') {
        $list_order[__('Relevance')] = ['sOrder' => 'relevance', 'iOrderType' => 'desc'];
    }

    $list_order[__('Newly listed')] = ['sOrder' => 'dt_pub_date', 'iOrderType' => 'desc'];

    if (osc_price_enabled_at_items()) {
        $list_order[__('Lower price first')] = ['sOrder' => 'i_price', 'iOrderType' => 'asc'];

        $list_order[__('Higher price first')] = ['sOrder' => 'i_price', 'iOrderType' => 'desc'];
    }

    return $list_order;
}

/**
 * Gets current search page
 *
 * @return bool
 */
function osc_search_alert_subscribed()
{
    return View::newInstance()->_get('search_alert_subscribed') == 1;
}

/**
 * Gets current search page
 *
 * @return int
 */
function osc_search_page()
{
    return View::newInstance()->_get('search_page');
}

/**
 * Gets total pages of search
 *
 * @return int
 */
function osc_search_total_pages()
{
    return View::newInstance()->_get('search_total_pages');
}

/**
 * Gets if "has pic" option is enabled or not in the search
 *
 * @return boolean
 */
function osc_search_has_pic()
{
    return View::newInstance()->_get('search_has_pic');
}

/**
 * Gets if "only premium" option is enabled or not in the search
 *
 * @return boolean
 */
function osc_search_only_premium()
{
    return View::newInstance()->_get('search_only_premium');
}

/**
 * Gets current search order
 *
 * @return string
 */
function osc_search_order()
{
    return View::newInstance()->_get('search_order');
}

/**
 * Gets current search order type
 *
 * @return string
 */
function osc_search_order_type()
{
    return View::newInstance()->_get('search_order_type');
}

/**
 * Gets current search pattern
 *
 * @return string
 */
function osc_search_pattern()
{
    if (View::newInstance()->_exists('search_pattern')) {
        return View::newInstance()->_get('search_pattern');
    }

    return '';
}

/**
 * Gets current search country
 *
 * @return string
 */
function osc_search_country()
{
    return View::newInstance()->_get('search_country');
}

/**
 * Gets current search region
 *
 * @return string
 */
function osc_search_region()
{
    return View::newInstance()->_get('search_region');
}

/**
 * Gets current search city
 *
 * @return string
 */
function osc_search_city()
{
    return View::newInstance()->_get('search_city');
}

/**
 * Gets current search users
 *
 * @return array<int,string>
 */
function osc_search_user()
{
    if (is_array(View::newInstance()->_get('search_from_user'))) {
        return View::newInstance()->_get('search_from_user');
    }

    return array();
}

/**
 * Gets current search max price
 *
 * @return float
 */
function osc_search_price_max()
{
    return View::newInstance()->_get('search_price_max');
}

/**
 * Gets current search min price
 *
 * @return float
 */
function osc_search_price_min()
{
    return View::newInstance()->_get('search_price_min');
}

/**
 * Gets current search total items
 *
 * @return int
 */
function osc_search_total_items()
{
    return View::newInstance()->_get('search_total_items');
}

/**
 * Gets current search "show as" variable (show the items as a list or as a gallery)
 *
 * @return string
 */
function osc_search_show_as()
{
    return View::newInstance()->_get('search_show_as');
}

/**
 * Gets current search start item record
 *
 * @return int
 */
function osc_search_start()
{
    return View::newInstance()->_get('search_start');
}

/**
 * Gets current search end item record
 *
 * @return int
 */
function osc_search_end()
{
    return View::newInstance()->_get('search_end');
}

/**
 * Gets current search category
 *
 * @return array<int|string,mixed>
 */
function osc_search_category()
{
    if (View::newInstance()->_exists('search_subcategories')) {
        $category = View::newInstance()->_current('search_subcategories');
    } elseif (View::newInstance()->_exists('search_categories')) {
        $category = View::newInstance()->_current('search_categories');
    } else {
        $category = View::newInstance()->_get('search_category');
    }
    if (!is_array($category)) {
        $category = array();
    }

    return $category;
}

/**
 * Gets current search category id
 *
 * @return int[]
 */
function osc_search_category_id()
{
    $categories = osc_search_category();
    $category   = array();
    $mCat       = Category::newInstance();

    foreach ($categories as $cat) {
        if (is_numeric($cat)) {
            $tmp = $mCat->findByPrimaryKey($cat);
            if (isset($tmp['pk_i_id'])) {
                $category[] = $tmp['pk_i_id'];
            }
        } else {
            $slug_cat = explode('/', trim($cat, '/'));
            $tmp      = $mCat->findBySlug($slug_cat[count($slug_cat) - 1]);
            if (isset($tmp['pk_i_id'])) {
                $category[] = $tmp['pk_i_id'];
            }
        }
    }

    return $category;
}

/**
 * Name of the category the current search is filtered to. Takes the first of a
 * multi-category search, so it always agrees with
 * {@see osc_search_category_description()}.
 *
 * @param string $locale
 *
 * @return string
 */
function osc_search_category_name($locale = '')
{
    $a_search_category_id = osc_search_category_id();
    $text                 = '';
    if (!empty($a_search_category_id)) {
        list($search_category_id) = $a_search_category_id;
        if (is_numeric($search_category_id)) {
            $tmp = Category::newInstance()->findByPrimaryKey($search_category_id, $locale);
            if (isset($tmp['s_name'])) {
                $text = $tmp['s_name'];
            }
        }
    }

    return $text;
}

/**
 * Description of the category the current search is filtered to. Takes the first of a
 * multi-category search, so it always agrees with {@see osc_search_category_name()}.
 *
 * @param string $locale
 *
 * @return string
 */
function osc_search_category_description($locale = '')
{
    $a_search_category_id = osc_search_category_id();
    $text              = '';
    if (!empty($a_search_category_id)) {
        list($search_category_id) = $a_search_category_id;
        if (is_numeric($search_category_id)) {
            $mCat = Category::newInstance();
            $tmp = $mCat->findByPrimaryKey($search_category_id, $locale);
            if (isset($tmp['s_description'])) {
                $text = $tmp['s_description'];
            }
        }
    }

    return $text;
}

/**
 * Update the search url with new options
 *
 * @param array $params
 * @param bool  $forced
 *
 * @return string
 */
function osc_update_search_url($params = array(), $forced = false)
{
    $request = Params::getParamsAsArray();
    unset($request['osclass']);
    if (isset($request['sCategory[0]'])) {
        unset($request['sCategory']);
    }
    unset($request['sCategory[]']);
    if (isset($request['sUser[0]'])) {
        unset($request['sUser']);
    }
    unset($request['sUser[]']);
    if (!$forced && View::newInstance()->_get('subdomain_slug') != '') {
        $subdomain_type = osc_subdomain_type();
        if ($subdomain_type === 'category') {
            unset($request['sCategory']);
        } elseif ($subdomain_type === 'country') {
            unset($request['sCountry']);
        } elseif ($subdomain_type === 'region') {
            unset($request['sCountry'], $request['sRegion']);
        } elseif ($subdomain_type === 'city') {
            unset($request['sCountry'], $request['sRegion'], $request['sCity']);
        } elseif ($subdomain_type === 'user') {
            unset($request['sUser']);
        }
    }
    $merged = array_merge($request, $params);

    return osc_search_url($merged);
}

/**
 * Load the form for the alert subscription
 *
 * @return void
 */
function osc_alert_form()
{
    // One field on a core-owned contract. A theme that ships the view still owns
    // it; one that does not gets core's rather than nothing, which is what the
    // walk used to leave behind.
    if (file_exists(WebThemes::newInstance()->getCurrentThemePath() . 'alert-form.php')) {
        osc_current_web_theme_path('alert-form.php');

        return;
    }

    require ABS_PATH . 'oc-includes/osclass/gui/alert-form-content.php';
}

/**
 * Gets alert of current search
 *
 * @return string
 */
function osc_search_alert()
{
    return View::newInstance()->_get('search_alert');
}

/**
 * Gets for a default search (all categories, noother option)
 *
 * @param array $params
 *
 * @return string
 */
function osc_search_show_all_url($params = array())
{
    $params['page'] = 'search';

    return osc_update_search_url($params);
}

/**
 * Gets search url given params
 *
 * @param array<string,mixed>|null $params
 *
 * @return string
 */
function osc_search_url($params = null)
{
    if (is_array($params)) {
        osc_prune_array($params);
    }
    $countP = is_array($params) ? count($params) : 0;
    if ($countP == 0) {
        $params['page'] = 'search';
    }
    $base_url = osc_base_url();
    $http_url = osc_is_ssl() ? 'https://' : 'http://';
    if (!empty($params['sPattern'])) {
        $params['sPattern'] = osc_apply_filter('search_pattern', $params['sPattern']);
    }
    // One subdomain mode can be active at a time, so the five that used to be written
    // out one after another are one lookup and one body.
    $sub = _aux_search_subdomains()[osc_subdomain_type()] ?? null;
    if ($sub !== null && isset($params[$sub['param']])) {
        $key = $sub['param'];
        if ($params[$key] != Params::getParam($key)) {
            if (is_array($params[$key])) {
                $params[$key] = implode(',', $params[$key]);
            }
            if ($params[$key] != '' && strpos($params[$key], ',') === false) {
                $row = _aux_search_find($sub, $params[$key]);
                if (isset($row[$sub['label']])) {
                    $base_url =
                        $http_url . $row[$sub['label']] . '.' . osc_subdomain_host() . REL_WEB_URL;
                    unset($params[$key]);
                }
            }
        } elseif (osc_is_subdomain()) {
            unset($params[$key]);
        }
    }

    $countP = count($params);
    if ($countP == 0) {
        return $base_url;
    }
    unset($params['page']);
    $countP = count($params);

    if (osc_rewrite_enabled()) {
        foreach ($params as $kp => $vp) {
            $params[$kp] = osc_remove_slash($vp);
        }
        $url = $base_url . osc_get_preference('rewrite_search_url');
        // CANONICAL URLS
        if (isset($params['sCategory']) && !is_array($params['sCategory'])
            && strpos($params['sCategory'], ',') === false
            && ($countP == 1 || ($countP == 2 && isset($params['iPage'])))
        ) {
            if (osc_category_id() == $params['sCategory']) {
                $category['pk_i_id'] = osc_category_id();
                $category['s_slug']  = osc_category_slug();
            } elseif (is_numeric($params['sCategory'])) {
                $category = Category::newInstance()->findByPrimaryKey($params['sCategory']);
            } else {
                $category = Category::newInstance()->findBySlug($params['sCategory']);
            }
            if (isset($category['pk_i_id'])) {
                $values = array(
                    'CATEGORY_NAME' => $category['s_slug'],
                    'CATEGORY_SLUG' => $category['s_slug'], // the older spelling, still built
                    'CATEGORY_ID'   => $category['pk_i_id'],
                );
                if (stripos((string)osc_get_preference('rewrite_cat_url'), '{CATEGORIES}') !== false) {
                    $values['CATEGORIES'] =
                        \mindstellar\routing\CoreRoutes::categoryPath($category['pk_i_id']);
                }
                $seo_prefix = '';
                if (osc_get_preference('seo_url_search_prefix') != '') {
                    $seo_prefix = osc_get_preference('seo_url_search_prefix') . '/';
                }
                $url = \mindstellar\routing\CoreRoutes::expand('category', $values);
            } else {
                // Search by a category which does not exists (by form)
                // TODO CHANGE TO NEW ROUTES!!
                return $base_url . 'index.php?page=search&sCategory='
                    . urlencode($params['sCategory']);
            }
            if (isset($params['iPage']) && $params['iPage'] != '' && $params['iPage'] != 1) {
                $url .= '/' . $params['iPage'];
            }
            $url = $base_url . $seo_prefix . $url;
        } elseif (_aux_search_place_wanted($params, 'sRegion', $countP)
            || _aux_search_place_wanted($params, 'sCity', $countP)
        ) {
            $key   = _aux_search_place_wanted($params, 'sRegion', $countP) ? 'sRegion' : 'sCity';
            $place = _aux_search_place_url($params, _aux_search_places()[$key], $base_url);
            if ($place['stop']) {
                return $place['url'];
            }
            $url = $place['url'];
        } elseif ($params != null && is_array($params)) {
            $names = _aux_search_param_names();
            foreach ($params as $k => $v) {
                if ($k === 'meta') {
                    $url .= _aux_search_meta_path($v);
                    continue;
                }
                if (is_array($v)) {
                    // Category and seller are the only filters that take several
                    // values; every other array is skipped, as it always has been.
                    if ($k !== 'sCategory' && $k !== 'sUser') {
                        continue;
                    }
                    $v = implode(',', $v);
                }
                if (isset($names[$k])) {
                    $k = osc_get_preference($names[$k]);
                }
                if ($v != '') {
                    $url .= '/' . $k . ',' . urlencode($v);
                }
            }
        }
    } else {
        $url = $base_url . 'index.php?page=search';
        if ($params != null && is_array($params)) {
            foreach ($params as $k => $v) {
                if ($k === 'meta' || strncmp($k, 'meta[', 5) === 0) {
                    if (is_array($v)) {
                        foreach ($v as $_k => $aux) {
                            if (is_array($aux)) {
                                foreach (array_keys($aux) as $aux_k) {
                                    $url .= "&meta[$_k][$aux_k]=" . urlencode($aux[$aux_k]);
                                }
                            } else {
                                $url .= '&meta[' . $_k . ']=' . urlencode($aux);
                            }
                        }
                    }
                } else {
                    if (is_array($v)) {
                        $v = implode(',', $v);
                    }
                    $url .= '&' . $k . '=' . urlencode($v);
                }
            }
        }
    }

    return str_replace('%2C', ',', $url);
}

/**
 * Replace every slash in a value, or in each value of an array, with a space.
 *
 * @param array|string $var
 *
 * @return array|string
 */
function osc_remove_slash($var)
{
    if (is_array($var)) {
        foreach ($var as $k => $v) {
            $var[$k] = osc_remove_slash($v);
        }
    } else {
        $var = str_ireplace('/', ' ', $var);
    }

    return $var;
}

/**
 * Gets list of countries with items
 *
 * @return array<string,mixed>|null Null when no country list has been loaded
 */
function osc_list_country()
{
    if (View::newInstance()->_exists('list_countries')) {
        return View::newInstance()->_current('list_countries');
    }

    return null;
}

/**
 * Gets list of regions with items
 *
 * @return array<string,mixed>|null Null when no region list has been loaded
 */
function osc_list_region()
{
    if (View::newInstance()->_exists('list_regions')) {
        return View::newInstance()->_current('list_regions');
    }

    return null;
}

/**
 * Gets list of cities with items
 *
 * @return array<string,mixed>|null Null when no city list has been loaded
 */
function osc_list_city()
{
    if (View::newInstance()->_exists('list_cities')) {
        return View::newInstance()->_current('list_cities');
    }

    return null;
}

/**
 * Load one place list into the view, unless something already did.
 *
 * @param string $key    Exported name: list_countries, list_regions or list_cities
 * @param string $filter The country or region to narrow by, where that applies
 *
 * @return void
 */
function _aux_search_load_list($key, $filter = '%%%%')
{
    if (View::newInstance()->_exists($key)) {
        return;
    }
    $loaders = array(
        'list_countries' => static function () {
            return CountryStats::newInstance()->listCountries();
        },
        'list_regions'   => static function () use ($filter) {
            return RegionStats::newInstance()->listRegions($filter);
        },
        'list_cities'    => static function () use ($filter) {
            return CityStats::newInstance()->listCities($filter);
        },
    );
    View::newInstance()->_exportVariableToView($key, $loaders[$key]());
}

/**
 * Step one place along, resetting the walk once the list runs out so the next loop
 * over the same list starts again from the top.
 *
 * @param string $key
 *
 * @return bool False once the list is exhausted
 */
function _aux_search_walk_list($key)
{
    $more = View::newInstance()->_next($key);
    if (!$more) {
        View::newInstance()->_reset($key);
    }

    return $more;
}

/**
 * Gets the next country in the list_countries list
 *
 * @return bool False once the list is exhausted
 */
function osc_has_list_countries()
{
    _aux_search_load_list('list_countries');

    return _aux_search_walk_list('list_countries');
}

/**
 * Gets the next region in the list_regions list
 *
 * @param string $country
 *
 * @return bool False once the list is exhausted
 */
function osc_has_list_regions($country = '%%%%')
{
    _aux_search_load_list('list_regions', $country);

    return _aux_search_walk_list('list_regions');
}

/**
 * Gets the next city in the list_cities list
 *
 * @param string $region
 *
 * @return bool False once the list is exhausted
 */
function osc_has_list_cities($region = '%%%%')
{
    _aux_search_load_list('list_cities', $region);

    return _aux_search_walk_list('list_cities');
}

/**
 * Gets the total number of countries in list_countries
 *
 * @return int
 */
function osc_count_list_countries()
{
    _aux_search_load_list('list_countries');

    return View::newInstance()->_count('list_countries');
}

/**
 * Gets the total number of regions in list_regions
 *
 * @param string $country
 *
 * @return int
 */
function osc_count_list_regions($country = '%%%%')
{
    _aux_search_load_list('list_regions', $country);

    return View::newInstance()->_count('list_regions');
}

/**
 * Gets the total number of cities in list_cities
 *
 * @param string $region
 *
 * @return int
 */
function osc_count_list_cities($region = '%%%%')
{
    _aux_search_load_list('list_cities', $region);

    return View::newInstance()->_count('list_cities');
}

/**
 * Gets the name of current "list country"
 *
 * @return string
 */
function osc_list_country_name()
{
    return osc_field(osc_list_country(), 'country_name', '');
}

/**
 * Gets the number of items of current "list country"
 *
 * @return string
 */
function osc_list_country_code()
{
    return osc_field(osc_list_country(), 'country_code', '');
}

/**
 * Gets the number of items of current "list country"
 *
 * @return int|string
 */
function osc_list_country_items()
{
    return osc_field(osc_list_country(), 'items', '');
}

/**
 * Gets the url of current "list country"
 *
 * @return string
 */
function osc_list_country_url()
{
    return osc_search_url(array('sCountry' => osc_list_country_code()));
}

// region attributes
/**
 * Gets the name of current "list region" by name
 *
 * @return string
 */
function osc_list_region_name()
{
    return osc_field(osc_list_region(), 'region_name', '');
}

/**
 * Gets the slug of current "list region"
 *
 * @return string
 */
function osc_list_region_slug()
{
    return osc_field(osc_list_region(), 'region_slug', '');
}

/**
 * Gets the ID of current "list region"
 *
 * @return string
 */
function osc_list_region_id()
{
    return osc_field(osc_list_region(), 'region_id', '');
}

/**
 * Gets the number of items of current "list region"
 *
 * @return int|string
 */
function osc_list_region_items()
{
    return osc_field(osc_list_region(), 'items', '');
}

/**
 * Gets the url of current "list region"
 *
 * @return string
 */
function osc_list_region_url()
{
    return osc_search_url(array('sRegion' => osc_list_region_id()));
}

// city attributes
/**
 * Gets the name of current "list city" by name
 *
 * @return string
 */
function osc_list_city_name()
{
    return osc_field(osc_list_city(), 'city_name', '');
}

/**
 * Gets the list of current "list city" by slug
 *
 * @return string
 */
function osc_list_city_slug()
{
    return osc_field(osc_list_city(), 'city_slug', '');
}

/**
 * Gets the ID of current "list city"
 *
 * @return string
 */
function osc_list_city_id()
{
    return osc_field(osc_list_city(), 'city_id', '');
}

/**
 * Gets the number of items of current "list city"
 *
 * @return int|string
 */
function osc_list_city_items()
{
    return osc_field(osc_list_city(), 'items', '');
}

/**
 * Gets the url of current "list city"
 *
 * @return string
 */
function osc_list_city_url()
{
    return osc_search_url(array('sCity' => osc_list_city_id()));
}

/**********************
 ** LATEST SEARCHES **
 **********************/
/**
 * Gets the latest searches done in the website
 *
 * @param int $limit
 *
 * @return array<int,array<string,mixed>>
 */
function osc_get_latest_searches($limit = 20)
{
    if (!View::newInstance()->_exists('latest_searches')) {
        View::newInstance()->_exportVariableToView(
            'latest_searches',
            LatestSearches::newInstance()->getSearches($limit)
        );
    }

    return View::newInstance()->_get('latest_searches');
}

/**
 * Gets the total number of latest searches done in the website
 *
 * @return int
 */
function osc_count_latest_searches()
{
    if (!View::newInstance()->_exists('latest_searches')) {
        View::newInstance()->_exportVariableToView(
            'latest_searches',
            LatestSearches::newInstance()->getSearches()
        );
    }

    return View::newInstance()->_count('latest_searches');
}

/**
 * Gets the next latest search
 *
 * @return bool False once the list is exhausted
 */
function osc_has_latest_searches()
{
    if (!View::newInstance()->_exists('latest_searches')) {
        View::newInstance()->_exportVariableToView(
            'latest_searches',
            LatestSearches::newInstance()->getSearches()
        );
    }

    return View::newInstance()->_next('latest_searches');
}

/**
 * Gets the current latest search
 *
 * @return array<string,mixed>|null Null when no latest searches have been loaded
 */
function osc_latest_search()
{
    if (View::newInstance()->_exists('latest_searches')) {
        return View::newInstance()->_current('latest_searches');
    }

    return null;
}

/**
 * Gets the current latest search pattern
 *
 * @return string
 */
function osc_latest_search_text()
{
    return osc_field(osc_latest_search(), 's_search', '');
}

/**
 * Gets the current latest search date
 *
 * @return string
 */
function osc_latest_search_date()
{
    return osc_field(osc_latest_search(), 'd_date', '');
}

/**
 * Gets the current latest search total
 *
 * @return string
 */
function osc_latest_search_total()
{
    return osc_field(osc_latest_search(), 'i_total', '');
}

/**
 * The canonical URL exported for the current page.
 *
 * @return string Empty string when none was set
 */
function osc_get_canonical()
{
    if (View::newInstance()->_exists('canonical')) {
        return View::newInstance()->_get('canonical');
    }

    return '';
}

/**
 * Strip a search condition set down to the filters a visitor actually chose, with
 * category ids resolved to names. Takes a decoded t_alerts.s_search; for an alert stored
 * as search values the result also carries `params`, the stored values, and for a held
 * alert it is only `held`, the reason.
 *
 * @param array<string,mixed> $conditions
 *
 * @return array<string,mixed>
 */
function osc_get_raw_search($conditions)
{
    if (\mindstellar\search\AlertEnvelope::isEnvelope($conditions)) {
        $params = \mindstellar\search\AlertEnvelope::validateDecoded($conditions);
        if ($params === null) {
            // A held alert: no search, only the reason it was held.
            return isset($conditions['held']) && is_string($conditions['held'])
                ? array('held' => $conditions['held'])
                : array();
        }
        $raw = array_filter(
            \mindstellar\search\AlertEnvelope::legacyFields($params),
            static fn ($v) => $v !== '' && $v !== array() && $v !== 0
        );
        if (isset($raw['aCategories'])) {
            $mCategory = Category::newInstance();
            foreach ($raw['aCategories'] as $k => $id) {
                $raw['aCategories'][$k] = $mCategory->findNameByPrimaryKey($id);
            }
        }
        $raw['params'] = $params;

        return $raw;
    }

    $keys      = array('aCategories', 'countries', 'regions', 'cities', 'city_areas');
    $mCategory = Category::newInstance();
    foreach ($keys as $key) {
        if (isset($conditions[$key]) && is_array($conditions[$key]) && !empty($conditions[$key])) {
            foreach ($conditions[$key] as $k => $v) {
                // A stored row is not trusted to hold only strings here.
                if (!is_string($v) && !is_int($v)) {
                    unset($conditions[$key][$k]);
                    continue;
                }
                if (preg_match('|([0-9]+)|', (string)$v, $match)) {
                    if ($key === 'aCategories') {
                        $conditions[$key][$k] = $mCategory->findNameByPrimaryKey($match[1]);
                    } else {
                        $conditions[$key][$k] = $match[1];
                    }
                }
            }
            $conditions[$key] = array_values($conditions[$key]);
        } else {
            unset($conditions[$key]);
        }
    }

    if (!isset($conditions['price_min']) || $conditions['price_min'] == 0) {
        unset($conditions['price_min']);
    }

    if (!isset($conditions['price_max']) || $conditions['price_max'] == 0) {
        unset($conditions['price_max']);
    }

    if (!isset($conditions['sPattern']) || !is_scalar($conditions['sPattern']) || $conditions['sPattern'] == '') {
        unset($conditions['sPattern']);
    }

    unset(
        $conditions['withPattern'],
        $conditions['tables'],
        $conditions['tables_join'],
        $conditions['no_catched_tables'],
        $conditions['no_catched_conditions'],
        $conditions['user_ids'],
        $conditions['order_column'],
        $conditions['order_direction'],
        $conditions['limit_init'],
        $conditions['results_per_page']
    );

    return $conditions;
}

/**
 * How each subdomain mode names its filter and where it looks the value up.
 *
 * @return array<string,array<string,string>> Keyed by osc_subdomain_type()
 */
function _aux_search_subdomains()
{
    return array(
        'category' => array('param' => 'sCategory', 'model' => 'Category',
            'finder' => 'findBySlug', 'label' => 's_slug'),
        'country'  => array('param' => 'sCountry', 'model' => 'Country',
            'finder' => 'findByCode', 'label' => 's_slug'),
        'region'   => array('param' => 'sRegion', 'model' => 'Region',
            'finder' => 'findByName', 'label' => 's_slug'),
        'city'     => array('param' => 'sCity', 'model' => 'City',
            'finder' => 'findByName', 'label' => 's_slug'),
        'user'     => array('param' => 'sUser', 'model' => 'User',
            'finder' => 'findByUsername', 'label' => 's_username'),
    );
}

/**
 * Look one filter value up: by id when it is numeric, otherwise by the spec's finder.
 *
 * @param array<string,string> $spec  A row of _aux_search_subdomains() or _aux_search_places()
 * @param mixed                $value
 *
 * @return array<string,mixed>|null
 */
function _aux_search_find(array $spec, $value)
{
    $model = call_user_func(array($spec['model'], 'newInstance'));

    return is_numeric($value)
        ? $model->findByPrimaryKey($value)
        : $model->{$spec['finder']}($value);
}

/**
 * The two places that get a canonical URL of their own, and how to build one.
 *
 * mark is the letter the id is written behind: /gujarat-r7, /surat-c11.
 *
 * @return array<string,array<string,string>> Keyed by the search parameter
 */
function _aux_search_places()
{
    return array(
        'sRegion' => array('param' => 'sRegion', 'model' => 'Region', 'finder' => 'findByName',
            'mark' => '-r', 'currentId' => 'osc_list_region_id', 'currentSlug' => 'osc_list_region_slug'),
        'sCity'   => array('param' => 'sCity', 'model' => 'City', 'finder' => 'findByName',
            'mark' => '-c', 'currentId' => 'osc_list_city_id', 'currentSlug' => 'osc_list_city_slug'),
    );
}

/**
 * Whether this search is just one place, optionally inside one category and on one
 * page -- the only shape that earns a canonical URL rather than a query string.
 *
 * Every value here has been through osc_remove_slash(), which returns a string for
 * anything that is not an array, so "not an array" is the same test as "a string".
 *
 * @param array<string,mixed> $params
 * @param string              $key
 * @param int                 $countP
 *
 * @return bool
 */
function _aux_search_place_wanted(array $params, $key, $countP)
{
    if (!isset($params[$key]) || is_array($params[$key])
        || strpos($params[$key], ',') !== false
    ) {
        return false;
    }

    return $countP == 1
        || ($countP == 2 && (isset($params['iPage']) || isset($params['sCategory'])))
        || (isset($params['iPage'], $params['sCategory']) && $countP == 3);
}

/**
 * The canonical URL for one region or city.
 *
 * 'stop' says the value matched no row, in which case 'url' is the query-string form
 * the caller must hand straight back. That fallback is built on top of the prefix and
 * category already appended, which is how it has always behaved.
 *
 * @param array<string,mixed>  $params
 * @param array<string,string> $spec     A row of _aux_search_places()
 * @param string               $base_url
 *
 * @return array{url:string,stop:bool}
 */
function _aux_search_place_url(array $params, array $spec, $base_url)
{
    $url = $base_url;
    if (osc_get_preference('seo_url_search_prefix') != '') {
        $url .= osc_get_preference('seo_url_search_prefix') . '/';
    }
    if (isset($params['sCategory'])) {
        $categorySlug = _aux_search_category_slug($params['sCategory']);
        if ($categorySlug != '') {
            $url .= $categorySlug . '_';
        }
    }

    $key   = $spec['param'];
    $value = $params[$key];
    if (call_user_func($spec['currentId']) == $value) {
        $url .= osc_sanitizeString(call_user_func($spec['currentSlug'])) . $spec['mark']
            . call_user_func($spec['currentId']);
    } else {
        $row = _aux_search_find($spec, $value);
        if (!isset($row['s_slug'])) {
            // Searching a place that does not exist, usually straight off a form.
            return array(
                'url'  => $url . 'index.php?page=search&' . $key . '=' . urlencode($value),
                'stop' => true,
            );
        }
        $url .= osc_sanitizeString($row['s_slug']) . $spec['mark'] . $row['pk_i_id'];
    }

    if (isset($params['iPage']) && $params['iPage'] != '' && $params['iPage'] != 1) {
        $url .= '/' . $params['iPage'];
    }

    return array('url' => $url, 'stop' => false);
}

/**
 * The friendly-URL name each search parameter is written under. An admin renames
 * these on the permalinks screen, so they are read, not hardcoded.
 *
 * @return array<string,string> Parameter => preference holding its name
 */
function _aux_search_param_names()
{
    return array(
        'sCountry'  => 'rewrite_search_country',
        'sRegion'   => 'rewrite_search_region',
        'sCity'     => 'rewrite_search_city',
        'sCityArea' => 'rewrite_search_city_area',
        'sCategory' => 'rewrite_search_category',
        'sUser'     => 'rewrite_search_user',
        'sPattern'  => 'rewrite_search_pattern',
    );
}

/**
 * Custom-field filters as friendly path segments: /meta4,red and, for a range,
 * /meta4-min,1/meta4-max,9.
 *
 * @param mixed $meta
 *
 * @return string
 */
function _aux_search_meta_path($meta)
{
    if (!is_array($meta)) {
        return '';
    }
    $path = '';
    foreach ($meta as $field => $value) {
        if (is_array($value)) {
            foreach ($value as $part => $partValue) {
                if ($value != '') {
                    $path .= '/meta' . $field . '-' . $part . ',' . urlencode($partValue);
                }
            }
        } elseif ($value != '') {
            $path .= '/meta' . $field . ',' . urlencode($value);
        }
    }

    return $path;
}

/**
 * Slug of the category a search is filtered to.
 *
 * @param array<int,int|string>|int|string $paramCat
 *
 * @return string Empty string for a multi-category search or an unknown category
 */
function _aux_search_category_slug($paramCat)
{
    if (is_array($paramCat)) {
        if (count($paramCat) == 1) {
            $paramCat = $paramCat[0];
        } else {
            return '';
        }
    }

    if (osc_category_id() == $paramCat) {
        $category['s_slug'] = osc_category_slug();
    } elseif (is_numeric($paramCat)) {
        $category = Category::newInstance()->findByPrimaryKey($paramCat);
    } else {
        $category = Category::newInstance()->findBySlug($paramCat);
    }

    return isset($category['s_slug']) ? $category['s_slug'] : '';
}
