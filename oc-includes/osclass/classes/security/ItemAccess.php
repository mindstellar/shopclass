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

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;

/**
 * @deprecated 7.0.0 compatibility: use \mindstellar\listing\ListingPolicy.
 */
final class ItemAccess
{
    /**
     * @param array<string,mixed> $item a t_item row
     *
     * @return bool true when the public may not see the listing: not validated, disabled or spam
     */
    public static function isHidden(array $item): bool
    {
        return ListingPolicy::isHidden($item);
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId the signed-in user's id, or null
     */
    public static function isOwner(array $item, $userId): bool
    {
        return ListingPolicy::isOwner($item, self::actor($userId, false));
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId
     */
    public static function canView(array $item, $userId, bool $isAdmin): bool
    {
        return ListingPolicy::canView($item, self::actor($userId, $isAdmin));
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId
     * @param string              $secret the item secret sent with the request; only a guest
     *                                    listing accepts it
     */
    public static function canManage(array $item, $userId, bool $isAdmin, string $secret): bool
    {
        return ListingPolicy::canManage($item, self::actor($userId, $isAdmin)->withSecret($secret));
    }

    /**
     * @param int|string|null $userId
     *
     * @return array<string,mixed> the listing when the caller may manage it, else an empty array
     */
    public static function manageable(int $id, $userId, bool $isAdmin, string $secret): array
    {
        return ListingPolicy::manageable($id, self::actor($userId, $isAdmin)->withSecret($secret)) ?? array();
    }

    /**
     * @param array<string,mixed>|mixed $resource a t_item_resource row, or what a failed lookup returned
     * @param array<string,mixed>       $item
     * @param string                    $code     the photo's s_name as sent
     */
    public static function isPhotoOf($resource, array $item, string $code): bool
    {
        return ListingPolicy::isPhotoOf($resource, $item, $code);
    }

    /**
     * @param int|string|null $userId
     */
    private static function actor($userId, bool $isAdmin): Actor
    {
        return new Actor((int) $userId, $isAdmin ? 0 : null);
    }
}
