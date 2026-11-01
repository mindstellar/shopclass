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
 * Rows of t_message. Like ConversationStore it holds no rules and does not read the clock.
 */
final class MessageStore
{
    /**
     * A stored message in the shape the site's scripts get it.
     *
     * @param array<string,mixed> $row
     *
     * @return array{id:int,conversation:int,sender:int,body:string,at:string}
     */
    public static function shape(array $row): array
    {
        return [
            'id'           => (int) $row['pk_i_id'],
            'conversation' => (int) $row['fk_i_conversation_id'],
            'sender'       => (int) $row['fk_i_sender_id'],
            'body'         => (string) $row['s_body'],
            'at'           => (string) $row['dt_created'],
        ];
    }

    /**
     * Insert a row and return its id.
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
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public function find(int $id): ?array
    {
        return $this->table()->where('pk_i_id', $id)->first();
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function setState(int $id, string $state): void
    {
        $this->table()->where('pk_i_id', $id)->update(['s_state' => $state]);
    }

    /**
     * One page of the thread, newest first. The viewer sees sent messages and their own held
     * ones; with $includeHeld every held message shows, for the admin review.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function ofConversation(int $conversationId, int $viewerId, int $limit = 50, ?int $beforeId = null, bool $includeHeld = false): array
    {
        $query = $this->table()->where('fk_i_conversation_id', $conversationId);
        $query = $includeHeld ? $query->whereIn('s_state', ['sent', 'held']) : self::visibleTo($query, $viewerId);

        return $query->newestBefore($beforeId, max(1, $limit));
    }

    /**
     * The messages after $afterId the viewer may see, oldest first.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function since(int $conversationId, int $viewerId, int $afterId, int $limit = 50): array
    {
        return self::visibleTo($this->table()->where('fk_i_conversation_id', $conversationId), $viewerId)
            ->where('pk_i_id', '>', $afterId)
            ->orderBy('pk_i_id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Messages in the thread that are sent or waiting for review.
     *
     * @throws \mindstellar\database\DbException
     */
    public function liveCount(int $conversationId): int
    {
        return $this->table()->where('fk_i_conversation_id', $conversationId)->whereIn('s_state', ['sent', 'held'])->count();
    }

    /**
     * Held messages, newest id first, for the admin review list.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function held(int $limit = 20, ?int $before = null): array
    {
        return $this->table()->where('s_state', 'held')->newestBefore($before, max(1, $limit));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function countHeld(): int
    {
        return $this->table()->where('s_state', 'held')->count();
    }

    /**
     * The messages the user wrote in the given threads.
     *
     * @param int[] $conversationIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function ownInConversations(int $userId, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return $this->table()
            ->whereIn('fk_i_conversation_id', $conversationIds)
            ->where('fk_i_sender_id', $userId)
            ->orderBy('pk_i_id')
            ->get();
    }

    /**
     * The newest message each thread shows the viewer, for the inbox preview. The body is cut
     * to 160 characters as s_preview. A thread with nothing visible is left out.
     *
     * @param int[] $conversationIds
     *
     * @return array<int,array<string,mixed>> rows by conversation id
     * @throws \mindstellar\database\DbException
     */
    public function lastVisible(array $conversationIds, int $viewerId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $one = '(SELECT pk_i_id, fk_i_conversation_id, fk_i_sender_id, s_state, dt_created, LEFT(s_body, 160) AS s_preview FROM '
            . DB_TABLE_PREFIX . "t_message WHERE fk_i_conversation_id = ? AND (s_state = 'sent' OR (s_state = 'held' AND fk_i_sender_id = ?))"
            . ' ORDER BY pk_i_id DESC LIMIT 1)';
        $params = [];
        foreach ($ids as $id) {
            $params[] = $id;
            $params[] = $viewerId;
        }
        $last = [];
        foreach (Db::select(implode(' UNION ALL ', array_fill(0, count($ids), $one)), $params) as $row) {
            $last[(int) $row['fk_i_conversation_id']] = $row;
        }

        return $last;
    }

    /**
     * Sent messages, and the viewer's own held ones.
     */
    private static function visibleTo(\mindstellar\database\QueryBuilder $query, int $viewerId): \mindstellar\database\QueryBuilder
    {
        return $query->whereRaw("(s_state = 'sent' OR (s_state = 'held' AND fk_i_sender_id = ?))", [$viewerId]);
    }

    private function table(): \mindstellar\database\QueryBuilder
    {
        return Db::table(DB_TABLE_PREFIX . 't_message');
    }
}
