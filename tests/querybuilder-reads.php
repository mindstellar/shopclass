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
 * The reads a theme needed raw SQL for: table aliases, a counted join grouped by
 * listing, and IS NULL tests. Each runs against a real database.
 *
 * Usage:  php tests/querybuilder-reads.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root -- the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_querybuilder_reads');

use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;

$admin->query('DROP TABLE IF EXISTS qb_items');
$admin->query('DROP TABLE IF EXISTS qb_res');
$admin->query(
    'CREATE TABLE qb_items (pk_i_id INT UNSIGNED NOT NULL, s_title VARCHAR(40) NOT NULL,'
    . ' s_note VARCHAR(40) NULL, PRIMARY KEY (pk_i_id)) ENGINE=InnoDB'
);
$admin->query(
    'CREATE TABLE qb_res (pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT, fk_i_item_id INT UNSIGNED NOT NULL,'
    . ' PRIMARY KEY (pk_i_id)) ENGINE=InnoDB'
);
$admin->query("INSERT INTO qb_items VALUES (1, 'bike', NULL), (2, 'car', 'x'), (3, 'boat', NULL)");
$admin->query('INSERT INTO qb_res (fk_i_item_id) VALUES (1), (1), (2)');

/** A fresh builder on the listing table, aliased. */
$items = static function (): QueryBuilder {
    return new QueryBuilder('qb_items AS i');
};

harness_section('Aliases');

pin(
    'an aliased table compiles to a quoted alias',
    'SELECT `i`.`s_title` FROM `qb_items` AS `i`',
    $items()->select('i.s_title')->toSql()
);
pin('an aliased read returns rows', array('bike', 'boat', 'car'), array_column(
    $items()->select('i.s_title')->orderBy('i.s_title')->get(),
    's_title'
));
pin('count honours an alias', 3, $items()->count());
pin('an aggregate honours an alias', '3', (string) $items()->max('i.pk_i_id'));

foreach (array('qb_items AS i.x', 'qb_items AS `i`', 'qb_items AS i; DROP', 'qb_items i', 'qb_items AS') as $bad) {
    $threw = '';
    try {
        new QueryBuilder($bad);
    } catch (DbException $e) {
        $threw = 'refused';
    }
    pin("a bad alias is refused: {$bad}", 'refused', $threw);
}

foreach (array('insert' => array('pk_i_id' => 9, 's_title' => 'z'), 'update' => array('s_title' => 'z')) as $write => $row) {
    $threw = '';
    try {
        $items()->where('i.pk_i_id', 9)->{$write}($row);
    } catch (DbException $e) {
        $threw = $e->getMessage();
    }
    pin("{$write} on an aliased table is refused", 'An aliased table is read-only', $threw);
}

harness_section('A counted join');

$rows = $items()
    ->select('i.pk_i_id')
    ->selectRaw('COUNT(r.pk_i_id) AS n_pic')
    ->leftJoin('qb_res AS r', 'r.fk_i_item_id', '=', 'i.pk_i_id')
    ->groupBy('i.pk_i_id')
    ->orderBy('i.pk_i_id')
    ->get();
pin('selectRaw counts per listing through an aliased join', array('1' => '2', '2' => '1', '3' => '0'), array_column(
    array_map(static fn ($r) => array_map('strval', $r), $rows),
    'n_pic',
    'pk_i_id'
));

$grouped = $items()
    ->selectRaw('COUNT(r.pk_i_id) AS n_pic')
    ->leftJoin('qb_res AS r', 'r.fk_i_item_id', '=', 'i.pk_i_id')
    ->groupBy('i.pk_i_id');
pin('count() of a grouped query counts the groups', 3, $grouped->count());
pin('...and a HAVING on a selectRaw name still counts', 1, $grouped->having('n_pic', '>', 1)->count());

// A selectRaw placeholder binds before the where's, in the order they appear.
$q = $items()->selectRaw('i.pk_i_id + ? AS shifted', array(100))->where('i.pk_i_id', 2);
pin('selectRaw bindings come before where bindings', array(100, 2), $q->getBindings());
pin('...and the query uses them in that order', '102', (string) ($q->first()['shifted'] ?? ''));
pin('selectRaw alone does not fall back to *', 'SELECT 1 AS one FROM `qb_items` AS `i`', $items()->selectRaw('1 AS one')->toSql());

harness_section('NULL tests');

pin('whereNull finds the empty notes', array('1', '3'), array_map('strval', array_column(
    $items()->whereNull('i.s_note')->orderBy('i.pk_i_id')->get(),
    'pk_i_id'
)));
pin('whereNotNull finds the filled one', array('2'), array_map('strval', array_column(
    $items()->whereNotNull('i.s_note')->get(),
    'pk_i_id'
)));
pin('orWhereNull joins with OR', 2, $items()->where('i.pk_i_id', 1)->orWhereNull('i.s_note')->count());
pin('orWhereNotNull joins with OR', 2, $items()->where('i.pk_i_id', 1)->orWhereNotNull('i.s_note')->count());
pin(
    'whereNull compiles to IS NULL',
    'SELECT * FROM `qb_items` AS `i` WHERE `i`.`s_note` IS NULL',
    $items()->whereNull('i.s_note')->toSql()
);

$threw = '';
try {
    $items()->whereNull('s_note; DROP');
} catch (DbException $e) {
    $threw = 'refused';
}
pin('whereNull validates its column', 'refused', $threw);

pin('an empty whereIn matches nothing', 0, $items()->whereIn('i.pk_i_id', array())->count());

harness_section('Keyset pages and LIKE');

pin('newestBefore: newest first, below the id given', [['3', '2'], ['1']], [
    array_map('strval', array_column($items()->newestBefore(null, 2, 'i.pk_i_id'), 'pk_i_id')),
    array_map('strval', array_column($items()->newestBefore(2, 2, 'i.pk_i_id'), 'pk_i_id')),
]);
pin('escapeLike keeps %, _ and \\ literal', '50\\% off\\_now\\\\', QueryBuilder::escapeLike('50% off_now\\'));

$admin->query('DROP TABLE IF EXISTS qb_items');
$admin->query('DROP TABLE IF EXISTS qb_res');

exit(harness_result());
