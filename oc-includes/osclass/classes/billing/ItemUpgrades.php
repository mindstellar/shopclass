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

use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;

/**
 * Persistence and read path for t_item_upgrade -- one row per (item, upgrade), covering
 * every present and future item-scoped effect (bump, highlight, urgent, ...) behind a
 * single registry id rather than a column each.
 *
 * The unique key on (fk_i_item_id, s_upgrade) is what keeps that promise: buying the
 * same upgrade twice extends the row that already exists instead of growing a second
 * one, so the expiry sweep never has to reconcile duplicates.
 *
 * prime() is the request-scoped batch cache that keeps a theme helper called inside a
 * listing loop to one query per page. active()/has()/expiresAt() read the primed set
 * when one exists for the item, and fall back to a single-item query otherwise -- that
 * fallback result is written into the same cache (see rowsFor()), so two helpers
 * called back to back on the same unprimed item (osc_item_is_highlighted() then
 * osc_item_is_urgent()) cost one query between them, not two. A write (grant())
 * invalidates the item's cached entry, so a later read in the same request still
 * reflects it rather than the state from before the write.
 *
 * @package mindstellar\billing
 */
final class ItemUpgrades
{
    /** Unprefixed table name. */
    private const TABLE = 't_item_upgrade';

    /**
     * @var array<int,array<string,?string>> item id => (upgrade => dt_expiration),
     *      populated only by prime(). An item present as a key with an empty array
     *      means it was primed and holds no upgrades -- distinct from an item never
     *      primed at all, which falls back to a live query instead of reading this.
     */
    private static array $primed = array();

    /**
     * Grant (or extend) $upgrade on $itemId.
     *
     * Extends from whichever is later, now or the row's current expiry -- buying 30
     * more days with 10 left gives 40, not 30. A row already permanent (dt_expiration
     * NULL) stays permanent regardless of $days/$hours, the same invariant
     * Entitlements::grant() holds for a duration grant. $days and $hours are additive
     * ways to say the same offset; both null means the row never lapses.
     *
     * The unique key on (fk_i_item_id, s_upgrade) can race two concurrent grants for
     * the same row: the loser's INSERT fails on the constraint rather than creating a
     * duplicate, so it is caught and turned into the same UPDATE the winner would have
     * needed anyway.
     */
    public static function grant(int $itemId, string $upgrade, ?int $days = null, ?int $hours = null): bool
    {
        $now = date('Y-m-d H:i:s');

        $granted = (bool) osc_db_transaction(static function () use ($itemId, $upgrade, $days, $hours, $now): bool {
            $row = self::table()
                ->where('fk_i_item_id', $itemId)
                ->where('s_upgrade', $upgrade)
                ->first();

            if ($row === null) {
                try {
                    self::table()->insert(array(
                        'fk_i_item_id'  => $itemId,
                        's_upgrade'     => $upgrade,
                        'dt_expiration' => self::nextExpiration(null, $days, $hours, $now),
                        'dt_date'       => $now,
                    ));

                    return true;
                } catch (DbException $e) {
                    // uq_item_upgrade: a concurrent grant landed first between the read
                    // above and this insert. Re-read and extend it below rather than
                    // losing this grant.
                    $row = self::table()
                        ->where('fk_i_item_id', $itemId)
                        ->where('s_upgrade', $upgrade)
                        ->first();
                    if ($row === null) {
                        throw $e;
                    }
                }
            }

            self::table()
                ->where('pk_i_id', $row['pk_i_id'])
                ->update(array('dt_expiration' => self::nextExpiration($row, $days, $hours, $now)));

            return true;
        });

        if ($granted) {
            // A write must invalidate any cached read for this item -- otherwise a
            // caller that reads the item's upgrades again later in the same request
            // (a hook firing right after grant(), for instance) would see the
            // pre-grant state instead of what it just paid for.
            unset(self::$primed[$itemId]);
        }

        return $granted;
    }

    /**
     * Upgrade ids currently in force on $itemId: every row whose dt_expiration is
     * either NULL or still in the future.
     *
     * @return string[]
     */
    public static function active(int $itemId): array
    {
        $now = date('Y-m-d H:i:s');
        $out = array();
        foreach (self::rowsFor($itemId) as $upgrade => $expiration) {
            if ($expiration === null || $expiration > $now) {
                $out[] = $upgrade;
            }
        }

        return $out;
    }

