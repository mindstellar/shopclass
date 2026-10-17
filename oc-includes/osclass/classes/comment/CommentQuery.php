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
 * Comment reads: one comment, a listing's approved comments, their ratings, and every comment
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
     * A listing's approved comments past $afterId (0 for the first page): oldest first, or newest
     * first when $newest, where $afterId is then the id to go below.
     *
     * @return array<int,array<string,mixed>>
     */
    public function approved(int $listingId, int $afterId, int $limit, bool $newest = false): array
    {
        $query = $this->approvedQuery($listingId);
        if ($newest) {
            return Db::stringifyRows($query->newestBefore($afterId > 0 ? $afterId : null, $limit));
        }

        return Db::stringifyRows($query->where('pk_i_id', '>', $afterId)->orderBy('pk_i_id')->limit($limit)->get());
    }

    public function countApproved(int $listingId): int
    {
        return $this->approvedQuery($listingId)->count();
    }

    /**
     * The rated, approved comments of these listings: listing id => the number of ratings and
     * their average to one decimal (null when there is none). Listings with no rating are
     * answered with a zero count. One query on the listing index.
     *
     * @param int[] $listingIds
     *
     * @return array<int,array{count:int,average:?float}>
     */
    public function summary(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));
        $out        = [];
        foreach ($listingIds as $id) {
            $out[$id] = ['count' => 0, 'average' => null];
        }
        if ($listingIds === []) {
            return $out;
        }
        $rows = CommentStatus::condition($this->table()->whereIn('fk_i_item_id', $listingIds), [CommentStatus::ACTIVE])
            ->whereNotNull('i_rating')
            ->select('fk_i_item_id')
            ->selectRaw('COUNT(*) AS n, SUM(i_rating) AS total')
            ->groupBy('fk_i_item_id')
            ->get();
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $out[(int) $row['fk_i_item_id']] = ['count' => $n, 'average' => $n > 0 ? round((int) $row['total'] / $n, 1) : null];
        }

        return $out;
    }

    /**
     * How many approved comments gave each rating: stars 5 down to 1 => count, zeros included.
     * One query on the listing index.
     *
     * @return array<int,int>
     */
    public function distribution(int $listingId): array
    {
        $out  = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $rows = $this->approvedQuery($listingId)->whereNotNull('i_rating')->select('i_rating')->selectRaw('COUNT(*) AS n')->groupBy('i_rating')->get();
        foreach ($rows as $row) {
            $stars = (int) $row['i_rating'];
            if (isset($out[$stars])) {
                $out[$stars] = (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * Whether the user already has a rated comment on the listing that is not blocked or spam,
     * live or waiting: one rated comment per user per listing.
     */
    public function hasRated(int $listingId, int $userId): bool
    {
        return $this->table()
            ->where('fk_i_item_id', $listingId)
            ->where('fk_i_user_id', $userId)
            ->whereNotNull('i_rating')
            ->where('b_enabled', 1)
            ->where('b_spam', 0)
            ->select('pk_i_id')
            ->limit(1)
            ->first() !== null;
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
