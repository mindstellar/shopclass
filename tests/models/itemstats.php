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
 * Characterization pins for the ItemStats model.
 *
 * t_item_stats holds one row per listing — running totals, 1:1 with t_item — and
 * the time series lives beside it in t_item_stats_daily, one row per (day, write
 * bucket) for the whole site. Before that split the counters were keyed by date
 * as well as by listing, so the table grew with page views rather than with
 * listings and every reader had to aggregate a listing's whole history back into
 * one number. Nothing ever read a single listing's count for a single day.
 *
 * What the split changed, and what these pins therefore had to be rewritten for:
 *
 *   - A listing has ONE row. emptyRow() collides on the second call for the same
 *     listing rather than on the second call for the same listing and day, and
 *     getViews() reads a total instead of summing dated rows.
 *   - increase() writes TWICE: the listing's row, then the rollup. Both are
 *     pinned, including the ordering guarantee that a failed listing write skips
 *     the rollup — a count for a listing that does not exist must not reach the
 *     chart.
 *   - The rollup picks its bucket at random, so every read of it here sums the
 *     buckets for a date rather than expecting a particular row.
 *
 * What deliberately did NOT change, and is pinned to stay that way: the public
 * signatures, the column allowlist (including the dt_date rejection and the
 * i_num_expired entry), the rejected-input query costs, and the three distinct
 * getViews() return values. That last one is the subtle survivor —
 *
 *   getViews(<no row>)   SQL NULL, because SUM() with no GROUP BY returns one
 *                        row whose aggregate is NULL when nothing matched
 *   getViews(<zero row>) the string "0", a real counter that happens to be zero
 *   getViews(null)       int 0, the SQL-error fallback and not a zero-row match
 *
 * — and it is why getViews() still says SUM() over what is now a primary-key
 * lookup. Callers distinguish those three.
 *
 * Usage:  php tests/models/itemstats.php          (standalone, own scratch database)
 *         php tests/run-models.php itemstats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemstats');
$table = DB_TABLE_PREFIX . 't_item_stats';
$daily = DB_TABLE_PREFIX . 't_item_stats_daily';

$model = ItemStats::newInstance();

$catId = seed_category($admin, 'Motors');
$itemA = seed_item($admin, $catId, null, 'Item A');
$itemB = seed_item($admin, $catId, null, 'Item B');
$itemC = seed_item($admin, $catId, null, 'Item C');

/** Row count in t_item_stats, read with raw mysqli. */
$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

/**
 * A single listing's stats row, read with raw mysqli.
 *
 * Deliberately unprepared (mysqli::query(), not a bound statement): a prepared
 * read returns native-typed columns, which would make this verification read
 * disagree with the all-string legacy row it is meant to check against (the
 * trap is in the test, not the model — see the harness's typed-row notes).
 * $itemId is a fixture id this file controls, never external input.
 */
$rowFor = static function (int $itemId) use ($admin, $table): ?array {
    $row = $admin->query("SELECT * FROM $table WHERE fk_i_item_id = $itemId")->fetch_assoc();

    return $row ?: null;
};

/**
 * Today's rollup total for one counter, summed across the write buckets.
 *
 * The bucket is chosen at random per write, so which row a given increment
 * landed on is not knowable — only the total for the date is.
 */
$dailyTotal = static function (string $column) use ($admin, $daily) {
    $row = $admin->query("SELECT SUM($column) t FROM $daily WHERE dt_date = CURDATE()")->fetch_assoc();

    return $row['t'];
};

/** How many bucket rows exist for today. */
$dailyRows = static function () use ($admin, $daily): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $daily WHERE dt_date = CURDATE()")->fetch_assoc()['c'];
};

$truncate = static function () use ($admin, $table, $daily): void {
    $admin->query("TRUNCATE TABLE $table");
    $admin->query("TRUNCATE TABLE $daily");
};

