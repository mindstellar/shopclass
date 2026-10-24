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

namespace mindstellar\messaging;

use mindstellar\database\Db;

/**
 * Rows of t_conversation: one thread per listing, buyer and seller, so a listing moved to a new
 * owner starts a new thread. The stores keep no rules and do not read the clock; MessagingService
 * passes the times as 'Y-m-d H:i:s' strings.
 */
final class ConversationStore
{
    public const SIDE_BUYER  = 'buyer';
    public const SIDE_SELLER = 'seller';

    public function __construct(private MessageStore $messages)
    {
    }

    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function find(int $id): ?array
    {
        return $this->table()->where('pk_i_id', $id)->first();
    }

    /**
     * Rows by id, keyed by id. Ids with no row are left out.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach ($this->table()->whereIn('pk_i_id', $ids)->get() as $row) {
            $rows[(int) $row['pk_i_id']] = $row;
        }

        return $rows;
    }

    /**
     * Read the row and lock it until the transaction ends.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function lock(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE pk_i_id = ? FOR UPDATE', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function findForListing(int $itemId, int $buyerId, int $sellerId): ?array
    {
        return $this->table()->where('fk_i_item_id', $itemId)->where('fk_i_buyer_id', $buyerId)->where('fk_i_seller_id', $sellerId)->first();
    }

    /**
     * As findForListing(), as a locking read: it sees the newest committed row and holds it.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function lockForListing(int $itemId, int $buyerId, int $sellerId): ?array
    {
        return Db::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND fk_i_buyer_id = ? AND fk_i_seller_id = ? FOR UPDATE', [$itemId, $buyerId, $sellerId]);
    }

    /**
     * Insert a row and return its id. A second thread for the same listing and buyer throws a
     * DbException with code 1062.
     *
     * @param array<string,mixed> $row
     *
     * @throws \mindstellar\database\DbException
     */
    public function create(array $row): int
    {
        return $this->table()->insert($row);
    }

    /**
     * One page of the user's threads, newest first, as buyer and as seller. The seller sees a
     * thread once a message in it was delivered, so not one held for review or removed unseen. Page on from the last row's dt_last_message and pk_i_id.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function inbox(int $userId, int $limit = 20, ?string $beforeDate = null, ?int $beforeId = null): array
    {
        $limit = max(1, $limit);
        $page  = '';
        $bound = [];
        if ($beforeDate !== null) {
            $page  = ' AND (dt_last_message < ? OR (dt_last_message = ? AND pk_i_id < ?))';
            $bound = [$beforeDate, $beforeDate, $beforeId ?? PHP_INT_MAX];
        }
        $one = static function (string $column, string $extra) use ($userId, $page, $bound, $limit): array {
            return [
                '(SELECT * FROM ' . self::tableName() . ' WHERE ' . $column . ' = ?' . $extra . $page
                . ' ORDER BY dt_last_message DESC, pk_i_id DESC LIMIT ' . $limit . ')',
                array_merge([$userId], $bound),
            ];
        };
        [$buyerSql, $buyerParams]   = $one('fk_i_buyer_id', '');
        [$sellerSql, $sellerParams] = $one(
            'fk_i_seller_id',
            " AND s_state <> 'held' AND EXISTS (SELECT 1 FROM " . DB_TABLE_PREFIX . "t_message m WHERE m.fk_i_conversation_id = " . self::tableName() . ".pk_i_id AND m.s_state = 'sent')"
        );

        return Db::select(
            $buyerSql . ' UNION ALL ' . $sellerSql . ' ORDER BY dt_last_message DESC, pk_i_id DESC LIMIT ' . $limit,
            array_merge($buyerParams, $sellerParams)
        );
    }

    /**
     * A new message for the side: stamp the thread and add one to that side's unread count.
     *
     * @throws \mindstellar\database\DbException
     */
    public function touch(int $id, string $side, string $now): void
    {
        $column = self::unreadColumn($side);
        Db::execute(
            'UPDATE ' . self::tableName() . ' SET dt_last_message = ?, ' . $column . ' = LEAST(' . $column . ' + 1, 65535) WHERE pk_i_id = ?',
            [$now, $id]
        );
    }

    /**
     * Zero the side's unread count and return how many it held.
     *
     * @throws \mindstellar\database\DbException
     */
    public function clearUnread(int $id, string $side): int
    {
        $column = self::unreadColumn($side);
        $count  = (int) Db::scalar('SELECT ' . $column . ' FROM ' . self::tableName() . ' WHERE pk_i_id = ?', [$id]);
        if ($count > 0) {
            Db::execute('UPDATE ' . self::tableName() . ' SET ' . $column . ' = 0 WHERE pk_i_id = ? AND ' . $column . ' > 0', [$id]);
        }

        return $count;
    }

    /**
     * Unread messages across all of the user's threads.
     *
     * @throws \mindstellar\database\DbException
     */
    public function unread(int $userId): int
    {
        $table = self::tableName();

        return (int) Db::scalar('SELECT COALESCE(SUM(i_buyer_unread), 0) FROM ' . $table . ' WHERE fk_i_buyer_id = ?', [$userId])
            + (int) Db::scalar('SELECT COALESCE(SUM(i_seller_unread), 0) FROM ' . $table . ' WHERE fk_i_seller_id = ?', [$userId]);
    }

