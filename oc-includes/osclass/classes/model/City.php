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
 * Model database for City table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class City extends DAO
{
    /**
     * It references to self object: City.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var City
     */
    private static $instance;

    /**
     * Set data related to t_city table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_city');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 'fk_i_region_id', 's_name', 'fk_c_country_code', 'b_active', 's_slug'));
    }

    /**
     * It creates a new City object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return City
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
     * Get the cities having part of the city name and region (it can be null)
     *
     * @access public
     *
     * @param string   $query    The beginning of the city name to look for
     * @param int|null $regionId Region id
     *
     * @return array If there's an error or 0 results, it returns an empty array
     * @since  unknown
     */
    public function ajax($query, $regionId = null)
    {
        // Table names are fixed internal identifiers set in each model's own
        // constructor, never user input, so they are safe to concatenate.
        $sql = 'SELECT a.pk_i_id AS id, a.s_name AS label, a.s_name AS value, aux.s_name AS region'
            . ' FROM ' . $this->getTableName() . ' AS a'
            . ' LEFT JOIN ' . Region::newInstance()->getTableName() . ' AS aux'
            . ' ON aux.pk_i_id = a.fk_i_region_id'
            . ' WHERE a.s_name LIKE ?';
        // Matches dao->like()'s escaping: '%'/'_' in the payload are escaped
        // before the wildcard is appended, so a literal '%' typed by a caller
        // stays literal instead of being read back as a SQL wildcard.
        $params = array(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $query) . '%');

        if ($regionId != null) {
            if (is_numeric($regionId)) {
                $sql .= ' AND a.fk_i_region_id = ?';
            } else {
                $sql .= ' AND aux.s_name = ?';
            }
            $params[] = $regionId;
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Get the cities from an specific region id. It's deprecated, use findByRegion
     *
     * @access     public
     *
     * @param int $regionId Region id
     *
     * @return array If there's an error or 0 results, it returns an empty array
     * @see        City::findByRegion
     * @since      unknown
     * @deprecated deprecated since 2.3
     */
    public function getByRegion($regionId)
    {
        return $this->findByRegion($regionId);
    }

    /**
     * Get the cities from an specific region id
     *
     * @access public
     *
     * @param int $regionId Region id
     *
     * @return array If there's an error or 0 results, it returns an empty array
     * @since  2.3
     */
    public function findByRegion($regionId)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('fk_i_region_id', $regionId)
                ->orderBy('s_name', 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Get the citiy by its name and region
     *
     * @access public
     *
     * @param     $cityName
     * @param int $regionId
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByName($cityName, $regionId = null)
    {
        $query = osc_db_table($this->getTableName())
            ->select(...$this->getFields())
            ->where('s_name', $cityName);

        if ($regionId != null) {
            $query = $query->where('fk_i_region_id', $regionId);
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
     * Get all the rows from the table t_city
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listAll()
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->orderBy('s_name', 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     *  Delete a city with its city areas
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
        $mCityAreas = CityArea::newInstance();
        $aCityAreas = $mCityAreas->findByCity($pk);
        $result     = 0;
        foreach ($aCityAreas as $cityarea) {
            $result += $mCityAreas->deleteByPrimaryKey($cityarea['pk_i_id']);
        }
        Item::newInstance()->deleteByCity($pk);
        CityStats::newInstance()->delete(array('fk_i_city_id' => $pk));
        User::newInstance()->update(array('fk_i_city_id' => null, 's_city' => ''), array('fk_i_city_id' => $pk));
        if (!$this->delete(array('pk_i_id' => $pk))) {
            $result++;
        }

        return $result;
    }

    /**
     * Find a location by its slug
     *
     * @access public
     *
     * @param $slug
     *
     * @return array
     * @since  3.2.1
     */
    public function findBySlug($slug)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('s_slug', $slug)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Find a locations with no slug
     *
     * @access public
     * @return array
     * @since  3.2.1
     */
    public function listByEmptySlug()
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('s_slug', '')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }
}

/* file end: ./oc-includes/osclass/model/City.php */
