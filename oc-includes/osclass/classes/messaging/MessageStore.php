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
        $this->table()->where('pk_i_id', $id)->update(array('s_state' => $state));
    }

    /**
     * One page of the thread, newest first. The viewer sees sent messages and their own held ones.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function ofConversation(int $conversationId, int $viewerId, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->table()
            ->where('fk_i_conversation_id', $conversationId)
            ->whereRaw("(s_state = 'sent' OR (s_state = 'held' AND fk_i_sender_id = ?))", array($viewerId))
            ->newestBefore($beforeId, max(1, $limit));
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
     * The messages the user wrote in the given threads.
     *
     * @param int[] $conversationIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function ownInConversations(int $userId, array $conversationIds): array
    {
        if ($conversationIds === array()) {
            return array();
        }

        return $this->table()
            ->whereIn('fk_i_conversation_id', $conversationIds)
            ->where('fk_i_sender_id', $userId)
            ->orderBy('pk_i_id')
            ->get();
    }

    private function table(): \mindstellar\database\QueryBuilder
    {
        return Db::table(DB_TABLE_PREFIX . 't_message');
    }
}
