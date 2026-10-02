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
 * The update channel replaces the prerelease switch: a site that allowed prereleases gets the
 * beta channel, any other stable. Automatic security installs start off. INSERT IGNORE keeps
 * what an admin already set, so a re-run is safe.
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table   = DB_TABLE_PREFIX . 't_preference';
        $old     = $conn->selectOne(
            'SELECT s_value FROM ' . $table . " WHERE s_section = 'osclass' AND s_name = 'allow_update_prerelease'"
        );
        $channel = in_array((string) ($old['s_value'] ?? ''), array('1', 'true'), true) ? 'beta' : 'stable';

        foreach (array('update_channel' => array($channel, 'STRING'), 'auto_security_updates' => array('0', 'BOOLEAN')) as $name => [$value, $type]) {
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $name, $value, $type)
            );
        }
    }
};
