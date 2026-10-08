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

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Small t_item reads and writes for the listing services. The legacy Item model keeps
 * its own methods; ListingQuery holds the paged lists.
 */
final class ListingStore extends Model
{
    protected const TABLE = 't_item';

    /**
     * One listing's columns, or null.
     *
     * @param string[] $columns
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id, array $columns): ?array
    {
        return self::table()->select(...$columns)->where('pk_i_id', $id)->first();
    }

    /**
     * The listing's owner, 0 for a guest listing or no listing.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function ownerId(int $id): int
    {
        return (int) Db::scalar('SELECT fk_i_user_id FROM ' . self::tableName() . ' WHERE pk_i_id = ?', array($id));
    }

    /**
     * The columns a premium change decides on, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function premiumState(int $id): ?array
    {
        return Db::selectOne(
            'SELECT b_premium, dt_premium_expiration, b_enabled, b_active, b_spam, dt_expiration FROM '
            . self::tableName() . ' WHERE pk_i_id = ?',
            array($id)
        );
    }

    /**
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function setPubDate(int $id, string $at): int
    {
        return self::table()->where('pk_i_id', $id)->update(['dt_pub_date' => $at]);
    }

    /**
     * Dated premium upgrades that ended by $now.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function endedPremium(string $now): array
    {
        return Db::select(
            'SELECT pk_i_id, b_enabled, b_active, b_spam, b_premium, dt_expiration FROM ' . self::tableName()
            . ' WHERE b_premium = 1 AND dt_premium_expiration IS NOT NULL AND dt_premium_expiration <= ?',
            array($now)
        );
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function clearPremium(array $ids): void
    {
        self::table()
            ->whereIn('pk_i_id', $ids)
            ->update(array('b_premium' => 0, 'dt_premium_expiration' => null));
    }

    /**
     * @param int[] $categoryIds
     *
     * @throws \mindstellar\database\DbException
     */
    public static function countInCategories(array $categoryIds): int
    {
        return self::table()->whereIn('fk_i_category_id', $categoryIds)->count();
    }

    /**
     * The first $limit listing ids in these categories, lowest first.
     *
     * @param int[] $categoryIds
     *
     * @return array<int,array<string,mixed>> rows with pk_i_id
     * @throws \mindstellar\database\DbException
     */
    public static function idsInCategories(array $categoryIds, int $limit): array
    {
        return self::table()
            ->select('pk_i_id')
            ->whereIn('fk_i_category_id', $categoryIds)
            ->orderBy('pk_i_id', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * The listing's t_item_location row, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function location(int $id): ?array
    {
        return Db::table(DB_TABLE_PREFIX . 't_item_location')->where('fk_i_item_id', $id)->first();
    }

    /**
     * Store looked-up coordinates, unless the listing got its own in the meantime.
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function setCoordinates(int $id, float $lat, float $lng): int
    {
        return Db::execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_item_location SET d_coord_lat = ?, d_coord_long = ? WHERE fk_i_item_id = ?'
            . ' AND (d_coord_lat IS NULL OR d_coord_lat = 0 OR d_coord_long IS NULL OR d_coord_long = 0)',
            array($lat, $lng, $id)
        );
    }

    /**
     * Whether any listing is priced in this currency.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function usesCurrency(string $code): bool
    {
        return self::table()->where('fk_c_currency_code', $code)->count() > 0;
    }
}
