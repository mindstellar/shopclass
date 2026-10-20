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
            ListingCountStore::cities()->clear('fk_i_region_id', (int)$regionId);
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
            try {
                $return = ListingCountStore::cities()->listing(
                    'pk_i_id as city_id, i_num_items as items, s_name as city_name, s_slug as city_slug',
                    (string)$zero,
                    (string)$order,
                    'city_name ASC',
                    is_numeric($region) ? array('fk_i_region_id' => (int)$region) : array()
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
     * Count a city's live listings from the listings themselves.
     *
     * @param int $cityId
     *
     * @return int|string the count as a string, or int 0 when the query fails
     */
    public function calculateNumItems($cityId)
    {
        try {
            return ListingCountStore::cities()->recount(array((int)$cityId))[(string)(int)$cityId] ?? 0;
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Recount the listings of these cities and save the counts.
     *
     * @param array<int,int|string> $cities array of city ids
     *
     * @return bool True once every counter is written, false when there is
     *              nothing to write or the write fails
     */
    public function updateAllStats(array $cities)
    {
        try {
            $store = ListingCountStore::cities();

            return $store->set($store->recount(array_map('intval', $cities)));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/CityStats.php */
