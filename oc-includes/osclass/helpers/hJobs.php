<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Background jobs.
 *
 * Work that must not happen inside somebody's page load goes on the queue: a plugin calls
 * `osc_job_enqueue()` when the request comes in, registers a handler on the `register_jobs`
 * hook, and the handler runs on the next cron tick.
 *
 * The shortest complete example, in a plugin:
 *
 *     osc_add_hook('register_jobs', function () {
 *         osc_job_register_handler('acme.send_digest', function ($job) {
 *             acme_send_digest((int) $job->get('user_id'));   // throw to retry
 *         });
 *     });
 *
 *     osc_job_enqueue('acme.send_digest', array('user_id' => $userId));
 *
 * Two rules the queue cannot enforce for you:
 *
 * - **Put the facts in the payload, not a foreign key.** The job may run long after the
 *   row that created it was deleted.
 * - **Make the handler safe to run twice.** A worker killed mid-job leaves the row behind
 *   and a later tick runs it again.
 *
 * Work too big for one tick calls `$job->repeat($payload)` and returns: the job is
 * re-queued with that payload instead of finishing, so a batch never holds a lock or a
 * PHP process longer than one round.
 *
 * @see docs/site/developers/jobs.md
 */

use mindstellar\job\JobQueue;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;

if (!function_exists('osc_job_enqueue')) {
    /**
     * Put a job on the queue.
     *
     * @param string              $type    namespaced, e.g. 'acme.send_digest'
     * @param array<string,mixed> $payload everything the handler will need, as plain data
     * @param array<string,mixed> $options delay: hold the job back this many seconds
     *
     * @return int the job id, or 0 when it could not be queued
     * @throws InvalidArgumentException on a malformed type or an unencodable payload
     */
    function osc_job_enqueue(string $type, array $payload = array(), array $options = array()): int
    {
        return JobQueue::instance()->enqueue($type, $payload, $options);
    }
}

if (!function_exists('osc_job_register_handler')) {
    /**
     * Say which callable runs $type. Register from the `register_jobs` hook, so the
     * handler exists in a cron request too -- not only where the job was queued.
     *
     * @param string   $type    namespaced
     * @param callable $handler fn(\mindstellar\job\Job $job): void -- throw to retry
     *
     * @return void
     * @throws InvalidArgumentException on a malformed type
     */
    function osc_job_register_handler(string $type, callable $handler): void
    {
        JobRegistry::register($type, $handler);
    }
}

if (!function_exists('osc_job_has_handler')) {
    /**
     * @param string $type
     *
     * @return bool
     */
    function osc_job_has_handler(string $type): bool
    {
        return JobRegistry::has($type);
    }
}

if (!function_exists('osc_job_registered_types')) {
    /**
     * Every type something has registered for, sorted.
     *
     * @return array<int,string>
     */
    function osc_job_registered_types(): array
    {
        return JobRegistry::types();
    }
}

if (!function_exists('osc_job_count')) {
    /**
     * How many jobs are in one status, optionally of one type.
     *
     * @param string      $status pending|running|error
     * @param string|null $type
     *
     * @return int
     */
    function osc_job_count(string $status = 'pending', ?string $type = null): int
    {
        return JobQueue::instance()->count($status, $type);
    }
}

if (!function_exists('osc_job_summary')) {
    /**
     * Jobs per status as status => count, with every status present.
     *
     * @return array<string,int>
     */
    function osc_job_summary(): array
    {
        return JobQueue::instance()->summary();
    }
}

if (!function_exists('osc_job_run')) {
    /**
     * Drain the queue now, for up to $maxSeconds. Cron already does this; call it only
     * where a job should happen without waiting for the next tick.
     *
     * @param int $maxSeconds
     *
     * @return int how many jobs ran
     */
    function osc_job_run(int $maxSeconds = 20): int
    {
        return JobWorker::run($maxSeconds);
    }
}

if (!function_exists('osc_job_retry')) {
    /**
     * Put a job that stopped retrying back on the queue, attempts cleared.
     *
     * @param int $id
     *
     * @return bool
     */
    function osc_job_retry(int $id): bool
    {
        return JobQueue::instance()->retry($id);
    }
}

if (!function_exists('osc_job_forget')) {
    /**
     * Throw away a job that stopped retrying.
     *
     * @param int $id
     *
     * @return bool
     */
    function osc_job_forget(int $id): bool
    {
        return JobQueue::instance()->forget($id);
    }
}

if (!function_exists('osc_job_dead_letters')) {
    /**
     * The jobs that stopped retrying, newest first, each with its last error.
     *
     * @param int $limit
     *
     * @return array<int,array<string,string|null>>
     */
    function osc_job_dead_letters(int $limit = 50): array
    {
        return JobQueue::instance()->deadLetters($limit);
    }
}

// Core's own handlers register here like anyone else's. The worker fires this hook once
// per request before its first claim.
osc_add_hook('register_jobs', static function () {
    \mindstellar\storage\StorageJobs::register();
    \mindstellar\job\CategoryJobs::register();
    \mindstellar\search\AlertJobs::register();
});

// A job queued by a web request would otherwise wait for the next cron tick. The worker
// costs one COUNT when the queue is empty, so running it on every tick is cheap.
osc_add_hook('cron', static function () {
    JobWorker::run();
});