/** Set a preference and drop the model layer's cached copy. */
$setPref = static function (string $key, string $value): void {
    // Stats prefs live in the shared osclass section (consolidated from the old
    // 'stats' section); seed where osc_item_views_enabled() now reads.
    osc_set_preference($key, $value, 'osclass', 'BOOLEAN');
    osc_reset_preferences();
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('ItemStats: public surface');

pin(
    'increase signature is unchanged',
    'public increase($column, $itemId)',
    harness_method_signature('ItemStats', 'increase')
);
pin(
    'increaseBatch takes a column and an array of ids',
    'public increaseBatch($column, array $itemIds)',
    harness_method_signature('ItemStats', 'increaseBatch')
);
pin('emptyRow signature is unchanged', 'public emptyRow($itemId)', harness_method_signature('ItemStats', 'emptyRow'));
pin('getViews signature is unchanged', 'public getViews($itemId)', harness_method_signature('ItemStats', 'getViews'));
pin('getAllViews signature is unchanged', 'public getAllViews()', harness_method_signature('ItemStats', 'getAllViews'));
pin(
    'purgeOlderThan signature',
    'public purgeOlderThan($date)',
    harness_method_signature('ItemStats', 'purgeOlderThan')
);
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('ItemStats', 'newInstance')
);
check('ItemStats still extends DAO', is_subclass_of('ItemStats', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('the rollup table sits beside it', $daily, $model->dailyTableName());
pin('primary key is unchanged', 'fk_i_item_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged — dt_date survives the split as a plain column',
    array(
        'fk_i_item_id',
        'i_num_views',
        'i_num_spam',
        'i_num_repeated',
        'i_num_bad_classified',
        'i_num_offensive',
        'i_num_expired',
        'i_num_premium_views',
        'dt_date',
    ),
    $model->getFields()
);
$declared = array(
    '__construct',
    'dailyTableName',
    'emptyRow',
    'getAllViews',
    'getViews',
    'increase',
    'increaseBatch',
    'newInstance',
    'purgeOlderThan',
);
pin(
    'the model declares exactly these public methods of its own',
    $declared,
    array_values(array_intersect(array_keys(harness_public_method_map('ItemStats')), $declared))
);

/* seed_item() writes its own stats row per listing — clear it so every section
 * below starts from a known, empty table. */
$truncate();

/* ----------------------------------------------------------------------------
 * increase() — rejected input, before any query is issued.
 * ------------------------------------------------------------------------- */
harness_section('increase — rejected column');

pin('the primary key column is rejected', false, $model->increase('fk_i_item_id', $itemA));
pin('the date column is rejected', false, $model->increase('dt_date', $itemA));
pin('an unknown column is rejected', false, $model->increase('not_a_column', $itemA));
pin('the empty string column is rejected', false, $model->increase('', $itemA));
pin('nothing was written by any rejected column', 0, $rowCount());
pin('a rejected column costs no queries at all', 0, harness_query_count(static function () use ($model, $itemA) {
    $model->increase('not_a_column', $itemA);
}));

harness_section('increase — rejected item id');

pin('a non-numeric item id is rejected', false, $model->increase('i_num_views', 'abc'));
pin('a null item id is rejected', false, $model->increase('i_num_views', null));
pin('the empty string item id is rejected', false, $model->increase('i_num_views', ''));
pin('nothing was written by any rejected item id', 0, $rowCount());
pin('a rejected item id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increase('i_num_views', 'abc');
}));

/* ----------------------------------------------------------------------------
 * increase() — a well-formed call whose listing does not exist. t_item_stats
 * carries a FOREIGN KEY on fk_i_item_id, so the upsert fails at the database.
 * The rollup write must not follow it: a view for a listing that does not exist
 * has no business reaching the reports chart.
 * ------------------------------------------------------------------------- */
harness_section('increase — well-formed call, unknown item id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->increase('i_num_views', 999999);
error_reporting($prevLevel);

pin('an unknown item id returns bool false', false, $ret);
pin('nothing was written for it', 0, $rowCount());
pin('and nothing reached the rollup either', 0, $dailyRows());
pin('the failed statement still costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $model->increase('i_num_views', 999999);
    error_reporting($prev);
}));

/* ----------------------------------------------------------------------------
 * increase() — the listing's own counter.
 * ------------------------------------------------------------------------- */
harness_section('increase — first call for a listing');

$truncate();
pin('a fresh listing returns bool true', true, $model->increase('i_num_views', $itemA));
$row = $rowFor($itemA);
check('the row was created', is_array($row));
pin('i_num_views started at 1', '1', $row['i_num_views']);
pin('every other counter stayed at its default 0', '0', $row['i_num_spam']);
pin('dt_date records today as the last activity', date('Y-m-d'), $row['dt_date']);
pin('exactly one row exists', 1, $rowCount());

