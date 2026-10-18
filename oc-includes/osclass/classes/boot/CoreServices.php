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
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderDelivered;
use mindstellar\billing\event\OrderFailed;
use mindstellar\billing\event\OrderPaid;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\billing\event\OrderReturnUrlResolving;
use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\ReceiptComposing;
use mindstellar\billing\event\TrackingUrlResolving;
use mindstellar\billing\GoodsCheckout;
use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;
use mindstellar\mail\CoreEmails;
use mindstellar\mail\event\MailComposing;

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
        $c->set(CoreEmails::class, static fn (Container $c): CoreEmails => new CoreEmails($c->get(DispatcherInterface::class)));
        foreach (self::events() as $event) {
            $bridge->own($event);
        }
    }

    /**
     * The site's dispatcher. Core's services are registered first when start-up has not done it.
     */
    public static function dispatcher(): DispatcherInterface
    {
        $c = Container::root();
        if (!$c->has(DispatcherInterface::class)) {
            self::register($c);
        }

        return $c->get(DispatcherInterface::class);
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
            OrderPlaced::class,
            OrderPaid::class,
            OrderFailed::class,
            OrderShipped::class,
            OrderDelivered::class,
            OrderCancelled::class,
            TrackingUrlResolving::class,
            ReceiptComposing::class,
            OrderReturnUrlResolving::class,
            MailComposing::class,
            \mindstellar\listing\event\VariantPriceChanged::class,
            \mindstellar\listing\event\VariantStockChanged::class,
            \mindstellar\widgets\event\CustomiserPublished::class,
        ];
    }
}
