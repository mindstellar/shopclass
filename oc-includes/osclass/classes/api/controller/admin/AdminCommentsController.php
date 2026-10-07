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
     */
    public function index(ApiCall $call): Response
    {
        $request = $call->request();

        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId(), ['list' => 'admin/comments'] + $request->query());
        $filter = static fn (string $name): ?int => $request->queryString($name) === '' ? null : $request->queryInt($name);
        [$statuses, $listing, $user] = [$request->queryList('status'), $filter('listing'), $filter('user')];

        return $pager->respond(
            fn (): array => $this->comments->newest($statuses, $listing, $user, $pager->afterId(), $pager->limit() + 1),
            fn (): int => $this->comments->count($statuses, $listing, $user),
            fn (array $page): array => array_map([$this->serializer, 'admin'], $page),
            $this->api->links(),
            'admin/comments',
            $request->query()
        );
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serializer->admin($this->comment($call->intArg())));
    }

    /**
     * PATCH /admin/comments/{id}. Members not sent keep their values; `blocked` and `approved`
     * change the status as the screen's actions do.
     */
    public function update(ApiCall $call): Response
    {
        $comment = $this->comment($call->intArg());
        $id      = (int) $comment['pk_i_id'];
        $input   = $call->input();
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
        return $this->comments->find($id) ?? throw ProblemException::notFound('No such comment.');
    }
}
