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
use mindstellar\exception\BlockedException;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\listing\ListingPolicy;
use mindstellar\security\SpamCheck;
use mindstellar\user\UserStore;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\ViewScope;

/**
 * Posting and deleting a comment on a listing, for the comment form, the API and plugins
 * alike. The hooks fire from here, so every caller fires the same ones in the same order;
 * each write runs in one transaction with its e-mails sent once it has committed.
 */
final class CommentService
{
    private \ItemComment $comments;
    private \Item $items;
    private \User $users;
    private CommentQuery $query;

    /**
     * Each collaborator defaults to the one the site uses; tests pass their own.
     */
    public function __construct(?\ItemComment $comments = null, ?\Item $items = null, ?\User $users = null, ?CommentQuery $query = null)
    {
        $this->query    = $query ?? new CommentQuery();
        $this->comments = $comments ?? \ItemComment::getInstance();
        $this->items    = $items ?? \Item::getInstance();
        $this->users    = $users ?? \User::getInstance();
    }

    /**
     * @return array<string,mixed>|null the comment's row
     */
    public function find(int $id): ?array
    {
        $comment = $this->comments->findByPrimaryKey($id);

        return is_array($comment) && $comment !== [] ? $comment : null;
    }

    /**
     * A comment the actor may see, as CommentPolicy::canView() decides; null otherwise.
     *
     * @return array<string,mixed>|null
     */
    public function visible(int $id, Actor $actor): ?array
    {
        $comment = $this->find($id);
        if ($comment === null) {
            return null;
        }
        $item = CommentPolicy::isLive($comment) ? $this->items->findByPrimaryKey((int) $comment['fk_i_item_id']) : false;

        return CommentPolicy::canView($comment, $item, $actor) ? $comment : null;
    }

    /**
     * Post a comment. Fires `pre_item_add_comment_post`, then on success `before_add_comment`,
     * the new-comment e-mail hooks and `add_comment`. A signed-in author comments under their
     * account's name and e-mail; a guest under the ones sent. A comment may carry a `rating` from
     * 1 to 5 and then needs no text; a rating needs a signed-in author, one rated comment per listing.
     *
     * @param array{title?:string,body?:string,rating?:mixed,author_name?:string,author_email?:string} $input
     *
     * @throws ForbiddenException when comments or ratings are off, the author is banned, or only users may comment
     * @throws NotFoundException  for a listing the actor cannot see
     * @throws InvalidException   for a bad e-mail, a rating out of range, or no text and no rating
     * @throws ConflictException  when the author already rated this listing
     * @throws BlockedException   past the hourly comment limit
     * @throws \RuntimeException when the row is not written
     */
    public function post(int $itemId, array $input, Actor $actor): SavedComment
    {
        $item = $this->items->findByPrimaryKey($itemId);
        // Anti-spam plugins check the comment here.
        osc_run_hook('pre_item_add_comment_post', $item);

        $user = null;
        if ($actor->userId() !== null) {
            $user = $this->users->findByPrimaryKey($actor->userId());
            if (!is_array($user) || $user === []) {
                throw new NotFoundException(_m('No such user.'));
            }
        }
        $authorName  = trim(strip_tags((string) ($user['s_name'] ?? $input['author_name'] ?? '')));
        $authorEmail = trim(strip_tags((string) ($user['s_email'] ?? $input['author_email'] ?? '')));
        $title       = trim(strip_tags((string) ($input['title'] ?? '')));
        $body        = trim(strip_tags((string) ($input['body'] ?? '')));
        $rating      = self::rating($input['rating'] ?? null);

        match (CommentPolicy::mayPost($actor, $authorEmail)) {
            CommentPolicy::DISABLED        => throw new ForbiddenException(_m('Sorry, comments are disabled'), ForbiddenException::DISABLED),
            CommentPolicy::BANNED          => throw new ForbiddenException(_m('Your comment has been marked as spam'), ForbiddenException::BANNED),
            CommentPolicy::REGISTERED_ONLY => throw new ForbiddenException(_m('You need to be logged to comment'), ForbiddenException::SIGN_IN),
            default                        => null,
        };
        if (!is_array($item) || $item === [] || !ListingPolicy::canView($item, $actor)) {
            throw new NotFoundException(_m("This listing doesn't exist"));
        }
        if (!osc_validate_email($authorEmail)) {
            throw new InvalidException('/author_email', 'format', _m('Please fill the required field (email)'));
        }
        if ($body === '' && $rating === null) {
            throw new InvalidException('/body', 'minLength', _m('Please type a comment'));
        }
        if ($rating !== null) {
            if (!osc_comment_ratings_enabled()) {
                throw new ForbiddenException(_m('Star ratings are turned off on this site'), ForbiddenException::DISABLED);
            }
            if ($actor->userId() === null) {
                throw new ForbiddenException(_m('You need to be logged to rate'), ForbiddenException::SIGN_IN);
            }
            if ($this->query->hasRated((int) $item['pk_i_id'], $actor->userId())) {
                throw new ConflictException(_m('You have already rated this item'), ConflictException::ALREADY_REVIEWED);
            }
        }
        if (CommentPolicy::tooMany($actor)) {
            throw BlockedException::rateLimit(_m('Too many comments in an hour. Try again later.'), CommentPolicy::retryAfter());
        }

        $status = $this->status($user, $authorName, $authorEmail, trim($title . "\n" . $body), $item, $actor->ip());
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
            'i_rating'       => $rating,
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

        // Before the transaction opens, so a plugin that redirects from it leaves none open.
        osc_run_hook('before_add_comment', $row);

        return DeferredMail::transaction(function () use ($row, $mail, $status, $actor): SavedComment {
            if ($actor->userId() !== null) {
                // Two comments or ratings sent at once wait here, so the second sees the first.
                UserStore::lock($actor->userId());
            }
            if ($row['i_rating'] !== null && $this->query->hasRated($row['fk_i_item_id'], (int) $row['fk_i_user_id'])) {
                throw new ConflictException(_m('You have already rated this item'), ConflictException::ALREADY_REVIEWED);
            }
            $id = (int) $this->comments->insertGetId($row);
            if ($id <= 0) {
                throw new \RuntimeException('The comment could not be saved.');
            }
            if ($status === SavedComment::LIVE && $actor->userId() !== null) {
                UserStore::addComment($actor->userId());
            }
            ViewScope::withItem($mail['item'], static function () use ($mail, $status, $actor): void {
                if ($status === SavedComment::LIVE && $actor->userId() !== null && osc_notify_new_comment_user()) {
                    osc_run_hook('hook_email_new_comment_user', $mail);
                }
                if (osc_notify_new_comment()) {
                    osc_run_hook('hook_email_new_comment_admin', $mail);
                }
            });
            osc_run_hook('add_comment', $id);

            return new SavedComment($id, $status);
        });
    }

