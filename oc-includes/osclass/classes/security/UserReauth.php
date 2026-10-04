<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

/**
 * Asks a signed-in user for their current password again before an account change. Wrong
 * answers count in the sign-in limit under the user's e-mail, as the sign-in form counts them.
 */
final class UserReauth
{
    /** The sign-in limit context, shared with the sign-in form. */
    public const CONTEXT = 'web';

    /**
     * @param array<string,mixed> $user     the signed-in user's t_user row
     * @param string              $password as typed
     *
     * @return string '' when the password is right, otherwise the reason to show
     */
    public static function verify(array $user, string $password): string
    {
        $account  = (string)($user['s_email'] ?? '');
        $throttle = LoginThrottle::evaluate(self::CONTEXT, $account);
        if ($throttle['status'] === LoginThrottle::BLOCKED) {
            return osc_login_throttle_message($throttle['retry_after']);
        }

        $hash = (string)($user['s_password'] ?? '');
        if ($password === '' || $hash === '' || !osc_verify_password($password, $hash)) {
            LoginThrottle::recordFailure(self::CONTEXT, $account);

            return _m("Current password doesn't match");
        }

        // The account's counter only: the address may still be guessing at other accounts.
        LoginThrottle::clear(self::CONTEXT, $account, false);

        return '';
    }
}
