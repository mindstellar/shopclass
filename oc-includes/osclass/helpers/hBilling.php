<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;
use mindstellar\billing\Entitlements;
use mindstellar\billing\Feature;
use mindstellar\billing\FeatureRegistry;
use mindstellar\billing\gateway\OfflineGateway;
use mindstellar\billing\ItemUpgrades;
use mindstellar\billing\Packages;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;

/**
 * Register a feature so credits can be spent on it. Thin wrapper over
 * FeatureRegistry::register() -- see that method for the $spec shape.
 *
 * @param string $id   Namespaced slug, [a-z0-9_.-]{1,64}.
 * @param array  $spec Feature specification.
 */
function osc_register_billing_feature(string $id, array $spec): void
{
    FeatureRegistry::instance()->register($id, $spec);
}

function osc_billing_feature(string $id): ?Feature
{
    return FeatureRegistry::instance()->get($id);
}

/**
 * @return array<string,Feature>
 */
function osc_billing_features(): array
{
    return FeatureRegistry::instance()->all();
}

/**
 * Free listing slots: how many of a seller's listings may be live (published, not
 * yet expired -- see Entitlements::liveListings()) at once before a listing.slot
 * entitlement is needed. 0 = unlimited, which is also what an unset preference
 * reads as -- an upgraded install stays unlimited.
 */
