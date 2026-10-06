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

namespace mindstellar\api\auth;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\RouteSpec;
use mindstellar\apiaccess\Credential;

/**
 * Whether a credential may call a route: its auth level, then its scope.
 */
final class Authorizer
{
    /**
     * @throws ProblemException 403 when it may not
     */
    public function check(RouteSpec $route, Credential $credential): void
    {
        if ($route->auth() === RouteSpec::AUTH_NONE) {
            return;
        }
        if ($route->auth() === RouteSpec::AUTH_USER && !$credential->isUser()) {
            throw ProblemException::of('forbidden', 'This endpoint needs a user\'s token or key.');
        }
        $scope = $route->scope();
        if ($route->auth() === RouteSpec::AUTH_ADMIN && !$credential->isAdmin()) {
            throw ProblemException::of('forbidden', 'This endpoint needs an admin key.');
        }
        // A moderator holds only the moderator scopes, so an admin route naming no scope is full admins' only.
        if ($route->auth() === RouteSpec::AUTH_ADMIN && $scope === null && $credential->isModerator()) {
            throw ProblemException::of('forbidden', 'This endpoint needs a full admin\'s key.');
        }
        if ($scope !== null && !$credential->has($scope)) {
            throw ProblemException::from(Problem::insufficientScope($scope));
        }
    }
}
