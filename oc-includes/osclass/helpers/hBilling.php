<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Entitlements;
use mindstellar\billing\Feature;
use mindstellar\billing\FeatureRegistry;
use mindstellar\billing\gateway\OfflineGateway;
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
 * Free listings allowed per period before a credit is required. 0 = unlimited, which
 * is also what an unset preference reads as -- an upgraded install stays unlimited.
 */
function osc_billing_free_posts_per_period(): int
{
    $v = osc_get_preference('billing_free_posts_per_period', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/**
 * Length of the free-quota window, in days. (int) '' is 0, which would make the quota
 * window vanish rather than default it, so an unset or empty preference reads as 30.
 */
function osc_billing_period_days(): int
{
    $v = osc_get_preference('billing_period_days', 'osclass');

    return $v === '' || $v === null ? 30 : max(1, (int) $v);
}

/** Credit price of one extra listing beyond the free quota. */
function osc_billing_publish_credits(): int
{
    $v = osc_get_preference('billing_publish_credits', 'osclass');

    return $v === '' || $v === null ? 1 : (int) $v;
}

/** Credit price of featuring a listing. 0 means the feature is not for sale. */
function osc_billing_premium_credits(): int
{
    $v = osc_get_preference('billing_premium_credits', 'osclass');

    return $v === '' || $v === null ? 0 : (int) $v;
}

/** Days a featured listing runs for. Same (int) '' = 0 trap as the period days above. */
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
 * Whether a listing is eligible to be featured for credits: billing switched on, a
 * price actually set for it, and the listing not already featured.
 */
function osc_item_can_be_featured(?array $item = null): bool
{
    if (!osc_billing_enabled() || osc_billing_premium_credits() <= 0) {
        return false;
    }

    $item = $item ?? osc_item();

    return is_array($item) && empty($item['b_premium']);
}

/*
 * Core's two built-in features. Registered unconditionally, like the core widget and
 * field types, so they exist -- and are overridable by a site -- whether or not billing
 * is switched on; Entitlements::canPublish() is what actually gates enforcement on
 * osc_billing_enabled().
 */
osc_register_billing_feature('listing.publish', array(
    'label'    => 'Extra listing',
    'consumes' => Feature::CONSUMES_QUANTITY,
    'price'    => static function () {
        return osc_billing_publish_credits();
    },
    'apply'    => static function (int $userId, array $ctx): bool {
        return Entitlements::grant($userId, 'listing.publish', 1, null, Entitlements::SOURCE_PURCHASE);
    },
));

osc_register_billing_feature('listing.premium', array(
    'label'    => 'Featured listing',
    'consumes' => Feature::CONSUMES_DURATION,
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

        return (bool) (new ItemActions())->premium((int) $itemId, true, osc_billing_premium_days());
    },
));

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
