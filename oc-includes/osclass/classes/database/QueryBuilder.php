<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

/**
 * Class QueryBuilder
 *
 * Immutable fluent query builder that compiles to prepared statements and runs
 * through the injection-safe Connection API. Every clause method returns a NEW
 * cloned instance, never mutating $this, so a partially-built builder can be
 * safely reused or stored and branched without any shared state leaking between
 * derived queries.
 *
 * Injection safety is structural: every VALUE becomes a bound '?' parameter and
 * every IDENTIFIER (table/column) is validated against a strict allowlist and
 * backtick-quoted before it touches the SQL string. The single documented escape
 * hatch is whereRaw(), whose expression is trusted SQL the CALLER owns.
 *
 * This sits BESIDE the legacy stateful DBCommandClass, which is untouched. Unlike
 * that class it compiles LIMIT/OFFSET as an explicit `LIMIT <count> OFFSET
 * <offset>` and never the inverted `LIMIT a,b` form.
 *
 * @package mindstellar\database
 */
class QueryBuilder
{
    /**
     * Sentinel default for the third where() argument so a genuine null value in
     * the 2-argument form (where('col', null)) is distinguishable from the
     * "operator omitted" case. Callers never pass this value.
     */
    private const NO_ARG = "\0__QB_NO_ARG__\0";

    /**
     * Comparison operators permitted in where()/having()/join() conditions.
     */
    private const OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE'];

    /**
     * Table this query targets (raw; validated on use).
     *
     * @var string
     */
    private $table;

    /**
     * Selected columns (raw identifiers). Empty means SELECT *.
     *
     * @var array
     */
    private $columns = [];

    /**
     * WHERE conditions. Each is a descriptor array keyed by 'type'.
     *
     * @var array
     */
    private $wheres = [];

    /**
     * JOIN clauses.
     *
     * @var array
     */
    private $joins = [];

    /**
     * GROUP BY columns (raw identifiers).
     *
     * @var array
     */
    private $groups = [];

    /**
     * HAVING conditions.
     *
     * @var array
     */
    private $havings = [];

    /**
     * ORDER BY clauses, each ['column' => string, 'direction' => 'ASC'|'DESC'].
     *
     * @var array
     */
    private $orders = [];

    /**
     * LIMIT row count, or null for none.
     *
     * @var int|null
     */
    private $limit;

    /**
     * OFFSET, or null for none. Only emitted when a LIMIT is also present.
     *
     * @var int|null
     */
    private $offset;

    /**
     * @param string $table Target table (validated when compiled)
     */
    public function __construct(string $table)
    {
        $this->assertIdent($table);
        $this->table = $table;
    }

    /**
     * Set the selected columns. Replaces any previous selection; defaults to '*'
     * when never called. Each identifier is validated immediately.
     *
     * @param string ...$columns
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier
     */
    public function select(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertIdent($column);
        }
        $c = clone $this;
        $c->columns = $columns;

