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
 * Model database for Country table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Country extends DAO
{
    /**
     *
     * @var Country
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_country');
        $this->setPrimaryKey('pk_c_code');
        $this->setFields(array('pk_c_code', 's_name', 's_slug'));
    }

    /**
     * @return \Country
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Find a country by its ISO code
     *
     * @access public
     *
     * @param $code
     *
     * @return array
     * @since  unknown
     */
    public function findByCode($code)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('pk_c_code', $code);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->row();
    }

    /**
     * Find a country by its name
     *
     * @access public
     *
     * @param $name
     *
     * @return array
     * @since  unknown
     */
    public function findByName($name)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_name', $name);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->row();
    }

    /**
     * List all the countries
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listAll()
    {
        $result = $this->dao->query(sprintf('SELECT * FROM %s ORDER BY s_name ASC', $this->getTableName()));
        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     *  Delete a country with its regions, cities,..
     *
     * @access public
     *
     * @param $pk
     *
     * @return int number of failed deletions or 0 in case of none
     * @since  2.4
     *
     */
    public function deleteByPrimaryKey($pk)
    {
        $mRegions = Region::newInstance();
        $aRegions = $mRegions->findByCountry($pk);
        $result   = 0;
        foreach ($aRegions as $region) {
            $result += $mRegions->deleteByPrimaryKey($region['pk_i_id']);
        }
        Item::newInstance()->deleteByCountry($pk);
        CountryStats::newInstance()->delete(array('fk_c_country_code' => $pk));
        User::newInstance()->update(
            array('fk_c_country_code' => null, 's_country' => ''),
            array('fk_c_country_code' => $pk)
        );
        if (!$this->delete(array('pk_c_code' => $pk))) {
            $result++;
        }

        return $result;
    }

    /**
     * List names of all the countries. Used for location import.
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listNames()
    {
        $result = $this->dao->query(sprintf('SELECT s_name FROM %s ORDER BY s_name ASC', $this->getTableName()));
        if ($result == false) {
            return array();
        }

        return array_column($result->result(), 's_name');
    }

    /**
     * Function that work with the ajax file
     *
     * @access public
     *
     * @param $query
     *
     * @return array
     * @since  unknown
     */
    public function ajax($query)
    {
        $this->dao->select('pk_c_code as id, s_name as label, s_name as value');
        $this->dao->from($this->getTableName());
        $this->dao->like('s_name', $query, 'after');
        $this->dao->limit(5);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->result();
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

/* file end: ./oc-includes/osclass/model/Country.php */
