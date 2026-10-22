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
 * A party blocked a thread, or lifted the block (`blocked` is false). Old action: `conversation_blocked`.
 */
final class ConversationBlocked extends Event implements Bridged
{
    public function __construct(private int $conversationId, private int $byUserId, private bool $blocked)
    {
    }

    public static function legacyNames(): array
    {
        return ['conversation_blocked'];
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function byUserId(): int
    {
        return $this->byUserId;
    }

    public function blocked(): bool
    {
        return $this->blocked;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->conversationId, $this->byUserId, $this->blocked);
    }
}
