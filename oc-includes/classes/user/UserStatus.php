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

namespace mindstellar\user;

/**
 * The status of a user, with the words listings and comments use: blocked first, then
 * waiting for confirmation.
 */
final class UserStatus
{
    public const ACTIVE   = 'active';
    public const PENDING  = 'pending';
    public const DISABLED = 'disabled';

    public const ALL = [self::ACTIVE, self::PENDING, self::DISABLED];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $row a t_user row
     */
    public static function of(array $row): string
    {
        return match (true) {
            (string) ($row['b_enabled'] ?? 1) !== '1' => self::DISABLED,
            (string) ($row['b_active'] ?? 0) !== '1'  => self::PENDING,
            default                                   => self::ACTIVE,
        };
    }
}
