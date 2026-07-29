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
 * Model database for ItemStat table
 *
 * t_item_stats holds one row per listing: running totals, 1:1 with t_item. The
 * time series lives beside it in t_item_stats_daily, one row per (day, write
 * bucket) for the whole site, because no reader ever wanted a single listing's
 * count for a single day — they either want a listing's total or the site's
 * total for a date. Keying the counters by date as well as by listing made the
 * table grow with page views instead of with listings, and made every read an
 * aggregate over a listing's whole history.
 *
 * i_bucket is picked at random per write. It exists so that concurrent views
 * across the site do not all contend on one row for the current date; reads sum
 * the buckets away.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class ItemStats extends DAO
{
    /**
     * It references to self object: ItemStats.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var ItemStats
     */
    private static $instance;

    /** Counter columns that may be incremented. */
    private const COUNTERS = array(
        'i_num_views',
        'i_num_spam',
        'i_num_repeated',
        'i_num_bad_classified',
        'i_num_offensive',
        'i_num_expired',
        'i_num_premium_views',
    );

    /**
     * The two counters driven by page traffic rather than by a person acting.
     * These are the ones the site owner can turn off: they are written on every
     * render, while the rest are moderation signals written a handful of times a
     * day and needed for the site to work.
     */
    private const TRAFFIC_COUNTERS = array('i_num_views', 'i_num_premium_views');

    /** How many rows per day the site-wide rollup spreads its writes across. */
    private const DAILY_BUCKETS = 8;

    /**
     * Set data related to t_item_stats table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_stats');
        $this->setPrimaryKey('fk_i_item_id');
        $this->setFields(array(
            'fk_i_item_id',
            'i_num_views',
            'i_num_spam',
            'i_num_repeated',
            'i_num_bad_classified',
            'i_num_offensive',
            'i_num_expired',
            'i_num_premium_views',
            'dt_date'
        ));
    }

    /**
     * It creates a new ItemStats object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return ItemStats
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Increase the stat column given column name and item id
     *
     * @access public
     *
     * @param string $column
     * @param int    $itemId
     *
     * @return bool
     * @since  unknown
     */
    public function increase($column, $itemId)
    {
        if (!in_array($column, self::COUNTERS, true)) {
            return false;
        }

        if (!is_numeric($itemId)) {
            return false;
        }

        if ($this->isDisabled($column)) {
            return true;
        }

        // $column is validated against the fixed allowlist above. dt_date is no
        // longer part of the key; it records when the listing was last active.
        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_item_id, dt_date, ' . $column . ')
                VALUES (?, CURDATE(), 1)
                ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column . ' + 1, dt_date = CURDATE()';

        try {
            osc_db_execute($sql, array($itemId));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        // Only once the listing's own row is known to have taken the increment,
        // so a count for a listing that does not exist cannot reach the chart.
        $this->increaseDaily($column, 1);

        return true;
    }

    /**
     * Increase one counter for many listings at once.
     *
     * The premium block re-counts every listing it renders on every search,
     * category and home page. One statement per listing made that the busiest
     * write on the site; this collapses a page's worth into a single multi-row
     * upsert plus a single rollup upsert, whatever the block size.
     *
     * @access public
     *
     * @param string $column
     * @param array  $itemIds
     *
     * @return bool false if the column is rejected or the statement fails
     * @since  5.3.0
     */
    public function increaseBatch($column, array $itemIds)
    {
        if (!in_array($column, self::COUNTERS, true)) {
            return false;
        }

        // Deduplicated: the same id twice in one statement would increment twice.
        $ids = array_values(array_unique(array_map('intval', array_filter($itemIds, 'is_numeric'))));
        if (!$ids) {
            return true;
        }

        if ($this->isDisabled($column)) {
            return true;
        }

        $values = implode(', ', array_fill(0, count($ids), '(?, CURDATE(), 1)'));

        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_item_id, dt_date, ' . $column . ')
                VALUES ' . $values . '
                ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column . ' + 1, dt_date = CURDATE()';

        try {
            osc_db_execute($sql, $ids);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        $this->increaseDaily($column, count($ids));

        return true;
    }

    /**
     * Add $by to today's site-wide rollup, on a randomly chosen write bucket.
     *
     * Failure is swallowed: the rollup only feeds the admin reports chart, and
     * losing a point from a graph is not worth failing a page render over.
     *
     * @param string $column already validated against self::COUNTERS
     * @param int    $by
     */
    private function increaseDaily($column, $by)
    {
        $sql = 'INSERT INTO ' . $this->dailyTableName() . ' (dt_date, i_bucket, ' . $column . ')
                VALUES (CURDATE(), ?, ?)
                ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column . ' + ?';

        $bucket = mt_rand(0, self::DAILY_BUCKETS - 1);

        try {
            osc_db_execute($sql, array($bucket, $by, $by));
        } catch (\mindstellar\database\DbException $e) {
            // ignore: a missing chart point is not worth failing a request for
        }
    }

    /**
     * Whether this counter is switched off. Only the traffic counters can be —
     * the moderation ones drive the reported-listings screen and the report
     * threshold, so turning those off would break moderation rather than save
     * space.
     *
     * @param string $column
     *
     * @return bool
     */
    private function isDisabled($column)
    {
        return in_array($column, self::TRAFFIC_COUNTERS, true) && !osc_item_views_enabled();
    }

    /**
     * The site-wide daily rollup table.
     *
     * @return string
     */
    public function dailyTableName()
    {
        return DB_TABLE_PREFIX . 't_item_stats_daily';
    }

    /**
     * Insert an empty row into table item stats
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return bool
     * @since  unknown
     */
    public function emptyRow($itemId)
    {
        return $this->insert(array(
            'fk_i_item_id' => $itemId,
            'dt_date'      => date('Y-m-d')
        ));
    }

    /**
     * Drop rollup rows older than $date. Backs the retention sweep on cron.
     *
     * @access public
     *
     * @param string $date
     *
     * @return int rows removed (0 on error)
     * @since  5.3.0
     */
    public function purgeOlderThan($date)
    {
        if (empty($date)) {
            return 0;
        }

        try {
            return (int)osc_db_table($this->dailyTableName())
                ->where('dt_date', '<', $date)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Return number of views of an item
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return int
     * @since  2.3.3
     */
    public function getViews($itemId)
    {
        if ($itemId === null) {
            // Legacy where('fk_i_item_id', null) only appends a value when it
            // is non-null, so it emits a bare "fk_i_item_id =" with no
            // right-hand side -- a SQL syntax error whose failure the caller
            // absorbed into 0. A bound null parameter is valid SQL that
            // matches zero rows and yields SUM = NULL instead, so it has to be
            // guarded explicitly rather than left to the placeholder.
            return 0;
        }

        // A primary-key lookup now that the row holds the total, but the SUM
        // stays: it is what makes a listing with no stats row return SQL NULL
        // rather than no row at all, and callers distinguish the two.
        try {
            $row = osc_db_select_one(
                'SELECT SUM(i_num_views) AS i_num_views FROM ' . $this->getTableName() . ' WHERE fk_i_item_id = ?',
                array($itemId)
            );
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        $row = osc_db_stringify_row($row);

        return $row['i_num_views'];
    }

    /**
     * Return number of views of an item
     *
     * @access public
     * @return int
     * @since  2.3.3
     */
    public function getAllViews()
    {
        try {
            $row = osc_db_select_one('SELECT SUM(i_num_views) AS i_num_views FROM ' . $this->getTableName());
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        $row = osc_db_stringify_row($row);

        return $row['i_num_views'];
    }
}

/* file end: ./oc-includes/osclass/model/ItemStats.php */
