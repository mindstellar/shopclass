<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\comment;

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\utility\DeferredMail;
use mindstellar\validation\BlockedException;
use mindstellar\validation\ConflictException;
use mindstellar\validation\ForbiddenException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;

/**
 * Posting and deleting a comment on a listing, for the comment form, the API and plugins
 * alike. The hooks fire from here, so every caller fires the same ones in the same order;
 * each write runs in one transaction with its e-mails sent once it has committed.
 */
final class CommentService
{
    private \ItemComment $comments;

    public function __construct()
    {
        $this->comments = \ItemComment::getInstance();
    }

    /**
     * Post a comment. Fires `pre_item_add_comment_post`, then on success `before_add_comment`,
     * the new-comment e-mail hooks and `add_comment`. A signed-in author comments under their
     * account's name and e-mail; a guest under the ones sent.
     *
     * @param array{title?:string,body?:string,author_name?:string,author_email?:string} $input
     *
     * @throws ForbiddenException when comments are off, the author is banned, or only users may comment
     * @throws NotFoundException  for a listing the actor cannot see
     * @throws InvalidException   for a bad e-mail or an empty body
     * @throws BlockedException   past the hourly comment limit
     * @throws \RuntimeException when the row is not written
     */
    public function post(int $itemId, array $input, Actor $actor): SavedComment
    {
        $item = \Item::getInstance()->findByPrimaryKey($itemId);
        // Anti-spam plugins check the comment here.
        osc_run_hook('pre_item_add_comment_post', $item);

        $user = null;
        if ($actor->userId() !== null) {
            $user = \User::getInstance()->findByPrimaryKey($actor->userId());
            if (!is_array($user) || $user === []) {
                throw new NotFoundException(_m('No such user.'));
            }
        }
        $authorName  = trim(strip_tags((string) ($user['s_name'] ?? $input['author_name'] ?? '')));
        $authorEmail = trim(strip_tags((string) ($user['s_email'] ?? $input['author_email'] ?? '')));
        $title       = trim(strip_tags((string) ($input['title'] ?? '')));
        $body        = trim(strip_tags((string) ($input['body'] ?? '')));

        match (CommentPolicy::mayPost($actor, $authorEmail)) {
            CommentPolicy::DISABLED        => throw new ForbiddenException(_m('Sorry, comments are disabled')),
            CommentPolicy::BANNED          => throw new ForbiddenException(_m('Your comment has been marked as spam')),
            CommentPolicy::REGISTERED_ONLY => throw new ForbiddenException(_m('You need to be logged to comment')),
            default                        => null,
        };
        if (!is_array($item) || $item === [] || !ListingPolicy::canView($item, $actor)) {
            throw new NotFoundException(_m("This listing doesn't exist"));
        }
        if (!osc_validate_email($authorEmail)) {
            throw new InvalidException('/author_email', 'format', _m('Please fill the required field (email)'));
        }
        if ($body === '') {
            throw new InvalidException('/body', 'minLength', _m('Please type a comment'));
        }
        if (CommentPolicy::tooMany($actor)) {
            throw BlockedException::rateLimit(_m('Too many comments in an hour. Try again later.'), CommentPolicy::retryAfter());
        }

        $status = $this->status($user, $authorName, $authorEmail, $body, $item);
        $row    = [
            'dt_pub_date'    => date('Y-m-d H:i:s'),
            'fk_i_item_id'   => (int) $item['pk_i_id'],
            's_author_name'  => $authorName,
            's_author_email' => $authorEmail,
            's_title'        => $title,
            's_body'         => $body,
            'b_active'       => $status === SavedComment::LIVE ? 1 : 0,
            'b_enabled'      => 1,
            'fk_i_user_id'   => $actor->userId(),
        ];
        // The shape the new-comment e-mails have always been handed.
        $mail = [
            'item'        => $item,
            'authorName'  => $authorName,
            'authorEmail' => $authorEmail,
            'body'        => $body,
            'title'       => $title,
            'id'          => (int) $item['pk_i_id'],
            'userId'      => $actor->userId(),
        ];

        return DeferredMail::transaction(function () use ($row, $mail, $status, $actor): SavedComment {
            osc_run_hook('before_add_comment', $row);
            $id = (int) $this->comments->insertGetId($row);
            if ($id <= 0) {
                throw new \RuntimeException('The comment could not be saved.');
            }
            if ($status === SavedComment::LIVE && $actor->userId() !== null) {
                $user = \User::getInstance()->findByPrimaryKey($actor->userId());
                if ($user) {
                    \User::getInstance()->update(['i_comments' => $user['i_comments'] + 1], ['pk_i_id' => $user['pk_i_id']]);
                }
                if (osc_notify_new_comment_user()) {
                    osc_run_hook('hook_email_new_comment_user', $mail);
                }
            }
            if (osc_notify_new_comment()) {
                osc_run_hook('hook_email_new_comment_admin', $mail);
            }
            osc_run_hook('add_comment', $id);

            return new SavedComment($id, $status);
        });
    }

    /**
     * The author deletes their own live comment. Fires `pre_item_delete_comment_post`, then
     * on success `delete_comment`.
     *
     * @param int $itemId the listing the request named, for the first hook when the comment is gone
     *
     * @throws ForbiddenException for a guest, or a comment someone else wrote
     * @throws NotFoundException  for no such comment
     * @throws ConflictException  for a comment that is not live
     */
    public function delete(int $commentId, Actor $actor, int $itemId = 0): void
    {
        $comment = $this->comments->findByPrimaryKey($commentId);
        $found   = is_array($comment) && $comment !== [];
        $item    = \Item::getInstance()->findByPrimaryKey($found ? (int) $comment['fk_i_item_id'] : $itemId);
        osc_run_hook('pre_item_delete_comment_post', $item, $commentId);

        if ($actor->userId() === null) {
            throw new ForbiddenException(_m('You must be logged in to delete a comment'));
        }
        if (!$found) {
            throw new NotFoundException(_m("The comment doesn't exist"));
        }
        if ((int) $comment['b_active'] !== 1) {
            throw new ConflictException(_m('The comment is not active, you cannot delete it'));
        }
        if (!CommentPolicy::isAuthor($comment, $actor)) {
            throw new ForbiddenException(_m('The comment was not added by you, you cannot delete it'));
        }

        DeferredMail::transaction(function () use ($commentId): void {
            if ($this->comments->deleteByPrimaryKey($commentId) === false) {
                throw new \RuntimeException('The comment could not be deleted.');
            }
            osc_run_hook('delete_comment', $commentId);
        });
    }

    /**
     * LIVE or PENDING by the site's moderation setting, SPAM when Akismet says so. The
     * Akismet call runs before the write, so no transaction waits on it.
     *
     * @param array<string,mixed>|null $user
     * @param array<string,mixed>      $item
     */
    private function status(?array $user, string $name, string $email, string $body, array $item): int
    {
        $threshold = (int) osc_moderate_comments();
        $approved  = (int) ($user['i_comments'] ?? 0);
        $status    = $threshold === -1 || ($threshold !== 0 && $approved >= $threshold) ? SavedComment::LIVE : SavedComment::PENDING;

        if (osc_akismet_key()) {
            \View::getInstance()->_exportVariableToView('item', $item);
            $akismet = new \Akismet(osc_base_url(), osc_akismet_key());
            $akismet->setCommentAuthor($name);
            $akismet->setCommentAuthorEmail($email);
            $akismet->setCommentContent($body);
            $akismet->setPermalink(osc_item_url());
            if ($akismet->isCommentSpam()) {
                $status = SavedComment::SPAM;
            }
        }

        return $status;
    }
}
