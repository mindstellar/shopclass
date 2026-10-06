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

// Hourly crons
$cron = Cron::getInstance()->getCronByType('HOURLY');
if (is_array($cron)) {
    $claimed = false;
    $i_next  = strtotime($cron['d_next_exec']);

    if ((CLI && (Params::getParam('cron-type') === 'hourly')) || ((($i_now - $i_next + $shift_seconds) >= 0) && !CLI)) {
        // Only the request that moves the schedule on runs the jobs, so two at once cannot both run.
        $d_next = date('Y-m-d H:i:s', $i_now_truncated + 3600);
        $claimed = Cron::getInstance()->claim('HOURLY', (string) $cron['d_next_exec'], $d_now, $d_next);
    }
    if ($claimed) {
        osc_runAlert('HOURLY', $cron['d_last_exec']);

        // Run cron AFTER updating the next execution time to avoid double run of cron
        $purge = osc_purge_latest_searches();
        if ($purge === 'hour') {
            LatestSearches::getInstance()->purgeDate(date('Y-m-d H:i:s', time() - 3600));
        } elseif (!in_array($purge, array('forever', 'day', 'week'))) {
            LatestSearches::getInstance()->purgeNumber($purge);
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
    }
}

// Daily cron
$cron = Cron::getInstance()->getCronByType('DAILY');
if (is_array($cron)) {
    $claimed = false;
    $i_next  = strtotime($cron['d_next_exec']);

    if ((CLI && (Params::getParam('cron-type') === 'daily')) || ((($i_now - $i_next + $shift_seconds) >= 0) && !CLI)) {
        // Only the request that moves the schedule on runs the jobs, so two at once cannot both run.
        $d_next = date('Y-m-d H:i:s', $i_now_truncated + (24 * 3600));
        $claimed = Cron::getInstance()->claim('DAILY', (string) $cron['d_next_exec'], $d_now, $d_next);
    }
    if ($claimed) {
        //osc_do_auto_upgrade();

        osc_runAlert('DAILY', $cron['d_last_exec']);

        // Run cron AFTER updating the next execution time to avoid double run of cron
        $purge = osc_purge_latest_searches();
        if ($purge === 'day') {
            LatestSearches::getInstance()->purgeDate(date('Y-m-d H:i:s', time() - (24 * 3600)));
        }
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
            osc_db_table(DB_TABLE_PREFIX . 't_user_email_tmp')
                ->where('dt_date', '<', date('Y-m-d H:i:s', time() - (7 * 24 * 3600)))
                ->delete();
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
    }
}

// Weekly cron
$cron = Cron::getInstance()->getCronByType('WEEKLY');
if (is_array($cron)) {
    $claimed = false;
    $i_next  = strtotime($cron['d_next_exec']);

    if ((CLI && (Params::getParam('cron-type') === 'weekly')) || ((($i_now - $i_next + $shift_seconds) >= 0) && !CLI)) {
        // Only the request that moves the schedule on runs the jobs, so two at once cannot both run.
        $d_next = date('Y-m-d H:i:s', $i_now_truncated + (7 * 24 * 3600));
        $claimed = Cron::getInstance()->claim('WEEKLY', (string) $cron['d_next_exec'], $d_now, $d_next);
    }
    if ($claimed) {
        osc_runAlert('WEEKLY', $cron['d_last_exec']);
        // Correct drift in the listing counts of every country, region and city.
        osc_update_location_stats(true);

        // Run cron AFTER updating the next execution time to avoid double run of cron
        $purge = osc_purge_latest_searches();
        if ($purge === 'week') {
            LatestSearches::getInstance()->purgeDate(date('Y-m-d H:i:s', time() - (7 * 24 * 3600)));
        }
        osc_run_hook('cron_weekly');
    }
}

osc_run_hook('cron');
