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
 * A party read unread messages of a thread. Old action: `message_read`.
 */
final class MessageRead extends Event implements Bridged
{
    public function __construct(private int $conversationId, private int $readerId, private int $count)
    {
    }

    public static function legacyNames(): array
    {
        return ['message_read'];
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function readerId(): int
    {
        return $this->readerId;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->conversationId, $this->readerId, $this->count);
    }
}
