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

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Small t_item reads and writes for the listing services. The legacy Item model keeps
 * its own methods; ListingQuery holds the paged lists.
 */
final class ListingStore extends Model
{
    protected const TABLE = 't_item';

    /**
     * One listing's columns, or null. With $lock the row is held (FOR UPDATE) until the
     * transaction ends.
     *
     * @param string[] $columns column names from code, never from input; '*' for all
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id, array $columns, bool $lock = false): ?array
    {
        if ($lock) {
            $list = implode(', ', array_map(static fn (string $c): string => $c === '*' ? '*' : '`' . str_replace('`', '', $c) . '`', $columns));

            return Db::selectOne('SELECT ' . $list . ' FROM ' . self::tableName() . ' WHERE pk_i_id = ? FOR UPDATE', [$id]);
        }

        return self::table()->select(...$columns)->where('pk_i_id', $id)->first();
    }

    /**
     * Lock a listing for a write: its t_item row, then its t_item_stats row, then its
     * t_item_variant rows by id. Take this before any plain SELECT in the transaction.
     *
     * @return array<string,mixed>|null the t_item row, or null when there is no such listing
     * @throws \mindstellar\database\DbException
     */
    public static function lockForWrite(int $id): ?array
    {
        $row = self::find($id, array('*'), true);
        if ($row === null) {
            return null;
        }
        ListingCounters::lockByItems(array($id));
        VariantStore::lockByItems(array($id));

        return $row;
    }

