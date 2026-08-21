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
 * Two independent fixes to the entitlement layer, in one migration because both
 * were found by the same review and both need a schema change before the PHP fix
 * behind them is correct.
 *
 * 1. t_item.dt_first_pub_date -- the free posting quota's own timestamp, distinct
 *    from dt_pub_date, which item.bump's apply() moves on purpose to resort the
 *    listing. Counting dt_pub_date for the quota meant a paid bump silently
 *    refilled the free quota it exists to move past. Backfilling this column from
 *    dt_pub_date is what keeps an upgraded install's quota reading exactly as it
 *    did the moment before this migration ran -- every existing listing's first
 *    publish stays its first publish. Nothing but the insert in ItemActions::add()
 *    ever writes it again after this backfill.
 *
 * 2. t_user_entitlement gains a unique key on (fk_i_user_id, s_feature), so
 *    Entitlements::grant() can merge with a single atomic INSERT ... ON DUPLICATE
 *    KEY UPDATE instead of a read-then-write two concurrent purchases could race.
 *    A live install can already hold duplicate rows for the same (user, feature)
 *    pair -- grant() has never enforced uniqueness itself -- so those are merged
 *    first (summed quantity, latest expiry, earliest grant date) or the ALTER
 *    fails the moment it meets the first live duplicate. The old idx_user_feature
 *    index is dropped once the unique key covers the same lookup, so an upgraded
 *    install ends up with the same indexes a fresh one gets from struct.sql.
 *
 * Idempotent: every step is guarded, so a re-run after an interrupted upgrade is
 * safe -- including the dedupe, which finds nothing left to merge the second time.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $this->addFirstPublishDate($conn);
        $this->keyEntitlementsByUserFeature($conn);
    }

    private function addFirstPublishDate(Connection $conn): void
    {
        $item = DB_TABLE_PREFIX . 't_item';

        if (!$this->columnExists($conn, $item, 'dt_first_pub_date')) {
            $conn->execute('ALTER TABLE ' . $item . ' ADD COLUMN dt_first_pub_date DATETIME NULL');
        }

        $conn->execute(
            'UPDATE ' . $item . ' SET dt_first_pub_date = dt_pub_date WHERE dt_first_pub_date IS NULL'
        );

        if (!$this->indexExists($conn, $item, 'idx_user_first_pub')) {
            $conn->execute(
                'CREATE INDEX idx_user_first_pub ON ' . $item . ' (fk_i_user_id, dt_first_pub_date)'
            );
        }
    }

    private function keyEntitlementsByUserFeature(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_user_entitlement';

        if ($this->indexExists($conn, $table, 'uq_user_feature')) {
            return; // already migrated
        }

        $this->dedupeEntitlements($conn, $table);

        $conn->execute('ALTER TABLE ' . $table . ' ADD UNIQUE KEY uq_user_feature (fk_i_user_id, s_feature)');

        if ($this->indexExists($conn, $table, 'idx_user_feature')) {
            // Superseded by uq_user_feature, which covers the same lookup -- struct.sql
            // for a fresh install never declares both.
            $conn->execute('ALTER TABLE ' . $table . ' DROP INDEX idx_user_feature');
        }
    }

    /**
     * Collapse duplicate (fk_i_user_id, s_feature) rows into one before the unique
     * key is added -- the ALTER fails outright against any live duplicate. The
     * keeper is the lowest pk_i_id; a NULL quantity or expiration among the
     * duplicates ("unlimited"/"never") wins over any finite value, quantities
     * otherwise sum, and the earliest dt_date is kept.
     */
    private function dedupeEntitlements(Connection $conn, string $table): void
    {
        $conn->execute('DROP TEMPORARY TABLE IF EXISTS tmp_entitlement_dedupe');
        $conn->execute(
            'CREATE TEMPORARY TABLE tmp_entitlement_dedupe AS'
            . ' SELECT fk_i_user_id, s_feature, MIN(pk_i_id) AS keep_id,'
            . ' CASE WHEN SUM(i_quantity IS NULL) > 0 THEN NULL ELSE SUM(i_quantity) END AS merged_quantity,'
            . ' CASE WHEN SUM(dt_expiration IS NULL) > 0 THEN NULL ELSE MAX(dt_expiration) END AS merged_expiration,'
            . ' MIN(dt_date) AS merged_date'
            . ' FROM ' . $table
            . ' GROUP BY fk_i_user_id, s_feature'
            . ' HAVING COUNT(*) > 1'
        );

        $conn->execute(
            'UPDATE ' . $table . ' e'
            . ' JOIN tmp_entitlement_dedupe d ON e.pk_i_id = d.keep_id'
            . ' SET e.i_quantity = d.merged_quantity,'
            . ' e.dt_expiration = d.merged_expiration,'
            . ' e.dt_date = d.merged_date'
        );

        $conn->execute(
            'DELETE e FROM ' . $table . ' e'
            . ' JOIN tmp_entitlement_dedupe d'
            . ' ON e.fk_i_user_id = d.fk_i_user_id AND e.s_feature = d.s_feature'
            . ' WHERE e.pk_i_id <> d.keep_id'
        );

        $conn->execute('DROP TEMPORARY TABLE tmp_entitlement_dedupe');
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return (int) $count > 0;
    }

    /**
     * Whether an index (unique or not) named $index already exists on $table.
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND INDEX_NAME = ?',
            array($table, $index)
        );

        return (int) $count > 0;
    }
};
