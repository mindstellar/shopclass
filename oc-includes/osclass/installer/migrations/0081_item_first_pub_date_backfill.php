<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * A NULL dt_first_pub_date now marks a listing held for payment. Fill it on rows a plugin
 * wrote without it, so none of them reads as held; rows with a queued hold delete are held.
 *
 * @title Item first publish date backfill
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $conn->execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_item i SET i.dt_first_pub_date = COALESCE(i.dt_pub_date, NOW())'
            . ' WHERE i.dt_first_pub_date IS NULL AND NOT EXISTS (SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_job_queue j'
            . " WHERE j.s_type = 'listing.unpaid_expire' AND j.s_unique = CAST(i.pk_i_id AS CHAR CHARACTER SET ascii) COLLATE ascii_bin)"
        );
    }
};
