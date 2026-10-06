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
use mindstellar\api\serializer\KeySerializer;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\PersonalKeys;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\Clock;

/**
 * `/account/keys`: personal API keys a user makes for their own scripts, when the site allows
 * them (PersonalKeys has the rules). Managing keys needs a signed-in access token, so a key
 * cannot make more keys.
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

    /**
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $this->allowed();
        $now = $this->clock->now();

        return Response::collection(array_map(
            fn (StoredKey $key): array => $this->serializer->personal($key, $now),
            $this->keys->list((int) $credential->userId())
        ));
    }

    /**
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $this->allowed();
        $userId = (int) $credential->userId();
        $user   = $this->users->find($userId);
        if ($user === null) {
            throw ProblemException::of('not_found', 'No such user.');
        }
        $input = $request->input();
        $issued = $this->keys->create(
            $user,
            (string) ($input['current_password'] ?? ''),
            (string) ($input['name'] ?? ''),
            (array) ($input['scopes'] ?? []),
            (string) ($input['expires_at'] ?? '')
        );
        $key = $this->keys->find($userId, $issued->id());
        if ($key === null) {
            throw ProblemException::of('server_error', 'The key was not stored.');
        }

        return Response::created($this->serializer->personal($key, $this->clock->now(), $issued->token()), $this->links->api('account/keys/' . $key->id()));
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $this->allowed();
        $key = $this->keys->find((int) $credential->userId(), (int) $args['id']);
        if ($key === null) {
            throw ProblemException::of('not_found', 'No such key.');
        }

        return Response::ok($this->serializer->personal($key, $this->clock->now()));
    }

    /**
     * @param array<string,string> $args
     */
    public function revoke(Request $request, Credential $credential, array $args): Response
    {
        $this->allowed();
        if (!$this->keys->revoke((int) $credential->userId(), (int) $args['id'])) {
            throw ProblemException::of('not_found', 'No such key.');
        }

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
