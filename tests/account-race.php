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
 * Account writes that run while another request holds one of the account's rows: a profile save,
 * an admin block, a delete and a sign-up confirmation wait for the row and then succeed. Guards
 * against MariaDB's error 1020 (snapshot isolation), which a read before the lock triggers.
 * Usage: php tests/account-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

$admin = api_admin_boot('osc_account_race');
$p     = DB_TABLE_PREFIX;
$db    = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat   = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$child = __DIR__ . '/lib/account-race-child.php';
$one   = static fn (string $sql) => $admin->query($sql)->fetch_row()[0];
$run   = static fn (string $hold, string $action, int $user, string $wait): array => race($admin, $hold, $db, [[$child, [$action, $user]]], $wait);

harness_section('profile save');
$sue = seed_user($admin, 'sue', 'sue@example.test');
[$waiting, $out] = $run("UPDATE {$p}t_user SET i_comments = i_comments + 5 WHERE pk_i_id = $sue", 'profile', $sue, "SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = % FOR UPDATE");
pin('the save waited on the user row', 1, $waiting);
pin('and succeeded', ["ok\n"], $out);
pin('the new username is stored', 'renamed', $one("SELECT s_username FROM {$p}t_user WHERE pk_i_id = $sue"));
pin('and the other change is kept', '5', $one("SELECT i_comments FROM {$p}t_user WHERE pk_i_id = $sue"));

harness_section('admin block');
$tom  = seed_user($admin, 'tom', 'tom@example.test');
$mug  = seed_item($admin, $cat, $tom, 'Mug', 5.0);
$tee  = seed_item($admin, $cat, $tom, 'Tee', 5.0);
[$waiting, $out] = $run("UPDATE {$p}t_item SET s_ip = '10.0.0.1' WHERE pk_i_id = $tee", 'disable', $tom, "SELECT pk_i_id FROM {$p}t_item WHERE fk_i_user_id = % FOR UPDATE");
pin('the block waited on the listing', 1, $waiting);
pin('and succeeded', ["ok\n"], $out);
pin('the user is blocked', '0', $one("SELECT b_enabled FROM {$p}t_user WHERE pk_i_id = $tom"));
pin('and both listings are disabled', '2', $one("SELECT COUNT(*) FROM {$p}t_item WHERE fk_i_user_id = $tom AND b_enabled = 0"));

harness_section('delete');
$ann = seed_user($admin, 'ann', 'ann@example.test');
$cap = seed_item($admin, $cat, $ann, 'Cap', 5.0);
seed_item($admin, $cat, $ann, 'Hat', 5.0);
[$waiting, $out] = $run("UPDATE {$p}t_item SET s_ip = '10.0.0.2' WHERE pk_i_id = $cap", 'delete', $ann, "SELECT pk_i_id FROM {$p}t_item WHERE fk_i_user_id = % FOR UPDATE");
pin('the delete waited on the listing', 1, $waiting);
pin('and succeeded', ["ok\n"], $out);
pin('the user is gone', '0', $one("SELECT COUNT(*) FROM {$p}t_user WHERE pk_i_id = $ann"));
pin('and so are the listings', '0', $one("SELECT COUNT(*) FROM {$p}t_item WHERE fk_i_user_id = $ann"));

harness_section('sign-up confirmation');
$bob   = seed_user($admin, 'bob', 'bob@example.test', 0);
$admin->query("UPDATE {$p}t_user SET s_secret = '" . \mindstellar\security\ActionToken::hash('code') . "' WHERE pk_i_id = $bob");
$guest = seed_item($admin, $cat, null, 'Guest', 5.0);
$admin->query("UPDATE {$p}t_item SET s_contact_email = 'bob@example.test' WHERE pk_i_id = $guest");
[$waiting, $out] = $run("UPDATE {$p}t_item SET s_ip = '10.0.0.3' WHERE pk_i_id = $guest", 'confirm', $bob, "SELECT pk_i_id FROM {$p}t_item WHERE s_contact_email = % FOR UPDATE");
pin('the confirmation waited on the guest listing', 1, $waiting);
pin('and succeeded', ["ok\n"], $out);
pin('the account is active', '1', $one("SELECT b_active FROM {$p}t_user WHERE pk_i_id = $bob"));
pin('and the guest listing is the account\'s', (string) $bob, $one("SELECT fk_i_user_id FROM {$p}t_item WHERE pk_i_id = $guest"));

exit(harness_result());
