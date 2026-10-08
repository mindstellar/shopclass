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

namespace mindstellar\widgets;

use mindstellar\base\Model;
use mindstellar\cache\CacheGroup;
use mindstellar\database\Db;

/**
 * t_widget writes for the Appearance > Widgets screen and page deletes. Every write drops the
 * 'widget' cache group, as the legacy Widget model does.
 */
final class WidgetStore extends Model
{
    protected const TABLE = 't_widget';

    /**
     * One widget row, or null when the id matches nothing.
     *
     * @return array<string,string|null>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id): ?array
    {
        $row = self::table()->where('pk_i_id', $id)->first();

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * Ids of the widgets in one location, read uncached so a move just made is seen.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public static function idsAt(string $location): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['pk_i_id'],
            self::table()->select('pk_i_id')->where('s_location', $location)->get()
        );
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return int the new widget id
     * @throws \mindstellar\database\DbException
     */
    public static function add(array $row): int
    {
        try {
            return (int) self::table()->insert($row);
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return int rows changed; 0 when nothing differs
     * @throws \mindstellar\database\DbException
     */
    public static function update(int $id, array $values): int
    {
        try {
            return self::table()->where('pk_i_id', $id)->update($values);
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function delete(int $id): int
    {
        try {
            return self::table()->where('pk_i_id', $id)->delete();
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * Remove every widget in one location, such as the blocks of a deleted page.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function deleteByLocation(string $location): int
    {
        try {
            return self::table()->where('s_location', $location)->delete();
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function moveTo(int $id, string $location): void
    {
        try {
            self::table()->where('pk_i_id', $id)->update(array('s_location' => $location));
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * Set i_order to each id's position in $orderedIds, in one transaction.
     *
     * @param int[] $orderedIds
     *
     * @return bool false when the transaction rolled back
     */
    public static function reorder(array $orderedIds): bool
    {
        try {
            Db::transaction(static function () use ($orderedIds): void {
                foreach (array_values($orderedIds) as $position => $id) {
                    self::table()->where('pk_i_id', (int) $id)->update(array('i_order' => $position));
                }
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * MAX(i_order) + 1 for a location, or 0 when it holds no widgets.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function nextOrder(string $location): int
    {
        $max = Db::scalar('SELECT MAX(i_order) FROM ' . self::tableName() . ' WHERE s_location = ?', array($location));

        return $max === null ? 0 : (int) $max + 1;
    }
}
