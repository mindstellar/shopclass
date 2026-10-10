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
 * Move listing photos from t_item_resource into t_resource (owner type `item`) with their ids
 * unchanged, then drop t_item_resource. A t_resource row whose id a listing photo holds gets a new
 * id first and keeps the old one in s_base_name, so no file or URL moves. The old table is dropped only
 * when every photo arrived. Safe to re-run: a photo already moved is kept, and a missing table is skipped.
 *
 * @title Listing photos in the resource table
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $photos    = DB_TABLE_PREFIX . 't_item_resource';
        $resources = DB_TABLE_PREFIX . 't_resource';
        if (!$this->exists($conn, $photos)) {
            return;
        }

        $hasBase = $conn->select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            array($resources, 's_base_name')
        );
        if ($hasBase === array()) {
            $conn->execute('ALTER TABLE ' . $resources . ' ADD COLUMN s_base_name VARCHAR(40) NULL');
        }

        $clashes = $conn->select(
            'SELECT r.pk_i_id FROM ' . $resources . ' r INNER JOIN ' . $photos . ' p ON p.pk_i_id = r.pk_i_id'
            . " WHERE NOT (r.s_owner_type = 'item' AND r.i_owner_id = p.fk_i_item_id) ORDER BY r.pk_i_id"
        );
        if ($clashes !== array()) {
            $next = 1 + max(
                (int) ($conn->select('SELECT MAX(pk_i_id) AS m FROM ' . $photos)[0]['m'] ?? 0),
                (int) ($conn->select('SELECT MAX(pk_i_id) AS m FROM ' . $resources)[0]['m'] ?? 0)
            );
            foreach ($clashes as $row) {
                $old = (int) $row['pk_i_id'];
                $conn->execute(
                    'UPDATE ' . $resources . ' SET s_base_name = COALESCE(s_base_name, ?), pk_i_id = ? WHERE pk_i_id = ?',
                    array((string) $old, $next++, $old)
                );
            }
        }

        $conn->execute(
            'INSERT INTO ' . $resources
            . ' (pk_i_id, s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, s_storage, dt_created)'
            . " SELECT p.pk_i_id, 'item', p.fk_i_item_id, p.s_name, p.s_extension, p.s_content_type, p.s_path, p.s_storage,"
            . ' COALESCE(i.dt_first_pub_date, i.dt_pub_date, NOW())'
            . ' FROM ' . $photos . ' p LEFT JOIN ' . DB_TABLE_PREFIX . 't_item i ON i.pk_i_id = p.fk_i_item_id'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $resources . ' x WHERE x.pk_i_id = p.pk_i_id)'
        );

        // DDL cannot be rolled back, so the old table goes only when every photo is in t_resource.
        $missing = (int) ($conn->select(
            'SELECT COUNT(*) AS n FROM ' . $photos . ' p WHERE NOT EXISTS (SELECT 1 FROM ' . $resources . ' x'
            . " WHERE x.pk_i_id = p.pk_i_id AND x.s_owner_type = 'item' AND x.i_owner_id = p.fk_i_item_id)"
        )[0]['n'] ?? 0);
        if ($missing > 0) {
            throw new RuntimeException($missing . ' listing photos did not reach t_resource; t_item_resource is kept.');
        }

        $conn->execute('DROP TABLE ' . $photos);
    }

    private function exists(Connection $conn, string $table): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            array($table)
        ) !== array();
    }
};
