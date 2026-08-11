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
 * Model database for Item table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Item extends DAO
{
    /**
     * It references to self object: Item.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Item
     */
    private static $instance;

    /**
     * Set data related to t_item table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            'fk_i_user_id',
            'fk_i_category_id',
            'dt_pub_date',
            'dt_mod_date',
            'f_price',
            'i_price',
            'fk_c_currency_code',
            's_contact_name',
            's_contact_email',
            's_contact_phone',
            'b_premium',
            'dt_premium_expiration',
            's_ip',
            'b_enabled',
            'b_active',
            'b_spam',
            's_secret',
            'b_show_email',
            'dt_expiration'
        );
        $this->setFields($array_fields);
    }

    /**
     * It creates a new Item object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return Item
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * List items ordered by views
     *
     * @access public
     *
     * @param int $limit
     *
     * @return array of items
     * @since  unknown
     */
    public function mostViewed($limit = 10)
    {
        // Hand-written for the i.*/stat-column projection, which is outside the
        // builder's identifier allowlist. There are no caller values: the join
        // conditions and column names are compile-time literals and $limit is
        // (int)-cast into a bound LIMIT placeholder. Location comes back from
        // extendData() below, so this no longer joins it.
        //
        // The stats row holds the running total, so ordering on it is exact.
        // Previously this grouped by listing without aggregating and ordered on
        // whichever day's row the server happened to pick — a total only by
        // accident, and only because ONLY_FULL_GROUP_BY is stripped from the
        // session. It also listed hidden and expired listings; the visibility
        // filters below are the same ones the public listing queries apply.
        $sql = 'SELECT i.*, s.i_num_views FROM ' . $this->getTableName() . ' i'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_item_stats s ON s.fk_i_item_id = i.pk_i_id'
            . ' WHERE i.b_enabled = 1 AND i.b_active = 1 AND i.b_spam = 0'
            . ' AND i.dt_expiration >= NOW()'
            . ' ORDER BY s.i_num_views DESC'
            . ' LIMIT ?';

        try {
            $items = osc_db_select($sql, array((int)$limit));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(osc_db_stringify_rows($items));
    }

    /**
     * Extends the given array $items with description in available locales
     *
     * @access public
     *
     * @param array $items array set of items
     *
     * @return array with description extended with all available locales
     *
     */
    public function extendData($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            $items = $this->extendItemDescription($items, $prefLocale);
            $items = $this->extendCategoryName($items, $prefLocale);
            $itemIds = array_column($items, 'pk_i_id');
            // First get stats and locations data. The s.*/l.* aliases are outside
            // the query builder's identifier allowlist, so this is hand-written
            // SQL. The only values are the item ids, bound as an IN (?, ...) list.
            //
            // This used to aggregate the seven counters over every dated row each
            // listing had ever accumulated, on every list render. The stats row is
            // now the total, so the counters come back as plain columns.
            if (!empty($itemIds)) {
                $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
                $sql = 'SELECT s.i_num_views,'
                    . ' s.i_num_spam,'
                    . ' s.i_num_bad_classified,'
                    . ' s.i_num_repeated,'
                    . ' s.i_num_offensive,'
                    . ' s.i_num_expired,'
                    . ' s.i_num_premium_views,'
                    . ' l.*'
                    . ' FROM ' . DB_TABLE_PREFIX . 't_item_stats s'
                    . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_item_location l ON s.fk_i_item_id = l.fk_i_item_id'
                    . ' WHERE s.fk_i_item_id IN (' . $placeholders . ')';

                try {
                    $itemStatsLocations = osc_db_stringify_rows(osc_db_select($sql, array_values($itemIds)));
                } catch (\mindstellar\database\DbException $e) {
                    $itemStatsLocations = array();
                }
            } else {
                $itemStatsLocations = array();
            }

            foreach ($items as $k => $aItem) {
                // Add stats and locations data
                if (isset($itemStatsLocations)) {
                    foreach ($itemStatsLocations as $key => $isl) {
                        if ($aItem['pk_i_id'] === $isl['fk_i_item_id']) {
                            $aItem += $isl;
                            unset($itemStatsLocations[$key]);
                        }
                    }
                }
                $items[$k] = $aItem;
            }

            // Batch-prime the resource cache for the whole page so the theme's
            // per-item osc_get_item_resources() calls are cache hits, not an N+1.
            if (count($itemIds) > 1) {
                ItemResource::newInstance()->primeResourcesCache($itemIds);
            }
        }

        return $items;
    }

    /**
     * List Items with category name
     *
     * @access public
     * @return array of items
     * @since  unknown
     */
    public function listAllWithCategories()
    {
        // Aliased multi-table join and the cd.s_name AS alias are outside the
        // builder's allowlist, so this is hand-written SQL with no bound values.
        $sql = 'SELECT i.*, cd.s_name AS s_category_name'
            . ' FROM ' . $this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_category c, '
            . DB_TABLE_PREFIX . 't_category_description cd'
            . ' WHERE c.pk_i_id = i.fk_i_category_id AND cd.fk_i_category_id = i.fk_i_category_id';

        try {
            $rows = osc_db_select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Find item resources belong to an item given its id
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array of resources
     * @since  unknown
     */
    public function findResourcesByID($id)
    {
        return ItemResource::newInstance()->getResources($id);
    }

    /**
     * Find the item location given a item id
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array of location
     * @since  unknown
     */
    public function findLocationByID($id)
    {
        return ItemLocation::newInstance()->findByPrimaryKey($id);
    }

    /**
     * Find items belong to a category given its id
     *
     * @access public
     *
     * @param int $catId
     *
     * @return array of items
     * @since  unknown
     */
    public function findByCategoryID($catId)
    {
        return $this->listWhere('fk_i_category_id = %d', (int)$catId);
    }

    /**
     * Comodin function to serve multiple queries
     *
     * @access public
     * @return array of items
     * @since  3.x.x
     */
    public function listWhere(...$args)
    {
        $where  = null;
        $params = array();
        switch (count($args)) {
            case 0:
                return array();
            case 1:
                // Single-argument form: a raw WHERE fragment the CALLER owns (its
                // docblock says the param is not escaped inside). It may embed
                // ORDER BY / LIMIT — listLatest() does exactly that. Internal
                // callers pass literals; no value is interpolated by this method.
                $where = $args[0];
                break;
            default:
                $format = array_shift($args);
                // Each printf conversion in the format becomes a bound '?'
                // placeholder, so the caller's values are bound rather than
                // escaped-and-concatenated. %d keeps its integer semantics by
                // casting the value it binds; %s binds the value verbatim as a
                // string (dropping the legacy numeric coercion, amendment T).
                $i     = 0;
                $where = preg_replace_callback('/%[ds]/', static function ($m) use (&$i, &$args) {
                    if ($m[0] === '%d' && array_key_exists($i, $args)) {
                        $args[$i] = (int)$args[$i];
                    }
                    $i++;

                    return '?';
                }, $format);
                $params = array_values($args);
                break;
        }

        // Item joined to its location. Every identifier is a compile-time literal
        // or the table-prefix constant; the only caller values are the bound
        // placeholders built above (or, for the raw single-arg form, the caller's
        // own trusted fragment).
        $sql = 'SELECT l.*, i.*'
            . ' FROM ' . $this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_item_location l'
            . ' WHERE l.fk_i_item_id = i.pk_i_id AND ' . $where;

        try {
            $items = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(osc_db_stringify_rows($items));
    }

    /**
     * Find items belong to a phone number
     *
     * @access public
     *
     * @param $phone
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByPhone($phone)
    {
        return $this->listWhere('s_contact_phone = %s', $phone);
    }

    /**
     * Find items belong to an email
     *
     * @access public
     *
     * @param $email
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByEmail($email)
    {
        return $this->listWhere('s_contact_email = %s', $email);
    }

    /**
     * Count all items, or all items belong to a category id, can be filtered
     * by $options  ['ACTIVE|INACTIVE|ENABLED|DISABLED|SPAM|NOTSPAM|EXPIRED|NOTEXPIRED|PREMIUM|TODAY']
     *
     * @access public
     *
     * @param int   $categoryId
     * @param mixed $options could be a string with | separator or an array with the options
     *
     * @return int total items
     * @since  unknown
     */
    public function totalItems($categoryId = null, $options = null)
    {
        $conditions = array();
        $params     = array();
        $join       = '';
        if (null !== $categoryId) {
            $join = ' INNER JOIN ' . DB_TABLE_PREFIX . 't_category c ON c.pk_i_id = i.fk_i_category_id';
            $this->pushWhere($conditions, $params, 'i.fk_i_category_id = ?', 'AND', array($categoryId));
        }

        $this->addWhereByOptions($options, $conditions, $params);

        $sql = 'SELECT count(*) as total FROM ' . $this->getTableName() . ' i' . $join;
        if ($conditions !== array()) {
            $sql .= ' WHERE ' . implode(' ', $conditions);
        }

        try {
            $total = osc_db_scalar($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        // COUNT(*) always yields one row; cast the (possibly native-int) scalar to
        // the string the legacy row value was.
        return (string)$total;
    }

    /**
     * Append one WHERE fragment (with its AND/OR connector) and its bound values
     * to the running condition/param lists. The first fragment carries no
     * connector, mirroring how the legacy DBCommandClass emitted its aWhere array.
     * $sql is a compile-time SQL fragment whose only values are '?' placeholders.
     *
     * @param string[] $conditions
     * @param array    $params
     * @param string   $sql
     * @param string   $bool 'AND' or 'OR'
     * @param array    $vals values for the placeholders in $sql, in order
     */
    private function pushWhere(array &$conditions, array &$params, string $sql, string $bool = 'AND', array $vals = array()): void
    {
        $conditions[] = ($conditions === array() ? '' : $bool . ' ') . $sql;
        foreach ($vals as $v) {
            $params[] = $v;
        }
    }

    /**
     * Add where conditions by options
     * $options  ['ACTIVE|INACTIVE|ENABLED|DISABLED|SPAM|NOTSPAM|EXPIRED|NOTEXPIRED|PREMIUM|TODAY']
     *
     * Appends bound WHERE fragments to $conditions/$params. The date comparisons
     * keep PHP's date() as the clock source (never SQL NOW()); the value is bound.
     * NOTEXPIRED keeps its OR connector so the mixed AND/OR structure the legacy
     * orWhere() produced is preserved verbatim.
     *
     * @access  private
     *
     * @param string|array $options could be a string with | separator or an array with the options
     * @param string[]     $conditions
     * @param array        $params
     *
     * @since   4.0.0
     */
    private function addWhereByOptions($options, array &$conditions, array &$params)
    {
        if (!is_array($options)) {
            $options = explode('|', $options);
        }
        foreach ($options as $option) {
            switch ($option) {
                case 'ACTIVE':
                    $this->pushWhere($conditions, $params, 'i.b_active = ?', 'AND', array(1));
                    break;
                case 'INACTIVE':
                    $this->pushWhere($conditions, $params, 'i.b_active = ?', 'AND', array(0));
                    break;
                case 'ENABLED':
                    $this->pushWhere($conditions, $params, 'i.b_enabled = ?', 'AND', array(1));
                    break;
                case 'DISABLED':
                    $this->pushWhere($conditions, $params, 'i.b_enabled = ?', 'AND', array(0));
                    break;
                case 'SPAM':
                    $this->pushWhere($conditions, $params, 'i.b_spam = ?', 'AND', array(1));
                    break;
                case 'NOTSPAM':
                    $this->pushWhere($conditions, $params, 'i.b_spam = ?', 'AND', array(0));
                    break;
                case 'EXPIRED':
                    $this->pushWhere($conditions, $params, 'i.b_premium = ?', 'AND', array(0));
                    $this->pushWhere($conditions, $params, '( i.dt_expiration < ? )', 'AND', array(date('Y-m-d H:i:s')));
                    break;
                case 'NOTEXPIRED':
                    $this->pushWhere($conditions, $params, 'i.b_premium = ?', 'OR', array(1));
                    $this->pushWhere($conditions, $params, '( i.dt_expiration >= ? )', 'AND', array(date('Y-m-d H:i:s')));
                    break;
                case 'PREMIUM':
                    $this->pushWhere($conditions, $params, 'i.b_premium = ?', 'AND', array(1));
                    break;
                case 'TODAY':
                    $this->pushWhere($conditions, $params, 'DATEDIFF(?, i.dt_pub_date) < 1', 'AND', array(date('Y-m-d H:i:s')));
                    break;
                default:
            }
        }
    }

    /**
     * LEAVE THIS FOR COMPATIBILITIES ISSUES (ONLY SITEMAP GENERATOR)
     * BUT REMEMBER TO DELETE IN ANYTHING > 2.1.x THANKS
     *
     * @param      $category
     * @param bool $enabled
     * @param bool $active
     *
     * @return int
     */
    /**
     * SQL predicate for "this listing is publicly live": enabled, active, not flagged spam, and
     * either premium or not yet expired. Single source of truth for the visibility rule that
     * search, category counts and any integrator must agree on — copy it by hand and the copies
     * drift, which is exactly what makes search disagree with the counts about what is live.
     *
     * Returns an array of SQL fragments to join with AND. The expiry bound carries PHP's clock
     * as a quoted literal (matching how the search builder consumes it). Pass an $alias ending
     * in a dot (e.g. 'i.' or DB_TABLE_PREFIX . 't_item.') to qualify the columns.
     *
     * @param string $alias column qualifier ending in a dot, or '' for none
     *
     * @return string[]
     */
    public static function liveConditions($alias = '')
    {
        return array(
            $alias . 'b_enabled = 1',
            $alias . 'b_active = 1',
            $alias . 'b_spam = 0',
            sprintf("(%sb_premium = 1 || %sdt_expiration >= '%s')", $alias, $alias, date('Y-m-d H:i:s')),
        );
    }

    public function numItems($category, $enabled = true, $active = true)
    {
        $conditions = array();
        $params     = array();
        $this->pushWhere($conditions, $params, 'fk_i_category_id = ?', 'AND', array((int)$category['pk_i_id']));
        $this->pushWhere($conditions, $params, 'b_enabled = ?', 'AND', array($enabled));
        $this->pushWhere($conditions, $params, 'b_active = ?', 'AND', array($active));
        $this->pushWhere($conditions, $params, 'b_spam = ?', 'AND', array(0));
        // The premium test is unparenthesised against the AND chain and uses || —
        // preserved verbatim (the same latent-clause shape as RegionStats). The
        // date keeps PHP's clock and is bound.
        $this->pushWhere($conditions, $params, '( b_premium = 1 || dt_expiration >= ? )', 'AND', array(date('Y-m-d H:i:s')));

        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->getTableName() . ' WHERE ' . implode(' ', $conditions);

        try {
            $total = osc_db_scalar($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string)$total;
    }

    /**
     * @param int $limit
     *
     * @return array
     */
    public function listLatest($limit = 10)
    {
        return $this->listWhere(' b_active = 1 AND b_enabled = 1 ORDER BY dt_pub_date DESC LIMIT %d', (int)$limit);
    }

    /**
     * Insert title and description for a given locale and item id.
     *
     * @access public
     *
     * @param string $id Item id
     * @param string $locale
     * @param string $title
     * @param string $description
     *
     * @return boolean
     * @since  unknown
     */
    public function insertLocale($id, $locale, $title, $description)
    {
        $array_set = array(
            'fk_i_item_id'     => $id,
            'fk_c_locale_code' => $locale,
            's_title'          => $title,
            's_description'    => $description
        );

        try {
            osc_db_table(DB_TABLE_PREFIX . 't_item_description')->insert($array_set);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     * @param int $start  begining
     * @param int $end    ending
     *
     * @return array of items
     * @since  unknown
     */
    public function findByUserID($userId, $start = 0, $end = null)
    {
        $condition = 'fk_i_user_id = ' . (int)$userId;

        return $this->findItemByTypes($condition, 'all', false, $start, $end);
    }

    /**
     * Find enabled items or count of items by types with given where condition
     *
     * @access public
     *
     * @param string | array $conditions Where condition on t_item table i.e "pk_i_id = 3"
     * @param int            $limit      beginning from $start
     * @param int            $offset     ending
     * @param bool           $itemType   item(active, expired, pending, pending validate, premium, all, enabled,
     *                                   blocked)
     *
     * @return array | int array of items or count of item
     * @since  unknown
     *
     */
    public function findItemByTypes($conditions = null, $itemType = false, $count = false, $limit = 0, $offset = null)
    {
        // $conditions is RAW SQL the CALLER owns: a string or an array of strings
        // (a raw-fragment public API, like User::countUsers). Internal callers
        // build safe fragments — 'fk_i_user_id = ' . (int)$userId — and the
        // (int) values they use keep it injection-safe. An array element may also be
        // a [fragment-with-?-placeholders, [bound values]] pair, which is how a caller
        // passes an untrusted value safely instead of escaping it into the fragment.
        $conds  = array();
        $params = array();
        if ($conditions !== null) {
            if (is_array($conditions)) {
                foreach ($conditions as $condition) {
                    if (is_array($condition)) {
                        $this->pushWhere($conds, $params, $condition[0], 'AND', $condition[1] ?? array());
                    } else {
                        $this->pushWhere($conds, $params, $condition, 'AND');
                    }
                }
            } else {
                $this->pushWhere($conds, $params, $conditions, 'AND');
            }
        }

        $this->addWhereByType($itemType, $conds, $params);

        $where = $conds === array() ? '' : ' WHERE ' . implode(' ', $conds);

        if ($count === true) {
            $sql = 'SELECT count(pk_i_id) as total FROM ' . $this->getTableName() . ' i' . $where;
            try {
                $total = osc_db_scalar($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                return 0;
            }

            return (string)$total;
        }

        $sql = 'SELECT i.* FROM ' . $this->getTableName() . ' i' . $where . ' ORDER BY dt_pub_date DESC';

        // Legacy paging: limit($limit, $offset) compiled "LIMIT $limit, $offset"
        // (offset $limit, count $offset), emitting the second value only when it
        // is > 0, and dropping the whole clause when the first is non-numeric.
        // limit($limit) alone compiled "LIMIT $limit". Reproduced by emitted SQL,
        // never by argument name.
        if ($offset !== null) {
            if (is_numeric($limit)) {
                $sql .= ' LIMIT ' . (int)$limit;
                if (is_numeric($offset) && (int)$offset > 0) {
                    $sql .= ', ' . (int)$offset;
                }
            }
        } elseif ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        try {
            $items = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(osc_db_stringify_rows($items));
    }

    /**
     * add conditions by type
     *
     * @param $itemType
     */
    private function addWhereByType($itemType, array &$conditions, array &$params)
    {
        switch ($itemType) {
            case 'blocked':
                $this->addWhereByOptions(['DISABLED'], $conditions, $params);

                return;
            case 'active':
                $this->addWhereByOptions(['ACTIVE', 'ENABLED', 'NOTEXPIRED'], $conditions, $params);

                return;
            case 'nospam':
                $this->addWhereByOptions(['ACTIVE', 'NOSPAM', 'NOTEXPIRED'], $conditions, $params);

                return;
            case 'expired':
                $this->addWhereByOptions(['EXPIRED'], $conditions, $params);

                return;
            case 'pending':
            case 'pending_validate':
                $this->addWhereByOptions(['INACTIVE'], $conditions, $params);

                return;
            case 'premium':
                $this->addWhereByOptions(['PREMIUM'], $conditions, $params);

                return;
            case 'all':
                return;
            default:
                $this->addWhereByOptions(['ENABLED', 'ACTIVE', 'NOTEXPIRED', 'NOTSPAM'], $conditions, $params);
        }
    }

    /**
     * Count items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     *
     * @return int number of items
     * @since  unknown
     */
    public function countByUserID($userId)
    {
        return $this->countItemTypesByUserID($userId, 'all');
    }

    /**
     * Count items by User Id according the
     *
     * @access public
     *
     * @param int    $userId   User id
     * @param bool   $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string $cond
     *
     * @return int number of items
     * @since  unknown
     */
    public function countItemTypesByUserID($userId, $itemType = false, $cond = '')
    {
        $condition[] = 'fk_i_user_id = ' . (int)$userId;
        if ($cond) {
            $condition[] = $cond;
        }

        return $this->findItemByTypes($condition, $itemType, true);
    }

    /**
     * Find enabled items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     * @param int $start  beginning from $start
     * @param int $end    ending
     *
     * @return array of items
     * @since  unknown
     */
    public function findByUserIDEnabled($userId, $start = 0, $end = null)
    {
        $condition = 'fk_i_user_id = ' . (int)$userId;

        return $this->findItemByTypes($condition, false, false, $start, $end);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @access public
     *
     * @param int $hours
     *
     * @return array of items
     * @since  3.2
     */
    public function findByHourExpiration($hours = 24)
    {
        $conditions = ['TIMESTAMPDIFF(HOUR, NOW(), dt_expiration) = ' . (int)$hours, 'b_active = 1', 'b_spam = 0'];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @access public
     *
     * @param int $days
     *
     * @return array of items
     * @since  3.2
     */
    public function findByDayExpiration($days = 1)
    {
        $conditions = ['TIMESTAMPDIFF(DAY, NOW(), dt_expiration) = ' . (int)$days, 'b_active = 1', 'b_spam = 0'];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Count enabled items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     *
     * @return int number of items
     * @since  unknown
     */
    public function countByUserIDEnabled($userId)
    {
        return $this->countItemTypesByUserID($userId, 'enabled');
    }

    /**
     * Find enable items according the
     *
     * @access public
     *
     * @param int  $userId   User id
     * @param int  $start    beginning from $start
     * @param int  $end      ending
     * @param bool $itemType item(active, expired, pending, premium, all, enabled, blocked)
     *
     * @return array of items
     * @since  unknown
     *
     */
    public function findItemTypesByUserID($userId, $start = 0, $end = null, $itemType = false)
    {
        return $this->findItemByTypes('fk_i_user_id = ' . (int)$userId, $itemType, false, $start, $end);
    }

    /**
     * Count items by Email according the
     * Useful for counting item that posted by unregistered user
     *
     * @access public
     *
     * @param int    $email    Email
     * @param bool   $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string $cond
     *
     * @return int number of items
     * @since  unknown
     */
    public function countItemTypesByEmail($email, $itemType = false, $cond = '')
    {
        // The email is untrusted, so it goes through findItemByTypes' bound-value
        // channel as a [fragment, values] pair rather than being escaped into the
        // WHERE string. $cond stays a raw caller-owned fragment.
        $conditions = array(array('s_contact_email = ?', array((string)$email)));
        if ($cond) {
            $conditions[] = $cond;
        }

        return $this->findItemByTypes($conditions, $itemType, true);
    }

    /**
     * Clear item stat given item id and stat to clear
     * $stat array('spam', 'duplicated', 'bad', 'offensive', 'expired', 'all')
     *
     * @access public
     *
     * @param int    $id
     * @param string $stat
     *
     * @return mixed int if updated correctly or false when error occurs
     * @since  unknown
     */
    public function clearStat($id, $stat)
    {
        switch ($stat) {
            case 'spam':
                $array_set = array('i_num_spam' => 0);
                break;
            case 'duplicated':
                $array_set = array('i_num_repeated' => 0);
                break;
            case 'bad':
                $array_set = array('i_num_bad_classified' => 0);
                break;
            case 'offensive':
                $array_set = array('i_num_offensive' => 0);
                break;
            case 'expired':
                $array_set = array('i_num_expired' => 0);
                break;
            case 'all':
                $array_set = array(
                    'i_num_spam'           => 0,
                    'i_num_repeated'       => 0,
                    'i_num_bad_classified' => 0,
                    'i_num_offensive'      => 0,
                    'i_num_expired'        => 0
                );
                break;
            default:
                break;
        }
        if (isset($array_set)) {
            try {
                return osc_db_table(DB_TABLE_PREFIX . 't_item_stats')
                    ->where('fk_i_item_id', $id)
                    ->update($array_set);
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
        }
    }

    /**
     * Update title and description given a item id and locale.
     *
     * @access public
     *
     * @param int    $id
     * @param string $locale
     * @param string $title
     * @param string $text
     *
     * @return bool
     * @since  unknown
     */
    public function updateLocaleForce($id, $locale, $title, $text)
    {
        // REPLACE INTO has no query-builder equivalent, so it is hand-written with
        // every value bound. Column order matches the legacy replace() set.
        $sql = 'REPLACE INTO ' . DB_TABLE_PREFIX . 't_item_description'
            . ' (s_title, s_description, fk_c_locale_code, fk_i_item_id) VALUES (?, ?, ?, ?)';

        try {
            osc_db_execute($sql, array($title, $text, $locale, $id));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        // Model-level write, so it is invisible to the controller-layer item events. Announce it
        // for anything mirroring item content (a search index, a cache): title/description changed.
        osc_run_hook('item_content_updated', (int)$id, $locale);

        return true;
    }

    /**
     * Update dt_expiration field, using $expiration_time
     *
     * @param       $id
     * @param mixed $expiration_time could be interget (number of days) or directly a date
     * @param bool  $do_stats
     *
     * @return string new date expiration, false if error occurs
     *
     */
    public function updateExpirationDate($id, $expiration_time, $do_stats = true)
    {
        if (!$expiration_time) {
            return false;
        }

        try {
            $item = osc_db_select_one('SELECT dt_expiration FROM ' . $this->getTableName() . ' WHERE pk_i_id = ?', array($id));
        } catch (\mindstellar\database\DbException $e) {
            $item = null;
        }

        // Legacy entered this block whenever the read did not error, even for a
        // zero-row (missing id) result — in which case the UPDATE below matched
        // nothing and the method fell through to false. A missing id here yields a
        // null row and converges on the same false, so it is guarded up front.
        if ($item !== null) {
            $item        = osc_db_stringify_row($item);
            $expired_old = osc_isExpired($item['dt_expiration']);
            if (ctype_digit($expiration_time)) {
                if ($expiration_time > 0) {
                    // A DATE_ADD(...) expression must reach the column UNquoted:
                    // the table name is a fixed identifier and the interval days
                    // are (int)-cast, so the expression is written inline. The
                    // pk_i_id filter is bound.
                    $sql    = 'UPDATE ' . $this->getTableName()
                        . ' SET dt_expiration = DATE_ADD(' . $this->getTableName() . '.dt_pub_date, INTERVAL '
                        . (int)$expiration_time . ' DAY) WHERE pk_i_id = ?';
                    $params = array($id);
                } else {
                    $sql    = 'UPDATE ' . $this->getTableName() . ' SET dt_expiration = ? WHERE pk_i_id = ?';
                    $params = array('9999-12-31 23:59:59', $id);
                }
            } else {
                $sql    = 'UPDATE ' . $this->getTableName() . ' SET dt_expiration = ? WHERE pk_i_id = ?';
                $params = array($expiration_time, $id);
            }

            try {
                $result = osc_db_execute($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                $result = 0;
            }

            if ($result && $result > 0) {
                try {
                    $_item = osc_db_select_one(
                        'SELECT i.dt_expiration, i.fk_i_user_id, i.fk_i_category_id, l.fk_c_country_code,'
                        . ' l.fk_i_region_id, l.fk_i_city_id'
                        . ' FROM ' . $this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_item_location l'
                        . ' WHERE i.pk_i_id = l.fk_i_item_id AND i.pk_i_id = ?',
                        array($id)
                    );
                } catch (\mindstellar\database\DbException $e) {
                    $_item = null;
                }
                if ($_item === null) {
                    // The row is read with an inner join on t_item_location, so a listing
                    // with no location row yields null here. There is nothing to announce
                    // or re-count, and the dereferences below would fatal on it; converge
                    // on the method's own false failure path.
                    return false;
                }
                $_item = osc_db_stringify_row($_item);

                // Model-level write the controller-layer events never see. Announce the new
                // expiry so an index or cache mirroring liveness can react (an expiry change can
                // flip a listing in or out of the "live" set).
                osc_run_hook('item_expiration_updated', (int)$id, $_item['dt_expiration']);

                if (!$do_stats) {
                    return $_item['dt_expiration'];
                }

                $expired = osc_isExpired($_item['dt_expiration']);
                if ($expired !== $expired_old) {
                    if ($expired) {
                        if ($_item['fk_i_user_id'] != null) {
                            User::newInstance()->decreaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::newInstance()->decreaseNumItems($_item['fk_i_category_id']);
                        CountryStats::newInstance()->decreaseNumItems($_item['fk_c_country_code']);
                        RegionStats::newInstance()->decreaseNumItems($_item['fk_i_region_id']);
                        CityStats::newInstance()->decreaseNumItems($_item['fk_i_city_id']);
                    } else {
                        if ($_item['fk_i_user_id'] != null) {
                            User::newInstance()->increaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::newInstance()->increaseNumItems($_item['fk_i_category_id']);
                        CountryStats::newInstance()->increaseNumItems($_item['fk_c_country_code']);
                        RegionStats::newInstance()->increaseNumItems($_item['fk_i_region_id']);
                        CityStats::newInstance()->increaseNumItems($_item['fk_i_city_id']);
                    }
                }

                return $_item['dt_expiration'];
            }
        }

        return false;
    }

    /**
     * Enable all items by given category ids
     *
     * @param int 0|1 $enable
     * @param array $aIds
     *
     * @return \DBRecordsetClass
     */
    public function enableByCategory($enable, $aIds)
    {
        $aIds = array_map('intval', (array)$aIds);
        if (empty($aIds)) {
            return false;
        }
        // $aIds are already (int)-cast with an empty guard, so the IN list is a
        // safe literal; $enable binds through a placeholder ((int)-cast to keep the
        // legacy %d truncation).
        $sql = 'UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_enabled = ? WHERE '
            . DB_TABLE_PREFIX . 't_item.fk_i_category_id IN (' . implode(',', $aIds) . ')';

        try {
            osc_db_execute($sql, array((int)$enable));
            $result = true;
        } catch (\mindstellar\database\DbException $e) {
            $result = false;
        }

        // The model fires no lifecycle event for this bulk change, so search indexes,
        // caches and audit listeners would never see it (core only fires item hooks
        // for single-item actions). Announce it so they can reconcile the affected
        // items — $aIds are category ids, $enable is the new b_enabled value.
        if ($result !== false) {
            osc_run_hook('items_bulk_enabled_by_category', $aIds, $enable);
        }

        return $result;
    }

    /**
     * Return the number of items marked as $type
     *
     * @param string $type spam, repeated, bad_classified, offensive, expired
     *
     * @return int
     */
    public function countByMarkas($type)
    {
        if (null === $type) {
            return 0;
        }

        // i_num_spam, i_num_repeated, i_num_bad_classified, i_num_offensive, i_num_expired
        $extra = '';
        switch ($type) {
            case 'spam':
                $extra = ' AND s.i_num_spam > 0 AND i.b_spam = 0';
                break;
            case 'repeated':
                $extra = ' AND s.i_num_repeated > 0';
                break;
            case 'bad_classified':
                $extra = ' AND s.i_num_bad_classified > 0';
                break;
            case 'offensive':
                $extra = ' AND s.i_num_offensive > 0';
                break;
            case 'expired':
                $extra = ' AND s.i_num_expired > 0';
                break;
            default:
        }

        // The stat comparisons are fixed literals with no bound value. Hand-written
        // to preserve the exact projection.
        //
        // This counts rows, and the stats table now holds exactly one per listing —
        // so it counts listings, which is what the caller wants and what the name
        // says. It did not before: a listing reported on three different days had
        // three rows and was counted three times.
        $sql = 'SELECT count(*) as total FROM ' . $this->getTableName() . ' i'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_item_stats s ON s.fk_i_item_id = i.pk_i_id'
            . ' WHERE 1 = 1' . $extra;

        try {
            $total = osc_db_scalar($sql);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string)$total;
    }

    /**
     * Return meta fields for a given item
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array meta fields array
     * @since  unknown
     */
    public function metaFields($id)
    {
        $metaFields = Field::newInstance()->findByItem($id);
        if (empty($metaFields)) {
            return [];
        }
        $aTemp = $metaFields;

        $array = array();
        // prepare data - date interval - from <-> to
        foreach ($aTemp as $value) {
            if ($value['e_type'] === 'DATEINTERVAL') {
                $aValue = array();
                if (isset($array[$value['pk_i_id']])) {
                    $aValue = $array[$value['pk_i_id']]['s_value'];
                }
                $aValue[$value['s_multi']] = $value['s_value'];
                $value['s_value']          = $aValue;
            }
            $array[$value['pk_i_id']] = $value;
        }

        return $array;
    }

    /**
     * Delete by city area
     *
     * @access public
     *
     * @param int $cityAreaId city area id
     *
     * @return bool
     *
     * @since  3.1
     *
     */
    public function deleteByCityArea($cityAreaId)
    {
        // Legacy had no error branch here (a failed read fataled on ->result()),
        // so a DbException is left to propagate rather than absorbed.
        $items = osc_db_stringify_rows(
            osc_db_table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_city_area_id', $cityAreaId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by primary key, delete dependencies too
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByPrimaryKey($id)
    {
        $item = $this->findByPrimaryKey($id);

        if (null === $item) {
            return false;
        }

        $isAdmin = false;
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $isAdmin = true;
        }

        // Read now, unlinked further down once the transaction has committed. The rows
        // are the only record of which files belong to the listing and the transaction
        // is about to remove them, but deleting a file cannot be undone — so a delete
        // that rolls back has to leave the listing with its images intact rather than
        // stranding it with none.
        $resources = ItemResource::newInstance()->getAllResourcesFromItem($id);

        // t_item_moderation_log and t_item_report_log carry no foreign key to the
        // item, so nothing blocked the delete and nothing removed them either: an
        // id reused by a later listing would inherit the old listing's report and
        // moderation history. The rest are covered by ON DELETE CASCADE as well,
        // and stay listed for installs whose foreign keys were never created.
        $dependents = array(
            't_item_description',
            't_item_comment',
            't_item_resource',
            't_item_location',
            't_item_stats',
            't_item_meta',
            't_item_moderation_log',
            't_item_report_log',
        );

        try {
            $deleted = osc_db_transaction(function () use ($id, $dependents) {
                foreach ($dependents as $depTable) {
                    osc_db_table(DB_TABLE_PREFIX . $depTable)->where('fk_i_item_id', $id)->delete();
                }

                $removed = parent::deleteByPrimaryKey($id);
                if ($removed === false) {
                    // The legacy DAO reports a failed delete by returning false instead
                    // of raising. Returned as-is it would look like an ordinary result,
                    // the transaction would commit, and the listing would be left in
                    // place with its description, images and stats already deleted --
                    // the exact outcome the transaction is here to prevent. Raising is
                    // what turns it into a rollback.
                    throw new \RuntimeException('Deleting item ' . (int)$id . ' failed');
                }

                return $removed;
            });
        } catch (\Throwable $e) {
            return false;
        }

        // The row is gone for good, so the files can go too.
        ItemActions::deleteResourcesFromHD($id, $isAdmin, $resources);

        // Counters are decremented only once the row is really gone. Doing it first
        // meant a delete that failed still took the listing out of every total, and
        // the numbers stayed wrong until the next stats rebuild.
        if ($item['b_active'] == 1 && $item['b_enabled'] == 1 && $item['b_spam'] == 0
            && !osc_isExpired($item['dt_expiration'])
        ) {
            if ($item['fk_i_user_id'] != null) {
                User::newInstance()->decreaseNumItems($item['fk_i_user_id']);
            }
            CategoryStats::newInstance()->decreaseNumItems($item['fk_i_category_id']);
            CountryStats::newInstance()->decreaseNumItems($item['fk_c_country_code']);
            RegionStats::newInstance()->decreaseNumItems($item['fk_i_region_id']);
            CityStats::newInstance()->decreaseNumItems($item['fk_i_city_id']);
        }

        Plugins::runHook('delete_item', $id);

        return $deleted;
    }

    /**
     * Delete each of the given items by primary key, firing the standard
     * item-lifecycle hooks (before_delete_item / after_delete_item) around
     * every deletion. Cascade deletes triggered by removing a location then
     * emit the same signals a direct item delete does, so listeners that keep
     * external indexes or caches in sync do not need to special-case them.
     *
     * @access private
     *
     * @param array $items rows containing an fk_i_item_id column
     *
     * @return int number of affected rows
     */
    private function deleteItemsFiringHooks($items)
    {
        $arows = 0;
        foreach ($items as $i) {
            $itemId = $i['fk_i_item_id'];
            $item   = $this->findByPrimaryKey($itemId);
            osc_run_hook('before_delete_item', $itemId);
            $deleted = $this->deleteByPrimaryKey($itemId);
            if ($deleted !== false) {
                $arows += $deleted;
                osc_run_hook('after_delete_item', $itemId, $item);
            }
        }

        return $arows;
    }

    /**
     * Get the result match of the primary key passed by parameter, extended with
     * location information and number of views.
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findByPrimaryKey($id)
    {
        if (!is_numeric($id) || $id === null) {
            return array();
        }
        // Aliased i.* projection; hand-written with the id bound.
        try {
            $rows = osc_db_select('SELECT i.* FROM ' . $this->getTableName() . ' i WHERE i.pk_i_id = ?', array($id));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($rows === array()) {
            return array();
        }

        return $this->extendDataSingle(osc_db_stringify_row($rows[0]));
    }

    /**
     * Extends the given array $item with description in available locales
     *
     * @access public
     *
     * @param array $item
     *
     * @return array item array with description in available locales
     *
     * @since  unknown
     *
     */
    public function extendDataSingle($item)
    {
        return $this->extendData(array($item))[0];
    }

    /**
     * Delete by city
     *
     * @access public
     *
     * @param int $cityId city id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByCity($cityId)
    {
        $items = osc_db_stringify_rows(
            osc_db_table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_city_id', $cityId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by region
     *
     * @access public
     *
     * @param int $regionId region id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByRegion($regionId)
    {
        $items = osc_db_stringify_rows(
            osc_db_table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_region_id', $regionId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by country
     *
     * @access public
     *
     * @param int $countryId country id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByCountry($countryId)
    {
        $items = osc_db_stringify_rows(
            osc_db_table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_c_country_code', $countryId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Extends the given array $items with category name , and description in available locales
     *
     * @access public
     *
     * @param array $items array with items
     *
     * @return array with category name
     * @since  unknown
     */
    public function extendCategoryName($items, $prefLocale = null)
    {
        if (null === $prefLocale) {
            $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
        }
        $results = array();
        // get categoryIds from items
        $categoryIds = array_column($items, 'fk_i_category_id');
        $categoryIds = array_unique($categoryIds);

        // Hand-written so the optional category-id IN() and the s_name != ''
        // filter stay in the legacy order; every value is bound.
        $conditions = array();
        $params     = array();
        if (count($categoryIds) > 0) {
            $placeholders = implode(', ', array_fill(0, count($categoryIds), '?'));
            $conditions[] = 'fk_i_category_id IN (' . $placeholders . ')';
            foreach ($categoryIds as $cid) {
                $params[] = $cid;
            }
        }
        $conditions[] = 's_name != ?';
        $params[]     = '';

        $sql = 'SELECT fk_i_category_id, fk_c_locale_code, s_name FROM ' . DB_TABLE_PREFIX . 't_category_description'
            . ' WHERE ' . implode(' AND ', $conditions);

        try {
            $categories = osc_db_stringify_rows(osc_db_select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return $items;
        }
        $aCategories = array();
        foreach ($categories as $c) {
            // if category name is not empty
            if ($c['s_name'] != '') {
                $aCategories[$c['fk_i_category_id']]['locale'][$c['fk_c_locale_code']]['s_category_name'] = $c['s_name'];
            }
        }

        foreach ($items as $item) {
            if (isset($item['fk_i_category_id'], $aCategories[$item['fk_i_category_id']])) {
                if (isset($item['locale']) && is_array($item['locale'])) {
                    foreach ($item['locale'] as $localeCode => $itemLocale) {
                        if (isset($aCategories[$item['fk_i_category_id']]['locale'][$localeCode])) {
                            $item['locale'][$localeCode]['s_category_name'] = $aCategories[$item['fk_i_category_id']]['locale'][$localeCode]['s_category_name'];
                        }
                    }
                }
            }
            if (isset($aCategories[$item['fk_i_category_id']]['locale'][$prefLocale]['s_category_name'])) {
                $item['s_category_name'] = $aCategories[$item['fk_i_category_id']]['locale'][$prefLocale]['s_category_name'];
            } else {
                // check each locale until we find one that has a name
                $item['s_category_name'] = '';
                foreach ($aCategories[$item['fk_i_category_id']]['locale'] as $locale => $data) {
                    if ($data['s_category_name'] != '') {
                        $item['s_category_name'] = $data['s_category_name'];
                        break;
                    }
                }
            }
            $results[] = $item;
        }
        return $results;
    }

    /**
     * Extends the given array $items with description in available locales
     *
     * @access public
     *
     * @param array $items array with items
     *
     * @return array $items with description
     * @since  unknown
     */
    private function extendItemDescription($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            $itemIds = array_column($items, 'pk_i_id');

            // One fan-out over every listed item's descriptions, bound as IN (?, ...).
            $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
            $sql = 'SELECT fk_i_item_id, fk_c_locale_code, s_title, s_description'
                . ' FROM ' . DB_TABLE_PREFIX . 't_item_description'
                . ' WHERE fk_i_item_id IN (' . $placeholders . ')';

            try {
                $descriptions = osc_db_stringify_rows(osc_db_select($sql, array_values($itemIds)));
            } catch (\mindstellar\database\DbException $e) {
                return $items;
            }
            $aDescriptions = array();
            foreach ($descriptions as $d) {
                if ($d['s_title'] != '') {
                    $aDescriptions[$d['fk_i_item_id']]['locale'][$d['fk_c_locale_code']]['s_title'] = $d['s_title'];
                }
                if ($d['s_description'] != '') {
                    $aDescriptions[$d['fk_i_item_id']]['locale'][$d['fk_c_locale_code']]['s_description'] = $d['s_description'];
                }
            }
            $extendedItems = [];
            foreach ($items as $item) {
                if (isset($item['pk_i_id'], $aDescriptions[$item['pk_i_id']])) {
                    //if $item['locale'] exists, then we have to merge the arrays
                    if (isset($item['locale']) && is_array($item['locale'])) {
                        $item['locale'] = array_merge($item['locale'], $aDescriptions[$item['pk_i_id']]['locale']);
                    } else {
                        $item['locale'] = $aDescriptions[$item['pk_i_id']]['locale'];
                    }
                }
                if (isset($item['locale'][$prefLocale]['s_title'])) {
                    $item['s_title'] = $item['locale'][$prefLocale]['s_title'];
                } else {
                    // check each locale until we find one that has a title
                    $item['s_title'] = '';
                    if (isset($item['locale'])) {
                        foreach ($item['locale'] as $locale => $title) {
                            if (isset($title['s_title']) && $title['s_title']  != '') {
                                $item['s_title'] = $title['s_title'];
                                break;
                            }
                        }
                    }
                }
                if (isset($item['locale'][$prefLocale]['s_description'])) {
                    $item['s_description'] = $item['locale'][$prefLocale]['s_description'];
                } else {
                    // check each locale until we find one that has a description
                    $item['s_description'] = '';
                    if (isset($item['locale']) && is_array($item['locale'])) {
                        foreach ($item['locale'] as $locale => $description) {
                            if (isset($description['s_description']) && $description['s_description'] != '') {
                                $item['s_description'] = $description['s_description'];
                                break;
                            }
                        }
                    }
                }
                $extendedItems[] = $item;
            }
            return $extendedItems;
        }
        return $items;
    }
}

/* file end: ./oc-includes/osclass/model/Item.php */
