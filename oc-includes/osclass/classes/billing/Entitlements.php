<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

/**
 * What a user is entitled to: a quantity, a duration, or both, per feature.
 *
 * One row per (user, feature) is the working assumption throughout -- grant() merges
 * into it rather than appending, and the other readers all take the newest unexpired
 * match. An expired row is inert data left for purge() to clear; nothing here treats
 * it as current.
 *
 * @package mindstellar\billing
 */
final class Entitlements
{
    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_GRANT    = 'grant';
    public const SOURCE_PLAN     = 'plan';
    public const SOURCE_DEFAULT  = 'default';

    /** Unprefixed table name. */
    private const TABLE = 't_user_entitlement';

    /**
     * Add to or extend a user's entitlement for $feature, creating the row if none
     * exists yet.
     *
     * A quantity adds to whatever the row already holds; unlimited (NULL) absorbs a
     * quantity grant and stays unlimited -- a buyer already holding "unlimited" is
     * never knocked down to a finite number by topping up. A duration extends
     * dt_expiration from whichever is later, now or the row's current expiry, so
     * buying more time while some remains is additive rather than a discount. A row
     * whose expiration is already NULL ("never") stays NULL -- a duration grant cannot
     * shorten a permanent entitlement into a time-limited one.
     *
     * One statement, not a read then a write: uq_user_feature on (fk_i_user_id,
     * s_feature) means the merge itself is what MySQL applies atomically, so two
     * concurrent purchases can no longer lose one to the other.
     */
    public static function grant(
        int $userId,
        string $feature,
        ?int $quantity = null,
        ?int $days = null,
        string $source = self::SOURCE_PURCHASE
    ): bool {
        $now   = date('Y-m-d H:i:s');
        $table = self::table();

        $params = array(
            $userId,
            $feature,
            $quantity,
            $days !== null ? self::addDays($now, $days) : null,
            $source,
            $now,
        );

        $setParts = array();
        if ($quantity !== null) {
            $setParts[] = 'i_quantity = IF(i_quantity IS NULL, NULL, i_quantity + ?)';
            $params[]   = $quantity;
        }
        if ($days !== null) {
            // GREATEST(NULL, ?) is NULL in MySQL, so a permanent row (dt_expiration
            // already NULL) is left untouched by this assignment and stays NULL.
            // Otherwise extend from whichever is later, now or the row's current
            // expiry -- the same rule the docblock above describes, computed here
            // instead of read-then-written so it stays inside the one statement.
            $setParts[] = 'dt_expiration = DATE_ADD(GREATEST(dt_expiration, ?), INTERVAL ? DAY)';
            $params[]   = $now;
            $params[]   = $days;
        }
        if ($setParts === array()) {
            // A grant supplying neither quantity nor days still has to be valid SQL
            // and still has to insert a row when none exists; this no-op keeps the
            // statement legal without touching an existing row.
            $setParts[] = 'pk_i_id = pk_i_id';
        }

        osc_db_execute(
            'INSERT INTO ' . $table
            . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $setParts),
            $params
        );

