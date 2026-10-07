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
use mindstellar\api\Request;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\KeyCheck;

/**
 * Turns a request's token into a Credential: an API key (`sck_`, `scp_`) or a signed-in
 * user's access token (`sca_`). Only an `Authorization: Bearer` header is a token (or, for a
 * public key on a GET, `?api_key=`). With no token, a page token in X-Shopclass-Token asks
 * for the same-site session mode (PageTokenAuth); a cookie alone never authenticates a call.
 * A user's key or access token is refused while a ban rule matches the user or the address.
 */
final class Authenticator
{
    /** @var (\Closure(int, string): bool)|null */
    private ?\Closure $banned;

    public function __construct(
        private ApiKeys $keys,
        private FailureCounter $failures,
        private AccessTokens $tokens,
        private ?PageTokenAuth $session = null,
        ?callable $banned = null
    ) {
        $this->banned = $banned === null ? null : \Closure::fromCallable($banned);
    }

    /**
     * The token a request carries, or '' when it carries none. Another scheme in the header
     * (Basic auth on a staging site, say) is not a token.
     */
    public static function token(Request $request): string
    {
        if (preg_match('/^Bearer\s+(\S+)$/iD', $request->authorization(), $m) === 1) {
            return $m[1];
        }
        $key = $request->query()['api_key'] ?? null;
        if ($request->isRead() && is_string($key) && str_starts_with($key, ApiKeys::PUBLIC_PREFIX)) {
            return $key;
        }

        return '';
    }

    /**
     * @return Credential|null null when the request carries no token and no page token
     * @throws ProblemException 429 when this key is shut out from this address or a token fails from
     *                    an address that failed too often, 401 for a refused token, 403 for a
     *                    banned user, 401 or 403 for a refused session call
     */
    public function authenticate(Request $request): ?Credential
    {
        $token = self::token($request);
        if ($token === '') {
            return $this->session?->authenticate($request);
        }
        $tokenId = ApiKeys::tokenId($token);
        if ($this->failures->keyBlocked($request->ip(), $tokenId)) {
            throw self::tooManyFailures();
        }

        $check = match (true) {
            ApiKeys::looksLikeKey($token) => $this->keys->check($token, $request->ip()),
            AccessTokens::looksLikeToken($token) => $this->tokens->check($token),
            default => KeyCheck::refused(false),
        };
        $credential = $check->credential();
        if ($credential === null && $check->genuine()) {
            // Signed by this site, so not a guess: not counted.
            throw ProblemException::from($check->expired()
                ? Problem::make('token_expired', 'The access token has expired. Get a new one with the refresh token.')
                    ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token", error_description="The access token expired"')
                : Problem::unauthorized(true));
        }
        if ($credential === null) {
            $this->failures->record($request->ip(), $tokenId, $check->known());

            throw $this->failures->addressBlocked($request->ip()) ? self::tooManyFailures() : ProblemException::from(Problem::unauthorized(true));
        }
        $userId = $credential->userId();
        if ($userId !== null && $this->banned !== null && ($this->banned)($userId, $request->ip())) {
            throw ProblemException::of('banned', 'This account or address may not use the API.');
        }

        return $credential;
    }

    private static function tooManyFailures(): ProblemException
    {
        return ProblemException::from(
            Problem::make('too_many_failures', 'Too many failed attempts from this address. Try again later.')
                ->withHeader('Retry-After', (string) FailureCounter::WINDOW)
        );
    }
}
