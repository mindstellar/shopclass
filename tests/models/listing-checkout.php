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

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_listing_checkout');

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

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

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
