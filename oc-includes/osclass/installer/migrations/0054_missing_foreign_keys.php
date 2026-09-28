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
 * Add the foreign keys t_item_description, t_meta_fields, t_form_submission and t_alerts
 * never had. Orphan rows are cleared first on every run, since a manual repair may already
 * have added a key without checking the rows. Constraint names are read from
 * information_schema, never assumed.
 *
 * The key is added with foreign_key_checks off, so InnoDB builds it in place in well under
 * a second instead of copying and write-locking the table. That is only safe because the
 * orphans are gone.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** [child, column, parent, parent column, ON DELETE rule, what happens to an orphan]. */
    private const KEYS = array(
        array('t_item_description', 'fk_i_item_id', 't_item', 'pk_i_id', 'CASCADE', 'delete'),
        array('t_item_description', 'fk_c_locale_code', 't_locale', 'pk_c_code', 'CASCADE', 'delete'),
        array('t_meta_fields', 'fk_i_group_id', 't_meta_group', 'pk_i_id', 'SET NULL', 'null'),
        array('t_form_submission', 'fk_i_group_id', 't_meta_group', 'pk_i_id', 'CASCADE', 'delete'),
        array('t_alerts', 'fk_i_user_id', 't_user', 'pk_i_id', 'CASCADE', 'delete'),
    );

    /** Orphan values cleared per statement. */
    private const CHUNK = 500;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        // The lock MigrationRunner takes; taken here only for a direct call, since re-taking it
        // inside the runner's session would release it on MySQL before 5.7.5.
        $lock = 'osc_migrate_' . md5((string) $conn->scalar('SELECT DATABASE()') . '|' . DB_TABLE_PREFIX);
        $held = (int) $conn->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', array($lock)) === 1;
        if (!$held && (int) $conn->scalar('SELECT GET_LOCK(?, 60)', array($lock)) !== 1) {
            throw new \RuntimeException('Another upgrade is already running.');
        }
        try {
            $this->addKeys($conn);
        } finally {
            if (!$held) {
                $conn->scalar('SELECT RELEASE_LOCK(?)', array($lock));
            }
        }
    }

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    private function addKeys(Connection $conn): void
    {
        $byTable = array();
        foreach (self::KEYS as $key) {
            $byTable[$key[0]][] = $key;
        }
        foreach ($byTable as $childName => $keys) {
            $this->addKeysForTable($conn, $childName, $keys);
        }
    }

    /**
     * Add every foreign key due on one child table. A charset/collation mismatch forces a
     * COPY-algorithm MODIFY, so every column needing one is folded into a single ALTER TABLE
     * per pass instead of one ALTER per column -- one rebuild instead of several.
     *
     * @param Connection            $conn
     * @param string                $childName
     * @param array<int,array<int,string>> $keys self::KEYS entries for this child table
     *
     * @throws \mindstellar\database\DbException
     */
    private function addKeysForTable(Connection $conn, string $childName, array $keys): void
    {
        $child = DB_TABLE_PREFIX . $childName;
        if (!$this->tableExists($conn, $child)) {
            return;
        }

        $pending = array();
        foreach ($keys as [, $column, $parentName, $parentColumn, $rule, $orphan]) {
            $parent = DB_TABLE_PREFIX . $parentName;
            if (!$this->tableExists($conn, $parent) || !$this->columnExists($conn, $child, $column)) {
                continue;
            }

            if ($childName === 't_alerts') {
                // Guest alerts written before 6.4 hold 0, which no user row can match.
                $conn->execute('UPDATE ' . $child . ' SET fk_i_user_id = NULL WHERE fk_i_user_id = 0');
            }

            $names = $this->constraintNames($conn, $child, $column, $parent);
            $pending[] = array(
                'column'       => $column,
                'parent'       => $parent,
                'parentColumn' => $parentColumn,
                'rule'         => $rule,
                'orphan'       => $orphan,
                'names'        => $names,
                'done'         => count($names) === 1 && $this->deleteRule($conn, $child, $names[0]) === $rule,
            );
        }

        // A charset or collation mismatch would break the orphan join, so it goes first.
        $needed = array_filter($pending, static fn ($p) => !$p['done']);
        $this->alignColumns($conn, $child, $needed, false);

        // Orphans are cleared every run, even for a key that is already correct, since a
        // manual repair may have added the key without checking the rows.
        foreach ($pending as $p) {
            $this->clearOrphans($conn, $child, $p['column'], $p['parent'], $p['parentColumn'], $p['orphan']);
        }

        $needed = array_filter($pending, static fn ($p) => !$p['done']);
        if ($needed === array()) {
            return;
        }
        $this->alignColumns($conn, $child, $needed, true);

        $checks = (int) $conn->scalar('SELECT @@SESSION.foreign_key_checks');
        $conn->execute('SET SESSION foreign_key_checks = 0');
        try {
            foreach ($needed as $p) {
                foreach ($p['names'] as $name) {
                    $conn->execute('ALTER TABLE ' . $child . ' DROP FOREIGN KEY ' . $name);
                }
                $conn->execute(
                    'ALTER TABLE ' . $child . ' ADD FOREIGN KEY (' . $p['column'] . ')'
                    . ' REFERENCES ' . $p['parent'] . ' (' . $p['parentColumn'] . ') ON DELETE ' . $p['rule']
                    . ', ALGORITHM=INPLACE, LOCK=NONE'
                );
            }
        } finally {
            $conn->execute('SET SESSION foreign_key_checks = ' . $checks);
        }
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

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
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
     * Run every column's MODIFY due for one pass -- collation (\$type false) or type
     * (\$type true) -- as a single ALTER TABLE, so a table needing several rebuilds one
     * instead of once per column.
     *
     * @param Connection                $conn
     * @param string                    $child
     * @param array<int,array<string,mixed>> $pending entries with column/parent/parentColumn/rule
     * @param bool                      $type
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
     * with $type also its type, since a foreign key needs both to match. Values that would not
     * convert belong to orphans, which is why the type change waits until they are cleared.
     * Null when the column already matches.
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
};
