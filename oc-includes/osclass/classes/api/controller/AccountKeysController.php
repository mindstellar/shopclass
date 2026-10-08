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
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\KeySerializer;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\PersonalKeys;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\Clock;

/**
 * `/account/keys`: personal API keys a user makes for their own scripts, when the site allows
 * them (PersonalKeys has the rules). Managing keys needs a signed-in access token, so a key
 * cannot make more keys, and a new key cannot hold a scope the token lacks.
 */
final class AccountKeysController
{
    private KeySerializer $serializer;

    private PersonalKeys $keys;
    private UserRows $users;
    private Links $links;
    private Clock $clock;

    public function __construct(private ApiServices $api)
    {
        $this->keys = $api->personalKeys();
        $this->users = $api->users();
        $this->links = $api->links();
        $this->clock = $api->clock();
        $this->serializer = new KeySerializer();
    }

    public function index(ApiCall $call): Response
    {
        $this->allowed();
        $now = $this->clock->now();

        return Page::whole(array_map(
            fn (StoredKey $key): array => $this->serializer->personal($key, $now),
            $this->keys->list($call->userId())
        ), $this->links, $call);
    }

    public function create(ApiCall $call): Response
    {
        $this->allowed();
        $userId = $call->userId();
        $user   = ProblemException::found($this->users->find($userId), 'user');
        $input  = $call->input();
        $scopes = (array) ($input['scopes'] ?? []);
        $call->credential()->checkGrant($scopes);
        $issued = $this->keys->create(
            $user,
            (string) ($input['current_password'] ?? ''),
            (string) ($input['name'] ?? ''),
            $scopes,
            (string) ($input['expires_at'] ?? '')
        );
        $key = $this->keys->find($userId, $issued->id());
        if ($key === null) {
            throw ProblemException::of('server_error', 'The key was not stored.');
        }

        return $this->api->created($call, $this->serializer->personal($key, $this->clock->now(), $issued->token()), 'account/keys/' . $key->id());
    }

    public function show(ApiCall $call): Response
    {
        $this->allowed();
        $key = ProblemException::found($this->keys->find($call->userId(), $call->intArg()), 'key');

        return Response::ok($this->serializer->personal($key, $this->clock->now()));
    }

    public function revoke(ApiCall $call): Response
    {
        $this->allowed();
        $userId = $call->userId();
        $key    = ProblemException::found($this->keys->find($userId, $call->intArg()), 'key');
        if ($key->revokedAt() !== null) {
            throw ProblemException::of('conflict', 'That key is already revoked.');
        }
        $this->keys->revoke($userId, $key->id());

        return Response::noContent();
    }

    /**
     * @throws ProblemException 403 when the site does not let users make keys
     */
    private function allowed(): void
    {
        if (!$this->keys->allowed()) {
            throw ProblemException::of('feature_disabled', _m('This site does not let users make API keys.'));
        }
    }
}
