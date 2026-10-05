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

use mindstellar\api\ApiServices;
use mindstellar\api\auth\AccessEntries;
use mindstellar\api\auth\AccessEntry;
use mindstellar\api\auth\Credential;
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\AccessEntrySerializer;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\write\AccountBody;
use mindstellar\model\Resource;
use mindstellar\user\AccountService;

/**
 * `/admin/users`: every user in full, the users screen's edit, actions and delete through
 * AccountService as an admin (so its checks, activity log and hooks apply), and the user's
 * sign-ins and keys.
 */
final class AdminUsersController
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 100;

    private UserRows $users;
    private AccessEntries $sessions;

    public function __construct(private ApiServices $api)
    {
        $this->users = $api->users();
        $this->sessions = $api->accessEntries();
    }

    /**
     * GET /admin/users: newest first, paged by id.
     *
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $context = $this->api->context($request, $credential, 'user', UserSerializer::MEMBERS);
        $pager   = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('desc', self::DEFAULT_LIMIT, self::MAX_LIMIT), ['list' => 'admin/users'] + $request->query());
        $query = osc_db_table(DB_TABLE_PREFIX . 't_user');
        foreach (['active' => 'b_active', 'enabled' => 'b_enabled'] as $filter => $column) {
            if (array_key_exists($filter, $request->query())) {
                $query = $query->where($column, $request->queryBool($filter) ? 1 : 0);
            }
        }
        $q = trim($request->queryString('q'));
        if ($q !== '') {
            $like  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query = $query->whereRaw('(s_email LIKE ? OR s_username LIKE ? OR s_name LIKE ?)', [$like, $like, $like]);
        }
        $total = $pager->counts() ? $query->count() : null;
        $after = $pager->after();
        if ($after !== null) {
            $query = $query->where('pk_i_id', '<', (int) $after[0]);
        }
        $rows = osc_db_stringify_rows($query->orderBy('pk_i_id', 'DESC')->limit($pager->limit() + 1)->get());
        $next = $pager->next($rows);
        $page = $pager->page($rows);
        if ($page !== [] && $context->wants('avatar')) {
            (new Resource())->primeOwnerCache(Resource::OWNER_USER, array_column($page, 'pk_i_id'));
        }
        $serializer = $this->serializer();
        $data       = array_map(static fn (array $user): array => $serializer->one($user, $context), $page);

        return (new Page($data, $total, $pager->limit(), $next))->response($this->api->links(), 'admin/users', $request->query());
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $context = $this->api->context($request, $credential, 'user', UserSerializer::MEMBERS);

        return Response::ok($this->serializer()->one($this->user((int) $args['id']), $context));
    }

    /**
     * PATCH /admin/users/{id}. Members not sent keep their values.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $user   = $this->user((int) $args['id']);
        $userId = (int) $user['pk_i_id'];
        $form   = AccountBody::admin($user, $request->input());
        (new AccountService())->update($userId, $form, $credential->actor($request->ip(), 'admin:users'));

        return $this->fresh($request, $credential, $userId);
    }

    /**
     * DELETE /admin/users/{id}: the user, their listings and comments, profile texts, saved
     * searches, avatar, sign-ins and keys, all or none.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        $id = (int) $this->user((int) $args['id'])['pk_i_id'];
        (new AccountService())->delete($id, $credential->actor($request->ip(), 'admin:users'));
        $this->users->forget($id);

        return Response::noContent();
    }

    /**
     * POST /admin/users/{id}/<action>: activate, deactivate, enable or disable, as the users
     * screen does; read from the path's last segment.
     *
     * @param array<string,string> $args
     */
    public function act(Request $request, Credential $credential, array $args): Response
    {
        $id       = (int) $this->user((int) $args['id'])['pk_i_id'];
        $accounts = new AccountService();
        $actor    = $credential->actor($request->ip(), 'admin:users');
        $changed  = match (basename((string) $request->path())) {
            'activate'   => $accounts->activate($id, $actor),
            'deactivate' => $accounts->deactivate($id, $actor),
            'enable'     => $accounts->enable($id, $actor),
            'disable'    => $accounts->disable($id, $actor),
        };
        if (!$changed) {
            throw ProblemException::of('server_error', 'The user could not be changed.');
        }

        return $this->fresh($request, $credential, $id);
    }

    /**
     * POST /admin/users/{id}/sign-out-everywhere: every web sign-in, API token and personal key
     * of the user stops working.
     *
     * @param array<string,string> $args
     */
    public function signOutEverywhere(Request $request, Credential $credential, array $args): Response
    {
        $id = (int) $this->user((int) $args['id'])['pk_i_id'];
        \mindstellar\auth\SignOut::everywhereUser($id);
        $this->users->forget($id);

        return Response::noContent();
    }

    /**
     * GET /admin/users/{id}/sessions
     *
     * @param array<string,string> $args
     */
    public function sessions(Request $request, Credential $credential, array $args): Response
    {
        $id         = (int) $this->user((int) $args['id'])['pk_i_id'];
        $serializer = new AccessEntrySerializer();

        return Response::collection(array_map(
            static fn (AccessEntry $session): array => $serializer->one($session, $credential),
            $this->sessions->list($id)
        ));
    }

    /**
     * DELETE /admin/users/{id}/sessions/{session}
     *
     * @param array<string,string> $args
     */
    public function endSession(Request $request, Credential $credential, array $args): Response
    {
        $id = (int) $this->user((int) $args['id'])['pk_i_id'];
        if (!$this->sessions->end($id, $args['session'])) {
            throw ProblemException::of('not_found', 'No such session.');
        }

        return Response::noContent();
    }

    /**
     * The user read again after a write.
     */
    private function fresh(Request $request, Credential $credential, int $id): Response
    {
        $this->users->forget($id);

        return $this->show($request, $credential, ['id' => (string) $id]);
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
