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
 * Two comments by one user at once: the user's comment count ends up two higher. Guards the
 * count against MariaDB's error 1020 (snapshot isolation), which a read-then-write count hits.
 * Usage: php tests/comment-count-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

$admin = api_admin_boot('osc_comment_count_race');
$p     = DB_TABLE_PREFIX;
$db    = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat  = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue  = seed_user($admin, 'sue', 'sue@example.test');
$tom  = seed_user($admin, 'tom', 'tom@example.test');
$mug  = seed_item($admin, $cat, $sue, 'Mug', 5.0);
$count = static fn (int $user): int => (int) $admin->query("SELECT i_comments FROM {$p}t_user WHERE pk_i_id = $user")->fetch_row()[0];
$child = __DIR__ . '/lib/comment-count-child.php';

harness_section('two comments at once');
[$waiting, $out] = race(
    $admin,
    "UPDATE {$p}t_user SET i_items = i_items WHERE pk_i_id = $tom",
    $db,
    [[$child, [$mug, $tom]], [$child, [$mug, $tom]]],
    "SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = % FOR UPDATE"
);
pin('both posts waited on the user', 2, $waiting);
pin('both are saved', ["ok\n", "ok\n"], $out);
pin('the user has two more comments', 2, $count($tom));
pin('and two rows are written', 2, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_comment WHERE fk_i_user_id = $tom")->fetch_row()[0]);

harness_section('a change to the user row while a comment lands');
[$waiting, $out] = race(
    $admin,
    "UPDATE {$p}t_user SET i_items = i_items + 1 WHERE pk_i_id = $sue",
    $db,
    [[$child, [$mug, $sue]]],
    "SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = % FOR UPDATE"
);
pin('the post waited on the user', 1, $waiting);
pin('it is saved', ["ok\n"], $out);
pin('and counted', 1, $count($sue));

exit(harness_result());
