<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\listing;

use mindstellar\base\Model;
use mindstellar\cache\RedisCache;
use mindstellar\database\Db;
use mindstellar\database\DbException;

/**
 * The per-listing counters in t_item_stats: views, and the reports visitors file.
 *
 * With a Redis-protocol cache, views are added up there and written to the table by a job
 * about once a minute, so a page view costs no database write. Without one, each view is
 * written at once.
 */
final class ListingCounters extends Model
{
    protected const TABLE = 't_item_stats';

    public const FLUSH_JOB = 'listing.views_flush';

    /** Seconds views are added up before a job writes them. */
    public const FLUSH_EVERY = 60;

    /** The counters added up in the cache. */
    private const BUFFERED = array('i_num_views', 'i_num_premium_views');

    /** Report name => the counter it resets. */
    public const REPORTS = array(
        'spam'       => array('i_num_spam'),
        'duplicated' => array('i_num_repeated'),
        'bad'        => array('i_num_bad_classified'),
        'offensive'  => array('i_num_offensive'),
        'expired'    => array('i_num_expired'),
        'all'        => array('i_num_spam', 'i_num_repeated', 'i_num_bad_classified', 'i_num_offensive', 'i_num_expired'),
    );

    /**
     * Count one view. Does nothing when views are switched off.
     */
    public static function addView(int $itemId): bool
    {
        return self::buffer('i_num_views', array($itemId))
            || \ItemStats::getInstance()->increase('i_num_views', $itemId) !== false;
    }

    /**
     * Count one premium-block view for each listing shown.
     *
     * @param array<int|string> $itemIds
     */
    public static function addPremiumViews(array $itemIds): bool
    {
        return self::buffer('i_num_premium_views', $itemIds)
            || \ItemStats::getInstance()->increaseBatch('i_num_premium_views', $itemIds) !== false;
    }

    /**
     * Register the job that writes the added-up views; called from `register_jobs`.
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::FLUSH_JOB, static function (): void {
            self::flush();
        });
        osc_job_describe(self::FLUSH_JOB, __('Save listing views'), static fn (): string => '');
    }

    /**
     * Write the views added up in the cache to the table.
     *
     * @return int how many listings got views
     * @throws \RuntimeException when the table cannot be written; the counts go back to the cache
     */
    public static function flush(): int
    {
        $redis = RedisCache::site();
        if ($redis === null) {
            return 0;
        }
        $written = 0;
        foreach (self::BUFFERED as $column) {
            $counts = $redis->hashTake('views_' . $column) ?? array();
            if ($counts === array()) {
                continue;
            }
            // A listing deleted meanwhile has no stats row to add to.
            $live   = array_map('intval', array_column(
                Db::table(DB_TABLE_PREFIX . 't_item')->select('pk_i_id')->whereIn('pk_i_id', array_map('intval', array_keys($counts)))->get(),
                'pk_i_id'
            ));
            $counts = array_intersect_key($counts, array_flip($live));
            if ($counts !== array() && !\ItemStats::getInstance()->increaseBy($column, $counts)) {
                foreach ($counts as $id => $by) {
                    $redis->hashAdd('views_' . $column, (string) $id, $by, 86400);
                }

                throw new \RuntimeException('Listing views could not be saved; they are kept for the next run.');
            }
            $written += count($counts);
        }

        return $written;
    }

    /**
     * Add views up in the cache, and queue the job that writes them.
     *
     * @param array<int|string> $itemIds
     *
     * @return bool false when there is no cache to add them up in, so the caller writes them
     */
    private static function buffer(string $column, array $itemIds): bool
    {
        $redis = RedisCache::site();
        if ($redis === null || !in_array($column, self::BUFFERED, true)) {
            return false;
        }
        if (!osc_item_views_enabled()) {
            return true;
        }
        foreach (array_unique(array_map('intval', $itemIds)) as $id) {
            if ($id > 0 && $redis->hashAdd('views_' . $column, (string) $id, 1, 86400) === null) {
                return false;
            }
        }
        // One job a minute at most, whatever the traffic.
        if ($redis->counter('views_flush', 1, self::FLUSH_EVERY) === 1) {
            try {
                osc_job_enqueue(self::FLUSH_JOB, array(), array('delay' => self::FLUSH_EVERY, 'unique_key' => 'views'));
            } catch (\Throwable $e) {
                error_log('Listing views flush was not queued: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Reset one report counter ('spam', 'duplicated', 'bad', 'offensive', 'expired' or 'all').
     *
     * @return int rows changed; 0 for an unknown name or a failed query
     */
    public static function clearReport(int $itemId, string $report): int
    {
        if (!isset(self::REPORTS[$report])) {
            return 0;
        }

        try {
            return self::table()->where('fk_i_item_id', $itemId)
                ->update(array_fill_keys(self::REPORTS[$report], 0));
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * Reset every report counter and forget who reported, so old reports cannot re-trigger the auto-block.
     */
    public static function clearAllReports(int $itemId): void
    {
        self::clearReport($itemId, 'all');

        try {
            Db::table(DB_TABLE_PREFIX . 't_item_report_log')->where('fk_i_item_id', $itemId)->delete();
        } catch (DbException $e) {
            // A failed delete was never reported to the admin.
        }
    }
}