harness_section('increase — subsequent calls, same column');

pin('a repeat call also returns bool true', true, $model->increase('i_num_views', $itemA));
pin('the counter incremented to 2', '2', $rowFor($itemA)['i_num_views']);
pin('still exactly one row — it incremented, it did not duplicate', 1, $rowCount());

harness_section('increase — a different column, same listing');

pin('a different column also returns bool true', true, $model->increase('i_num_spam', $itemA));
$row = $rowFor($itemA);
pin('the new column started at 1', '1', $row['i_num_spam']);
pin('the earlier column is untouched', '2', $row['i_num_views']);
pin('still one row for this listing', 1, $rowCount());

harness_section('increase — i_num_expired is accepted');

pin('i_num_expired is accepted', true, $model->increase('i_num_expired', $itemA));
pin('i_num_expired started at 1', '1', $rowFor($itemA)['i_num_expired']);

harness_section('increase — a second listing is independent');

pin('a different listing is a fresh insert again', true, $model->increase('i_num_views', $itemB));
pin('the new listing counter starts at 1', '1', $rowFor($itemB)['i_num_views']);
pin('the first listing is untouched', '2', $rowFor($itemA)['i_num_views']);
pin('both rows now exist', 2, $rowCount());

/* ----------------------------------------------------------------------------
 * increase() — the site-wide rollup that runs alongside it.
 * ------------------------------------------------------------------------- */
harness_section('increase — the daily rollup');

$truncate();
$model->increase('i_num_views', $itemA);
pin("one increment puts 1 into today's rollup", '1', $dailyTotal('i_num_views'));

$model->increase('i_num_views', $itemA);
$model->increase('i_num_views', $itemB);
pin('three increments across two listings total 3 for the site', '3', $dailyTotal('i_num_views'));
pin('the listings still hold their own separate totals', '2', $rowFor($itemA)['i_num_views']);

$model->increase('i_num_spam', $itemA);
pin('a different counter lands in its own rollup column', '1', $dailyTotal('i_num_spam'));
pin('without disturbing the views column', '3', $dailyTotal('i_num_views'));

check(
    'the rollup holds at most one row per write bucket for the date (' . $dailyRows() . ')',
    $dailyRows() >= 1 && $dailyRows() <= 8
);

harness_section('increase — query cost');

pin('an insert costs two queries: the listing row, then the rollup', 2, harness_query_count(
    static function () use ($model, $itemC) {
        $model->increase('i_num_premium_views', $itemC);
    }
));
pin('so does an increment', 2, harness_query_count(static function () use ($model, $itemC) {
    $model->increase('i_num_premium_views', $itemC);
}));

/* ----------------------------------------------------------------------------
 * increaseBatch() — the premium block's write. Its whole reason to exist is that
 * the cost does not grow with the number of listings on the page.
 * ------------------------------------------------------------------------- */
harness_section('increaseBatch — rejected input');

$truncate();
pin('an unknown column is rejected', false, $model->increaseBatch('not_a_column', array($itemA)));
pin('the date column is rejected', false, $model->increaseBatch('dt_date', array($itemA)));
pin('nothing was written by a rejected column', 0, $rowCount());
pin('an empty list is accepted and does nothing', true, $model->increaseBatch('i_num_premium_views', array()));
pin('an empty list writes nothing', 0, $rowCount());
pin('an empty list costs no queries', 0, harness_query_count(static function () use ($model) {
    $model->increaseBatch('i_num_premium_views', array());
}));

harness_section('increaseBatch — many listings at once');

pin('a batch returns bool true', true, $model->increaseBatch('i_num_premium_views', array($itemA, $itemB, $itemC)));
pin('every listing in the batch got a row', 3, $rowCount());
pin('each counted once', '1', $rowFor($itemA)['i_num_premium_views']);
pin('including the last one', '1', $rowFor($itemC)['i_num_premium_views']);
pin('the rollup got the whole batch in one go', '3', $dailyTotal('i_num_premium_views'));

pin('a second batch increments rather than duplicating', true, $model->increaseBatch(
    'i_num_premium_views',
    array($itemA, $itemB)
));
pin('the repeated listing is at 2', '2', $rowFor($itemA)['i_num_premium_views']);
pin('the one left out stayed at 1', '1', $rowFor($itemC)['i_num_premium_views']);
pin('still three rows', 3, $rowCount());
pin('the rollup accumulated the second batch too', '5', $dailyTotal('i_num_premium_views'));

