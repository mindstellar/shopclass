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

namespace mindstellar\billing\event;

use mindstellar\billing\Order;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * An invoice or credit note is being put together, before it is numbered and frozen. A listener may
 * change its s_data array; the money, order and type fields stay core's. Old callbacks on the
 * `billing_document_compose` filter get ($data, $order, $type) and return the data.
 */
final class DocumentComposing extends Event implements Bridged
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(private array $data, private Order $order, private string $type)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function order(): Order
    {
        return $this->order;
    }

    /**
     * Document::INVOICE or Document::CREDIT_NOTE.
     */
    public function type(): string
    {
        return $this->type;
    }

    public static function legacyNames(): array
    {
        return ['billing_document_compose'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $data = $fn($this->data, $this->order, $this->type);
        if (is_array($data)) {
            $this->data = $data;
        }
    }
}
