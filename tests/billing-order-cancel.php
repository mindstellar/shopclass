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
 * A seller cancels their own pending listing-fee or credits order from the order list: the list
 * shows a Cancel form with a CSRF token, the listing stays held, and nobody cancels a paid order
 * or someone else's. A post with no token changes nothing.
 * Usage: php tests/billing-order-cancel.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_order_cancel');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Cars', null, 'en_US', 1, 30);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
scratchdb_forget_cache();
osc_reset_preferences();

require_once __DIR__ . '/lib/billing-cancel-post.php';

$render = static function (array $orders): string {
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/order-list.php';

    return (string) ob_get_clean();
};

$sam  = seed_user($admin, 'sam', 'sam@example.test');
$kim  = seed_user($admin, 'kim', 'kim@example.test');
$held = seed_item($admin, $cat, $sam, 'Held car');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");

$fee = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_LISTING, '_item' => $held, '_label' => 'Listing: Held car'));
OrderStore::linkListing($held, $fee->getId(), time() + 86400);
$credits = OrderStore::create($sam, 'offline', 2_000_000, 'USD', 10, array());
$paid    = OrderStore::create($sam, 'offline', 2_000_000, 'USD', 10, array());
OrderStore::settle($paid->getId(), Order::STATUS_PAID, 'ref-1');
$paid = OrderStore::find($paid->getId());
$status = static fn (int $id): string => OrderStore::find($id)->getStatus();

harness_section('the order list');
$html = $render(array($fee, $credits, $paid));
check('a pending listing fee and credits order each get a Cancel form', substr_count($html, 'name="action" value="cancel"') === 2
    && str_contains($html, 'value="' . $fee->getId() . '"') && str_contains($html, 'value="' . $credits->getId() . '"'));
check('the form carries a CSRF token', str_contains($html, "name='CSRFToken'"));
check('a paid order has none', !str_contains($html, 'name="id" value="' . $paid->getId() . '"'));

harness_section('someone else');
$r = cancel_post($kim, $fee->getId());
pin('kim cannot cancel sam\'s order', array(osc_billing_orders_url(), Order::STATUS_PENDING), array($r['redirect'], $status($fee->getId())));
check('and is told so', str_starts_with($r['flashes'], 'error:'));

harness_section('the seller');
$r = cancel_post($sam, $fee->getId());
pin('sam cancels the listing fee and goes back to the orders', array(osc_billing_orders_url(), Order::STATUS_CANCELLED), array($r['redirect'], $status($fee->getId())));
check('with a word that it worked', str_starts_with($r['flashes'], 'ok:'));
pin('the listing stays held', 'held', OrderStore::listingState($held));
$r = cancel_post($sam, $credits->getId());
pin('and the credits order', Order::STATUS_CANCELLED, $status($credits->getId()));
$r = cancel_post($sam, $credits->getId());
pin('twice changes nothing', array(Order::STATUS_CANCELLED, true), array($status($credits->getId()), str_starts_with($r['flashes'], 'warning:')));
$r = cancel_post($sam, $paid->getId());
pin('a paid order stays paid', Order::STATUS_PAID, $status($paid->getId()));
$r = cancel_post($sam, 999999);
pin('an unknown order goes back to the list', osc_billing_orders_url(), $r['redirect']);

harness_section('no CSRF token');
$open = OrderStore::create($sam, 'offline', 2_000_000, 'USD', 10, array());
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$out    = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lib/billing-cancel-post.php') . ' ' . escapeshellarg($dbName) . ' ' . $sam . ' ' . $open->getId() . ' 2>&1');
pin('a post without a token stops before the cancel', array(false, Order::STATUS_PENDING), array(str_contains($out, 'went on'), $status($open->getId())));

$_SERVER['REQUEST_METHOD'] = 'GET';
exit(harness_result());
