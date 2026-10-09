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

namespace mindstellar\apikey;

use mindstellar\auth\Reauth;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\utility\Clock;

/**
 * Personal API keys a user makes for their own scripts, when the site allows them. A key
 * holds some of the user's scopes, never `account:write`, must expire within a year, and is
 * revoked when the user is signed out everywhere, which a password change does. Making one
 * asks for the password again. The rules a key is checked against are the admin screen's
 * (ApiKeyService).
 */
final class PersonalKeys
{
    /** The longest a personal key may live, in days. */
    public const MAX_DAYS = 366;

    public function __construct(
        private ApiKeyService $keyService,
        private SignInStore $store,
        private bool $allowed,
        private Clock $clock
    ) {
    }

    /**
     * Whether the site lets users make keys.
     */
    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * The user's keys, live or not, newest first.
     *
     * @return StoredKey[]
     */
    public function list(int $userId): array
    {
        return $this->store->listBy(CredentialKind::KEY, $userId);
    }

    /**
     * One of the user's keys, or null.
     */
    public function find(int $userId, int $id): ?StoredKey
    {
        $key = $this->store->find($id);

        return $key !== null && $key->kind() === CredentialKind::KEY && $key->owner()?->userId() === $userId ? $key : null;
    }

    /**
     * The scopes a personal key may hold, with their descriptions.
     *
     * @return array<string,string>
     */
    public function grantable(int $userId): array
    {
        return $this->keyService->grantable(CredentialKind::KEY, KeyOwner::user($userId));
    }

    /**
     * Make a key after checking the password again.
     *
     * @param array<string,mixed> $user    the t_user row
     * @param string[]            $scopes
     * @param string              $expires the last day it works, Y-m-d
     *
     * @throws ForbiddenException when the site does not allow keys
     * @throws BlockedException   while too many wrong passwords came in a row
     * @throws RefusedException   for a wrong password, or for what was asked
     */
    public function create(array $user, string $password, string $name, array $scopes, string $expires): IssuedToken
    {
        if (!$this->allowed) {
            throw new ForbiddenException(_m('This site does not let users make API keys.'), ForbiddenException::DISABLED);
        }
        Reauth::check($user, $password);
        try {
            $at = $this->keyService->expiry($expires);
        } catch (RefusedException $e) {
            throw new InvalidException('/expires_at', 'rejected', $e->getMessage());
        }
        if ($at === null || $at > $this->clock->now() + self::MAX_DAYS * 86400) {
            throw new InvalidException('/expires_at', 'rejected', _m('Choose an expiry date within a year.'));
        }
        $owner = KeyOwner::user((int) $user['pk_i_id']);
        return $this->keyService->create($owner, $name, CredentialKind::KEY, array_map('strval', $scopes), $expires);
    }

    /**
     * Revoke one of the user's keys; revoking a revoked key is a no-op.
     *
     * @return bool false when the user has no such key
     */
    public function revoke(int $userId, int $id): bool
    {
        $key = $this->find($userId, $id);
        if ($key === null) {
            return false;
        }
        if ($key->revokedAt() === null) {
            $this->store->revoke($key->id());
        }

        return true;
    }
}
