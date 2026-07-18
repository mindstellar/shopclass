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
     *
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
     * Modify the structure of table.
     *
     * @access public
     * @since  unknown
     */
    public function toArray()
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 0) {
            return false;
        }

        $aTmpPref = $result->result();
        foreach ($aTmpPref as $tmpPref) {
            $this->pref[$tmpPref['s_section']][$tmpPref['s_name']] = $tmpPref['s_value'];
        }

        return true;
    }

    /**
     * @return \Preference
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Find a value by its name
     *
     * @access public
     *
     * @param $name
     *
     * @return bool
     * @since  unknown
     *
     */
    public function findValueByName($name)
    {
        $this->dao->select('s_value');
        $this->dao->from($this->getTableName());
        $this->dao->where('s_name', $name);
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 0) {
            return false;
        }

        $row = $result->row();

        return $row['s_value'];
    }

    /**
     * Find array preference for a given section
     *
     * @access public
     *
     * @param string $name
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findBySection($name)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_section', $name);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        if ($result->numRows() == 0) {
            return false;
        }

        return $result->result();
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @access public
     *
     * @param string $key
     * @param string $section
     *
     * @return string
     * @since  unknown
     */
    public function get($key, $section = 'osclass')
    {
        return $this->pref[$section][$key] ?? '';
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @access public
     *
     * @param string $section
     *
     * @return array
     * @since  unknown
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
     * @access public
     *
     * @param string $key
     * @param string $value
     * @param string $section
     *
     * @since  unknown
     */
    public function set($key, $value, $section = 'osclass')
    {
        $this->pref[$section][$key] = $value;
    }

    /**
     * Replace preference value, given preference name, preference section and value.
     *
     * @access public
     *
     * @param string $key
     * @param string $value
     * @param string $section
     * @param string $type
     *
     * @return boolean
     * @since  unknown
     */
    public function replace($key, $value, $section = 'osclass', $type = 'STRING')
    {
        static $aValidEnumTypes = array('STRING', 'INTEGER', 'BOOLEAN');
        $array_replace = array(
            's_name'    => $key,
            's_value'   => $value,
            's_section' => $section,
            'e_type'    => in_array($type, $aValidEnumTypes) ? $type : 'STRING'
        );

        return $this->dao->replace($this->getTableName(), $array_replace);
    }
}

/* file end: ./oc-includes/osclass/model/Preference.php */
