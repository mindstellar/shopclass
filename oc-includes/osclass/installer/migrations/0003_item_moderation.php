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
 * Item-moderation tables and preferences for the keyword blocklist, item
 * reporting and the "why hidden" moderation log.
 *
 * All three CREATE statements are IF NOT EXISTS and every preference is seeded
 * with INSERT IGNORE, so the step is idempotent and safe to re-run after an
 * interrupted upgrade. The same tables and preferences are declared in
 * installer/struct.sql / installer/basic_data.sql for a fresh install, which the
 * runner baselines rather than replays; this migration is what brings an existing
 * install up to the same state.
 *
 * t_item_report_log is created with the exact column set and charset an already
 * installed classifieds theme uses for its own IF NOT EXISTS import of the report
 * log, so whichever side creates the table first, both read and write the same
 * structure during the window before the theme defers to core.
 */
return new class implements MigrationInterface {
    /** Section the core item-moderation preferences live under. */
    private const SECTION = 'moderation';

    /**
     * key => [default value, e_type]. Mirrors installer/basic_data.sql so a fresh
     * install and an upgraded install converge on the same seed. Only seeded when
     * absent — a deliberate '0' set by an operator is never overwritten.
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const PREFERENCES = array(
        'keyword_spam_enabled'    => array('0', 'BOOLEAN'),
        'keyword_spam_hard_block' => array('0', 'BOOLEAN'),
        'report_autoblock'        => array('1', 'BOOLEAN'),
        'report_threshold'        => array('5', 'INTEGER'),
    );

    public function up(DBCommandClass $comm): void
    {
        $this->createKeywordBlock($comm);
        $this->createReportLog($comm);
        $this->createModerationLog($comm);
        $this->seedPreferences($comm);
    }

    private function createKeywordBlock(DBCommandClass $comm): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_keyword_block ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . " s_keyword VARCHAR(191) NOT NULL DEFAULT '',"
            . " s_scope ENUM('title','description','all','meta') NOT NULL DEFAULT 'all',"
            . ' b_substring TINYINT(1) NOT NULL DEFAULT 0,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";

        if ($comm->query($sql) === false) {
            throw new RuntimeException('item moderation migration: failed to create t_keyword_block');
        }
    }

    private function createReportLog(DBCommandClass $comm): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_item_report_log ('
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' s_reporter   VARCHAR(70) NOT NULL,'
            . ' fk_i_user_id INT UNSIGNED NULL,'
            . ' s_ip         VARCHAR(64) NULL,'
            . ' s_reason     VARCHAR(20) NOT NULL,'
            . ' dt_date      DATETIME NOT NULL,'
            . ' PRIMARY KEY (fk_i_item_id, s_reporter)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI'";

        if ($comm->query($sql) === false) {
            throw new RuntimeException('item moderation migration: failed to create t_item_report_log');
        }
    }

    private function createModerationLog(DBCommandClass $comm): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_item_moderation_log ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . " s_source VARCHAR(20) NOT NULL DEFAULT '',"
            . " s_reason VARCHAR(191) NOT NULL DEFAULT '',"
            . " s_field VARCHAR(20) NOT NULL DEFAULT '',"
            . " s_action VARCHAR(20) NOT NULL DEFAULT '',"
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' KEY fk_i_item_id (fk_i_item_id)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";

        if ($comm->query($sql) === false) {
            throw new RuntimeException('item moderation migration: failed to create t_item_moderation_log');
        }
    }

    private function seedPreferences(DBCommandClass $comm): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        foreach (self::PREFERENCES as $name => $spec) {
            [$default, $type] = $spec;

            $sql = 'INSERT IGNORE INTO ' . $table
                . ' (s_section, s_name, s_value, e_type) VALUES ('
                . $comm->escape(self::SECTION) . ', '
                . $comm->escape($name) . ', '
                . $comm->escape($default) . ', '
                . $comm->escape($type) . ')';

            if ($comm->query($sql) === false) {
                throw new RuntimeException('item moderation migration: failed to seed ' . $name);
            }
        }
    }
};
