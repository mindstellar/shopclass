<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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
use mindstellar\admin\form\MailServerSettingsForm;

/**
 * Class CAdminSettingsMailserver
 */
class CAdminSettingsMailserver extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_mail hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_mail');
    }

    //Business Layer...
    /**
     * Draws the mail server settings form, or saves a posted one and redirects back to it.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('mailserver'):
                // calling the mailserver view
                $this->drawForm();
                break;
            case ('mailserver_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=mailserver');
                }

                osc_csrf_check();

                $result = CoreSettings::attempt(MailServerSettingsForm::register());
                if ($result['errors'] !== array()) {
                    // Redrawn with what was typed rather than thrown away with a redirect.
                    $this->drawForm($result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Mail server configuration has changed'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=mailserver');
                break;
        }
    }

    /**
     * Exports the mail server settings form and renders its view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        $this->_exportVariableToView('mailserver_form', MailServerSettingsForm::formVars($values));
        $this->doView('settings/mailserver.php');
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMailserver.php
