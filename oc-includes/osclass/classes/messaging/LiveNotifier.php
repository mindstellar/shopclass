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
 * Nudges a user's open pages when a message arrives or their unread count drops. Notes carry ids
 * only. A held message publishes nothing: the recipient must not learn of it.
 */
final class LiveNotifier
{
    public function __construct(private LivePublisher $live)
    {
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
    }
}
