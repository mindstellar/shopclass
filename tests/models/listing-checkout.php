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
 * Pins for paying at the end of posting: the listing order kind and the link between a held
 * listing and its open order.
 *
 * Usage:  php tests/models/listing-checkout.php      (standalone, own scratch database)
 *         php tests/run-models.php listing-checkout   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_listing_checkout');

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

use mindstellar\auth\Actor;
use mindstellar\billing\Billing;
use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Wallet;
use mindstellar\exception\ForbiddenException;

$userId = seed_user($admin, 'seller', 'seller@example.test');

harness_section('Orders: listing kind');

$listing = OrderStore::create($userId, 'fake', 3000000, 'USD', 3, array(
    '_kind'  => Order::KIND_LISTING,
    '_item'  => 7,
    '_label' => 'Listing: Red car',
));
pin('a listing order reads as a listing', true, $listing->isListing());
pin('and not as goods', false, $listing->isGoods());
pin('its listing id', 7, $listing->listingId());
pin('its label is the one given', 'Listing: Red car', $listing->getLabel());

$credits = OrderStore::create($userId, 'fake', 5000000, 'USD', 5);
pin('a credits order is not a listing order', false, $credits->isListing());
pin('and has no listing id', null, $credits->listingId());

$fromRow = OrderStore::find($listing->getId());
pin('the kind survives a reload', 7, $fromRow->listingId());

harness_section('Orders: listing link');

pin('no link, no order', null, OrderStore::listingOrder(7));
OrderStore::linkListing(7, $listing->getId(), time() + 3600);
pin('a linked listing finds its order', $listing->getId(), OrderStore::listingOrder(7)?->getId());
pin('another listing finds none', null, OrderStore::listingOrder(8));

OrderStore::unlinkListing(7);
pin('unlinked, no order', null, OrderStore::listingOrder(7));

OrderStore::linkListing(7, $listing->getId(), time() - 60);
pin('a lapsed link finds none', null, OrderStore::listingOrder(7));

OrderStore::linkListing(7, 999999, time() + 3600);
pin('a link to a missing order finds none', null, OrderStore::listingOrder(7));

harness_section('Pay step: set-up');

$p = DB_TABLE_PREFIX;
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
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_slot();

