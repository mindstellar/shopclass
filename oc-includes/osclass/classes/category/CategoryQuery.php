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

namespace mindstellar\category;

/**
 * Category reads for admin: rows with their listing counts, enabled or not, and every
 * language's texts.
 */
final class CategoryQuery
{
    /**
     * Category rows with `i_num_items`: one, or all in display order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(?int $id = null): array
    {
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT c.*, s.i_num_items FROM ' . $p . 't_category c LEFT JOIN ' . $p . 't_category_stats s ON s.fk_i_category_id = c.pk_i_id';

        return osc_db_stringify_rows($id === null
            ? osc_db_select($sql . ' ORDER BY c.i_position ASC, c.pk_i_id ASC')
            : osc_db_select($sql . ' WHERE c.pk_i_id = ?', [$id]));
    }

    /**
     * Every language's texts of some categories, in one query.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<int,array<int,array<string,mixed>>> category id => rows by locale
     */
    public function texts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = osc_db_stringify_rows(osc_db_table(DB_TABLE_PREFIX . 't_category_description')
            ->whereIn('fk_i_category_id', array_map('intval', $ids))
            ->orderBy('fk_c_locale_code')
            ->get());
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_category_id']][] = $row;
        }

        return $out;
    }
}
