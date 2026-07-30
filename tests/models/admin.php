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
 * Characterization pins for the Admin model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * findByEmail()/findByUsername()/findByIdSecret()/findByIdPassword()/
 * deleteBatch() and the constructor's SHOW COLUMNS probe move to the
 * parameterized query layer.
 *
 * findByEmail/findByUsername/findByIdSecret/findByIdPassword all share one
 * shape: they read `$result->numRows` as a PLAIN PROPERTY (not the numRows()
 * method) after dao->get(). A malformed argument (null in particular) makes
 * dao->_where() emit a comparison with no right-hand side, the query fails at
 * the driver, get() returns bool false, and `false->numRows` is read on a
 * non-object — a PHP warning, evaluating to null, and `null == 0` is true. So
 * the SQL-error branch and the "no match" branch land on the exact same
 * observable value (false) by two different roads. Both are pinned rather than
 * assumed to converge.
 *
 * findByCredentials() has no query of its own: it delegates to
 * findByUsername() and osc_verify_password(), so its ledger is a straight
 * function of those two.
 *
 * findByPrimaryKey() also has no query of its own to convert (parent::
 * findByPrimaryKey() is a DAO base method, out of scope for this effort); it
 * wraps the parent call with a per-instance memoization cache keyed by id. The
 * cache is pinned here (C9): a repeat lookup costs zero queries, and a write
 * through the inherited DAO::update() does NOT invalidate it — the cache
 * strategy is characterized as-is, not changed.
 *
 * deleteBatch(array()) is the write-side sibling of the null-where correction:
 * an empty id list makes the legacy dao->whereIn() emit `IN ()`, a SQL syntax
 * error, so dao->delete() returns bool false. A naive builder conversion
 * (QueryBuilder::whereIn() emits `1 = 0` for an empty array, which is valid SQL
 * matching zero rows) would return int 0 instead — false and 0 are both falsy
 * to the one caller in this codebase, but they are not the same value, so the
 * conversion must reproduce false explicitly rather than let the two diverge.
 *
 * The constructor's `SHOW COLUMNS FROM ... WHERE Field = "b_moderator"` probe
 * is exercised in both directions (column present / column absent) by altering
 * the scratch table's schema and constructing a throwaway `new Admin()` — the
 * class has no private constructor, only a private static $instance, so a
 * second instance can be built directly without disturbing the singleton used
 * by the rest of this file.
 *
 * Usage:  php tests/models/admin.php          (standalone, own scratch database)
 *         php tests/run-models.php admin      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

// findByCredentials() calls osc_verify_password(), a genuine helper the
// bootstrap does not load on its own. It has no heavier dependencies (no
// Params/HTMLPurifier chain), so it is required directly rather than stubbed.
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hSecurity.php';

$admin = scratchdb_session('osc_models_admin');
$table = DB_TABLE_PREFIX . 't_admin';

/**
 * Insert one admin row with raw mysqli, never through the code under test.
 * scratchdb.php has no seed helper for this table, so it lives here.
 *
 * @return int The admin id just inserted
 */
$seedAdmin = static function (
    string $username,
    string $email,
    string $password = 'not-a-real-hash-not-a-real-hash-not-a-real-hash-000000000',
    string $name = 'Admin',
    ?string $secret = null,
    int $moderator = 0
) use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table (s_name, s_username, s_password, s_email, s_secret, b_moderator)
         VALUES (?, ?, ?, ?, ?, ?)",
        'sssssi',
        array($name, $username, $password, $email, $secret, $moderator)
    );
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$model = Admin::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Admin: public surface');

