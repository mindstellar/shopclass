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

namespace mindstellar\auth;

use mindstellar\database\Db;

/**
 * "Sign out of all devices", the one way every sign-in of an account ends: its sign-out stamp
 * goes up, which ends every cookie and token signed over it, then an action lets stored
 * credentials (API keys, refresh tokens) be revoked in the same transaction.
 *
 * A password change (user or admin) goes through here too, so a new password ends every old
 * sign-in. This is the only code that raises a stamp.
 */
final class SignOut
{
    public const USER_HOOK  = 'user_signout_all_after';
    public const ADMIN_HOOK = 'admin_signout_all_after';

    private function __construct()
    {
    }

    /**
     * @return bool whether the user exists
     * @throws \mindstellar\database\DbException
     */
    public static function everywhereUser(int $userId): bool
    {
        return (bool) Db::transaction(static function () use ($userId): bool {
            if (!self::bump(\User::getInstance()->getTableName(), $userId)) {
                return false;
            }
            if (function_exists('osc_invalidate_user_cache')) {
                osc_invalidate_user_cache($userId);
            }
            osc_run_hook('user_signout_all_after', $userId);

            return true;
        });
    }

    /**
     * @return bool whether the admin exists
     * @throws \mindstellar\database\DbException
     */
    public static function everywhereAdmin(int $adminId): bool
    {
        return (bool) Db::transaction(static function () use ($adminId): bool {
            if (!self::bump(\Admin::getInstance()->getTableName(), $adminId)) {
                return false;
            }
            osc_run_hook('admin_signout_all_after', $adminId);

            return true;
        });
    }

    /**
     * Raise the stamp of one t_user or t_admin row. Private: raising it without the
     * sign-out action would leave stored credentials alive.
     *
     * @throws \mindstellar\database\DbException
     */
    private static function bump(string $table, int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        return Db::execute(
            'UPDATE ' . $table . ' SET ' . AuthStamp::COLUMN . ' = ' . AuthStamp::COLUMN . ' + 1 WHERE pk_i_id = ?',
            [$id]
        ) > 0;
    }
}
