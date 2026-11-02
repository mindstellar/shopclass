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

declare(strict_types=1);

namespace mindstellar\search\query;

/**
 * One SELECT being assembled: clause text plus the values bound to its `?`s.
 *
 * Plugin fragments are raw SQL and go in as written. Values core adds go in as
 * parameters. The text keeps the spelling plugins have always seen: a two-space
 * "LEFT  JOIN", extra FROM tables as CROSS JOIN, and select lists split on commas.
 */
final class Statement
{
    /** @var array<int,string> */
    private array $select = array();
    /** @var array<int,string> */
    private array $from = array();
    /** @var array<int,string> */
    private array $join = array();
    /** @var array<int,string> */
    private array $where = array();
    /** @var array<int,string> */
    private array $groupBy = array();
    /** @var array<int,string> */
    private array $having = array();
    /** @var array<int,string> */
    private array $orderBy = array();
    private ?int $limit = null;
    private ?int $offset = null;

    /** @var array<string,array<int,mixed>> bound values per clause, in clause order */
    private array $params = array(
        'select' => array(),
        'join'   => array(),
        'where'  => array(),
        'having' => array(),
    );

    /**
     * Add select expressions; a string is split on commas.
     *
     * @param string|array<int,string> $select
     *
     * @return void
     */
    public function select($select): void
    {
        if (is_string($select)) {
            $select = explode(',', $select);
        }
        foreach ($select as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $this->select[] = $s;
            }
        }
    }

    /**
     * Add one select expression with bound values; it is never split.
     *
     * @param string           $expr
     * @param array<int,mixed> $params
     *
     * @return void
     */
    public function selectBound(string $expr, array $params): void
    {
        $this->select[] = $expr;
        array_push($this->params['select'], ...$params);
    }

    /**
     * Add FROM tables. A comma list is split unless it holds a subquery.
     *
     * @param string $from
     *
     * @return void
     */
    public function from(string $from): void
    {
        if (strpos($from, '(') !== false && strpos($from, ')') !== false) {
            $parts = array($from);
        } else {
            $parts = explode(',', $from);
        }
        foreach ($parts as $f) {
            $this->from[] = $f;
        }
    }

    /**
     * Add a JOIN. An unknown join type is dropped, leaving a plain JOIN.
     *
     * @param string           $table
     * @param string           $cond
     * @param string           $type
     * @param array<int,mixed> $params
     *
     * @return void
     */
    public function join(string $table, string $cond, string $type = '', array $params = array()): void
    {
        if ($type !== '') {
            $type = strtoupper(trim($type));
            $type = in_array($type, array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER'), true)
                ? $type . ' '
                : '';
        }
        $this->join[] = $type . ' JOIN ' . $table . ' ON ' . $cond;
        array_push($this->params['join'], ...$params);
    }

    /**
     * Add a WHERE condition, AND-joined to the ones before it. One without an
     * operator gets " =" appended, as it always did.
     *
     * @param string           $sql
     * @param array<int,mixed> $params
     *
     * @return void
     */
    public function where(string $sql, array $params = array()): void
    {
        if (!self::hasOperator($sql)) {
            $sql .= ' =';
        }
        $this->where[] = ($this->where !== array() ? 'AND ' : '') . $sql;
        array_push($this->params['where'], ...$params);
    }

    /**
     * Add a WHERE condition already carrying its own connector, or none.
     *
     * @param string $sql
     *
     * @return void
     */
    public function whereAsIs(string $sql): void
    {
        $this->where[] = $sql;
    }

    /**
     * Whether any WHERE condition is set.
     *
     * @return bool
     */
    public function hasWhere(): bool
    {
        return $this->where !== array();
    }

    /**
     * Whether each row is a different listing: one FROM table, joins only to the given
     * one-row-per-listing tables, and no GROUP BY.
     *
     * @param string ...$oneToOne
     *
     * @return bool
     */
    public function onePerListing(string ...$oneToOne): bool
    {
        if (count($this->from) !== 1 || $this->groupBy !== array()) {
            return false;
        }
        foreach ($this->join as $join) {
            $table = (string)preg_replace('/^.*?JOIN (\S+) ON .*$/s', '$1', $join);
            if (!in_array($table, $oneToOne, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add GROUP BY columns; a string is split on commas.
     *
     * @param string|array<int,string> $by
     *
     * @return void
     */
    public function groupBy($by): void
    {
        if (is_string($by)) {
            $by = explode(',', $by);
        }
        foreach ($by as $val) {
            $val = trim((string)$val);
            if ($val !== '') {
                $this->groupBy[] = $val;
            }
        }
    }

    /**
     * Add a HAVING condition, or column => value pairs. One without an operator gets
     * " = " appended, as it always did.
     *
     * @param string|array<string,mixed> $having
     *
     * @return void
     */
    public function having($having): void
    {
        foreach (is_array($having) ? $having : array($having => '') as $key => $value) {
            $key = (string)$key;
            if (!self::hasOperator($key)) {
                $key .= ' = ';
            }
            $this->having[] = ($this->having === array() ? '' : 'AND ') . $key . ' ' . SqlValue::escape((string)$value);
        }
    }

    /**
     * Add a HAVING clause as written.
     *
     * @param string $sql
     *
     * @return void
     */
    public function havingAsIs(string $sql): void
    {
        $this->having[] = $sql;
    }

    /**
     * Add an ORDER BY term. A direction other than ASC/DESC becomes ASC; 'random' is RAND().
     *
     * @param string $orderBy
     * @param string $direction
     *
     * @return void
     */
    public function orderBy(string $orderBy, string $direction = ''): void
    {
        if (strtolower($direction) === 'random') {
            $direction = ' RAND()';
        } elseif (trim($direction) !== '') {
            $direction = in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC';
        }
        $this->orderBy[] = $orderBy . $direction;
    }

    /**
     * Put ORDER BY terms ahead of the ones already set.
     *
     * @param array<int,string> $terms
     *
     * @return void
     */
    public function prependOrderBy(array $terms): void
    {
        $this->orderBy = array_merge($terms, $this->orderBy);
    }

    /**
     * The row window, emitted as "LIMIT <offset>, <count>".
     *
     * @param mixed $offset
     * @param mixed $count
     *
     * @return void
     */
    public function limit($offset, $count = ''): void
    {
        if (is_numeric($offset)) {
            $this->limit = (int)$offset;
        }
        if ($count !== '' && $count !== null) {
            $this->offset = is_numeric($count) ? (int)$count : 0;
        }
    }

    /**
     * Fold in clauses a caller added straight onto the model's legacy $dao: its SELECT,
     * JOIN, GROUP BY, HAVING and WHERE are appended, its ORDER BY goes first.
     *
     * @param \DBCommandClass $dao
     * @param bool            $count true leaves the order out
     *
     * @return void
     */
    public function mergeDao(\DBCommandClass $dao, bool $count): void
    {
        foreach (array('aSelect' => 'select', 'aJoin' => 'join', 'aGroupby' => 'groupBy', 'aHaving' => 'having') as $from => $to) {
            foreach ((array)$dao->$from as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $this->{$to}[] = $part;
                }
            }
        }
        foreach ((array)$dao->aWhere as $w) {
            $w = trim((string)$w);
            if ($w === '') {
                continue;
            }
            if ($this->where !== array() && !preg_match('/^(AND|OR)\b/i', $w)) {
                $w = 'AND ' . $w;
            }
            $this->where[] = $w;
        }
        if (!$count) {
            $order = array();
            foreach ((array)$dao->aOrderby as $o) {
                $o = trim((string)$o);
                if ($o !== '') {
                    $order[] = $o;
                }
            }
            $this->prependOrderBy($order);
        }
    }

    /**
     * Whether a fragment carries its own operator.
     *
     * @param string $sql
     *
     * @return bool
     */
    private static function hasOperator(string $sql): bool
    {
        return preg_match('/(\s|<|>|!|=|is null|is not null)/i', trim($sql)) === 1;
    }

    /**
     * The SQL text and its bound values, in placeholder order.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function compile(): array
    {
        $sql = 'SELECT ' . ($this->select === array() ? '*' : implode(', ', $this->select));
        if ($this->from !== array()) {
            $sql .= "\nFROM " . implode(count($this->from) > 1 ? ' CROSS JOIN ' : ', ', $this->from);
        }
        if ($this->join !== array()) {
            $sql .= "\n" . implode("\n", $this->join);
        }
        if ($this->where !== array()) {
            $sql .= "\nWHERE " . implode("\n", $this->where);
        }
        if ($this->groupBy !== array()) {
            $sql .= "\nGROUP BY " . implode(', ', $this->groupBy);
        }
        if ($this->having !== array()) {
            $sql .= "\nHAVING " . implode(', ', $this->having);
        }
        if ($this->orderBy !== array()) {
            $sql .= "\nORDER BY " . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= "\nLIMIT " . $this->limit;
            if ($this->offset !== null && $this->offset > 0) {
                $sql .= ', ' . $this->offset;
            }
        }

        return array($sql, array_merge(
            $this->params['select'],
            $this->params['join'],
            $this->params['where'],
            $this->params['having']
        ));
    }
}
