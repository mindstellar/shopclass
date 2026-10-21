<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Read and seed t_item_facet rows by their key and value, as the term table names them.
 * facet_rows_sql() is a derived table with s_key, s_value, fk_i_item_id, fk_i_variant_id and
 * d_number (NULL on a text row); facet_put() writes a row; facet_retag() points a key's rows at
 * another value. Needs DB_TABLE_PREFIX.
 */

if (!function_exists('facet_rows_sql')) {
    function facet_rows_sql(): string
    {
        $p = DB_TABLE_PREFIX;

        return "(SELECT t.s_key, t.s_value, f.fk_i_item_id, f.fk_i_variant_id, IF(t.s_value = '', f.d_number, NULL) AS d_number"
            . " FROM {$p}t_item_facet f JOIN {$p}t_facet_term t ON t.pk_i_id = f.fk_i_term_id)";
    }

    /**
     * Write one row, making its term when missing; a row already there is kept. A number row has value ''.
     */
    function facet_put(mysqli $db, string $key, string $value, int $item, int $variant = 0, float $number = 0.0): void
    {
        $p    = DB_TABLE_PREFIX;
        $stmt = $db->prepare("INSERT IGNORE INTO {$p}t_facet_term (s_key, s_value) VALUES (?, ?)");
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt = $db->prepare("INSERT IGNORE INTO {$p}t_item_facet (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id)"
            . " SELECT pk_i_id, ?, ?, ? FROM {$p}t_facet_term WHERE s_key = ? AND s_value = ?");
        $stmt->bind_param('diiss', $number, $item, $variant, $key, $value);
        $stmt->execute();
    }

    /**
     * Point the rows of $key that match $where (on t_item_facet f) at the term ($key, $value).
     */
    function facet_retag(mysqli $db, string $key, string $value, string $where = '1'): void
    {
        $p = DB_TABLE_PREFIX;
        $k = $db->real_escape_string($key);
        $v = $db->real_escape_string($value);
        $db->query("INSERT IGNORE INTO {$p}t_facet_term (s_key, s_value) VALUES ('$k', '$v')");
        $db->query("UPDATE {$p}t_item_facet f JOIN {$p}t_facet_term o ON o.pk_i_id = f.fk_i_term_id"
            . " JOIN {$p}t_facet_term n ON n.s_key = '$k' AND n.s_value = '$v'"
            . " SET f.fk_i_term_id = n.pk_i_id WHERE o.s_key = '$k' AND ($where)");
    }
}
