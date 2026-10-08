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
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Pager;
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
    /** The PATCH members that change the status rather than the text => CommentModeration's flag. */
    private const STATUS_MEMBERS = ['approved' => 'active', 'blocked' => 'blocked'];

    private CommentSerializer $serializer;

    private CommentModeration $moderation;

    private CommentQuery $comments;

    public function __construct(private ApiServices $api)
    {
        $this->moderation = $api->commentModeration();
        $this->serializer = new CommentSerializer();
        $this->comments   = new CommentQuery();
    }

    public function index(ApiCall $call): Response
    {
        $request = $call->request();

        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId(), ['list' => 'admin/comments'] + $request->query());
        [$statuses, $listings, $users] = [$request->queryList('status'), $request->queryIds('listing'), $request->queryIds('user')];

        return $pager->respond(
            fn (): array => $this->comments->newest($statuses, $listings, $users, $pager->afterId(), $pager->limit() + 1),
            fn (): int => $this->comments->count($statuses, $listings, $users),
            fn (array $page): array => array_map([$this->serializer, 'admin'], $page),
            $this->api->links(),
            'admin/comments',
            $request->query(),
            $request->version()
        );
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serializer->admin($this->comment($call->intArg())));
    }

    /**
     * Members not sent keep their values; `blocked` and `approved`
     * change the status as the screen's actions do.
     */
    public function update(ApiCall $call): Response
    {
        $comment = $this->comment($call->intArg());
        $id      = (int) $comment['pk_i_id'];
        $input   = $call->input();
        $flags   = [];
        foreach (array_intersect_key($input, self::STATUS_MEMBERS) as $member => $value) {
            $flags[self::STATUS_MEMBERS[$member]] = (bool) $value;
        }
        $this->moderation->adminEdit($id, array_diff_key($input, self::STATUS_MEMBERS) === [] ? null : [
            'title'        => (string) ($input['title'] ?? $comment['s_title']),
            'body'         => (string) ($input['body'] ?? $comment['s_body']),
            'author_name'  => (string) ($input['author_name'] ?? $comment['s_author_name']),
            'author_email' => (string) ($input['author_email'] ?? $comment['s_author_email']),
        ], $flags);

        return $this->show($call);
    }

    public function delete(ApiCall $call): Response
    {
        if (!$this->moderation->delete((int) $this->comment($call->intArg())['pk_i_id'])) {
            throw ProblemException::of('server_error', 'The comment could not be deleted.');
        }

        return Response::noContent();
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function comment(int $id): array
    {
        return ProblemException::found($this->comments->find($id), 'comment');
    }
}
