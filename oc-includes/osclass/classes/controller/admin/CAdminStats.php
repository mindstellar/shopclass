<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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
 * Class CAdminStats
 */
class CAdminStats extends AdminSecBaseModel
{
    //specific for this class
    /**
     * Let plugins hook the stats section before anything is dispatched.
     */
    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        osc_run_hook('init_admin_stats');
    }

    //Business Layer...

    /**
     * Draw the requested stats report: listing reports, comments, items or users.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        $period = self::period(Params::getParam('type_stat'));
        $stats  = Stats::getInstance();
        switch ($this->action) {
            case 'reports':
                $fields  = array('views', 'spam', 'repeated', 'bad_classified', 'offensive', 'expired');
                $reports = self::buckets($period, array_fill_keys($fields, 0));
                $max     = array('views' => 0, 'other' => 0);
                foreach ($stats->new_reports_count(self::since($period, 'Y-m-d'), $period) as $report) {
                    foreach ($fields as $field) {
                        $reports[$report['d_date']][$field] = $report[$field];
                        $kind                              = $field === 'views' ? 'views' : 'other';
                        if ($report[$field] > $max[$kind]) {
                            $max[$kind] = $report[$field];
                        }
                    }
                }
                $this->_exportVariableToView('reports', $reports);
                $this->_exportVariableToView('max', $max);
                $this->doView('stats/reports.php');
                break;
            case 'comments':
                [$comments, $max] = self::fill(self::buckets($period, 0), $stats->new_comments_count(self::since($period, 'Y-m-d H:i:s'), $period));
                $this->_exportVariableToView('comments', $comments);
                $this->_exportVariableToView('latest_comments', $stats->latest_comments());
                $this->_exportVariableToView('max', $max);
                $this->doView('stats/comments.php');
                break;
            default:
            case 'items':
                [$items, $max] = self::fill(self::buckets($period, 0), $stats->new_items_count(self::since($period, 'Y-m-d H:i:s'), $period));
                $reports       = self::buckets($period, array('views' => 0));
                $max_views     = 0;
                foreach ($stats->new_reports_count(self::since($period, 'Y-m-d'), $period) as $report) {
                    $reports[$report['d_date']]['views'] = $report['views'];
                    if ($report['views'] > $max_views) {
                        $max_views = $report['views'];
                    }
                }
                [$alerts, $max_alerts]    = self::fill(self::buckets($period, 0), $stats->new_alerts_count(self::since($period, 'Y-m-d H:i:s'), $period));
                [$subscribers, $max_subs] = self::fill(self::buckets($period, 0), $stats->new_subscribers_count(self::since($period, 'Y-m-d'), $period));

                $this->_exportVariableToView('reports', $reports);
                $this->_exportVariableToView('items', $items);
                $this->_exportVariableToView('latest_items', $stats->latest_items());
                $this->_exportVariableToView('max', $max);
                $this->_exportVariableToView('max_views', $max_views);

                $this->_exportVariableToView('subscribers', $subscribers);
                $this->_exportVariableToView('alerts', $alerts);
                $this->_exportVariableToView('max_alerts', $max_alerts);
                $this->_exportVariableToView('max_subs', $max_subs);

                $this->doView('stats/items.php');
                break;
            case 'users':
                [$users, $max] = self::fill(self::buckets($period, 0), $stats->new_users_count(self::since($period, 'Y-m-d H:i:s'), $period));
                $item          = $stats->items_by_user();
                $this->_exportVariableToView('users_by_country', $stats->users_by_country());
                $this->_exportVariableToView('users_by_region', $stats->users_by_region());
                $this->_exportVariableToView(
                    'item',
                    (!isset($item[0]['avg']) || !is_numeric($item[0]['avg'])) ? 0 : $item[0]['avg']
                );
                $this->_exportVariableToView('latest_users', $stats->latest_users());
                $this->_exportVariableToView('users', $users);
                $this->_exportVariableToView('max', $max);
                $this->doView('stats/users.php');
                break;
        }
    }

    /**
     * The chart's period: 'week', 'month', or 'day' for anything else.
     */
    private static function period(mixed $asked): string
    {
        return $asked === 'week' || $asked === 'month' ? $asked : 'day';
    }

    /**
     * The first date the chart reads: 70 days, 10 months or 10 days back.
     */
    private static function since(string $period, string $format, ?int $now = null): string
    {
        $now ??= time();
        [$m, $d, $y] = array((int) date('m', $now), (int) date('d', $now), (int) date('Y', $now));

        return date($format, match ($period) {
            'week'  => mktime(0, 0, 0, $m, $d - 70, $y),
            'month' => mktime(0, 0, 0, $m - 10, $d, $y),
            default => mktime(0, 0, 0, $m, $d - 10, $y),
        });
    }

    /**
     * The chart's 11 points, oldest first, each set to $zero. A week is keyed by its number,
     * a month by its name and a day by its date, as the stats queries key their rows.
     *
     * @return array<int|string,mixed>
     */
    private static function buckets(string $period, mixed $zero, ?int $now = null): array
    {
        $now ??= time();
        [$m, $d, $y] = array((int) date('m', $now), (int) date('d', $now), (int) date('Y', $now));
        $points      = array();
        for ($k = 10; $k >= 0; $k--) {
            $key          = match ($period) {
                'week'  => (int) date('W', mktime(0, 0, 0, $m, $d, $y)) - $k,
                'month' => date('F', mktime(0, 0, 0, $m - $k, $d, $y)),
                default => date('Y-m-d', mktime(0, 0, 0, $m, $d - $k, $y)),
            };
            $points[$key] = $zero;
        }

        return $points;
    }

    /**
     * The points with each row's count on its date, and the largest count.
     *
     * @param array<int|string,mixed>          $points from buckets()
     * @param array<int,array<string,mixed>> $rows   with d_date and num
     *
     * @return array{0:array<int|string,mixed>,1:mixed}
     */
    private static function fill(array $points, array $rows): array
    {
        $max = 0;
        foreach ($rows as $row) {
            $points[$row['d_date']] = $row['num'];
            if ($row['num'] > $max) {
                $max = $row['num'];
            }
        }

        return array($points, $max);
    }

}

/* file end: ./oc-admin/CAdminStats.php */
