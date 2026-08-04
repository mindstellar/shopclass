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
 * Remove the dead "instant" alert email template.
 *
 * The INSTANT alert type was never dispatched — cron only ran HOURLY/DAILY/WEEKLY —
 * so its mail builder and hook are gone and struct.sql no longer seeds the
 * alert_email_instant page. This drops the seeded (b_indelible, so the admin UI
 * can't) template from existing installs: its per-locale descriptions first to
 * satisfy the foreign key, then the page itself.
 *
 * Idempotent: a DELETE matching no rows is a no-op, so re-running is safe.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $conn->execute(
            'DELETE d FROM ' . DB_TABLE_PREFIX . 't_pages_description d'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_pages p ON p.pk_i_id = d.fk_i_pages_id'
            . ' WHERE p.s_internal_name = ?',
            ['alert_email_instant']
        );

        $conn->execute(
            'DELETE FROM ' . DB_TABLE_PREFIX . 't_pages WHERE s_internal_name = ?',
            ['alert_email_instant']
        );
    }
};
