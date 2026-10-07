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
 * Small t_item_resource reads and writes for listing photos. The legacy ItemResource
 * model keeps its own methods.
 */
final class PhotoStore extends Model
{
    protected const TABLE = 't_item_resource';

    /**
     * Delete one photo of one listing.
     *
     * @return int rows deleted
     * @throws \mindstellar\database\DbException
     */
    public static function delete(int $photoId, int $itemId): int
    {
        return self::table()
            ->where('pk_i_id', $photoId)
            ->where('fk_i_item_id', $itemId)
            ->delete();
    }

    /**
     * Every photo of these listings, in upload order.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function ofItems(array $itemIds): array
    {
        return self::table()->whereIn('fk_i_item_id', $itemIds)->orderBy('pk_i_id')->get();
    }

    /**
     * The listing's first image, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function firstImage(int $itemId): ?array
    {
        $rows = Db::select(
            'SELECT pk_i_id, s_path, s_extension, s_content_type, s_storage FROM '
            . self::tableName() . " WHERE fk_i_item_id = ? AND s_content_type LIKE 'image/%' "
            . 'ORDER BY pk_i_id ASC LIMIT 1',
            array($itemId)
        );

        return $rows === [] ? null : $rows[0];
    }

    /**
     * How many photos sit in this storage, e.g. 's3'.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function countIn(string $storage): int
    {
        return self::table()->where('s_storage', $storage)->count();
    }
}
