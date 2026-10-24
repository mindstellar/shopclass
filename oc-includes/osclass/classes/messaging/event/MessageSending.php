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
 * A message is about to be stored; a listener may change the body, hold it or refuse it.
 * Old filter: `message_body`. $conversationId is null for the first message of a thread.
 */
final class MessageSending extends Event implements Bridged
{
    private ?string $refusal = null;

    private ?string $holdReason = null;

    public function __construct(
        private string $body,
        private int $itemId,
        private int $senderId,
        private int $recipientId,
        private ?int $conversationId
    ) {
    }

    public static function legacyNames(): array
    {
        return ['message_body'];
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @throws \InvalidArgumentException when the body is empty after trimming
     */
    public function setBody(string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('A message body cannot be empty.');
        }
        $this->body = $body;
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function senderId(): int
    {
        return $this->senderId;
    }

    public function recipientId(): int
    {
        return $this->recipientId;
    }

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    /**
     * Refuse the message; no later listener runs.
     */
    public function refuse(string $reason): void
    {
        $this->refusal = $reason;
        $this->stopPropagation();
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Store the message but keep it from the recipient until an admin releases it.
     */
    public function hold(string $reason): void
    {
        $this->holdReason = $reason;
    }

    public function isHeld(): bool
    {
        return $this->holdReason !== null;
    }

    public function holdReason(): ?string
    {
        return $this->holdReason;
    }

    /**
     * A string that is not blank replaces the body; anything else leaves it, so a callback that
     * forgets to return cannot erase a message.
     */
    public function applyLegacy(string $name, callable $fn): void
    {
        $out = $fn($this->body, $this->itemId, $this->senderId, $this->recipientId, $this->conversationId);
        if (is_string($out) && trim($out) !== '') {
            $this->body = trim($out);
        }
    }
}
