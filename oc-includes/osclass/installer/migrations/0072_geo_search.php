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
 * Give every listing a search point for distance search: its own map position, else its city
 * centre, else its region centre. Adds the two settings, off and in km. Safe to re-run: columns
 * and the index are added only when missing, and the points are recomputed from the same rows.
 *
 * @title Distance search points
 */
return new class () implements MigrationInterface {
    private const BATCH = 20000;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $p     = DB_TABLE_PREFIX;
        $table = $p . 't_item_location';

        $add = array();
        foreach (array(
            'd_geo_lat'       => 'd_geo_lat DECIMAL(10,6) NULL',
            'd_geo_long'      => 'd_geo_long DECIMAL(10,6) NULL',
            'e_geo_precision' => "e_geo_precision ENUM('exact','city','region') NULL",
        ) as $column => $definition) {
            if (!$this->has($conn, 'columns', $table, 'column_name', $column)) {
                $add[] = 'ADD COLUMN ' . $definition;
            }
        }
        if (!$this->has($conn, 'statistics', $table, 'index_name', 'idx_geo')) {
            $add[] = 'ADD INDEX idx_geo (d_geo_lat, d_geo_long)';
        }
        if ($add !== array()) {
            $conn->execute('ALTER TABLE ' . $table . ' ' . implode(', ', $add));
        }

        foreach (array(array('geo_search', '0', 'BOOLEAN'), array('distance_unit', 'km', 'STRING')) as $pref) {
            $conn->execute(
                'INSERT IGNORE INTO ' . $p . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $pref[0], $pref[1], $pref[2])
            );
        }

        $exact  = 'l.d_coord_lat IS NOT NULL AND l.d_coord_lat <> 0 AND l.d_coord_long IS NOT NULL AND l.d_coord_long <> 0';
        $city   = 'c.d_coord_lat IS NOT NULL AND c.d_coord_lat <> 0 AND c.d_coord_long IS NOT NULL AND c.d_coord_long <> 0';
        $region = 'r.d_coord_lat IS NOT NULL AND r.d_coord_lat <> 0 AND r.d_coord_long IS NOT NULL AND r.d_coord_long <> 0';
        $update = 'UPDATE ' . $table . ' l'
            . ' LEFT JOIN ' . $p . 't_city c ON c.pk_i_id = l.fk_i_city_id'
            . ' LEFT JOIN ' . $p . 't_region r ON r.pk_i_id = l.fk_i_region_id'
            . " SET l.e_geo_precision = CASE WHEN $exact THEN 'exact' WHEN $city THEN 'city' WHEN $region THEN 'region' ELSE NULL END,"
            . " l.d_geo_lat = CASE WHEN $exact THEN l.d_coord_lat WHEN $city THEN c.d_coord_lat WHEN $region THEN r.d_coord_lat ELSE NULL END,"
            . " l.d_geo_long = CASE WHEN $exact THEN l.d_coord_long WHEN $city THEN c.d_coord_long WHEN $region THEN r.d_coord_long ELSE NULL END"
            . ' WHERE l.fk_i_item_id > ? AND l.fk_i_item_id <= ?';

        $range = $conn->select('SELECT MIN(fk_i_item_id) AS lo, MAX(fk_i_item_id) AS hi FROM ' . $table)[0] ?? array();
        $max   = (int) ($range['hi'] ?? 0);
        for ($after = (int) ($range['lo'] ?? 1) - 1; $after < $max; $after += self::BATCH) {
            $conn->execute($update, array($after, $after + self::BATCH));
        }
    }

    private function has(Connection $conn, string $view, string $table, string $column, string $name): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.' . $view . ' WHERE table_schema = DATABASE() AND table_name = ? AND ' . $column . ' = ? LIMIT 1',
            array($table, $name)
        ) !== array();
    }
};
