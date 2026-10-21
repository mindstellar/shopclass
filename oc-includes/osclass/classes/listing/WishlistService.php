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
use mindstellar\exception\NotFoundException;
use mindstellar\user\UserStore;

/**
 * A user's wishlist: live listing ids, newest first, at most MAX. Each change locks the user's
 * row, so two taps at once both land. The rows have no user column, so the list is exported on
 * `personal_data_export` and removed on `delete_user` here.
 */
final class WishlistService
{
    /** Most listings one wishlist keeps; the oldest go first. */
    public const MAX = 200;

    private WishlistStore $store;

    public function __construct(?WishlistStore $store = null)
    {
        $this->store = $store ?? new WishlistStore();
    }

    /**
     * The saved listing ids, newest first.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public function all(int $userId): array
    {
        return self::clean($this->store->ids($userId));
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
        $this->change($userId, static fn (array $old): array => $ids);

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
        $this->change($userId, static fn (array $ids): array => array_slice(array_merge(array($listingId), array_values(array_diff($ids, array($listingId)))), 0, self::MAX));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function remove(int $userId, int $listingId): void
    {
        $this->change($userId, static fn (array $ids): array => array_values(array_diff($ids, array($listingId))));
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
        (new WishlistStore())->delete((int) $userId);
    }

    /**
     * Read, change and write the list with the user's row locked; nothing is written when
     * the list stays the same.
     *
     * @param callable(int[]):int[] $edit
     *
     * @throws \mindstellar\database\DbException
     */
    private function change(int $userId, callable $edit): void
    {
        if ($userId <= 0) {
            return;
        }
        Db::transaction(function () use ($userId, $edit): void {
            UserStore::lock($userId);
            $ids  = $this->all($userId);
            $next = $edit($ids);
            if ($next !== $ids) {
                $this->store->save($userId, $next);
            }
        });
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
     * The ids among $ids that are live listings.
     *
     * @param int[] $ids
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    private static function live(array $ids): array
    {
        return $ids === array() ? array() : array_map(static fn (array $row): int => (int) $row['pk_i_id'], (new ListingQuery())->findLive($ids));
    }
}
