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
 * BanRule DAO
 */
class BanRule extends DAO
{
    /**
     *
     * @var \BanRule
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_ban_rule');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            's_name',
            's_ip',
            's_email'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \BanRule
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Return list of ban rules
     *
     * @access public
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param string $name
     *
     * @return array
     * @parma  string $name
     * @since  3.1
     *
     */
    public function search($start = 0, $end = 10, $order_column = 'pk_i_id', $order_direction = 'DESC', $name = '')
    {
        // SET data, so we always return a valid object
        $rules                  = array();
        $rules['rows']          = 0;
        $rules['total_results'] = 0;
        $rules['rules']         = array();

        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_column)) {
            $order_column = 'pk_i_id';
        }

        // $order_column is validated against the allowlist above. $order_direction
        // reproduces DBCommandClass::orderBy()'s own handling, quirks included:
        // 'random' becomes RAND(), a recognised-but-not-ASC/DESC direction
        // collapses to ASC, and an empty or '0' direction is appended
        // unvalidated, exactly as the legacy builder does (a '0' direction is
        // therefore a genuine SQL syntax error, not a no-op).
        $direction = (string)$order_direction;
        if (strtolower($direction) === 'random') {
            $orderSql = $order_column . ' RAND()';
        } elseif (trim($direction) !== '' && trim($direction) !== '0') {
            $orderSql = $order_column
                . (in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC');
        } else {
            $orderSql = $order_column . $direction;
        }

        $params = array();
        $sql    = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $this->getTableName();
        if ($name != '') {
            // Mirrors like()'s own escapeStr($v, true): % and _ are escaped in the
            // payload before the wildcard boundaries are added, so a literal
            // wildcard character typed by the caller stays literal.
            $escaped  = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$name);
            $sql     .= ' WHERE s_name LIKE ?';
            $params[] = '%' . $escaped . '%';
        }
        $sql .= ' ORDER BY ' . $orderSql;

        // Mirrors DBCommandClass::limit($start, $end): the clause is omitted
        // entirely when $start is not numeric, and the row-count half is
        // appended only when $end is both numeric and greater than zero.
        // MySQL's own two-argument LIMIT reads the first number as the OFFSET
        // and the second as the COUNT -- the opposite of what the parameter
        // names here suggest.
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int)$start;
            if ($end != '' && is_numeric($end) && (int)$end > 0) {
                $sql .= ', ' . (int)$end;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return $rules;
        }

        $rules['rules'] = osc_db_stringify_rows($rows);

        // FOUND_ROWS() must run immediately after the SQL_CALC_FOUND_ROWS select
        // above, on the same connection, with nothing else in between -- it
        // reports on whichever query last ran with that hint. Both this and the
        // COUNT(*) below run through osc_db_select_one() with no params, which
        // shares the singleton connection the main select just used and (like
        // the legacy dao->query() path) returns plain strings.
        $total = osc_db_select_one('SELECT FOUND_ROWS() as total');
        if ($total !== null && $total['total']) {
            $rules['total_results'] = $total['total'];
        }

        // Unconditional: this always counts the WHOLE table, ignoring the
        // s_name filter above -- that is what the legacy query did too.
        $rowsTotal = osc_db_select_one('SELECT COUNT(*) as total FROM ' . $this->getTableName());
        if ($rowsTotal !== null && $rowsTotal['total']) {
            $rules['rows'] = $rowsTotal['total'];
        }

        return $rules;
    }

    /**
     * Return number of ban rules
     *
     * @return int
     * @since 3.1
     */
    public function countRules()
    {
        // COUNT(*) always returns exactly one row, so the legacy numRows() == 0
        // branch was unreachable; only the query-failure branch matters, and
        // this table/query can't realistically produce one.
        return (string)osc_db_scalar('SELECT COUNT(*) as i_total FROM ' . $this->getTableName());
    }
}
