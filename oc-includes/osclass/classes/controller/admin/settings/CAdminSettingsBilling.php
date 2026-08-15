<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;
use mindstellar\billing\PaymentGatewayRegistry;

/**
 * The billing switch, and the list of payment gateways installed on this site.
 *
 * This page stays reachable whether billing is on or off — it is where the switch lives,
 * so hiding it with the rest of the section would make the feature impossible to turn
 * back on.
 *
 * Class CAdminSettingsBilling
 */
class CAdminSettingsBilling extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_billing');
    }

    /** Integer preferences the Pricing section writes, with their minimum value. */
    private const PRICING_INT_PREFS = array(
        'billing_free_posts_per_period' => 0,
        'billing_period_days'           => 1,
        'billing_publish_credits'       => 0,
        'billing_premium_credits'       => 0,
        'billing_premium_days'          => 1,
    );

    /** Boolean preferences the Upgrades section writes. */
    private const UPGRADES_BOOL_PREFS = array(
        'billing_bump_enabled',
        'billing_highlight_enabled',
        'billing_urgent_enabled',
    );

    /** Integer preferences the Upgrades section writes, with their minimum value:
     *  prices clamp at 0, day/hour counts clamp at 1. */
    private const UPGRADES_INT_PREFS = array(
        'billing_bump_credits'        => 0,
        'billing_bump_cooldown_hours' => 1,
        'billing_highlight_credits'   => 0,
        'billing_highlight_days'      => 1,
        'billing_urgent_credits'      => 0,
        'billing_urgent_days'         => 1,
    );

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('billing_post'):
                osc_csrf_check();

                osc_set_preference(
                    Billing::PREF_ENABLED,
                    Params::getParam(Billing::PREF_ENABLED) != '' ? 1 : 0,
                    Billing::PREF_GROUP,
                    'BOOLEAN'
                );
                osc_reset_preferences();

                osc_add_flash_ok_message(_m('Billing settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
                break;
            case ('billing_pricing_post'):
                osc_csrf_check();

                $currency = strtoupper(trim(Params::getParamString('billing_currency')));
                if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                    osc_add_flash_error_message(_m('Currency must be a 3-letter code, e.g. USD.'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');

                    return;
                }

                foreach (self::PRICING_INT_PREFS as $key => $min) {
                    osc_set_preference(
                        $key,
                        max($min, Params::getParamInt($key)),
                        Billing::PREF_GROUP,
                        'INTEGER'
                    );
                }
                osc_set_preference('billing_currency', $currency, Billing::PREF_GROUP, 'STRING');
                osc_reset_preferences();

                osc_add_flash_ok_message(_m('Pricing settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
                break;
            case ('billing_offline_post'):
                osc_csrf_check();

                osc_set_preference(
                    'billing_offline_enabled',
                    Params::getParam('billing_offline_enabled') != '' ? 1 : 0,
                    Billing::PREF_GROUP,
                    'BOOLEAN'
                );
                osc_set_preference(
                    'billing_offline_instructions',
                    Params::getParamString('billing_offline_instructions'),
                    Billing::PREF_GROUP,
                    'STRING'
                );
                osc_reset_preferences();

                osc_add_flash_ok_message(_m('Bank transfer settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
                break;
            case ('billing_upgrades_post'):
                osc_csrf_check();

                foreach (self::UPGRADES_BOOL_PREFS as $key) {
                    osc_set_preference(
                        $key,
                        Params::getParam($key) != '' ? 1 : 0,
                        Billing::PREF_GROUP,
                        'BOOLEAN'
                    );
                }
                foreach (self::UPGRADES_INT_PREFS as $key => $min) {
                    osc_set_preference(
                        $key,
                        max($min, Params::getParamInt($key)),
                        Billing::PREF_GROUP,
                        'INTEGER'
                    );
                }
                osc_reset_preferences();
                // Re-run registration under the new preference values so a toggle
                // takes effect immediately, not only on the next request.
                osc_register_billing_item_upgrades();

                osc_add_flash_ok_message(_m('Upgrade settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
                break;
            default:
                $this->_exportVariableToView('billing_enabled', osc_billing_enabled());
                $this->_exportVariableToView('gateways', PaymentGatewayRegistry::instance()->all());
                $this->doView('settings/billing.php');
                break;
        }
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsBilling.php */
