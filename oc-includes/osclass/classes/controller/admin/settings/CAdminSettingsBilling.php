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

use mindstellar\admin\form\BillingSettingsForm;
use mindstellar\admin\form\CoreSettings;
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
    /** The five forms on the screen, by the action each posts to. */
    private const FORMS = array(
        'billing_post',
        'billing_pricing_post',
        'billing_offline_post',
        'billing_upgrades_post',
        'billing_limits_post',
    );

    /**
     * Boots the admin controller and fires the init_admin_settings_billing hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_billing');
    }

    //Business Layer...
    /**
     * Saves whichever of the five billing forms was posted, otherwise draws the screen.
     *
     * @return void
     */
    public function doModel()
    {
        if (in_array($this->action, self::FORMS, true)) {
            $this->save($this->action);

            return;
        }

        $this->drawForms();
    }

    /**
     * Save one of the five forms. Which preferences are written, and what is re-registered
     * afterwards, is the declaration's; all that is left here is the CSRF check, the message
     * and where to go next.
     *
     * @param string $action the form action that was posted, one of self::FORMS
     *
     * @return void
     */
    private function save(string $action)
    {
        osc_csrf_check();

        $pageId = self::pageId($action);
        $result = CoreSettings::attempt($pageId);
        if ($result['errors'] !== array()) {
            // Redrawn with what was typed rather than thrown away with a redirect.
            $this->drawForms($pageId, $result['values']);

            return;
        }

        osc_add_flash_ok_message(self::saved($action), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
    }

    /**
     * What each form says once it has saved. Spelled out rather than looked up, because a
     * message built from a variable is one the translators never see.
     *
     * @param string $action
     *
     * @return string
     */
    private static function saved(string $action): string
    {
        switch ($action) {
            case 'billing_pricing_post':
                return _m('Pricing settings have been updated');
            case 'billing_offline_post':
                return _m('Bank transfer settings have been updated');
            case 'billing_upgrades_post':
                return _m('Upgrade settings have been updated');
            case 'billing_limits_post':
                return _m('Seller limit settings have been updated');
            default:
                return _m('Billing settings have been updated');
        }
    }

    /**
     * The declared page one action saves, registered on the way past.
     *
     * @param string $action
     *
     * @return string
     */
    private static function pageId(string $action): string
    {
        switch ($action) {
            case 'billing_pricing_post':
                return BillingSettingsForm::registerPricing();
            case 'billing_offline_post':
                return BillingSettingsForm::registerOffline();
            case 'billing_upgrades_post':
                return BillingSettingsForm::registerUpgrades();
            case 'billing_limits_post':
                return BillingSettingsForm::registerLimits();
            default:
                return BillingSettingsForm::registerSwitch();
        }
    }

    /**
     * Exports the five billing forms and the gateway list, then renders the billing view.
     *
     * @param string     $rejected the page id of the form that was refused, if any
     * @param array|null $values   that form's submitted values
     *
     * @return void
     */
    private function drawForms(string $rejected = '', ?array $values = null)
    {
        // Exported under the name it has always had as well: a replaced admin theme's own
        // view reads it, and View::_get() answers '' for a key nobody exported -- so the
        // switch would draw unticked and the next save would turn billing off.
        $this->_exportVariableToView('billing_enabled', osc_billing_enabled());
        $this->_exportVariableToView('gateways', PaymentGatewayRegistry::instance()->all());
        $this->_exportVariableToView('billing_forms', BillingSettingsForm::formVars($rejected, $values));
        $this->doView('settings/billing.php');
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsBilling.php */
