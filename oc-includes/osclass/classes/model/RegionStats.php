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
 * The live listing count of each region, kept in t_region.i_num_items
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class RegionStats extends DAO
{
    use \mindstellar\base\SharedInstance;

    /**
     * Names the table that held the counts before 7.0, so the inherited DAO methods have no
     * table to act on. The counts are in t_region.i_num_items, through ListingCountStore.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_region_stats');
        $this->setPrimaryKey('fk_i_region_id');
        $this->setFields(array('fk_i_region_id', 'i_num_items'));
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Add one to a region's count.
     *
     * @param int $regionId Region id
     *
     * @return bool false when the id is not a number, no region has it, or the write fails
     */
    public function increaseNumItems($regionId)
    {
        try {
            return is_numeric($regionId) && ListingCountStore::regions()->add(array((int)$regionId), 1) === 1;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Take one from a region's count, never below zero.
     *
     * @param int $regionId Region id
     *
     * @return int|false rows changed, or false when the id is not a number or the write fails
     */
    public function decreaseNumItems($regionId)
    {
        try {
            return is_numeric($regionId) ? ListingCountStore::regions()->add(array((int)$regionId), -1) : false;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Set a region's count.
     *
     * @param int $regionID
     * @param int $numItems
     *
     * @return bool false when the write fails
     */
    public function setNumItems($regionID, $numItems)
    {
        try {
            return ListingCountStore::regions()->set(array((int)$regionID => (int)$numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * A region's count, as the old stats row: fk_i_region_id and i_num_items.
     *
     * @param int $regionId region id
     *
     * @return array<string,string>|false False when there is no such region
     */
    public function findByRegionId($regionId)
    {
        try {
            return (ListingCountStore::regions()->row((int)$regionId, 'fk_i_region_id') ?? false);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Return a list of regions and counter items.
     * Can be filtered by country and num_items,
     * and ordered by region_name or items counter.
     * $order = 'region_name ASC' OR $oder = 'items DESC'
     *
     * @param string $country Country code; the '%%%%' sentinel means every country
     * @param string $zero    Comparison operator applied to i_num_items
     * @param string $order   '<column> ASC|DESC'
     *
     * @return array<int,array<string,string|null>> Columns depend on the sort column
     * @since  2.4
     */
    public function listRegions($country = '%%%%', $zero = '>', $order = 'region_name ASC')
    {
        $key   = md5(osc_base_url() . (string)$country . (string)$zero . (string)$order);
        $found = false;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
                $zero = '>';
            }
            if (!preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', (string)$order)) {
                $order = 'region_name ASC';
            }
            $order_split = explode(' ', $order);

            $regionTable = ListingCountStore::regions()->table();

            // The projection depends on the sort column, and an order over any
            // other column selects nothing at all — which is how the legacy
            // builder fell through to SELECT *.
            if ($order_split[0] === 'region_name') {
                $columns = 'pk_i_id as region_id, i_num_items as items, s_name as region_name, s_slug as region_slug';
            } elseif ($order_split[0] === 'items') {
                $columns = 'pk_i_id as region_id, i_num_items as items, s_name as region_name';
            } else {
                $columns = '*';
            }

            // Column aliases put this beyond the builder's identifier allowlist. The country code
            // is the one caller value and it is bound; $zero and $order were checked above.
            $params = array();
            $sql    = 'SELECT ' . $columns . ' FROM ' . $regionTable . ' WHERE i_num_items ' . $zero . ' 0';

            if ($country !== '%%%%') {
                $sql     .= ' AND fk_c_country_code = ?';
                $params[] = $country;
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
     * Calculate the total items that belong to region
     *
     * @param int $regionId
     *
     * @return int|string Item count as a string, or int 0 when the query fails
     */
    public function calculateNumItems($regionId)
    {
        // Three fixed core tables and an aggregate, so the builder cannot carry
        // it; the region id and the expiry cut-off are the only caller values and
        // both are bound. The id keeps the (int) cast legacy already applied, and
        // the cut-off stays on PHP's clock, as it has always been, rather than
        // moving to the server's NOW().
        $sql = 'SELECT count(*) as total FROM ' . DB_TABLE_PREFIX . 't_item_location, ' . DB_TABLE_PREFIX . 't_item, '
            . DB_TABLE_PREFIX . 't_category ';
        $sql .= 'WHERE ' . DB_TABLE_PREFIX . 't_item_location.fk_i_region_id = ? AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.b_active = 1 AND ' . DB_TABLE_PREFIX . 't_item.b_enabled = 1 AND '
            . DB_TABLE_PREFIX . 't_item.b_spam = 0 AND ';
        $sql .= '(' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX
            . 't_item.dt_expiration >= ? ) AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.b_enabled = 1 ';

        try {
            $row = Db::selectOne($sql, array((int)$regionId, date('Y-m-d H:i:s')));
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
     * Batch calculate the total items that belong to region id
     *
     * @param array<int,int|string> $regions array of region ids
     *
     * @return array<int|string,int|string> Region id => item count, 0 for a region with none
     */
    private function calculateAllStats(array $regions): array
    {
        if (empty($regions)) {
            return array();
        }
        $return = array();
        $ids    = array_map('intval', $regions);

        // Two joins and an aggregate put this beyond the builder. Every caller
        // value is bound: the expiry cut-off and the region ids, the latter
        // keeping the intval() legacy already applied to them. Every table and
        // column name is a compile-time literal.
        //
        // The premium/expiry test is deliberately left UNPARENTHESISED, exactly
        // as the legacy clause was. `||` binds looser than AND, so it splits the
        // whole WHERE in two and the region filter constrains only one half.
        // That is load-bearing for what this query returns today; changing it is
        // a behaviour fix, not part of a conversion.
        $sql = 'SELECT fk_i_region_id, count(*) as i_num_items'
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
            . ' AND fk_i_region_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' GROUP BY fk_i_region_id';

        try {
            $rows = Db::select($sql, array_merge(array(date('Y-m-d H:i:s')), $ids));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        foreach (Db::stringifyRows($rows) as $a) {
            $return[$a['fk_i_region_id']] = $a['i_num_items'];
        }
        // fill missing values with 0
        foreach ($regions as $c) {
            if (!isset($return[$c])) {
                $return[$c] = 0;
            }
        }

        return $return;
    }

    /**
     * Update the number of items for given regions ids
     *
     * @param array<int,int|string> $regions array of region ids
     *
     * @return bool True once every counter is written, false when there is
     *              nothing to write or the write fails
     */
    public function updateAllStats(array $regions)
    {
        try {
            return ListingCountStore::regions()->set($this->calculateAllStats($regions));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/RegionStats.php */
