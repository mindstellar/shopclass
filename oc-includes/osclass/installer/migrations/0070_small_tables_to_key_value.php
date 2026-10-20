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
 * Move cron times, daily alert counts, pending e-mail changes and staged uploads into
 * t_key_value, then drop their four tables. Expired rows, and uploads whose file name the
 * store cannot hold, are not moved. Safe to re-run: a moved key is kept, and a missing
 * table is skipped.
 *
 * @title Small tables in the key-value store
 */
return new class () implements MigrationInterface {
    private const INSERT = ' (s_group, s_key, s_value, dt_created, dt_expires) VALUES (?, ?, ?, ?, ?)';

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $now  = time();
        $kv   = 'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_key_value' . self::INSERT;
        $made = gmdate('Y-m-d H:i:s', $now);

        if ($this->exists($conn, 't_cron')) {
            foreach ($conn->select('SELECT e_type, d_last_exec, d_next_exec FROM ' . DB_TABLE_PREFIX . 't_cron') as $row) {
                $times = json_encode(array('last' => (string) $row['d_last_exec'], 'next' => (string) $row['d_next_exec']));
                $conn->execute($kv, array('cron', (string) $row['e_type'], $times, $made, null));
            }
        }

        if ($this->exists($conn, 't_alerts_sent')) {
            foreach ($conn->select('SELECT d_date, i_num_alerts_sent FROM ' . DB_TABLE_PREFIX . 't_alerts_sent') as $row) {
                $conn->execute($kv, array('alerts_sent', (string) $row['d_date'], (string) (int) $row['i_num_alerts_sent'], $made, null));
            }
        }

        if ($this->exists($conn, 't_user_email_tmp')) {
            $since = date('Y-m-d H:i:s', $now - 7 * 24 * 3600);
            foreach ($conn->select('SELECT fk_i_user_id, s_new_email, dt_date FROM ' . DB_TABLE_PREFIX . 't_user_email_tmp WHERE dt_date > ?', array($since)) as $row) {
                $expires = (int) strtotime((string) $row['dt_date']) + 7 * 24 * 3600;
                if ($expires > $now) {
                    $conn->execute($kv, array('email_change', (string) $row['fk_i_user_id'], (string) $row['s_new_email'], $made, gmdate('Y-m-d H:i:s', $expires)));
                }
            }
        }

        if ($this->exists($conn, 't_item_upload_tmp')) {
            $since = date('Y-m-d H:i:s', $now - 2 * 3600);
            foreach ($conn->select('SELECT s_token, s_uuid, s_file, dt_date FROM ' . DB_TABLE_PREFIX . 't_item_upload_tmp WHERE dt_date > ?', array($since)) as $row) {
                $expires = (int) strtotime((string) $row['dt_date']) + 2 * 3600;
                $file    = (string) $row['s_file'];
                if ($expires > $now && $file !== '' && trim($file) === $file && preg_match('/[\x00-\x1f\x7f]/', $file) !== 1) {
                    $group = 'upload.' . sha1((string) $row['s_token']);
                    $conn->execute($kv, array($group, $file, (string) $row['s_uuid'], $made, gmdate('Y-m-d H:i:s', $expires)));
                }
            }
        }

        foreach (array('t_cron', 't_alerts_sent', 't_user_email_tmp', 't_item_upload_tmp') as $table) {
            $conn->execute('DROP TABLE IF EXISTS ' . DB_TABLE_PREFIX . $table);
        }
    }

    private function exists(Connection $conn, string $table): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            array(DB_TABLE_PREFIX . $table)
        ) !== array();
    }
};
