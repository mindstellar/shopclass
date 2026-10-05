<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\auth;

use mindstellar\security\LoginThrottle;

use mindstellar\validation\BlockedException;
use mindstellar\validation\InvalidException;

/**
 * Asks a signed-in user for their current password again before an account change, on the
 * web and in the API alike. Wrong answers count in the sign-in limit under the user's e-mail,
 * as the sign-in form counts them.
 */
final class Reauth
{
    /** The sign-in limit context, shared with the sign-in form and API sign-ins. */
    public const CONTEXT = 'web';

    /** attempt(): the password is right. */
    public const OK = 0;

    /** attempt(): the password is wrong. */
    public const WRONG = -1;

    /**
     * Check the password and count a wrong one.
     *
     * @param array<string,mixed> $user     the signed-in user's t_user row
     * @param string              $password as typed
     *
     * @return int OK, WRONG, or while blocked the seconds until another try is taken
     */
    public static function attempt(array $user, string $password): int
    {
        $account  = (string)($user['s_email'] ?? '');
        $throttle = LoginThrottle::evaluate(self::CONTEXT, $account);
        if ($throttle['status'] === LoginThrottle::BLOCKED) {
            return max(1, (int)$throttle['retry_after']);
        }

        $hash = (string)($user['s_password'] ?? '');
        if ($password === '' || $hash === '' || !osc_verify_password($password, $hash)) {
            LoginThrottle::recordFailure(self::CONTEXT, $account);

            return self::WRONG;
        }

        // The account's counter only: the address may still be guessing at other accounts.
        LoginThrottle::clear(self::CONTEXT, $account, false);

        return self::OK;
    }

    /**
     * attempt(), as the reason a form shows.
     *
     * @param array<string,mixed> $user     the signed-in user's t_user row
     * @param string              $password as typed
     *
     * @return string '' when the password is right, otherwise the reason to show
     */
    public static function verify(array $user, string $password): string
    {
        $result = self::attempt($user, $password);
        if ($result === self::OK) {
            return '';
        }

        return $result === self::WRONG ? _m("Current password doesn't match") : osc_login_throttle_message($result);
    }

    /**
     * attempt(), refusing for a service: a wrong password is InvalidException on `current_password`.
     *
     * @param array<string,mixed> $user     the signed-in user's t_user row
     * @param string              $password as typed
     *
     * @throws BlockedException while too many wrong passwords came in a row
     * @throws InvalidException for a wrong password
     */
    public static function check(array $user, string $password): void
    {
        $result = self::attempt($user, $password);
        if ($result === self::WRONG) {
            throw new InvalidException('/current_password', 'mismatch', _m('is not the current password'));
        }
        if ($result !== self::OK) {
            throw new BlockedException(_m('Too many wrong passwords. Try again later.'), $result);
        }
    }
}
