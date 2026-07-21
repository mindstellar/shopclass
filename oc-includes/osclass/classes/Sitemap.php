<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Hookable XML sitemap generator.
 *
 * Builds a sitemap index plus a set of child sitemaps (paginated item sitemaps,
 * category, optional category x region / category x city / cities / regions /
 * countries, and pages) as DOMDocument trees, each cached in the object cache
 * for {@see Sitemap::$cacheTTL} seconds. Every document carries an
 * xml-stylesheet processing instruction pointing at a core-served XSL so the
 * output is human-readable in a browser.
 *
 * The item enumeration goes through three filters so a search backend (e.g. a
 * Manticore plugin) can power the sitemap from its own index instead of the SQL
 * default:
 *
 *   - `sitemap_items_source` (array $items, array{page:int,per_page:int} $args)
 *     returns an ordered list of live listings for one page. Each row is an
 *     associative array with the keys the DOM builder consumes:
 *       pk_i_id, s_title, fk_i_category_id, s_city, dt_pub_date, dt_mod_date.
 *   - `sitemap_items_total` (int $count) is the live-listing count the index uses
 *     to work out how many item sitemaps to reference.
 *   - `sitemap_url_entry` (array{loc:string,lastmod:string,changefreq:string} $entry,
 *     string $type) lets a plugin adjust any single URL entry before it is written.
 *
 * Routing is core-native: {@see Rewrite} maps sitemap.xml / sitemapindex.xml /
 * sitemap-index.xml / sitemap/*.xml / sitemap/item-sitemap_s{N}.xml to
 * page=sitemap, and index.php calls {@see Sitemap::serve()}.
 */
class Sitemap extends DAO
{
    /** @var Sitemap */
    private static $instance;

    /** Object-cache TTL for every generated document, in seconds (6 hours). */
    public static $cacheTTL = 21600;

    /** The sitemaps.org per-file URL ceiling — also the safety cap on the location scans. */
    public const MAX_SITEMAP_URLS = 50000;

    /** Preference group (t_preference.s_section) that holds the sitemap settings. */
    public const PREF_GROUP = 'sitemap';

    /** Default number of item URLs per child sitemap when the preference is unset. */
    private const DEFAULT_ITEMS_PER_SITEMAP = 5000;

    /** @var DOMDocument The document currently being built. */
    private $dom;

    /** @var DOMElement The root element (urlset or sitemapindex). */
    private $root;

    /** @var int|null Memoised live-listing count. */
    private $total_results_table;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return Sitemap
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The canonical "is this listing live" predicate, as a single grouped SQL
     * condition. The parentheses are load-bearing: premium bypasses expiry and
     * ONLY expiry — never b_enabled / b_active / b_spam — so the OR must be
     * grouped, otherwise every premium listing (including blocked, deactivated
     * and spam-flagged ones) would leak into the public sitemap.
     *
     * This must stay in step with Search::__construct(), which builds the same
     * predicate for the on-site search: b_enabled = 1 AND b_active = 1 AND
     * b_spam = 0 AND (b_premium = 1 OR dt_expiration >= now). Search assembles it
     * from separate conditions with `||`; keep the two semantically identical.
     *
     * @param string $alias optional table alias the caller's query uses
     *
     * @return string
     */
    public static function liveItemCondition($alias = '')
    {
        $p = ($alias === '') ? '' : $alias . '.';

        return $p . 'b_enabled = 1'
            . ' AND ' . $p . 'b_active = 1'
            . ' AND ' . $p . 'b_spam = 0'
            . ' AND (' . $p . 'b_premium = 1'
            . ' OR ' . $p . "dt_expiration >= '" . date('Y-m-d H:i:s') . "')";
    }

