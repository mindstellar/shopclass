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
 * Class AdminBaseModel
 */
class AdminBaseModel extends BaseModel
{
    public function __construct()
    {
        parent::__construct();

        osc_run_hook('init_admin_insecure');
    }

    public function doModel()
    {
    }

    /**
     * @param $file
     */
    public function doView($file)
    {
    }
}

/* file end: ./oc-includes/osclass/core/AdminBaseModel.php */
