<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\search;

use mindstellar\database\Connection;
use mindstellar\job\Job;
use mindstellar\job\JobQueue;
use mindstellar\job\JobRegistry;

/**
 * Converts stored alerts in the background, a batch per run, after the upgrade has
 * done what it could in its own time budget: one pass over t_alerts in primary-key
 * order. Until a row is converted, replay skips it.
 */
final class AlertJobs
{
    public const TYPE = 'alerts.convert_search';

    /** Passes from the start again when rows changed while being converted. */
    private const MAX_RESTARTS = 3;

    /**
     * @return void
     */
    public static function register(): void
    {
        JobRegistry::register(self::TYPE, static fn (Job $job) => self::convert($job));
    }

    /**
     * Queue the conversion to go on after $after, unless a run is already queued or running.
     *
     * @param int $after the last primary key already handled
     *
     * @return bool false when it could not be queued
     */
    public static function ensureQueued(int $after): bool
    {
        $queue = JobQueue::instance();
        if ($queue->count(JobQueue::STATUS_PENDING, self::TYPE) > 0
            || $queue->count(JobQueue::STATUS_RUNNING, self::TYPE) > 0
        ) {
            return true;
        }

        return $queue->enqueue(self::TYPE, array('after' => $after)) > 0;
    }

    /**
     * One batch, then repeat until the table is done.
     *
     * @param Job $job
     *
     * @return void
     * @throws \mindstellar\database\DbException
     */
    private static function convert(Job $job): void
    {
        $restarts = (int)$job->get('restarts', 0);
        $missed   = (int)$job->get('missed', 0);

        $result = AlertStore::convertBatch(Connection::instance(), (int)$job->get('after', 0));
        $missed += $result['missed'];
        if (!$result['done']) {
            $job->repeat(array('after' => $result['last'], 'restarts' => $restarts, 'missed' => $missed));

            return;
        }
        if ($missed > 0 && $restarts < self::MAX_RESTARTS) {
            $job->repeat(array('after' => 0, 'restarts' => $restarts + 1, 'missed' => 0));
        }
    }
}
