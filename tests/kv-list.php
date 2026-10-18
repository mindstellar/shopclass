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
 * osc_kv_list(): a group's live keys in key order, paged by the last key, expired rows skipped,
 * filtered by last write, values decoded, and one group never leaks into another.
 * Usage: php tests/kv-list.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_kv_list');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
$table = DB_TABLE_PREFIX . 't_key_value';

foreach (['a', 'b', 'c', 'd', 'e'] as $i => $key) {
    osc_kv_set('lst', $key, ['n' => $i]);
}
osc_kv_set('other', 'a', 'x');
osc_kv_set('lst', 'gone', 1, 3600);
$admin->query("UPDATE $table SET dt_expires = '2000-01-01 00:00:00' WHERE s_key = 'gone'");

harness_section('paging');
$keys = static fn (array $rows): array => array_column($rows, 'key');
pin('rows come in key order without the expired one', ['a', 'b', 'c', 'd', 'e'], $keys(osc_kv_list('lst')));
$first = osc_kv_list('lst', 2);
pin('a page holds the limit', ['a', 'b'], $keys($first));
pin('the next page starts after the last key', ['c', 'd'], $keys(osc_kv_list('lst', 2, null, 'b')));
pin('the last page is short', ['e'], $keys(osc_kv_list('lst', 2, null, 'd')));
pin('past the end is empty', [], osc_kv_list('lst', 2, null, 'e'));
pin('values come back decoded', ['n' => 0], $first[0]['value']);
check('a live row without expiry has none', $first[0]['expires_at'] === null && is_int($first[0]['updated_at']));

harness_section('last write');
$admin->query("UPDATE $table SET dt_created = '2020-01-01 00:00:00' WHERE s_group = 'lst'");
$admin->query("UPDATE $table SET dt_updated = '2026-06-01 00:00:00' WHERE s_group = 'lst' AND s_key IN ('d', 'e')");
pin('only keys written before the date', ['a', 'b', 'c'], $keys(osc_kv_list('lst', 100, '2025-01-01 00:00:00')));
pin('an update counts over the creation date', ['a', 'b', 'c', 'd', 'e'], $keys(osc_kv_list('lst', 100, '2027-01-01 00:00:00')));
$refused = static function (callable $fn): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
};
check('a bad date, group or key is refused', $refused(static fn () => osc_kv_list('lst', 5, 'yesterday')) && $refused(static fn () => osc_kv_list('Bad Group')) && $refused(static fn () => osc_kv_list('lst', 5, null, '')));

harness_section('groups stay apart');
pin('the other group has its own row', ['a'], $keys(osc_kv_list('other')));

harness_section('the PK is used');
$plan = $admin->query("EXPLAIN SELECT s_key FROM $table WHERE s_group = 'lst' AND s_key > 'b' ORDER BY s_key LIMIT 10")->fetch_assoc();
pin('the query reads the primary key', 'PRIMARY', $plan['key']);

exit(harness_result());
