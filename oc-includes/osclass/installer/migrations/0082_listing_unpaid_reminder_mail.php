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
 * Add the e-mail template for the reminder to pay for a saved listing. The row is found by
 * name, never by id, because admin pages share the table and a fixed id may be taken.
 * Safe to re-run: the page and its text are added only when missing.
 *
 * @title Unpaid listing reminder mail
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $name = 'email_listing_unpaid_reminder';
        $id   = $conn->scalar('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_pages WHERE s_internal_name = ?', [$name]);
        if ($id === null || $id === false) {
            $id = $conn->insertGetId(
                'INSERT INTO ' . DB_TABLE_PREFIX . 't_pages (s_internal_name, b_indelible, dt_pub_date) VALUES (?, 1, NOW())',
                [$name]
            );
        }
        $conn->execute(
            'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_pages_description (fk_i_pages_id, fk_c_locale_code, s_title, s_text)'
            . " SELECT ?, pk_c_code, ?, ? FROM " . DB_TABLE_PREFIX . "t_locale WHERE pk_c_code = 'en_US'",
            [
                (int) $id,
                '{WEB_TITLE} - Pay to publish your listing',
                '<p>Hi {USER_NAME},</p><p>Your listing <a href="{PAY_URL}">{ITEM_TITLE}</a> is saved but not published. '
                . 'It costs {CREDITS} credits to publish.</p><p>Pay for it by {DEADLINE} or it is deleted: {PAY_LINK}</p>'
                . '<p>Regards,</p><p>{WEB_LINK}</p>',
            ]
        );
    }
};
