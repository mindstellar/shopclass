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

use mindstellar\database\Db;
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

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * A listing's approved comments with an id above $afterId, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function approved(int $listingId, int $afterId, int $limit): array
    {
        return Db::stringifyRows($this->approvedQuery($listingId)->where('pk_i_id', '>', $afterId)->orderBy('pk_i_id')->limit($limit)->get());
    }

    public function countApproved(int $listingId): int
    {
        return $this->approvedQuery($listingId)->count();
    }

    /**
     * Comments newest first, below $beforeId when given.
     *
     * @param string[] $statuses   names from CommentStatus::ALL; all when empty
     * @param int[]    $listingIds only these listings; any when empty
     * @param int[]    $userIds    only these authors; any when empty
     *
     * @return array<int,array<string,mixed>>
     */
    public function newest(array $statuses, array $listingIds, array $userIds, ?int $beforeId, int $limit): array
    {
        return Db::stringifyRows($this->filtered($statuses, $listingIds, $userIds)->newestBefore($beforeId, $limit));
    }

    /**
     * @param string[] $statuses   names from CommentStatus::ALL; all when empty
     * @param int[]    $listingIds only these listings; any when empty
     * @param int[]    $userIds    only these authors; any when empty
     */
    public function count(array $statuses, array $listingIds, array $userIds): int
    {
        return $this->filtered($statuses, $listingIds, $userIds)->count();
    }

    /**
     * @param string[] $statuses
     * @param int[]    $listingIds
     * @param int[]    $userIds
     */
    private function filtered(array $statuses, array $listingIds, array $userIds): QueryBuilder
    {
        $query = CommentStatus::condition($this->table(), $statuses);
        if ($listingIds !== []) {
            $query = $query->whereIn('fk_i_item_id', $listingIds);
        }
        if ($userIds !== []) {
            $query = $query->whereIn('fk_i_user_id', $userIds);
        }

        return $query;
    }

    private function approvedQuery(int $listingId): QueryBuilder
    {
        return CommentStatus::condition($this->table()->where('fk_i_item_id', $listingId), [CommentStatus::ACTIVE]);
    }

    private function table(): QueryBuilder
    {
        return Db::table(DB_TABLE_PREFIX . 't_item_comment');
    }
}
