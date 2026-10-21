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

namespace mindstellar\listing;

use mindstellar\database\RowHashQuery;
use mindstellar\model\KeyValue;

/**
 * Wishlist rows: one t_key_value row per user (group `wishlist`, key the user id, no expiry)
 * holding a JSON list of listing ids. WishlistService holds the rules.
 */
final class WishlistStore
{
    public const GROUP = 'wishlist';

    private KeyValue $kv;

    public function __construct(?KeyValue $kv = null)
    {
        $this->kv = $kv ?? new KeyValue();
    }

    /**
     * The stored list as written, or an empty one when there is no row or it is not a list.
     *
     * @return array<int,mixed>
     * @throws \mindstellar\database\DbException
     */
    public function ids(int $userId): array
    {
        $row  = $userId > 0 ? $this->kv->get(self::GROUP, (string) $userId) : null;
        $list = $row === null || $row['value'] === null ? null : json_decode($row['value'], true);

        return is_array($list) ? array_values($list) : array();
    }

    /**
     * Write the list; an empty one removes the row.
     *
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public function save(int $userId, array $ids): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($ids === array()) {
            $this->delete($userId);

            return;
        }
        $this->kv->set(self::GROUP, (string) $userId, (string) json_encode(array_values($ids)));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function delete(int $userId): void
    {
        if ($userId > 0) {
            $this->kv->delete(self::GROUP, (string) $userId);
        }
    }

    /**
     * The hash of the user's row, for an If-Match on the list; none when the list is empty.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function rowHashes(int $userId, bool $lock): array
    {
        return $userId > 0 ? RowHashQuery::keyedHashes(KeyValue::TABLE, [['s_group' => self::GROUP, 's_key' => (string) $userId]], $lock) : array();
    }
}
