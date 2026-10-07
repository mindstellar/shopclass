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

/**
 * Writes on t_user and on the rows an account owns elsewhere, plus the small reads the
 * account services need. The legacy User model keeps its own methods.
 */
final class UserStore extends Model
{
    protected const TABLE = 't_user';

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
     * Carry a changed e-mail onto the user's listings, comments and alerts, and drop any
     * pending change to that address.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function carryEmail(int $userId, string $email): void
    {
        self::owned('t_item', $userId)->update(['s_contact_email' => $email]);
        self::owned('t_item_comment', $userId)->update(['s_author_email' => $email]);
        self::owned('t_alerts', $userId)->update(['s_email' => $email]);
        Db::table(DB_TABLE_PREFIX . 't_user_email_tmp')->where('s_new_email', $email)->delete();
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
     * Drop pending e-mail changes made before $before.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function prunePendingEmails(string $before): void
    {
        Db::table(DB_TABLE_PREFIX . 't_user_email_tmp')->where('dt_date', '<', $before)->delete();
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
