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

use mindstellar\auth\AuthStamp;
use mindstellar\security\SignedPayload;

/**
 * Access tokens: `sca_<SignedPayload>`, signed with the install's key and stored nowhere.
 *
 * The payload names the user, the scopes, the sign-in (refresh family) it came from and the
 * fingerprint of the user's sign-out stamp. Every use reads the user row, so a deleted,
 * suspended or unconfirmed user, a changed password or signing out of all devices ends the
 * token at once.
 */
final class AccessTokens
{
    public const PREFIX = 'sca_';

    /** Seconds an access token lives; it cannot be revoked early, so it stays short. */
    public const TTL = 900;

    private const PURPOSE = 'api-access';

    /**
     * @param int $ttl seconds a token lives
     */
    public function __construct(private Scopes $scopes, private UserRows $users, private int $ttl = self::TTL)
    {
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
     * The credential a token stands for, or null for any refusal: forged, expired, or a user
     * who is gone, cannot sign in or was signed out everywhere since.
     */
    public function verify(string $token): ?Credential
    {
        return $this->check($token)->credential();
    }

    /**
     * verify(), telling a forged token from a genuine one that expired or went stale.
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
        $user = $this->users->find($data['sub']);
        if ($user === null || !UserRows::canSignIn($user)
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
