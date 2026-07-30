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

define('DB_FUNC_NOW', 'NOW()');
define('DB_CONST_TRUE', 'TRUE');
define('DB_CONST_FALSE', 'FALSE');
define('DB_CONST_NULL', 'NULL');
define('DB_CUSTOM_COND', 'DB_CUSTOM_COND');

/**
 * DAO base model
 *
 * The CRUD primitives below run on the parameterized query layer: column names
 * come from the model's own field list and every value is bound. Row shapes are
 * unchanged -- values are returned as strings, as the driver used to hand them
 * back, because callers compare and print them loosely.
 *
 * $this->dao is still constructed and still public. It is no longer used by the
 * methods here, but plugins reach models' query builder through it, so it stays
 * a supported way in. New query code should use mindstellar\database\Connection
 * or QueryBuilder rather than either.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.3
 */
class DAO
{
    /**
     * DBCommandClass object
     *
     * The legacy stateful query builder. Nothing in core routes through it any
     * more; it is kept because plugins reach a model's builder as $model->dao,
     * and two of the bundled ones do.
     *
     * @acces      public
     * @since      2.3
     * @deprecated 5.3 Build queries with mindstellar\database\Connection or
     *             QueryBuilder instead. Still populated and still supported --
     *             the models themselves stopped using it in 5.3, so this is now
     *             purely a compatibility surface for plugins.
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
     * Driver error number from this object's most recent operation, 0 when it
     * succeeded. Tracked here rather than read back off $this->dao, which no
     * longer sees these queries.
     *
     * @var int
     */
    private $errorLevel = 0;

    /**
     * Driver error text matching {@see $errorLevel}, '' when the last operation
     * succeeded.
     *
     * @var string
     */
    private $errorDesc = '';

    /**
     * Init connection of the database and create DBCommandClass object
     */
    public function __construct()
    {
        $conn              = \mindstellar\database\ConnectionManager::newInstance();
        $data              = $conn->getHandle();
        $this->dao         = new DBCommandClass($data);
        $this->tablePrefix = DB_TABLE_PREFIX;
    }

    /**
     * Reinitialize connection to the database once the object is unserialized
     */
    public function __wakeup()
    {
        $conn      = \mindstellar\database\ConnectionManager::newInstance();
        $data      = $conn->getHandle();
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
        $rows = $this->fetch(
            'SELECT ' . $this->columnList() . ' FROM ' . $this->getTableName()
            . ' WHERE ' . $this->getPrimaryKey() . ' = ?',
            array($value)
        );

        if ($rows === false || count($rows) !== 1) {
            return false;
        }

        return $rows[0];
    }

    /**
     * Check whether a row with the given primary key value exists
     *
     * @access public
     * @param int|string $value
     * @return bool True if a matching row exists, false otherwise
     *
     * @since  5.3 return value corrected; previously this returned the logical
     *         inverse (false when the row existed, true when it did not).
     */
    public function existsByPrimaryKey($value)
    {
        $rows = $this->fetch(
            'SELECT ' . $this->getPrimaryKey() . ' FROM ' . $this->getTableName()
            . ' WHERE ' . $this->getPrimaryKey() . ' = ?',
            array($value)
        );

        return $rows !== false && count($rows) > 0;
    }

