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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\auth\RefreshTokens;
use mindstellar\api\auth\TokenIssuer;
use mindstellar\api\auth\UserRows;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\auth\SignIn;

/**
 * `POST /auth/token` and `POST /auth/sign-out`: a user signs in with their password and gets an
 * access token and a refresh token, swaps the refresh token for new ones, or signs out.
 *
 * Sign-in is the web login's decision (SignIn): the same limit and counter, the same
 * password check and timing for an unknown account, the same ban, confirmation and suspension
 * answers, and the same hooks. There is no captcha, so a blocked account or address answers
 * 429. Admins never sign in here; they use keys.
 *
 * The token endpoint speaks OAuth 2 (RFC 6749): it takes JSON or a form, answers the token
 * members at the top level with `Cache-Control: no-store`, and a refused grant is a 400 with
 * the OAuth `error` (`invalid_grant`, `invalid_request`, `invalid_scope` or
 * `unsupported_grant_type`); Kernel shapes the refusals through OAuthError.
 */
final class AuthController
{
    private UserRows $users;
    private TokenIssuer $tokens;
    private RefreshTokens $refresh;

    public function __construct(private ApiServices $api)
    {
        $this->users = $api->users();
        $this->tokens = $api->tokenIssuer();
        $this->refresh = $api->refreshTokens();
    }

    public function token(ApiCall $call): Response
    {
        $request = $call->request();

        $input = $request->input();
        if (!osc_users_enabled()) {
            throw ProblemException::of('feature_disabled', 'This site has no user accounts.');
        }

        $grant = $input['grant_type'] ?? null;
        if ($grant === 'password' && !$this->api->settings()->passwordGrant()) {
            throw ProblemException::from(Problem::make(
                'unsupported_grant_type',
                'Password sign-in is switched off. Use grant_type refresh_token.',
                ['error' => 'unsupported_grant_type']
            ));
        }

        return match ($grant) {
            'password'      => $this->password($request, $input),
            'refresh_token' => $this->refreshGrant($request, (string) ($input['refresh_token'] ?? '')),
            default         => throw ProblemException::from(Problem::make(
                'unsupported_grant_type',
                'Use grant_type password or refresh_token.',
                ['error' => 'unsupported_grant_type']
            )),
        };
    }

    public function signOut(ApiCall $call): Response
    {
        $credential = $call->credential();

        $family = $credential->isAccessToken() ? $credential->family() : null;
        if ($family === null) {
            throw ProblemException::of('wrong_credential', 'Signing out needs an access token. Revoke a key at /account/keys.');
        }
        $this->refresh->end((int) $credential->userId(), ($call->input()['all'] ?? false) === true ? null : $family);

        return Response::noContent();
    }

    /**
     * @param array<mixed> $input
     *
     * @throws ProblemException
     */
    private function password(Request $request, array $input): Response
    {
        $account  = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $missing  = [];
        foreach (['username' => $account, 'password' => $password] as $field => $value) {
            if ($value === '') {
                $missing[] = ['pointer' => '/' . $field, 'code' => 'required', 'message' => 'is required for the password grant'];
            }
        }
        if ($missing !== []) {
            throw ProblemException::from(Problem::validation($missing)->withBodyMember('error', 'invalid_request'));
        }

        osc_run_hook('before_validating_login');
        $signIn = SignIn::attempt($account, $password, false, $request->ip());
        $user   = $signIn->user();
        if ($user !== null) {
            $this->users->forget((int) $user['pk_i_id']);
        }
        match ($signIn->status()) {
            SignIn::OK       => null,
            SignIn::BLOCKED  => throw ProblemException::tooMany('Too many failed sign-ins. Try again later.', $signIn->retryAfter(), 'login_blocked'),
            SignIn::WRONG    => throw self::refusedGrant('The e-mail, username or password is wrong.'),
            SignIn::BANNED   => throw self::refusedGrant('This account or address may not sign in.'),
            SignIn::INACTIVE => throw self::refusedGrant('This account is not confirmed yet. Open the link in the activation e-mail.'),
            default              => throw self::refusedGrant('This account is suspended.'),
        };

        try {
            $scopes = $this->tokens->grantable((int) $user['pk_i_id'], (string) ($input['scope'] ?? ''));
        } catch (ProblemException $e) {
            throw ProblemException::from(Problem::make('invalid_scope', 'The scope names no scope a user can hold.', ['error' => 'invalid_scope']));
        }
        WebIdentity::assume($user);
        $grant = $this->refresh->start($user, $scopes, (string) ($input['label'] ?? ''), $request->ip());
        // The web login passes where it sends the user next; an API sign-in goes nowhere.
        $url_redirect = '';
        osc_run_hook('after_login', $user, $url_redirect);

        return $this->tokens->answer($user, $scopes, $grant->family(), $grant);
    }

    /**
     * @throws ProblemException for a refused refresh token
     */
    private function refreshGrant(Request $request, string $token): Response
    {
        if ($token === '') {
            throw ProblemException::from(
                Problem::validation([['pointer' => '/refresh_token', 'code' => 'required', 'message' => 'is required for the refresh_token grant']])
                    ->withBodyMember('error', 'invalid_request')
            );
        }
        $grant = $this->refresh->rotate($token, $request->ip());
        $user = $this->users->find($grant->userId());
        if ($user === null) {
            throw ProblemException::from(Problem::make('invalid_grant', 'The refresh token is not valid. Sign in again.', ['error' => 'invalid_grant']));
        }
        if (osc_is_banned((string) $user['s_email'], $request->ip()) !== 0) {
            $this->refresh->end($grant->userId(), $grant->family());

            throw self::refusedGrant('This account or address may not sign in.');
        }

        return $this->tokens->answer($user, $grant->scopes(), $grant->family(), $grant);
    }

    /**
     * An account that may not sign in, as an OAuth `invalid_grant`.
     */
    private static function refusedGrant(string $detail): ProblemException
    {
        return ProblemException::from(Problem::make('invalid_grant', $detail, ['error' => 'invalid_grant']));
    }
}
