<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Characterization pins for the StorageQueue model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the model moves to the parameterized query layer — with a single sanctioned
 * exception, the "deliberate behaviour change" section at the very end, which is
 * red against the pre-conversion code by exactly one pin and green after.
 *
 * StorageQueue backs the S3/offload job queue: rows are enqueued, claimed by a
 * worker under a unique token, retried with exponential backoff on failure, and
 * removed on completion. Everything below was established by RUNNING the code,
 * never by trusting a method name or a comment. The quirks that matter, all
 * reproduced rather than fixed:
 *
 *  - Of the eight own methods only three carry SQL of their own. enqueue() (DAO
 *    insert), complete() (deleteByPrimaryKey) and fail() (findByPrimaryKey +
 *    updateByPrimaryKey) are pure delegation to inherited DAO base bodies, which
 *    are out of scope; their bodies stay byte-identical and are pinned as a
 *    regression guard.
 *  - claim() is a three-statement sequence: a stale-lock recovery UPDATE, a
 *    conditional claim UPDATE that stamps a fresh unique worker token onto up to
 *    max(1,$batch) pending+due rows ORDER BY pk_i_id, then a SELECT of that
 *    token's running rows. The claim does NOT read the UPDATE's affected-row
 *    count — it identifies the rows it owns by re-selecting on the token — so no
 *    caller and nothing inside the model reads dao->affectedRows() after it.
 *  - claim() picks jobs by pk_i_id (oldest id first), NOT by dt_next_run: a job
 *    with an older due date but a higher id is claimed after a newer-dated,
 *    lower-id one. A changed ORDER BY would silently reshuffle which job a worker
 *    runs.
 *  - claim($batch) floors the batch at max(1,$batch): claim(0) and claim(-7) each
 *    claim exactly one row.
 *  - fail() is a read-modify-write, not an atomic increment: it reads i_attempts,
 *    adds one in PHP and writes the literal back. Past attempt 8 the job is
 *    dead-lettered to 'error'; below it, it is rescheduled 2**min(attempts,7)
 *    minutes out. It clears the worker every time and truncates the error to 250
 *    chars. A missing id is a no-op after a single read.
 *  - deadLetters() returns 'error' rows newest-id-first; its int limit maps onto
 *    LIMIT and a zero or negative limit yields an empty array.
 *  - Every read (claim, deadLetters) returns legacy all-string rows; the counter
 *    columns must survive the move to the prepared path as strings (C4).
 *  - countByStatus() carries the escape() numeric-coercion (amendment T): the
 *    string '0' compiled to a NUMERIC comparison that coerced the VARCHAR status
 *    column, so countByStatus('0') counted EVERY row. Dropping that coercion is a
 *    deliberate change, pinned in its own section.
 *
 * Usage:  php tests/models/storagequeue.php          (standalone, own scratch database)
 *         php tests/run-models.php storagequeue      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_storagequeue');
$table = DB_TABLE_PREFIX . 't_storage_queue';
$model = StorageQueue::newInstance();

/*
 * t_storage_queue has no seed helper in scratchdb.php, so seeding lives here as
 * local closures, raw mysqli, never through the code under test. A seeded row can
 * force any status / due date / lock / attempt count / worker the pins need.
 *
 * @return int The id just inserted
 */
$seed = static function (
    string $status = 'pending',
    string $nextRun = '2000-01-01 00:00:00',
    ?string $locked = null,
    int $attempts = 0,
    ?string $worker = null,
    string $type = 'delete',
    string $storage = 'local',
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

/**
 * One row's shape as a compact "status:aN:worker:next:locked" token, read with
 * raw UNPREPARED mysqli so the verification itself stays on the all-string path
 * (a prepared read would return native ints and disagree with the pin — trap 2.1
 * applies to a test's own reads too).
 *
 * @param int $id
 *
 * @return string|null
 */
$rowState = static function (int $id) use ($admin, $table): ?string {
    $res = $admin->query(
        "SELECT s_status, i_attempts, s_worker, dt_next_run, dt_locked FROM $table WHERE pk_i_id = " . $id
    );
    $r = $res->fetch_assoc();
    $res->free();
    if ($r === null) {
        return null;
    }

    return $r['s_status']
        . ':a' . $r['i_attempts']
        . ':w' . ($r['s_worker'] === null ? 'NULL' : 'SET')
        . ':nr' . $r['dt_next_run']
        . ':lk' . ($r['dt_locked'] ?? 'NULL');
};

/** Silence the legacy warning a malformed query raises, so a failure-branch pin is readable. */
$quiet = static function (callable $fn) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    try {
        return $fn();
    } finally {
        error_reporting($prev);
    }
};

