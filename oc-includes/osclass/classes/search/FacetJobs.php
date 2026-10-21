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

use mindstellar\admin\DatabaseTools;
use mindstellar\database\Db;
use mindstellar\database\DbException;
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

    /**
     * Rebuilds the index in the foreground from listing $from on, then sets the ready flag.
     * $line gets one progress line per batch. A DbException is rethrown after its line.
     *
     * @return int the last listing id done
     */
    public static function reindex(int $from, callable $line): int
    {
        $after = $from;
        $done  = 0;
        do {
            try {
                $last = FacetIndex::buildStep($after, FacetIndex::BATCH);
            } catch (DbException $e) {
                $line(sprintf('Stopped after %d listings: %s. Resume with --from=%d', $done, $e->getMessage(), $after));

                throw $e;
            }
            if ($last !== null) {
                $done += FacetIndex::BATCH;
                $after = $last;
                $line(sprintf('%d listings done, last id %d', $done, $after));
            }
        } while ($last !== null);
        $after = max($after, (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_item'));
        FacetIndex::markReady();
        $line(sprintf('Index built up to listing #%d', $after));

        return $after;
    }

    /**
     * After a schema repair: when it created the index table, clear the ready flag and queue a build.
     *
     * @param array $ran the statements the repair ran
     */
    public static function afterRepair(array $ran): void
    {
        foreach ($ran as $statement) {
            if (DatabaseTools::createdTable((string) $statement) === FacetIndex::tableName()) {
                FacetIndex::markStale();
                self::queueBuild();

                return;
            }
        }
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
