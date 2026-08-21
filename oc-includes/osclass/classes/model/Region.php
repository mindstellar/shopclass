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
 * Model database for Region table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Region extends DAO
{
    /**
     *
     * @var \Region
     */
    private static $instance;

    /**
     * Region constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_region');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 'fk_c_country_code', 's_name', 'b_active', 's_slug'));
    }

    /**
     * @return \Region
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Gets all regions from a country
     *
     * @access     public
     *
     * @param $countryId
     *
     * @return array
     * @see        Region::findByCountry
     * @since      unknown
     * @deprecated since 2.3
     */
    public function getByCountry($countryId)
    {
        return $this->findByCountry($countryId);
    }

    /**
     * Gets all regions from a country
     *
     * @access public
     *
     * @param $countryId
     *
     * @return array
     * @since  unknown
     */
    public function findByCountry($countryId)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('fk_c_country_code', $countryId)
                ->orderBy('s_name', 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Find a region by its name and country
     *
     * @access public
     *
     * @param string $name
     * @param string $country
     *
     * @return array
     * @since  unknown
     */
    public function findByName($name, $country = null)
    {
        $query = osc_db_table($this->getTableName())->where('s_name', $name);
        if ($country != null) {
            $query = $query->where('fk_c_country_code', $country);
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
     * Function to deal with ajax queries
     *
     * @access public
     *
     * @param      $query
     * @param null $country
     *
     * @return array
     * @since  unknown
     *
     */
    public function ajax($query, $country = null)
    {
        $country = trim($country);
        // Reproduces DBCommandClass::like()'s own escaping (escapeStr($v, true)):
        // a caller-typed '%' or '_' must stay literal, never a SQL wildcard.
        $pattern = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$query) . '%';

        $sql    = 'SELECT a.pk_i_id as id, a.s_name as label, a.s_name as value FROM '
            . $this->getTableName() . ' as a';
        $params = array();

        if ($country != null) {
            if (strlen($country) == 2) {
                $sql .= ' WHERE a.fk_c_country_code = ? AND a.s_name LIKE ?';
                $params[] = strtolower($country);
                $params[] = $pattern;
            } else {
                // Country::getTableName() is a fixed configuration value (the
                // table prefix constant), never caller input.
                $sql .= ' LEFT JOIN ' . Country::newInstance()->getTableName() . ' as aux'
                    . ' ON aux.pk_c_code = a.fk_c_country_code'
                    . ' WHERE aux.s_name = ? AND a.s_name LIKE ?';
                $params[] = $country;
                $params[] = $pattern;
            }
        } else {
            $sql .= ' WHERE a.s_name LIKE ?';
            $params[] = $pattern;
        }

        $sql .= ' LIMIT 5';

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     *  Delete a region with its cities and city areas
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
        osc_run_hook('before_delete_region', $pk);

        $mCities = City::newInstance();
        $aCities = $mCities->findByRegion($pk);
        $result  = 0;
        foreach ($aCities as $city) {
            $result += $mCities->deleteByPrimaryKey($city['pk_i_id']);
        }
        Item::newInstance()->deleteByRegion($pk);
        RegionStats::newInstance()->delete(array('fk_i_region_id' => $pk));
        User::newInstance()->update(array('fk_i_region_id' => null, 's_region' => ''), array('fk_i_region_id' => $pk));

        // Recorded renames for this region. The table has no foreign key -- fk_i_id
        // points into either t_region or t_city depending on e_type -- so nothing
        // removes these rows on its own, and a leftover slug would keep redirecting
        // to a region that no longer exists.
        try {
            osc_db_table(DB_TABLE_PREFIX . 't_location_slug_history')
                ->where('e_type', 'REGION')
                ->where('fk_i_id', (int)$pk)
                ->delete();
        } catch (\Throwable $e) {
            // A stale redirect is not worth failing the delete over.
        }
        // Count the own-row delete as a failure only when the query itself
        // errors (DAO::delete() returns false), not when it validly matches no
        // rows (returns 0). Deleting a primary key that does not exist is not a
        // failure -- there was simply nothing to remove.
        if ($this->delete(array('pk_i_id' => $pk)) === false) {
            $result++;
        }

        if ($result === 0) {
            osc_run_hook('after_delete_region', $pk);
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
            $row = osc_db_table($this->getTableName())->where('s_slug', $slug)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Find a region by its upstream source id
     *
     * i_source_id is the identifier the upstream location dataset uses for this row, and
     * it is the only stable way to match a region across dataset updates, because names
     * and slugs are renamed upstream constantly. It is unique table-wide, not scoped to
     * a country.
     *
     * @access public
     *
     * @param $sourceId
     *
     * @return array
     * @since  6.2.0
     */
    public function findBySourceId($sourceId)
    {
        try {
            $row = osc_db_table($this->getTableName())->where('i_source_id', $sourceId)->first();
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
            $rows = osc_db_table($this->getTableName())->where('s_slug', '')->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }
}

/* file end: ./oc-includes/osclass/model/Region.php */