pin(
    'findByPrimaryKey signature is unchanged',
    'public findByPrimaryKey($id, $locale = NULL)',
    harness_method_signature('Admin', 'findByPrimaryKey')
);
pin(
    'findByEmail signature is unchanged',
    'public findByEmail($email)',
    harness_method_signature('Admin', 'findByEmail')
);
pin(
    'findByCredentials signature is unchanged',
    'public findByCredentials($userName, $password)',
    harness_method_signature('Admin', 'findByCredentials')
);
pin(
    'findByUsername signature is unchanged',
    'public findByUsername($username)',
    harness_method_signature('Admin', 'findByUsername')
);
pin(
    'findByIdSecret signature is unchanged',
    'public findByIdSecret($id, $secret)',
    harness_method_signature('Admin', 'findByIdSecret')
);
pin(
    'findByIdPassword signature is unchanged',
    'public findByIdPassword($id, $password)',
    harness_method_signature('Admin', 'findByIdPassword')
);
pin(
    'deleteBatch signature is unchanged',
    'public deleteBatch($id)',
    harness_method_signature('Admin', 'deleteBatch')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('Admin', 'newInstance')
);
check('Admin still extends DAO', is_subclass_of('Admin', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist includes b_moderator on the seeded schema',
    array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret', 'b_moderator'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct',
        'deleteBatch',
        'findByCredentials',
        'findByEmail',
        'findByIdPassword',
        'findByIdSecret',
        'findByPrimaryKey',
        'findByUsername',
        'newInstance',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Admin')),
        array(
            '__construct',
            'newInstance',
            'findByPrimaryKey',
            'findByEmail',
            'findByCredentials',
            'findByUsername',
            'findByIdSecret',
            'findByIdPassword',
            'deleteBatch',
        )
    ))
);

$idAlice = $seedAdmin('alice', 'alice@example.test');
$hash    = osc_hash_password('CorrectHorseBatteryStaple');
$idBob   = $seedAdmin('bob', 'bob@example.test', $hash, 'Bob', 'a-secret-code', 1);

/* ----------------------------------------------------------------------------
 * findByEmail() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByEmail — match');

$row = $model->findByEmail('alice@example.test');
check('a match returns an array', is_array($row), describe($row));
pin(
    'the row carries every schema column (SELECT * with no explicit column list)',
    array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret', 'b_moderator'),
    array_keys($row)
);
pin('pk_i_id round-trips as a string, not an int (C4)', (string) $idAlice, $row['pk_i_id']);
pin('s_username round-trips', 'alice', $row['s_username']);
pin('b_moderator round-trips as a string, not an int (C4)', '0', $row['b_moderator']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

$rowBob = $model->findByEmail('bob@example.test');
pin('a second admin is reached independently', 'bob', $rowBob['s_username']);
pin('b_moderator "1" round-trips as a string, not an int (C4)', '1', $rowBob['b_moderator']);

harness_section('Admin::findByEmail — no match');

pin('an unknown email returns false, not null or empty array', false, $model->findByEmail('nobody@example.test'));

harness_section('Admin::findByEmail — malformed lookup (null email)');

/* Passing null builds "s_email =" with no right-hand side, so the query fails
 * at the driver; get() returns false, and reading ->numRows off that bool
 * warns and evaluates to null, so `null == 0` sends the method down its
 * "not found" branch — the same value a clean zero-row match would produce. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null email returns false rather than raising', false, $model->findByEmail(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByUsername() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByUsername — match');

$row = $model->findByUsername('alice');
pin('the match is the right admin', (string) $idAlice, $row['pk_i_id']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Admin::findByUsername — no match');

pin('an unknown username returns false', false, $model->findByUsername('nosuchuser'));

harness_section('Admin::findByUsername — malformed lookup (null username)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null username returns false rather than raising', false, $model->findByUsername(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByCredentials() — pure delegation to findByUsername() + osc_verify_password().
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByCredentials — match');

$row = $model->findByCredentials('bob', 'CorrectHorseBatteryStaple');
check('the right username/password returns the admin row', is_array($row), describe($row));
pin('it is the right admin', (string) $idBob, $row['pk_i_id']);

harness_section('Admin::findByCredentials — negative cases (wrong credential must not match)');

pin(
    'the right username with the WRONG password does not match',
    false,
    $model->findByCredentials('bob', 'WrongPassword')
);
pin(
    'a nonexistent username does not match regardless of password',
    false,
    $model->findByCredentials('nosuchuser', 'CorrectHorseBatteryStaple')
);
pin(
    'a username that exists but has no usable password hash does not match',
    false,
    $model->findByCredentials('alice', 'not-a-real-hash-not-a-real-hash-not-a-real-hash-000000000')
);

/* ----------------------------------------------------------------------------
 * findByIdSecret() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByIdSecret — match');

$row = $model->findByIdSecret($idBob, 'a-secret-code');
pin('the matching id+secret pair returns the admin row', 'bob', $row['s_username']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Admin::findByIdSecret — negative cases (wrong credential must not match)');

pin('a mismatched secret for a real id does not match', false, $model->findByIdSecret($idBob, 'the-wrong-secret'));
pin('a nonexistent id does not match regardless of secret', false, $model->findByIdSecret(999999, 'a-secret-code'));
pin(
    'the right id with another admin\'s secret does not match',
    false,
    $model->findByIdSecret($idAlice, 'a-secret-code')
);

harness_section('Admin::findByIdSecret — malformed lookup (null secret)');

/* Two AND'd conditions; a null value on either one makes that one clause bare
 * ("s_secret =" with nothing after it), which is enough to fail the whole
 * query the same way a single malformed condition does elsewhere in this
 * class. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null secret returns false rather than raising', false, $model->findByIdSecret($idBob, null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByIdPassword() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByIdPassword — match');

$row = $model->findByIdPassword($idBob, $hash);
pin('the matching id+password pair returns the admin row', 'bob', $row['s_username']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Admin::findByIdPassword — negative cases (wrong credential must not match)');

pin(
    'a mismatched raw password value for a real id does not match',
    false,
    $model->findByIdPassword($idBob, 'not-the-stored-hash')
);
pin('a nonexistent id does not match regardless of password', false, $model->findByIdPassword(999999, $hash));
pin(
    'the right id with another admin\'s password value does not match',
    false,
    $model->findByIdPassword(
        $idAlice,
        $hash
    )
);

harness_section('Admin::findByIdPassword — malformed lookup (null password)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null password returns false rather than raising', false, $model->findByIdPassword($idBob, null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByPrimaryKey() — no query of its own (parent::findByPrimaryKey() is a
 * DAO base method, out of scope), but the per-instance cache wrapped around it
 * IS this model's own code and is characterized here (C9).
 * ------------------------------------------------------------------------- */
