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
 * Database command object
 *
 * A stateful, string-concatenation query builder (a CodeIgniter-2-era Active
 * Record fork). Two footguns to know: its clause state is shared per instance
 * and only cleared by _resetSelect()/_resetWrite(), so unreset state can leak
 * between queries; and limit()/offset() compile to the inverted
 * `LIMIT <offset>, <count>` form.
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      2.3
 * @deprecated 5.3 For new code prefer the parameterized, injection-safe
 *             mindstellar\database\Connection and the immutable
 *             mindstellar\database\QueryBuilder. Deprecated for direct use only:
 *             DAO::__construct() still instantiates it, so every legacy model
 *             depends on it and it cannot be removed before the legacy DAO is.
 * @see \mindstellar\database\Connection
 * @see \mindstellar\database\QueryBuilder
 */
class DBCommandClass
{
    private static $instance;
    /**
     * Driver result of the most recent query: a result set for a SELECT, true for
     * a write, false on failure. Initialised to 0.
     *
     * @since  2.3
     * @var mysqli_result|bool|int
     */
    public $resultId;
    /**
     * Every statement run through this object, when OSC_DEBUG_DB is on.
     *
     * @var array<int,string>
     */
    public $queries;
    /**
     * Wall-clock seconds per entry in {@see $queries}.
     *
     * @var array<int,float>
     */
    public $queryTimes;
    /**
     * Most recent SQL statement, tracked cheaply even when query logging is off.
     *
     * @var string
     */
    public $lastQuerySql = '';
    /**
     * Statements run through this object since it was created.
     *
     * @var int
     */
    public $queryCount;
    /**
     * Driver error number of the last statement, 0 when it succeeded.
     *
     * @var int
     */
    public $errorLevel;
    /**
     * Driver error text of the last statement, '' when it succeeded.
     *
     * @var string
     */
    public $errorDesc;
    /**
     * SELECT column expressions.
     *
     * @var array<int,string>
     */
    public $aSelect;
    /**
     * FROM tables (or subquery expressions).
     *
     * @var array<int,string>
     */
    public $aFrom;
    /*var $aDistinct; */
    /**
     * Compiled JOIN clauses.
     *
     * @var array<int,string>
     */
    public $aJoin;
    /**
     * Compiled WHERE conditions, each already carrying its AND/OR prefix.
     *
     * @var array<int,string>
     */
    public $aWhere;
    /**
     * Compiled LIKE conditions.
     *
     * @var array<int,string>
     */
    public $aLike;
    /**
     * GROUP BY expressions.
     *
     * @var array<int,string>
     */
    public $aGroupby;
    /**
     * Compiled HAVING conditions.
     *
     * @var array<int,string>
     */
    public $aHaving;
    /**
     * LIMIT row count, false when unset.
     *
     * @var int|false
     */
    public $aLimit;
    /* var $aKeys; */
    /**
     * LIMIT offset, false when unset.
     *
     * @var int|false
     */
    public $aOffset;
    /**
     * Global ORDER BY direction ('desc' or anything else for ASC), false when unset.
     *
     * @var string|false
     */
    public $aOrder;
    /**
     * ORDER BY expressions, each with its own direction appended.
     *
     * @var array<int,string>
     */
    public $aOrderby;
    /**
     * Column => already-escaped value map for the next INSERT/REPLACE/UPDATE.
     *
     * @var array<string,string|int>
     */
    public $aSet;
    /**
     * Escaped values collected while building the current IN (...) list.
     *
     * @var array<int,string|int>
     */
    public $aWherein;
    /**
     * Query log, only instantiated when OSC_DEBUG_DB or OSC_DEBUG_DB_EXPLAIN is on.
     *
     * @var LogDatabase|null
     */
    public $log;
    /* var $aAliasedTables; */
    /* var $aStoreArray; */
    /**
     * Database connection object to Shopclass database
     *
     * @since  2.3
     * @var mysqli|false|null
     */
    private $connId;
    /**
     * Written by _getSelect() on a branch that cannot be reached and read nowhere.
     *
     * @var array<int,string>
     */
    private $a_from;

