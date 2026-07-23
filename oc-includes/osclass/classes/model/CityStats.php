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
 * Model database for CityStats table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class CityStats extends DAO
{
    /**
     * It references to self object: CityStats.
     * It is used as a singleton
     *
     * @access private
     * @since  2.4
     * @var CityStats
     */
    private static $instance;

    /**
     * Set data related to t_city_stats table
     *
     * @access public
     * @since  2.4
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_city_stats');
        $this->setPrimaryKey('fk_i_city_id');
        $this->setFields(array('fk_i_city_id', 'i_num_items'));
    }

    /**
     * It creates a new CityStats object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return \CityStats
     * @since  2.4
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Increase number of city items, given a city id
     *
     * @access public
     *
     * @param int $cityId City id
     *
     * @return bool True once the counter is written, false when the id is
     *              rejected or the write fails
     * @since  2.4
     */
    public function increaseNumItems($cityId)
    {
        if (!is_numeric($cityId)) {
            return false;
        }

        // Legacy built this SQL with sprintf('%d', $cityId), truncating to an
        // integer before the query ever reached the database. A bound parameter
        // has to reproduce that truncation explicitly: MySQL's foreign-key check
        // on a prepared value does not coerce a fractional numeric string the way
        // a plain integer literal does, so an uncast bind of e.g. "5.7" fails
        // where the legacy, already-truncated SQL text succeeded.
        $cityId = (int)$cityId;

        // The only caller value is the bound placeholder; the table name is the
        // one this model set on itself in the constructor.
        $sql = sprintf(
            'INSERT INTO %s (fk_i_city_id, i_num_items) VALUES (?, 1) ON DUPLICATE KEY UPDATE i_num_items = i_num_items + 1',
            $this->getTableName()
        );

        try {
            osc_db_execute($sql, array($cityId));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Increase number of city items, given a city id
     *
     * @access public
     *
     * @param int $cityId City id
     *
     * @return bool|int Number of affected rows, or false when there is no
     *                  counter row for that city
     * @since  2.4
     */
    public function decreaseNumItems($cityId)
    {
        if (!is_numeric($cityId)) {
            return false;
        }

        try {
            $cityStat = osc_db_table($this->getTableName())
                ->select('i_num_items')
                ->where($this->getPrimaryKey(), $cityId)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            $cityStat = null;
        }

        if (isset($cityStat['i_num_items'])) {
            // The counter is assigned from itself, which no builder update can
            // express; the city id is the single bound value and the table name
            // is the model's own.
            $sql = 'UPDATE ' . $this->getTableName()
                . ' SET i_num_items = i_num_items - 1 WHERE i_num_items > 0 AND fk_i_city_id = ?';

            try {
                return osc_db_execute($sql, array($cityId));
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Set i_num_items, given a city id
     *
     * @access public
     *
     * @param int $cityID
     * @param int $numItems
     *
     * @return bool True once the counter is written, false when the write fails
     * @since  2.4
     *
     */
    public function setNumItems($cityID, $numItems)
    {
        $cityID   = (int)$cityID;
        $numItems = (int)$numItems;

        // Both caller values are bound; the count is bound twice rather than
        // read back with VALUES(), which MySQL 8.0.20 deprecates and whose
        // replacement syntax MariaDB does not accept.
        $sql = 'INSERT INTO ' . $this->getTableName()
            . ' (fk_i_city_id, i_num_items) VALUES (?, ?) ON DUPLICATE KEY UPDATE i_num_items = ?';

        try {
            osc_db_execute($sql, array($cityID, $numItems, $numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find stats by city id
     *
     * @access public
     *
     * @param int $cityId city id
     *
     * @return array
     * @since  2.4
     */
    public function findByCityId($cityId)
    {
        return $this->findByPrimaryKey($cityId);
    }

    /**
     *
     * @param int $regionId
     *
     * @return bool True once the delete has run, false when it fails
     */
    public function deleteByRegion($regionId)
    {
        // The WHERE is an IN subquery over another table, which no builder delete
        // can express. The region id is the single bound value and still carries
        // the (int) cast legacy applied to it; both table names are compile-time
        // literals.
        $sql = 'DELETE FROM ' . DB_TABLE_PREFIX
            . 't_city_stats WHERE fk_i_city_id IN (SELECT pk_i_id FROM ' . DB_TABLE_PREFIX
            . 't_city WHERE fk_i_region_id = ?)';

        try {
            osc_db_execute($sql, array((int)$regionId));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Return a list of cities and counter items.
     * Can be filtered by region and num_items,
     * and ordered by city_name or items counter
     * $order = 'city_name ASC' OR $oder = 'items DESC'
     *
     * @param int    $region
     * @param string $zero
     * @param string $order
     *
     * @return array
     */
    public function listCities($region = null, $zero = '>', $order = 'city_name ASC')
    {
        $key   = md5(osc_base_url() . (string)$region . (string)$zero . (string)$order);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
                $zero = '>';
            }
            if (!preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', (string)$order)) {
                $order = 'city_name ASC';
            }
            $statsTable = $this->getTableName();
            $cityTable  = DB_TABLE_PREFIX . 't_city';

            // Column aliases and a LEFT JOIN put this beyond the builder's
            // identifier allowlist. The region id is the one caller value and it
            // is bound: $zero is one of the seven operators the in_array() above
            // accepts, $order matched the identifier-plus-direction pattern
            // above, and both table names are fixed (the model's own and t_city).
            $params = array();
            $sql    = 'SELECT ' . $statsTable . '.fk_i_city_id as city_id, ' . $statsTable
                . '.i_num_items as items, ' . $cityTable . '.s_name as city_name, ' . $cityTable
                . '.s_slug as city_slug'
                . ' FROM ' . $statsTable
                . ' LEFT JOIN ' . $cityTable . ' ON ' . $statsTable . '.fk_i_city_id = ' . $cityTable . '.pk_i_id'
                . ' WHERE i_num_items ' . $zero . ' 0';

            if (is_numeric($region)) {
                $sql     .= ' AND ' . $cityTable . '.fk_i_region_id = ?';
                $params[] = $region;
            }

            $sql .= ' ORDER BY ' . $order;

            try {
                $rows = osc_db_select($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                return array();
            }

            $return = osc_db_stringify_rows($rows);
            osc_cache_set($key, $return, OSC_CACHE_TTL);

            return $return;
        }

        return $cache;
    }

    /**
     * Calculate the total items that belong to city id
     *
     * @param int $cityId
     *
     * @return int|string Item count as a string, or int 0 when the query fails
     */
    public function calculateNumItems($cityId)
    {
        // Three fixed core tables and an aggregate, so the builder cannot carry
        // it; the city id and the expiry cut-off are the only caller values and
        // both are bound. The id keeps the (int) cast legacy already applied, and
        // the cut-off stays on PHP's clock, as it has always been, rather than
        // moving to the server's NOW().
        $sql = 'SELECT count(*) as total FROM ' . DB_TABLE_PREFIX . 't_item_location, ' . DB_TABLE_PREFIX . 't_item, '
            . DB_TABLE_PREFIX . 't_category ';
        $sql .= 'WHERE ' . DB_TABLE_PREFIX . 't_item_location.fk_i_city_id = ? AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.b_active = 1 AND ' . DB_TABLE_PREFIX . 't_item.b_enabled = 1 AND '
            . DB_TABLE_PREFIX . 't_item.b_spam = 0 AND ';
        $sql .= '(' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX
            . 't_item.dt_expiration >= ? ) AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.b_enabled = 1 ';

        try {
            $row = osc_db_select_one($sql, array((int)$cityId, date('Y-m-d H:i:s')));
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        if ($row !== null) {
            $row = osc_db_stringify_row($row);

            return $row['total'];
        }

        return 0;
    }

    /**
     * Batch calculate the total items that belong to city id
     *
     * @param array $cities array of city ids
     *
     * @return array
     */
    private function calculateAllStats(array $cities)
    : array
    {
        if (empty($cities)) {
            return array();
        }
        $return = array();
        $ids    = array_map('intval', $cities);

        // Two joins and an aggregate put this beyond the builder. Every caller
        // value is bound: the expiry cut-off and the city ids, the latter keeping
        // the intval() legacy already applied to them. Every table and column
        // name is a compile-time literal.
        //
        // The premium/expiry test is deliberately left UNPARENTHESISED, exactly
        // as the legacy clause was. `||` binds looser than AND, so it splits the
        // whole WHERE in two and the city filter constrains only one half. That
        // is load-bearing for what this query returns today; changing it is a
        // behaviour fix, not part of a conversion.
        $sql = 'SELECT fk_i_city_id, count(*) as i_num_items'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item_location'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item ON ' . DB_TABLE_PREFIX . 't_item.pk_i_id = '
            . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_category ON ' . DB_TABLE_PREFIX . 't_category.pk_i_id = '
            . DB_TABLE_PREFIX . 't_item.fk_i_category_id'
            . ' WHERE ' . DB_TABLE_PREFIX . 't_item.b_active = 1'
            . ' AND ' . DB_TABLE_PREFIX . 't_item.b_enabled = 1'
            . ' AND ' . DB_TABLE_PREFIX . 't_item.b_spam = 0'
            . ' AND ' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX
            . 't_item.dt_expiration >= ? '
            . ' AND ' . DB_TABLE_PREFIX . 't_category.b_enabled = 1'
            . ' AND fk_i_city_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' GROUP BY fk_i_city_id';

        try {
            $rows = osc_db_select($sql, array_merge(array(date('Y-m-d H:i:s')), $ids));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        foreach (osc_db_stringify_rows($rows) as $a) {
            $return[$a['fk_i_city_id']] = $a['i_num_items'];
        }
        // fill missing values with 0
        foreach ($cities as $c) {
            if (!isset($return[$c])) {
                $return[$c] = 0;
            }
        }

        return $return;
    }

    /**
     * Update the number of items for given cities ids
     *
     * @param array $cities array of city ids
     *
     * @return bool True once every counter is written, false when there is
     *              nothing to write or the upsert fails
     */
    public function updateAllStats(array $cities)
    {
        $newCalculated = $this->calculateAllStats($cities);

        if (empty($newCalculated)) {
            return false;
        }

        // A multi-row upsert: one placeholder pair per city, every id and count
        // bound and still carrying the (int) cast legacy applied to them, and the
        // table name the model's own. VALUES(i_num_items) is kept because it is
        // the only assignment form that reads back a per-ROW value, which a bound
        // parameter in the update clause cannot express; MySQL deprecates it but
        // both it and MariaDB still accept it, and the alias syntax that replaces
        // it is MySQL-only.
        $tuples = array();
        $params = array();
        foreach ($newCalculated as $id => $num) {
            $tuples[] = '(?, ?)';
            $params[] = (int)$id;
            $params[] = (int)$num;
        }

        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_city_id, i_num_items) VALUES '
            . implode(',', $tuples)
            . ' ON DUPLICATE KEY UPDATE i_num_items = VALUES(i_num_items)';

        try {
            osc_db_execute($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }
}

/* file end: ./oc-includes/osclass/model/CityStats.php */
