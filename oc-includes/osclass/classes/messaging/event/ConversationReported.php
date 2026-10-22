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
 * A party reported a thread. Old action: `conversation_reported`.
 */
final class ConversationReported extends Event implements Bridged
{
    public function __construct(private int $conversationId, private int $byUserId, private string $reason)
    {
    }

    public static function legacyNames(): array
    {
        return ['conversation_reported'];
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function byUserId(): int
    {
        return $this->byUserId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->conversationId, $this->byUserId, $this->reason);
    }
}
