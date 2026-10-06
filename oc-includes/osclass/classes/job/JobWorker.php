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

    /** Job types that run while a backup is restored; nothing else does. */
    public const RESTORE_TYPES = 'backup.';

    /** @var string|null the .maintenance file read, when not the site's own */
    private static $maintenance = null;

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

        $queue = JobQueue::getInstance();
        if ($queue->count(JobQueue::STATUS_PENDING) === 0) {
            return 0;
        }
        // Mid-restore other jobs would work on half-restored data, so only the restore goes on.
        $only = self::restoring() ? self::RESTORE_TYPES : null;

        $start    = time();
        $ran      = 0;
        $outcomes = array();
        $types    = array();

        while ((time() - $start) < $maxSeconds) {
            $rows = $queue->claim($batch, $only);
            if ($rows === array()) {
                break;
            }

            foreach ($rows as $i => $row) {
                $outcome            = self::process($queue, $row);
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                $type               = (string) $row['s_type'];
                $types[$type]       = ($types[$type] ?? 0) + 1;
                $ran++;

                // A single job can outlast the budget -- a category batch, a large
                // upload. Stop there, and hand back the claimed jobs not yet run.
                if ((time() - $start) >= $maxSeconds) {
                    $queue->release(array_column(array_slice($rows, $i + 1), 'pk_i_id'));
                    break;
                }
            }
        }

        if ($ran > 0) {
            self::logRun($outcomes, $types);
        }

        return $ran;
    }

    /**
     * One activity-log row per worker pass that did anything, so there is a history of
     * the work after the finished jobs have left the queue.
     *
     * @param array<string,int> $outcomes outcome => count
     * @param array<string,int> $types    type => count
     *
     * @return void
     */
    private static function logRun(array $outcomes, array $types): void
    {
        $words = array(
            'done'    => __('%d done'),
            'repeat'  => __('%d continuing'),
            'retry'   => __('%d to retry'),
            'gave_up' => __('%d gave up'),
        );
        $parts = array();
        foreach ($words as $outcome => $word) {
            if (!empty($outcomes[$outcome])) {
                $parts[] = sprintf($word, $outcomes[$outcome]);
            }
        }
        $names = array();
        foreach ($types as $type => $count) {
            $names[] = JobRegistry::name($type) . ' (' . $count . ')';
        }

        self::log('run', 0, implode(', ', $parts) . ': ' . implode(', ', $names));
    }

    /**
     * Write a row in the activity log under the Jobs section.
     *
     * @param string $action
     * @param int    $id
     * @param string $text
     *
     * @return void
     */
    public static function log(string $action, int $id, string $text): void
    {
        \Log::getInstance()->insertLog('jobs', $action, $id, mb_substr($text, 0, 250), 'system', 0);
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
     * @return string done|repeat|retry|gave_up
     */
    private static function process(JobQueue $queue, array $row): string
    {
        $id   = (int) $row['pk_i_id'];
        $type = (string) $row['s_type'];

        $handler = JobRegistry::handler($type);
        if ($handler === null) {
            // Not thrown away. It backs off and eventually dead-letters with a message
            // that names the type, because the usual cause is a plugin that was
            // deactivated with its jobs still queued -- and reactivating it should be
            // enough to let them run.
            return self::failed($queue, $id, $type, array(), 'No handler registered for job type "' . $type . '"');
        }

        $payload = json_decode((string) $row['s_payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $job = new Job($row, $payload);

        try {
            $handler($job);
        } catch (Throwable $e) {
            $retry = $e instanceof JobRetry ? $e : null;

            return self::failed($queue, $id, $type, $payload, $e->getMessage(), $retry?->delay(), $retry?->maxAttempts());
        }

        $repeat = $job->repeatRequest();
        if ($repeat !== null) {
            $queue->repeat($id, $repeat['payload'], $repeat['delay']);

            return 'repeat';
        }

        $queue->complete($id);

        return 'done';
    }

    /**
     * Record a failure. A job that has used its last try is logged, because it now
     * waits for someone.
     *
     * @param JobQueue            $queue
     * @param int                 $id
     * @param string              $type
     * @param array<string,mixed> $payload
     * @param string              $error
     * @param int|null            $delay       the handler's own wait before the next try
     * @param int|null            $maxAttempts the handler's own try limit
     *
     * @return string retry|gave_up
     */
    private static function failed(JobQueue $queue, int $id, string $type, array $payload, string $error, ?int $delay = null, ?int $maxAttempts = null): string
    {
        if (!$queue->fail($id, $error, $delay, $maxAttempts)) {
            return 'retry';
        }
        $detail = JobRegistry::detail($type, $payload);
        self::log('gave_up', $id, JobRegistry::name($type) . ($detail !== '' ? ' - ' . $detail : '') . ': ' . $error);
        osc_run_hook('job_gave_up', $type, $payload, $error, $id);

        return 'gave_up';
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

    /**
     * Whether a backup is being restored, so only its own jobs may run.
     *
     * @return bool
     */
    public static function restoring(): bool
    {
        $file = self::$maintenance ?? (defined('ABS_PATH') ? ABS_PATH . '.maintenance' : '');

        return $file !== '' && function_exists('osc_maintenance_is_restoring') && osc_maintenance_is_restoring($file);
    }

    /**
     * Read another .maintenance file, for tests; null for the site's own.
     *
     * @param string|null $path
     *
     * @return void
     */
    public static function useMaintenanceFile(?string $path): void
    {
        self::$maintenance = $path;
    }
}
