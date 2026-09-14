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
 * Aggregate counts backing the admin statistics screens.
 *
 * Every query is a read-only aggregate, so these run through the parameterized
 * osc_db_select() helper rather than a query builder: the grouped date buckets
 * (WEEK/MONTHNAME/DATE) and the derived table in items_by_user() are not
 * expressible through QueryBuilder's identifier allowlist. Only $from_date
 * varies at runtime and it is bound, never interpolated.
 */
class Stats
{
    /**
     *
     * @var \Stats
     */
    private static $instance;

    /**
     * The shared Stats instance, created on first call.
     *
     * @return \Stats
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Pick the date-bucket expressions for the requested granularity.
     *
     * $map holds the verbatim SQL fragments per granularity as
     * array('week' => array(<d_date expr>, <group by expr>), 'month' => ..., 'day' => ...).
     * They differ between callers -- some bucket a day as DATE(), others as DAY()
     * or the bare column -- so each caller supplies its own rather than sharing a
     * single definition.
     *
     * @param string                                 $date Bucket granularity: 'day' | 'week' | 'month'
     * @param array<string,array{0:string,1:string}> $map
     *
     * @return array{0:string,1:string} the SELECT expression and the GROUP BY expression
     */
    private function bucket($date, array $map)
    {
        if ($date === 'week') {
            return $map['week'];
        }

        if ($date === 'month') {
            return $map['month'];
        }

        return $map['day'];
    }

