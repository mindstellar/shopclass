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

use mindstellar\api\ApiServices;
use mindstellar\api\auth\RefreshTokens;

use mindstellar\api\auth\TokenIssuer;
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingList;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\AccessEntrySerializer;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\write\AccountBody;
use mindstellar\apiaccess\AccessEntries;
use mindstellar\apiaccess\AccessEntry;
use mindstellar\apiaccess\Credential;
use mindstellar\user\AccountService;
use mindstellar\utility\DeferredMail;

/**
 * The signed-in user's own account: `GET` and `PATCH /account`, `POST /account/password`,
 * their listings at `/account/listings`, and the sign-ins and keys that act for them at
 * `/account/sessions`.
 *
 * Edits go through AccountService as the profile form's do, so its checks and hooks run. A
 * new e-mail address is not applied here: it gets the same confirmation link the web sends.
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
        $this->users = $api->users();
        $this->tokens = $api->tokenIssuer();
        $this->refresh = $api->refreshTokens();
        $this->sessions = $api->accessEntries();
        $this->accounts = new AccountService();
    }

    /**
     * GET /account/listings: the user's own listings in any status, as their listings page
     * lists them, newest first.
     *
     * @param array<string,string> $args
     */
    public function listings(Request $request, Credential $credential, array $args): Response
    {
        return (new ListingList($this->api, $this->api->listingReader()))
            ->run($request, $credential, 'account/listings', $request->queryList('status'), [(int) $credential->userId()]);
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serialize($request, $credential, $this->user($credential)));
    }

    /**
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $input    = $request->input();
        $user     = $this->user($credential);
        $userId   = (int) $user['pk_i_id'];
        $warnings = [];

        $actor    = $credential->actor($request->ip());
        $newEmail = isset($input['email']) ? trim((string) $input['email']) : '';
        if (strcasecmp($newEmail, (string) $user['s_email']) === 0) {
            $newEmail = '';
        }

        $profile  = array_diff_key($input, ['email' => true]);
        $accounts = $this->accounts;
        // One transaction, so an e-mail change over its hourly cap leaves the profile as it was.
        DeferredMail::transaction(static function () use ($accounts, $actor, $user, $userId, $profile, $newEmail): void {
            if ($profile !== []) {
                $accounts->update($userId, AccountBody::profile($user, $profile), $actor);
            }
            if ($newEmail !== '') {
                $accounts->requestEmailChange($userId, $newEmail, $actor);
            }
        });
        if ($profile !== []) {
            $this->users->forget($userId);
        }
        if ($newEmail !== '') {
            $warnings[] = ['code' => 'email_confirmation_sent', 'message' => 'A confirmation link went to the new address. The e-mail changes once it is opened.'];
        }

        $data = $this->serialize($request, $credential, $this->user($credential));

        return Response::ok($data, 200, $warnings === [] ? [] : ['warnings' => $warnings]);
    }

    /**
     * @param array<string,string> $args
     */
    public function password(Request $request, Credential $credential, array $args): Response
    {
        $input  = $request->input();
        $user   = $this->user($credential);
        $userId = (int) $user['pk_i_id'];
        $family = $credential->family();
        if ($family === null) {
            throw ProblemException::of('wrong_credential', 'Changing the password needs an access token.');
        }
        $label = '';
        foreach ($this->sessions->list($userId) as $session) {
            if (!$session->isKey() && $session->id() === $family) {
                $label = $session->row()->name();
            }
        }

        // The same change as the web form's: every sign-in ends, this one too, so this
        // client carries on with a new sign-in under the same name.
        $this->accounts->changePassword($user, (string) ($input['current_password'] ?? ''), (string) $input['new_password']);
        $this->users->forget($userId);
        $user  = $this->user($credential);
        $grant = $this->refresh->start($user, $credential->scopes(), $label, $request->ip());

        return $this->tokens->answer($user, $grant->scopes(), $grant->family(), $grant);
    }

    /**
     * POST /account/sign-out-everywhere: every sign-in of the user ends, this one too.
     *
     * @param array<string,string> $args
     */
    public function signOutEverywhere(Request $request, Credential $credential, array $args): Response
    {
        if ($credential->family() === null) {
            throw ProblemException::of('wrong_credential', 'Signing out of all devices needs an access token.');
        }
        $userId = (int) $this->user($credential)['pk_i_id'];
        \mindstellar\auth\SignOut::everywhereUser($userId);
        $this->users->forget($userId);

        return Response::noContent();
    }

    /**
     * @param array<string,string> $args
     */
    public function sessions(Request $request, Credential $credential, array $args): Response
    {
        $serializer = new AccessEntrySerializer();

        return Response::collection(array_map(
            static fn (AccessEntry $session): array => $serializer->one($session, $credential),
            $this->sessions->list((int) $credential->userId())
        ));
    }

    /**
     * @param array<string,string> $args
     */
    public function endSession(Request $request, Credential $credential, array $args): Response
    {
        if (!$this->sessions->end((int) $credential->userId(), $args['session'])) {
            throw ProblemException::of('not_found', 'No such session.');
        }

        return Response::noContent();
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404 when the user is gone
     */
    private function user(Credential $credential): array
    {
        $user = $this->users->find((int) $credential->userId());
        if ($user === null) {
            throw ProblemException::of('not_found', 'No such user.');
        }

        return $user;
    }

    /**
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    private function serialize(Request $request, Credential $credential, array $user): array
    {
        $context = $this->api->context($request, $credential, 'user', UserSerializer::MEMBERS);

        return (new UserSerializer($this->api->links(), $this->api->extensions()))->one($user, $context);
    }
}
