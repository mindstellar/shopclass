<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
