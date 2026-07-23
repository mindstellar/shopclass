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
        try {
            $builder = osc_db_table($this->getTableName());
            // Legacy limit() gated the clause on is_numeric(): a non-numeric
            // $max dropped it entirely and returned every row, rather than
            // clamping to zero. Preserve that gate.
            if (is_numeric($max)) {
                $builder = $builder->limit((int)$max);
            }
            $rows = $builder->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Populate Cities from City Table
     * @return bool
     */
    public function populateCities()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_i_id column from t_city table into the
        // staging table. Both table names are model-owned (never runtime input)
        // and the one literal value, the e_type, is bound.
        $cityTableName = City::newInstance()->getTableName();
        $sql = 'INSERT IGNORE INTO ' . $this->getTableName()
            . ' (id_location, e_type) SELECT pk_i_id, ? FROM ' . $cityTableName;

        try {
            osc_db_execute($sql, array('CITY'));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * populate Regions from Region Table
     * @return bool
     */
    public function populateRegions()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_i_id column from t_region table into the
        // staging table. Both table names are model-owned; the e_type is bound.
        $regionTableName = Region::newInstance()->getTableName();
        $sql = 'INSERT IGNORE INTO ' . $this->getTableName()
            . ' (id_location, e_type) SELECT pk_i_id, ? FROM ' . $regionTableName;

        try {
            osc_db_execute($sql, array('REGION'));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * populate Countries from Country Table
     * @return bool
     */
    public function populateCountries()
    : bool
    {
        // INSERT IGNORE ...SELECT pk_c_code column from t_country table into the
        // staging table. Both table names are model-owned; the e_type is bound.
        $countryTableName = Country::newInstance()->getTableName();
        $sql = 'INSERT IGNORE INTO ' . $this->getTableName()
            . ' (id_location, e_type) SELECT pk_c_code, ? FROM ' . $countryTableName;

        try {
            osc_db_execute($sql, array('COUNTRY'));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }


    /**
     * @param array $where
     *
     * @return bool|int
     */
    public function delete($where)
    {
        // Legacy dao->delete() refuses to run without a where and returned false
        // for one; reproduce that guard before the builder (which also refuses).
        if (!is_array($where) || empty($where)) {
            return false;
        }

        try {
            $builder = osc_db_table($this->getTableName());
            // Each key is a caller-supplied column name; the builder's where()
            // validates it against its identifier allowlist, and each value is
            // bound. A numeric-looking id (a country code such as '0') therefore
            // compares as a string rather than coercing the VARCHAR column the
            // way legacy escape()'s unquoted-numeric passthrough did.
            foreach ($where as $column => $value) {
                $builder = $builder->where($column, $value);
            }

            return $builder->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
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
            // One (?, ?) tuple per id: the id keeps the (int) truncation legacy
            // applied, and the e_type is bound. The table name is model-owned.
            $tuples = array();
            $params = array();
            foreach ($ids as $id) {
                $tuples[] = '(?, ?)';
                $params[] = (int)$id;
                $params[] = $type;
            }

            $sql = 'INSERT INTO ' . $this->getTableName()
                . ' (id_location, e_type) VALUES ' . implode(',', $tuples);

            try {
                osc_db_execute($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }

            return true;
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
            // The !empty() guard keeps an empty id set off the IN () path, so
            // the whereIn empty-array divergence cannot arise. Each id keeps the
            // intval() legacy applied and is bound; the e_type is bound too, and
            // the table name is model-owned.
            $ids    = array_map('intval', $ids);
            $params = $ids;
            $params[] = $type;

            $sql = 'DELETE FROM ' . $this->getTableName()
                . ' WHERE id_location IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                . ' AND e_type = ?';

            try {
                osc_db_execute($sql, $params);
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }

            return true;
        }

        return false;
    }
}
