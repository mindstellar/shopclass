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
 * Class Search
 */
class Search extends DAO
{
    private static $instance;
    private $conditions;
    private $itemConditions;
    private $liveConditions = array();
    private $tables; // ?
    private $tables_join;
    private $sql;
    private $order_column;
    private $order_direction;
    private $limit_init;
    private $results_per_page;
    private $cities;
    private $city_areas;
    private $regions;
    private $countries;
    private $categories;
    private $search_fields;
    private $total_results;
    private $total_results_table;
    private $sPattern;
    private $sPatternRaw;
    private $sEmail;
    private $groupBy;
    private $having;
    private $locale_code;
    private $userLocaleCode;
    private $withPattern;
    private $withPicture;
    private $withLocations;
    private $withCategoryId;
    private $withUserId;
    private $withItemId;
    private $withNoUserEmail;
    private $onlyPremium;
    private $price_min;
    private $price_max;
    private $user_ids;
    private $itemId;

    /**
     * Accumulated clauses for the statement currently being assembled.
     *
     * Search composes SQL as text rather than as bound parameters, and that is a
     * compatibility boundary rather than an oversight: the condition fragments are
     * serialized verbatim into t_alerts, handed to the sql_search_* plugin filters,
     * and parsed back by getConditions() with regexes that match their exact
     * spelling. Alerts already stored by earlier versions must keep round-tripping,
     * so the emitted text -- including its whitespace -- is preserved exactly.
     *
     * Cleared by resetQuery() once a statement has been compiled. notFromUser()
     * writes here before makeSQL() runs, so the state deliberately outlives a
     * single call.
     */
    private $qSelect = array();
    private $qFrom = array();
    private $qJoin = array();
    private $qWhere = array();
    private $qGroupBy = array();
    private $qHaving = array();
    private $qOrderBy = array();
    private $qLimit = false;
    private $qOffset = false;

    /**
     * @param bool $expired
     */
    public function __construct($expired = false)
    {
        parent::__construct();
        $this->setTableName('t_item');
        $this->setFields(array('pk_i_id'));

        $this->withPattern     = false;
        $this->withLocations   = false;
        $this->withCategoryId  = false;
        $this->withUserId      = false;
        $this->withPicture     = false;
        $this->withNoUserEmail = false;
        $this->onlyPremium     = false;

        $this->price_min = null;
        $this->price_max = null;

        $this->user_ids = null;
        $this->itemId   = null;
        $this->resetQuery();

        $this->city_areas     = array();
        $this->cities         = array();
        $this->regions        = array();
        $this->countries      = array();
        $this->categories     = array();
        $this->conditions     = array();
        $this->tables         = array();
        $this->tables_join    = array();
        $this->search_fields  = array();
        $this->itemConditions = array();
        $this->locale_code    = array();
        $this->groupBy        = '';
        $this->having         = '';

        $this->order();
        $this->limit();
        // Default page size. A Search that is never paged carries this into doSearch(),
        // so hydrating a known id set through this model silently truncates to 10 rows
        // unless the caller re-pages — use fromPrimaryKeys(), which pages to the id count.
        $this->results_per_page = 10;

        // The visibility predicate (see Item::liveConditions) — the same rule the category
        // counts use, sourced from one place so the two cannot drift about what is "live".
        // Held on the instance so includeHidden() can lift it for an admin or owner view.
        $this->liveConditions = Item::liveConditions(DB_TABLE_PREFIX . 't_item.');
        if (!$expired) {
            $this->addItemConditions($this->liveConditions);
        }
        $this->total_results       = null;
        $this->total_results_table = null;
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $this->userLocaleCode = osc_current_admin_locale();
        } else {
            $this->userLocaleCode = osc_current_user_locale();
        }

