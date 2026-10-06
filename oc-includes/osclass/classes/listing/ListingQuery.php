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

namespace mindstellar\listing;

use mindstellar\database\QueryBuilder;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;

/**
 * Listing reads in any status: one listing's status columns, and listings newest first by
 * status, seller, category or title, paged by id. A seller's own list and the moderation
 * list both read here.
 */
final class ListingQuery
{
    /** The columns ListingStatus and ListingPolicy read. */
    private const STATUS_COLUMNS = ['pk_i_id', 'fk_i_user_id', 'b_enabled', 'b_active', 'b_spam', 'b_premium', 'dt_expiration'];

    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @return array<string,mixed>|null the listing's status columns, or null for no such listing
     */
    public function statusRow(int $id): ?array
    {
        $row = $this->table()->select(...self::STATUS_COLUMNS)->where('pk_i_id', $id)->first();

        return $row === null ? null : osc_db_stringify_row($row);
    }

    /**
     * Bare t_item rows, newest first, below $beforeId when given.
     *
     * @param string[] $statuses names from ListingStatus::ALL; all when empty
     * @param string   $title    keep titles containing this; '' for any
     *
     * @return array<int,array<string,mixed>>
     */
    public function newest(array $statuses, ?int $userId, ?int $categoryId, string $title, ?int $beforeId, int $limit): array
    {
        $query = $this->filtered($statuses, $userId, $categoryId, $title);
        if ($beforeId !== null) {
            $query = $query->where('pk_i_id', '<', $beforeId);
        }

        return osc_db_stringify_rows($query->orderBy('pk_i_id', 'DESC')->limit($limit)->get());
    }

    /**
     * @param string[] $statuses names from ListingStatus::ALL; all when empty
     */
    public function count(array $statuses, ?int $userId, ?int $categoryId, string $title): int
    {
        return $this->filtered($statuses, $userId, $categoryId, $title)->count();
    }

    /**
     * @param string[] $statuses
     */
    private function filtered(array $statuses, ?int $userId, ?int $categoryId, string $title): QueryBuilder
    {
        $query = ListingStatus::condition($this->table(), $statuses, $this->clock->now());
        if ($userId !== null) {
            $query = $query->where('fk_i_user_id', $userId);
        }
        if ($categoryId !== null) {
            $query = $query->where('fk_i_category_id', $categoryId);
        }
        if ($title !== '') {
            $query = $query->whereRaw(
                'pk_i_id IN (SELECT fk_i_item_id FROM ' . DB_TABLE_PREFIX . 't_item_description WHERE s_title LIKE ?)',
                ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $title) . '%']
            );
        }

        return $query;
    }

    private function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . 't_item');
    }
}
