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
 * Polymorphic resource table.
 *
 * t_resource mirrors the column shape of t_item_resource but replaces the
 * item-specific foreign key with an (s_owner_type, i_owner_id) owner pair, so a
 * single row can belong to an item, a user, a page or a plugin-defined owner
 * type. There is deliberately no foreign key — a polymorphic owner cannot be
 * enforced by MySQL — so referential integrity is handled at the application
 * level.
 *
 * The CREATE is IF NOT EXISTS, so the step is idempotent and safe to re-run
 * after an interrupted upgrade. The same table is declared in
 * installer/struct.sql for a fresh install, which the runner baselines rather
 * than replays; this migration brings an existing install up to the same state.
 */
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_resource ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_owner_type VARCHAR(20) NOT NULL,'
            . ' i_owner_id INT UNSIGNED NOT NULL,'
            . ' s_name VARCHAR(60) NULL,'
            . ' s_extension VARCHAR(10) NULL,'
            . ' s_content_type VARCHAR(40) NULL,'
            . ' s_path VARCHAR(250) NULL,'
            . " s_storage VARCHAR(30) NOT NULL DEFAULT 'local',"
            . ' dt_created DATETIME NOT NULL,'
            . ' dt_updated DATETIME NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_owner (s_owner_type, i_owner_id),'
            . ' INDEX idx_storage (s_storage)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";

        if ($comm->query($sql) === false) {
            throw new RuntimeException('resource migration: failed to create t_resource');
        }
    }
};
