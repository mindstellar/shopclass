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

use mindstellar\auth\Reauth;

/**
 * @deprecated 7.0.0 compatibility: use \mindstellar\auth\Reauth.
 */
final class UserReauth
{
    /** The sign-in limit context, shared with the sign-in form. */
    public const CONTEXT = Reauth::CONTEXT;

    /**
     * @param array<string,mixed> $user     the signed-in user's t_user row
     * @param string              $password as typed
     *
     * @return string '' when the password is right, otherwise the reason to show
     */
    public static function verify(array $user, string $password): string
    {
        return Reauth::verify($user, $password);
    }
}