    /**
     * DBCommandClass constructor.
     *
     * @param mysqli|false $connId Handed straight from ConnectionManager::getHandle()
     */
    public function __construct(&$connId)
    {
        $this->connId   = &$connId;
        $this->resultId = 0;

        $this->queries    = array();
        $this->queryTimes = array();
        $this->queryCount = 0;

        $this->errorLevel = 0;
        $this->errorDesc  = '';

        $this->aSelect  = array();
        $this->aFrom    = array();
        $this->aJoin    = array();
        $this->aWhere   = array();
        $this->aLike    = array();
        $this->aGroupby = array();
        $this->aHaving  = array();
        $this->aLimit   = false;
        $this->aOffset  = false;
        $this->aOrder   = false;
        $this->aOrderby = array();
        $this->aWherein = array();

        if (OSC_DEBUG_DB || OSC_DEBUG_DB_EXPLAIN) {
            $this->log = LogDatabase::newInstance();
        }
    }

    /**
     * It creates a new DBCommandClass object or if it has been created before, it
     * returns the previous object
     *
     * @return DBCommandClass
     * @since  2.3
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Unset connection and result objects
     *
     * @return void
     */
    public function __destruct()
    {
        unset($this->connId, $this->resultId);
    }

    /**
     * Set SELECT clause
     *
     * @param string|array<int,string> $select Comma-separated list, or one entry per column
     *
     * @return self
     * @since  2.3
     */
    public function select($select = '*')
    {
        if (is_string($select)) {
            $select = explode(',', $select);
        }

        foreach ($select as $s) {
            $s = trim($s);

            if ($s != '') {
                $this->aSelect[] = $s;
            }
        }

        return $this;
    }

    /**
     * Set JOIN clause
     *
     * @param string $table
     * @param string $cond
     * @param string $type It can be: LEFT, RIGHT, OUTER, INNER, LEFT OUTER or RIGHT OUTER
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder::join()
     */
    public function join($table, $cond, $type = '')
    {
        if ($type != '') {
            $type = strtoupper(trim($type));

            if (!in_array($type, array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER'))) {
                $type = '';
            } else {
                $type .= ' ';
            }
        }

        $join          = $type . ' JOIN ' . $table . ' ON ' . $cond;
        $this->aJoin[] = $join;

        return $this;
    }

    /**
     * Set WHERE clause using OR operator
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param mixed                      $value Ignored when $key is an array
     *
     * @return self
     * @since  2.3
     */
    public function orWhere($key, $value = null)
    {
        return $this->_where($key, $value, 'OR ');
    }

    /**
     * Set WHERE clause
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param mixed                      $value Ignored when $key is an array
     * @param string                     $type  'AND ' or 'OR ', used from the second condition on
     *
     * @return self
     * @since  2.3
     */
    private function _where($key, $value = null, $type = 'AND ')
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }

        foreach ($key as $k => $v) {
            $prefix = (count($this->aWhere) > 0) ? $type : '';

            if (!$this->_hasOperator($k)) {
                $k .= ' =';
            }

            if (null !== $v) {
                $v = ' ' . $this->escape($v);
            }

            $this->aWhere[] = $prefix . $k . $v;
        }