    /**
     * Run an aggregate query, returning legacy string-typed rows.
     *
     * The driver hands back native ints for integer columns while the previous
     * query layer returned strings; the views compare and print these loosely, so
     * rows are stringified to keep that shape. A failed query yields $fallback,
     * matching what the previous layer returned when it could not run.
     *
     * @param string $sql
     * @param array  $params
     * @param mixed  $fallback
     *
     * @return mixed
     */
    private function rows($sql, array $params = array(), $fallback = array())
    {
        try {
            return osc_db_stringify_rows(osc_db_select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return $fallback;
        }
    }

    /**
     * Registrations per bucket since $from_date, newest bucket first.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_users_count($from_date, $date = 'day')
    {
        list($dDate, $groupBy) = $this->bucket($date, array(
            'week'  => array('WEEK(dt_reg_date)', 'WEEK(dt_reg_date)'),
            'month' => array('MONTHNAME(dt_reg_date)', 'MONTH(dt_reg_date)'),
            'day'   => array('DATE(dt_reg_date)', 'DAY(dt_reg_date)'),
        ));

        $sql = 'SELECT ' . $dDate . ' as d_date, COUNT(pk_i_id) as num'
            . ' FROM ' . DB_TABLE_PREFIX . 't_user'
            . ' WHERE dt_reg_date >= ?'
            . ' GROUP BY ' . $groupBy . ', ' . $dDate
            . ' ORDER BY MAX(dt_reg_date) DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * Registered users per country.
     *
     * @return array<int,array<string,string>>
     */
    public function users_by_country()
    {
        return $this->rows(
            'SELECT s_country, COUNT(pk_i_id) as num FROM ' . DB_TABLE_PREFIX . 't_user GROUP BY s_country'
        );
    }

    /**
     * Registered users per region.
     *
     * @return array<int,array<string,string>>
     */
    public function users_by_region()
    {
        return $this->rows(
            'SELECT s_region, COUNT(pk_i_id) as num FROM ' . DB_TABLE_PREFIX . 't_user GROUP BY s_region'
        );
    }

    /**
     * The average number of listings per contact email, as a single row.
     *
     * @return array<int,array<string,string>>
     */
    public function items_by_user()
    {
        return $this->rows(
            'SELECT AVG( num ) as avg FROM (SELECT COUNT( pk_i_id ) AS num FROM ' . DB_TABLE_PREFIX
            . 't_item GROUP BY s_contact_email ) AS dummy_table'
        );
    }

    /**
     * The five most recently registered users.
     *
     * @return array<int,array<string,string>>
     */
    public function latest_users()
    {
        return $this->rows(
            'SELECT * FROM ' . DB_TABLE_PREFIX . 't_user ORDER BY dt_reg_date DESC LIMIT 5'
        );
    }

    /**
     * Published listings per bucket since $from_date, newest bucket first.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_items_count($from_date, $date = 'day')
    {
        list($dDate, $groupBy) = $this->bucket($date, array(
            'week'  => array('WEEK(dt_pub_date)', 'WEEK(dt_pub_date)'),
            'month' => array('MONTHNAME(dt_pub_date)', 'MONTH(dt_pub_date)'),
            'day'   => array('DATE(dt_pub_date)', 'DAY(dt_pub_date)'),
        ));

        $sql = 'SELECT ' . $dDate . ' as d_date, COUNT(pk_i_id) as num'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item'
            . ' WHERE dt_pub_date >= ?'
            . ' GROUP BY ' . $groupBy . ', ' . $dDate
            . ' ORDER BY MAX(dt_pub_date) DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * The five most recently published listings, with location and description.
     *
     * @return array<int,array<string,string>>
     */
    public function latest_items()
    {
        // One description row per listing is chosen in the WHERE clause rather than
        // collapsed afterwards: t_item_description is keyed on (item, locale), so
        // GROUP BY i.pk_i_id left its columns undetermined and the whole query was
        // rejected under ONLY_FULL_GROUP_BY.
        $sql = 'SELECT l.*, i.*, d.*'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item i'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item_location l ON l.fk_i_item_id = i.pk_i_id'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item_description d ON d.fk_i_item_id = i.pk_i_id'
            . ' WHERE d.fk_c_locale_code = (SELECT MIN(d2.fk_c_locale_code) FROM '
            . DB_TABLE_PREFIX . 't_item_description d2 WHERE d2.fk_i_item_id = i.pk_i_id)'
            . ' ORDER BY i.dt_pub_date DESC'
            . ' LIMIT 5';

        return $this->rows($sql);
    }

    /**
     * Comments per bucket since $from_date, newest bucket first.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_comments_count($from_date, $date = 'day')
    {
        list($dDate, $groupBy) = $this->bucket($date, array(
            'week'  => array('WEEK(dt_pub_date)', 'WEEK(dt_pub_date)'),
            'month' => array('MONTH(dt_pub_date)', 'MONTH(dt_pub_date)'),
            'day'   => array('DAY(dt_pub_date)', 'DAY(dt_pub_date)'),
        ));

        $sql = 'SELECT ' . $dDate . ' as d_date, COUNT(pk_i_id) as num'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item_comment'
            . ' WHERE dt_pub_date >= ?'
            . ' GROUP BY ' . $groupBy . ', ' . $dDate
            . ' ORDER BY MAX(dt_pub_date) DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * The five most recent comments, with their listing.
     *
     * @return array<int,array<string,string>>|false false when the query fails
     */
    public function latest_comments()
    {
        $sql = 'SELECT i.*, c.*'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item i, ' . DB_TABLE_PREFIX . 't_item_comment c'
            . ' WHERE c.fk_i_item_id = i.pk_i_id'
            . ' ORDER BY c.dt_pub_date DESC'
            . ' LIMIT 5';

        return $this->rows($sql, array(), false);
    }

    /**
     * Site-wide view and report totals per bucket since $from_date.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_reports_count($from_date, $date = 'day')
    {
        $sums = 'SUM(i_num_views) as views, SUM(i_num_spam) as spam, SUM(i_num_repeated) as repeated,'
            . ' SUM(i_num_bad_classified) as bad_classified, SUM(i_num_offensive) as offensive,'
            . ' SUM(i_num_expired) as expired';

        list($dDate, $groupBy) = $this->bucket($date, array(
            'week'  => array('WEEK(dt_date)', 'WEEK(dt_date)'),
            'month' => array('MONTHNAME(dt_date)', 'MONTH(dt_date)'),
            'day'   => array('dt_date', 'DAY(dt_date)'),
        ));

        // The site-wide daily rollup rather than the per-listing table: this
        // screen never wanted one listing's history, only the site's, and holding
        // the date dimension per listing is what made that table grow with page
        // views. Same aggregates over a few rows per day instead of one per
        // listing per day.
        $sql = 'SELECT ' . $dDate . ' as d_date, ' . $sums
            . ' FROM ' . DB_TABLE_PREFIX . 't_item_stats_daily'
            . ' WHERE dt_date >= ?'
            . ' GROUP BY ' . $groupBy . ', ' . $dDate;

        return $this->rows($sql, array($from_date));
    }

    /**
     * Alerts created per bucket since $from_date.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_alerts_count($from_date, $date = 'day')
    {
        return $this->rows($this->alertsSql($date, 'COUNT(s_email)'), array($from_date));
    }

    /**
     * Distinct alert subscribers per bucket since $from_date.
     *
     * @param string $from_date Inclusive lower bound, as a SQL datetime
     * @param string $date      Bucket granularity: 'day' | 'week' | 'month'
     *
     * @return array<int,array<string,string>>
     */
    public function new_subscribers_count($from_date, $date = 'day')
    {
        return $this->rows($this->alertsSql($date, 'COUNT(DISTINCT s_email)'), array($from_date));
    }

    /**
     * Alert counts and subscriber counts differ only in their aggregate.
     *
     * @param string $date
     * @param string $aggregate
     *
     * @return string
     */
    private function alertsSql($date, $aggregate)
    {
        list($dDate, $groupBy) = $this->bucket($date, array(
            'week'  => array('WEEK(dt_date)', 'WEEK(dt_date)'),
            'month' => array('MONTHNAME(dt_date)', 'MONTH(dt_date)'),
            'day'   => array('DATE(dt_date)', 'DAY(dt_date)'),
        ));

        return 'SELECT ' . $dDate . ' as d_date, ' . $aggregate . ' as num'
            . ' FROM ' . DB_TABLE_PREFIX . 't_alerts'
            . ' WHERE dt_date >= ? AND dt_unsub_date IS NULL'
            . ' GROUP BY ' . $groupBy . ', ' . $dDate
            . ' ORDER BY MAX(dt_date) ASC';
    }
}
