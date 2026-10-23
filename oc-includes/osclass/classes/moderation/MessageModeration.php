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

namespace mindstellar\moderation;

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\event\DispatcherInterface;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\NotFoundException;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\event\ConversationStarted;
use mindstellar\messaging\event\MessageSent;
use mindstellar\messaging\MessageStore;
use mindstellar\security\Demo;
use mindstellar\user\UserStore;
use mindstellar\utility\Clock;

/**
 * What an admin does with held messages and reported conversations, for the review screen and
 * the API alike. Release and remove a held message; close a reported thread or clear its report.
 */
final class MessageModeration
{
    /** ConflictException reasons. */
    public const NOT_HELD     = 'not_held';
    public const NOT_REPORTED = 'not_reported';

    public function __construct(
        private ConversationStore $conversations,
        private MessageStore $messages,
        private DispatcherInterface $events,
        private Clock $clock
    ) {
    }

    /**
     * Deliver a held message: it counts as unread for the recipient and `message_sent` fires. A
     * thread held with it opens, and `conversation_started` fires first.
     *
     * @throws ForbiddenException a demo site or an actor who is not an admin
     * @throws NotFoundException  no such message
     * @throws ConflictException  a message that is not held
     */
    public function release(int $messageId, Actor $admin): void
    {
        $this->guard($admin);
        $now = date('Y-m-d H:i:s', $this->clock->now());
        Db::transaction(function () use ($messageId, $now): void {
            [$message, $conversation] = $this->lockHeld($messageId);
            $conversationId = (int) $conversation['pk_i_id'];
            $senderId       = (int) $message['fk_i_sender_id'];
            $recipientId    = ConversationStore::otherParty($conversation, $senderId);
            $started        = (string) $conversation['s_state'] === 'held';
            $this->messages->setState($messageId, 'sent');
            $this->conversations->touch($conversationId, (string) ConversationStore::sideOf($conversation, $recipientId), $now);
            if ($started) {
                $this->conversations->setState($conversationId, 'open');
            }
            Db::afterCommit(function () use ($started, $conversation, $conversationId, $messageId, $senderId, $recipientId): void {
                if ($started) {
                    $this->events->dispatch(new ConversationStarted(
                        $conversationId,
                        (int) $conversation['fk_i_item_id'],
                        (int) $conversation['fk_i_buyer_id'],
                        (int) $conversation['fk_i_seller_id']
                    ));
                }
                $this->events->dispatch(new MessageSent($messageId, $conversationId, $senderId, $recipientId));
            });
        });
    }

    /**
     * Drop a held message. A held thread with nothing else left in it is closed.
     *
     * @throws ForbiddenException a demo site or an actor who is not an admin
     * @throws NotFoundException  no such message
     * @throws ConflictException  a message that is not held
     */
    public function remove(int $messageId, Actor $admin): void
    {
        $this->guard($admin);
        Db::transaction(function () use ($messageId): void {
            [, $conversation] = $this->lockHeld($messageId);
            $conversationId   = (int) $conversation['pk_i_id'];
            $this->messages->setState($messageId, 'removed');
            if ((string) $conversation['s_state'] === 'held' && $this->messages->liveCount($conversationId) === 0) {
                $this->conversations->setState($conversationId, 'closed');
            }
        });
    }

    /**
     * Close a reported thread: nobody writes in it any more, both sides still read it.
     *
     * @throws ForbiddenException a demo site or an actor who is not an admin
     * @throws NotFoundException  no such conversation
     * @throws ConflictException  a thread that is not reported
     */
    public function close(int $conversationId, Actor $admin): void
    {
        $this->guard($admin);
        Db::transaction(function () use ($conversationId): void {
            $this->lockReported($conversationId);
            $this->conversations->setState($conversationId, 'closed');
        });
    }

