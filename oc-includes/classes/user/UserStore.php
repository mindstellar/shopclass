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

namespace mindstellar\user;

use mindstellar\base\Model;
use mindstellar\database\Db;
use mindstellar\listing\ListingCounters;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\ListingStore;
use mindstellar\listing\VariantStore;
use mindstellar\search\AlertStore;

/**
 * Writes on t_user and on the rows an account owns elsewhere, plus the small reads the
 * account services need. The legacy User model keeps its own methods.
 */
final class UserStore extends Model
{
    protected const TABLE = 't_user';

    /** The columns setAddress() may write. */
    public const ADDRESS_COLUMNS = array(
        's_address', 's_zip', 's_city', 'fk_i_city_id', 's_region', 'fk_i_region_id', 's_country', 'fk_c_country_code',
        's_phone_mobile', 'fk_i_city_area_id', 's_city_area',
    );

    /**
     * One bare t_user row, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id): ?array
    {
        return self::table()->where('pk_i_id', $id)->first();
    }

    /**
     * Hold the user's row until the transaction ends, so two writes to that user's own rows
     * run one after the other.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function lock(int $id): void
    {
        Db::select('SELECT pk_i_id FROM ' . self::tableName() . ' WHERE pk_i_id = ? FOR UPDATE', array($id));
    }

    /**
     * Lock the user's row and everything the account owns that a profile save, a block or a
     * delete touches: listings, their stats and variants, comments and alerts. Call it first in
     * the transaction, before any plain SELECT, so a row another request changes meanwhile makes
     * this one wait instead of failing with error 1020. Rows are taken in primary key order.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function lockOwned(int $userId): void
    {
        self::lock($userId);
        $items = ListingStore::lockByUser($userId);
        ListingCounters::lockByItems($items);
        VariantStore::lockByItems($items);
        \ItemComment::lockByUser($userId);
        AlertStore::lockByUser($userId);
    }

    /**
     * Lock what a profile save writes: the user's row, listings, comments and alerts. Not the
     * stats or variants, which a profile save leaves alone. Same order as lockOwned().
     *
     * @throws \mindstellar\database\DbException
     */
    public static function lockProfile(int $userId): void
    {
        self::lock($userId);
        ListingStore::lockByUser($userId);
        \ItemComment::lockByUser($userId);
        AlertStore::lockByUser($userId);
    }

    /**
     * Lock the guest listings and alerts posted with this e-mail, which an account takes over
     * once it is confirmed. Same rule as lockOwned(): before any plain SELECT.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function lockGuestListings(string $email): void
    {
        if ($email === '') {
            return;
        }
        $items = ListingStore::lockGuest($email);
        ListingCounters::lockByItems($items);
        VariantStore::lockByItems($items);
        AlertStore::lockGuest($email);
    }

    /**
     * Write the account's address columns: the default address of the address book.
     *
     * @param array<string,mixed> $columns t_user address column => value
     *
     * @throws \mindstellar\database\DbException
     */
    public static function setAddress(int $userId, array $columns): void
    {
        $columns = array_intersect_key($columns, array_flip(self::ADDRESS_COLUMNS));
        if ($columns === array()) {
            return;
        }
        self::table()->where('pk_i_id', $userId)->update($columns);
        if (function_exists('osc_invalidate_user_cache')) {
            osc_invalidate_user_cache($userId);
        }
    }

    /**
     * Count one more live comment on the user, in one statement so no other change is lost.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function addComment(int $id): void
    {
        Db::execute('UPDATE ' . self::tableName() . ' SET i_comments = i_comments + 1 WHERE pk_i_id = ?', array($id));
        if (function_exists('osc_invalidate_user_cache')) {
            osc_invalidate_user_cache($id);
        }
    }

    /**
     * A user row with `b_family_live`: whether the API sign-in $family still has an unrevoked token.
     *
     * @return array<string,mixed>|null
     */
    public static function findWithFamily(int $id, string $family): ?array
    {
        return Db::selectOne(
            'SELECT u.*, EXISTS(SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_api_credential c WHERE c.s_family = ? AND c.dt_revoked IS NULL) AS b_family_live'
            . ' FROM ' . DB_TABLE_PREFIX . 't_user u WHERE u.pk_i_id = ? LIMIT 1',
            [$family, $id]
        );
    }

