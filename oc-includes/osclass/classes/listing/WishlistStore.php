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

use mindstellar\database\Db;
use mindstellar\database\RowHashQuery;
use mindstellar\exception\NotFoundException;
use mindstellar\model\KeyValue;

/**
 * A user's wishlist: listing ids, newest first, at most MAX. One t_key_value row per user
 * (group `wishlist`, key the user id, no expiry). The store has no user column, so the list
 * is exported on `personal_data_export` and removed on `delete_user` from here.
 */
final class WishlistStore
{
    public const GROUP = 'wishlist';

    /** Most listings one wishlist keeps; the oldest go first. */
    public const MAX = 200;

    private KeyValue $kv;

    public function __construct(?KeyValue $kv = null)
    {
        $this->kv = $kv ?? new KeyValue();
    }

    /**
     * The saved listing ids, newest first.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public function all(int $userId): array
    {
        if ($userId <= 0) {
            return array();
        }
        $row  = $this->kv->get(self::GROUP, (string) $userId);
        $list = $row === null || $row['value'] === null ? null : json_decode($row['value'], true);

        return is_array($list) ? self::clean($list) : array();
    }

    /**
     * Replace the list with $ids, in that order. Ids that are not live listings are dropped,
     * then duplicates, then all past MAX.
     *
     * @param array<int,mixed> $ids
     *
     * @return int[] the list as saved
     * @throws \mindstellar\database\DbException
     */
    public function replace(int $userId, array $ids): array
    {
        $ids  = self::clean($ids);
        $live = array_flip(self::live($ids));
        $ids  = array_values(array_filter($ids, static fn (int $id): bool => isset($live[$id])));
        $this->save($userId, $ids);

        return $ids;
    }

    /**
     * Put a live listing at the top of the list.
     *
     * @throws NotFoundException when the listing is not live
     * @throws \mindstellar\database\DbException
     */
    public function add(int $userId, int $listingId): void
    {
        if (self::live(array($listingId)) === array()) {
            throw new NotFoundException(_m('The listing was not found.'));
        }
        $ids = $this->all($userId);
        $this->save($userId, array_merge(array($listingId), array_values(array_diff($ids, array($listingId)))));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function remove(int $userId, int $listingId): void
    {
        $ids  = $this->all($userId);
        $kept = array_values(array_diff($ids, array($listingId)));
        if ($kept !== $ids) {
            $this->save($userId, $kept);
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

    /**
     * `personal_data_export`: the wishlist beside the account's tables.
     *
     * @param mixed $out
     * @param mixed $userId
     *
     * @return mixed
     */
    public static function export($out, $userId = 0)
    {
        if (is_array($out)) {
            $out['data']['wishlist'] = array(
                'retention' => \mindstellar\privacy\PersonalData::ERASE_DELETED,
                'why'       => 'The listings they saved to their wishlist.',
                'rows'      => array(array('listing_ids' => (new self())->all((int) $userId))),
            );
        }

        return $out;
    }

    /**
     * `delete_user`: the wishlist goes with the account.
     *
     * @param mixed $userId
     */
    public static function erase($userId): void
    {
        if ((int) $userId > 0) {
            (new KeyValue())->delete(self::GROUP, (string) (int) $userId);
        }
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    private function save(int $userId, array $ids): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($ids === array()) {
            $this->kv->delete(self::GROUP, (string) $userId);

            return;
        }
        $this->kv->set(self::GROUP, (string) $userId, (string) json_encode(array_slice($ids, 0, self::MAX)));
    }

    /**
     * Positive whole ids, first one kept, at most MAX.
     *
     * @param array<int,mixed> $ids
     *
     * @return int[]
     */
    private static function clean(array $ids): array
    {
        $out = array();
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0 && !isset($out[$id])) {
                    $out[$id] = $id;
                }
            }
            if (count($out) === self::MAX) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * The ids among $ids that are live listings, in one primary-key lookup.
     *
     * @param int[] $ids
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    private static function live(array $ids): array
    {
        if ($ids === array()) {
            return array();
        }
        $rows = Db::select(
            'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' AND ' . implode(' AND ', ListingStatus::liveConditions()),
            $ids
        );

        return array_map(static fn (array $row): int => (int) $row['pk_i_id'], $rows);
    }
}
