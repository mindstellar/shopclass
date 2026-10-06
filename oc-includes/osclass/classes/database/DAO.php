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
     * @see        \mindstellar\database\Connection
     * @see        \mindstellar\database\QueryBuilder
     * @var DBCommandClass
     */
    public $dao;
    /**
     * Table name
     *
     * @var string
     */
    public $tableName;
    /**
     * Table prefix
     *
     * @var string
     */
    public $tablePrefix;
    /**
     * Primary key of the table
     *
     * @since  2.3
     * @var string
     */
    public $primaryKey;
    /**
     * Fields of the table
     *
     * @since  2.3
     * @var array
     */
    public $fields;
    /**
     * The cache group a successful write drops, for a model whose reads are cached.
     *
     * @var string|null
     */
    protected $cacheGroup = null;

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
        $conn              = \mindstellar\database\ConnectionManager::getInstance();
        $data              = $conn->getHandle();
        $this->dao         = new DBCommandClass($data);
        $this->tablePrefix = DB_TABLE_PREFIX;
    }

    /**
     * Reinitialize connection to the database once the object is unserialized
     *
     * @return void
     */
    public function __wakeup()
    {
        $conn      = \mindstellar\database\ConnectionManager::getInstance();
        $data      = $conn->getHandle();
        $this->dao = new DBCommandClass($data);
    }

    /**
     * Get the result match of the primary key passed by parameter
     *
     * @param int|string $value
     *
     * @return array<string,string|null>|false The matching row, or false when there is not exactly one
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
     * @param array<string,mixed> $where Array with keys (database field) and values
     *
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
     * @return string
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * Set table name, adding the DB_TABLE_PREFIX at the beginning
     *
     * @param string $table
     *
     * @return void
     */
    public function setTableName($table)
    {
        $this->tableName = $this->tablePrefix . $table;
    }

    /**
     * Get primary key string
     *
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * Set primary key string
     *
     * @param string $key
     *
     * @return void
     */
    public function setPrimaryKey($key)
    {
        $this->primaryKey = $key;
    }

    /**
     * Update row by primary key
     *
     * @param array<string,mixed> $values Array with keys (database field) and values
     * @param int|string          $key    Primary key to be updated
     *
     * @return int|false It return the number of affected rows if the update has been
     * correct or false if nothing has been modified
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
     * @param array<string,mixed> $values Array with keys (database field) and values;
     *                                    anything else is refused with false
     * @param array<string,mixed> $where
     *
     * @return int|false It returns the number of affected rows if the update has been
     * correct or false if an error happended
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
     * @param array<int,string> $aKey
     *
     * @return bool
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
     * @return array<int,string>|null null until setFields() has run
     * @since  2.3
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Set fields array
     *
     * @param array<int,string> $fields
     *
     * @return void
     * @since  2.3
     */
    public function setFields($fields)
    {
        $this->fields = $fields;
    }

    /**
     * Delete the result match from the primary key passed by parameter
     *
     * @param int|string $value
     *
     * @return int|false It return the number of affected rows if the delete has been
     * correct or false if nothing has been modified
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
     * @param array<string,mixed> $where
     *
     * @return int|false It returns the number of affected rows if the delete has been
     * correct or false if an error happended
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
     * @return array<int,array<string,string|null>> Empty when the query fails
     */
    public function listAll()
    {
        $rows = $this->fetch('SELECT ' . $this->columnList() . ' FROM ' . $this->getTableName());

        return $rows === false ? array() : $rows;
    }

    /**
     * Basic insert
     *
     * @param array<string,mixed> $values
     *
     * @return bool
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
     * Insert a row and return its auto-increment id in one step.
     *
     * The id is captured from the insert statement itself (Connection::insertGetId, read
     * before the statement closes) rather than by a later, decoupled $this->dao->insertedId()
     * read of the connection-level mysqli->insert_id. That connection-level value is shared
     * and is reset to 0 by any other statement on the same handle — transaction control, a
     * form/widget save, a plugin query — so reading it after the fact intermittently returned
     * 0, whereupon callers wrote child rows with a 0 foreign key (FK failures) and fired hooks
     * with an empty item. Callers that need the new id must use this, not ->dao->insertedId().
     *
     * @param array<string,mixed> $values
     *
     * @return int the new row's id, or 0 when the insert wrote no row
     */
    public function insertGetId($values)
    {
        if (!$this->checkFieldKeys(array_keys($values)) || $values === array()) {
            return 0;
        }

        $columns      = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        try {
            $id = osc_db_insert_id(
                'INSERT INTO ' . $this->getTableName() . ' (' . implode(', ', $columns) . ')'
                . ' VALUES (' . $placeholders . ')',
                array_values($values)
            );
            $this->clearError();
            $this->cacheChanged();

            return (int) $id;
        } catch (\mindstellar\database\DbException $e) {
            $this->recordError($e);

            return 0;
        }
    }

    /**
     * Get table prefix
     *
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
     * @return int|string
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
     * The filtered and whole-table row counts for a paged list, as strings, or int 0.
     * Null when a count fails.
     *
     * @param string           $where  WHERE clause without the WHERE keyword, '' for none
     * @param array<int,mixed> $params bound values for $where
     *
     * @return array{0:int|string,1:int|string}|null
     */
    protected function pagedCounts(string $where, array $params = array()): ?array
    {
        try {
            $total = osc_db_count($this->getTableName(), $where, $params);
            $rows  = osc_db_count($this->getTableName());
        } catch (\mindstellar\database\DbException $e) {
            return null;
        }

        return array($total > 0 ? (string) $total : 0, $rows > 0 ? (string) $rows : 0);
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
     * @param array<string,mixed> $where
     *
     * @return array{0:string,1:array<int,mixed>} clause ('' when $where is empty) and its values
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
     * @param string           $sql
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,string|null>>|false
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
     * @param string           $sql
     * @param array<int,mixed> $params
     *
     * @return int|false
     */
    private function write($sql, array $params = array())
    {
        try {
            $affected = osc_db_execute($sql, $params);
            $this->clearError();
            $this->cacheChanged();
        } catch (\mindstellar\database\DbException $e) {
            $this->recordError($e);

            return false;
        }

        return $affected;
    }

    /**
     * Drop the model's cached reads after a write. Callers invoke this after the write, so a
     * read inside it cannot put the old row back.
     *
     * @return void
     */
    protected function cacheChanged()
    {
        if ($this->cacheGroup !== null) {
            \mindstellar\cache\CacheGroup::invalidate($this->cacheGroup);
        }
    }

    /**
     * The ", pk_i_id ASC|DESC" to append to an ORDER BY so ties break on the id and
     * pages of a sorted list never overlap. Empty for a random or an id-ordered list.
     *
     * @param string $orderColumn
     * @param string $direction
     *
     * @return string
     */
    protected function idTieBreak(string $orderColumn, string $direction): string
    {
        if (strtolower($direction) === 'random' || $orderColumn === 'pk_i_id') {
            return '';
        }

        return ', pk_i_id' . (strtoupper(trim($direction)) === 'DESC' ? ' DESC' : ' ASC');
    }

    /**
     * Reset this object's recorded error to "last operation succeeded".
     *
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

        $conn            = \mindstellar\database\ConnectionManager::getInstance()->getHandle();
        $this->errorDesc = ($conn instanceof mysqli && $conn->error !== '')
            ? $conn->error
            : $e->getMessage();
    }
}

/* file end: ./oc-includes/osclass/classes/database/DAO.php */
