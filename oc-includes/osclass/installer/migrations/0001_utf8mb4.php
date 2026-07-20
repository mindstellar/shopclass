<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\migration\MigrationInterface;

/**
 * Convert every core table on this install from the legacy 3-byte `utf8` charset
 * to `utf8mb4`, so 4-byte characters (emoji, many CJK/supplementary code points)
 * store and round-trip intact instead of being truncated or replaced.
 *
 * The charset/collation pair is frozen at utf8mb4 / utf8mb4_general_ci and must
 * stay byte-identical to the fresh-install schema in installer/struct.sql: the
 * engine default collation differs between servers (MySQL 8 utf8mb4_0900_ai_ci
 * vs MariaDB utf8mb4_uca1400_ai_ci), so the collation is always stated explicitly
 * and never left to the engine.
 *
 * The collation is utf8mb4_general_ci, not utf8mb4_unicode_ci, on purpose. The
 * legacy tables are utf8_general_ci; utf8mb4_general_ci is its charset-widened
 * successor with the same simplified per-character weights over the shared BMP
 * range, so CONVERT preserves the existing equality, sort and uniqueness
 * behaviour. utf8mb4_unicode_ci uses full UCA weights that treat some distinct
 * strings as equal (e.g. 'ß' == 'ss'), which would both change search/sort
 * results and can make CONVERT fail with a duplicate-key error on a UNIQUE column
 * that holds two rows equal under UCA but distinct under general_ci — neither is
 * acceptable on a live install.
 *
 * The conversion has to reproduce a fresh install exactly, which forces three
 * corrections around a plain `ALTER TABLE ... CONVERT TO CHARACTER SET`:
 *
 *  1. FK-bound char columns. A column in a foreign key cannot have its charset
 *     changed while the constraint exists (error 1832), and disabling
 *     FOREIGN_KEY_CHECKS does not lift that DDL rule. Only char/varchar foreign
 *     keys are affected (integer keys are untouched by a charset change), so just
 *     those are dropped before the convert and recreated after — verbatim, with
 *     the same name, columns, reference and referential actions. struct.sql declares
 *     no ON DELETE/ON UPDATE rules (all default RESTRICT/NO ACTION, which SHOW CREATE
 *     omits), so the core keys re-emit nothing; but a third-party char/varchar FK with
 *     a non-default action (CASCADE / SET NULL / SET DEFAULT) has that action preserved
 *     so its data-integrity behaviour is not silently dropped by the convert.
 *
 *  2. TEXT widening. CONVERT preserves byte capacity, so it silently promotes
 *     TEXT->MEDIUMTEXT and MEDIUMTEXT->LONGTEXT (utf8mb4 is up to 4 bytes/char).
 *     A fresh install keeps the declared TEXT/MEDIUMTEXT types, so each widened
 *     column is shrunk back to its original type afterwards. Data authored under
 *     the old 3-byte TEXT limits always fits the same-tier utf8mb4 type.
 *
 *  3. FK index names. Recreating a foreign key through ALTER names its supporting
 *     index after the constraint, whereas a fresh install (inline FK at CREATE
 *     TABLE) names it after the column. Each recreated FK index is renamed back to
 *     the name it had before the drop so both paths match.
 *
 * Re-entrancy. MySQL auto-commits DDL and there is no rollback, so a CONVERT that
 * throws mid-run leaves the schema partly converted with the FKs already dropped;
 * the runner does not record the migration, so the next upgrade retries this file.
 * To make that retry fully correct, the char/varchar FK set and the original TEXT
 * column types are captured BEFORE anything is dropped and persisted durably into
 * t_preference. On a retry the snapshot is read back instead of being recomputed
 * from the now-FK-less live schema (where the dropped keys are invisible), so every
 * FK is recreated and every widened TEXT column is restored to its true original
 * type. Each step is guarded against the current live state (drop only existing
 * keys, recreate only missing ones, CONVERT/MODIFY are idempotent no-ops when
 * already at target), and the snapshot row is deleted only after a full success.
 *
 * Only tables owned by this install are touched — enumerated via
 * SHOW TABLES LIKE '<DB_TABLE_PREFIX>%' (with LIKE metacharacters in the prefix
 * escaped so `_` and `%` match literally) — so third-party tables sharing the same
 * database are never rewritten.
 *
 * Ancient-server caveat: on InnoDB before MySQL 5.7 / MariaDB 10.2 the 767-byte
 * index-prefix limit can reject the wider utf8mb4 columns. Such servers need
 * innodb_default_row_format=DYNAMIC (or ROW_FORMAT=DYNAMIC per table) plus
 * innodb_large_prefix=ON before this runs. Shopclass's supported floor
 * (MySQL 5.7+ / MariaDB 10.2+) defaults to DYNAMIC and a 3072-byte prefix, so no
 * per-table row-format clause is needed here; supporting older engines is out of
 * scope and documented rather than handled.
 */
