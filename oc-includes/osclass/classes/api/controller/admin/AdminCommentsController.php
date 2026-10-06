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
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\comment\CommentQuery;
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

    /** The PATCH members that change the status rather than the text. */
    private const STATUS_MEMBERS = ['approved' => true, 'blocked' => true];

    private CommentSerializer $serializer;

    private CommentModeration $moderation;

    private CommentQuery $comments;

    public function __construct(private ApiServices $api)
    {
        $this->moderation = $api->commentModeration();
        $this->serializer = new CommentSerializer();
        $this->comments   = new CommentQuery();
    }

    /**
     * GET /admin/comments: newest first, paged by id.
     *
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('desc', self::DEFAULT_LIMIT, self::MAX_LIMIT), ['list' => 'admin/comments'] + $request->query());
        $filter = static fn (string $name): ?int => $request->queryString($name) === '' ? null : $request->queryInt($name);
        [$statuses, $listing, $user] = [$request->queryList('status'), $filter('listing'), $filter('user')];
        $total  = $pager->counts() ? $this->comments->count($statuses, $listing, $user) : null;
        $after  = $pager->after();
        $rows   = $this->comments->newest($statuses, $listing, $user, $after === null ? null : (int) $after[0], $pager->limit() + 1);
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
     * PATCH /admin/comments/{id}. Members not sent keep their values; `blocked` and `approved`
     * change the status as the screen's actions do.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $comment = $this->comment((int) $args['id']);
        $id      = (int) $comment['pk_i_id'];
        $input   = $request->input();
        $status  = array_intersect_key($input, self::STATUS_MEMBERS);
        if ($status === [] || array_diff_key($input, self::STATUS_MEMBERS) !== []) {
            $this->moderation->edit($id, [
                'title'        => (string) ($input['title'] ?? $comment['s_title']),
                'body'         => (string) ($input['body'] ?? $comment['s_body']),
                'author_name'  => (string) ($input['author_name'] ?? $comment['s_author_name']),
                'author_email' => (string) ($input['author_email'] ?? $comment['s_author_email']),
            ]);
        }
        // Unblock before approving, so the author is told once the comment is live.
        if (isset($status['blocked']) && $status['blocked'] === ((string) $comment['b_enabled'] === '1')) {
            $this->moderation->{$status['blocked'] ? 'disable' : 'enable'}($id);
        }
        if (isset($status['approved']) && $status['approved'] !== ((string) $comment['b_active'] === '1')) {
            $this->moderation->{$status['approved'] ? 'activate' : 'deactivate'}($id);
        }

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
     * or disable, read from the path's last segment. Deprecated for PATCH's `approved` and `blocked`.
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
        return $this->comments->find($id) ?? throw ProblemException::of('not_found', 'No such comment.');
    }
}