    /**
     * Recount i_items, the live listing count, of each seller who had a listing expire from $since
     * to $until, or posted or edited one already expired. An expiry is the one change that does not lower the count when it happens; a
     * renewal raises it again. $since goes back 30 days at most, so a long break stays cheap.
     *
     * @return int sellers whose count changed
     * @throws \mindstellar\database\DbException
     */
    public static function recountExpired(string $since, string $until, int $chunk = 1000): int
    {
        $since = max($since, date('Y-m-d H:i:s', time() - 30 * 86400));
        $p     = DB_TABLE_PREFIX;
        // An expiry in the window, then a post or edit in it of a listing that expired before it.
        $ids = array_map('intval', array_column(Db::select(
            'SELECT fk_i_user_id AS id FROM ' . $p . 't_item'
            . ' WHERE dt_expiration >= ? AND dt_expiration <= ? AND fk_i_user_id IS NOT NULL'
            . ' UNION SELECT fk_i_user_id FROM ' . $p . 't_item'
            . ' WHERE dt_pub_date >= ? AND dt_expiration < ? AND fk_i_user_id IS NOT NULL'
            . ' UNION SELECT fk_i_user_id FROM ' . $p . 't_item'
            . ' WHERE dt_mod_date >= ? AND dt_expiration < ? AND fk_i_user_id IS NOT NULL',
            [$since, $until, $since, $since, $since, $since]
        ), 'id'));
        $live    = implode(' AND ', ListingStatus::liveConditions('i.'));
        $changed = 0;
        foreach (array_chunk($ids, max(1, $chunk)) as $chunk) {
            // A count is read and written in one statement, so a listing posted meanwhile is not lost.
            $count = '(SELECT COUNT(*) FROM ' . $p . 't_item i WHERE i.fk_i_user_id = u.pk_i_id AND ' . $live . ')';
            $rows  = Db::execute(
                'UPDATE ' . $p . 't_user u SET u.i_items = ' . $count
                . ' WHERE u.pk_i_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND NOT (u.i_items <=> ' . $count . ')',
                $chunk
            );
            if ($rows > 0 && function_exists('osc_invalidate_user_cache')) {
                array_map('osc_invalidate_user_cache', $chunk);
            }
            $changed += $rows;
        }

        return $changed;
    }

    /**
     * Whether an account is live: confirmed and not blocked. byIds() with $liveOnly asks the
     * same in SQL.
     *
     * @param array<string,mixed> $user a t_user row
     */
    public static function isLive(array $user): bool
    {
        return (int) ($user['b_enabled'] ?? 0) === 1 && (int) ($user['b_active'] ?? 0) === 1;
    }

    /**
     * The name to show for a user row: the full name, else the username.
     *
     * @param array<string,mixed> $row a row with s_name and s_username
     */
    public static function displayName(array $row): string
    {
        $name = trim((string) ($row['s_name'] ?? ''));

        return $name !== '' ? $name : (string) ($row['s_username'] ?? '');
    }

    /**
     * Bare rows for these ids, keyed by nothing.
     *
     * @param int[]    $ids
     * @param string[] $columns
     * @param bool     $liveOnly only enabled, active accounts
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function byIds(array $ids, array $columns, bool $liveOnly = false): array
    {
        $query = self::table()->select(...$columns)->whereIn('pk_i_id', $ids);
        if ($liveOnly) {
            $query = $query->where('b_enabled', 1)->where('b_active', 1);
        }

        return $query->get();
    }

    /**
     * Names to show other people, by id: the user's name, or the username when the name is empty.
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    public static function displayNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (self::byIds(array_values(array_unique($ids)), ['pk_i_id', 's_name', 's_username']) as $user) {
            $names[(int) $user['pk_i_id']] = (string) ($user['s_name'] !== '' ? $user['s_name'] : $user['s_username']);
        }

        return $names;
    }

    /**
     * Usernames by id.
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    public static function usernames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Db::select(
            'SELECT pk_i_id, s_username FROM ' . self::tableName() . ' WHERE pk_i_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['pk_i_id']] = (string) $row['s_username'];
        }

        return $out;
    }

    /**
     * The named lock that serialises username claims on this site.
     */
    public static function usernameLock(): string
    {
        return 'osc_username_' . md5((defined('DB_NAME') ? DB_NAME : '') . self::tableName());
    }

