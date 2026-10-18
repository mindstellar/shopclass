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
use mindstellar\job\Job;

/**
 * Jobs for a listing saved and not yet paid for: a reminder a day before the deadline, and
 * the delete at the deadline.
 */
final class ListingHoldJobs
{
    public const EXPIRE = 'listing.unpaid_expire';
    public const REMIND = 'listing.unpaid_remind';

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

    public static function registerJobs(): void
    {
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
    }

    /** @return array<string,mixed>|null the listing, when it is still waiting for payment */
    private static function heldRow(Job $job): ?array
    {
        $row = ListingStore::find((int) $job->get('item', 0), array('*'));

        return $row !== null && ListingStatus::isHeld($row) ? $row : null;
    }
}
