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

/**
 * What MessagingService::start() and reply() stored: the thread, the message, whether the
 * message is held for review and whether this call started the thread.
 */
final class SentMessage
{
    /**
     * @param array{id:int,conversation:int,sender:int,body:string,at:string} $message
     */
    public function __construct(private int $conversationId, private int $messageId, private bool $held, private bool $started, private array $message)
    {
    }

    /**
     * The stored message, as MessageStore::shape() gives it.
     *
     * @return array{id:int,conversation:int,sender:int,body:string,at:string}
     */
    public function message(): array
    {
        return $this->message;
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    public function held(): bool
    {
        return $this->held;
    }

    public function started(): bool
    {
        return $this->started;
    }
}