return new class implements MigrationInterface {
    /** t_preference (s_section, s_name) coordinates of the durable re-entrancy snapshot. */
    private const BACKUP_SECTION = 'osclass';
    private const BACKUP_NAME    = 'utf8mb4_fk_backup';

    public function up(DBCommandClass $comm): void
    {
        $comm->query('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->installTables($comm);
        if ($tables === array()) {
            $comm->query('SET FOREIGN_KEY_CHECKS = 1');
            return;
        }

        // Durable snapshot for re-entrancy. On a fresh run compute the char/varchar
        // FK set and the original TEXT column types from the live (still-utf8) schema
        // and persist them. On a retry after an interrupted run, load that snapshot
        // instead: the dropped FKs and already-widened TEXT columns are no longer
        // recoverable from the live schema, so recomputing would lose them.
        $backup = $this->loadBackup($comm);
        if ($backup === null) {
            $charForeignKeys = $this->computeCharForeignKeys($comm, $tables);
            $textColumns     = $this->textColumns($comm, $tables);
            $backup = array('fks' => $charForeignKeys, 'texts' => $textColumns);
            $this->saveBackup($comm, $backup);
        }
        $charForeignKeys = $backup['fks'];
        $textColumns     = $backup['texts'];

        // Reconstruct the pre-drop index picture: start from the live indexes, then
        // fold in each FK's original support-index name from the snapshot so a retry
        // (whose live schema has already lost those indexes) can still restore them.
        $indexesBefore = $this->secondaryIndexes($comm, $tables);
        foreach ($charForeignKeys as $fk) {
            $signature = implode(',', $fk['columns']);
            if (($fk['index_name'] ?? null) !== null
                && !isset($indexesBefore[$fk['table']][$signature])
            ) {
                $indexesBefore[$fk['table']][$signature] = $fk['index_name'];
            }
        }

        // 1. Drop the char/varchar foreign keys so their columns become convertible.
        //    Guarded: only drop a key that still exists (a retry may have dropped it).
        $liveForeignKeys = $this->liveForeignKeyNames($comm, $tables);
        foreach ($charForeignKeys as $fk) {
            if (!isset($liveForeignKeys[$fk['table'] . '.' . $fk['name']])) {
                continue;
            }
            $this->run(
                $comm,
                'ALTER TABLE ' . $this->quoteIdent($fk['table'])
                . ' DROP FOREIGN KEY ' . $this->quoteIdent($fk['name']),
                'drop foreign key ' . $fk['name'] . ' on ' . $fk['table']
            );
        }

        // 2. Convert every table's charset/collation. Idempotent: converting a table
        //    already at utf8mb4/utf8mb4_general_ci is a harmless no-op.
        foreach ($tables as $table) {
            $this->run(
                $comm,
                'ALTER TABLE ' . $this->quoteIdent($table)
                . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
                'convert table ' . $table
            );
        }

        // 3. Undo the TEXT/MEDIUMTEXT widening the convert introduced. Idempotent:
        //    a column already at its original type is modified to the same type.
        foreach ($textColumns as $col) {
            $nullSql = $col['nullable'] ? ' DEFAULT NULL' : ' NOT NULL';
            $this->run(
                $comm,
                'ALTER TABLE ' . $this->quoteIdent($col['table'])
                . ' MODIFY ' . $this->quoteIdent($col['column']) . ' ' . $col['type'] . $nullSql,
                'restore type of ' . $col['table'] . '.' . $col['column']
            );
        }

        // 4. Recreate the char/varchar foreign keys; all tables are utf8mb4 now, so
        //    the parent/child column charsets match again. Recreate every snapshot key
        //    that is currently missing (not just the ones this invocation dropped), so
        //    a retry restores keys an earlier interrupted run had already dropped. Any
        //    non-default ON DELETE/ON UPDATE action is re-emitted so referential
        //    behaviour survives; RESTRICT/NO ACTION are the defaults and stay implicit.
        $liveForeignKeys = $this->liveForeignKeyNames($comm, $tables);
        foreach ($charForeignKeys as $fk) {
            if (isset($liveForeignKeys[$fk['table'] . '.' . $fk['name']])) {
                continue;
            }
            $this->run(
                $comm,
                'ALTER TABLE ' . $this->quoteIdent($fk['table'])
                . ' ADD CONSTRAINT ' . $this->quoteIdent($fk['name'])
                . ' FOREIGN KEY (' . $this->columnList($fk['columns']) . ')'
                . ' REFERENCES ' . $this->quoteIdent($fk['ref_table'])
                . ' (' . $this->columnList($fk['ref_columns']) . ')'
                . $this->referentialActions($fk['delete_rule'], $fk['update_rule']),
                'recreate foreign key ' . $fk['name'] . ' on ' . $fk['table']
            );
        }

        // 5. Rename any FK support index back to the name it had before recreation.
        $indexesAfter = $this->secondaryIndexes($comm, $tables);
        foreach ($indexesAfter as $table => $signatures) {
            foreach ($signatures as $signature => $currentName) {
                $originalName = $indexesBefore[$table][$signature] ?? null;
                if ($originalName !== null
                    && $originalName !== $currentName
                    && !$this->indexNameInUse($indexesAfter[$table], $originalName)
                ) {
                    $this->run(
                        $comm,
                        'ALTER TABLE ' . $this->quoteIdent($table)
                        . ' RENAME INDEX ' . $this->quoteIdent($currentName)
                        . ' TO ' . $this->quoteIdent($originalName),
                        'restore index name ' . $originalName . ' on ' . $table
                    );
                    $indexesAfter[$table][$signature] = $originalName;
                }
            }
        }

        // Full success: the conversion is complete and every FK is back, so the
        // durable snapshot is no longer needed. A partial failure above leaves it in
        // place for the next run to finish from.
        $this->deleteBackup($comm);

        $comm->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * The char/varchar foreign keys on this install, each annotated with the name of
     * its supporting index so the index name can be restored after recreation.
     *
     * @param string[] $tables
     *
     * @return array<int, array{name:string,table:string,columns:string[],ref_table:string,ref_columns:string[],delete_rule:string,update_rule:string,index_name:?string}>
     */
    private function computeCharForeignKeys(DBCommandClass $comm, array $tables): array
    {
        $charColumns = $this->charColumns($comm);
        $foreignKeys = $this->foreignKeys($comm, $tables);

        // Only foreign keys whose columns carry a charset (char/varchar) block the
        // convert; integer keys are left untouched so their indexes never move.
        $charForeignKeys = array_values(array_filter(
            $foreignKeys,
            static function ($fk) use ($charColumns) {
                foreach ($fk['columns'] as $col) {
                    if (isset($charColumns[$fk['table'] . '.' . $col])) {
                        return true;
                    }
                }
                return false;
            }
        ));

        $indexes = $this->secondaryIndexes($comm, $tables);
        foreach ($charForeignKeys as &$fk) {
            $signature = implode(',', $fk['columns']);
            $fk['index_name'] = $indexes[$fk['table']][$signature] ?? null;
        }
        unset($fk);

        return $charForeignKeys;
    }

    /**
     * Run one DDL statement, throwing on failure so the runner halts and does not
     * record the migration as applied.
     */
    private function run(DBCommandClass $comm, string $sql, string $what): void
    {
        if ($comm->query($sql) === false) {
            throw new RuntimeException('utf8mb4 migration: failed to ' . $what);
        }
    }

    /**
     * Load the durable re-entrancy snapshot, or null if no interrupted run left one.
     *
     * @return null|array{fks:array<int,array<string,mixed>>,texts:array<int,array<string,mixed>>}
     */
    private function loadBackup(DBCommandClass $comm): ?array
    {
        $result = $comm->query(
            'SELECT s_value FROM ' . $this->quoteIdent(DB_TABLE_PREFIX . 't_preference')
            . ' WHERE s_section = ' . $comm->escape(self::BACKUP_SECTION)
            . ' AND s_name = ' . $comm->escape(self::BACKUP_NAME)
        );
        if (!is_object($result)) {
            return null;
        }

        $rows = $result->result('array');
        if ($rows === array()) {
            return null;
        }

        $decoded = json_decode($rows[0]['s_value'], true);
        if (!is_array($decoded) || !isset($decoded['fks'], $decoded['texts'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Persist the re-entrancy snapshot into t_preference. REPLACE keys on the
     * (s_section, s_name) unique index, so a stale row from a prior run is overwritten.
     *
     * @param array{fks:array,texts:array} $backup
     */
    private function saveBackup(DBCommandClass $comm, array $backup): void
    {
        $json = json_encode($backup);
        if ($json === false) {
            throw new RuntimeException('utf8mb4 migration: unable to encode foreign-key backup');
        }

        $this->run(
            $comm,
            'REPLACE INTO ' . $this->quoteIdent(DB_TABLE_PREFIX . 't_preference')
            . ' (s_section, s_name, s_value, e_type) VALUES ('
            . $comm->escape(self::BACKUP_SECTION) . ', '
            . $comm->escape(self::BACKUP_NAME) . ', '
            . $comm->escape($json) . ', '
            . $comm->escape('STRING') . ')',
            'save foreign-key backup'
        );
    }

    /**
     * Delete the re-entrancy snapshot after a fully successful conversion.
     */
    private function deleteBackup(DBCommandClass $comm): void
    {
        $comm->query(
            'DELETE FROM ' . $this->quoteIdent(DB_TABLE_PREFIX . 't_preference')
            . ' WHERE s_section = ' . $comm->escape(self::BACKUP_SECTION)
            . ' AND s_name = ' . $comm->escape(self::BACKUP_NAME)
        );
    }

    /**
     * Constraint names of the foreign keys currently present on this install,
     * keyed "<table>.<constraint>". Used to guard drop/recreate against live state.
     *
     * @param string[] $tables
     *
     * @return array<string,true>
     */
    private function liveForeignKeyNames(DBCommandClass $comm, array $tables): array
    {
        $set = array();
        foreach ($this->foreignKeys($comm, $tables) as $fk) {
            $set[$fk['table'] . '.' . $fk['name']] = true;
        }

        return $set;
    }

    /**
     * Tables belonging to this install, matched by DB_TABLE_PREFIX.
     *
     * @return string[]
     */
    private function installTables(DBCommandClass $comm): array
    {
        $result = $comm->query("SHOW TABLES LIKE '" . $this->prefixLikePattern() . "'");
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to list tables for prefix ' . DB_TABLE_PREFIX);
        }

        $tables = array();
        foreach ($result->result('array') as $row) {
            // SHOW TABLES returns a single column keyed by "Tables_in_<db>"; read
            // it positionally so the key name is irrelevant.
            $tables[] = array_values($row)[0];
        }

        return $tables;
    }

    /**
     * DB_TABLE_PREFIX as a LIKE pattern that matches the literal prefix followed by
     * any suffix. LIKE metacharacters in the prefix (`_` single-char wildcard, `%`
     * multi-char wildcard, `\` the escape char) are escaped so the common `oc_`
     * prefix cannot spuriously match `ocX...` tables. Backslash is escaped first so
     * the escapes added for `_`/`%` are not doubled. Each escape carries the two
     * backslashes LIKE needs plus the doubling the SQL string-literal parser strips.
     */
    private function prefixLikePattern(): string
    {
        return str_replace(
            array('\\', '_', '%'),
            array('\\\\\\\\', '\\_', '\\%'),
            DB_TABLE_PREFIX
        ) . '%';
    }

    /**
     * Set of "<table>.<column>" for every char/varchar column on this install.
     *
     * @return array<string,true>
     */
    private function charColumns(DBCommandClass $comm): array
    {
        $result = $comm->query(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME LIKE '" . $this->prefixLikePattern() . "'"
            . " AND DATA_TYPE IN ('char', 'varchar')"
        );
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to read char columns');
        }

        $set = array();
        foreach ($result->result('array') as $row) {
            $set[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = true;
        }

        return $set;
    }

    /**
     * TEXT/MEDIUMTEXT columns whose declared type CONVERT would widen, captured
     * with their original type and nullability so they can be restored after.
     * LONGTEXT is already the widest tier and never widens, so it is skipped.
     *
     * @param string[] $tables
     *
     * @return array<int, array{table:string,column:string,type:string,nullable:bool}>
     */
    private function textColumns(DBCommandClass $comm, array $tables): array
    {
        $result = $comm->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME LIKE '" . $this->prefixLikePattern() . "'"
            . " AND DATA_TYPE IN ('tinytext', 'text', 'mediumtext')"
        );
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to read text columns');
        }

        $tableSet = array_flip($tables);
        $columns = array();
        foreach ($result->result('array') as $row) {
            if (!isset($tableSet[$row['TABLE_NAME']])) {
                continue;
            }
            $columns[] = array(
                'table'    => $row['TABLE_NAME'],
                'column'   => $row['COLUMN_NAME'],
                'type'     => $row['COLUMN_TYPE'],
                'nullable' => strtoupper($row['IS_NULLABLE']) === 'YES',
            );
        }

        return $columns;
    }

    /**
     * Foreign keys defined on this install's tables, grouped by constraint and
     * with columns ordered.
     *
     * @param string[] $tables
     *
     * @return array<int, array{name:string,table:string,columns:string[],ref_table:string,ref_columns:string[],delete_rule:string,update_rule:string}>
     */
    private function foreignKeys(DBCommandClass $comm, array $tables): array
    {
        $rules = $this->referentialRules($comm);

        $result = $comm->query(
            'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
            . ' FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL'
            . " AND TABLE_NAME LIKE '" . $this->prefixLikePattern() . "'"
            . ' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to read foreign keys');
        }

        $tableSet = array_flip($tables);
        $keys = array();
        foreach ($result->result('array') as $row) {
            $table = $row['TABLE_NAME'];
            if (!isset($tableSet[$table])) {
                continue;
            }
            $id = $table . '.' . $row['CONSTRAINT_NAME'];
            if (!isset($keys[$id])) {
                $keys[$id] = array(
                    'name'        => $row['CONSTRAINT_NAME'],
                    'table'       => $table,
                    'columns'     => array(),
                    'ref_table'   => $row['REFERENCED_TABLE_NAME'],
                    'ref_columns' => array(),
                    'delete_rule' => $rules[$id]['delete_rule'] ?? '',
                    'update_rule' => $rules[$id]['update_rule'] ?? '',
                );
            }
            $keys[$id]['columns'][]     = $row['COLUMN_NAME'];
            $keys[$id]['ref_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return array_values($keys);
    }

    /**
     * Referential actions (ON DELETE / ON UPDATE) per constraint, so a recreated
     * foreign key can re-emit any non-default rule. Keyed by "<table>.<constraint>"
     * to match the foreignKeys() grouping.
     *
     * @return array<string, array{delete_rule:string,update_rule:string}>
     */
    private function referentialRules(DBCommandClass $comm): array
    {
        $result = $comm->query(
            'SELECT CONSTRAINT_NAME, TABLE_NAME, UPDATE_RULE, DELETE_RULE'
            . ' FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . " AND TABLE_NAME LIKE '" . $this->prefixLikePattern() . "'"
        );
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to read referential constraints');
        }

        $rules = array();
        foreach ($result->result('array') as $row) {
            $rules[$row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME']] = array(
                'delete_rule' => (string) $row['DELETE_RULE'],
                'update_rule' => (string) $row['UPDATE_RULE'],
            );
        }

        return $rules;
    }

    /**
     * ON DELETE / ON UPDATE clause for a recreated foreign key. Only non-default
     * actions (CASCADE / SET NULL / SET DEFAULT) are emitted; RESTRICT and NO ACTION
     * are the engine defaults and omitted by SHOW CREATE, so emitting nothing for
     * them keeps the recreated SQL byte-identical to a fresh install.
     */
    private function referentialActions(string $deleteRule, string $updateRule): string
    {
        $nonDefault = array('CASCADE', 'SET NULL', 'SET DEFAULT');

        $clause = '';
        if (in_array(strtoupper($deleteRule), $nonDefault, true)) {
            $clause .= ' ON DELETE ' . strtoupper($deleteRule);
        }
        if (in_array(strtoupper($updateRule), $nonDefault, true)) {
            $clause .= ' ON UPDATE ' . strtoupper($updateRule);
        }

        return $clause;
    }

    /**
     * Non-PRIMARY indexes per table as a map of column-signature => index name.
     * Used to restore FK support index names after recreation.
     *
     * @param string[] $tables
     *
     * @return array<string, array<string,string>>
     */
    private function secondaryIndexes(DBCommandClass $comm, array $tables): array
    {
        $result = $comm->query(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME LIKE '" . $this->prefixLikePattern() . "'"
            . " AND INDEX_NAME <> 'PRIMARY'"
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        if (!is_object($result)) {
            throw new RuntimeException('utf8mb4 migration: unable to read indexes');
        }

        $tableSet = array_flip($tables);
        $byIndex = array();
        foreach ($result->result('array') as $row) {
            if (!isset($tableSet[$row['TABLE_NAME']])) {
                continue;
            }
            $key = $row['TABLE_NAME'] . "\0" . $row['INDEX_NAME'];
            $byIndex[$key][] = $row['COLUMN_NAME'];
        }

        $out = array();
        foreach ($byIndex as $key => $columns) {
            [$table, $index] = explode("\0", $key, 2);
            $signature = implode(',', $columns);
            $out[$table][$signature] = $index;
        }

        return $out;
    }

    /**
     * @param array<string,string> $signatures signature => index name for one table
     */
    private function indexNameInUse(array $signatures, string $name): bool
    {
        return in_array($name, $signatures, true);
    }

    /**
     * @param string[] $columns
     *
     * @return string backtick-quoted, comma-separated column list
     */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(function ($col) {
            return $this->quoteIdent($col);
        }, $columns));
    }

    /**
     * Quote a SQL identifier (table/column/constraint/index) for safe embedding in
     * DDL: wrap in backticks and double any embedded backtick.
     */
    private function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
};
