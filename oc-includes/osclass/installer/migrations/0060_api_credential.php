<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\apikey\ApiSettings;
use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Add t_api_credential for REST API keys and refresh tokens, and seed the `api` preferences.
 * A new, empty table, so nothing existing is touched; safe to re-run.
 *
 * @title REST API keys
 */
return new class () implements MigrationInterface {
    /** preference => e_type; the values are ApiSettings::DEFAULTS, as installer/basic_data.sql seeds them. */
    private const PREFERENCES = array(
        'api_enabled'                => 'BOOLEAN',
        'api_public_reads'           => 'BOOLEAN',
        'api_rate_limit_default'     => 'INTEGER',
        'api_rate_limit_anon'        => 'INTEGER',
        'api_rate_limit_write'       => 'INTEGER',
        'api_cors_origins'           => 'STRING',
        'api_cache_max_age'          => 'INTEGER',
        'api_hide_phone'             => 'BOOLEAN',
        'api_user_keys'              => 'BOOLEAN',
        'api_registration'           => 'BOOLEAN',
        'api_photo_urls'             => 'BOOLEAN',
        'api_listing_rate'           => 'INTEGER',
        'api_webhooks_allow_private' => 'BOOLEAN',
        'api_password_grant'         => 'BOOLEAN',
        'api_signups_per_hour'       => 'INTEGER',
        'api_photo_fetches_per_hour' => 'INTEGER',
        'api_refresh_days'           => 'INTEGER',
    );

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_api_credential ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . " e_kind ENUM('key', 'public', 'refresh') NOT NULL,"
            . ' s_token_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . ' s_secret_hash CHAR(64) NOT NULL,'
            . " s_name VARCHAR(100) NOT NULL DEFAULT '',"
            . ' s_scopes VARCHAR(500) NOT NULL,'
            . ' fk_i_user_id INT UNSIGNED NULL,'
            . ' fk_i_admin_id INT UNSIGNED NULL,'
            . ' s_family CHAR(16) NULL,'
            . ' i_rate_limit INT UNSIGNED NULL,'
            . ' b_enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . ' dt_expires DATETIME NULL,'
            . ' dt_revoked DATETIME NULL,'
            . ' dt_last_used DATETIME NULL,'
            . " s_last_ip VARCHAR(45) NOT NULL DEFAULT '',"
            . ' dt_created DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uk_token_id (s_token_id),'
            . ' INDEX idx_user (fk_i_user_id),'
            . ' INDEX idx_admin (fk_i_admin_id),'
            . ' INDEX idx_family (s_family),'
            . ' FOREIGN KEY (fk_i_user_id) REFERENCES ' . DB_TABLE_PREFIX . 't_user (pk_i_id) ON DELETE CASCADE,'
            . ' FOREIGN KEY (fk_i_admin_id) REFERENCES ' . DB_TABLE_PREFIX . 't_admin (pk_i_id) ON DELETE CASCADE'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        $table = DB_TABLE_PREFIX . 't_preference';
        foreach (self::PREFERENCES as $name => $type) {
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array(ApiSettings::SECTION, $name, ApiSettings::seedValue($name), $type)
            );
        }
    }
};
