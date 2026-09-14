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

use mindstellar\admin\form\store\StoreFactory;

/**
 * Class CAdminSettingsCustom
 *
 * The one controller behind every declared settings page (osc_register_settings_page()).
 * It is what a plugin no longer has to write: the CSRF check, the capability check, the
 * save, the flash message and the redirect, once, for all of them.
 */
class CAdminSettingsCustom extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_custom hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_custom');
    }

    //Business Layer...
    /**
     * Serves one declared settings page: resolves it from the id param, checks its capability, then saves or renders it.
     *
     * @return void
     */
    public function doModel()
    {
        $id   = Params::getParam('id');
        $page = is_string($id) ? osc_settings_page($id) : null;

        // An unregistered id is the normal case for a bookmarked URL whose plugin has since
        // been deactivated, so it is a message and a redirect rather than an error page.
        if ($page === null) {
            osc_add_flash_error_message(_m('That settings page is not available'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=settings');

            return;
        }

        // A page may ask for administrator even though the section it sits in lets a
        // moderator in. AdminSecBaseModel has already checked the section.
        if ($page['capability'] === 'administrator' && $this->isModerator()) {
            osc_add_flash_error_message(_m("You don't have enough permissions"), 'admin');
            $this->redirectTo(osc_admin_base_url());

            return;
        }

        // A table-backed page edits one row, and nothing here knows which: taking the key
        // out of the request would let anyone who can reach this page name somebody else's
        // row, and taking none at all inserts a copy on every save. Until a controller that
        // owns its row drives them, these pages are declared but not served.
        if (StoreFactory::isTable($page)) {
            osc_add_flash_error_message(
                _m('That settings page saves into a table, which needs a controller of its own'),
                'admin'
            );
            $this->redirectTo(osc_admin_base_url(true) . '?page=settings');

            return;
        }

        switch ($this->action) {
            case ('custom_post'):
                osc_csrf_check();
                $result = osc_settings_save($page['id']);

                if ($result['errors'] !== array()) {
                    foreach ($result['errors'] as $error) {
                        osc_add_flash_error_message($error, 'admin');
                    }
                    // Re-render rather than redirect, so the values that were rejected are
                    // still on screen to be corrected.
                    $this->render($page, $result['values']);

                    return;
                }

                osc_add_flash_ok_message(
                    $result['updated'] > 0
                        ? _m('Settings have been updated')
                        : _m('Nothing to update'),
                    'admin'
                );
                $this->redirectTo(osc_settings_page_url($page['id']));
                break;
            default:
                $this->render($page, osc_settings_values($page['id']));
                break;
        }
    }

    /**
     * Draw the page from core's own view, with the values it should show.
     *
     * @param array $page
     * @param array $values
     *
     * @return void
     */
    private function render(array $page, array $values)
    {
        $this->_exportVariableToView('settings_page', $page);
        $this->_exportVariableToView('settings_values', $values);

        osc_run_hook('before_admin_html');
        require osc_lib_path() . 'osclass/gui/admin/settings-page.php';
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_admin_html');
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCustom.php */
