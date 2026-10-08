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

/**
 * Model database for Item table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Item extends DAO
{
    /** Width of t_item_description.s_title, in characters. */
    public const TITLE_WIDTH = 100;

    /**
     * It references to self object: Item.
     * It is used as a singleton
     *
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
            'dt_first_pub_date',
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
     * @return Item
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
     * List items ordered by views
     *
     * @param int $limit
     *
     * @return array<int,array<string,mixed>> Empty when the query failed
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
            $items = Db::select($sql, array((int)$limit));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(Db::stringifyRows($items));
    }

    /**
     * Extends the given array $items with description in available locales
     *
     * @param array<int,array<string,mixed>> $items array set of items
     * @param string|null                    $prefLocale Defaults to the current locale
     *
     * @return array<int,array<string,mixed>> with description extended with all available locales
     */
    public function extendData($items, $prefLocale = null)
    {
        $items = $this->extendRows($items, $prefLocale);

        // Batch-prime the resource cache for the whole page so the theme's
        // per-item osc_get_item_resources() calls are cache hits, not an N+1.
        if (is_array($items) && count($items) > 1) {
            ItemResource::getInstance()->primeResourcesCache(array_column($items, 'pk_i_id'));
        }

        return $items;
    }

    /**
     * extendData() without priming the photo cache, for a caller that loads photos itself.
     *
     * @param array<int,array<string,mixed>> $items
     * @param string|null                    $prefLocale Defaults to the current locale
     *
     * @return array<int,array<string,mixed>>
     */
    public function extendRows($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            $items = $this->extendItemDescription($items, $prefLocale);
            $items = $this->extendCategoryName($items, $prefLocale);
            try {
                $itemStatsLocations = (new \mindstellar\listing\ListingQuery())->statsAndLocations(array_column($items, 'pk_i_id'));
            } catch (\mindstellar\database\DbException $e) {
                $itemStatsLocations = array();
            }

            foreach ($items as $k => $aItem) {
                // Add stats and locations data
                if ($itemStatsLocations !== array()) {
                    foreach ($itemStatsLocations as $key => $isl) {
                        if ($aItem['pk_i_id'] === $isl['fk_i_item_id']) {
                            $aItem += $isl;
                            unset($itemStatsLocations[$key]);
                        }
                    }
                }
                $items[$k] = $aItem;
            }
        }

        return $items;
    }

    /**
     * List Items with category name
     *
     * @return array<int,array<string,mixed>> Each row carries an extra s_category_name
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
            $rows = Db::select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }

    /**
     * Find item resources belong to an item given its id
     *
     * @param int $id Item id
     *
     * @return array<int,array<string,string|null>> resource rows
     */
    public function findResourcesByID($id)
    {
        return ItemResource::getInstance()->getResources($id);
    }

    /**
     * Find the item location given a item id
     *
     * @param int $id Item id
     *
     * @return array<string,string|null>|false False when the item has no location row
     */
    public function findLocationByID($id)
    {
        return ItemLocation::getInstance()->findByPrimaryKey($id);
    }

    /**
     * Find items belong to a category given its id
     *
     * @param int $catId
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByCategoryID($catId)
    {
        return $this->listWhere('fk_i_category_id = %d', (int)$catId);
    }

    /**
     * Comodin function to serve multiple queries
     *
     * @param mixed ...$args A raw WHERE fragment the caller owns, or a printf-style
     *                       format followed by the values bound to its %d/%s conversions
     *
     * @return array<int,array<string,mixed>> Empty when there is no argument, no match or a query failure
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
            $items = Db::select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(Db::stringifyRows($items));
    }

    /**
     * Find items belong to a phone number
     *
     * @param string $phone
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByPhone($phone)
    {
        return $this->listWhere('s_contact_phone = %s', $phone);
    }

    /**
     * Find items belong to an email
     *
     * @param string $email
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByEmail($email)
    {
        return $this->listWhere('s_contact_email = %s', $email);
    }

    /**
     * Count all items, or all items belong to a category id, can be filtered
     * by $options  ['ACTIVE|INACTIVE|ENABLED|DISABLED|SPAM|NOTSPAM|EXPIRED|NOTEXPIRED|PREMIUM|TODAY']
     *
     * @param int|null                       $categoryId
     * @param string|array<int,string>|null  $options could be a string with | separator or an array with the options
     *
     * @return int|string total items as a string, int 0 on a query failure
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
            $total = Db::scalar($sql, $params);
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
     * @param array<int,mixed> $params
     * @param string   $sql
     * @param string   $bool 'AND' or 'OR'
     * @param array<int,mixed> $vals values for the placeholders in $sql, in order
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
     * Every option contributes an AND-connected fragment; NOTEXPIRED groups its own
     * premium/expiry alternation rather than leaking an OR into the caller's chain.
     *
     * @param string|array<int,string>|null $options could be a string with | separator or an array with the options
     * @param string[]                      $conditions
     * @param array<int,mixed>              $params
     *
     * @return void
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
                    // One self-contained fragment, ANDed like every other option. Pushed as
                    // two -- the first with an OR connector -- it broke out of the AND chain
                    // and swallowed the caller's own conditions: findItemByTypes() joins
                    // fragments unparenthesised, so `owner = 24 AND live OR premium` reads as
                    // `(mine AND live) OR (any premium listing)` and every seller's listing
                    // page showed the whole site's premium rows. Same predicate as
                    // liveConditions(), which had it right all along.
                    $this->pushWhere(
                        $conditions,
                        $params,
                        '( i.b_premium = ? OR i.dt_expiration >= ? )',
                        'AND',
                        array(1, date('Y-m-d H:i:s'))
                    );
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
     * SQL fragments for "this listing is publicly live", to join with AND.
     *
     * Compatibility: use \mindstellar\listing\ListingStatus::liveConditions(), which holds the rule.
     *
     * @param string $alias column qualifier ending in a dot, or '' for none
     *
     * @return string[]
     */
    public static function liveConditions($alias = '')
    {
        return \mindstellar\listing\ListingStatus::liveConditions((string) $alias);
    }

    /**
     * The nearest live listing by id, with only the fields the listing URL needs.
     * The title follows findByPrimaryKey(): $locale first, else the first non-empty one.
     *
     * @param int    $itemId Listing to start from
     * @param bool   $next   true for the next higher id, false for the next lower
     * @param string $locale Preferred title locale
     *
     * @return array{pk_i_id:string,fk_i_category_id:string,s_city:string,s_title:string}|array{}
     *         Empty when there is no such listing or the query fails
     */
    public function findAdjacentLive(int $itemId, bool $next, string $locale): array
    {
        $sql = 'SELECT a.pk_i_id, a.fk_i_category_id, l.s_city, d.fk_c_locale_code, d.s_title'
            . ' FROM (SELECT i.pk_i_id, i.fk_i_category_id FROM ' . $this->getTableName() . ' i'
            . ' WHERE i.pk_i_id ' . ($next ? '>' : '<') . ' ? AND ' . implode(' AND ', self::liveConditions('i.'))
            . ' ORDER BY i.pk_i_id ' . ($next ? 'ASC' : 'DESC') . ' LIMIT 1) a'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . 't_item_location l ON l.fk_i_item_id = a.pk_i_id'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . "t_item_description d ON d.fk_i_item_id = a.pk_i_id AND d.s_title <> ''"
            . ' ORDER BY d.fk_c_locale_code';

        try {
            $rows = Db::stringifyRows(Db::select($sql, array($itemId)));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($rows === array()) {
            return array();
        }

        $title = '';
        foreach ($rows as $row) {
            if ($row['fk_c_locale_code'] === $locale) {
                $title = (string)$row['s_title'];
                break;
            }
            if ($title === '') {
                $title = (string)$row['s_title'];
            }
        }

        return array(
            'pk_i_id'          => (string)$rows[0]['pk_i_id'],
            'fk_i_category_id' => (string)$rows[0]['fk_i_category_id'],
            's_city'           => (string)$rows[0]['s_city'],
            's_title'          => $title,
        );
    }

    /**
     * Count the live items in a category.
     *
     * LEAVE THIS FOR COMPATIBILITIES ISSUES (ONLY SITEMAP GENERATOR)
     * BUT REMEMBER TO DELETE IN ANYTHING > 2.1.x THANKS
     *
     * @param array{pk_i_id:int|string} $category
     * @param bool                      $enabled
     * @param bool                      $active
     *
     * @return int|string The count as a string, int 0 on a query failure
     */
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
            $total = Db::scalar($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string)$total;
    }

    /**
     * List the most recently published live items.
     *
     * @param int $limit
     *
     * @return array<int,array<string,mixed>>
     */
    public function listLatest($limit = 10)
    {
        return $this->listWhere(' b_active = 1 AND b_enabled = 1 ORDER BY dt_pub_date DESC LIMIT %d', (int)$limit);
    }

    /**
     * Insert title and description for a given locale and item id.
     *
     * @param int    $id Item id
     * @param string $locale
     * @param string $title
     * @param string $description
     *
     * @return bool False when the write failed
     */
    public function insertLocale($id, $locale, $title, $description)
    {
        $array_set = array(
            'fk_i_item_id'     => $id,
            'fk_c_locale_code' => $locale,
            's_title'          => self::fitTitle($title),
            's_description'    => $description
        );

        try {
            Db::table(DB_TABLE_PREFIX . 't_item_description')->insert($array_set);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find items belong to an user given its id
     *
     * @param int      $userId User id
     * @param int      $start  begining
     * @param int|null $end    ending
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUserID($userId, $start = 0, $end = null)
    {
        $condition = 'fk_i_user_id = ' . (int)$userId;

        return $this->findItemByTypes($condition, 'all', false, $start, $end);
    }

    /**
     * Find enabled items or count of items by types with given where condition
     *
     * @param string|array<int,string|array{0:string,1:array<int,mixed>}>|null $conditions
     *                                Where condition on t_item table i.e "pk_i_id = 3"
     * @param string|false $itemType  item(active, expired, pending, pending validate, premium, all, enabled,
     *                                blocked)
     * @param bool         $count     return the count instead of the rows
     * @param int          $limit     beginning from $limit
     * @param int|null     $offset    ending
     *
     * @return array<int,array<string,mixed>>|int|string array of items, or the count as a string
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
                $total = Db::scalar($sql, $params);
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
            $items = Db::select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(Db::stringifyRows($items));
    }

    /**
     * add conditions by type
     *
     * @param string|false     $itemType
     * @param string[]         $conditions
     * @param array<int,mixed> $params
     *
     * @return void
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
                // 'NOSPAM' matched no case in addWhereByOptions() and fell through its
                // default, so the one filter this type exists for was never applied.
                $this->addWhereByOptions(['ACTIVE', 'NOTSPAM', 'NOTEXPIRED'], $conditions, $params);

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
     * @param int $userId User id
     *
     * @return int|string number of items, as a string
     */
    public function countByUserID($userId)
    {
        return $this->countItemTypesByUserID($userId, 'all');
    }

    /**
     * Count items by User Id according the
     *
     * @param int          $userId   User id
     * @param string|false $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string       $cond     Raw WHERE fragment the caller owns
     *
     * @return int|string number of items, as a string
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
     * @param int      $userId User id
     * @param int      $start  beginning from $start
     * @param int|null $end    ending
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUserIDEnabled($userId, $start = 0, $end = null)
    {
        $condition = 'fk_i_user_id = ' . (int)$userId;

        return $this->findItemByTypes($condition, false, false, $start, $end);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @param int $hours
     *
     * @return array<int,array<string,mixed>>
     * @since  3.2
     */
    public function findByHourExpiration($hours = 24)
    {
        // Same rows as TIMESTAMPDIFF(HOUR, NOW(), dt_expiration) = $hours, as a range an index can use.
        $hours      = (int)$hours;
        $conditions = [
            'dt_expiration >= NOW() + INTERVAL ' . $hours . ' HOUR AND dt_expiration < NOW() + INTERVAL ' . ($hours + 1) . ' HOUR',
            'b_active = 1',
            'b_spam = 0'
        ];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @param int $days
     *
     * @return array<int,array<string,mixed>>
     * @since  3.2
     */
    public function findByDayExpiration($days = 1)
    {
        $days       = (int)$days;
        $conditions = [
            'dt_expiration >= NOW() + INTERVAL ' . $days . ' DAY AND dt_expiration < NOW() + INTERVAL ' . ($days + 1) . ' DAY',
            'b_active = 1',
            'b_spam = 0'
        ];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Count enabled items belong to an user given its id
     *
     * @param int $userId User id
     *
     * @return int|string number of items, as a string
     */
    public function countByUserIDEnabled($userId)
    {
        return $this->countItemTypesByUserID($userId, 'enabled');
    }

    /**
     * Find enable items according the
     *
     * @param int          $userId   User id
     * @param int          $start    beginning from $start
     * @param int|null     $end      ending
     * @param string|false $itemType item(active, expired, pending, premium, all, enabled, blocked)
     *
     * @return array<int,array<string,mixed>>
     */
    public function findItemTypesByUserID($userId, $start = 0, $end = null, $itemType = false)
    {
        return $this->findItemByTypes('fk_i_user_id = ' . (int)$userId, $itemType, false, $start, $end);
    }

    /**
     * Count items by Email according the
     * Useful for counting item that posted by unregistered user
     *
     * @param string       $email    Email
     * @param string|false $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string       $cond     Raw WHERE fragment the caller owns
     *
     * @return int|string number of items, as a string
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
     * @param int    $id
     * @param string $stat
     *
     * @return int|null rows changed (0 on a failed query); null for an unknown stat
     */
    public function clearStat($id, $stat)
    {
        if (!is_string($stat) || !isset(\mindstellar\listing\ListingCounters::REPORTS[$stat])) {
            return null;
        }

        return \mindstellar\listing\ListingCounters::clearReport((int) $id, $stat);
    }

    /**
     * Cut a title to the column width: strict SQL mode refuses an over-long one and loses the row.
     *
     * @param mixed $title
     *
     * @return string|null
     */
    private static function fitTitle($title): ?string
    {
        return $title === null ? null : mb_substr((string) $title, 0, self::TITLE_WIDTH, 'UTF-8');
    }

    /**
     * Update title and description given a item id and locale.
     *
     * @param int    $id
     * @param string $locale
     * @param string $title
     * @param string $text
     *
     * @return bool
     */
    public function updateLocaleForce($id, $locale, $title, $text)
    {
        // REPLACE INTO has no query-builder equivalent, so it is hand-written with
        // every value bound. Column order matches the legacy replace() set.
        $sql = 'REPLACE INTO ' . DB_TABLE_PREFIX . 't_item_description'
            . ' (s_title, s_description, fk_c_locale_code, fk_i_item_id) VALUES (?, ?, ?, ?)';

        try {
            Db::execute($sql, array(self::fitTitle($title), $text, $locale, $id));
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
     * @param int        $id
     * @param int|string $expiration_time could be interget (number of days) or directly a date
     * @param bool       $do_stats
     *
     * @return string|false new date expiration, false if error occurs
     *
     */
    public function updateExpirationDate($id, $expiration_time, $do_stats = true)
    {
        if (!$expiration_time) {
            return false;
        }

        try {
            $item = Db::selectOne(
                'SELECT dt_expiration, b_enabled, b_active, b_spam, b_premium FROM ' . $this->getTableName() . ' WHERE pk_i_id = ?',
                array($id)
            );
        } catch (\mindstellar\database\DbException $e) {
            $item = null;
        }

        // Legacy entered this block whenever the read did not error, even for a
        // zero-row (missing id) result — in which case the UPDATE below matched
        // nothing and the method fell through to false. A missing id here yields a
        // null row and converges on the same false, so it is guarded up front.
        if ($item !== null) {
            $item        = Db::stringifyRow($item);
            $counted_old = osc_item_is_counted($item);
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
                $result = Db::execute($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                $result = 0;
            }

            if ($result && $result > 0) {
                try {
                    $_item = Db::selectOne(
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
                $_item = Db::stringifyRow($_item);

                // Model-level write the controller-layer events never see. Announce the new
                // expiry so an index or cache mirroring liveness can react (an expiry change can
                // flip a listing in or out of the "live" set).
                osc_run_hook('item_expiration_updated', (int)$id, $_item['dt_expiration']);

                if (!$do_stats) {
                    return $_item['dt_expiration'];
                }

                $counted = osc_item_is_counted(array('dt_expiration' => $_item['dt_expiration']) + $item);
                if ($counted !== $counted_old) {
                    if (!$counted) {
                        if ($_item['fk_i_user_id'] != null) {
                            User::getInstance()->decreaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::getInstance()->decreaseNumItems($_item['fk_i_category_id']);
                        CountryStats::getInstance()->decreaseNumItems($_item['fk_c_country_code']);
                        RegionStats::getInstance()->decreaseNumItems($_item['fk_i_region_id']);
                        CityStats::getInstance()->decreaseNumItems($_item['fk_i_city_id']);
                    } else {
                        if ($_item['fk_i_user_id'] != null) {
                            User::getInstance()->increaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::getInstance()->increaseNumItems($_item['fk_i_category_id']);
                        CountryStats::getInstance()->increaseNumItems($_item['fk_c_country_code']);
                        RegionStats::getInstance()->increaseNumItems($_item['fk_i_region_id']);
                        CityStats::getInstance()->increaseNumItems($_item['fk_i_city_id']);
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
     * @param int                             $enable 0 or 1
     * @param array<int,int|string>|int|string $aIds   Category ids
     *
     * @return bool False for an empty id list or a failed write
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
            Db::execute($sql, array((int)$enable));
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
     * @return int|string The count as a string, int 0 for a null type or a query failure
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
            $total = Db::scalar($sql);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string)$total;
    }

    /**
     * Return meta fields for a given item
     *
     * @param int $id Item id
     *
     * @return array<int|string,array<string,mixed>> meta fields keyed by field id
     */
    public function metaFields($id)
    {
        $metaFields = Field::getInstance()->findByItem($id);
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
     * @param int $cityAreaId city area id
     *
     * @return int number of affected rows
     * @throws \mindstellar\database\DbException on a query failure
     *
     * @since  3.1
     */
    public function deleteByCityArea($cityAreaId)
    {
        // Legacy had no error branch here (a failed read fataled on ->result()),
        // so a DbException is left to propagate rather than absorbed.
        $items = Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_city_area_id', $cityAreaId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by primary key, delete dependencies too
     *
     * @param int $id Item id
     *
     * @return int|false Rows removed from t_item, or false when the transaction failed
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
        $resources = ItemResource::getInstance()->getAllResourcesFromItem($id);

        // t_item_moderation_log and t_item_report_log carry no foreign key to the
        // item, so only this removes them. t_item_comment and t_item_resource are
        // RESTRICT and must go first; the rest cascade, and stay listed for installs
        // whose foreign keys were never created.
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
            $deleted = Db::transaction(function () use ($id, $dependents) {
                foreach ($dependents as $depTable) {
                    Db::table(DB_TABLE_PREFIX . $depTable)->where('fk_i_item_id', $id)->delete();
                }

                // Not parent::deleteByPrimaryKey(): the inherited DAO reports a failed
                // delete by returning false rather than raising, and a plain return
                // inside a transaction reads as success — so the dependent deletes above
                // would commit and leave the listing in place with everything attached
                // to it already gone. This is the same statement the DAO would run, from
                // the layer that raises, which is what makes the rollback real. It still
                // reports 0 for an id that matched nothing, which is not a failure.
                return Db::table(DB_TABLE_PREFIX . 't_item')->where('pk_i_id', $id)->delete();
            });
        } catch (\Throwable $e) {
            return false;
        }

        // The files go once the outermost transaction commits, and stay if it rolls back.
        \mindstellar\listing\PhotoService::deleteFilesFromDisk($id, $isAdmin, $resources);

        // Counters are decremented only once the row is really gone. Doing it first
        // meant a delete that failed still took the listing out of every total, and
        // the numbers stayed wrong until the next stats rebuild.
        if (osc_item_is_counted($item)) {
            if ($item['fk_i_user_id'] != null) {
                User::getInstance()->decreaseNumItems($item['fk_i_user_id']);
            }
            CategoryStats::getInstance()->decreaseNumItems($item['fk_i_category_id']);
            CountryStats::getInstance()->decreaseNumItems($item['fk_c_country_code']);
            RegionStats::getInstance()->decreaseNumItems($item['fk_i_region_id']);
            CityStats::getInstance()->decreaseNumItems($item['fk_i_city_id']);
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
     * @param array<int,array{fk_i_item_id:int|string}> $items rows containing an fk_i_item_id column
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
     * @param int $id Item id
     *
     * @return array<string,mixed>|false Empty array when the id is unknown, false on a query failure
     */
    public function findByPrimaryKey($id)
    {
        if (!is_numeric($id)) {
            return array();
        }
        // Aliased i.* projection; hand-written with the id bound.
        try {
            $rows = Db::select('SELECT i.* FROM ' . $this->getTableName() . ' i WHERE i.pk_i_id = ?', array($id));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($rows === array()) {
            return array();
        }

        return $this->extendDataSingle(Db::stringifyRow($rows[0]));
    }

    /**
     * Extends the given array $item with description in available locales
     *
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed> item array with description in available locales
     */
    public function extendDataSingle($item)
    {
        return $this->extendData(array($item))[0];
    }

    /**
     * Delete by city
     *
     * @param int $cityId city id
     *
     * @return int number of affected rows
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function deleteByCity($cityId)
    {
        $items = Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_city_id', $cityId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by region
     *
     * @param int $regionId region id
     *
     * @return int number of affected rows
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function deleteByRegion($regionId)
    {
        $items = Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_i_region_id', $regionId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by country
     *
     * @param string $countryId country code
     *
     * @return int number of affected rows
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function deleteByCountry($countryId)
    {
        $items = Db::stringifyRows(
            Db::table(DB_TABLE_PREFIX . 't_item_location')
                ->select('fk_i_item_id')
                ->where('fk_c_country_code', $countryId)
                ->get()
        );

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Extends the given array $items with category name , and description in available locales
     *
     * @param array<int,array<string,mixed>> $items array with items
     * @param string|null                    $prefLocale Defaults to the current locale
     *
     * @return array<int,array<string,mixed>> with category name
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
            $categories = Db::stringifyRows(Db::select($sql, $params));
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
     * @param array<int,array<string,mixed>> $items array with items
     * @param string|null                    $prefLocale Defaults to the current locale
     *
     * @return array<int,array<string,mixed>> $items with description
     */
    private function extendItemDescription($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            try {
                $descriptions = (new \mindstellar\listing\ListingQuery())->descriptions(array_column($items, 'pk_i_id'));
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