    /**
     * Time of the user's newest thread activity, or null when they have none. A thread still
     * held for review does not count for its seller.
     *
     * @throws \mindstellar\database\DbException
     */
    public function latest(int $userId): ?string
    {
        $table = self::tableName();
        $times = array_filter([
            Db::scalar('SELECT MAX(dt_last_message) FROM ' . $table . ' WHERE fk_i_buyer_id = ?', [$userId]),
            Db::scalar('SELECT MAX(dt_last_message) FROM ' . $table . ' WHERE fk_i_seller_id = ? AND s_state <> ' . "'held'", [$userId]),
        ]);

        return $times === [] ? null : (string) max($times);
    }

    /**
     * Whether either side blocked any thread between this buyer and seller.
     *
     * @throws \mindstellar\database\DbException
     */
    public function blockedBetween(int $buyerId, int $sellerId): bool
    {
        return Db::selectOne(
            'SELECT 1 FROM ' . self::tableName() . ' WHERE fk_i_buyer_id = ? AND fk_i_seller_id = ? AND (b_buyer_blocked = 1 OR b_seller_blocked = 1) LIMIT 1',
            [$buyerId, $sellerId]
        ) !== null;
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function setBlocked(int $id, string $side, bool $blocked): void
    {
        $column = $side === self::SIDE_BUYER ? 'b_buyer_blocked' : ($side === self::SIDE_SELLER ? 'b_seller_blocked' : null);
        if ($column === null) {
            throw new \InvalidArgumentException('Unknown side');
        }
        $this->table()->where('pk_i_id', $id)->update([$column => $blocked ? 1 : 0]);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function setState(int $id, string $state): void
    {
        $this->table()->where('pk_i_id', $id)->update(['s_state' => $state]);
    }

    /**
     * Mark the thread reported by the user.
     *
     * @throws \mindstellar\database\DbException
     */
    public function setReport(int $id, int $reporterId, string $reason): void
    {
        $this->table()->where('pk_i_id', $id)->update([
            's_state'          => 'reported',
            'fk_i_reporter_id' => $reporterId,
            's_report_reason'  => $reason,
        ]);
    }

    /**
     * Put a reported thread back to open and forget who reported it and why.
     *
     * @throws \mindstellar\database\DbException
     */
    public function clearReport(int $id): void
    {
        $this->table()->where('pk_i_id', $id)->update([
            's_state'          => 'open',
            'fk_i_reporter_id' => null,
            's_report_reason'  => null,
        ]);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function countReported(): int
    {
        return $this->table()->where('s_state', 'reported')->count();
    }

    /**
     * Reported threads, newest id first, for the admin review list.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function reported(int $limit = 20, ?int $before = null): array
    {
        return $this->table()->where('s_state', 'reported')->newestBefore($before, max(1, $limit));
    }

    /**
     * One page of threads for the admin API, newest id first. Filters: `status` (a state),
     * `user` (ids; the buyer or the seller) and `listing` (item ids). Page on with the last row's id.
     *
     * @param array{status?:string,user?:int[],listing?:int[]} $filters
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function listNewest(array $filters, int $limit = 20, ?int $beforeId = null): array
    {
        $query = $this->table();
        if (isset($filters['status'])) {
            $query = $query->where('s_state', (string) $filters['status']);
        }
        if (isset($filters['listing'])) {
            $query = $query->whereIn('fk_i_item_id', array_map('intval', $filters['listing']));
        }
        if (isset($filters['user'])) {
            $users = array_map('intval', $filters['user']);
            $query = $query->whereGroup(static fn ($q) => $q->whereIn('fk_i_buyer_id', $users)->orWhereGroup(static fn ($o) => $o->whereIn('fk_i_seller_id', $users)));
        }

        return $query->newestBefore($beforeId, max(1, $limit));
    }

    /**
     * What a person may take with them: the threads they took part in (each with a `side`) and
     * the text of their own messages. The other party's text is not theirs.
     *
     * @return array{conversations: array<int,array<string,mixed>>, messages: array<int,array<string,mixed>>}
     * @throws \mindstellar\database\DbException
     */
    public function export(int $userId): array
    {
        $rows = array_merge(
            $this->table()->where('fk_i_buyer_id', $userId)->get(),
            $this->table()->where('fk_i_seller_id', $userId)->get()
        );
        $ids = [];
        foreach ($rows as &$row) {
            $row['side'] = self::sideOf($row, $userId);
            $ids[]      = (int) $row['pk_i_id'];
        }
        unset($row);

        return ['conversations' => $rows, 'messages' => $this->messages->ownInConversations($userId, $ids)];
    }

    /**
     * 'buyer' or 'seller' for a user on the thread, null for anyone else.
     *
     * @param array<string,mixed> $row
     */
    public static function sideOf(array $row, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        if ((int) $row['fk_i_buyer_id'] === $userId) {
            return self::SIDE_BUYER;
        }

        return (int) $row['fk_i_seller_id'] === $userId ? self::SIDE_SELLER : null;
    }

    /**
     * The id of the user on the other side of the thread.
     *
     * @param array<string,mixed> $row
     */
    public static function otherParty(array $row, int $userId): int
    {
        return (int) $row[(int) $row['fk_i_buyer_id'] === $userId ? 'fk_i_seller_id' : 'fk_i_buyer_id'];
    }

    private static function unreadColumn(string $side): string
    {
        if ($side === self::SIDE_BUYER) {
            return 'i_buyer_unread';
        }
        if ($side === self::SIDE_SELLER) {
            return 'i_seller_unread';
        }
        throw new \InvalidArgumentException('Unknown side');
    }

    private static function tableName(): string
    {
        return DB_TABLE_PREFIX . 't_conversation';
    }

    private function table(): \mindstellar\database\QueryBuilder
    {
        return Db::table(self::tableName());
    }
}
