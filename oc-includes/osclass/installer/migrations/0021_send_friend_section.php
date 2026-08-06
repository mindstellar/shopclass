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
 * Move the send-to-friend preferences out of the shared `osclass` section into
 * their own `send_friend` section, so they are grouped and cannot collide with a
 * like-named key from core or a plugin. Migration 0021 relocates any that exist,
 * preserving whatever value an admin already set; fresh installs seed them in the
 * new section directly (installer/basic_data.sql), so this only touches installs
 * that already ran 0021's predecessor.
 *
 * Idempotent: UPDATE IGNORE skips a name whose target (send_friend, name) already
 * exists — the unique key would otherwise block it — and the follow-up DELETE
 * drops any osclass leftover. So the target value always wins and a re-run after
 * an interrupted upgrade converges without clobbering the live setting.
 */
return new class () implements MigrationInterface {
    /** @var string[] */
    private const NAMES = array('enable_send_friend', 'reg_user_can_send_friend');

    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        foreach (self::NAMES as $name) {
            $conn->execute(
                'UPDATE IGNORE ' . $table
                . " SET s_section = 'send_friend' WHERE s_section = 'osclass' AND s_name = ?",
                array($name)
            );
            $conn->execute(
                'DELETE FROM ' . $table . " WHERE s_section = 'osclass' AND s_name = ?",
                array($name)
            );
        }
    }
};
