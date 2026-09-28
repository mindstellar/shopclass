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
use mindstellar\migration\MigrationRunner;
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

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        // The lock MigrationRunner takes; taken here only for a direct call, since re-taking it
        // inside the runner's session would release it on MySQL before 5.7.5.
        $lock = (new MigrationRunner($conn, __DIR__))->lockName();
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
};
