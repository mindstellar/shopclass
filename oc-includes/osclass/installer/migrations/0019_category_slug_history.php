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
 * Add t_category_slug_history, mapping a category's former slugs back to its id so a
 * renamed category's old inbound links can 301 to the current URL instead of 404ing.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, so re-running after an interrupted upgrade
 * leaves an existing table alone.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_category_slug_history';

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . ' fk_i_category_id INT UNSIGNED NOT NULL,'
            . ' fk_c_locale_code CHAR(5) NOT NULL DEFAULT \'\','
            . ' s_slug VARCHAR(191) NOT NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (s_slug, fk_c_locale_code),'
            . ' INDEX idx_hist_cat (fk_i_category_id),'
            . ' FOREIGN KEY (fk_i_category_id) REFERENCES ' . DB_TABLE_PREFIX . 't_category (pk_i_id)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );
    }
};
