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

namespace mindstellar\form\builder;

use mindstellar\base\Model;

/**
 * t_meta_group_fields: which fields a form holds, and in what order.
 */
final class FormFieldLinkStore extends Model
{
    protected const TABLE = 't_meta_group_fields';

    /**
     * @return int[] the form's field ids, in order
     */
    public static function fieldIds(int $formId): array
    {
        $rows = self::table()
            ->select('fk_i_field_id')
            ->where('fk_i_group_id', $formId)
            ->orderBy('i_position', 'ASC')
            ->get();

        return array_map(static fn ($r) => (int) $r['fk_i_field_id'], $rows);
    }

    /**
     * Replace the form's links with $fieldIds, in that order. The caller runs it in a transaction.
     *
     * @param int[] $fieldIds
     *
     * @throws \mindstellar\database\DbException
     */
    public static function replace(int $formId, array $fieldIds): void
    {
        self::table()->where('fk_i_group_id', $formId)->delete();
        $position = 0;
        foreach ($fieldIds as $fieldId) {
            self::table()->insert(array(
                'fk_i_group_id' => $formId,
                'fk_i_field_id' => $fieldId,
                'i_position'    => $position,
            ));
            $position++;
        }
    }

    /**
     * @return int[] every field that sits in at least one form
     */
    public static function placedFieldIds(): array
    {
        $rows = self::table()
            ->select('fk_i_field_id')
            ->groupBy('fk_i_field_id')
            ->get();

        return array_map(static fn ($r) => (int) $r['fk_i_field_id'], $rows);
    }

    /**
     * How many forms hold this field.
     */
    public static function formCount(int $fieldId): int
    {
        $rows = self::table()
            ->select('fk_i_group_id')
            ->where('fk_i_field_id', $fieldId)
            ->get();

        return count($rows);
    }
}
