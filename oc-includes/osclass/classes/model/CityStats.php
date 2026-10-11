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
use mindstellar\listing\ListingCountStore;

/**
 * The live listing count of each city, kept in t_city.i_num_items
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class CityStats extends DAO
{
    use \mindstellar\base\SharedInstance;

    /**
     * No table is behind this DAO: the counts are in t_city.i_num_items, through ListingCountStore.
     * The DAO names a table that no longer exists, so the inherited DAO methods fail on purpose.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_city_stats');
        $this->setPrimaryKey('fk_i_city_id');
        $this->setFields(array('fk_i_city_id', 'i_num_items'));
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Add one to a city's count.
     *
     * @param int $cityId City id
     *
     * @return bool false when the id is not a number, no city has it, or the write fails
     */
    public function increaseNumItems($cityId)
    {
        try {
            return is_numeric($cityId) && ListingCountStore::cities()->add(array((int)$cityId), 1) === 1;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Take one from a city's count, never below zero.
     *
     * @param int $cityId City id
     *
     * @return int|false rows changed, or false when the id is not a number or the write fails
     */
    public function decreaseNumItems($cityId)
    {
        try {
            return is_numeric($cityId) ? ListingCountStore::cities()->add(array((int)$cityId), -1) : false;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Set a city's count.
     *
     * @param int $cityID
     * @param int $numItems
     *
     * @return bool false when the write fails
     */
    public function setNumItems($cityID, $numItems)
    {
        try {
            return ListingCountStore::cities()->set(array((int)$cityID => (int)$numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * A city's count, as the old stats row: fk_i_city_id and i_num_items.
     *
     * @param int $cityId city id
     *
     * @return array<string,string>|false False when there is no such city
     */
    public function findByCityId($cityId)
    {
        try {
            return (ListingCountStore::cities()->row((int)$cityId, 'fk_i_city_id') ?? false);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Zero the count of every city in a region.
     *
     * @param int $regionId
     *
     * @return bool false when the write fails
     */
    public function deleteByRegion($regionId)
    {
        try {
            Db::table(ListingCountStore::cities()->table())->where('fk_i_region_id', (int)$regionId)->update(array('i_num_items' => 0));
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
     * @param int|string|null $region a region id, or %%%% for any
     * @param string   $zero  Comparison operator applied to i_num_items
     * @param string   $order '<column> ASC|DESC'
     *
     * @return array<int,array{city_id:string,items:string,city_name:string|null,city_slug:string|null}>
     */
    public function listCities($region = null, $zero = '>', $order = 'city_name ASC')
    {
        $key   = md5(osc_base_url() . (string)$region . (string)$zero . (string)$order);
        $found = false;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
                $zero = '>';
            }
            if (!preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', (string)$order)) {
                $order = 'city_name ASC';
            }
            $cityTable = ListingCountStore::cities()->table();

            // Column aliases put this beyond the builder's identifier allowlist. The region id
            // is the one caller value and it is bound; $zero and $order were checked above.
            $params = array();
            $sql    = 'SELECT pk_i_id as city_id, i_num_items as items, s_name as city_name, s_slug as city_slug'
                . ' FROM ' . $cityTable . ' WHERE i_num_items ' . $zero . ' 0';

            if (is_numeric($region)) {
                $sql     .= ' AND fk_i_region_id = ?';
                $params[] = $region;
            }

            $sql .= ' ORDER BY ' . $order;

            try {
                $rows = Db::select($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                return array();
            }

            $return = Db::stringifyRows($rows);
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
            $row = Db::selectOne($sql, array((int)$cityId, date('Y-m-d H:i:s')));
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        if ($row !== null) {
            $row = Db::stringifyRow($row);

            return $row['total'];
        }

        return 0;
    }

    /**
     * Batch calculate the total items that belong to city id
     *
     * @param array<int,int|string> $cities array of city ids
     *
     * @return array<int|string,int|string> City id => item count, 0 for a city with none
     */
    private function calculateAllStats(array $cities): array
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
            . ' AND (' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX
            . 't_item.dt_expiration >= ?) '
            . ' AND ' . DB_TABLE_PREFIX . 't_category.b_enabled = 1'
            . ' AND fk_i_city_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' GROUP BY fk_i_city_id';

        try {
            $rows = Db::select($sql, array_merge(array(date('Y-m-d H:i:s')), $ids));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        foreach (Db::stringifyRows($rows) as $a) {
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
     * @param array<int,int|string> $cities array of city ids
     *
     * @return bool True once every counter is written, false when there is
     *              nothing to write or the write fails
     */
    public function updateAllStats(array $cities)
    {
        try {
            return ListingCountStore::cities()->set($this->calculateAllStats($cities));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/CityStats.php */
