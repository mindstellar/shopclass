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
 * Listing counters (user, category) and their hooks wait for the commit when a transaction is
 * open: unchanged inside it, changed after it, dropped on a rollback, and immediate with no
 * transaction. Covers ListingStats::increase, decrease and afterEdit, and the delete and
 * expiry-change paths of Item.
 * Usage: php tests/listing-counters-after-commit.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\database\Db;
use mindstellar\listing\ListingStats;

$admin = api_admin_boot('osc_listing_counters_after_commit');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
$cat    = seed_category($admin, 'Tees', null, $locale);
$cat2   = seed_category($admin, 'Mugs', null, $locale);
$sue    = seed_user($admin, 'sue', 'sue@example.test');
$admin->query("UPDATE {$p}t_category SET i_num_items = 5 WHERE pk_i_id IN ($cat, $cat2)");
$admin->query("UPDATE {$p}t_user SET i_items = 5 WHERE pk_i_id = $sue");

$catCount  = static fn (int $c): int => (int) $admin->query("SELECT i_num_items FROM {$p}t_category WHERE pk_i_id = $c")->fetch_row()[0];
$userCount = static fn (): int => (int) $admin->query("SELECT i_items FROM {$p}t_user WHERE pk_i_id = $sue")->fetch_row()[0];

$fired = ['up' => 0, 'down' => 0];
osc_add_hook('item_increase_stat', static function () use (&$fired) {
    $fired['up']++;
});
osc_add_hook('item_decrease_stat', static function () use (&$fired) {
    $fired['down']++;
});

$row = [
    'fk_i_user_id'      => $sue,
    'fk_i_category_id'  => $cat,
    'fk_c_country_code' => '',
    'fk_i_region_id'    => '',
    'fk_i_city_id'      => '',
];

harness_section('no transaction: immediate');
ListingStats::increase($row);
pin('category up at once', 6, $catCount($cat));
pin('user up at once', 6, $userCount());
pin('hook fired once', 1, $fired['up']);

harness_section('inside a transaction: waits for the commit');
Db::transaction(function () use ($row, &$fired, $catCount, $userCount, $cat) {
    ListingStats::increase($row);
    pin('category unchanged inside', 6, $catCount($cat));
    pin('user unchanged inside', 6, $userCount());
    pin('hook not fired inside', 1, $fired['up']);
});
pin('category up after commit', 7, $catCount($cat));
pin('user up after commit', 7, $userCount());
pin('hook fired after commit', 2, $fired['up']);

harness_section('rolled back: dropped');
try {
    Db::transaction(function () use ($row) {
        ListingStats::decrease($row);
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException $e) {
}
pin('category unchanged after rollback', 7, $catCount($cat));
pin('user unchanged after rollback', 7, $userCount());
pin('decrease hook not fired', 0, $fired['down']);

harness_section('decrease after commit');
Db::transaction(function () use ($row, &$fired, $catCount) {
    ListingStats::decrease($row);
    pin('category unchanged inside', 7, $catCount($GLOBALS['cat']));
});
pin('category down after commit', 6, $catCount($cat));
pin('decrease hook fired after commit', 1, $fired['down']);

harness_section('afterEdit: category move waits for the commit');
$old = ['b_enabled' => 1, 'b_active' => 1, 'b_spam' => 0, 'fk_i_user_id' => $sue, 'fk_i_category_id' => $cat];
$loc = ['fk_c_country_code' => '', 'fk_i_region_id' => '', 'fk_i_city_id' => ''];
$new = ['userId' => $sue, 'catId' => $cat2];
Db::transaction(function () use ($old, $loc, $new, $catCount, $cat, $cat2) {
    ListingStats::afterEdit(1, $old, false, $loc, $new, false, $loc);
    pin('old category unchanged inside', 6, $catCount($cat));
    pin('new category unchanged inside', 5, $catCount($cat2));
});
pin('old category down after commit', 5, $catCount($cat));
pin('new category up after commit', 6, $catCount($cat2));

harness_section('Item::deleteByPrimaryKey inside an outer transaction');
$item = seed_item($admin, $cat, $sue, 'Tee', 10.0);
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = $item");
$before = $catCount($cat);
Db::transaction(function () use ($item, $catCount, $before) {
    Item::getInstance()->deleteByPrimaryKey($item);
    pin('category unchanged inside', $before, $catCount($GLOBALS['cat']));
});
pin('category down after commit', $before - 1, $catCount($cat));

harness_section('two posts under one category at the same time');
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$start  = $catCount($cat);
[, $out] = race($admin, "UPDATE {$p}t_category SET i_num_items = i_num_items + 1 WHERE pk_i_id = $cat", $dbName, [[__DIR__ . '/lib/listing-counter-child.php', [$sue, $cat]]], '%t_category SET i_num_items%');
$printed = $out[0];
pin('the poster is not failed by the other post', 'ok', trim($printed));
pin('both posts are counted', $start + 2, $catCount($cat));

exit(harness_result());
