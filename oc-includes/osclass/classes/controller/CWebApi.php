<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class CWebApi
 *
 * Serves the REST API under /api/ (or index.php?page=api&path=...).
 */
class CWebApi extends BaseModel
{
    /**
     * Fires `init`, so plugins have registered their routes. The browser-page setup of the base
     * controller (canonical host redirect, subdomain lookups) is left out: an API call has no page.
     */
    public function __construct()
    {
        $this->ajax = true;
        $this->setParams();
        $this->time = microtime(true);
        WebThemes::newInstance();
        osc_run_hook('init');
    }

    /**
     * Answers the request with JSON and stops.
     *
     * @return void
     */
    public function doModel()
    {
        \mindstellar\api\Kernel::serve();
    }

    /**
     * Unused: the API renders no template.
     *
     * @param string $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./CWebApi.php */
