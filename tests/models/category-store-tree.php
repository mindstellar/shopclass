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
 * CategoryStore tree reads and writes: the parent map is one query at any size, and
 * reorder and the bulk setters are one statement each.
 *
 * Usage:  php tests/models/category-store-tree.php      (standalone, own scratch database)
 *         php tests/run-models.php category-store-tree  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_category_store_tree');

use mindstellar\category\CategoryStore;

$prefix = DB_TABLE_PREFIX;

$seedMany = static function (int $n) use ($admin, $prefix): void {
    $admin->query('DELETE FROM ' . $prefix . 't_category');
    $rows = array();
    for ($i = 1; $i <= $n; $i++) {
        $parent = $i > 10 ? (int) (($i - 1) / 10) : 'NULL';
        $rows[] = '(' . $i . ', ' . $parent . ', 0, 0, 1, 1)';
    }
    $admin->query(
        'INSERT INTO ' . $prefix . 't_category (pk_i_id, fk_i_parent_id, i_expiration_days, i_position, b_enabled, b_price_enabled) VALUES '
        . implode(',', $rows)
    );
    CategoryStore::forget();
};

harness_section('parentMap');
foreach (array(20, 1500) as $n) {
    $seedMany($n);
    $map = array();
    $q   = harness_query_count(static function () use (&$map): void {
        $map = CategoryStore::parentMap();
    });
    pin("first read is one query at $n rows", 1, $q);
    pin("map has $n entries", $n, count($map));
    $q = harness_query_count(static function (): void {
        CategoryStore::parentMap();
    });
    pin("second read is no query at $n rows", 0, $q);
}
pin('top level maps to null', null, CategoryStore::parentMap()[1]);
pin('a child maps to its parent', 1, CategoryStore::parentMap()[11]);

harness_section('Writes clear the memo');
CategoryStore::setParent(11, null);
pin('setParent is seen by the next read', null, CategoryStore::parentMap()[11]);
CategoryStore::forget();
$q = harness_query_count(static function (): void {
    CategoryStore::parentMap();
});
pin('forget() makes the next read a query', 1, $q);

harness_section('reorder');
$seedMany(300);
$ids = range(300, 1);
$q   = harness_query_count(static function () use ($ids): void {
    CategoryStore::reorder($ids);
});
pin('reordering 300 ids is one query', 1, $q);
$res = $admin->query('SELECT pk_i_id, i_position FROM ' . $prefix . 't_category');
$ok  = true;
$n   = 0;
while ($r = $res->fetch_assoc()) {
    $n++;
    $ok = $ok && (int) $r['i_position'] === 300 - (int) $r['pk_i_id'];
}
check('positions run 0..299 in the given order', $ok && $n === 300);
pin('an empty list runs nothing', 0, harness_query_count(static function (): void {
    CategoryStore::reorder(array());
}));
pin('siblings follow i_position', array(10, 9, 8, 7, 6, 5, 4, 3, 2, 1), CategoryStore::siblings(null));

harness_section('Bulk setters');
pin('setEnabled is one query', 1, harness_query_count(static function () use ($ids): void {
    CategoryStore::setEnabled($ids, true);
}));
$res = $admin->query('SELECT COUNT(*) c FROM ' . $prefix . 't_category WHERE b_enabled = 1');
pin('all rows switched on', 300, (int) $res->fetch_assoc()['c']);
pin('setExpirationDays is one query', 1, harness_query_count(static function () use ($ids): void {
    CategoryStore::setExpirationDays($ids, 30);
}));
$res = $admin->query('SELECT COUNT(*) c FROM ' . $prefix . 't_category WHERE i_expiration_days = 30');
pin('all rows got the days', 300, (int) $res->fetch_assoc()['c']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
