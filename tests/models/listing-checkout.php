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
require_once __DIR__ . '/../lib/listing-order-gateway.php';

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
use mindstellar\billing\PackageStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;

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
pin('and say where it landed', ListingCheckout::LANDING_LIVE, $r['landing']);
pin('the listing is live', ['1', '1'], [$row($held)['b_enabled'], $row($held)['b_active']]);
pin('three credits were spent, one ledger row', [2, 1], [Wallet::balance($uid), $debits($uid)]);
$again = ListingCheckout::publish($held, Actor::user($uid));
pin('a second call finds it not held', [false, 'not_held'], [$again['published'], $again['reason']]);
pin('which still says where it is', ListingCheckout::LANDING_LIVE, $again['landing']);
pin('and spends nothing more', [2, 1], [Wallet::balance($uid), $debits($uid)]);

[$uid, , $held] = $seller(2);
$r = ListingCheckout::publish($held, Actor::user($uid));
pin('two credits are short', [false, 'insufficient_credits', 1], [$r['published'], $r['reason'], $r['quote']['shortfall']]);
pin('the short listing lands nowhere yet', ListingCheckout::LANDING_HELD, $r['landing']);
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

[$uid, , $held] = $seller(5);
$veto = static fn (): bool => false;
osc_add_filter('billing_can_publish', $veto);
$r = ListingCheckout::publish($held, Actor::user($uid));
osc_remove_filter('billing_can_publish', $veto);
pin('a plugin refusal stops it and spends nothing', [false, 'refused', 5, null], [$r['published'], $r['reason'], Wallet::balance($uid), $row($held)['dt_first_pub_date']]);

[$uid, , $held] = $seller(5);
OrderStore::linkListing($held, $listing->getId(), time() + 3600);
(new \mindstellar\listing\ListingService())->publish($held, Actor::user($uid));
pin('the listing service leaves the order link to the checkout', $listing->getId(), OrderStore::listingOrder($held)?->getId());
[$uid, , $held] = $seller(5);
OrderStore::linkListing($held, $listing->getId(), time() + 3600);
ListingCheckout::publish($held, Actor::user($uid));
pin('a publish through the checkout drops the link', null, OrderStore::listingOrder($held));

