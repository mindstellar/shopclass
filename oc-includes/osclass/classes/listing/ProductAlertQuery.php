<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\listing;

use mindstellar\database\Db;
use mindstellar\search\AlertEnvelope;

/**
 * The reads behind product alerts: a listing with its variants' prices, and the alerts that
 * wait on a listing.
 */
final class ProductAlertQuery
{
    /**
     * A listing's live-rule columns and price, one row per variant (variant_id null when it
     * has none), in one primary-key read.
     *
     * @return array<int,array<string,mixed>> empty when there is no such listing
     * @throws \mindstellar\database\DbException
     */
    public static function listingPrices(int $listingId): array
    {
        return Db::select(
            'SELECT i.b_enabled, i.b_active, i.b_spam, i.b_premium, i.dt_expiration, i.i_price, v.pk_i_id AS variant_id, v.i_price AS variant_price'
            . ' FROM ' . DB_TABLE_PREFIX . 't_item i LEFT JOIN ' . VariantStore::tableName() . ' v ON v.fk_i_item_id = i.pk_i_id'
            . ' WHERE i.pk_i_id = ?',
            array($listingId)
        );
    }

    /**
     * The live product alerts of one kind on a listing, by id after $after. The range is on
     * idx_type (e_type, b_active, dt_unsub_date), so the LIKE reads product alerts only, never the
     * saved searches; ordering by the index's columns keeps the planner on it, since ORDER BY
     * pk_i_id alone made it walk the primary key through the whole table.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function waiting(string $kind, int $itemId, int $after, int $limit): array
    {
        return Db::select(
            'SELECT * FROM ' . DB_TABLE_PREFIX . 't_alerts WHERE e_type = ? AND b_active = 1 AND dt_unsub_date IS NULL'
            . ' AND s_search LIKE ? AND pk_i_id > ? ORDER BY e_type, b_active, dt_unsub_date, pk_i_id LIMIT ' . max(1, $limit),
            array(\Alerts::PRODUCT, AlertEnvelope::productPattern($itemId, $kind), $after)
        );
    }
}
