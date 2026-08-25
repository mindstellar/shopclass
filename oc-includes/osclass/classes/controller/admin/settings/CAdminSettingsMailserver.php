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

/**
 * Class CAdminSettingsMailserver
 */
class CAdminSettingsMailserver extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_mail');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('mailserver'):
                // calling the mailserver view
                $this->doView('settings/mailserver.php');
                break;
            case ('mailserver_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=mailserver');
                }

                osc_csrf_check();
                // updating mailserver
                $iUpdated           = 0;
                $mailserverAuth     = Params::getParam('mailserver_auth');
                $mailserverAuth     = ($mailserverAuth != '' ? true : false);
                $mailserverPop      = Params::getParam('mailserver_pop');
                $mailserverPop      = ($mailserverPop != '' ? true : false);
                $mailserverType     = osc_mailserver_normalize_type(Params::getParam('mailserver_type'));
                $mailserverHost     = Params::getParam('mailserver_host');
                $mailserverPort     = Params::getParam('mailserver_port');
                $mailserverUsername = Params::getParam('mailserver_username');
                $mailserverPassword = Params::getParam('mailserver_password', false, false);
                $mailserverSsl      = Params::getParam('mailserver_ssl');
                $mailserverMailFrom = Params::getParam('mailserver_mail_from');
                $mailserverNameFrom = Params::getParam('mailserver_name_from');

                if (!in_array($mailserverType, osc_mailserver_allowed_types(), true)) {
                    osc_add_flash_error_message(_m('Mail server type is incorrect'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=mailserver');
                }

                $iUpdated += osc_set_preference('mailserver_auth', $mailserverAuth);
                $iUpdated += osc_set_preference('mailserver_pop', $mailserverPop);
                $iUpdated += osc_set_preference('mailserver_type', $mailserverType);
                $iUpdated += osc_set_preference('mailserver_host', $mailserverHost);
                $iUpdated += osc_set_preference('mailserver_port', $mailserverPort);
                $iUpdated += osc_set_preference('mailserver_username', $mailserverUsername);
                $iUpdated += osc_set_preference('mailserver_password', $mailserverPassword);
                $iUpdated += osc_set_preference('mailserver_ssl', $mailserverSsl);
                $iUpdated += osc_set_preference('mailserver_mail_from', $mailserverMailFrom);
                $iUpdated += osc_set_preference('mailserver_name_from', $mailserverNameFrom);

                $rawJson = '';
                if (isset($_POST['mailserver_presets_json']) && is_string($_POST['mailserver_presets_json'])) {
                    $rawJson = $_POST['mailserver_presets_json'];
                }
                $posted = ($rawJson !== '') ? json_decode($rawJson, true) : null;
                $presets = osc_mailserver_merge_posted_presets(
                    osc_mailserver_read_stored(),
                    $posted,
                    $mailserverType,
                    array(
                        'host'      => $mailserverHost,
                        'port'      => $mailserverPort,
                        'username'  => $mailserverUsername,
                        'password'  => $mailserverPassword,
                        'ssl'       => $mailserverSsl,
                        'auth'      => $mailserverAuth ? '1' : '',
                        'pop'       => $mailserverPop ? '1' : '',
                        'mail_from' => $mailserverMailFrom,
                        'name_from' => $mailserverNameFrom,
                    )
                );
                osc_mailserver_save_presets($presets);

                if ($iUpdated > 0) {
                    osc_add_flash_ok_message(_m('Mail server configuration has changed'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=mailserver');
                break;
        }
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMailserver.php
