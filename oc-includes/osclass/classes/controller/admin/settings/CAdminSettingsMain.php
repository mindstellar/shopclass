<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\CoreSettings;
use mindstellar\admin\form\MainSettingsForm;

/**
 * Class CAdminSettingsMain
 */
class CAdminSettingsMain extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_main hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_main');
    }

    //Business Layer...
    /**
     * Draws the general settings form, saves a posted one, or forces a theme/plugin update check.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('check_updates'):
                osc_admin_toolbar_update_themes(true);
                osc_admin_toolbar_update_plugins(true);

                osc_add_flash_ok_message(_m('Last check') . ':   ' . date('Y-m-d H:i'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings');
                break;
            case ('update'):
                // update index view
                osc_csrf_check();

                $result = CoreSettings::attempt(MainSettingsForm::register());
                if ($result['errors'] !== array()) {
                    // Redrawn with what was typed rather than thrown away with a redirect.
                    $this->drawForm($result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('General settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings');
                break;
            default:
                // calling the view
                $this->drawForm();
                break;
        }
    }

    /**
     * Exports the general settings form, its language and currency lists, and renders the view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        // The declared form carries its own choice lists. These two are exported beside it
        // under the names they have always had, because a replaced admin theme's own view
        // reads them and would silently draw empty selects without them.
        $this->_exportVariableToView('aLanguages', OSCLocale::newInstance()->listAllEnabled());
        $this->_exportVariableToView('aCurrencies', Currency::newInstance()->listAll());
        $this->_exportVariableToView('main_form', MainSettingsForm::formVars($values));
        $this->doView('settings/index.php');
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMain.php
