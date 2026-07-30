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
 * Characterization pins for the Currency model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * findByPrimaryKey() moves to the parameterized query layer.
 *
 * Currency's only custom method is a findByPrimaryKey() override backed by a
 * process-lifetime static map, so the cache semantics are as much of the
 * contract as the query is: a second lookup must be served without touching the
 * database and must return a value identical to the first. A miss must NOT be
 * cached, or a currency added later in the same request would stay invisible.
 *
 * tests/dao-contract.php also pins this override (and uses Currency for the DAO
 * base deleteByPrimaryKey shape, because Region overrides that one). It must
 * stay green alongside this file.
 *
 * Usage:  php tests/models/currency.php          (standalone, own scratch database)
 *         php tests/run-models.php currency      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_currency');

/**
 * Empty the static currency map.
 *
 * The runner truncates tables between test files but cannot reach a private
 * static property, so a cache populated by an earlier file would survive into
 * this one and serve rows for a table that has since been emptied. Every pin
 * below that depends on a cold cache resets it first.
 */
$resetCache = static function (): void {
    $prop = new ReflectionProperty('Currency', '_currencies');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
};

$seedCurrency = static function (string $code, string $name, int $enabled = 1) use ($admin): void {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_currency (pk_c_code, s_name, s_description, b_enabled)
         VALUES (?, ?, ?, ?)',
        'sssi',
        array($code, $name, $name . ' description', $enabled)
    );
};

$resetCache();
$seedCurrency('USD', 'US Dollar');
$seedCurrency('EUR', 'Euro', 0);

$model = Currency::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('Currency: public surface');

pin(
    'findByPrimaryKey signature is unchanged',
    'public findByPrimaryKey($value)',
    harness_method_signature('Currency', 'findByPrimaryKey')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Currency', 'newInstance'));
check('Currency still extends DAO', is_subclass_of('Currency', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', DB_TABLE_PREFIX . 't_currency', $model->getTableName());
pin('primary key is unchanged', 'pk_c_code', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_c_code', 's_name', 's_description', 'b_enabled'),
    $model->getFields()
);
pin(
    'the model overrides exactly one base method',
    array('__construct', 'findByPrimaryKey', 'newInstance'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Currency')),
        array('__construct', 'newInstance', 'findByPrimaryKey')
    ))
);

/* ----------------------------------------------------------------------------
 * findByPrimaryKey — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('findByPrimaryKey — match');

$resetCache();
$row = $model->findByPrimaryKey('USD');

check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the four schema columns', array('pk_c_code', 's_name', 's_description', 'b_enabled'), array_keys($row));
pin('pk_c_code round-trips', 'USD', $row['pk_c_code']);
pin('s_name round-trips', 'US Dollar', $row['s_name']);
pin('b_enabled is the string "1", not int 1 (C4)', '1', $row['b_enabled']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

$disabled = $model->findByPrimaryKey('EUR');
pin('a disabled currency is still returned — the lookup does not filter on b_enabled', 'EUR', $disabled['pk_c_code']);
pin('its b_enabled is the string "0"', '0', $disabled['b_enabled']);

harness_section('findByPrimaryKey — no match');

$resetCache();
pin('an unknown code returns bool false', false, $model->findByPrimaryKey('QQQ'));
pin('the empty string returns bool false', false, $model->findByPrimaryKey(''));

/* A null code built a comparison with no right-hand side, failing the query;
 * a bound null is valid SQL matching no rows. Both branches return false here,
 * so the two converge — pinned so that stays true. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null code returns bool false', false, $model->findByPrimaryKey(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * The static cache (C9) — as much of the contract as the query.
 * ------------------------------------------------------------------------- */
harness_section('findByPrimaryKey — the static cache');

$resetCache();

$firstCost = harness_query_count(static function () use ($model) {
    $model->findByPrimaryKey('USD');
});
pin('a cold lookup costs one query', 1, $firstCost);

$secondCost = harness_query_count(static function () use ($model) {
    $model->findByPrimaryKey('USD');
});
pin('a repeat lookup costs zero queries — it is served from the map', 0, $secondCost);

$a = $model->findByPrimaryKey('USD');
$b = $model->findByPrimaryKey('USD');
check('the cached value is identical to the first result', $a === $b);

/* The cache is keyed per code, so one warm entry must not serve another. */
$resetCache();
$model->findByPrimaryKey('USD');
$eurCost = harness_query_count(static function () use ($model) {
    $model->findByPrimaryKey('EUR');
});
pin('a different code still costs its own query', 1, $eurCost);

/* A miss is deliberately not cached: a repeat lookup queries again, so a row
 * inserted later in the same request becomes visible rather than being masked
 * by a cached negative. */
$resetCache();
$model->findByPrimaryKey('GBP');
$missCost = harness_query_count(static function () use ($model) {
    $model->findByPrimaryKey('GBP');
});
pin('a miss is not cached — the second lookup queries again', 1, $missCost);

$seedCurrency('GBP', 'Pound Sterling');
$nowFound = $model->findByPrimaryKey('GBP');
check('a currency added after a failed lookup is then found', is_array($nowFound) && $nowFound['pk_c_code'] === 'GBP');

/* The cache is not invalidated by writes through the model — a stale row is
 * served for the rest of the process. Pinned as the existing contract. */
$resetCache();
$model->findByPrimaryKey('USD');
$admin->query("UPDATE " . DB_TABLE_PREFIX . "t_currency SET s_name = 'Renamed' WHERE pk_c_code = 'USD'");
pin('a warm entry survives an external update — the map is never invalidated', 'US Dollar', $model->findByPrimaryKey('USD')['s_name']);

$resetCache();
pin('after a reset the new value is read', 'Renamed', $model->findByPrimaryKey('USD')['s_name']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/currency.php */