    /**
     * Check whether at least one row matches the given conditions
     *
     * @access public
     * @param array $where Array with keys (database field) and values
     * @return bool True if a matching row exists, false otherwise
     */
    public function exists(array $where): bool
    {
        if (!$this->checkFieldKeys(array_keys($where))) {
            return false;
        }

        list($clause, $params) = $this->buildWhere($where);

        $sql = 'SELECT ' . $this->getPrimaryKey() . ' FROM ' . $this->getTableName();
        if ($clause !== '') {
            $sql .= ' WHERE ' . $clause;
        }

        $rows = $this->fetch($sql, $params);

        return $rows !== false && count($rows) > 0;
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
     * An empty $where updates every row, which is what this has always done --
     * only delete() refuses an unbounded write.
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

        if ($values === array()) {
            return false;
        }

        $assignments = array();
        $params      = array();
        foreach ($values as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[]      = $value;
        }

        list($clause, $whereParams) = $this->buildWhere($where);

        $sql = 'UPDATE ' . $this->getTableName() . ' SET ' . implode(', ', $assignments);
        if ($clause !== '') {
            $sql .= ' WHERE ' . $clause;
        }

        return $this->write($sql, array_merge($params, $whereParams));
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
     * An empty $where is refused rather than deleting every row.
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

        list($clause, $params) = $this->buildWhere($where);

        if ($clause === '') {
            return false;
        }

        return $this->write('DELETE FROM ' . $this->getTableName() . ' WHERE ' . $clause, $params);
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
        $rows = $this->fetch('SELECT ' . $this->columnList() . ' FROM ' . $this->getTableName());

        return $rows === false ? array() : $rows;
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

        if ($values === array()) {
            return false;
        }

        $columns      = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $affected = $this->write(
            'INSERT INTO ' . $this->getTableName() . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . $placeholders . ')',
            array_values($values)
        );

        return $affected !== false;
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
        return $this->errorLevel;
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
        return $this->errorDesc;
    }

    /**
     * Returns the number of rows in the table represented by this object.
     *
     * The count comes back as a string, the shape the driver has always
     * returned; a failed query yields int 0.
     *
     * @access public
     * @return int|string
     * @since  unknown
     */
    public function count()
    {
        try {
            $total = osc_db_scalar('SELECT COUNT(*) FROM ' . $this->getTableName());
            $this->clearError();
        } catch (\mindstellar\database\DbException $e) {
            $this->recordError($e);

            return 0;
        }

        return (string)$total;
    }

    /**
     * The model's field list as a SELECT column list, or '*' when it has none.
     *
     * @return string
     */
    private function columnList()
    {
        $fields = $this->getFields();
        if (!is_array($fields) || $fields === array()) {
            return '*';
        }

        return implode(', ', array_map('trim', $fields));
    }

    /**
     * Turn a field => value map into a bound WHERE clause.
     *
     * Keys have already been checked against the model's field list by the
     * caller, so they are the model's own column names rather than input.
     *
     * @param array $where
     *
     * @return array{0:string,1:array} clause ('' when $where is empty) and its values
     */
    private function buildWhere(array $where)
    {
        $parts  = array();
        $params = array();
        foreach ($where as $column => $value) {
            $parts[]  = $column . ' = ?';
            $params[] = $value;
        }

        return array(implode(' AND ', $parts), $params);
    }

    /**
     * Run a SELECT, returning string-valued rows or false on failure.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return array|false
     */
    private function fetch($sql, array $params = array())
    {
        try {
            $rows = osc_db_select($sql, $params);
            $this->clearError();
        } catch (\mindstellar\database\DbException $e) {
            $this->recordError($e);

            return false;
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Run a write, returning the affected-row count or false on failure.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return int|false
     */
    private function write($sql, array $params = array())
    {
        try {
            $affected = osc_db_execute($sql, $params);
            $this->clearError();
        } catch (\mindstellar\database\DbException $e) {
            $this->recordError($e);

            return false;
        }

        return $affected;
    }

    /**
     * @return void
     */
    private function clearError()
    {
        $this->errorLevel = 0;
        $this->errorDesc  = '';
    }

    /**
     * Capture the driver's view of a failure. The exception carries the error
     * number; the text is read off the connection, which still holds it at this
     * point and describes the failure far better than the generic message.
     *
     * @param \mindstellar\database\DbException $e
     *
     * @return void
     */
    private function recordError(\mindstellar\database\DbException $e)
    {
        $this->errorLevel = (int)$e->getCode();

        $conn            = \mindstellar\database\ConnectionManager::newInstance()->getHandle();
        $this->errorDesc = ($conn instanceof mysqli && $conn->error !== '')
            ? $conn->error
            : $e->getMessage();
    }
}

/* file end: ./oc-includes/osclass/classes/database/DAO.php */
