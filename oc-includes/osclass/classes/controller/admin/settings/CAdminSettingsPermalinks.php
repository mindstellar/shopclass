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
use mindstellar\admin\form\PermalinkSettingsForm;

/**
 * Class CAdminSettingsPermalinks
 */
class CAdminSettingsPermalinks extends AdminSecBaseModel
{
    /**
     * Boots the admin controller and fires the init_admin_settings_permalinks hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_permalinks');
    }

    //Business Layer...
    /**
     * Draws the permalink settings form, or saves a posted one and redirects back to it.
     *
     * @return void
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('permalinks'):
                // calling the permalinks view
                $this->drawForm();
                break;
            case ('permalinks_post'):
                // updating permalinks option
                osc_csrf_check();

                $result = CoreSettings::attempt(PermalinkSettingsForm::register());
                if ($result['errors'] !== array()) {
                    // Nothing was written and nothing was put on disk: the rules file must
                    // never describe a structure the database does not hold. Redrawn with
                    // what was typed rather than thrown away.
                    $this->drawForm($result['values']);
                    break;
                }

                // What the save says is decided by the declaration's own after_save, which
                // is the half that knows whether the file could be written.
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=permalinks');
                break;
        }
    }

    /**
     * Exports the permalink settings form and renders its view.
     *
     * @param array|null $values values a rejected save is handing back
     *
     * @return void
     */
    private function drawForm(?array $values = null)
    {
        $this->_exportVariableToView('permalinks_form', PermalinkSettingsForm::formVars($values));
        $this->doView('settings/permalinks.php');
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsPermalinks.php
