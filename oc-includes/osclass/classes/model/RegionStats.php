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
     * No table is behind this DAO: the counts are in t_region.i_num_items, through ListingCountStore.
     * The DAO names a table that no longer exists, so the inherited DAO methods fail on purpose.
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
            // The columns depend on the sort column; an order over any other column selects every column.
            $by = explode(' ', ListingCountStore::order((string)$order, 'region_name ASC'))[0];
            if ($by === 'region_name') {
                $columns = 'pk_i_id as region_id, i_num_items as items, s_name as region_name, s_slug as region_slug';
            } elseif ($by === 'items') {
                $columns = 'pk_i_id as region_id, i_num_items as items, s_name as region_name';
            } else {
                $columns = '*';
            }

            try {
                $return = ListingCountStore::regions()->listing(
                    $columns,
                    (string)$zero,
                    (string)$order,
                    'region_name ASC',
                    $country !== '%%%%' ? array('fk_c_country_code' => (string)$country) : array()
                );
            } catch (\mindstellar\database\DbException $e) {
                return array();
            }

            osc_cache_set($key, $return, OSC_CACHE_TTL);

            return $return;
        }

        return $cache;
    }

    /**
     * Count a region's live listings from the listings themselves.
     *
     * @param int $regionId
     *
     * @return int|string the count as a string, or int 0 when the query fails
     */
    public function calculateNumItems($regionId)
    {
        try {
            return ListingCountStore::regions()->recount(array((int)$regionId))[(string)(int)$regionId] ?? 0;
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Recount the listings of these regions and save the counts.
     *
     * @param array<int,int|string> $regions array of region ids
     *
     * @return bool True once every counter is written, false when there is
     *              nothing to write or the write fails
     */
    public function updateAllStats(array $regions)
    {
        try {
            $store = ListingCountStore::regions();

            return $store->set($store->recount(array_map('intval', $regions)));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/RegionStats.php */
