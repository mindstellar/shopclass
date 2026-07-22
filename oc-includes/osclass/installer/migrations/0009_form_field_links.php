<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\migration\MigrationInterface;

/**
 * Field ↔ form membership becomes many-to-many.
 *
 * Until now a field belonged to at most one form (group) via the single column
 * t_meta_fields.fk_i_group_id. To let one field live in any number of forms (the
 * palette / drag-into-form model), membership moves to a link table:
 *
 *   t_meta_group_fields(fk_i_group_id, fk_i_field_id, i_position)
 *
 * i_position is the field's order WITHIN that form, so the same field can sit at
 * different positions in different forms. The link table is the new source of
 * truth for grouped-field resolution.
 *
 * Back-compat: this migration back-fills one link row per field that currently has
 * a non-NULL fk_i_group_id, copying its i_position. The old column is left in place
 * (nullable, no longer read) purely as rollback insurance for one release cycle —
 * the resolvers still produce identical output, so nothing on a live install
 * changes. Loose fields (fk_i_group_id already NULL, assigned via t_meta_categories)
 * are untouched and keep resolving into the "Ungrouped" section.
 *
 * Each step is guarded (CREATE IF NOT EXISTS, INSERT … WHERE NOT EXISTS) so the
 * migration is idempotent and safe to re-run after an interrupted upgrade. The same
 * table is declared in installer/struct.sql for a fresh install, which the runner
 * baselines rather than replays.
 */
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        $link = DB_TABLE_PREFIX . 't_meta_group_fields';
        $sql  = 'CREATE TABLE IF NOT EXISTS ' . $link . ' ('
            . ' fk_i_group_id INT UNSIGNED NOT NULL,'
            . ' fk_i_field_id INT UNSIGNED NOT NULL,'
            . ' i_position INT(2) UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (fk_i_group_id, fk_i_field_id),'
            . ' INDEX idx_group_fields_field (fk_i_field_id),'
            . ' FOREIGN KEY (fk_i_group_id) REFERENCES ' . DB_TABLE_PREFIX . 't_meta_group (pk_i_id),'
            . ' FOREIGN KEY (fk_i_field_id) REFERENCES ' . DB_TABLE_PREFIX . 't_meta_fields (pk_i_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";
        if ($comm->query($sql) === false) {
            throw new RuntimeException('form field links migration: failed to create t_meta_group_fields');
        }

        // Back-fill one link row per currently-grouped field. WHERE NOT EXISTS keeps
        // the step idempotent if a partial run already inserted some rows.
        $fields = DB_TABLE_PREFIX . 't_meta_fields';
        $sql = 'INSERT INTO ' . $link . ' (fk_i_group_id, fk_i_field_id, i_position)'
            . ' SELECT mf.fk_i_group_id, mf.pk_i_id, mf.i_position FROM ' . $fields . ' mf'
            . ' WHERE mf.fk_i_group_id IS NOT NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $link . ' gf'
            . '   WHERE gf.fk_i_group_id = mf.fk_i_group_id AND gf.fk_i_field_id = mf.pk_i_id)';
        if ($comm->query($sql) === false) {
            throw new RuntimeException('form field links migration: failed to back-fill t_meta_group_fields');
        }
    }
};
