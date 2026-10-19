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
 * Pins for what a listing order shows: the failed-publish note, the listing state and the
 * receipt's "for" line.
 *
 * Usage:  php tests/models/listing-order-notes.php      (standalone, own scratch database)
 *         php tests/run-models.php listing-order-notes   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_listing_order_notes');

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;

$p      = DB_TABLE_PREFIX;
$userId = seed_user($admin, 'seller', 'seller@example.test');
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat    = seed_category($admin);

harness_section('Publish failed note');

$order = OrderStore::create($userId, 'fake', 3000000, 'USD', 3, array(
    '_kind'  => Order::KIND_LISTING,
    '_item'  => 7,
    '_label' => 'Listing: Red car',
    'keep'   => 'me',
));
pin('no note yet', null, OrderStore::find($order->getId())->meta(OrderStore::PUBLISH_FAILED));
pin('the note is stored', true, OrderStore::notePublishFailed($order->getId(), 'not_held'));
$note = OrderStore::find($order->getId())->meta(OrderStore::PUBLISH_FAILED);
pin('with reason and time', ['not_held', true], [$note['reason'] ?? null, isset($note['at']) && $note['at'] !== '']);
pin('other meta is kept', ['me', 7], [OrderStore::find($order->getId())->meta('keep'), OrderStore::find($order->getId())->listingId()]);
pin('a missing order is refused', false, OrderStore::notePublishFailed(999999, 'x'));

harness_section('Listing state');

$live = seed_item($admin, $cat, $userId, 'Live');
$held = seed_item($admin, $cat, $userId, 'Held');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL WHERE pk_i_id = {$held}");
pin('a live listing', 'live', OrderStore::listingState($live));
pin('a held listing', 'held', OrderStore::listingState($held));
pin('a deleted listing', 'gone', OrderStore::listingState(999999));

harness_section('Receipt');

$vars = Receipts::vars($order);
pin('a listing order names its listing', 'Listing: Red car', $vars['for']);
$credits = OrderStore::create($userId, 'fake', 5000000, 'USD', 5);
pin('a credits order has no for line', '', Receipts::vars($credits)['for']);
check('the mail carries the line', str_contains(Receipts::emailBody($vars), 'Listing: Red car'));

exit(harness_result());
