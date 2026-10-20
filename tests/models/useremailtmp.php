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
 * Pins for the UserEmailTmp model: one pending e-mail change per user in the
 * `email_change` group of t_key_value.
 *
 * insertOrUpdate() keeps its old return ledger: false when there was no pending
 * change, 1 when one was replaced, 0 when nothing changed or the user is unknown.
 *
 * Usage:  php tests/models/useremailtmp.php      (standalone, own scratch database)
 *         php tests/run-models.php useremailtmp  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_useremailtmp');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'email_change'");

seed_country($admin);
seed_region($admin);
$userId      = seed_user($admin, 'u1', 'u1@example.test');
$otherUserId = seed_user($admin, 'u2', 'u2@example.test');

$model = UserEmailTmp::getInstance();
$table = DB_TABLE_PREFIX . 't_key_value';

/**
 * Read the stored row back with raw mysqli, never through the code under test.
 *
 * @return array|null
 */
$storedRow = static function (int $forUser) use ($admin, $table): ?array {
    $key  = (string) $forUser;
    $stmt = $admin->prepare("SELECT s_value AS s_new_email, dt_expires FROM $table WHERE s_group = 'email_change' AND s_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table WHERE s_group = 'email_change'")->fetch_assoc()['c'];
};

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

/* ----------------------------------------------------------------------------
 * The return ledger.
 * ------------------------------------------------------------------------- */
harness_section('insertOrUpdate — fresh insert');

$ret = $model->insertOrUpdate(array('fk_i_user_id' => $userId, 's_new_email' => 'first@example.test'));

pin('a fresh insert returns bool false, not the id', false, $ret);
pin('exactly one row exists', 1, $rowCount());

$row = $storedRow($userId);
check('the row was written', is_array($row));
pin('s_new_email landed', 'first@example.test', $row['s_new_email']);
pin('it expires in seven days', true, abs(strtotime($row['dt_expires'] . ' UTC') - (time() + UserEmailTmp::TTL)) < 60);
pin('findByPrimaryKey reads it back in the old row shape', array((string) $userId, 'first@example.test'), array_values(array_slice((array) $model->findByPrimaryKey($userId), 0, 2)));

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

$ret = $model->insertOrUpdate(array('fk_i_user_id' => 999999, 's_new_email' => 'ghost@example.test'));

pin('an unknown user id returns int 0 rather than raising', 0, $ret);
pin('nothing was written for it', 2, $rowCount());

harness_section('insertOrUpdate — the same address again');

pin('an unchanged address returns int 0', 0, $model->insertOrUpdate(array('fk_i_user_id' => $userId, 's_new_email' => 'second@example.test')));

harness_section('deleteByUser');

pin('it removes only that user\'s change', array(1, null, 'other@example.test'), array($model->deleteByUser($userId), $storedRow($userId), $storedRow($otherUserId)['s_new_email'] ?? null));
pin('a missing change reads as false', false, $model->findByPrimaryKey($userId));

harness_section('UserEmailTmp: query cost');

$freshCost = harness_query_count(static function () use ($model, $otherUserId) {
    $model->insertOrUpdate(array('fk_i_user_id' => $otherUserId, 's_new_email' => 'cost-a@example.test'));
});
check('an existing-row write costs at most three statements (' . $freshCost . ')', $freshCost <= 3);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/useremailtmp.php */
