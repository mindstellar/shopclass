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
 * Move the market catalogue cache from t_preference, which every request loads, to the
 * `market` group of t_key_value. Copies before it deletes, so it is safe to re-run.
 *
 * @title Market cache out of preferences
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $prefs = DB_TABLE_PREFIX . 't_preference';
        $rows  = $conn->select(
            'SELECT s_name, s_value FROM ' . $prefs . " WHERE s_section = 'osclass'"
            . " AND (s_name LIKE 'market\\_plugins\\_%' OR s_name LIKE 'market\\_themes\\_%')"
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $conn->execute(
                'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_key_value (s_group, s_key, s_value, dt_created)'
                . ' VALUES (?, ?, ?, ?)',
                array('market', (string) $row['s_name'], json_encode((string) $row['s_value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now)
            );
            $conn->execute(
                'DELETE FROM ' . $prefs . " WHERE s_section = 'osclass' AND s_name = ?",
                array((string) $row['s_name'])
            );
        }
    }
};
