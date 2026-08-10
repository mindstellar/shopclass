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
            default:
                $this->_exportVariableToView('billing_enabled', osc_billing_enabled());
                $this->_exportVariableToView('gateways', PaymentGatewayRegistry::instance()->all());
                $this->doView('settings/billing.php');
                break;
        }
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsBilling.php */