/**
 * Run $fn with the table renamed out of the way, so every query the model makes
 * fails. It is the only way to reach the error-fallback branches, and those
 * branches are the whole reason each converted read needs a catch.
 *
 * @return mixed Whatever $fn returned
 */
$withTableMissing = static function (callable $fn) use ($admin, $table) {
    $admin->query("RENAME TABLE `$table` TO `{$table}_hidden`");
    $previous = error_reporting(E_ALL & ~E_WARNING);
    try {
        return $fn();
    } finally {
        error_reporting($previous);
        $admin->query("RENAME TABLE `{$table}_hidden` TO `$table`");
    }
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * Public + protected only (per the C2 rule): a sanctioned private helper must
 * remain addable without editing a pin.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue: public surface');

check('StorageQueue still extends DAO', is_subclass_of('StorageQueue', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'pk_i_id', 's_type', 's_storage', 's_payload', 's_status', 'i_attempts',
        's_last_error', 's_worker', 'dt_next_run', 'dt_locked', 'dt_created',
    ),
    $model->getFields()
);

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('StorageQueue', 'newInstance'));
pin(
    'enqueue signature is unchanged',
    'public enqueue(string $type, string $storageId, array $snapshot): void',
    harness_method_signature('StorageQueue', 'enqueue')
);
pin('claim signature is unchanged', 'public claim(int $batch = 20): array', harness_method_signature('StorageQueue', 'claim'));
pin('complete signature is unchanged', 'public complete(int $id): void', harness_method_signature('StorageQueue', 'complete'));
pin('fail signature is unchanged', 'public fail(int $id, string $error): void', harness_method_signature('StorageQueue', 'fail'));
pin(
    'countByStatus signature is unchanged',
    'public countByStatus(string $status): int',
    harness_method_signature('StorageQueue', 'countByStatus')
);
pin(
    'deadLetters signature is unchanged',
    'public deadLetters(int $limit = 50): array',
    harness_method_signature('StorageQueue', 'deadLetters')
);

pin(
    'the model exposes exactly these public+protected methods, nothing added or removed',
    array('__construct', 'claim', 'complete', 'countByStatus', 'deadLetters', 'enqueue', 'fail', 'newInstance'),
    (static function () {
        $names = array();
        foreach ((new ReflectionClass('StorageQueue'))->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === 'StorageQueue' && !$m->isPrivate()) {
                $names[] = $m->getName();
            }
        }
        sort($names);

        return $names;
    })()
);