    /**
     * Put a reported thread back to open and drop the report. The reporter's block stays; they
     * can lift it themselves.
     *
     * @throws ForbiddenException a demo site or an actor who is not an admin
     * @throws NotFoundException  no such conversation
     * @throws ConflictException  a thread that is not reported
     */
    public function clearReport(int $conversationId, Actor $admin): void
    {
        $this->guard($admin);
        Db::transaction(function () use ($conversationId): void {
            $this->lockReported($conversationId);
            $this->conversations->clearReport($conversationId);
        });
    }

    /**
     * Held messages, newest first, each with its `conversation` row and the `sender_name`,
     * `sender_email`, `recipient_id` and `recipient_name`. Page on with the last row's id.
     *
     * @return array<int,array<string,mixed>>
     * @throws ForbiddenException an actor who is not an admin
     * @throws \mindstellar\database\DbException
     */
    public function held(Actor $admin, int $limit = 20, ?int $before = null): array
    {
        $this->requireAdmin($admin);

        return $this->withNames($this->messages->held($limit, $before));
    }

    /**
     * One message with its `conversation` row and the same names as held() gives.
     *
     * @return array<string,mixed>|null null when there is no such message
     * @throws ForbiddenException an actor who is not an admin
     * @throws \mindstellar\database\DbException
     */
    public function message(int $id, Actor $admin): ?array
    {
        $this->requireAdmin($admin);
        $row = $this->messages->find($id);

        return $row === null ? null : $this->withNames(array($row))[0];
    }

    /**
     * Reported threads, newest first, each with `buyer_name`, `seller_name` and `reporter_name`.
     * Page on with the last row's id.
     *
     * @return array<int,array<string,mixed>>
     * @throws ForbiddenException an actor who is not an admin
     * @throws \mindstellar\database\DbException
     */
    public function reported(Actor $admin, int $limit = 20, ?int $before = null): array
    {
        $this->requireAdmin($admin);

        return $this->namedThreads($this->conversations->reported($limit, $before));
    }

    /**
     * Threads for the admin list, newest first, with the same names as reported() gives. Filters
     * are `status`, `user` (ids, buyer or seller) and `listing` (item ids). Page on with the last
     * row's id.
     *
     * @param array{status?:string,user?:int[],listing?:int[]} $filters
     *
     * @return array<int,array<string,mixed>>
     * @throws ForbiddenException an actor who is not an admin
     * @throws \mindstellar\database\DbException
     */
    public function conversations(array $filters, int $limit, ?int $before, Actor $admin): array
    {
        $this->requireAdmin($admin);

        return $this->namedThreads($this->conversations->listNewest($filters, $limit, $before));
    }

    /**
     * A thread for the admin view: its row, every sent and held message oldest first, and the
     * two parties' names by user id.
     *
     * @return array{conversation: array<string,mixed>, messages: array<int,array<string,mixed>>, names: array<int,string>}
     * @throws ForbiddenException an actor who is not an admin
     * @throws NotFoundException  no such conversation
     */
    public function thread(int $conversationId, Actor $admin, int $limit = 100, ?int $beforeId = null): array
    {
        $this->requireAdmin($admin);
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundException(_m('No such conversation'));
        }
        $names = array();
        $users = $this->users(array($conversation['fk_i_buyer_id'], $conversation['fk_i_seller_id']));
        foreach (array((int) $conversation['fk_i_buyer_id'], (int) $conversation['fk_i_seller_id']) as $id) {
            $names[$id] = (string) ($users[$id]['s_name'] ?? '');
        }

