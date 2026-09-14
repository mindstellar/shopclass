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

/**
 * Class AdminBaseModel
 */
class AdminBaseModel extends BaseModel
{
    /**
     * Boots the base controller and fires the init_admin_insecure hook.
     */
    public function __construct()
    {
        parent::__construct();

        osc_run_hook('init_admin_insecure');
    }

    /**
     * No-op: admin pages that need model work override this.
     *
     * @return void
     */
    public function doModel()
    {
    }

    /**
     * No-op: admin pages that render a template override this.
     *
     * @param string $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./oc-includes/osclass/core/AdminBaseModel.php */
