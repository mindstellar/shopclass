<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 * @package    Osclass
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
        $this->dao->select('a.pk_i_id as id, a.s_name as label, a.s_name as value, aux.s_name as region');
        $this->dao->from($this->getTableName() . ' as a');
        $this->dao->join(Region::newInstance()->getTableName() . ' as aux', 'aux.pk_i_id = a.fk_i_region_id', 'LEFT');
        $this->dao->like('a.s_name', $query, 'after');
        if ($regionId != null) {
            if (is_numeric($regionId)) {
                $this->dao->where('a.fk_i_region_id', $regionId);
            } else {
                $this->dao->where('aux.s_name', $regionId);
            }
        }

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
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
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_region_id', $regionId);
        $this->dao->orderBy('s_name', 'ASC');

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
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
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->where('s_name', $cityName);
        $this->dao->limit(1);
        if ($regionId != null) {
            $this->dao->where('fk_i_region_id', $regionId);
        }

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->row();
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
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->orderBy('s_name', 'ASC');
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_slug', $slug);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->row();
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_slug', '');
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }
}

/* file end: ./oc-includes/osclass/model/City.php */
