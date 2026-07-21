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
     * @return bool|\DBRecordsetClass number of affected rows, id error occurred return false
     * @since  2.4
     */
    public function increaseNumItems($cityId)
    {
        if (!is_numeric($cityId)) {
            return false;
        }

        return $this->dao->query(sprintf(
            'INSERT INTO %s (fk_i_city_id, i_num_items) VALUES (%d, 1) ON DUPLICATE KEY UPDATE i_num_items = i_num_items + 1',
            $this->getTableName(),
            $cityId
        ));
    }

    /**
     * Increase number of city items, given a city id
     *
     * @access public
     *
     * @param int $cityId City id
     *
     * @return int number of affected rows, id error occurred return false
     * @since  2.4
     */
    public function decreaseNumItems($cityId)
    {
        if (!is_numeric($cityId)) {
            return false;
        }

        $this->dao->select('i_num_items');
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $cityId);
        $result   = $this->dao->get();
        if ($result instanceof  DBRecordsetClass) {
            $cityStat = $result->row();
        }

        if (isset($cityStat['i_num_items'])) {
            $this->dao->from($this->getTableName());
            $this->dao->set('i_num_items', 'i_num_items - 1', false);
            $this->dao->where('i_num_items > 0');
            $this->dao->where('fk_i_city_id', $cityId);

            return $this->dao->update();
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
     * @return bool|\DBRecordsetClass
     * @since  2.4
     *
     */
    public function setNumItems($cityID, $numItems)
    {
        $cityID   = (int)$cityID;
        $numItems = (int)$numItems;

        return $this->dao->query('INSERT INTO ' . $this->getTableName()
            . " (fk_i_city_id, i_num_items) VALUES ($cityID, $numItems) ON DUPLICATE KEY UPDATE i_num_items = "
            . $numItems);
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
     * @return bool|\DBRecordsetClass
     */
    public function deleteByRegion($regionId)
    {
        return $this->dao->query('DELETE FROM ' . DB_TABLE_PREFIX
            . 't_city_stats WHERE fk_i_city_id IN (SELECT pk_i_id FROM ' . DB_TABLE_PREFIX
            . 't_city WHERE fk_i_region_id = ' . (int)$regionId . ');');
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
            $this->dao->select($this->getTableName() . '.fk_i_city_id as city_id, ' . $this->getTableName()
                . '.i_num_items as items, ' . DB_TABLE_PREFIX . 't_city.s_name as city_name, ' . DB_TABLE_PREFIX
                . 't_city.s_slug as city_slug');
            $this->dao->from($this->getTableName());
            $this->dao->join(
                DB_TABLE_PREFIX . 't_city',
                $this->getTableName() . '.fk_i_city_id = ' . DB_TABLE_PREFIX . 't_city.pk_i_id',
                'LEFT'
            );
            $this->dao->where('i_num_items ' . $zero . ' 0');
            if (is_numeric($region)) {
                $this->dao->where(DB_TABLE_PREFIX . 't_city.fk_i_region_id = ' . $region);
            }
            $this->dao->orderBy($order);

            $rs = $this->dao->get();

            if ($rs === false) {
                return array();
            }
            $return = $rs->result();
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
     * @return int total items
     */
    public function calculateNumItems($cityId)
    {
        $sql = 'SELECT count(*) as total FROM ' . DB_TABLE_PREFIX . 't_item_location, ' . DB_TABLE_PREFIX . 't_item, '
            . DB_TABLE_PREFIX . 't_category ';
        $sql .= 'WHERE ' . DB_TABLE_PREFIX . 't_item_location.fk_i_city_id = ' . (int)$cityId . ' AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.b_active = 1 AND ' . DB_TABLE_PREFIX . 't_item.b_enabled = 1 AND '
            . DB_TABLE_PREFIX . 't_item.b_spam = 0 AND ';
        $sql .= '(' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX . 't_item.dt_expiration >= \''
            . date('Y-m-d H:i:s') . '\' ) AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.b_enabled = 1 ';

        $return = $this->dao->query($sql);
        if ($return === false) {
            return 0;
        }

        if ($return->numRows() > 0) {
            $aux = $return->result();

            return $aux[0]['total'];
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
        
        $this->dao->select('fk_i_city_id, count(*) as i_num_items');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->join(DB_TABLE_PREFIX . 't_item', DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id');
        $this->dao->join(DB_TABLE_PREFIX . 't_category', DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_active = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_enabled = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_spam = 0');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX . 't_item.dt_expiration >= \''
            . date('Y-m-d H:i:s') . '\' ');
        $this->dao->where(DB_TABLE_PREFIX . 't_category.b_enabled = 1');
        $this->dao->where('fk_i_city_id IN (' . implode(',', array_map('intval', $cities)) . ')');
        $this->dao->groupBy('fk_i_city_id');
        $rs = $this->dao->get();
        if ($rs === false) {
            return array();
        }
        if ($rs->numRows() > 0) {
            $aux = $rs->result();
            foreach ($aux as $a) {
                $return[$a['fk_i_city_id']] = $a['i_num_items'];
            }
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
     * @return boolean| \DBRecordsetClass
     */
    public function updateAllStats(array $cities)
    {
        $newCalculated = $this->calculateAllStats($cities);

        if (empty($newCalculated)) {
            return false;
        }
        //INSERT or Update on duplicate key update use dao
        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_city_id, i_num_items) VALUES ';
        $values = array();
        foreach ($newCalculated as $id => $num) {
            $values[] = '(' . (int)$id . ', ' . (int)$num . ')';
        }
        $sql .= implode(',', $values);
        $sql .= ' ON DUPLICATE KEY UPDATE i_num_items = VALUES(i_num_items)';
        return $this->dao->query($sql);
    }
}

/* file end: ./oc-includes/osclass/model/CityStats.php */
