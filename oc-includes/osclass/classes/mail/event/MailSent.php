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

namespace mindstellar\mail\event;

use mindstellar\billing\Order;
use mindstellar\event\Event;

/**
 * A shop mail went through the mailer, or failed to. Read only.
 */
final class MailSent extends Event
{
    public function __construct(
        private string $kind,
        private ?Order $order,
        private string $to,
        private string $subject,
        private string $body,
        private bool $ok
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function order(): ?Order
    {
        return $this->order;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function ok(): bool
    {
        return $this->ok;
    }
}
