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
 * Add t_login_attempt, the ledger the sign-in throttle counts.
 *
 * Nothing bounded how often a password could be guessed: there was no counter,
 * no delay and no lockout anywhere in core, so the only cost of an attempt was
 * the password hash itself. This table records a row per failed sign-in or
 * password-reset request so the throttle can count recent failures per source
 * address and per submitted account name.
 *
 * It is append-only and read with COUNT(*) over a rolling window, so the two
 * lookup indexes carry dt_date as their trailing column and a third exists only
 * for the daily prune. s_account is indexed on a 64-character prefix: it holds
 * whatever identifier was submitted, which is unbounded attacker-controlled
 * text, and 64 characters is far past the point where the count stops being
 * selective.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, so re-running after an interrupted
 * upgrade leaves the existing table alone.
 */
return new class implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_login_attempt';

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_context VARCHAR(20) NOT NULL DEFAULT \'\','
            . ' s_account VARCHAR(191) NOT NULL DEFAULT \'\','
            . ' s_ip VARCHAR(45) NOT NULL DEFAULT \'\','
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_ip (s_ip, dt_date),'
            . ' INDEX idx_account (s_context, s_account(64), dt_date),'
            . ' INDEX idx_date (dt_date)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );
    }
};
