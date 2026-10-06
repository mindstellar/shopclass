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
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\apiaccess\Credential;
use mindstellar\comment\CommentService;

/**
 * A user's comments, through CommentService as the comment form's: `POST /listings/{id}/comments`
 * posts one, so the same checks, moderation, spam check, e-mails and hooks apply;
 * `GET /comments/{id}` reads one, and the author deletes their own with `DELETE /comments/{id}`.
 */
final class CommentsController
{
    public function __construct(private ApiServices $api)
    {
    }

    /**
     * POST /listings/{id}/comments
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $input = $request->input();
        $comments = new CommentService();
        $saved    = $comments->post((int) $args['id'], [
            'title' => (string) ($input['title'] ?? ''),
            'body'  => (string) ($input['body'] ?? ''),
        ], $credential->actor($request->ip(), ViewContext::LISTINGS_SCOPE));

        $row      = $comments->find($saved->id());
        $warnings = $saved->isLive() ? [] : ['warnings' => [['code' => 'comment_pending', 'message' => 'The comment shows once it is approved.']]];

        return Response::created((new CommentSerializer())->one($row ?? []), $this->api->links()->api('comments/' . $saved->id()), $warnings);
    }

    /**
     * GET /comments/{id}: an approved comment on a listing the caller can see, or the
     * caller's own whatever its state. Anything else is 404, as if it did not exist.
     *
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $comment = (new CommentService())->visible((int) $args['id'], $credential->actor($request->ip(), ViewContext::LISTINGS_SCOPE));
        if ($comment === null) {
            throw ProblemException::of('not_found', 'No such comment.');
        }

        return Response::ok((new CommentSerializer())->one($comment));
    }

    /**
     * DELETE /comments/{id}: the author's own live comment.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        (new CommentService())->delete((int) $args['id'], $credential->actor($request->ip()));

        return Response::noContent();
    }
}
