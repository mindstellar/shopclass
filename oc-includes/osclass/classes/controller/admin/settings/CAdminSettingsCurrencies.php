<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\currency\CurrencyService;
use mindstellar\validation\ConflictException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;

/**
 * Class CAdminSettingsCurrencies
 */
class CAdminSettingsCurrencies extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_currencies hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_currencies');
    }

    //Business Layer...
    /**
     * Routes the currency actions (add, edit, delete) and their views, keyed off the type param.
     *
     * @return void
     */
    public function doModel()
    {
        switch (Params::getParam('type')) {
            case ('add'):
                // calling add currency view
                $aCurrency = array(
                    'pk_c_code'     => '',
                    's_name'        => '',
                    's_description' => '',
                );
                $this->_exportVariableToView('aCurrency', $aCurrency);
                $this->_exportVariableToView('typeForm', 'add_post');

                $this->doView('settings/currency_form.php');
                break;
            case ('add_post'):
                osc_csrf_check();
                try {
                    $this->service()->create(Params::getParamString('pk_c_code'), Params::getParamString('s_name'), Params::getParamString('s_description'));
                    osc_add_flash_ok_message(_m('Currency added'), 'admin');
                } catch (InvalidException $e) {
                    osc_add_flash_error_message($e->getMessage(), 'admin');
                } catch (RefusedException | RuntimeException $e) {
                    osc_add_flash_error_message(_m("Currency couldn't be added"), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=currencies');
                break;
            case ('edit'):
                // calling edit currency view
                $currencyCode = Params::getParam('code');
                $currencyCode = trim(strip_tags($currencyCode));

                if ($currencyCode == '') {
                    osc_add_flash_warning_message(
                        sprintf(_m("The currency code '%s' doesn't exist"), $currencyCode),
                        'admin'
                    );
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=currencies');
                }

                $aCurrency = Currency::getInstance()->findByPrimaryKey($currencyCode);

                if (!$aCurrency) {
                    osc_add_flash_warning_message(
                        sprintf(_m("The currency code '%s' doesn't exist"), $currencyCode),
                        'admin'
                    );
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=currencies');
                }

                $this->_exportVariableToView('aCurrency', $aCurrency);
                $this->_exportVariableToView('typeForm', 'edit_post');

                $this->doView('settings/currency_form.php');
                break;
            case ('edit_post'):
                osc_csrf_check();
                $currencyCode = trim(Params::getParamString('pk_c_code'));
                try {
                    if ($this->service()->update($currencyCode, Params::getParamString('s_name'), Params::getParamString('s_description'))) {
                        osc_add_flash_ok_message(_m('Currency updated'), 'admin');
                    } else {
                        osc_add_flash_info_message(_m('No changes were made'), 'admin');
                    }
                } catch (NotFoundException $e) {
                    osc_add_flash_warning_message(sprintf(_m("The currency code '%s' doesn't exist"), $currencyCode), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=currencies');
                break;
            case ('delete'):
                // deleting a currency
                osc_csrf_check();
                $rowChanged    = 0;
                $aCurrencyCode = Params::getParam('code');

                if (!is_array($aCurrencyCode)) {
                    $aCurrencyCode = array($aCurrencyCode);
                }

                $msg_current = '';
                foreach ($aCurrencyCode as $currencyCode) {
                    $currencyCode = is_string($currencyCode) ? trim($currencyCode) : '';
                    try {
                        $this->service()->delete($currencyCode);
                        $rowChanged++;
                    } catch (ConflictException $e) {
                        $msg_current .= '</p><p>' . osc_esc_html($currencyCode . ': ' . $e->getMessage());
                    } catch (RefusedException | RuntimeException $e) {
                        continue;
                    }
                }

                $msg    = '';
                $status = '';
                switch ($rowChanged) {
                    case ('0'):
                        $msg    = _m('No currencies have been deleted');
                        $status = 'error';
                        break;
                    case ('1'):
                        $msg    = _m('One currency has been deleted');
                        $status = 'ok';
                        break;
                    default:
                        $msg    = sprintf(_m('%s currencies have been deleted'), $rowChanged);
                        $status = 'ok';
                        break;
                }

                if ($status == 'ok' && $msg_current != '') {
                    $status = 'warning';
                }

                switch ($status) {
                    case ('error'):
                        osc_add_flash_error_message($msg . $msg_current, 'admin');
                        break;
                    case ('warning'):
                        osc_add_flash_warning_message($msg . $msg_current, 'admin');
                        break;
                    case ('ok'):
                        osc_add_flash_ok_message($msg, 'admin');
                        break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=currencies');
                break;
            default:
                // calling the currencies view
                $aCurrencies = Currency::getInstance()->listAll();
                $this->_exportVariableToView('aCurrencies', $aCurrencies);

                $this->doView('settings/currencies.php');
                break;
        }
    }

    private function service(): CurrencyService
    {
        return CurrencyService::make();
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsCurrencies.php
