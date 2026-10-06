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
     * @param array<string,mixed> $options delay: hold the job back this many seconds.
     *                                     unique_key: fold into a waiting job of the same
     *                                     type and key instead of adding another.
     *
     * @return int the job id, or 0 when it could not be queued
     * @throws InvalidArgumentException on a malformed type or an unencodable payload
     */
    function osc_job_enqueue(string $type, array $payload = array(), array $options = array()): int
    {
        return JobQueue::getInstance()->enqueue($type, $payload, $options);
    }
}

if (!function_exists('osc_job_enqueue_many')) {
    /**
     * Put many jobs of one type on the queue in a few inserts.
     *
     * @param string                         $type
     * @param array<int,array<string,mixed>> $payloads
     * @param array<string,mixed>            $options as osc_job_enqueue(); unique_key may be
     *                                                a callable fn(array $payload): ?string
     *
     * @return int how many were queued
     * @throws InvalidArgumentException on a malformed type, key or payload
     */
    function osc_job_enqueue_many(string $type, array $payloads, array $options = array()): int
    {
        return JobQueue::getInstance()->enqueueMany($type, $payloads, $options);
    }
}

if (!function_exists('osc_job_ensure')) {
    /**
     * Queue a job of $type only when none is pending or running.
     *
     * @param string              $type
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options as osc_job_enqueue()
     *
     * @return bool true when a job of $type is now queued or running
     */
    function osc_job_ensure(string $type, array $payload = array(), array $options = array()): bool
    {
        return JobQueue::getInstance()->ensure($type, $payload, $options);
    }
}

if (!function_exists('osc_job_stats')) {
    /**
     * Pending, running and failed counts, when the oldest pending job was created, and how
     * many pending jobs are due now and since when.
     *
     * @param string|null $type narrow to one type
     *
     * @return array{pending:int,running:int,error:int,oldest:?string,due:int,due_since:?string}
     */
    function osc_job_stats(?string $type = null): array
    {
        return JobQueue::getInstance()->stats($type);
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

if (!function_exists('osc_job_describe')) {
    /**
     * Name a job type for the admin's Background jobs screen and activity log.
     *
     * @param string        $type   namespaced
     * @param string        $name   e.g. "Send the weekly digest"
     * @param callable|null $detail fn(array $payload): string, e.g. the user it is for. Plain
     *                              text: it is escaped where it is shown.
     *
     * @return void
     * @throws InvalidArgumentException on a malformed type
     */
    function osc_job_describe(string $type, string $name, ?callable $detail = null): void
    {
        JobRegistry::describe($type, $name, $detail);
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
        return JobQueue::getInstance()->count($status, $type);
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
        return JobQueue::getInstance()->summary();
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
        return JobQueue::getInstance()->retry($id);
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
        return JobQueue::getInstance()->forget($id);
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
        return JobQueue::getInstance()->deadLetters($limit);
    }
}

// Core's own handlers register here like anyone else's. The worker fires this hook once
// per request before its first claim.
osc_add_hook('register_jobs', static function () {
    \mindstellar\storage\StorageJobs::register();
    \mindstellar\job\CategoryJobs::register();
    \mindstellar\job\CleanupJobs::register();
    \mindstellar\location\LocationRecountJobs::register();
    \mindstellar\search\AlertJobs::register();
    \mindstellar\backup\BackupJobs::register();
    \mindstellar\security\MessageHold::registerJobs();
    \mindstellar\billing\Receipts::registerJobs();
    \mindstellar\webhook\Delivery::register();
});

// A job queued by a web request would otherwise wait for the next cron tick. The worker
// costs one COUNT when the queue is empty, so running it on every tick is cheap.
osc_add_hook('cron', static function () {
    JobWorker::run();
});

/**
 * Runs scheduled tasks after the response when auto-cron is on, at most once per five minutes.
 * Web pages queue the work to run at shutdown; an API response is already sent, so it runs now.
 *
 * @param bool $responseSent true when the caller has already written the whole response
 */
function osc_auto_cron_dispatch(bool $responseSent = false): void
{
    if (defined('__FROM_CRON__') || !osc_auto_cron() || osc_maintenance_is_restoring(ABS_PATH . '.maintenance')) {
        return;
    }

    // Auto-cron sends a fire-and-forget self request to run scheduled tasks. Left ungated it
    // fires on EVERY page view, so a busy site hammers itself with one internal POST per hit
    // (each spawns an FPM worker). Throttle it to at most one dispatch per 5 minutes.
    //
    // Prefer the object cache as the lock: with a real backend (memcached/apcu) the window is
    // shared across every web node and every locale (Object_Cache_Factory directly, not the
    // locale-suffixed osc_cache_* helpers). The default driver is a per-request array that never
    // survives between requests and so cannot throttle anything, so there fall back to the
    // modification time of a stamp file under uploads/, no cache backend required. Either path
    // fails open (write fails or file unwritable => cron still runs), never closed.
    $window = 300;
    $fire   = false;
    $cache  = Object_Cache_Factory::getInstance();

    if (!($cache instanceof Object_Cache_default)) {
        $found = false;
        if ($cache->get('osclass_autocron_tick', $found) === false) {
            $cache->set('osclass_autocron_tick', 1, $window);
            $fire = true;
        }
    } else {
        // A dotfile, so a "deny hidden files" web-server rule keeps it unreadable; it carries no
        // data anyway, only its mtime matters.
        $stamp = osc_uploads_path() . '.autocron_tick';
        if (!file_exists($stamp) || (time() - (int)@filemtime($stamp)) >= $window) {
            @touch($stamp);
            $fire = true;
        }
    }

    if (!$fire) {
        return;
    }

    $finish = null;
    if (function_exists('fastcgi_finish_request')) {
        $finish = 'fastcgi_finish_request';
    } elseif (function_exists('litespeed_finish_request')) {
        $finish = 'litespeed_finish_request';
    }

    if ($finish === null) {
        // No way to detach on this SAPI, so the self request stays -- unchanged, including
        // its inability to reach a proxied origin. Shared hosting is where it is still the
        // only option, and it is also where nobody can add a real crontab.
        \mindstellar\utility\Utils::doRequest(osc_base_url(), array('page' => 'cron'));

        return;
    }

    // Under FPM the work runs here, after the response has gone, instead of asking the site to
    // call itself over HTTP. That self request is only a way to detach, and it cannot survive an
    // origin behind a proxy, which resolves its own public host to the edge and never hairpins
    // back. Running it here needs no network and puts failures in the site's own error log.
    $run = static function () use ($finish, $window) {
        $finish();
        // The visitor already has their response, so nothing is waiting on this.
        ignore_user_abort(true);
        // Bounded, never 0: this occupies an FPM worker, and one that hangs is one the pool
        // cannot serve from. The throttle window is the ceiling, so a run cannot overlap the next.
        @set_time_limit($window);
        if (!defined('__FROM_CRON__')) {
            define('__FROM_CRON__', true);
        }
        require_once LIB_PATH . 'osclass/cron.php';
    };

    if ($responseSent) {
        $run();

        return;
    }

    // A shutdown function, not an inline call: the CSRF guard holds the page in an output buffer
    // and injects tokens from its own shutdown function, which must run before the request ends.
    register_shutdown_function($run);
}
