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

namespace mindstellar\comment;

use mindstellar\database\QueryBuilder;

/**
 * Comment reads: one comment, a listing's approved comments oldest first, and every comment
 * newest first with the moderation screen's filters. Pages are by id.
 */
final class CommentQuery
{
    /**
     * @return array<string,mixed>|null the t_item_comment row
     */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('pk_i_id', $id)->first();

        return $row === null ? null : osc_db_stringify_row($row);
    }

    /**
     * A listing's approved comments with an id above $afterId, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function approved(int $listingId, int $afterId, int $limit): array
    {
        return osc_db_stringify_rows($this->approvedQuery($listingId)->where('pk_i_id', '>', $afterId)->orderBy('pk_i_id')->limit($limit)->get());
    }

    public function countApproved(int $listingId): int
    {
        return $this->approvedQuery($listingId)->count();
    }

    /**
     * Comments newest first, below $beforeId when given.
     *
     * @param string[] $statuses names from CommentStatus::ALL; all when empty
     *
     * @return array<int,array<string,mixed>>
     */
    public function newest(array $statuses, ?int $listingId, ?int $userId, ?int $beforeId, int $limit): array
    {
        return osc_db_stringify_rows($this->filtered($statuses, $listingId, $userId)->newestBefore($beforeId, $limit));
    }

    /**
     * @param string[] $statuses names from CommentStatus::ALL; all when empty
     */
    public function count(array $statuses, ?int $listingId, ?int $userId): int
    {
        return $this->filtered($statuses, $listingId, $userId)->count();
    }

    /**
     * @param string[] $statuses
     */
    private function filtered(array $statuses, ?int $listingId, ?int $userId): QueryBuilder
    {
        $query = CommentStatus::condition($this->table(), $statuses);
        if ($listingId !== null) {
            $query = $query->where('fk_i_item_id', $listingId);
        }
        if ($userId !== null) {
            $query = $query->where('fk_i_user_id', $userId);
        }

        return $query;
    }

    private function approvedQuery(int $listingId): QueryBuilder
    {
        return CommentStatus::condition($this->table()->where('fk_i_item_id', $listingId), [CommentStatus::ACTIVE]);
    }

    private function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . 't_item_comment');
    }
}
