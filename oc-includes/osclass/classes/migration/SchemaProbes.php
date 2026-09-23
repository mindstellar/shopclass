<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\migration;

use mindstellar\database\Connection;

/**
 * The three "is it already there?" questions a migration asks before it changes
 * anything, so a re-run after an interrupted upgrade is safe.
 *
 * Every migration that needed one used to carry its own private copy -- fifteen of
 * them, all the same lookup. Use this instead:
 *
 *   return new class () implements MigrationInterface {
 *       use SchemaProbes;
 *       ...
 *   };
 */
trait SchemaProbes
{
    /**
     * @param Connection $conn
     * @param string     $table Unprefixed or prefixed, as the caller spells it
     *
     * @return bool
     */
    private function tableExists(Connection $conn, string $table): bool
    {
        return $this->probe(
            $conn,
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table)
        );
    }

    /**
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return bool
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        return $this->probe(
            $conn,
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        );
    }

    /**
     * @param Connection $conn
     * @param string     $table
     * @param string     $index
     *
     * @return bool
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        return $this->probe(
            $conn,
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, $index)
        );
    }

    /**
     * @param Connection        $conn
     * @param string            $sql
     * @param array<int,string> $params
     *
     * @return bool
     */
    private function probe(Connection $conn, string $sql, array $params): bool
    {
        return (int)$conn->scalar($sql, $params) > 0;
    }
}
