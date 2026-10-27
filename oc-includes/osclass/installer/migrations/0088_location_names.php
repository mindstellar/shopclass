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
 * Add t_location_name, the country, region and city names per language. Safe to re-run: the
 * table is made only when missing.
 *
 * @title Location names per language
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $locale = $conn->selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array(DB_TABLE_PREFIX . 't_locale', 'pk_c_code')
        );
        if ($locale !== null && ($locale['charset_name'] ?? '') !== 'utf8mb4') {
            throw new \RuntimeException(
                'The language table still uses the "' . $locale['charset_name'] . '" character set. '
                . 'Convert ' . DB_TABLE_PREFIX . 't_locale to utf8mb4, then run the upgrade again.'
            );
        }
        $collate = $locale !== null && preg_match('/^utf8mb4_\w+$/', (string) $locale['collation_name'])
            ? (string) $locale['collation_name'] : 'utf8mb4_general_ci';

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_location_name ('
            . " e_place_type ENUM('country','region','city') NOT NULL,"
            . ' s_place_id VARCHAR(12) NOT NULL,'
            . ' fk_c_locale_code CHAR(5) COLLATE ' . $collate . ' NOT NULL,'
            . ' s_name VARCHAR(100) NOT NULL,'
            . ' PRIMARY KEY (e_place_type, s_place_id, fk_c_locale_code),'
            . ' INDEX idx_type_name (e_place_type, s_name),'
            . ' FOREIGN KEY (fk_c_locale_code) REFERENCES ' . DB_TABLE_PREFIX . 't_locale (pk_c_code) ON DELETE CASCADE)'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }
};
