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

use mindstellar\database\Db;

/**
 * Model database for Country table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Country extends DAO
{
    /**
     *
     * @var Country
     */
    private static $instance;

    /**
     * Set data related to t_country table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_country');
        $this->setPrimaryKey('pk_c_code');
        $this->setFields(array('pk_c_code', 's_name', 's_slug'));
    }

    /**
     * Return the shared Country model instance, creating it on first use.
     *
     * @return \Country
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Find a country by its ISO code
     *
     * @param string $code
     *
     * @return array<string,string|null> Empty when the code is unknown
     */
    public function findByCode($code)
    {
        try {
            $row = Db::table($this->getTableName())
                ->where('pk_c_code', $code)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $row === null ? array() : Db::stringifyRow($row);
    }

    /**
     * Find a country by its name
     *
     * @param string $name
     *
     * @return array<string,string|null> Empty when the name is unknown
     */
    public function findByName($name)
    {
        try {
            $row = Db::table($this->getTableName())
                ->where('s_name', $name)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $row === null ? array() : Db::stringifyRow($row);
    }

    /**
     * List all the countries
     *
     * @return array<int,array<string,string|null>> Empty when the query failed
     */
    public function listAll()
    {
        try {
            // The table name comes from getTableName(), fixed in the constructor
            // — never runtime input — so the query needs no placeholder for it.
            $rows = Db::select(sprintf('SELECT * FROM %s ORDER BY s_name ASC', $this->getTableName()));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }

    /**
     *  Delete a country with its regions, cities,..
     *
     * @param string $pk Country code
     *
     * @return int number of failed deletions or 0 in case of none
     * @since  2.4
     */
    public function deleteByPrimaryKey($pk)
    {
        osc_run_hook('before_delete_country', $pk);

        $mRegions = Region::getInstance();
        $aRegions = $mRegions->findByCountry($pk);
        $result   = 0;
        foreach ($aRegions as $region) {
            $result += $mRegions->deleteByPrimaryKey((int) $region['pk_i_id']);
        }
        Item::getInstance()->deleteByCountry($pk);
        CountryStats::getInstance()->delete(array('fk_c_country_code' => $pk));
        User::getInstance()->update(
            array('fk_c_country_code' => null, 's_country' => ''),
            array('fk_c_country_code' => $pk)
        );
        if (!$this->delete(array('pk_c_code' => $pk))) {
            $result++;
        }

        // Regions and cities record their own slug history and clear it as they go,
        // so a country only has to account for what the recursion above left behind.
        if ($result === 0) {
            osc_run_hook('after_delete_country', $pk);
        }

        return $result;
    }

    /**
     * List names of all the countries. Used for location import.
     *
     * @return array<int,string>
     */
    public function listNames()
    {
        try {
            // The table name comes from getTableName(), fixed in the constructor
            // — never runtime input — so the query needs no placeholder for it.
            $rows = Db::select(sprintf('SELECT s_name FROM %s ORDER BY s_name ASC', $this->getTableName()));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return array_column(Db::stringifyRows($rows), 's_name');
    }

    /**
     * Function that work with the ajax file
     *
     * @param string $query Prefix typed into the autocomplete
     *
     * @return array<int,array{id:string,label:string,value:string}>
     */
    public function ajax($query)
    {
        // Column aliases are outside the query builder's identifier allowlist,
        // so this stays hand-written SQL. dao->like() routed the payload
        // through escapeStr($v, true), which escapes LIKE metacharacters
        // before adding the wildcard, so a literal '%'/'_' typed by a caller
        // is preserved here the same way.
        $pattern = \mindstellar\database\QueryBuilder::escapeLike((string) $query) . '%';

        try {
            // The table name comes from getTableName(), fixed in the constructor
            // — never runtime input — so the query needs no placeholder for it.
            $rows = Db::select(
                sprintf(
                    'SELECT pk_c_code as id, s_name as label, s_name as value FROM %s WHERE s_name LIKE ? LIMIT 5',
                    $this->getTableName()
                ),
                array($pattern)
            );
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }

    /**
     * Find a location by its slug
     *
     * @param string $slug
     *
     * @return array<string,string|null> Empty when the slug is unknown
     * @since  3.2.1
     */
    public function findBySlug($slug)
    {
        try {
            $row = Db::table($this->getTableName())
                ->where('s_slug', $slug)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $row === null ? array() : Db::stringifyRow($row);
    }

    /**
     * Find a locations with no slug
     *
     * @return array<int,array<string,string|null>>
     * @since  3.2.1
     */
    public function listByEmptySlug()
    {
        try {
            $rows = Db::table($this->getTableName())
                ->where('s_slug', '')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }
}

/* file end: ./oc-includes/osclass/model/Country.php */
