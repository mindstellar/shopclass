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

namespace mindstellar\billing;

use mindstellar\auth\Actor;
use mindstellar\billing\event\OrderPaid;
use mindstellar\billing\event\OrderReturnUrlResolving;
use mindstellar\database\Db;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingQuery;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\ListingStore;
use RuntimeException;
use Throwable;

/**
 * What a held listing costs and paying for it. The web page, the API and the order listeners
 * all come through here, so the price and the double-spend guard live in one place.
 *
 * @package mindstellar\billing
 */
final class ListingCheckout
{
    /** The feature whose slots a listing over the limit has to buy. */
    private const FEATURE = 'listing.slot';

    /**
     * The price of putting the listing live, worked out fresh on every call.
     *
     * @return array{held: bool, live: bool, slots: int, credits: int, balance: int, shortfall: int}
     *
     * @throws ForbiddenException when the listing is not the user's
     */
    public static function quote(int $itemId, int $userId): array
    {
        $row = ListingStore::find($itemId, array('fk_i_user_id', 'b_enabled', 'b_active', 'dt_first_pub_date'));
        if ($row === null || (int) $row['fk_i_user_id'] !== $userId || $userId <= 0) {
            throw new ForbiddenException('', ForbiddenException::NOT_OWNER);
        }

        return self::price(Db::stringifyRow($row), $userId);
    }

    /**
     * Spend the credits and put the listing live. Nothing is spent when it is not held, the
     * balance is short or a plugin refuses it through `billing_can_publish` (reason `refused`).
     * Locking the seller makes two simultaneous calls, for one listing or two, spend once.
     *
     * @return array{published: bool, reason: ?string, quote: array{held: bool, live: bool, slots: int, credits: int, balance: int, shortfall: int}}
     *
     * @throws ForbiddenException when the actor is not the listing's owner
     */
    public static function publish(int $itemId, Actor $actor): array
    {
        $userId = (int) $actor->userId();

        return Db::transaction(static function () use ($itemId, $actor, $userId): array {
            // The seller first, then the listing: the price depends on the seller's other listings.
            EntitlementStore::lockSeller($userId);
            $row = ListingStore::find($itemId, array('*'), true);
            if ($row === null || $userId <= 0 || (int) $row['fk_i_user_id'] !== $userId) {
                throw new ForbiddenException('', ForbiddenException::NOT_OWNER);
            }
            $quote = self::price(Db::stringifyRow($row), $userId, true);

            if (!$quote['held']) {
                return array('published' => false, 'reason' => 'not_held', 'quote' => $quote);
            }
            if ($quote['shortfall'] > 0) {
                return array('published' => false, 'reason' => 'insufficient_credits', 'quote' => $quote);
            }
            // The slots are as good as bought here, so only a plugin's billing_can_publish can still say no.
            if (!EntitlementStore::canPublish($userId, array('item' => $row), true)) {
                return array('published' => false, 'reason' => 'refused', 'quote' => $quote);
            }
            for ($i = 0; $i < $quote['slots']; $i++) {
                if (!Billing::spend($userId, self::FEATURE, array('ref_type' => 'item', 'ref_id' => $itemId))) {
                    throw new RuntimeException('Could not spend the credits for listing #' . $itemId);
                }
            }
            if (!(new ListingService())->publish($itemId, $actor)) {
                throw new RuntimeException('Listing #' . $itemId . ' could not be published');
            }
            Db::afterCommit(static function () use ($itemId): void {
                OrderStore::unlinkListing($itemId);
            });

            return array('published' => true, 'reason' => null, 'quote' => $quote);
        });
    }

