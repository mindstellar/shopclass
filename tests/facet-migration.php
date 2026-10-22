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
 * Migration 0083 (the listing filter index): both tables (terms and rows) come back after a
 * drop, existing listings are indexed and the ready flag is set, a second run changes nothing
 * and never lowers the flag, and a site past the inline limit queues the background build instead.
 *
 * Usage: php tests/facet-migration.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/facet-rows.php';

use mindstellar\database\Connection;
use mindstellar\search\FacetIndex;
use mindstellar\search\FacetTerms;

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
$terms     = FacetTerms::tableName();
$rows      = static fn (): array => $admin->query('SELECT s_key, s_value, fk_i_item_id, fk_i_variant_id FROM ' . facet_rows_sql() . ' x ORDER BY s_key, s_value, fk_i_item_id, fk_i_variant_id')->fetch_all(MYSQLI_NUM);
$tables    = static fn (): array => array_merge(...$admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('$facet', '$terms') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM));
$schema    = static function () use ($admin, $facet, $terms): array {
    return [$admin->query("SHOW CREATE TABLE $terms")->fetch_row()[1], $admin->query("SHOW CREATE TABLE $facet")->fetch_row()[1]];
};
$pref      = static function (string $name) use ($admin, $p): ?string {
    $r = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '$name'")->fetch_row();

    return $r ? $r[0] : null;
};
$jobs      = static fn (): array => $admin->query("SELECT s_payload FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status = 'pending'")->fetch_all(MYSQLI_NUM);
$reset     = static function () use ($admin, $p, $facet, $terms): void {
    $admin->query("DROP TABLE IF EXISTS $facet");
    $admin->query("DROP TABLE IF EXISTS $terms");
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

harness_section('first run on tables that are gone');
$reset();
pin('no table before', [], $tables());
$migration->up($conn);
pin('both tables made', [$terms, $facet], $tables());
check('rows name their term by id', str_contains($schema()[1], 'REFERENCES `' . $terms . '` (`pk_i_id`) ON DELETE CASCADE'));
pin('one term per (key, value)', 5, (int) $admin->query("SELECT COUNT(*) FROM $terms")->fetch_row()[0]);
$before = $schema();
$norm = static fn (array $r): array => array_map(static fn (array $x): array => [$x[0], $x[1], (int) $x[2], (int) $x[3]], $r);
pin('the listings are indexed', $expected, $norm($rows()));
pin('the flag is on', '1', $pref('facet_ready'));
check('the build time is a date, as markReady() writes it', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $pref('facet_built_at')));
pin('no background job', [], $jobs());
pin('a pack job is queued, as a job build queues one', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_PACK . "' AND s_status = 'pending'")->fetch_row()[0]);

harness_section('second run');
$migration->up($conn);
pin('same rows', $expected, $norm($rows()));
pin('same tables', $before, $schema());
pin('same terms', 5, (int) $admin->query("SELECT COUNT(*) FROM $terms")->fetch_row()[0]);
pin('the flag stays on', '1', $pref('facet_ready'));

$admin->query("UPDATE $terms SET s_value = 'Kept' WHERE s_key = 'oSize' AND s_value = 'L'");
$migration->up($conn);
pin('a ready flag over a filled table skips the build', 1, count(array_filter($rows(), static fn (array $r): bool => $r[1] === 'Kept')));
$admin->query("UPDATE $terms SET s_value = 'L' WHERE s_value = 'Kept'");

$admin->query("DELETE FROM $facet");
$migration->up($conn);
pin('a ready flag over an empty table, with listings, is rebuilt', $expected, $norm($rows()));
pin('and is not lowered', '1', $pref('facet_ready'));

$admin->query("DROP TABLE $facet");
$migration->up($conn);
pin('a ready flag restored without its table is rebuilt', $expected, $norm($rows()));
pin('and ends ready', '1', $pref('facet_ready'));

harness_section('past the inline limit');
$migration->up($conn);
$admin->query("DELETE FROM $facet");
$admin->query("UPDATE {$p}t_preference SET s_value = '1' WHERE s_name = 'facet_ready'");
$admin->query("DELETE FROM {$p}t_job_queue");
osc_reset_preferences();
$migration->build($conn, 2);
pin('nothing indexed', [], $rows());
pin('a ready flag over the empty table is lowered', ['0', false], [$pref('facet_ready'), FacetIndex::ready()]);
pin('one build job at the start', [['after' => 0]], array_map(static fn (array $r): array => json_decode($r[0], true), $jobs()));
$migration->build($conn, 2);
pin('a second call queues no second job', 1, count($jobs()));

harness_section('past the time budget');
$admin->query("DELETE FROM $facet");
$admin->query("DELETE FROM {$p}t_job_queue");
$admin->query("UPDATE {$p}t_preference SET s_value = '1' WHERE s_name = 'facet_ready'");
osc_reset_preferences();
$migration->build($conn, 100, 0.0, 2);
pin('the batch in hand is indexed', array_values(array_filter($expected, static fn (array $r): bool => $r[2] !== $items[3])), $norm($rows()));
pin('a ready flag over the empty table is lowered', ['0', false], [$pref('facet_ready'), FacetIndex::ready()]);
pin('the job starts after the last listing done', [['after' => $items[2]]], array_map(static fn (array $r): array => json_decode($r[0], true), $jobs()));
pin('the inline time is 20 seconds', 20.0, $migration::INLINE_SECONDS);

$admin->query("DELETE FROM $facet");
$admin->query("DELETE FROM {$p}t_job_queue");
$migration->build($conn, 100, 5.0, 2, microtime(true) - 10);
pin('time spent before the build counts too', [['after' => $items[2]]], array_map(static fn (array $r): array => json_decode($r[0], true), $jobs()));
pin('the clock is read every 20 listings', 20, $migration::INLINE_BATCH);

pin('the inline limit is 5000', 5000, $migration::INLINE_LIMIT);

exit(harness_result());