    /**
     * The author deletes their own live comment. Fires `pre_item_delete_comment_post` once the
     * author is confirmed, then on success `delete_comment`.
     *
     * @throws ForbiddenException for a guest, or a comment someone else wrote
     * @throws NotFoundException  for no such comment, or someone else's this user may not read
     * @throws ConflictException  for the author's own comment that is not live
     */
    public function delete(int $commentId, Actor $actor): void
    {
        if ($actor->userId() === null) {
            throw new ForbiddenException(_m('You must be logged in to delete a comment'), ForbiddenException::SIGN_IN);
        }
        $comment = $this->comments->findByPrimaryKey($commentId);
        if (!is_array($comment) || $comment === []) {
            throw new NotFoundException(_m("The comment doesn't exist"));
        }
        $item = $this->items->findByPrimaryKey((int) $comment['fk_i_item_id']);
        if (!CommentPolicy::isAuthor($comment, $actor)) {
            // A comment this user may not read is not there for them, as when reading it.
            if (!CommentPolicy::canView($comment, $item, $actor)) {
                throw new NotFoundException(_m("The comment doesn't exist"));
            }
            throw new ForbiddenException(_m('The comment was not added by you, you cannot delete it'), ForbiddenException::NOT_OWNER);
        }
        osc_run_hook('pre_item_delete_comment_post', $item, $commentId);
        if ((int) $comment['b_active'] !== 1) {
            throw new ConflictException(_m('The comment is not active, you cannot delete it'));
        }

        DeferredMail::transaction(function () use ($commentId): void {
            if ($this->comments->deleteByPrimaryKey($commentId) === false) {
                throw new \RuntimeException('The comment could not be deleted.');
            }
            osc_run_hook('delete_comment', $commentId);
        });
    }

    /**
     * A rating as 1 to 5, or null when none was sent.
     *
     * @throws InvalidException for anything else
     */
    private static function rating(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $whole = match (true) {
            is_int($value)    => $value,
            is_float($value)  => floor($value) === $value ? (int) $value : null,
            is_string($value) => preg_match('/^-?[0-9]{1,6}$/', trim($value)) === 1 ? (int) $value : null,
            default           => null,
        };
        if ($whole === null) {
            throw new InvalidException('/rating', 'type', _m('The rating must be a whole number from 1 to 5'));
        }
        $stars = $whole;
        if ($stars < 1) {
            throw new InvalidException('/rating', 'minimum', _m('The rating must be from 1 to 5'));
        }
        if ($stars > 5) {
            throw new InvalidException('/rating', 'maximum', _m('The rating must be from 1 to 5'));
        }

        return $stars;
    }

    /**
     * LIVE or PENDING by the site's moderation setting, SPAM when Akismet says so of the
     * title and body. The Akismet call runs before the write, so no transaction waits on it.
     *
     * @param array<string,mixed>|null $user
     * @param array<string,mixed>      $item
     */
    private function status(?array $user, string $name, string $email, string $text, array $item, ?string $ip): int
    {
        $threshold = (int) osc_moderate_comments();
        $approved  = (int) ($user['i_comments'] ?? 0);
        $status    = $threshold === -1 || ($threshold !== 0 && $approved >= $threshold) ? SavedComment::LIVE : SavedComment::PENDING;

        if ($text !== '' && osc_akismet_key() && SpamCheck::akismet($name, $email, $text, $ip, osc_item_url_from_item($item))) {
            $status = SavedComment::SPAM;
        }

        return $status;
    }
}
