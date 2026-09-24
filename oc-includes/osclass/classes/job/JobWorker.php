<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

use Throwable;

/**
 * Drains the queue for as long as it is given.
 *
 * Runs on the `cron` hook, from `oc-cli.php jobs:run`, and wherever an admin screen wants
 * a job to happen now. It costs one COUNT when the queue is empty, which is the normal
 * case on a site that never queues anything.
 *
 * Handlers are registered through the `register_jobs` hook before the first claim, so a
 * plugin's handler exists even in a cron request that loaded nothing else of its.
 */
final class JobWorker
{
    /** @var bool whether register_jobs has been fired this request */
    private static $registered = false;

    /**
     * Claim and run jobs until the queue is drained or the budget is spent.
     *
     * @param int $maxSeconds wall-clock budget for this tick
     * @param int $batch      jobs claimed per round
     *
     * @return int how many jobs ran, whether they succeeded or not
     */
    public static function run(int $maxSeconds = 20, int $batch = 20): int
    {
        self::registerHandlers();

        $queue = JobQueue::instance();
        if ($queue->count(JobQueue::STATUS_PENDING) === 0) {
            return 0;
        }

        $start = time();
        $ran   = 0;

        while ((time() - $start) < $maxSeconds) {
            $rows = $queue->claim($batch);
            if ($rows === array()) {
                break;
            }

            foreach ($rows as $i => $row) {
                self::process($queue, $row);
                $ran++;

                // A single job can outlast the budget -- a category batch, a large
                // upload. Stop there, and hand back the claimed jobs not yet run.
                if ((time() - $start) >= $maxSeconds) {
                    $queue->release(array_column(array_slice($rows, $i + 1), 'pk_i_id'));
                    break;
                }
            }
        }

        return $ran;
    }

    /**
     * Fire `register_jobs` once per request, so every handler is known before the first
     * claim. Core's own handlers hang off this hook too -- there is no privileged path.
     *
     * @return void
     */
    public static function registerHandlers(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        osc_run_hook('register_jobs');
    }

    /**
     * Run one job. Never throws: a handler's error is recorded on its own row.
     *
     * @param JobQueue                  $queue
     * @param array<string,string|null> $row a t_job_queue row
     *
     * @return void
     */
    private static function process(JobQueue $queue, array $row): void
    {
        $id   = (int) $row['pk_i_id'];
        $type = (string) $row['s_type'];

        $handler = JobRegistry::handler($type);
        if ($handler === null) {
            // Not thrown away. It backs off and eventually dead-letters with a message
            // that names the type, because the usual cause is a plugin that was
            // deactivated with its jobs still queued -- and reactivating it should be
            // enough to let them run.
            $queue->fail($id, 'No handler registered for job type "' . $type . '"');

            return;
        }

        $payload = json_decode((string) $row['s_payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $job = new Job($row, $payload);

        try {
            $handler($job);
        } catch (Throwable $e) {
            $queue->fail($id, $e->getMessage());

            return;
        }

        $repeat = $job->repeatRequest();
        if ($repeat !== null) {
            $queue->repeat($id, $repeat['payload'], $repeat['delay']);

            return;
        }

        $queue->complete($id);
    }

    /**
     * Forget that `register_jobs` has run, so the next call fires it again. For tests.
     *
     * @return void
     */
    public static function resetRegistration(): void
    {
        self::$registered = false;
    }
}
