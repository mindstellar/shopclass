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

namespace mindstellar\moderation;

use Item;
use ItemComment;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\ViewScope;
use mindstellar\validation\InvalidException;

/**
 * What an admin does to a comment: approve, hold, block, unblock, edit, delete. Each fires
 * the hook the comments screen has always fired, in one transaction with its e-mails sent
 * after it commits. An approval that changed the row e-mails the author, and so does an
 * unblock that leaves the comment live. The comments screen and the API both call it.
 */
final class CommentModeration
{
    public function __construct(private ItemComment $comments)
    {
    }

    public static function make(): self
    {
        return new self(ItemComment::getInstance());
    }

    /**
     * Approve a comment.
     *
     * @return bool whether the row changed
     */
    public function activate(int $id): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id): bool {
            $updated = (bool) $this->comments->update(['b_active' => 1], ['pk_i_id' => $id]);
            if ($updated) {
                $this->notifyAuthor($id);
            }
            osc_run_hook('activate_comment', $id);

            return $updated;
        });
    }

    /**
     * Hold a comment back.
     *
     * @return bool whether the row changed
     */
    public function deactivate(int $id): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id): bool {
            $updated = (bool) $this->comments->update(['b_active' => 0], ['pk_i_id' => $id]);
            osc_run_hook('deactivate_comment', $id);

            return $updated;
        });
    }

    /**
     * Unblock a comment. The author is told only when it is live now: approved and not spam.
     *
     * @return bool whether the row changed
     */
    public function enable(int $id): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id): bool {
            $updated = (bool) $this->comments->update(['b_enabled' => 1], ['pk_i_id' => $id]);
            $comment = $updated ? $this->comments->findByPrimaryKey($id) : null;
            if (is_array($comment) && (int) ($comment['b_active'] ?? 0) === 1 && (int) ($comment['b_spam'] ?? 0) === 0) {
                $this->notifyAuthor($id);
            }
            osc_run_hook('enable_comment', $id);

            return $updated;
        });
    }

    /**
     * Block a comment.
     *
     * @return bool whether the row changed
     */
    public function disable(int $id): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id): bool {
            $updated = (bool) $this->comments->update(['b_enabled' => 0], ['pk_i_id' => $id]);
            osc_run_hook('disable_comment', $id);

            return $updated;
        });
    }

    /**
     * Change a comment's text and author, stored as plain text as the comment form stores it.
     *
     * @param array{title:string,body:string,author_name:string,author_email:string} $fields
     *
     * @throws InvalidException for an empty body or an author e-mail that is not an address
     */
    public function edit(int $id, array $fields): void
    {
        $row = [
            's_title'        => trim(strip_tags($fields['title'])),
            's_body'         => trim(strip_tags($fields['body'])),
            's_author_name'  => trim(strip_tags($fields['author_name'])),
            's_author_email' => trim(strip_tags($fields['author_email'])),
        ];
        $errors = [];
        if (!osc_validate_email($row['s_author_email'], true)) {
            $errors[] = ['pointer' => '/author_email', 'code' => 'format', 'message' => _m('Email is not correct')];
        }
        if ($row['s_body'] === '') {
            $errors[] = ['pointer' => '/body', 'code' => 'minLength', 'message' => _m('Comment is required')];
        }
        if ($errors !== []) {
            throw InvalidException::all($errors);
        }
        DeferredMail::transaction(function () use ($id, $row): void {
            $this->comments->update($row, ['pk_i_id' => $id]);
            osc_run_hook('edit_comment', $id);
        });
    }

    /**
     * @return bool whether a row was deleted
     */
    public function delete(int $id): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id): bool {
            $deleted = (bool) $this->comments->deleteByPrimaryKey($id);
            osc_run_hook('delete_comment', $id);

            return $deleted;
        });
    }

    /**
     * Tell the author their comment is live: `hook_email_comment_validated`, with the
     * listing exported for the e-mail's template.
     */
    public function notifyAuthor(int $id): void
    {
        $comment = $this->comments->findByPrimaryKey($id);
        if (!is_array($comment) || $comment === []) {
            return;
        }
        $item = Item::getInstance()->findByPrimaryKey((int) $comment['fk_i_item_id']);
        ViewScope::withItem(is_array($item) ? $item : [], static fn () => osc_run_hook('hook_email_comment_validated', $comment));
    }
}
