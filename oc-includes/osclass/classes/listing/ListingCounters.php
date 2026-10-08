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
use mindstellar\database\Db;
use mindstellar\database\DbException;

/**
 * The per-listing counters in t_item_stats: views, and the reports visitors file.
 */
final class ListingCounters extends Model
{
    protected const TABLE = 't_item_stats';

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
        return \ItemStats::getInstance()->increase('i_num_views', $itemId) !== false;
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
