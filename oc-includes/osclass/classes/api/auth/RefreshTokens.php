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
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\IssuedToken;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\SignInStore;
use mindstellar\apiaccess\StoredKey;
use mindstellar\auth\AuthStamp;
use mindstellar\user\UserStore;
use mindstellar\utility\Clock;

/**
 * Refresh tokens: `scr_<token id>.<secret>`, one t_api_credential row each, the secret kept only as
 * a sha256 hash. Each use swaps the token for a new one in the same family, and a swapped token
 * coming back revokes it except within RefreshRetries::WINDOW.
 */
final class RefreshTokens
{
    public const PREFIX = 'scr_';

    /** The default of the refresh-token setting: days a token lives unused, each use starting the count again. */
    public const TTL_DAYS = ApiSettings::DEFAULTS['api_refresh_days'];

    private const REFUSED = 'refused';
    private const REUSED  = 'reused';

    /**
     * @param int $ttlDays days a token lives unused
     */
    public function __construct(
        private SignInStore $store,
        private Scopes $scopes,
        private UserRows $users,
        private int $ttlDays,
        private Clock $clock,
        private ?RefreshRetries $retries = null
    ) {
    }

    /**
     * Start a sign-in: a new family and its first token.
     *
     * @param array<string,mixed> $user   the t_user row
     * @param string[]            $scopes already cut to what the user may hold
     */
    public function start(array $user, array $scopes, string $label, string $ip): IssuedToken
    {
        return $this->issue((int) $user['pk_i_id'], AuthStamp::of($user), ApiKeys::newId(), $scopes, mb_substr(trim($label), 0, 100), $ip);
    }

    /**
     * Swap a refresh token for the next one in its family.
     *
     * @return IssuedToken the new token
     * @throws ProblemException 400 `invalid_grant`, for a token already swapped (its family is then
     *                    revoked) or any other refusal; thrown after the commit, so the
     *                    revokes a refusal made are kept
     */
    public function rotate(string $token, string $ip): IssuedToken
    {
        $parts  = ApiKeys::parse($token, [self::PREFIX]);
        $secret = $parts[2] ?? '';
        $found  = $parts === null ? null : $this->store->findByTokenId($parts[1]);
        if ($found === null || $found->kind() !== CredentialKind::REFRESH || $found->family() === null
            || !ApiKeys::secretMatches($found, $secret)
        ) {
            throw self::refused();
        }
        $family = $found->family();

        // The family's rows are locked, so rotations and revokes run one at a time. Refusals are
        // thrown after the commit, so the revokes they made are kept.
        $outcome = $this->store->atomically(function () use ($found, $family, $ip, $secret) {
            $this->store->lockFamily($family);
            $row = $this->store->find($found->id());
            if ($row === null) {
                return self::REFUSED;
            }
            $userId = $row->owner()?->userId();
            $user   = $userId === null ? null : $this->users->find($userId);
            if ($row->revokedAt() !== null) {
                $retry = $this->retries?->recall($family, $row->id(), $secret);
                $next  = $retry === null ? null : $this->store->find((int) $retry->id());
                if ($next !== null && $next->revokedAt() === null && $next->isUsableAt($this->clock->now()) && $user !== null && UserStore::isLive($user)) {
                    return $retry;
                }

                // A family with live tokens left means this one was swapped and is back.
                $this->retries?->forget($family);

                return $this->store->revokeFamily($family) > 0 ? self::REUSED : self::REFUSED;
            }
            if ($user === null || !$row->isUsableAt($this->clock->now()) || !UserStore::isLive($user)) {
                $this->retries?->forget($family);
                $this->store->revokeFamily($family);

                return self::REFUSED;
            }
            if (!$this->store->revoke($row->id())) {
                $this->store->revokeFamily($family);

                return self::REUSED;
            }
            $scopes = Scopes::normalize($row->scopes(), $this->scopes->allowedFor(CredentialKind::USER, KeyOwner::user((int) $userId)));

            $issued = $this->issue((int) $userId, AuthStamp::of($user), $family, $scopes, $row->name(), $ip);
            $this->retries?->remember($family, $row->id(), $secret, $issued, $this->clock->now());

            return $issued;
        });
        if ($outcome instanceof IssuedToken) {
            return $outcome;
        }

        throw $outcome === self::REUSED ? self::reused() : self::refused();
    }

    /**
     * Sign out: one sign-in of a user, or every one of them but $except. A sign-in of
     * another user is left alone.
     *
     * @param string|null $family the sign-in to end; null for all of the user's
     * @param string|null $except with no $family, the sign-in to keep
     *
     * @return int tokens revoked
     */
    public function end(int $userId, ?string $family = null, ?string $except = null): int
    {
        if ($family === null) {
            return $this->store->revokeRefreshFor($userId, $except);
        }
        foreach ($this->store->listBy(CredentialKind::REFRESH, $userId, null, true) as $key) {
            if ($key->family() === $family) {
                $this->retries?->forget($family);

                return $this->store->revokeFamily($family);
            }
        }

        return 0;
    }

    /**
     * @param string[] $scopes
     */
    private function issue(int $userId, int $stamp, string $family, array $scopes, string $label, string $ip): IssuedToken
    {
        $now     = $this->clock->now();
        $expires = $now + $this->ttlDays * 86400;
        [$tokenId, $secret, $hash] = ApiKeys::mint();
        $id = $this->store->insert(new StoredKey(
            id: 0,
            kind: CredentialKind::REFRESH,
            tokenId: $tokenId,
            secretHash: $hash,
            name: $label,
            scopes: $scopes,
            owner: KeyOwner::user($userId, $stamp),
            expiresAt: $expires,
            family: $family,
            createdAt: $now,
            authStamp: $stamp
        ));
        $this->store->touch($id, substr($ip, 0, 45), $now);

        return new IssuedToken(self::PREFIX . $tokenId . '.' . $secret, $expires, $scopes, id: $id, userId: $userId, family: $family);
    }

    private static function refused(): ProblemException
    {
        return ProblemException::from(Problem::make('invalid_grant', 'The refresh token is not valid. Sign in again.', ['error' => 'invalid_grant']));
    }

    private static function reused(): ProblemException
    {
        return ProblemException::from(Problem::make('invalid_grant', 'This refresh token was already used, so its sign-in has been ended. Sign in again.', ['error' => 'invalid_grant']));
    }
}
