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

use mindstellar\api\http\SiteOrigin;
use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\PageTokens;
use mindstellar\apiaccess\Scopes;
use mindstellar\auth\AuthStamp;
use mindstellar\security\RememberMe;

/**
 * The same-site session mode: theme JavaScript on the site's own pages calls the API as the
 * signed-in web user, with no key or token of its own.
 *
 * It applies only to a request with no API token that carries a page token in the
 * X-Shopclass-Token header. The page token is the CSRF guard: a page on another site can
 * neither read it nor, without a CORS grant the API never gives for it, send the header.
 * Then every guard must pass, or the call is refused rather than run as anonymous:
 * - the request comes from a page of this site (SiteOrigin),
 * - the signed sign-in cookie is valid for a user who may sign in and is not banned,
 * - the page token was made for that user and their current password, and has not expired.
 * The credential holds the user scopes a key may hold: never account:write, never admin.
 * A cookie with no page token stays anonymous.
 */
final class PageTokenAuth
{
    /** @var \Closure(array<string,mixed>, string): bool */
    private \Closure $banned;

    /**
     * @param callable|null $banned (user row, client address) => whether a ban rule matches; osc_is_banned() by default
     */
    public function __construct(
        private PageTokens $tokens,
        private UserRows $users,
        private Scopes $scopes,
        private SiteOrigin $origin,
        ?callable $banned = null
    ) {
        $this->banned = \Closure::fromCallable($banned ?? [self::class, 'bannedOnSite']);
    }

    /**
     * Whether a request asks for the session mode: a page token and no API token.
     */
    public static function applies(Request $request): bool
    {
        return Authenticator::token($request) === '' && trim($request->header(PageTokens::HEADER)) !== '';
    }

    /**
     * @return Credential|null null when the request does not ask for the session mode
     * @throws ProblemException 403 cross_origin from another site, 401 session_required or
     *                    token_expired for a sign-in or page token that is not good, 403
     *                    forbidden for a banned user
     */
    public function authenticate(Request $request): ?Credential
    {
        if (!self::applies($request)) {
            return null;
        }
        if (!$this->origin->matches($request)) {
            throw ProblemException::of('cross_origin', 'A page token is accepted only from this site\'s own pages.');
        }
        $user = $this->signedInUser($request);
        if ($user === null) {
            throw ProblemException::from(self::refused('Sign in on the site, then reload the page.'));
        }

        $state = $this->tokens->check(trim($request->header(PageTokens::HEADER)), $user);
        if ($state === PageTokens::EXPIRED) {
            throw ProblemException::from(
                Problem::make('token_expired', 'The page token has expired. Get a new one from GET /auth/session before it runs out, or reload the page.')
                    ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token", error_description="The page token expired"')
            );
        }
        if ($state !== PageTokens::VALID) {
            throw ProblemException::from(self::refused('The page token does not belong to this sign-in. Reload the page.'));
        }
        if (($this->banned)($user, $request->ip())) {
            throw ProblemException::of('forbidden', 'This account or address may not use the API.');
        }
        $id = (int) $user['pk_i_id'];

        return new Credential(CredentialKind::SESSION, $this->scopes->allowedFor(CredentialKind::SESSION, KeyOwner::user($id)), $id);
    }

    /**
     * The user the signed sign-in cookie names, when the cookie is valid and they may sign in.
     *
     * @return array<string,mixed>|null
     */
    private function signedInUser(Request $request): ?array
    {
        $cookie = $request->signIn();
        if ($cookie === null) {
            return null;
        }
        $user = $this->users->find($cookie->userId());
        if ($user === null || !UserRows::canSignIn($user)
            || !RememberMe::verify('web', $cookie->rawUserId(), $cookie->secret(), (string) ($user['s_password'] ?? ''), AuthStamp::of($user))
        ) {
            return null;
        }

        return $user;
    }

    private static function refused(string $detail): Response
    {
        return Problem::make('session_required', $detail)->withHeader('WWW-Authenticate', 'Bearer');
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function bannedOnSite(array $user, string $ip): bool
    {
        return function_exists('osc_is_banned') && osc_is_banned((string) ($user['s_email'] ?? ''), $ip) !== 0;
    }
}