/* ----------------------------------------------------------------------------
 * enqueue() — pure DAO insert. Builds a minimal, self-contained JSON payload.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::enqueue');

$truncate();
$model->enqueue('offload', 's3', array(
    'pk_i_id'        => 5,
    'fk_i_item_id'   => 9,
    's_path'         => 'p/',
    's_extension'    => 'jpg',
    's_content_type' => 'image/jpeg',
));
$model->enqueue('delete', 's3', array('pk_i_id' => 6, 'local' => 0));
$model->enqueue('delete', 's3', array());

$rowOf = static function (int $id) use ($admin, $table): array {
    $res = $admin->query("SELECT * FROM $table WHERE pk_i_id = $id");
    $r   = $res->fetch_assoc();
    $res->free();

    return $r;
};

$r1 = $rowOf(1);
pin('enqueue stores the job type', 'offload', $r1['s_type']);
pin('enqueue stores the storage id', 's3', $r1['s_storage']);
pin('a fresh job starts pending', 'pending', $r1['s_status']);
pin('a fresh job starts at zero attempts', '0', $r1['i_attempts']);
pin('a fresh job has no worker', null, $r1['s_worker']);
pin('a fresh job has no lock', null, $r1['dt_locked']);
pin('dt_next_run and dt_created are stamped together', $r1['dt_created'], $r1['dt_next_run']);
pin(
    'the payload is trimmed to the self-contained snapshot, s_storage defaulting to the storage id',
    array(
        'pk_i_id'        => 5,
        'fk_i_item_id'   => 9,
        's_path'         => 'p/',
        's_extension'    => 'jpg',
        's_content_type' => 'image/jpeg',
        's_storage'      => 's3',
    ),
    json_decode($r1['s_payload'], true)
);
pin(
    "the 'local' flag rides only when the snapshot carries it, cast to a bool",
    array(
        'pk_i_id'        => 6,
        'fk_i_item_id'   => null,
        's_path'         => null,
        's_extension'    => null,
        's_content_type' => null,
        's_storage'      => 's3',
        'local'          => false,
    ),
    json_decode($rowOf(2)['s_payload'], true)
);
pin(
    'an empty snapshot still stores every payload key, s_storage falling back to the storage id',
    array(
        'pk_i_id'        => null,
        'fk_i_item_id'   => null,
        's_path'         => null,
        's_extension'    => null,
        's_content_type' => null,
        's_storage'      => 's3',
    ),
    json_decode($rowOf(3)['s_payload'], true)
);
pin('enqueue returns void', null, $model->enqueue('delete', 'local', array('pk_i_id' => 1)));
pin('enqueue costs a single query', 1, harness_query_count(static function () use ($model) {
    $model->enqueue('delete', 'local', array('pk_i_id' => 2));
}));

/* ----------------------------------------------------------------------------
 * countByStatus() — COUNT(*) of one status. Returns a native int.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::countByStatus');

$truncate();
$seed('pending');
$seed('pending');
$seed('running', '2000-01-01 00:00:00', '2026-01-01 00:00:00', 0, 'w');
$seed('error');
pin('counts rows of the given status', 2, $model->countByStatus('pending'));
pin('a status nothing matches counts zero', 0, $model->countByStatus('nope'));
pin('the return is a native int', 'integer', gettype($model->countByStatus('pending')));
pin('countByStatus costs a single query', 1, harness_query_count(static function () use ($model) {
    $model->countByStatus('pending');
}));
pin('an empty table counts zero', 0, (static function () use ($model, $truncate) {
    $truncate();

    return $model->countByStatus('pending');
})());

/* ----------------------------------------------------------------------------
 * deadLetters() — the 'error' rows, newest id first, capped by the limit.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::deadLetters');

$truncate();
$de1 = $seed('error');
$de2 = $seed('error');
$de3 = $seed('error');
$seed('pending');
$seed('running', '2000-01-01 00:00:00', '2026-01-01 00:00:00', 0, 'w');

$dl = $model->deadLetters();
pin('returns only the error rows', 3, count($dl));
pin('ordered newest id first', array($de3, $de2, $de1), array_map('intval', array_column($dl, 'pk_i_id')));
pin('a dead-letter row carries every schema column', array(
    'pk_i_id', 's_type', 's_storage', 's_payload', 's_status', 'i_attempts',
    's_last_error', 's_worker', 'dt_next_run', 'dt_locked', 'dt_created',
), array_keys($dl[0]));
check('every value in a dead-letter row is a string or null (C4)', all_rows_string($dl));
pin('the limit caps the result', 2, count($model->deadLetters(2)));
pin('a zero limit returns an empty array', array(), $quiet(static function () use ($model) {
    return $model->deadLetters(0);
}));
pin('a negative limit returns an empty array', array(), $quiet(static function () use ($model) {
    return $model->deadLetters(-3);
}));
pin('no error rows returns an empty array', array(), (static function () use ($model, $truncate, $seed) {
    $truncate();
    $seed('pending');

    return $model->deadLetters();
})());
pin('deadLetters costs a single query', 1, (static function () use ($model, $truncate, $seed) {
    $truncate();
    $seed('error');

    return harness_query_count(static function () use ($model) {
        $model->deadLetters();
    });
})());
pin('deadLetters absorbs a query failure into an empty array', array(), $withTableMissing(static function () use ($model) {
    return $model->deadLetters();
}));

/* ----------------------------------------------------------------------------
 * claim() — the heart. Recover stale locks, claim a batch under a fresh token,
 * return that token's running rows.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::claim — selection and side effects');

$truncate();
$c1 = $seed('pending', '2000-01-01 00:00:00');                                              // due, claimable
$c2 = $seed('pending', '2000-01-02 00:00:00');                                              // due, claimable
$c3 = $seed('pending', '2099-01-01 00:00:00');                                              // not due yet
$c4 = $seed('running', '2000-01-01 00:00:00', date('Y-m-d H:i:s', time() - 3600), 0, 'old'); // stale lock -> recovered + reclaimed
$c5 = $seed('running', '2000-01-01 00:00:00', date('Y-m-d H:i:s'), 0, 'fresh');              // fresh lock -> left alone
$c6 = $seed('error', '2000-01-01 00:00:00');                                                // dead-lettered -> left alone

$claimed = $model->claim();
$claimedIds = array_map('intval', array_column($claimed, 'pk_i_id'));
sort($claimedIds);
pin('claims the due pending rows plus the recovered stale-locked one', array($c1, $c2, $c4), $claimedIds);
pin('a not-yet-due pending row is left pending', 'pending:a0:wNULL:nr2099-01-01 00:00:00:lkNULL', $rowState($c3));
pin('a freshly-locked running row is untouched', 'running:a0:wSET', substr($rowState($c5), 0, 15));
pin('a dead-lettered row is untouched', 'error', substr($rowState($c6), 0, 5));

$firstWorker = null;
foreach ($claimed as $row) {
    if ($firstWorker === null) {
        $firstWorker = $row['s_worker'];
    }
    check('every claimed row now runs under the same non-empty worker token', $row['s_worker'] === $firstWorker && $row['s_worker'] !== '' && $row['s_worker'] !== null);
    pin('a claimed row is marked running', 'running', $row['s_status']);
}
check('every value in a claimed row is a string or null (C4)', all_rows_string($claimed));
pin('a claimed row carries all eleven schema columns (SELECT *)', array(
    'pk_i_id', 's_type', 's_storage', 's_payload', 's_status', 'i_attempts',
    's_last_error', 's_worker', 'dt_next_run', 'dt_locked', 'dt_created',
), array_keys($claimed[0]));
pin('claimed rows come back ordered by id', array($c1, $c2, $c4), array_map('intval', array_column($claimed, 'pk_i_id')));
pin('a second claim finds nothing left to claim', array(), $model->claim());

harness_section('StorageQueue::claim — batch ceiling and ordering');

$truncate();
$b = array();
for ($i = 0; $i < 5; $i++) {
    $b[] = $seed('pending');
}
pin('claim(2) takes the two lowest ids', array($b[0], $b[1]), array_map('intval', array_column($model->claim(2), 'pk_i_id')));
pin('claim(0) floors the batch to one row', array($b[2]), array_map('intval', array_column($model->claim(0), 'pk_i_id')));
pin('claim(-7) also floors to one row', array($b[3]), array_map('intval', array_column($model->claim(-7), 'pk_i_id')));

$truncate();
$oNotDue = $seed('pending', '2099-01-01 00:00:00'); // lowest id but not due
$oOld    = $seed('pending', '1999-01-01 00:00:00');
$oMid    = $seed('pending', '2000-01-01 00:00:00');
$oOldest = $seed('pending', '1990-01-01 00:00:00'); // oldest date but highest id
pin(
    'selection is by id among the due rows, not by dt_next_run',
    array($oOld, $oMid),
    array_map('intval', array_column($model->claim(2), 'pk_i_id'))
);

harness_section('StorageQueue::claim — empty and failure');

$truncate();
pin('an empty queue claims an empty array', array(), $model->claim());
pin('claim runs three statements (recover, claim, read)', 3, (static function () use ($model, $truncate) {
    $truncate();

    return harness_query_count(static function () use ($model) {
        $model->claim();
    });
})());
pin('claim absorbs a query failure into an empty array', array(), $withTableMissing(static function () use ($model) {
    return $model->claim();
}));

/* ----------------------------------------------------------------------------
 * A claim is a conditional UPDATE: claiming a free row reports one affected row,
 * claiming an already-claimed row reports zero. Observed on the raw stack so the
 * count's origin is grounded, not assumed. claim() itself never reads this count
 * (it re-selects on the worker token), which is why no affected-rows dependency
 * survives the conversion.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::claim — affected-row semantics of a conditional claim');

$truncate();
$freeRow = $seed('pending');
pin('claiming a free pending row reports one affected row', 1, osc_db_execute(
    "UPDATE $table SET s_status = 'running', s_worker = ? WHERE s_status = 'pending' AND pk_i_id = ?",
    array('tokA', $freeRow)
));
pin('claiming the same row again reports zero — it is no longer pending', 0, osc_db_execute(
    "UPDATE $table SET s_status = 'running', s_worker = ? WHERE s_status = 'pending' AND pk_i_id = ?",
    array('tokB', $freeRow)
));

/* ----------------------------------------------------------------------------
 * fail() — read-modify-write of the attempt counter, with dead-lettering and
 * exponential backoff. Pure DAO delegation (findByPrimaryKey + updateByPrimaryKey).
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::fail — retry progression and backoff');

$truncate();
$fj = $seed('running', '2000-01-01 00:00:00', date('Y-m-d H:i:s'), 0, 'w1');
pin('fail returns void', null, $model->fail($fj, 'boom'));

$attemptsOf = static function (int $id) use ($admin, $table): int {
    $res = $admin->query("SELECT i_attempts FROM $table WHERE pk_i_id = $id");
    $r   = $res->fetch_assoc();
    $res->free();

    return (int) $r['i_attempts'];
};
$statusOf = static function (int $id) use ($admin, $table): string {
    $res = $admin->query("SELECT s_status FROM $table WHERE pk_i_id = $id");
    $r   = $res->fetch_assoc();
    $res->free();

    return $r['s_status'];
};
$workerOf = static function (int $id) use ($admin, $table): ?string {
    $res = $admin->query("SELECT s_worker FROM $table WHERE pk_i_id = $id");
    $r   = $res->fetch_assoc();
    $res->free();

    return $r['s_worker'];
};

pin('a single failure bumps the counter to one', 1, $attemptsOf($fj));
pin('a rescheduled job is set back to pending', 'pending', $statusOf($fj));
pin('a rescheduled job has its worker cleared', null, $workerOf($fj));

// Backoff progression: attempts 1..7 reschedule 2**min(prevAttempts,7) minutes out;
// attempt 8 dead-letters. Measure the delta between dt_next_run and "now".
$truncate();
$bj      = $seed('running', '2000-01-01 00:00:00', null, 0);
$expects = array(
    1 => 60,    // 2**0 minutes
    2 => 120,   // 2**1
    3 => 240,   // 2**2
    4 => 480,   // 2**3
    5 => 960,   // 2**4
    6 => 1920,  // 2**5
    7 => 3840,  // 2**6
);
for ($n = 1; $n <= 7; $n++) {
    $base = time();
    $model->fail($bj, 'e');
    $res  = $admin->query("SELECT i_attempts, s_status, dt_next_run FROM $table WHERE pk_i_id = $bj");
    $r    = $res->fetch_assoc();
    $res->free();
    $delta = strtotime($r['dt_next_run']) - $base;
    pin("attempt $n keeps the job pending", 'pending', $r['s_status']);
    pin("attempt $n counter is $n", $n, (int) $r['i_attempts']);
    check("attempt $n backs off ~{$expects[$n]}s", abs($delta - $expects[$n]) <= 2, "delta=$delta expected={$expects[$n]}");
}
$model->fail($bj, 'e'); // attempt 8
pin('the eighth failure dead-letters the job', 'error', $statusOf($bj));
pin('the dead-lettered counter is eight', 8, $attemptsOf($bj));
$model->fail($bj, 'e'); // attempt 9, past the ceiling
pin('a failure past the ceiling keeps it dead-lettered', 'error', $statusOf($bj));

harness_section('StorageQueue::fail — error truncation, missing id, query cost');

$truncate();
$tj = $seed('running');
$model->fail($tj, str_repeat('x', 400));
$res = $admin->query("SELECT s_last_error FROM $table WHERE pk_i_id = $tj");
$r   = $res->fetch_assoc();
$res->free();
pin('the last error is truncated to 250 chars', 250, strlen($r['s_last_error']));

pin('failing a missing id is a no-op costing one read', 1, harness_query_count(static function () use ($model) {
    $model->fail(999999, 'x');
}));
pin('failing a real row costs a read and a write', 2, harness_query_count(static function () use ($model, $tj) {
    $model->fail($tj, 'x');
}));
pin('fail on a missing row is silent', null, $model->fail(999999, 'x'));

/* ----------------------------------------------------------------------------
 * complete() — removes the job by primary key. Pure DAO delegation.
 * ------------------------------------------------------------------------- */
