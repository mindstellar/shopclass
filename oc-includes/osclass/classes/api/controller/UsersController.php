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

namespace mindstellar\api\controller;

use mindstellar\api\ApiServices;
use mindstellar\api\auth\Credential;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\user\UserQuery;

/**
 * `GET /users/{id}` and `GET /users/{id}/listings`. A disabled or unconfirmed account is 404
 * except to the user and to admin keys with `admin:users`; so is every user when the site
 * has no accounts.
 */
final class UsersController
{
    private ListingSearch $search;

    public function __construct(private ApiServices $api)
    {
        $this->search = $api->listingSearch();
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $context = $this->api->context($request, $credential, 'user', UserSerializer::MEMBERS);
        $id      = (int) $args['id'];
        $user    = $this->api->facts()->usersEnabled() ? \User::getInstance()->findByPrimaryKey($id) : null;
        if (!is_array($user) || $user === [] || !self::visible($user, $credential)) {
            throw ProblemException::of('not_found', 'No such user.');
        }

        return Response::ok((new UserSerializer($this->api->links(), $this->api->extensions()))->one($user, $context));
    }

    /**
     * @param array<string,string> $args
     */
    public function listings(Request $request, Credential $credential, array $args): Response
    {
        $id   = (int) $args['id'];
        $user = null;
        if ($this->api->facts()->usersEnabled()) {
            $user = (new UserQuery())->statusRow($id);
        }
        if ($user === null || !self::visible($user, $credential)) {
            throw ProblemException::of('not_found', 'No such user.');
        }

        return $this->search->run($request, $credential, $id, 'users/' . $id . '/listings');
    }

    /**
     * @param array<string,mixed> $user a t_user row with pk_i_id, b_enabled and b_active
     */
    private static function visible(array $user, Credential $credential): bool
    {
        return (Format::bool($user['b_enabled'] ?? 0) && Format::bool($user['b_active'] ?? 0))
            || ViewContext::viewOf($credential, Format::int($user['pk_i_id'] ?? 0), ViewContext::USERS_SCOPE) !== ViewContext::PUBLIC;
    }
}