        return array(
            'conversation' => $conversation,
            'messages'     => array_reverse($this->messages->ofConversation($conversationId, 0, $limit, $beforeId, true)),
            'names'        => $names,
        );
    }

    /**
     * How many messages wait for review and how many threads are reported.
     *
     * @return array{held:int,reported:int}
     * @throws \mindstellar\database\DbException
     */
    public function counts(): array
    {
        return array('held' => $this->messages->countHeld(), 'reported' => $this->conversations->countReported());
    }

    /**
     * @throws ForbiddenException
     */
    private function guard(Actor $admin): void
    {
        Demo::refuse();
        $this->requireAdmin($admin);
    }

    /**
     * @throws ForbiddenException
     */
    private function requireAdmin(Actor $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new ForbiddenException(_m('Only an admin can review messages'), ForbiddenException::NOT_OWNER);
        }
    }

    /**
     * Held-message rows with their `conversation` and the sender and recipient names.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function withNames(array $rows): array
    {
        $conversations = $this->conversations->byIds(array_column($rows, 'fk_i_conversation_id'));
        $userIds       = array();
        foreach ($rows as $row) {
            $conversation = $conversations[(int) $row['fk_i_conversation_id']] ?? null;
            $userIds[]    = (int) $row['fk_i_sender_id'];
            if ($conversation !== null) {
                $userIds[] = ConversationStore::otherParty($conversation, (int) $row['fk_i_sender_id']);
            }
        }
        $users = $this->users($userIds);
        foreach ($rows as &$row) {
            $conversation          = $conversations[(int) $row['fk_i_conversation_id']] ?? null;
            $senderId              = (int) $row['fk_i_sender_id'];
            $recipientId           = $conversation !== null ? ConversationStore::otherParty($conversation, $senderId) : 0;
            $row['conversation']   = $conversation;
            $row['sender_name']    = (string) ($users[$senderId]['s_name'] ?? '');
            $row['sender_email']   = (string) ($users[$senderId]['s_email'] ?? '');
            $row['recipient_id']   = $recipientId;
            $row['recipient_name'] = (string) ($users[$recipientId]['s_name'] ?? '');
        }
        unset($row);

        return $rows;
    }

    /**
     * Thread rows with `buyer_name`, `seller_name` and `reporter_name`.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function namedThreads(array $rows): array
    {
        $users = $this->users(array_merge(
            array_column($rows, 'fk_i_buyer_id'),
            array_column($rows, 'fk_i_seller_id'),
            array_column($rows, 'fk_i_reporter_id')
        ));
        foreach ($rows as &$row) {
            foreach (array('buyer', 'seller', 'reporter') as $who) {
                $row[$who . '_name'] = (string) ($users[(int) $row['fk_i_' . $who . '_id']]['s_name'] ?? '');
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * The held message and its thread, the thread locked.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     * @throws NotFoundException|ConflictException
     */
    private function lockHeld(int $messageId): array
    {
        $message      = $this->messages->find($messageId);
        $conversation = $message !== null ? $this->conversations->lock((int) $message['fk_i_conversation_id']) : null;
        // Read again under the thread's lock, which every change to its messages takes.
        $message = $conversation !== null ? $this->messages->find($messageId) : null;
        if ($message === null || $conversation === null) {
            throw new NotFoundException(_m('No such message'));
        }
        if ((string) $message['s_state'] !== 'held') {
            throw new ConflictException(_m('This message is not waiting for review'), self::NOT_HELD);
        }

        return array($message, $conversation);
    }

    /**
     * @throws NotFoundException|ConflictException
     */
    private function lockReported(int $conversationId): void
    {
        $conversation = $this->conversations->lock($conversationId);
        if ($conversation === null) {
            throw new NotFoundException(_m('No such conversation'));
        }
        if ((string) $conversation['s_state'] !== 'reported') {
            throw new ConflictException(_m('This conversation is not reported'), self::NOT_REPORTED);
        }
    }

    /**
     * Name and e-mail by user id.
     *
     * @param array<int,int|string|null> $ids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    private function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === array()) {
            return array();
        }
        $users = array();
        foreach (UserStore::byIds($ids, array('pk_i_id', 's_name', 's_email')) as $user) {
            $users[(int) $user['pk_i_id']] = $user;
        }

        return $users;
    }
}
