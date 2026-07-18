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
 * Class CAdminSettingsAdvanced
 */
class CAdminSettingsAdvanced extends AdminSecBaseModel
{

    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_advanced');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('advanced'):
                //calling the advanced settings view
                $this->doView('settings/advanced.php');
                break;
            case ('advanced_post'):
                // updating advanced settings
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=advanced');
                }
                osc_csrf_check();
                $subdomain_type = Params::getParam('e_type');
                if (!in_array($subdomain_type, array('category', 'country', 'region', 'city', 'user'))) {
                    $subdomain_type = '';
                }
                $iUpdated = osc_set_preference('subdomain_type', $subdomain_type);
                $iUpdated += osc_set_preference('subdomain_host', Params::getParam('s_host'));

                if ($iUpdated > 0) {
                    osc_add_flash_ok_message(_m('Advanced settings have been updated'), 'admin');
                }
                osc_calculate_location_slug(osc_subdomain_type());
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=advanced');
                break;
            case ('advanced_cache_flush'):
                osc_cache_flush();
                osc_add_flash_ok_message(_m('Cache flushed correctly'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=advanced');
                break;
        }
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMain.php
