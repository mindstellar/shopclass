<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * KeywordBlock DAO — the operator-managed keyword blocklist consumed by
 * ItemSpamFilter. Table-backed and admin-managed in the same shape as BanRule.
 */
class KeywordBlock extends DAO
{
    /** @var KeywordBlock */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_keyword_block');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array(
            'pk_i_id',
            's_keyword',
            's_scope',
            'b_substring',
            'dt_date',
        ));
    }

    /**
     * @return KeywordBlock
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Paginated list for the admin datatable.
     *
     * The main listing can't go through the query builder: SQL_CALC_FOUND_ROWS
     * isn't a column identifier the builder's allowlist will pass, so the whole
     * SELECT is hand-written with every value bound and every identifier either
     * a literal or validated a few lines above. $start/$end are kept in the
     * exact "LIMIT $start, $end" text legacy produced (MySQL reads that
     * two-argument form as OFFSET=$start, ROW_COUNT=$end) rather than
     * reinterpreted through the builder's LIMIT/OFFSET, so a non-numeric $start
     * still disables the clause entirely, same as before.
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param string $keyword optional keyword LIKE filter
     *
     * @return array{rows:int,total_results:int,keywords:array}
     */
    public function search($start = 0, $end = 10, $order_column = 'pk_i_id', $order_direction = 'DESC', $keyword = '')
    {
        $result                  = array();
        $result['rows']          = 0;
        $result['total_results'] = 0;
        $result['keywords']      = array();

        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_column)) {
            $order_column = 'pk_i_id';
        }

        // Mirrors DBCommandClass::orderBy(): 'random' becomes RAND(), anything
        // else is checked against ASC/DESC (case-insensitive) before being
        // placed in the SQL text; only $order_column above and this literal
        // ASC/DESC/RAND() ever reach the query as raw SQL, never $order_direction
        // itself unless it already matched the allowlist.
        $direction = (string)$order_direction;
        if (strtolower($direction) === 'random') {
            $orderSql = $order_column . ' RAND()';
        } elseif (trim($direction) !== '' && trim($direction) !== '0') {
            $orderSql = $order_column
                . (in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC');
        } else {
            // The legacy branch tested trim($direction) for truthiness, so the
            // string '0' fell through unvalidated and was concatenated straight
            // onto the column, producing an invalid identifier and a failed
            // query. Reproduced rather than corrected: callers read the empty
            // result that failure produces.
            $orderSql = $order_column . $direction;
        }

        $table  = $this->getTableName();
        $params = array();
        $sql    = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $table;
        if ($keyword != '') {
            // Same wildcard escaping DBCommandClass::escapeStr($v, true) applied
            // before the legacy LIKE: a literal % or _ typed by an admin stays
            // literal rather than acting as a SQL wildcard.
            $pattern  = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $keyword) . '%';
            $sql     .= ' WHERE s_keyword LIKE ?';
            $params[] = $pattern;
        }
        $sql .= ' ORDER BY ' . $orderSql;
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int)$start;
            if ($end != '' && is_numeric($end) && (int)$end > 0) {
                $sql .= ', ' . (int)$end;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return $result;
        }

        $result['keywords'] = osc_db_stringify_rows($rows);

        // FOUND_ROWS() reads off the SQL_CALC_FOUND_ROWS query just run above; it
        // needs no value of its own and must execute on the same connection with
        // nothing in between, which holds here since Connection shares the
        // singleton mysqli handle $this->dao also uses.
        $total = osc_db_scalar('SELECT FOUND_ROWS() as total');
        if ($total) {
            $result['total_results'] = $total;
        }

        // $table is fixed in the constructor, never runtime input.
        $rowsTotal = osc_db_scalar('SELECT COUNT(*) as total FROM ' . $table);
        if ($rowsTotal) {
            $result['rows'] = $rowsTotal;
        }

        return $result;
    }

    /**
     * @return int total number of blocked keywords
     */
    public function countKeywords()
    {
        try {
            return osc_db_table($this->getTableName())->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }
}
