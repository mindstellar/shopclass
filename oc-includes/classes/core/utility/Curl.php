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

namespace mindstellar\utility;

/**
 * Whether this server can make HTTP requests with cURL.
 */
final class Curl
{
    private function __construct()
    {
    }

    /**
     * @param bool $multi also needs curl_multi, for several requests side by side
     */
    public static function available(bool $multi = false): bool
    {
        return function_exists('curl_init') && function_exists('curl_exec') && (!$multi || function_exists('curl_multi_init'));
    }
}
