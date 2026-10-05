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

namespace mindstellar\security;

/**
 * The key a client address is counted under by a rate limit. An IPv6 client usually holds a
 * whole /64, so it is counted as one, or it could step around every per-address limit.
 */
final class AddressBucket
{
    private function __construct()
    {
    }

    /**
     * The /64 for an IPv6 address, the address itself otherwise.
     */
    public static function of(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string) inet_pton($ip);
        // An IPv4-mapped address (::ffff:a.b.c.d) is that IPv4 client, not a /64.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
