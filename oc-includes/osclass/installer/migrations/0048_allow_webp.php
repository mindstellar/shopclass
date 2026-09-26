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
 * Accept WebP photos on sites that still have the old default list of image extensions. A list
 * someone changed is left as it is. Re-running changes nothing.
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
            'UPDATE ' . DB_TABLE_PREFIX . "t_preference SET s_value = 'png,gif,jpg,jpeg,webp'"
            . " WHERE s_section = 'osclass' AND s_name = 'allowedExt' AND s_value = 'png,gif,jpg,jpeg'"
        );
    }
};