function osc_billing_free_live_listings(): int
{
    $v = osc_get_preference('billing_free_live_listings', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Whether buying an extra listing slot is registered as a purchasable feature at all. */
function osc_billing_slot_enabled(): bool
{
    return osc_get_bool_preference('billing_slot_enabled', 'osclass');
}

/** Credit price of one listing.slot purchase. 0 with billing_slot_enabled on means free. */
function osc_billing_slot_credits(): int
{
    $v = osc_get_preference('billing_slot_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Slots granted per listing.slot purchase. */
function osc_billing_slot_quantity(): int
{
    $v = osc_get_preference('billing_slot_quantity', 'osclass');

    return $v === '' || $v === null ? 1 : max(1, (int) $v);
}

/** Whether featuring a listing is registered as a purchasable feature at all. */
function osc_billing_premium_enabled(): bool
{
    return osc_get_bool_preference('billing_premium_enabled', 'osclass');
}

/** Credit price of featuring a listing. 0 with billing_premium_enabled on means free, not off. */
function osc_billing_premium_credits(): int
{
    $v = osc_get_preference('billing_premium_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Days a featured listing runs for. (int) '' is 0, so an unset preference defaults to 30. */
function osc_billing_premium_days(): int
{
    $v = osc_get_preference('billing_premium_days', 'osclass');

    return $v === '' || $v === null ? 30 : max(1, (int) $v);
}

/** ISO 4217 code credits are priced in, always upper-case. */
function osc_billing_currency(): string
{
    $v = osc_get_preference('billing_currency', 'osclass');

    return strtoupper($v === '' || $v === null ? 'USD' : (string) $v);
}

/** Whether the bundled bank-transfer gateway is switched on. */
function osc_billing_offline_enabled(): bool
{
    return osc_get_bool_preference('billing_offline_enabled', 'osclass');
}

/** Admin-authored payment instructions shown on the offline checkout screen. */
function osc_billing_offline_instructions(): string
{
    $v = osc_get_preference('billing_offline_instructions', 'osclass');

    return $v === null ? '' : (string) $v;
}

/*
 * Item upgrades: bump, highlight, urgent. Every one of the three ships disabled --
 * *_enabled and *_credits are deliberately separate preferences, because an enabled
 * upgrade priced at 0 credits is free to every seller, not switched off.
 */

/** Whether bump-to-top is registered as a purchasable upgrade at all. */
function osc_billing_bump_enabled(): bool
{
    return osc_get_bool_preference('billing_bump_enabled', 'osclass');
}

/** Credit price of a bump. 0 with billing_bump_enabled on means free, not off. */
function osc_billing_bump_credits(): int
{
    $v = osc_get_preference('billing_bump_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Hours a listing must wait between bumps -- the row ItemUpgrades grants IS the cooldown. */
function osc_billing_bump_cooldown_hours(): int
{
    $v = osc_get_preference('billing_bump_cooldown_hours', 'osclass');

    return $v === '' || $v === null ? 24 : max(1, (int) $v);
}

/** Whether highlighting is registered as a purchasable upgrade at all. */
function osc_billing_highlight_enabled(): bool
{
    return osc_get_bool_preference('billing_highlight_enabled', 'osclass');
}

/** Credit price of highlighting a listing. */
function osc_billing_highlight_credits(): int
{
    $v = osc_get_preference('billing_highlight_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Days a highlight runs for. */
function osc_billing_highlight_days(): int
{
    $v = osc_get_preference('billing_highlight_days', 'osclass');

    return $v === '' || $v === null ? 30 : max(1, (int) $v);
}

/** Whether marking a listing urgent is registered as a purchasable upgrade at all. */
function osc_billing_urgent_enabled(): bool
{
    return osc_get_bool_preference('billing_urgent_enabled', 'osclass');
}

/** Credit price of marking a listing urgent. */
function osc_billing_urgent_credits(): int
{
    $v = osc_get_preference('billing_urgent_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Days an urgent mark runs for. */
function osc_billing_urgent_days(): int
{
    $v = osc_get_preference('billing_urgent_days', 'osclass');

    return $v === '' || $v === null ? 7 : max(1, (int) $v);
}

/*
 * Seller limits: an optional entitlement raising an otherwise-global posting limit.
 * Same enabled/credits split as the item upgrades above, for the same reason -- an
 * enabled limit priced at 0 credits is free to every seller, not switched off. See
 * osc_max_images_for_user()/osc_items_wait_time_for_user()/osc_item_extra_runtime_days()
 * below for the read side third-party code should call.
 */

/** Whether raising a seller's photo cap is registered as a purchasable limit at all. */
function osc_billing_photos_enabled(): bool
{
    return osc_get_bool_preference('billing_photos_enabled', 'osclass');
}

/** Credit price of the raised photo cap. */
function osc_billing_photos_credits(): int
{
    $v = osc_get_preference('billing_photos_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Photo cap granted while the entitlement is held. */
function osc_billing_photos_quantity(): int
{
    $v = osc_get_preference('billing_photos_quantity', 'osclass');

    return $v === '' || $v === null ? 10 : max(1, (int) $v);
}

/** Whether waiving the flood wait is registered as a purchasable limit at all. */
function osc_billing_no_wait_enabled(): bool
{
    return osc_get_bool_preference('billing_no_wait_enabled', 'osclass');
}

/** Credit price of waiving the flood wait. */
function osc_billing_no_wait_credits(): int
{
    $v = osc_get_preference('billing_no_wait_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Days the waiver holds once bought. */
function osc_billing_no_wait_days(): int
{
    $v = osc_get_preference('billing_no_wait_days', 'osclass');

    return $v === '' || $v === null ? 30 : max(1, (int) $v);
}

/** Whether extra listing runtime is registered as a purchasable limit at all. */
function osc_billing_runtime_enabled(): bool
{
    return osc_get_bool_preference('billing_runtime_enabled', 'osclass');
}

/** Credit price of the extra runtime. */
function osc_billing_runtime_credits(): int
{
    $v = osc_get_preference('billing_runtime_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Extra days over the category ceiling granted while the entitlement is held. */
function osc_billing_runtime_days(): int
{
    $v = osc_get_preference('billing_runtime_days', 'osclass');

    return $v === '' || $v === null ? 30 : max(1, (int) $v);
}

/*
 * Entitlement-aware siblings of osc_max_images_per_item()/osc_items_wait_time(), the
 * two preference helpers that raw-read a global limit and are called by third-party
 * themes and plugins we cannot see -- their return values must never change. Each
 * sibling here falls back to the plain preference value when billing is off or no
 * user (or an entitlement-less one) is given, so on a site selling none of this the
 * new helper and the old one always agree.
 */

/**
 * The photo cap in force for $userId, raised by a listing.photos entitlement.
 * Defaults to osc_logged_user_id(). -1 means unlimited -- treat it that way, never
 * compare it numerically. Falls back to osc_max_images_per_item() (where 0, not -1,
 * is that helper's own "unlimited") whenever billing is off, no user is known, or
 * the user holds nothing.
 */
function osc_max_images_for_user(?int $userId = null): int
{
    $default = osc_max_images_per_item();
    if (!osc_billing_enabled()) {
        return $default;
    }

    // 0 is osc_max_images_per_item()'s own "unlimited" -- the ItemActions::
    // uploadItemResources() enforcement site already treats it that way, and it is
    // already the most permissive cap that site understands, so no entitlement can
    // raise it further. Consulting capacity() here would do the opposite: a finite
    // entitlement would read back as a NEW, lower cap for a seller who currently has
    // none at all.
    if ($default === 0) {
        return 0;
    }

    $userId = $userId ?? osc_logged_user_id();
    if (empty($userId)) {
        return $default;
    }

    return Entitlements::capacity((int) $userId, 'listing.photos', $default);
}

/**
 * Seconds $userId must wait between posts -- 0 while a listing.no_wait entitlement is
 * held, osc_items_wait_time() otherwise. Defaults to osc_logged_user_id(). Guests
 * (no user id) always read the global wait: this is an anti-flood control and
 * anonymous posting has no entitlements to check.
 */
function osc_items_wait_time_for_user(?int $userId = null): int
{
    $default = osc_items_wait_time();
    if (!osc_billing_enabled()) {
        return $default;
    }

    $userId = $userId ?? osc_logged_user_id();
    if (empty($userId)) {
        return $default;
    }

    return Entitlements::has((int) $userId, 'listing.no_wait') ? 0 : $default;
}

/**
 * Extra days $userId may run a listing beyond its category's expiration ceiling,
 * raised by a listing.runtime entitlement. 0 (not osc_items_wait_time_for_user()'s
 * "no preference to fall back to") when billing is off, no user is known, or the
 * user holds nothing -- there is no old global preference this one overrides, so 0
 * is simply "no extra". -1 means unlimited extra runtime; treat it that way, never
 * compare it numerically.
 */
function osc_item_extra_runtime_days(?int $userId = null): int
{
    if (!osc_billing_enabled()) {
        return 0;
    }

    $userId = $userId ?? osc_logged_user_id();
    if (empty($userId)) {
        return 0;
    }

    return Entitlements::capacity((int) $userId, 'listing.runtime', 0);
}

/**
 * A user's credit balance -- the logged-in buyer's own by default. Callers that pass
 * $userId explicitly must own that decision themselves; the wallet page never does,
 * so it can only ever read osc_logged_user_id()'s balance.
 */
function osc_user_credits(?int $userId = null): int
{
    return Wallet::balance($userId ?? osc_logged_user_id());
}

/**
 * The packages a buyer can choose from at checkout.
 *
 * @return array[]
 */
function osc_billing_packages(): array
{
    return Packages::enabled();
}

/** Balance and ledger history. */
function osc_billing_wallet_url(): string
{
    return osc_base_url() . '?page=billing';
}

/** Buy credits: packages plus the configured payment methods. */
function osc_billing_buy_url(): string
{
    return osc_base_url() . '?page=billing&action=buy';
}

/** The buyer's own past orders. */
function osc_billing_orders_url(): string
{
    return osc_base_url() . '?page=billing&action=orders';
}

/**
 * The POST target for featuring one of the user's own listings. The item id travels in
 * the URL so a theme's "feature this listing" form needs no hidden field beyond the
 * CSRF token the shutdown injector already adds.
 */
function osc_billing_upgrade_url(int $itemId): string
{
    return osc_base_url() . '?page=billing&action=upgrade&itemId=' . $itemId;
}

/**
 * Raw expiration datetime of a featured listing, or null when it is not currently
 * featured. Defaults to the item currently in view, the same convention osc_item_field()
 * uses, so it drops straight into an item loop.
 */
function osc_item_premium_expiration(?array $item = null): ?string
{
    $item = $item ?? osc_item();
    if (!is_array($item) || empty($item['dt_premium_expiration'])) {
        return null;
    }

    return (string) $item['dt_premium_expiration'];
}

/**
 * Whether a listing is eligible to be featured for credits: billing switched on,
 * featuring itself switched on, and the listing not already featured. A price of 0
 * with billing_premium_enabled on means every seller can feature for free -- it does
 * not mean unavailable.
 */
function osc_item_can_be_featured(?array $item = null): bool
{
    if (!osc_billing_enabled() || !osc_billing_premium_enabled()) {
        return false;
    }

    $item = $item ?? osc_item();

    return is_array($item) && empty($item['b_premium']);
}

/*
 * Item upgrades, read side. Themes are external repositories and cannot be edited
 * from here, so this is the whole surface core exposes: state only, no markup, no
 * CSS class, no template. All five default to the current loop item, the same
 * convention osc_item_field() and osc_item_premium_expiration() follow.
 */

/**
 * Batch-load item-upgrade state for $items into the request cache
 * ItemUpgrades::prime() fills, so osc_item_upgrades()/osc_item_is_highlighted()/
 * osc_item_is_urgent() called inside a listing loop cost one query for the whole
 * page rather than one per card. Call this where a result set is built (core
 * already does, for search/category/home); a theme working a custom loop can
 * call it too.
 *
 * $items may be item rows (as a search returns) or bare ids, since a caller has
 * whichever is already to hand -- a row's 'pk_i_id' is read when present,
 * otherwise the value itself is taken as the id. A no-op while billing is off,
 * since nothing reads ItemUpgrades in that state and the query would be waste.
 *
 * @param array $items item rows and/or int ids, mixed within one call is fine
 */
function osc_prime_item_upgrades(array $items): void
{
    if (!osc_billing_enabled() || $items === array()) {
        return;
    }

    $ids = array();
    foreach ($items as $item) {
        if (is_array($item)) {
            if (!empty($item['pk_i_id'])) {
                $ids[] = (int) $item['pk_i_id'];
            }
        } elseif (is_numeric($item)) {
            $ids[] = (int) $item;
        }
    }

    ItemUpgrades::prime($ids);
}

/**
 * Upgrade ids currently in force on an item.
 *
 * @return string[]
 */
function osc_item_upgrades(?array $item = null): array
{
    $item = $item ?? osc_item();
    if (!is_array($item) || empty($item['pk_i_id'])) {
        return array();
    }

    return ItemUpgrades::active((int) $item['pk_i_id']);
}

function osc_item_has_upgrade(string $upgrade, ?array $item = null): bool
{
    return in_array($upgrade, osc_item_upgrades($item), true);
}

function osc_item_is_highlighted(?array $item = null): bool
{
    return osc_item_has_upgrade('item.highlight', $item);
}

function osc_item_is_urgent(?array $item = null): bool
{
    return osc_item_has_upgrade('item.urgent', $item);
}

/**
 * Whether an item may be bumped right now: bump is switched on, the item belongs to
 * the logged-in user, and there is no live cooldown row. Bump has no state of its
 * own beyond that row -- the cooldown IS the row's expiry, not a second concept.
 */
function osc_item_can_bump(?array $item = null): bool
{
    if (!osc_billing_enabled() || !osc_billing_bump_enabled()) {
        return false;
    }

    $item = $item ?? osc_item();
    if (!is_array($item) || empty($item['pk_i_id'])) {
        return false;
    }

    $userId = osc_logged_user_id();
    if (empty($userId) || (int) ($item['fk_i_user_id'] ?? 0) !== (int) $userId) {
        return false;
    }

    return !ItemUpgrades::has((int) $item['pk_i_id'], 'item.bump');
}

/**
 * The POST target for applying $feature to $itemId. Generalises
 * osc_billing_upgrade_url() to any item-scoped feature; that one is kept, unchanged,
 * for the listing.premium links already out there.
 */
function osc_item_upgrade_url(int $itemId, string $feature): string
{
    return osc_base_url() . '?page=billing&action=upgrade&itemId=' . $itemId . '&feature=' . rawurlencode($feature);
}

/**
 * Raw expiration datetime of an item's $upgrade row, or null when there is no row
 * at all -- the same "raw value regardless of active state" convention
 * osc_item_premium_expiration() follows.
 */
function osc_item_upgrade_expiration(string $upgrade, ?array $item = null): ?string
{
    $item = $item ?? osc_item();
    if (!is_array($item) || empty($item['pk_i_id'])) {
        return null;
    }

    return ItemUpgrades::expiresAt((int) $item['pk_i_id'], $upgrade);
}

/**
 * Register listing.slot -- the feature that raises a seller's listing-slot ceiling
 * -- only while billing_slot_enabled is on, the same conditional-registration
 * pattern osc_register_billing_seller_limits() uses: a disabled feature is absent
 * from the registry entirely, not merely free or unpriced. CONSUMES_CAPACITY
 * because it raises the seller's slot ceiling while held and is never spent --
 * Entitlements::withinFreeQuota() reads it back through capacity(), the same way
 * listing.photos raises osc_max_images_for_user(). Called once below, and callable
 * again by the admin Pricing save.
 */
function osc_register_billing_slot(): void
{
    if (!osc_billing_slot_enabled()) {
        return;
    }

    osc_register_billing_feature('listing.slot', array(
        'label'    => 'Extra listing slot',
        'consumes' => Feature::CONSUMES_CAPACITY,
        'price'    => static function () {
            return osc_billing_slot_credits();
        },
        // Capacity is granted, not deducted -- same reasoning as listing.photos'
        // apply() in osc_register_billing_seller_limits() below.
        'apply'    => static function (int $userId, array $ctx): bool {
            return Entitlements::grant($userId, 'listing.slot', osc_billing_slot_quantity(), null);
        },
    ));
}
osc_register_billing_slot();

/**
 * Register listing.premium, gated on its own billing_premium_enabled preference --
 * the same split every newer feature below uses, so a disabled premium feature is
 * absent from the registry entirely, not merely priced at 0. Called once below, and
 * callable again by the admin Pricing save so a toggle takes effect without a fresh
 * request.
 */
function osc_register_billing_premium(): void
{
    if (!osc_billing_premium_enabled()) {
        return;
    }

    osc_register_billing_feature('listing.premium', array(
        'label'    => 'Featured listing',
        'consumes' => Feature::CONSUMES_DURATION,
        'scope'    => Feature::SCOPE_ITEM,
        'price'    => static function () {
            return osc_billing_premium_credits();
        },
        'duration' => static function () {
            return osc_billing_premium_days();
        },
        // Ownership is the caller's job (the public "feature this listing" action), not
        // this feature's -- it only knows how to apply itself once asked to.
        'apply'    => static function (int $userId, array $ctx): bool {
            $itemId = $ctx['itemId'] ?? null;
            if (empty($itemId)) {
                return false;
            }
            // Billing::spend() resolves the duration (billing_feature_duration
            // included) and threads it through $ctx; a plugin calling apply()
            // directly with no context still gets the preference's own answer.
            $days = $ctx['days'] ?? osc_billing_premium_days();

            // The write stays here, inside spend()'s transaction; item_premium_on is
            // deferred (see Billing::deferHook()) so it fires once that transaction
            // has committed, not while it still holds the wallet row's lock.
            if (!(new ItemActions())->premium((int) $itemId, true, $days, false)) {
                return false;
            }
            Billing::deferHook('item_premium_on', array((int) $itemId));

            return true;
        },
    ));
}
osc_register_billing_premium();

/**
 * Register the three built-in item upgrades, each gated on its own *_enabled
 * preference so a disabled upgrade is not merely unpriced but absent from the
 * registry entirely. Called once below, and callable again by anything that
 * changes those preferences without a fresh request (the admin settings save).
 */
function osc_register_billing_item_upgrades(): void
{
    if (osc_billing_bump_enabled()) {
        osc_register_billing_feature('item.bump', array(
            'label'    => 'Bump to top',
            'consumes' => Feature::CONSUMES_QUANTITY,
            'scope'    => Feature::SCOPE_ITEM,
            'price'    => static function () {
                return osc_billing_bump_credits();
            },
            // No ownership check here either, for the same reason as listing.premium.
            'apply'    => static function (int $userId, array $ctx): bool {
                $itemId = $ctx['itemId'] ?? null;
                if (empty($itemId)) {
                    return false;
                }
                $itemId = (int) $itemId;

                // Bump re-sorts the listing by moving the date every "newest first"
                // query already orders by. It carries no state of its own beyond
                // that -- the row below exists purely so the cooldown is enforceable.
                $moved = osc_db_table(DB_TABLE_PREFIX . 't_item')
                    ->where('pk_i_id', $itemId)
                    ->update(array('dt_pub_date' => date('Y-m-d H:i:s')));
                if ($moved !== 1) {
                    return false;
                }

                ItemUpgrades::grant($itemId, 'item.bump', null, osc_billing_bump_cooldown_hours());
                // Deferred (see Billing::deferHook()) so it fires once spend()'s
                // transaction has committed, not while it still holds the wallet
                // row's lock -- same reasoning as listing.premium's apply() above.
                Billing::deferHook('item_bumped', array($itemId));

                return true;
            },
        ));
    }

    if (osc_billing_highlight_enabled()) {
        osc_register_billing_feature('item.highlight', array(
            'label'    => 'Highlighted listing',
            'consumes' => Feature::CONSUMES_DURATION,
            'scope'    => Feature::SCOPE_ITEM,
            'price'    => static function () {
                return osc_billing_highlight_credits();
            },
            'duration' => static function () {
                return osc_billing_highlight_days();
            },
            'apply'    => static function (int $userId, array $ctx): bool {
                $itemId = $ctx['itemId'] ?? null;
                if (empty($itemId)) {
                    return false;
                }
                // Same fallback convention as listing.premium's apply() above.
                $days = $ctx['days'] ?? osc_billing_highlight_days();

                return ItemUpgrades::grant((int) $itemId, 'item.highlight', $days);
            },
        ));
    }

    if (osc_billing_urgent_enabled()) {
        osc_register_billing_feature('item.urgent', array(
            'label'    => 'Urgent listing',
            'consumes' => Feature::CONSUMES_DURATION,
            'scope'    => Feature::SCOPE_ITEM,
            'price'    => static function () {
                return osc_billing_urgent_credits();
            },
            'duration' => static function () {
                return osc_billing_urgent_days();
            },
            'apply'    => static function (int $userId, array $ctx): bool {
                $itemId = $ctx['itemId'] ?? null;
                if (empty($itemId)) {
                    return false;
                }
                // Same fallback convention as listing.premium's apply() above.
                $days = $ctx['days'] ?? osc_billing_urgent_days();

                return ItemUpgrades::grant((int) $itemId, 'item.urgent', $days);
            },
        ));
    }
}
osc_register_billing_item_upgrades();

/**
 * Register the three optional seller-limit entitlements, each gated on its own
 * *_enabled preference for the same reason as osc_register_billing_item_upgrades():
 * a disabled limit is absent from the registry entirely, not merely unpriced. Called
 * once below, and callable again by the admin Seller limits save.
 */
function osc_register_billing_seller_limits(): void
{
    if (osc_billing_photos_enabled()) {
        osc_register_billing_feature('listing.photos', array(
            'label'    => 'Extra photo capacity',
            'consumes' => Feature::CONSUMES_CAPACITY,
            'price'    => static function () {
                return osc_billing_photos_credits();
            },
            // Capacity is granted, not deducted -- the wallet debit above this
            // callable already happened once; this only mints the entitlement
            // Entitlements::capacity() will read back as the raised cap.
            'apply'    => static function (int $userId, array $ctx): bool {
                return Entitlements::grant($userId, 'listing.photos', osc_billing_photos_quantity(), null);
            },
        ));
    }

    if (osc_billing_no_wait_enabled()) {
        osc_register_billing_feature('listing.no_wait', array(
            'label'    => 'Skip the posting wait',
            'consumes' => Feature::CONSUMES_DURATION,
            'price'    => static function () {
                return osc_billing_no_wait_credits();
            },
            'duration' => static function () {
                return osc_billing_no_wait_days();
            },
            'apply'    => static function (int $userId, array $ctx): bool {
                // Same fallback convention as listing.premium's apply() above.
                $days = $ctx['days'] ?? osc_billing_no_wait_days();

                return Entitlements::grant($userId, 'listing.no_wait', null, $days);
            },
        ));
    }

    if (osc_billing_runtime_enabled()) {
        osc_register_billing_feature('listing.runtime', array(
            'label'    => 'Extra listing runtime',
            'consumes' => Feature::CONSUMES_CAPACITY,
            'price'    => static function () {
                return osc_billing_runtime_credits();
            },
            // Capacity again: the granted quantity IS the number of extra days
            // over the category ceiling, read by osc_item_extra_runtime_days().
            'apply'    => static function (int $userId, array $ctx): bool {
                return Entitlements::grant($userId, 'listing.runtime', osc_billing_runtime_days(), null);
            },
        ));
    }
}
osc_register_billing_seller_limits();

/*
 * Core's reference gateway -- bank transfer settled by hand from the admin order
 * screen. Registered on init, and only once billing is switched on, the same way
 * hStorage.php registers its optional remote adapters: an unconditional registration
 * would offer a payment method at checkout that a site never asked for.
 */
osc_add_hook('init', static function () {
    if (osc_billing_enabled()) {
        PaymentGatewayRegistry::instance()->register(new OfflineGateway());
    }
});

/*
 * The content of the wallet/buy/orders pages, registered as render targets so a
 * theme's user-custom.php can include them (see CWebBilling::doView()) and so
 * they are reachable, by id only, through ?page=custom&file=billing/<page>.
 */
osc_register_render_target('billing/wallet', ABS_PATH . 'oc-includes/osclass/gui/billing/wallet-content.php');
osc_register_render_target('billing/buy', ABS_PATH . 'oc-includes/osclass/gui/billing/buy-content.php');
osc_register_render_target('billing/orders', ABS_PATH . 'oc-includes/osclass/gui/billing/orders-content.php');

/*
 * Wallet/buy links on the account menu. This runs through the same 'user_menu_filter'
 * a theme's own account sidebar already applies its options through (see
 * osc_private_user_menu()), so it reaches every theme without either of them editing
 * the other -- no theme file has to know billing exists.
 */
osc_add_hook('user_menu_filter', static function (array $options): array {
    if (!osc_billing_enabled()) {
        return $options;
    }

    $options[] = array('name' => _m('Credits'), 'url' => osc_billing_wallet_url(), 'class' => 'opt_billing_wallet');
    $options[] = array('name' => _m('Buy credits'), 'url' => osc_billing_buy_url(), 'class' => 'opt_billing_buy');

    return $options;
});

/* file end: ./oc-includes/osclass/helpers/hBilling.php */
