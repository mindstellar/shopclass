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
 * Add the foreign key t_form_submission.fk_i_user_id never had, so deleting a user removes
 * their form messages with them instead of leaving them behind. Deleting a submission
 * cascades to t_form_submission_value. Orphans are cleared first on every run.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    private const CHILD         = 't_form_submission';
    private const COLUMN        = 'fk_i_user_id';
    private const PARENT        = 't_user';
    private const PARENT_COLUMN = 'pk_i_id';
    private const RULE          = 'CASCADE';

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
            $this->addKey($conn);
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
    private function addKey(Connection $conn): void
    {
        $child  = DB_TABLE_PREFIX . self::CHILD;
        $parent = DB_TABLE_PREFIX . self::PARENT;
        if (!$this->tableExists($conn, $child) || !$this->tableExists($conn, $parent)
            || !$this->columnExists($conn, $child, self::COLUMN)
        ) {
            return;
        }

        $names = $this->constraintNames($conn, $child, self::COLUMN, $parent);
        $done  = count($names) === 1 && $this->deleteRule($conn, $child, $names[0]) === self::RULE;

        $pending = array(array(
            'column'       => self::COLUMN,
            'parent'       => $parent,
            'parentColumn' => self::PARENT_COLUMN,
            'rule'         => self::RULE,
        ));

        // A type mismatch would break the orphan join, so it is aligned first.
        if (!$done) {
            $this->alignColumns($conn, $child, $pending, false);
        }

        // Orphans are cleared every run, even when the key is already correct, since a
        // manual repair may have added it without checking the rows.
        $this->clearOrphans($conn, $child, self::COLUMN, $parent, self::PARENT_COLUMN, 'delete');

        if ($done) {
            return;
        }
        $this->alignColumns($conn, $child, $pending, true);

        $checks = (int) $conn->scalar('SELECT @@SESSION.foreign_key_checks');
        $conn->execute('SET SESSION foreign_key_checks = 0');
        try {
            foreach ($names as $name) {
                $conn->execute('ALTER TABLE ' . $child . ' DROP FOREIGN KEY ' . $name);
            }
            $conn->execute(
                'ALTER TABLE ' . $child . ' ADD FOREIGN KEY (' . self::COLUMN . ')'
                . ' REFERENCES ' . $parent . ' (' . self::PARENT_COLUMN . ') ON DELETE ' . self::RULE
                . ', ALGORITHM=INPLACE, LOCK=NONE'
            );
        } finally {
            $conn->execute('SET SESSION foreign_key_checks = ' . $checks);
        }
    }
};
