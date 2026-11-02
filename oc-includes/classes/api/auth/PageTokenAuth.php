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
use mindstellar\apikey\Credential;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\PageTokens;
use mindstellar\apikey\Scopes;
use mindstellar\auth\AuthStamp;
use mindstellar\security\RememberMe;
use mindstellar\user\UserStore;

/**
 * The same-site session mode: theme JavaScript on the site's own pages calls the API with a page
 * token in X-Shopclass-Token. A guest token reads public data only. A user's token is refused unless
 * the sign-in cookie is valid and names that user; its credential never holds account:write or admin.
 * An admin's token is refused unless the browser's admin panel session names that admin. Every page
 * token is refused from another site.
 */
final class PageTokenAuth
{
    /** @var \Closure(array<string,mixed>, string): bool */
    private \Closure $banned;

    /** @var \Closure(): (array<string,mixed>|null) */
    private \Closure $admin;

    /**
     * @param callable|null $banned (user row, client address) => whether a ban rule matches; osc_is_banned() by default
     * @param callable|null $admin  () => the t_admin row the browser's admin session names; none by default
     */
    public function __construct(
        private PageTokens $tokens,
        private UserRows $users,
        private Scopes $scopes,
        private SiteOrigin $origin,
        ?callable $banned = null,
        ?callable $admin = null
    ) {
        $this->banned = \Closure::fromCallable($banned ?? [self::class, 'bannedOnSite']);
        $this->admin  = \Closure::fromCallable($admin ?? static fn (): ?array => null);
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
        $token = trim($request->header(PageTokens::HEADER));
        $guest = $this->tokens->checkGuest($token);
        if ($guest === PageTokens::EXPIRED) {
            throw self::expired('The page token has expired. Reload the page.');
        }
        if ($guest === PageTokens::VALID) {
            if (($this->banned)(['s_email' => ''], $request->ip())) {
                throw ProblemException::of('banned', 'This address may not use the API.');
            }

            return new Credential(CredentialKind::GUEST, Scopes::PUBLIC);
        }
        if ($this->tokens->isAdminToken($token)) {
            return $this->admin($token);
        }
        $user = $this->signedInUser($request);
        if ($user === null) {
            throw ProblemException::from(self::refused('Sign in on the site, then reload the page.'));
        }

        $state = $this->tokens->check($token, $user);
        if ($state === PageTokens::EXPIRED) {
            throw self::expired('The page token has expired. Get a new one from GET /auth/session before it runs out, or reload the page.');
        }
        if ($state !== PageTokens::VALID) {
            throw ProblemException::from(self::refused('The page token does not belong to this sign-in. Reload the page.'));
        }
        if (($this->banned)($user, $request->ip())) {
            throw ProblemException::of('banned', 'This account or address may not use the API.');
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
        if ($user === null || !UserStore::isLive($user)
            || !RememberMe::verify('web', $cookie->rawUserId(), $cookie->secret(), (string) ($user['s_password'] ?? ''), AuthStamp::of($user))
        ) {
            return null;
        }

        return $user;
    }

    /**
     * @throws ProblemException 401 session_required with no admin session or another admin's token,
     *                    401 token_expired
     */
    private function admin(string $token): Credential
    {
        $admin = ($this->admin)();
        if ($admin === null) {
            throw ProblemException::from(self::refused('Sign in to the admin panel, then reload the page.'));
        }
        $state = $this->tokens->checkAdmin($token, $admin);
        if ($state === PageTokens::EXPIRED) {
            throw self::expired('The page token has expired. Reload the page.');
        }
        if ($state !== PageTokens::VALID) {
            throw ProblemException::from(self::refused('The page token does not belong to this sign-in. Reload the page.'));
        }
        $id        = (int) $admin['pk_i_id'];
        $moderator = !empty($admin['b_moderator']);

        return new Credential(
            CredentialKind::SESSION,
            $this->scopes->allowedFor(CredentialKind::SESSION, KeyOwner::admin($id, $moderator)),
            null,
            $id,
            moderator: $moderator
        );
    }

    private static function expired(string $detail): ProblemException
    {
        return ProblemException::from(
            Problem::make('token_expired', $detail)
                ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token", error_description="The page token expired"')
        );
    }

    private static function refused(string $detail): Response
    {
        return Problem::make('session_required', $detail)->withHeader('WWW-Authenticate', 'Bearer');
    }

    /**
     * Whether a ban rule matches the user's e-mail or the address.
     *
     * @param array<string,mixed> $user
     */
    public static function bannedOnSite(array $user, string $ip): bool
    {
        return osc_is_banned((string) ($user['s_email'] ?? ''), $ip) !== 0;
    }
}
