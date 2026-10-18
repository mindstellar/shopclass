<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\billing\Billing;
use mindstellar\billing\Receipts;
use mindstellar\currency\CurrencyCode;
use mindstellar\currency\Money;

/**
 * The billing settings screen, which is seven independent forms: the switch that turns
 * selling on at all, what posting and featuring cost, the built-in bank-transfer method,
 * the per-listing upgrades, the seller limits that can be bought past, receipts, and the
 * shop (delivery, cash on delivery, held orders, order numbers).
 *
 * Each of the last four re-runs the registration its values feed, so a switch takes effect
 * on the request that flipped it rather than the next one. That is a save-time effect, so
 * it is declared: after a submission that was refused, nothing is re-registered because
 * nothing was written.
 *
 * Every price and count is floored rather than refused, which is what the hand-written
 * controller did -- a negative price is a typo, not a reason to throw the form away.
 *
 * @package mindstellar\admin\form
 */
final class BillingSettingsScreen
{
    public const PAGE_SWITCH = 'core.settings_billing';

    public const PAGE_PRICING = 'core.settings_billing_pricing';

    public const PAGE_OFFLINE = 'core.settings_billing_offline';

    public const PAGE_UPGRADES = 'core.settings_billing_upgrades';

    public const PAGE_LIMITS = 'core.settings_billing_limits';

    public const PAGE_RECEIPTS = 'core.settings_billing_receipts';

    public const PAGE_SHOP = 'core.settings_billing_shop';

    /**
     * The switch. Nothing else on the screen matters while it is off, which is why it has a
     * form to itself and why the screen stays reachable either way.
     *
     * @return string the page id
     */
    public static function registerSwitch(): string
    {
        if (osc_settings_page(self::PAGE_SWITCH) !== null) {
            return self::PAGE_SWITCH;
        }

        CoreSettings::page(self::PAGE_SWITCH, __('Billing settings'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
            })
            ->group(
                __('Billing'),
                __('Sell featured listings, posting credits or plugin extras. While off, posting stays free and the Billing menu is hidden.')
            )
            ->checkbox(
                Billing::PREF_ENABLED,
                __('Enable billing'),
                __('Turning it off later keeps every order and balance.')
            )
                ->rowLabel(__('Selling on this site'))
            ->register();

        return self::PAGE_SWITCH;
    }

