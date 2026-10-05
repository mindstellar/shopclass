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
use mindstellar\api\auth\Credential;
use mindstellar\api\ProblemException;
use mindstellar\api\read\CommentStatus;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\moderation\CommentModeration;

/**
 * `/admin/comments`: every comment whatever its status, and what the comments screen does to
 * one, through the same CommentModeration, so the same hooks fire and the author is told
 * when their comment goes live.
 */
final class AdminCommentsController
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 100;

    private CommentSerializer $serializer;

    private CommentModeration $moderation;

    public function __construct(private ApiServices $api)
    {
        $this->moderation = $api->commentModeration();
        $this->serializer = new CommentSerializer();
    }

    /**
     * GET /admin/comments: newest first, paged by id.
     *
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('desc', self::DEFAULT_LIMIT, self::MAX_LIMIT), ['list' => 'admin/comments'] + $request->query());
        $query = CommentStatus::condition(osc_db_table(DB_TABLE_PREFIX . 't_item_comment'), $request->queryList('status'));
        foreach (['listing' => 'fk_i_item_id', 'user' => 'fk_i_user_id'] as $filter => $column) {
            if ($request->queryString($filter) !== '') {
                $query = $query->where($column, $request->queryInt($filter));
            }
        }
        $total = $pager->counts() ? $query->count() : null;
        $after = $pager->after();
        if ($after !== null) {
            $query = $query->where('pk_i_id', '<', (int) $after[0]);
        }
        $rows = osc_db_stringify_rows($query->orderBy('pk_i_id', 'DESC')->limit($pager->limit() + 1)->get());
        $next = $pager->next($rows);
        $data = array_map([$this->serializer, 'admin'], $pager->page($rows));

        return (new Page($data, $total, $pager->limit(), $next))->response($this->api->links(), 'admin/comments', $request->query());
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serializer->admin($this->comment((int) $args['id'])));
    }

    /**
     * PATCH /admin/comments/{id}. Members not sent keep their values.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $comment = $this->comment((int) $args['id']);
        $input   = $request->input();
        $fields  = [
            'title'        => (string) ($input['title'] ?? $comment['s_title']),
            'body'         => (string) ($input['body'] ?? $comment['s_body']),
            'author_name'  => (string) ($input['author_name'] ?? $comment['s_author_name']),
            'author_email' => (string) ($input['author_email'] ?? $comment['s_author_email']),
        ];
        $this->moderation->edit((int) $comment['pk_i_id'], $fields);

        return $this->show($request, $credential, $args);
    }

    /**
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        if (!$this->moderation->delete((int) $this->comment((int) $args['id'])['pk_i_id'])) {
            throw ProblemException::of('server_error', 'The comment could not be deleted.');
        }

        return Response::noContent();
    }

    /**
     * POST /admin/comments/{id}/<action>: activate (its author is told), deactivate, enable
     * or disable, read from the path's last segment.
     *
     * @param array<string,string> $args
     */
    public function act(Request $request, Credential $credential, array $args): Response
    {
        $id         = (int) $this->comment((int) $args['id'])['pk_i_id'];
        $moderation = $this->moderation;
        match (basename((string) $request->path())) {
            'activate'   => $moderation->activate($id),
            'deactivate' => $moderation->deactivate($id),
            'enable'     => $moderation->enable($id),
            'disable'    => $moderation->disable($id),
        };

        return $this->show($request, $credential, $args);
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function comment(int $id): array
    {
        $row = osc_db_table(DB_TABLE_PREFIX . 't_item_comment')->where('pk_i_id', $id)->first();
        if ($row === null) {
            throw ProblemException::of('not_found', 'No such comment.');
        }

        return osc_db_stringify_row($row);
    }
}
