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

        // Legacy dao->limit($limit) only appends a clause when is_numeric($limit)
        // is true; a non-numeric $limit leaves the whole clause off (unbounded),
        // not zero rows. A negative numeric $limit compiles an invalid clause,
        // which the try/catch below turns into the same false the legacy
        // get()-returned-false branch produced.
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

        // where('d_date', ...) is an EXACT equality, not a range -- kept as-is.
        $sql = 'SELECT d_date, s_search, COUNT(s_search) as i_total FROM '
            . $this->getTableName() . ' WHERE d_date = ? GROUP BY s_search ORDER BY d_date DESC';
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

        // Legacy $this->dao->limit($number, 1) compiles the literal clause
        // "LIMIT $number, 1": DBCommandClass's own aLimit/aOffset names are
        // misleading -- with two arguments the FIRST becomes the emitted
        // clause's offset and the SECOND its row count (MySQL's comma form of
        // LIMIT is offset-then-count), so $number is the OFFSET here despite
        // its name. A non-numeric $number disables the whole clause (legacy's
        // is_numeric() gate), running the query unbounded; a negative numeric
        // $number instead compiles an invalid clause. Legacy's next line reads
        // ->row() off that failed result before its own false-check and
        // crashes with an uncaught Error, so a negative $number here throws
        // rather than being silently clamped to offset 0 by the new offset().
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
