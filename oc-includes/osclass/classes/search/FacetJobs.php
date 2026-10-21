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

namespace mindstellar\search;

use mindstellar\job\Job;

/**
 * Builds the listing filter index on the job queue, a batch of listings per run. A rebuild
 * replaces rows in place and never clears the ready flag, so searches keep using the index.
 */
final class FacetJobs
{
    public static function queueBuild(): bool
    {
        return osc_job_ensure(FacetIndex::JOB_BUILD, array('after' => 0));
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(FacetIndex::JOB_BUILD, static function (Job $job): void {
            $last = FacetIndex::buildStep((int) $job->get('after', 0), FacetIndex::BATCH);
            if ($last === null) {
                FacetIndex::markReady();

                return;
            }
            $job->repeat(array('after' => $last));
        });
        osc_job_register_handler(FacetIndex::JOB_FIELD, static function (Job $job): void {
            $last = FacetIndex::buildStep((int) $job->get('after', 0), FacetIndex::BATCH, (int) $job->get('field', 0));
            if ($last !== null) {
                $job->repeat(array('field' => (int) $job->get('field', 0), 'after' => $last));
            }
        });
        osc_job_describe(FacetIndex::JOB_BUILD, __('Listing filter index'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
        osc_job_describe(FacetIndex::JOB_FIELD, __('Filter index of one field'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
    }
}
