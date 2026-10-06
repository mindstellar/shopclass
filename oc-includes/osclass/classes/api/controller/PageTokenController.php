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
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\PageTokens;

/**
 * `GET /auth/session`: a fresh page token for a page open longer than its token lives. Only a
 * same-site session call (cookie plus a page token that still works) gets one.
 */
final class PageTokenController
{
    private UserRows $users;
    private PageTokens $tokens;

    public function __construct(private ApiServices $api)
    {
        $this->users = $api->users();
        $this->tokens = $api->pageTokens();
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $user = $credential->isSession() ? $this->users->find((int) $credential->userId()) : null;
        if ($user === null) {
            throw ProblemException::of('forbidden', 'Only a same-site session call can renew its page token.');
        }
        $token = $this->tokens->issue($user);

        return Response::ok([
            'token'      => $token->token(),
            'header'     => PageTokens::HEADER,
            'expires_at' => Format::timestamp($token->expiresAt()),
        ]);
    }
}
