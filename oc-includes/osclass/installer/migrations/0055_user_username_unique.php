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
 * Make t_user.s_username unique. An empty username becomes the user id (or id_2, id_3, ...
 * when that is taken), and every duplicate but the oldest account gets "_<id>" appended,
 * compared as the column's collation compares them. Then uk_user_username replaces
 * idx_s_username. Each step reads the current rows, so a re-run is safe.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** Length of t_user.s_username. */
    private const MAX = 100;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_user';
        if (!$this->tableExists($conn, $table)) {
            return;
        }

        // The lock UserActions::claimUsername() takes, so a sign-up cannot claim a name mid-way.
        $lock = 'osc_username_' . md5((defined('DB_NAME') ? DB_NAME : '') . $table);
        if ((int) $conn->scalar('SELECT GET_LOCK(?, 30)', array($lock)) !== 1) {
            throw new \RuntimeException('Could not take the username lock; run the upgrade again.');
        }
        try {
            $this->fillEmpty($conn, $table);
            $this->renameDuplicates($conn, $table);
            if ($this->uniqueUsernameIndexes($conn, $table) === array()) {
                $conn->execute('ALTER TABLE ' . $table . ' ADD UNIQUE KEY uk_user_username (s_username), ALGORITHM=INPLACE, LOCK=NONE');
            }
            if (!in_array('idx_s_username', $this->uniqueUsernameIndexes($conn, $table), true)
                && in_array('idx_s_username', $this->indexesOnColumns($conn, $table, array('s_username')), true)
            ) {
                $conn->execute('ALTER TABLE ' . $table . ' DROP INDEX idx_s_username, ALGORITHM=INPLACE, LOCK=NONE');
            }
        } finally {
            $conn->scalar('SELECT RELEASE_LOCK(?)', array($lock));
        }
    }

    /**
     * Give every empty username the user id, or the id with the first free suffix.
     *
     * @param Connection $conn
     * @param string     $table
     *
     * @throws \mindstellar\database\DbException
     */
    private function fillEmpty(Connection $conn, string $table): void
    {
        // Most ids are free as names, so they are set in one statement.
        $conn->execute(
            'UPDATE ' . $table . ' u LEFT JOIN ' . $table . " t ON t.s_username = CONCAT('', u.pk_i_id)"
            . " SET u.s_username = CONCAT('', u.pk_i_id) WHERE u.s_username = '' AND t.pk_i_id IS NULL"
        );

        $rows = $conn->select('SELECT pk_i_id FROM ' . $table . " WHERE s_username = '' ORDER BY pk_i_id");
        foreach ($rows as $row) {
            $id   = (int) $row['pk_i_id'];
            $name = $this->freeName($conn, $table, (string) $id, 2);
            $conn->execute('UPDATE ' . $table . " SET s_username = ? WHERE pk_i_id = ? AND s_username = ''", array($name, $id));
        }
    }

    /**
     * Keep each duplicated username on its oldest account and append "_<id>" to the rest.
     *
     * @param Connection $conn
     * @param string     $table
     *
     * @throws \mindstellar\database\DbException
     */
    private function renameDuplicates(Connection $conn, string $table): void
    {
        $groups = $conn->select('SELECT s_username FROM ' . $table . ' GROUP BY s_username HAVING COUNT(*) > 1');
        foreach ($groups as $group) {
            $rows = $conn->select(
                'SELECT pk_i_id, s_username FROM ' . $table . ' WHERE s_username = ? ORDER BY pk_i_id',
                array((string) $group['s_username'])
            );
            array_shift($rows);
            foreach ($rows as $row) {
                $id   = (int) $row['pk_i_id'];
                $base = $this->fit((string) $row['s_username'], '_' . $id);
                $name = $this->freeName($conn, $table, $base, 1);
                $conn->execute(
                    'UPDATE ' . $table . ' SET s_username = ? WHERE pk_i_id = ? AND s_username = ?',
                    array($name, $id, (string) $row['s_username'])
                );
            }
        }
    }

    /**
     * $base when no row holds it, else $base_2, $base_3, ... from suffix $n.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string     $base
     * @param int        $n     First suffix to try; 1 tries $base itself first
     *
     * @return string
     * @throws \mindstellar\database\DbException
     */
    private function freeName(Connection $conn, string $table, string $base, int $n): string
    {
        for (; ; $n++) {
            $name = $n === 1 ? $base : $this->fit($base, '_' . $n);
            if ((int) $conn->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE s_username = ?', array($name)) === 0) {
                return $name;
            }
        }
    }

    /**
     * $name with $suffix appended, the name cut short so the result fits the column.
     *
     * @param string $name
     * @param string $suffix
     *
     * @return string
     */
    private function fit(string $name, string $suffix): string
    {
        return mb_substr($name, 0, self::MAX - mb_strlen($suffix)) . $suffix;
    }

    /**
     * Names of the unique indexes on exactly (s_username).
     *
     * @param Connection $conn
     * @param string     $table
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    private function uniqueUsernameIndexes(Connection $conn, string $table): array
    {
        return array_values(array_filter(
            $this->indexesOnColumns($conn, $table, array('s_username')),
            fn ($name) => (int) $conn->scalar(
                'SELECT NON_UNIQUE FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                array($table, $name)
            ) === 0
        ));
    }
};
