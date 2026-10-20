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
 * Reads a row id from text the way every API input does: digits only, or nothing.
 */
final class RowId
{
    private function __construct()
    {
    }

    /**
     * The id, or null when $value is not made of digits alone.
     */
    public static function parse(string|int $value): ?int
    {
        $value = (string) $value;

        return ctype_digit($value) ? (int) $value : null;
    }
}
