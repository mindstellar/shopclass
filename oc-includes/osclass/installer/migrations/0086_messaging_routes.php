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
 * Add the friendly paths for the inbox (user/messages) and a thread (user/messages/{id}).
 * Safe to re-run: existing rows are kept.
 *
 * @title Messaging URLs
 */
return new class () implements MigrationInterface {
    /** key => [default path, e_type]; mirrors installer/basic_data.sql. The thread id is added to the path. */
    private const PREFERENCES = array(
        'rewrite_messages'        => array('user/messages', 'STRING'),
        'rewrite_messages_thread' => array('user/messages', 'STRING'),
    );

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';
        foreach (self::PREFERENCES as $name => [$value, $type]) {
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $name, $value, $type)
            );
        }
    }
};
