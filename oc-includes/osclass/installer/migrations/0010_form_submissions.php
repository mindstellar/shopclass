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
 * Form submissions — the polymorphic sink for forms placed off an item.
 *
 * A form rendered on an item (the publish/search flow) writes t_item_meta because
 * the item is the record. A form placed on a page or in a layout (the core.form
 * widget) has no item, so its entries land here instead:
 *
 *   t_form_submission        one entry: which form, which context (page/widget/…),
 *                            who/when, and a triage status
 *   t_form_submission_value  the per-field values, shaped exactly like t_item_meta
 *                            (fk_i_field_id, s_value, s_multi) so field types
 *                            serialize identically to both sinks
 *
 * The (s_context_type, i_context_id) pair mirrors t_resource's polymorphic owner
 * (installer/migrations/0004_resource_table.php) — no FK on the context, since a
 * polymorphic owner cannot be enforced by MySQL; a submission survives its page
 * being deleted (the entry is the valuable part). The value table DOES cascade on
 * its submission.
 *
 * Purely additive: nothing reads these tables until the submit route (Phase 3)
 * exists, and t_item_meta is untouched. CREATE IF NOT EXISTS keeps the step
 * idempotent; the same tables are declared in struct.sql for a fresh install.
 */
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        $submission = DB_TABLE_PREFIX . 't_form_submission';
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $submission . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_group_id INT UNSIGNED NOT NULL,'
            . ' s_context_type VARCHAR(20) NOT NULL,'
            . ' i_context_id INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' fk_i_user_id INT UNSIGNED NULL DEFAULT NULL,'
            . ' s_ip VARCHAR(45) NULL DEFAULT NULL,'
            . " s_status VARCHAR(20) NOT NULL DEFAULT 'new',"
            . ' dt_created DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_form (fk_i_group_id, dt_created),'
            . ' INDEX idx_context (s_context_type, i_context_id),'
            . ' INDEX idx_status (s_status)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";
        if ($comm->query($sql) === false) {
            throw new RuntimeException('form submissions migration: failed to create t_form_submission');
        }

        $value = DB_TABLE_PREFIX . 't_form_submission_value';
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $value . ' ('
            . ' fk_i_submission_id INT UNSIGNED NOT NULL,'
            . ' fk_i_field_id INT UNSIGNED NOT NULL,'
            . ' s_value TEXT NULL,'
            . " s_multi VARCHAR(20) NOT NULL DEFAULT '',"
            . ' PRIMARY KEY (fk_i_submission_id, fk_i_field_id, s_multi),'
            . ' INDEX idx_field (fk_i_field_id),'
            . ' FOREIGN KEY (fk_i_submission_id) REFERENCES ' . $submission . ' (pk_i_id) ON DELETE CASCADE,'
            . ' FOREIGN KEY (fk_i_field_id) REFERENCES ' . DB_TABLE_PREFIX . 't_meta_fields (pk_i_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";
        if ($comm->query($sql) === false) {
            throw new RuntimeException('form submissions migration: failed to create t_form_submission_value');
        }
    }
};
