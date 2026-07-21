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

        $this->dao->select('SQL_CALC_FOUND_ROWS *');
        $this->dao->from($this->getTableName());
        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_column)) {
            $order_column = 'pk_i_id';
        }
        $this->dao->orderBy($order_column, $order_direction);
        $this->dao->limit($start, $end);
        if ($keyword != '') {
            $this->dao->like('s_keyword', $keyword);
        }
        $rs = $this->dao->get();

        if ($rs === false) {
            return $result;
        }

        $result['keywords'] = $rs->result();

        $rsRows = $this->dao->query('SELECT FOUND_ROWS() as total');
        $data   = $rsRows->row();
        if ($data['total']) {
            $result['total_results'] = $data['total'];
        }

        $rsTotal = $this->dao->query('SELECT COUNT(*) as total FROM ' . $this->getTableName());
        $data    = $rsTotal->row();
        if ($data['total']) {
            $result['rows'] = $data['total'];
        }

        return $result;
    }

    /**
     * @return int total number of blocked keywords
     */
    public function countKeywords()
    {
        $this->dao->select('COUNT(*) as i_total');
        $this->dao->from($this->getTableName());

        $result = $this->dao->get();
        if ($result === false || $result->numRows() === 0) {
            return 0;
        }

        $row = $result->row();

        return (int)$row['i_total'];
    }
}
