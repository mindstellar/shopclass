<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\boot;

use mindstellar\billing\CartPricer;
use mindstellar\billing\event\CartDiscountLines;
use mindstellar\billing\event\CartFees;
use mindstellar\billing\event\CartFreeOver;
use mindstellar\billing\event\CartItemAdded;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\CartShipping;
use mindstellar\billing\event\CartShippingLines;
use mindstellar\billing\event\CartTaxLines;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\GoodsCheckout;
use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;

/**
 * The services core puts in the site-wide container at start-up. Plugins may add their own on init.
 */
final class CoreServices
{
    private function __construct()
    {
    }

    /**
     * Put the dispatcher, the hook bridge and the services that fire events in $c, and give the bridge core's events.
     */
    public static function register(Container $c): void
    {
        $events = new Dispatcher($c);
        $bridge = new HookBridge($events);
        $c->instance(Dispatcher::class, $events);
        $c->instance(DispatcherInterface::class, $events);
        $c->instance(HookBridge::class, $bridge);
        $c->set(CartPricer::class, static fn (Container $c): CartPricer => new CartPricer($c->get(DispatcherInterface::class)));
        $c->set(GoodsCheckout::class, static fn (Container $c): GoodsCheckout => new GoodsCheckout($c->get(CartPricer::class), $c->get(DispatcherInterface::class)));
        foreach (self::events() as $event) {
            $bridge->own($event);
        }
    }

    /**
     * The core events that own old hook names.
     *
     * @return list<class-string<Bridged>>
     */
    public static function events(): array
    {
        return [
            CartShipping::class,
            CartShippingLines::class,
            CartTaxLines::class,
            CartDiscountLines::class,
            CartFees::class,
            CartFreeOver::class,
            CartProblems::class,
            CartItemAdded::class,
            CheckoutStarting::class,
        ];
    }
}
