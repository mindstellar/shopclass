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
 * t_item_stats.i_num_saved: how many wishlists hold a listing. Wishlist writes move it by the
 * change, erasing a user takes it back, it never goes below 0, and migration 0090 counts it
 * from the stored wishlists and can run again.
 *
 * Usage:  php tests/models/wishlist-saved-count.php
 *         php tests/run-models.php wishlist-saved-count
 */

use mindstellar\database\Connection;
use mindstellar\listing\WishlistService;
use mindstellar\listing\WishlistStore;

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_wishlist_saved_count');
$p     = DB_TABLE_PREFIX;

$cat = seed_category($admin, 'Goods');
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$a   = seed_item($admin, $cat, null, 'Alpha');
$b   = seed_item($admin, $cat, null, 'Beta');
$c   = seed_item($admin, $cat, null, 'Gamma');

$saved = static function (int ...$ids) use ($admin, $p): array {
    $out = array();
    foreach ($ids as $id) {
        $row   = $admin->query("SELECT i_num_saved FROM {$p}t_item_stats WHERE fk_i_item_id = $id")->fetch_row();
        $out[] = $row === null ? null : (int) $row[0];
    }

    return $out;
};
$wishlist = new WishlistService();

harness_section('wishlist writes');

$wishlist->add($sue, $a);
pin('adding a listing counts it once', array(1, 0, 0), $saved($a, $b, $c));
$wishlist->add($sue, $a);
pin('adding it again changes nothing', array(1, 0, 0), $saved($a, $b, $c));
$wishlist->add($tom, $a);
pin('another user adds one more', array(2, 0, 0), $saved($a, $b, $c));
$wishlist->remove($sue, $a);
pin('removing it takes one off', array(1, 0, 0), $saved($a, $b, $c));
$wishlist->remove($sue, $a);
pin('removing one that is not there changes nothing', array(1, 0, 0), $saved($a, $b, $c));

$wishlist->replace($sue, array($a, $b));
pin('replace counts the new ones', array(2, 1, 0), $saved($a, $b, $c));
$wishlist->replace($sue, array($c, $b));
pin('replace moves by the difference only', array(1, 1, 1), $saved($a, $b, $c));
$wishlist->replace($sue, array());
pin('an empty list takes all of them back', array(1, 0, 0), $saved($a, $b, $c));

$admin->query("UPDATE {$p}t_item_stats SET i_num_saved = 0 WHERE fk_i_item_id = $a");
$wishlist->remove($tom, $a);
pin('it never goes below 0', array(0, 0, 0), $saved($a, $b, $c));

harness_section('user erase');

$wishlist->replace($sue, array($a, $b));
$wishlist->replace($tom, array($b, $c));
pin('two lists', array(1, 2, 1), $saved($a, $b, $c));
WishlistService::erase($tom);
pin('erasing a user takes back every listing on their list', array(1, 1, 0), $saved($a, $b, $c));
pin('and their list is gone', array(), (new WishlistStore())->ids($tom));

harness_section('the read');

pin('savedCount reads the count', 1, $wishlist->savedCount($b));
pin('an unknown listing reads 0', 0, $wishlist->savedCount(999999));
$rows = (new \mindstellar\listing\ListingQuery())->statsAndLocations(array($b));
pin('the stats a loaded listing row is extended with carry the count', '1', $rows[0]['i_num_saved'] ?? null);

require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
View::getInstance()->_exportVariableToView('item', array('pk_i_id' => (string) $b, 'i_num_saved' => '7'));
pin('the helper reads the loaded row of the current item', 7, osc_item_saved_count());
pin('and the table for another listing', 1, osc_item_saved_count($a));
View::getInstance()->_exportVariableToView('item', array('pk_i_id' => (string) $b));
pin('and the table when the row has no count', 1, osc_item_saved_count());

harness_section('migration 0090');

$mig = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0090_item_stats_saved.php';
$admin->query("ALTER TABLE {$p}t_item_stats DROP COLUMN i_num_saved");
$admin->query("DELETE FROM {$p}t_key_value WHERE s_group = 'wishlist'");
$users = array();
for ($i = 0; $i < 1205; $i++) {
    $users[] = 100000 + $i;
}
$values = array();
foreach ($users as $n => $user) {
    $list     = $n % 2 === 0 ? array($a, $b) : array($b);
    $values[] = "('wishlist', '$user', '" . json_encode($list) . "', NOW())";
}
$values[] = "('wishlist', '200000', '[\"$c\",$c,\"x\",-1,999999]', NOW())";
$values[] = "('wishlist', '200001', 'not json', NOW())";
$values[] = "('cart', '200002', '[$c]', NOW())";
$admin->query("INSERT INTO {$p}t_key_value (s_group, s_key, s_value, dt_created) VALUES " . implode(', ', $values));

$mig->up(Connection::getInstance());
$expect = array(603, 1205, 1);
pin('the backfill counts every stored list, past the first batch; bad ids and other groups are skipped', $expect, $saved($a, $b, $c));

$admin->query("UPDATE {$p}t_item_stats SET i_num_saved = 50");
$mig->up(Connection::getInstance());
pin('a second run sets the same counts, not double', $expect, $saved($a, $b, $c));
pin('and adds no second column', 1, $admin->query("SHOW COLUMNS FROM {$p}t_item_stats LIKE 'i_num_saved'")->num_rows);

$admin->query("DELETE FROM {$p}t_key_value WHERE s_group = 'wishlist' AND s_key = '200000'");
$mig->up(Connection::getInstance());
pin('a listing on no list goes back to 0', array(603, 1205, 0), $saved($a, $b, $c));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
