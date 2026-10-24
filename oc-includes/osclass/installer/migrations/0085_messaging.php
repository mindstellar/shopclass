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
 * Add t_conversation and t_message for on-site messages between a buyer and a seller, and the
 * `enabled_messaging` switch (off). Safe to re-run: tables are made only when missing and the
 * preference is never overwritten.
 *
 * @title On-site messaging
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_conversation ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' fk_i_buyer_id INT UNSIGNED NOT NULL,'
            . ' fk_i_seller_id INT UNSIGNED NOT NULL,'
            . ' s_state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'open\','
            . ' i_buyer_unread SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_seller_unread SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' b_buyer_blocked TINYINT(1) NOT NULL DEFAULT 0,'
            . ' b_seller_blocked TINYINT(1) NOT NULL DEFAULT 0,'
            . ' fk_i_reporter_id INT UNSIGNED NULL,'
            . ' s_report_reason VARCHAR(20) NULL,'
            . ' dt_created DATETIME NOT NULL,'
            . ' dt_last_message DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY idx_item_buyer (fk_i_item_id, fk_i_buyer_id, fk_i_seller_id),'
            . ' INDEX idx_buyer (fk_i_buyer_id, dt_last_message),'
            . ' INDEX idx_seller (fk_i_seller_id, dt_last_message),'
            . ' INDEX idx_state (s_state),'
            . ' FOREIGN KEY (fk_i_buyer_id) REFERENCES ' . DB_TABLE_PREFIX . 't_user (pk_i_id) ON DELETE CASCADE,'
            . ' FOREIGN KEY (fk_i_seller_id) REFERENCES ' . DB_TABLE_PREFIX . 't_user (pk_i_id) ON DELETE CASCADE'
            . ' ) ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\'',
        );
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_message ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_conversation_id INT UNSIGNED NOT NULL,'
            . ' fk_i_sender_id INT UNSIGNED NOT NULL,'
            . ' s_body TEXT NOT NULL,'
            . ' s_state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'sent\','
            . ' dt_created DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_conversation (fk_i_conversation_id, pk_i_id),'
            . ' INDEX idx_state (s_state),'
            . ' FOREIGN KEY (fk_i_conversation_id) REFERENCES ' . DB_TABLE_PREFIX . 't_conversation (pk_i_id) ON DELETE CASCADE'
            . ' ) ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\'',
        );
        $conn->execute(
            'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
            array('osclass', 'enabled_messaging', '0', 'BOOLEAN')
        );
    }
};
