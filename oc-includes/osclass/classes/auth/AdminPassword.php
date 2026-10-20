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

        return (bool) Db::transaction(static function () use ($adminId, $values): bool {
            if (AdminStore::update($adminId, $values) === 0) {
                return false;
            }

            return SignOut::everywhereAdmin($adminId);
        });
    }

    /**
     * Store a fresh hash of the password the admin just signed in with. Unlike set(), other
     * sessions stay signed in; a failed write is ignored so the sign-in still goes ahead.
     *
     * @return string the new hash
     */
    public static function rehash(int $adminId, string $password): string
    {
        $hash = osc_hash_password($password);
        try {
            AdminStore::update($adminId, ['s_password' => $hash]);
        } catch (\mindstellar\database\DbException) {
        }

        return $hash;
    }

    /**
     * Start a password reset: store a fingerprint of a new random code and return the code
     * for the e-mailed link. A failed write is ignored, so the reply never shows whether the account exists.
     */
    public static function issueReset(int $adminId): string
    {
        require_once dirname(__DIR__, 2) . '/helpers/hSecurity.php';
        $code = osc_genRandomPassword(40);
        try {
            AdminStore::update($adminId, ['s_secret' => \mindstellar\security\ActionToken::hash($code)]);
        } catch (\mindstellar\database\DbException) {
        }

        return $code;
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
            ? AdminStore::stampRow($adminId)
            : null;

        return $row === null ? null : AuthStamp::of($row);
    }
}
