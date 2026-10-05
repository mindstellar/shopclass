<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\routing;

/**
 * Core's v1 route table, 'METHOD path' => spec: the public routes, then the admin ones.
 */
final class RouteTable
{
    private function __construct()
    {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function core(): array
    {
        return PublicRoutes::all() + AdminRoutes::all();
    }
}
