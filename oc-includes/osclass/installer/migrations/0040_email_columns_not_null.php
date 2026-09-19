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
 * Brings three columns to the shape `struct.sql` has declared since 5.0.0. Installs old enough
 * to predate that never had it, and the schema reconciler cannot see the difference: it matches
 * a column's type with a one-token regex, so NULL and NOT NULL compare equal.
 *
 * The one that matters is `t_user.s_email`. A unique index permits unlimited NULLs, so while the
 * column is nullable `uk_user_email` does not actually guarantee one account per address.
 *
 * NOT NULL does not make a field required — '' is still allowed, and core stores it for a listing
 * with no contact address. Nothing about who must supply an e-mail changes here.
 *
 * `i_permissions` is a dead Osclass 2011 column, dropped from `struct.sql` long before 5.0.0.
 *
 * Idempotent, and it never alters data: a column already in the declared shape is skipped, and
 * one holding NULLs is left alone rather than letting a non-strict server turn them into ''.
 */
return new class () implements MigrationInterface {
    /** Column => the definition struct.sql declares for it. */
    private const COLUMNS = array(
        't_user#s_email'                   => "VARCHAR(100) NOT NULL",
        't_item#s_contact_email'           => "VARCHAR(140) NOT NULL",
        // This one drifted the other way: stricter on old installs than core declares.
        't_category_description#s_name'    => "VARCHAR(100) NULL DEFAULT NULL",
    );

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        foreach (self::COLUMNS as $key => $definition) {
            [$suffix, $column] = explode('#', $key);
            $table             = DB_TABLE_PREFIX . $suffix;

            $nullable = $this->nullability($conn, $table, $column);
            if ($nullable === null) {
                continue; // No such table or column on this install.
            }

            $wantNullable = stripos($definition, 'NOT NULL') === false;
            if ($nullable === $wantNullable) {
                continue; // Already declared the way core declares it.
            }

            // Tightening: refuse rather than rewrite. Without STRICT_TRANS_TABLES the server
            // would convert every NULL to '' and only warn, which is a data change this
            // migration has no business making on its own.
            if (!$wantNullable && $this->nullCount($conn, $table, $column) > 0) {
                continue;
            }

            $conn->execute('ALTER TABLE ' . $table . ' MODIFY ' . $column . ' ' . $definition);
        }

        $this->dropDeadColumn($conn, DB_TABLE_PREFIX . 't_user', 'i_permissions');
    }

    /**
     * Whether a column accepts NULL, or null when the table or column is absent.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return bool|null
     * @throws \mindstellar\database\DbException
     */
    private function nullability(Connection $conn, string $table, string $column): ?bool
    {
        $value = $conn->scalar(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return $value === null ? null : (strtoupper((string) $value) === 'YES');
    }

    /**
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return int
     * @throws \mindstellar\database\DbException
     */
    private function nullCount(Connection $conn, string $table, string $column): int
    {
        return (int) $conn->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' IS NULL');
    }

    /**
     * Drop a column core no longer declares, if this install still carries it.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return void
     * @throws \mindstellar\database\DbException
     */
    private function dropDeadColumn(Connection $conn, string $table, string $column): void
    {
        if ($this->nullability($conn, $table, $column) === null) {
            return;
        }

        $conn->execute('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
    }
};
