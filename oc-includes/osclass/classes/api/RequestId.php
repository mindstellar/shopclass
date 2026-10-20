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

namespace mindstellar\api;

/**
 * The id of one API request. A client's own `Request-Id` or `X-Request-Id` is reused when it is
 * 8 to 64 characters of letters, digits, `.`, `_` and `-`; otherwise a short random one is made.
 */
final class RequestId
{
    public const HEADER = 'Request-Id';

    private const VALID = '/^[A-Za-z0-9._-]{8,64}$/D';

    public static function for(Request $request): string
    {
        foreach ([self::HEADER, 'X-Request-Id'] as $name) {
            $sent = $request->header($name);
            if (preg_match(self::VALID, $sent) === 1) {
                return $sent;
            }
        }

        return rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
    }
}
