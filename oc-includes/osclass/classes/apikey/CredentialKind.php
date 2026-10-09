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

namespace mindstellar\apikey;

/**
 * The kinds of credential. KEY, PUBLIC and REFRESH are also the stored kinds in
 * t_api_credential.e_kind.
 */
final class CredentialKind
{
    /** @api No credential. */
    public const ANONYMOUS = 'anonymous';
    /** @api A public key, `scp_`: reads public data only. */
    public const PUBLIC = 'public';
    /** @api An admin's or a user's API key, `sck_`. */
    public const KEY = 'key';
    /** @api A signed-in user's access token, `sca_`. */
    public const USER = 'user';
    /** @api A signed-in web user calling from the site's own pages: cookie plus page token, `scs_`. */
    public const SESSION = 'session';
    /** A refresh token; never authenticates a call itself. */
    public const REFRESH = 'refresh';

    public const STORED = [self::KEY, self::PUBLIC, self::REFRESH];

    private function __construct()
    {
    }
}
