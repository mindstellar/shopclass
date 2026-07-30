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
 * Alerts DAO
 */
class Alerts extends DAO
{
    /**
     *
     * @var \Alerts
     */
    private static $instance;

    /**
     * Alerts constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_alerts');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            's_email',
            'fk_i_user_id',
            's_search',
            's_secret',
            'b_active',
            'e_type',
            'dt_date',
            'dt_unsub_date'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \Alerts
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Searches for user alerts, given an user id.
     * If user id not exist return empty array.
     *
     * @access public
     *
     * @param string $userId
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByUser($userId, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('fk_i_user_id', $userId);
        if (!$unsub) {
            // Value-less compile-time literal, so it is a whereRaw with no bound value.
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for user alerts, given an user id.
     * If user id not exist return empty array.
     *
     * @access public
     *
     * @param string $email
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByEmail($email, $unsub = false)
    {
        // s_email is VARCHAR and $email is bound as-is: a numeric-looking value
        // (e.g. '0') compares as a string, NOT as a number. Legacy escape()
        // returned a single-character numeric bare, so where('s_email', '0')
        // compiled `s_email = 0` and coerced every non-numeric email to 0,
        // matching them all. That coercion is deliberately not reproduced.
        $query = osc_db_table($this->getTableName())
            ->where('s_email', $email);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given a type.
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $type
     * @param bool   $active
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     */
    public function findByType($type, $active = false, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('e_type', $type);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }
        if ($active) {
            $query = $query->where('b_active', 1);
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given a type group by s_search.
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $type
     * @param bool   $active
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     */
    public function findByTypeGroup($type, $active = false, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('e_type', $type);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }
        if ($active) {
            $query = $query->where('b_active', 1);
        }
        $query = $query->groupBy('s_search');

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given an user and a s_search.
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $search
     * @param string $user
     * @param bool   $unsub
     *
     * @return array
     *
     * WARNIGN doble where!
     * @since  unknown
     */
    public function findBySearchAndUser($search, $user, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('fk_i_user_id', $user)
            ->where('s_search', $search);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given a type group and a s_search.
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $search
     * @param string $type
     * @param bool   $unsub
     *
     * @return array
     *
     * WARNIGN doble where!
     * @since  unknown
     */
    public function findBySearchAndType($search, $type, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('e_type', $type)
            ->where('s_search', $search);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    // a.s_email, a.fk_i_user_id @TODO

    /**
     * Searches for users, given a type group and a s_search.
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $search
     * @param string $type
     * @param bool   $active
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     */
    public function findUsersBySearchAndType($search, $type, $active = false, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('e_type', $type)
            ->where('s_search', $search);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }
        if ($active) {
            $query = $query->where('b_active', 1);
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given a type group and an user id
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param int    $userId
     * @param string $type
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     */
    public function findByUserByType($userId, $type, $unsub = false)
    {
        $query = osc_db_table($this->getTableName())
            ->where('e_type', $type)
            ->where('fk_i_user_id', $userId);
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for alerts, given a type group and an email
     * If type don't match return empty array.
     *
     * @access public
     *
     * @param string $email
     * @param string $type
     * @param bool   $unsub
     *
     * @return array
     * @since  unknown
     */
    public function findByEmailByType($email, $type, $unsub = false)
    {
        // Legacy appended the unsub clause BEFORE the e_type/s_email conditions;
        // the order is preserved but every clause is AND-joined, so it is
        // result-identical either way.
        $query = osc_db_table($this->getTableName());
        if (!$unsub) {
            $query = $query->whereRaw('dt_unsub_date IS NULL');
        }
        $query = $query
            ->where('e_type', $type)
            ->where('s_email', $email);

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Create a new alert
     *
     * @access public
     *
     * @param int    $userid
     * @param string $email
     * @param string $alert
     * @param string $secret
     * @param string $type
     *
     * @return bool on success
     * @since  unknown
     */
    public function createAlert($userid, $email, $alert, $secret, $type = 'DAILY')
    {
        $query = osc_db_table($this->getTableName())
            ->where('s_search', $alert)
            ->whereRaw('dt_unsub_date IS NULL');

        if ($userid == 0 || $userid == null) {
            $query = $query
                ->where('fk_i_user_id', 0)
                ->where('s_email', $email);
        } else {
            $query = $query->where('fk_i_user_id', $userid);
        }

        // No false-branch in the legacy body (it dereferenced get()'s result
        // directly), so a genuine query failure is left to propagate rather than
        // absorbed. The stored blob ($alert) is written verbatim; dt_date keeps
        // the legacy PHP clock (date()), which was never a MySQL NOW() sentinel.
        if (count($query->get()) === 0) {
            return osc_db_table($this->getTableName())->insert(array(
                'fk_i_user_id' => $userid,
                's_email'      => $email,
                's_search'     => $alert,
                'e_type'       => $type,
                's_secret'     => $secret,
                'dt_date'      => date('Y-m-d H:i:s'),
            ));
        }

        return false;
    }

    /**
     * Activate an alert
     *
     * @access public
     *
     * @param string $id
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  unknown
     */
    public function activate($id)
    {
        try {
            return osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->update(array('b_active' => 1));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Dectivate an alert
     *
     * @access public
     *
     * @param string $id
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  3.1
     */
    public function deactivate($id)
    {
        try {
            return osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->update(array('b_active' => 0));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Unsub from an alert
     *
     * @access public
     *
     * @param string $id
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  3.1
     */
    public function unsub($id)
    {
        // dt_unsub_date keeps the legacy PHP clock (date()); it was never a MySQL
        // NOW() sentinel here.
        try {
            return osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->update(array('dt_unsub_date' => date('Y-m-d H:i:s')));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }


    /**
     * Search alerts
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
     * @since  3.1
     */
    public function search($start = 0, $end = 10, $order_column = 'dt_date', $order_direction = 'DESC', $name = '')
    {
        // SET data, so we always return a valid object
        $alerts                  = array();
        $alerts['rows']          = 0;
        $alerts['total_results'] = 0;
        $alerts['alerts']        = array();

        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_column)) {
            $order_column = 'dt_date';
        }

        // $order_column is validated against the allowlist above. $order_direction
        // reproduces DBCommandClass::orderBy()'s own handling, quirks included:
        // 'random' becomes RAND(), a recognised-but-not-ASC/DESC direction
        // collapses to ASC, and an empty or '0' direction is appended
        // unvalidated (a '0' direction is therefore a genuine SQL syntax error,
        // not a no-op).
        $direction = (string)$order_direction;
        if (strtolower($direction) === 'random') {
            $orderSql = $order_column . ' RAND()';
        } elseif (trim($direction) !== '' && trim($direction) !== '0') {
            $orderSql = $order_column
                . (in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC');
        } else {
            $orderSql = $order_column . $direction;
        }

        // SQL_CALC_FOUND_ROWS + FOUND_ROWS() cannot be expressed through the query
        // builder, so this stays hand-written SQL with every value bound.
        $params = array();
        $sql    = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $this->getTableName();
        if ($name != '') {
            // Mirrors like()'s own escapeStr($v, true): % and _ are escaped in the
            // payload before the wildcard boundaries are added, so a literal
            // wildcard character typed by the caller stays literal.
            $escaped  = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$name);
            $sql     .= ' WHERE s_email LIKE ?';
            $params[] = '%' . $escaped . '%';
        }
        $sql .= ' ORDER BY ' . $orderSql;

        // Mirrors DBCommandClass::limit($start, $end): MySQL's two-argument LIMIT
        // reads the first number as the OFFSET and the second as the COUNT -- the
        // opposite of what the parameter names suggest. The clause is omitted when
        // $start is not numeric, and the count half only when $end is numeric > 0.
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int)$start;
            if ($end != '' && is_numeric($end) && (int)$end > 0) {
                $sql .= ', ' . (int)$end;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return $alerts;
        }

        $alerts['alerts'] = osc_db_stringify_rows($rows);

        // FOUND_ROWS() must run immediately after the SQL_CALC_FOUND_ROWS select
        // above, on the same connection, with nothing in between -- it reports on
        // whichever query last carried that hint. Both this and the COUNT(*) below
        // run with no params, which shares the singleton connection and (like the
        // legacy dao->query() path) returns plain strings.
        $data = osc_db_select_one('SELECT FOUND_ROWS() as total');
        if ($data !== null && $data['total']) {
            $alerts['total_results'] = $data['total'];
        }

        // Unconditional: this always counts the WHOLE table, ignoring the s_email
        // filter above -- that is what the legacy query did too.
        $data = osc_db_select_one('SELECT COUNT(*) as total FROM ' . $this->getTableName());
        if ($data !== null && $data['total']) {
            $alerts['rows'] = $data['total'];
        }

        return $alerts;
    }
}

/* file end: ./oc-includes/osclass/model/Alerts.php */
