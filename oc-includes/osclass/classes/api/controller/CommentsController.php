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
use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\Warning;
use mindstellar\comment\CommentService;

/**
 * A user's comments, through CommentService as the comment form's: `POST /listings/{id}/comments`
 * posts one, so the same checks, moderation, spam check, e-mails and hooks apply;
 * `GET /comments/{id}` reads one, and the author deletes their own with `DELETE /comments/{id}`.
 */
final class CommentsController
{
    private CommentService $comments;

    public function __construct(private ApiServices $api)
    {
        $this->comments = new CommentService();
    }

    public function create(ApiCall $call): Response
    {
        $input = $call->input();
        $saved = $this->comments->post($call->intArg(), [
            'title' => (string) ($input['title'] ?? ''),
            'body'  => (string) ($input['body'] ?? ''),
        ], $call->listingActor());

        $row      = $this->comments->find($saved->id());
        $warnings = Warning::member($saved->isLive() ? [] : [Warning::COMMENT_PENDING => 'The comment shows once it is approved.']);

        return $this->api->created($call, (new CommentSerializer())->one($row ?? []), 'comments/' . $saved->id(), $warnings);
    }

    /**
     * An approved comment on a listing the caller can see, or the
     * caller's own whatever its state. Anything else is 404, as if it did not exist.
     */
    public function show(ApiCall $call): Response
    {
        $actor   = $call->listingActor();
        $comment = ProblemException::found($this->comments->visible($call->intArg(), $actor), 'comment');

        return Response::ok((new CommentSerializer())->one($comment));
    }

    public function delete(ApiCall $call): Response
    {
        $this->comments->delete($call->intArg(), $call->actor());

        return Response::noContent();
    }
}
