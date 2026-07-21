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
 * Model database for RegionStats table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class RegionStats extends DAO
{
    /**
     * It references to self object: RegionStats.
     * It is used as a singleton
     *
     * @access private
     * @since  2.4
     * @var RegionStats
     */
    private static $instance;

    /**
     * Set data related to t_region_stats table
     *
     * @access public
     * @since  2.4
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_region_stats');
        $this->setPrimaryKey('fk_i_region_id');
        $this->setFields(array('fk_i_region_id', 'i_num_items'));
    }

    /**
     * It creates a new RegionStats object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return RegionStats
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
     * Increase number of region items, given a region id
     *
     * @access public
     *
     * @param int $regionId Region id
     *
     * @return bool|\DBRecordsetClass number of affected rows, id error occurred return false
     * @since  2.4
     */
    public function increaseNumItems($regionId)
    {
        if (!is_numeric($regionId)) {
            return false;
        }
        $sql =
            sprintf(
                'INSERT INTO %s (fk_i_region_id, i_num_items) VALUES (%d, 1) ON DUPLICATE KEY UPDATE i_num_items = i_num_items + 1',
                $this->getTableName(),
                $regionId
            );

        return $this->dao->query($sql);
    }

    /**
     * Decrease number of region items, given a region id
     *
     * @access public
     *
     * @param int $regionId Region id
     *
     * @return int number of affected rows, id error occurred return false
     * @since  2.4
     */
    public function decreaseNumItems($regionId)
    {
        if (!is_numeric($regionId)) {
            return false;
        }
        $this->dao->select('i_num_items');
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $regionId);
        $result     = $this->dao->get();
        if ($result instanceof DBRecordsetClass) {
            $regionStat = $result->row();
        }

        if (isset($regionStat['i_num_items'])) {
            $this->dao->from($this->getTableName());
            $this->dao->set('i_num_items', 'i_num_items - 1', false);
            $this->dao->where('i_num_items > 0');
            $this->dao->where('fk_i_region_id', $regionId);

            return $this->dao->update();
        }

        return false;
    }

    /**
     * Set i_num_items, given a region id
     *
     * @access public
     *
     * @param int $regionID
     * @param int $numItems
     *
     * @return bool|\DBRecordsetClass
     * @since  2.4
     *
     */
    public function setNumItems($regionID, $numItems)
    {
        $regionID = (int)$regionID;
        $numItems = (int)$numItems;
        $sql = 'INSERT INTO ' . $this->getTableName()
            . " (fk_i_region_id, i_num_items) VALUES ($regionID, $numItems) ON DUPLICATE KEY UPDATE i_num_items = "
            . $numItems;

        return $this->dao->query($sql);
    }

    /**
     * Find stats by region id
     *
     * @access public
     *
     * @param int $regionId region id
     *
     * @return array
     * @since  2.4
     */

    public function findByRegionId($regionId)
    {
        return $this->findByPrimaryKey($regionId);
    }

    /**
     * Return a list of regions and counter items.
     * Can be filtered by country and num_items,
     * and ordered by region_name or items counter.
     * $order = 'region_name ASC' OR $oder = 'items DESC'
     *
     * @access public
     *
     * @param string $country
     * @param string $zero
     * @param string $order
     *
     * @return array
     * @since  2.4
     *
     */
    public function listRegions($country = '%%%%', $zero = '>', $order = 'region_name ASC')
    {
        $key   = md5(osc_base_url() . (string)$country . (string)$zero . (string)$order);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
                $zero = '>';
            }
            if (!preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', (string)$order)) {
                $order = 'region_name ASC';
            }
            $order_split = explode(' ', $order);

            $this->dao->from(DB_TABLE_PREFIX . 't_region , ' . $this->getTableName());
            $this->dao->where($this->getTableName() . '.fk_i_region_id = ' . DB_TABLE_PREFIX . 't_region.pk_i_id');

            if ($order_split[0] === 'region_name') {
                $this->dao->select('STRAIGHT_JOIN ' . $this->getTableName() . '.fk_i_region_id as region_id, '
                    . $this->getTableName() . '.i_num_items as items, ' . DB_TABLE_PREFIX
                    . 't_region.s_name as region_name, ' . DB_TABLE_PREFIX . 't_region.s_slug as region_slug');
            } elseif ($order_split[0] === 'items') {
                $this->dao->select($this->getTableName() . '.fk_i_region_id as region_id, ' . $this->getTableName()
                    . '.i_num_items as items, ' . DB_TABLE_PREFIX . 't_region.s_name as region_name');
            }

            $this->dao->where('i_num_items ' . $zero . ' 0');
            if ($country !== '%%%%') {
                $this->dao->where(DB_TABLE_PREFIX . 't_region.fk_c_country_code = '
                    . $this->dao->escape($country));
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
     * Calculate the total items that belong to region
     *
     * @param $regionId
     *
     * @return int total items
     */
    public function calculateNumItems($regionId)
    {
        $sql = 'SELECT count(*) as total FROM ' . DB_TABLE_PREFIX . 't_item_location, ' . DB_TABLE_PREFIX . 't_item, '
            . DB_TABLE_PREFIX . 't_category ';
        $sql .= 'WHERE ' . DB_TABLE_PREFIX . 't_item_location.fk_i_region_id = ' . (int)$regionId . ' AND ';
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
     * Batch calculate the total items that belong to region id
     *
     * @param array $regions array of region ids
     *
     * @return array
     */
    private function calculateAllStats(array $regions)
    : array
    {
        if (empty($regions)) {
            return array();
        }
        $return = array();
        
        $this->dao->select('fk_i_region_id, count(*) as i_num_items');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->join(DB_TABLE_PREFIX . 't_item', DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id');
        $this->dao->join(DB_TABLE_PREFIX . 't_category', DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_active = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_enabled = 1');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_spam = 0');
        $this->dao->where(DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX . 't_item.dt_expiration >= \''
            . date('Y-m-d H:i:s') . '\' ');
        $this->dao->where(DB_TABLE_PREFIX . 't_category.b_enabled = 1');
        $this->dao->where('fk_i_region_id IN (' . implode(',', array_map('intval', $regions)) . ')');
        $this->dao->groupBy('fk_i_region_id');
        $rs = $this->dao->get();
        if ($rs === false) {
            return array();
        }
        if ($rs->numRows() > 0) {
            $aux = $rs->result();
            foreach ($aux as $a) {
                $return[$a['fk_i_region_id']] = $a['i_num_items'];
            }
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
     * @param array $regions array of region ids
     *
     * @return boolean| \DBRecordsetClass
     */
    public function updateAllStats(array $regions)
    {
        $newCalculated = $this->calculateAllStats($regions);

        if (empty($newCalculated)) {
            return false;
        }
        //INSERT or Update on duplicate key update use dao
        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_region_id, i_num_items) VALUES ';
        $values = array();
        foreach ($newCalculated as $id => $num) {
            $values[] = '(' . (int)$id . ', ' . (int)$num . ')';
        }
        $sql .= implode(',', $values);
        $sql .= ' ON DUPLICATE KEY UPDATE i_num_items = VALUES(i_num_items)';
        return $this->dao->query($sql);
    }
}

/* file end: ./oc-includes/osclass/model/RegionStats.php */
