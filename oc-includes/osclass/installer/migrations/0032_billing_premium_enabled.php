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
 * Split listing.premium's existence from its price, the same way 0030/0031 already did
 * for the six newer billing features: billing_premium_enabled is a new preference,
 * separate from billing_premium_credits, so featuring can be priced at 0 and still be
 * for sale -- something the old "0 credits means unavailable" rule could not express.
 *
 * This is a backfill, not just a seed. An install that already priced featuring
 * (billing_premium_credits > 0) gets billing_premium_enabled = 1, so it keeps selling
 * exactly what it was selling. An install at 0 credits -- which meant "unavailable"
 * under the old rule -- gets billing_premium_enabled = 0, so it stays unavailable.
 * Either way the change is invisible to an existing site.
 *
 * Idempotent: does nothing once the key exists, so a re-run after an interrupted
 * upgrade cannot overwrite an admin's own choice made since.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';

        $exists = $conn->scalar(
            'SELECT s_value FROM ' . $table . ' WHERE s_section = ? AND s_name = ?',
            array('osclass', 'billing_premium_enabled')
        );
        if ($exists !== null) {
            return;
        }

        $credits = $conn->scalar(
            'SELECT s_value FROM ' . $table . ' WHERE s_section = ? AND s_name = ?',
            array('osclass', 'billing_premium_credits')
        );
        $enabled = ((int) $credits) > 0 ? '1' : '0';

        $conn->execute(
            'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
            array('osclass', 'billing_premium_enabled', $enabled, 'BOOLEAN')
        );
    }
};
