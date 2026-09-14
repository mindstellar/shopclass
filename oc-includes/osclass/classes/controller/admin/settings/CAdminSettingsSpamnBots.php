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
use mindstellar\admin\form\SpamSettingsForm;

/**
 * Class CAdminSettingsSpamnBots
 */
class CAdminSettingsSpamnBots extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_spam hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_spam');
    }

    //Business Layer...
    /**
     * Draws the spam and bots screen, or saves one of its forms (Akismet, captcha, alerts, login throttle).
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('spamNbots'):
                // calling the spam and bots view
                $this->drawForms();
                break;
            case ('akismet_post'):
                // updating the Akismet key
                osc_csrf_check();

                $result = CoreSettings::attempt(SpamSettingsForm::registerAkismet());
                if ($result['errors'] !== array()) {
                    $this->drawForms(SpamSettingsForm::PAGE_AKISMET, $result['values']);
                    break;
                }

                if ($result['values']['akismetKey'] === '') {
                    osc_add_flash_info_message(_m('Your Akismet key has been cleared'), 'admin');
                } else {
                    osc_add_flash_ok_message(_m('Your Akismet key has been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('recaptcha_post'):
                // updating the captcha provider and its keys
                osc_csrf_check();

                $result = CoreSettings::attempt(SpamSettingsForm::registerCaptcha());
                if ($result['errors'] !== array()) {
                    $this->drawForms(SpamSettingsForm::PAGE_CAPTCHA, $result['values']);
                    break;
                }

                if ($result['values']['recaptchaPubKey'] === '') {
                    osc_add_flash_info_message(_m('Your reCAPTCHA key has been cleared'), 'admin');
                } else {
                    osc_add_flash_ok_message(_m('Your reCAPTCHA key has been updated'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('alerts_post'):
                // updating search-alert subscription option
                osc_csrf_check();

                $result = CoreSettings::attempt(SpamSettingsForm::registerAlerts());
                if ($result['errors'] !== array()) {
                    $this->drawForms(SpamSettingsForm::PAGE_ALERTS, $result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Search alert settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('login_throttle_post'):
                // updating the sign-in rate limit
                osc_csrf_check();

                $result = CoreSettings::attempt(SpamSettingsForm::registerLoginThrottle());
                if ($result['errors'] !== array()) {
                    $this->drawForms(SpamSettingsForm::PAGE_LOGIN_THROTTLE, $result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Sign-in protection settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
            case ('login_throttle_reset'):
                // clearing every recorded attempt, so an operator can let a
                // locked-out visitor (or themselves) back in immediately
                osc_csrf_check();
                LoginAttempt::newInstance()->pruneBefore(date('Y-m-d H:i:s'));
                osc_add_flash_ok_message(_m('Recorded sign-in attempts have been cleared'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=spamNbots');
                break;
        }
    }

    /**
     * Draw the screen: four independent forms, at most one of which is being handed back
     * what was typed into it.
     *
     * @param string     $rejected the page id of the form that was refused, if any
     * @param array|null $values   that form's submitted values
     *
     * @return void
     */
    private function drawForms(string $rejected = '', ?array $values = null)
    {
        // Whether the stored key is one Akismet recognises. A request to their service, so
        // it is made only for the screen that shows the answer.
        $akismetKey    = osc_akismet_key();
        $akismetStatus = 3;
        if ($akismetKey != '') {
            require_once(osc_lib_path() . 'Akismet.class.php');
            $akismet       = new Akismet(osc_base_url(), $akismetKey);
            $akismetStatus = $akismet->isKeyValid() ? 1 : 2;
        }

        // Exported under the name it has always had as well: a replaced admin theme's own
        // view reads it, and View::_get() answers '' for a key nobody exported -- which
        // reads as "no key configured" rather than as anything being wrong.
        $this->_exportVariableToView('akismet_status', $akismetStatus);
        $this->_exportVariableToView('spam_forms', SpamSettingsForm::formVars($akismetStatus, $rejected, $values));
        $this->doView('settings/spamNbots.php');
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsSpamnBots.php
