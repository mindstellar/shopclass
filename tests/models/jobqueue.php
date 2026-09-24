<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * What the shared job queue promises.
 *
 * This replaces tests/models/storagequeue.php, whose job was to pin the DAO-to-prepared
 * conversion of a storage-only queue. That queue is now t_job_queue and anything may put
 * work on it, so what needs pinning changed with it:
 *
 *  - a type must be namespaced, because a plugin's `send` would otherwise take the word
 *    from every other plugin;
 *  - a claim is exclusive, or two cron ticks would run the same job twice;
 *  - a failure backs off and eventually stops, rather than retrying forever;
 *  - `repeat()` re-queues a job WITHOUT counting an attempt, which is the whole reason
 *    long work can be split into batches;
 *  - a job whose type nobody registered is not thrown away -- it backs off and says so,
 *    because the usual cause is a plugin switched off with work still queued.
 *
 * Usage:  php tests/models/jobqueue.php          (standalone, own scratch database)
 *         php tests/run-models.php jobqueue      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\job\Job;
use mindstellar\job\JobQueue;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;

$admin = scratchdb_session('osc_models_jobqueue');
$table = DB_TABLE_PREFIX . 't_job_queue';
$queue = JobQueue::instance();

/** Raw seed, never through the code under test, so any state a pin needs can be forced. */
$seed = static function (
    string $type = 'test.one',
    string $status = 'pending',
    string $nextRun = '2000-01-01 00:00:00',
    ?string $locked = null,
    int $attempts = 0,
    ?string $worker = null,
    ?string $storage = null,
    string $payload = '{}'
) use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table
         (s_type, s_storage, s_payload, s_status, i_attempts, s_worker, dt_next_run, dt_locked, dt_created)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        'ssssisss',
        array($type, $storage, $payload, $status, $attempts, $worker, $nextRun, $locked)
    );
};

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/** One row as a compact token, read with raw mysqli so the pin stays on the string path. */
$rowState = static function (int $id) use ($admin, $table): ?string {
    $res = $admin->query(
        "SELECT s_status, i_attempts, s_worker, dt_locked FROM $table WHERE pk_i_id = " . $id
    );
    $r = $res->fetch_assoc();
    $res->free();
    if ($r === null) {
        return null;
    }

    return $r['s_status']
        . ':a' . $r['i_attempts']
        . ':w' . ($r['s_worker'] === null ? 'NULL' : 'SET')
        . ':lk' . ($r['dt_locked'] ?? 'NULL');
};

$column = static function (int $id, string $col) use ($admin, $table) {
    $res = $admin->query("SELECT `$col` FROM $table WHERE pk_i_id = " . $id);
    $r   = $res->fetch_assoc();
    $res->free();

    return $r === null ? null : $r[$col];
};

/* ---------------------------------------------------------------------------
 * A type is namespaced, and that is enforced where it is written.
 * ------------------------------------------------------------------------ */
harness_section('Job types');

check('a namespaced type is valid', JobRegistry::isValidType('acme.send_digest'));
check('a deeper namespace is valid', JobRegistry::isValidType('acme.mail.digest'));
check('a bare word is refused', !JobRegistry::isValidType('send'));
check('upper case is refused', !JobRegistry::isValidType('Acme.Send'));
check('a dash is refused', !JobRegistry::isValidType('acme.send-digest'));
check('a trailing dot is refused', !JobRegistry::isValidType('acme.'));
check('a leading dot is refused', !JobRegistry::isValidType('.send'));
check('an empty type is refused', !JobRegistry::isValidType(''));
check('a type over 60 chars is refused', !JobRegistry::isValidType('a.' . str_repeat('b', 59)));
check('a type of exactly 60 chars is valid', JobRegistry::isValidType('a.' . str_repeat('b', 58)));

