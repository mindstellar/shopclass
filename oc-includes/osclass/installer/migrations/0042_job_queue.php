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
 * Renames `t_storage_queue` to `t_job_queue`, widens `s_type` to 60 and lets `s_storage` go NULL
 * so the queue can carry non-storage jobs, namespacing already-queued rows under `storage.`.
 * RENAME TABLE is a metadata-only change on InnoDB, so this is instant at any size.
 */
return new class () implements MigrationInterface {
    /** The job types this table held before it was namespaced. */
    private const STORAGE_TYPES = array('delete', 'offload', 'restore', 'adopt', 'regenerate', 'seed');

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $old = DB_TABLE_PREFIX . 't_storage_queue';
        $new = DB_TABLE_PREFIX . 't_job_queue';

        $haveOld = $this->exists($conn, $old);
        $haveNew = $this->exists($conn, $new);

        if ($haveOld && !$haveNew) {
            $conn->execute('RENAME TABLE ' . $old . ' TO ' . $new);
            $haveNew = true;
        } elseif ($haveOld && $haveNew) {
            // `db:upgrade` reconciles the schema against struct.sql BEFORE running
            // migrations, so on that path the new table is already here -- created
            // empty -- and the rename above would be skipped, stranding whatever was
            // still queued in the old one.
            if ((int) $conn->scalar('SELECT COUNT(*) FROM ' . $new) === 0) {
                $conn->execute('DROP TABLE ' . $new);
                $conn->execute('RENAME TABLE ' . $old . ' TO ' . $new);
            } else {
                $conn->execute(
                    'INSERT INTO ' . $new
                    . ' (s_type, s_storage, s_payload, s_status, i_attempts, s_last_error,'
                    . ' s_worker, dt_next_run, dt_locked, dt_created)'
                    . ' SELECT s_type, s_storage, s_payload, s_status, i_attempts, s_last_error,'
                    . ' s_worker, dt_next_run, dt_locked, dt_created FROM ' . $old
                );
                $conn->execute('DROP TABLE ' . $old);
            }
        }

        if (!$haveNew) {
            // Neither name is here, so there is nothing to carry across. The reconciler
            // creates the table from struct.sql, already in its new shape.
            return;
        }

        $conn->execute(
            'ALTER TABLE ' . $new
            . ' MODIFY s_type VARCHAR(60) NOT NULL,'
            . ' MODIFY s_storage VARCHAR(30) NULL'
        );

        // Namespace whatever is still queued. Anything already carrying a dot has been
        // through here before, or was written by the new code during a re-run.
        foreach (self::STORAGE_TYPES as $type) {
            $conn->execute(
                'UPDATE ' . $new . ' SET s_type = ? WHERE s_type = ?',
                array('storage.' . $type, $type)
            );
        }
    }

    /**
     * @param Connection $conn
     * @param string     $table
     *
     * @return bool
     * @throws \mindstellar\database\DbException
     */
    private function exists(Connection $conn, string $table): bool
    {
        return (int) $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table)
        ) > 0;
    }
};