        // get all item_location data
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $this->addField(sprintf('%st_item_location.*', DB_TABLE_PREFIX));
        }
    }

    /**
     * Establish the order of the search
     *
     * @access public
     *
     * @param string $o_c column
     * @param string $o_d direction
     * @param string $table
     *
     * @since  unknown
     */
    public function order($o_c = '', $o_d = 'DESC', $table = null)
    {
        if ($o_c === '') {
            if ($this->withPattern) {
                $o_c = 'relevance';
            } else {
                $o_c = 'dt_pub_date';
            }
        }
        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$o_c)) {
            $o_c = $this->withPattern ? 'relevance' : 'dt_pub_date';
        }
        if ($table == '') {
            $this->order_column = $o_c;
        } elseif ($table != '') {
            if ($table === '%st_user') {
                $this->order_column =
                    sprintf("ISNULL($table.$o_c), $table.$o_c", DB_TABLE_PREFIX, DB_TABLE_PREFIX);
            } else {
                $this->order_column = sprintf("$table.$o_c", DB_TABLE_PREFIX);
            }
        }
        $this->order_direction = $o_d;
    }

    /**
     * Limit the results of the search
     *
     * @access public
     *
     * @param int  $l_i
     * @param null $r_p_p
     *
     * @since  unknown
     *
     */
    public function limit($l_i = 0, $r_p_p = null)
    {
        $this->limit_init = $l_i;
        if ($r_p_p !== null) {
            $this->results_per_page = $r_p_p;
        }
    }

    /**
     * Constrain the search to an explicit set of item ids and page to its length.
     *
     * For hydrating a match set produced elsewhere — an external search engine, a
     * plugin's own query — back through the core row-fetch (extendData, resources,
     * locale sub-array, the joined location/stats columns). It sizes the page to the
     * id count so the constructor's default of 10 cannot silently truncate the result,
     * which is the trap a manual hydration keeps rediscovering.
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
            // No ids means an empty result set, not "everything": constrain to nothing
            // and page to nothing so doSearch()/count() agree on zero.
            $this->addWhere('1 = 0');
            $this->limit(0, 0);

            return $this;
        }

        $list = implode(',', $ids);
        $this->addWhere(DB_TABLE_PREFIX . 't_item.pk_i_id IN (' . $list . ')');

        if ($preserveOrder) {
            // Keep the caller's ranking. A raw dao orderBy is folded in ahead of the
            // model's own order, so it decides the result order.
            $this->dao->orderBy('FIND_IN_SET(' . DB_TABLE_PREFIX . "t_item.pk_i_id, '" . $list . "')");
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
            $this->itemConditions = array_values(array_diff($this->itemConditions, $this->liveConditions));
        } else {
            $this->addItemConditions($this->liveConditions);
        }

        return $this;
    }

    /**
     * Add item conditions to the search
     *
     * @access public
     *
     * @param mixed $conditions
     *
     * @since  unknown
     */
    public function addItemConditions($conditions)
    {
        if (is_array($conditions)) {
            foreach ($conditions as $condition) {
                $condition = trim($condition);
                if (($condition) && !in_array($condition, $this->itemConditions)) {
                    $this->itemConditions[] = $condition;
                }
            }
        } else {
            $conditions = trim($conditions);
            if (($conditions) && !in_array($conditions, $this->itemConditions)) {
                $this->itemConditions[] = $conditions;
            }
        }
    }

    /**
     * Add new fields to the search
     *
     * @access public
     *
     * @param mixed $fields
     *
     * @since  unknown
     */
    public function addField($fields)
    {
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $field = trim($field);
                if (($field) && !in_array($field, $this->fields)) {
                    $this->search_fields[] = $field;
                }
            }
        } else {
            $fields = trim($fields);
            if (($fields) && !in_array($fields, $this->fields)) {
                $this->search_fields[] = $fields;
            }
        }
    }

    /**
     * @return \Search
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return an array with columns allowed for sorting
     *
     * @return array
     */
    public static function getAllowedColumnsForSorting()
    {
        return array('i_price', 'dt_pub_date', 'dt_expiration', 'relevance');
    }

    /**
     * Return an array with of sorting
     *
     * @return array
     */
    public static function getAllowedTypesForSorting()
    {
        return array(0 => 'asc', 1 => 'desc');
    }

    /**
     * Add conditions to the search
     *
     * @access public
     *
     * @param mixed $conditions
     *
     * @since  unknown
     */
    public function addConditions($conditions)
    {
        if (is_array($conditions)) {
            foreach ($conditions as $condition) {
                $condition = trim($condition);
                if (($condition) && !in_array($condition, $this->conditions)) {
                    $this->conditions[] = $condition;
                }
            }
        } else {
            $conditions = trim($conditions);
            if (($conditions) && !in_array($conditions, $this->conditions)) {
                $this->conditions[] = $conditions;
            }
        }
    }

    /**
     * Add locale conditions to the search
     *
     * @access public
     *
     * @param array|string $locales
     *
     * @since  3.2
     */
    public function addLocale($locales)
    {
        if (is_array($locales)) {
            foreach ($locales as $locale) {
                if ($locale) {
                    $this->locale_code[$locale] = $locale;
                }
            }
        } elseif ($locales) {
            $this->locale_code[$locales] = $locales;
        }
    }

    /**
     * Add extra table to the search
     *
     * @access public
     *
     * @param mixed $tables
     *
     * @since  unknown
     */
    public function addTable($tables)
    {
        if (is_array($tables)) {
            foreach ($tables as $table) {
                $table = trim($table);
                if (($table) && !in_array($table, $this->tables)) {
                    $this->tables[] = $table;
                }
            }
        } else {
            $tables = trim($tables);
            if (($tables) && !in_array($tables, $this->tables)) {
                $this->tables[] = $tables;
            }
        }
    }

    /**
     * Add group by to the search
     *
     * @access public
     *
     * @param $groupBy
     *
     * @since  unknown
     *
     */
    public function addGroupBy($groupBy)
    {
        $this->groupBy = $groupBy;
    }

    /**
     * Select the page of the search
     *
     * @access public
     *
     * @param int  $p page
     * @param null $r_p_p
     *
     * @since  unknown
     */
    public function page($p = 0, $r_p_p = null)
    {
        if ($r_p_p !== null) {
            $this->results_per_page = $r_p_p;
        }
        $this->limit_init = $this->results_per_page * $p;
    }

    /**
     * Add city areas to the search
     *
     * @access public
     *
     * @param mixed $city_area
     *
     * @since  unknown
     */
    public function addCityArea($city_area = array())
    {
        if (is_array($city_area)) {
            foreach ($city_area as $c) {
                $c = trim($c);
                if ($c) {
                    if (is_numeric($c)) {
                        $this->city_areas[] =
                            sprintf(
                                '%st_item_location.fk_i_city_area_id = %d ',
                                DB_TABLE_PREFIX,
                                $this->escapeValue($c)
                            );
                    } else {
                        $this->city_areas[] =
                            sprintf(
                                "%st_item_location.s_city_area LIKE %s ",
                                DB_TABLE_PREFIX,
                                $this->escapeValue($c)
                            );
                    }
                }
            }
        } else {
            $city_area = trim($city_area);
            if ($city_area) {
                if (is_numeric($city_area)) {
                    $this->city_areas[] =
                        sprintf(
                            '%st_item_location.fk_i_city_area_id = %d ',
                            DB_TABLE_PREFIX,
                            $this->escapeValue($city_area)
                        );
                } else {
                    $this->city_areas[] =
                        sprintf(
                            "%st_item_location.s_city_area LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->escapeValue($city_area)
                        );
                }
            }
        }
    }

    /**
     * Establish max price
     *
     * @access public
     *
     * @param int $price
     *
     * @since  unknown
     */
    public function priceMax($price)
    {
        $this->priceRange(null, $price);
    }

    /**
     * Establish price range
     *
     * @access public
     *
     * @param int $price_min
     * @param int $price_max
     *
     * @since  unknown
     */
    public function priceRange($price_min = 0, $price_max = 0)
    {
        $this->price_min = 1000000 * ((int)$price_min);
        $this->price_max = 1000000 * ((int)$price_max);
    }

    /**
     * Establish min price
     *
     * @access public
     *
     * @param int $price
     *
     * @since  unknown
     */
    public function priceMin($price)
    {
        $this->priceRange($price, null);
    }

    /**
     * Set having sentence to sql
     *
     * @param $having
     */
    public function addHaving($having)
    {
        $this->having = $having;
    }

    /**
     * Filter by email
     *
     * @access public
     *
     * @param $email
     *
     * @since  2.4
     */
    public function addContactEmail($email)
    {
        $this->withNoUserEmail = true;
        $this->sEmail          = $email;
    }

    /**
     * @param $id
     */
    public function notFromUser($id)
    {
        $this->addWhere(sprintf(
            '(%st_item.fk_i_user_id != %d || %st_item.fk_i_user_id IS NULL) ',
            DB_TABLE_PREFIX,
            $id,
            DB_TABLE_PREFIX
        ));
    }

    /**
     * @param $id
     */
    public function addItemId($id)
    {
        $this->withItemId = true;
        $this->itemId     = $id;
    }

    /**
     *  Add joins for future use
     *
     * @param string $key
     * @param string $table
     * @param string $condition
     * @param string $type
     *
     * @since 2.4
     */
    public function addJoinTable($key, $table, $condition, $type)
    {
        $this->tables_join[$key] = array($table, $condition, $type);
    }

    /**
     * Return number of ads selected
     *
     * @access public
     * @since  unknown
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
     * @access public
     *
     * @param bool $extended if you want to extend ad's data
     *
     * @param bool $count
     *
     * @return array
     * @since  unknown
     *
     */
    public function doSearch($extended = true, $count = true)
    {
        // The assembler still inlines its values (they are serialized verbatim
        // into t_alerts and read by the sql_search_conditions plugin filter, so
        // their format is a compatibility boundary), but execution now runs
        // through the parameterized Connection like every other model. makeSQL
        // produces complete SQL, so the params list is empty here.
        $sql       = $this->makeSQL();
        $mainError = false;
        try {
            $items = osc_db_stringify_rows(osc_db_select($sql));
        } catch (\mindstellar\database\DbException $e) {
            $items     = array();
            $mainError = true;
        }

        if ($count) {
            // Wrap the (unlimited) match query in COUNT(*) so the total is exact and
            // only one row crosses the wire, instead of fetching up to 100 pages of
            // ids and counting them client-side (which also capped the total).
            $sql = 'SELECT COUNT(*) AS total FROM (' . $this->makeSQL(true) . ') AS search_count';
            try {
                $row                 = osc_db_select_one($sql);
                $this->total_results = (int)($row['total'] ?? 0);
            } catch (\mindstellar\database\DbException $e) {
                $this->total_results = 0;
            }
        } else {
            $this->total_results = 0;
        }

        if ($mainError) {
            return array();
        }

        if (($extended === true) && !empty($items)) {
            return Item::newInstance()->extendData($items);
        }

        return $items;
    }

    /**
     * Make the SQL for the search with all the conditions and filters specified
     *
     * @access private
     *
     * @param bool $count
     *
     * @param bool $premium
     *
     * @return string
     * @since  unknown
     *
     */
    private function makeSQL($count = false, $premium = false)
    {
        $arrayConditions = $this->conditions();
        $extraFields     = $arrayConditions['extraFields'];
        $conditionsSQL   = $arrayConditions['conditionsSQL'];

        $sql = '';

        if ($this->withItemId) {
            // add field s_user_name
            $this->addSelect(sprintf(
                '%st_item.*, %st_item.s_contact_name as s_user_name',
                DB_TABLE_PREFIX,
                DB_TABLE_PREFIX
            ));
            $this->addFrom(sprintf('%st_item', DB_TABLE_PREFIX));
            $this->addWhere('pk_i_id', (int)$this->itemId);
        } else {
            if ($count) {
                $this->addSelect(DB_TABLE_PREFIX . 't_item.pk_i_id');
                $this->addSelect($extraFields); // plugins!
            } else {
                $this->addSelect(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                                   . 't_item.s_contact_name as s_user_name');
                $this->addSelect($extraFields); // plugins!
            }
            $this->addFrom(DB_TABLE_PREFIX . 't_item');

            if ($this->withNoUserEmail) {
                $this->addWhere(DB_TABLE_PREFIX . 't_item.s_contact_email', $this->sEmail);
            }

            if ($this->withPattern) {
                $this->addJoin(
                    DB_TABLE_PREFIX . 't_item_description as d',
                    'd.fk_i_item_id = ' . DB_TABLE_PREFIX . 't_item.pk_i_id',
                    'LEFT'
                );
                if ($this->ftUsable()) {
                    $bool = $this->booleanPattern();
                    if ($this->order_column === 'relevance') {
                        // Rank a title hit above a description-only hit: the
                        // combined index cannot weight columns, so add a
                        // title-only MATCH and score it double.
                        $this->addSelect(sprintf(
                            "(2 * MATCH(d.s_title) AGAINST(%s IN BOOLEAN MODE)"
                                               . " + MATCH(d.s_description, d.s_title) AGAINST(%s IN BOOLEAN MODE))"
                                               . " as relevance",
                            $bool,
                            $bool
                        ));
                        $this->addHavingClause(sprintf("relevance > %s", 0));
                    } else {
                        $this->addWhere(sprintf(
                            "MATCH(d.s_description, d.s_title) AGAINST(%s IN BOOLEAN MODE)",
                            $bool
                        ));
                    }
                } else {
                    // Every term is below the FULLTEXT min token size, so MATCH
                    // would return nothing: fall back to substring matching.
                    $this->addWhere($this->likePattern());
                    if ($this->order_column === 'relevance') {
                        $this->addSelect('1 as relevance');
                    }
                }
                if (empty($this->locale_code)) {
                    $this->locale_code[$this->userLocaleCode] = $this->userLocaleCode;
                }
                $this->addWhere(sprintf(
                    "( d.fk_c_locale_code LIKE '%s' )",
                    implode("' d.fk_c_locale_code LIKE '", $this->locale_code)
                ));
            }

            // item conditions
            if (count($this->itemConditions) > 0) {
                $itemConditions = implode(
                    ' AND ',
                    osc_apply_filter('sql_search_item_conditions', $this->itemConditions)
                );
                $this->addWhere($itemConditions);
            }
            if ($this->withCategoryId && (count($this->categories) > 0)) {
                $this->addWhere(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }
            if ($this->withUserId) {
                $this->addFromUser();
            }
            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->addJoin(
                    sprintf('%st_item_location', DB_TABLE_PREFIX),
                    sprintf(
                        '%st_item_location.fk_i_item_id = %st_item.pk_i_id',
                        DB_TABLE_PREFIX,
                        DB_TABLE_PREFIX
                    ),
                    'LEFT'
                );
                $this->addLocations();
            }
            if ($this->withPicture) {
                $this->addJoin(
                    sprintf('%st_item_resource', DB_TABLE_PREFIX),
                    sprintf(
                        '%st_item_resource.fk_i_item_id = %st_item.pk_i_id',
                        DB_TABLE_PREFIX,
                        DB_TABLE_PREFIX
                    ),
                    'LEFT'
                );
                $this->addWhere(sprintf(
                    "%st_item_resource.s_content_type LIKE '%%image%%' ",
                    DB_TABLE_PREFIX
                ));
                $this->addGroupByClause(DB_TABLE_PREFIX . 't_item.pk_i_id');
            }
            if ($this->onlyPremium) {
                $this->addWhere(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            }
            $this->addPriceRange();

            // add joinTables
            $this->joinTable();

            // PLUGINS TABLES !!
            if (!empty($this->tables)) {
                $tables = implode(', ', $this->tables);
                $this->addFrom($tables);
            }
            // WHERE PLUGINS extra conditions
            if (count($this->conditions) > 0) {
                $this->addWhere($conditionsSQL);
            }
            // ---------------------------------------------------------
            // groupBy
            if ($this->groupBy) {
                $this->addGroupByClause($this->groupBy);
            }
            // having
            if ($this->having) {
                $this->addHavingClause($this->having);
            }
            // ---------------------------------------------------------

            // order & limit — neither matters when we only need COUNT(*), and dropping
            // the limit is what makes the wrapped count exact instead of capped.
            if (!$count) {
                $this->addOrderBy($this->order_column, $this->order_direction);
                $this->addLimit($this->limit_init, $this->results_per_page);
            }

            // Fold in anything a caller added straight onto $this->dao (the legacy
            // DBCommandClass API), which this builder no longer reads on its own.
            $this->mergeDaoConditions($count);
        }

        $this->sql = $this->compileQuery();
        // reset dao attributes
        $this->resetQuery();

        return $this->sql;
    }

    /**
     * Create extraFields & conditionsSQL and return as an array
     *
     * @return array with extraFields & conditions strings
     */
    private function conditions()
    {
        if (count($this->city_areas) > 0) {
            $this->withLocations = true;
        }

        if (count($this->cities) > 0) {
            $this->withLocations = true;
        }

        if (count($this->regions) > 0) {
            $this->withLocations = true;
        }

        if (count($this->countries) > 0) {
            $this->withLocations = true;
        }

        if (count($this->categories) > 0) {
            $this->withCategoryId = true;
        }

        $conditionsSQL =
            implode(' AND ', osc_apply_filter('sql_search_conditions', $this->conditions));
        if ($conditionsSQL != '') {
            $conditionsSQL = ' ' . $conditionsSQL;
        }

        $extraFields = '';
        if (count($this->search_fields) > 0) {
            $extraFields = ',';
            $extraFields .= implode(
                ' ,',
                osc_apply_filter('sql_search_fields', $this->search_fields)
            );
        }

        return array(
            'extraFields'   => $extraFields,
            'conditionsSQL' => $conditionsSQL
        );
    }

    private function addFromUser()
    {
        $this->addJoin(DB_TABLE_PREFIX.'t_user', DB_TABLE_PREFIX.'t_user.pk_i_id = '.DB_TABLE_PREFIX.'t_item.fk_i_user_id', 'LEFT');
        if (is_array($this->user_ids)) {
            $this->addWhere(' ( ' . implode(' || ', $this->user_ids) . ' ) ');
        } else {
            $this->addWhere(sprintf(
                '%st_item.fk_i_user_id = %d ',
                DB_TABLE_PREFIX,
                $this->user_ids
            ));
        }
    }

    private function addLocations()
    {
        if (count($this->city_areas) > 0) {
            $this->addWhere('( ' . implode(' || ', $this->city_areas) . ' )');
        }
        if (count($this->cities) > 0) {
            $this->addWhere('( ' . implode(' || ', $this->cities) . ' )');
        }
        if (count($this->regions) > 0) {
            $this->addWhere('( ' . implode(' || ', $this->regions) . ' )');
        }
        if (count($this->countries) > 0) {
            $this->addWhere('( ' . implode(' || ', $this->countries) . ' )');
        }
    }

    private function addPriceRange()
    {
        if (is_numeric($this->price_min) && $this->price_min != 0) {
            $this->addWhere(sprintf('i_price >= %0.0f', $this->price_min));
        }
        if (is_numeric($this->price_max) && $this->price_max > 0) {
            $this->addWhere(sprintf('i_price <= %0.0f', $this->price_max));
        }
    }

    /**
     * Add join to current query
     *
     * @since 2.4
     */
    private function joinTable()
    {
        foreach ($this->tables_join as $tJoin) {
            $this->addJoin($tJoin[0], $tJoin[1], $tJoin[2]);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Statement assembly                                                 *
     *                                                                     *
     *  Search builds SQL text, so these reproduce the emitted spelling    *
     *  exactly -- including the quirks callers and stored alerts already  *
     *  depend on: a two-space "LEFT  JOIN", comma-separated tables        *
     *  compiled as CROSS JOIN, and the trailing space a valueless HAVING  *
     *  leaves behind.                                                     *
     * ------------------------------------------------------------------ */

    /**
     * Quote a value for inclusion in SQL text.
     *
     * Numbers pass through unquoted unless they carry a leading zero, which would
     * otherwise be lost; strings are driver-escaped and quoted; booleans become
     * 1/0 and null becomes NULL.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function escapeValue($value)
    {
        if (is_numeric($value)) {
            if (strlen($value) > 1 && strpos($value, '0') === 0) {
                return "'" . $value . "'";
            }

            return $value;
        }
        if (is_string($value)) {
            return "'" . $this->escapeString($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (null === $value) {
            return 'NULL';
        }

        return $value;
    }

    /**
     * Driver-level string escaping, without the surrounding quotes.
     *
     * @param string $value
     *
     * @return string
     */
    private function escapeString($value)
    {
        return \mindstellar\database\Connection::instance()->escape((string)$value);
    }

    /**
     * Whether a fragment already carries its own operator, in which case it is
     * emitted as written instead of having " =" appended.
     *
     * @param string $str
     *
     * @return bool
     */
    private function hasOperator($str)
    {
        return preg_match('/(\s|<|>|!|=|is null|is not null)/i', trim((string)$str)) === 1;
    }

    /**
     * @param string|array $select comma-separated list or array of expressions
     *
     * @return void
     */
    private function addSelect($select = '*')
    {
        if (is_string($select)) {
            $select = explode(',', $select);
        }
        foreach ($select as $s) {
            $s = trim($s);
            if ($s != '') {
                $this->qSelect[] = $s;
            }
        }
    }

    /**
     * @param string|array $from
     *
     * @return void
     */
    private function addFrom($from)
    {
        if (!is_array($from)) {
            if (strpos($from, '(') !== false && strpos($from, ')') !== false) {
                // A subquery: never split, its own commas are not table separators.
                $from = array($from);
            } elseif (strpos($from, ',') !== false) {
                $from = explode(',', $from);
            } else {
                $from = array($from);
            }
        }
        foreach ($from as $f) {
            $this->qFrom[] = $f;
        }
    }

    /**
     * @param string $table
     * @param string $cond
     * @param string $type LEFT, RIGHT, OUTER, INNER, LEFT OUTER or RIGHT OUTER
     *
     * @return void
     */
    private function addJoin($table, $cond, $type = '')
    {
        if ($type != '') {
            $type = strtoupper(trim($type));
            $type = in_array($type, array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER'))
                ? $type . ' '
                : '';
        }

        $this->qJoin[] = $type . ' JOIN ' . $table . ' ON ' . $cond;
    }

    /**
     * @param string|array $key   fragment, or column when $value is supplied
     * @param mixed        $value bound-by-value; escaped into the text
     *
     * @return void
     */
    private function addWhere($key, $value = null)
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }
        foreach ($key as $k => $v) {
            $prefix = (count($this->qWhere) > 0) ? 'AND ' : '';
            if (!$this->hasOperator($k)) {
                $k .= ' =';
            }
            if (null !== $v) {
                $v = ' ' . $this->escapeValue($v);
            }
            $this->qWhere[] = $prefix . $k . $v;
        }
    }

    /**
     * @param string|array $by
     *
     * @return void
     */
    private function addGroupByClause($by)
    {
        if (is_string($by)) {
            $by = explode(',', $by);
        }
        foreach ($by as $val) {
            $val = trim($val);
            if ($val != '') {
                $this->qGroupBy[] = $val;
            }
        }
    }

    /**
     * @param string|array $key
     * @param string       $value
     *
     * @return void
     */
    private function addHavingClause($key, $value = '')
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }
        foreach ($key as $k => $v) {
            $prefix = (count($this->qHaving) === 0) ? '' : 'AND ';
            if (!$this->hasOperator($k)) {
                $k .= ' = ';
            }
            $this->qHaving[] = $prefix . $k . ' ' . $this->escapeString($v);
        }
    }

    /**
     * @param string $orderby
     * @param string $direction ASC, DESC or 'random'
     *
     * @return void
     */
    private function addOrderBy($orderby, $direction = '')
    {
        if (strtolower($direction) === 'random') {
            $direction = ' RAND()';
        } elseif (trim($direction)) {
            $direction = in_array(strtoupper(trim($direction)), array('ASC', 'DESC')) ? ' ' . $direction : ' ASC';
        }

        $this->qOrderBy[] = $orderby . $direction;
    }

    /**
     * Bridge conditions added directly on $this->dao into the internal builder.
     *
     * Before this model assembled its own statement, callers filtered a search by
     * calling $oSearch->dao->where()/orderBy()/select()/join()/having()/groupBy()
     * and the dao compiled them in. The builder no longer touches $this->dao, so
     * those clauses were being dropped — silently returning an unfiltered result
     * (e.g. a theme hydrating a Manticore id list via dao->where('... IN (...)')).
     * Fold them back in here so that contract keeps working.
     *
     * The dao's own first WHERE carries no boolean connector, so add one when it
     * lands after clauses the model already built. ORDER BY goes to the FRONT so a
     * caller ordering stays primary (a FIND_IN_SET() preserving an external rank
     * must win over the model's default), and is skipped for a COUNT(*) wrap.
     *
     * @param bool $count
     *
     * @return void
     */
    private function mergeDaoConditions($count = false)
    {
        $dao = $this->dao;
        if (!$dao instanceof DBCommandClass) {
            return;
        }

        foreach ((array) $dao->aSelect as $s) {
            $s = trim($s);
            if ($s !== '') {
                $this->qSelect[] = $s;
            }
        }
        foreach ((array) $dao->aJoin as $j) {
            $j = trim($j);
            if ($j !== '') {
                $this->qJoin[] = $j;
            }
        }
        foreach ((array) $dao->aGroupby as $g) {
            $g = trim($g);
            if ($g !== '') {
                $this->qGroupBy[] = $g;
            }
        }
        foreach ((array) $dao->aHaving as $h) {
            $h = trim($h);
            if ($h !== '') {
                $this->qHaving[] = $h;
            }
        }
        foreach ((array) $dao->aWhere as $w) {
            $w = trim($w);
            if ($w === '') {
                continue;
            }
            if (count($this->qWhere) > 0 && !preg_match('/^(AND|OR)\b/i', $w)) {
                $w = 'AND ' . $w;
            }
            $this->qWhere[] = $w;
        }
        if (!$count) {
            $daoOrder = array();
            foreach ((array) $dao->aOrderby as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $daoOrder[] = $o;
                }
            }
            if ($daoOrder !== array()) {
                $this->qOrderBy = array_merge($daoOrder, $this->qOrderBy);
            }
        }
    }

    /**
     * Row window. Compiles to MySQL's comma form, "LIMIT <count>, <offset>", which
     * is how the previous layer emitted it: the first argument lands in the
     * clause's leading position and the second in its trailing one.
     *
     * @param int        $value
     * @param int|string $offset
     *
     * @return void
     */
    private function addLimit($value, $offset = '')
    {
        if (is_numeric($value)) {
            $this->qLimit = (int)$value;
        }
        if ($offset != '') {
            $this->qOffset = is_numeric($offset) ? (int)$offset : 0;
        }
    }

    /**
     * Compile the accumulated clauses into a SELECT statement.
     *
     * @return string
     */
    private function compileQuery()
    {
        $sql = 'SELECT ';
        $sql .= (count($this->qSelect) === 0) ? '*' : implode(', ', $this->qSelect);

        if (count($this->qFrom) > 0) {
            $sql .= "\nFROM ";
            // More than one table is a cross join, which is what the comma form means.
            $sql .= (count($this->qFrom) > 1)
                ? implode(' CROSS JOIN ', $this->qFrom)
                : implode(', ', $this->qFrom);
        }

        if (count($this->qJoin) > 0) {
            $sql .= "\n" . implode("\n", $this->qJoin);
        }

        if (count($this->qWhere) > 0) {
            $sql .= "\nWHERE ";
        }
        $sql .= implode("\n", $this->qWhere);

        if (count($this->qGroupBy) > 0) {
            $sql .= "\nGROUP BY " . implode(', ', $this->qGroupBy);
        }
        if (count($this->qHaving) > 0) {
            $sql .= "\nHAVING " . implode(', ', $this->qHaving);
        }
        if (count($this->qOrderBy) > 0) {
            $sql .= "\nORDER BY " . implode(', ', $this->qOrderBy);
        }
        if (is_numeric($this->qLimit)) {
            $sql .= "\nLIMIT " . $this->qLimit;
            if ($this->qOffset > 0) {
                $sql .= ', ' . $this->qOffset;
            }
        }

        return $sql;
    }

    /**
     * Drop every accumulated clause, so the next statement starts clean.
     *
     * @return void
     */
    private function resetQuery()
    {
        $this->qSelect  = array();
        $this->qFrom    = array();
        $this->qJoin    = array();
        $this->qWhere   = array();
        $this->qGroupBy = array();
        $this->qHaving  = array();
        $this->qOrderBy = array();
        $this->qLimit   = false;
        $this->qOffset  = false;
    }

    /**
     * Return total items on t_item without any filter
     *
     * @return null
     */
    public function countAll()
    {
        if (null === $this->total_results_table) {
            try {
                $row                       = osc_db_select_one('SELECT COUNT(*) AS total FROM ' . DB_TABLE_PREFIX . 't_item');
                $this->total_results_table = $row === null ? null : (string)$row['total'];
            } catch (\mindstellar\database\DbException $e) {
                // Leave the memo null so a later call retries, as the legacy
                // recordset-instanceof check did on a failed query.
                $this->total_results_table = null;
            }
        }

        return $this->total_results_table;
    }

    /**
     * solo acepta pattern + location + stats, category
     *
     * @param int $max
     *
     * @return array
     */
    public function getPremiums($max = 2)
    {
        $premium_sql = $this->makeSQLPremium($max); // make premium sql

        try {
            $items = osc_db_stringify_rows(osc_db_select($premium_sql));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if (!empty($items)) {
            // The premium block renders on the home page, every category page and
            // every search page, so this is the most frequently executed write on
            // the site. One statement for the whole block rather than one per
            // listing, and only when the request is a reader rather than a crawler
            // — this path had no such check at all, so bots drove both the counter
            // and the write load.
            if (osc_request_counts_as_view()) {
                ItemStats::newInstance()->increaseBatch(
                    'i_num_premium_views',
                    array_column($items, 'pk_i_id')
                );
            }

            return Item::newInstance()->extendData($items);
        }

        return array();
    }

    /**
     * Only search by pattern + location + category
     *
     * @param int $num
     *
     * @return string
     */
    private function makeSQLPremium($num = 2)
    {
        $arrayConditions = $this->conditions();

        if ($this->withPattern) {
            // sub select for JOIN ----------------------
            $this->addSelect('distinct d.fk_i_item_id');
            $this->addFrom(DB_TABLE_PREFIX . 't_item_description as d');
            $this->addFrom(DB_TABLE_PREFIX . 't_item as ti');
            $this->addWhere('ti.pk_i_id = d.fk_i_item_id');
            $this->addWhere(sprintf(
                "MATCH(d.s_description, d.s_title) AGAINST(%s IN BOOLEAN MODE)",
                $this->booleanPattern()
            ));
            $this->addWhere('ti.b_premium = 1');

            if (empty($this->locale_code)) {
                if (defined('OC_ADMIN') && OC_ADMIN) {
                    $this->locale_code[osc_current_admin_locale()] = osc_current_admin_locale();
                } else {
                    $this->locale_code[osc_current_user_locale()] = osc_current_user_locale();
                }
            }
            $this->addWhere(sprintf(
                "( d.fk_c_locale_code LIKE '%s' )",
                implode("' d.fk_c_locale_code LIKE '", $this->locale_code)
            ));

            $subSelect = $this->compileQuery();
            $this->resetQuery();
            // END sub select ----------------------
            $this->addSelect(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                               . 't_item.s_contact_name as s_user_name');
            $this->addFrom(DB_TABLE_PREFIX . 't_item');
            $this->addFrom(sprintf('%st_item_stats', DB_TABLE_PREFIX));
            $this->addWhere(sprintf(
                '%st_item_stats.fk_i_item_id = %st_item.pk_i_id',
                DB_TABLE_PREFIX,
                DB_TABLE_PREFIX
            ));
            $this->addWhere(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_enabled = 1 ', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_active = 1 ', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_spam = 0', DB_TABLE_PREFIX));

            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->addJoin(
                    sprintf('%st_item_location', DB_TABLE_PREFIX),
                    sprintf(
                        '%st_item_location.fk_i_item_id = %st_item.pk_i_id',
                        DB_TABLE_PREFIX,
                        DB_TABLE_PREFIX
                    ),
                    'LEFT'
                );
                $this->addLocations();
            }
            if ($this->withCategoryId && (count($this->categories) > 0)) {
                $this->addWhere(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }
            $this->addWhere(DB_TABLE_PREFIX . 't_item.pk_i_id IN (' . $subSelect . ')');

            // Least-shown first, so the block rotates. The stats row holds the
            // running total and there is exactly one per listing, so neither the
            // SUM nor the GROUP BY that used to collapse a listing's dated rows
            // is needed to read it.
            $this->addOrderBy(
                sprintf('%st_item_stats.i_num_premium_views', DB_TABLE_PREFIX),
                'ASC'
            );
            $this->addOrderBy(null, 'random');
            $this->addLimit(0, $num);
        } else {
            $this->addSelect(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                               . 't_item.s_contact_name as s_user_name');
            $this->addFrom(DB_TABLE_PREFIX . 't_item');
            $this->addFrom(sprintf('%st_item_stats', DB_TABLE_PREFIX));
            $this->addWhere(sprintf(
                '%st_item_stats.fk_i_item_id = %st_item.pk_i_id',
                DB_TABLE_PREFIX,
                DB_TABLE_PREFIX
            ));
            $this->addWhere(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_enabled = 1 ', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_active = 1 ', DB_TABLE_PREFIX));
            $this->addWhere(sprintf('%st_item.b_spam = 0', DB_TABLE_PREFIX));

            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->addJoin(
                    sprintf('%st_item_location', DB_TABLE_PREFIX),
                    sprintf(
                        '%st_item_location.fk_i_item_id = %st_item.pk_i_id',
                        DB_TABLE_PREFIX,
                        DB_TABLE_PREFIX
                    ),
                    'LEFT'
                );
                $this->addLocations();
            }
            if ($this->withCategoryId && (count($this->categories) > 0)) {
                $this->addWhere(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }

            // Least-shown first, so the block rotates. The stats row holds the
            // running total and there is exactly one per listing, so neither the
            // SUM nor the GROUP BY that used to collapse a listing's dated rows
            // is needed to read it.
            $this->addOrderBy(
                sprintf('%st_item_stats.i_num_premium_views', DB_TABLE_PREFIX),
                'ASC'
            );
            $this->addOrderBy(null, 'random');
            $this->addLimit(0, $num);
        }

        $sql = $this->compileQuery();
        // reset dao attributes
        $this->resetQuery();

        return $sql;
    }

    /**
     * Return latest posted items, you can filter by category and specify the
     * number of items returned.
     *
     * @param int   $numItems
     * @param mixed $options
     * @param bool  $withPicture
     *
     * @return array
     */
    public function getLatestItems($numItems = 10, $options = array(), $withPicture = false)
    {
        $key         =
            md5(osc_cache_search_generation() . osc_base_url() . (string)$numItems . json_encode($options) . (string)$withPicture);
        $found       = null;
        $latestItems = osc_cache_get($key, $found);
        if ($latestItems === false) {
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
            $return = $this->doSearch();
            osc_cache_set($key, $return, OSC_CACHE_TTL);

            return $return;
        }

        return $latestItems;
    }

    /**
     * Limit the results of the search
     *
     * @access public
     *
     * @param $r_p_p
     *
     * @since  unknown
     */
    public function set_rpp($r_p_p)
    {
        $this->results_per_page = $r_p_p;
    }

    /**
     * Filter by ad with picture or not
     *
     * @access public
     *
     * @param bool $pic
     *
     * @since  unknown
     */
    public function withPicture($pic = false)
    {
        $this->withPicture = $pic;
    }

    /**
     * Add categories to the search
     *
     * @access public
     *
     * @param mixed $category
     *
     * @return bool
     * @since  unknown
     *
     */
    public function addCategory($category = null)
    {
        if ($category == null) {
            return false;
        }

        if (!is_numeric($category)) {
            $category  = preg_replace('|/$|', '', $category);
            $aCategory = explode('/', $category);
            $category  = Category::newInstance()->findBySlug($aCategory[count($aCategory) - 1]);

            if (count($category) == 0) {
                return false;
            }

            $category = $category['pk_i_id'];
        }
        $tree = Category::newInstance()->toSubTree($category);
        if (!in_array($category, $this->categories)) {
            $this->categories[] = $category;
        }
        $this->pruneBranches($tree);

        return true;
    }

    /**
     * Clear the categories
     *
     * @access private
     *
     * @param array $branches
     *
     * @since  unknown
     */
    private function pruneBranches($branches = null)
    {
        if ($branches != null) {
            foreach ($branches as $branch) {
                if (!in_array($branch['pk_i_id'], $this->categories)) {
                    $this->categories[] = $branch['pk_i_id'];
                    if (isset($branch['categories'])) {
                        $this->pruneBranches($branch['categories']);
                    }
                }
            }
        }
    }

    /**
     * Add countries to the search
     *
     * @access public
     *
     * @param mixed $country
     *
     * @since  unknown
     */
    public function addCountry($country = array())
    {
        $prepareConditions = function ($country) {
            $country = trim($country);
            if ($country) {
                if (strlen($country) === 2) {
                    $this->countries[] =
                        sprintf(
                            "%st_item_location.fk_c_country_code = %s ",
                            DB_TABLE_PREFIX,
                            strtolower($this->escapeValue($country))
                        );
                } else {
                    $this->countries[] =
                        sprintf(
                            "%st_item_location.s_country LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->escapeValue($country)
                        );
                }
            }
        };
        if (is_array($country)) {
            foreach ($country as $c) {
                $prepareConditions($c);
            }
        } else {
            $prepareConditions($country);
        }
    }

    /**
     * Add regions to the search
     *
     * @access public
     *
     * @param mixed $region
     *
     * @since  unknown
     */
    public function addRegion($region = array())
    {
        $prepareConditions = function ($region) {
            $region = trim($region);
            if ($region) {
                if (is_numeric($region)) {
                    $this->regions[] =
                        sprintf(
                            '%st_item_location.fk_i_region_id = %d ',
                            DB_TABLE_PREFIX,
                            $this->escapeValue($region)
                        );
                } else {
                    $this->regions[] =
                        sprintf(
                            "%st_item_location.s_region LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->escapeValue($region)
                        );
                }
            }
        };
        if (is_array($region)) {
            foreach ($region as $r) {
                $prepareConditions($r);
            }
        } else {
            $prepareConditions($region);
        }
    }

    /**
     * Add cities to the search
     *
     * @access public
     *
     * @param array|string|int $city
     *
     * @since  unknown
     */
    public function addCity($city = array())
    {
        $prepareConditions = function ($city) {
            $city = trim($city);
            if ($city) {
                if (is_numeric($city)) {
                    $this->cities[] =
                        sprintf(
                            '%st_item_location.fk_i_city_id = %d ',
                            DB_TABLE_PREFIX,
                            $this->escapeValue($city)
                        );
                } else {
                    $this->cities[] =
                        sprintf(
                            "%st_item_location.s_city LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->escapeValue($city)
                        );
                }
            }
        };
        if (is_array($city)) {
            foreach ($city as $c) {
                $prepareConditions($c);
            }
        } else {
            $prepareConditions($city);
        }
    }

    /**
     * Return ads from specified users
     *
     * @access public
     *
     * @param array|string|int $id
     *
     * @since  unknown
     */
    public function fromUser($id = null)
    {
        if (is_array($id)) {
            $this->withUserId = true;
            $ids              = array();
            foreach ($id as $_id) {
                if (!is_numeric($_id)) {
                    $user = User::newInstance()->findByUsername($_id);
                    if (isset($user['pk_i_id'])) {
                        $ids[] = sprintf(
                            '%st_item.fk_i_user_id = %d ',
                            DB_TABLE_PREFIX,
                            $this->escapeValue($user['pk_i_id'])
                        );
                    }
                } else {
                    $ids[] = sprintf('%st_item.fk_i_user_id = %d ', DB_TABLE_PREFIX, $_id);
                }
            }
            $this->user_ids = $ids;
        } else {
            $this->withUserId = true;
            if (!is_numeric($id)) {
                $user = User::newInstance()->findByUsername($id);
                if (isset($user['pk_i_id'])) {
                    $this->user_ids = $this->escapeValue($user['pk_i_id']);
                }
            } else {
                $this->user_ids = $this->escapeValue($id);
            }
        }
    }

    /**
     * Returns number of ads from each country
     *
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array
     * @since  unknown
     *
     * @deprecated
     * @access public
     */
    public function listCountries($zero = '>', $order = 'items DESC')
    {
        return CountryStats::newInstance()->listCountries($zero, $order);
    }

    /**
     * Returns number of ads from each region
     * <code>
     *  Search::newInstance()->listRegions($country, ">=", "country_name ASC" )
     * </code>
     *
     * @param string $country
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array
     * @since  unknown
     *
     * @deprecated
     * @access public
     */
    public function listRegions($country = '%%%%', $zero = '>', $order = 'items DESC')
    {
        return RegionStats::newInstance()->listRegions($country, $zero, $order);
    }

    /**
     * Returns number of ads from each city
     *
     * <code>
     *  Search::newInstance()->listCities($region, ">=", "city_name ASC" )
     * </code>
     *
     * @param string $region
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array
     * @since  unknown
     *
     * @deprecated
     * @access public
     */
    public function listCities($region = null, $zero = '>', $order = 'city_name ASC')
    {
        return CityStats::newInstance()->listCities($region, $zero, $order);
    }

    /**
     * Returns number of ads from each city area
     *
     * @access public
     *
     * @param string $city
     * @param string $zero if you want to include locations with zero results
     * @param string $order
     *
     * @return array
     * @since  unknown
     *
     */
    public function listCityAreas($city = null, $zero = '>', $order = 'items DESC')
    {
        // Validate the sort and the comparison operator against fixed sets
        // before they reach the SQL text — the same identifiers the location
        // stats listers allowlist. Callers pass literals today; this keeps that
        // the only thing that can reach ORDER BY / HAVING.
        $aOrder    = explode(' ', $order);
        $orderCol  = preg_match('/^[A-Za-z0-9_.]+$/', $aOrder[0] ?? '') === 1 ? $aOrder[0] : 'items';
        $orderDir  = (isset($aOrder[1]) && in_array(strtoupper($aOrder[1]), array('ASC', 'DESC'), true))
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
            // The premium/expiry test is one fully-parenthesised OR group, so it
            // carries no precedence hazard. The cut-off timestamp is bound.
            . ' AND (' . $p . 't_item.b_premium = 1 || ' . $p . 't_category.i_expiration_days = 0'
            . ' || DATEDIFF(?, ' . $p . 't_item.dt_pub_date) < ' . $p . 't_category.i_expiration_days)'
            . ' AND fk_i_city_area_id IS NOT NULL'
            . ' AND ' . $p . 't_country.pk_c_code = fk_c_country_code';

        $params = array(date('Y-m-d H:i:s'));

        $city_int = (int)$city;
        if ($city_int !== 0) {
            // int-cast, so it is a literal integer, not caller text.
            $sql .= ' AND fk_i_city_id = ' . $city_int;
        }

        $sql .= ' GROUP BY fk_i_city_area_id'
            . ' HAVING items ' . $zero . ' 0'
            . ' ORDER BY ' . $orderCol . ' ' . $orderDir;

        try {
            return osc_db_stringify_rows(osc_db_select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    /**
     * Return json with all search attributes
     *
     * @param bool $convert
     *
     * @return string
     */
    public function toJson($convert = false)
    {
        if ($convert) {
            $aData = $this->getConditions();
        } else {
            $aData['price_min']   = $this->price_min / 1000000;
            $aData['price_max']   = $this->price_max / 1000000;
            $aData['aCategories'] = $this->categories;
            // locations
            $aData['city_areas'] = $this->city_areas;
            $aData['cities']     = $this->cities;
            $aData['regions']    = $this->regions;
            $aData['countries']  = $this->countries;
            // pattern
            $aData['withPattern'] = $this->withPattern;
            // Serialise the raw pattern, not the escaped/quoted sPattern: this record is
            // search criteria, and setJsonAlert() re-escapes it through addPattern() on
            // replay. Storing the escaped form escaped it twice each round trip, which
            // shifted the matched set (visible on the short-term LIKE path where the stray
            // quotes survive into LIKE '%…%').
            $aData['sPattern']    = $this->sPatternRaw !== null ? $this->sPatternRaw : $this->sPattern;
            if ($this->withPicture) {
                $aData['withPicture'] = $this->withPicture;
            }

            if ($this->onlyPremium) {
                $aData['onlyPremium'] = $this->onlyPremium;
            }

            $aData['tables']      = $this->tables;
            $aData['tables_join'] = $this->tables_join;

            $aData['no_catched_tables']     = $this->tables;
            $aData['no_catched_conditions'] = $this->conditions;

            $aData['user_ids'] = $this->user_ids;

            // get order & limit
            $aData['order_column']     = $this->order_column;
            $aData['order_direction']  = $this->order_direction;
            $aData['limit_init']       = $this->limit_init;
            $aData['results_per_page'] = $this->results_per_page;
        }

        return json_encode($aData);
    }

    /**
     * Given the current search object, extract search parameters & conditions
     * as array.
     *
     * @return array
     */
    private function getConditions()
    {
        $aData = array();

        $item_id             = DB_TABLE_PREFIX . 't_item.pk_i_id';
        $item_category_id    = DB_TABLE_PREFIX . 't_item.fk_i_category_id';
        $item_description_id = 'd.fk_i_item_id';
        $category_id         = DB_TABLE_PREFIX . 't_category.pk_i_id';
        $item_location_id    = DB_TABLE_PREFIX . 't_item_location.fk_i_item_id';
        $item_resource_id    = DB_TABLE_PREFIX . 't_item_resource.fk_i_item_id';

        // get item conditions
        foreach ($this->conditions as $condition) {
            // item table
            if (preg_match('/' . DB_TABLE_PREFIX . 't_item\.b_active/', $condition, $matches)) {
                $aData['itemConditions'][] = $condition;
            } elseif (preg_match('/' . DB_TABLE_PREFIX . 't_item\.b_spam/', $condition, $matches)) {
                $aData['itemConditions'][] = $condition;
            } elseif (preg_match(
                '/' . DB_TABLE_PREFIX . 't_item\.b_enabled/',
                $condition,
                $matches
            )
            ) {
                $aData['itemConditions'][] = $condition;
            } elseif (preg_match(
                '/' . DB_TABLE_PREFIX . 't_item\.b_premium/',
                $condition,
                $matches
            )
            ) {
                $aData['itemConditions'][] = $condition;
            } elseif (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_item\.)?f_price >= (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['price_min'] = (int)$matches[2];
            } elseif (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_item\.)?f_price <= (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['price_max'] = (int)$matches[2];
            } elseif (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_item\.)?i_price >= (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['price_min'] = ((float)$matches[2] / 1000000);
            } elseif (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_item\.)?i_price <= (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['price_max'] = ((float)$matches[2] / 1000000);
            } elseif (preg_match_all(
                '/(' . DB_TABLE_PREFIX
                . 't_item_location.s_city_area\s*LIKE\s*\'%([\s\p{L}\p{N}]*)%\'\s*)/u',
                $condition,
                $matches
            )
            ) { // OJO
                // Comprobar: si ( s_name existe ) then get location id,
                $aData['s_city_area'][] =
                    DB_TABLE_PREFIX . 't_item_location.s_city_area LIKE \'%' . $matches[2][0]
                    . '%\'';
            } elseif (preg_match('/' . DB_TABLE_PREFIX
                                 . 't_item_location.fk_i_city_area_id = (.*)/', $condition, $matches)
            ) {
                $aData['fk_i_city_area_id'][] =
                    DB_TABLE_PREFIX . 't_item_location.fk_i_city_area_id = ' . $matches[1];
            } elseif (preg_match_all(
                '/(' . DB_TABLE_PREFIX
                . 't_item_location.s_city\s*LIKE\s*\'%([\s\p{L}\p{N}]*)%\'\s*)/u',
                $condition,
                $matches
            )
            ) { // OJO
                // Comprobar: si ( s_name existe ) then get location id,
                $aData['cities'][] =
                    DB_TABLE_PREFIX . 't_item_location.s_city LIKE \'%' . $matches[2][0] . '%\'';
            } elseif (preg_match(
                '/' . DB_TABLE_PREFIX . 't_item_location.fk_i_city_id = (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['cities'][] =
                    DB_TABLE_PREFIX . 't_item_location.fk_i_city_id = ' . $matches[1];
            } elseif (preg_match_all(
                '/(' . DB_TABLE_PREFIX
                . 't_item_location.s_region\s*LIKE\s*\'%([\s\p{L}\p{N}]*)%\'\s*)/u',
                $condition,
                $matches
            )
            ) { // OJO
                // Comprobar: si ( s_name existe ) then get location id,
                $aData['s_region'][] =
                    DB_TABLE_PREFIX . 't_item_location.s_region LIKE \'%' . $matches[2][0] . '%\'';
            } elseif (preg_match(
                '/' . DB_TABLE_PREFIX . 't_item_location.fk_i_region_id = (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['fk_i_region_id'] =
                    DB_TABLE_PREFIX . 't_item_location.fk_i_region_id = ' . $matches[1];
            } elseif (preg_match_all(
                '/(' . DB_TABLE_PREFIX
                . 't_item_location.s_country\s*LIKE\s*\'%([\s\p{L}\p{N}]*)%\'\s*)/u',
                $condition,
                $matches
            )
            ) { // OJO
                // Comprobar: si ( s_name existe ) then get location id,
                $aData['s_country'][] =
                    DB_TABLE_PREFIX . 't_item_location.s_country LIKE \'%' . $matches[2][0] . '%\'';
            } elseif (preg_match(
                '/' . DB_TABLE_PREFIX
                                                                                      . 't_item_location.fk_c_country_code = \'?(.*)\'?/',
                $condition,
                $matches
            )
            ) {
                $aData['fk_c_country_code'][] =
                    DB_TABLE_PREFIX . 't_item_location.fk_c_country_code = ' . $matches[1];
            } elseif (preg_match(
                '/d\.s_title\s*LIKE\s*\'%([\s\p{L}\p{N}]*)%\'/u',
                $condition,
                $matches
            )
            ) {  // OJO
                $aData['sPattern']    = $matches[1];
                $aData['withPattern'] = true;
            } elseif (preg_match(
                '/MATCH\(d\.s_title, d\.s_description\) AGAINST\(\'([\s\p{L}\p{N}]*)\' IN BOOLEAN MODE\)/u',
                $condition,
                $matches
            )
            ) { // OJO
                $aData['sPattern']    = $matches[1];
                $aData['withPattern'] = true;
            } elseif (preg_match_all(
                '/(' . DB_TABLE_PREFIX . 't_item\.fk_i_category_id = (\d*))/',
                $condition,
                $matches
            )
            ) {
                $aData['aCategories'] = $matches[2];
            } else {
                $aData['no_catched_conditions'][] = $condition;
            }
        }

        // get tables
        foreach ($this->tables as $table) {
            if (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_category_description( as cd)?)/',
                $table,
                $matches
            )
            ) {
                // t_item_description
                $aData['tables'][] = $matches[1];
            } elseif (preg_match('/(' . DB_TABLE_PREFIX . 't_item_resource)/', $table, $matches)) {
                $aData['withPicture'] = true;
            } else {
                $aData['no_catched_tables'][] = $table;
            }
        }

        // get order & limit
        $aData['order_column']     = $this->order_column;
        $aData['order_direction']  = $this->order_direction;
        $aData['limit_init']       = $this->limit_init;
        $aData['results_per_page'] = $this->results_per_page;

        return $aData;
    }

    /**
     * @param $aData
     */
    public function setJsonAlert($aData)
    {
        // Restore a whole search from JSON, so clear any pattern left by a previous
        // restore first: newInstance() hands back one shared Search, and the alert cron
        // reuses it per alert. addPattern() only runs when a keyword is present, so
        // without this reset a keyword-less alert keeps the prior alert's pattern and
        // silently matches nothing.
        $this->withPattern = false;
        $this->sPattern    = null;
        $this->sPatternRaw = null;

        $this->priceRange($aData['price_min'], $aData['price_max']);

        $this->categories = $aData['aCategories'];
        // locations
        $this->city_areas = $aData['city_areas'];
        $this->cities     = $aData['cities'];
        $this->regions    = $aData['regions'];
        $this->countries  = $aData['countries'];

        $this->user_ids = $aData['user_ids'];

        $this->tables_join = $aData['tables_join'];
        $this->tables      = $aData['no_catched_tables'];
        $this->conditions  = $aData['no_catched_conditions'];

        // get order & limit
        $this->order_column     = $aData['order_column'];
        $this->order_direction  = $aData['order_direction'];
        $this->limit_init       = $aData['limit_init'];
        $this->results_per_page = $aData['results_per_page'];

        // pattern
        if (isset($aData['sPattern'])) {
            $this->addPattern($this->unescapeLegacyAlertPattern($aData['sPattern']));
        }
        if (isset($aData['withPicture'])) {
            $this->withPicture(true);
        }
        if (isset($aData['onlyPremium'])) {
            $this->onlyPremium(true);
        }
    }

    /**
     * Normalise a stored alert's pattern on the way back in.
     *
     * Alerts saved before toJson() switched to the raw pattern hold the escaped form
     * (the old escapeValue() output: driver-escaped, wrapped in single quotes). Replaying
     * that through addPattern() escapes it a second time, and the stray quotes shift the
     * matched set on the short-term LIKE path. Strip one legacy layer when the value is
     * quote-wrapped; a pattern saved raw (the current form) is not wrapped and passes
     * through unchanged, so old and new alerts converge and the call is idempotent.
     *
     * @param string $pattern
     *
     * @return string
     */
    private function unescapeLegacyAlertPattern($pattern)
    {
        $pattern = (string)$pattern;
        $len     = strlen($pattern);
        if ($len >= 2 && $pattern[0] === "'" && $pattern[$len - 1] === "'") {
            return stripslashes(substr($pattern, 1, -1));
        }

        return $pattern;
    }

    /**
     * Filter by search pattern
     *
     * @access public
     *
     * @param string $pattern
     *
     * @since  2.4
     */
    public function addPattern($pattern)
    {
        $this->withPattern = true;
        $this->sPatternRaw = trim((string)$pattern);
        $this->sPattern    = $this->escapeValue($pattern);
    }

    /**
     * Minimum indexed word length. InnoDB ignores tokens shorter than
     * innodb_ft_min_token_size (server default 3); a term below it never matches
     * a FULLTEXT query, so the short-term LIKE fallback keys off this. Override with
     * the OSC_FT_MIN_WORD_LEN constant when the server is tuned to a smaller value.
     *
     * @return int
     */
    private function ftMinWord()
    {
        return defined('OSC_FT_MIN_WORD_LEN') ? max(1, (int)OSC_FT_MIN_WORD_LEN) : 3;
    }

    /**
     * Unicode-aware length, degrading to byte length when mbstring is absent.
     *
     * @param string $s
     *
     * @return int
     */
    private function uLen($s)
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    /**
     * Split the raw pattern into meaningful terms, stripping the BOOLEAN MODE
     * operator characters so user input cannot inject its own operators. Leading
     * '-' is preserved as an exclusion marker; quoted "phrases" are returned whole
     * (without the quotes) via the $phrases out-parameter.
     *
     * Hyphens and other punctuation are word separators: MySQL FULLTEXT indexes
     * "co-working" as "co" + "working". Deleting the hyphen glued those into
     * "coworking", and +coworking* then matched nothing, so a pasted title missed.
     *
     * @param array $phrases  filled with the quoted phrases found (operators stripped)
     *
     * @return array          the loose words, each ['neg' => bool, 'text' => string]
     */
    private function patternTerms(&$phrases)
    {
        $phrases = array();
        $raw     = (string)$this->sPatternRaw;

        // On invalid UTF-8 the /u pattern functions return null/false; cast so a
        // bad byte sequence degrades to an empty term set rather than a warning.
        if (preg_match_all('/"([^"]+)"/u', $raw, $m)) {
            foreach ($m[1] as $phrase) {
                $phrase = trim((string)preg_replace('/[+\-*"()~<>@]/u', ' ', $phrase));
                $phrase = (string)preg_replace('/\s+/u', ' ', $phrase);
                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
            $raw = (string)preg_replace('/"[^"]+"/u', ' ', $raw);
        }

        $words = array();
        foreach ((array)preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $neg  = (isset($word[0]) && $word[0] === '-');
            $text = $neg ? substr($word, 1) : $word;
            foreach ((array)preg_split('/[^\p{L}\p{N}\']+/u', (string)$text, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $words[] = array('neg' => $neg, 'text' => $part);
            }
        }

        return $words;
    }

    /**
     * English function words that overlap InnoDB's default stopword list.
     * +and* / +in* are not dropped by BOOLEAN truncation and then require a
     * token the index never stored, so a pasted title with "and" or "in" missed.
     *
     * @param string $text
     *
     * @return bool
     */
    private function ftIsStopword($text)
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(array(
                'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
                'how', 'in', 'is', 'it', 'of', 'on', 'or', 'that', 'the', 'this',
                'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with',
            ));
        }

        return isset($stop[$text]);
    }

    /**
     * Skip FULLTEXT BOOLEAN tokens that are too short or stopwords.
     *
     * @param string $text
     *
     * @return bool
     */
    private function ftSkipToken($text)
    {
        if ($this->uLen($text) < $this->ftMinWord()) {
            return true;
        }

        return $this->ftIsStopword($text);
    }

    /**
     * Phrase words that are long enough and not stopwords.
     * "co-working" becomes "co working"; "co" is dropped so BOOLEAN does not
     * require a token MySQL never indexed.
     *
     * @param string $phrase
     *
     * @return array
     */
    private function ftPhraseKept($phrase)
    {
        $kept = array();
        foreach ((array)preg_split('/\s+/u', (string)$phrase, -1, PREG_SPLIT_NO_EMPTY) as $pw) {
            if ($pw !== '' && !$this->ftSkipToken($pw)) {
                $kept[] = $pw;
            }
        }

        return $kept;
    }

    /**
     * Whether the pattern has at least one term FULLTEXT can index. A query made
     * only of below-min-length words (e.g. "tv", "hp 15") matches nothing in
     * InnoDB, so it routes to the LIKE fallback instead.
     *
     * @return bool
     */
    private function ftUsable()
    {
        if ($this->sPatternRaw === null || $this->sPatternRaw === '') {
            return true;
        }
        $words = $this->patternTerms($phrases);
        foreach ($phrases as $phrase) {
            if ($this->ftPhraseKept($phrase) !== array()) {
                return true;
            }
        }
        foreach ($words as $w) {
            if (!$w['neg'] && !$this->ftSkipToken($w['text'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an escaped, quoted IN BOOLEAN MODE query from the raw pattern:
     * every loose word becomes a required prefix term (+word*), quoted "phrases"
     * become required exact phrases, and -word becomes an exclusion. This turns
     * MySQL's default OR-any-term matching into AND-all-terms with prefix recall.
     * Falls back to the stored escaped pattern when there is nothing to build.
     *
     * @return string
     */
    private function booleanPattern()
    {
        $words  = $this->patternTerms($phrases);
        $tokens = array();

        foreach ($phrases as $phrase) {
            $kept = $this->ftPhraseKept($phrase);
            if ($kept !== array()) {
                $tokens[] = '+"' . implode(' ', $kept) . '"';
            }
        }
        foreach ($words as $w) {
            if ($this->ftSkipToken($w['text'])) {
                continue;
            }
            $tokens[] = $w['neg'] ? '-' . $w['text'] : '+' . $w['text'] . '*';
        }

        if (empty($tokens)) {
            return $this->sPattern;
        }

        return "'" . $this->escapeString(implode(' ', $tokens)) . "'";
    }

    /**
     * WHERE fragment for the short-term fallback: match each term as a substring
     * of the title or description. Wildcard/escape metacharacters are stripped so
     * the term cannot alter the LIKE pattern.
     *
     * @return string
     */
    private function likePattern()
    {
        $words = $this->patternTerms($phrases);
        $terms = array();
        foreach ($phrases as $phrase) {
            $kept = $this->ftPhraseKept($phrase);
            if ($kept !== array()) {
                $terms[] = implode(' ', $kept);
            }
        }
        foreach ($words as $w) {
            if ($w['neg'] || $this->ftIsStopword($w['text'])) {
                continue;
            }
            $terms[] = $w['text'];
        }

        $clauses = array();
        foreach ($terms as $term) {
            $term = str_replace(array('%', '_'), array('\%', '\_'), $term);
            $esc  = $this->escapeString($term);
            $clauses[] = sprintf(
                "(d.s_title LIKE '%%%s%%' OR d.s_description LIKE '%%%s%%')",
                $esc,
                $esc
            );
        }

        if (empty($clauses)) {
            return sprintf(
                "MATCH(d.s_description, d.s_title) AGAINST(%s IN BOOLEAN MODE)",
                $this->booleanPattern()
            );
        }

        return '(' . implode(' AND ', $clauses) . ')';
    }

    /**
     * Filter by premium ad status
     *
     * @access public
     *
     * @param bool $premium
     *
     * @since  3.2
     */
    public function onlyPremium($premium = false)
    {
        $this->onlyPremium = $premium;
    }
}

/* file end: ./oc-includes/osclass/model/Search.php */
