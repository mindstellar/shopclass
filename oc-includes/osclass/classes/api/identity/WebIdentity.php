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

namespace mindstellar\api\identity;

use Cookie;
use Session;
use View;

/**
 * The identity core code sees on an API request: cookies and the session are forgotten, and
 * the credential's user (or an admin key's admin) is taken on for this request only. The user's
 * signed sign-in cookie is kept aside, unchecked, for the same-site session mode.
 */
final class WebIdentity
{
    public const SESSION_KEYS = [
        'userId', 'userName', 'userEmail', 'userPhone',
        'adminId', 'adminUserName', 'adminName', 'adminEmail', 'adminLocale',
    ];

    public const COOKIES = ['oc_userId', 'oc_userSecret', 'oc_adminId', 'oc_adminSecret'];

    private static ?SignInCookie $signIn = null;

    private function __construct()
    {
    }

    public static function forget(): void
    {
        self::$signIn = SignInCookie::from(Cookie::getInstance()->val);
        Session::getInstance()->_forgetForRequest(self::SESSION_KEYS);
        $cookie = Cookie::getInstance();
        foreach (self::COOKIES as $name) {
            $cookie->pop($name);
        }
        // An empty user row reads as "nobody", and stops osc_resolve_web_user() looking again.
        View::getInstance()->_exportVariableToView('_loggedUser', []);
    }

    /**
     * The user's sign-in cookie forget() kept aside, unchecked; null when the request had none.
     */
    public static function signInCookie(): ?SignInCookie
    {
        return self::$signIn;
    }

    /**
     * Act as this user for the rest of the request. Nothing is stored: no session, no cookie.
     *
     * @param array<string,mixed> $user the t_user row
     */
    public static function assume(array $user): void
    {
        osc_web_user_apply_identity($user);
    }

    /**
     * Act as this admin for the rest of the request, so core actions log who made a change.
     * Nothing is stored.
     *
     * @param array<string,mixed> $admin the t_admin row
     */
    public static function assumeAdmin(array $admin): void
    {
        $session = Session::getInstance();
        $session->_setEphemeral('adminId', (string) $admin['pk_i_id']);
        $session->_setEphemeral('adminUserName', (string) ($admin['s_username'] ?? ''));
        $session->_setEphemeral('adminName', (string) ($admin['s_name'] ?? ''));
        $session->_setEphemeral('adminEmail', (string) ($admin['s_email'] ?? ''));
    }
}