    /**
     * Front-controller entry point. Reads the document requested by the router
     * (Params 'sitemap_doc' / 'sitemap_page') and streams the matching XML.
     * Always terminates the request.
     *
     * @return void
     */
    public function serve()
    {
        $doc = Params::getParam('sitemap_doc');

        switch ($doc) {
            case 'index':
                $this->generateIndex();
                break;
            case 'item':
                $page = (int) Params::getParam('sitemap_page');
                if ($page < 0 || $page > $this->lastItemPage()) {
                    $this->notFound();
                }
                $this->generateItemSitemap($page);
                break;
            case 'category':
                if (!$this->includeCategories()) {
                    $this->notFound();
                }
                $this->generateCategorySitemap();
                break;
            case 'pages':
                if (!$this->includePages()) {
                    $this->notFound();
                }
                $this->generatePageSitemap();
                break;
            case 'cities':
                $this->generateCitiesSitemap();
                break;
            case 'regions':
                $this->generateRegionsSitemap();
                break;
            case 'countries':
                $this->generateCountriesSitemap();
                break;
            case 'cat_regions':
                $this->generateCatRegionSitemap();
                break;
            case 'cat_cities':
                $this->generateCatCitySitemap();
                break;
            case 'robots':
                $this->serveRobots();
                break;
            default:
                $this->notFound();
        }

        if (class_exists('Session', false)) {
            Session::newInstance()->_clearVariables();
        }
        exit;
    }

    /* ------------------------------------------------------------------ *
     *  Document builders                                                  *
     * ------------------------------------------------------------------ */

