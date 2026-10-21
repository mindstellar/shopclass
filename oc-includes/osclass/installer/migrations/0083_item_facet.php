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
use mindstellar\search\FacetIndex;

/**
 * Add t_item_facet, the filter index of custom field values and variant options, with
 * t_facet_term, the (key, value) terms its rows name, and fill it from the existing listings. Up to 5,000 listings are indexed here; more go to a background
 * job. Safe to re-run: the tables are made only when missing and a ready index is not rebuilt.
 *
 * @title Listing filter index
 */
return new class () implements MigrationInterface {
    /** Largest site indexed inside the upgrade request. */
    public const INLINE_LIMIT = 5000;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $existed = $conn->selectOne('SHOW TABLES LIKE ?', array(DB_TABLE_PREFIX . 't_item_facet')) !== null;
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_facet_term ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_key VARCHAR(48) NOT NULL,'
            . ' s_value VARCHAR(191) NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY idx_term (s_key, s_value))'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_item_facet ('
            . ' fk_i_term_id INT UNSIGNED NOT NULL,'
            . ' d_number DOUBLE NOT NULL DEFAULT 0,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' fk_i_variant_id INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (fk_i_term_id, d_number, fk_i_item_id, fk_i_variant_id),'
            . ' INDEX idx_item (fk_i_item_id, fk_i_term_id),'
            . ' FOREIGN KEY (fk_i_term_id) REFERENCES ' . DB_TABLE_PREFIX . 't_facet_term (pk_i_id) ON DELETE CASCADE,'
            . ' FOREIGN KEY (fk_i_item_id) REFERENCES ' . DB_TABLE_PREFIX . 't_item (pk_i_id) ON DELETE CASCADE)'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
        $conn->execute(
            'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
            array('osclass', FacetIndex::PREF_READY, '0', 'BOOLEAN')
        );
        if (!$existed) {
            // A ready flag restored from a backup says nothing about a table that was missing.
            $this->setPreference($conn, FacetIndex::PREF_READY, '0', 'BOOLEAN');
        }
        $this->build($conn, self::INLINE_LIMIT);
    }

    /**
     * Index every listing here when the site has at most $inlineLimit of them, else queue the
     * background build. Does nothing once the index is marked ready, unless the table is empty
     * while listings exist: a flag restored from a backup says nothing about this table.
     *
     * @param Connection $conn
     * @param int        $inlineLimit
     *
     * @throws \mindstellar\database\DbException
     */
    public function build(Connection $conn, int $inlineLimit): void
    {
        $flag = $conn->selectOne(
            'SELECT s_value FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'osclass' AND s_name = ?",
            array(FacetIndex::PREF_READY)
        );
        if ($flag !== null && $flag['s_value'] === '1'
            && ($conn->selectOne('SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_item_facet LIMIT 1') !== null
                || $conn->selectOne('SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_item LIMIT 1') === null)
        ) {
            return;
        }

        $count = (int) $conn->scalar('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_item');
        if ($count > $inlineLimit) {
            osc_job_ensure(FacetIndex::JOB_BUILD, array('after' => 0));

            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);
        $after = 0;
        while (($after = FacetIndex::buildStep($after, FacetIndex::BATCH)) !== null) {
        }
        $this->setPreference($conn, FacetIndex::PREF_READY, '1', 'BOOLEAN');
        $this->setPreference($conn, FacetIndex::PREF_BUILT, gmdate('Y-m-d H:i:s'), 'STRING');
        if (function_exists('osc_reset_preferences')) {
            osc_reset_preferences();
        }
    }

    private function setPreference(Connection $conn, string $name, string $value, string $type): void
    {
        $conn->execute(
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)',
            array('osclass', $name, $value, $type)
        );
    }
};
