<?php
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
 * Class CAdminUpgrade
 */
class CAdminUpgrade extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_upgrade');
    }

    //Business Layer...
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            default:
                $this->doView('upgrade/index.php');
        }
    }

    //hopefully generic...
}