        return $this;
    }

    /**
     * Check if the string has an operator
     *
     * @param string $str
     *
     * @return bool
     * @since  2.3
     */
    private function _hasOperator($str)
    {
        $str = trim($str);

        if (!preg_match('/(\s|<|>|!|=|is null|is not null)/i', $str)) {
            return false;
        }

        return true;
    }

    /**
     * Add the apostrophe if it's an string; 0 or 1 if it's a bool ;  NULL
     *
     * Note: this escape-then-concatenate approach is the legacy path and is easy
     * to misuse. For new code prefer bound parameters via
     * mindstellar\database\Connection; never use escape() for identifiers.
     *
     * @param string|bool|int|float|null $str
     *
     * @return string|int|float A numeric value is returned unchanged, a bool as 0 or 1
     * @since  2.3
     */
    public function escape($str)
    {
        if (is_numeric($str)) {
            if (strlen($str) > 1 && strpos($str, '0') === 0) {
                $str = "'" . $str . "'";
            } else {
                return $str;
            }
        } elseif (is_string($str)) {
            $str = "'" . $this->escapeStr($str) . "'";
        } elseif (is_bool($str)) {
            $str = ($str === false) ? 0 : 1;
        } elseif (null === $str) {
            $str = 'NULL';
        }

        return $str;
    }

    /**
     * Escape the string if it's necessary
     *
     * @param string $str
     * @param bool   $like
     *
     * @return string
     * @since  2.3
     */
    private function escapeStr($str, $like = false)
    {
        if (is_object($this->connId)) {
            $str = $this->connId->real_escape_string($str);
        } else {
            $str = addslashes($str);
        }

        if ($like) {
            $str = str_replace(array('%', '_'), array('\\%', '\\_'), $str);
        }

        return $str;
    }

    /**
     * Set WHERE IN clause using AND operator
     *
     * @param string|null      $key
     * @param array<int,mixed>|scalar|null $values A non-array is treated as a single-element list
     *
     * @return self
     * @since  2.3
     */
    public function whereIn($key = null, $values = null)
    {
        return $this->_whereIn($key, $values);
    }

    /**
     * Set WHERE IN clause
     *
     * @param string|null                  $key
     * @param array<int,mixed>|scalar|null $values A non-array is treated as a single-element list
     * @param bool                         $not    true for NOT IN
     * @param string                       $type   'AND ' or 'OR ', used from the second condition on
     *
     * @return self
     * @since  2.3
     */
    private function _whereIn($key = null, $values = null, $not = false, $type = 'AND ')
    {
        if (!is_array($values)) {
            $values = array($values);
        }

        $not = $not ? ' NOT' : '';

        foreach ($values as $value) {
            $this->aWherein[] = $this->escape($value);
        }

        $prefix = (count($this->aWhere) > 0) ? $type : '';

        $whereIn = $prefix . $key . $not . ' IN (' . implode(', ', $this->aWherein) . ') ';

        $this->aWhere[] = $whereIn;
        $this->aWherein = array();

        return $this;
    }

    /**
     * Set WHERE IN clause using OR operator
     *
     * @param string|null                  $key
     * @param array<int,mixed>|scalar|null $values
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function orWhereIn($key = null, $values = null)
    {
        return $this->_whereIn($key, $values, false, 'OR ');
    }

    /**
     * Set WHERE NOT IN clause using AND operator
     *
     * @param string|null                  $key
     * @param array<int,mixed>|scalar|null $values
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function whereNotIn($key = null, $values = null)
    {
        return $this->_whereIn($key, $values, true);
    }

    /**
     * Set WHERE NOT IN clause using OR operator
     *
     * @param string|null                  $key
     * @param array<int,mixed>|scalar|null $values
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function orWhereNotIn($key = null, $values = null)
    {
        return $this->_whereIn($key, $values, true, 'OR ');
    }

    /**
     * Set LIKE clause
     *
     * @param string|array<string,string> $field Column, or a column => match map
     * @param string                      $match Ignored when $field is an array
     * @param string                      $side  before, after or both
     *
     * @return self
     */
    public function like($field, $match = '', $side = 'both')
    {
        return $this->_like($field, $match, 'AND ', $side);
    }

    /**
     * Set LIKE clause
     *
     * @param string|array<string,string> $field Column, or a column => match map
     * @param string                      $match Ignored when $field is an array
     * @param string                      $type  Types: AND, OR
     * @param string                      $side  Options: before, after, both
     * @param string                      $not   Two possibilities: blank or NOT
     *
     * @return self
     * @since  2.3
     */
    private function _like($field, $match = '', $type = 'AND ', $side = 'both', $not = '')
    {
        $likeStatement = '';

        if (!is_array($field)) {
            $field = array($field => $match);
        }

        foreach ($field as $k => $v) {
            $prefix = (count($this->aLike) === 0) ? '' : $type;
            $v      = $this->escapeStr($v, true);

            switch ($side) {
                case 'before':
                    $likeStatement = "$prefix $k $not LIKE '%$v'";
                    break;
                case 'after':
                    $likeStatement = "$prefix $k $not LIKE '$v%'";
                    break;
                default:
                    $likeStatement = "$prefix $k $not LIKE '%$v%'";
                    break;
            }

            $this->aLike[] = $likeStatement;
        }

        return $this;
    }

    /**
     * Set NOT LIKE clause using AND operator
     *
     * @param string $field
     * @param string $match
     * @param string $side before, after or both
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function notLike($field, $match = '', $side = 'both')
    {
        return $this->_like($field, $match, 'AND ', $side, 'NOT');
    }

    /**
     * Set LIKE clause using OR operator
     *
     * @param string $field
     * @param string $match
     * @param string $side before, after or both
     *
     * @return self
     * @since  2.3
     *
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function orLike($field, $match = '', $side = 'both')
    {
        return $this->_like($field, $match, 'OR ', $side);
    }

    /**
     * Set NOT LIKE clause using OR operator
     *
     * @param string $field
     * @param string $match
     * @param string $side before, after or both
     *
     * @return self
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function orNotLike($field, $match = '', $side = 'both')
    {
        return $this->_like($field, $match, 'OR ', $side, 'NOT');
    }

    /**
     * Fields for GROUP BY clause
     *
     * @param string|array<int,string> $by Comma-separated list, or one entry per expression
     *
     * @return self
     * @since  2.3
     */
    public function groupBy($by)
    {
        if (is_string($by)) {
            $by = explode(',', $by);
        }

        foreach ($by as $val) {
            $val = trim($val);

            if ($val != '') {
                $this->aGroupby[] = $val;
            }
        }

        return $this;
    }

    /**
     * Add an AND HAVING condition. Unlike the other clause methods this does NOT
     * return the builder, so it cannot be chained.
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param string                     $value Ignored when $key is an array
     *
     * @return void
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder::having()
     */
    public function having($key, $value = '')
    {
        return $this->_having($key, $value);
    }

    /**
     * Append a HAVING condition, joined to the previous one with $type.
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param string                     $value Ignored when $key is an array
     * @param string                     $type  'AND ' or 'OR ', used from the second condition on
     *
     * @return void
     */
    private function _having($key, $value = '', $type = 'AND ')
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }

        foreach ($key as $k => $v) {
            $prefix = (count($this->aHaving) == 0) ? '' : $type;

            if (!$this->_hasOperator($k)) {
                $k .= ' = ';
            }

            $v = ' ' . $this->escapeStr($v);

            $this->aHaving[] = $prefix . $k . $v;
        }
    }

    /**
     * Add an OR HAVING condition. Like having(), it does NOT return the builder.
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param string                     $value Ignored when $key is an array
     *
     * @return void
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder::having()
     */
    public function orHaving($key, $value = '')
    {
        return $this->_having($key, $value, 'OR ');
    }

    /**
     * Set ORDER BY clause
     *
     * @param string $orderby
     * @param string $direction Accepted directions: random, asc, desc
     *
     * @return self
     * @since  2.3
     */
    public function orderBy($orderby, $direction = '')
    {
        if (strtolower($direction) === 'random') {
            $direction = ' RAND()';
        } elseif (trim($direction)) {
            $direction = in_array(strtoupper(trim($direction)), array('ASC', 'DESC')) ? ' ' . $direction : ' ASC';
        }

        $this->aOrderby[] = $orderby . $direction;

        return $this;
    }

    /**
     * Set the offset in the LIMIT clause
     *
     * @param int|string $offset Anything non-numeric resets the offset to 0
     *
     * @return self
     * @since  2.3
     */
    public function offset($offset)
    {
        $this->aOffset = 0;
        if (is_numeric($offset)) {
            $this->aOffset = (int)$offset;
        }

        return $this;
    }

    /**
     * Create the INSERT sql and perform the query
     *
     * @param string                   $table Falls back to the first FROM table when empty
     * @param array<string,mixed>|null $set   Merged into the pending SET map first
     *
     * @return bool
     * @since  2.3
     */
    public function insert($table = '', $set = null)
    {
        if (null !== $set) {
            $this->set($set);
        }

        if (count($this->aSet) === 0) {
            return false;
        }

        if (!$table) {
            if (!isset($this->aFrom[0])) {
                return false;
            }

            $table = $this->aFrom[0];
        }

        $sql = $this->_insert($table, array_keys($this->aSet), array_values($this->aSet));
        $this->_resetWrite();

        return $this->query($sql);
    }

    /**
     * Set aSet array
     *
     * @param string|array<string,mixed> $key    Column, or a column => value map
     * @param mixed                      $value  Ignored when $key is an array
     * @param bool                       $escape Run each value through escape() first
     *
     * @return self
     * @since  2.3
     */
    public function set($key, $value = '', $escape = true)
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }

        foreach ($key as $k => $v) {
            if ($escape) {
                $this->aSet[$k] = $this->escape($v);
            } else {
                $this->aSet[$k] = $v;
            }
        }

        return $this;
    }

    /**
     * Create the INSERT sql string
     *
     * @param string            $table
     * @param array<int,string> $keys
     * @param array<int,mixed>  $values Already escaped by set()
     *
     * @return string
     * @since  2.3
     */
    private function _insert($table, $keys, $values)
    {
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $values) . ')';
    }

    /**
     * Reset variables used in write sql: aSet, aFrom, aWhere, aLike, aOrderby, aLimit, aOrder
     *
     * @return void
     * @since  2.3
     */
    private function _resetWrite()
    {
        $aReset = array(
            'aSet'     => array(),
            'aFrom'    => array(),
            'aWhere'   => array(),
            'aLike'    => array(),
            'aOrderby' => array(),
            'aLimit'   => false,
            'aOrder'   => false
        );

        $this->_resetRun($aReset);
    }

    /**
     * Initializate $aReset variables
     *
     * @param array<string,mixed> $aReset Property name => value to restore
     *
     * @return void
     * @since  2.3
     */
    private function _resetRun($aReset)
    {
        foreach ($aReset as $item => $defaultValue) {
            $this->$item = $defaultValue;
        }
    }

    /**
     * Performs a query on the database
     *
     * @param string $sql
     *
     * @return bool|DBRecordsetClass
     * @since  2.3
     */
    public function query($sql)
    {
        if ($sql == '') {
            return false;
        }

        if (OSC_DEBUG_DB_EXPLAIN && $this->isSelectType($sql)) {
            $this->query_debug($sql);
        }

        $this->lastQuerySql = $sql;
        if (OSC_DEBUG_DB) {
            $this->queries[] = $sql;
        }
        $timeStart = microtime(true);

        $this->resultId = $this->execute($sql);

        $this->errorReport();
        if (false === $this->resultId) {
            if (OSC_DEBUG_DB) {
                $this->log->addMessage($sql, 0, $this->errorLevel, $this->errorDesc);
            }

            return false;
        }

        $timeEnd = microtime(true);
        if (OSC_DEBUG_DB) {
            $this->queryTimes[] = $timeEnd - $timeStart;
        }

        $this->queryCount++;

        if (OSC_DEBUG_DB) {
            $this->log->addMessage($sql, $timeEnd - $timeStart, $this->errorLevel, $this->errorDesc);
        }

        if ($this->isWriteType($sql) === true) {
            return true;
        }

        $rs           = new DBRecordsetClass();
        $rs->connId   = $this->connId;
        $rs->resultId = $this->resultId;
        $rs->numRows  = $rs->numRows();

        return $rs;
    }

    /**
     * Check if the sql is a select
     *
     * @param string $sql
     *
     * @return bool
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function isSelectType($sql)
    {
        if (!preg_match('/^\s*"?(SELECT)\s+/i', $sql)) {
            return false;
        }

        return true;
    }

    /**
     * Run EXPLAIN for $sql and record the plan in the debug log.
     *
     * @param string $sql
     *
     * @return bool false when the statement is empty, fails, or explains to nothing
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function query_debug($sql)
    {
        if ($sql == '') {
            return false;
        }

        $sql  = 'EXPLAIN ' . $sql;
        $rsID = $this->execute($sql);

        if (false === $rsID) {
            return false;
        }

        $rs           = new DBRecordsetClass();
        $rs->connId   = $this->connId;
        $rs->resultId = $rsID;
        $rs->numRows  = $rs->numRows();

        if ($rs->numRows() == 0) {
            return false;
        }

        $this->log->addExplainMessage($sql, $rs->result());

        return true;
    }

    /**
     * Performs a query on the database
     *
     * @param string $sql
     *
     * @return bool|\mysqli_result
     * @since  2.3
     */
    private function execute($sql)
    {
        if ($this->connId === null) {
            return false;
        }
        try {
            $result = $this->connId->query($sql);
        } catch (Exception $e) {
            $this->errorLevel = $e->getCode();
            $this->errorDesc  = $e->getMessage();
            $result = false;
        }

        return $result;
    }

    /**
     * Set last error code and descriptionfor the most recent mysqli function call
     *
     * @return void
     * @since  2.3
     */
    private function errorReport()
    {
        $this->errorLevel = $this->connId->errno;
        $this->errorDesc  = $this->connId->error;
    }

    /**
     * Check if the sql is a write such as INSERT, UPDATE, UPDATE...
     *
     * @param string $sql
     *
     * @return bool
     * @since  2.3
     */
    private function isWriteType($sql)
    {
        if (!preg_match(
            '/^\s*"?(SET|INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|TRUNCATE|LOAD DATA|COPY|ALTER|GRANT|REVOKE|LOCK|UNLOCK|RENAME)\s+/i',
            $sql
        )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Create the REPLACE INTO sql and perform the query
     *
     * @param string                   $table Falls back to the first FROM table when empty
     * @param array<string,mixed>|null $set   Merged into the pending SET map first
     *
     * @return bool
     * @since  2.3
     */
    public function replace($table = '', $set = null)
    {
        if (null !== $set) {
            $this->set($set);
        }

        if (count($this->aSet) == 0) {
            return false;
        }

        if ($table == '') {
            if (!isset($this->aFrom[0])) {
                return false;
            }

            $table = $this->aFrom[0];
        }

        $sql = $this->_replace($table, array_keys($this->aSet), array_values($this->aSet));
        $this->_resetWrite();

        return $this->query($sql);
    }

    /**
     * Create the REPLACE INTO sql string
     *
     * @param string            $table
     * @param array<int,string> $keys
     * @param array<int,mixed>  $values Already escaped by set()
     *
     * @return string
     * @since  2.3
     */
    private function _replace($table, $keys, $values)
    {
        return 'REPLACE INTO ' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $values) . ')';
    }

    /**
     * Create the UPDATE sql and perform the query
     *
     * @param string                                  $table Falls back to the first FROM table when empty
     * @param array<string,mixed>|null                $set   Merged into the pending SET map first
     * @param string|array<string,mixed>|null         $where Added to the pending WHERE conditions
     *
     * @return int|false Affected rows, or false when nothing could be run
     * @since  2.3
     */
    public function update($table = '', $set = null, $where = null)
    {
        if (null !== $set) {
            $this->set($set);
        }

        if (count($this->aSet) == 0) {
            return false;
        }

        if ($table == '') {
            if (!isset($this->aFrom[0])) {
                return false;
            }

            $table = $this->aFrom[0];
        }

        if ($where != null) {
            $this->where($where);
        }

        $sql = $this->_update($table, $this->aSet, $this->aWhere);

        $this->_resetWrite();
        $result = $this->query($sql);

        if ($result === false) {
            return false;
        }

        return $this->affectedRows();
    }

    /**
     * Set WHERE clause using OR operator
     *
     * @param string|array<string,mixed> $key   Column (with optional operator), or a column => value map
     * @param mixed                      $value Ignored when $key is an array
     *
     * @return self
     * @since  2.3
     */
    public function where($key, $value = null)
    {
        return $this->_where($key, $value);
    }

    /**
     * Create the UPDATE sql string
     *
     * @param string              $table
     * @param array<string,mixed> $values Column => already-escaped value
     * @param array<int,string>   $where  Compiled WHERE conditions
     *
     * @return string
     * @since  2.3
     */
    private function _update($table, $values, $where)
    {
        foreach ($values as $k => $v) {
            $valstr[] = $k . ' = ' . $v;
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $valstr);

        $sql .= ($where != '' && count($where) > 0) ? ' WHERE ' . implode(' ', $where) : '';

        return $sql;
    }

    /**
     * Gets the number of affected rows in a previous MySQL operation
     *
     * @return int
     * @since  2.3
     */
    public function affectedRows()
    {
        return $this->connId->affected_rows;
    }

    /**
     * Create the DELETE sql and perform the query
     *
     * @param string                          $table Falls back to the first FROM table when empty
     * @param string|array<string,mixed>|null $where Added to the pending WHERE conditions
     *
     * @return int|false Affected rows, or false when there is nothing to bound the delete
     * @since  2.3
     */
    public function delete($table = '', $where = '')
    {
        if ($table == '') {
            if (!isset($this->aFrom[0])) {
                return false;
            }

            $table = $this->aFrom[0];
        }

        if ($where != null) {
            $this->where($where);
        }

        if (count($this->aWhere) == 0 && count($this->aWherein) == 0 && count($this->aLike) == 0) {
            return false;
        }

        $sql = $this->_delete($table, $this->aWhere, $this->aLike);

        $this->_resetWrite();
        $result = $this->query($sql);

        if ($result == false) {
            return false;
        }

        return $this->affectedRows();
    }

    /**
     * Create the DELETE sql string
     *
     * @param string            $table
     * @param array<int,string> $where Compiled WHERE conditions
     * @param array<int,string> $like  Compiled LIKE conditions
     *
     * @return string
     * @since  2.3
     */
    private function _delete($table, $where, $like)
    {
        $conditions = '';

        if (count($where) > 0 || count($like) > 0) {
            $conditions = "\nWHERE ";
            $conditions .= implode("\n", $where);

            if (count($where) > 0 && count($like) > 0) {
                $conditions .= ' AND ';
            }
            $conditions .= implode("\n", $like);
        }

        return 'DELETE FROM ' . $table . $conditions;
    }

    /**
     * Compile the select sql string and perform the query. Quick method for
     * getting the rows of one table
     *
     * @param string          $table
     * @param int|null        $limit
     * @param int|string|null $offset Only applied when $limit is given
     *
     * @return DBRecordsetClass|bool The recordset, or false when the query fails
     * @since  2.3
     */
    public function get($table = '', $limit = null, $offset = null)
    {
        if ($table != '') {
            $this->from($table);
        }

        if (null !== $limit) {
            $this->limit($limit, $offset);
        }

        $sql = $this->_getSelect();

        $result = $this->query($sql);
        $this->_resetSelect();

        return $result;
    }

    /**
     * Set FROM clause
     *
     * @param string|array<int,string> $from Comma-separated list, a single subquery, or one entry per table
     *
     * @return self
     */
    public function from($from)
    {
        if (!is_array($from)) {
            // Check if $from is a subquery
            if (strpos($from, '(') !== false && strpos($from, ')') !== false) {
                $from = array($from); // Wrap the subquery in an array
            } else {
                // Explode by comma as before
                if (strpos($from, ',') !== false) {
                    $from = explode(',', $from);
                } else {
                    $from = array($from);
                }
            }
        }

        foreach ($from as $f) {
            $this->aFrom[] = $f;
        }

        return $this;
    }

    /**
     * Set LIMIT clause
     *
     * @param int|string        $value
     * @param int|string         $offset Ignored when empty
     *
     * @return self
     * @since  2.3
     */
    public function limit($value, $offset = '')
    {
        if (is_numeric($value)) {
            $this->aLimit = (int)$value;
        }

        if ($offset != '') {
            $this->aOffset = 0;
            if (is_numeric($offset)) {
                $this->aOffset = (int)$offset;
            }
        }

        return $this;
    }

    /**
     * Create SELECT sql statement
     *
     * @return string
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function _getSelect()
    {
        $sql = 'SELECT ';

        // "SELECT" portion of the query
        if (count($this->aSelect) == 0) {
            $sql .= '*';
        } else {
            $sql .= implode(', ', $this->aSelect);
        }

        // "FROM" portion of the query
        if (count($this->aFrom) > 0) {
            $sql .= "\nFROM ";
            if (!is_array($this->aFrom)) {
                $this->a_from = array($this->aFrom);
            }
            // instead of comma separated tables, we use cross join
            // , is same as CROSS JOIN in this case
            if (count($this->aFrom) > 1) {
                $sql .= implode(' CROSS JOIN ', $this->aFrom);
            } else {
                $sql .= implode(', ', $this->aFrom);
            }
        }

        // "JOIN" portion of the query
        if (count($this->aJoin) > 0) {
            $sql .= "\n";
            $sql .= implode("\n", $this->aJoin);
        }

        // "WHERE" portion of the query
        if (count($this->aWhere) > 0 || count($this->aLike) > 0) {
            $sql .= "\n";
            $sql .= 'WHERE ';
        }

        $sql .= implode("\n", $this->aWhere);

        // "LIKE" portion of the query
        if (count($this->aLike) > 0) {
            if (count($this->aWhere) > 0) {
                $sql .= "\nAND";
            }

            $sql .= implode("\n", $this->aLike);
        }

        // "GROUP BY" portion of the query
        if (count($this->aGroupby) > 0) {
            $sql .= "\nGROUP BY ";
            $sql .= implode(', ', $this->aGroupby);
        }

        // "HAVING" portion of the query
        if (count($this->aHaving) > 0) {
            $sql .= "\nHAVING ";
            $sql .= implode(', ', $this->aHaving);
        }

        // "ORDER BY" portion of the query
        if (count($this->aOrderby) > 0) {
            $sql .= "\nORDER BY ";
            $sql .= implode(', ', $this->aOrderby);

            if ($this->aOrder !== false) {
                $sql .= ($this->aOrder === 'desc') ? ' DESC' : ' ASC';
            }
        }

        // "LIMIT" portion of the query
        if (is_numeric($this->aLimit)) {
            $sql .= "\n";
            $sql .= 'LIMIT ' . $this->aLimit;

            if ($this->aOffset > 0) {
                $sql .= ', ' . $this->aOffset;
            }
        }

        return $sql;
    }

    /**
     * Reset variables used in select sql: aSelect, aFrom, aJoin, aWhere, aLike, aGroupby, aHaving,
     * aOrderby, aWherein, aLimit, aOffset, aOrder
     *
     * @return void
     * @since  2.3
     */
    public function _resetSelect()
    {
        $aReset = array(
            'aSelect'  => array(),
            'aFrom'    => array(),
            'aJoin'    => array(),
            'aWhere'   => array(),
            'aLike'    => array(),
            'aGroupby' => array(),
            'aHaving'  => array(),
            'aOrderby' => array(),
            'aWherein' => array(),
            'aLimit'   => false,
            'aOffset'  => false,
            'aOrder'   => false
        );

        $this->_resetRun($aReset);
    }

    /**
     * Execute queries sql. We replace TABLE_PREFIX for the real prefix: DB_TABLE_PREFIX
     * The executions is stopped if some query throws an error.
     *
     * @param string $sql
     *
     * @return boolean true if it's succesful, false if not
     * @since  2.3
     */
    public function importSQL($sql)
    {
        // Parsing lives in mindstellar\database\SqlScript so the installer, the
        // migration runner and this path all split a script the same way.
        // Execution stays here, on this object's own connection: callers read
        // getErrorLevel() straight afterwards, and one of them holds a handle
        // that is not the shared singleton.
        foreach (\mindstellar\database\SqlScript::statements($sql) as $q) {
            if (!$this->query($q)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bring an installed schema up to the one struct.sql describes.
     *
     * The implementation now lives in mindstellar\database\SchemaReconciler,
     * which runs on the parameterized layer; this stays as the entry point the
     * upgrade tooling and any plugin already calls, with the same return shape.
     *
     * @param array<int,string>|string $queries struct.sql, or its statements already split
     *
     * @return array{0:bool,1:array<int|string,string>,2:array<int,string>} success, the statements it
     *                                                                     ran, and those that failed
     */
    public function updateDB($queries = '')
    {
        $reconciler = new \mindstellar\database\SchemaReconciler(
            new \mindstellar\database\Connection($this->connId)
        );

        return $reconciler->reconcile($queries);
    }

    /**
     * Get last SQL query
     *
     * @return string
     * @since  2.3
     * @deprecated 5.3 Unused by core. Retained only because the object is reachable
     *             from plugins as $model->dao; prefer mindstellar\database\Connection
     *             or QueryBuilder in new code.
     * @see \mindstellar\database\QueryBuilder
     */
    public function lastQuery()
    {
        return $this->lastQuerySql;
    }

    /**
     * Get the ID generated from the previous INSERT operation
     *
     * @return int|string
     * @since  2.3
     */
    public function insertedId()
    {
        return $this->connId->insert_id;
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
}

/* file end: ./oc-includes/osclass/classes/database/DBCommandClass.php */
