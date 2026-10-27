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
 * Sort order and row window of a search.
 */
final class Ordering
{
    /** The t_item columns orderBy() accepts. */
    public const COLUMNS = array('pk_i_id', 'dt_pub_date', 'dt_mod_date', 'dt_expiration', 'i_price', 'b_premium');

    /** @var mixed */
    private $column = 'dt_pub_date';
    /** @var mixed */
    private $direction = 'DESC';
    /** @var mixed */
    private $offset = 0;
    /** @var mixed */
    private $perPage = 10;
    /** @var array<int,array{table:?string,column:string,direction:string}> $column as plain terms; set both together */
    private array $terms = array(array('table' => null, 'column' => 'dt_pub_date', 'direction' => 'DESC'));

    /**
     * Order by one column. An empty or malformed column falls back to relevance for a
     * keyword search, else the publish date.
     *
     * @param mixed       $column
     * @param mixed       $direction
     * @param string|null $table      a table qualifier, or a '%s'-style prefix format
     * @param bool        $hasPattern
     *
     * @return void
     */
    public function order($column, $direction, $table, bool $hasPattern): void
    {
        $fallback = $hasPattern ? 'relevance' : 'dt_pub_date';
        if ($column === '' || !preg_match('/^[A-Za-z0-9_.]+$/', (string)$column)) {
            $column = $fallback;
        }
        if ($table == '') {
            $this->column = $column;
        } elseif ($table === '%st_user') {
            $this->column = sprintf("ISNULL($table.$column), $table.$column", DB_TABLE_PREFIX, DB_TABLE_PREFIX);
        } else {
            $this->column = sprintf("$table.$column", DB_TABLE_PREFIX);
        }
        $this->direction = $direction;
        $this->terms     = array(self::term($table == '' ? '' : sprintf((string)$table, DB_TABLE_PREFIX), (string)$column, $direction));
    }

    /**
     * Order by several t_item columns, each with its own direction, most significant first.
     *
     * @param array<int,array{0:string,1:string}> $columns
     *
     * @return void
     * @throws \InvalidArgumentException for a column outside COLUMNS, a direction other
     *                                   than ASC or DESC, or no column
     */
    public function orderBy(array $columns): void
    {
        $terms = array();
        foreach ($columns as $pair) {
            [$column, $direction] = array_values((array)$pair) + array('', '');
            $direction            = strtoupper((string)$direction);
            if (!in_array($column, self::COLUMNS, true) || !in_array($direction, array('ASC', 'DESC'), true)) {
                throw new \InvalidArgumentException('Search::orderBy(): cannot order by ' . json_encode($pair) . '.');
            }
            $terms[] = array(DB_TABLE_PREFIX . 't_item.' . $column, $direction);
        }
        if ($terms === array()) {
            throw new \InvalidArgumentException('Search::orderBy(): no column given.');
        }
        // The direction is appended to the last term only, so the others carry their own.
        $last = array_pop($terms);
        $lead = '';
        foreach ($terms as $term) {
            $lead .= $term[0] . ' ' . $term[1] . ', ';
        }
        $this->column    = $lead . $last[0];
        $this->direction = $last[1];
        $this->terms     = array();
        foreach (array_merge($terms, array($last)) as $term) {
            $this->terms[] = self::term('', $term[0], $term[1]);
        }
    }

    /**
     * The order as plain terms, most significant first. The table is null for a bare
     * column such as `relevance`, else its name without DB_TABLE_PREFIX.
     *
     * @return array<int,array{table:?string,column:string,direction:string}>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    /**
     * @param string $table  the table, with or without DB_TABLE_PREFIX; empty when $column carries it
     * @param string $column
     * @param mixed  $direction
     *
     * @return array{table:?string,column:string,direction:string}
     */
    private static function term(string $table, string $column, $direction): array
    {
        $dot = strrpos($column, '.');
        if ($dot !== false) {
            $table  = substr($column, 0, $dot);
            $column = substr($column, $dot + 1);
        }
        if ($table !== '' && DB_TABLE_PREFIX !== '' && str_starts_with($table, DB_TABLE_PREFIX)) {
            $table = substr($table, strlen(DB_TABLE_PREFIX));
        }

        return array(
            'table'     => $table === '' ? null : $table,
            'column'    => $column,
            'direction' => strtoupper(trim((string)$direction)) === 'ASC' ? 'ASC' : 'DESC',
        );
    }

    /**
     * @param mixed $offset
     * @param mixed $perPage null keeps the page size
     *
     * @return void
     */
    public function limit($offset, $perPage = null): void
    {
        $this->offset = $offset;
        if ($perPage !== null) {
            $this->perPage = $perPage;
        }
    }

    /**
     * @param mixed $page    zero-based
     * @param mixed $perPage null keeps the page size
     *
     * @return void
     */
    public function page($page, $perPage = null): void
    {
        if ($perPage !== null) {
            $this->perPage = $perPage;
        }
        $this->offset = $this->perPage * $page;
    }

    /**
     * @param mixed $perPage
     *
     * @return void
     */
    public function setPerPage($perPage): void
    {
        $this->perPage = $perPage;
    }

    /**
     * @return mixed
     */
    public function column()
    {
        return $this->column;
    }

    /**
     * @return mixed
     */
    public function direction()
    {
        return $this->direction;
    }

    /**
     * @return mixed
     */
    public function offset()
    {
        return $this->offset;
    }

    /**
     * @return mixed
     */
    public function perPage()
    {
        return $this->perPage;
    }

    /**
     * Add ORDER BY and LIMIT to $statement.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function apply(Statement $statement): void
    {
        $column = (string)$this->column;
        if (preg_match('/^(?:[A-Za-z0-9_]*t_item\.)?i_price$/', $column)) {
            // A listing with no price goes last, whichever way the prices run.
            $column = sprintf('ISNULL(%1$s), %1$s', $column);
        }
        $statement->orderBy($column, (string)$this->direction);
        if ($this->column === 'relevance') {
            // Many listings share a score; the id keeps their order the same on every page.
            $statement->orderBy(DB_TABLE_PREFIX . 't_item.pk_i_id', (string)$this->direction);
        }
        $statement->limit($this->offset, $this->perPage);
    }
}
