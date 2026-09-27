<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

use Cleanup;

/**
 * Tools > Cleanup in the background: one job per enabled rule removes a batch, then
 * queues itself again until nothing matches. A listing delete also removes its photos,
 * which is slow on remote storage, so a backlog is never cleared in a page request.
 */
final class CleanupJobs
{
    public const TYPE = 'cleanup.purge';

    /**
     * @return void
     */
    public static function register(): void
    {
        JobRegistry::register(self::TYPE, static fn (Job $job) => self::purge($job));
    }

    /**
     * Queue a job for each enabled rule that matches something. Does nothing while an
     * earlier cleanup is still running, since a repeated job no longer holds its key.
     *
     * @return int how many rules were queued
     */
    public static function queue(): int
    {
        if (self::isRunning()) {
            return 0;
        }
        $engine = Cleanup::newInstance();
        $queued = 0;
        foreach (Cleanup::RULES as $rule) {
            if (!Cleanup::isEnabled($rule) || $engine->countFor($rule, Cleanup::days($rule)) === 0) {
                continue;
            }
            if (osc_job_enqueue(self::TYPE, array('rule' => $rule), array('unique_key' => $rule)) > 0) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Whether cleanup jobs are waiting or running.
     *
     * @return bool
     */
    public static function isRunning(): bool
    {
        $stats = osc_job_stats(self::TYPE);

        return $stats['pending'] + $stats['running'] > 0;
    }

    /**
     * Remove one batch for the job's rule, then ask to run again while more is left.
     * Settings are read on every run, so turning a rule off stops its job.
     *
     * @param Job $job
     *
     * @return void
     */
    private static function purge(Job $job): void
    {
        // One worker pass runs many batches; read the settings fresh for each.
        osc_reset_preferences();
        $rule = (string) $job->get('rule', '');
        if (!in_array($rule, Cleanup::RULES, true) || !Cleanup::isEnabled($rule)) {
            return;
        }

        $engine  = Cleanup::newInstance();
        $days    = Cleanup::days($rule);
        $removed = $engine->purge($rule, $days, Cleanup::batchLimit());
        $left    = $engine->countFor($rule, $days);
        if ($left === 0) {
            return;
        }

        if ($removed === 0) {
            // Every row in the batch refused to go. Carrying on would spin forever, so
            // fail and let the backoff and the queue screen show it.
            throw new \RuntimeException(
                'Cleanup rule ' . $rule . ' still matches ' . $left . ' row(s) and none could be removed this run'
            );
        }

        $job->repeat(array('rule' => $rule));
    }
}
