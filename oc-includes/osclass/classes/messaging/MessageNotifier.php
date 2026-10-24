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
use mindstellar\job\Job;
use mindstellar\mail\CoreEmails;
use mindstellar\messaging\event\MessageSent;
use mindstellar\user\UserStore;

/**
 * Tells the recipient about new messages by e-mail. A sent message queues one delayed job per
 * conversation and recipient; messages that arrive before it runs share that one mail.
 */
final class MessageNotifier
{
    public const JOB   = 'message.notify';
    public const DELAY = 60;

    public function __construct(private ConversationStore $conversations, private CoreEmails $mail)
    {
    }

    public function onMessageSent(MessageSent $event): void
    {
        $conversationId = $event->conversationId();
        $recipientId    = $event->recipientId();
        Db::afterCommit(static function () use ($conversationId, $recipientId): void {
            osc_job_enqueue(
                self::JOB,
                ['conversation' => $conversationId, 'recipient' => $recipientId],
                ['unique_key' => $conversationId . ':' . $recipientId, 'keep_existing' => true, 'delay' => self::DELAY]
            );
        });
    }

    /**
     * Mail the recipient unless the thread was read, blocked or closed meanwhile.
     *
     * @throws \mindstellar\database\DbException
     */
    public function run(Job $job): void
    {
        $conversation = $this->conversations->find((int) $job->get('conversation', 0));
        $recipientId  = (int) $job->get('recipient', 0);
        if ($conversation === null) {
            return;
        }
        $side = ConversationStore::sideOf($conversation, $recipientId);
        if ($side === null || (int) ($conversation['i_' . $side . '_unread'] ?? 0) === 0) {
            return;
        }
        if (MessagePolicy::canSend($conversation, $recipientId) !== MessagePolicy::ALLOWED) {
            return;
        }
        $recipient = UserStore::find($recipientId);
        if ($recipient === null || !UserStore::isLive($recipient)) {
            return;
        }
        $this->mail->mailNewMessage($conversation, $recipient, $this->listing((int) $conversation['fk_i_item_id']));
    }

    /**
     * The listing row with its title, or null when it was deleted.
     *
     * @return array<string,mixed>|null
     */
    private function listing(int $itemId): ?array
    {
        $item = \Item::getInstance()->findByPrimaryKey($itemId);

        return is_array($item) && $item !== [] ? $item : null;
    }
}
