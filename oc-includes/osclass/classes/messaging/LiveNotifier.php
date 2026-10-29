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

use mindstellar\live\LiveChannel;
use mindstellar\live\LivePublisher;
use mindstellar\messaging\event\MessageRead;
use mindstellar\messaging\event\MessageSent;

/**
 * Nudges a user's open pages when a message arrives, their unread count drops, or the other
 * side reads or types. Notes carry ids only. A held message publishes nothing: the recipient
 * must not learn of it.
 */
final class LiveNotifier
{
    public function __construct(private LivePublisher $live, private ConversationStore $conversations)
    {
    }

    /**
     * Whether notes reach anyone; false while live delivery is off.
     */
    public function isOn(): bool
    {
        return $this->live->mode() !== 'off';
    }

    /**
     * Tell the user the other side is typing in the thread. $at is the server's Unix time.
     */
    public function typing(int $conversationId, int $toUserId, int $at): void
    {
        $this->live->publish(LiveChannel::forUser($toUserId), [
            'kind'         => 'typing',
            'conversation' => $conversationId,
            'at'           => $at,
        ]);
    }

    public function onMessageSent(MessageSent $event): void
    {
        $this->live->publish(LiveChannel::forUser($event->recipientId()), [
            'kind'         => 'sent',
            'conversation' => $event->conversationId(),
            'message'      => $event->messageId(),
        ]);
    }

    public function onMessageRead(MessageRead $event): void
    {
        $this->live->publish(LiveChannel::forUser($event->readerId()), [
            'kind'         => 'read',
            'conversation' => $event->conversationId(),
        ]);
        if (!$this->isOn()) {
            return;
        }
        $conversation = $this->conversations->find($event->conversationId());
        $side         = $conversation !== null ? ConversationStore::sideOf($conversation, $event->readerId()) : null;
        if ($side === null) {
            return;
        }
        $otherSide = $side === ConversationStore::SIDE_BUYER ? ConversationStore::SIDE_SELLER : ConversationStore::SIDE_BUYER;
        if ($otherSide === ConversationStore::SIDE_SELLER && (string) $conversation['s_state'] === 'held') {
            // The seller does not see a held thread.
            return;
        }
        $this->live->publish(LiveChannel::forUser((int) $conversation['fk_i_' . $otherSide . '_id']), [
            'kind'         => 'seen',
            'conversation' => $event->conversationId(),
        ]);
    }
}
