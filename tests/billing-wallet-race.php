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
 * Wallet writes racing another write of the same wallet: a credits order paid while the user
 * spends, and a held listing published while a credit purchase lands. Each waits on the wallet
 * row and then succeeds; on MariaDB 11.6+ (snapshot isolation ON) a read before that lock fails
 * the write with error 1020.
 * Usage: php tests/billing-wallet-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Billing;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Wallet;
use mindstellar\database\Db;

$admin  = api_admin_boot('osc_billing_wallet_race');
$p      = DB_TABLE_PREFIX;
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', null, 'en_US', 1, 30);
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_receipt_email', '0', 'osclass', 'BOOLEAN');
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();

$hold = static fn (int $uid, int $delta): string => "UPDATE {$p}t_billing_wallet SET i_balance = i_balance + ($delta) WHERE fk_i_user_id = $uid";

harness_section('a credits order paid while the user spends');
$uid = seed_user($admin, 'buyer', 'buyer@example.test');
Wallet::credit($uid, 5, Wallet::REASON_GRANT);
$order = OrderStore::create($uid, 'offline', 5000000, 'USD', 10);
[$waiting, $out] = race($admin, $hold($uid, -1), $dbName, [[__DIR__ . '/lib/order-pay-child.php', [$order->getId(), 'pay']]], "%{$p}t_billing_wallet%");
pin('the payment waited on the wallet row', 1, $waiting);
pin('it is paid without an error', "taken\n", $out[0]);
pin('the balance has both changes', 14, Wallet::balance($uid));
pin('and the ledger row records it', '14', $admin->query("SELECT i_balance_after FROM {$p}t_billing_ledger WHERE s_idempotency_key = 'order:{$order->getId()}'")->fetch_row()[0] ?? null);

harness_section('a held listing published while a credit purchase lands');
$seller = seed_user($admin, 'seller', 'seller@example.test');
seed_item($admin, $cat, $seller, 'Live');
$held = seed_item($admin, $cat, $seller, 'Held');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");
Wallet::credit($seller, 5, Wallet::REASON_GRANT);
[$waiting, $out] = race($admin, $hold($seller, 5), $dbName, [[__DIR__ . '/lib/listing-publish-child.php', [$held, $seller]]], "%{$p}t_billing_wallet%");
pin('the publish waited on the wallet row', 1, $waiting);
pin('it publishes without an error', "published\n", $out[0]);
pin('the purchase and the spend are both in the balance', 7, Wallet::balance($seller));
pin('the listing is live', '1', $admin->query("SELECT b_active FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_row()[0]);

harness_section('a first credit while another request creates the wallet');
$fresh = seed_user($admin, 'fresh', 'fresh@example.test');
pin('the user has no wallet row yet', '0', $admin->query("SELECT COUNT(*) FROM {$p}t_billing_wallet WHERE fk_i_user_id = $fresh")->fetch_row()[0]);
[$waiting, $out] = race($admin, "INSERT INTO {$p}t_billing_wallet (fk_i_user_id, i_balance, dt_mod_date) VALUES ($fresh, 4, NOW())", $dbName, [[__DIR__ . '/lib/wallet-credit-child.php', [$fresh, 3]]], "%{$p}t_billing_wallet%");
pin('the credit waited on the new row', 1, $waiting);
pin('it credits without an error', "credited\n", $out[0]);
pin('the balance has both', 7, Wallet::balance($fresh));

harness_section('locking the wallet of a user who has none');
$none = seed_user($admin, 'none', 'none@example.test');
Db::transaction(static function () use ($none): void {
    Wallet::lock($none);
});
pin('the row exists with a zero balance', 0, Wallet::balance($none));
pin('and is a single row', '1', $admin->query("SELECT COUNT(*) FROM {$p}t_billing_wallet WHERE fk_i_user_id = $none")->fetch_row()[0]);

harness_section('a ledger row written inside a transaction leaves no snapshot open');
$spender = seed_user($admin, 'spender', 'spender@example.test');
Wallet::credit($spender, 5, Wallet::REASON_GRANT);
$row = seed_item($admin, $cat, $spender, 'Other');
$ok  = true;
try {
    Db::transaction(static function () use ($admin, $p, $spender, $row): void {
        Wallet::debit($spender, 2, Wallet::REASON_SPEND);
        $admin->query("UPDATE {$p}t_item SET s_contact_name = 'changed meanwhile' WHERE pk_i_id = $row");
        Db::execute("UPDATE {$p}t_item SET s_contact_name = 'ours' WHERE pk_i_id = $row");
    });
} catch (\Throwable $e) {
    $ok = false;
}
pin('a write to a row another request changed meanwhile still succeeds', true, $ok);

exit(harness_result());
