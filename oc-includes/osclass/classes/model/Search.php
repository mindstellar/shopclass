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

use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\search\query\SearchCompiler;
use mindstellar\search\query\SearchExecutor;
use mindstellar\search\query\SearchParts;
use mindstellar\search\query\SearchRecord;
use mindstellar\search\query\SqlValue;

/**
 * The listing search plugins and themes build on.
 *
 * It collects what is asked for; mindstellar\search\query builds the statements with
 * bound values and runs them. Fragments plugins pass in (addConditions(), addTable(),
 * addJoinTable(), addField(), addGroupBy(), addHaving(), $dao) are SQL and go in as
 * written.
 */
class Search extends DAO
{
    private static $instance;
    private SearchParts $parts;
    private $total_results;
    private $total_results_table;
    private $primeResources = true;

    /**
     * @param bool $expired true includes listings the public cannot see
     */
    public function __construct($expired = false)
    {
        parent::__construct();
        $this->setTableName('t_item');
        $this->setFields(array('pk_i_id'));

        $this->parts = new SearchParts();
        // The rule for a public listing, shared with the category counts.
        $this->parts->liveConditions = Item::liveConditions(DB_TABLE_PREFIX . 't_item.');
        if (!$expired) {
            $this->addItemConditions($this->parts->liveConditions);
        }
        $this->total_results       = null;
        $this->total_results_table = null;
        $admin                     = defined('OC_ADMIN') && OC_ADMIN;
        $this->parts->userLocale   = $admin ? osc_current_admin_locale() : osc_current_user_locale();
        if ($admin) {
            $this->addField(sprintf('%st_item_location.*', DB_TABLE_PREFIX));
        }
    }

    /**
     * Establish the order of the search
     *
     * @param string      $o_c   column
     * @param string      $o_d   direction
     * @param string|null $table table qualifier, or a '%s'-style prefix format
     *
     * @return void
     */
    public function order($o_c = '', $o_d = 'DESC', $table = null)
    {
        $this->parts->ordering->order($o_c, $o_d, $table, $this->parts->pattern->active());
    }

    /**
     * Order by several t_item columns, each with its own direction, most significant first:
     * [['dt_pub_date', 'DESC'], ['pk_i_id', 'DESC']]. Replaces what order() set. To order by
     * relevance, use order().
     *
     * @param array<int,array{0:string,1:string}> $columns column => direction pairs
     *
     * @return void
     * @throws InvalidArgumentException for a column outside the allowed t_item columns or a
     *                                  direction other than ASC or DESC
     */
    public function orderBy(array $columns)
    {
        $this->parts->ordering->orderBy($columns);
    }

    /**
     * Limit the results of the search
     *
     * @param int      $l_i   offset
     * @param int|null $r_p_p results per page; null keeps the current value
     *
     * @return void
     */
    public function limit($l_i = 0, $r_p_p = null)
    {
        $this->parts->ordering->limit($l_i, $r_p_p);
    }

    /**
     * Constrain the search to an explicit set of item ids and page to its length.
     *
     * For hydrating a match set produced elsewhere (an external search engine, a
     * plugin's own query) through the core row fetch. The page is sized to the id
     * count, so the default page size of 10 cannot truncate it.
     *
     * @param array $ids           item primary keys; non-ints are dropped
     * @param bool  $preserveOrder keep the caller's order (its ranking) via FIND_IN_SET
     *
     * @return Search $this
     */
    public function fromPrimaryKeys(array $ids, $preserveOrder = true)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            // No ids is an empty result, not everything.
            $this->parts->addOnce('1 = 0');
            $this->limit(0, 0);

