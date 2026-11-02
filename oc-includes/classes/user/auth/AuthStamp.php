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
 * The sign-out stamp of a user or admin: `i_auth_stamp`, a counter every signed sign-in
 * credential carries (remember-me cookies, the admin session, API access and page tokens).
 * Only SignOut raises it, on "sign out of all devices" and on a password change, which ends
 * every credential issued before, on every device, without storing any of them. There is no
 * raise here on purpose: a sign-out must also run the sign-out actions that revoke what is stored.
 *
 * Read from the row the identity code loads anyway, so checking it costs no query. A row from
 * before the column existed reads as 0, which signs exactly as before.
 */
final class AuthStamp
{
    public const COLUMN = 'i_auth_stamp';

    private function __construct()
    {
    }

    /**
     * The stamp of a t_user or t_admin row.
     *
     * @param array<string,mixed> $row
     */
    public static function of(array $row): int
    {
        return max(0, (int) ($row[self::COLUMN] ?? 0));
    }

    /**
     * A short fingerprint of an account's id and stamp, for a signed token to carry: a token
     * whose fingerprint no longer matches the row was issued before the last sign-out.
     *
     * @param array<string,mixed> $row a t_user or t_admin row
     */
    public static function fingerprint(array $row): string
    {
        return substr(hash('sha256', 'auth-stamp|' . (int) ($row['pk_i_id'] ?? 0) . '|' . self::of($row)), 0, 8);
    }
}