harness_section('increaseBatch — a repeated id counts once');

$truncate();
$model->increaseBatch('i_num_premium_views', array($itemA, $itemA, $itemA));
pin('the same id three times in one call still counts once', '1', $rowFor($itemA)['i_num_premium_views']);
pin('and reaches the rollup once', '1', $dailyTotal('i_num_premium_views'));

harness_section('increaseBatch — query cost does not grow with the batch');

$truncate();
pin('one listing costs two queries', 2, harness_query_count(static function () use ($model, $itemA) {
    $model->increaseBatch('i_num_premium_views', array($itemA));
}));
pin('three listings cost the same two queries', 2, harness_query_count(
    static function () use ($model, $itemA, $itemB, $itemC) {
        $model->increaseBatch('i_num_premium_views', array($itemA, $itemB, $itemC));
    }
));

/* ----------------------------------------------------------------------------
 * The traffic toggle. Only the two counters written on every render can be
 * switched off; the moderation counters drive the reported-listings screen and
 * the report threshold, so turning those off would break moderation rather than
 * save space.
 * ------------------------------------------------------------------------- */
harness_section('view counting switched off');

$truncate();
$setPref('item_views_enabled', '0');

pin('increase() still reports success', true, $model->increase('i_num_views', $itemA));
pin('but wrote nothing', 0, $rowCount());
pin('and cost no queries', 0, harness_query_count(static function () use ($model, $itemA) {
    $model->increase('i_num_views', $itemA);
}));
pin('premium views are off too', true, $model->increase('i_num_premium_views', $itemA));
pin('still nothing written', 0, $rowCount());
pin('a batch of premium views is a no-op as well', true, $model->increaseBatch(
    'i_num_premium_views',
    array($itemA, $itemB)
));
pin('which wrote nothing either', 0, $rowCount());

pin('a moderation counter is NOT affected by the toggle', true, $model->increase('i_num_spam', $itemA));
pin('it wrote its row', '1', $rowFor($itemA)['i_num_spam']);
pin('and reached the rollup', '1', $dailyTotal('i_num_spam'));

$setPref('item_views_enabled', '1');
$truncate();
pin('turning it back on restores counting', true, $model->increase('i_num_views', $itemA));
pin('which wrote again', '1', $rowFor($itemA)['i_num_views']);

/* ----------------------------------------------------------------------------
 * getViews() — the return ledger. SUM() with no GROUP BY always returns one
 * row, so "no matching row" and "a matching row whose count is 0" are genuinely
 * different observable results (NULL vs the string "0").
 * ------------------------------------------------------------------------- */
harness_section('getViews — setup: known counts per listing');

$truncate();
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, CURDATE(), 12)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date) VALUES ($itemB, CURDATE())"); // i_num_views defaults to 0

harness_section('getViews — a listing with a count');

pin('the total comes back as the string "12"', '12', $model->getViews($itemA));

harness_section('getViews — a listing whose count is genuinely 0');

pin('a real zero-count row returns the string "0", not null', '0', $model->getViews($itemB));

harness_section('getViews — a listing with no stats row at all');

pin('no matching row returns null, not 0 and not the string "0"', null, $model->getViews(999999));

harness_section('getViews — a non-numeric item id matches nothing (no SQL error)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->getViews('abc');
error_reporting($prevLevel);
pin('a non-numeric item id also returns null, it does not error', null, $ret);

harness_section('getViews — a null item id (the null-where divergence)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->getViews(null);
error_reporting($prevLevel);
pin('a null item id returns int 0, NOT null (this is the SQL-error fallback, not a zero-row match)', 0, $ret);

harness_section('getViews — query cost');

pin('a matching lookup costs exactly one query', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->getViews($itemA);
}));
$nullCost = harness_query_count(static function () use ($model) {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    $model->getViews(null);
    error_reporting($prev);
});
check('a null item id costs no more than the legacy one failed query (' . $nullCost . ')', $nullCost <= 1);

/* ----------------------------------------------------------------------------
 * getAllViews() — same aggregate shape, no WHERE at all.
 * ------------------------------------------------------------------------- */
harness_section('getAllViews — empty table');

$truncate();
pin('an empty table returns null, not 0', null, $model->getAllViews());

