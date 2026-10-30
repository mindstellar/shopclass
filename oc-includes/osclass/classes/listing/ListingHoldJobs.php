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
use mindstellar\billing\EntitlementStore;
use mindstellar\database\Db;
use mindstellar\job\Job;
use mindstellar\job\JobQueue;

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

    /**
     * Queue the delete after $holdDays days, and the reminder one day earlier. Call it in the
     * hold's own transaction: the delete job is what marks the listing as held for payment.
     *
     * @throws \RuntimeException when a job could not be queued
     */
    public static function queue(int $itemId, int $holdDays): void
    {
        $key    = (string) $itemId;
        $queued = osc_job_enqueue(self::EXPIRE, array('item' => $itemId), array('delay' => self::daysAhead($holdDays), 'unique_key' => $key)) > 0;
        if ($queued && $holdDays > 1) {
            $queued = osc_job_enqueue(self::REMIND, array('item' => $itemId), array('delay' => self::daysAhead($holdDays - 1), 'unique_key' => $key)) > 0;
        }
        if (!$queued) {
            throw new \RuntimeException('Unpaid listing jobs were not queued for listing ' . $itemId . '.');
        }
    }

    /**
     * Seconds until the same clock time $days days from now. The deadline adds calendar days,
     * so across a clock change this is an hour more or less than $days * 86400.
     */
    public static function daysAhead(int $days, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return $now->modify('+' . $days . ' days')->getTimestamp() - $now->getTimestamp();
    }

    /** Drop the listing's waiting delete and reminder, once it is live or deleted. */
    public static function cancel(int $itemId): void
    {
        $queue = JobQueue::getInstance();
        $queue->cancelKey(self::EXPIRE, (string) $itemId);
        $queue->cancelKey(self::REMIND, (string) $itemId);
    }

    /**
     * Whether core holds the listing for payment: the held columns and its queued delete job.
     * A hidden row a plugin wrote without a first publish date has no job, so moderation may change it.
     *
     * @param array<string,mixed> $row a t_item row with the status columns
     */
    public static function holds(int $itemId, array $row): bool
    {
        return ListingStatus::isHeld($row) && JobQueue::instance()->hasKey(self::EXPIRE, (string) $itemId);
    }

    /**
     * The reminder to the seller.
     *
     * @param array<string,mixed> $row
     */
    public static function remind(array $row): void
    {
        try {
            \mindstellar\mail\CoreEmails::listingUnpaidReminder($row);
        } catch (\Throwable $e) {
            error_log('Reminder for unpaid listing ' . ($row['pk_i_id'] ?? '?') . ' was not sent: ' . $e->getMessage());
        }
    }

    /**
     * Queue the daily sweep, which deletes held listings whose delete job is still waiting;
     * called from `cron_daily`.
     */
    public static function queueSweep(): void
    {
        osc_job_ensure(self::SWEEP);
    }

    /**
     * Delete one batch of held listings more than a day past their deadline that still have
     * a delete job queued. A row with no such job was not held by core, so it is left alone.
     *
     * @return int how many it found
     */
    public static function sweep(): int
    {
        $rows = ListingStore::heldPast(date('Y-m-d H:i:s', time() - 86400), self::SWEEP_BATCH);
        foreach ($rows as $row) {
            try {
                self::deleteIfHeld((int) $row['pk_i_id']);
            } catch (\Throwable $e) {
                // Its delete job is still queued, so the next sweep tries again.
                error_log('Unpaid listing ' . $row['pk_i_id'] . ' was not deleted: ' . $e->getMessage());
            }
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
            $itemId = (int) $job->get('item', 0);
            try {
                self::deleteIfHeld($itemId);
            } catch (\Throwable $e) {
                // A running job has lost its key, so queue a keyed one again for the sweep to find.
                error_log('Unpaid listing ' . $itemId . ' was not deleted: ' . $e->getMessage());
                self::queue($itemId, 1);
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

    /**
     * Delete a listing that is still waiting for payment. It re-reads the row under the seller
     * lock and then the row lock, the order ListingCheckout::publish() takes, so a listing paid
     * at the same moment is kept and the two never deadlock.
     *
     * @return bool whether it was deleted; false when it is no longer held
     * @throws \RuntimeException when it is still held and was not deleted
     */
    private static function deleteIfHeld(int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }
        $ownerId = ListingStore::ownerId($itemId);

        return Db::transaction(static function () use ($itemId, $ownerId): bool {
            if ($ownerId > 0) {
                EntitlementStore::lockSeller($ownerId);
            }
            $row = ListingStore::find($itemId, array('*'), true);
            if ($row === null || !ListingStatus::isHeld($row)) {
                return false;
            }
            if ((int) $row['fk_i_user_id'] !== $ownerId
                || (new ListingService())->delete($itemId, (string) $row['s_secret'], Actor::admin(0)) === false) {
                throw new \RuntimeException('The delete did not run.');
            }

            return true;
        });
    }

    /** @return array<string,mixed>|null the listing, when it is still waiting for payment */
    private static function heldRow(Job $job): ?array
    {
        $row = ListingStore::find((int) $job->get('item', 0), array('*'));

        return $row !== null && ListingStatus::isHeld($row) ? $row : null;
    }
}
