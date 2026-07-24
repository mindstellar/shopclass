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
     * @return \Stats
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
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
     * @param string $date
     * @param array  $map
     *
     * @return array
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
     * @param        $from_date
     * @param string $date
     *
     * @return array
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
            . ' GROUP BY ' . $groupBy
            . ' ORDER BY dt_reg_date DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * @return array
     */
    public function users_by_country()
    {
        return $this->rows(
            'SELECT s_country, COUNT(pk_i_id) as num FROM ' . DB_TABLE_PREFIX . 't_user GROUP BY s_country'
        );
    }

    /**
     * @return array
     */
    public function users_by_region()
    {
        return $this->rows(
            'SELECT s_region, COUNT(pk_i_id) as num FROM ' . DB_TABLE_PREFIX . 't_user GROUP BY s_region'
        );
    }

    /**
     * @return array
     */
    public function items_by_user()
    {
        return $this->rows(
            'SELECT AVG( num ) as avg FROM (SELECT COUNT( pk_i_id ) AS num FROM ' . DB_TABLE_PREFIX
            . 't_item GROUP BY s_contact_email ) AS dummy_table'
        );
    }

    /**
     * @return array
     */
    public function latest_users()
    {
        return $this->rows(
            'SELECT * FROM ' . DB_TABLE_PREFIX . 't_user ORDER BY dt_reg_date DESC LIMIT 5'
        );
    }

    /**
     * @param        $from_date
     * @param string $date
     *
     * @return array
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
            . ' GROUP BY ' . $groupBy
            . ' ORDER BY dt_pub_date DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * @return array
     */
    public function latest_items()
    {
        $sql = 'SELECT l.*, i.*, d.*'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item i, ' . DB_TABLE_PREFIX . 't_item_location l, '
            . DB_TABLE_PREFIX . 't_item_description d'
            . ' WHERE l.fk_i_item_id = i.pk_i_id AND d.fk_i_item_id = i.pk_i_id'
            . ' GROUP BY i.pk_i_id'
            . ' ORDER BY dt_pub_date DESC'
            . ' LIMIT 5';

        return $this->rows($sql);
    }

    /**
     * @param        $from_date
     * @param string $date
     *
     * @return array
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
            . ' GROUP BY ' . $groupBy
            . ' ORDER BY dt_pub_date DESC';

        return $this->rows($sql, array($from_date));
    }

    /**
     * @return array|false
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
     * @param        $from_date
     * @param string $date
     *
     * @return array
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

        $sql = 'SELECT ' . $dDate . ' as d_date, ' . $sums
            . ' FROM ' . DB_TABLE_PREFIX . 't_item_stats'
            . ' WHERE dt_date >= ?'
            . ' GROUP BY ' . $groupBy;

        return $this->rows($sql, array($from_date));
    }

    /**
     * @param        $from_date
     * @param string $date
     *
     * @return array
     */
    public function new_alerts_count($from_date, $date = 'day')
    {
        return $this->rows($this->alertsSql($date, 'COUNT(s_email)'), array($from_date));
    }

    /**
     * @param        $from_date
     * @param string $date
     *
     * @return array
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
            . ' GROUP BY ' . $groupBy
            . ' ORDER BY dt_date ASC';
    }
}