harness_section('Admin::findByPrimaryKey — bad input');

pin('an empty-string id returns an empty string without querying', '', $model->findByPrimaryKey(''));

harness_section('Admin::findByPrimaryKey — cache (C9)');

$first = $model->findByPrimaryKey((string) $idAlice);
check('a match returns an array', is_array($first), describe($first));
pin('s_username round-trips', 'alice', $first['s_username']);

$costSecondCall = harness_query_count(static function () use ($model, $idAlice) {
    $model->findByPrimaryKey((string) $idAlice);
});
pin('a repeat lookup for the same id costs zero queries (served from cache)', 0, $costSecondCall);

$second = $model->findByPrimaryKey((string) $idAlice);
pin('the cached value is identical to the first call', $first, $second);

harness_section('Admin::findByPrimaryKey — cache is not invalidated by a write');

/* update() is the inherited DAO base method (out of scope for this
 * conversion); it goes straight to the database and does not know the cache
 * exists. */
$model->update(array('s_name' => 'Alice Changed'), array('pk_i_id' => $idAlice));

$rawAfterWrite = $admin->query("SELECT s_name FROM $table WHERE pk_i_id = $idAlice")->fetch_assoc();
pin('the write itself reached the database', 'Alice Changed', $rawAfterWrite['s_name']);

$staleRead = $model->findByPrimaryKey((string) $idAlice);
pin('a subsequent cached read still returns the PRE-write value (documented, not fixed)', 'Admin', $staleRead['s_name']);

$costAfterWrite = harness_query_count(static function () use ($model, $idAlice) {
    $model->findByPrimaryKey((string) $idAlice);
});
pin('the stale read after a write still costs zero queries', 0, $costAfterWrite);

harness_section('Admin::findByPrimaryKey — no-match id is cached too');

$missingFirst = $model->findByPrimaryKey('999999');
pin('a nonexistent id returns false', false, $missingFirst);
$costMissingSecond = harness_query_count(static function () use ($model) {
    $model->findByPrimaryKey('999999');
});
pin('the false result for a nonexistent id is also cached', 0, $costMissingSecond);

