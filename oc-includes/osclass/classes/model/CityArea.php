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
 * Model database for CityArea table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class CityArea extends DAO
{
    /**
     * It references to self object: CityArea.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var CityArea
     */
    private static $instance;

    /**
     * Set data related to t_city_area table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_city_area');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 'fk_i_city_id', 's_name'));
    }

    /**
     * It creates a new CityArea object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return CityArea
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Get the cityArea by its name and city
     *
     * @access public
     *
     * @param     $cityAreaName
     * @param int $cityId
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByName($cityAreaName, $cityId = null)
    {
        $query = osc_db_table($this->getTableName())
            ->select(...$this->getFields())
            ->where('s_name', $cityAreaName);

        if ($cityId != null) {
            $query = $query->where('fk_i_city_id', $cityId);
        }

        try {
            $row = $query->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Return city areas of a given city ID
     *
     * @access public
     *
     * @param $cityId
     *
     * @return array
     * @since  2.4
     */
    public function findByCity($cityId)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('fk_i_city_id', $cityId)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     *  Delete a city area
     *
     * @access public
     *
     * @param $pk
     *
     * @return int number of failed deletions or 0 in case of none
     * @since  3.1
     *
     */
    public function deleteByPrimaryKey($pk)
    {
        Item::newInstance()->deleteByCityArea($pk);
        User::newInstance()->update(
            array('fk_i_city_area_id' => null, 's_city_area' => ''),
            array('fk_i_city_area_id' => $pk)
        );
        if (!$this->delete(array('pk_i_id' => $pk))) {
            return 1;
        }

        return 0;
    }
}

/* file end: ./oc-includes/osclass/model/CityArea.php */
