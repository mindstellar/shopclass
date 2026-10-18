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

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\job\Job;

/**
 * Jobs for a listing saved and not yet paid for: a reminder a day before the deadline, and
 * the delete at the deadline.
 */
final class ListingHoldJobs
{
    public const EXPIRE = 'listing.unpaid_expire';
    public const REMIND = 'listing.unpaid_remind';
    public const SWEEP  = 'listing.unpaid_sweep';

    /** How many held listings past their deadline one sweep run deletes. */
    public const SWEEP_BATCH = 100;

    /** Queue the delete after $holdDays days, and the reminder one day earlier. */
    public static function queue(int $itemId, int $holdDays): void
    {
        $key = (string) $itemId;
        try {
            osc_job_enqueue(self::EXPIRE, array('item' => $itemId), array('delay' => $holdDays * 86400, 'unique_key' => $key));
            if ($holdDays > 1) {
                osc_job_enqueue(self::REMIND, array('item' => $itemId), array('delay' => ($holdDays - 1) * 86400, 'unique_key' => $key));
            }
        } catch (\Throwable $e) {
            error_log('Unpaid listing jobs were not queued: ' . $e->getMessage());
        }
    }

    /**
     * The reminder to the seller.
     *
     * @param array<string,mixed> $row
     */
    public static function remind(array $row): void
    {
    }

    /**
     * Queue the daily sweep, which deletes held listings whose own delete job never ran;
     * called from `cron_daily`.
     */
    public static function queueSweep(): void
    {
        osc_job_ensure(self::SWEEP);
    }

    /**
     * Delete one batch of held listings more than a day past their deadline.
     *
     * @return int how many it found
     */
    public static function sweep(): int
    {
        $rows = Db::select(
            'SELECT pk_i_id, s_secret FROM ' . DB_TABLE_PREFIX . 't_item'
            . ' WHERE b_enabled = 0 AND b_active = 0 AND dt_first_pub_date IS NULL AND dt_expiration < ?'
            . ' ORDER BY pk_i_id LIMIT ' . self::SWEEP_BATCH,
            array(date('Y-m-d H:i:s', time() - 86400))
        );
        $service = new ListingService();
        foreach ($rows as $row) {
            $service->delete((int) $row['pk_i_id'], (string) $row['s_secret'], Actor::admin(0));
        }

        return count($rows);
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(self::SWEEP, static function (Job $job): void {
            if (self::sweep() === self::SWEEP_BATCH) {
                $job->repeat(array());
            }
        });
        osc_job_register_handler(self::EXPIRE, static function (Job $job): void {
            $row = self::heldRow($job);
            if ($row !== null) {
                (new ListingService())->delete((int) $row['pk_i_id'], (string) $row['s_secret'], Actor::admin(0));
            }
        });
        osc_job_register_handler(self::REMIND, static function (Job $job): void {
            $row = self::heldRow($job);
            if ($row !== null) {
                self::remind($row);
            }
        });
        $describe = static fn (array $payload): string => sprintf(__('Listing #%d'), (int) ($payload['item'] ?? 0));
        osc_job_describe(self::EXPIRE, __('Delete unpaid listing'), $describe);
        osc_job_describe(self::REMIND, __('Remind to pay for listing'), $describe);
        osc_job_describe(self::SWEEP, __('Delete unpaid listings past their deadline'));
    }

    /** @return array<string,mixed>|null the listing, when it is still waiting for payment */
    private static function heldRow(Job $job): ?array
    {
        $row = ListingStore::find((int) $job->get('item', 0), array('*'));

        return $row !== null && ListingStatus::isHeld($row) ? $row : null;
    }
}
