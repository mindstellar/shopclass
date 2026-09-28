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
use mindstellar\migration\SchemaProbes;

/**
 * Give t_cron and t_plugin_category the primary keys their lookups assume, after removing
 * duplicate rows, and restore any missing core cron row. Each step probes first, so a
 * re-run is safe.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** Cron types core schedules, with the seed values the installer writes. */
    private const CORE_CRON_TYPES = array('HOURLY', 'DAILY', 'WEEKLY');

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $cron = DB_TABLE_PREFIX . 't_cron';
        if ($this->tableExists($conn, $cron)) {
            if (!$this->hasPrimaryKey($conn, $cron)) {
                $this->dedupeCron($conn, $cron);
                $conn->execute('ALTER TABLE ' . $cron . ' ADD PRIMARY KEY (e_type), ALGORITHM=INPLACE, LOCK=NONE');
            }
            // cron.php skips a type with no row, so a lost row would stop that schedule for good.
            foreach (self::CORE_CRON_TYPES as $type) {
                $conn->execute(
                    'INSERT IGNORE INTO ' . $cron . " (e_type, d_last_exec, d_next_exec) VALUES (?, '1000-01-01 00:00:00', '1000-01-01 00:00:00')",
                    array($type)
                );
            }
        }

        $pluginCategory = DB_TABLE_PREFIX . 't_plugin_category';
        if ($this->tableExists($conn, $pluginCategory) && !$this->hasPrimaryKey($conn, $pluginCategory)) {
            $this->dedupePluginCategory($conn, $pluginCategory);
            $conn->execute(
                'ALTER TABLE ' . $pluginCategory . ' ADD PRIMARY KEY (s_plugin_name, fk_i_category_id), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    /**
     * Keep one row per type, the newest by d_last_exec then d_next_exec. Each duplicated
     * type is emptied and its kept row written back in one transaction, so no run can
     * leave a type with no row.
     *
     * @param Connection $conn
     * @param string     $table
     *
     * @throws \mindstellar\database\DbException
     */
    public function dedupeCron(Connection $conn, string $table): void
    {
        $this->atomically($conn, function () use ($conn, $table) {
            $keep  = array();
            $count = array();
            $rows  = $conn->select('SELECT e_type, d_last_exec, d_next_exec FROM ' . $table . ' FOR UPDATE');
            foreach ($rows as $row) {
                $type         = (string) $row['e_type'];
                $count[$type] = ($count[$type] ?? 0) + 1;
                $rank         = array((string) $row['d_last_exec'], (string) $row['d_next_exec']);
                if (!isset($keep[$type]) || $rank > array($keep[$type]['d_last_exec'], $keep[$type]['d_next_exec'])) {
                    $keep[$type] = $row;
                }
            }
            foreach ($count as $type => $n) {
                if ($n < 2) {
                    continue;
                }
                $conn->execute('DELETE FROM ' . $table . ' WHERE e_type = ?', array($type));
                $conn->execute(
                    'INSERT INTO ' . $table . ' (e_type, d_last_exec, d_next_exec) VALUES (?, ?, ?)',
                    array($type, $keep[$type]['d_last_exec'], $keep[$type]['d_next_exec'])
                );
            }
        });
    }

    /**
     * Collapse each duplicated (plugin, category) pair to one row. The rows carry no other
     * column, so the kept row is the same whichever one survives. It is written back as it
     * was, so the foreign key is not checked again.
     *
     * @param Connection $conn
     * @param string     $table
     *
     * @throws \mindstellar\database\DbException
     */
    public function dedupePluginCategory(Connection $conn, string $table): void
    {
        $checks = (int) $conn->scalar('SELECT @@SESSION.foreign_key_checks');
        $conn->execute('SET SESSION foreign_key_checks = 0');
        try {
            $this->rewritePluginCategory($conn, $table);
        } finally {
            $conn->execute('SET SESSION foreign_key_checks = ' . $checks);
        }
    }

    /**
     * @param Connection $conn
     * @param string     $table
     *
     * @throws \mindstellar\database\DbException
     */
    private function rewritePluginCategory(Connection $conn, string $table): void
    {
        $this->atomically($conn, function () use ($conn, $table) {
            $dupes = $conn->select(
                'SELECT s_plugin_name, fk_i_category_id FROM ' . $table
                . ' GROUP BY s_plugin_name, fk_i_category_id HAVING COUNT(*) > 1 FOR UPDATE'
            );
            foreach ($dupes as $row) {
                $pair = array((string) $row['s_plugin_name'], (int) $row['fk_i_category_id']);
                $conn->execute('DELETE FROM ' . $table . ' WHERE s_plugin_name = ? AND fk_i_category_id = ?', $pair);
                $conn->execute('INSERT INTO ' . $table . ' (s_plugin_name, fk_i_category_id) VALUES (?, ?)', $pair);
            }
        });
    }

    /**
     * Run $fn in a transaction on this migration's own connection.
     *
     * @param Connection $conn
     * @param callable   $fn
     *
     * @throws \Throwable
     */
    private function atomically(Connection $conn, callable $fn): void
    {
        $handle = $conn->handle();
        $handle->begin_transaction();
        try {
            $fn();
            $handle->commit();
        } catch (\Throwable $e) {
            $handle->rollback();
            throw $e;
        }
    }
};
