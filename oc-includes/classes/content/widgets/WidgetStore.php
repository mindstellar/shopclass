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
     * Reorder the widgets of one location; ids that do not live there are dropped, so a
     * forged post cannot move widgets from elsewhere.
     *
     * @param array<mixed> $ids
     */
    public static function reorderWithin(string $location, array $ids): bool
    {
        try {
            $valid = array_flip(self::idsAt($location));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
        $ids = array_map('intval', array_filter($ids, 'is_numeric'));

        return self::reorder(array_values(array_filter($ids, static fn (int $id): bool => isset($valid[$id]))));
    }

    /**
     * Make one location hold exactly $rows, in that order, in one transaction. A row whose id
     * already lives there is updated in place; any other row is inserted; rows left out are
     * deleted. Other locations are never touched.
     *
     * @param array<int,array{id?:int|null,type:string,config:array<string,mixed>,description:string}> $rows
     *
     * @throws \Throwable when the write fails; nothing is changed
     */
    public static function replaceLocation(string $location, array $rows): void
    {
        try {
            Db::transaction(static function () use ($location, $rows): void {
                $existing = array_flip(self::idsAt($location));
                $kept     = array();
                foreach (array_values($rows) as $position => $row) {
                    $values = array(
                        's_description' => (string) $row['description'],
                        's_content'     => '',
                        's_type'        => (string) $row['type'],
                        's_config'      => json_encode($row['config'], JSON_THROW_ON_ERROR),
                        'i_order'       => $position,
                    );
                    $id = (int) ($row['id'] ?? 0);
                    if ($id > 0 && isset($existing[$id]) && !isset($kept[$id])) {
                        self::table()->where('pk_i_id', $id)->update($values);
                        $kept[$id] = true;
                        continue;
                    }
                    self::table()->insert($values + array('s_location' => $location, 'e_kind' => 'html'));
                }
                $dropped = array_keys(array_diff_key($existing, $kept));
                if ($dropped !== array()) {
                    self::table()->whereIn('pk_i_id', $dropped)->delete();
                }
            });
        } finally {
            CacheGroup::invalidate('widget');
        }
    }

    /**
     * A fingerprint of one location's rows, so a draft can tell when the live rows changed under it.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function locationHash(string $location): string
    {
        $rows = self::table()->select('pk_i_id', 's_type', 's_description', 's_config', 'i_order')
            ->where('s_location', $location)->orderBy('pk_i_id')->get();

        return md5((string) json_encode(array_map(array(Db::class, 'stringifyRow'), $rows)));
    }

    /**
     * Flip a row's hide switch (WidgetConfig::ENABLED) and store it.
     *
     * @return bool the row's new state: true when shown
     * @throws \mindstellar\database\DbException
     */
    public static function toggleEnabled(int $id): bool
    {
        $row     = self::find($id);
        $config  = $row === null ? array() : json_decode((string) $row['s_config'], true);
        $config  = is_array($config) ? $config : array();
        $enabled = !WidgetConfig::enabled($config);
        $config[WidgetConfig::ENABLED] = $enabled;
        self::update($id, array('s_config' => json_encode($config)));

        return $enabled;
    }

    /**
     * $config with the stored row's hide switch carried over, so editing a hidden row keeps it hidden.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    public static function keepEnabled(int $id, array $config): array
    {
        try {
            $row = self::find($id);
        } catch (\Throwable $e) {
            $row = null;
        }
        $stored = $row !== null ? json_decode((string) $row['s_config'], true) : null;
        if (is_array($stored) && array_key_exists(WidgetConfig::ENABLED, $stored)) {
            $config[WidgetConfig::ENABLED] = $stored[WidgetConfig::ENABLED] !== false;
        }

        return $config;
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