        return $c;
    }

    /**
     * Add an AND WHERE condition. Two-argument form where('id', 5) means `= 5`;
     * three-argument form where('id', '>', 5) uses the given operator. The value
     * is always a bound parameter.
     *
     * @param string $column
     * @param mixed  $operatorOrValue Operator (3-arg) or value (2-arg)
     * @param mixed  $value           Value (3-arg only)
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or operator
     */
    public function where(string $column, $operatorOrValue, $value = self::NO_ARG): self
    {
        return $this->addWhere($column, $operatorOrValue, $value, 'AND', func_num_args());
    }

    /**
     * Add an OR WHERE condition. Same forms and validation as where().
     *
     * @param string $column
     * @param mixed  $operatorOrValue
     * @param mixed  $value
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or operator
     */
    public function orWhere(string $column, $operatorOrValue, $value = self::NO_ARG): self
    {
        return $this->addWhere($column, $operatorOrValue, $value, 'OR', func_num_args());
    }

    /**
     * Add a `column IN (?, ?, ...)` condition with one bound param per value. An
     * empty array yields a condition that matches nothing (`1 = 0`) rather than
     * emitting invalid SQL.
     *
     * @param string $column
     * @param array  $values
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier
     */
    public function whereIn(string $column, array $values): self
    {
        $this->assertIdent($column);
        $c = clone $this;
        if ($values === []) {
            $c->wheres[] = ['type' => 'raw', 'sql' => '1 = 0', 'params' => [], 'boolean' => 'AND'];

            return $c;
        }
        $c->wheres[] = [
            'type'    => 'in',
            'column'  => $column,
            'values'  => array_values($values),
            'boolean' => 'AND',
        ];

        return $c;
    }

    /**
     * Escape hatch for a raw boolean expression. The expression is TRUSTED SQL
     * that the CALLER owns: it must supply its own '?' placeholders and the
     * matching $params, and must never interpolate untrusted input. Nothing in
     * $expr is validated or quoted by the builder.
     *
     * @param string $expr   Raw boolean SQL (may contain '?' placeholders)
     * @param array  $params Values for the placeholders in $expr
     *
     * @return self A cloned builder
     */
    public function whereRaw(string $expr, array $params = []): self
    {
        $c = clone $this;
        $c->wheres[] = ['type' => 'raw', 'sql' => $expr, 'params' => array_values($params), 'boolean' => 'AND'];

        return $c;
    }

    /**
     * OR variant of whereRaw(). Same trusted-SQL contract.
     *
     * @param string $expr
     * @param array  $params
     *
     * @return self A cloned builder
     */
    public function orWhereRaw(string $expr, array $params = []): self
    {
        $c = clone $this;
        $c->wheres[] = ['type' => 'raw', 'sql' => $expr, 'params' => array_values($params), 'boolean' => 'OR'];

        return $c;
    }

    /**
     * Add a parenthesised group of conditions joined to the rest with AND. The
     * callback receives a fresh builder and must RETURN it after adding
     * conditions (the builder is immutable, so the fluent chain's result is what
     * gets used):
     *
     *   ->where('b_active', 1)
     *   ->whereGroup(fn($q) => $q->where('s_title', 'LIKE', $t)->orWhere('s_body', 'LIKE', $t))
     *   // => WHERE b_active = ? AND (s_title LIKE ? OR s_body LIKE ?)
     *
     * Groups may nest. Every condition inside the group still binds its values
     * and validates its identifiers exactly as at the top level.
     *
     * @param callable $fn
     *
     * @return self A cloned builder
     * @throws DbException when the callback does not return the builder
     */
    public function whereGroup(callable $fn): self
    {
        return $this->addGroup($fn, 'AND');
    }

    /**
     * OR variant of whereGroup(): the parenthesised group is joined with OR.
     *
     * @param callable $fn
     *
     * @return self A cloned builder
     * @throws DbException when the callback does not return the builder
     */
    public function orWhereGroup(callable $fn): self
    {
        return $this->addGroup($fn, 'OR');
    }

    /**
     * Add an INNER JOIN. The ON clause compares two COLUMNS (both validated
     * identifiers), never a bound value; the operator is whitelisted.
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or operator
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    /**
     * Add a LEFT JOIN. Same validation as join().
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or operator
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    /**
     * Add a LIKE condition. The wildcard payload is built in PHP and bound as a
     * single parameter; LIKE metacharacters (% and _) in $value are escaped
     * first, so a literal '%' or '_' typed by a user stays literal.
     *
     * @param string $column
     * @param string $value
     * @param string $side 'both' (default), 'before' (%value) or 'after' (value%)
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or side
     */
    public function like(string $column, string $value, string $side = 'both'): self
    {
        $this->assertIdent($column);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        switch ($side) {
            case 'before':
                $pattern = '%' . $escaped;
                break;
            case 'after':
                $pattern = $escaped . '%';
                break;
            case 'both':
                $pattern = '%' . $escaped . '%';
                break;
            default:
                throw new DbException('Invalid LIKE side');
        }
        $c = clone $this;
        $c->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => 'LIKE',
            'value'    => $pattern,
            'boolean'  => 'AND',
        ];

        return $c;
    }

    /**
     * Add GROUP BY columns. Each identifier is validated immediately.
     *
     * @param string ...$columns
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertIdent($column);
        }
        $c = clone $this;
        $c->groups = array_merge($c->groups, $columns);

        return $c;
    }

    /**
     * Add a HAVING condition. The value is bound as a parameter.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or operator
     */
    public function having(string $column, string $operator, $value): self
    {
        $this->assertIdent($column);
        $operator = $this->normalizeOperator($operator);
        $c = clone $this;
        $c->havings[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => 'AND',
        ];

        return $c;
    }

    /**
     * Add an ORDER BY clause. Direction is validated to ASC or DESC only.
     *
     * @param string $column
     * @param string $direction 'ASC' (default) or 'DESC'
     *
     * @return self A cloned builder
     * @throws DbException on an invalid identifier or direction
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertIdent($column);
        $direction = strtoupper(trim($direction));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new DbException('Invalid order direction');
        }
        $c = clone $this;
        $c->orders[] = ['column' => $column, 'direction' => $direction];

        return $c;
    }

    /**
     * Set the LIMIT row count. Negative values are clamped to zero.
     *
     * @param int $count
     *
     * @return self A cloned builder
     */
    public function limit(int $count): self
    {
        $c = clone $this;
        $c->limit = max(0, $count);

        return $c;
    }

    /**
     * Set the OFFSET. Compiled with the explicit OFFSET keyword and only when a
     * LIMIT is also present. Negative values are clamped to zero.
     *
     * @param int $offset
     *
     * @return self A cloned builder
     */
    public function offset(int $offset): self
    {
        $c = clone $this;
        $c->offset = max(0, $offset);

        return $c;
    }

    /**
     * Run the compiled SELECT and return every matching row.
     *
     * @return array
     * @throws DbException
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();

        return Connection::instance()->select($sql, $bindings);
    }

    /**
     * Run the compiled SELECT with LIMIT 1 and return the first row, or null.
     *
     * @return array|null
     * @throws DbException
     */
    public function first(): ?array
    {
        [$sql, $bindings] = $this->limit(1)->compileSelect();

        return Connection::instance()->selectOne($sql, $bindings);
    }

    /**
     * Count matching rows, honoring where/join. When a GROUP BY is set, this
     * returns the number of GROUPS (the grouped query wrapped in a derived
     * table), not the number of underlying rows.
     *
     * @return int
     * @throws DbException
     */
    public function count(): int
    {
        if ($this->groups === []) {
            $sql = 'SELECT COUNT(*) AS aggregate FROM ' . $this->quoteIdent($this->table);
            $bindings = [];
            $sql .= $this->compileJoins();
            [$whereSql, $whereBindings] = $this->compileWheres();
            $sql .= $whereSql;
            $bindings = array_merge($bindings, $whereBindings);

            return (int) Connection::instance()->scalar($sql, $bindings);
        }

        // Grouped: count the number of groups by wrapping the grouped query in a
        // derived table, so count() honours GROUP BY (and HAVING) instead of
        // silently dropping them.
        $inner = 'SELECT 1 FROM ' . $this->quoteIdent($this->table);
        $bindings = [];
        $inner .= $this->compileJoins();
        [$whereSql, $whereBindings] = $this->compileWheres();
        $inner .= $whereSql;
        $bindings = array_merge($bindings, $whereBindings);
        $inner .= ' GROUP BY ' . implode(', ', array_map([$this, 'quoteIdent'], $this->groups));
        [$havingSql, $havingBindings] = $this->compileHavings();
        $inner .= $havingSql;
        $bindings = array_merge($bindings, $havingBindings);
        $sql = 'SELECT COUNT(*) AS aggregate FROM (' . $inner . ') AS oscsub';

        return (int) Connection::instance()->scalar($sql, $bindings);
    }

    /**
     * Return the value of $column from the first matching row, or null.
     *
     * @param string $column
     *
     * @return mixed
     * @throws DbException
     */
    public function value(string $column)
    {
        [$sql, $bindings] = $this->select($column)->limit(1)->compileSelect();

        return Connection::instance()->scalar($sql, $bindings);
    }

    /**
     * INSERT a single row and return the new AUTO_INCREMENT id. Columns are
     * validated identifiers; values are bound.
     *
     * @param array $data Column => value map
     *
     * @return int
     * @throws DbException when $data is empty or a column is invalid
     */
    public function insert(array $data): int
    {
        if ($data === []) {
            throw new DbException('Cannot insert an empty row');
        }
        $columns = [];
        foreach (array_keys($data) as $column) {
            $columns[] = $this->quoteIdent((string) $column);
        }
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = 'INSERT INTO ' . $this->quoteIdent($this->table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        return Connection::instance()->insertGetId($sql, array_values($data));
    }

    /**
     * UPDATE matching rows and return the affected-row count. Columns are
     * validated identifiers; values are bound. REFUSES to run without a WHERE
     * clause to prevent an accidental full-table update.
     *
     * @param array $data Column => value map
     *
     * @return int
     * @throws DbException when $data is empty or there is no WHERE clause
     */
    public function update(array $data): int
    {
        if ($data === []) {
            throw new DbException('Cannot update with an empty data set');
        }
        if ($this->wheres === []) {
            throw new DbException('Refusing to UPDATE without a WHERE clause');
        }
        $assignments = [];
        $bindings = [];
        foreach ($data as $column => $val) {
            $assignments[] = $this->quoteIdent((string) $column) . ' = ?';
            $bindings[] = $val;
        }
        $sql = 'UPDATE ' . $this->quoteIdent($this->table) . ' SET ' . implode(', ', $assignments);
        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql .= $whereSql;
        $bindings = array_merge($bindings, $whereBindings);

        return Connection::instance()->execute($sql, $bindings);
    }

    /**
     * DELETE matching rows and return the affected-row count. REFUSES to run
     * without a WHERE clause to prevent an accidental full-table delete.
     *
     * @return int
     * @throws DbException when there is no WHERE clause
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new DbException('Refusing to DELETE without a WHERE clause');
        }
        $sql = 'DELETE FROM ' . $this->quoteIdent($this->table);
        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql .= $whereSql;

        return Connection::instance()->execute($sql, $whereBindings);
    }

    /**
     * Compile the SELECT this builder represents WITHOUT executing it.
     *
     * @return string
     */
    public function toSql(): string
    {
        [$sql] = $this->compileSelect();

        return $sql;
    }

    /**
     * Return the bound parameters for the compiled SELECT, in order.
     *
     * @return array
     */
    public function getBindings(): array
    {
        [, $bindings] = $this->compileSelect();

        return $bindings;
    }

    /**
     * Shared where()/orWhere() implementation. Resolves the 2- vs 3-argument
     * form from the caller's real argument count so a literal null value is not
     * mistaken for an omitted operator.
     *
     * @param string $column
     * @param mixed  $operatorOrValue
     * @param mixed  $value
     * @param string $boolean 'AND' or 'OR'
     * @param int    $argCount func_num_args() from the public method
     *
     * @return self
     * @throws DbException
     */
    private function addWhere(string $column, $operatorOrValue, $value, string $boolean, int $argCount): self
    {
        $this->assertIdent($column);
        if ($argCount < 3 || $value === self::NO_ARG) {
            $operator = '=';
            $bound = $operatorOrValue;
        } else {
            $operator = $this->normalizeOperator((string) $operatorOrValue);
            $bound = $value;
        }
        $c = clone $this;
        $c->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $bound,
            'boolean'  => $boolean,
        ];

        return $c;
    }

    /**
     * Shared whereGroup()/orWhereGroup() implementation. Runs the callback
     * against a fresh builder for the same table and captures its where
     * descriptors as a nested, parenthesised group.
     *
     * @param callable $fn
     * @param string   $boolean 'AND' or 'OR'
     *
     * @return self
     * @throws DbException when the callback does not return a QueryBuilder
     */
    private function addGroup(callable $fn, string $boolean): self
    {
        $group = $fn(new self($this->table));
        if (!$group instanceof self) {
            throw new DbException('Group callback must return the QueryBuilder it received');
        }
        $c = clone $this;
        $c->wheres[] = ['type' => 'group', 'wheres' => $group->wheres, 'boolean' => $boolean];

        return $c;
    }

    /**
     * Shared join()/leftJoin() implementation.
     *
     * @param string $type INNER or LEFT
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     *
     * @return self
     * @throws DbException
     */
    private function addJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        $this->assertIdent($table);
        $this->assertIdent($first);
        $this->assertIdent($second);
        $operator = $this->normalizeOperator($operator);
        $c = clone $this;
        $c->joins[] = [
            'type'     => $type,
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
        ];

        return $c;
    }

    /**
     * Compile the full SELECT statement and its bindings.
     *
     * @return array [string $sql, array $bindings]
     */
    private function compileSelect(): array
    {
        $columns = $this->columns === []
            ? '*'
            : implode(', ', array_map([$this, 'quoteIdent'], $this->columns));

        $sql = 'SELECT ' . $columns . ' FROM ' . $this->quoteIdent($this->table);
        $bindings = [];

        $sql .= $this->compileJoins();

        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql .= $whereSql;
        $bindings = array_merge($bindings, $whereBindings);

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map([$this, 'quoteIdent'], $this->groups));
        }

        [$havingSql, $havingBindings] = $this->compileHavings();
        $sql .= $havingSql;
        $bindings = array_merge($bindings, $havingBindings);

        if ($this->orders !== []) {
            $parts = [];
            foreach ($this->orders as $order) {
                $parts[] = $this->quoteIdent($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        $sql .= $this->compileLimit();

        return [$sql, $bindings];
    }

    /**
     * Compile the JOIN clauses (no bindings; ON compares two columns).
     *
     * @return string
     */
    private function compileJoins(): string
    {
        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->quoteIdent($join['table'])
                . ' ON ' . $this->quoteIdent($join['first']) . ' ' . $join['operator'] . ' '
                . $this->quoteIdent($join['second']);
        }

        return $sql;
    }

    /**
     * Compile the WHERE clause and collect its bindings in order.
     *
     * @return array [string $sql, array $bindings]
     */
    private function compileWheres(): array
    {
        [$conditions, $bindings] = $this->compileWhereConditions($this->wheres);
        if ($conditions === '') {
            return ['', []];
        }

        return [' WHERE ' . $conditions, $bindings];
    }

    /**
     * Compile a list of where descriptors into a boolean expression (without the
     * leading WHERE keyword) and its bindings, in order. Recurses into nested
     * groups so a group's own AND/OR structure is preserved inside parentheses.
     *
     * @param array $wheres
     *
     * @return array [string $sql, array $bindings]
     */
    private function compileWhereConditions(array $wheres): array
    {
        if ($wheres === []) {
            return ['', []];
        }
        $sql = '';
        $bindings = [];
        foreach ($wheres as $i => $where) {
            if ($i > 0) {
                $sql .= ' ' . $where['boolean'] . ' ';
            }
            switch ($where['type']) {
                case 'basic':
                    $sql .= $this->quoteIdent($where['column']) . ' ' . $where['operator'] . ' ?';
                    $bindings[] = $where['value'];
                    break;
                case 'in':
                    $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                    $sql .= $this->quoteIdent($where['column']) . ' IN (' . $placeholders . ')';
                    $bindings = array_merge($bindings, $where['values']);
                    break;
                case 'raw':
                    $sql .= '(' . $where['sql'] . ')';
                    $bindings = array_merge($bindings, $where['params']);
                    break;
                case 'group':
                    [$innerSql, $innerBindings] = $this->compileWhereConditions($where['wheres']);
                    // An empty group emits a harmless TRUE so a surrounding AND/OR
                    // still parses.
                    $sql .= '(' . ($innerSql === '' ? '1 = 1' : $innerSql) . ')';
                    $bindings = array_merge($bindings, $innerBindings);
                    break;
            }
        }

        return [$sql, $bindings];
    }

    /**
     * Compile the HAVING clause and collect its bindings in order.
     *
     * @return array [string $sql, array $bindings]
     */
    private function compileHavings(): array
    {
        if ($this->havings === []) {
            return ['', []];
        }
        $sql = ' HAVING ';
        $bindings = [];
        foreach ($this->havings as $i => $having) {
            if ($i > 0) {
                $sql .= ' ' . $having['boolean'] . ' ';
            }
            $sql .= $this->quoteIdent($having['column']) . ' ' . $having['operator'] . ' ?';
            $bindings[] = $having['value'];
        }

        return [$sql, $bindings];
    }

    /**
     * Compile LIMIT/OFFSET as `LIMIT <count> OFFSET <offset>`, never the inverted
     * `LIMIT a,b`. OFFSET is emitted only alongside a LIMIT (MySQL requires it),
     * and both are cast to ints so no caller value is ever interpolated raw.
     *
     * @return string
     */
    private function compileLimit(): string
    {
        if ($this->limit === null) {
            return '';
        }
        $sql = ' LIMIT ' . (int) $this->limit;
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int) $this->offset;
        }

        return $sql;
    }

    /**
     * Assert that a comparison operator is on the whitelist and normalize LIKE to
     * upper case.
     *
     * @param string $operator
     *
     * @return string The canonical operator
     * @throws DbException on an unknown operator
     */
    private function normalizeOperator(string $operator): string
    {
        $candidate = strtoupper($operator) === 'LIKE' ? 'LIKE' : trim($operator);
        if (!in_array($candidate, self::OPERATORS, true)) {
            throw new DbException('Invalid operator');
        }

        return $candidate;
    }

    /**
     * Validate an identifier without producing SQL (fail-fast in clause methods).
     *
     * @param string $name
     *
     * @throws DbException on an invalid identifier
     */
    private function assertIdent(string $name): void
    {
        $this->quoteIdent($name);
    }

    /**
     * Validate and backtick-quote an identifier. Accepts a single `*`, a bare
     * `name`, or one `table.column` dotted pair; each side must match
     * ^[A-Za-z0-9_]+$ (or be `*`). Anything else throws. Embedded backticks are
     * doubled defensively even though the allowlist forbids them.
     *
     * @param string $name
     *
     * @return string The quoted identifier
     * @throws DbException on an invalid identifier
     */
    private function quoteIdent(string $name): string
    {
        $name = trim($name);
        if ($name === '*') {
            return '*';
        }
        if (strpos($name, '.') !== false) {
            $parts = explode('.', $name);
            if (count($parts) !== 2) {
                throw new DbException('Invalid identifier');
            }

            return $this->quoteSegment($parts[0]) . '.' . $this->quoteSegment($parts[1]);
        }

        return $this->quoteSegment($name);
    }

    /**
     * Validate and quote one identifier segment.
     *
     * @param string $segment
     *
     * @return string
     * @throws DbException on an invalid segment
     */
    private function quoteSegment(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '*') {
            return '*';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $segment)) {
            throw new DbException('Invalid identifier');
        }

        return '`' . str_replace('`', '``', $segment) . '`';
    }
}

/* file end: ./oc-includes/osclass/classes/database/QueryBuilder.php */
