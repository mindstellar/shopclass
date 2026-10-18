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
use mindstellar\database\Db;
use mindstellar\exception\ForbiddenException;
use mindstellar\listing\ListingHoldJobs;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingStore;
use RuntimeException;

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
     * Spend the credits and put the listing live. Nothing is spent when it is not held or the
     * balance is short. The row lock makes two simultaneous calls spend once.
     *
     * @return array{published: bool, reason: ?string, quote: array{held: bool, live: bool, slots: int, credits: int, balance: int, shortfall: int}}
     *
     * @throws ForbiddenException when the actor is not the listing's owner
     */
    public static function publish(int $itemId, Actor $actor): array
    {
        $userId = (int) $actor->userId();

        return Db::transaction(static function () use ($itemId, $actor, $userId): array {
            $row = ListingStore::find($itemId, array('*'), true);
            if ($row === null || $userId <= 0 || (int) $row['fk_i_user_id'] !== $userId) {
                throw new ForbiddenException('', ForbiddenException::NOT_OWNER);
            }
            $quote = self::price(Db::stringifyRow($row), $userId);

            if (!$quote['held']) {
                return array('published' => false, 'reason' => 'not_held', 'quote' => $quote);
            }
            if ($quote['shortfall'] > 0) {
                return array('published' => false, 'reason' => 'insufficient_credits', 'quote' => $quote);
            }
            for ($i = 0; $i < $quote['slots']; $i++) {
                if (!Billing::spend($userId, self::FEATURE, array('ref_type' => 'item', 'ref_id' => $itemId))) {
                    throw new RuntimeException('Could not spend the credits for listing #' . $itemId);
                }
            }
            if (!(new ListingService())->publish($itemId, $actor)) {
                throw new RuntimeException('Listing #' . $itemId . ' could not be published');
            }

            return array('published' => true, 'reason' => null, 'quote' => $quote);
        });
    }

    /**
     * @param array<string,string|null> $row
     *
     * @return array{held: bool, live: bool, slots: int, credits: int, balance: int, shortfall: int}
     */
    private static function price(array $row, int $userId): array
    {
        $held    = ListingHoldJobs::isHeld($row);
        $ceiling = EntitlementStore::listingCeiling($userId);
        $slots   = 0;
        if ($held && $ceiling !== -1) {
            $over  = EntitlementStore::liveListings($userId) - $ceiling + 1;
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
