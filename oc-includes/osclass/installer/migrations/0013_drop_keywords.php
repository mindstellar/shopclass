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
 * Drop the legacy t_keywords table.
 *
 * t_keywords was a search-keyword index from the original Osclass schema.
 * Nothing populates or reads it any more — the search path uses
 * t_latest_searches — so on existing installs it only ever held stale rows and
 * was cleaned up on locale removal. struct.sql no longer creates it for fresh
 * installs; this brings upgraded installs to the same state.
 *
 * Idempotent: DROP TABLE IF EXISTS is a no-op when the table is already absent.
 */
return new class implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $conn->execute('DROP TABLE IF EXISTS ' . DB_TABLE_PREFIX . 't_keywords');
    }
};
