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

use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyCheck;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\auth\AuthStamp;
use mindstellar\security\SignedPayload;
use mindstellar\user\UserStore;

/**
 * Access tokens: `sca_<SignedPayload>`, signed with the install's key and stored nowhere. Every use
 * reads the user row and the sign-in family, so a deleted or suspended user, a changed password,
 * signing out or a revoked family ends the token at once.
 */
final class AccessTokens
{
    public const PREFIX = 'sca_';

    /** Seconds an access token lives. */
    public const TTL = 900;

    private const PURPOSE = 'api-access';

    /** @var \Closure(string): bool|null */
    private ?\Closure $familyLive;

    /**
     * @param int           $ttl        seconds a token lives
     * @param callable|null $familyLive (family) => whether the sign-in still has a live refresh token; by default checked in the same query as the user
     */
    public function __construct(
        private Scopes $scopes,
        private UserRows $users,
        private int $ttl = self::TTL,
        ?callable $familyLive = null
    ) {
        $this->familyLive = $familyLive === null ? null : \Closure::fromCallable($familyLive);
    }

    public static function looksLikeToken(string $token): bool
    {
        return str_starts_with($token, self::PREFIX);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * A token for a user with these scopes, from one sign-in.
     *
     * @param array<string,mixed> $user   the t_user row
     * @param string[]            $scopes
     */
    public function issue(array $user, array $scopes, string $family, ?int $ttl = null): string
    {
        return self::PREFIX . SignedPayload::pack(self::PURPOSE, [
            'sub'    => (int) $user['pk_i_id'],
            'kind'   => CredentialKind::USER,
            'scopes' => implode(' ', $scopes),
            'st'     => AuthStamp::fingerprint($user),
            'fam'    => $family,
        ], $ttl ?? $this->ttl);
    }

    /**
     * The credential a token stands for, or a refusal (forged, expired, a user who is gone, cannot
     * sign in or was signed out since, or a revoked sign-in) telling a forged token from a stale one.
     */
    public function check(string $token): KeyCheck
    {
        if (!self::looksLikeToken($token)) {
            return KeyCheck::refused(false);
        }
        $opened = SignedPayload::open(self::PURPOSE, substr($token, strlen(self::PREFIX)));
        $data   = $opened['data'] ?? null;
        if ($data === null || ($data['kind'] ?? null) !== CredentialKind::USER || !is_int($data['sub'] ?? null)
            || !is_string($data['fam'] ?? null) || !is_string($data['st'] ?? null)
        ) {
            return KeyCheck::refused(false);
        }
        if ($opened['expired']) {
            return KeyCheck::stale(true);
        }
        if ($this->familyLive === null) {
            [$user, $familyLive] = $this->users->findWithFamily($data['sub'], $data['fam']);
        } else {
            $user       = $this->users->find($data['sub']);
            $familyLive = $user !== null && ($this->familyLive)($data['fam']);
        }
        if ($user === null || !$familyLive || !UserStore::isLive($user)
            || !hash_equals(AuthStamp::fingerprint($user), $data['st'])
        ) {
            return KeyCheck::stale(false);
        }
        $id = (int) $user['pk_i_id'];

        return KeyCheck::accepted(new Credential(
            CredentialKind::USER,
            Scopes::normalize((string) ($data['scopes'] ?? ''), $this->scopes->allowedFor(CredentialKind::USER, KeyOwner::user($id))),
            $id,
            family: $data['fam']
        ));
    }
}
