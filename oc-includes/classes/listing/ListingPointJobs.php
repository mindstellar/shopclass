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

use mindstellar\job\Job;

/**
 * Sets every listing's distance search point again on the job queue, a batch per run, so new
 * city and region centres reach the listings that use them. Runs weekly.
 */
final class ListingPointJobs
{
    public const JOB = 'listing.points';

    /** Listings done per job run. */
    public const BATCH = 5000;

    public static function queue(): void
    {
        osc_job_enqueue(self::JOB, array('after' => 0), array('unique_key' => 'all'));
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function (Job $job): void {
            $last = ListingStore::refreshPoints((int) $job->get('after', 0), self::BATCH);
            if ($last > 0) {
                $job->repeat(array('after' => $last));
            }
        });
        osc_job_describe(self::JOB, __('Listing search points'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
    }
}
