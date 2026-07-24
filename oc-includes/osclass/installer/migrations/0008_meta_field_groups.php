<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Custom-field groups (reusable forms).
 *
 * A group is a named, ordered, reusable set of custom fields attached to
 * categories as a unit and rendered as a form section. This adds:
 *
 *   - t_meta_group             the group itself (name, slug, position, s_meta)
 *   - t_meta_group_categories  which categories a group applies to (inherited
 *                              down the category tree like loose fields)
 *   - t_meta_fields.fk_i_group_id  a field's group membership (NULL = loose)
 *
 * Every change is additive and back-compatible: existing fields keep
 * fk_i_group_id NULL and continue to resolve through t_meta_categories exactly
 * as before (the "Ungrouped" section is virtual — no seed row is needed).
 *
 * Each step is guarded (CREATE IF NOT EXISTS, information_schema column check) so
 * the migration is idempotent and safe to re-run after an interrupted upgrade.
 * The same objects are declared in installer/struct.sql for a fresh install,
 * which the runner baselines rather than replays.
 */
return new class implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $group = DB_TABLE_PREFIX . 't_meta_group';
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $group . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_name VARCHAR(255) NOT NULL,'
            . ' s_slug VARCHAR(255) NOT NULL,'
            . ' i_position INT(2) UNSIGNED NOT NULL DEFAULT 0,'
            . ' s_meta MEDIUMTEXT NULL DEFAULT NULL,'
            . ' PRIMARY KEY (pk_i_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";
        $conn->execute($sql);

        $groupCat = DB_TABLE_PREFIX . 't_meta_group_categories';
        // Foreign keys mirror t_meta_categories and struct.sql so a fresh install and
        // an upgraded install converge on the same schema (schema-drift check).
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $groupCat . ' ('
            . ' fk_i_group_id INT UNSIGNED NOT NULL,'
            . ' fk_i_category_id INT UNSIGNED NOT NULL,'
            . ' PRIMARY KEY (fk_i_group_id, fk_i_category_id),'
            . ' INDEX idx_group_cat_category (fk_i_category_id),'
            . ' FOREIGN KEY (fk_i_group_id) REFERENCES ' . $group . ' (pk_i_id),'
            . ' FOREIGN KEY (fk_i_category_id) REFERENCES ' . DB_TABLE_PREFIX . 't_category (pk_i_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";
        $conn->execute($sql);

        $fields = DB_TABLE_PREFIX . 't_meta_fields';
        if (!$this->columnExists($conn, $fields, 'fk_i_group_id')) {
            $sql = 'ALTER TABLE ' . $fields . ' ADD COLUMN fk_i_group_id INT UNSIGNED NULL DEFAULT NULL';
            $conn->execute($sql);
        }
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return (int) $count > 0;
    }
};
