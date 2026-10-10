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

use mindstellar\database\Db;
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

    /** What a listing write reads: the t_item columns with the location's. */
    private const EDIT_COLUMNS = 'i.pk_i_id, i.fk_i_user_id, i.fk_i_category_id, i.i_price, i.fk_c_currency_code, i.s_contact_phone,'
        . ' i.b_show_email, i.s_secret, i.b_enabled, i.b_active, i.b_spam, i.b_premium, i.dt_expiration,'
        . ' i.s_contact_name, i.s_contact_email,'
        . ' l.fk_c_country_code, l.s_country, l.fk_i_region_id, l.s_region, l.fk_i_city_id, l.s_city, l.s_city_area,'
        . ' l.s_address, l.s_zip, l.d_coord_lat, l.d_coord_long';

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

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * Bare t_item rows, newest first, below $beforeId when given.
     *
     * @param string[] $statuses    names from ListingStatus::ALL; all when empty
     * @param int[]    $userIds     only these sellers; any when empty
     * @param int[]    $categoryIds only these categories; any when empty
     * @param string   $title       keep titles containing this; '' for any
     *
     * @return array<int,array<string,mixed>>
     */
    public function newest(array $statuses, array $userIds, array $categoryIds, string $title, ?int $beforeId, int $limit): array
    {
        return Db::stringifyRows($this->filtered($statuses, $userIds, $categoryIds, $title)->newestBefore($beforeId, $limit));
    }

    /**
     * @param string[] $statuses    names from ListingStatus::ALL; all when empty
     * @param int[]    $userIds     only these sellers; any when empty
     * @param int[]    $categoryIds only these categories; any when empty
     */
    public function count(array $statuses, array $userIds, array $categoryIds, string $title): int
    {
        return $this->filtered($statuses, $userIds, $categoryIds, $title)->count();
    }

    /**
     * @param string[] $statuses
     * @param int[]    $userIds
     * @param int[]    $categoryIds
     */
    private function filtered(array $statuses, array $userIds, array $categoryIds, string $title): QueryBuilder
    {
        $query = ListingStatus::condition($this->table(), $statuses, $this->clock->now());
        if ($userIds !== []) {
            $query = $query->whereIn('fk_i_user_id', $userIds);
        }
        if ($categoryIds !== []) {
            $query = $query->whereIn('fk_i_category_id', $categoryIds);
        }
        if ($title !== '') {
            $query = $query->whereRaw(
                'pk_i_id IN (SELECT fk_i_item_id FROM ' . DB_TABLE_PREFIX . 't_item_description WHERE s_title LIKE ?)',
                ['%' . QueryBuilder::escapeLike($title) . '%']
            );
        }

        return $query;
    }

    /**
     * The listing as a write needs it, one row per language when $withTexts.
     *
     * @return array<int,array<string,mixed>> empty for no such listing
     * @throws \mindstellar\database\DbException
     */
    public function editRows(int $id, bool $withTexts): array
    {
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT ' . self::EDIT_COLUMNS . ($withTexts ? ', d.fk_c_locale_code, d.s_title, d.s_description' : '')
            . ' FROM ' . $p . 't_item i LEFT JOIN ' . $p . 't_item_location l ON l.fk_i_item_id = i.pk_i_id'
            . ($withTexts ? ' LEFT JOIN ' . $p . 't_item_description d ON d.fk_i_item_id = i.pk_i_id' : '')
            . ' WHERE i.pk_i_id = ?';

        return Db::select($sql, [$id]);
    }

    /**
     * Region or city links for the search footer: one representative listing per location
     * group with the group's live listing count.
     *
     * @param int[]    $categoryIds only these categories; any when empty
     * @param int|null $regionId    group a region's cities; regions when null
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function footerLocations(array $categoryIds, ?int $regionId): array
    {
        $where  = array();
        $params = array();

        if ($categoryIds !== array()) {
            $where[] = 'i.fk_i_category_id IN (' . implode(', ', array_fill(0, count($categoryIds), '?')) . ')';
            $params  = array_merge($params, $categoryIds);
        }

        $where[]  = 'i.pk_i_id = l.fk_i_item_id';
        $where[]  = 'i.b_enabled = 1';
        $where[]  = 'i.b_active = 1';
        $where[]  = 'dt_expiration >= ?';
        $params[] = date('Y-m-d H:i:s', $this->clock->now());
        $where[]  = 'l.fk_i_region_id IS NOT NULL';
        $where[]  = 'l.fk_i_city_id IS NOT NULL';

        if ($regionId !== null) {
            $where[]  = 'l.fk_i_region_id = ?';
            $params[] = $regionId;
            $groupBy  = 'l.fk_i_city_id';
        } else {
            $groupBy = 'l.fk_i_region_id';
        }

        // The count is grouped in a subquery that also names one representative listing
        // per group; l.* beside GROUP BY on one column is rejected under ONLY_FULL_GROUP_BY.
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT i.fk_i_category_id, l.*, g.total'
            . ' FROM (SELECT MIN(l.fk_i_item_id) AS rep_id, COUNT(*) AS total'
            . ' FROM ' . $p . 't_item as i, ' . $p . 't_item_location as l'
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY ' . $groupBy . ') AS g'
            . ' JOIN ' . $p . 't_item_location as l ON l.fk_i_item_id = g.rep_id'
            . ' JOIN ' . $p . 't_item as i ON i.pk_i_id = g.rep_id';

        return Db::select($sql, $params);
    }

    /**
     * The bare t_item row, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('pk_i_id', $id)->first();

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * The bare t_item rows of some listings, keyed by id; missing ids are left out.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach ($this->table()->whereIn('pk_i_id', $ids)->get() as $row) {
            $rows[(int) $row['pk_i_id']] = Db::stringifyRow($row);
        }

        return $rows;
    }

    /**
     * Title and description rows of some listings, in one query.
     *
     * @param int[]       $ids
     * @param string|null $locale only this language; every language when null
     *
     * @return array<int,array<string,mixed>> fk_i_item_id, fk_c_locale_code, s_title, s_description
     * @throws \mindstellar\database\DbException
     */
    public function descriptions(array $ids, ?string $locale = null): array
    {
        if ($ids === []) {
            return [];
        }
        $sql    = 'SELECT fk_i_item_id, fk_c_locale_code, s_title, s_description FROM ' . DB_TABLE_PREFIX . 't_item_description'
            . ' WHERE fk_i_item_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
        $params = array_values($ids);
        if ($locale !== null) {
            $sql     .= ' AND fk_c_locale_code = ?';
            $params[] = $locale;
        }

        return Db::stringifyRows(Db::select($sql, $params));
    }

    /**
     * View counters and the location of some listings, in one query.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> the t_item_stats counters with the t_item_location columns
     * @throws \mindstellar\database\DbException
     */
    public function statsAndLocations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $p = DB_TABLE_PREFIX;

        return Db::stringifyRows(Db::select(
            'SELECT s.i_num_views, s.i_num_spam, s.i_num_bad_classified, s.i_num_repeated, s.i_num_offensive,'
            . ' s.i_num_expired, s.i_num_premium_views, l.*'
            . ' FROM ' . $p . 't_item_stats s INNER JOIN ' . $p . 't_item_location l ON s.fk_i_item_id = l.fk_i_item_id'
            . ' WHERE s.fk_i_item_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            array_values($ids)
        ));
    }

    /**
     * Photos of some listings, in upload order, in one query.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> listing photo rows
     * @throws \mindstellar\database\DbException
     */
    public function photos(array $ids): array
    {
        return $ids === [] ? [] : Db::stringifyRows(PhotoStore::ofItems($ids));
    }

    /**
     * The listing table's full name, for SQL fragments the search compiler takes.
     */
    public static function tableName(): string
    {
        return DB_TABLE_PREFIX . 't_item';
    }

    private function table(): QueryBuilder
    {
        return Db::table(self::tableName());
    }
}
