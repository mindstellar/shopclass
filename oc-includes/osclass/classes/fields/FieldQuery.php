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

namespace mindstellar\fields;

use mindstellar\database\Db;

/**
 * Custom field reads: one field's row, and the values listings hold, joined to their field.
 * The legacy Field model keeps its own methods.
 */
final class FieldQuery
{
    /**
     * @return array<string,mixed>|null the bare t_meta_fields row
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id): ?array
    {
        return Db::table(DB_TABLE_PREFIX . 't_meta_fields')->where('pk_i_id', $id)->first();
    }

    /**
     * One listing's values, each with its field's type.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function listingValues(int $itemId): array
    {
        return Db::select(
            'SELECT m.fk_i_field_id, m.s_multi, m.s_value, f.e_type FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . 't_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE m.fk_i_item_id = ?',
            [$itemId]
        );
    }

    /**
     * The values of several listings joined to their field, in form order.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function valuesOf(array $itemIds): array
    {
        $p = DB_TABLE_PREFIX;

        return Db::select(
            'SELECT im.fk_i_item_id, mf.pk_i_id, im.s_value, im.s_multi, mf.s_name, mf.s_slug, mf.e_type, mf.s_meta'
            . ' FROM ' . $p . 't_item_meta im'
            . ' INNER JOIN ' . $p . 't_meta_fields mf ON mf.pk_i_id = im.fk_i_field_id'
            . ' WHERE im.fk_i_item_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')'
            . ' ORDER BY mf.i_position ASC, mf.pk_i_id ASC',
            $itemIds
        );
    }

    /**
     * Up to ten distinct values of a field on live listings that match a LIKE pattern.
     *
     * @return array<int,array<string,mixed>> rows with value
     * @throws \mindstellar\database\DbException
     */
    public static function suggest(int $fieldId, string $like): array
    {
        return Db::select(
            'SELECT DISTINCT m.s_value AS value FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item i ON i.pk_i_id = m.fk_i_item_id'
            . ' WHERE m.fk_i_field_id = ? AND m.s_value LIKE ?'
            . ' AND i.b_active = 1 AND i.b_enabled = 1 AND i.b_spam = 0'
            . ' ORDER BY m.s_value LIMIT 10',
            array($fieldId, $like)
        );
    }
}
