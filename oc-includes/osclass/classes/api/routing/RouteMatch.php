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

use mindstellar\api\RouteSpec;

/**
 * A matched route and the values its path placeholders took.
 */
final class RouteMatch
{
    /**
     * @param array<string,string> $args placeholder name => value
     */
    public function __construct(private RouteSpec $route, private array $args)
    {
    }

    public function route(): RouteSpec
    {
        return $this->route;
    }

    /**
     * @return array<string,string>
     */
    public function args(): array
    {
        return $this->args;
    }
}
