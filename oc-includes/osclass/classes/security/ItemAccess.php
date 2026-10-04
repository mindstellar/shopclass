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

/**
 * Who may see or change a listing from the public site. The owner is the signed-in user the
 * listing belongs to or, for a listing posted as a guest, whoever holds its secret.
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
        return (int)($item['b_active'] ?? 0) !== 1
            || (int)($item['b_enabled'] ?? 0) === 0
            || (int)($item['b_spam'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId the signed-in user's id, or null
     */
    public static function isOwner(array $item, $userId): bool
    {
        return (int)$userId > 0 && (int)($item['fk_i_user_id'] ?? 0) === (int)$userId;
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId
     */
    public static function canView(array $item, $userId, bool $isAdmin): bool
    {
        return $isAdmin || !self::isHidden($item) || self::isOwner($item, $userId);
    }

    /**
     * @param array<string,mixed> $item
     * @param int|string|null     $userId
     * @param string              $secret the item secret sent with the request; only a guest
     *                                    listing accepts it
     */
    public static function canManage(array $item, $userId, bool $isAdmin, string $secret): bool
    {
        if ($isAdmin || self::isOwner($item, $userId)) {
            return true;
        }

        return empty($item['fk_i_user_id'])
            && $secret !== ''
            && hash_equals((string)($item['s_secret'] ?? ''), $secret);
    }

    /**
     * @param int|string|null $userId
     *
     * @return array<string,mixed> the listing when the caller may manage it, else an empty array
     */
    public static function manageable(int $id, $userId, bool $isAdmin, string $secret): array
    {
        if ($id <= 0) {
            return array();
        }
        $item = \Item::newInstance()->findByPrimaryKey($id);

        return is_array($item) && $item !== array() && self::canManage($item, $userId, $isAdmin, $secret)
            ? $item
            : array();
    }

    /**
     * @param array<string,mixed>|mixed $resource a t_item_resource row, or what a failed lookup returned
     * @param array<string,mixed>       $item
     * @param string                    $code     the photo's s_name as sent
     */
    public static function isPhotoOf($resource, array $item, string $code): bool
    {
        return is_array($resource)
            && isset($resource['fk_i_item_id'], $resource['s_name'], $item['pk_i_id'])
            && (int)$resource['fk_i_item_id'] === (int)$item['pk_i_id']
            && $code !== ''
            && hash_equals((string)$resource['s_name'], $code);
    }
}
