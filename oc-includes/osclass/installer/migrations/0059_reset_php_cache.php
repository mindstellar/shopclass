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
 * Clear OPcache once the new files are on disk. The 6.3 updater never did, so on a server
 * that does not re-check file times the site kept running 6.3 after a "successful" update.
 *
 * @title Clear the PHP cache so the new version runs
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     */
    public function up(Connection $conn): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
};
