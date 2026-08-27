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
 * Gives the three navigable billing pages -- the wallet, the package picker and
 * the buyer's own orders -- the rewrite preferences every other user-area route
 * has had since 3.x. Without them osc_billing_wallet_url() and its siblings had
 * nothing to build a path from and emitted a query string even on a site with
 * rewriting on, the only account links that did.
 *
 * They sit under the account prefix, beside user/dashboard and user/items,
 * because that is what they are -- account pages, not a section of their own.
 *
 * The POST targets (osc_billing_upgrade_url(), osc_item_upgrade_url()) get no
 * preference here on purpose: a form action is never read, shared or bookmarked,
 * and carrying an item id and a feature name through a path buys nothing for the
 * rewrite complexity it costs.
 *
 * Nothing breaks for an install that skips this: index.php answers the
 * query-string form regardless of what rules exist, so links already in the wild
 * -- and the gateway callback, which is deliberately left on ?page=billing --
 * keep resolving.
 *
 * Idempotent: INSERT IGNORE against the (s_section, s_name) unique key.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'rewrite_billing_wallet' => array('user/credits', 'STRING'),
        'rewrite_billing_buy'    => array('user/credits/buy', 'STRING'),
        'rewrite_billing_orders' => array('user/orders', 'STRING'),
    );

    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';
        foreach (self::PREFERENCES as $name => $spec) {
            [$value, $type] = $spec;
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $name, $value, $type)
            );
        }
    }
};
