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
 * The three things QueryBuilder could not express, and the billing and location code
 * hand-writes SQL for: an aggregate, a column moved by an amount, and an insert that
 * updates on a clash.
 *
 * The middle one is not a convenience. `update(['i_balance' => $new])` has to read the
 * balance first, and two requests that read the same figure both write it -- one top-up
 * disappears. That race is pinned below with two connections, not argued about.
 *
 * Usage:  php tests/querybuilder-writes.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root -- the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_querybuilder_writes');

use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;

$t = 'qb_writes';
$admin->query('DROP TABLE IF EXISTS ' . $t);
$admin->query(
    'CREATE TABLE ' . $t . ' ('
    . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
    . ' s_key VARCHAR(40) NOT NULL,'
    . ' i_balance INT NOT NULL DEFAULT 0,'
    . ' s_note VARCHAR(40) NULL,'
    . ' PRIMARY KEY (pk_i_id), UNIQUE KEY uk_key (s_key)'
    . ') ENGINE=InnoDB'
);

/** A fresh builder on the table under test. */
$qb = static function () use ($t): QueryBuilder {
    return new QueryBuilder($t);
};

$admin->query("INSERT INTO $t (s_key, i_balance) VALUES ('a', 10), ('b', 25), ('c', 7)");

harness_section('Aggregates');

pin('sum adds the column up', 42.0, $qb()->sum('i_balance'));
pin('min finds the smallest', '7', (string) $qb()->min('i_balance'));
pin('max finds the largest', '25', (string) $qb()->max('i_balance'));
pin('avg averages', '14.0000', (string) $qb()->avg('i_balance'));

pin('a where narrows the sum', 35.0, $qb()->where('s_key', '!=', 'c')->sum('i_balance'));
pin('nothing matching sums to zero, not null', 0.0, $qb()->where('s_key', 'nope')->sum('i_balance'));
pin('...and min of nothing is null', null, $qb()->where('s_key', 'nope')->min('i_balance'));

$threw = '';
try {
    $qb()->aggregate('DROP', 'i_balance');
} catch (DbException $e) {
    $threw = 'refused';
}
pin('an aggregate that is not one of the four is refused', 'refused', $threw);

$threw = '';
try {
    $qb()->sum('i_balance); DROP TABLE x; --');
} catch (DbException $e) {
    $threw = 'refused';
}
pin('the column is an identifier, not text to interpolate', 'refused', $threw);

harness_section('Moving a column by an amount');

pin('increment moves it up', 1, $qb()->where('s_key', 'a')->increment('i_balance', 5));
pin('...and the row says so', '15', (string) $qb()->where('s_key', 'a')->value('i_balance'));

pin('decrement moves it down', 1, $qb()->where('s_key', 'a')->decrement('i_balance', 3));
pin('...and the row says so too', '12', (string) $qb()->where('s_key', 'a')->value('i_balance'));

pin('a negative increment is a decrement', 1, $qb()->where('s_key', 'a')->increment('i_balance', -2));
pin('...leaving ten', '10', (string) $qb()->where('s_key', 'a')->value('i_balance'));

$qb()->where('s_key', 'a')->increment('i_balance', 1, ['s_note' => 'touched']);
pin('a plain column can be set in the same statement', 'touched', $qb()->where('s_key', 'a')->value('s_note'));

// The whole point. $admin is a second connection to the same row; both add to it, and
// neither read it first, so neither can overwrite the other's figure.
$admin->query("UPDATE $t SET i_balance = 100 WHERE s_key = 'b'");
$qb()->where('s_key', 'b')->increment('i_balance', 10);
$admin->query("UPDATE $t SET i_balance = i_balance + 10 WHERE s_key = 'b'");
pin('two adds against one row both land', '120', (string) $qb()->where('s_key', 'b')->value('i_balance'));

$threw = '';
try {
    $qb()->increment('i_balance', 1);
} catch (DbException $e) {
    $threw = 'refused';
}
pin('incrementing every row at once is refused', 'refused', $threw);

$threw = '';
try {
    $qb()->where('s_key', 'a')->increment('i_balance', '1; DROP TABLE x');
} catch (DbException $e) {
    $threw = 'refused';
}
pin('the amount has to be a number', 'refused', $threw);

harness_section('Insert, or update what is already there');

pin('a new key inserts', 1, $qb()->upsert(['s_key' => 'd', 'i_balance' => 4]));
pin('...and the row is there', '4', (string) $qb()->where('s_key', 'd')->value('i_balance'));

pin('the same key updates rather than erroring', 2, $qb()->upsert(['s_key' => 'd', 'i_balance' => 9]));
pin('...with the new value', '9', (string) $qb()->where('s_key', 'd')->value('i_balance'));
pin('and there is still one row for it', 1, $qb()->where('s_key', 'd')->count());

// Only the named columns are overwritten, so a clash can leave a column alone.
$qb()->upsert(['s_key' => 'd', 'i_balance' => 99, 's_note' => 'keep me']);
$qb()->upsert(['s_key' => 'd', 'i_balance' => 1, 's_note' => 'overwritten'], ['i_balance']);
pin('a column left out of the update list survives a clash', 'keep me', $qb()->where('s_key', 'd')->value('s_note'));
pin('...while the named one changed', '1', (string) $qb()->where('s_key', 'd')->value('i_balance'));

$threw = '';
try {
    $qb()->upsert([]);
} catch (DbException $e) {
    $threw = 'refused';
}
pin('an empty row is refused', 'refused', $threw);

$admin->query('DROP TABLE IF EXISTS ' . $t);

exit(harness_result());
