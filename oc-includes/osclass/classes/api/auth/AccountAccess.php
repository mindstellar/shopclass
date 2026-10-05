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

use mindstellar\api\ApiSettings;
use mindstellar\api\serializer\AccessEntrySerializer;
use mindstellar\validation\InvalidException;
use mindstellar\validation\RefusedException;

/**
 * What the account's "API access" page shows and does: the user's sign-ins (refresh
 * families) and personal keys, ending one, and making a key when the site allows it. The
 * rules are the API's own services.
 */
final class AccountAccess
{
    public function __construct(private ApiSettings $settings, private AccessEntries $sessions, private PersonalKeys $keys)
    {
    }

    /**
     * Whether the page has anything to show: the API is on, and the user may make keys or
     * already has a sign-in or key.
     */
    public function relevant(int $userId): bool
    {
        return $this->settings->enabled() && ($this->keys->allowed() || $this->sessions->hasAny($userId));
    }

    public function userKeys(): bool
    {
        return $this->keys->allowed();
    }

    /**
     * The live sign-ins and keys, newest first, as `GET /account/sessions` lists them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sessions(int $userId): array
    {
        $serializer = new AccessEntrySerializer();

        return array_map(static fn (AccessEntry $session): array => $serializer->one($session, Credential::anonymous()), $this->sessions->list($userId));
    }

    /**
     * End one sign-in or revoke one key of this user.
     *
     * @return bool false when the user has no such session
     */
    public function end(int $userId, string $session): bool
    {
        return $this->sessions->end($userId, $session);
    }

    /**
     * The scopes a personal key may hold, with their descriptions.
     *
     * @return array<string,string>
     */
    public function keyScopes(int $userId): array
    {
        return $this->keys->grantable($userId);
    }

    /**
     * Make a personal key after checking the password again.
     *
     * @param array<string,mixed> $user    the t_user row
     * @param string[]            $scopes
     * @param string              $expires the last day it works, Y-m-d
     *
     * @return string the key's token, shown once
     * @throws \InvalidArgumentException with the reason to show
     */
    public function createKey(array $user, string $password, string $name, array $scopes, string $expires): string
    {
        try {
            return $this->keys->create($user, $password, $name, $scopes, $expires)->token();
        } catch (InvalidException $e) {
            throw $e->pointer() === '/current_password' ? new RefusedException(_m('The password is not right.')) : $e;
        }
    }
}