    public static function has(int $itemId, string $upgrade): bool
    {
        return in_array($upgrade, self::active($itemId), true);
    }

    /**
     * Raw dt_expiration for $itemId's $upgrade row, whether or not it is still
     * active, or null when there is no row at all -- the same "raw value" convention
     * osc_item_premium_expiration() follows.
     */
    public static function expiresAt(int $itemId, string $upgrade): ?string
    {
        $rows = self::rowsFor($itemId);

        return $rows[$upgrade] ?? null;
    }

    /**
     * Batch-load $itemIds into the request cache, one query for every id not
     * already primed. Call this where a result set is built (a search page, a
     * category listing) rather than once per item -- active()/has()/expiresAt()
     * read the cache this fills.
     *
     * Idempotent and additive: priming an id already primed does nothing, so
     * calling it again with an overlapping set costs nothing extra.
     *
     * @param int[] $itemIds
     */
    public static function prime(array $itemIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0
        )));
        $missing = array_values(array_diff($ids, array_keys(self::$primed)));
        if ($missing === array()) {
            return;
        }

        // Marked primed up front, with no rows yet, so an id with no upgrades at all
        // is still recognised as primed rather than falling through to a live query.
        foreach ($missing as $id) {
            self::$primed[$id] = array();
        }

        $rows = self::table()->whereIn('fk_i_item_id', $missing)->get();
        foreach ($rows as $row) {
            self::$primed[(int) $row['fk_i_item_id']][(string) $row['s_upgrade']] = $row['dt_expiration'];
        }
    }

    /**
     * Delete every row whose expiration has passed. Rows with a NULL expiration
     * never lapse and are never touched here. Shares the hourly cron slot that
     * already sweeps Premium and Entitlements.
     *
     * @return int how many rows were purged
     */
    public static function purge(): int
    {
        return osc_db_execute(
            'DELETE FROM ' . self::tableName() . ' WHERE dt_expiration IS NOT NULL AND dt_expiration <= ?',
            array(date('Y-m-d H:i:s'))
        );
    }

    /**
     * upgrade => dt_expiration for $itemId, from the primed cache when one exists
     * for this id, or a fresh single-item query otherwise. The fresh-query result is
     * written into the same cache prime() fills -- an item never primed still costs
     * only its first read, not one query per helper called on it (grant() invalidates
     * the entry it writes, so this does not go stale after a purchase).
     *
     * @return array<string,?string>
     */
    private static function rowsFor(int $itemId): array
    {
        if (array_key_exists($itemId, self::$primed)) {
            return self::$primed[$itemId];
        }

        $rows = self::table()->where('fk_i_item_id', $itemId)->get();
        $out  = array();
        foreach ($rows as $row) {
            $out[(string) $row['s_upgrade']] = $row['dt_expiration'];
        }

        self::$primed[$itemId] = $out;

        return $out;
    }

    /**
     * The row's next dt_expiration after adding $days/$hours, following grant()'s
     * rules: a permanent row stays permanent, "no offset at all" means permanent,
     * and any real offset extends from the later of now or the row's own expiry.
     *
     * @param array{dt_expiration:?string}|null $row null when the row is new
     */
    private static function nextExpiration(?array $row, ?int $days, ?int $hours, string $now): ?string
    {
        if ($row !== null && $row['dt_expiration'] === null) {
            return null;
        }

        if ($days === null && $hours === null) {
            return null;
        }

        $base = ($row !== null && $row['dt_expiration'] !== null && $row['dt_expiration'] > $now)
            ? $row['dt_expiration']
            : $now;

        // Days are calendar arithmetic -- a 30-day purchase expires 30 calendar days
        // later regardless of a DST change in between, not 30 * 86400 raw seconds.
        // Hours stay literal * 3600: the bump cooldown means wait N real hours, not
        // "until this time tomorrow", so it must not shift with the clocks either.
        $ts = strtotime($base);
        if ($days !== null) {
            $ts = strtotime('+' . $days . ' days', $ts);
        }
        $ts += ($hours ?? 0) * 3600;

        return date('Y-m-d H:i:s', $ts);
    }

    private static function tableName(): string
    {
        return DB_TABLE_PREFIX . self::TABLE;
    }

    private static function table(): QueryBuilder
    {
        return osc_db_table(self::tableName());
    }
}

/* file end: ./oc-includes/osclass/classes/billing/ItemUpgrades.php */
