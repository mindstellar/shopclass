<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;

/** A gateway that sends the buyer away, or fails to when told to. */
final class ListingOrderGateway implements PaymentGateway
{
    public bool $fail = false;
    /** @var array<int,int|null> the order each checkout found linked to its listing */
    public array $linked = [];

    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Listing test';
    }

    public function getSupportedCurrencies(): array
    {
        return array('USD');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        $this->linked[] = OrderStore::listingOrder((int) $order->listingId())?->getId();
        if ($this->fail) {
            throw new RuntimeException('down');
        }

        return CheckoutIntent::redirect('https://example.test/pay/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }
}