$seq    = 0;
$seller = static function (int $credits) use ($admin, $cat, &$seq): array {
    $seq++;
    $uid  = seed_user($admin, 'payer' . $seq, 'payer' . $seq . '@example.test');
    $live = seed_item($admin, $cat, $uid, 'Live ' . $seq);
    $held = seed_item($admin, $cat, $uid, 'Held ' . $seq);
    holdItem($held);
    if ($credits > 0) {
        Wallet::credit($uid, $credits, Wallet::REASON_GRANT);
    }

    return [$uid, $live, $held];
};
function holdItem(int $id): void
{
    global $admin, $p;
    $admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$id}");
}
$row = static function (int $id) use ($admin, $p): array {
    return $admin->query("SELECT b_enabled, b_active, dt_first_pub_date, dt_expiration FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc();
};
$debits = static function (int $uid) use ($admin, $p): int {
    return (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_ledger WHERE fk_i_user_id = {$uid} AND s_ref_type = 'item'")->fetch_row()[0];
};

harness_section('Pay step: quote');

[$uid, $live, $held] = $seller(0);
pin('no credits: one slot, three credits, all short', ['held' => true, 'live' => false, 'slots' => 1, 'credits' => 3, 'balance' => 0, 'shortfall' => 3], ListingCheckout::quote($held, $uid));
Wallet::credit($uid, 5, Wallet::REASON_GRANT);
$q = ListingCheckout::quote($held, $uid);
pin('five credits cover three', [3, 5, 0], [$q['credits'], $q['balance'], $q['shortfall']]);
$q = ListingCheckout::quote($live, $uid);
pin('a live listing is live, held no more, and costs nothing', [false, true, 0, 0], [$q['held'], $q['live'], $q['slots'], $q['credits']]);

$second = seed_item($admin, $cat, $uid, 'Second held');
holdItem($second);
pin('a second held listing does not raise the first price', 1, ListingCheckout::quote($held, $uid)['slots']);

$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = {$live}");
$q = ListingCheckout::quote($held, $uid);
pin('with the live listing gone it is free', [0, 0], [$q['slots'], $q['credits']]);

[$uid2, , $held2] = $seller(0);
osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('an unlimited ceiling costs nothing', 0, ListingCheckout::quote($held2, $uid2)['slots']);
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();

osc_set_preference('billing_slot_quantity', '2', 'osclass', 'INTEGER');
osc_reset_preferences();
[$uid4, , $held4] = $seller(0);
seed_item($admin, $cat, $uid4, 'Live b');
seed_item($admin, $cat, $uid4, 'Live c');
$q = ListingCheckout::quote($held4, $uid4);
pin('slots come in pairs, rounded up', [2, 6], [$q['slots'], $q['credits']]);
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_reset_preferences();

[$uid3, , $held3] = $seller(0);
$thrown = '';
try {
    ListingCheckout::quote($held3, $uid2);
} catch (ForbiddenException $e) {
    $thrown = $e->reason();
}
pin("another seller's listing is refused", ForbiddenException::NOT_OWNER, $thrown);

harness_section('Pay step: publish');

[$uid, $live, $held] = $seller(5);
$r = ListingCheckout::publish($held, Actor::user($uid));
pin('five credits publish it', [true, null], [$r['published'], $r['reason']]);
pin('the listing is live', ['1', '1'], [$row($held)['b_enabled'], $row($held)['b_active']]);
pin('three credits were spent, one ledger row', [2, 1], [Wallet::balance($uid), $debits($uid)]);
$again = ListingCheckout::publish($held, Actor::user($uid));
pin('a second call finds it not held', [false, 'not_held'], [$again['published'], $again['reason']]);
pin('and spends nothing more', [2, 1], [Wallet::balance($uid), $debits($uid)]);

[$uid, , $held] = $seller(2);
$r = ListingCheckout::publish($held, Actor::user($uid));
pin('two credits are short', [false, 'insufficient_credits', 1], [$r['published'], $r['reason'], $r['quote']['shortfall']]);
pin('nothing was spent and the row stays held', [2, 0, null], [Wallet::balance($uid), $debits($uid), $row($held)['dt_first_pub_date']]);

[$uid, $live, $held] = $seller(0);
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = {$live}");
$r = ListingCheckout::publish($held, Actor::user($uid));
pin('within quota it is free', [true, 0, 0], [$r['published'], Wallet::balance($uid), $debits($uid)]);

[$uid, , $held] = $seller(5);
$thrown = '';
try {
    ListingCheckout::publish($held, Actor::user($uid + 1000));
} catch (ForbiddenException $e) {
    $thrown = $e->reason();
}
pin('another user cannot publish it', [ForbiddenException::NOT_OWNER, 5, null], [$thrown, Wallet::balance($uid), $row($held)['dt_first_pub_date']]);

harness_section('Pay step: two at once');

[$uid, , $held] = $seller(10);
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$admin->begin_transaction();
$admin->query("SELECT pk_i_id FROM {$p}t_item WHERE pk_i_id = {$held} FOR UPDATE");
$procs = [];
$pipes = [];
foreach ([0, 1] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-publish-child.php', $dbName, (string) $held, (string) $uid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    $pipes[$i] = $pp;
}
$watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $dbName, (int) ini_get('mysqli.default_port'));
$waiting = 0;
for ($try = 0; $try < 400 && $waiting < 2; $try++) {
    usleep(50000);
    $waiting = (int) $watch->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '{$dbName}' AND COMMAND = 'Query' AND INFO LIKE 'SELECT * FROM %t_item WHERE pk_i_id%FOR UPDATE'")->fetch_row()[0];
}
$watch->close();
$admin->commit();
$printed = '';
foreach ($procs as $i => $proc) {
    $printed .= stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
    proc_close($proc);
}
pin('both waited on the listing row', 2, $waiting);
pin('one publishes, the other finds it not held, none throws', [1, 1, 0], [substr_count($printed, 'published'), substr_count($printed, 'not_held'), substr_count($printed, 'threw')]);
pin('one debit and seven credits left', [1, 7], [$debits($uid), Wallet::balance($uid)]);

harness_section('Pay step: URL');

pin('the pay-step URL is the billing_publish route', true, str_contains(osc_billing_publish_url(9), 'billing_publish'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