    /**
     * Lock a user's listings in primary key order.
     *
     * @return int[] the listing ids
     * @throws \mindstellar\database\DbException
     */
    public static function lockByUser(int $userId): array
    {
        $rows = Db::select('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE fk_i_user_id = ? ORDER BY pk_i_id FOR UPDATE', array($userId));

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * Lock the guest listings posted with this e-mail in primary key order.
     *
     * @return int[] the listing ids
     * @throws \mindstellar\database\DbException
     */
    public static function lockGuest(string $email): array
    {
        $rows = Db::select('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE s_contact_email = ? AND fk_i_user_id IS NULL ORDER BY pk_i_id FOR UPDATE', array($email));

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * The listing's owner, 0 for a guest listing or no listing.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function ownerId(int $id): int
    {
        return (int) Db::scalar('SELECT fk_i_user_id FROM ' . self::tableName() . ' WHERE pk_i_id = ?', array($id));
    }

    /**
     * Listing titles by id in one read: the locale's own title, else the first locale that has one.
     * An id with no title is left out.
     *
     * @param int[]       $ids
     * @param string|null $locale defaults to the visitor's locale
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    public static function titles(array $ids, ?string $locale = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $locale ??= (string) osc_current_user_locale();
        $rows     = Db::table(DB_TABLE_PREFIX . 't_item_description')
            ->select('fk_i_item_id', 'fk_c_locale_code', 's_title')
            ->whereIn('fk_i_item_id', $ids)
            ->get();
        $titles = [];
        $own    = [];
        foreach ($rows as $row) {
            $title = (string) $row['s_title'];
            $id    = (int) $row['fk_i_item_id'];
            if ($title === '') {
                continue;
            }
            if ((string) $row['fk_c_locale_code'] === $locale) {
                $own[$id] = $title;
            } elseif (!isset($titles[$id])) {
                $titles[$id] = $title;
            }
        }

        return $own + $titles;
    }

    /**
     * What an inbox row needs from each listing, in one read. A listing that is gone is left out.
     * "live" is true when visitors can see it.
     *
     * @param int[] $ids
     *
     * @return array<int,array{price:?int,currency:?string,live:bool}>
     * @throws \mindstellar\database\DbException
     */
    public static function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = self::table()
            ->select('pk_i_id', 'i_price', 'fk_c_currency_code', 'b_enabled', 'b_active', 'b_spam', 'b_premium', 'dt_expiration', 'dt_first_pub_date')
            ->whereIn('pk_i_id', $ids)
            ->get();
        $cards = [];
        foreach ($rows as $row) {
            $cards[(int) $row['pk_i_id']] = [
                'price'    => $row['i_price'] === null ? null : (int) $row['i_price'],
                'currency' => $row['fk_c_currency_code'] === null ? null : (string) $row['fk_c_currency_code'],
                'live'     => ListingStatus::isLive($row),
            ];
        }

        return $cards;
    }

    /**
     * The columns a premium change decides on, or null. $lock holds the row (FOR UPDATE) until the
     * transaction ends.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function premiumState(int $id, bool $lock = false): ?array
    {
        return Db::selectOne(
            'SELECT b_premium, dt_premium_expiration, b_enabled, b_active, b_spam, dt_expiration FROM '
            . self::tableName() . ' WHERE pk_i_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            array($id)
        );
    }

    /**
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function setPubDate(int $id, string $at): int
    {
        return self::table()->where('pk_i_id', $id)->update(['dt_pub_date' => $at]);
    }

    /**
     * Dated premium upgrades that ended by $now.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function endedPremium(string $now): array
    {
        return Db::select(
            'SELECT pk_i_id, b_enabled, b_active, b_spam, b_premium, dt_expiration FROM ' . self::tableName()
            . ' WHERE b_premium = 1 AND dt_premium_expiration IS NOT NULL AND dt_premium_expiration <= ?',
            array($now)
        );
    }

    /**
     * Listings held for payment whose deadline passed before $before and whose delete job is
     * still queued, oldest id first.
     *
     * @return array<int,array<string,mixed>> pk_i_id and s_secret of each
     * @throws \mindstellar\database\DbException
     */
    public static function heldPast(string $before, int $limit): array
    {
        // The id is cast to ascii_bin, as s_unique is, so the two compare without a collation error.
        return Db::select(
            'SELECT i.pk_i_id, i.s_secret FROM ' . self::tableName() . ' i'
            . ' WHERE ' . ListingStatus::heldSql('i.') . ' AND i.dt_expiration < ?'
            . ' AND EXISTS (SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_job_queue j'
            . ' WHERE j.s_type = ? AND j.s_unique = CAST(i.pk_i_id AS CHAR CHARACTER SET ascii) COLLATE ascii_bin)'
            . ' ORDER BY i.pk_i_id LIMIT ' . $limit,
            array($before, ListingHoldJobs::EXPIRE)
        );
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function clearPremium(array $ids): void
    {
        self::table()
            ->whereIn('pk_i_id', $ids)
            ->update(array('b_premium' => 0, 'dt_premium_expiration' => null));
    }

    /**
     * @param int[] $categoryIds
     *
     * @throws \mindstellar\database\DbException
     */
    public static function countInCategories(array $categoryIds): int
    {
        return self::table()->whereIn('fk_i_category_id', $categoryIds)->count();
    }

    /**
     * The first $limit listing ids in these categories, lowest first.
     *
     * @param int[] $categoryIds
     *
     * @return array<int,array<string,mixed>> rows with pk_i_id
     * @throws \mindstellar\database\DbException
     */
    public static function idsInCategories(array $categoryIds, int $limit): array
    {
        return self::table()
            ->select('pk_i_id')
            ->whereIn('fk_i_category_id', $categoryIds)
            ->orderBy('pk_i_id', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * The listing's t_item_location row, or null. $lock locks it (FOR UPDATE) until the transaction ends.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function location(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            return Db::selectOne('SELECT * FROM ' . DB_TABLE_PREFIX . 't_item_location WHERE fk_i_item_id = ? FOR UPDATE', [$id]);
        }

        return Db::table(DB_TABLE_PREFIX . 't_item_location')->where('fk_i_item_id', $id)->first();
    }

    /**
     * Set the point distance search uses for one listing: its own map position, else its
     * city centre, else its region centre. The map position itself is left as it is.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function stampPoint(int $itemId): void
    {
        Db::execute(self::pointSql() . ' WHERE l.fk_i_item_id = ?', array($itemId));
    }

    /**
     * Set the point of up to $batch listings after $afterId.
     *
     * @return int the last listing id done, or 0 when none was left
     * @throws \mindstellar\database\DbException
     */
    public static function refreshPoints(int $afterId, int $batch): int
    {
        $last = Db::select(
            'SELECT MAX(fk_i_item_id) AS m FROM (SELECT fk_i_item_id FROM ' . DB_TABLE_PREFIX . 't_item_location'
            . ' WHERE fk_i_item_id > ? ORDER BY fk_i_item_id LIMIT ' . max(1, $batch) . ') AS batch',
            array($afterId)
        );
        $lastId = (int) ($last[0]['m'] ?? 0);
        if ($lastId > 0) {
            Db::execute(self::pointSql() . ' WHERE l.fk_i_item_id > ? AND l.fk_i_item_id <= ?', array($afterId, $lastId));
        }

        return $lastId;
    }

    private static function pointSql(): string
    {
        $p      = DB_TABLE_PREFIX;
        $exact  = 'l.d_coord_lat IS NOT NULL AND l.d_coord_lat <> 0 AND l.d_coord_long IS NOT NULL AND l.d_coord_long <> 0';
        $city   = 'c.d_coord_lat IS NOT NULL AND c.d_coord_lat <> 0 AND c.d_coord_long IS NOT NULL AND c.d_coord_long <> 0';
        $region = 'r.d_coord_lat IS NOT NULL AND r.d_coord_lat <> 0 AND r.d_coord_long IS NOT NULL AND r.d_coord_long <> 0';

        return 'UPDATE ' . $p . 't_item_location l'
            . ' LEFT JOIN ' . $p . 't_city c ON c.pk_i_id = l.fk_i_city_id'
            . ' LEFT JOIN ' . $p . 't_region r ON r.pk_i_id = l.fk_i_region_id'
            . " SET l.e_geo_precision = CASE WHEN $exact THEN 'exact' WHEN $city THEN 'city' WHEN $region THEN 'region' ELSE NULL END,"
            . " l.d_geo_lat = CASE WHEN $exact THEN l.d_coord_lat WHEN $city THEN c.d_coord_lat WHEN $region THEN r.d_coord_lat ELSE NULL END,"
            . " l.d_geo_long = CASE WHEN $exact THEN l.d_coord_long WHEN $city THEN c.d_coord_long WHEN $region THEN r.d_coord_long ELSE NULL END";
    }

    /**
     * Ids after $afterId of enabled, non-spam listings with a street address and no map position
     * of their own, oldest first.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public static function withoutPosition(int $afterId, int $limit): array
    {
        $rows = Db::select(
            'SELECT l.fk_i_item_id FROM ' . DB_TABLE_PREFIX . 't_item_location l JOIN ' . self::tableName() . ' i ON i.pk_i_id = l.fk_i_item_id'
            . ' WHERE l.fk_i_item_id > ? AND TRIM(l.s_address) <> \'\' AND i.b_enabled = 1 AND i.b_spam = 0'
            . ' AND (l.d_coord_lat IS NULL OR l.d_coord_lat = 0 OR l.d_coord_long IS NULL OR l.d_coord_long = 0)'
            . ' ORDER BY l.fk_i_item_id LIMIT ' . max(1, $limit),
            array($afterId)
        );

        return array_map(static fn (array $row): int => (int) $row['fk_i_item_id'], $rows);
    }

    /**
     * Store looked-up coordinates, unless the listing got its own in the meantime.
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function setCoordinates(int $id, float $lat, float $lng): int
    {
        $changed = Db::execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_item_location SET d_coord_lat = ?, d_coord_long = ? WHERE fk_i_item_id = ?'
            . ' AND (d_coord_lat IS NULL OR d_coord_lat = 0 OR d_coord_long IS NULL OR d_coord_long = 0)',
            array($lat, $lng, $id)
        );
        if ($changed > 0) {
            self::stampPoint($id);
        }

        return $changed;
    }

    /**
     * Whether any listing is priced in this currency.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function usesCurrency(string $code): bool
    {
        return self::table()->where('fk_c_currency_code', $code)->count() > 0;
    }
}
