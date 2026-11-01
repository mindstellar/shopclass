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
 * Add t_item_stats.i_num_saved, how many wishlists hold a listing, and count it from the stored
 * wishlists. Safe to re-run: the column is added only when missing, and every count is set, not added.
 *
 * @title Wishlist count per listing
 */
return new class () implements MigrationInterface {
    private const BATCH = 1000;

    /** Most listings one wishlist keeps, as the wishlist service reads it. */
    private const MAX = 200;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $stats = DB_TABLE_PREFIX . 't_item_stats';
        // A column added alone at the end is instant on MySQL 8 and MariaDB 10.3+.
        $rerun = $conn->select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            array($stats, 'i_num_saved')
        ) !== array();
        if (!$rerun) {
            $conn->execute('ALTER TABLE ' . $stats . ' ADD COLUMN i_num_saved INT UNSIGNED NOT NULL DEFAULT 0');
        }

        $counts = array();
        $after  = '';
        do {
            $rows = $conn->select(
                'SELECT s_key, s_value FROM ' . DB_TABLE_PREFIX . "t_key_value WHERE s_group = 'wishlist' AND s_key > ?"
                . ' ORDER BY s_key LIMIT ' . self::BATCH,
                array($after)
            );
            foreach ($rows as $row) {
                $after = (string) $row['s_key'];
                foreach ($this->ids((string) $row['s_value']) as $id) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        } while (count($rows) === self::BATCH);

        // Listings counted on an earlier run but on no list now go back to 0.
        $after = 0;
        while ($rerun) {
            $ids = array_map('intval', array_column($conn->select(
                'SELECT fk_i_item_id FROM ' . $stats . ' WHERE fk_i_item_id > ? AND i_num_saved > 0 ORDER BY fk_i_item_id LIMIT ' . self::BATCH,
                array($after)
            ), 'fk_i_item_id'));
            if ($ids !== array()) {
                $after = end($ids);
                $this->set($conn, $stats, 0, array_values(array_filter($ids, static fn (int $id): bool => !isset($counts[$id]))));
            }
            if (count($ids) < self::BATCH) {
                break;
            }
        }

        $byCount = array();
        foreach ($counts as $id => $count) {
            $byCount[$count][] = $id;
        }
        foreach ($byCount as $count => $ids) {
            $this->set($conn, $stats, $count, $ids);
        }
    }

    /**
     * The listing ids of one stored list, as the wishlist service reads them: positive whole
     * numbers, each once, at most MAX.
     *
     * @return int[]
     */
    private function ids(string $json): array
    {
        $list = json_decode($json, true);
        $out  = array();
        foreach (is_array($list) ? $list : array() as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[$id] = $id;
                }
            }
            if (count($out) === self::MAX) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    private function set(Connection $conn, string $stats, int $count, array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $conn->execute(
                'UPDATE ' . $stats . ' SET i_num_saved = ? WHERE fk_i_item_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_merge(array($count), $chunk)
            );
        }
    }
};