    /**
     * Generate the sitemap index that references every child sitemap.
     *
     * @param bool $output whether to stream the result (false = warm cache only)
     *
     * @return string
     */
    public function generateIndex($output = true)
    {
        $xml = $this->render('sitemap_index_xml', true, function () {
            $perPage  = $this->itemsPerSitemap();
            $total    = (int) osc_apply_filter('sitemap_items_total', $this->countLiveItems());
            $numPages = ($perPage > 0) ? (int) ceil($total / $perPage) : 0;

            for ($i = 0; $i < $numPages; $i++) {
                $this->addSitemap(osc_base_url() . 'sitemap/item-sitemap_s' . $i . '.xml', date('Y-m-d'));
            }

            if ($this->includeCategories()) {
                $this->addSitemap(osc_base_url() . 'sitemap/category.xml', date('Y-m-d'));
            }

            if (osc_get_preference('sitemap_cat_regions', self::PREF_GROUP)) {
                $this->addSitemap(osc_base_url() . 'sitemap/categories-regions.xml', date('Y-m-d'));
            }
            if (osc_get_preference('sitemap_cat_city', self::PREF_GROUP)) {
                $this->addSitemap(osc_base_url() . 'sitemap/categories-cities.xml', date('Y-m-d'));
            }
            if (osc_get_preference('sitemap_cities', self::PREF_GROUP)) {
                $this->addSitemap(osc_base_url() . 'sitemap/cities.xml', date('Y-m-d'));
            }
            if (osc_get_preference('sitemap_regions', self::PREF_GROUP)) {
                $this->addSitemap(osc_base_url() . 'sitemap/regions.xml', date('Y-m-d'));
            }
            if (osc_get_preference('sitemap_countries', self::PREF_GROUP)) {
                $this->addSitemap(osc_base_url() . 'sitemap/countries.xml', date('Y-m-d'));
            }

            if ($this->includePages()) {
                $this->addSitemap(osc_base_url() . 'sitemap/pages.xml', $this->pagesModificationDate());
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * Generate one paginated item sitemap.
     *
     * @param int  $page   zero-based page index
     * @param bool $output whether to stream the result
     *
     * @return string
     */
    public function generateItemSitemap($page, $output = true)
    {
        $page    = (int) $page;
        $perPage = $this->itemsPerSitemap();

        $xml = $this->render('sitemap_item_xml_' . $page, false, function () use ($page, $perPage) {
            @set_time_limit(180);
            $default = $this->defaultItemsSource($page, $perPage);
            $items   = osc_apply_filter(
                'sitemap_items_source',
                $default,
                array('page' => $page, 'per_page' => $perPage)
            );

            if (!is_array($items) && !($items instanceof Traversable)) {
                return;
            }

            foreach ($items as $row) {
                $city    = isset($row['s_city']) ? $row['s_city'] : '';
                $pubDate = isset($row['dt_pub_date']) ? $row['dt_pub_date'] : '';
                $modDate = isset($row['dt_mod_date']) ? $row['dt_mod_date'] : '';
                $stamp   = ($modDate > $pubDate) ? strtotime($modDate) : strtotime($pubDate);
                if (!$stamp) {
                    $stamp = time();
                }
                $loc = $this->itemUrl(
                    $row['pk_i_id'],
                    isset($row['s_title']) ? $row['s_title'] : '',
                    isset($row['fk_i_category_id']) ? $row['fk_i_category_id'] : '',
                    $city
                );
                $this->addUrl($loc, date('Y-m-d', $stamp), 'weekly', 'item');
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateCategorySitemap($output = true)
    {
        $xml = $this->render('sitemap_category_xml', false, function () {
            $categories = new Category();
            View::newInstance()->_exportVariableToView('categories', $categories->listWhere('i_num_items > 0'));

            if (osc_count_categories() > 0) {
                while (osc_has_categories()) {
                    $this->addUrl(osc_search_category_url(), date('Y-m-d'), 'hourly', 'category');
                }
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generatePageSitemap($output = true)
    {
        $xml = $this->render('sitemap_page_xml', false, function () {
            $this->addUrl(osc_base_url(), date('Y-m-d'), 'always', 'home');
            $this->addUrl(osc_search_url(), date('Y-m-d'), 'always', 'search');

            if (osc_count_static_pages() > 0) {
                while (osc_has_static_pages()) {
                    $lastmod = substr(
                        osc_static_page_mod_date() != '' ? osc_static_page_mod_date() : osc_static_page_pub_date(),
                        0,
                        10
                    );
                    $this->addUrl(osc_static_page_url(), $lastmod, 'monthly', 'page');
                }
            }

            foreach ($this->customUrls() as $custom) {
                if (isset($custom['url'])) {
                    $this->addUrl(
                        $custom['url'],
                        isset($custom['lastmod']) ? $custom['lastmod'] : date('Y-m-d'),
                        isset($custom['freq']) ? $custom['freq'] : 'weekly',
                        'custom'
                    );
                }
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateCitiesSitemap($output = true)
    {
        $xml = $this->render('sitemap_cities_xml', false, function () {
            if (osc_count_list_cities() > 0) {
                while (osc_has_list_cities()) {
                    $this->addUrl(osc_list_city_url(), date('Y-m-d'), 'weekly', 'city');
                }
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateRegionsSitemap($output = true)
    {
        $xml = $this->render('sitemap_regions_xml', false, function () {
            if (osc_count_list_regions() > 0) {
                while (osc_has_list_regions()) {
                    $this->addUrl(osc_list_region_url(), date('Y-m-d'), 'weekly', 'region');
                }
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateCountriesSitemap($output = true)
    {
        $xml = $this->render('sitemap_countries_xml', false, function () {
            if (osc_count_list_countries() > 0) {
                while (osc_has_list_countries()) {
                    $this->addUrl(osc_list_country_url(), date('Y-m-d'), 'weekly', 'country');
                }
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateCatRegionSitemap($output = true)
    {
        $xml = $this->render('sitemap_cat_region_xml', false, function () {
            @set_time_limit(160);
            $this->dao->select('DISTINCT a.fk_i_category_id, b.fk_i_region_id');
            $this->dao->from(DB_TABLE_PREFIX . 't_item AS a');
            $this->dao->join(DB_TABLE_PREFIX . 't_item_location AS b', 'a.pk_i_id = b.fk_i_item_id', 'LEFT');
            $this->dao->where(self::liveItemCondition('a'));
            $this->dao->where('a.fk_i_category_id IS NOT NULL');
            $this->dao->where('b.fk_i_region_id IS NOT NULL');
            $this->dao->limit(self::MAX_SITEMAP_URLS);
            $result = $this->dao->get();
            $rows   = $result ? $result->result() : array();

            foreach ($rows as $row) {
                $url = osc_update_search_url(array(
                    'action_specific' => '',
                    'CSRFName'        => '',
                    'CSRFToken'       => '',
                    'route'           => '',
                    'sCategory'       => $row['fk_i_category_id'],
                    'sRegion'         => $row['fk_i_region_id'],
                ));
                $this->addUrl($url, date('Y-m-d'), 'weekly', 'cat_region');
            }
        });

        return $this->deliver($xml, $output);
    }

    /**
     * @param bool $output
     *
     * @return string
     */
    public function generateCatCitySitemap($output = true)
    {
        $xml = $this->render('sitemap_cat_city_xml', false, function () {
            @set_time_limit(160);
            $this->dao->select('DISTINCT a.fk_i_category_id, b.fk_i_city_id');
            $this->dao->from(DB_TABLE_PREFIX . 't_item AS a');
            $this->dao->join(DB_TABLE_PREFIX . 't_item_location AS b', 'a.pk_i_id = b.fk_i_item_id', 'LEFT');
            $this->dao->where(self::liveItemCondition('a'));
            $this->dao->where('a.fk_i_category_id IS NOT NULL');
            $this->dao->where('b.fk_i_city_id IS NOT NULL');
            $this->dao->limit(self::MAX_SITEMAP_URLS);
            $result = $this->dao->get();
            $rows   = $result ? $result->result() : array();

            foreach ($rows as $row) {
                $url = osc_update_search_url(array(
                    'action_specific' => '',
                    'CSRFName'        => '',
                    'CSRFToken'       => '',
                    'route'           => '',
                    'sCategory'       => $row['fk_i_category_id'],
                    'sCity'           => $row['fk_i_city_id'],
                ));
                $this->addUrl($url, date('Y-m-d'), 'weekly', 'cat_city');
            }
        });

        return $this->deliver($xml, $output);
    }

    /* ------------------------------------------------------------------ *
     *  Item source (the swappable seam) + URL building                    *
     * ------------------------------------------------------------------ */

    /**
     * Default implementation behind the `sitemap_items_source` filter: the live
     * listings for one page, ordered by id, joined to their description and
     * location for title/city.
     *
     * One row per listing: t_item_description holds a row per locale, so a naive
     * join emits the same listing once per locale. Collapsing with MAX() (over an
     * arbitrary aggregate) is ONLY_FULL_GROUP_BY-safe, and requiring a non-empty
     * title drops untranslated locales that would otherwise yield a blank slug.
     *
     * @param int $page    zero-based page index
     * @param int $perPage rows per page
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultItemsSource($page, $perPage)
    {
        $this->dao->select('pk_i_id, fk_i_category_id, dt_pub_date, dt_mod_date');
        $this->dao->from(DB_TABLE_PREFIX . 't_item');
        $this->dao->where(self::liveItemCondition());
        $this->dao->orderby('pk_i_id', 'ASC');
        $this->dao->limit($page * $perPage, $perPage);
        $subQuery = $this->dao->_getSelect();
        $this->dao->_resetSelect();

        $this->dao->select(
            't.pk_i_id, t.fk_i_category_id, t.dt_pub_date, t.dt_mod_date,'
            . ' MAX(d.s_title) AS s_title, MAX(l.s_city) AS s_city'
        );
        $this->dao->from('(' . $subQuery . ') AS t');
        $this->dao->join(DB_TABLE_PREFIX . 't_item_description AS d', 't.pk_i_id = d.fk_i_item_id', 'LEFT');
        $this->dao->join(DB_TABLE_PREFIX . 't_item_location AS l', 'd.fk_i_item_id = l.fk_i_item_id', 'LEFT');
        $this->dao->where("d.s_title IS NOT NULL AND d.s_title != ''");
        $this->dao->groupBy('t.pk_i_id, t.fk_i_category_id, t.dt_pub_date, t.dt_mod_date');
        $result = $this->dao->get();

        return $result ? $result->result() : array();
    }

    /**
     * Build the public URL for a single listing, honouring the friendly-URL
     * template when rewrite is enabled.
     *
     * @param int|string    $itemId
     * @param string        $itemTitle
     * @param int|string    $itemCategory
     * @param string        $itemCity
     * @param string        $locale
     *
     * @return string
     */
    public function itemUrl($itemId, $itemTitle, $itemCategory = '', $itemCity = '', $locale = '')
    {
        if (osc_rewrite_enabled()) {
            $url = osc_get_preference('rewrite_item_url');
            if (preg_match('{CATEGORIES}', $url)) {
                $sanitizedCategories = array();
                $cat                 = Category::newInstance()->hierarchy($itemCategory);
                for ($i = count($cat); $i > 0; $i--) {
                    $sanitizedCategories[] = $cat[$i - 1]['s_slug'];
                }
                $url = str_replace('{CATEGORIES}', implode('/', $sanitizedCategories), $url);
            }
            $url = str_replace('{ITEM_ID}', osc_sanitizeString($itemId), $url);
            $url = str_replace('{ITEM_CITY}', osc_sanitizeString($itemCity), $url);
            $url = str_replace('{ITEM_TITLE}', osc_sanitizeString($itemTitle), $url);
            $url = str_replace('?', '', $url);
            $path = ($locale !== '') ? osc_base_url() . $locale . '/' . $url : osc_base_url() . $url;
        } else {
            $path = osc_item_url_ns($itemId, $locale);
        }

        // Return the raw URL: addUrl() wraps it in a DOMDocument text node,
        // which is the single XML-escaping step. Pre-encoding here would
        // double-escape "&" in query-string (non-friendly) URLs.
        return $path;
    }

    /* ------------------------------------------------------------------ *
     *  Counts / dates                                                     *
     * ------------------------------------------------------------------ */

    /**
     * Live-listing count, memoised.
     *
     * @return int
     */
    public function countLiveItems()
    {
        if ($this->total_results_table === null) {
            $result = $this->dao->query(
                'SELECT COUNT(*) AS total FROM ' . DB_TABLE_PREFIX . 't_item'
                . ' WHERE ' . self::liveItemCondition()
            );
            $row                       = $result ? $result->row() : array('total' => 0);
            $this->total_results_table = (int) $row['total'];
        }

        return $this->total_results_table;
    }

    /**
     * Zero-based index of the last item sitemap page.
     *
     * @return int
     */
    private function lastItemPage()
    {
        $perPage = $this->itemsPerSitemap();
        $total   = (int) osc_apply_filter('sitemap_items_total', $this->countLiveItems());
        if ($perPage <= 0 || $total <= 0) {
            return 0;
        }

        return (int) ceil($total / $perPage) - 1;
    }

    /**
     * Latest modification date across static pages and custom URLs, RFC-3339.
     *
     * @return string
     */
    private function pagesModificationDate()
    {
        $result = $this->dao->query(
            'SELECT MAX(dt_pub_date) AS max_pub_date, MAX(dt_mod_date) AS max_mod_date'
            . ' FROM ' . DB_TABLE_PREFIX . 't_pages WHERE b_indelible < 1'
        );
        $row     = $result ? $result->row() : array('max_pub_date' => null, 'max_mod_date' => null);
        $newdate = ($row['max_pub_date'] > $row['max_mod_date']) ? $row['max_pub_date'] : $row['max_mod_date'];

        foreach ($this->customUrls() as $custom) {
            if (isset($custom['lastmod']) && $custom['lastmod'] > $newdate) {
                $newdate = $custom['lastmod'];
            }
        }

        return date('c', $newdate ? strtotime($newdate) : time());
    }

    /* ------------------------------------------------------------------ *
     *  Cache warming / invalidation                                       *
     * ------------------------------------------------------------------ */

    /**
     * Pre-generate every enabled sitemap into the object cache. Wired to
     * cron_daily so the (potentially heavy) location scans run off the request
     * path rather than on the first bot to hit a cold route.
     *
     * @return array<string, bool> per-document success map
     */
    public function warmCache()
    {
        $done = array();

        $build = array(
            'items' => function () {
                for ($i = 0, $last = $this->lastItemPage(); $i <= $last; $i++) {
                    $this->generateItemSitemap($i, false);
                }
            },
        );

        if ($this->includeCategories()) {
            $build['category'] = function () {
                $this->generateCategorySitemap(false);
            };
        }
        if ($this->includePages()) {
            $build['pages'] = function () {
                $this->generatePageSitemap(false);
            };
        }

        if (osc_get_preference('sitemap_cities', self::PREF_GROUP)) {
            $build['cities'] = function () {
                $this->generateCitiesSitemap(false);
            };
        }
        if (osc_get_preference('sitemap_regions', self::PREF_GROUP)) {
            $build['regions'] = function () {
                $this->generateRegionsSitemap(false);
            };
        }
        if (osc_get_preference('sitemap_countries', self::PREF_GROUP)) {
            $build['countries'] = function () {
                $this->generateCountriesSitemap(false);
            };
        }
        if (osc_get_preference('sitemap_cat_regions', self::PREF_GROUP)) {
            $build['cat_regions'] = function () {
                $this->generateCatRegionSitemap(false);
            };
        }
        if (osc_get_preference('sitemap_cat_city', self::PREF_GROUP)) {
            $build['cat_cities'] = function () {
                $this->generateCatCitySitemap(false);
            };
        }

        // Index last: it depends on the same counts the children materialise.
        $build['index'] = function () {
            $this->generateIndex(false);
        };

        foreach ($build as $name => $fn) {
            try {
                $fn();
                $done[$name] = true;
            } catch (Throwable $e) {
                $done[$name] = false;
                error_log('Sitemap cache warming failed for ' . $name . ': ' . $e->getMessage());
            }
        }

        return $done;
    }

    /**
     * Drop every cached sitemap document so the next request rebuilds it. Called
     * from the admin "Regenerate / Clear cache" action.
     *
     * @return void
     */
    public function clearCache()
    {
        $keys = array(
            'sitemap_index_xml',
            'sitemap_category_xml',
            'sitemap_page_xml',
            'sitemap_cities_xml',
            'sitemap_regions_xml',
            'sitemap_countries_xml',
            'sitemap_cat_region_xml',
            'sitemap_cat_city_xml',
        );
        for ($i = 0, $last = $this->lastItemPage(); $i <= $last; $i++) {
            $keys[] = 'sitemap_item_xml_' . $i;
        }

        foreach ($keys as $key) {
            osc_cache_delete($key);
        }
    }

    /* ------------------------------------------------------------------ *
     *  robots.txt                                                         *
     * ------------------------------------------------------------------ */

    /**
     * The `Sitemap:` directive advertising the index, as an absolute URL. Built
     * at runtime from osc_base_url() so no host is ever baked into a tracked file.
     *
     * @return string
     */
    public static function robotsLine()
    {
        return 'Sitemap: ' . osc_base_url() . 'sitemapindex.xml';
    }

    /**
     * Default robots.txt body: disallow the admin panel and advertise the sitemap.
     *
     * @return string
     */
    public static function defaultRobotsTxt()
    {
        return "User-agent: *\n"
            . 'Disallow: ' . str_replace(osc_base_url(), '/', osc_admin_base_url()) . "\n"
            . self::robotsLine() . "\n";
    }

    /**
     * Serve a dynamic robots.txt (used when no static file shadows the route).
     *
     * @return void
     */
    private function serveRobots()
    {
        header('Content-Type: text/plain; charset=UTF-8');
        echo self::defaultRobotsTxt();
    }

    /* ------------------------------------------------------------------ *
     *  DOM helpers                                                         *
     * ------------------------------------------------------------------ */

    /**
     * Render (or fetch from cache) one document. Builds a fresh DOM, runs
     * $builder to populate it, caches the serialised XML and returns it.
     *
     * @param string   $cacheKey object-cache key
     * @param bool     $isIndex  true for a sitemapindex, false for a urlset
     * @param callable $builder  populates $this->root
     *
     * @return string
     */
    private function render($cacheKey, $isIndex, callable $builder)
    {
        $found = false;
        $cached = osc_cache_get($cacheKey, $found);
        if ($found && is_string($cached) && $cached !== '') {
            return $cached;
        }

        $this->initDocument($isIndex);
        $builder();
        $xml = $this->dom->saveXML();
        osc_cache_set($cacheKey, $xml, self::$cacheTTL);

        return $xml;
    }

    /**
     * Emit the XML with the correct content type, unless output is suppressed
     * (cache-warming). Always returns the XML string.
     *
     * @param string $xml
     * @param bool   $output
     *
     * @return string
     */
    private function deliver($xml, $output)
    {
        if ($output) {
            header('Content-Type: text/xml; charset=UTF-8');
            echo $xml;
        }

        return $xml;
    }

    /**
     * Initialise a fresh DOMDocument with the stylesheet PI and root element.
     *
     * @param bool $isIndex
     *
     * @return void
     */
    private function initDocument($isIndex = false)
    {
        $style = $isIndex ? self::stylesheetUrl('xmlsitemapindex.xsl') : self::stylesheetUrl('xmlsitemap.xsl');

        $this->dom               = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $pi = $this->dom->createProcessingInstruction(
            'xml-stylesheet',
            'type="text/xsl" href="' . $style . '"'
        );
        $this->dom->appendChild($pi);

        $tag        = $isIndex ? 'sitemapindex' : 'urlset';
        $this->root = $this->dom->createElement($tag);
        $this->root->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->dom->appendChild($this->root);
    }

    /**
     * Core-served URL of a sitemap XSL stylesheet.
     *
     * @param string $file
     *
     * @return string
     */
    public static function stylesheetUrl($file)
    {
        return osc_assets_url('osclass/sitemap/' . $file);
    }

    /**
     * Append a <url> entry to a urlset, after passing it through the
     * `sitemap_url_entry` filter so a plugin can rewrite loc/lastmod/changefreq.
     *
     * @param string $loc
     * @param string $lastmod
     * @param string $changefreq
     * @param string $type context tag handed to the filter
     *
     * @return void
     */
    private function addUrl($loc, $lastmod, $changefreq, $type = '')
    {
        $entry = osc_apply_filter(
            'sitemap_url_entry',
            array('loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $changefreq),
            $type
        );

        if (!isset($entry['loc']) || !filter_var($entry['loc'], FILTER_VALIDATE_URL)) {
            return;
        }
        // Only http(s) locs. FILTER_VALIDATE_URL also passes javascript:/data:
        // URIs, which the bundled XSL viewer renders as a clickable link.
        $scheme = strtolower((string) parse_url($entry['loc'], PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $url = $this->dom->createElement('url');
        $this->root->appendChild($url);

        $locNode = $this->dom->createElement('loc');
        $locNode->appendChild($this->dom->createTextNode($entry['loc']));
        $url->appendChild($locNode);

        if (!empty($entry['lastmod'])) {
            $url->appendChild($this->dom->createElement('lastmod', $entry['lastmod']));
        }
        if (!empty($entry['changefreq'])) {
            $url->appendChild($this->dom->createElement('changefreq', $entry['changefreq']));
        }
    }

    /**
     * Whether the category sitemap is included. Defaults to on (opt-out): only
     * an explicit '0' preference disables it, so existing installs that never
     * had the toggle keep emitting it.
     *
     * @return bool
     */
    private function includeCategories()
    {
        return osc_get_preference('sitemap_categories', self::PREF_GROUP) !== '0';
    }

    /**
     * Whether the static-pages sitemap is included. Defaults to on (opt-out).
     *
     * @return bool
     */
    private function includePages()
    {
        return osc_get_preference('sitemap_pages', self::PREF_GROUP) !== '0';
    }

    /**
     * Append a <sitemap> entry to a sitemapindex.
     *
     * @param string $loc
     * @param string $lastmod
     *
     * @return void
     */
    private function addSitemap($loc, $lastmod)
    {
        if (!filter_var($loc, FILTER_VALIDATE_URL)) {
            return;
        }
        $scheme = strtolower((string) parse_url($loc, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $sitemap = $this->dom->createElement('sitemap');
        $this->root->appendChild($sitemap);

        $locNode = $this->dom->createElement('loc');
        $locNode->appendChild($this->dom->createTextNode($loc));
        $sitemap->appendChild($locNode);

        $sitemap->appendChild($this->dom->createElement('lastmod', $lastmod));
    }

    /* ------------------------------------------------------------------ *
     *  Small preference accessors                                         *
     * ------------------------------------------------------------------ */

    /**
     * URLs-per-item-sitemap, from the `sitemap_number` preference.
     *
     * @return int
     */
    private function itemsPerSitemap()
    {
        $n = (int) osc_get_preference('sitemap_number', self::PREF_GROUP);

        return ($n > 0) ? $n : self::DEFAULT_ITEMS_PER_SITEMAP;
    }

    /**
     * Decoded custom URL list (the `custom_urls` JSON preference).
     *
     * @return array<int, array<string, string>>
     */
    private function customUrls()
    {
        $raw = osc_get_preference('custom_urls', self::PREF_GROUP);
        if ($raw === '' || $raw === null) {
            return array();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return void
     */
    private function notFound()
    {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
}
