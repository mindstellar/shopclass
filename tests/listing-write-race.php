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
 * Listing writes lock their rows before they read, so a row another request changes meanwhile
 * makes them wait instead of failing with error 1020 (MariaDB 11.6+, snapshot isolation ON):
 * delete (t_item_stats row), edit (variant row), activate, enable, spam, premium and photo add
 * (t_item row), and a post counting its rate limit once its transaction has committed.
 * Usage: php tests/listing-write-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\listing\VariantStore;
use mindstellar\security\ActionThrottle;
use mindstellar\security\RateLimit;

$admin = api_admin_boot('osc_listing_write_race');
$p     = DB_TABLE_PREFIX;
$db    = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'title_character_length' => '100',
    'description_character_length' => '5000', 'moderate_admin_edit' => '1', 'map_type' => 'openstreet', 'numImages@items' => '3', 'maxSizeKb' => '2048',
    'allowedExt' => 'png,gif,jpg,jpeg,webp', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'dimNormal' => '640x480', 'dimPreview' => '480x340', 'dimThumbnail' => '240x200'] as $k => $v) {
    osc_set_preference($k, $v, 'osclass', 'STRING');
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';
Params::init();

$uploads = sys_get_temp_dir() . '/osc-listing-write-race-' . getmypid();
@mkdir($uploads, 0777, true);
register_shutdown_function(static function () use ($uploads): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($uploads);
});

$strict = race_snapshot_isolation($admin);
$child  = __DIR__ . '/lib/listing-write-child.php';

/**
 * Hold $lockSql, run one child, wait until the server has it waiting on a lock, release.
 *
 * @return array{0:int,1:string} how many transactions waited, and what the child printed
 */
$raceOne = static function (string $lockSql, string $mode, int $item) use ($admin, $db, $child, $sue, $uploads): array {
    race_hold($admin, $lockSql);
    $run     = race_run($child, $db, [$mode, $item, $sue, $uploads]);
    $waiting = 0;
    for ($try = 0; $try < 200 && $waiting < 1; $try++) {
        usleep(50000);
        // A statement that has run for a second is stuck on the held lock.
        $waiting = (int) $admin->query(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '$db' AND COMMAND = 'Query' AND TIME >= 1"
        )->fetch_row()[0];
    }
    $out = race_release($admin, [$run]);

    // A notice the legacy code prints on stderr follows the answer.
    preg_match('/^(ok|false|threw.*)$/m', $out[0], $m);

    return [$waiting, $m[1] ?? trim($out[0])];
};
$listing = static function (int $active = 1) use ($admin, $cat, $sue): int {
    $id = seed_item($admin, $cat, $sue, 'Tee', 10.0, $active);
    VariantStore::saveDefault($id, ['i_stock' => 5]);

    return $id;
};
$value = static fn (string $sql) => $admin->query($sql)->fetch_row()[0] ?? null;

harness_section('delete waits for the stats row');
$id = $listing();
[$waiting, $out] = $raceOne("UPDATE {$p}t_item_stats SET i_num_views = i_num_views + 1 WHERE fk_i_item_id = $id", 'delete', $id);
pin('the delete waited on the held row', 1, $waiting);
pin('and then succeeded', 'ok', $out);
pin('the listing is gone', '0', (string) $value("SELECT COUNT(*) FROM {$p}t_item WHERE pk_i_id = $id"));

harness_section('edit waits for a variant row');
$id      = $listing();
$variant = (int) $value("SELECT pk_i_id FROM {$p}t_item_variant WHERE fk_i_item_id = $id");
[$waiting, $out] = $raceOne("UPDATE {$p}t_item_variant SET i_stock = i_stock - 1 WHERE pk_i_id = $variant", 'edit', $id);
pin('the edit waited on the held row', 1, $waiting);
pin('and then succeeded', 'ok', $out);
pin('the stock is the edit, not lost', '7', (string) $value("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $variant"));

harness_section('moderation and photos wait for the listing row');
$touch = static fn (int $id): string => "UPDATE {$p}t_item SET dt_mod_date = DATE_ADD(dt_mod_date, INTERVAL 1 SECOND) WHERE pk_i_id = $id";
$id = $listing(0);
[$waiting, $out] = $raceOne($touch($id), 'activate', $id);
pin('activate waited, then succeeded', [1, 'ok', '1'], [$waiting, $out, (string) $value("SELECT b_active FROM {$p}t_item WHERE pk_i_id = $id")]);
$id = $listing(1);
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = $id");
[$waiting, $out] = $raceOne($touch($id), 'enable', $id);
pin('enable waited, then succeeded', [1, 'ok', '1'], [$waiting, $out, (string) $value("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id = $id")]);
$id = $listing(1);
[$waiting, $out] = $raceOne($touch($id), 'spam', $id);
pin('spam waited, then succeeded', [1, 'ok', '1'], [$waiting, $out, (string) $value("SELECT b_spam FROM {$p}t_item WHERE pk_i_id = $id")]);
$id = $listing(1);
[$waiting, $out] = $raceOne($touch($id), 'premium', $id);
pin('premium waited, then succeeded', [1, 'ok', '1'], [$waiting, $out, (string) $value("SELECT b_premium FROM {$p}t_item WHERE pk_i_id = $id")]);
$id = $listing(1);
[$waiting, $out] = $raceOne($touch($id), 'photo', $id);
pin('a photo add waited, then succeeded', [1, 'ok', '1'], [$waiting, $out, (string) $value("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item' AND i_owner_id = $id")]);

harness_section('a post counts its rate limit after it commits');
$posts = static fn (): string => (string) $value('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_item');
// Start of a ten-second slice, so the first count and the child's land on one row.
while (time() % RateLimit::SLICE !== 0) {
    usleep(100000);
}
ActionThrottle::record('item_post', 60);
$before = (int) $posts();
[$waiting, $out] = $raceOne('UPDATE ' . $p . 't_rate_counter' . ' SET i_count = i_count + 1', 'post', $cat);
pin('the post waited on the held counter', 1, $waiting);
pin('and then succeeded', 'ok', $out);
pin('with its listing saved', $before + 1, (int) $posts());

exit(harness_result());
