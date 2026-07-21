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
 * Model database for LocationsTmp table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class LocationsTmp extends DAO
{
    /**
     * It references to self object: LocationsTmp.
     * It is used as a singleton
     *
     * @access private
     * @since  2.4
     * @var CountryStats
     */
    private static $instance;

    /**
     * Set data related to t_locations_tmp table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_locations_tmp');
        $this->setFields(array('id_location', 'e_type'));
    }

    /**
     * It creates a new LocationsTmp object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return LocationsTmp
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
     * @param $max
     *
     * @return array
     */
    public function getLocations($max)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->limit($max);
        $rs = $this->dao->get();

        if ($rs === false) {
            return array();
        }

        return $rs->result();
    }

    /**
     * Populate Cities from City Table
     * @return bool
     */
    public function populateCities()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_i_id column from t_city table to t_locations_tmp table
        $cityTableName = City::newInstance()->getTableName();
        $rs = $this->dao->query(sprintf("INSERT IGNORE INTO %s (id_location, e_type) SELECT pk_i_id, 'CITY' FROM %s", $this->getTableName(), $cityTableName));

        return !($rs === false);
    }

    /**
     * populate Regions from Region Table
     * @return bool
     */
    public function populateRegions()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_i_id column from t_region table to t_locations_tmp table
        $regionTableName = Region::newInstance()->getTableName();
        $rs = $this->dao->query(sprintf("INSERT IGNORE INTO %s (id_location, e_type) SELECT pk_i_id, 'REGION' FROM %s", $this->getTableName(), $regionTableName));

        return !($rs === false);
    }

    /**
     * populate Countries from Country Table
     * @return bool
     */
    public function populateCountries()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_c_code column from t_country table to t_locations_tmp table
        $countryTableName = Country::newInstance()->getTableName();
        $rs = $this->dao->query(sprintf("INSERT IGNORE INTO %s (id_location, e_type) SELECT pk_c_code, 'COUNTRY' FROM %s", $this->getTableName(), $countryTableName));

        return !($rs === false);
    }


    /**
     * @param array $where
     *
     * @return bool|int
     */
    public function delete($where)
    {
        return $this->dao->delete($this->getTableName(), $where);
    }

    /**
     * @param $ids
     * @param $type
     *
     * @return bool|\DBRecordsetClass
     */
    public function batchInsert($ids, $type)
    {
        if (!empty($ids)) {
            $type   = $this->dao->escape($type);
            $values = array();
            foreach ($ids as $id) {
                $values[] = '(' . (int)$id . ', ' . $type . ')';
            }

            return $this->dao->query(sprintf(
                'INSERT INTO %s (id_location, e_type) VALUES %s',
                $this->getTableName(),
                implode(',', $values)
            ));
        }

        return false;
    }

    /**
     * Batch Delete Locations
     * @param $ids
     * @param $type
     * @return bool|\DBRecordsetClass
     */
    public function batchDelete($ids, $type)
    {
        if (!empty($ids)) {
            return $this->dao->query(sprintf(
                'DELETE FROM %s WHERE id_location IN (%s) AND e_type = %s',
                $this->getTableName(),
                implode(',', array_map('intval', $ids)),
                $this->dao->escape($type)
            ));
        }

        return false;
    }
}
