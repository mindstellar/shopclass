<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Osclass (Mindstellar).
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
 * Class CAdminSettingsLatestSearches
 */
class CAdminSettingsLatestSearches extends AdminSecBaseModel
{

    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_latest');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('latestsearches'):
                //calling the comments settings view
                $this->doView('settings/searches.php');
                break;
            case ('latestsearches_post'):
                // updating comment
                osc_csrf_check();
                if (Params::getParam('save_latest_searches') === 'on') {
                    osc_set_preference('save_latest_searches', 1);
                } else {
                    osc_set_preference('save_latest_searches', 0);
                }

                if (Params::getParam('customPurge') == '') {
                    osc_add_flash_error_message(_m('Custom number could not be left empty'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=latestsearches');
                } else {
                    osc_set_preference('purge_latest_searches', Params::getParam('customPurge'));

                    osc_add_flash_ok_message(_m('Last search settings have been updated'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=latestsearches');
                }
                break;
        }
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsLatestSearches.php
