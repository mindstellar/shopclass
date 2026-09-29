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
 * Asks a signed-in admin to prove it is them again before a dangerous action: the
 * password, plus a code when two-step sign-in is on. Failures count in the sign-in limit.
 */
final class AdminReauth
{
    /** The sign-in limit context a restore's password check counts under. */
    public const RESTORE = 'restore_reauth';

    /**
     * @param array<string,mixed> $admin    the signed-in admin's t_admin row
     * @param string              $password as typed
     * @param string              $code     an app or backup code; ignored when 2FA is off
     * @param string              $context  the sign-in limit context to count failures under
     *
     * @return string '' when both pass, otherwise the reason to show
     */
    public static function verify(array $admin, string $password, string $code, string $context = self::RESTORE): string
    {
        $account  = (string)($admin['s_username'] ?? '');
        $throttle = LoginThrottle::evaluate($context, $account);
        if ($throttle['status'] === LoginThrottle::BLOCKED) {
            return osc_login_throttle_message($throttle['retry_after']);
        }

        $hash = (string)($admin['s_password'] ?? '');
        if ($password === '' || $hash === '' || !osc_verify_password($password, $hash)) {
            LoginThrottle::recordFailure($context, $account);

            return _m('That password is not right. Nothing was started.');
        }

        if (AdminTwoFactor::enabled($admin) && !AdminTwoFactor::check($admin, $code)) {
            LoginThrottle::recordFailure($context, $account);
            AdminTwoFactor::noteFailure($admin);

            return AdminTwoFactor::refusedMessage();
        }

        LoginThrottle::clear($context, $account);

        return '';
    }
}
