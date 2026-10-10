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

$shift_seconds   = 60;
$d_now           = date('Y-m-d H:i:s');
$i_now           = strtotime($d_now);
$i_now_truncated = strtotime(date('Y-m-d H:i:00'));
if (!defined('CLI')) {
    define('CLI', PHP_SAPI === 'cli');
}

// Each schedule runs its jobs only in the request that moves it on, so two at once cannot both run.
// Each one sends its alerts, then purges latest searches when that setting names its period.
$schedules = array(
    'HOURLY' => array(3600, 'hour', static function (array $cron): void {
        $purge = osc_purge_latest_searches();
        if (!in_array($purge, array('forever', 'hour', 'day', 'week'))) {
            LatestSearches::getInstance()->purgeNumber((int) $purge);
        }

        // WARN EXPIRATION EACH HOUR (COMMENT TO DISABLE)
        // NOTE: IF THIS IS ENABLE, SAME CODE SHOULD BE DISABLE ON CRON DAILY
        if (is_numeric(osc_warn_expiration()) && osc_warn_expiration() > 0) {
            $items = Item::getInstance()->findByHourExpiration(24 * osc_warn_expiration());
            foreach ($items as $item) {
                osc_run_hook('hook_email_warn_expiration', $item);
            }
        }

        $qqprefixes = array('qqfile_*', 'auto_qqfile_*');
        foreach ($qqprefixes as $qqprefix) {
            $qqfiles = glob(osc_content_path() . 'uploads/temp/' . $qqprefix);
            if (is_array($qqfiles)) {
                foreach ($qqfiles as $qqfile) {
                    if ((time() - filemtime($qqfile)) > (2 * 3600)) {
                        @unlink($qqfile);
                    }
                }
            }
        }
        // Drop the tracking rows abandoned uploads leave in t_item_upload_tmp, on the same
        // window as the temp files swept above.
        ItemTmpUpload::getInstance()->pruneBefore(date('Y-m-d H:i:s', time() - (2 * 3600)));

        \mindstellar\security\RateLimit::prune();

        osc_run_hook('cron_hourly');
    }),
    'DAILY'  => array(24 * 3600, 'day', static function (array $cron): void {
        osc_update_cat_stats();
        \mindstellar\security\MessageGuard::purgeExpired();

        // Retention: prune admin activity-log rows past the configured window
        // (0 = keep forever), so t_log cannot grow without bound. Mirrors the
        // latest-searches purge above.
        $logRetention = osc_admin_log_retention_days();
        if ($logRetention > 0) {
            Log::getInstance()->purgeOlderThan(date('Y-m-d H:i:s', time() - ($logRetention * 24 * 3600)));
        }

        // Retention: prune the site-wide daily stats rollup past the configured
        // window (0 = keep forever, the default). The rollup is a few rows per day
        // for the whole site, so this is a knob for owners who want it rather than
        // something the schema depends on.
        $statsRetention = osc_item_stats_retention_days();
        if ($statsRetention > 0) {
            ItemStats::getInstance()->purgeOlderThan(date('Y-m-d', time() - ($statsRetention * 24 * 3600)));
        }

        // Retention: drop recorded sign-in attempts past the configured window
        // (0 = keep forever). Only the throttle's own rolling window decides
        // anything; what is left is history, and under a sustained guessing run
        // the table is the fastest-growing one in the schema.
        \mindstellar\security\LoginThrottle::prune();

        // Expired key-value rows (API Idempotency-Keys among them), and refresh tokens that can no longer be used.
        try {
            (new \mindstellar\model\KeyValue())->prune(time());
            (new \mindstellar\model\ApiCredential())->pruneRefresh(time() - (7 * 24 * 3600));
        } catch (\mindstellar\database\DbException $e) {
            error_log('Key-value and API prune failed: ' . $e->getMessage());
        }

        // Pending e-mail changes are dropped after 7 days; their confirmation link then stops working.
        try {
            \mindstellar\user\UserStore::prunePendingEmails(date('Y-m-d H:i:s', time() - (7 * 24 * 3600)));
        } catch (\mindstellar\database\DbException $e) {
            error_log('Pending e-mail change prune failed: ' . $e->getMessage());
        }

        // Pre-generate the XML sitemap into the object cache so bots never trigger
        // the (potentially heavy) location scans on the request path. Regeneration
        // is otherwise lazy-on-request; this closes that gap.
        try {
            Sitemap::getInstance()->warmCache();
        } catch (Throwable $e) {
            error_log('Sitemap cron warming failed: ' . $e->getMessage());
        }

        // WARN EXPIRATION EACH DAY (UNCOMMENT TO ENABLE)
        // NOTE: IF THIS IS ENABLE, SAME CODE SHOULD BE DISABLE ON CRON HOURLY
        /*if(is_numeric(osc_warn_expiration()) && osc_warn_expiration()>0) {
            $items = Item::getInstance()->findByDayExpiration(osc_warn_expiration());
            foreach($items as $item) {
                osc_run_hook('hook_email_warn_expiration', $item);
            }
        }*/

        osc_run_hook('cron_daily');
    }),
    'WEEKLY' => array(7 * 24 * 3600, 'week', static function (array $cron): void {
        // Correct drift in the listing counts of every country, region and city.
        osc_update_location_stats(true);
        osc_run_hook('cron_weekly');
    }),
);
foreach ($schedules as $type => [$period, $purgeKey, $jobs]) {
    $cron = Cron::getInstance()->getCronByType($type);
    if (!is_array($cron)) {
        continue;
    }
    // The CLI names a schedule to force it; with none named it runs what is due, as the web does.
    $forced = CLI ? strtolower((string) Params::getParam('cron-type')) : '';
    $due    = $forced !== ''
        ? $forced === strtolower($type)
        : ($i_now - strtotime($cron['d_next_exec']) + $shift_seconds) >= 0;
    if ($due && Cron::getInstance()->claim($type, (string) $cron['d_next_exec'], $d_now, date('Y-m-d H:i:s', $i_now_truncated + $period))) {
        osc_runAlert($type, $cron['d_last_exec']);
        if (osc_purge_latest_searches() === $purgeKey) {
            LatestSearches::getInstance()->purgeDate(date('Y-m-d H:i:s', time() - $period));
        }
        $jobs($cron);
    }
}

osc_run_hook('cron');
