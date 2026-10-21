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

exit(harness_result());
