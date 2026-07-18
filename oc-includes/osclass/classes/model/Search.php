<?php

/*
 * This file is part of Osclass (Mindstellar).
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
    private $userTableLoaded;

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

        $this->user_ids        = null;
        $this->itemId          = null;
        $this->userTableLoaded = false;

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
        $this->results_per_page = 10;

        if (!$expired) {
            // t_item
            $this->addItemConditions(sprintf('%st_item.b_enabled = 1 ', DB_TABLE_PREFIX));
            $this->addItemConditions(sprintf('%st_item.b_active = 1 ', DB_TABLE_PREFIX));
            $this->addItemConditions(sprintf('%st_item.b_spam = 0', DB_TABLE_PREFIX));
            $this->addItemConditions(sprintf(
                                         "(%st_item.b_premium = 1 || %st_item.dt_expiration >= '%s')",
                                         DB_TABLE_PREFIX,
                                         DB_TABLE_PREFIX,
                                         date('Y-m-d H:i:s')
                                     ));
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
            self::$instance = new self;
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
                                $this->dao->escape($c)
                            );
                    } else {
                        $this->city_areas[] =
                            sprintf(
                                "%st_item_location.s_city_area LIKE %s ",
                                DB_TABLE_PREFIX,
                                $this->dao->escape($c)
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
                            $this->dao->escape($city_area)
                        );
                } else {
                    $this->city_areas[] =
                        sprintf(
                            "%st_item_location.s_city_area LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->dao->escape($city_area)
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
        $this->dao->where(sprintf(
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
        $sql    = $this->makeSQL();
        $result = $this->dao->query($sql);
        if ($count) {
            // Wrap the (unlimited) match query in COUNT(*) so the total is exact and
            // only one row crosses the wire, instead of fetching up to 100 pages of
            // ids and counting them client-side (which also capped the total).
            $sql     = 'SELECT COUNT(*) AS total FROM (' . $this->makeSQL(true) . ') AS search_count';
            $datatmp = $this->dao->query($sql);

            if ($datatmp === false) {
                $this->total_results = 0;
            } else {
                $row                 = $datatmp->row();
                $this->total_results = (int)($row['total'] ?? 0);
            }
        } else {
            $this->total_results = 0;
        }

        if ($result === false) {
            return array();
        }

        $items = array();
        if ($result instanceof DBRecordsetClass) {
            $items = $result->result();
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
            $this->dao->select(sprintf(
                                   '%st_item.*, %st_item.s_contact_name as s_user_name',
                                   DB_TABLE_PREFIX,
                                   DB_TABLE_PREFIX
                               ));
            $this->dao->from(sprintf('%st_item', DB_TABLE_PREFIX));
            $this->dao->where('pk_i_id', (int)$this->itemId);
        } else {
            if ($count) {
                $this->dao->select(DB_TABLE_PREFIX . 't_item.pk_i_id');
                $this->dao->select($extraFields); // plugins!
            } else {
                $this->dao->select(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                                   . 't_item.s_contact_name as s_user_name');
                $this->dao->select($extraFields); // plugins!
            }
            $this->dao->from(DB_TABLE_PREFIX . 't_item');

            if ($this->withNoUserEmail) {
                $this->dao->where(DB_TABLE_PREFIX . 't_item.s_contact_email', $this->sEmail);
            }

            if ($this->withPattern) {
                $this->dao->join(
                    DB_TABLE_PREFIX . 't_item_description as d',
                    'd.fk_i_item_id = ' . DB_TABLE_PREFIX . 't_item.pk_i_id',
                    'LEFT'
                );
                if ($this->order_column === 'relevance') {
                    $this->dao->select(sprintf(
                                           "MATCH(d.s_title, d.s_description) AGAINST(%s) as relevance",
                                           $this->sPattern
                                       ));
                    $this->dao->having(sprintf("relevance > %s", 0));
                } else {
                    $this->dao->where(sprintf(
                                          "MATCH(d.s_title, d.s_description) AGAINST(%s IN BOOLEAN MODE)",
                                          $this->sPattern
                                      ));
                }
                if (empty($this->locale_code)) {
                    $this->locale_code[$this->userLocaleCode] = $this->userLocaleCode;
                }
                $this->dao->where(sprintf(
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
                $this->dao->where($itemConditions);
            }
            if ($this->withCategoryId && (count($this->categories) > 0)) {
                $this->dao->where(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }
            if ($this->withUserId) {
                $this->addFromUser();
            }
            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->dao->join(
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
                $this->dao->join(
                    sprintf('%st_item_resource', DB_TABLE_PREFIX),
                    sprintf(
                        '%st_item_resource.fk_i_item_id = %st_item.pk_i_id',
                        DB_TABLE_PREFIX,
                        DB_TABLE_PREFIX
                    ),
                    'LEFT'
                );
                $this->dao->where(sprintf(
                                      "%st_item_resource.s_content_type LIKE '%%image%%' ",
                                      DB_TABLE_PREFIX
                                  ));
                $this->dao->groupBy(DB_TABLE_PREFIX . 't_item.pk_i_id');
            }
            if ($this->onlyPremium) {
                $this->dao->where(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            }
            $this->addPriceRange();

            // add joinTables
            $this->joinTable();

            // PLUGINS TABLES !!
            if (!empty($this->tables)) {
                $tables = implode(', ', $this->tables);
                $this->dao->from($tables);
            }
            // WHERE PLUGINS extra conditions
            if (count($this->conditions) > 0) {
                $this->dao->where($conditionsSQL);
            }
            // ---------------------------------------------------------
            // groupBy
            if ($this->groupBy) {
                $this->dao->groupBy($this->groupBy);
            }
            // having
            if ($this->having) {
                $this->dao->having($this->having);
            }
            // ---------------------------------------------------------

            // order & limit — neither matters when we only need COUNT(*), and dropping
            // the limit is what makes the wrapped count exact instead of capped.
            if (!$count) {
                $this->dao->orderBy($this->order_column, $this->order_direction);
                $this->dao->limit($this->limit_init, $this->results_per_page);
            }
        }

        $this->sql = $this->dao->_getSelect();
        // reset dao attributes
        $this->dao->_resetSelect();

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
        $this->dao->join(DB_TABLE_PREFIX.'t_user', DB_TABLE_PREFIX.'t_user.pk_i_id = '.DB_TABLE_PREFIX.'t_item.fk_i_user_id', 'LEFT');
        if (is_array($this->user_ids)) {
            $this->dao->where(' ( ' . implode(' || ', $this->user_ids) . ' ) ');
        } else {
            $this->dao->where(sprintf(
                                  '%st_item.fk_i_user_id = %d ',
                                  DB_TABLE_PREFIX,
                                  $this->user_ids
                              ));
        }
    }

    private function loadUserTable()
    {
        $from = $this->dao->aFrom;
        if (!in_array(DB_TABLE_PREFIX . 't_user', $from)) {
            $this->dao->from(DB_TABLE_PREFIX . 't_user');
            $this->userTableLoaded = true;
        }
    }

    private function addLocations()
    {
        if (count($this->city_areas) > 0) {
            $this->dao->where('( ' . implode(' || ', $this->city_areas) . ' )');
        }
        if (count($this->cities) > 0) {
            $this->dao->where('( ' . implode(' || ', $this->cities) . ' )');
        }
        if (count($this->regions) > 0) {
            $this->dao->where('( ' . implode(' || ', $this->regions) . ' )');
        }
        if (count($this->countries) > 0) {
            $this->dao->where('( ' . implode(' || ', $this->countries) . ' )');
        }
    }

    private function addPriceRange()
    {
        if (is_numeric($this->price_min) && $this->price_min != 0) {
            $this->dao->where(sprintf('i_price >= %0.0f', $this->price_min));
        }
        if (is_numeric($this->price_max) && $this->price_max > 0) {
            $this->dao->where(sprintf('i_price <= %0.0f', $this->price_max));
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
            $this->dao->join($tJoin[0], $tJoin[1], $tJoin[2]);
        }
    }

    /**
     * Return total items on t_item without any filter
     *
     * @return null
     */
    public function countAll()
    {
        if (null === $this->total_results_table) {
            $result =
                $this->dao->query(sprintf(
                                      'select count(*) as total from %st_item',
                                      DB_TABLE_PREFIX
                                  ));
            if ($result instanceof DBRecordsetClass) {
                $row                       = $result->row();
                $this->total_results_table = $row['total'];
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

        $result = $this->dao->query($premium_sql);
        if ($result instanceof DBRecordsetClass) {
            $items = $result->result();
            if (!empty($items)) {
                $mStat = ItemStats::newInstance();
                foreach ($items as $item) {
                    $mStat->increase('i_num_premium_views', $item['pk_i_id']);
                }

                return Item::newInstance()->extendData($items);
            }
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
            $this->dao->select('distinct d.fk_i_item_id');
            $this->dao->from(DB_TABLE_PREFIX . 't_item_description as d');
            $this->dao->from(DB_TABLE_PREFIX . 't_item as ti');
            $this->dao->where('ti.pk_i_id = d.fk_i_item_id');
            $this->dao->where(sprintf(
                                  "MATCH(d.s_title, d.s_description) AGAINST(%s IN BOOLEAN MODE)",
                                  $this->sPattern
                              ));
            $this->dao->where('ti.b_premium = 1');

            if (empty($this->locale_code)) {
                if (defined('OC_ADMIN') && OC_ADMIN) {
                    $this->locale_code[osc_current_admin_locale()] = osc_current_admin_locale();
                } else {
                    $this->locale_code[osc_current_user_locale()] = osc_current_user_locale();
                }
            }
            $this->dao->where(sprintf(
                                  "( d.fk_c_locale_code LIKE '%s' )",
                                  implode("' d.fk_c_locale_code LIKE '", $this->locale_code)
                              ));

            $subSelect = $this->dao->_getSelect();
            $this->dao->_resetSelect();
            // END sub select ----------------------
            $this->dao->select(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                               . 't_item.s_contact_name as s_user_name');
            $this->dao->from(DB_TABLE_PREFIX . 't_item');
            $this->dao->from(sprintf('%st_item_stats', DB_TABLE_PREFIX));
            $this->dao->where(sprintf(
                                  '%st_item_stats.fk_i_item_id = %st_item.pk_i_id',
                                  DB_TABLE_PREFIX,
                                  DB_TABLE_PREFIX
                              ));
            $this->dao->where(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_enabled = 1 ', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_active = 1 ', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_spam = 0', DB_TABLE_PREFIX));


            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->dao->join(
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
                $this->dao->where(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }
            $this->dao->where(DB_TABLE_PREFIX . 't_item.pk_i_id IN (' . $subSelect . ')');

            $this->dao->groupBy(DB_TABLE_PREFIX . 't_item.pk_i_id');
            $this->dao->orderBy(
                sprintf('SUM(%st_item_stats.i_num_premium_views)', DB_TABLE_PREFIX),
                'ASC'
            );
            $this->dao->orderBy(null, 'random');
            $this->dao->limit(0, $num);
        } else {
            $this->dao->select(DB_TABLE_PREFIX . 't_item.*, ' . DB_TABLE_PREFIX
                               . 't_item.s_contact_name as s_user_name');
            $this->dao->from(DB_TABLE_PREFIX . 't_item');
            $this->dao->from(sprintf('%st_item_stats', DB_TABLE_PREFIX));
            $this->dao->where(sprintf(
                                  '%st_item_stats.fk_i_item_id = %st_item.pk_i_id',
                                  DB_TABLE_PREFIX,
                                  DB_TABLE_PREFIX
                              ));
            $this->dao->where(sprintf('%st_item.b_premium = 1', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_enabled = 1 ', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_active = 1 ', DB_TABLE_PREFIX));
            $this->dao->where(sprintf('%st_item.b_spam = 0', DB_TABLE_PREFIX));

            if ($this->withLocations || (defined('OC_ADMIN') && OC_ADMIN)) {
                $this->dao->join(
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
                $this->dao->where(sprintf('%st_item.fk_i_category_id', DB_TABLE_PREFIX) . ' IN ('
                                  . implode(', ', $this->categories) . ')');
            }

            $this->dao->groupBy(DB_TABLE_PREFIX . 't_item.pk_i_id');
            $this->dao->orderBy(
                sprintf('SUM(%st_item_stats.i_num_premium_views)', DB_TABLE_PREFIX),
                'ASC'
            );
            $this->dao->orderBy(null, 'random');
            $this->dao->limit(0, $num);
        }

        $sql = $this->dao->_getSelect();
        // reset dao attributes
        $this->dao->_resetSelect();

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
            md5(osc_base_url() . (string)$numItems . json_encode($options) . (string)$withPicture);
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
                            strtolower($this->dao->escape($country))
                        );
                } else {
                    $this->countries[] =
                        sprintf(
                            "%st_item_location.s_country LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->dao->escape($country)
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
                            $this->dao->escape($region)
                        );
                } else {
                    $this->regions[] =
                        sprintf(
                            "%st_item_location.s_region LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->dao->escape($region)
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
                            $this->dao->escape($city)
                        );
                } else {
                    $this->cities[] =
                        sprintf(
                            "%st_item_location.s_city LIKE %s ",
                            DB_TABLE_PREFIX,
                            $this->dao->escape($city)
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
                            $this->dao->escape($user['pk_i_id'])
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
                    $this->user_ids = $this->dao->escape($user['pk_i_id']);
                }
            } else {
                $this->user_ids = $this->dao->escape($id);
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
        $aOrder = explode(' ', $order);
        $nOrder = count($aOrder);

        if ($nOrder === 2) {
            $this->dao->orderBy($aOrder[0], $aOrder[1]);
        } elseif ($nOrder === 1) {
            $this->dao->orderBy($aOrder[0], 'DESC');
        } else {
            $this->dao->orderBy('item', 'DESC');
        }

        $this->dao->select('fk_i_city_area_id as city_area_id');
        $this->dao->select('s_city_area as city_area_name');
        $this->dao->select('fk_i_city_id');
        $this->dao->select('s_city as city_name');
        $this->dao->select('fk_i_region_id as region_id');
        $this->dao->select('s_region as region_name');
        $this->dao->select('fk_c_country_code as pk_c_code');
        $this->dao->select('s_country as country_name');
        $this->dao->select('count(*) as items');

        $this->dao->from(DB_TABLE_PREFIX . 't_item');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->from(DB_TABLE_PREFIX . 't_category');
        $this->dao->from(DB_TABLE_PREFIX . 't_country');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX
                          . 't_item_location.fk_i_item_id');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_enabled = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_active = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_spam = 0');
        $this->dao->where(DB_TABLE_PREFIX . 't_category.b_enabled = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX
                          . 't_item.fk_i_category_id');
        $this->dao->where('(' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX
                          . 't_category.i_expiration_days = 0 || DATEDIFF(\'' . date('Y-m-d H:i:s') . '\','
                          . DB_TABLE_PREFIX . 't_item.dt_pub_date) < ' . DB_TABLE_PREFIX
                          . 't_category.i_expiration_days)');
        $this->dao->where('fk_i_city_area_id IS NOT NULL');
        $this->dao->where(DB_TABLE_PREFIX . 't_country.pk_c_code = fk_c_country_code');
        $this->dao->groupBy('fk_i_city_area_id');
        $this->dao->having("items $zero 0");

        $city_int = (int)$city;

        if (is_numeric($city_int) && $city_int != 0) {
            $this->dao->where("fk_i_city_id = $city_int");
        }

        $result = $this->dao->get();
        if ($result) {
            return $result->result();
        }

        return array();
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
            $aData['sPattern']    = $this->sPattern;
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
                $aData['price_min'] = ((double)$matches[2] / 1000000);
            } elseif (preg_match(
                '/(' . DB_TABLE_PREFIX . 't_item\.)?i_price <= (.*)/',
                $condition,
                $matches
            )
            ) {
                $aData['price_max'] = ((double)$matches[2] / 1000000);
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
            } elseif (preg_match(                                                     '/' . DB_TABLE_PREFIX
                                                                                      . 't_item_location.fk_c_country_code = \'?(.*)\'?/',
                                                                                      $condition, $matches)
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
            $this->addPattern($aData['sPattern']);
        }
        if (isset($aData['withPicture'])) {
            $this->withPicture(true);
        }
        if (isset($aData['onlyPremium'])) {
            $this->onlyPremium(true);
        }
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
        $this->sPattern    = $this->dao->escape($pattern);
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
