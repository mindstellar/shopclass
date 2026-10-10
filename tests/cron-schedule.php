<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * oc-includes/osclass/cron.php: each schedule's period, the CLI cron-type match and the order
 * its jobs and hooks run in. Every job is a stand-in that notes its call.
 *
 * No database.  Usage:  php tests/cron-schedule.php
 */

namespace mindstellar\security {
    class RateLimit
    {
        public static function prune(): void
        {
            $GLOBALS['ran'][] = 'RateLimit::prune';
        }
    }

    class MessageGuard
    {
        public static function purgeExpired(): void
        {
            $GLOBALS['ran'][] = 'MessageGuard::purgeExpired';
        }
    }

    class LoginThrottle
    {
        public static function prune(): void
        {
            $GLOBALS['ran'][] = 'LoginThrottle::prune';
        }
    }
}

namespace mindstellar\model {
    class KeyValue
    {
        public function prune(int $now): void
        {
            $GLOBALS['ran'][] = 'KeyValue::prune';
        }
    }

    class ApiCredential
    {
        public function pruneRefresh(int $before): void
        {
            $GLOBALS['ran'][] = 'ApiCredential::pruneRefresh';
        }
    }
}

namespace mindstellar\user {
    class UserStore
    {
        public static function prunePendingEmails(string $before): void
        {
            $GLOBALS['ran'][] = 'UserStore::prunePendingEmails';
        }
    }
}

namespace {
    error_reporting(E_ALL);

    define('ABS_PATH', dirname(__DIR__) . '/');
    require_once __DIR__ . '/lib/harness.php';

    /** A stand-in whose every call is noted by class and method. */
    trait Noted
    {
        public static function getInstance(): static
        {
            return new static();
        }

        public function __call(string $name, array $args)
        {
            $GLOBALS['ran'][] = static::class . '::' . $name;

            return array();
        }
    }

    class Cron
    {
        use Noted;

        public function getCronByType(string $type): array
        {
            return array('d_last_exec' => 'last-' . $type, 'd_next_exec' => $GLOBALS['nextExec'] ?? '2000-01-01 00:00:00');
        }

        public function claim(string $type, string $next, string $now, string $nextExec): bool
        {
            $GLOBALS['ran'][] = 'claim ' . $type;
            $GLOBALS['periods'][$type] = strtotime($nextExec) - intdiv((int) strtotime($now), 60) * 60;

            return true;
        }
    }

    class LatestSearches
    {
        use Noted;

        public function purgeDate(string $before): void
        {
            $GLOBALS['ran'][] = 'purgeDate ' . (int) round((time() - strtotime($before)) / 60) * 60;
        }

        public function purgeNumber(int $keep): void
        {
            $GLOBALS['ran'][] = 'purgeNumber ' . $keep;
        }
    }

    class Item
    {
        use Noted;
    }

    class ItemTmpUpload
    {
        use Noted;
    }

    class Log
    {
        use Noted;
    }

    class ItemStats
    {
        use Noted;
    }

    class Sitemap
    {
        use Noted;
    }

    class Params
    {
        public static function getParam(string $name): string
        {
            return $name === 'cron-type' ? $GLOBALS['cronType'] : '';
        }
    }

    function osc_runAlert($type, $last)
    {
        $GLOBALS['ran'][] = 'alert ' . $type . ' since ' . $last;
    }

    function osc_purge_latest_searches()
    {
        return $GLOBALS['purge'];
    }

    function osc_warn_expiration()
    {
        return 0;
    }

    function osc_content_path()
    {
        return sys_get_temp_dir() . '/osc-cron-schedule-none/';
    }

    function osc_update_cat_stats()
    {
        $GLOBALS['ran'][] = 'osc_update_cat_stats';
    }

    function osc_update_location_stats($force)
    {
        $GLOBALS['ran'][] = 'osc_update_location_stats';
    }

    function osc_admin_log_retention_days()
    {
        return 0;
    }

    function osc_item_stats_retention_days()
    {
        return 0;
    }

    function osc_run_hook($name, ...$args)
    {
        $GLOBALS['ran'][] = 'hook ' . $name;
    }

    /** What one CLI run of cron.php does for this cron-type and latest-searches setting. */
    function cron_run(string $cronType, string $purge): array
    {
        $GLOBALS['ran']      = array();
        $GLOBALS['cronType'] = $cronType;
        $GLOBALS['purge']    = $purge;
        include ABS_PATH . 'oc-includes/osclass/cron.php';

        return $GLOBALS['ran'];
    }

    harness_section('the hourly schedule');
    pin('HOURLY in capitals runs the hourly jobs, alerts first, then the purge, then its hook', array(
        'claim HOURLY', 'alert HOURLY since last-HOURLY', 'purgeDate 3600', 'ItemTmpUpload::pruneBefore',
        'RateLimit::prune', 'hook cron_hourly', 'hook cron',
    ), cron_run('HOURLY', 'hour'));
    pin('a numeric setting keeps that many searches instead', array(
        'claim HOURLY', 'alert HOURLY since last-HOURLY', 'purgeNumber 1000', 'ItemTmpUpload::pruneBefore',
        'RateLimit::prune', 'hook cron_hourly', 'hook cron',
    ), cron_run('hourly', '1000'));
    pin('a daily purge is not run hourly', array('claim HOURLY', 'alert HOURLY since last-HOURLY', 'ItemTmpUpload::pruneBefore', 'RateLimit::prune', 'hook cron_hourly', 'hook cron'), cron_run('hourly', 'day'));

    harness_section('the daily schedule');
    pin('daily runs alerts, the day purge, the stats and prunes, then its hook', array(
        'claim DAILY', 'alert DAILY since last-DAILY', 'purgeDate 86400', 'osc_update_cat_stats', 'MessageGuard::purgeExpired',
        'LoginThrottle::prune', 'KeyValue::prune', 'ApiCredential::pruneRefresh', 'UserStore::prunePendingEmails',
        'Sitemap::warmCache', 'hook cron_daily', 'hook cron',
    ), cron_run('Daily', 'day'));

    harness_section('the weekly schedule');
    pin('weekly runs alerts, the week purge, the location recount, then its hook', array(
        'claim WEEKLY', 'alert WEEKLY since last-WEEKLY', 'purgeDate 604800', 'osc_update_location_stats', 'hook cron_weekly', 'hook cron',
    ), cron_run('weekly', 'week'));

    harness_section('periods and other types');
    pin('each schedule moves its next run on by its period', array('HOURLY' => 3600, 'DAILY' => 86400, 'WEEKLY' => 604800), $GLOBALS['periods']);
    pin('an unknown cron-type runs only the cron hook', array('hook cron'), cron_run('monthly', 'hour'));

    harness_section('no cron-type: only what is due');
    $claims = static fn (array $ran): array => array_values(array_filter($ran, static fn (string $r): bool => str_starts_with($r, 'claim ')));
    pin('every overdue schedule runs', array('claim HOURLY', 'claim DAILY', 'claim WEEKLY'), $claims(cron_run('', 'forever')));
    $GLOBALS['nextExec'] = date('Y-m-d H:i:s', time() + 3600);
    pin('none that is not due yet: only the cron hook', array('hook cron'), cron_run('', 'forever'));
    unset($GLOBALS['nextExec']);

    exit(harness_result());
}
