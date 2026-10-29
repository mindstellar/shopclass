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
use mindstellar\billing\ListingCheckout;
use mindstellar\container\Container;
use mindstellar\event\Bridged;
use mindstellar\event\Dispatcher;
use mindstellar\event\DispatcherInterface;
use mindstellar\event\HookBridge;
use mindstellar\http\CurlHttpClient;
use mindstellar\http\HttpClient;
use mindstellar\live\LivePublisher;
use mindstellar\live\LiveStatus;
use mindstellar\live\NchanPublisher;
use mindstellar\live\NullPublisher;
use mindstellar\location\LocationNames;
use mindstellar\location\LocationNameStore;
use mindstellar\mail\CoreEmails;
use mindstellar\mail\event\MailComposing;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\event\ConversationBlocked;
use mindstellar\messaging\event\ConversationReported;
use mindstellar\messaging\event\ConversationStarted;
use mindstellar\messaging\event\ConversationUnblocked;
use mindstellar\messaging\event\MessageHeld;
use mindstellar\messaging\event\MessageRead;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\event\MessageSent;
use mindstellar\messaging\LiveNotifier;
use mindstellar\messaging\MessageNotifier;
use mindstellar\messaging\MessageStore;
use mindstellar\messaging\MessagingService;
use mindstellar\messaging\PushNotifier;
use mindstellar\model\KeyValue;
use mindstellar\moderation\MessageModeration;
use mindstellar\push\PushSubscriptions;
use mindstellar\push\Vapid;
use mindstellar\push\WebPushSender;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;

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
        $c->set(Clock::class, static fn (): Clock => new SystemClock());
        $c->set(LocationNameStore::class, static fn (): LocationNameStore => new LocationNameStore());
        $c->set(LocationNames::class, static fn (Container $c): LocationNames => new LocationNames($c->get(LocationNameStore::class)));
        $c->set(MessageStore::class, static fn (): MessageStore => new MessageStore());
        $c->set(ConversationStore::class, static fn (Container $c): ConversationStore => new ConversationStore($c->get(MessageStore::class)));
        $c->set(MessagingService::class, static fn (Container $c): MessagingService => new MessagingService(
            $c->get(ConversationStore::class),
            $c->get(MessageStore::class),
            $c->get(DispatcherInterface::class),
            $c->get(Clock::class),
            $c->get(LiveNotifier::class)
        ));
        $c->set(MessageModeration::class, static fn (Container $c): MessageModeration => new MessageModeration(
            $c->get(ConversationStore::class),
            $c->get(MessageStore::class),
            $c->get(DispatcherInterface::class),
            $c->get(Clock::class)
        ));
        $c->set(MessageNotifier::class, static fn (Container $c): MessageNotifier => new MessageNotifier($c->get(ConversationStore::class), $c->get(CoreEmails::class)));
        $events->listen(MessageSent::class, [MessageNotifier::class, 'onMessageSent']);
        $c->set(LiveStatus::class, static fn (Container $c): LiveStatus => new LiveStatus(new KeyValue(), $c->get(Clock::class)));
        $c->set(LivePublisher::class, static fn (Container $c): LivePublisher => getenv('OSC_LIVE') === 'nchan'
            ? new NchanPublisher(
                getenv('OSC_LIVE_PUBLISH_URL') ?: 'http://127.0.0.1:8090/live/pub/',
                getenv('OSC_LIVE_SUBSCRIBE_URL') ?: '/live/sub/',
                $c->get(LiveStatus::class)
            )
            : new NullPublisher());
        $c->set(LiveNotifier::class, static fn (Container $c): LiveNotifier => new LiveNotifier($c->get(LivePublisher::class), $c->get(ConversationStore::class)));
        $events->listen(MessageSent::class, [LiveNotifier::class, 'onMessageSent']);
        $events->listen(MessageRead::class, [LiveNotifier::class, 'onMessageRead']);
        $c->set(HttpClient::class, static fn (): HttpClient => new CurlHttpClient());
        $c->set(AddressGuard::class, static fn (): AddressGuard => new AddressGuard());
        $c->set(Vapid::class, static fn (Container $c): Vapid => new Vapid(new KeyValue(), $c->get(Clock::class)));
        $c->set(PushSubscriptions::class, static fn (Container $c): PushSubscriptions => new PushSubscriptions(
            new KeyValue(),
            $c->get(Clock::class),
            $c->get(AddressGuard::class)
        ));
        $c->set(WebPushSender::STATUS, static fn (Container $c): LiveStatus => new LiveStatus(
            new KeyValue(),
            $c->get(Clock::class),
            WebPushSender::STATUS_KEY,
            'Web push'
        ));
        $c->set(WebPushSender::class, static fn (Container $c): WebPushSender => new WebPushSender(
            $c->get(HttpClient::class),
            $c->get(AddressGuard::class),
            $c->get(Vapid::class),
            $c->get(WebPushSender::STATUS)
        ));
        $c->set(PushNotifier::class, static fn (Container $c): PushNotifier => new PushNotifier(
            $c->get(PushSubscriptions::class),
            $c->get(WebPushSender::class),
            $c->get(ConversationStore::class),
            $c->get(Clock::class)
        ));
        $events->listen(MessageSent::class, [PushNotifier::class, 'onMessageSent']);
        $events->listen(OrderPaid::class, [ListingCheckout::class, 'onOrderPaid']);
        $events->listen(OrderReturnUrlResolving::class, [ListingCheckout::class, 'onReturnUrl']);
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
            ConversationStarted::class,
            MessageSending::class,
            MessageSent::class,
            MessageHeld::class,
            ConversationBlocked::class,
            ConversationUnblocked::class,
            ConversationReported::class,
            MessageRead::class,
            \mindstellar\listing\event\VariantPriceChanged::class,
            \mindstellar\listing\event\VariantStockChanged::class,
            \mindstellar\widgets\event\CustomiserPublished::class,
            \mindstellar\language\event\TranslationLoaded::class,
        ];
    }
}
