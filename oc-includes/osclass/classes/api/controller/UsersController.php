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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\apiaccess\Credential;
use mindstellar\user\UserQuery;
use mindstellar\user\UserStore;

/**
 * `GET /users/{id}` and `GET /users/{id}/listings`. A disabled or unconfirmed account is 404
 * except to the user and to admin keys with `admin:users`; so is every user when the site
 * has no accounts.
 */
final class UsersController
{
    public function __construct(private ApiServices $api)
    {
    }

    public function show(ApiCall $call): Response
    {
        $credential = $call->credential();

        $context = $this->api->context($call->request(), $credential, 'user', UserSerializer::MEMBERS);
        $id      = $call->intArg();
        $user    = self::visible($this->api->facts()->usersEnabled() ? (new UserQuery())->find($id) : null, $credential);

        return Response::ok((new UserSerializer($this->api->links(), $this->api->extensions()))->one($user, $context));
    }

    public function listings(ApiCall $call): Response
    {
        $credential = $call->credential();

        $id = $call->intArg();
        self::visible($this->api->facts()->usersEnabled() ? (new UserQuery())->statusRow($id) : null, $credential);

        return $this->api->listingSearch()->run($call->request(), $credential, $id, 'users/' . $id . '/listings');
    }

    /**
     * The user when the caller may see them: a live account, or one the caller may view in full.
     *
     * @param array<string,mixed>|null $user a t_user row with pk_i_id, b_enabled and b_active
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private static function visible(?array $user, Credential $credential): array
    {
        $seen = $user !== null && (UserStore::isLive($user)
            || ViewContext::viewOf($credential, Format::int($user['pk_i_id'] ?? 0), ViewContext::USERS_SCOPE) !== ViewContext::PUBLIC);

        return ProblemException::found($seen ? $user : null, 'user');
    }
}