/* ----------------------------------------------------------------------------
 * deleteBatch() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Admin::deleteBatch — deleting more than one id');

$idCarol = $seedAdmin('carol', 'carol@example.test');
$idDave  = $seedAdmin('dave', 'dave@example.test');
$before  = $rowCount();

pin('deleting two existing ids returns the affected-row count as an int', 2, $model->deleteBatch(array($idCarol, $idDave)));
pin('both rows are actually gone', $before - 2, $rowCount());

harness_section('Admin::deleteBatch — no matching ids');

pin('deleting ids that match nothing returns int 0, not false', 0, $model->deleteBatch(array(9999998, 9999999)));

harness_section('Admin::deleteBatch — a mix of matching and non-matching ids');

$idErin = $seedAdmin('erin', 'erin@example.test');
pin('one of two ids matching returns 1', 1, $model->deleteBatch(array($idErin, 8888888)));

$countBeforeEmptyDelete = $rowCount();

harness_section('Admin::deleteBatch — empty id list (the write-side null-where sibling)');

/* An empty array makes the legacy dao->whereIn() emit "pk_i_id IN ()", a SQL
 * syntax error; dao->delete() returns bool false. This must NOT converge with
 * the "no ids matched" case above, which returns int 0 — false and 0 are a
 * different value even though both are falsy to the one caller in this
 * codebase. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('an empty id list returns false, not int 0', false, $model->deleteBatch(array()));
error_reporting($prevLevel);

check(
    'nothing further was written by the empty-array call',
    $rowCount() === $countBeforeEmptyDelete,
    (string) $rowCount()
);

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('Admin: query cost');

pin('one findByEmail() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByEmail('bob@example.test');
}));
pin('one findByUsername() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByUsername('bob');
}));
pin('one findByIdSecret() call costs one query', 1, harness_query_count(static function () use ($model, $idBob) {
    $model->findByIdSecret($idBob, 'a-secret-code');
}));
pin('one findByIdPassword() call costs one query', 1, harness_query_count(static function () use ($model, $idBob, $hash) {
    $model->findByIdPassword($idBob, $hash);
}));
pin('one findByCredentials() call costs one query (findByUsername\'s)', 1, harness_query_count(static function () use ($model) {
    $model->findByCredentials('bob', 'CorrectHorseBatteryStaple');
}));

$idFrank = $seedAdmin('frank', 'frank@example.test');
pin('one deleteBatch() call (matching ids) costs one query', 1, harness_query_count(static function () use ($model, $idFrank) {
    $model->deleteBatch(array($idFrank));
}));

/* ----------------------------------------------------------------------------
 * Constructor SHOW COLUMNS site — both directions.
 *
 * Admin has no private constructor, only a private static $instance, so a
 * throwaway `new Admin()` exercises __construct() again without disturbing
 * $model (the singleton used by every pin above).
 * ------------------------------------------------------------------------- */
harness_section('Admin::__construct — SHOW COLUMNS probe (b_moderator present)');

pin('one Admin construction costs exactly one query (the SHOW COLUMNS probe)', 1, harness_query_count(static function () {
    new Admin();
}));

harness_section('Admin::__construct — SHOW COLUMNS probe (b_moderator absent)');

if (!$admin->query("ALTER TABLE $table DROP COLUMN b_moderator")) {
    fwrite(STDERR, "setup failed: could not drop b_moderator: {$admin->error}\n");
    exit(2);
}

$freshWithout = new Admin();
pin(
    'fields omit b_moderator when SHOW COLUMNS finds no such column',
    array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret'),
    $freshWithout->getFields()
);

if (!$admin->query("ALTER TABLE $table ADD COLUMN b_moderator TINYINT(1) NOT NULL DEFAULT 0")) {
    fwrite(STDERR, "teardown failed: could not restore b_moderator: {$admin->error}\n");
    exit(2);
}

$freshWith = new Admin();
pin(
    'fields include b_moderator again once the column is restored',
    array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret', 'b_moderator'),
    $freshWith->getFields()
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/admin.php */
