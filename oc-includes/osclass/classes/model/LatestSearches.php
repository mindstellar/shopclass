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
 * LatestSearches DAO
 */
class LatestSearches extends DAO
{
    /**
     *
     * @var \LatestSearches
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_latest_searches');
        $array_fields = array(
            'd_date',
            's_search'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \LatestSearches
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Get last searches, given a limit.
     *
     * @access public
     *
     * @param int $limit
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function getSearches($limit = 20)
    {
        // The COUNT(...) AS alias in a comma-separated column list is rejected
        // by the builder's identifier allowlist, so this stays hand-written SQL.
        $sql = 'SELECT d_date, s_search, COUNT(s_search) as i_total FROM '
            . $this->getTableName() . ' GROUP BY s_search ORDER BY d_date DESC';

        // A non-numeric $limit leaves the clause off entirely and returns every
        // row, not zero rows -- callers relying on that unbounded behaviour exist.
        // A negative numeric $limit builds invalid SQL, which the try/catch below
        // reports as false.
        if (is_numeric($limit)) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $rows = osc_db_select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Get last searches, given since time.
     *
     * @access public
     *
     * @param int $time
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function getSearchesByDate($time = null, $limit = 20)
    {
        if ($time == null) {
            $time = time() - (7 * 24 * 3600);
        }

        // Searches on or after $time (which defaults to seven days ago), which is
        // what the method name and its $time parameter describe. An exact equality
        // here matched only rows written in the same second as the cutoff, so it
        // returned nothing for any realistic input.
        $sql = 'SELECT d_date, s_search, COUNT(s_search) as i_total FROM '
            . $this->getTableName() . ' WHERE d_date >= ? GROUP BY s_search ORDER BY d_date DESC';
        $params = array(date('Y-m-d H:i:s', $time));

        // Same is_numeric() gate as getSearches() above.
        if (is_numeric($limit)) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Purge n last searches.
     *
     * @access public
     *
     * @param int $number
     *
     * @return bool
     * @since  unknown
     */
    public function purgeNumber($number = null)
    {
        if ($number == null) {
            return false;
        }

        $sql = 'SELECT d_date FROM ' . $this->getTableName() . ' GROUP BY s_search ORDER BY d_date DESC';

        // $number is an OFFSET, not a row count: the clause is MySQL's comma form
        // ("LIMIT <offset>, <count>"), so this selects the single row $number
        // places down the list and purges from there. A non-numeric $number
        // leaves the clause off entirely, running the query unbounded; a negative
        // one is rejected rather than clamped to offset 0, since silently purging
        // from the newest row would delete far more than the caller asked for.
        if (is_numeric($number)) {
            if ((int) $number < 0) {
                throw new \mindstellar\database\DbException('Invalid limit');
            }
            $sql .= ' LIMIT ' . (int) $number . ', 1';
        }

        $rows = osc_db_select($sql);

        if (count($rows) === 0) {
            return false;
        }

        return $this->purgeDate($rows[0]['d_date']);
    }

    /**
     * Purge all searches by date.
     *
     * @access public
     *
     * @param string $date
     *
     * @return bool
     * @since  unknown
     */
    public function purgeDate($date = null)
    {
        if ($date == null) {
            return false;
        }

        try {
            return osc_db_table($this->getTableName())
                ->where('d_date', '<=', $date)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/LatestSearches.php */
