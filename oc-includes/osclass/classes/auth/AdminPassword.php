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

/**
 * An admin's new password, from the profile screen, the recovery link and the CLI alike: it is
 * stored and the admin is signed out everywhere in one transaction.
 */
final class AdminPassword
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $also other t_admin columns to write with it, such as a used-up reset code
     *
     * @return bool whether the admin exists and the password was stored
     * @throws \mindstellar\database\DbException
     */
    public static function set(int $adminId, string $new, array $also = []): bool
    {
        $values = ['s_password' => osc_hash_password($new)] + $also;

        return (bool) osc_db_transaction(static function () use ($adminId, $values): bool {
            if (osc_db_table(DB_TABLE_PREFIX . 't_admin')->where('pk_i_id', $adminId)->update($values) === 0) {
                return false;
            }

            return SignOut::everywhereAdmin($adminId);
        });
    }

    /**
     * The admin's current sign-out stamp, for the screen that keeps its own session signed in
     * after the admin changes their own password.
     *
     * @return int|null null when there is no such admin
     * @throws \mindstellar\database\DbException
     */
    public static function stamp(int $adminId): ?int
    {
        $row = $adminId > 0
            ? osc_db_select_one('SELECT ' . AuthStamp::COLUMN . ' FROM ' . DB_TABLE_PREFIX . 't_admin WHERE pk_i_id = ?', [$adminId])
            : null;

        return $row === null ? null : AuthStamp::of($row);
    }
}
