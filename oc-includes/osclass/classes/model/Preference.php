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
 * Class Preference
 */
class Preference extends DAO
{
    /**
     *
     * @var \Preference
     */
    private static $instance;
    /**
     * array for save preferences
     *
     * @var array
     */
    private $pref;

    /**
     * Set data related to t_preference table and load the cached preference map.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_preference');
        /* $this->set_primary_key($key); // no primary key in preference table */
        $this->setFields(array('s_section', 's_name', 's_value', 'e_type'));
        $this->toArray();
    }

    /**
     * Load every preference row into the in-memory section => name => value map.
     *
     * @return bool False when the table is empty or the query failed
     */
    public function toArray()
    {
        try {
            $rows = osc_db_table($this->getTableName())->get();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($rows === []) {
            return false;
        }

        // Merges into the existing map rather than replacing it, so a key
        // removed from the table since the last load stays cached until it is
        // overwritten by name.
        foreach (osc_db_stringify_rows($rows) as $row) {
            $this->pref[$row['s_section']][$row['s_name']] = $row['s_value'];
        }

        return true;
    }

    /**
     * Return the shared Preference model instance, creating it on first use.
     *
     * @return \Preference
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Find a value by its name
     *
     * @param string $name
     *
     * @return string|false False when the name is unknown or the query failed
     */
    public function findValueByName($name)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->select('s_value')
                ->where('s_name', $name)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row)['s_value'];
    }

    /**
     * Find array preference for a given section
     *
     * @param string $name
     *
     * @return array<int,array<string,string|null>>|false False when the section has no rows,
     *         an empty array when the query failed
     */
    public function findBySection($name)
    {
        if ($name === null) {
            // A null section builds a comparison with no right-hand side under
            // the legacy query layer, which fails outright and lands on the
            // array() branch below rather than the zero-row false branch a
            // valid, merely non-matching section reaches.
            return array();
        }

        try {
            $rows = osc_db_table($this->getTableName())
                ->where('s_section', $name)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($rows === []) {
            return false;
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @param string $key
     * @param string $section
     *
     * @return string
     */
    public function get($key, $section = 'osclass')
    {
        return $this->pref[$section][$key] ?? '';
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @param string $section
     *
     * @return array<string,string> Empty when the section is not loaded
     */
    public function getSection($section = 'osclass')
    {
        if (isset($this->pref[$section]) && is_array($this->pref[$section])) {
            return $this->pref[$section];
        }

        return array();
    }

    /**
     * Set preference value, given a preference name and a section name.
     *
     * @param string $key
     * @param string $value
     * @param string $section
     *
     * @return void
     */
    public function set($key, $value, $section = 'osclass')
    {
        $this->pref[$section][$key] = $value;
    }

    /**
     * Replace preference value, given preference name, preference section and value.
     *
     * @param string $key
     * @param string $value
     * @param string $section
     * @param string $type
     *
     * @return boolean
     */
    public function replace($key, $value, $section = 'osclass', $type = 'STRING')
    {
        static $aValidEnumTypes = array('STRING', 'INTEGER', 'BOOLEAN');
        $e_type = in_array($type, $aValidEnumTypes) ? $type : 'STRING';

        try {
            // No QueryBuilder equivalent for REPLACE INTO; the unique key on
            // (s_section, s_name) is what makes this an upsert.
            osc_db_execute(
                'REPLACE INTO ' . $this->getTableName() . ' (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)',
                array($key, $value, $section, $e_type)
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        // The map this class answers reads from is loaded once per request, so without
        // this a value written here stays invisible to the code that wrote it until the
        // next request. That reads as a stale preference rather than a missing write: the
        // location catalog refreshes to a new release, records where that release lives,
        // then builds its download URLs from the previous one and fails every checksum.
        $this->set($key, $value, $section);

        return true;
    }
}

/* file end: ./oc-includes/osclass/model/Preference.php */
