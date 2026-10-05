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
 * Move each plugin's category list from t_plugin_category into the `plugin_categories`
 * group of t_key_value, one list per plugin, then drop the table. Safe to re-run: a list
 * already moved is kept, and a missing table is skipped.
 *
 * @title Plugin categories in the key-value store
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table  = DB_TABLE_PREFIX . 't_plugin_category';
        $exists = $conn->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            array($table)
        );
        if ($exists === array()) {
            return;
        }

        $lists = array();
        foreach ($conn->select('SELECT s_plugin_name, fk_i_category_id FROM ' . $table) as $row) {
            $lists[(string) $row['s_plugin_name']][] = (int) $row['fk_i_category_id'];
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach ($lists as $plugin => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $conn->execute(
                'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_key_value (s_group, s_key, s_value, dt_created) VALUES (?, ?, ?, ?)',
                array('plugin_categories', $plugin, json_encode($ids), $now)
            );
        }

        $conn->execute('DROP TABLE ' . $table);
    }
};
