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
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\AccessEntrySerializer;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\write\AccountBody;
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
    /** The PATCH members that change the account's status rather than its profile. */
    private const STATUS_MEMBERS = ['confirmed' => true, 'blocked' => true];

    private UserRows $users;
    private AccessEntries $sessions;
    private UserQuery $query;

    public function __construct(private ApiServices $api)
    {
        $this->users = $api->users();
        $this->sessions = $api->accessEntries();
        $this->query = new UserQuery();
    }

    /**
     * GET /admin/users: newest first, paged by id.
     */
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
            $request->query()
        );
    }

    public function show(ApiCall $call): Response
    {
        $context = $this->api->context($call->request(), $call->credential(), 'user', UserSerializer::MEMBERS);

        return Response::ok($this->serializer()->one($this->user((int) $call->arg('id')), $context));
    }

    /**
     * PATCH /admin/users/{id}. Members not sent keep their values; `blocked` and `confirmed`
     * change the account's status as the screen's actions do.
     */
    public function update(ApiCall $call): Response
    {
        $request = $call->request();
        $credential = $call->credential();

        $user     = $this->user((int) $call->arg('id'));
        $userId   = (int) $user['pk_i_id'];
        $input    = $request->input();
        $status   = array_intersect_key($input, self::STATUS_MEMBERS);
        $accounts = new AccountService();
        $actor    = $credential->actor($request->ip(), 'admin:users');
        if ($status === [] || array_diff_key($input, self::STATUS_MEMBERS) !== []) {
            $accounts->update($userId, AccountBody::admin($user, array_diff_key($input, self::STATUS_MEMBERS)), $actor);
        }
        // Unblock before confirming, so the account's listings come back with it.
        $changes = [];
        if (isset($status['blocked']) && $status['blocked'] === ((string) $user['b_enabled'] === '1')) {
            $changes[] = $status['blocked'] ? 'disable' : 'enable';
        }
        if (isset($status['confirmed']) && $status['confirmed'] !== ((string) $user['b_active'] === '1')) {
            $changes[] = $status['confirmed'] ? 'activate' : 'deactivate';
        }
        foreach ($changes as $change) {
            if (!$accounts->$change($userId, $actor)) {
                throw ProblemException::of('server_error', 'The user could not be changed.');
            }
        }

        return $this->fresh($call, $userId);
    }

    /**
     * DELETE /admin/users/{id}: the user, their listings and comments, profile texts, saved
     * searches, avatar, sign-ins and keys, all or none.
     */
    public function delete(ApiCall $call): Response
    {
        $id = (int) $this->user((int) $call->arg('id'))['pk_i_id'];
        (new AccountService())->delete($id, $call->credential()->actor($call->request()->ip(), 'admin:users'));
        $this->users->forget($id);

        return Response::noContent();
    }

    /**
     * POST /admin/users/{id}/sign-out-everywhere: every web sign-in, API token and personal key
     * of the user stops working.
     */
    public function signOutEverywhere(ApiCall $call): Response
    {
        $id = (int) $this->user((int) $call->arg('id'))['pk_i_id'];
        \mindstellar\auth\SignOut::everywhereUser($id);
        $this->users->forget($id);

        return Response::noContent();
    }

    /**
     * GET /admin/users/{id}/sessions
     */
    public function sessions(ApiCall $call): Response
    {
        $id         = (int) $this->user((int) $call->arg('id'))['pk_i_id'];
        $serializer = new AccessEntrySerializer();

        return Response::collection(array_map(
            static fn (AccessEntry $session): array => $serializer->one($session, $call->credential()),
            $this->sessions->list($id)
        ));
    }

    /**
     * DELETE /admin/users/{id}/sessions/{session}
     */
    public function endSession(ApiCall $call): Response
    {
        $args = $call->args();

        $id = (int) $this->user((int) $args['id'])['pk_i_id'];
        if (!$this->sessions->end($id, $args['session'])) {
            throw ProblemException::of('not_found', 'No such session.');
        }

        return Response::noContent();
    }

    /**
     * The user read again after a write.
     */
    private function fresh(ApiCall $call, int $id): Response
    {
        $this->users->forget($id);

        return $this->show(new ApiCall($call->request(), $call->credential(), ['id' => (string) $id]));
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function user(int $id): array
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw ProblemException::of('not_found', 'No such user.');
        }

        return $user;
    }

    private function serializer(): UserSerializer
    {
        return new UserSerializer($this->api->links(), $this->api->extensions());
    }
}
