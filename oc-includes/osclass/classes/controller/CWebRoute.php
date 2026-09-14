<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class CWebRoute
 *
 * Serves routes registered with osc_add_route_hook(): runs the hook named after the route.
 */
class CWebRoute extends BaseModel
{
    /**
     * Boots the base controller, so `init` has fired before the route's hook runs.
     */
    public function __construct()
    {
        parent::__construct();
        // Route handlers stream their own response (JSON, redirects), so no debug timing comment.
        $this->ajax = true;
    }

    /**
     * Runs the hook of a registered hook route, or 404s. Any other name is refused, so the
     * request cannot fire an arbitrary hook such as cron_hourly.
     *
     * @return void
     */
    public function doModel()
    {
        $id = Params::getParamString('route');
        if (!self::isHookRoute(Rewrite::newInstance()->getRoutes(), $id)) {
            $this->do404();

            return;
        }

        osc_run_hook($id);
    }

    /**
     * Whether $id names a route registered with osc_add_route_hook().
     *
     * @param array<string,array<string,mixed>> $routes Rewrite::getRoutes()
     * @param string                            $id
     *
     * @return bool
     */
    public static function isHookRoute(array $routes, string $id): bool
    {
        return $id !== '' && !empty($routes[$id]['routeController']);
    }

    /**
     * Unused: a hook route renders its own response.
     *
     * @param string $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./CWebRoute.php */
