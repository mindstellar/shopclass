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

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;
use mindstellar\utility\Utils;

/**
 * Class AdminSecBaseModel
 */
class AdminSecBaseModel extends SecBaseModel
{
    public function __construct()
    {
        parent::__construct();

        // check if is moderator and can enter to this page
        if ($this->isModerator()
            && !in_array($this->page, osc_apply_filter('moderator_access', array(
                'items',
                'comments',
                'media',
                'login',
                'admins',
                'ajax',
                'stats',
                ''
            )), false)
        ) {
            osc_add_flash_error_message(_m("You don't have enough permissions"), 'admin');
            $this->redirectTo(osc_admin_base_url());
        }
        osc_run_hook('init_admin');

        $config_version = OSCLASS_VERSION;
        $installed_version = osc_get_preference('version');
        if (strlen($installed_version) === 3) {
            // It's a legacy osclass version i.e. below 390 make it compatible with new methods
            $installed_version = implode('.', str_split($installed_version));
        }
        if (!defined('IS_AJAX')
            && !$this instanceof CAdminUpgrade
            && !$this instanceof CAdminTools
            && Utils::versionCompare($config_version, $installed_version, 'gt')
            && !$this->autoUpgradeVersion($config_version)
        ) {
            $this->redirectTo(osc_admin_base_url(true) . '?page=upgrade');
        }

        // show donation successful
        if (Params::getParam('donation') === 'successful') {
            osc_add_flash_ok_message(_m('Thank you very much for your donation'), 'admin');
        }

    }

    /**
     * Carry a version-only release across without the upgrade screen.
     *
     * A release that ships no migration has nothing to apply but its own version number,
     * and that is the usual case -- every 6.2.0 release candidate was one, and each still
     * locked the whole admin behind a screen with nothing to do. When the migration ledger
     * is already complete the version is written here and the request continues to the page
     * that was asked for.
     *
     * Anything with real work waiting still goes to the screen. So does the schema
     * reconcile, deliberately: it is the slow half of an upgrade, and meeting a drifted
     * schema unattended, part-way through somebody's page load, is the wrong way to find
     * out. An install carried across by this path has therefore not been reconciled --
     * running the upgrade screen by hand is still what does that.
     *
     * @param string $configVersion the version the code on disk declares
     *
     * @return bool true when the version was carried across and the request may continue
     */
    private function autoUpgradeVersion($configVersion)
    {
        try {
            $runner = new MigrationRunner(
                Connection::instance(),
                osc_lib_path() . 'osclass/installer/migrations'
            );
            $runner->ensureLedger();
            if ($runner->pending() !== array()) {
                return false;
            }
        } catch (Throwable $e) {
            // An unreadable ledger or migrations directory is not something to decide
            // silently -- send them to the screen, which reports what went wrong.
            return false;
        }

        // Re-read before writing: two admin requests can arrive together and both see the
        // old version. The write itself is idempotent, so this is only about not
        // announcing the same news twice.
        osc_reset_preferences();
        if (Utils::versionCompare($configVersion, (string) osc_get_preference('version'), 'gt')) {
            Utils::changeOsclassVersionTo($configVersion);
            osc_reset_preferences();
            osc_add_flash_ok_message(
                sprintf(_m('Shopclass has been updated to %s'), osc_esc_html($configVersion)),
                'admin'
            );
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isModerator()
    {
        return osc_is_moderator();
    }

    /**
     * @return bool
     */
    public function isLogged()
    {
        return osc_is_admin_user_logged_in();
    }

    public function logout()
    {
        //destroying session
        $locale = Session::newInstance()->_get('oc_adminLocale');
        Session::newInstance()->session_destroy();
        Session::newInstance()->_drop('adminId');
        Session::newInstance()->_drop('adminUserName');
        Session::newInstance()->_drop('adminName');
        Session::newInstance()->_drop('adminEmail');
        Session::newInstance()->_drop('adminLocale');
        Session::newInstance()->session_start();
        Session::newInstance()->_set('oc_adminLocale', $locale);

        Cookie::newInstance()->pop('oc_adminId');
        Cookie::newInstance()->pop('oc_adminSecret');
        Cookie::newInstance()->pop('oc_adminLocale');
        Cookie::newInstance()->set();
    }

    /**
     * @param $file
     */
    public function doView($file)
    {
        osc_run_hook('before_admin_html');
        osc_current_admin_theme_path($file);
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_admin_html');
    }

    public function showAuthFailPage()
    {
        if (Params::getParam('page') === 'ajax') {
            echo json_encode(array('error' => 1, 'msg' => __('Session timed out')));
            exit;
        }

        // Remember the protected page the admin was trying to reach, in a signed cookie
        // rather than the session, and send them to the admin login.
        osc_set_admin_login_redirect(
            osc_base_url()
            . Params::getRequestURI(false, false, false)
        );
        header('Location: ' . osc_admin_base_url(true) . '?page=login');
        exit;
    }
}

/* file end: ./oc-includes/osclass/core/AdminSecBaseModel.php */
