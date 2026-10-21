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
 * Migration 0083 (the listing filter index): the table comes back after a drop, existing
 * listings are indexed and the ready flag is set, a second run changes nothing and never
 * lowers the flag, and a site past the inline limit queues the background build instead.
 *
 * Usage: php tests/facet-migration.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\database\Connection;
use mindstellar\search\FacetIndex;

$admin = api_admin_boot('osc_facet_migration');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);

$field = static function (string $slug, string $type) use ($admin, $p): int {
    return seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type) VALUES (?, ?, ?)", 'sss', [$slug, $slug, $type]);
};
$colour = $field('colour', 'DROPDOWN');
$gift   = $field('gift', 'CHECKBOX');

$items = [];
foreach ([1, 2, 3] as $n) {
    $items[$n] = seed_item($admin, $cat, null, "Mug $n");
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, '')", 'iis', [$items[$n], $colour, $n === 3 ? 'Blue' : 'Red']);
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, '')", 'iis', [$items[$n], $gift, $n === 1 ? '1' : '0']);
}
$variant = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$items[2], '{"Size":"M"}']);
$variant2 = seed_exec($admin, "INSERT INTO {$p}t_item_variant (fk_i_item_id, s_options) VALUES (?, ?)", 'is', [$items[2], '{"Size":"L"}']);

$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0083_item_facet.php';
$conn      = Connection::getInstance();
$facet     = FacetIndex::tableName();
$rows      = static fn (): array => $admin->query("SELECT s_key, s_value, fk_i_item_id, fk_i_variant_id FROM $facet ORDER BY s_key, s_value, fk_i_item_id, fk_i_variant_id")->fetch_all(MYSQLI_NUM);
$pref      = static function (string $name) use ($admin, $p): ?string {
    $r = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '$name'")->fetch_row();

    return $r ? $r[0] : null;
};
$jobs      = static fn (): array => $admin->query("SELECT s_payload FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status = 'pending'")->fetch_all(MYSQLI_NUM);
$reset     = static function () use ($admin, $p, $facet): void {
    $admin->query("DROP TABLE IF EXISTS $facet");
    $admin->query("DELETE FROM {$p}t_preference WHERE s_name IN ('facet_ready', 'facet_built_at')");
    $admin->query("DELETE FROM {$p}t_job_queue");
    osc_reset_preferences();
};
$expected = [
    ['f' . $colour, 'Blue', $items[3], 0],
    ['f' . $colour, 'Red', $items[1], 0],
    ['f' . $colour, 'Red', $items[2], 0],
    ['f' . $gift, '1', $items[1], 0],
    ['oSize', 'L', $items[2], $variant2],
    ['oSize', 'M', $items[2], $variant],
];

harness_section('first run on a table that is gone');
$reset();
$migration->up($conn);
$norm = static fn (array $r): array => array_map(static fn (array $x): array => [$x[0], $x[1], (int) $x[2], (int) $x[3]], $r);
pin('the listings are indexed', $expected, $norm($rows()));
pin('the flag is on', '1', $pref('facet_ready'));
check('the build time is set', (int) $pref('facet_built_at') > 0);
pin('no background job', [], $jobs());

harness_section('second run');
$migration->up($conn);
pin('same rows', $expected, $norm($rows()));
pin('the flag stays on', '1', $pref('facet_ready'));

$admin->query("DELETE FROM $facet");
$migration->up($conn);
pin('a ready flag skips the build', [], $rows());
pin('and is not lowered', '1', $pref('facet_ready'));

$admin->query("DROP TABLE $facet");
$migration->up($conn);
pin('a ready flag restored without its table is rebuilt', $expected, $norm($rows()));
pin('and ends ready', '1', $pref('facet_ready'));

harness_section('past the inline limit');
$migration->up($conn);
$admin->query("DELETE FROM $facet");
$admin->query("UPDATE {$p}t_preference SET s_value = '0' WHERE s_name = 'facet_ready'");
$admin->query("DELETE FROM {$p}t_job_queue");
osc_reset_preferences();
$migration->build($conn, 2);
pin('nothing indexed', [], $rows());
pin('the flag stays off', '0', $pref('facet_ready'));
pin('one build job at the start', [['after' => 0]], array_map(static fn (array $r): array => json_decode($r[0], true), $jobs()));
$migration->build($conn, 2);
pin('a second call queues no second job', 1, count($jobs()));
pin('the inline limit is 20000', 20000, $migration::INLINE_LIMIT);

exit(harness_result());
