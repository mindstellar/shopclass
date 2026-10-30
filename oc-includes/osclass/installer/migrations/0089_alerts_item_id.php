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
 * Add t_alerts.fk_i_item_id, the listing a back-in-stock or price-drop alert waits on, with an
 * index, and fill it from each product alert's stored search. Safe to re-run: the column and
 * index are added only when missing, and only rows still without a listing are filled.
 *
 * @title Listing id on product alerts
 */
return new class () implements MigrationInterface {
    private const BATCH  = 1000;
    private const PREFIX = '{"v":2,"params":{"product":';

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_alerts';
        // Two statements: a column added alone at the end is instant on MySQL 8 and MariaDB 10.3+.
        if (!$this->has($conn, 'columns', $table, 'column_name', 'fk_i_item_id')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN fk_i_item_id INT UNSIGNED NULL');
        }
        if (!$this->has($conn, 'statistics', $table, 'index_name', 'idx_item')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD INDEX idx_item (fk_i_item_id)');
        }

        $after = 0;
        do {
            $rows = $conn->select(
                'SELECT pk_i_id, s_search FROM ' . $table . " WHERE pk_i_id > ? AND e_type = 'CUSTOM' AND fk_i_item_id IS NULL"
                . ' ORDER BY pk_i_id LIMIT ' . self::BATCH,
                array($after)
            );
            $byItem = array();
            foreach ($rows as $row) {
                $after = (int) $row['pk_i_id'];
                $item  = $this->listingOf((string) $row['s_search']);
                if ($item !== null) {
                    $byItem[$item][] = $after;
                }
            }
            foreach ($byItem as $item => $ids) {
                $conn->execute(
                    'UPDATE ' . $table . ' SET fk_i_item_id = ? WHERE pk_i_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                    array_merge(array($item), $ids)
                );
            }
        } while (count($rows) === self::BATCH);
    }

    /**
     * The listing id of a product alert's stored search, or null for any other search.
     */
    private function listingOf(string $json): ?int
    {
        if (strncmp($json, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return null;
        }
        $listing = json_decode($json, true)['params']['product']['listing'] ?? null;

        return is_int($listing) && $listing > 0 ? $listing : null;
    }

    private function has(Connection $conn, string $view, string $table, string $column, string $name): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.' . $view . ' WHERE table_schema = DATABASE() AND table_name = ? AND ' . $column . ' = ? LIMIT 1',
            array($table, $name)
        ) !== array();
    }
};
