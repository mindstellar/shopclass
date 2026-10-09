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
use mindstellar\api\ProblemException;
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\KeySerializer;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\Warning;
use mindstellar\api\write\AccountBody;
use mindstellar\apikey\AccessEntries;
use mindstellar\apikey\AccessEntry;
use mindstellar\apikey\Credential;
use mindstellar\auth\Reauth;
use mindstellar\auth\SignOut;
use mindstellar\user\AccountService;

/**
 * The signed-in user's own account, listings, sign-ins and keys. Edits go through AccountService as
 * the profile form's do. A new e-mail address needs the current password, then gets the same
 * confirmation link the web sends.
 */
final class AccountController
{
    private UserRows $users;
    private TokenIssuer $tokens;
    private RefreshTokens $refresh;
    private AccessEntries $sessions;
    private AccountService $accounts;

    public function __construct(private ApiServices $api)
    {
        $this->users    = $api->users();
        $this->tokens   = $api->tokenIssuer();
        $this->refresh  = $api->refreshTokens();
        $this->sessions = $api->access()->accessEntries();
        $this->accounts = $api->accounts();
    }

    /**
     * The user's own listings in any status, as their listings page
     * lists them, newest first.
     */
    public function listings(ApiCall $call): Response
    {
        $request = $call->request();

        return $this->api->listingSearch()
            ->newest($request, $call->credential(), 'account/listings', $request->queryList('status'), [$call->userId()]);
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serialize($call, $this->user($call->credential())));
    }

    public function update(ApiCall $call): Response
    {
        $input    = $call->input();
        $user     = $this->user($call->credential());
        $userId   = (int) $user['pk_i_id'];
        $newEmail = isset($input['email']) ? trim((string) $input['email']) : '';
        if (strcasecmp($newEmail, (string) $user['s_email']) === 0) {
            $newEmail = '';
        }
        // A stolen access token alone must not move the account to another address.
        if ($newEmail !== '') {
            if (($input['current_password'] ?? '') === '') {
                throw ProblemException::field('/current_password', 'required', 'is required to change the e-mail');
            }
            Reauth::check($user, (string) $input['current_password']);
        }

        $profile = array_diff_key($input, ['email' => true, 'current_password' => true]);
        $this->accounts->editOwn($userId, $profile === [] ? null : AccountBody::profile($user, $profile), $newEmail, $call->actor());
        if ($profile !== []) {
            $this->users->forget($userId);
        }
        $warnings = $newEmail === '' ? [] : [Warning::EMAIL_CONFIRMATION_SENT => 'A confirmation link went to the new address. The e-mail changes once it is opened.'];

        return Response::ok($this->serialize($call, $this->user($call->credential())), 200, Warning::member($warnings));
    }

    public function password(ApiCall $call): Response
    {
        $credential = $call->credential();
        $input      = $call->input();
        $user       = $this->user($credential);
        $userId     = (int) $user['pk_i_id'];
        $family     = $credential->family();
        if ($family === null) {
            throw ProblemException::of('wrong_credential', 'Changing the password needs an access token.');
        }
        $label = '';
        foreach ($this->sessions->list($userId) as $session) {
            if (!$session->isKey() && $session->id() === $family) {
                $label = $session->row()->name();
                break;
            }
        }

        // The same change as the web form's: every sign-in ends, this one too, so this
        // client carries on with a new sign-in under the same name.
        $this->accounts->changePassword($user, (string) ($input['current_password'] ?? ''), (string) $input['new_password']);
        $this->users->forget($userId);
        $user  = $this->user($credential);
        $grant = $this->refresh->start($user, $credential->scopes(), $label, $call->request()->ip());

        return $this->tokens->answer($user, $grant->scopes(), $grant->family(), $grant);
    }

    public function signOutEverywhere(ApiCall $call): Response
    {
        $credential = $call->credential();
        if ($credential->family() === null) {
            throw ProblemException::of('wrong_credential', 'Signing out of all devices needs an access token.');
        }
        $userId = (int) $this->user($credential)['pk_i_id'];
        SignOut::everywhereUser($userId);
        $this->users->forget($userId);

        return Response::noContent();
    }

    public function sessions(ApiCall $call): Response
    {
        $credential = $call->credential();
        $serializer = new KeySerializer();

        return Page::whole(array_map(
            static fn (AccessEntry $session): array => $serializer->session($session, $credential),
            $this->sessions->signIns($call->userId())
        ), $this->api->links(), $call);
    }

    public function endSession(ApiCall $call): Response
    {
        if (!$this->sessions->endSignIn($call->userId(), $call->arg('session') ?? '')) {
            throw ProblemException::notFound('No such sign-in.');
        }

        return Response::noContent();
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404 when the user is gone
     */
    private function user(Credential $credential): array
    {
        return ProblemException::found($this->users->find((int) $credential->userId()), 'user');
    }

    /**
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    private function serialize(ApiCall $call, array $user): array
    {
        $context = $this->api->context($call->request(), $call->credential(), 'user', UserSerializer::MEMBERS);

        return $this->api->userSerializer()->one($user, $context);
    }
}
