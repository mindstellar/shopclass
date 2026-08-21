<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Location import identity: lets regions and cities be matched back to an upstream data
 * source on re-import, instead of re-created (and re-slugged) every time.
 *
 * i_source_id is signed, not UNSIGNED: a region the source gives no cities of its own is
 * published as a single synthesised city carrying the region's id negated, so negative
 * values are legitimate, not a data error.
 *
 * The unique key on each table is a single column, not scoped to the parent: upstream ids
 * are already globally unique within their own table, so a parent-scoped index buys
 * nothing, and it actively hurts -- the importer has to look a row up by source id alone
 * to detect that upstream re-parented a city into a different region, and a composite
 * index led by the parent column cannot serve that lookup. This is safe to add on an
 * existing install because MySQL lets NULL repeat in a unique index -- every
 * pre-migration row has i_source_id NULL and none of them collide.
 *
 * t_location_slug_history mirrors t_category_slug_history: the default search-URL scheme
 * embeds the row id ({slug}-r{id}) and already self-heals on rename, but subdomain-based
 * location routing resolves purely by slug and has no such fallback, so a rename needs a
 * recorded history to redirect from. It carries no foreign key because fk_i_id points into
 * either t_region or t_city depending on e_type, and one column cannot reference two
 * tables.
 *
 * Idempotent: ADD COLUMN and ADD KEY are guarded by information_schema lookups, and the
 * new table is CREATE TABLE IF NOT EXISTS, so re-running after an interrupted upgrade is
 * safe. The same end state is declared in installer/struct.sql for a fresh install, which
 * the runner baselines rather than replays.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $region = DB_TABLE_PREFIX . 't_region';
        $city = DB_TABLE_PREFIX . 't_city';

        foreach (array($region, $city) as $table) {
            if (!$this->columnExists($conn, $table, 'i_source_id')) {
                $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN i_source_id INT NULL');
            }

            if (!$this->columnExists($conn, $table, 'd_coord_lat')) {
                $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN d_coord_lat DECIMAL(10,6) NULL');
            }

            if (!$this->columnExists($conn, $table, 'd_coord_long')) {
                $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN d_coord_long DECIMAL(10,6) NULL');
            }
        }

        if (!$this->indexExists($conn, $region, 'uq_region_source')) {
            $conn->execute('ALTER TABLE ' . $region . ' ADD UNIQUE KEY uq_region_source (i_source_id)');
        }

        if (!$this->indexExists($conn, $city, 'uq_city_source')) {
            $conn->execute('ALTER TABLE ' . $city . ' ADD UNIQUE KEY uq_city_source (i_source_id)');
        }

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_location_slug_history ('
            . ' e_type ENUM(\'REGION\', \'CITY\') NOT NULL,'
            . ' s_slug VARCHAR(191) NOT NULL,'
            . ' fk_i_id INT UNSIGNED NOT NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (e_type, s_slug),'
            . ' INDEX idx_hist_loc (e_type, fk_i_id)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return (int) $count > 0;
    }

    /**
     * Whether $index already exists on $table in the current database.
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND INDEX_NAME = ?',
            array($table, $index)
        );

        return (int) $count > 0;
    }
};
