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
 * Characterization pins for the Alerts model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the ten find / create / activate / deactivate / unsub / search bodies move to
 * the parameterized query layer -- with ONE labelled exception, the amendment-T
 * section below, which records a deliberate behaviour change and is therefore
 * RED against the pre-conversion code by design.
 *
 * Alerts is the one model that stores serialized Search objects: the s_search
 * column holds a JSON blob whose values were already inlined/escaped by the old
 * Search code. That blob is a hard back-compat boundary -- existing rows must
 * read back byte-for-byte, and createAlert() must store a new blob without
 * reformatting it. Search itself is not converted yet, so these pins treat the
 * blob as opaque bytes and only assert round-trip identity, never structure.
 *
 * The ten legacy raw-string wheres are all `where('dt_unsub_date IS NULL')` -- a
 * value-less compile-time literal that becomes whereRaw('dt_unsub_date IS NULL').
 * The value-bearing wheres (s_email, e_type, s_search, fk_i_user_id, b_active)
 * are already key=value form and become bound where() calls.
 *
 * Amendment T: the value-bearing comparison against the VARCHAR s_email column
 * dropped the legacy escape() numeric-coercion. Legacy where('s_email', '0')
 * compiled `s_email = 0`, coercing every non-numeric email to 0 and matching
 * them all; the bound form compares '0' as a string and matches nothing. That
 * divergence is pinned in its own clearly-labelled section (amendment X).
 *
 * Usage:  php tests/models/alerts.php          (standalone, own scratch database)
 *         php tests/run-models.php alerts      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_alerts');
$table = DB_TABLE_PREFIX . 't_alerts';

/**
 * Insert one alert row with raw mysqli, never through the code under test.
 * scratchdb.php has no seed helper for this table, so it lives here. A null
 * $userId or null $dtUnsub is bound as SQL NULL.
 *
 * @return int The alert id just inserted
 */
