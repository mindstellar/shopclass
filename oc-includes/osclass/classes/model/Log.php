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

use mindstellar\database\Db;

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
     * Set data related to t_log table
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
     * Return the shared Log model instance, creating it on first use.
     *
     * @return \Log
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Insert a log row.
     *
     * @param string     $section
     * @param string     $action
     * @param int|string $id    Primary key of the subject row
     * @param string     $data
     * @param string     $who   'admin' or 'user'
     * @param int|null   $whoId
     *
     * @return bool False when activity logging is off or the write failed
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

        // Cut each text to its column width: strict SQL mode refuses an over-long value
        // and would lose the whole row.
        $fit       = static fn ($value, int $max): ?string => $value === null ? null : mb_substr((string) $value, 0, $max, 'UTF-8');
        $array_set = array(
            'dt_date'     => date('Y-m-d H:i:s'),
            's_section'   => $fit($section, 50),
            's_action'    => $fit($action, 50),
            'fk_i_id'     => $id,
            's_data'      => $fit($data, 250),
            's_ip'        => $fit($ip, 50),
            's_who'       => $fit($who, 50),
            'fk_i_who_id' => $whoId
        );

        try {
            Db::table($this->getTableName())->insert($array_set);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Paginated, filterable list for the admin activity-log datatable.
     *
     * Hand-written SELECT, with every value bound and the ORDER BY column
     * checked against the known column set. Mirrors KeywordBlock::search().
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param array{section?:string,who?:string,q?:string} $filters
     *
     * @return array{rows:int|string,total_results:int|string,logs:array<int,array<string,string|null>>}
     *         The two counts stay int 0 on failure and are unprepared-query strings otherwise
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
            $pattern  = '%' . \mindstellar\database\QueryBuilder::escapeLike((string) $filters['q']) . '%';
            $where[]  = '(s_data LIKE ? OR s_action LIKE ? OR s_ip LIKE ?)';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $sql = 'SELECT * FROM ' . $table . $whereSql;
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
            $rows = Db::select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return $result;
        }

        $result['logs'] = Db::stringifyRows($rows);

        $counts = $this->pagedCounts(implode(' AND ', $where), $params);
        if ($counts === null) {
            return $result;
        }
        [$result['total_results'], $result['rows']] = $counts;

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
            $rows = Db::select(
                'SELECT DISTINCT s_section FROM ' . $this->getTableName() . ' ORDER BY s_section ASC'
            );
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $out = array();
        foreach (Db::stringifyRows($rows) as $r) {
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
            return (int) Db::table($this->getTableName())
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
            $conn = \mindstellar\database\Connection::getInstance();
            $n    = (int) $conn->scalar('SELECT COUNT(*) FROM ' . $this->getTableName());
            $conn->execute('DELETE FROM ' . $this->getTableName());

            return $n;
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }
}

/* file end: ./oc-includes/osclass/model/Log.php */
