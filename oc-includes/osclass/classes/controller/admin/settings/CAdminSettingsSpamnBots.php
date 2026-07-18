<?php if (!defined('ABS_PATH')) {
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
 * Class CAdminSettingsSpamnBots
 */
class CAdminSettingsSpamnBots extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_spam');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('spamNbots'):
                // calling the spam and bots view
                $akismet_key    = osc_akismet_key();
                $akismet_status = 3;
                if ($akismet_key != '') {
                    require_once(osc_lib_path() . 'Akismet.class.php');
                    $akismet_obj    = new Akismet(osc_base_url(), $akismet_key);
                    $akismet_status = 2;
                    if ($akismet_obj->isKeyValid()) {
                        $akismet_status = 1;
                    }
                }

                View::newInstance()->_exportVariableToView('akismet_status', $akismet_status);
                $this->doView('settings/spamNbots.php');
                break;
            case ('akismet_post'):
                // updating spam and bots option
                osc_csrf_check();
                $updated    = 0;
                $akismetKey = Params::getParam('akismetKey');
                $akismetKey = trim($akismetKey);

                $updated = osc_set_preference('akismetKey', $akismetKey);

                if ($akismetKey == '') {
                    osc_add_flash_info_message(_m('Your Akismet key has been cleared'), 'admin');
                } else {
                    osc_add_flash_ok_message(_m('Your Akismet key has been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('recaptcha_post'):
                // updating spam and bots option
                osc_csrf_check();
                $iUpdated         = 0;
                $recaptchaPrivKey = Params::getParam('recaptchaPrivKey');
                $recaptchaPrivKey = trim($recaptchaPrivKey);
                $recaptchaPubKey  = Params::getParam('recaptchaPubKey');
                $recaptchaPubKey  = trim($recaptchaPubKey);
                $recaptchaVersion = Params::getParam('recaptchaVersion');
                $recaptchaVersion = trim($recaptchaVersion);

                $iUpdated += osc_set_preference('recaptchaPrivKey', $recaptchaPrivKey);
                $iUpdated += osc_set_preference('recaptchaPubKey', $recaptchaPubKey);
                $iUpdated += osc_set_preference('recaptcha_version', $recaptchaVersion);

                if ($recaptchaPubKey == '') {
                    osc_add_flash_info_message(_m('Your reCAPTCHA key has been cleared'), 'admin');
                } else {
                    osc_add_flash_ok_message(_m('Your reCAPTCHA key has been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('alerts_post'):
                // updating search-alert subscription option
                osc_csrf_check();
                $alertsRequireLogin = Params::getParam('alerts_require_login') != '' ? 1 : 0;
                osc_set_preference('alerts_require_login', $alertsRequireLogin, 'osclass', 'BOOLEAN');
                osc_add_flash_ok_message(_m('Search alert settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
        }
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsSpamnBots.php