    /**
     * What posting and featuring cost.
     *
     * @return string the page id
     */
    public static function registerPricing(): string
    {
        if (osc_settings_page(self::PAGE_PRICING) !== null) {
            return self::PAGE_PRICING;
        }

        CoreSettings::page(self::PAGE_PRICING, __('Pricing'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
                // Re-run registration under the new preference values so a toggle
                // takes effect immediately, not only on the next request.
                osc_register_billing_premium();
                osc_register_billing_slot();
            })
            ->group(
                __('Pricing'),
                __('Applies with or without a payment plugin.')
            )
            ->number(
                'billing_free_live_listings',
                __('Free listings per seller'),
                __('Live listings a seller may have at once. 0 means no limit. Pending listings count; only expiry or deletion frees a slot.')
            )
                ->clampMin(0)
                ->default(0)
            ->checkbox(
                'billing_slot_enabled',
                __('Sell extra listing slots'),
                __('A price of 0 makes extra slots free.')
            )
                ->rowLabel(__('Extra slots'))
            ->number('billing_slot_credits', __('Slot price in credits'))
                ->clampMin(0)
                ->default(0)
            ->number('billing_slot_quantity', __('Slots per purchase'))
                ->clampMin(1)
                ->default(1)
            ->checkbox(
                'billing_premium_enabled',
                __('Sell featured listings'),
                __('A price of 0 makes featuring free.')
            )
                ->rowLabel(__('Featured listings'))
            ->number('billing_premium_credits', __('Featured listing price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number('billing_premium_days', __('Featured listing duration (days)'))
                ->clampMin(1)
                ->default(30)
            ->text('billing_currency', __('Currency'), __('A 3-letter ISO 4217 code, e.g. USD, EUR.'))
                ->default('USD')
                ->width('num')
                ->attrs(array('maxlength' => '3'))
                ->required()
                ->sanitize(static fn ($value) => strtoupper((string)$value))
                ->validate(static function ($value) {
                    return CurrencyCode::valid((string)$value)
                        ? null
                        : _m('Currency must be a 3-letter code, e.g. USD.');
                })
            ->register();

        return self::PAGE_PRICING;
    }

    /**
     * Core's own payment method: instructions a buyer pays against outside the site.
     *
     * @return string the page id
     */
    public static function registerOffline(): string
    {
        if (osc_settings_page(self::PAGE_OFFLINE) !== null) {
            return self::PAGE_OFFLINE;
        }

        CoreSettings::page(self::PAGE_OFFLINE, __('Bank transfer'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
            })
            ->group(
                __('Bank transfer'),
                __('The buyer sees your instructions at checkout and pays outside the site. You mark the order paid.')
            )
            ->checkbox(
                'billing_offline_enabled',
                __('Accept bank transfer / cash payment'),
                __('Hidden at checkout until you write instructions.')
            )
                ->rowLabel(__('Availability'))
            ->textarea(
                'billing_offline_instructions',
                __('Payment instructions'),
                __('Shown to the buyer as written.')
            )
                ->set('rows', 5)
                ->width('key')
            ->register();

        return self::PAGE_OFFLINE;
    }

    /**
     * The optional per-listing upgrades.
     *
     * @return string the page id
     */
    public static function registerUpgrades(): string
    {
        if (osc_settings_page(self::PAGE_UPGRADES) !== null) {
            return self::PAGE_UPGRADES;
        }

        CoreSettings::page(self::PAGE_UPGRADES, __('Upgrades'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
                // Re-run registration under the new preference values so a toggle
                // takes effect immediately, not only on the next request.
                osc_register_billing_item_upgrades();
            })
            ->group(
                __('Upgrades'),
                __('Each upgrade is off until you turn it on. A price of 0 makes it free.')
            )
            ->checkbox('billing_bump_enabled', __('Sell bump to top'))
                ->rowLabel(__('Bump to top'))
            ->number('billing_bump_credits', __('Bump price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number(
                'billing_bump_cooldown_hours',
                __('Cooldown (hours)'),
                __('How long a listing must wait before it can be bumped again.')
            )
                ->clampMin(1)
                ->default(24)
            ->checkbox('billing_highlight_enabled', __('Sell highlighting'))
                ->rowLabel(__('Highlight'))
            ->number('billing_highlight_credits', __('Highlight price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number('billing_highlight_days', __('Highlight duration (days)'))
                ->clampMin(1)
                ->default(30)
            ->checkbox('billing_urgent_enabled', __('Sell marking a listing urgent'))
                ->rowLabel(__('Urgent'))
            ->number('billing_urgent_credits', __('Urgent price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number('billing_urgent_days', __('Urgent duration (days)'))
                ->clampMin(1)
                ->default(7)
            ->register();

        return self::PAGE_UPGRADES;
    }

    /**
     * The global limits a seller can buy their way past.
     *
     * @return string the page id
     */
    public static function registerLimits(): string
    {
        if (osc_settings_page(self::PAGE_LIMITS) !== null) {
            return self::PAGE_LIMITS;
        }

        CoreSettings::page(self::PAGE_LIMITS, __('Seller limits'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
                // Re-run registration under the new preference values so a toggle
                // takes effect immediately, not only on the next request.
                osc_register_billing_seller_limits();
            })
            ->group(
                __('Seller limits'),
                __('Lets a seller buy a higher limit. Everyone else keeps the global limit.')
            )
            ->checkbox('billing_photos_enabled', __('Sell a raised photo cap'))
                ->rowLabel(__('Extra photos'))
            ->number('billing_photos_credits', __('Price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number(
                'billing_photos_quantity',
                __('Photo cap'),
                __('Photos allowed per listing while the entitlement is held.')
            )
                ->clampMin(1)
                ->default(10)
            ->checkbox('billing_no_wait_enabled', __('Sell waiving the flood wait'))
                ->rowLabel(__('Skip the posting wait'))
            ->number('billing_no_wait_credits', __('Price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number('billing_no_wait_days', __('Duration (days)'))
                ->clampMin(1)
                ->default(30)
            ->checkbox('billing_runtime_enabled', __('Sell extra runtime beyond the category limit'))
                ->rowLabel(__('Extra listing runtime'))
            ->number('billing_runtime_credits', __('Price (credits)'))
                ->clampMin(0)
                ->default(0)
            ->number(
                'billing_runtime_days',
                __('Extra days'),
                __('Added on top of the category\'s own expiration ceiling.')
            )
                ->clampMin(1)
                ->default(30)
            ->register();

        return self::PAGE_LIMITS;
    }

    /**
     * Payment receipts: whether they are e-mailed, and the business details they carry.
     *
     * @return string the page id
     */
    public static function registerReceipts(): string
    {
        if (osc_settings_page(self::PAGE_RECEIPTS) !== null) {
            return self::PAGE_RECEIPTS;
        }

        CoreSettings::page(self::PAGE_RECEIPTS, __('Receipts'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
            })
            ->group(
                __('Receipts'),
                __('A buyer can open and print a receipt for every paid order from their orders page. '
                   . 'A receipt is not a tax invoice.')
            )
            ->checkbox(Receipts::PREF_EMAIL, __('E-mail a receipt when an order is paid'))
                ->rowLabel(__('Receipt e-mail'))
                ->default(true)
            ->textarea(
                Receipts::PREF_BUSINESS,
                __('Business details on receipts'),
                __('Optional. Your business name, address and tax number, printed at the top '
                   . 'of every receipt exactly as written here.')
            )
                ->set('rows', 4)
                ->width('key')
            ->register();

        return self::PAGE_RECEIPTS;
    }

    /**
     * Selling goods: delivery charges, cash on delivery, how long unpaid offline orders hold
     * stock, and the order number prefix. Amounts are typed as money and stored as micros.
     *
     * @return string the page id
     */
    public static function registerShop(): string
    {
        if (osc_settings_page(self::PAGE_SHOP) !== null) {
            return self::PAGE_SHOP;
        }

        CoreSettings::page(self::PAGE_SHOP, __('Shop'), Billing::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_reset_preferences();
            })
            ->group(
                __('Delivery'),
                __('One delivery method, "Standard delivery", at a flat charge. Amounts are in the listings\' currency.')
            )
            ->field(self::money('billing_shipping_flat', __('Delivery charge'), __('0 makes delivery free.'), osc_get_int_preference('billing_shipping_flat', 0, 0)))
            ->field(self::money('billing_free_over', __('Free delivery from'), __('Orders of this much or more ship free. 0 means never.'), osc_get_int_preference('billing_free_over', 0, 0)))
            ->group(
                __('Cash on delivery'),
                __('The buyer pays the courier. You mark the order paid once it is delivered.')
            )
            ->checkbox('billing_cod_enabled', __('Accept cash on delivery'))
                ->rowLabel(__('Availability'))
            ->field(self::money('billing_cod_fee', __('Cash on delivery fee'), __('Added to the order total. 0 means no fee.'), osc_get_int_preference('billing_cod_fee', 0, 0)))
            ->group(
                __('Orders'),
                __('Bank transfer and cash on delivery orders keep their stock until they are paid.')
            )
            ->number(
                'billing_hold_days',
                __('Cancel unpaid orders after (days)'),
                __('An unpaid bank transfer or cash on delivery order is cancelled after this many days and its stock goes back on sale.')
            )
                ->clampMin(1)
                ->default(7)
            ->text('billing_order_prefix', __('Order number prefix'), __('Optional. Letters, digits and dashes, e.g. KC- gives #KC-1042.'))
                ->width('num')
                ->attrs(array('maxlength' => '10'))
                ->sanitize(static fn ($value) => (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $value))
            ->register();

        return self::PAGE_SHOP;
    }

    /**
     * A money field: typed in the site's number format, stored as micros, drawn from what is stored.
     *
     * @return array<string,mixed>
     */
    private static function money(string $name, string $label, string $help, int $stored): array
    {
        return array(
            'name'       => $name,
            'type'       => 'text',
            'label'      => $label,
            'help'       => $help,
            'width'      => 'num',
            'default'    => Money::fromMicros($stored),
            'write_only' => true,
            'validate'   => static fn ($value): ?string => trim((string) $value) === '' || Money::parse((string) $value) !== null
                ? null
                : _m('Enter an amount, e.g. 49 or 49.50.'),
            'persist'    => static fn ($value): string => (string) max(0, Money::parse((string) $value) ?? 0),
        );
    }

    /**
     * What the view needs to draw all seven forms, keyed by the section each one is.
     *
     * @param string                   $rejected the page id of the form that was refused, if any
     * @param array<string,mixed>|null $values   that form's submitted values
     *
     * @return array<string,array<string,mixed>> view variables per section: 'switch',
     *         'pricing', 'offline', 'upgrades', 'limits', 'receipts', 'shop'
     */
    public static function formVars(string $rejected = '', ?array $values = null): array
    {
        $forms = array(
            'switch'   => array(self::registerSwitch(), 'billing_post', __('Save settings')),
            'pricing'  => array(self::registerPricing(), 'billing_pricing_post', __('Save pricing')),
            'offline'  => array(self::registerOffline(), 'billing_offline_post', __('Save bank transfer settings')),
            'upgrades' => array(self::registerUpgrades(), 'billing_upgrades_post', __('Save upgrade settings')),
            'limits'   => array(self::registerLimits(), 'billing_limits_post', __('Save limits')),
            'receipts' => array(self::registerReceipts(), 'billing_receipts_post', __('Save receipt settings')),
            'shop'     => array(self::registerShop(), 'billing_shop_post', __('Save shop settings')),
        );

        $vars = array();
        foreach ($forms as $key => $form) {
            [$pageId, $action, $label] = $form;
            $vars[$key] = CoreSettings::vars(
                $pageId,
                $action,
                $pageId === $rejected ? $values : null,
                array('actions' => array(array('label' => $label, 'type' => 'submit')))
            );
        }

        return $vars;
    }
}
