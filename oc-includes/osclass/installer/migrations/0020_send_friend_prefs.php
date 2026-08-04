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
 * Seed the "share listing / send to a friend" preferences on existing installs.
 *
 * The form relays site-branded mail to a recipient taken from the request, so it
 * ships off by default (`enable_send_friend` = 0) and, once enabled, behind a
 * login (`reg_user_can_send_friend` = 1). Seeding is idempotent: INSERT IGNORE
 * against the (s_section, s_name) unique key never overwrites a value an admin
 * already set, so a re-run after an interrupted upgrade is safe.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'enable_send_friend'       => array('0', 'BOOLEAN'),
        'reg_user_can_send_friend' => array('1', 'BOOLEAN'),
    );

    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        foreach (self::PREFERENCES as $name => $spec) {
            [$value, $type] = $spec;
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $name, $value, $type)
            );
        }
    }
};