harness_section('getAllViews — sums across every listing');

$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, CURDATE(), 5)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemB, CURDATE(), 10)");
pin('the total across two listings is the string "15"', '15', $model->getAllViews());

$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemC, CURDATE(), 3)");
pin('a third listing is included too: "18"', '18', $model->getAllViews());

harness_section('getAllViews — query cost');

pin('one call costs exactly one query', 1, harness_query_count(static function () use ($model) {
    $model->getAllViews();
}));

/* ----------------------------------------------------------------------------
 * purgeOlderThan() — retention for the rollup. It never touches the per-listing
 * totals, which are bounded by the listing count and are not history.
 * ------------------------------------------------------------------------- */
harness_section('purgeOlderThan');

$truncate();
$admin->query("INSERT INTO $daily (dt_date, i_bucket, i_num_views) VALUES ('2020-01-01', 0, 5)");
$admin->query("INSERT INTO $daily (dt_date, i_bucket, i_num_views) VALUES ('2020-06-01', 0, 7)");
$admin->query("INSERT INTO $daily (dt_date, i_bucket, i_num_views) VALUES (CURDATE(), 0, 9)");
$admin->query("INSERT INTO $table (fk_i_item_id, dt_date, i_num_views) VALUES ($itemA, '2020-01-01', 5)");

pin('an empty date is refused and removes nothing', 0, $model->purgeOlderThan(''));
pin('it removes only the rollup rows before the cutoff', 2, $model->purgeOlderThan(date('Y-m-d')));
pin("today's rollup survived", 1, $dailyRows());
pin('and the per-listing totals were not touched', 1, $rowCount());

/* ----------------------------------------------------------------------------
 * emptyRow() — pinned as a regression guard only; it delegates straight to
 * DAO::insert(), the base method, which is out of scope for this effort.
 * ------------------------------------------------------------------------- */
harness_section('emptyRow — regression guard (not converted)');

$truncate();
pin('a fresh listing insert returns bool true', true, $model->emptyRow($itemA));
$row = $rowFor($itemA);
check('the row was created', is_array($row));
pin('i_num_views defaults to 0', '0', $row['i_num_views']);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->emptyRow($itemA);
error_reporting($prevLevel);
pin('a duplicate insert for the same listing returns bool false (PK collision)', false, $ret);
pin('still exactly one row — the duplicate did not land', 1, $rowCount());

/* ----------------------------------------------------------------------------
 * sumByUser() — one aggregate over a user's listings, replacing the walk-and-
 * hydrate-every-item pattern a dashboard would otherwise need.
 * ------------------------------------------------------------------------- */
harness_section('ItemStats::sumByUser');

pin(
    'sumByUser signature is unchanged',
    'public sumByUser($column, $userId, $liveOnly = true)',
    harness_method_signature('ItemStats', 'sumByUser')
);

$truncate();
$seller  = seed_user($admin, 'seller', 'seller@example.test');
$other   = seed_user($admin, 'other', 'other@example.test');
$live1   = seed_item($admin, $catId, $seller, 'Seller live 1');
$live2   = seed_item($admin, $catId, $seller, 'Seller live 2');
$hidden  = seed_item($admin, $catId, $seller, 'Seller hidden');
$foreign = seed_item($admin, $catId, $other, 'Other seller');
// Hide one of the seller's listings; give each listing a distinct view count.
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_enabled = 0 WHERE pk_i_id = ' . (int)$hidden);
foreach (array($live1 => 10, $live2 => 7, $hidden => 100, $foreign => 5) as $id => $views) {
    $admin->query('UPDATE ' . $table . ' SET i_num_views = ' . (int)$views . ' WHERE fk_i_item_id = ' . (int)$id);
}

pin('sumByUser: live-only sums the seller\'s visible listings', 17, $model->sumByUser('i_num_views', $seller));
pin('sumByUser: liveOnly=false includes the hidden listing', 117, $model->sumByUser('i_num_views', $seller, false));
pin('sumByUser: a different user is counted separately', 5, $model->sumByUser('i_num_views', $other));
pin('sumByUser: a user with no listings sums to 0', 0, $model->sumByUser('i_num_views', 999999));
pin('sumByUser: a non-whitelisted column is rejected as 0', 0, $model->sumByUser('i_num_views); DROP', $seller));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemstats.php */