            return $this;
        }

        $this->parts->addOnce(DB_TABLE_PREFIX . 't_item.pk_i_id IN (' . SqlValue::placeholders(count($ids)) . ')', $ids);

        if ($preserveOrder) {
            $this->parts->leadOrder = array('FIND_IN_SET(' . DB_TABLE_PREFIX . "t_item.pk_i_id, '" . implode(',', $ids) . "')");
        }

        $this->limit(0, count($ids));

        return $this;
    }

    /**
     * Include listings the public cannot see — disabled, deactivated, spam or expired — in the
     * results. A default Search hides them; an admin table or an owner-facing view needs them.
     * This is the supported switch for that, instead of `new Search(true)` (which cannot be
     * undone, and which the newInstance() singleton can never be).
     *
     * @param bool $include
     *
     * @return $this
     */
    public function includeHidden($include = true)
    {
        if ($include) {
            $this->parts->plugin->removeItemConditions($this->parts->liveConditions);
        } else {
            $this->addItemConditions($this->parts->liveConditions);
        }

        return $this;
    }

    /**
     * Add item conditions to the search
     *
     * @param string|array<int,string> $conditions
     *
     * @return void
     */
    public function addItemConditions($conditions)
    {
        $this->parts->plugin->addItemConditions($conditions);
    }

    /**
     * Add new fields to the search
     *
     * @param string|array<int,string> $fields
     *
     * @return void
     */
    public function addField($fields)
    {
        $this->parts->plugin->addField($fields, (array)$this->getFields());
    }

    /**
     * Return the shared Search instance, creating it on first use.
     *
     * @return \Search
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
     * Replace the shared Search instance; null clears it, so the next newInstance()
     * builds a fresh one.
     *
     * @param \Search|null $instance
     *
     * @return \Search|null the instance it replaced
     */
    public static function resetInstance($instance = null)
    {
        $previous       = self::$instance;
        self::$instance = $instance instanceof self ? $instance : null;

        return $previous;
    }

    /**
     * Return an array with columns allowed for sorting
     *
     * @return string[]
     */
    public static function getAllowedColumnsForSorting()
    {
        return array('i_price', 'dt_pub_date', 'dt_expiration', 'relevance');
    }

    /**
     * Return an array with of sorting
     *
     * @return array<int,string>
     */
    public static function getAllowedTypesForSorting()
    {
        return array(0 => 'asc', 1 => 'desc');
    }

    /**
     * Add conditions to the search
     *
     * @param string|array<int,string> $conditions
     *
     * @return void
     */
    public function addConditions($conditions)
    {
        $this->parts->plugin->addConditions($conditions);
    }

    /**
     * Add one condition whose values come in $params, one per `?`.
     *
     * Plugin conditions are kept as SQL text (the sql_search_conditions filter and
     * toJson() show them), so each value is type-checked and inlined: ints and finite
     * floats as they are, booleans as 1/0, null as NULL, strings escaped and quoted.
     * The fragment itself may hold no quotes, so every `?` in it is a placeholder.
     *
     * @param string                              $sql    e.g. 't_item.pk_i_id < ?'
     * @param array<int,int|float|string|bool|null> $params
     *
     * @return void
     * @throws InvalidArgumentException for a quote in $sql, a count that does not match, or a value of another type
     */
    public function addCondition(string $sql, array $params = array())
    {
        if (strpbrk($sql, "'\"\\`#") !== false || str_contains($sql, '--') || str_contains($sql, '/*')) {
            throw new InvalidArgumentException('Search::addCondition(): pass values in $params, not quoted in the SQL.');
        }
        $parts  = explode('?', $sql);
        $params = array_values($params);
        if (count($parts) - 1 !== count($params)) {
            throw new InvalidArgumentException('Search::addCondition(): ' . (count($parts) - 1) . ' placeholders, ' . count($params) . ' values.');
        }
        $out = $parts[0];
        foreach ($params as $i => $value) {
            $out .= match (true) {
                is_int($value)                        => (string)$value,
                is_float($value) && is_finite($value) => (string)$value,
                is_bool($value)                       => $value ? '1' : '0',
                $value === null                       => 'NULL',
                is_string($value)                     => "'" . SqlValue::escape($value) . "'",
                default                               => throw new InvalidArgumentException('Search::addCondition(): a value must be an int, a finite float, a string, a bool or null.'),
            };
            $out .= $parts[$i + 1];
        }
        $this->addConditions($out);
    }

    /**
     * Whether doSearch() primes the photo cache for the page, which the theme then reads per
     * listing. On by default; a caller that loads photos itself turns it off.
     *
     * @param bool $prime
     *
     * @return void
     */
    public function primeResources($prime = true)
    {
        $this->primeResources = (bool)$prime;
    }

    /**
     * Add locale conditions to the search
     *
     * @param string|array<int,string> $locales
     *
     * @return void
     * @since  3.2
     */
    public function addLocale($locales)
    {
        $this->parts->pattern->addLocale($locales);
    }

    /**
     * Add extra table to the search
     *
     * @param string|array<int,string> $tables
     *
     * @return void
     */
    public function addTable($tables)
    {
        $this->parts->plugin->addTable($tables);
    }

    /**
     * Add group by to the search
     *
     * @param string $groupBy
     *
     * @return void
     */
    public function addGroupBy($groupBy)
    {
        $this->parts->plugin->setGroupBy($groupBy);
    }

    /**
     * Select the page of the search
     *
     * @param int      $p     page
     * @param int|null $r_p_p results per page; null keeps the current value
     *
     * @return void
     */
    public function page($p = 0, $r_p_p = null)
    {
        $this->parts->ordering->page($p, $r_p_p);
    }

    /**
     * Add city areas to the search
     *
     * @param string|int|array<int,string|int> $city_area
     *
     * @return void
     */
    public function addCityArea($city_area = array())
    {
        $this->parts->locations->addCityArea($city_area);
    }

    /**
     * Establish max price
     *
     * @param int $price
     *
     * @return void
     */
    public function priceMax($price)
    {
        $this->priceRange(null, $price);
    }

    /**
     * Establish price range
     *
     * @param int|null $price_min
     * @param int|null $price_max
     *
     * @return void
     */
    public function priceRange($price_min = 0, $price_max = 0)
    {
        $this->parts->priceRange($price_min, $price_max);
    }

    /**
     * Establish min price
     *
     * @param int $price
     *
     * @return void
     */
    public function priceMin($price)
    {
        $this->priceRange($price, null);
    }

    /**
     * Set having sentence to sql
     *
     * @param string $having
     *
     * @return void
     */
    public function addHaving($having)
    {
        $this->parts->plugin->setHaving($having);
    }

    /**
     * Filter by email
     *
     * @param string $email
     *
     * @return void
     * @since  2.4
     */
    public function addContactEmail($email)
    {
        $this->parts->byContactEmail = true;
        $this->parts->contactEmail   = $email;
    }

    /**
     * Exclude one user's listings from the results.
     *
     * @param int $id
     *
     * @return void
     */
    public function notFromUser($id)
    {
        $p = DB_TABLE_PREFIX;
        $this->parts->addOnce('(' . $p . 't_item.fk_i_user_id != ? || ' . $p . 't_item.fk_i_user_id IS NULL) ', array((int)$id));
    }

    /**
     * Restrict the search to one listing id.
     *
     * @param int $id
     *
     * @return void
     */
    public function addItemId($id)
    {
        $this->parts->byItemId = true;
        $this->parts->itemId   = $id;
    }

    /**
     *  Add joins for future use
     *
     * @param string $key
     * @param string $table
     * @param string $condition
     * @param string $type
     *
     * @return void
     * @since 2.4
     */
    public function addJoinTable($key, $table, $condition, $type)
    {
        $this->parts->plugin->addJoin($key, $table, $condition, $type);
    }

    /**
     * Return number of ads selected
     *
     * @return int
     */
    public function count()
    {
        if (null === $this->total_results) {
            $this->doSearch();
        }

        return $this->total_results;
    }

    /**
     * Perform the search
     *
     * @param bool $extended if you want to extend ad's data
     *
     * @param bool $count
     *
     * @return array<int,array<string,mixed>> Empty when the query failed
     */
    public function doSearch($extended = true, $count = true)
    {
        $mainError = false;
        try {
            $items = SearchExecutor::rows(SearchCompiler::results($this->parts, $this->dao));
        } catch (DbException $e) {
            $items     = array();
            $mainError = true;
        }

        // COUNT(*) over the unlimited match list: exact, and one row on the wire.
        $this->total_results = $count
            ? SearchExecutor::total(SearchCompiler::count(SearchCompiler::results($this->parts, $this->dao, true)))
            : 0;

        if ($mainError) {
            return array();
        }

        if (($extended === true) && !empty($items)) {
            return $this->primeResources ? Item::getInstance()->extendData($items) : Item::getInstance()->extendRows($items);
        }

        return $items;
    }

    /**
     * The featured-block SQL, its values as `?` placeholders.
     *
     * @param int $num
     *
     * @return string
     */
    // @phpstan-ignore method.unused (called through reflection by the tests)
    private function makeSQLPremium($num = 2)
    {
        return SearchCompiler::premiums($this->parts, $num)[0];
    }

    /**
     * Return total items on t_item without any filter
     *
     * @return string|null The count as a string, null when the query failed
     */
    public function countAll()
    {
        if (null === $this->total_results_table) {
            try {
                $row                       = Db::selectOne('SELECT COUNT(*) AS total FROM ' . DB_TABLE_PREFIX . 't_item');
                $this->total_results_table = $row === null ? null : (string)$row['total'];
            } catch (DbException $e) {
                // A later call retries.
                $this->total_results_table = null;
            }
        }

        return $this->total_results_table;
    }

    /**
     * Premium listings matching only the keyword, location and category, in a random
     * order that holds for a few minutes.
     *
     * @param int $max
     *
     * @return array<int,array<string,mixed>> Empty when there are no premium listings
     */
    public function getPremiums($max = 2)
    {
        try {
            $items = SearchExecutor::rows(SearchCompiler::premiums($this->parts, $max));
        } catch (DbException $e) {
            return array();
        }

        if (!empty($items)) {
            // This block shows on the home, category and search pages: one write for all
            // of it, and none for a crawler.
            if (osc_request_counts_as_view()) {
                ItemStats::getInstance()->increaseBatch(
                    'i_num_premium_views',
                    array_column($items, 'pk_i_id')
                );
            }

            return Item::getInstance()->extendData($items);
        }

        return array();
    }

    /**
     * Return latest posted items, you can filter by category and specify the
     * number of items returned.
     *
     * @param int                 $numItems
     * @param array<string,mixed> $options    sCategory / sCity / sRegion / sCountry filters
     * @param bool                $withPicture
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLatestItems($numItems = 10, $options = array(), $withPicture = false)
    {
        $key = 'latest:' . osc_current_user_locale() . '|' . $numItems . json_encode($options) . (int)$withPicture;

        return \mindstellar\cache\CacheGroup::remember('search', $key, function () use ($numItems, $options, $withPicture): array {
            $this->set_rpp($numItems);
            if ($withPicture) {
                $this->withPicture(true);
            }
            if (isset($options['sCategory'])) {
                $this->addCategory($options['sCategory']);
            }
            if (isset($options['sCountry'])) {
                $this->addCountry($options['sCountry']);
            }
            if (isset($options['sRegion'])) {
                $this->addRegion($options['sRegion']);
            }
            if (isset($options['sCity'])) {
                $this->addCity($options['sCity']);
            }
            if (isset($options['sUser'])) {
                $this->fromUser($options['sUser']);
            }

            return $this->doSearch();
        });
    }

    /**
     * Limit the results of the search
     *
     * @param int $r_p_p
     *
     * @return void
     */
    public function set_rpp($r_p_p)
    {
        $this->parts->ordering->setPerPage($r_p_p);
    }

    /**
     * Filter by ad with picture or not
     *
     * @param bool $pic
     *
     * @return void
     */
    public function withPicture($pic = false)
    {
        $this->parts->withPicture = $pic;
    }

    /**
     * Add categories to the search
     *
     * @param int|string|array<string,mixed>|null $category Category id, slug, or a category row
     *
     * @return bool False when $category is empty or unknown
     */
    public function addCategory($category = null)
    {
        return $this->parts->categories->add($category);
    }

    /**
     * Add countries to the search
     *
     * @param string|array<int,string> $country Country codes or names
     *
     * @return void
     */
    public function addCountry($country = array())
    {
        $this->parts->locations->addCountry($country);
    }

    /**
     * Add regions to the search
     *
     * @param string|int|array<int,string|int> $region Region ids or names
     *
     * @return void
     */
    public function addRegion($region = array())
    {
        $this->parts->locations->addRegion($region);
    }

    /**
     * Add cities to the search
     *
     * @param string|int|array<int,string|int> $city City ids or names
     *
     * @return void
     */
    public function addCity($city = array())
    {
        $this->parts->locations->addCity($city);
    }

    /**
     * Return ads from specified users
     *
     * @param string|int|array<int,string|int>|null $id User ids or usernames
     *
     * @return void
     */
    public function fromUser($id = null)
    {
        $this->parts->users->from($id);
    }

    /**
     * Returns number of ads from each country
     *
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array<int,array<string,string|null>>
     *
     * @see        CountryStats::listCountries
     * @deprecated since 2.4 use CountryStats::listCountries() instead
     */
    public function listCountries($zero = '>', $order = 'items DESC')
    {
        return CountryStats::getInstance()->listCountries($zero, $order);
    }

    /**
     * Returns number of ads from each region
     * <code>
     *  Search::getInstance()->listRegions($country, ">=", "country_name ASC" )
     * </code>
     *
     * @param string $country
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array<int,array<string,string|null>>
     *
     * @see        RegionStats::listRegions
     * @deprecated since 2.4 use RegionStats::listRegions() instead
     */
    public function listRegions($country = '%%%%', $zero = '>', $order = 'items DESC')
    {
        return RegionStats::getInstance()->listRegions($country, $zero, $order);
    }

    /**
     * Returns number of ads from each city
     *
     * <code>
     *  Search::getInstance()->listCities($region, ">=", "city_name ASC" )
     * </code>
     *
     * @param int|string|null $region a region id, or %%%% for any
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array<int,array<string,string|null>>
     *
     * @see        CityStats::listCities
     * @deprecated since 2.4 use CityStats::listCities() instead
     */
    public function listCities($region = null, $zero = '>', $order = 'city_name ASC')
    {
        return CityStats::getInstance()->listCities($region, $zero, $order);
    }

    /**
     * Returns number of ads from each city area
     *
     * @param int|string|null $city a city id, or %%%% for any
     * @param string   $zero if you want to include locations with zero results
     * @param string   $order
     *
     * @return array<int,array<string,string|null>>
     */
    public function listCityAreas($city = null, $zero = '>', $order = 'items DESC')
    {
        // The sort column and the comparison come from fixed sets, never from caller text.
        $aOrder   = explode(' ', $order);
        $orderCol = preg_match('/^[A-Za-z0-9_.]+$/', $aOrder[0] ?? '') === 1 ? $aOrder[0] : 'items';
        $orderDir = (isset($aOrder[1]) && in_array(strtoupper($aOrder[1]), array('ASC', 'DESC'), true))
            ? strtoupper($aOrder[1]) : 'DESC';
        if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
            $zero = '>';
        }

        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT fk_i_city_area_id as city_area_id, s_city_area as city_area_name,'
            . ' fk_i_city_id, s_city as city_name, fk_i_region_id as region_id,'
            . ' s_region as region_name, fk_c_country_code as pk_c_code, s_country as country_name,'
            . ' count(*) as items'
            . ' FROM ' . $p . 't_item, ' . $p . 't_item_location, ' . $p . 't_category, ' . $p . 't_country'
            . ' WHERE ' . $p . 't_item.pk_i_id = ' . $p . 't_item_location.fk_i_item_id'
            . ' AND ' . $p . 't_item.b_enabled = 1'
            . ' AND ' . $p . 't_item.b_active = 1'
            . ' AND ' . $p . 't_item.b_spam = 0'
            . ' AND ' . $p . 't_category.b_enabled = 1'
            . ' AND ' . $p . 't_category.pk_i_id = ' . $p . 't_item.fk_i_category_id'
            . ' AND (' . $p . 't_item.b_premium = 1 || ' . $p . 't_category.i_expiration_days = 0'
            . ' || DATEDIFF(?, ' . $p . 't_item.dt_pub_date) < ' . $p . 't_category.i_expiration_days)'
            . ' AND fk_i_city_area_id IS NOT NULL'
            . ' AND ' . $p . 't_country.pk_c_code = fk_c_country_code';

        $params = array(date('Y-m-d H:i:s'));
        if ((int)$city !== 0) {
            $sql      .= ' AND fk_i_city_id = ?';
            $params[] = (int)$city;
        }

        $sql .= ' GROUP BY fk_i_city_area_id'
            . ' HAVING items ' . $zero . ' 0'
            . ' ORDER BY ' . $orderCol . ' ' . $orderDir;

        try {
            return Db::stringifyRows(Db::select($sql, $params));
        } catch (DbException $e) {
            return array();
        }
    }

    /**
     * The search's attributes as JSON. It is the key of core's result cache, and themes
     * and search backends read it; saved alerts no longer store it.
     *
     * @param bool $convert ignored since 6.4.0; kept so existing callers still work
     *
     * @return string
     */
    public function toJson($convert = false)
    {
        return SearchRecord::encode($this->parts);
    }

    /**
     * Restore a whole search from a stored alert's decoded JSON.
     *
     * @param array<string,mixed> $aData
     *
     * @return void
     */
    public function setJsonAlert($aData)
    {
        SearchRecord::restore($this, $this->parts, $aData);
    }

    /**
     * Filter by search pattern
     *
     * @param string $pattern
     *
     * @return void
     * @since  2.4
     */
    public function addPattern($pattern)
    {
        $this->parts->pattern->set($pattern);
    }

    /**
     * Filter by premium ad status
     *
     * @param bool $premium
     *
     * @return void
     * @since  3.2
     */
    public function onlyPremium($premium = false)
    {
        $this->parts->onlyPremium = $premium;
    }
}

/* file end: ./oc-includes/osclass/model/Search.php */
