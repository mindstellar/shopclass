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
 * Add t_item_upload_tmp, the ledger of photos uploaded to a listing form before the
 * listing itself is saved.
 *
 * The photo uploader staged this list in $_SESSION, so uploading a photo started a
 * session — an osclass cookie that then defeats reverse-proxy caching for the rest of the
 * visit. The rows here are keyed by an unguessable per-form upload token (carried in a
 * cookie), which lets the "remove photo" action authorise a delete without a session, and
 * lets an abandoned upload be pruned. The temp files themselves are already swept by the
 * hourly cron; this only tracks which token owns which file.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, so re-running after an interrupted upgrade
 * leaves an existing table alone.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item_upload_tmp';

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_token VARCHAR(64) NOT NULL DEFAULT \'\','
            . ' s_uuid VARCHAR(191) NOT NULL DEFAULT \'\','
            . ' s_file VARCHAR(191) NOT NULL DEFAULT \'\','
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_token (s_token),'
            . ' INDEX idx_date (dt_date)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );
    }
};
