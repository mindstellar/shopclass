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
 * CategoryService on a 1500-category, 3000-listing tree costs the same number of queries as
 * on a 30-category tree, and each call stays under a second.
 *
 * Usage:  php tests/models/category-big-tree.php
 *         php tests/run-models.php category-big-tree
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\category\CategoryService;
use mindstellar\category\CategoryStore;
use mindstellar\utility\Utils;

$admin = api_admin_boot('osc_models_category_big_tree');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$user = seed_user($admin, 'sue', 'sue@example.test');
scratchdb_forget_cache();
osc_reset_preferences();

$service = CategoryService::make();

/**
 * Replace every category and listing with a tree of the given level sizes (each level's nodes
 * spread evenly over the level above) and two listings per category.
 *
 * @param int[] $levels
 *
 * @return array<int,int[]> ids by level, 1 = top
 */
$seed = static function (array $levels) use ($admin, $p, $user, $locale): array {
    $admin->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['t_item_stats', 't_item_location', 't_item_description', 't_item', 't_category_description', 't_category'] as $t) {
        $admin->query("DELETE FROM {$p}$t");
    }
    $admin->query('SET FOREIGN_KEY_CHECKS = 1');

    $byLevel = [];
    $rows    = [];
    $texts   = [];
    $id      = 0;
    foreach ($levels as $depth => $size) {
        for ($i = 0; $i < $size; $i++) {
            $id++;
            $parent = $depth === 0 ? 'NULL' : (string) $byLevel[$depth][$i % count($byLevel[$depth])];
            $rows[]  = "($id, $parent, $i, 1, 1)";
            $texts[] = "($id, '$locale', 'Category $id', '', 'category-$id')";
            $byLevel[$depth + 1][] = $id;
        }
    }
    $admin->query("INSERT INTO {$p}t_category (pk_i_id, fk_i_parent_id, i_position, b_enabled, b_price_enabled) VALUES " . implode(',', $rows));
    $admin->query("INSERT INTO {$p}t_category_description (fk_i_category_id, fk_c_locale_code, s_name, s_description, s_slug) VALUES " . implode(',', $texts));

    $items = [];
    for ($c = 1; $c <= $id; $c++) {
        for ($k = 0; $k < 2; $k++) {
            $items[] = "($user, $c, NOW(), NOW(), NOW(), 1, 1, 'USD', 'Contact', 'c@example.test', '127.0.0.1', 0, 1, 1, 0, 's$c-$k', DATE_ADD(NOW(), INTERVAL 30 DAY))";
        }
    }
    foreach (array_chunk($items, 1000) as $chunk) {
        $admin->query("INSERT INTO {$p}t_item (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_first_pub_date, dt_mod_date, f_price, i_price,
            fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium, b_enabled, b_active, b_spam, s_secret, dt_expiration) VALUES "
            . implode(',', $chunk));
    }
    Utils::updateAllCategoriesStats();
    CategoryStore::forget();
    osc_invalidate_category_cache();

    return $byLevel;
};

/**
 * Query count and seconds for one call, starting from a cold tree memo.
 *
 * @return array{0:int,1:float}
 */
$measure = static function (callable $fn): array {
    CategoryStore::forget();
    $start = microtime(true);
    $count = harness_query_count($fn);

    return [$count, microtime(true) - $start];
};

/**
 * Run the calls on the current tree. $siblings leaves are first put under the move target, so
 * a per-sibling query in move() shows as a higher count on the big tree.
 */
$runAll = static function (array $byLevel, int $siblings) use ($service, $measure, $admin, $p): array {
    $all    = array_merge(...array_values($byLevel));
    $ten    = array_map(static fn (int $i): int => $all[intdiv(count($all) * $i, 10)], range(0, 9));
    $fifty  = array_map(static fn (int $i): int => $all[intdiv(count($all) * $i, 50)], range(0, 49));
    $root   = $byLevel[1][0];
    $third  = $byLevel[3][0];
    $target = $byLevel[1][1];
    $leaves = implode(',', array_slice(end($byLevel), 0, $siblings));
    $admin->query("UPDATE {$p}t_category SET fk_i_parent_id = $target WHERE pk_i_id IN ($leaves)");

    return [
        'move'           => $measure(static fn () => $service->move($third, $target, 0)),
        'disable'        => $measure(static fn () => $service->setEnabled($root, false, ['fk_i_parent_id' => null])),
        'bulk enable'    => $measure(static fn () => $service->bulk('enable', [$root])),
        'delete preview' => $measure(static fn () => $service->deletePreview($ten)),
        'expiry keep'    => $measure(static fn () => $service->setExpiry($fifty, 30, false, false)),
        'expiry subtree' => $measure(static fn () => $service->setExpiry([$root], 20, false, true)),
        'bulk expiry'    => $measure(static fn () => $service->bulk('expiry', [$root], ['days' => 10, 'newDates' => false, 'toSubcategories' => true])),
    ];
};

harness_section('a 30-category tree');
$small = $runAll($seed([2, 3, 5, 8, 12]), 3);
pin('the small tree has 30 categories', 30, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_category")->fetch_row()[0]);

harness_section('a 1500-category tree');
$levels = $seed([5, 15, 60, 300, 1120]);
pin('1500 categories over 5 levels', [1500, 5], [(int) $admin->query("SELECT COUNT(*) FROM {$p}t_category")->fetch_row()[0], count($levels)]);
pin('and 3000 listings', 3000, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0]);
$disabled = count((new \mindstellar\category\CategoryTree(CategoryStore::parentMap()))->subtree($levels[1][0]));
check('the disabled subtree is a few hundred nodes', $disabled >= 250, (string) $disabled);
$big = $runAll($levels, 300);

harness_section('the same number of queries, each under a second');
foreach ($small as $name => [$count, $seconds]) {
    check("$name: {$small[$name][0]} queries on 30, {$big[$name][0]} on 1500", $big[$name][0] >= 0 && $big[$name][0] <= $count);
    check("$name: under a second on 1500", $big[$name][1] < 1.0, sprintf('%.3fs', $big[$name][1]));
}
pin('the subtree is back on after the bulk enable', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_category WHERE b_enabled = 0")->fetch_row()[0]);
pin('and the subtree says 10 days', count(CategoryStore::tree()->subtree($levels[1][0])), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_category WHERE i_expiration_days = 10")->fetch_row()[0]);

exit(harness_result());
