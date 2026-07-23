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
 * Characterization pins for the UserEmailTmp model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method body moves to the parameterized query layer.
 *
 * insertOrUpdate() is an insert-then-update-on-failure dance: t_user_email_tmp
 * keys on fk_i_user_id, so a second request for the same user fails the insert
 * and falls through to an update. Its return value is inverted relative to what
 * the name suggests — a successful insert reports false, and only the update
 * branch reports a row count. Every in-repo caller ignores the return, but it is
 * a public method, so the ledger is pinned rather than tidied.
 *
 * Usage:  php tests/models/useremailtmp.php      (standalone, own scratch database)
 *         php tests/run-models.php useremailtmp  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_useremailtmp');

seed_country($admin);
seed_region($admin);
$userId      = seed_user($admin, 'u1', 'u1@example.test');
$otherUserId = seed_user($admin, 'u2', 'u2@example.test');

$model = UserEmailTmp::newInstance();
$table = DB_TABLE_PREFIX . 't_user_email_tmp';

/**
 * Read the stored row back with raw mysqli, never through the code under test.
 *
 * @return array|null
 */
$storedRow = static function (int $forUser) use ($admin, $table): ?array {
    $stmt = $admin->prepare("SELECT * FROM $table WHERE fk_i_user_id = ?");
    $stmt->bind_param('i', $forUser);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('UserEmailTmp: public surface');

pin(
    'insertOrUpdate signature is unchanged',
    'public insertOrUpdate($userEmailTmp)',
    harness_method_signature('UserEmailTmp', 'insertOrUpdate')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('UserEmailTmp', 'newInstance')
);
check('UserEmailTmp still extends DAO', is_subclass_of('UserEmailTmp', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_user_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('fk_i_user_id', 's_new_email', 'dt_date'),
    $model->getFields()
);

/* ----------------------------------------------------------------------------
 * The return ledger.
 * ------------------------------------------------------------------------- */
harness_section('insertOrUpdate — fresh insert');

$ret = $model->insertOrUpdate(array('fk_i_user_id' => $userId, 's_new_email' => 'first@example.test'));

pin('a fresh insert returns bool false, not the id', false, $ret);
pin('exactly one row exists', 1, $rowCount());

$row = $storedRow($userId);
check('the row was written', is_array($row));
/* $storedRow reads with a prepared statement, so numeric columns arrive as
 * native ints. That is the verification path, not the model's return, so the
 * all-string rule does not apply to it. */
pin('fk_i_user_id landed', $userId, (int)$row['fk_i_user_id']);
pin('s_new_email landed', 'first@example.test', $row['s_new_email']);
check('dt_date was populated', $row['dt_date'] !== null && $row['dt_date'] !== '0000-00-00 00:00:00', describe($row['dt_date']));

harness_section('insertOrUpdate — existing row, value changes');

$ret = $model->insertOrUpdate(array('fk_i_user_id' => $userId, 's_new_email' => 'second@example.test'));

pin('the update branch returns int 1, not bool true', 1, $ret);
pin('still exactly one row — it updated, it did not duplicate', 1, $rowCount());
pin('s_new_email was overwritten', 'second@example.test', $storedRow($userId)['s_new_email']);

harness_section('insertOrUpdate — a second user is independent');

$ret = $model->insertOrUpdate(array('fk_i_user_id' => $otherUserId, 's_new_email' => 'other@example.test'));

pin('a different user is a fresh insert again', false, $ret);
pin('both rows now exist', 2, $rowCount());
pin('the first user\'s row is untouched', 'second@example.test', $storedRow($userId)['s_new_email']);

harness_section('insertOrUpdate — rejected row (unknown user id)');

/* fk_i_user_id carries a foreign key onto t_user, so an unknown id fails the
 * insert and then matches nothing on the update. The caller is told 0 rather
 * than being given an error to handle. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->insertOrUpdate(array('fk_i_user_id' => 999999, 's_new_email' => 'ghost@example.test'));
error_reporting($prevLevel);

pin('an unknown user id returns int 0 rather than raising', 0, $ret);
pin('nothing was written for it', 2, $rowCount());

/* ----------------------------------------------------------------------------
 * Query cost. The legacy dance costs two statements on the update branch: the
 * insert that fails, then the update. Collapsing that is a legitimate
 * improvement so long as the ledger above is unchanged, so this pins the ceiling
 * rather than an exact number.
 * ------------------------------------------------------------------------- */
harness_section('UserEmailTmp: query cost');

$freshCost = harness_query_count(static function () use ($model, $otherUserId) {
    $model->insertOrUpdate(array('fk_i_user_id' => $otherUserId, 's_new_email' => 'cost-a@example.test'));
});
check('an existing-row write costs no more than the legacy two statements (' . $freshCost . ')', $freshCost <= 2);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/useremailtmp.php */
