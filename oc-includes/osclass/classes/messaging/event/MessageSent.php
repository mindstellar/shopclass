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

namespace mindstellar\messaging\event;

use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * A message was stored and is visible to the recipient. Old action: `message_sent`.
 */
final class MessageSent extends Event implements Bridged
{
    public function __construct(private int $messageId, private int $conversationId, private int $senderId, private int $recipientId)
    {
    }

    public static function legacyNames(): array
    {
        return ['message_sent'];
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function senderId(): int
    {
        return $this->senderId;
    }

    public function recipientId(): int
    {
        return $this->recipientId;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->messageId, $this->conversationId, $this->senderId, $this->recipientId);
    }
}
