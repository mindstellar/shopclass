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
use mindstellar\admin\form\LatestSearchSettingsForm;

/**
 * Class CAdminSettingsLatestSearches
 */
class CAdminSettingsLatestSearches extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_latest hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_latest');
    }

    //Business Layer...
    /**
     * Draws the latest-searches settings form, or saves a posted one and redirects back to it.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('latestsearches'):
                //calling the latest searches settings view
                $this->drawForm();
                break;
            case ('latestsearches_post'):
                osc_csrf_check();

                $result = CoreSettings::attempt(LatestSearchSettingsForm::register());
                if ($result['errors'] !== array()) {
                    // Nothing was written, not even the switch: a rejected save is not half
                    // a save. Redrawn with what was typed rather than thrown away.
                    $this->drawForm($result['values']);
                    break;
                }

                osc_add_flash_ok_message(_m('Last search settings have been updated'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=latestsearches');
                break;
        }
    }

    /**
     * Exports the latest-searches settings form and renders its view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        $this->_exportVariableToView('searches_form', LatestSearchSettingsForm::formVars($values));
        $this->doView('settings/searches.php');
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsLatestSearches.php
