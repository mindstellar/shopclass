<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\billing\Billing;

/**
 * The billing settings screen, which is five independent forms: the switch that turns
 * selling on at all, what posting and featuring cost, the built-in bank-transfer method,
 * the per-listing upgrades, and the seller limits that can be bought past.
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
final class BillingSettingsForm
{
    public const PAGE_SWITCH = 'core.settings_billing';

    public const PAGE_PRICING = 'core.settings_billing_pricing';

    public const PAGE_OFFLINE = 'core.settings_billing_offline';

    public const PAGE_UPGRADES = 'core.settings_billing_upgrades';

    public const PAGE_LIMITS = 'core.settings_billing_limits';

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
                __('Turn this on to sell things on your site — featured listings, posting credits, '
                   . 'or whatever a payment plugin offers. Leave it off and nothing changes: posting '
                   . 'stays free and unlimited, and the Billing menu stays hidden.')
            )
            ->checkbox(
                Billing::PREF_ENABLED,
                __('Enable billing'),
                __('Switching this off later hides the Billing menu but keeps every order '
                   . 'and balance exactly as it is. Nothing is deleted.')
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
                __('What posting costs once the free quota runs out, and what a featured '
                   . 'listing costs. These apply whether a payment plugin is installed or an '
                   . 'admin grants credits by hand.')
            )
            ->number(
                'billing_free_live_listings',
                __('Free listings per seller'),
                __('How many of a seller\'s listings may be live '
                   . '(published, not yet expired) at once. 0 means '
                   . 'unlimited. A pending or admin-disabled listing '
                   . 'still counts -- only expiry or deletion frees a slot. '
                   . 'Lowering this never touches a listing a seller already '
                   . 'has -- it only changes whether their next post is allowed.')
            )
                ->clampMin(0)
                ->default(0)
            ->checkbox(
                'billing_slot_enabled',
                __('Sell extra listing slots'),
                __('Enabled with a price of 0 means every seller can raise their '
                   . 'own slot ceiling for free.')
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
                __('Enabled with a price of 0 means every seller can feature a listing for free.')
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
                ->attrs(array('maxlength' => 3))
                ->required()
                ->sanitize(static fn ($value) => strtoupper((string)$value))
                ->validate(static function ($value) {
                    return preg_match('/^[A-Z]{3}$/', (string)$value)
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
                __('Core\'s built-in payment method — no card processor, no API keys. A buyer '
                   . 'sees these instructions at checkout and pays outside the site; you settle '
                   . 'the order by hand once the money arrives.')
            )
            ->checkbox(
                'billing_offline_enabled',
                __('Accept bank transfer / cash payment'),
                __('Stays hidden at checkout until instructions are written below — '
                   . 'there is nothing for a buyer to pay if they do not know where to send it.')
            )
                ->rowLabel(__('Availability'))
            ->textarea(
                'billing_offline_instructions',
                __('Payment instructions'),
                __('Bank details, or wherever a buyer sends the money. Shown to the '
                   . 'buyer exactly as written here.')
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
                __('Bump, highlight and urgent are optional item upgrades, each priced and switched '
                   . 'on separately. Every one ships off — turning it on with a price of 0 makes it '
                   . 'free to every seller, not the same as leaving it off.')
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
                __('Photo count, the posting wait, and listing runtime are global limits by '
                   . 'default. Turning one of these on lets a seller raise their own ceiling by '
                   . 'buying it — everyone else keeps the global limit unchanged.')
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
     * What the view needs to draw all five forms, keyed by the section each one is.
     *
     * @param string                   $rejected the page id of the form that was refused, if any
     * @param array<string,mixed>|null $values   that form's submitted values
     *
     * @return array<string,array<string,mixed>> view variables per section: 'switch',
     *         'pricing', 'offline', 'upgrades', 'limits'
     */
    public static function formVars(string $rejected = '', ?array $values = null): array
    {
        $forms = array(
            'switch'   => array(self::registerSwitch(), 'billing_post', __('Save settings')),
            'pricing'  => array(self::registerPricing(), 'billing_pricing_post', __('Save pricing')),
            'offline'  => array(self::registerOffline(), 'billing_offline_post', __('Save bank transfer settings')),
            'upgrades' => array(self::registerUpgrades(), 'billing_upgrades_post', __('Save upgrade settings')),
            'limits'   => array(self::registerLimits(), 'billing_limits_post', __('Save limits')),
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
