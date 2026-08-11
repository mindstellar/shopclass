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
 * Schema changes made between 5.0 and 5.2 that were only ever declared in struct.sql.
 *
 * Each of these arrived without a migration, so an install upgrading from 5.0 or 5.1
 * received them solely from the schema reconciler noticing the difference. Nothing was
 * broken by that, but it meant the migration sequence could not rebuild the schema on
 * its own, which tests/schema-drift.php now requires of it.
 *
 * What is closed here, all of it widening or additive and none of it destructive:
 *
 *  - t_locale.s_direction, for right-to-left locales.
 *  - t_meta_fields.e_type gains NUMBER. The other members keep their order, so the
 *    integer each existing row stores still resolves to the same name -- appending
 *    would have been safe too, but this keeps the column identical to struct.sql.
 *  - t_meta_fields.s_meta, the per-field settings blob.
 *  - t_user.s_pass_ip and s_access_ip widen from 15 to 50. Fifteen characters holds
 *    an IPv4 address and truncates every IPv6 one.
 *
 * Every step is guarded, so this is a no-op on any install from 5.2 onward and on
 * every 6.x install, where struct.sql already produced exactly this shape.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $locale = DB_TABLE_PREFIX . 't_locale';
        if (!$this->columnExists($conn, $locale, 's_direction')) {
            $conn->execute(
                'ALTER TABLE ' . $locale . " ADD COLUMN s_direction VARCHAR(3) NOT NULL DEFAULT 'ltr'"
            );
        }

        $fields = DB_TABLE_PREFIX . 't_meta_fields';
        if (!$this->columnExists($conn, $fields, 's_meta')) {
            $conn->execute('ALTER TABLE ' . $fields . ' ADD COLUMN s_meta MEDIUMTEXT NULL DEFAULT NULL');
        }

        // Rewriting the ENUM is only skipped once NUMBER is already one of its members.
        $type = (string) $conn->scalar(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($fields, 'e_type')
        );
        if ($type !== '' && stripos($type, "'NUMBER'") === false) {
            $conn->execute(
                'ALTER TABLE ' . $fields . ' CHANGE COLUMN e_type e_type'
                . " ENUM('TEXT','NUMBER','TEXTAREA','DROPDOWN','RADIO','CHECKBOX','URL','DATE','DATEINTERVAL')"
                . " NOT NULL DEFAULT 'TEXT'"
            );
        }

        $user = DB_TABLE_PREFIX . 't_user';
        if ($this->columnShorterThan($conn, $user, 's_pass_ip', 50)) {
            $conn->execute('ALTER TABLE ' . $user . ' CHANGE COLUMN s_pass_ip s_pass_ip VARCHAR(50) NULL');
        }
        if ($this->columnShorterThan($conn, $user, 's_access_ip', 50)) {
            $conn->execute(
                'ALTER TABLE ' . $user . " CHANGE COLUMN s_access_ip s_access_ip VARCHAR(50) NOT NULL DEFAULT ''"
            );
        }
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
     * Whether $column exists and holds fewer than $length characters. A column that is
     * absent reports false: this migration widens, it does not create.
     */
    private function columnShorterThan(Connection $conn, string $table, string $column, int $length): bool
    {
        $current = $conn->scalar(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return $current !== null && (int) $current < $length;
    }
};
