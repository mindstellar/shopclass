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

define('DB_FUNC_NOW', 'NOW()');
define('DB_CONST_TRUE', 'TRUE');
define('DB_CONST_FALSE', 'FALSE');
define('DB_CONST_NULL', 'NULL');
define('DB_CUSTOM_COND', 'DB_CUSTOM_COND');

/**
 * DAO base model
 *
 * @package    Osclass
 * @subpackage Model
 * @since      2.3
 */
class DAO
{
    /**
     * DBCommandClass object
     *
     * @acces public
     * @since 2.3
     * @var DBCommandClass
     */
    public $dao;
    /**
     * Table name
     *
     * @access private
     * @since  unknown
     * @var string
     */
    public $tableName;
    /**
     * Table prefix
     *
     * @access private
     * @since  unknown
     * @var string
     */
    public $tablePrefix;
    /**
     * Primary key of the table
     *
     * @access private
     * @since  2.3
     * @var string
     */
    public $primaryKey;
    /**
     * Fields of the table
     *
     * @access private
     * @since  2.3
     * @var array
     */
    public $fields;

    /**
     * Init connection of the database and create DBCommandClass object
     */
    public function __construct()
    {
        $conn              = DBConnectionClass::newInstance();
        $data              = $conn->getOsclassDb();
        $this->dao         = new DBCommandClass($data);
        $this->tablePrefix = DB_TABLE_PREFIX;
    }

    /**
     * Reinitialize connection to the database once the object is unserialized
     */
    public function __wakeup()
    {
        $conn      = DBConnectionClass::newInstance();
        $data      = $conn->getOsclassDb();
        $this->dao = new DBCommandClass($data);
    }

    /**
     * Get the result match of the primary key passed by parameter
     *
     * @access public
     *
     * @param string $value
     *
     * @return mixed If the result has been found, it return the array row. If not, it returns false
     * @since  unknown
     */
    public function findByPrimaryKey($value)
    {
        $this->dao->select($this->fields);
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $value);
        $result = $this->dao->get();

        if ($result === false) {
            return false;
        }

        if ($result->numRows() !== 1) {
            return false;
        }

        return $result->row();
    }

    /**
     * Get the result matching of the primary key passed by parameter
     * Check if Item exists
     * @access public
     * @param int $value
     * @return bool If the result has been found, it return the array row. If not, it returns false
     */
    public function existsByPrimaryKey($value)
    {
        $this->dao->select($this->getPrimaryKey());
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $value);
        $result = $this->dao->get();

        if ($result === false) {
            return false;
        }

        if ($result->numRows() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Get table name
     *
     * @access public
     * @return string
     * @since  unknown
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * Set table name, adding the DB_TABLE_PREFIX at the beginning
     *
     * @access private
     *
     * @param string $table
     *
     * @since  unknown
     */
    public function setTableName($table)
    {
        $this->tableName = $this->tablePrefix . $table;
    }

    /**
     * Get primary key string
     *
     * @access public
     * @return string
     * @since  unknown
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * Set primary key string
     *
     * @access private
     *
     * @param string $key
     *
     * @since  unknown
     */
    public function setPrimaryKey($key)
    {
        $this->primaryKey = $key;
    }

    /**
     * Update row by primary key
     *
     * @access public
     *
     * @param array  $values Array with keys (database field) and values
     * @param string $key    Primary key to be updated
     *
     * @return mixed It return the number of affected rows if the update has been
     * correct or false if nothing has been modified
     * @since  unknown
     */
    public function updateByPrimaryKey($values, $key)
    {
        $cond = array(
            $this->getPrimaryKey() => $key
        );

        return $this->update($values, $cond);
    }

    /**
     * Basic update. It returns false if the keys from $values or $where doesn't
     * match with the fields defined in the construct
     *
     * @access public
     *
     * @param string|array $values Array with keys (database field) and values
     * @param array        $where
     *
     * @return mixed It returns the number of affected rows if the update has been
     * correct or false if an error happended
     * @since  unknown
     *
     */
    public function update($values, $where)
    {
        if (!is_array($values) || !is_array($where)) {
            return false;
        }
        if (!$this->checkFieldKeys(array_keys($values))) {
            return false;
        }

        if (!$this->checkFieldKeys(array_keys($where))) {
            return false;
        }

        $this->dao->from($this->getTableName());
        $this->dao->set($values);
        $this->dao->where($where);

        return $this->dao->update();
    }

    /**
     * Check if the keys of the array exist in the $fields array
     *
     * @access private
     *
     * @param array $aKey
     *
     * @return boolean
     * @since  2.3
     */
    public function checkFieldKeys($aKey)
    {
        foreach ($aKey as $key) {
            if (!in_array($key, $this->getFields())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get fields array
     *
     * @access public
     * @return array
     * @since  2.3
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Set fields array
     *
     * @access private
     *
     * @param array $fields
     *
     * @since  2.3
     */
    public function setFields($fields)
    {
        $this->fields = $fields;
    }

    /**
     * Delete the result match from the primary key passed by parameter
     *
     * @access public
     *
     * @param string $value
     *
     * @return mixed It return the number of affected rows if the delete has been
     * correct or false if nothing has been modified
     * @since  unknown
     */
    public function deleteByPrimaryKey($value)
    {
        $cond = array(
            $this->getPrimaryKey() => $value
        );

        return $this->delete($cond);
    }

    /**
     * Basic delete. It returns false if the keys from $where doesn't
     * match with the fields defined in the construct
     *
     * @access public
     *
     * @param array $where
     *
     * @return bool|int It returns the number of affected rows if the delete has been
     * correct or false if an error happended
     * @since  unknown
     */
    public function delete($where)
    {
        if (!$this->checkFieldKeys(array_keys($where))) {
            return false;
        }

        $this->dao->from($this->getTableName());
        $this->dao->where($where);

        return $this->dao->delete();
    }

    /**
     * Get all the rows from the table $tableName
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listAll()
    {
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Basic insert
     *
     * @access public
     *
     * @param array $values
     *
     * @return boolean
     * @since  unknown
     */
    public function insert($values)
    {
        if (!$this->checkFieldKeys(array_keys($values))) {
            return false;
        }

        $this->dao->from($this->getTableName());
        $this->dao->set($values);

        return $this->dao->insert();
    }

    /**
     * Get table prefix
     *
     * @access public
     * @return string
     * @since  2.3
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }

    /**
     * Returns the last error code for the most recent mysqli function call
     *
     * @access public
     * @return int
     * @since  2.3
     */
    public function getErrorLevel()
    {
        return $this->dao->getErrorLevel();
    }

    /**
     * Returns a string description of the last error for the most recent MySQLi function call
     *
     * @access public
     * @return string
     * @since  2.3
     */
    public function getErrorDesc()
    {
        return $this->dao->getErrorDesc();
    }

    /**
     * Returns the number of rows in the table represented by this object.
     *
     * @access public
     * @return int
     * @since  unknown
     */
    public function count()
    {
        $this->dao->select('COUNT(*) AS count');
        $this->dao->from($this->getTableName());
        $result = $this->dao->get();

        if ($result == false) {
            return 0;
        }

        if ($result->numRows() == 0) {
            return 0;
        }

        $row = $result->row();

        return $row['count'];
    }
}

/* file end: ./oc-includes/osclass/classes/database/DAO.php */
