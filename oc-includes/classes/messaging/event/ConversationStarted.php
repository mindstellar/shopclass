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
 * A buyer wrote the first message of a thread. Old action: `conversation_started`.
 */
final class ConversationStarted extends Event implements Bridged
{
    public function __construct(private int $conversationId, private int $itemId, private int $buyerId, private int $sellerId)
    {
    }

    public static function legacyNames(): array
    {
        return ['conversation_started'];
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function buyerId(): int
    {
        return $this->buyerId;
    }

    public function sellerId(): int
    {
        return $this->sellerId;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->conversationId, $this->itemId, $this->buyerId, $this->sellerId);
    }
}