        return true;
    }

    /**
     * Whether $userId currently holds $feature: an unexpired row with a positive or
     * unlimited (NULL) quantity.
     */
    public static function has(int $userId, string $feature): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = osc_db_table(self::table())
            ->where('fk_i_user_id', $userId)
            ->where('s_feature', $feature)
            ->whereRaw('(dt_expiration IS NULL OR dt_expiration > ?)', array($now))
            ->whereRaw('(i_quantity IS NULL OR i_quantity > 0)', array())
            ->first();

        return $row !== null;
    }

    /**
     * Remaining quantity for $feature, or -1 for unlimited. 0 when there is no
     * unexpired row at all.
     */
    public static function quantity(int $userId, string $feature): int
    {
        $row = osc_db_table(self::table())
            ->where('fk_i_user_id', $userId)
            ->where('s_feature', $feature)
            ->whereRaw('(dt_expiration IS NULL OR dt_expiration > ?)', array(date('Y-m-d H:i:s')))
            ->orderBy('pk_i_id', 'DESC')
            ->first();

        if ($row === null) {
            return 0;
        }

        return $row['i_quantity'] === null ? -1 : (int) $row['i_quantity'];
    }

    /**
     * Spend $n of $feature's quantity.
     *
     * The deduction is one conditional UPDATE, not a read followed by a write -- the
     * same reasoning as Wallet::debit(): two concurrent posts spending the last unit
     * cannot both succeed, because the second matches no row. Zero rows affected is a
     * normal "not enough" outcome the caller handles, not an exception.
     *
     * @return bool false when there is not enough quantity left
     */
    public static function consume(int $userId, string $feature, int $n = 1): bool
    {
        if ($n <= 0) {
            return true;
        }

        // A capacity feature is a ceiling that is read, not spent -- refuse before
        // touching a row at all, so a mistaken call (ours or a plugin's) cannot drain
        // one. A feature id with no registry entry cannot be checked this way and
        // falls through to the ordinary spend below, same as before this guard.
        $registered = FeatureRegistry::instance()->get($feature);
        if ($registered !== null && $registered->getConsumes() === Feature::CONSUMES_CAPACITY) {
            return false;
        }

        $now   = date('Y-m-d H:i:s');
        $table = self::table();

        // LIMIT 1 because a user may hold more than one row for the same feature -- grant()
        // merges, but a plugin granting directly need not. Without it a single spend would
        // decrement every one of them. The soonest to expire goes first, so quantity that is
        // about to lapse is used before quantity that is not.
        $affected = osc_db_execute(
            'UPDATE ' . $table . ' SET i_quantity = i_quantity - ?'
            . ' WHERE fk_i_user_id = ? AND s_feature = ? AND i_quantity IS NOT NULL AND i_quantity >= ?'
            . ' AND (dt_expiration IS NULL OR dt_expiration > ?)'
            . ' ORDER BY dt_expiration IS NULL ASC, dt_expiration ASC, pk_i_id ASC LIMIT 1',
            array($n, $userId, $feature, $n, $now)
        );

        if ($affected > 0) {
            return true;
        }

        // The UPDATE above can never match a NULL quantity, so failing it does not
        // yet mean "insufficient" -- an unlimited row consumes nothing and reports
        // success. Nothing is written on this path, so there is no race to guard.
        return (bool) osc_db_scalar(
            'SELECT 1 FROM ' . $table
            . ' WHERE fk_i_user_id = ? AND s_feature = ? AND i_quantity IS NULL'
            . ' AND (dt_expiration IS NULL OR dt_expiration > ?) LIMIT 1',
            array($userId, $feature, $now)
        );
    }

    /**
     * The larger of $default and the largest i_quantity among $userId's unexpired
     * rows for $feature.
     *
     * A capacity entitlement can only ever RAISE a limit, never lower one: $default
     * is a floor, not merely a fallback for "no row at all". Without that floor, a
     * global cap of 20 plus a bought entitlement of 10 would leave the paying seller
     * with 10, and a global cap read as unlimited by the caller's own convention
     * would be capped outright by the upsell.
     *
     * Read-only: this never calls consume(), and a capacity row is not meant to be
     * spendable by anything else either -- it is a ceiling, checked on every read,
     * not a balance that runs out. A row with i_quantity IS NULL (unlimited) returns
     * -1 unconditionally (it already beats any finite $default); every caller MUST
     * treat -1 as unlimited rather than compare it numerically, or unlimited reads
     * as "less than everything."
     */
    public static function capacity(int $userId, string $feature, int $default = 0): int
    {
        $rows = osc_db_table(self::table())
            ->where('fk_i_user_id', $userId)
            ->where('s_feature', $feature)
            ->whereRaw('(dt_expiration IS NULL OR dt_expiration > ?)', array(date('Y-m-d H:i:s')))
            ->get();

        if ($rows === array()) {
            return $default;
        }

        $best = null;
        foreach ($rows as $row) {
            if ($row['i_quantity'] === null) {
                return -1; // unlimited beats any finite quantity among the rest, and $default
            }
            $q    = (int) $row['i_quantity'];
            $best = $best === null ? $q : max($best, $q);
        }

        return max($best, $default);
    }

    /**
     * Raw entitlement rows for a user, newest first.
     *
     * @return array<int,array>
     */
    public static function forUser(int $userId): array
    {
        return osc_db_table(self::table())
            ->where('fk_i_user_id', $userId)
            ->orderBy('pk_i_id', 'DESC')
            ->get();
    }

    /**
     * Delete every row whose expiration has passed. Rows with a NULL expiration are
     * permanent grants and are never touched here.
     *
     * @return int how many rows were purged
     */
    public static function purge(): int
    {
        return osc_db_execute(
            'DELETE FROM ' . self::table() . ' WHERE dt_expiration IS NOT NULL AND dt_expiration <= ?',
            array(date('Y-m-d H:i:s'))
        );
    }

    /**
     * Whether $userId is still inside the free posting quota for the current period.
     * Always true when the quota is off (0 = unlimited).
     */
    public static function withinFreeQuota(int $userId): bool
    {
        $limit = osc_billing_free_posts_per_period();
        if ($limit <= 0) {
            return true;
        }

        return self::publishQuotaUsed($userId) < $limit;
    }

    /**
     * Listings $userId has published within the current billing_period_days window.
     *
     * Counts publication events (dt_first_pub_date), not the sort key (dt_pub_date)
     * -- item.bump moves dt_pub_date on purpose, to resort the listing, and must not
     * also refill the free quota it exists to move past. COALESCE(dt_first_pub_date,
     * dt_pub_date) is the fallback for a row written by a path other than
     * ItemActions::add() (a plugin inserting into t_item directly), so it still
     * counts rather than silently vanishing from the quota.
     */
    public static function publishQuotaUsed(int $userId): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . osc_billing_period_days() . ' days'));

        return (int) osc_db_scalar(
            'SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_item'
            . ' WHERE fk_i_user_id = ? AND COALESCE(dt_first_pub_date, dt_pub_date) >= ?',
            array($userId, $cutoff)
        );
    }

    /**
     * The one choke point ItemActions::add() consults. True immediately when billing
     * is off; otherwise true within the free quota, or with a listing.publish
     * entitlement once it is exhausted. The billing_can_publish filter runs on every
     * path, including the billing-off one, so a plugin can veto or override either way.
     */
    public static function canPublish(int $userId, array $ctx = array()): bool
    {
        if (!osc_billing_enabled()) {
            $allowed = true;
        } elseif (self::withinFreeQuota($userId)) {
            $allowed = true;
        } else {
            $allowed = self::has($userId, 'listing.publish');
        }

        return (bool) osc_apply_filter('billing_can_publish', $allowed, $userId, $ctx);
    }

    private static function addDays(string $datetime, int $days): string
    {
        return date('Y-m-d H:i:s', strtotime($datetime) + ($days * 86400));
    }

    private static function table(): string
    {
        return DB_TABLE_PREFIX . self::TABLE;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Entitlements.php */
