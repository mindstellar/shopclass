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
use mindstellar\billing\event\CarriersListing;
use mindstellar\billing\event\CartDiscountLines;
use mindstellar\billing\event\CartFees;
use mindstellar\billing\event\CartFreeOver;
use mindstellar\billing\event\CartItemAdded;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\CartShipping;
use mindstellar\billing\event\CartShippingLines;
use mindstellar\billing\event\CartTaxLines;
use mindstellar\billing\event\CheckoutFields;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\DocumentComposing;
use mindstellar\billing\event\DocumentIssued;
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderDelivered;
use mindstellar\billing\event\OrderFailed;
use mindstellar\billing\event\OrderPaid;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\billing\event\OrderProcessing;
use mindstellar\billing\event\OrderReturnUrlResolving;
use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\PaymentTestModes;
use mindstellar\billing\event\ReceiptComposing;
use mindstellar\billing\event\ShipmentAdded;
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
use mindstellar\image\Engine;
use mindstellar\image\Processor;
use mindstellar\language\event\TranslationLoaded;
use mindstellar\listing\event\VariantPriceChanged;
use mindstellar\listing\event\VariantStockChanged;
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
use mindstellar\messaging\InboxQuery;
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
use mindstellar\widgets\event\CustomiserPublished;

/**
 * The services core puts in the site-wide container at start-up. Plugins may add their own on init.
 */
final class CoreServices
{
    /**
     * Each old hook name a core event owns, with that event, in events() order. The bridge reads
     * it without loading the event classes; tests/hook-bridge-lazy.php keeps it equal to their legacyNames().
     */
    public const EVENT_HOOKS = [
        'cart_needs_shipping' => CartShipping::class,
        'cart_shipping_methods' => CartShipping::class,
        'cart_shipping_lines' => CartShippingLines::class,
        'cart_tax_lines' => CartTaxLines::class,
        'cart_discount_lines' => CartDiscountLines::class,
        'cart_totals' => CartFees::class,
        'cart_free_over' => CartFreeOver::class,
        'cart_problems' => CartProblems::class,
        'cart_item_add_after' => CartItemAdded::class,
        'checkout_fields' => CheckoutFields::class,
        'cart_checkout_before' => CheckoutStarting::class,
        'billing_order_placed' => OrderPlaced::class,
        'billing_order_paid' => OrderPaid::class,
        'billing_order_failed' => OrderFailed::class,
        'billing_order_processing' => OrderProcessing::class,
        'billing_order_shipped' => OrderShipped::class,
        'billing_order_delivered' => OrderDelivered::class,
        'billing_order_cancelled' => OrderCancelled::class,
        'billing_shipment_add_after' => ShipmentAdded::class,
        'billing_tracking_url' => TrackingUrlResolving::class,
        'admin_billing_carriers' => CarriersListing::class,
        'admin_payment_test_modes' => PaymentTestModes::class,
        'billing_receipt_details' => ReceiptComposing::class,
        'billing_order_return_url' => OrderReturnUrlResolving::class,
        'billing_document_compose' => DocumentComposing::class,
        'billing_document_issued' => DocumentIssued::class,
        'email_order_placed_title' => MailComposing::class,
        'email_order_placed_description' => MailComposing::class,
        'email_order_placed_title_after' => MailComposing::class,
        'email_order_placed_description_after' => MailComposing::class,
        'email_order_shipped_title' => MailComposing::class,
        'email_order_shipped_description' => MailComposing::class,
        'email_order_shipped_title_after' => MailComposing::class,
        'email_order_shipped_description_after' => MailComposing::class,
        'email_order_part_shipped_title' => MailComposing::class,
        'email_order_part_shipped_description' => MailComposing::class,
        'email_order_part_shipped_title_after' => MailComposing::class,
        'email_order_part_shipped_description_after' => MailComposing::class,
        'email_order_delivered_title' => MailComposing::class,
        'email_order_delivered_description' => MailComposing::class,
        'email_order_delivered_title_after' => MailComposing::class,
        'email_order_delivered_description_after' => MailComposing::class,
        'email_order_cancelled_title' => MailComposing::class,
        'email_order_cancelled_description' => MailComposing::class,
        'email_order_cancelled_title_after' => MailComposing::class,
        'email_order_cancelled_description_after' => MailComposing::class,
        'email_order_refunded_title' => MailComposing::class,
        'email_order_refunded_description' => MailComposing::class,
        'email_order_refunded_title_after' => MailComposing::class,
        'email_order_refunded_description_after' => MailComposing::class,
        'email_order_part_refunded_title' => MailComposing::class,
        'email_order_part_refunded_description' => MailComposing::class,
        'email_order_part_refunded_title_after' => MailComposing::class,
        'email_order_part_refunded_description_after' => MailComposing::class,
        'email_admin_new_order_title' => MailComposing::class,
        'email_admin_new_order_description' => MailComposing::class,
        'email_admin_new_order_title_after' => MailComposing::class,
        'email_admin_new_order_description_after' => MailComposing::class,
        'email_product_alert_title' => MailComposing::class,
        'email_product_alert_description' => MailComposing::class,
        'email_product_alert_title_after' => MailComposing::class,
        'email_product_alert_description_after' => MailComposing::class,
        'email_listing_unpaid_reminder_title' => MailComposing::class,
        'email_listing_unpaid_reminder_description' => MailComposing::class,
        'email_listing_unpaid_reminder_title_after' => MailComposing::class,
        'email_listing_unpaid_reminder_description_after' => MailComposing::class,
        'email_new_message_title' => MailComposing::class,
        'email_new_message_description' => MailComposing::class,
        'email_new_message_title_after' => MailComposing::class,
        'email_new_message_description_after' => MailComposing::class,
        'conversation_started' => ConversationStarted::class,
        'message_body' => MessageSending::class,
        'message_sent' => MessageSent::class,
        'message_held' => MessageHeld::class,
        'conversation_blocked' => ConversationBlocked::class,
        'conversation_unblocked' => ConversationUnblocked::class,
        'conversation_reported' => ConversationReported::class,
        'message_read' => MessageRead::class,
        'variant_price_changed' => VariantPriceChanged::class,
        'variant_stock_changed' => VariantStockChanged::class,
        'customiser_published' => CustomiserPublished::class,
        'translation_load_after' => TranslationLoaded::class,
    ];

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
        $c->set(InboxQuery::class, static fn (Container $c): InboxQuery => new InboxQuery($c->get(MessageStore::class)));
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
        $c->set(Engine::class, static fn (): Engine => Processor::siteEngine());
        $c->set(Processor::class, static fn (Container $c): Processor => new Processor($c->get(Engine::class)));
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
        $bridge->claim(self::EVENT_HOOKS);
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
        return array_values(array_unique(self::EVENT_HOOKS));
    }
}