    /**
     * Start an order for the credits a held listing is short of. The amount, currency and
     * credits come from the package row, never from the caller.
     *
     * @return array{order: Order, next: CheckoutIntent}
     *
     * @throws ForbiddenException when the listing is not the user's
     * @throws InvalidException with `/package` (nothing_to_pay, unavailable, too_small) or `/gateway` (unavailable)
     */
    public static function startOrder(int $itemId, int $userId, int $packageId, string $gatewayId): array
    {
        $quote = self::quote($itemId, $userId);
        if ($quote['shortfall'] <= 0) {
            throw new InvalidException('/package', 'nothing_to_pay', _m('You have enough credits to publish this listing.'));
        }
        $package = PackageStore::find($packageId);
        if ($package === null || empty($package['b_enabled'])) {
            throw new InvalidException('/package', 'unavailable', _m('That package is no longer available'));
        }
        if ((int) $package['i_credits'] < $quote['shortfall']) {
            throw new InvalidException('/package', 'too_small', _m('That package does not cover the credits this listing needs.'));
        }
        $currency  = (string) $package['s_currency'];
        $available = PaymentGatewayRegistry::getInstance()->available($currency);
        if (!isset($available[$gatewayId])) {
            throw new InvalidException('/gateway', 'unavailable', _m('That payment method is not available'));
        }

        $order = OrderStore::create($userId, $gatewayId, (int) $package['i_amount'], $currency, (int) $package['i_credits'], array(
            '_kind'  => Order::KIND_LISTING,
            '_item'  => $itemId,
            '_label' => mb_substr(sprintf(_m('Listing: %s'), self::title($itemId)), 0, 200, 'UTF-8'),
        ));
        // Linked before the gateway runs, so a buyer who never comes back still leaves the link.
        $deadline = ListingStore::find($itemId, array('dt_expiration'))['dt_expiration'] ?? null;
        $ends     = ($deadline === null ? time() + osc_billing_hold_days() * 86400 : (int) strtotime((string) $deadline)) + 86400;
        $previous = OrderStore::listingOrder($itemId);
        OrderStore::linkListing($itemId, $order->getId(), $ends);

        $intent = Billing::checkout($order);
        if ($intent === null) {
            if ($previous !== null) {
                OrderStore::linkListing($itemId, $previous->getId(), $ends);
            } else {
                OrderStore::unlinkListing($itemId);
            }
            OrderStore::markMailSent($order->getId(), Receipts::MAIL_CANCELLED);
            OrderStore::cancel($order->getId());
            throw new InvalidException('/gateway', 'unavailable', _m('That payment method is not available right now. Try again or choose another.'));
        }

        return array('order' => $order, 'next' => $intent);
    }

    /**
     * A paid listing order publishes its listing when it is still held and still the payer's.
     * Otherwise the credits stay in the wallet and nothing else happens.
     */
    public static function onOrderPaid(OrderPaid $e): void
    {
        $order  = $e->order();
        $itemId = $order === null ? null : $order->listingId();
        if ($order === null || $itemId === null) {
            return;
        }
        $row = ListingStore::find($itemId, array('fk_i_user_id', 'b_enabled', 'b_active', 'dt_first_pub_date'));
        if ($row === null || !ListingStatus::isHeld(Db::stringifyRow($row))) {
            return;
        }
        if ((int) $row['fk_i_user_id'] !== $order->getUserId()) {
            error_log(sprintf('Billing: order #%d paid for listing #%d, which its payer no longer owns', $order->getId(), $itemId));

            return;
        }
        // The payment is already settled, so a failure here must not fail the gateway's callback.
        try {
            self::publish($itemId, Actor::user($order->getUserId()));
        } catch (Throwable $t) {
            error_log(sprintf('Billing: order #%d could not publish listing #%d: %s', $order->getId(), $itemId, get_class($t)));
        }
    }

    /**
     * After paying for a listing the buyer goes back to its pay step.
     */
    public static function onReturnUrl(OrderReturnUrlResolving $e): void
    {
        $itemId = OrderStore::find($e->orderId())?->listingId();
        if ($itemId !== null) {
            $e->setUrl(osc_billing_publish_url($itemId));
        }
    }

    /**
     * The listing's title in the user's language, else any title, else "Listing #N".
     */
    private static function title(int $itemId): string
    {
        $locale = function_exists('osc_current_user_locale') ? (string) osc_current_user_locale() : '';
        $title  = '';
        foreach ((new ListingQuery())->descriptions(array($itemId)) as $text) {
            $t = trim((string) $text['s_title']);
            if ($t !== '' && ($title === '' || $text['fk_c_locale_code'] === $locale)) {
                $title = $t;
            }
        }

        return $title !== '' ? $title : sprintf(_m('Listing #%d'), $itemId);
    }

    /**
     * @param array<string,string|null> $row
     * @param bool                      $current count the seller's listings as committed now, under lockSeller()
     *
     * @return array{held: bool, live: bool, slots: int, credits: int, balance: int, shortfall: int}
     */
    private static function price(array $row, int $userId, bool $current = false): array
    {
        $held    = ListingStatus::isHeld($row);
        $ceiling = EntitlementStore::listingCeiling($userId);
        $slots   = 0;
        if ($held && $ceiling !== -1) {
            $over  = EntitlementStore::liveListings($userId, $current) - $ceiling + 1;
            $slots = max(0, (int) ceil($over / osc_billing_slot_quantity()));
        }
        $feature = FeatureRegistry::getInstance()->get(self::FEATURE);
        $credits = $feature === null ? 0 : $slots * $feature->price($userId);
        $balance = osc_user_credits($userId);

        return array(
            'held'      => $held,
            'live'      => !$held && (int) ($row['b_enabled'] ?? 0) === 1,
            'slots'     => $slots,
            'credits'   => $credits,
            'balance'   => $balance,
            'shortfall' => max(0, $credits - $balance),
        );
    }
}