    /**
     * Whether another account holds the username, or a hidden sign-up holds it (Usernames::hold()) unless $ignoreHolds.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function usernameTaken(string $username, int $exceptId, bool $ignoreHolds = false): bool
    {
        return self::table()
            ->where('s_username', $username)
            ->where('pk_i_id', '!=', $exceptId)
            ->count() > 0 || (!$ignoreHolds && Usernames::held($username));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function setUsername(int $id, string $username): void
    {
        self::table()->where('pk_i_id', $id)->update(['s_username' => $username]);
    }

    /**
     * Store the new e-mail, only while the confirmation code still matches.
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function switchEmail(int $userId, string $code, string $email): int
    {
        return self::table()
            ->where('pk_i_id', $userId)
            ->where('s_pass_code', $code)
            ->update(['s_email' => $email, 's_pass_code' => null, 's_pass_date' => null]);
    }

    /**
     * Carry a changed e-mail onto the user's listings, comments and alerts, and drop the
     * user's pending change.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function carryEmail(int $userId, string $email): void
    {
        self::owned('t_item', $userId)->update(['s_contact_email' => $email]);
        self::owned('t_item_comment', $userId)->update(['s_author_email' => $email]);
        self::owned('t_alerts', $userId)->update(['s_email' => $email]);
        \UserEmailTmp::getInstance()->deleteByUser($userId);
    }

    /**
     * Carry an admin's edit of the name and e-mail onto the user's listings, comments and alerts.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function carryContact(int $userId, mixed $name, mixed $email): void
    {
        self::owned('t_item', $userId)->update(['s_contact_name' => $name, 's_contact_email' => $email]);
        self::owned('t_item_comment', $userId)->update(['s_author_name' => $name, 's_author_email' => $email]);
        self::owned('t_alerts', $userId)->update(['s_email' => $email]);
    }

    /**
     * Carry a changed name onto the user's listings and comments.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function carryName(int $userId, mixed $name): void
    {
        self::owned('t_item', $userId)->update(['s_contact_name' => $name]);
        self::owned('t_item_comment', $userId)->update(['s_author_name' => $name]);
    }

    /**
     * Record a visit when the last one is at least $every seconds old.
     *
     * @return bool whether the visit was written
     * @throws \mindstellar\database\DbException
     */
    public static function touchAccess(int $userId, string $date, string $ip, int $every): bool
    {
        $written = self::table()
            ->where('pk_i_id', $userId)
            ->where('dt_access_date', '<=', date('Y-m-d H:i:s', time() - $every))
            ->update(['dt_access_date' => $date, 's_access_ip' => $ip]) > 0;
        if ($written && function_exists('osc_invalidate_user_cache')) {
            osc_invalidate_user_cache($userId);
        }

        return $written;
    }

    /**
     * Give the guest listings posted with this e-mail to the account.
     *
     * @return int listings claimed
     * @throws \mindstellar\database\DbException
     */
    public static function claimGuestListings(int $userId, string $email, ?string $name): int
    {
        return Db::table(\Item::getInstance()->getTableName())
            ->where('s_contact_email', $email)
            ->whereNull('fk_i_user_id')
            ->update(['fk_i_user_id' => $userId, 's_contact_name' => $name]);
    }

    /**
     * Give the guest alerts made with this e-mail to the account.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function claimGuestAlerts(int $userId, string $email): void
    {
        Db::table(\Alerts::getInstance()->getTableName())
            ->where('s_email', $email)
            ->whereRaw('(fk_i_user_id IS NULL OR fk_i_user_id = 0)')
            ->update(['fk_i_user_id' => $userId]);
    }

    private static function owned(string $table, int $userId): \mindstellar\database\QueryBuilder
    {
        return Db::table(DB_TABLE_PREFIX . $table)->where('fk_i_user_id', $userId);
    }
}