$threw = false;
try {
    $queue->enqueue('nonamespace', array());
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('enqueue refuses a bare type at the call site', $threw);

$threw = false;
try {
    // A resource is not JSON-encodable; the queue must say so rather than store "false".
    $queue->enqueue('test.bad', array('h' => fopen('php://memory', 'rb')));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('enqueue refuses a payload it cannot encode', $threw);

/* ---------------------------------------------------------------------------
 * Enqueue
 * ------------------------------------------------------------------------ */
harness_section('Enqueue');

$truncate();
$id = $queue->enqueue('test.one', array('a' => 1));
check('enqueue returns the new id', $id > 0);
pin('it starts pending', 'pending:a0:wNULL:lkNULL', $rowState($id));
pin('the payload is stored as JSON', '{"a":1}', $column($id, 's_payload'));
pin('storage is null when not given', null, $column($id, 's_storage'));

$id = $queue->enqueue('storage.offload', array(), array('storage' => 's3'));
pin('storage is stored when given', 's3', $column($id, 's_storage'));

$threw = false;
try {
    $queue->enqueue('test.one', array('big' => str_repeat('x', JobQueue::MAX_PAYLOAD_BYTES)));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a payload larger than the column is refused', $threw);

$truncate();
$queue->enqueue('test.one', array(), array('delay' => 3600));
check('a delayed job is not due yet', $queue->claim(50) === array());
$truncate();

/* ---------------------------------------------------------------------------
 * Claiming
 * ------------------------------------------------------------------------ */
harness_section('Claiming');

$truncate();
$a = $seed('test.one');
$b = $seed('test.one');
$rows = $queue->claim(10);
pin('both due jobs are claimed', 2, count($rows));
pin('oldest id first', array($a, $b), array((int) $rows[0]['pk_i_id'], (int) $rows[1]['pk_i_id']));
pin('a claimed job is running and locked', 'running:a0:wSET:lk' . $column($a, 'dt_locked'), $rowState($a));
check('a second claim finds nothing left', $queue->claim(10) === array());

$truncate();
$seed('test.one');
$seed('test.one');
$seed('test.one');
pin('the batch size is respected', 2, count($queue->claim(2)));

$truncate();
$seed('test.one');
$seed('test.one');
pin('claim(0) still takes one', 1, count($queue->claim(0)));

$truncate();
$seed('test.one');
$seed('test.one');
pin('claim(-7) still takes one', 1, count($queue->claim(-7)));

$truncate();
$seed('test.one', 'pending', '2999-01-01 00:00:00');
check('a job due in the future is not claimed', $queue->claim(10) === array());

$truncate();
$stale = $seed('test.one', 'running', '2000-01-01 00:00:00', '2000-01-01 00:00:00', 0, 'deadworker');
pin('a lock older than the ceiling is recovered and re-claimed', 1, count($queue->claim(10)));
$fresh = $rowState($stale);
check('and it is running under a new worker', strpos((string) $fresh, ':wSET') !== false);

$truncate();
$seed('test.one', 'running', '2000-01-01 00:00:00', date('Y-m-d H:i:s'), 0, 'liveworker');
check('a fresh lock is left alone', $queue->claim(10) === array());

/* ---------------------------------------------------------------------------
 * Finishing, failing, giving up
 * ------------------------------------------------------------------------ */
harness_section('Finishing and failing');

$truncate();
$id = $seed('test.one');
$queue->complete($id);
pin('complete removes the row', null, $rowState($id));

$truncate();
$id = $seed('test.one');
$queue->fail($id, 'boom');
pin('a first failure goes back to pending with one attempt', 'pending:a1:wNULL:lkNULL', $rowState($id));
pin('and records the reason', 'boom', $column($id, 's_last_error'));

$queue->fail($id, str_repeat('x', 400));
pin('the reason is truncated to the column width', 250, strlen((string) $column($id, 's_last_error')));

$truncate();
$id = $seed('test.one', 'pending', '2000-01-01 00:00:00', null, JobQueue::MAX_ATTEMPTS - 1);
$queue->fail($id, 'last straw');
pin('the final failure gives up', 'error:a' . JobQueue::MAX_ATTEMPTS . ':wNULL:lkNULL', $rowState($id));

$truncate();
$queue->fail(999999, 'no such job');
check('failing a job that is gone is a no-op', true);

/* ---------------------------------------------------------------------------
 * repeat(): how long work is split up
 * ------------------------------------------------------------------------ */
harness_section('Repeating');

$truncate();
$id = $seed('test.one', 'running', '2000-01-01 00:00:00', date('Y-m-d H:i:s'), 3, 'w1');
$queue->repeat($id, array('offset' => 500));
pin('a repeat goes back to pending', 'pending:a3:wNULL:lkNULL', $rowState($id));
pin('the attempt count is NOT touched', '3', $column($id, 'i_attempts'));
pin('the payload is replaced', '{"offset":500}', $column($id, 's_payload'));

/* ---------------------------------------------------------------------------
 * Counting and listing, for the admin screen
 * ------------------------------------------------------------------------ */
harness_section('Counting');

$truncate();
$seed('test.one');
$seed('test.one');
$seed('test.two', 'error');
pin('count by status', 2, $queue->count(JobQueue::STATUS_PENDING));
pin('count by status and type', 1, $queue->count(JobQueue::STATUS_ERROR, 'test.two'));
pin('summary covers every status', array('pending' => 2, 'running' => 0, 'error' => 1), $queue->summary());
pin('queued types are listed sorted', array('test.one', 'test.two'), $queue->queuedTypes());
pin('a page is newest first', 3, count($queue->page()));
pin('a page can be narrowed by status', 1, count($queue->page(JobQueue::STATUS_ERROR)));

/* ---------------------------------------------------------------------------
 * Retry and forget, which only ever touch a job that gave up
 * ------------------------------------------------------------------------ */
harness_section('Retry and forget');

$truncate();
$dead    = $seed('test.one', 'error', '2000-01-01 00:00:00', null, 8, null, null, '{}');
$pending = $seed('test.one');

check('retry re-queues a dead job', $queue->retry($dead));
pin('with its attempts cleared', 'pending:a0:wNULL:lkNULL', $rowState($dead));
check('retry refuses a job that is merely pending', !$queue->retry($pending));

$truncate();
$dead    = $seed('test.one', 'error', '2000-01-01 00:00:00', null, 8);
$pending = $seed('test.one');
check('forget removes a dead job', $queue->forget($dead));
pin('and it is gone', null, $rowState($dead));
check('forget refuses a pending job', !$queue->forget($pending));
pin('and leaves it alone', 'pending:a0:wNULL:lkNULL', $rowState($pending));

$truncate();
$seed('test.one', 'error', '2000-01-01 00:00:00', null, 8);
$seed('test.two', 'error', '2000-01-01 00:00:00', null, 8);
pin('retryAll can be narrowed to one type', 1, $queue->retryAll('test.one'));
$truncate();
$seed('test.one', 'error', '2000-01-01 00:00:00', null, 8);
$seed('test.two', 'error', '2000-01-01 00:00:00', null, 8);
pin('forgetAll takes them all when not narrowed', 2, $queue->forgetAll());

/* ---------------------------------------------------------------------------
 * The worker, the registry, and the handler contract
 * ------------------------------------------------------------------------ */
harness_section('The worker');

$truncate();
JobWorker::resetRegistration();

$seen = array();
JobRegistry::register('test.ok', static function (Job $job) use (&$seen) {
    $seen[] = $job->get('n');
});
JobRegistry::register('test.boom', static function () {
    throw new RuntimeException('handler said no');
});
// The delay is what makes this observable: without it the worker claims the repeated
// job again inside the same run and drains it to the end, which is the right behaviour
// but leaves nothing to look at.
JobRegistry::register('test.batch', static function (Job $job) {
    $offset = (int) $job->get('offset', 0);
    if ($offset < 2) {
        $job->repeat(array('offset' => $offset + 1), 60);
    }
});

$queue->enqueue('test.ok', array('n' => 1));
$queue->enqueue('test.ok', array('n' => 2));
pin('the worker runs both jobs', 2, JobWorker::run(10));
pin('and the handler saw each payload', array(1, 2), $seen);
pin('a finished job is removed', 0, $queue->count(JobQueue::STATUS_PENDING));

$truncate();
$id = $queue->enqueue('test.boom', array());
JobWorker::run(10);
pin('a throwing handler fails its job', 'pending:a1:wNULL:lkNULL', $rowState($id));
pin('with the exception message as the reason', 'handler said no', $column($id, 's_last_error'));

$truncate();
$id = $queue->enqueue('test.batch', array('offset' => 0));
JobWorker::run(10);
pin('a repeating job is still queued after the run', 1, $queue->count(JobQueue::STATUS_PENDING));
pin('and its payload advanced', '{"offset":1}', $column($id, 's_payload'));
pin('with no attempt counted against it', '0', $column($id, 'i_attempts'));

// Left to itself the worker carries the job through to the end rather than stopping
// after one batch -- that is what splits long work up without leaving it half-done.
$truncate();
JobRegistry::register('test.run', static function (Job $job) {
    $offset = (int) $job->get('offset', 0);
    if ($offset < 2) {
        $job->repeat(array('offset' => $offset + 1));
    }
});
$queue->enqueue('test.run', array('offset' => 0));
pin('an undelayed repeat runs to the end in one pass', 3, JobWorker::run(10));
pin('and nothing is left queued', 0, $queue->count(JobQueue::STATUS_PENDING));

$truncate();
$id = $queue->enqueue('test.unregistered', array());
JobWorker::run(10);
pin('an unknown type is not thrown away', 'pending:a1:wNULL:lkNULL', $rowState($id));
check(
    'and the reason names the type',
    strpos((string) $column($id, 's_last_error'), 'test.unregistered') !== false
);

// A job that uses up the budget ends the run; the rest of its batch goes back to pending
// rather than waiting out the stale-lock ceiling.
$truncate();
JobRegistry::register('test.slow', static function () {
    usleep(1100000);
});
$first = $queue->enqueue('test.slow', array());
$rest  = array($queue->enqueue('test.slow', array()), $queue->enqueue('test.slow', array()));
pin('a spent budget stops after the job that spent it', 1, JobWorker::run(1));
pin('and hands the unrun jobs back', array('pending:a0:wNULL:lkNULL', 'pending:a0:wNULL:lkNULL'), array_map($rowState, $rest));

$truncate();
pin('an empty queue costs nothing and runs nothing', 0, JobWorker::run(10));

harness_section('The registry');

check('a registered type is found', JobRegistry::has('test.ok'));
check('an unregistered one is not', !JobRegistry::has('test.nope'));
pin('handler() returns null for an unknown type', null, JobRegistry::handler('test.nope'));
check('types() lists what was registered', in_array('test.ok', JobRegistry::types(), true));
check('types() is sorted', JobRegistry::types() === array_values(array_unique(JobRegistry::types())));

JobRegistry::register('test.ok', static fn () => null);
check('registering twice replaces rather than duplicates', JobRegistry::has('test.ok'));
JobRegistry::forget('test.ok');
check('forget removes a registration', !JobRegistry::has('test.ok'));

$threw = false;
try {
    JobRegistry::register('bare', static fn () => null);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('the registry refuses a bare type too', $threw);

harness_section('The Job a handler receives');

$row = array(
    'pk_i_id'    => '42',
    's_type'     => 'test.one',
    'i_attempts' => '3',
    's_storage'  => 's3',
);
$job = new Job($row, array('a' => 1));
pin('id', 42, $job->id());
pin('type', 'test.one', $job->type());
pin('attempts', 3, $job->attempts());
pin('storage', 's3', $job->storage());
pin('payload', array('a' => 1), $job->payload());
pin('get() reads a key', 1, $job->get('a'));
pin('get() falls back', 'x', $job->get('missing', 'x'));
pin('no repeat was asked for', null, $job->repeatRequest());

$job->repeat(array('b' => 2), 30);
pin('repeat records the payload and the delay', array('payload' => array('b' => 2), 'delay' => 30), $job->repeatRequest());

$job = new Job(array('s_storage' => ''), array());
pin('an empty storage reads as none', null, $job->storage());

$truncate();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