$seedAlert = static function (
    string $email,
    ?int $userId,
    string $search,
    string $secret,
    string $type = 'DAILY',
    int $active = 1,
    string $dtDate = '2026-01-01 00:00:00',
    ?string $dtUnsub = null
) use ($admin, $table): int {
    $stmt = $admin->prepare(
        "INSERT INTO $table
         (s_email, fk_i_user_id, s_search, s_secret, b_active, e_type, dt_date, dt_unsub_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        fwrite(STDERR, "seedAlert prepare failed: {$admin->error}\n");
        exit(2);
    }
    $stmt->bind_param('sississs', $email, $userId, $search, $secret, $active, $type, $dtDate, $dtUnsub);
    if (!$stmt->execute()) {
        fwrite(STDERR, "seedAlert execute failed: {$stmt->error}\n");
        exit(2);
    }
    $id = $admin->insert_id;
    $stmt->close();

    return $id;
};

/** Read s_search back for an id with raw prepared mysqli (opaque-bytes check). */
$rawSearchFor = static function (int $id) use ($admin, $table): ?string {
    $stmt = $admin->prepare("SELECT s_search FROM $table WHERE pk_i_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : $row['s_search'];
};

/** Read one string column back with raw prepared mysqli. */
$rawColFor = static function (int $id, string $col) use ($admin, $table): ?string {
    $stmt = $admin->prepare("SELECT `$col` FROM $table WHERE pk_i_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : ($row[$col] === null ? null : (string)$row[$col]);
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$model = Alerts::newInstance();

/* An old, already-serialized Search blob with everything a byte-identity check
 * must survive: a single quote, a backslash, LIKE wildcards, and multibyte
 * text. The model must never re-encode or "clean" it. */
$oldBlob = '{"sPattern":"o\'reilly \\ 50% off_ café","fromUser":"7","conds":"a.b = \'x\'"}';

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Alerts: public surface');

pin('findByUser signature', 'public findByUser($userId, $unsub = false)', harness_method_signature('Alerts', 'findByUser'));
pin('findByEmail signature', 'public findByEmail($email, $unsub = false)', harness_method_signature('Alerts', 'findByEmail'));
pin('findByType signature', 'public findByType($type, $active = false, $unsub = false)', harness_method_signature('Alerts', 'findByType'));
pin('findByTypeGroup signature', 'public findByTypeGroup($type, $active = false, $unsub = false)', harness_method_signature('Alerts', 'findByTypeGroup'));
pin('findBySearchAndUser signature', 'public findBySearchAndUser($search, $user, $unsub = false)', harness_method_signature('Alerts', 'findBySearchAndUser'));
pin('findBySearchAndType signature', 'public findBySearchAndType($search, $type, $unsub = false)', harness_method_signature('Alerts', 'findBySearchAndType'));
pin('findUsersBySearchAndType signature', 'public findUsersBySearchAndType($search, $type, $active = false, $unsub = false)', harness_method_signature('Alerts', 'findUsersBySearchAndType'));
pin('findByUserByType signature', 'public findByUserByType($userId, $type, $unsub = false)', harness_method_signature('Alerts', 'findByUserByType'));
pin('findByEmailByType signature', 'public findByEmailByType($email, $type, $unsub = false)', harness_method_signature('Alerts', 'findByEmailByType'));
pin('createAlert signature', "public createAlert(\$userid, \$email, \$alert, \$secret, \$type = 'DAILY')", harness_method_signature('Alerts', 'createAlert'));
pin('activate signature', 'public activate($id)', harness_method_signature('Alerts', 'activate'));
pin('deactivate signature', 'public deactivate($id)', harness_method_signature('Alerts', 'deactivate'));
pin('unsub signature', 'public unsub($id)', harness_method_signature('Alerts', 'unsub'));
pin('search signature', "public search(\$start = 0, \$end = 10, \$order_column = 'dt_date', \$order_direction = 'DESC', \$name = '')", harness_method_signature('Alerts', 'search'));
pin('newInstance signature', 'public static newInstance()', harness_method_signature('Alerts', 'newInstance'));

check('Alerts still extends DAO', is_subclass_of('Alerts', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_email', 'fk_i_user_id', 's_search', 's_secret', 'b_active', 'e_type', 'dt_date', 'dt_unsub_date'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct',
        'activate',
        'createAlert',
        'deactivate',
        'findByEmail',
        'findByEmailByType',
        'findBySearchAndType',
        'findBySearchAndUser',
        'findByType',
        'findByTypeGroup',
        'findByUser',
        'findByUserByType',
        'findUsersBySearchAndType',
        'newInstance',
        'search',
        'unsub',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Alerts')),
        array(
            '__construct', 'newInstance', 'findByUser', 'findByEmail', 'findByType', 'findByTypeGroup',
            'findBySearchAndUser', 'findBySearchAndType', 'findUsersBySearchAndType', 'findByUserByType',
            'findByEmailByType', 'createAlert', 'activate', 'deactivate', 'unsub', 'search',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * Fixtures. Two users; a mix of active/unsub/type; one row carrying the old
 * serialized blob; one row whose email holds a literal LIKE wildcard.
 * ------------------------------------------------------------------------- */
$uAlice = 11;
$uBob   = 22;

$idA1 = $seedAlert('alice@example.test', $uAlice, $oldBlob, 'secretA1', 'DAILY', 1, '2026-01-01 10:00:00');
$idA2 = $seedAlert('alice@example.test', $uAlice, '{"q":"cars"}', 'secretA2', 'WEEKLY', 0, '2026-01-02 10:00:00');
$idB1 = $seedAlert('bob@example.test', $uBob, '{"q":"boats"}', 'secretB1', 'DAILY', 1, '2026-01-03 10:00:00');
// An unsubscribed row: dt_unsub_date is set, so the default (unsub=false) filter hides it.
$idB2 = $seedAlert('bob@example.test', $uBob, '{"q":"planes"}', 'secretB2', 'DAILY', 1, '2026-01-04 10:00:00', '2026-02-01 00:00:00');
// An anonymous alert (fk_i_user_id = 0), the createAlert no-user branch shape.
$idAnon = $seedAlert('anon@example.test', 0, '{"q":"anon"}', 'secretAnon', 'DAILY', 1, '2026-01-05 10:00:00');
// A row whose email contains a literal % so the search() LIKE-escaping is testable.
$idPct = $seedAlert('a%b@example.test', 33, '{"q":"pct"}', 'secretPct', 'DAILY', 1, '2026-01-06 10:00:00');

/* ----------------------------------------------------------------------------
 * The serialized-Search JSON boundary (S4 alert-compat constraint).
 * ------------------------------------------------------------------------- */
harness_section('Alerts: serialized JSON round-trip (read path)');

$aliceRows = $model->findByUser($uAlice);
check('findByUser returns rows for a user with alerts', count($aliceRows) === 2, describe($aliceRows));
$blobRow = null;
foreach ($aliceRows as $r) {
    if ($r['pk_i_id'] === (string)$idA1) {
        $blobRow = $r;
    }
}
check('the blob-carrying row was returned', $blobRow !== null, 'not found');
pin('the stored blob reads back BYTE-IDENTICAL through the model', $oldBlob, $blobRow['s_search']);
pin('the raw column and the model agree on the blob bytes', $rawSearchFor($idA1), $blobRow['s_search']);
check('every value in the blob row is a string or null (C4)', all_values_string($blobRow), describe($blobRow));

harness_section('Alerts: serialized JSON round-trip (match path)');

/* findBySearchAndUser binds the blob as a WHERE value; a bound param matches the
 * exact bytes with no escaping surprises even with quotes/backslash/%/unicode. */
$matched = $model->findBySearchAndUser($oldBlob, $uAlice);
check('the blob can be matched exactly as a WHERE value', count($matched) === 1, describe($matched));
if ($matched) {
    pin('the matched row is the blob row', (string)$idA1, $matched[0]['pk_i_id']);
    pin('the matched row still carries the identical blob', $oldBlob, $matched[0]['s_search']);
}

harness_section('Alerts: serialized JSON round-trip (write path via createAlert)');

/* createAlert stores $alert into s_search. A blob with special bytes must land
 * byte-for-byte -- no re-encoding, no cleaning. */
$writeBlob = '{"sPattern":"50%_ \\\\ \"quoted\"","x":"café"}';
$newId = $model->createAlert(99, 'writer@example.test', $writeBlob, 'writerSecret', 'HOURLY');
check('createAlert returns a positive int id for a fresh alert', is_int($newId) && $newId > 0, describe($newId));
pin('the blob was stored byte-identical (read back with raw mysqli)', $writeBlob, $rawSearchFor((int)$newId));
pin('createAlert wrote the email verbatim', 'writer@example.test', $rawColFor((int)$newId, 's_email'));
pin('createAlert wrote the type verbatim', 'HOURLY', $rawColFor((int)$newId, 'e_type'));
pin('createAlert wrote the secret verbatim', 'writerSecret', $rawColFor((int)$newId, 's_secret'));

/* ----------------------------------------------------------------------------
 * findByUser() -- the return ledger. No LIMIT (F5: unbounded, baseline pinned).
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByUser');

$aliceDefault = $model->findByUser($uAlice);
check('a user with alerts returns an array', is_array($aliceDefault), describe($aliceDefault));
pin('both of Alice\'s (non-unsub) alerts are returned', 2, count($aliceDefault));
pin(
    'a row carries every schema column (SELECT *)',
    array('pk_i_id', 's_email', 'fk_i_user_id', 's_search', 's_secret', 'b_active', 'e_type', 'dt_date', 'dt_unsub_date'),
    array_keys($aliceDefault[0])
);
pin('fk_i_user_id round-trips as a string, not an int (C4)', (string)$uAlice, $aliceDefault[0]['fk_i_user_id']);
pin('b_active round-trips as a string, not an int (C4)', '1', $aliceDefault[0]['b_active']);
check('every value in a findByUser row is a string or null (C4)', all_rows_string($aliceDefault), 'not all strings');

harness_section('Alerts::findByUser -- no match');

pin('an unknown user returns an empty array', array(), $model->findByUser(987654));

harness_section('Alerts::findByUser -- unsub flag');

// Bob has one active alert (idB1) and one unsubscribed (idB2, dt_unsub_date set).
pin('default (unsub=false) hides the unsubscribed alert', 1, count($model->findByUser($uBob)));
pin('unsub=true includes the unsubscribed alert', 2, count($model->findByUser($uBob, true)));

pin('findByUser costs one query', 1, harness_query_count(static function () use ($model, $uAlice) {
    $model->findByUser($uAlice);
}));

/* ----------------------------------------------------------------------------
 * findByEmail() -- the return ledger, positive and negative.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByEmail');

pin('an email with alerts returns both of them', 2, count($model->findByEmail('alice@example.test')));
check('every value in a findByEmail row is a string or null (C4)', all_rows_string($model->findByEmail('alice@example.test')), 'not strings');

harness_section('Alerts::findByEmail -- negative cases');

pin('an unknown email returns an empty array', array(), $model->findByEmail('nobody@example.test'));
pin('a real email with the wrong case/spelling does not match', array(), $model->findByEmail('alice@example.TEST-typo'));

harness_section('Alerts::findByEmail -- unsub flag');

pin('default hides Bob\'s unsubscribed alert', 1, count($model->findByEmail('bob@example.test')));
pin('unsub=true reveals it', 2, count($model->findByEmail('bob@example.test', true)));

/* ----------------------------------------------------------------------------
 * findByType() -- type / active / unsub filters.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByType');

// DAILY (unsub excluded): idA1, idB1, idAnon, idPct  -> 4
pin('DAILY (default filters) returns the four active daily alerts', 4, count($model->findByType('DAILY')));
pin('WEEKLY returns Alice\'s single weekly alert', 1, count($model->findByType('WEEKLY')));
pin('an unmatched type returns an empty array', array(), $model->findByType('INSTANT'));
check('every value in a findByType row is a string or null (C4)', all_rows_string($model->findByType('DAILY')), 'not strings');

harness_section('Alerts::findByType -- active flag');

// idA2 is WEEKLY and inactive; among DAILY all four are active, so active=true keeps 4.
pin('active=true keeps only b_active=1 rows', 4, count($model->findByType('DAILY', true)));
// WEEKLY's only row is inactive, so active=true drops it.
pin('active=true drops an inactive row', 0, count($model->findByType('WEEKLY', true)));

harness_section('Alerts::findByType -- unsub flag');

// Bob's DAILY unsub row (idB2) shows only with unsub=true: 4 -> 5
pin('unsub=true includes the unsubscribed daily alert', 5, count($model->findByType('DAILY', false, true)));

/* ----------------------------------------------------------------------------
 * findByTypeGroup() -- GROUP BY s_search.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByTypeGroup');

// Seed a duplicate s_search so the grouping is observable.
$seedAlert('dup@example.test', 44, '{"q":"cars"}', 'secretDup', 'WEEKLY', 1, '2026-01-07 10:00:00');
// WEEKLY now has two rows both with s_search '{"q":"cars"}' -> one group.
pin('WEEKLY collapses two identical s_search rows into one group', 1, count($model->findByTypeGroup('WEEKLY')));
// DAILY has four distinct s_search blobs -> four groups.
pin('DAILY keeps four distinct-s_search groups', 4, count($model->findByTypeGroup('DAILY')));
pin('an unmatched type groups to an empty array', array(), $model->findByTypeGroup('CUSTOM'));

/* ----------------------------------------------------------------------------
 * findBySearchAndUser() -- match + negative.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findBySearchAndUser');

$fsu = $model->findBySearchAndUser('{"q":"cars"}', $uAlice);
pin('the right (search,user) pair returns Alice\'s weekly alert', 1, count($fsu));
if ($fsu) {
    pin('it is the expected row', (string)$idA2, $fsu[0]['pk_i_id']);
}
pin('the right search with the WRONG user does not match', array(), $model->findBySearchAndUser('{"q":"cars"}', $uBob));
pin('the right user with a non-existent search does not match', array(), $model->findBySearchAndUser('{"q":"nope"}', $uAlice));

/* ----------------------------------------------------------------------------
 * findBySearchAndType() -- match + negative.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findBySearchAndType');

pin('the right (search,type) returns the boats alert', 1, count($model->findBySearchAndType('{"q":"boats"}', 'DAILY')));
pin('the right search with the WRONG type does not match', array(), $model->findBySearchAndType('{"q":"boats"}', 'WEEKLY'));

/* ----------------------------------------------------------------------------
 * findUsersBySearchAndType() -- match + active filter.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findUsersBySearchAndType');

pin('matches the boats/daily alert', 1, count($model->findUsersBySearchAndType('{"q":"boats"}', 'DAILY')));
pin('active=true still keeps the active match', 1, count($model->findUsersBySearchAndType('{"q":"boats"}', 'DAILY', true)));
// '{"q":"cars"}' WEEKLY has two rows: idA2 (inactive) and the dup (active).
pin('active=true drops the inactive of two same-search rows', 1, count($model->findUsersBySearchAndType('{"q":"cars"}', 'WEEKLY', true)));
pin('active=false keeps both same-search rows', 2, count($model->findUsersBySearchAndType('{"q":"cars"}', 'WEEKLY')));

/* ----------------------------------------------------------------------------
 * findByUserByType() -- match + negative.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByUserByType');

pin('the right (user,type) returns Alice\'s daily alert', 1, count($model->findByUserByType($uAlice, 'DAILY')));
pin('the right user with the WRONG type does not match', array(), $model->findByUserByType($uAlice, 'INSTANT'));
pin('a non-existent user does not match', array(), $model->findByUserByType(987654, 'DAILY'));

/* ----------------------------------------------------------------------------
 * findByEmailByType() -- match + negative. (Legacy adds the unsub clause BEFORE
 * the e_type/s_email conditions; the order is preserved but is AND-only, so it
 * is result-identical.)
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByEmailByType');

pin('the right (email,type) returns the daily alert', 1, count($model->findByEmailByType('bob@example.test', 'DAILY')));
pin('the right email with the WRONG type does not match', array(), $model->findByEmailByType('bob@example.test', 'INSTANT'));
pin('a non-existent email does not match', array(), $model->findByEmailByType('ghost@example.test', 'DAILY'));

/* ----------------------------------------------------------------------------
 * createAlert() -- dedup, anonymous branch, return ledger, query cost.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::createAlert -- deduplication');

$before = $rowCount();
$dupResult = $model->createAlert(99, 'writer@example.test', $writeBlob, 'anotherSecret', 'HOURLY');
pin('a duplicate (same user + same s_search, not unsubbed) returns false', false, $dupResult);
pin('no row was written for the duplicate', $before, $rowCount());

harness_section('Alerts::createAlert -- anonymous (userid = 0) branch');

$anonId = $model->createAlert(0, 'newanon@example.test', '{"q":"newanon"}', 'anonSecret2');
check('an anonymous alert is created and returns an int id', is_int($anonId) && $anonId > 0, describe($anonId));
pin('the anonymous alert stored fk_i_user_id = 0', '0', $rawColFor((int)$anonId, 'fk_i_user_id'));
pin('the anonymous alert defaulted e_type to DAILY', 'DAILY', $rawColFor((int)$anonId, 'e_type'));
// Same email + same s_search but a different (0) user is still a fresh anonymous alert only if
// no anonymous row already matches; a repeat of the exact anon pair dedups.
pin('a repeat anonymous pair (email+search) dedups to false', false, $model->createAlert(0, 'newanon@example.test', '{"q":"newanon"}', 'anonSecret2'));

harness_section('Alerts::createAlert -- query cost');

$freshCost = harness_query_count(static function () use ($model) {
    $model->createAlert(0, 'costfresh@example.test', '{"q":"costfresh"}', 's');
});
check('a fresh createAlert costs no more than two statements, select + insert (' . $freshCost . ')', $freshCost <= 2);
$dupCost = harness_query_count(static function () use ($model) {
    $model->createAlert(0, 'costfresh@example.test', '{"q":"costfresh"}', 's');
});
check('a duplicate createAlert costs one statement, the select only (' . $dupCost . ')', $dupCost <= 1);

/* ----------------------------------------------------------------------------
 * activate() / deactivate() / unsub() -- affected-row count ledger.
 * The admin bulk handler sums these (`$iUpdated += $mAlerts->activate($id)`),
 * so the int-vs-false shape matters.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::activate / deactivate');

$targetInactive = $seedAlert('act@example.test', 55, '{"q":"act"}', 'actSecret', 'DAILY', 0, '2026-01-08 10:00:00');
pin('activating an inactive alert reports one changed row', 1, $model->activate($targetInactive));
pin('the row is now active (raw check)', '1', $rawColFor($targetInactive, 'b_active'));
pin('activating an already-active alert reports zero changed rows', 0, $model->activate($targetInactive));
pin('deactivating it reports one changed row', 1, $model->deactivate($targetInactive));
pin('the row is now inactive (raw check)', '0', $rawColFor($targetInactive, 'b_active'));
pin('activating a non-existent id reports zero changed rows', 0, $model->activate(987654));

harness_section('Alerts::unsub');

$targetSub = $seedAlert('sub@example.test', 66, '{"q":"sub"}', 'subSecret', 'DAILY', 1, '2026-01-09 10:00:00');
pin('the target starts subscribed (dt_unsub_date is NULL)', null, $rawColFor($targetSub, 'dt_unsub_date'));
pin('unsub() reports one changed row', 1, $model->unsub($targetSub));
check('dt_unsub_date is now populated', $rawColFor($targetSub, 'dt_unsub_date') !== null, 'still null');
// A second unsub in the same second rewrites dt_unsub_date to the identical
// value, so MySQL reports zero changed rows (affected_rows counts CHANGED rows).
pin('a same-second repeat unsub reports zero changed rows (value unchanged)', 0, $model->unsub($targetSub));
pin('unsubbing a non-existent id reports zero changed rows', 0, $model->unsub(987654));

/* ----------------------------------------------------------------------------
 * search() -- the admin listing. SQL_CALC_FOUND_ROWS + FOUND_ROWS() shape,
 * same as BanRule/KeywordBlock. Returns {rows, total_results, alerts}.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::search -- structure and value types');

$searchAll = $model->search();
check('search returns an array', is_array($searchAll), describe($searchAll));
pin('the result shape has exactly rows/total_results/alerts', array('rows', 'total_results', 'alerts'), array_keys($searchAll));
check('alerts is a list', is_array($searchAll['alerts']), describe($searchAll['alerts']));
check('every alert row is a string or null (C4)', all_rows_string($searchAll['alerts']), 'not strings');

harness_section('Alerts::search -- ordering (dt_date DESC default)');

$ordered = $model->search(0, 100)['alerts'];
$dates = array_map(static function ($r) {
    return $r['dt_date'];
}, $ordered);
$sorted = $dates;
rsort($sorted);
pin('the default order is dt_date DESC', $sorted, $dates);

harness_section('Alerts::search -- pagination (count != offset, an inversion tripwire)');

/* start = OFFSET, end = COUNT under MySQL two-argument LIMIT. Asking for offset 1
 * count 2 must skip the newest row and return the next two, NOT return 1 row. A
 * limit/offset inversion would fail this loudly. */
$page = $model->search(1, 2, 'dt_date', 'DESC');
pin('offset 1 / count 2 returns exactly two rows', 2, count($page['alerts']));
$fullDesc = $model->search(0, 100, 'dt_date', 'DESC')['alerts'];
pin('the window is the 2nd and 3rd rows of the full DESC ordering', array($fullDesc[1]['pk_i_id'], $fullDesc[2]['pk_i_id']), array($page['alerts'][0]['pk_i_id'], $page['alerts'][1]['pk_i_id']));

harness_section('Alerts::search -- total_results / rows counters');

$whole = $model->search(0, 5);
$totalRows = $rowCount();
pin('rows reflects the whole-table count as a string', (string)$totalRows, $whole['rows']);
pin('total_results reflects the unfiltered SQL_CALC_FOUND_ROWS count as a string', (string)$totalRows, $whole['total_results']);
check('the returned page honoured the LIMIT (<= 5 rows)', count($whole['alerts']) <= 5, (string)count($whole['alerts']));

harness_section('Alerts::search -- name filter (LIKE on s_email)');

$named = $model->search(0, 100, 'dt_date', 'DESC', 'bob@example.test');
check('a name filter narrows the alerts to matching emails', count($named['alerts']) >= 1, describe($named['alerts']));
foreach ($named['alerts'] as $r) {
    check('every filtered row has a bob email', strpos($r['s_email'], 'bob@example.test') !== false, $r['s_email']);
}
pin('total_results reflects the FILTERED count', (string)count($named['alerts']), $named['total_results']);
pin('rows still reflects the WHOLE table, ignoring the filter', (string)$rowCount(), $named['rows']);

harness_section('Alerts::search -- LIKE wildcards are treated literally (escaped)');

/* '%' as the name must match the literal-% email only, not every row. Legacy
 * like() ran escapeStr($v, true), escaping % and _ in the payload. */
$pctSearch = $model->search(0, 100, 'dt_date', 'DESC', '%');
$pctEmails = array_map(static function ($r) {
    return $r['s_email'];
}, $pctSearch['alerts']);
check('a literal % matches only the a%b email, not everything', in_array('a%b@example.test', $pctEmails, true) && count($pctEmails) < $rowCount(), implode(',', $pctEmails));

harness_section('Alerts::search -- order_column allowlist');

/* An injection-shaped order column is rejected by the pre-existing regex gate
 * and falls back to dt_date, so the query still succeeds and returns rows. */
$injOrder = $model->search(0, 5, 'dt_date; DROP TABLE x', 'DESC');
check('a non-allowlisted order column falls back safely and still returns a result', is_array($injOrder) && isset($injOrder['alerts']), describe($injOrder));

harness_section('Alerts::search -- empty result set (fresh scratch state on a filter that matches nothing)');

$none = $model->search(0, 10, 'dt_date', 'DESC', 'no-such-email-anywhere@nope.invalid');
pin('a filter matching nothing yields an empty alerts list', array(), $none['alerts']);
pin('total_results is int 0 when nothing matched the filter', 0, $none['total_results']);
check('rows still counts the whole (non-empty) table', $none['rows'] !== 0, describe($none['rows']));

/* ----------------------------------------------------------------------------
 * AMENDMENT T / AMENDMENT X -- DELIBERATE BEHAVIOUR CHANGE.
 *
 * These pins are GREEN against the converted model and RED against the
 * pre-conversion model. They are NOT characterization: they record that the
 * bound-parameter form drops the legacy escape() numeric coercion on the VARCHAR
 * s_email column.
 *
 * Legacy where('s_email', '0') compiled `s_email = 0` (escape() returns a
 * single-character numeric bare), coercing every non-numeric email to 0 and
 * matching ALL of them. The bound form compares the string '0' and matches
 * nothing -- correct, and consistent with the secret/credential coercion fixes
 * elsewhere in this effort. Kept in this labelled section so a later reader does
 * not mistake it for a preserved contract.
 * ------------------------------------------------------------------------- */
harness_section('Alerts::findByEmail -- amendment T (coercion dropped; RED vs legacy)');

pin(
    'findByEmail("0") matches NOTHING now (legacy coerced s_email to 0 and matched every non-numeric email)',
    array(),
    $model->findByEmail('0')
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/alerts.php */
