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

/**
 * Aggregate counts backing the admin statistics screens. The SQL lives in
 * mindstellar\stats\StatsQuery.
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
     * @var \mindstellar\stats\StatsQuery|null
     */
    private $query;

    /**
     * @return \mindstellar\stats\StatsQuery
     */
    private function query()
    {
        return $this->query ??= new \mindstellar\stats\StatsQuery();
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
        return $this->query()->newUsers($from_date, $date);
    }

    /**
     * Registered users per country.
     *
     * @return array<int,array<string,string>>
     */
    public function users_by_country()
    {
        return $this->query()->usersByCountry();
    }

    /**
     * Registered users per region.
     *
     * @return array<int,array<string,string>>
     */
    public function users_by_region()
    {
        return $this->query()->usersByRegion();
    }

    /**
     * The average number of listings per contact email, as a single row.
     *
     * @return array<int,array<string,string>>
     */
    public function items_by_user()
    {
        return $this->query()->itemsPerContact();
    }

    /**
     * The five most recently registered users.
     *
     * @return array<int,array<string,string>>
     */
    public function latest_users()
    {
        return $this->query()->latestUsers();
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
        return $this->query()->newItems($from_date, $date);
    }

    /**
     * The five most recently published listings, with location and description.
     *
     * @return array<int,array<string,string>>
     */
    public function latest_items()
    {
        return $this->query()->latestItems();
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
        return $this->query()->newComments($from_date, $date);
    }

    /**
     * The five most recent comments, with their listing.
     *
     * @return array<int,array<string,string>>|false false when the query fails
     */
    public function latest_comments()
    {
        return $this->query()->latestComments();
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
        return $this->query()->newReports($from_date, $date);
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
        return $this->query()->newAlerts($from_date, $date);
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
        return $this->query()->newSubscribers($from_date, $date);
    }
}
