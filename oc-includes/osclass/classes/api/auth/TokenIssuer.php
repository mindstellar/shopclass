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

use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\IssuedToken;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\utility\Clock;

/**
 * What a sign-in hands out: the scopes it may hold and the token answer (RFC 6749 §5.1).
 */
final class TokenIssuer
{
    public function __construct(private AccessTokens $access, private Scopes $scopes, private Clock $clock)
    {
    }

    /**
     * The scopes a sign-in gets: those asked for that a user may hold, or all of them.
     *
     * @param string $asked space separated; '' for every user scope
     *
     * @return string[]
     * @throws ProblemException 422 when none of the asked scopes can be granted
     */
    public function grantable(int $userId, string $asked): array
    {
        $allowed = $this->scopes->allowedFor(CredentialKind::USER, KeyOwner::user($userId));
        if (trim($asked) === '') {
            return $allowed;
        }
        $scopes = Scopes::normalize($asked, $allowed);
        if ($scopes === []) {
            throw ProblemException::field('/scope', 'enum', 'names no scope a user can hold');
        }

        return $scopes;
    }

    /**
     * A new access token for one sign-in, as the token answer. The refresh token is
     * only there when one was handed out.
     *
     * @param array<string,mixed> $user   the t_user row
     * @param string[]            $scopes
     *
     * @return Response
     */
    public function answer(array $user, array $scopes, string $family, ?IssuedToken $grant = null): Response
    {
        $body = [
            'access_token' => $this->access->issue($user, $scopes, $family),
            'token_type'   => 'Bearer',
            'expires_in'   => $this->access->ttl(),
            'scope'        => implode(' ', $scopes),
        ];
        if ($grant !== null) {
            $body['refresh_token']      = $grant->token();
            $body['refresh_expires_in'] = max(0, (int) $grant->expiresAt() - $this->clock->now());
        }

        // The members at the top level and never cached, as RFC 6749 §5.1 has it.
        return (new Response(200, $body))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
