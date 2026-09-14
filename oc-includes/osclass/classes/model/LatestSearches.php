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
     * Set data related to t_latest_searches table
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
     * Return the shared LatestSearches model instance, creating it on first use.
     *
     * @return \LatestSearches
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get last searches, given a limit.
     *
     * @param int $limit A non-numeric value returns every row
     *
     * @return array<int,array{d_date:string,s_search:string,i_total:string}>|false False on a query failure
     */
    public function getSearches($limit = 20)
    {
        // The COUNT(...) AS alias in a comma-separated column list is rejected
        // by the builder's identifier allowlist, so this stays hand-written SQL.
        // d_date is the group's most recent hit rather than an arbitrary member's:
        // a bare d_date beside GROUP BY s_search is rejected under ONLY_FULL_GROUP_BY.
        $sql = 'SELECT MAX(d_date) AS d_date, s_search, COUNT(s_search) as i_total FROM '
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
     * @param int|null $time Unix timestamp; null means seven days ago
     * @param int      $limit A non-numeric value returns every row
     *
     * @return array<int,array{d_date:string,s_search:string,i_total:string}>|false False on a query failure
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
        $sql = 'SELECT MAX(d_date) AS d_date, s_search, COUNT(s_search) as i_total FROM '
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
     * @param int|null $number Offset of the newest search to keep; null is a no-op
     *
     * @return int|false Rows deleted, or false when there is nothing to purge
     * @throws \mindstellar\database\DbException on a negative $number or a query failure
     */
    public function purgeNumber($number = null)
    {
        if ($number == null) {
            return false;
        }

        $sql = 'SELECT MAX(d_date) AS d_date FROM ' . $this->getTableName()
            . ' GROUP BY s_search ORDER BY d_date DESC';

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
     * @param string|null $date 'Y-m-d H:i:s'; null is a no-op
     *
     * @return int|false Rows deleted, or false on a no-op or a query failure
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