osc_set_preference('moderate_items', '0', 'osclass', 'INTEGER');
osc_set_preference('logged_user_item_validation', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
[$uid, , $held] = $seller(5);
pin('a listing that needs the e-mail check lands in validation', ListingCheckout::LANDING_VALIDATION, ListingCheckout::publish($held, Actor::user($uid))['landing']);
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();

harness_section('Pay step: two at once');

[$uid, , $held] = $seller(10);
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
// The parent holds the seller's lock: both children must wait for it, then spend once.
$admin->begin_transaction();
$admin->query("SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = {$uid} FOR UPDATE");
$procs = [];
$pipes = [];
foreach ([0, 1] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-publish-child.php', $dbName, (string) $held, (string) $uid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    $pipes[$i] = $pp;
}
usleep(1500000);
$waiting = count(array_filter($procs, static fn ($proc): bool => proc_get_status($proc)['running']));
$admin->commit();
$printed = '';
foreach ($procs as $i => $proc) {
    $printed .= stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
    proc_close($proc);
}
pin('both waited for the seller lock', 2, $waiting);
pin('one publishes, the other finds it not held, none throws', [1, 1, 0], [substr_count($printed, 'published'), substr_count($printed, 'not_held'), substr_count($printed, 'threw')]);
pin('one debit and seven credits left', [1, 7], [$debits($uid), Wallet::balance($uid)]);

harness_section('Pay step: one free slot, two listings');

// The parent takes the seller's lock and the last free slot without committing; the child,
// publishing another listing of the same seller, must wait and then find the slot gone.
[$uid, $first, $second] = $seller(0);
holdItem($first);
$admin->begin_transaction();
$admin->query("SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = {$uid} FOR UPDATE");
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_active = 1, dt_first_pub_date = NOW(), dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE pk_i_id = {$first}");
$child = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-publish-child.php', $dbName, (string) $second, (string) $uid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp);
usleep(1500000);
$waited = proc_get_status($child)['running'];
$admin->commit();
$printed = stream_get_contents($cp[1]) . stream_get_contents($cp[2]);
proc_close($child);
pin('the second publish waits for the first', true, $waited);
pin('then finds the free slot taken and spends nothing', ['insufficient_credits', null], [trim($printed), $row($second)['dt_first_pub_date']]);

harness_section('Listing order: start');

$gw   = new ListingOrderGateway('lcgw');
$down = new ListingOrderGateway('lcdown');
$down->fail = true;
PaymentGatewayRegistry::getInstance()->register($gw);
PaymentGatewayRegistry::getInstance()->register($down);

$pkg      = PackageStore::create(['s_name' => 'Five', 'i_amount' => 2_000_000, 's_currency' => 'USD', 'i_credits' => 5]);
$small    = PackageStore::create(['s_name' => 'Two', 'i_amount' => 900_000, 's_currency' => 'USD', 'i_credits' => 2]);
$disabled = PackageStore::create(['s_name' => 'Off', 'i_amount' => 1_000_000, 's_currency' => 'USD', 'i_credits' => 10, 'b_enabled' => 0]);
$orders   = static function (int $uid) use ($admin, $p): int {
    return (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order WHERE fk_i_user_id = {$uid}")->fetch_row()[0];
};
$refusal = static function (callable $fn): string {
    try {
        $fn();
    } catch (InvalidException $e) {
        return $e->errors()[0]['pointer'] . ' ' . $e->errors()[0]['code'];
    } catch (ForbiddenException $e) {
        return 'forbidden';
    }

    return 'none';
};

[$uid, $live, $held] = $seller(0);
// What a browser might post: its amount and credits must never reach the order.
$req     = ['packageId' => $pkg, 'gateway' => 'lcgw', 'amount' => 1, 'credits' => 999];
$started = ListingCheckout::startOrder($held, $uid, $req['packageId'], $req['gateway']);
$order   = $started['order'];
$stored  = OrderStore::find($order->getId());
pin('the order is a listing order for the held listing', [true, $held, 'Listing: Held ' . $seq], [$stored->isListing(), $stored->listingId(), $stored->getLabel()]);
pin('amount, currency and credits are the package row', [2_000_000, 'USD', 5, 'lcgw'], [$stored->getAmount(), $stored->getCurrency(), $stored->getCredits(), $stored->getGateway()]);
pin('the gateway sends the buyer on', true, $started['next']->isRedirect());
pin('the listing links to its order', $order->getId(), OrderStore::listingOrder($held)?->getId());
pin('the link is there before the gateway runs', [$order->getId()], $gw->linked);
$kvEnds = (int) $admin->query("SELECT UNIX_TIMESTAMP(dt_expires) - UNIX_TIMESTAMP(i.dt_expiration) FROM {$p}t_key_value k JOIN {$p}t_item i ON i.pk_i_id = {$held} WHERE k.s_group = 'listing_unpaid' AND k.s_key = '{$held}'")->fetch_row()[0];
pin('the link lapses a day after the hold', 86400, $kvEnds);

$before = $orders($uid);
pin('a package short of the shortfall is refused', '/package too_small', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $small, 'lcgw')));
pin('a disabled package is refused', '/package unavailable', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $disabled, 'lcgw')));
pin('an unknown gateway is refused', '/gateway unavailable', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $pkg, 'nope')));
pin('and none of them wrote an order', $before, $orders($uid));
pin("another seller's listing is refused", 'forbidden', $refusal(fn () => ListingCheckout::startOrder($held, $uid + 1000, $pkg, 'lcgw')));
pin('a gateway that fails is refused', '/gateway unavailable', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $pkg, 'lcdown')));
pin('and the listing keeps the order it had', $order->getId(), OrderStore::listingOrder($held)?->getId());
$last = (int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_billing_order WHERE fk_i_user_id = {$uid}")->fetch_row()[0];
pin('and its order is cancelled', Order::STATUS_CANCELLED, OrderStore::find($last)->getStatus());

osc_set_preference('billing_cod_enabled', '1', 'osclass', 'BOOLEAN');
osc_set_preference('billing_currency', 'USD', 'osclass', 'STRING');
osc_reset_preferences();
PaymentGatewayRegistry::getInstance()->register(new \mindstellar\billing\gateway\CashOnDeliveryGateway());
pin('cash on delivery is a gateway', true, isset(PaymentGatewayRegistry::getInstance()->available()['cod']));
pin('but not one a listing order offers', [false, true], [isset(ListingCheckout::gateways()['cod']), isset(ListingCheckout::gateways()['lcgw'])]);
pin('nor takes', '/gateway unavailable', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $pkg, 'cod')));
osc_set_preference('billing_cod_enabled', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();

[$uid, , $held] = $seller(5);
pin('nothing to pay is refused', '/package nothing_to_pay', $refusal(fn () => ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')));

harness_section('Listing order: paid');

$ledger = static function (int $uid) use ($admin, $p): array {
    $rows = $admin->query("SELECT i_amount FROM {$p}t_billing_ledger WHERE fk_i_user_id = {$uid} ORDER BY pk_i_id")->fetch_all();

    return array_map(static fn (array $r): int => (int) $r[0], $rows);
};

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
pin('paying settles the order', true, Billing::markPaid($order));
pin('the listing is live', ['1', '1', true], [$row($held)['b_enabled'], $row($held)['b_active'], $row($held)['dt_first_pub_date'] !== null]);
pin('five credits minted, three spent', [[5, -3], 2], [$ledger($uid), Wallet::balance($uid)]);
pin('the order link is gone', null, OrderStore::listingOrder($held));
pin('a replayed payment changes nothing', [false, [5, -3], 2], [Billing::markPaid($order), $ledger($uid), Wallet::balance($uid)]);

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = {$held}");
pin('paid after the listing was deleted: settled', true, Billing::markPaid($order));
pin('the credits stay in the wallet and no row comes back', [5, 0], [Wallet::balance($uid), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_row()[0]]);

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
$other = seed_user($admin, 'newowner', 'newowner@example.test');
$admin->query("UPDATE {$p}t_item SET fk_i_user_id = {$other} WHERE pk_i_id = {$held}");
Billing::markPaid($order);
pin('paid by a seller who no longer owns it: stays held', null, $row($held)['dt_first_pub_date']);
pin('and the credits are the payer\'s', [5, 0], [Wallet::balance($uid), Wallet::balance($other)]);

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
osc_set_preference('billing_slot_credits', '10', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_slot();
Billing::markPaid($order);
pin('a price that rose meanwhile leaves it held with the credits kept', [null, 5], [$row($held)['dt_first_pub_date'], Wallet::balance($uid)]);
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_slot();

harness_section('Listing order: listing deleted');

$secret = static fn (int $id): string => (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_row()[0];
[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
(new \mindstellar\listing\ListingService())->delete($held, $secret($held), Actor::user($uid));
pin('the owner deletes it: the order link is gone', null, OrderStore::listingOrder($held));
pin('and its pending order is cancelled', Order::STATUS_CANCELLED, OrderStore::find($order->getId())->getStatus());

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
\mindstellar\listing\ListingHoldJobs::queue($held, 7);
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE pk_i_id = {$held}");
\mindstellar\listing\ListingHoldJobs::sweep();
pin('the deadline sweep deletes it and cleans up the same way', [false, null, Order::STATUS_CANCELLED], [(bool) $admin->query("SELECT COUNT(*) FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_row()[0], OrderStore::listingOrder($held), OrderStore::find($order->getId())->getStatus()]);

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
\mindstellar\database\Db::execute("UPDATE {$p}t_billing_order SET s_status = 'paid' WHERE pk_i_id = ?", [$order->getId()]);
(new \mindstellar\listing\ListingService())->delete($held, $secret($held), Actor::user($uid));
pin('a paid order stays paid', [Order::STATUS_PAID, null], [OrderStore::find($order->getId())->getStatus(), OrderStore::listingOrder($held)]);

harness_section('Listing order: paid, not published');

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
seed_item($admin, $cat, $uid, 'Price riser');
Billing::markPaid($order);
$note = OrderStore::find($order->getId())->meta(OrderStore::PUBLISH_FAILED);
pin('a paid order whose listing cannot go live: still held', null, $row($held)['dt_first_pub_date']);
pin('and the order says why', 'insufficient_credits', $note['reason'] ?? null);
pin('the pay step reads it as paid, not published', 'failed', ListingCheckout::state($held, $uid)['step']);

[$uid, , $held] = $seller(0);
$order = ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order'];
Billing::markPaid($order);
Billing::markPaid($order);
ListingCheckout::onOrderPaid(new \mindstellar\billing\event\OrderPaid($order->getId(), $uid, 5));
pin('a replay on a live listing writes no note', null, OrderStore::find($order->getId())->meta(OrderStore::PUBLISH_FAILED));
pin('the pay step reads it as published', 'published', ListingCheckout::state($held, $uid)['step']);

harness_section('Pay step: where a paid listing landed');

[$uid, , $held] = $seller(5);
ListingCheckout::publish($held, Actor::user($uid));
$admin->query("UPDATE {$p}t_item SET b_active = 0 WHERE pk_i_id = {$held}");
$st = ListingCheckout::state($held, $uid);
pin('waiting for the e-mail check: its own step, not published', ['validation', false], [$st['step'], $st['quote']['live']]);
$admin->query("UPDATE {$p}t_item SET b_active = 1, b_enabled = 0 WHERE pk_i_id = {$held}");
pin('waiting for an admin: its own step', 'moderation', ListingCheckout::state($held, $uid)['step']);
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_spam = 1 WHERE pk_i_id = {$held}");
pin('marked spam: closed', 'closed', ListingCheckout::state($held, $uid)['step']);
$admin->query("UPDATE {$p}t_item SET b_spam = 0 WHERE pk_i_id = {$held}");
pin('live again: published', ['published', true], [ListingCheckout::state($held, $uid)['step'], ListingCheckout::state($held, $uid)['quote']['live']]);
pin('landing reads a held row as held', ListingCheckout::LANDING_HELD, ListingCheckout::landing(['b_enabled' => '0', 'b_active' => '0', 'dt_first_pub_date' => null]));
pin('validation and moderation are the pending landings', [false, false, true, true, false], array_map(
    [ListingCheckout::class, 'isPending'],
    [ListingCheckout::LANDING_HELD, ListingCheckout::LANDING_LIVE, ListingCheckout::LANDING_VALIDATION, ListingCheckout::LANDING_MODERATION, ListingCheckout::LANDING_CLOSED]
));

harness_section('Listing order: return URL');

pin('a listing order returns to its pay step', osc_billing_publish_url($held), osc_billing_order_return_url($order->getId()));
$creditsOrder = OrderStore::create($uid, 'lcgw', 1_000_000, 'USD', 5);
pin('a credits order returns to the orders page', osc_billing_orders_url(), osc_billing_order_return_url($creditsOrder->getId()));

harness_section('Next listing price');

[$nuid, $nlive, $nheld] = $seller(2);
$next = ListingCheckout::nextListing($nuid);
pin('one live at a limit of one: one slot, three credits', [1, 3, 2, 1], [$next['slots'], $next['credits'], $next['balance'], $next['shortfall']]);
$held2 = ListingCheckout::quote($nheld, $nuid);
pin('the held listing quote equals the next-listing price', $next, array_intersect_key($held2, $next));
seed_item($admin, $cat, $nuid, 'Another live');
pin('two live: two slots, six credits', [2, 6], [ListingCheckout::nextListing($nuid)['slots'], ListingCheckout::nextListing($nuid)['credits']]);
osc_set_preference('billing_slot_quantity', '2', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('slots of two cover it in one purchase', 1, ListingCheckout::nextListing($nuid)['slots']);
seed_item($admin, $cat, $nuid, 'Third live');
pin('a part-filled purchase rounds up', 2, ListingCheckout::nextListing($nuid)['slots']);
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
$fresh = seed_user($admin, 'freshnext', 'freshnext@example.test');
pin('inside the quota nothing is needed', [0, 0], [ListingCheckout::nextListing($fresh)['slots'], ListingCheckout::nextListing($fresh)['credits']]);
osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('an unlimited ceiling needs nothing', 0, ListingCheckout::nextListing($nuid)['slots']);
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('the helper returns the same figures', ListingCheckout::nextListing($nuid), osc_billing_next_listing_quote($nuid));
pin('the helper is all zeros with no user', ['slots' => 0, 'credits' => 0, 'balance' => 0, 'shortfall' => 0], osc_billing_next_listing_quote(0));

harness_section('Going live or deleted drops the hold jobs');

$holdJobs = static function (int $id) use ($admin, $p): int {
    return (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type IN ('listing.unpaid_expire', 'listing.unpaid_remind') AND s_unique = '{$id}'")->fetch_row()[0];
};
$queued = static function (int $credits) use ($seller): array {
    $s = $seller($credits);
    \mindstellar\listing\ListingHoldJobs::queue($s[2], 7);

    return $s;
};
[$uid, , $held] = $queued(3);
$before = $holdJobs($held);
ListingCheckout::publish($held, Actor::user($uid));
pin('paid with credits: both jobs gone', [2, 0], [$before, $holdJobs($held)]);
[$uid, , $held] = $queued(0);
Billing::markPaid(ListingCheckout::startOrder($held, $uid, $pkg, 'lcgw')['order']);
pin('paid by an order: both jobs gone', [true, 0], [$row($held)['dt_first_pub_date'] !== null, $holdJobs($held)]);
[$uid, , $held] = $queued(0);
pin('published free by an admin: both jobs gone', [true, 0], [ListingCheckout::publishFree($held, Actor::admin(1)), $holdJobs($held)]);
[$uid, , $held] = $queued(0);
(new \mindstellar\listing\ListingService())->delete($held, $secret($held), Actor::user($uid));
pin('deleted: both jobs gone', 0, $holdJobs($held));
[$uid, , $held] = $queued(0);
$refuse = static function (): void {
    throw new RuntimeException('refused');
};
osc_add_hook('after_delete_item', $refuse);
try {
    \mindstellar\database\Db::transaction(static fn () => (new \mindstellar\listing\ListingService())->delete($held, $secret($held), Actor::user($uid)));
} catch (RuntimeException $e) {
}
osc_remove_hook('after_delete_item', $refuse);
pin('a delete that rolls back keeps them', 2, $holdJobs($held));

harness_section('Pay step: URL');

pin('the pay-step URL is the billing_publish route', true, str_contains(osc_billing_publish_url(9), 'billing_publish'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
