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
 * Log DAO
 */
class Log extends DAO
{
    /**
     *
     * @var \Log
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_log');
        $array_fields = array(
            'dt_date',
            's_section',
            's_action',
            'fk_i_id',
            's_data',
            's_ip',
            's_who',
            'fk_i_who_id'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \Log
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Insert a log row.
     *
     * @access public
     *
     * @param string  $section
     * @param string  $action
     * @param integer $id
     * @param string  $data
     * @param string  $who
     * @param         $whoId
     *
     * @return boolean
     * @since  unknown
     *
     */
    public function insertLog($section, $action, $id, $data, $who, $whoId)
    {
        // Honour the admin activity-log toggle: when logging is turned off, write
        // nothing. Defaults to on, so existing installs are unaffected.
        if (!osc_is_admin_log_enabled()) {
            return false;
        }

        $ip = Params::getServerParam('REMOTE_ADDR');
        if (!$ip) {
            // No request address (e.g. a cron run): record the loopback address,
            // and expose it on $_SERVER for anything later in the same request.
            // The row now stores this value directly rather than re-reading the
            // Params snapshot, which was taken before the assignment and so left
            // s_ip empty on every cron-path log.
            $ip                     = '127.0.0.1';
            $_SERVER['REMOTE_ADDR'] = $ip;
        }

        $array_set = array(
            'dt_date'     => date('Y-m-d H:i:s'),
            's_section'   => $section,
            's_action'    => $action,
            'fk_i_id'     => $id,
            's_data'      => $data,
            's_ip'        => $ip,
            's_who'       => $who,
            'fk_i_who_id' => $whoId
        );

        try {
            osc_db_table($this->getTableName())->insert($array_set);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Paginated, filterable list for the admin activity-log datatable.
     *
     * Hand-written SELECT (SQL_CALC_FOUND_ROWS is not a column identifier the
     * query builder's allowlist will pass), with every value bound and the
     * ORDER BY column checked against the known column set. Mirrors
     * KeywordBlock::search().
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param array  $filters optional {section:string, who:string, q:string}
     *
     * @return array{rows:int,total_results:int,logs:array}
     */
    public function search($start = 0, $end = 20, $order_column = 'dt_date', $order_direction = 'DESC', $filters = array())
    {
        $result = array('rows' => 0, 'total_results' => 0, 'logs' => array());

        $sortable = array('dt_date', 's_section', 's_action', 's_who', 's_ip');
        if (!in_array((string) $order_column, $sortable, true)) {
            $order_column = 'dt_date';
        }
        $direction = in_array(strtoupper(trim((string) $order_direction)), array('ASC', 'DESC'), true)
            ? strtoupper(trim((string) $order_direction))
            : 'DESC';

        $table  = $this->getTableName();
        $params = array();
        $where  = array();

        if (!empty($filters['section'])) {
            $where[]  = 's_section = ?';
            $params[] = (string) $filters['section'];
        }
        if (!empty($filters['who'])) {
            $where[]  = 's_who = ?';
            $params[] = (string) $filters['who'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            // Same wildcard escaping the builder applies before a LIKE: a literal
            // % or _ typed by an admin stays literal rather than acting as a
            // SQL wildcard.
            $pattern  = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $filters['q']) . '%';
            $where[]  = '(s_data LIKE ? OR s_action LIKE ? OR s_ip LIKE ?)';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $sql = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $table;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // $order_column and $direction are both validated against fixed allowlists
        // above; only those literals ever reach the SQL text.
        $sql .= ' ORDER BY ' . $order_column . ' ' . $direction;
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int) $start;
            if ($end !== '' && is_numeric($end) && (int) $end > 0) {
                $sql .= ', ' . (int) $end;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return $result;
        }

        $result['logs'] = osc_db_stringify_rows($rows);

        // FOUND_ROWS() reads off the SQL_CALC_FOUND_ROWS query just run, on the
        // same connection with nothing in between.
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
     * Distinct section names present in the log, for the filter dropdown.
     *
     * @return string[]
     */
    public function distinctSections()
    {
        try {
            $rows = osc_db_select(
                'SELECT DISTINCT s_section FROM ' . $this->getTableName() . ' ORDER BY s_section ASC'
            );
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $out = array();
        foreach (osc_db_stringify_rows($rows) as $r) {
            if ($r['s_section'] !== '') {
                $out[] = $r['s_section'];
            }
        }

        return $out;
    }

    /**
     * Delete log rows older than $date (Y-m-d H:i:s). Backs the retention cron.
     *
     * @param string $date
     *
     * @return int rows removed (0 on error or nothing matched)
     */
    public function purgeOlderThan($date)
    {
        if (empty($date)) {
            return 0;
        }

        try {
            return (int) osc_db_table($this->getTableName())
                ->where('dt_date', '<', $date)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Remove every log row. Backs the admin "Clear log" action.
     *
     * @return int rows removed (0 on error)
     */
    public function clearAll()
    {
        try {
            $conn = \mindstellar\database\Connection::instance();
            $n    = (int) $conn->scalar('SELECT COUNT(*) FROM ' . $this->getTableName());
            $conn->execute('DELETE FROM ' . $this->getTableName());

            return $n;
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }
}

/* file end: ./oc-includes/osclass/model/Log.php */
