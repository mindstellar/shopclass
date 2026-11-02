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
 * The "is it already there?" questions a migration asks before it changes anything, so a
 * re-run after an interrupted upgrade is safe, plus the shared machinery for adding a
 * foreign key: clearing orphans and aligning a child column with its parent's type.
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
     * Names of the indexes on $table over exactly $columns, in that order and with no
     * prefix length, whatever they are called. PRIMARY is included.
     *
     * @param Connection        $conn
     * @param string            $table
     * @param array<int,string> $columns
     *
     * @return array<int,string>
     */
    private function indexesOnColumns(Connection $conn, string $table, array $columns): array
    {
        $rows = $conn->select(
            'SELECT INDEX_NAME,'
            . " GROUP_CONCAT(CONCAT(COLUMN_NAME, IF(SUB_PART IS NULL, '', CONCAT('(', SUB_PART, ')')))"
            . ' ORDER BY SEQ_IN_INDEX) AS cols'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' GROUP BY INDEX_NAME',
            array($table)
        );

        $wanted = implode(',', $columns);
        $names  = array();
        foreach ($rows as $row) {
            if (($row['cols'] ?? '') === $wanted) {
                $names[] = (string)$row['INDEX_NAME'];
            }
        }

        return $names;
    }

    /**
     * @param Connection $conn
     * @param string     $table
     *
     * @return bool
     */
    private function hasPrimaryKey(Connection $conn, string $table): bool
    {
        return $this->indexExists($conn, $table, 'PRIMARY');
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

    /**
     * Delete, or set to NULL, every child row whose parent is missing. The missing values
     * are read without locking, then each batch is re-checked as it is written.
     *
     * @param Connection $conn
     * @param string     $child
     * @param string     $column
     * @param string     $parent
     * @param string     $parentColumn
     * @param string     $orphan 'delete' or 'null'
     *
     * @throws \mindstellar\database\DbException
     */
    private function clearOrphans(
        Connection $conn,
        string $child,
        string $column,
        string $parent,
        string $parentColumn,
        string $orphan
    ): void {
        $rows = $conn->select(
            'SELECT DISTINCT c.' . $column . ' AS v FROM ' . $child . ' c'
            . ' LEFT JOIN ' . $parent . ' p ON c.' . $column . ' = p.' . $parentColumn
            . ' WHERE c.' . $column . ' IS NOT NULL AND p.' . $parentColumn . ' IS NULL'
        );
        $values = array_map(static fn ($row) => $row['v'], $rows);

        foreach (array_chunk($values, 500) as $chunk) {
            $where = ' WHERE ' . $column . ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $parent . ' p WHERE p.' . $parentColumn . ' = ' . $child . '.' . $column . ')';
            $conn->execute(
                $orphan === 'null'
                    ? 'UPDATE ' . $child . ' SET ' . $column . ' = NULL' . $where
                    : 'DELETE FROM ' . $child . $where,
                $chunk
            );
        }
    }

    /**
     * Run every column's MODIFY due for one pass -- collation ($type false) or type
     * ($type true) -- as a single ALTER TABLE, so a table needing several rebuilds one
     * instead of once per column.
     *
     * @param Connection                      $conn
     * @param string                          $child
     * @param array<int,array<string,mixed>>  $pending entries with column/parent/parentColumn/rule
     * @param bool                            $type
     *
     * @throws \mindstellar\database\DbException
     */
    private function alignColumns(Connection $conn, string $child, array $pending, bool $type): void
    {
        $modifies = array();
        foreach ($pending as $p) {
            $definition = $this->columnDefinition(
                $conn,
                $child,
                $p['column'],
                $p['parent'],
                $p['parentColumn'],
                $p['rule'] === 'SET NULL',
                $type
            );
            if ($definition !== null) {
                $modifies[] = 'MODIFY ' . $p['column'] . ' ' . $definition;
            }
        }
        if ($modifies !== array()) {
            $conn->execute('ALTER TABLE ' . $child . ' ' . implode(', ', $modifies));
        }
    }

    /**
     * The MODIFY definition to give the child column the parent's charset and collation, and
     * with $type also its type. Null when the column already matches.
     *
     * @param Connection $conn
     * @param string     $child
     * @param string     $column
     * @param string     $parent
     * @param string     $parentColumn
     * @param bool       $nullable Force NULL, for an ON DELETE SET NULL key
     * @param bool       $type
     *
     * @return string|null
     * @throws \mindstellar\database\DbException
     */
    private function columnDefinition(
        Connection $conn,
        string $child,
        string $column,
        string $parent,
        string $parentColumn,
        bool $nullable,
        bool $type
    ): ?string {
        $c = $this->columnInfo($conn, $child, $column);
        $p = $this->columnInfo($conn, $parent, $parentColumn);
        if ($c === null || $p === null) {
            return null;
        }

        $sameCharset = $c['CHARACTER_SET_NAME'] === $p['CHARACTER_SET_NAME'] && $c['COLLATION_NAME'] === $p['COLLATION_NAME'];
        $sameType    = self::baseType($c['COLUMN_TYPE']) === self::baseType($p['COLUMN_TYPE']);
        $nullOk      = !$nullable || $c['IS_NULLABLE'] === 'YES';
        if ($sameCharset && ($sameType || !$type) && ($nullOk || !$type)) {
            return null;
        }

        $definition = $type ? $p['COLUMN_TYPE'] : $c['COLUMN_TYPE'];
        if ($p['CHARACTER_SET_NAME'] !== null) {
            $definition .= ' CHARACTER SET ' . $p['CHARACTER_SET_NAME'] . ' COLLATE ' . $p['COLLATION_NAME'];
        }
        $definition .= ($c['IS_NULLABLE'] === 'YES' || ($type && $nullable)) ? ' NULL DEFAULT NULL' : ' NOT NULL';

        return $definition;
    }

    /**
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return array<string,string|null>|null
     * @throws \mindstellar\database\DbException
     */
    private function columnInfo(Connection $conn, string $table, string $column): ?array
    {
        return $conn->selectOne(
            'SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        );
    }

    /**
     * A column type without the integer display width: "int(10) unsigned" -> "int unsigned".
     *
     * @param string|null $type
     *
     * @return string
     */
    private static function baseType(?string $type): string
    {
        return (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower((string) $type));
    }

    /**
     * @param Connection $conn
     * @param string     $table
     * @param string     $constraint
     *
     * @return string
     * @throws \mindstellar\database\DbException
     */
    private function deleteRule(Connection $conn, string $table, string $constraint): string
    {
        return (string) $conn->scalar(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            array($table, $constraint)
        );
    }

    /**
     * Names of every foreign key on $table.$column pointing at $parent.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     * @param string     $parent
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    private function constraintNames(Connection $conn, string $table, string $column, string $parent): array
    {
        $rows = $conn->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?'
            . ' ORDER BY CONSTRAINT_NAME',
            array($table, $column, $parent)
        );

        return array_map(static fn ($row) => (string) $row['CONSTRAINT_NAME'], $rows);
    }
}
