<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

use mindstellar\listing\ListingStore;

/**
 * Time-limited premium listings.
 *
 * A premium listing is exempt from expiry -- Item::liveConditions() reads b_premium = 1
 * as "live regardless of dt_expiration" -- so nothing else in the codebase will ever
 * stop showing one. Clearing the flag when its date passes is what makes a premium
 * upgrade sellable by duration instead of granted forever, and it is the only reason
 * dt_premium_expiration exists.
 *
 * @package mindstellar\billing
 */
final class Premium
{
    /**
     * End every upgrade whose date has passed.
     *
     * Rows with a NULL dt_premium_expiration are left alone: that is the permanent
     * upgrade an admin grants by hand, which has no end date by design.
     *
     * @return int how many upgrades were ended
     */
    public static function expire(): int
    {
        $rows = ListingStore::endedPremium(date('Y-m-d H:i:s'));
        if ($rows === array()) {
            return 0;
        }

        $ids = array_map(static function ($row) {
            return (int) $row['pk_i_id'];
        }, $rows);

        ListingStore::clearPremium($ids);

        // The same notification an admin unmarking a listing sends, so a plugin
        // reacting to premium ending need not know whether a person or the clock
        // ended it.
        foreach ($ids as $id) {
            osc_run_hook('item_premium_off', $id);
        }

        // An expired listing was counted only because it was premium.
        foreach ($rows as $row) {
            if (osc_item_is_counted($row) && !osc_item_is_counted(array('b_premium' => 0) + $row)) {
                \ItemActions::decreaseStatsFor((int) $row['pk_i_id']);
            }
        }

        return count($ids);
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Premium.php */
