<?php

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

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;
use mindstellar\utility\AjaxResponse;
use mindstellar\utility\Utils;

/**
 * Class AdminSecBaseModel
 */
class AdminSecBaseModel extends SecBaseModel
{
    /**
     * Enforces moderator page access, carries a version-only upgrade across, and fires init_admin.
     */
    public function __construct()
    {
        parent::__construct();

        // check if is moderator and can enter to this page
        if ($this->isModerator()
            && !in_array($this->page, \mindstellar\admin\ModeratorAccess::pages(), false)
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
     * Anything with real work waiting still goes to the screen.
     *
     * @param string $configVersion the version the code on disk declares
     *
     * @return bool true when the version was carried across and the request may continue
     */
    private function autoUpgradeVersion($configVersion)
    {
        try {
            $runner = new MigrationRunner(
                Connection::getInstance(),
                \mindstellar\admin\DatabaseTools::migrationsDir()
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
     * Whether the logged-in admin is a moderator rather than a full administrator.
     *
     * @return bool
     */
    public function isModerator()
    {
        return osc_is_moderator();
    }

    /**
     * Whether an admin user is logged in.
     *
     * @return bool
     */
    public function isLogged()
    {
        return osc_is_admin_user_logged_in();
    }

    /**
     * Ends the admin session, expires its cookie and drops the remember-me cookies. A chosen
     * admin locale is kept in a new session under a new id.
     *
     * @return void
     */
    public function logout()
    {
        $locale = Session::getInstance()->_get('oc_adminLocale');
        Session::getInstance()->_drop('adminId');
        Session::getInstance()->_drop('adminUserName');
        Session::getInstance()->_drop('adminName');
        Session::getInstance()->_drop('adminEmail');
        Session::getInstance()->_drop('adminStamp');
        Session::getInstance()->_drop('adminLocale');
        Session::getInstance()->session_end();
        if ($locale !== '') {
            Session::getInstance()->_set('oc_adminLocale', $locale);
        }

        Cookie::getInstance()->pop('oc_adminId');
        Cookie::getInstance()->pop('oc_adminSecret');
        Cookie::getInstance()->pop('oc_adminLocale');
        Cookie::getInstance()->set();
    }

    /**
     * Renders an admin theme template, wrapped in the before/after_admin_html hooks.
     *
     * @param string $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_admin_html');
        osc_current_admin_theme_path($file);
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_admin_html');
    }

    /**
     * Answers an ajax request with a session-timeout error, otherwise remembers the requested
     * page and redirects to the admin login.
     *
     * @return void
     */
    public function showAuthFailPage()
    {
        if (Params::getParam('page') === 'ajax') {
            AjaxResponse::json(array('error' => 1, 'msg' => __('Session timed out')));
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
