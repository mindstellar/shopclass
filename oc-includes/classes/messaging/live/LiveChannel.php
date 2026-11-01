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

namespace mindstellar\live;

/**
 * Channel names. One channel per user, `user-<id>`; the nginx locations in the Docker entrypoint
 * match the same shape.
 */
final class LiveChannel
{
    public const PATTERN = '/^user-(\d+)$/D';

    public static function forUser(int $userId): string
    {
        return 'user-' . $userId;
    }

    /**
     * The user id a channel belongs to, or null when the name is not a user channel.
     */
    public static function userId(string $channel): ?int
    {
        if (preg_match(self::PATTERN, $channel, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 && (string) $id === $m[1] ? $id : null;
    }
}
