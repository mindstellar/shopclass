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

namespace mindstellar\api\controller\admin;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\KeySerializer;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\write\AccountBody;
use mindstellar\api\write\StatusMembers;
use mindstellar\apiaccess\AccessEntries;
use mindstellar\apiaccess\AccessEntry;
use mindstellar\model\Resource;
use mindstellar\user\AccountService;
use mindstellar\user\UserQuery;

/**
 * `/admin/users`: every user in full, the users screen's edit, actions and delete through
 * AccountService as an admin (so its checks, activity log and hooks apply), and the user's
 * sign-ins and keys.
 */
final class AdminUsersController
{
    /** The PATCH members that change the account's status rather than its profile => AccountService's flag. */
    private const STATUS_MEMBERS = ['confirmed' => 'active', 'blocked' => 'blocked'];

    private UserRows $users;
    private AccessEntries $sessions;
    private UserQuery $query;

    public function __construct(private ApiServices $api)
    {
        $this->users = $api->users();
        $this->sessions = $api->accessEntries();
        $this->query = new UserQuery();
    }

    public function index(ApiCall $call): Response
    {
        $request = $call->request();

        $context = $this->api->context($request, $call->credential(), 'user', UserSerializer::MEMBERS);
        $pager   = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId(), ['list' => 'admin/users'] + $request->query());
        $flag    = static fn (string $name): ?bool => array_key_exists($name, $request->query()) ? $request->queryBool($name) : null;
        $blocked = $flag('blocked');
        [$active, $enabled, $q] = [$flag('confirmed'), $blocked === null ? null : !$blocked, trim($request->queryString('q'))];
        $serializer = $this->serializer();

        return $pager->respond(
            fn (): array => $this->query->newest($active, $enabled, $q, $pager->afterId(), $pager->limit() + 1),
            fn (): int => $this->query->count($active, $enabled, $q),
            static function (array $page) use ($serializer, $context): array {
                if ($page !== [] && $context->wants('avatar')) {
                    (new Resource())->primeOwnerCache(Resource::OWNER_USER, array_column($page, 'pk_i_id'));
                }

                return array_map(static fn (array $user): array => $serializer->one($user, $context), $page);
            },
            $this->api->links(),
            'admin/users',
            $request->query(),
            $request->version()
        );
    }

    public function show(ApiCall $call): Response
    {
        return $this->one($call, $call->intArg());
    }

    /**
     * Members not sent keep their values; `blocked` and `confirmed`
     * change the account's status as the screen's actions do.
     */
    public function update(ApiCall $call): Response
    {
        $user     = $this->user($call->intArg());
        $userId   = (int) $user['pk_i_id'];
        $input    = $call->input();
        $accounts = new AccountService();
        $actor    = $call->actor('admin:users');
        [$flags, $edit] = StatusMembers::split($input, self::STATUS_MEMBERS);
        $accounts->adminEdit($userId, $edit === [] ? null : AccountBody::admin($user, $edit), $flags, $actor);

        return $this->fresh($call, $userId);
    }

    /**
     * The user, their listings and comments, profile texts, saved
     * searches, avatar, sign-ins and keys, all or none.
     */
    public function delete(ApiCall $call): Response
    {
        $id = (int) $this->user($call->intArg())['pk_i_id'];
        (new AccountService())->delete($id, $call->actor('admin:users'));
        $this->users->forget($id);

        return Response::noContent();
    }

    /**
     * Every web sign-in, API token and personal key
     * of the user stops working.
     */
    public function signOutEverywhere(ApiCall $call): Response
    {
        $id = (int) $this->user($call->intArg())['pk_i_id'];
        \mindstellar\auth\SignOut::everywhereUser($id);
        $this->users->forget($id);

        return Response::noContent();
    }

    public function sessions(ApiCall $call): Response
    {
        $id         = (int) $this->user($call->intArg())['pk_i_id'];
        $serializer = new KeySerializer();

        return Page::whole(array_map(
            static fn (AccessEntry $session): array => $serializer->session($session, $call->credential()),
            $this->sessions->signIns($id)
        ), $this->api->links(), $call);
    }

    public function endSession(ApiCall $call): Response
    {
        $id = (int) $this->user($call->intArg())['pk_i_id'];
        if (!$this->sessions->endSignIn($id, $call->arg('session') ?? '')) {
            throw ProblemException::notFound('No such sign-in.');
        }

        return Response::noContent();
    }

    /**
     * The user read again after a write.
     */
    private function fresh(ApiCall $call, int $id): Response
    {
        $this->users->forget($id);

        return $this->one($call, $id);
    }

    private function one(ApiCall $call, int $id): Response
    {
        $context = $this->api->context($call->request(), $call->credential(), 'user', UserSerializer::MEMBERS);

        return Response::ok($this->serializer()->one($this->user($id), $context));
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function user(int $id): array
    {
        return ProblemException::found($this->users->find($id), 'user');
    }

    private function serializer(): UserSerializer
    {
        return new UserSerializer($this->api->links(), $this->api->extensions());
    }
}