harness_section('StorageQueue::complete');

$truncate();
$kj = $seed('running');
pin('complete returns void', null, $model->complete($kj));
pin('the completed row is gone', null, $rowState($kj));
pin('completing a missing id costs one query', 1, harness_query_count(static function () use ($model) {
    $model->complete(999999);
}));

/* ============================================================================
 * DELIBERATE BEHAVIOUR CHANGE (amendment T) — NOT characterization.
 *
 * These pins assert the POST-conversion behaviour and are therefore RED against
 * the pre-conversion model by exactly their own count. countByStatus() passes
 * the status through the legacy escape(), which returned an is_numeric() value
 * UNQUOTED: the string '0' compiled to `s_status = 0`, a numeric comparison that
 * coerced the VARCHAR status column so that 'pending'/'running'/'error' all
 * collapsed to 0 and matched — countByStatus('0') counted EVERY row. Binding the
 * value compares it as the string it is, so '0' now matches only a literal '0'
 * status (of which there are none). No caller passes a numeric status; the
 * queue's statuses are all alphabetic. Reproducing the coercion would be type
 * confusion, so it is dropped, in line with the established policy.
 * ========================================================================== */
harness_section('StorageQueue::countByStatus — numeric-coercion dropped (deliberate)');

$truncate();
$seed('pending');
$seed('pending');
$seed('running', '2000-01-01 00:00:00', '2026-01-01 00:00:00', 0, 'w');
$seed('error');
pin("the string '0' now matches only a literal '0' status, i.e. nothing", 0, $model->countByStatus('0'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/storagequeue.php */
