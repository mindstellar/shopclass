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
 * Rebuild the dependent foreign keys with ON DELETE CASCADE, matching the referential
 * policy now declared at the top of installer/struct.sql.
 *
 * These keys were created without any ON DELETE clause, so they defaulted to RESTRICT:
 * every dependent row had to be removed by the model before the parent could go. That
 * worked only as long as each delete path remembered every child. When one was missed
 * -- t_meta_categories on a category delete, t_form_submission_value on a custom field
 * delete -- the parent delete failed while the children the model *had* remembered were
 * already gone, leaving a half-erased row that could never be deleted.
 *
 * Only the tables whose rows are meaningless without the parent are converted. Children
 * that are entities in their own right keep RESTRICT, because their removal has side
 * effects (files on disk, counter updates, lifecycle hooks) that only the model can
 * perform; see the struct.sql note.
 *
 * Constraint names are not hard-coded. These keys were declared anonymously, so MySQL
 * generated `<table>_ibfk_<n>` names whose numbering depends on the order the keys were
 * created in -- which differs between a fresh install and one upgraded across versions.
 * Each is resolved from information_schema by (table, column, referenced table) instead.
 *
 * Idempotent: a key already carrying ON DELETE CASCADE is skipped, and a key that has
 * been dropped entirely on some install is simply re-created.
 */
return new class () implements MigrationInterface {
    /**
     * Child table, its FK column, and the parent it references. Order is irrelevant --
     * each entry is rebuilt independently.
     */
    private const CASCADE_KEYS = array(
        array('t_user_description', 'fk_i_user_id', 't_user', 'pk_i_id'),
        array('t_user_description', 'fk_c_locale_code', 't_locale', 'pk_c_code'),
        array('t_user_email_tmp', 'fk_i_user_id', 't_user', 'pk_i_id'),
        array('t_category_description', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_category_description', 'fk_c_locale_code', 't_locale', 'pk_c_code'),
        array('t_category_stats', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_category_slug_history', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_item_location', 'fk_i_item_id', 't_item', 'pk_i_id'),
        array('t_item_stats', 'fk_i_item_id', 't_item', 'pk_i_id'),
        array('t_item_meta', 'fk_i_item_id', 't_item', 'pk_i_id'),
        array('t_item_meta', 'fk_i_field_id', 't_meta_fields', 'pk_i_id'),
        array('t_pages_description', 'fk_i_pages_id', 't_pages', 'pk_i_id'),
        array('t_pages_description', 'fk_c_locale_code', 't_locale', 'pk_c_code'),
        array('t_meta_group_categories', 'fk_i_group_id', 't_meta_group', 'pk_i_id'),
        array('t_meta_group_categories', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_meta_group_fields', 'fk_i_group_id', 't_meta_group', 'pk_i_id'),
        array('t_meta_group_fields', 'fk_i_field_id', 't_meta_fields', 'pk_i_id'),
        array('t_meta_categories', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_meta_categories', 'fk_i_field_id', 't_meta_fields', 'pk_i_id'),
        array('t_form_submission_value', 'fk_i_field_id', 't_meta_fields', 'pk_i_id'),
        array('t_plugin_category', 'fk_i_category_id', 't_category', 'pk_i_id'),
        array('t_country_stats', 'fk_c_country_code', 't_country', 'pk_c_code'),
        array('t_region_stats', 'fk_i_region_id', 't_region', 'pk_i_id'),
        array('t_city_stats', 'fk_i_city_id', 't_city', 'pk_i_id'),
    );

    public function up(Connection $conn): void
    {
        foreach (self::CASCADE_KEYS as $key) {
            list($childName, $column, $parentName, $parentColumn) = $key;

            $child  = DB_TABLE_PREFIX . $childName;
            $parent = DB_TABLE_PREFIX . $parentName;

            if (!$this->tableExists($conn, $child) || !$this->tableExists($conn, $parent)) {
                // A table a bundled plugin was expected to create may be absent.
                continue;
            }

            $existing = $this->constraintNames($conn, $child, $column, $parent);

            // Already exactly one key, already cascading: nothing to do. More than one
            // means an earlier reconcile appended a duplicate, so rebuild regardless.
            if (count($existing) === 1 && $this->isCascade($conn, $child, $existing[0])) {
                continue;
            }

            foreach ($existing as $name) {
                $conn->execute('ALTER TABLE ' . $child . ' DROP FOREIGN KEY ' . $name);
            }

            // A row whose parent is already gone cannot exist while the key is enforced,
            // but SchemaReconciler and the utf8mb4 migration both run with
            // FOREIGN_KEY_CHECKS off, so an interrupted run can leave one behind. It
            // would block the ADD below, and it is exactly the row the new cascade
            // would have removed, so clear it rather than halt the upgrade.
            $conn->execute(
                'DELETE c FROM ' . $child . ' c'
                . ' LEFT JOIN ' . $parent . ' p ON c.' . $column . ' = p.' . $parentColumn
                . ' WHERE c.' . $column . ' IS NOT NULL AND p.' . $parentColumn . ' IS NULL'
            );

            $conn->execute(
                'ALTER TABLE ' . $child
                . ' ADD FOREIGN KEY (' . $column . ')'
                . ' REFERENCES ' . $parent . ' (' . $parentColumn . ') ON DELETE CASCADE'
            );
        }
    }

    /**
     * Whether $table exists in the current database.
     */
    private function tableExists(Connection $conn, string $table): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table)
        );

        return (int) $count > 0;
    }

    /**
     * Whether the named foreign key deletes in cascade.
     */
    private function isCascade(Connection $conn, string $table, string $constraint): bool
    {
        $rule = $conn->scalar(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ?'
            . '   AND CONSTRAINT_NAME = ?',
            array($table, $constraint)
        );

        return $rule === 'CASCADE';
    }

    /**
     * Generated names of every foreign key on $table.$column pointing at $parent. More
     * than one means a previous schema reconcile appended a duplicate instead of
     * replacing the key.
     *
     * @return string[]
     */
    private function constraintNames(Connection $conn, string $table, string $column, string $parent): array
    {
        $rows = $conn->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ?'
            . '   AND COLUMN_NAME = ?'
            . '   AND REFERENCED_TABLE_NAME = ?'
            . ' ORDER BY CONSTRAINT_NAME',
            array($table, $column, $parent)
        );

        $names = array();
        foreach ($rows as $row) {
            $names[] = (string) $row['CONSTRAINT_NAME'];
        }

        return $names;
    }
};
